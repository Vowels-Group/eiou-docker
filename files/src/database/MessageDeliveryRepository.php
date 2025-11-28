<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Message Delivery Repository
 *
 * Manages message delivery tracking, multi-stage acknowledgments, and retry logic.
 * Supports the Transaction Reliability & Message Handling System (Issue #139).
 *
 * @package Database\Repository
 */
class MessageDeliveryRepository extends AbstractRepository {
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
     * @return int|false Insert ID or false on failure
     */
    public function createDelivery(
        string $messageType,
        string $messageId,
        string $recipientAddress,
        string $stage = 'pending',
        int $maxRetries = 5
    ) {
        $data = [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'recipient_address' => $recipientAddress,
            'delivery_stage' => $stage,
            'max_retries' => $maxRetries,
            'retry_count' => 0
        ];

        return $this->insert($data);
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
     * Get messages ready for retry
     *
     * @param int $limit Maximum number of messages to return
     * @return array Array of delivery records ready for retry
     */
    public function getMessagesForRetry(int $limit = 10): array {
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
    public function getExhaustedRetries(int $limit = 10): array {
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
        return $this->updateStage($messageType, $messageId, 'failed', $reason);
    }

    /**
     * Mark delivery as completed
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool Success status
     */
    public function markCompleted(string $messageType, string $messageId): bool {
        return $this->updateStage($messageType, $messageId, 'completed');
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
     * Delete old completed/failed records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = 30): int {
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
