<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;
use PDOException;

/**
 * Transaction Archive Repository
 *
 * Read + batch-move operations for `transactions_archive`, plus CRUD for
 * the `transaction_chain_checkpoints` metadata table.
 *
 * Writes to the archive only happen via `moveRows()` from the archival
 * service (`TransactionArchivalService`), which enforces a
 * chain-integrity precondition per bilateral pair. There is no path for
 * the normal send/receive flow to insert directly into the archive.
 *
 * Read paths in the rest of the app don't use this class directly today —
 * Phase 2 of #863 will extend `TransactionChainRepository` +
 * `TransactionContactRepository` + friends to consult the archive / the
 * checkpoint metadata. For Phase 1, this repository is only exercised by
 * the archival cron and by operator-visibility helpers
 * (`countAll`, `getLatestArchivedAt`).
 */
class TransactionArchiveRepository extends AbstractRepository
{
    protected $tableName = 'transactions_archive';

    /**
     * Columns that get copied live -> archive. The archive row additionally
     * gets `archived_at = NOW(6)` set by the INSERT. `id` is excluded so the
     * archive assigns its own AUTO_INCREMENT.
     */
    private const COPY_COLUMNS = [
        'tx_type', 'type', 'status',
        'sender_address', 'sender_public_key', 'sender_public_key_hash',
        'receiver_address', 'receiver_public_key', 'receiver_public_key_hash',
        'amount_whole', 'amount_frac', 'currency',
        'timestamp', 'txid', 'previous_txid',
        'sender_signature', 'recipient_signature', 'signature_nonce',
        'time', 'memo', 'description',
        'initial_sender_address', 'end_recipient_address',
        'sending_started_at', 'recovery_count', 'needs_manual_review',
        'expires_at', 'signed_message_content',
    ];

    private const CHECKPOINT_TABLE = 'transaction_chain_checkpoints';

    // ------------------------------------------------------------------
    // Eligibility queries
    // ------------------------------------------------------------------

    /**
     * Find distinct bilateral pairs that have at least one completed
     * transaction older than the retention window. The archival service
     * iterates these pairs and evaluates chain integrity for each one
     * independently.
     *
     * A "bilateral pair" is identified by a {user, contact} pubkey-hash
     * tuple; we canonicalize as (LEAST, GREATEST) so the same pair isn't
     * returned twice for sender/receiver direction. Caller is responsible
     * for mapping hashes back to keys/addresses via the contacts table.
     *
     * Only transactions involving a contact the user has settled with are
     * candidates — we rely on status='completed' as the eligibility
     * filter (matches the gap-detection semantics in
     * TransactionChainRepository::verifyChainIntegrity's settled set).
     *
     * @return array{hash_a:string,hash_b:string}[]
     */
    /**
     * Return every distinct bilateral pair with at least one transaction in
     * live OR archive. Unlike findEligibleBilateralPairs(), this doesn't
     * filter on retention / status — used by `eiou verify-chain` to scan
     * the full set of chains on this node.
     *
     * @return array{hash_a:string,hash_b:string}[]
     */
    public function findAllBilateralPairs(int $pairLimit = 10000): array
    {
        $sqlParts = [
            "SELECT DISTINCT
                    LEAST(sender_public_key_hash, receiver_public_key_hash) AS hash_a,
                    GREATEST(sender_public_key_hash, receiver_public_key_hash) AS hash_b
             FROM transactions
             WHERE sender_public_key_hash IS NOT NULL
               AND receiver_public_key_hash IS NOT NULL
               AND sender_public_key_hash != receiver_public_key_hash"
        ];

        // Archive side — skip gracefully if the table is absent.
        try {
            $probe = $this->pdo->query("SELECT 1 FROM transactions_archive LIMIT 1");
            if ($probe !== false) {
                $sqlParts[] = "SELECT DISTINCT
                    LEAST(sender_public_key_hash, receiver_public_key_hash) AS hash_a,
                    GREATEST(sender_public_key_hash, receiver_public_key_hash) AS hash_b
                 FROM transactions_archive
                 WHERE sender_public_key_hash IS NOT NULL
                   AND receiver_public_key_hash IS NOT NULL
                   AND sender_public_key_hash != receiver_public_key_hash";
            }
        } catch (PDOException $e) {
            // Archive missing — live-only result is correct.
        }

        $unionSql = implode("\n UNION \n", $sqlParts)
                  . "\nORDER BY hash_a, hash_b\nLIMIT :limit";
        $stmt = $this->pdo->prepare($unionSql);
        $stmt->bindValue(':limit', max(1, $pairLimit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findEligibleBilateralPairs(int $retentionDays, int $pairLimit): array
    {
        // Age measured against the DB-assigned `timestamp` column (DATETIME(6))
        // rather than the integer `time` column. `time` on this codebase is
        // stored as 10x-microseconds since epoch (e.g. 17766918680650), not
        // plain epoch-seconds — a numeric < cutoff_in_seconds comparison would
        // never match. `timestamp` is always populated (`DEFAULT CURRENT_TIMESTAMP`
        // on the live table) and matches the "days ago" semantics directly.
        $sql = "SELECT DISTINCT
                    LEAST(sender_public_key_hash, receiver_public_key_hash) AS hash_a,
                    GREATEST(sender_public_key_hash, receiver_public_key_hash) AS hash_b
                FROM transactions
                WHERE status = 'completed'
                  AND sender_public_key_hash IS NOT NULL
                  AND receiver_public_key_hash IS NOT NULL
                  AND sender_public_key_hash != receiver_public_key_hash
                  AND timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY hash_a, hash_b
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', max(1, $retentionDays), PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $pairLimit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return live transaction IDs eligible for archival within a given
     * bilateral pair. Eligibility: status='completed' and older than the
     * retention window. Other statuses (pending, sending, etc.) are never
     * archived — only settled history.
     *
     * @return int[]
     */
    public function findEligibleLiveIdsForPair(string $hashA, string $hashB, int $retentionDays, int $limit): array
    {
        $sql = "SELECT id FROM transactions
                WHERE status = 'completed'
                  AND ((sender_public_key_hash = :hash_a AND receiver_public_key_hash = :hash_b)
                       OR (sender_public_key_hash = :hash_b2 AND receiver_public_key_hash = :hash_a2))
                  AND timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY id ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':hash_a', $hashA);
        $stmt->bindValue(':hash_b', $hashB);
        $stmt->bindValue(':hash_a2', $hashA);
        $stmt->bindValue(':hash_b2', $hashB);
        $stmt->bindValue(':days', max(1, $retentionDays), PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Fetch `txid` + `time` + `timestamp` for a set of live IDs. Used by
     * the archival service to compute the batch's highest-archived metadata
     * (for the checkpoint row) before the move actually happens.
     *
     * @param int[] $liveIds
     * @return array[] Each row: [txid, time, timestamp]
     */
    public function getTxidMetaForIds(array $liveIds): array
    {
        if ($liveIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($liveIds), '?'));
        $sql = "SELECT txid, time, timestamp
                FROM transactions
                WHERE id IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($liveIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Atomic batch move
    // ------------------------------------------------------------------

    /**
     * Atomically move a fixed set of live rows into the archive.
     *
     * Copies into `transactions_archive` (with `archived_at = NOW(6)`),
     * then deletes from `transactions`. Wrapped in a single transaction —
     * partial failure rolls back and the next cron run retries.
     *
     * Returns 0 on an insert/delete mismatch (a row was probably the
     * victim of a concurrent update — e.g. status changed out from under
     * us) so the caller can stop and let the next run retry rather than
     * hot-looping on the same IDs.
     *
     * @param int[] $liveIds
     * @return int Rows moved (0 on mismatch)
     */
    public function moveRows(array $liveIds): int
    {
        if ($liveIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($liveIds), '?'));
        $colList = implode(', ', self::COPY_COLUMNS);

        $this->pdo->beginTransaction();
        try {
            $insertSql = "INSERT INTO transactions_archive
                          ({$colList}, archived_at)
                          SELECT {$colList}, NOW(6)
                          FROM transactions
                          WHERE id IN ({$placeholders})";
            $insertStmt = $this->pdo->prepare($insertSql);
            $insertStmt->execute($liveIds);
            $movedIn = $insertStmt->rowCount();

            $deleteSql = "DELETE FROM transactions WHERE id IN ({$placeholders})";
            $deleteStmt = $this->pdo->prepare($deleteSql);
            $deleteStmt->execute($liveIds);
            $movedOut = $deleteStmt->rowCount();

            if ($movedIn !== $movedOut) {
                $this->pdo->rollBack();
                return 0;
            }

            $this->pdo->commit();
            return $movedIn;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Chain checkpoint CRUD
    // ------------------------------------------------------------------

    /**
     * Fetch the current checkpoint row for a bilateral pair, or null if
     * none exists yet. Pair hashes are passed in canonical order (LEAST,
     * GREATEST) so there's exactly one checkpoint per bilateral chain.
     */
    public function getCheckpoint(string $hashA, string $hashB): ?array
    {
        [$hashA, $hashB] = self::canonicalizePair($hashA, $hashB);
        $sql = "SELECT * FROM " . self::CHECKPOINT_TABLE . "
                WHERE user_public_key_hash = :a AND contact_public_key_hash = :b
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':a' => $hashA, ':b' => $hashB]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Upsert the checkpoint row for a bilateral pair. Called by the
     * archival service after a successful gap-free-verified batch move.
     *
     * Pair hashes are canonicalized internally so callers can pass them in
     * either order.
     *
     * @param string $hashA                     One side of the bilateral pair
     * @param string $hashB                     Other side
     * @param string $highestArchivedTimestamp  Newest `archived_at` under the checkpoint (DATETIME(6))
     * @param int|null $highestArchivedTime     Newest wallet `time` (epoch seconds, BIGINT) or null
     * @param int    $archivedCountAbsolute     Total rows in archive for this pair after the move
     * @param string $archivedTxidHash          SHA-256 over the sorted archived-txid list
     */
    public function upsertCheckpoint(
        string $hashA,
        string $hashB,
        string $highestArchivedTimestamp,
        ?int $highestArchivedTime,
        int $archivedCountAbsolute,
        string $archivedTxidHash
    ): void {
        [$hashA, $hashB] = self::canonicalizePair($hashA, $hashB);

        $sql = "INSERT INTO " . self::CHECKPOINT_TABLE . " (
                    user_public_key_hash, contact_public_key_hash,
                    highest_archived_timestamp, highest_archived_time,
                    archived_count, archived_txid_hash,
                    last_verified_gap_free_at
                ) VALUES (
                    :a, :b,
                    :hts, :htime,
                    :cnt, :hash,
                    NOW(6)
                )
                ON DUPLICATE KEY UPDATE
                    highest_archived_timestamp = VALUES(highest_archived_timestamp),
                    highest_archived_time      = VALUES(highest_archived_time),
                    archived_count             = VALUES(archived_count),
                    archived_txid_hash         = VALUES(archived_txid_hash),
                    last_verified_gap_free_at  = NOW(6)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':a'     => $hashA,
            ':b'     => $hashB,
            ':hts'   => $highestArchivedTimestamp,
            ':htime' => $highestArchivedTime,
            ':cnt'   => $archivedCountAbsolute,
            ':hash'  => $archivedTxidHash,
        ]);
    }

    /**
     * Return the newest `archived_at` + `time` for the pair's archive
     * rows, or null if none exist. Used by the archival service to
     * populate the checkpoint's highest-archived metadata after a move.
     *
     * @return array{highest_archived_timestamp:string,highest_archived_time:?int}|null
     */
    public function getArchiveHeadForPair(string $hashA, string $hashB): ?array
    {
        $sql = "SELECT archived_at AS highest_archived_timestamp,
                       `time`      AS highest_archived_time
                FROM {$this->tableName}
                WHERE (sender_public_key_hash = :a AND receiver_public_key_hash = :b)
                   OR (sender_public_key_hash = :b2 AND receiver_public_key_hash = :a2)
                ORDER BY archived_at DESC
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':a' => $hashA, ':b' => $hashB, ':a2' => $hashA, ':b2' => $hashB]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'highest_archived_timestamp' => (string) $row['highest_archived_timestamp'],
            'highest_archived_time'      => $row['highest_archived_time'] === null ? null : (int) $row['highest_archived_time'],
        ];
    }

    /**
     * Count archived rows for a given bilateral pair. Used after a move to
     * compute the checkpoint's `archived_count` absolute value.
     */
    public function countArchivedForPair(string $hashA, string $hashB): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName}
                WHERE (sender_public_key_hash = :a AND receiver_public_key_hash = :b)
                   OR (sender_public_key_hash = :b2 AND receiver_public_key_hash = :a2)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':a' => $hashA, ':b' => $hashB, ':a2' => $hashA, ':b2' => $hashB]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Compute a tamper-detection hash over every archived txid for a
     * given bilateral pair. Fetches sorted txids, concatenates with a
     * separator, and hashes. Used by the archival service to populate the
     * checkpoint's `archived_txid_hash` column after a move.
     *
     * The choice of a full recomputation (vs. an incremental hash-chain)
     * trades write cost for simplicity: each archival batch reads the
     * pair's archive rows once, which is dominated by the batch size
     * itself. Phase 2's verify path will recompute the same way and
     * compare.
     */
    public function computeArchivedTxidHash(string $hashA, string $hashB): string
    {
        $sql = "SELECT txid FROM {$this->tableName}
                WHERE (sender_public_key_hash = :a AND receiver_public_key_hash = :b)
                   OR (sender_public_key_hash = :b2 AND receiver_public_key_hash = :a2)
                ORDER BY txid ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':a' => $hashA, ':b' => $hashB, ':a2' => $hashA, ':b2' => $hashB]);
        $txids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($txids === []) {
            return hash('sha256', '');
        }
        return hash('sha256', implode("\n", $txids));
    }

    // ------------------------------------------------------------------
    // Operator visibility
    // ------------------------------------------------------------------

    /**
     * Look up a single archived transaction by its `txid`. Returns null if
     * not in the archive; callers should check live first.
     */
    public function getByTxid(string $txid): ?array
    {
        return $this->findByColumn('txid', $txid);
    }

    /**
     * Total archive row count — used for operator visibility / tests.
     */
    public function countAll(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$this->tableName}");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Most recent `archived_at` timestamp, or null if the archive is empty.
     * Cron reports this so operators can confirm the job actually ran.
     */
    public function getLatestArchivedAt(): ?string
    {
        $stmt = $this->pdo->query(
            "SELECT archived_at FROM {$this->tableName}
             ORDER BY archived_at DESC LIMIT 1"
        );
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /**
     * Canonicalize a bilateral pair to (LEAST, GREATEST) order. Ensures
     * {Alice, Bob} and {Bob, Alice} map to the same checkpoint row.
     *
     * @return array{0:string,1:string}
     */
    private static function canonicalizePair(string $x, string $y): array
    {
        return strcmp($x, $y) <= 0 ? [$x, $y] : [$y, $x];
    }
}
