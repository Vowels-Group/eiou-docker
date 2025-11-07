<?php
# Copyright 2025

/**
 * Deduplication Service
 *
 * Prevents duplicate message processing through hash-based fingerprinting
 * and time-based deduplication windows.
 *
 * @package Services
 */
class DeduplicationService {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * @var int Deduplication window in seconds (default: 1 hour)
     */
    private int $dedupWindowSeconds;

    /**
     * @var string Hash algorithm for fingerprinting
     */
    private string $hashAlgorithm;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param int $dedupWindowSeconds Deduplication window in seconds (default: 3600 = 1 hour)
     * @param string $hashAlgorithm Hash algorithm to use (default: sha256)
     */
    public function __construct(
        PDO $pdo,
        int $dedupWindowSeconds = 3600,
        string $hashAlgorithm = 'sha256'
    ) {
        $this->pdo = $pdo;
        $this->dedupWindowSeconds = $dedupWindowSeconds;
        $this->hashAlgorithm = $hashAlgorithm;
    }

    /**
     * Generate a unique fingerprint for a message
     *
     * Fingerprint is based on: message type + hash/txid + sender + amount + timestamp
     * This ensures exact duplicate detection while allowing legitimate retries
     * with different timestamps.
     *
     * @param string $messageType Type of message (p2p, transaction, rp2p, contact)
     * @param array $messageData Message data containing relevant fields
     * @return string Message fingerprint hash
     */
    public function generateFingerprint(string $messageType, array $messageData): string {
        // Build fingerprint components based on message type
        $components = [$messageType];

        switch ($messageType) {
            case 'p2p':
                // For P2P: hash is already unique (recipient + salt + time)
                // We add sender and amount to detect true duplicates vs. forwarding
                $components[] = $messageData['hash'] ?? '';
                $components[] = $messageData['senderAddress'] ?? '';
                $components[] = $messageData['amount'] ?? '';
                $components[] = $messageData['time'] ?? '';
                break;

            case 'rp2p':
                // For RP2P: hash + sender
                $components[] = $messageData['hash'] ?? '';
                $components[] = $messageData['senderAddress'] ?? '';
                $components[] = $messageData['time'] ?? '';
                break;

            case 'transaction':
                // For transactions: txid is unique
                $components[] = $messageData['txid'] ?? '';
                $components[] = $messageData['senderAddress'] ?? $messageData['sender_address'] ?? '';
                $components[] = $messageData['receiverAddress'] ?? $messageData['receiver_address'] ?? '';
                $components[] = $messageData['amount'] ?? '';
                break;

            case 'contact':
                // For contact requests: public key + sender address
                $components[] = $messageData['senderPublicKey'] ?? $messageData['sender_public_key'] ?? '';
                $components[] = $messageData['senderAddress'] ?? $messageData['sender_address'] ?? '';
                break;

            default:
                // Generic fingerprint: serialize all data
                $components[] = serialize($messageData);
                break;
        }

        // Create fingerprint
        $fingerprintString = implode('|', $components);
        return hash($this->hashAlgorithm, $fingerprintString);
    }

    /**
     * Check if a message is a duplicate
     *
     * @param string $messageType Type of message
     * @param array $messageData Message data
     * @return bool True if duplicate (should be rejected), false if new (should be processed)
     */
    public function isDuplicate(string $messageType, array $messageData): bool {
        try {
            $fingerprint = $this->generateFingerprint($messageType, $messageData);

            // Check if fingerprint exists within deduplication window
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM message_deduplication
                WHERE fingerprint = :fingerprint
                AND expires_at > NOW()
            ");
            $stmt->execute(['fingerprint' => $fingerprint]);

            $count = $stmt->fetchColumn();
            return $count > 0;

        } catch (PDOException $e) {
            error_log("Deduplication check failed: " . $e->getMessage());
            // On error, allow message through (fail open for availability)
            return false;
        }
    }

    /**
     * Record a message fingerprint to prevent future duplicates
     *
     * @param string $messageType Type of message
     * @param array $messageData Message data
     * @return bool True if recorded successfully, false on error
     */
    public function recordMessage(string $messageType, array $messageData): bool {
        try {
            $fingerprint = $this->generateFingerprint($messageType, $messageData);

            // Calculate expiration time
            $expiresAt = date('Y-m-d H:i:s', time() + $this->dedupWindowSeconds);

            // Insert fingerprint with expiration
            $stmt = $this->pdo->prepare("
                INSERT INTO message_deduplication
                (fingerprint, message_type, expires_at, created_at)
                VALUES (:fingerprint, :message_type, :expires_at, NOW())
                ON DUPLICATE KEY UPDATE
                    expires_at = VALUES(expires_at),
                    updated_at = NOW()
            ");

            return $stmt->execute([
                'fingerprint' => $fingerprint,
                'message_type' => $messageType,
                'expires_at' => $expiresAt
            ]);

        } catch (PDOException $e) {
            error_log("Failed to record message fingerprint: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check and record a message in one atomic operation
     *
     * This is the primary method to use for deduplication.
     * It checks if the message is a duplicate and records it if not.
     *
     * @param string $messageType Type of message
     * @param array $messageData Message data
     * @return bool True if message is new and recorded, false if duplicate or error
     */
    public function checkAndRecord(string $messageType, array $messageData): bool {
        // Check for duplicate first
        if ($this->isDuplicate($messageType, $messageData)) {
            error_log("Duplicate message detected - Type: $messageType, Fingerprint: " .
                     $this->generateFingerprint($messageType, $messageData));
            return false;
        }

        // Record the message
        return $this->recordMessage($messageType, $messageData);
    }

    /**
     * Clean up expired deduplication records
     *
     * This should be called periodically (e.g., via cron or cleanup service)
     * to remove expired fingerprints and keep the table size manageable.
     *
     * @return int Number of records deleted
     */
    public function cleanupExpired(): int {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM message_deduplication
                WHERE expires_at <= NOW()
            ");
            $stmt->execute();

            return $stmt->rowCount();

        } catch (PDOException $e) {
            error_log("Failed to cleanup expired deduplication records: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get deduplication statistics
     *
     * @return array Statistics about the deduplication cache
     */
    public function getStats(): array {
        try {
            // Count total active records
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total,
                       COUNT(CASE WHEN expires_at > NOW() THEN 1 END) as active,
                       COUNT(CASE WHEN expires_at <= NOW() THEN 1 END) as expired
                FROM message_deduplication
            ");
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);

            // Count by message type
            $stmt = $this->pdo->query("
                SELECT message_type, COUNT(*) as count
                FROM message_deduplication
                WHERE expires_at > NOW()
                GROUP BY message_type
            ");
            $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'total_records' => (int)$counts['total'],
                'active_records' => (int)$counts['active'],
                'expired_records' => (int)$counts['expired'],
                'by_type' => $byType,
                'window_seconds' => $this->dedupWindowSeconds
            ];

        } catch (PDOException $e) {
            error_log("Failed to get deduplication stats: " . $e->getMessage());
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Clear all deduplication records (for testing only)
     *
     * WARNING: This should only be used in test environments
     *
     * @return bool True if successful
     */
    public function clearAll(): bool {
        try {
            $stmt = $this->pdo->prepare("TRUNCATE TABLE message_deduplication");
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Failed to clear deduplication table: " . $e->getMessage());
            return false;
        }
    }
}
