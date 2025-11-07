<?php
# Copyright 2025

/**
 * Retry Service
 *
 * Implements retry mechanism with exponential backoff for failed message transmissions.
 * Provides automatic retry with configurable backoff intervals and jitter to prevent
 * thundering herd problems.
 *
 * @package Services
 */

require_once __DIR__ . '/../core/Constants.php';

class RetryService {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * @var SecureLogger Logger instance
     */
    private SecureLogger $logger;

    /**
     * @var int Maximum number of retry attempts
     */
    private int $maxRetries;

    /**
     * @var array Base retry intervals in seconds (exponential backoff)
     */
    private array $retryIntervals;

    /**
     * @var float Maximum jitter as a percentage of base interval (0.0 to 1.0)
     */
    private float $jitterPercent;

    /**
     * @var int Circuit breaker threshold - failures before circuit opens
     */
    private int $circuitBreakerThreshold;

    /**
     * @var int Circuit breaker timeout in seconds
     */
    private int $circuitBreakerTimeout;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param SecureLogger $logger Logger instance
     * @param int $maxRetries Maximum retry attempts (default: 6)
     * @param array|null $retryIntervals Custom retry intervals in seconds
     * @param float $jitterPercent Jitter percentage (default: 0.25 = 25%)
     * @param int $circuitBreakerThreshold Failures before circuit opens (default: 5)
     * @param int $circuitBreakerTimeout Circuit breaker timeout in seconds (default: 60)
     */
    public function __construct(
        PDO $pdo,
        SecureLogger $logger,
        int $maxRetries = 6,
        ?array $retryIntervals = null,
        float $jitterPercent = 0.25,
        int $circuitBreakerThreshold = 5,
        int $circuitBreakerTimeout = 60
    ) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->maxRetries = $maxRetries;
        $this->retryIntervals = $retryIntervals ?? [1, 2, 4, 8, 16, 32];
        $this->jitterPercent = max(0.0, min(1.0, $jitterPercent));
        $this->circuitBreakerThreshold = $circuitBreakerThreshold;
        $this->circuitBreakerTimeout = $circuitBreakerTimeout;
    }

    /**
     * Calculate retry delay with exponential backoff and jitter
     *
     * @param int $attemptNumber Current attempt number (0-indexed)
     * @return int Delay in seconds
     */
    public function calculateRetryDelay(int $attemptNumber): int {
        // Get base interval for this attempt (use last interval if beyond configured intervals)
        $baseInterval = $this->retryIntervals[$attemptNumber]
            ?? $this->retryIntervals[count($this->retryIntervals) - 1];

        // Add jitter: random value between 0 and (baseInterval * jitterPercent)
        $maxJitter = (int)($baseInterval * $this->jitterPercent);
        $jitter = random_int(0, $maxJitter);

        return $baseInterval + $jitter;
    }

    /**
     * Record a retry attempt in the database
     *
     * @param string $messageId Unique message identifier (txid or hash)
     * @param string $messageType Type of message (transaction, p2p, rp2p)
     * @param string $recipientAddress Recipient address
     * @param int $attemptNumber Current attempt number
     * @param string|null $errorMessage Error message if failed
     * @param string $status Status of retry (scheduled, sent, failed, completed)
     * @return bool Success status
     */
    public function recordRetryAttempt(
        string $messageId,
        string $messageType,
        string $recipientAddress,
        int $attemptNumber,
        ?string $errorMessage = null,
        string $status = 'scheduled'
    ): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO message_retries (
                    message_id,
                    message_type,
                    recipient_address,
                    attempt_number,
                    status,
                    error_message,
                    next_retry_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            // Calculate next retry time
            $delay = $this->calculateRetryDelay($attemptNumber);
            $nextRetryAt = date('Y-m-d H:i:s', time() + $delay);

            $result = $stmt->execute([
                $messageId,
                $messageType,
                $recipientAddress,
                $attemptNumber,
                $status,
                $errorMessage,
                $nextRetryAt
            ]);

            if ($result) {
                $this->logger->info("Retry attempt recorded", [
                    'message_id' => $messageId,
                    'message_type' => $messageType,
                    'attempt' => $attemptNumber,
                    'next_retry' => $nextRetryAt,
                    'status' => $status
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->logger->error("Failed to record retry attempt", [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get retry information for a message
     *
     * @param string $messageId Message identifier
     * @return array|null Retry information or null if not found
     */
    public function getRetryInfo(string $messageId): ?array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    message_id,
                    message_type,
                    recipient_address,
                    attempt_number,
                    status,
                    error_message,
                    created_at,
                    next_retry_at,
                    completed_at
                FROM message_retries
                WHERE message_id = ?
                ORDER BY attempt_number DESC
                LIMIT 1
            ");

            $stmt->execute([$messageId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ?: null;
        } catch (PDOException $e) {
            $this->logger->error("Failed to get retry info", [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get all retry attempts for a message
     *
     * @param string $messageId Message identifier
     * @return array Array of retry attempts
     */
    public function getAllRetryAttempts(string $messageId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    message_id,
                    message_type,
                    recipient_address,
                    attempt_number,
                    status,
                    error_message,
                    created_at,
                    next_retry_at,
                    completed_at
                FROM message_retries
                WHERE message_id = ?
                ORDER BY attempt_number ASC
            ");

            $stmt->execute([$messageId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error("Failed to get all retry attempts", [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Check if a message should be retried
     *
     * @param string $messageId Message identifier
     * @return bool True if message should be retried
     */
    public function shouldRetry(string $messageId): bool {
        $retryInfo = $this->getRetryInfo($messageId);

        if (!$retryInfo) {
            // No retry record exists, this is the first attempt
            return true;
        }

        // Check if max retries exceeded
        if ($retryInfo['attempt_number'] >= $this->maxRetries - 1) {
            $this->logger->warning("Max retries exceeded", [
                'message_id' => $messageId,
                'attempts' => $retryInfo['attempt_number'] + 1
            ]);
            return false;
        }

        // Check if message already completed
        if ($retryInfo['status'] === 'completed') {
            return false;
        }

        // Check if circuit breaker is open for this recipient
        if ($this->isCircuitOpen($retryInfo['recipient_address'])) {
            $this->logger->warning("Circuit breaker open for recipient", [
                'recipient' => $retryInfo['recipient_address']
            ]);
            return false;
        }

        // Check if enough time has passed for next retry
        if ($retryInfo['next_retry_at']) {
            $nextRetryTime = strtotime($retryInfo['next_retry_at']);
            if (time() < $nextRetryTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark a retry as completed successfully
     *
     * @param string $messageId Message identifier
     * @return bool Success status
     */
    public function markCompleted(string $messageId): bool {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE message_retries
                SET status = 'completed',
                    completed_at = CURRENT_TIMESTAMP
                WHERE message_id = ?
                AND status != 'completed'
            ");

            $result = $stmt->execute([$messageId]);

            if ($result) {
                $this->logger->info("Retry marked as completed", [
                    'message_id' => $messageId
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->logger->error("Failed to mark retry as completed", [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Mark a retry as permanently failed
     *
     * @param string $messageId Message identifier
     * @param string $reason Failure reason
     * @return bool Success status
     */
    public function markFailed(string $messageId, string $reason): bool {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE message_retries
                SET status = 'failed',
                    error_message = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE message_id = ?
                AND status != 'completed'
            ");

            $result = $stmt->execute([$reason, $messageId]);

            if ($result) {
                $this->logger->warning("Retry marked as failed", [
                    'message_id' => $messageId,
                    'reason' => $reason
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->logger->error("Failed to mark retry as failed", [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get messages ready for retry
     *
     * @param int $limit Maximum number of messages to return
     * @return array Array of messages ready for retry
     */
    public function getMessagesReadyForRetry(int $limit = 100): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    message_id,
                    message_type,
                    recipient_address,
                    attempt_number,
                    error_message
                FROM message_retries
                WHERE status IN ('scheduled', 'sent')
                AND next_retry_at <= CURRENT_TIMESTAMP
                AND attempt_number < ?
                ORDER BY next_retry_at ASC
                LIMIT ?
            ");

            $stmt->execute([$this->maxRetries, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error("Failed to get messages ready for retry", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Check if circuit breaker is open for a recipient
     *
     * @param string $recipientAddress Recipient address
     * @return bool True if circuit is open
     */
    public function isCircuitOpen(string $recipientAddress): bool {
        try {
            // Count recent failures for this recipient
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as failure_count
                FROM message_retries
                WHERE recipient_address = ?
                AND status = 'failed'
                AND created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND)
            ");

            $stmt->execute([$recipientAddress, $this->circuitBreakerTimeout]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $failureCount = $result['failure_count'] ?? 0;
            return $failureCount >= $this->circuitBreakerThreshold;
        } catch (PDOException $e) {
            $this->logger->error("Failed to check circuit breaker", [
                'recipient' => $recipientAddress,
                'error' => $e->getMessage()
            ]);
            // Fail safe: if we can't check, assume circuit is closed
            return false;
        }
    }

    /**
     * Get retry statistics for monitoring
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        try {
            $stmt = $this->pdo->query("
                SELECT
                    COUNT(*) as total_retries,
                    COUNT(DISTINCT message_id) as unique_messages,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                    AVG(attempt_number) as avg_attempts,
                    MAX(attempt_number) as max_attempts
                FROM message_retries
                WHERE created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)
            ");

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logger->error("Failed to get retry statistics", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Clean up old retry records
     *
     * @param int $daysToKeep Number of days to keep records (default: 30)
     * @return int Number of records deleted
     */
    public function cleanup(int $daysToKeep = 30): int {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM message_retries
                WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY)
                AND status IN ('completed', 'failed')
            ");

            $stmt->execute([$daysToKeep]);
            $deletedCount = $stmt->rowCount();

            if ($deletedCount > 0) {
                $this->logger->info("Cleaned up old retry records", [
                    'deleted' => $deletedCount,
                    'days' => $daysToKeep
                ]);
            }

            return $deletedCount;
        } catch (PDOException $e) {
            $this->logger->error("Failed to cleanup retry records", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get current retry attempt number for a message
     *
     * @param string $messageId Message identifier
     * @return int Current attempt number (0 if no retries exist)
     */
    public function getCurrentAttemptNumber(string $messageId): int {
        $retryInfo = $this->getRetryInfo($messageId);
        return $retryInfo ? ($retryInfo['attempt_number'] + 1) : 0;
    }

    /**
     * Reset retry state for a message (for manual intervention)
     *
     * @param string $messageId Message identifier
     * @return bool Success status
     */
    public function resetRetryState(string $messageId): bool {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM message_retries
                WHERE message_id = ?
            ");

            $result = $stmt->execute([$messageId]);

            if ($result) {
                $this->logger->info("Retry state reset", [
                    'message_id' => $messageId
                ]);
            }

            return $result;
        } catch (PDOException $e) {
            $this->logger->error("Failed to reset retry state", [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
