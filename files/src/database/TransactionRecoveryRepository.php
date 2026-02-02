<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Utils\SecureLogger;
use PDO;
use PDOException;

/**
 * Transaction Recovery Repository
 *
 * Manages transaction recovery and processing operations.
 * Extracted from TransactionRepository for better separation of concerns.
 *
 * Handles:
 * - Pending transaction retrieval and claiming
 * - Stuck transaction detection and recovery
 * - Transaction status transitions (pending -> sending -> sent)
 * - Manual review flagging
 *
 * @package Database\Repository
 */
class TransactionRecoveryRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'tx_type', 'type', 'status', 'sender_address', 'sender_public_key',
        'sender_public_key_hash', 'receiver_address', 'receiver_public_key',
        'receiver_public_key_hash', 'amount', 'currency', 'timestamp', 'txid',
        'previous_txid', 'sender_signature', 'recipient_signature', 'signature_nonce',
        'time', 'memo', 'description', 'initial_sender_address', 'end_recipient_address',
        'sending_started_at', 'recovery_count', 'needs_manual_review'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'transactions';
        $this->primaryKey = 'id';
    }

    /**
     * Retrieve pending transaction messages
     *
     * @param int $limit Maximum number of messages to retrieve
     * @return array Array of pending transactions
     */
    public function getPendingTransactions(int $limit = 5): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE status = 'pending'
                  ORDER BY timestamp ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve pending transactions", $e);
            return [];
        }
    }

    /**
     * Atomically claim a pending transaction for processing
     *
     * This method uses an atomic UPDATE with WHERE clause to change status
     * from 'pending' to 'sending'. Only succeeds if the transaction is still
     * in 'pending' status, preventing duplicate processing by multiple workers.
     *
     * The sending_started_at timestamp is set to track how long the transaction
     * has been in 'sending' status for recovery purposes.
     *
     * @param string $txid Transaction ID to claim
     * @return bool True if claim was successful, false if already claimed or not found
     */
    public function claimPendingTransaction(string $txid): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = :new_status,
                      sending_started_at = :started_at
                  WHERE txid = :txid
                  AND status = :current_status";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':new_status', Constants::STATUS_SENDING);
        $stmt->bindValue(':started_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':txid', $txid);
        $stmt->bindValue(':current_status', Constants::STATUS_PENDING);

        try {
            $stmt->execute();
            $claimed = $stmt->rowCount() > 0;

            if ($claimed && function_exists('output')) {
                output("Transaction {$txid} claimed for processing (pending -> sending)", 'SILENT');
            }

            return $claimed;
        } catch (PDOException $e) {
            $this->logError("Failed to claim pending transaction", $e);
            return false;
        }
    }

    /**
     * Get transactions stuck in 'sending' status that need recovery
     *
     * Returns transactions that have been in 'sending' status longer than
     * the configured timeout, indicating the processor may have crashed
     * while processing them.
     *
     * @param int $timeoutSeconds Timeout in seconds (default from Constants)
     * @return array Array of stuck transactions
     */
    public function getStuckSendingTransactions(int $timeoutSeconds = 0): array {
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = Constants::RECOVERY_SENDING_TIMEOUT_SECONDS;
        }

        $cutoffTime = date('Y-m-d H:i:s', time() - $timeoutSeconds);

        $query = "SELECT * FROM {$this->tableName}
                  WHERE status = :status
                  AND sending_started_at IS NOT NULL
                  AND sending_started_at < :cutoff_time
                  ORDER BY sending_started_at ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':status', Constants::STATUS_SENDING);
        $stmt->bindValue(':cutoff_time', $cutoffTime);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve stuck sending transactions", $e);
            return [];
        }
    }

    /**
     * Recover a stuck transaction by resetting it to pending
     *
     * Increments the recovery_count and resets status to 'pending' for retry.
     * If recovery_count exceeds max retries, marks transaction for manual review.
     *
     * @param string $txid Transaction ID to recover
     * @param int $maxRetries Maximum recovery attempts before manual review
     * @return array Result with 'recovered' (bool), 'needs_review' (bool), 'recovery_count' (int)
     */
    public function recoverStuckTransaction(string $txid, int $maxRetries = 0): array {
        if ($maxRetries <= 0) {
            $maxRetries = Constants::RECOVERY_MAX_RETRY_COUNT;
        }

        // First, get current recovery count
        $query = "SELECT recovery_count FROM {$this->tableName} WHERE txid = :txid";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':txid', $txid);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return ['recovered' => false, 'needs_review' => false, 'recovery_count' => 0];
        }

        $currentCount = (int)($result['recovery_count'] ?? 0);
        $newCount = $currentCount + 1;

        if ($newCount > $maxRetries) {
            // Mark for manual review
            $updateQuery = "UPDATE {$this->tableName}
                           SET status = :status,
                               recovery_count = :count,
                               sending_started_at = NULL,
                               needs_manual_review = 1
                           WHERE txid = :txid";

            $updateStmt = $this->pdo->prepare($updateQuery);
            $updateStmt->bindValue(':status', Constants::STATUS_FAILED);
            $updateStmt->bindValue(':count', $newCount);
            $updateStmt->bindValue(':txid', $txid);
            $updateStmt->execute();

            SecureLogger::warning("Transaction exceeded max recovery attempts, marked for manual review", [
                'txid' => $txid,
                'recovery_count' => $newCount,
                'max_retries' => $maxRetries
            ]);

            return ['recovered' => false, 'needs_review' => true, 'recovery_count' => $newCount];
        }

        // Reset to pending for retry
        $updateQuery = "UPDATE {$this->tableName}
                       SET status = :status,
                           recovery_count = :count,
                           sending_started_at = NULL
                       WHERE txid = :txid
                       AND status = :current_status";

        $updateStmt = $this->pdo->prepare($updateQuery);
        $updateStmt->bindValue(':status', Constants::STATUS_PENDING);
        $updateStmt->bindValue(':count', $newCount);
        $updateStmt->bindValue(':txid', $txid);
        $updateStmt->bindValue(':current_status', Constants::STATUS_SENDING);
        $updateStmt->execute();

        $recovered = $updateStmt->rowCount() > 0;

        if ($recovered) {
            SecureLogger::info("Transaction recovered from stuck sending state", [
                'txid' => $txid,
                'recovery_count' => $newCount
            ]);
        }

        return ['recovered' => $recovered, 'needs_review' => false, 'recovery_count' => $newCount];
    }

    /**
     * Get transactions marked for manual review
     *
     * @return array Array of transactions needing manual review
     */
    public function getTransactionsNeedingReview(): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE needs_manual_review = 1
                  ORDER BY timestamp ASC";

        $stmt = $this->pdo->prepare($query);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve transactions needing review", $e);
            return [];
        }
    }

    /**
     * Mark transaction as successfully sent (sending -> sent)
     *
     * Atomically updates status from 'sending' to 'sent', clearing the
     * sending_started_at timestamp.
     *
     * @param string $txid Transaction ID
     * @return bool True if update was successful
     */
    public function markAsSent(string $txid): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = :new_status,
                      sending_started_at = NULL
                  WHERE txid = :txid
                  AND status = :current_status";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':new_status', Constants::STATUS_SENT);
        $stmt->bindValue(':txid', $txid);
        $stmt->bindValue(':current_status', Constants::STATUS_SENDING);

        try {
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Failed to mark transaction as sent", $e);
            return false;
        }
    }

    /**
     * Get transactions that are in progress (not completed/rejected/cancelled)
     * Returns transactions with status: pending, sent, accepted (but not yet confirmed)
     * Also includes P2P route discovery requests sent by user that are not expired
     *
     * @param int $limit Maximum number of transactions to retrieve
     * @return array Array of in-progress transactions
     */
    public function getInProgressTransactions(int $limit = 10): array {
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = $this->createPlaceholders($userAddresses);

        // Current microtime for expiration check (P2P expiration is stored as microtime * TIME_MICROSECONDS_TO_INT)
        $currentMicrotime = (int)(microtime(true) * Constants::TIME_MICROSECONDS_TO_INT);

        // Check if held_transactions table exists (may not exist in older databases)
        $heldTableExists = false;
        try {
            $checkStmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='held_transactions'");
            $heldTableExists = ($checkStmt && $checkStmt->fetch() !== false);
        } catch (PDOException $e) {
            // Table check failed, assume table doesn't exist
            $heldTableExists = false;
        }

        // Query combines:
        // 1. Regular in-progress transactions (pending, sent, accepted) where user is sender
        // 2. P2P route requests sent by user (destination_address NOT NULL) that are in route search phase
        //    Note: 'paid' status is excluded as the transaction has moved to regular transaction flow
        // 3. Held transactions (on hold pending chain sync) - shown with 'syncing' phase (if table exists)

        // Build base query for regular transactions
        $heldExclusion = $heldTableExists ? "AND txid NOT IN (SELECT txid FROM held_transactions)" : "";

        $query = "SELECT
                    txid,
                    tx_type,
                    status,
                    sender_address,
                    receiver_address,
                    amount,
                    currency,
                    memo,
                    timestamp,
                    CASE WHEN tx_type = 'p2p' THEN 'p2p_request' ELSE 'transaction' END as source_type,
                    NULL as destination_address,
                    NULL as fee_amount,
                    CASE
                        WHEN status = 'pending' THEN 'pending'
                        WHEN status = 'sent' THEN 'sending'
                        WHEN status = 'accepted' THEN 'sending'
                        ELSE 'pending'
                    END as phase,
                    0 as is_held
                  FROM {$this->tableName}
                  WHERE status IN ('pending', 'sent', 'accepted')
                    AND sender_address IN ($placeholders)
                    AND tx_type != 'contact'
                    $heldExclusion";

        // Add held transactions query if table exists
        if ($heldTableExists) {
            $query .= "

                  UNION ALL

                  SELECT
                    t.txid,
                    t.tx_type,
                    t.status,
                    t.sender_address,
                    t.receiver_address,
                    t.amount,
                    t.currency,
                    t.memo,
                    t.timestamp,
                    CASE WHEN t.tx_type = 'p2p' THEN 'p2p_request' ELSE 'transaction' END as source_type,
                    NULL as destination_address,
                    NULL as fee_amount,
                    'syncing' as phase,
                    1 as is_held
                  FROM {$this->tableName} t
                  INNER JOIN held_transactions ht ON t.txid = ht.txid
                  WHERE t.sender_address IN ($placeholders)
                    AND t.tx_type != 'contact'";
        }

        // Add P2P query
        $query .= "

                  UNION ALL

                  SELECT
                    hash as txid,
                    'p2p' as tx_type,
                    status,
                    sender_address,
                    destination_address as receiver_address,
                    amount,
                    currency,
                    hash as memo,
                    created_at as timestamp,
                    'p2p_request' as source_type,
                    destination_address,
                    my_fee_amount as fee_amount,
                    CASE
                        WHEN status IN ('initial', 'queued') THEN 'pending'
                        WHEN status = 'sent' THEN 'route_search'
                        WHEN status = 'found' THEN 'route_found'
                        ELSE 'pending'
                    END as phase,
                    0 as is_held
                  FROM p2p
                  WHERE destination_address IS NOT NULL
                    AND status NOT IN ('completed', 'expired', 'cancelled', 'paid')
                    AND expiration > ?

                  ORDER BY timestamp DESC
                  LIMIT ?";

        // Build params based on whether held_transactions table exists
        if ($heldTableExists) {
            // user addresses for regular transactions, user addresses for held transactions,
            // current time for p2p expiration, limit
            $params = array_merge($userAddresses, $userAddresses, [$currentMicrotime, $limit]);
        } else {
            // user addresses for regular transactions, current time for p2p expiration, limit
            $params = array_merge($userAddresses, [$currentMicrotime, $limit]);
        }
        $stmt = $this->pdo->prepare($query);

        try {
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve in-progress transactions", $e);
            return [];
        }
    }
}
