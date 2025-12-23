<?php
# Copyright 2025 The Vowels Company

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Dead Letter Queue Repository
 *
 * Manages failed messages that could not be delivered after all retry attempts.
 * Provides manual review and reprocessing capabilities.
 *
 * @package Database\Repository
 */
class DeadLetterQueueRepository extends AbstractRepository {
    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'dead_letter_queue';
        $this->primaryKey = 'id';
    }

    /**
     * Add a failed message to the dead letter queue
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Message identifier (txid, hash, etc.) - matches message_delivery.message_id
     * @param array $payload The full message payload
     * @param string $recipientAddress Recipient's address
     * @param int $retryCount Number of retry attempts made
     * @param string $failureReason Reason for failure
     * @return int|false Insert ID or false on failure
     */
    public function addToQueue(
        string $messageType,
        string $messageId,
        array $payload,
        string $recipientAddress,
        int $retryCount,
        string $failureReason
    ) {
        // Use DateTime for proper microsecond support (date() doesn't support .u)
        $now = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        $timestamp = $now ? $now->format('Y-m-d H:i:s.u') : date('Y-m-d H:i:s') . '.000000';

        $data = [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'payload' => json_encode($payload),
            'recipient_address' => $recipientAddress,
            'retry_count' => $retryCount,
            'last_retry_at' => $timestamp,
            'failure_reason' => $failureReason,
            'status' => 'pending'
        ];

        $result = $this->insert($data);

        $this->log('warning', "Message added to Dead Letter Queue", [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'recipient' => $recipientAddress,
            'failure_reason' => $failureReason
        ]);

        return $result;
    }

    /**
     * Log a message using SecureLogger if available
     *
     * @param string $level Log level (info, warning, error)
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void {
        if (class_exists('SecureLogger')) {
            SecureLogger::$level($message, $context);
        }
    }

    /**
     * Get all pending items in the queue
     *
     * @param int $limit Maximum number of items
     * @return array Array of DLQ records
     */
    public function getPendingItems(int $limit = 50): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE status = 'pending'
                  ORDER BY created_at ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->decodeJsonFields($results, 'payload');
        } catch (PDOException $e) {
            $this->logError("Failed to get pending DLQ items", $e);
            return [];
        }
    }

    /**
     * Get item by ID
     *
     * @param int $id DLQ item ID
     * @return array|null Item data or null
     */
    public function getById(int $id): ?array {
        $result = $this->findById($id);

        if ($result) {
            $this->decodeJsonFields($result, 'payload');
        }

        return $result;
    }

    /**
     * Get items by message type
     *
     * @param string $messageType Type of message
     * @param string|null $status Optional status filter
     * @param int $limit Maximum number of items
     * @return array Array of DLQ records
     */
    public function getByMessageType(string $messageType, ?string $status = null, int $limit = 50): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE message_type = :type";

        $params = [':type' => $messageType];

        if ($status !== null) {
            $query .= " AND status = :status";
            $params[':status'] = $status;
        }

        $query .= " ORDER BY created_at DESC LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->decodeJsonFields($results, 'payload');
        } catch (PDOException $e) {
            $this->logError("Failed to get DLQ items by type", $e);
            return [];
        }
    }

    /**
     * Update item status to 'retrying'
     *
     * @param int $id DLQ item ID
     * @return bool Success status
     */
    public function markRetrying(int $id): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = 'retrying',
                      last_retry_at = CURRENT_TIMESTAMP(6)
                  WHERE id = :id";

        $stmt = $this->execute($query, [':id' => $id]);
        return $stmt !== false;
    }

    /**
     * Mark item as resolved (successfully reprocessed)
     *
     * @param int $id DLQ item ID
     * @return bool Success status
     */
    public function markResolved(int $id): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = 'resolved',
                      resolved_at = CURRENT_TIMESTAMP(6)
                  WHERE id = :id";

        $stmt = $this->execute($query, [':id' => $id]);
        return $stmt !== false;
    }

    /**
     * Mark item as abandoned (manually abandoned)
     *
     * @param int $id DLQ item ID
     * @param string|null $reason Optional reason for abandonment
     * @return bool Success status
     */
    public function markAbandoned(int $id, ?string $reason = null): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = 'abandoned',
                      resolved_at = CURRENT_TIMESTAMP(6),
                      failure_reason = CONCAT(failure_reason, ' | Abandoned: ', :reason)
                  WHERE id = :id";

        $stmt = $this->execute($query, [':id' => $id, ':reason' => $reason ?? 'Manual']);
        return $stmt !== false;
    }

    /**
     * Return item to pending status for retry
     *
     * @param int $id DLQ item ID
     * @return bool Success status
     */
    public function returnToPending(int $id): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = 'pending',
                      retry_count = retry_count + 1
                  WHERE id = :id";

        $stmt = $this->execute($query, [':id' => $id]);
        return $stmt !== false;
    }

    /**
     * Get DLQ statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        $query = "SELECT
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END) as retrying_count,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
                    SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_count,
                    COUNT(DISTINCT message_type) as message_types,
                    AVG(retry_count) as avg_retries
                  FROM {$this->tableName}";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get statistics grouped by message type
     *
     * @return array Statistics by type
     */
    public function getStatisticsByType(): array {
        $query = "SELECT
                    message_type,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
                    SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) as abandoned_count
                  FROM {$this->tableName}
                  GROUP BY message_type";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get count of pending items (for alerting)
     *
     * @return int Number of pending items
     */
    public function getPendingCount(): int {
        return $this->count('status', 'pending');
    }

    /**
     * Check if item exists by message ID
     *
     * @param string $messageId Message ID (matches message_delivery.message_id)
     * @return bool True if exists
     */
    public function existsByMessageId(string $messageId): bool {
        return $this->exists('message_id', $messageId);
    }

    /**
     * Delete old resolved/abandoned records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = 90): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE status IN ('resolved', 'abandoned')
                    AND resolved_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old DLQ records", $e);
            return 0;
        }
    }
}
