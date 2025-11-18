<?php
/**
 * Message Reliability Service
 *
 * Implements transaction reliability features to prevent message loss:
 * - Multi-stage acknowledgments (received, inserted, forwarded)
 * - Retry mechanism with exponential backoff
 * - Duplicate prevention
 * - Dead letter queue for failed messages
 *
 * Copyright 2025
 */

class MessageReliabilityService {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * Message acknowledgment states
     */
    private const STATE_RECEIVED = 'received';
    private const STATE_INSERTED = 'inserted';
    private const STATE_FORWARDED = 'forwarded';
    private const STATE_FAILED = 'failed';

    /**
     * Retry configuration
     */
    private const MAX_RETRIES = 5;
    private const INITIAL_BACKOFF = 1;  // 1 second
    private const MAX_BACKOFF = 60;     // 60 seconds

    /**
     * Constructor
     *
     * @param PDO|null $pdo Database connection
     */
    public function __construct(?PDO $pdo = null) {
        if ($pdo === null) {
            require_once __DIR__ . '/../database/pdo.php';
            $this->pdo = createPDOConnection();
        } else {
            $this->pdo = $pdo;
        }

        $this->ensureTablesExist();
    }

    /**
     * Ensure reliability tracking tables exist
     *
     * @return void
     */
    private function ensureTablesExist(): void {
        // Message tracking table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS message_tracking (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            message_hash VARCHAR(64) UNIQUE NOT NULL,
            sender_address VARCHAR(255) NOT NULL,
            receiver_address VARCHAR(255) NOT NULL,
            message_data TEXT NOT NULL,
            state VARCHAR(20) NOT NULL,
            retry_count INTEGER DEFAULT 0,
            last_retry_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hash (message_hash),
            INDEX idx_state (state),
            INDEX idx_retry (retry_count, last_retry_at)
        )");

        // Dead letter queue table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS dead_letter_queue (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            message_hash VARCHAR(64) NOT NULL,
            message_data TEXT NOT NULL,
            error_message TEXT,
            retry_count INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hash (message_hash)
        )");
    }

    /**
     * Track message receipt
     *
     * @param string $messageHash Unique message hash
     * @param string $senderAddress Sender address
     * @param string $receiverAddress Receiver address
     * @param string $messageData JSON message data
     * @return bool True if tracked successfully
     */
    public function trackMessageReceived(
        string $messageHash,
        string $senderAddress,
        string $receiverAddress,
        string $messageData
    ): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO message_tracking
                (message_hash, sender_address, receiver_address, message_data, state)
                VALUES (:hash, :sender, :receiver, :data, :state)
                ON DUPLICATE KEY UPDATE state = :state, updated_at = CURRENT_TIMESTAMP
            ");

            return $stmt->execute([
                ':hash' => $messageHash,
                ':sender' => $senderAddress,
                ':receiver' => $receiverAddress,
                ':data' => $messageData,
                ':state' => self::STATE_RECEIVED
            ]);
        } catch (PDOException $e) {
            error_log("Failed to track message received: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark message as inserted
     *
     * @param string $messageHash Message hash
     * @return bool Success status
     */
    public function markMessageInserted(string $messageHash): bool {
        return $this->updateMessageState($messageHash, self::STATE_INSERTED);
    }

    /**
     * Mark message as forwarded
     *
     * @param string $messageHash Message hash
     * @return bool Success status
     */
    public function markMessageForwarded(string $messageHash): bool {
        return $this->updateMessageState($messageHash, self::STATE_FORWARDED);
    }

    /**
     * Update message state
     *
     * @param string $messageHash Message hash
     * @param string $state New state
     * @return bool Success status
     */
    private function updateMessageState(string $messageHash, string $state): bool {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE message_tracking
                SET state = :state, updated_at = CURRENT_TIMESTAMP
                WHERE message_hash = :hash
            ");

            return $stmt->execute([
                ':hash' => $messageHash,
                ':state' => $state
            ]);
        } catch (PDOException $e) {
            error_log("Failed to update message state: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if message is duplicate
     *
     * @param string $messageHash Message hash
     * @return bool True if duplicate
     */
    public function isDuplicate(string $messageHash): bool {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM message_tracking
                WHERE message_hash = :hash
                AND state IN (:inserted, :forwarded)
            ");

            $stmt->execute([
                ':hash' => $messageHash,
                ':inserted' => self::STATE_INSERTED,
                ':forwarded' => self::STATE_FORWARDED
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("Failed to check duplicate: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get pending retry messages
     *
     * @return array Messages needing retry
     */
    public function getPendingRetries(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM message_tracking
                WHERE state = :received
                AND retry_count < :max_retries
                AND (
                    last_retry_at IS NULL
                    OR last_retry_at < DATE_SUB(NOW(), INTERVAL :backoff SECOND)
                )
                LIMIT 100
            ");

            $stmt->execute([
                ':received' => self::STATE_RECEIVED,
                ':max_retries' => self::MAX_RETRIES,
                ':backoff' => self::INITIAL_BACKOFF
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to get pending retries: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Increment retry count with exponential backoff
     *
     * @param string $messageHash Message hash
     * @return int Next retry delay in seconds
     */
    public function incrementRetry(string $messageHash): int {
        try {
            // Get current retry count
            $stmt = $this->pdo->prepare("
                SELECT retry_count FROM message_tracking
                WHERE message_hash = :hash
            ");
            $stmt->execute([':hash' => $messageHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return 0;
            }

            $newRetryCount = $row['retry_count'] + 1;

            // Update retry count and timestamp
            $stmt = $this->pdo->prepare("
                UPDATE message_tracking
                SET retry_count = :count, last_retry_at = CURRENT_TIMESTAMP
                WHERE message_hash = :hash
            ");

            $stmt->execute([
                ':count' => $newRetryCount,
                ':hash' => $messageHash
            ]);

            // Calculate exponential backoff: 2^retry * initial_backoff
            // Max: 60 seconds
            $backoff = min(
                pow(2, $newRetryCount) * self::INITIAL_BACKOFF,
                self::MAX_BACKOFF
            );

            return (int) $backoff;
        } catch (PDOException $e) {
            error_log("Failed to increment retry: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Move message to dead letter queue
     *
     * @param string $messageHash Message hash
     * @param string $errorMessage Error description
     * @return bool Success status
     */
    public function moveToDeadLetterQueue(string $messageHash, string $errorMessage = ''): bool {
        try {
            // Get message data
            $stmt = $this->pdo->prepare("
                SELECT message_data, retry_count
                FROM message_tracking
                WHERE message_hash = :hash
            ");
            $stmt->execute([':hash' => $messageHash]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$message) {
                return false;
            }

            // Insert into dead letter queue
            $stmt = $this->pdo->prepare("
                INSERT INTO dead_letter_queue
                (message_hash, message_data, error_message, retry_count)
                VALUES (:hash, :data, :error, :retries)
            ");

            $stmt->execute([
                ':hash' => $messageHash,
                ':data' => $message['message_data'],
                ':error' => $errorMessage,
                ':retries' => $message['retry_count']
            ]);

            // Mark as failed
            $this->updateMessageState($messageHash, self::STATE_FAILED);

            return true;
        } catch (PDOException $e) {
            error_log("Failed to move to DLQ: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get dead letter queue messages
     *
     * @param int $limit Maximum messages to return
     * @return array Dead letter queue messages
     */
    public function getDeadLetterQueue(int $limit = 100): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM dead_letter_queue
                ORDER BY created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to get DLQ: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get reliability statistics
     *
     * @return array Statistics
     */
    public function getStatistics(): array {
        try {
            $stats = [];

            // Total messages by state
            $stmt = $this->pdo->query("
                SELECT state, COUNT(*) as count
                FROM message_tracking
                GROUP BY state
            ");
            $stats['by_state'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Messages in DLQ
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count FROM dead_letter_queue
            ");
            $stats['dead_letter_count'] = $stmt->fetchColumn();

            // Success rate
            $total = array_sum($stats['by_state']);
            $successful = ($stats['by_state'][self::STATE_INSERTED] ?? 0) +
                         ($stats['by_state'][self::STATE_FORWARDED] ?? 0);
            $stats['success_rate'] = $total > 0 ? ($successful / $total) * 100 : 0;

            return $stats;
        } catch (PDOException $e) {
            error_log("Failed to get statistics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean old tracking records
     *
     * @param int $days Records older than this many days
     * @return int Number of deleted records
     */
    public function cleanOldRecords(int $days = 30): int {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM message_tracking
                WHERE state IN (:inserted, :forwarded, :failed)
                AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");

            $stmt->execute([
                ':inserted' => self::STATE_INSERTED,
                ':forwarded' => self::STATE_FORWARDED,
                ':failed' => self::STATE_FAILED,
                ':days' => $days
            ]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Failed to clean old records: " . $e->getMessage());
            return 0;
        }
    }
}
