<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../core/ErrorCodes.php';
require_once __DIR__ . '/../core/Constants.php';
require_once __DIR__ . '/../contracts/RateLimiterServiceInterface.php';

/**
 * Rate Limiter Service
 *
 * Business logic for rate limiting functionality.
 * Prevents abuse and brute force attacks.
 */
class RateLimiterService implements RateLimiterServiceInterface {
    private RateLimiterRepository $repository;

    /**
     * Initialize rate limiter with repository
     *
     * @param RateLimiterRepository $repository Rate limiter repository
     */
    public function __construct(RateLimiterRepository $repository) {
        $this->repository = $repository;
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
    public function checkLimit(string $identifier, string $action, int $maxAttempts = 10, int $windowSeconds = 60, int $blockSeconds = 300): array {
        // If rate limiting is disabled or in test mode, always allow
        $testMode = getenv('EIOU_TEST_MODE') === 'true';
        if (!Constants::RATE_LIMIT_ENABLED || $testMode) {
            return [
                'allowed' => true,
                'remaining' => $maxAttempts,
                'reset_at' => time() + $windowSeconds
            ];
        }

        // Clean up old entries
        $this->repository->cleanup($windowSeconds);

        // Check if currently blocked
        $blocked = $this->repository->getBlockedRecord($identifier, $action);
        if ($blocked) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => $blocked['blocked_until'],
                'retry_after' => strtotime($blocked['blocked_until']) - time()
            ];
        }

        // Get current attempts within window
        $record = $this->repository->getAttemptsInWindow($identifier, $action, $windowSeconds);

        if (!$record) {
            // First attempt
            $this->repository->insertFirstAttempt($identifier, $action);
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
            $blockedUntil = date(Constants::DISPLAY_DATE_FORMAT, time() + $blockSeconds);
            $this->repository->blockIdentifier($identifier, $action, $attempts, $blockedUntil);

            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => $blockedUntil,
                'retry_after' => $blockSeconds
            ];
        }

        // Update attempts
        $this->repository->updateAttempts($identifier, $action, $attempts);

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
    public function reset(string $identifier, string $action): void {
        $this->repository->reset($identifier, $action);
    }

    /**
     * Get client IP address
     *
     * @return string IP address
     */
    public static function getClientIp(): string {
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
    public function enforce(string $action, array $limits = ['max' => 10, 'window' => 60, 'block' => 300]): bool {
        // If rate limiting is disabled, skip all rate limit checks
        if (!Constants::RATE_LIMIT_ENABLED) {
            return true;
        }

        $ip = self::getClientIp();
        $result = $this->checkLimit($ip, $action, $limits['max'], $limits['window'], $limits['block']);

        if (!$result['allowed']) {
            http_response_code(ErrorCodes::HTTP_TOO_MANY_REQUESTS);
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
