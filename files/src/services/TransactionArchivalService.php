<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\BackupServiceInterface;
use Eiou\Core\UserContext;
use Eiou\Database\TransactionArchiveRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Utils\Logger;
use Throwable;

/**
 * Transaction Archival Service
 *
 * Moves completed transactions older than the configured retention window
 * from `transactions` to `transactions_archive`. Invoked by
 * `transaction-archive-cron.php` on a nightly schedule.
 *
 * Load-bearing correctness invariant
 * ----------------------------------
 * Unlike payment_requests (which have no inter-row structure to preserve),
 * transactions form per-pair chains linked by `previous_txid`. Archival
 * MUST NOT blind the chain walk performed before every outbound send by
 * TransactionChainRepository::verifyChainIntegrity(). A gap archived
 * invisibly becomes permanently undetectable.
 *
 * We enforce the invariant per bilateral pair:
 *
 *   1. Enumerate distinct bilateral pairs that have >=1 eligible row.
 *   2. For each pair, call verifyChainIntegrityByHashes().
 *   3. If the pair reports valid=true: move the pair's eligible rows,
 *      update the pair's checkpoint row (archived_count + archived_txid_hash
 *      + last_verified_gap_free_at), log success.
 *   4. If the pair reports valid=false: SKIP the pair's archival, log a
 *      WARNING. Operator can investigate the gap before the next cron run;
 *      archival will naturally retry once the chain is repaired.
 *
 * Phase 2 of #863 will make verifyChainIntegrity() use the checkpoint row
 * to skip the archived portion of the chain, collapsing its cost from
 * O(all history) to O(recent tail). That optimization is intentionally
 * NOT in this phase — this phase only produces the checkpoint metadata
 * that Phase 2 will consume.
 */
class TransactionArchivalService
{
    private TransactionArchiveRepository $archiveRepository;
    private TransactionChainRepository $chainRepository;
    private UserContext $currentUser;
    private ?BackupServiceInterface $backupService;

    public function __construct(
        TransactionArchiveRepository $archiveRepository,
        TransactionChainRepository $chainRepository,
        UserContext $currentUser,
        ?BackupServiceInterface $backupService = null
    ) {
        $this->archiveRepository = $archiveRepository;
        $this->chainRepository   = $chainRepository;
        $this->currentUser       = $currentUser;
        $this->backupService     = $backupService;
    }

    /**
     * Run the archival pass. See class docblock for the invariant.
     *
     * @param bool $dryRun If true, only counts eligible pairs + rows — no writes.
     * @return array{
     *     moved:int,
     *     pairs_processed:int,
     *     pairs_archived:int,
     *     pairs_skipped_gap:int,
     *     batches:int,
     *     eligible:int,
     *     dry_run:bool,
     *     latest_archived_at:?string,
     *     archive_backup_file?:?string
     * }
     */
    public function run(bool $dryRun = false): array
    {
        $retentionDays = $this->currentUser->getTransactionsArchiveRetentionDays();
        $batchSize     = $this->currentUser->getTransactionsArchiveBatchSize();

        $logger = Logger::getInstance();
        $logger->info('Transaction archival starting', [
            'retention_days' => $retentionDays,
            'batch_size'     => $batchSize,
            'dry_run'        => $dryRun,
        ]);

        // Hard cap on the pair lookup so a pathological state (e.g. a
        // hashing bug producing many distinct spurious pairs) can't run the
        // node out of memory. 10k distinct counterparties per cron run is
        // far beyond any realistic wallet.
        $maxPairs = 10000;
        $pairs = $this->archiveRepository->findEligibleBilateralPairs($retentionDays, $maxPairs);

        if ($dryRun) {
            $eligibleCount = 0;
            foreach ($pairs as $pair) {
                // Count without a limit cap so dry-run tells the operator the
                // real backlog. max(1, PHP_INT_MAX - 1) is just to respect
                // the findEligibleLiveIdsForPair limit floor.
                $ids = $this->archiveRepository->findEligibleLiveIdsForPair(
                    $pair['hash_a'], $pair['hash_b'], $retentionDays, PHP_INT_MAX
                );
                $eligibleCount += count($ids);
            }
            $result = [
                'moved'             => 0,
                'pairs_processed'   => count($pairs),
                'pairs_archived'    => 0,
                'pairs_skipped_gap' => 0,
                'batches'           => 0,
                'eligible'          => $eligibleCount,
                'dry_run'           => true,
                'latest_archived_at' => $this->archiveRepository->getLatestArchivedAt(),
            ];
            $logger->info('Transaction archival dry-run complete', $result);
            return $result;
        }

        $totalMoved       = 0;
        $totalBatches     = 0;
        $pairsArchived    = 0;
        $pairsSkippedGap  = 0;

        foreach ($pairs as $pair) {
            $hashA = $pair['hash_a'];
            $hashB = $pair['hash_b'];

            // THE INVARIANT: verify gap-free BEFORE moving anything.
            // Currency is NOT filtered here — a chain can span currencies
            // via previous_txid links (rare, but possible), so checkpoint
            // the pair as a whole.
            $verify = $this->chainRepository->verifyChainIntegrityByHashes($hashA, $hashB, null);
            if (!$verify['valid']) {
                $logger->warning('Transaction archival skipped pair due to chain gap', [
                    'hash_a'       => substr($hashA, 0, 16),
                    'hash_b'       => substr($hashB, 0, 16),
                    'gap_count'    => count($verify['gaps']),
                    'broken_count' => count($verify['broken_txids']),
                ]);
                $pairsSkippedGap++;
                continue;
            }

            // Pair is gap-free. Move its eligible rows in batches.
            $pairMoved     = 0;
            $pairBatches   = 0;
            $maxBatches    = 1000; // 500 rows * 1000 = 500k per pair per run, safety cap

            while ($pairBatches < $maxBatches) {
                $ids = $this->archiveRepository->findEligibleLiveIdsForPair(
                    $hashA, $hashB, $retentionDays, $batchSize
                );
                if ($ids === []) {
                    break;
                }

                try {
                    $moved = $this->archiveRepository->moveRows($ids);
                } catch (Throwable $e) {
                    $logger->error('Transaction archival batch failed', [
                        'error'        => $e->getMessage(),
                        'hash_a'       => substr($hashA, 0, 16),
                        'hash_b'       => substr($hashB, 0, 16),
                        'batch'        => $pairBatches,
                        'moved_so_far' => $pairMoved,
                    ]);
                    throw $e;
                }

                $pairMoved   += $moved;
                $pairBatches++;

                // Hot-loop protection: the insert/delete mismatch guard in
                // moveRows() can legitimately return 0 if a row was changed
                // concurrently. Stop the pair's loop rather than spin.
                if ($moved === 0) {
                    $logger->warning('Transaction archival batch produced zero moves, stopping pair', [
                        'hash_a'         => substr($hashA, 0, 16),
                        'hash_b'         => substr($hashB, 0, 16),
                        'eligible_count' => count($ids),
                    ]);
                    break;
                }
            }

            if ($pairMoved > 0) {
                // Update the checkpoint row for this pair. Must happen AFTER
                // the move so countArchivedForPair / computeArchivedTxidHash
                // see the newly-archived rows.
                $this->upsertCheckpointAfterMove($hashA, $hashB);
                $pairsArchived++;
            }

            $totalMoved   += $pairMoved;
            $totalBatches += $pairBatches;
        }

        // One archive backup per run, after ALL pairs are processed. Mirrors
        // the payment-request pattern: backup failure is logged loudly but
        // does not fail the archival run (rows are already safely moved).
        $archiveBackupFile = null;
        if ($totalMoved > 0 && $this->backupService !== null) {
            try {
                $backupResult = $this->backupService->createArchiveBackup();
                $archiveBackupFile = $backupResult['filename'] ?? null;
                $this->backupService->cleanupOldBackups();
            } catch (Throwable $e) {
                $logger->error('Archive backup failed after successful archival', [
                    'error' => $e->getMessage(),
                    'moved' => $totalMoved,
                ]);
            }
        }

        $result = [
            'moved'               => $totalMoved,
            'pairs_processed'     => count($pairs),
            'pairs_archived'      => $pairsArchived,
            'pairs_skipped_gap'   => $pairsSkippedGap,
            'batches'             => $totalBatches,
            'eligible'            => $totalMoved,
            'dry_run'             => false,
            'latest_archived_at'  => $this->archiveRepository->getLatestArchivedAt(),
            'archive_backup_file' => $archiveBackupFile,
        ];

        $logger->info('Transaction archival complete', $result);
        return $result;
    }

    /**
     * Recompute the pair's archived count + txid-hash from the archive
     * table and upsert the checkpoint row. Called after a successful move.
     *
     * Using absolute counts (rather than deltas) makes the checkpoint
     * self-healing: if a previous run's checkpoint write was lost, the
     * next run re-derives the correct state from the actual archive.
     */
    private function upsertCheckpointAfterMove(string $hashA, string $hashB): void
    {
        $archivedCount = $this->archiveRepository->countArchivedForPair($hashA, $hashB);
        $txidHash      = $this->archiveRepository->computeArchivedTxidHash($hashA, $hashB);
        $meta          = $this->archiveRepository->getArchiveHeadForPair($hashA, $hashB);

        $this->archiveRepository->upsertCheckpoint(
            $hashA,
            $hashB,
            $meta['highest_archived_timestamp'] ?? date('Y-m-d H:i:s.u'),
            $meta['highest_archived_time'] ?? null,
            $archivedCount,
            $txidHash
        );
    }
}
