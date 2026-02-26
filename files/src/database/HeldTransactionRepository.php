<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Core\Constants;
use Eiou\Database\Traits\QueryBuilder;
use Eiou\Utils\Logger;
use PDO;
use PDOException;

/**
 * HeldTransactionRepository
 *
 * Manages transactions that are held pending resynchronization completion.
 * When a transaction receives an "invalid previous txid" rejection, it is held
 * here while the contact's transaction history is resynced. Once sync completes,
 * held transactions are released for reprocessing.
 *
 * Table Structure:
 * - id: Auto-increment primary key
 * - contact_pubkey_hash: The contact whose transaction is held
 * - txid: The transaction ID being held
 * - original_previous_txid: The previous_txid that was rejected
 * - expected_previous_txid: The correct previous_txid (if known)
 * - transaction_type: Type of transaction (standard, p2p)
 * - hold_reason: Why the transaction was held (invalid_previous_txid, sync_in_progress)
 * - sync_status: Status of resync (not_started, in_progress, completed, failed)
 * - retry_count: Number of retry attempts
 * - max_retries: Maximum retry attempts before giving up
 * - held_at: When the transaction was first held
 * - last_sync_attempt: Last sync attempt timestamp
 * - next_retry_at: When to retry next
 * - resolved_at: When the sync was resolved
 *
 * Workflow:
 * 1. Transaction receives invalid previous txid rejection
 * 2. holdTransaction() stores it with sync_status='pending'
 * 3. markSyncStarted() updates to sync_status='in_progress'
 * 4. Resync process fetches and validates transaction history
 * 5. markSyncCompleted() updates to sync_status='completed'
 * 6. getTransactionsToResume() fetches completed transactions
 * 7. releaseTransaction() removes them after successful reprocessing
 */
class HeldTransactionRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'contact_pubkey_hash', 'txid', 'original_previous_txid',
        'expected_previous_txid', 'transaction_type', 'hold_reason',
        'sync_status', 'retry_count', 'max_retries', 'held_at',
        'last_sync_attempt', 'next_retry_at', 'resolved_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'held_transactions';
        $this->primaryKey = 'id';
    }

    /**
     * Hold a transaction pending resync completion
     *
     * Inserts a new held transaction record. If the transaction is already held,
     * this will fail due to unique constraint on txid.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @param string $txid Transaction ID to hold
     * @param string|null $originalPreviousTxid The previous_txid that was rejected
     * @param string|null $expectedPreviousTxid The correct previous_txid (if known)
     * @param string $transactionType Type of transaction (default: 'standard')
     * @return int|false Insert ID on success, false on failure
     */
    public function holdTransaction(
        string $contactPubkeyHash,
        string $txid,
        ?string $originalPreviousTxid,
        ?string $expectedPreviousTxid = null,
        string $transactionType = 'standard'
    ) {
        $data = [
            'contact_pubkey_hash' => $contactPubkeyHash,
            'txid' => $txid,
            'original_previous_txid' => $originalPreviousTxid,
            'expected_previous_txid' => $expectedPreviousTxid,
            'transaction_type' => $transactionType,
            'sync_status' => 'not_started',
            'retry_count' => 0,
            'max_retries' => 3
            // held_at uses DEFAULT CURRENT_TIMESTAMP
        ];

        $result = $this->insert($data);

        if ($result === false) {
            $this->logError("Failed to hold transaction: $txid for contact: $contactPubkeyHash");
            return false;
        }

        return (int)$result;
    }

    /**
     * Check if a contact has any held transactions
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return bool True if contact has held transactions
     */
    public function hasHeldTransactions(string $contactPubkeyHash): bool {
        return $this->exists('contact_pubkey_hash', $contactPubkeyHash);
    }

    /**
     * Get all held transactions for a contact
     *
     * Optionally filter by sync_status.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @param string|null $syncStatus Filter by sync status (pending, in_progress, completed, failed)
     * @return array Array of held transaction records
     */
    public function getHeldTransactionsForContact(string $contactPubkeyHash, ?string $syncStatus = null): array {
        if ($syncStatus === null) {
            return $this->findManyByColumn('contact_pubkey_hash', $contactPubkeyHash);
        }

        $query = "SELECT * FROM {$this->tableName}
                  WHERE contact_pubkey_hash = :contact_pubkey_hash
                  AND sync_status = :sync_status
                  ORDER BY held_at ASC";

        $stmt = $this->execute($query, [
            ':contact_pubkey_hash' => $contactPubkeyHash,
            ':sync_status' => $syncStatus
        ]);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a specific transaction is currently held
     *
     * @param string $txid Transaction ID
     * @return bool True if transaction is held
     */
    public function isTransactionHeld(string $txid): bool {
        return $this->exists('txid', $txid);
    }

    /**
     * Get a held transaction by its txid
     *
     * @param string $txid Transaction ID
     * @return array|null Held transaction record or null if not found
     */
    public function getByTxid(string $txid): ?array {
        return $this->findByColumn('txid', $txid);
    }

    /**
     * Update sync status for all held transactions of a contact
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @param string $status New sync status (pending, in_progress, completed, failed)
     * @return int Number of rows updated, -1 on error
     */
    public function updateSyncStatusForContact(string $contactPubkeyHash, string $status): int {
        $query = "UPDATE {$this->tableName}
                  SET sync_status = :status, last_sync_attempt = :last_sync_attempt
                  WHERE contact_pubkey_hash = :contact_pubkey_hash";

        $stmt = $this->execute($query, [
            ':status' => $status,
            ':last_sync_attempt' => date('Y-m-d H:i:s.u'),
            ':contact_pubkey_hash' => $contactPubkeyHash
        ]);

        if (!$stmt) {
            return -1;
        }

        return $stmt->rowCount();
    }

    /**
     * Mark all held transactions for a contact as ready to resume
     *
     * Sets sync_status to 'completed', indicating the resync has finished
     * and transactions are ready for reprocessing.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return int Number of rows updated, -1 on error
     */
    public function markReadyToResume(string $contactPubkeyHash): int {
        return $this->updateSyncStatusForContact($contactPubkeyHash, 'completed');
    }

    /**
     * Get transactions that are ready to resume (sync completed)
     *
     * Returns transactions with sync_status='completed', ordered by creation time.
     * These should be reprocessed and then released.
     *
     * @param int $limit Maximum number of transactions to return
     * @return array Array of held transaction records
     */
    public function getTransactionsToResume(int $limit = Constants::HELD_TX_BATCH_SIZE): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE sync_status = 'completed'
                  ORDER BY held_at ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to get transactions to resume", $e);
            return [];
        }
    }

    /**
     * Release a held transaction (delete it)
     *
     * Called after a held transaction has been successfully reprocessed.
     *
     * @param string $txid Transaction ID to release
     * @return bool True on success, false on failure
     */
    public function releaseTransaction(string $txid): bool {
        $result = $this->delete('txid', $txid);
        return $result > 0;
    }

    /**
     * Mark a held transaction as failed and release it
     *
     * Called when a held transaction cannot be processed (resign failed, resume failed, etc.)
     * This prevents infinite retry loops by removing the transaction from the held queue.
     *
     * @param string $txid Transaction ID to mark as failed
     * @param string $reason Reason for failure (for logging)
     * @return bool True on success, false on failure
     */
    public function markAsFailed(string $txid, string $reason): bool {
        // Update sync_status to 'failed' before deleting for audit trail
        $query = "UPDATE {$this->tableName}
                  SET sync_status = 'failed',
                      resolved_at = :resolved_at
                  WHERE txid = :txid";

        $stmt = $this->execute($query, [
            ':txid' => $txid,
            ':resolved_at' => date('Y-m-d H:i:s')
        ]);

        // Log the failure reason
        Logger::getInstance()->warning("Held transaction marked as failed and released", [
            'txid' => $txid,
            'reason' => $reason
        ]);

        // Delete the record to prevent future retry attempts
        $result = $this->delete('txid', $txid);
        return $result > 0;
    }

    /**
     * Release all held transactions for a contact
     *
     * Deletes all held transaction records for the specified contact.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return int Number of transactions released, -1 on error
     */
    public function releaseAllForContact(string $contactPubkeyHash): int {
        return $this->delete('contact_pubkey_hash', $contactPubkeyHash);
    }

    /**
     * Check if a sync is currently in progress for a contact
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return bool True if sync is in progress
     */
    public function isSyncInProgress(string $contactPubkeyHash): bool {
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                  WHERE contact_pubkey_hash = :contact_pubkey_hash
                  AND sync_status = 'in_progress'";

        $stmt = $this->execute($query, [':contact_pubkey_hash' => $contactPubkeyHash]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0) > 0;
    }

    /**
     * Mark sync as started for all pending transactions of a contact
     *
     * Updates sync_status from 'pending' to 'in_progress'.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return bool True if at least one row was updated
     */
    public function markSyncStarted(string $contactPubkeyHash): bool {
        $query = "UPDATE {$this->tableName}
                  SET sync_status = 'in_progress', last_sync_attempt = :last_sync_attempt
                  WHERE contact_pubkey_hash = :contact_pubkey_hash
                  AND sync_status = 'not_started'";

        $stmt = $this->execute($query, [
            ':last_sync_attempt' => date('Y-m-d H:i:s.u'),
            ':contact_pubkey_hash' => $contactPubkeyHash
        ]);

        if (!$stmt) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark sync as completed for all in-progress transactions of a contact
     *
     * Updates sync_status from 'in_progress' to 'completed'.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return bool True if at least one row was updated
     */
    public function markSyncCompleted(string $contactPubkeyHash): bool {
        $query = "UPDATE {$this->tableName}
                  SET sync_status = 'completed', resolved_at = :resolved_at
                  WHERE contact_pubkey_hash = :contact_pubkey_hash
                  AND sync_status IN ('in_progress', 'not_started')";

        $stmt = $this->execute($query, [
            ':resolved_at' => date('Y-m-d H:i:s.u'),
            ':contact_pubkey_hash' => $contactPubkeyHash
        ]);

        if (!$stmt) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark sync as failed for all in-progress transactions of a contact
     *
     * Updates sync_status from 'in_progress' to 'failed'.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return bool True if at least one row was updated
     */
    public function markSyncFailed(string $contactPubkeyHash): bool {
        $query = "UPDATE {$this->tableName}
                  SET sync_status = 'failed', last_sync_attempt = :last_sync_attempt
                  WHERE contact_pubkey_hash = :contact_pubkey_hash
                  AND sync_status = 'in_progress'";

        $stmt = $this->execute($query, [
            ':last_sync_attempt' => date('Y-m-d H:i:s.u'),
            ':contact_pubkey_hash' => $contactPubkeyHash
        ]);

        if (!$stmt) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Increment retry count for a held transaction
     *
     * @param string $txid Transaction ID
     * @return bool True on success, false on failure
     */
    public function incrementRetry(string $txid): bool {
        $query = "UPDATE {$this->tableName}
                  SET retry_count = retry_count + 1, last_sync_attempt = :last_sync_attempt
                  WHERE txid = :txid";

        $stmt = $this->execute($query, [
            ':last_sync_attempt' => date('Y-m-d H:i:s.u'),
            ':txid' => $txid
        ]);

        if (!$stmt) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Get transactions that have exhausted their retry attempts
     *
     * Returns transactions where retry_count >= max_retries.
     * These may need manual intervention or permanent rejection.
     *
     * @param int $limit Maximum number of transactions to return
     * @return array Array of held transaction records
     */
    public function getExhaustedRetries(int $limit = Constants::HELD_TX_EXHAUSTED_BATCH_SIZE): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE retry_count >= max_retries
                  ORDER BY held_at ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to get exhausted retry transactions", $e);
            return [];
        }
    }

    /**
     * Clean up old resolved or failed records
     *
     * Deletes held transaction records that are older than the specified number
     * of days and have sync_status of 'completed' or 'failed'.
     *
     * @param int $days Number of days to keep records (default: 7)
     * @return int Number of records deleted, -1 on error
     */
    public function cleanupOldRecords(int $days = Constants::CLEANUP_HELD_TX_RETENTION_DAYS): int {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $query = "DELETE FROM {$this->tableName}
                  WHERE held_at < :cutoff_date
                  AND sync_status IN ('completed', 'failed')";

        $stmt = $this->execute($query, [':cutoff_date' => $cutoffDate]);

        if (!$stmt) {
            return -1;
        }

        return $stmt->rowCount();
    }

    /**
     * Timeout stale in_progress sync records
     *
     * Transitions held transactions stuck in 'in_progress' (or 'not_started') longer than
     * the timeout to 'failed'. This prevents held transactions from being stuck forever
     * if sync never completes (e.g., contact goes permanently offline).
     *
     * @param int $timeoutSeconds Seconds before an in_progress record is considered stale
     * @return int Number of records timed out, -1 on error
     */
    public function timeoutStaleSyncs(int $timeoutSeconds = Constants::HELD_TX_SYNC_TIMEOUT_SECONDS): int {
        $cutoffTime = date('Y-m-d H:i:s', time() - $timeoutSeconds);

        $query = "UPDATE {$this->tableName}
                  SET sync_status = 'failed',
                      resolved_at = :resolved_at
                  WHERE sync_status IN ('in_progress', 'not_started')
                  AND held_at < :cutoff_time";

        $stmt = $this->execute($query, [
            ':resolved_at' => date('Y-m-d H:i:s'),
            ':cutoff_time' => $cutoffTime
        ]);

        if (!$stmt) {
            return -1;
        }

        $count = $stmt->rowCount();
        if ($count > 0) {
            Logger::getInstance()->warning("Timed out stale in_progress held transactions", [
                'count' => $count,
                'timeout_seconds' => $timeoutSeconds
            ]);
        }

        return $count;
    }

    /**
     * Get all held transactions
     *
     * Used for statistics and monitoring.
     *
     * @param int $limit Maximum number of records (0 = no limit)
     * @return array All held transaction records
     */
    public function getAll(int $limit = 0): array {
        return $this->findAll($limit);
    }
}
