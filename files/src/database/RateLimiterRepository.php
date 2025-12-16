<?php
# Copyright 2025

require_once __DIR__ . '/../utils/SecureLogger.php';

/**
 * Rate Limiter Repository
 *
 * Handles database operations for rate limiting functionality.
 *
 * @package Database
 */

class RateLimiterRepository {
    private PDO $pdo;

    /**
     * Initialize repository with database connection
     *
     * @param PDO $pdo Database connection
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Check if identifier is currently blocked
     *
     * @param string $identifier User identifier (IP, user ID, etc.)
     * @param string $action Action being performed
     * @return array|false Blocked record or false if not blocked
     */
    public function getBlockedRecord(string $identifier, string $action) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM rate_limits
            WHERE identifier = ? AND action = ?
            AND blocked_until IS NOT NULL AND blocked_until > NOW()
        ");
        $stmt->execute([$identifier, $action]);
        return $stmt->fetch();
    }

    /**
     * Get current attempts within time window
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     * @param int $windowSeconds Time window in seconds
     * @return array|false Record or false if no attempts
     */
    public function getAttemptsInWindow(string $identifier, string $action, int $windowSeconds) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM rate_limits
            WHERE identifier = ? AND action = ?
            AND last_attempt > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$identifier, $action, $windowSeconds]);
        return $stmt->fetch();
    }

    /**
     * Insert first attempt record
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     * @return bool Success status
     */
    public function insertFirstAttempt(string $identifier, string $action): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (identifier, action, attempts, first_attempt, last_attempt)
            VALUES (?, ?, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            attempts = 1, first_attempt = NOW(), last_attempt = NOW()
        ");
        return $stmt->execute([$identifier, $action]);
    }

    /**
     * Update attempts count
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     * @param int $attempts New attempts count
     * @return bool Success status
     */
    public function updateAttempts(string $identifier, string $action, int $attempts): bool {
        $stmt = $this->pdo->prepare("
            UPDATE rate_limits
            SET attempts = ?, last_attempt = NOW()
            WHERE identifier = ? AND action = ?
        ");
        return $stmt->execute([$attempts, $identifier, $action]);
    }

    /**
     * Block identifier for specified duration
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     * @param int $attempts Current attempts count
     * @param string $blockedUntil Timestamp when block expires
     * @return bool Success status
     */
    public function blockIdentifier(string $identifier, string $action, int $attempts, string $blockedUntil): bool {
        $stmt = $this->pdo->prepare("
            UPDATE rate_limits
            SET attempts = ?, last_attempt = NOW(), blocked_until = ?
            WHERE identifier = ? AND action = ?
        ");
        return $stmt->execute([$attempts, $blockedUntil, $identifier, $action]);
    }

    /**
     * Reset rate limit for a specific identifier and action
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     * @return bool Success status
     */
    public function reset(string $identifier, string $action): bool {
        $stmt = $this->pdo->prepare("
            DELETE FROM rate_limits
            WHERE identifier = ? AND action = ?
        ");
        return $stmt->execute([$identifier, $action]);
    }

    /**
     * Clean up old rate limit records
     *
     * @param int $olderThanSeconds Remove records older than this
     * @return bool Success status
     */
    public function cleanup(int $olderThanSeconds = 3600): bool {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM rate_limits
                WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)
                AND (blocked_until IS NULL OR blocked_until < NOW())
            ");
            return $stmt->execute([$olderThanSeconds]);
        } catch (PDOException $e) {
            SecureLogger::warning("Rate limit cleanup failed", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
