<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\UserContext;
use Eiou\Database\PaymentRequestArchiveRepository;
use Eiou\Utils\Logger;
use Throwable;

/**
 * Payment Request Archival Service
 *
 * Moves resolved (non-pending) payment requests older than the configured
 * retention window from `payment_requests` to `payment_requests_archive`.
 * Invoked by `payment-request-archive-cron.php` on a nightly schedule.
 *
 * The rest of the app never touches this class — audit/history callers go
 * through `PaymentRequestRepository`, which transparently UNIONs the two
 * tables.
 */
class PaymentRequestArchivalService
{
    private PaymentRequestArchiveRepository $archiveRepository;
    private UserContext $currentUser;

    public function __construct(
        PaymentRequestArchiveRepository $archiveRepository,
        UserContext $currentUser
    ) {
        $this->archiveRepository = $archiveRepository;
        $this->currentUser = $currentUser;
    }

    /**
     * Archive every eligible row. Loops through the live table in
     * configurable batches so a single transaction never locks more rows
     * than the configured batch size.
     *
     * @param bool $dryRun If true, only counts eligible rows — no writes.
     * @return array{moved:int, batches:int, eligible:int, dry_run:bool, latest_archived_at:?string}
     */
    public function run(bool $dryRun = false): array
    {
        $retentionDays = $this->currentUser->getPaymentRequestsArchiveRetentionDays();
        $batchSize     = $this->currentUser->getPaymentRequestsArchiveBatchSize();

        $logger = Logger::getInstance();
        $logger->info('Payment request archival starting', [
            'retention_days' => $retentionDays,
            'batch_size'     => $batchSize,
            'dry_run'        => $dryRun,
        ]);

        if ($dryRun) {
            $eligibleIds = $this->archiveRepository->findEligibleLiveIds($retentionDays, PHP_INT_MAX);
            $result = [
                'moved'              => 0,
                'batches'            => 0,
                'eligible'           => count($eligibleIds),
                'dry_run'            => true,
                'latest_archived_at' => $this->archiveRepository->getLatestArchivedAt(),
            ];
            $logger->info('Payment request archival dry-run complete', $result);
            return $result;
        }

        $totalMoved = 0;
        $batches    = 0;

        // Safety cap on the outer loop. At 500 rows/batch * 1000 iterations =
        // 500k rows in a single cron run, which is far beyond any realistic
        // backlog. If we ever hit the cap it means something pathological is
        // happening (e.g. runaway insertions) — better to stop and let the
        // next run pick up.
        $maxBatches = 1000;

        while ($batches < $maxBatches) {
            $ids = $this->archiveRepository->findEligibleLiveIds($retentionDays, $batchSize);
            if ($ids === []) {
                break;
            }

            try {
                $moved = $this->archiveRepository->moveRows($ids);
            } catch (Throwable $e) {
                $logger->error('Payment request archival batch failed', [
                    'error'   => $e->getMessage(),
                    'batch'   => $batches,
                    'moved_so_far' => $totalMoved,
                ]);
                throw $e;
            }

            $totalMoved += $moved;
            $batches++;

            // If a batch reported zero moved despite having eligible IDs (the
            // insert/delete mismatch guard in moveRows()), stop to avoid a
            // hot loop — the next cron run can retry.
            if ($moved === 0) {
                $logger->warning('Payment request archival batch produced zero moves, stopping', [
                    'eligible_count' => count($ids),
                ]);
                break;
            }
        }

        $result = [
            'moved'              => $totalMoved,
            'batches'            => $batches,
            'eligible'           => $totalMoved,
            'dry_run'            => false,
            'latest_archived_at' => $this->archiveRepository->getLatestArchivedAt(),
        ];

        $logger->info('Payment request archival complete', $result);
        return $result;
    }
}
