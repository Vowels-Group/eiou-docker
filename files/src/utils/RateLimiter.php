<?php
# Copyright 2025

/**
 * Rate Limiter (Legacy Facade)
 *
 * This class now acts as a facade to RateLimiterService and RateLimiterRepository.
 * Maintained for backward compatibility with existing code.
 *
 * For new code, prefer using RateLimiterService via ServiceContainer:
 *   $container->getRateLimiterService()->checkLimit(...)
 *
 * @package Utils
 * @deprecated Use RateLimiterService via ServiceContainer instead
 */

class RateLimiter {
    private $pdo;
    private ?RateLimiterRepository $repository = null;
    private ?RateLimiterService $service = null;

    /**
     * Initialize rate limiter with database connection
     *
     * @param PDO $pdo Database connection
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->ensureTableExists();
        $this->initializeComponents();
    }

    /**
     * Ensure rate_limits table exists using schema definition
     */
    private function ensureTableExists(): void {
        try {
            require_once '/etc/eiou/src/database/databaseSchema.php';
            $sql = getRateLimitsTableSchema();
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            if (class_exists('SecureLogger')) {
                SecureLogger::error("Failed to create rate_limits table", [
                    'error' => $e->getMessage()
                ]);
            } else {
                error_log("Failed to create rate_limits table: " . $e->getMessage());
            }
        }
    }

    /**
     * Initialize repository and service components
     */
    private function initializeComponents(): void {
        require_once '/etc/eiou/src/database/RateLimiterRepository.php';
        require_once '/etc/eiou/src/services/RateLimiterService.php';

        $this->repository = new RateLimiterRepository($this->pdo);
        $this->service = new RateLimiterService($this->repository);
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
        return $this->service->checkLimit($identifier, $action, $maxAttempts, $windowSeconds, $blockSeconds);
    }

    /**
     * Reset rate limit for a specific identifier and action
     *
     * @param string $identifier User identifier
     * @param string $action Action being performed
     */
    public function reset($identifier, $action) {
        $this->service->reset($identifier, $action);
    }

    /**
     * Get client IP address
     *
     * @return string IP address
     */
    public static function getClientIp() {
        return RateLimiterService::getClientIp();
    }

    /**
     * Apply rate limit and return appropriate HTTP response if blocked
     *
     * @param string $action Action being performed
     * @param array $limits Rate limit configuration
     * @return bool True if allowed, sends HTTP 429 and returns false if blocked
     */
    public function enforce($action, $limits = ['max' => 10, 'window' => 60, 'block' => 300]) {
        return $this->service->enforce($action, $limits);
    }
}
