<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Dead Letter Queue Repository
 *
 * Manages all database interactions for the dead_letter_queue table.
 * Stores messages that failed all retry attempts for manual inspection and retry.
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
     * @param array $messageData Original message data
     * @param string $failureReason Reason for failure
     * @param int $retryCount Number of retry attempts made
     * @param string|null $lastError Last error message
     * @return string|false Insert ID or false on failure
     */
    public function addMessage(
        array $messageData,
        string $failureReason,
        int $retryCount,
        ?string $lastError = null
    ) {
        $data = [
            'message_type' => $messageData['typeMessage'] ?? 'unknown',
            'sender_address' => $messageData['senderAddress'] ?? '',
            'original_message' => json_encode($messageData),
            'failure_reason' => $failureReason,
            'last_error' => $lastError,
            'retry_count' => $retryCount,
            'status' => 'failed',
            'failed_at' => date('Y-m-d H:i:s')
        ];

        // Add hash if present (for transaction messages)
        if (isset($messageData['hash'])) {
            $data['transaction_hash'] = $messageData['hash'];
        }

        return $this->insert($data);
    }

    /**
     * Get all messages in the DLQ with optional filtering
     *
     * @param string|null $status Filter by status (failed, retrying, resolved)
     * @param string|null $messageType Filter by message type
     * @param int $limit Maximum number of records to return
     * @param int $offset Starting offset for pagination
     * @return array Array of DLQ messages
     */
    public function getMessages(
        ?string $status = null,
        ?string $messageType = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $query = "SELECT * FROM {$this->tableName} WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $query .= " AND status = :status";
            $params[':status'] = $status;
        }

        if ($messageType !== null) {
            $query .= " AND message_type = :message_type";
            $params[':message_type'] = $messageType;
        }

        $query .= " ORDER BY failed_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to fetch DLQ messages", $e);
            return [];
        }
    }

    /**
     * Get a single message by ID
     *
     * @param int $id Message ID
     * @return array|null Message data or null if not found
     */
    public function getMessageById(int $id): ?array {
        return $this->findById($id);
    }

    /**
     * Update message status
     *
     * @param int $id Message ID
     * @param string $status New status (failed, retrying, resolved, archived)
     * @param string|null $resolutionNotes Optional notes about resolution
     * @return int Number of affected rows, -1 on error
     */
    public function updateStatus(int $id, string $status, ?string $resolutionNotes = null): int {
        $data = ['status' => $status];

        if ($status === 'resolved' || $status === 'archived') {
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }

        if ($resolutionNotes !== null) {
            $data['resolution_notes'] = $resolutionNotes;
        }

        return $this->update($data, 'id', $id);
    }

    /**
     * Increment manual retry count
     *
     * @param int $id Message ID
     * @return bool Success status
     */
    public function incrementManualRetryCount(int $id): bool {
        $query = "UPDATE {$this->tableName}
                  SET manual_retry_count = manual_retry_count + 1,
                      last_retry_at = :retry_time
                  WHERE id = :id";

        $stmt = $this->execute($query, [
            ':retry_time' => date('Y-m-d H:i:s'),
            ':id' => $id
        ]);

        return $stmt !== false;
    }

    /**
     * Get statistics about DLQ messages
     *
     * @return array Statistics including counts by status and message type
     */
    public function getStatistics(): array {
        $stats = [];

        // Count by status
        $query = "SELECT status, COUNT(*) as count FROM {$this->tableName} GROUP BY status";
        $stmt = $this->pdo->query($query);
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Count by message type
        $query = "SELECT message_type, COUNT(*) as count FROM {$this->tableName}
                  WHERE status = 'failed' GROUP BY message_type";
        $stmt = $this->pdo->query($query);
        $stats['by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Count messages older than 7 days (for cleanup)
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                  WHERE failed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND status IN ('resolved', 'archived')";
        $stmt = $this->pdo->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['cleanup_eligible'] = (int) ($result['count'] ?? 0);

        // Count by failure reason
        $query = "SELECT failure_reason, COUNT(*) as count FROM {$this->tableName}
                  WHERE status = 'failed' GROUP BY failure_reason ORDER BY count DESC LIMIT 10";
        $stmt = $this->pdo->query($query);
        $stats['top_failures'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }

    /**
     * Clean up old resolved/archived messages
     *
     * @param int $daysToRetain Number of days to retain messages (default 7)
     * @return int Number of deleted messages
     */
    public function cleanupOldMessages(int $daysToRetain = 7): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE status IN ('resolved', 'archived')
                  AND resolved_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $daysToRetain, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to cleanup old DLQ messages", $e);
            return -1;
        }
    }

    /**
     * Check if a message with the same hash already exists in DLQ
     *
     * @param string $hash Transaction hash
     * @return bool True if exists in DLQ
     */
    public function hasMessageByHash(string $hash): bool {
        return $this->exists('transaction_hash', $hash);
    }

    /**
     * Get messages by failure reason (for pattern analysis)
     *
     * @param string $failureReason Failure reason to search for
     * @param int $limit Maximum number of records
     * @return array Array of matching messages
     */
    public function getMessagesByFailureReason(string $failureReason, int $limit = 100): array {
        return $this->findManyByColumn('failure_reason', $failureReason, $limit);
    }

    /**
     * Archive a message (soft delete)
     *
     * @param int $id Message ID
     * @return int Number of affected rows
     */
    public function archiveMessage(int $id): int {
        return $this->updateStatus($id, 'archived', 'Manually archived');
    }

    /**
     * Bulk archive messages by criteria
     *
     * @param string $status Status to filter by
     * @param int $olderThanDays Archive messages older than this many days
     * @return int Number of archived messages
     */
    public function bulkArchive(string $status, int $olderThanDays): int {
        $query = "UPDATE {$this->tableName}
                  SET status = 'archived',
                      resolved_at = NOW(),
                      resolution_notes = 'Bulk archived'
                  WHERE status = :status
                  AND failed_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':days', $olderThanDays, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to bulk archive DLQ messages", $e);
            return -1;
        }
    }

    /**
     * Get count of messages by status
     *
     * @param string $status Status to count
     * @return int Count of messages
     */
    public function countByStatus(string $status): int {
        return $this->count('status', $status);
    }
}
