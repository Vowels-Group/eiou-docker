<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;
use PDOException;

/**
 * Payment Request Archive Repository
 *
 * Read + batch-move operations for `payment_requests_archive`. Writes only
 * happen via `moveResolvedOlderThan()` from the archival cron — there is no
 * path for normal request flow to insert directly into the archive.
 *
 * Audit/history callers do NOT use this class directly. `PaymentRequestRepository`
 * transparently UNIONs live + archive for paginated history and search.
 */
class PaymentRequestArchiveRepository extends AbstractRepository
{
    protected $tableName = 'payment_requests_archive';
    protected array $allowedColumns = [
        'id', 'request_id', 'direction', 'status',
        'requester_pubkey_hash', 'requester_address', 'contact_name',
        'recipient_pubkey_hash',
        'amount_whole', 'amount_frac', 'currency', 'description',
        'created_at', 'expires_at', 'responded_at',
        'resulting_txid', 'signed_message_content', 'archived_at',
    ];
    protected array $splitAmountColumns = ['amount'];

    private const COPY_COLUMNS = [
        'request_id', 'direction', 'status',
        'requester_pubkey_hash', 'requester_address', 'contact_name',
        'recipient_pubkey_hash',
        'amount_whole', 'amount_frac', 'currency', 'description',
        'created_at', 'expires_at', 'responded_at',
        'resulting_txid', 'signed_message_content',
    ];

    /**
     * Find eligible rows to archive: non-pending, resolved (or created, for
     * expired rows where responded_at may be NULL) before now - $retentionDays.
     *
     * Returns an array of live `payment_requests.id` values, ascending.
     *
     * @param int $retentionDays Age threshold in days
     * @param int $limit         Batch size cap
     * @return int[]
     */
    public function findEligibleLiveIds(int $retentionDays, int $limit): array
    {
        $sql = "SELECT id FROM payment_requests
                WHERE status != 'pending'
                  AND COALESCE(responded_at, created_at) < DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY id ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', max(1, $retentionDays), PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Atomically move a fixed set of live rows into the archive.
     *
     * Copies the specified live rows into `payment_requests_archive` (with
     * `archived_at = NOW(6)`), then deletes them from `payment_requests`.
     * Wrapped in a single transaction — partial failure rolls back and the
     * next cron run retries the same rows.
     *
     * @param int[] $liveIds  IDs from `findEligibleLiveIds()`
     * @return int Number of rows moved (0 if $liveIds is empty or move failed)
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
            $insertSql = "INSERT INTO payment_requests_archive
                          ({$colList}, archived_at)
                          SELECT {$colList}, NOW(6)
                          FROM payment_requests
                          WHERE id IN ({$placeholders})";
            $insertStmt = $this->pdo->prepare($insertSql);
            $insertStmt->execute($liveIds);
            $movedIn = $insertStmt->rowCount();

            $deleteSql = "DELETE FROM payment_requests WHERE id IN ({$placeholders})";
            $deleteStmt = $this->pdo->prepare($deleteSql);
            $deleteStmt->execute($liveIds);
            $movedOut = $deleteStmt->rowCount();

            // Guard: any mismatch means we'd leave the tables inconsistent.
            // Roll back and let the next run retry — the individual row is
            // probably the victim of a concurrent update (e.g. pending -> cancelled
            // racing with the job).
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

    /**
     * Look up a single archived request by its `request_id`. Returns null if
     * not in the archive; callers should check live first.
     */
    public function getByRequestId(string $requestId): ?array
    {
        return $this->findByColumn('request_id', $requestId);
    }

    /**
     * Row count in the archive — used for operator visibility / tests.
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
}
