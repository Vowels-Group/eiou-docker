<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Database\TransactionArchiveRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Utils\Logger;

/**
 * Chain Verification Service
 *
 * Implements the `eiou verify-chain` CLI command — a safety-net / audit
 * path that bypasses the checkpoint-trust optimization in the send hot
 * path and walks every bilateral chain end-to-end (live + archive). Also
 * recomputes each pair's `archived_txid_hash` from the archive rows and
 * compares it against the stored checkpoint hash — detects tampering
 * that the hot path trusts past by design.
 *
 * Use when:
 *   - An operator wants to audit a node after a restore or backup swap.
 *   - The chain-integrity invariant was logged as skipping a pair and
 *     the operator wants a full explanation.
 *   - Any suspicion that the `transactions_archive` table was modified
 *     outside of the archival service's normal flow.
 *
 * Intentionally NOT on any cron — it's on-demand. Cost is O(all history)
 * per pair, which is the cost the per-pair checkpoint avoids on every
 * send; running it is a deliberate choice.
 */
class ChainAuditService
{
    private TransactionChainRepository $chainRepository;
    private TransactionArchiveRepository $archiveRepository;

    public function __construct(
        TransactionChainRepository $chainRepository,
        TransactionArchiveRepository $archiveRepository
    ) {
        $this->chainRepository   = $chainRepository;
        $this->archiveRepository = $archiveRepository;
    }

    /**
     * CLI entry point. Invoked by `eiou verify-chain` via Eiou.php.
     *
     * Exit code: 0 = all clean; 1 = any pair has findings (gap or hash
     * mismatch). The nonzero exit is the machine-readable summary; the
     * per-pair detail is on stdout.
     */
    public function handleCommand(array $argv, $output = null): int
    {
        $logger = Logger::getInstance();
        $logger->info('verify-chain CLI invoked');

        $result = $this->verifyAll();

        if ($result['pairs_scanned'] === 0) {
            echo "No bilateral chains found on this node.\n";
            return 0;
        }

        $findingsTotal = 0;
        foreach ($result['pairs'] as $pairResult) {
            $findingsTotal += $this->printPairResult($pairResult);
        }

        echo "\n";
        echo "Summary: {$result['pairs_scanned']} pair(s) scanned, {$findingsTotal} finding(s)\n";

        if ($findingsTotal > 0) {
            $logger->warning('verify-chain reported findings', [
                'pairs_scanned' => $result['pairs_scanned'],
                'findings'      => $findingsTotal,
            ]);
            return 1;
        }
        return 0;
    }

    /**
     * Programmatic entry point. Iterates every bilateral pair on this
     * node and runs the paranoid verify + archive-hash recomputation.
     *
     * @return array{pairs_scanned:int,pairs:array<int,array>}
     */
    public function verifyAll(): array
    {
        $pairs = $this->archiveRepository->findAllBilateralPairs();
        $results = [];
        foreach ($pairs as $p) {
            $results[] = $this->verifyPair($p['hash_a'], $p['hash_b']);
        }
        return [
            'pairs_scanned' => count($pairs),
            'pairs'         => $results,
        ];
    }

    /**
     * Verify a single canonical bilateral pair.
     *
     * @return array {
     *   hash_a: string,
     *   hash_b: string,
     *   chain: array (verifyChainIntegrityByHashes result with useCheckpoint=false),
     *   checkpoint: ?array,
     *   archive_hash_match: ?bool,  (null if no checkpoint)
     *   recomputed_archive_hash: string,
     * }
     */
    public function verifyPair(string $hashA, string $hashB): array
    {
        $chain = $this->chainRepository->verifyChainIntegrityByHashes($hashA, $hashB, null, false);

        $checkpoint       = $this->archiveRepository->getCheckpoint($hashA, $hashB);
        $recomputedHash   = $this->archiveRepository->computeArchivedTxidHash($hashA, $hashB);
        $archiveHashMatch = null;
        if ($checkpoint !== null) {
            $archiveHashMatch = hash_equals($checkpoint['archived_txid_hash'], $recomputedHash);
        }

        return [
            'hash_a'                  => $hashA,
            'hash_b'                  => $hashB,
            'chain'                   => $chain,
            'checkpoint'              => $checkpoint,
            'archive_hash_match'      => $archiveHashMatch,
            'recomputed_archive_hash' => $recomputedHash,
        ];
    }

    /**
     * Render one pair's result to stdout. Returns the number of findings
     * (0 if clean, 1 for a chain gap, 1 for a hash mismatch; up to 2 per
     * pair).
     */
    private function printPairResult(array $r): int
    {
        $findings = 0;
        $short    = substr($r['hash_a'], 0, 12) . '...' . substr($r['hash_b'], 0, 12);

        echo "\nPair $short\n";
        echo "  transactions: " . $r['chain']['transaction_count'] . " settled (across live + archive)\n";

        if ($r['chain']['valid']) {
            echo "  chain:        OK (no gaps)\n";
        } else {
            $findings++;
            $gapCount = count($r['chain']['gaps']);
            echo "  chain:        FAIL - {$gapCount} gap(s)\n";
            foreach ($r['chain']['gap_context'] as $ctx) {
                $missing = substr($ctx['missing_txid'] ?? '', 0, 16);
                $after   = substr($ctx['after_txid'] ?? '', 0, 16);
                echo "                  missing: {$missing}... (referenced by {$after}...)\n";
            }
        }

        if ($r['checkpoint'] === null) {
            echo "  checkpoint:   none (pair has no archive)\n";
        } elseif ($r['archive_hash_match'] === true) {
            echo "  checkpoint:   archive hash matches stored value\n";
        } else {
            $findings++;
            $stored     = substr($r['checkpoint']['archived_txid_hash'], 0, 16);
            $recomputed = substr($r['recomputed_archive_hash'], 0, 16);
            echo "  checkpoint:   FAIL - archive hash mismatch\n";
            echo "                stored:     {$stored}...\n";
            echo "                recomputed: {$recomputed}...\n";
            echo "                -> archive table was modified outside the archival service\n";
        }

        return $findings;
    }
}
