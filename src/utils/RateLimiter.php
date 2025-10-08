<?php

# Copyright 2025
/**
 * Rate limiting implementation for eIOU application
 * Prevents abuse and brute force attacks
 */

class RateLimiter {
    private $pdo;
    private $prefix = 'rate_limit_';

    /**
     * Initialize rate limiter with database connection
     *
     * @param PDO $pdo Database connection
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->createTableIfNotExists();
    }

    /**
     * Create rate limiting table if it doesn't exist
     */
    private function createTableIfNotExists() {
        $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            identifier VARCHAR(255) NOT NULL,
            action VARCHAR(100) NOT NULL,
            attempts INTEGER DEFAULT 0,
            first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            blocked_until TIMESTAMP NULL,
            INDEX idx_identifier_action (identifier, action),
            INDEX idx_blocked_until (blocked_until)
        )";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to create rate_limits table: " . $e->getMessage());
        }
    }

    /**
     * Check if an action is rate limited
     *
     * @param string $identifier User identifier (IP, user ID, etc.)
     * @param string $action Action being performed
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param int $blockSeconds How long to block after exceeding limit
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => timestamp]
     */
    public function checkLimit($identifier, $action, $maxAttempts = 10, $windowSeconds = 60, $blockSeconds = 300) {
        // Clean up old entries
        $this->cleanup($windowSeconds);

        // Check if currently blocked
        $stmt = $this->pdo->prepare("
            SELECT * FROM rate_limits
            WHERE identifier = ? AND action = ?
            AND blocked_until IS NOT NULL AND blocked_until > NOW()
        ");
        $stmt->execute([$identifier, $action]);
        $blocked = $stmt->fetch();

        if ($blocked) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => $blocked['blocked_until'],
                'retry_after' => strtotime($blocked['blocked_until']) - time()
            ];
        }

        // Get current attempts within window
        $stmt = $this->pdo->prepare("
            SELECT * FROM rate_limits
            WHERE identifier = ? AND action = ?
            AND last_attempt > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$identifier, $action, $windowSeconds]);
        $record = $stmt->fetch();

        if (!$record) {
            // First attempt
            $stmt = $this->pdo->prepare("
                INSERT INTO rate_limits (identifier, action, attempts, first_attempt, last_attempt)
                VALUES (?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                attempts = 1, first_attempt = NOW(), last_attempt = NOW()
            ");
            $stmt->execute([$identifier, $action]);

            return [
                'allowed' => true,
                'remaining' => $maxAttempts - 1,
                'reset_at' => time() + $windowSeconds
            ];
        }

        // Increment attempts
        $attempts = $record['attempts'] + 1;

        if ($attempts > $maxAttempts) {
            // Block the user
            $blockedUntil = date('Y-m-d H:i:s', time() + $blockSeconds);
            $stmt = $this->pdo->prepare("
                UPDATE rate_limits
                SET attempts = ?, last_attempt = NOW(), blocked_until = ?
                WHERE identifier = ? AND action = ?
            ");
            $stmt->execute([$attempts, $blockedUntil, $identifier, $action]);

            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => $blockedUntil,
                'retry_after' => $blockSeconds
            ];
        }

        // Update attempts
        $stmt = $this->pdo->prepare("
            UPDATE rate_limits
            SET attempts = ?, last_attempt = NOW()
            WHERE identifier = ? AND action = ?
        ");
        $stmt->execute([$attempts, $identifier, $action]);

        return [
            'allowed' => true,
            'remaining' => $maxAttempts - $attempts,
            'reset_at' => strtotime($record['first_attempt']) + $windowSeconds
        ];
    }

    /**
     * Reset rate limit for a specific identifier and action
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     */
    public function reset($identifier, $action) {
        $stmt = $this->pdo->prepare("
            DELETE FROM rate_limits
            WHERE identifier = ? AND action = ?
        ");
        $stmt->execute([$identifier, $action]);
    }

    /**
     * Clean up old rate limit records
     *
     * @param int $olderThanSeconds Remove records older than this
     */
    private function cleanup($olderThanSeconds = 3600) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM rate_limits
                WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)
                AND (blocked_until IS NULL OR blocked_until < NOW())
            ");
            $stmt->execute([$olderThanSeconds]);
        } catch (PDOException $e) {
            error_log("Rate limit cleanup failed: " . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     *
     * @return string IP address
     */
    public static function getClientIp() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    /**
     * Apply rate limit and return appropriate HTTP response if blocked
     *
     * @param string $action Action being performed
     * @param array $limits Rate limit configuration
     * @return bool True if allowed, sends HTTP 429 and returns false if blocked
     */
    public function enforce($action, $limits = ['max' => 10, 'window' => 60, 'block' => 300]) {
        $ip = self::getClientIp();
        $result = $this->checkLimit($ip, $action, $limits['max'], $limits['window'], $limits['block']);

        if (!$result['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . $result['retry_after']);
            header('X-RateLimit-Limit: ' . $limits['max']);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $result['reset_at']);

            echo json_encode([
                'error' => 'Too many requests',
                'retry_after' => $result['retry_after']
            ]);
            exit;
        }

        // Add rate limit headers
        header('X-RateLimit-Limit: ' . $limits['max']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset_at']);

        return true;
    }
}