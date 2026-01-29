<?php
namespace Eiou\Contracts;

/**
 * Rate Limiter Service Interface
 *
 * Defines the contract for rate limiting functionality.
 */
interface RateLimiterServiceInterface
{
    /**
     * Check if an action is rate limited.
     *
     * @param string $identifier User identifier (IP, user ID, etc.)
     * @param string $action Action being performed
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param int $blockSeconds How long to block after exceeding limit
     * @return array Rate limit status
     */
    public function checkLimit(
        string $identifier,
        string $action,
        int $maxAttempts = 10,
        int $windowSeconds = 60,
        int $blockSeconds = 300
    ): array;

    /**
     * Reset rate limit for a specific identifier and action.
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     * @return void
     */
    public function reset(string $identifier, string $action): void;

    /**
     * Apply rate limit and return appropriate HTTP response if blocked.
     *
     * @param string $action Action being performed
     * @param array $limits Rate limit configuration
     * @return bool True if allowed
     */
    public function enforce(
        string $action,
        array $limits = ['max' => 10, 'window' => 60, 'block' => 300]
    ): bool;
}
