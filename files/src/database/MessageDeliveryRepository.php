<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Utils\Logger;
use PDO;
use PDOException;

/**
 * Message Delivery Repository
 *
 * Manages message delivery tracking, multi-stage acknowledgments, and retry logic.
 * Supports the Transaction Reliability & Message Handling System.
 *
 * @package Database\Repository
 */
class MessageDeliveryRepository extends AbstractRepository {
    use QueryBuilder;
    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'message_type', 'message_id', 'recipient_address', 'payload',
        'delivery_stage', 'retry_count', 'max_retries', 'next_retry_at',
        'last_error', 'last_response', 'created_at', 'updated_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'message_delivery';
        $this->primaryKey = 'id';
    }

    /**
     * Create or update a delivery tracking record
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Unique identifier (txid, hash, etc.)
     * @param string $recipientAddress Recipient's address
     * @param string $stage Initial delivery stage
     * @param int $maxRetries Maximum retry attempts
     * @param array|null $payload Optional payload to store for retry attempts
     * @return int|false Insert ID or false on failure
     */
    public function createDelivery(
        string $messageType,
        string $messageId,
        string $recipientAddress,
        string $stage = Constants::DELIVERY_PENDING,
        int $maxRetries = 5,
        ?array $payload = null
    ) {
        $data = [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'recipient_address' => $recipientAddress,
            'delivery_stage' => $stage,
            'max_retries' => $maxRetries,
            'retry_count' => 0
        ];

        // Store payload as JSON for retry attempts
        if ($payload !== null) {
            $data['payload'] = json_encode($payload);
        }

        return $this->insert($data);
    }

    /**
     * Update the payload for an existing delivery record
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param array $payload Payload to store
     * @return bool Success status
     */
    public function updatePayload(string $messageType, string $messageId, array $payload): bool {
        $query = "UPDATE {$this->tableName}
                  SET payload = :payload,
                      updated_at = CURRENT_TIMESTAMP(6)
                  WHERE message_type = :type AND message_id = :id";

        $stmt = $this->execute($query, [
            ':payload' => json_encode($payload),
            ':type' => $messageType,
            ':id' => $messageId
        ]);

        return $stmt !== false;
    }

    /**
     * Get the stored payload for a delivery record
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return array|null Decoded payload or null
     */
    public function getPayload(string $messageType, string $messageId): ?array {
        $delivery = $this->getByMessage($messageType, $messageId);

        if (!$delivery || empty($delivery['payload'])) {
            return null;
        }

        $payload = json_decode($delivery['payload'], true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Get delivery record by message type and ID
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return array|null Delivery record or null
     */
    public function getByMessage(string $messageType, string $messageId): ?array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE message_type = :type AND message_id = :id
                  LIMIT 1";
        $stmt = $this->execute($query, [':type' => $messageType, ':id' => $messageId]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Update delivery stage
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $stage New delivery stage
     * @param string|null $response Last response received
     * @return bool Success status
     */
    public function updateStage(
        string $messageType,
        string $messageId,
        string $stage,
        ?string $response = null
    ): bool {
        $query = "UPDATE {$this->tableName}
                  SET delivery_stage = :stage,
                      last_response = :response,
                      updated_at = CURRENT_TIMESTAMP(6)
                  WHERE message_type = :type AND message_id = :id";

        $stmt = $this->execute($query, [
            ':stage' => $stage,
            ':response' => $response,
            ':type' => $messageType,
            ':id' => $messageId
        ]);

        return $stmt !== false;
    }

    /**
     * Increment retry count and schedule next retry
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param int $delaySeconds Delay before next retry
     * @param string|null $lastError Error message from last attempt
     * @return bool Success status
     */
    public function incrementRetry(
        string $messageType,
        string $messageId,
        int $delaySeconds,
        ?string $lastError = null
    ): bool {
        $query = "UPDATE {$this->tableName}
                  SET retry_count = retry_count + 1,
                      next_retry_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL :delay SECOND),
                      last_error = :error,
                      updated_at = CURRENT_TIMESTAMP(6)
                  WHERE message_type = :type AND message_id = :id";

        $stmt = $this->execute($query, [
            ':delay' => $delaySeconds,
            ':error' => $lastError,
            ':type' => $messageType,
            ':id' => $messageId
        ]);

        return $stmt !== false;
    }

    /**
     * Lock a message against processRetryQueue for the entire sync retry process.
     *
     * Sets next_retry_at unconditionally so the message is invisible to
     * processRetryQueue's eligible-message query for $seconds seconds.
     * Called by sendWithTracking() before entering attemptDeliveryWithRetries()
     * to cover the initial next_retry_at = NULL window that exists right after
     * createDelivery(). The incrementRetry() calls inside the retry loop then
     * refresh the lock with per-attempt delays + a delivery buffer.
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param int $seconds Lock duration in seconds
     * @return bool Success
     */
    public function lockForProcessing(string $messageType, string $messageId, int $seconds = 300): bool {
        $query = "UPDATE {$this->tableName}
                  SET next_retry_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL :seconds SECOND)
                  WHERE message_type = :type AND message_id = :id";

        $stmt = $this->execute($query, [
            ':seconds' => $seconds,
            ':type' => $messageType,
            ':id' => $messageId
        ]);
        return $stmt !== false;
    }

    /**
     * Atomically claim a message for retry processing.
     *
     * Uses a conditional UPDATE so only one concurrent worker can claim a given
     * message. Returns true only when this caller wins the race (rowCount = 1).
     * The next_retry_at is pushed 300 s forward as a processing lock; normal
     * incrementRetry() calls will overwrite it as each attempt completes.
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool True if this caller successfully claimed the message
     */
    public function claimForRetry(string $messageType, string $messageId): bool {
        $query = "UPDATE {$this->tableName}
                  SET next_retry_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL 300 SECOND)
                  WHERE message_type = :type
                    AND message_id = :id
                    AND delivery_stage IN ('pending', 'sent')
                    AND retry_count < max_retries
                    AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP(6))";

        $stmt = $this->execute($query, [':type' => $messageType, ':id' => $messageId]);
        return $stmt !== false && $stmt->rowCount() > 0;
    }

    /**
     * Get messages ready for retry
     *
     * @param int $limit Maximum number of messages to return
     * @return array Array of delivery records ready for retry
     */
    public function getMessagesForRetry(int $limit = Constants::DELIVERY_RETRY_BATCH_SIZE): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE delivery_stage IN ('pending', 'sent')
                    AND retry_count < max_retries
                    AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP(6))
                  ORDER BY created_at ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to get messages for retry", $e);
            return [];
        }
    }

    /**
     * Get messages that have exceeded max retries
     *
     * @param int $limit Maximum number of messages
     * @return array Array of failed delivery records
     */
    public function getExhaustedRetries(int $limit = Constants::DELIVERY_EXHAUSTED_BATCH_SIZE): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE delivery_stage NOT IN ('completed', 'failed')
                    AND retry_count >= max_retries
                  ORDER BY updated_at ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to get exhausted retries", $e);
            return [];
        }
    }

    /**
     * Mark delivery as failed
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $reason Failure reason
     * @return bool Success status
     */
    public function markFailed(string $messageType, string $messageId, string $reason): bool {
        return $this->updateStage($messageType, $messageId, Constants::DELIVERY_FAILED, $reason);
    }

    /**
     * Mark delivery as completed
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool Success status
     */
    public function markCompleted(string $messageType, string $messageId): bool {
        return $this->updateStage($messageType, $messageId, Constants::DELIVERY_COMPLETED);
    }

    /**
     * Check if delivery exists
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool True if exists
     */
    public function deliveryExists(string $messageType, string $messageId): bool {
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                  WHERE message_type = :type AND message_id = :id";
        $stmt = $this->execute($query, [':type' => $messageType, ':id' => $messageId]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0) > 0;
    }

    /**
     * Get delivery statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        $query = "SELECT
                    COUNT(*) as total_count,
                    SUM(CASE WHEN delivery_stage = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN delivery_stage = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN delivery_stage IN ('pending', 'sent') THEN 1 ELSE 0 END) as pending_count,
                    AVG(retry_count) as avg_retries,
                    MAX(retry_count) as max_retries_used
                  FROM {$this->tableName}";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get statistics by message type
     *
     * @param string $messageType Type of message
     * @return array Statistics array
     */
    public function getStatisticsByType(string $messageType): array {
        $query = "SELECT
                    COUNT(*) as total_count,
                    SUM(CASE WHEN delivery_stage = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN delivery_stage = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN delivery_stage IN ('pending', 'sent') THEN 1 ELSE 0 END) as pending_count,
                    AVG(retry_count) as avg_retries
                  FROM {$this->tableName}
                  WHERE message_type = :type";

        $stmt = $this->execute($query, [':type' => $messageType]);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark all deliveries matching a hash pattern as completed
     *
     * Used to mark all P2P broadcast deliveries as completed when the
     * transaction completes. P2P messages use IDs like:
     * - direct-{hash}
     * - broadcast-{hash}-{contactHash}
     *
     * @param string $messageType Type of message ('p2p' or 'rp2p')
     * @param string $hash The P2P hash to match (will match message_id LIKE '%{hash}%')
     * @return int Number of updated records
     */
    public function markCompletedByHash(string $messageType, string $hash): int {
        $query = "UPDATE {$this->tableName}
                  SET delivery_stage = :completed_stage,
                      updated_at = NOW()
                  WHERE message_type = :type
                    AND message_id LIKE :pattern
                    AND delivery_stage NOT IN (:exclude_completed, :exclude_failed)";

        $stmt = $this->pdo->prepare($query);
        $pattern = '%' . $hash . '%';
        $stmt->bindValue(':completed_stage', Constants::DELIVERY_COMPLETED, PDO::PARAM_STR);
        $stmt->bindValue(':type', $messageType, PDO::PARAM_STR);
        $stmt->bindValue(':pattern', $pattern, PDO::PARAM_STR);
        $stmt->bindValue(':exclude_completed', Constants::DELIVERY_COMPLETED, PDO::PARAM_STR);
        $stmt->bindValue(':exclude_failed', Constants::DELIVERY_FAILED, PDO::PARAM_STR);

        try {
            $stmt->execute();
            $count = $stmt->rowCount();

            if ($count > 0 && class_exists('Eiou\\Utils\\Logger')) {
                Logger::getInstance()->info("Marked P2P deliveries as completed by hash", [
                    'message_type' => $messageType,
                    'hash' => $hash,
                    'updated_count' => $count
                ]);
            }

            return $count;
        } catch (PDOException $e) {
            $this->logError("Failed to mark deliveries completed by hash", $e);
            return 0;
        }
    }

    /**
     * Delete old completed/failed records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = Constants::CLEANUP_DELIVERY_RETENTION_DAYS): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE delivery_stage IN ('completed', 'failed')
                    AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old delivery records", $e);
            return 0;
        }
    }
}
