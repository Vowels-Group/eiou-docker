<?php
# Copyright 2025

/**
 * Acknowledgment Repository
 *
 * Manages database operations for the 3-stage message acknowledgment protocol.
 * Handles tracking of message stages: received, processed, confirmed, and failed.
 *
 * Issue: #139 - Transaction Reliability & Message Handling System
 *
 * @package Database
 */

require_once __DIR__ . '/AbstractRepository.php';

class AcknowledgmentRepository extends AbstractRepository {
    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for testing
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'message_acknowledgments';
        $this->primaryKey = 'id';
    }

    /**
     * Create new acknowledgment record
     *
     * @param array $data Acknowledgment data
     * @return int|false Last insert ID or false on failure
     */
    public function create(array $data) {
        $requiredFields = ['message_id', 'message_hash', 'message_type', 'sender_address', 'receiver_address'];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logError("Missing required field: $field");
                return false;
            }
        }

        // Set initial stage if not provided
        if (!isset($data['stage'])) {
            $data['stage'] = 'received';
        }

        // Set initial timestamp based on stage
        if (!isset($data['received_at']) && $data['stage'] === 'received') {
            $data['received_at'] = date('Y-m-d H:i:s');
        }

        // Set default max_retries if not provided
        if (!isset($data['max_retries'])) {
            $data['max_retries'] = 5;
        }

        return $this->insert($data);
    }

    /**
     * Get acknowledgment record by message ID
     *
     * @param string $messageId Message ID
     * @return array|null Acknowledgment record or null if not found
     */
    public function getByMessageId(string $messageId): ?array {
        return $this->findByColumn('message_id', $messageId);
    }

    /**
     * Get acknowledgment records by stage
     *
     * @param string $stage Stage name (received, processed, confirmed, failed)
     * @param int $limit Maximum number of records (0 = no limit)
     * @return array Array of acknowledgment records
     */
    public function getByStage(string $stage, int $limit = 0): array {
        return $this->findManyByColumn('stage', $stage, $limit);
    }

    /**
     * Update acknowledgment stage with timestamp
     *
     * @param string $messageId Message ID
     * @param string $stage New stage (received, processed, confirmed, failed)
     * @param array $metadata Additional metadata to update
     * @return bool Success status
     */
    public function updateStage(string $messageId, string $stage, array $metadata = []): bool {
        $validStages = ['received', 'processed', 'confirmed', 'failed'];

        if (!in_array($stage, $validStages)) {
            $this->logError("Invalid stage: $stage");
            return false;
        }

        $data = ['stage' => $stage];

        // Set appropriate timestamp based on stage
        switch ($stage) {
            case 'received':
                $data['received_at'] = date('Y-m-d H:i:s');
                break;
            case 'processed':
                $data['processed_at'] = date('Y-m-d H:i:s');
                break;
            case 'confirmed':
                $data['confirmed_at'] = date('Y-m-d H:i:s');
                break;
            case 'failed':
                $data['failed_at'] = date('Y-m-d H:i:s');
                break;
        }

        // Merge additional metadata
        foreach ($metadata as $key => $value) {
            // Validate column exists (security)
            if (in_array($key, ['related_txid', 'related_p2p_hash', 'failure_reason'])) {
                $data[$key] = $value;
            }
        }

        $rowsAffected = $this->update($data, 'message_id', $messageId);
        return $rowsAffected > 0;
    }

    /**
     * Get messages ready for retry
     *
     * @param int $limit Maximum number of messages (default: 100)
     * @return array Array of messages ready for retry
     */
    public function getRetryQueue(int $limit = 100): array {
        $query = "
            SELECT * FROM {$this->tableName}
            WHERE stage IN ('received', 'processed')
              AND retry_count < max_retries
              AND is_dead_letter = FALSE
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY created_at ASC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to fetch retry queue", $e, $query);
            return [];
        }
    }

    /**
     * Increment retry count and schedule next retry
     *
     * @param string $messageId Message ID
     * @return bool Success status
     */
    public function incrementRetryCount(string $messageId): bool {
        // Get current retry count
        $record = $this->getByMessageId($messageId);

        if (!$record) {
            return false;
        }

        $newRetryCount = $record['retry_count'] + 1;

        // Calculate next retry time using exponential backoff
        $nextRetryDelay = $this->calculateRetryDelay($newRetryCount);
        $nextRetryAt = date('Y-m-d H:i:s', time() + $nextRetryDelay);

        $data = [
            'retry_count' => $newRetryCount,
            'next_retry_at' => $nextRetryAt,
            'last_retry_at' => date('Y-m-d H:i:s')
        ];

        $rowsAffected = $this->update($data, 'message_id', $messageId);
        return $rowsAffected > 0;
    }

    /**
     * Calculate retry delay using exponential backoff
     *
     * @param int $retryCount Current retry attempt number
     * @return int Delay in seconds
     */
    private function calculateRetryDelay(int $retryCount): int {
        // Base delay: 5 seconds
        // Max delay: 300 seconds (5 minutes)
        $baseDelay = 5;
        $maxDelay = 300;

        $delay = $baseDelay * (2 ** $retryCount); // Exponential: 5, 10, 20, 40, 80, 160

        return min($delay, $maxDelay);
    }

    /**
     * Mark message as dead letter
     *
     * @param string $messageId Message ID
     * @param string $reason Failure reason
     * @return bool Success status
     */
    public function markAsDeadLetter(string $messageId, string $reason): bool {
        $data = [
            'stage' => 'failed',
            'is_dead_letter' => true,
            'dead_letter_at' => date('Y-m-d H:i:s'),
            'failed_at' => date('Y-m-d H:i:s'),
            'failure_reason' => $reason
        ];

        $rowsAffected = $this->update($data, 'message_id', $messageId);
        return $rowsAffected > 0;
    }

    /**
     * Get dead letter queue messages
     *
     * @param int $limit Maximum number of messages (0 = no limit)
     * @return array Array of dead letter messages
     */
    public function getDeadLetterQueue(int $limit = 0): array {
        $query = "
            SELECT * FROM {$this->tableName}
            WHERE is_dead_letter = TRUE
            ORDER BY dead_letter_at DESC
        ";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to fetch dead letter queue", $e, $query);
            return [];
        }
    }

    /**
     * Get statistics grouped by stage
     *
     * @return array Stage distribution statistics
     */
    public function getStatsByStage(): array {
        $query = "
            SELECT
                stage,
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest
            FROM {$this->tableName}
            WHERE is_dead_letter = FALSE
            GROUP BY stage
        ";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calculate failure rate for given time period
     *
     * @param int $hoursBack Number of hours to look back (default: 24)
     * @return float Failure rate as percentage (0.0 to 100.0)
     */
    public function getFailureRate(int $hoursBack = 24): float {
        $since = date('Y-m-d H:i:s', time() - ($hoursBack * 3600));

        $query = "
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN stage = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$this->tableName}
            WHERE created_at >= :since
        ";

        $stmt = $this->execute($query, [':since' => $since]);

        if (!$stmt) {
            return 0.0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || $result['total'] == 0) {
            return 0.0;
        }

        return ($result['failed'] / $result['total']) * 100.0;
    }

    /**
     * Get delivery success rate statistics
     *
     * @param int $hoursBack Number of hours to look back (default: 24)
     * @return array Delivery statistics
     */
    public function getDeliveryStats(int $hoursBack = 24): array {
        $since = date('Y-m-d H:i:s', time() - ($hoursBack * 3600));

        $query = "
            SELECT
                COUNT(*) as total_messages,
                SUM(CASE WHEN stage = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN stage = 'processed' THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN stage = 'received' THEN 1 ELSE 0 END) as received,
                SUM(CASE WHEN stage = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(TIMESTAMPDIFF(SECOND, received_at, confirmed_at)) as avg_confirmation_time,
                SUM(retry_count) as total_retries
            FROM {$this->tableName}
            WHERE created_at >= :since
        ";

        $stmt = $this->execute($query, [':since' => $since]);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return [];
        }

        // Calculate success rate
        if ($result['total_messages'] > 0) {
            $result['success_rate'] = ($result['confirmed'] / $result['total_messages']) * 100.0;
        } else {
            $result['success_rate'] = 0.0;
        }

        return $result;
    }

    /**
     * Get retry statistics
     *
     * @param int $hoursBack Number of hours to look back (default: 24)
     * @return array Retry statistics
     */
    public function getRetryStats(int $hoursBack = 24): array {
        $since = date('Y-m-d H:i:s', time() - ($hoursBack * 3600));

        $query = "
            SELECT
                COUNT(*) as messages_with_retries,
                SUM(retry_count) as total_retries,
                AVG(retry_count) as avg_retries_per_message,
                MAX(retry_count) as max_retries
            FROM {$this->tableName}
            WHERE created_at >= :since
              AND retry_count > 0
        ";

        $stmt = $this->execute($query, [':since' => $since]);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: [];
    }

    /**
     * Check if message already exists (duplicate prevention)
     *
     * @param string $messageId Message ID
     * @return bool True if message exists
     */
    public function isDuplicate(string $messageId): bool {
        $record = $this->getByMessageId($messageId);

        if (!$record) {
            return false;
        }

        // Consider it a duplicate if it's already processed or confirmed
        return in_array($record['stage'], ['processed', 'confirmed']);
    }

    /**
     * Reprocess dead letter message
     *
     * @param string $messageId Message ID
     * @return bool Success status
     */
    public function reprocessDeadLetter(string $messageId): bool {
        $record = $this->getByMessageId($messageId);

        if (!$record || !$record['is_dead_letter']) {
            return false;
        }

        $data = [
            'stage' => 'received',
            'is_dead_letter' => false,
            'dead_letter_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'retry_count' => 0,
            'next_retry_at' => null,
            'last_retry_at' => null,
            'received_at' => date('Y-m-d H:i:s')
        ];

        $rowsAffected = $this->update($data, 'message_id', $messageId);
        return $rowsAffected > 0;
    }

    /**
     * Clean up old acknowledged messages
     *
     * @param int $daysOld Number of days to keep (default: 30)
     * @return int Number of deleted records
     */
    public function cleanupOldRecords(int $daysOld = 30): int {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($daysOld * 86400));

        $query = "
            DELETE FROM {$this->tableName}
            WHERE stage = 'confirmed'
              AND confirmed_at < :cutoff_date
              AND is_dead_letter = FALSE
        ";

        $stmt = $this->execute($query, [':cutoff_date' => $cutoffDate]);

        if (!$stmt) {
            return 0;
        }

        return $stmt->rowCount();
    }
}
