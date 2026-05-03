<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Contracts\RateLimiterServiceInterface;
use Eiou\Database\RateLimiterRepository;
use Eiou\Utils\Logger;
use Eiou\Utils\Security;

/**
 * Rate Limiter Service
 *
 * Business logic for rate limiting functionality.
 * Prevents abuse and brute force attacks.
 */
class RateLimiterService implements RateLimiterServiceInterface {
    private RateLimiterRepository $repository;
    private ?UserContext $userContext = null;

    /**
     * Initialize rate limiter with repository
     *
     * @param RateLimiterRepository $repository Rate limiter repository
     */
    public function __construct(RateLimiterRepository $repository) {
        $this->repository = $repository;
    }

    /**
     * Set user context for configurable rate limit values
     *
     * @param UserContext $userContext
     */
    public function setUserContext(UserContext $userContext): void {
        $this->userContext = $userContext;
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
    public function checkLimit(string $identifier, string $action, int $maxAttempts = Constants::RATE_LIMIT_MAX_ATTEMPTS, int $windowSeconds = Constants::RATE_LIMIT_WINDOW_SECONDS, int $blockSeconds = Constants::RATE_LIMIT_BLOCK_SECONDS): array {
        // Use UserContext values when defaults haven't been explicitly overridden
        if ($this->userContext) {
            if ($maxAttempts === Constants::RATE_LIMIT_MAX_ATTEMPTS) {
                $maxAttempts = $this->userContext->getRateLimitMaxAttempts();
            }
            if ($windowSeconds === Constants::RATE_LIMIT_WINDOW_SECONDS) {
                $windowSeconds = $this->userContext->getRateLimitWindowSeconds();
            }
            if ($blockSeconds === Constants::RATE_LIMIT_BLOCK_SECONDS) {
                $blockSeconds = $this->userContext->getRateLimitBlockSeconds();
            }
        }

        // If rate limiting is disabled or running under PHPUnit, always allow.
        //
        // The unconditional bypass used to honor the `EIOU_TEST_MODE` env
        // var directly, which meant a hostile orchestrator (or a typo in
        // docker-compose.yml) could disable login brute-force protection
        // in production by exporting one variable. Now the bypass is gated
        // on the `EIOU_TEST_MODE` PHP CONSTANT, which is only `define()`d
        // by `tests/bootstrap.php` (PHPUnit). Production builds never
        // define that constant — there's no env-var path that turns it
        // on at runtime.
        //
        // Belt-and-braces: if anyone ever DOES set `EIOU_TEST_MODE=true`
        // as an env var on a production container, we log a SECURITY
        // warning every request (no static-once gate) so it surfaces
        // loudly in logs without disabling rate limiting. Operators can
        // grep for the marker.
        $rateLimitEnabled = $this->userContext ? $this->userContext->getRateLimitEnabled() : Constants::RATE_LIMIT_ENABLED;
        $bypassEnabled = defined('EIOU_TEST_MODE') && EIOU_TEST_MODE === true;

        // Loud-warning channel: legacy env-var present but build-time
        // constant absent. Was previously THE bypass; now it's an alarm.
        if (!$bypassEnabled && getenv('EIOU_TEST_MODE') === 'true') {
            Logger::getInstance()->error(
                "SECURITY: EIOU_TEST_MODE env var set on a non-test build. " .
                "Rate-limit bypass IGNORED. If this is unexpected, audit your " .
                "container env config — the env var is no longer honored at " .
                "runtime; only the PHPUnit bootstrap constant disables rate limiting.",
                ['identifier_hash' => substr(hash('sha256', $identifier), 0, 12)]
            );
        }

        if (!$rateLimitEnabled || $bypassEnabled) {
            if ($bypassEnabled) {
                static $bypassWarned = false;
                if (!$bypassWarned) {
                    Logger::getInstance()->warning("Rate limiting bypassed: EIOU_TEST_MODE constant defined (PHPUnit bootstrap)");
                    $bypassWarned = true;
                }
            }
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
            // Block the user. Use the MySQL DATETIME wire format ('Y-m-d H:i:s')
            // — NOT DISPLAY_DATE_FORMAT, which is the European d/m/Y H:i:s
            // presentation format and causes SQLSTATE[22007] 1292 when written
            // back into the rate_limits.blocked_until DATETIME column.
            $blockedUntil = date('Y-m-d H:i:s', time() + $blockSeconds);
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
     * Delegates to Security::getClientIp() which only trusts proxy headers
     * when REMOTE_ADDR is in the trusted proxies list.
     *
     * @param \Eiou\Core\AppConfig $appConfig Typed config snapshot.
     * @return string IP address
     */
    public static function getClientIp(\Eiou\Core\AppConfig $appConfig): string {
        return Security::getClientIp($appConfig);
    }

}
