<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Rate Limiter Service Interface
 *
 * Defines the contract for rate limiting functionality.
 * Prevents abuse and brute force attacks by limiting the number
 * of requests per time window with configurable blocking periods.
 *
 * @package Eiou\Contracts
 */
interface RateLimiterServiceInterface
{
    /**
     * Check if an action is rate limited
     *
     * Evaluates whether the specified action should be allowed based on
     * the current rate limit state. Cleans up old entries, checks for
     * existing blocks, and updates attempt counts.
     *
     * @param string $identifier User identifier (IP, user ID, etc.)
     * @param string $action Action being performed
     * @param int $maxAttempts Maximum attempts allowed (default: 10)
     * @param int $windowSeconds Time window in seconds (default: 60)
     * @param int $blockSeconds How long to block after exceeding limit (default: 300)
     * @return array Contains keys:
     *               - 'allowed' (bool): Whether the action is permitted
     *               - 'remaining' (int): Number of attempts remaining
     *               - 'reset_at' (int|string): Timestamp when limit resets
     *               - 'retry_after' (int): Seconds until retry allowed (if blocked)
     */
    public function checkLimit(
        string $identifier,
        string $action,
        int $maxAttempts = 10,
        int $windowSeconds = 60,
        int $blockSeconds = 300
    ): array;

    /**
     * Reset rate limit for a specific identifier and action
     *
     * Clears the rate limit state, allowing the identifier to start fresh.
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     * @return void
     */
    public function reset(string $identifier, string $action): void;

    /**
     * Get client IP address
     *
     * Extracts the client IP address from various headers, supporting
     * proxies and CDNs (Cloudflare, X-Forwarded-For, etc.).
     *
     * @return string IP address (returns '0.0.0.0' if not determinable)
     */
    public static function getClientIp(): string;

    /**
     * Apply rate limit and return appropriate HTTP response if blocked
     *
     * Convenience method that checks the rate limit and sends HTTP 429
     * response with appropriate headers if the limit is exceeded.
     *
     * @param string $action Action being performed
     * @param array $limits Rate limit configuration with keys:
     *                      - 'max' (int): Maximum attempts (default: 10)
     *                      - 'window' (int): Time window in seconds (default: 60)
     *                      - 'block' (int): Block duration in seconds (default: 300)
     * @return bool True if allowed, sends HTTP 429 and exits if blocked
     */
    public function enforce(
        string $action,
        array $limits = ['max' => 10, 'window' => 60, 'block' => 300]
    ): bool;
}
