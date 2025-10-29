<?php
/**
 * Database Connection Manager with Retry Logic and Circuit Breaker
 *
 * Provides resilient database connection management with automatic retry,
 * circuit breaker pattern, and connection pooling capabilities.
 *
 * @package Services\Database
 * @since 1.0.0
 */

require_once dirname(__DIR__, 2) . '/core/Constants.php';
require_once dirname(__DIR__, 2) . '/core/DatabaseContext.php';
require_once dirname(__DIR__) . '/resilience/CircuitBreaker.php';
require_once dirname(__DIR__, 2) . '/logging/SecureLogger.php';

class ConnectionManager {
    /**
     * @var ConnectionManager|null Singleton instance
     */
    private static ?ConnectionManager $instance = null;

    /**
     * @var PDO|null Active database connection
     */
    private ?PDO $connection = null;

    /**
     * @var CircuitBreaker Circuit breaker for connection failures
     */
    private CircuitBreaker $circuitBreaker;

    /**
     * @var array Connection pool for future multi-connection support
     */
    private array $connectionPool = [];

    /**
     * @var int Maximum pool size
     */
    private int $maxPoolSize = 5;

    /**
     * @var int Current retry attempt
     */
    private int $currentRetryAttempt = 0;

    /**
     * @var int Maximum retry attempts
     */
    private int $maxRetryAttempts = 3;

    /**
     * @var int Base retry delay in milliseconds
     */
    private int $baseRetryDelayMs = 1000;

    /**
     * @var float Exponential backoff multiplier
     */
    private float $backoffMultiplier = 2.0;

    /**
     * @var array Connection statistics
     */
    private array $stats = [
        'total_connections' => 0,
        'successful_connections' => 0,
        'failed_connections' => 0,
        'total_retries' => 0,
        'circuit_breaker_trips' => 0,
        'last_connection_time' => null,
        'last_failure_time' => null,
        'last_failure_reason' => null
    ];

    /**
     * @var bool Whether to use connection pooling
     */
    private bool $usePooling = false;

    /**
     * @var int Connection timeout in seconds
     */
    private int $connectionTimeout = 5;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        // Initialize circuit breaker with conservative thresholds
        $this->circuitBreaker = new CircuitBreaker(
            'database_connection',
            5,     // Failure threshold
            60,    // Timeout in seconds
            3      // Success threshold to close
        );

        // Load configuration from Constants if available
        if (defined('Constants::DB_MAX_RETRY_ATTEMPTS')) {
            $this->maxRetryAttempts = Constants::DB_MAX_RETRY_ATTEMPTS;
        }

        if (defined('Constants::DB_RETRY_DELAY_MS')) {
            $this->baseRetryDelayMs = Constants::DB_RETRY_DELAY_MS;
        }

        if (defined('Constants::DB_CONNECTION_TIMEOUT')) {
            $this->connectionTimeout = Constants::DB_CONNECTION_TIMEOUT;
        }
    }

    /**
     * Get singleton instance
     *
     * @return ConnectionManager
     */
    public static function getInstance(): ConnectionManager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a database connection with retry logic and circuit breaker
     *
     * @param bool $forceNew Force creation of a new connection
     * @return PDO Database connection
     * @throws RuntimeException If all connection attempts fail
     */
    public function getConnection(bool $forceNew = false): PDO {
        // Check circuit breaker state first
        if ($this->circuitBreaker->isOpen()) {
            $this->stats['circuit_breaker_trips']++;
            SecureLogger::warning('Circuit breaker is open for database connections', [
                'trips' => $this->stats['circuit_breaker_trips'],
                'last_failure' => $this->stats['last_failure_reason']
            ]);

            throw new RuntimeException(
                'Database connection circuit breaker is open. Service temporarily unavailable.',
                503
            );
        }

        // Return existing connection if valid and not forcing new
        if (!$forceNew && $this->connection !== null && $this->isConnectionValid($this->connection)) {
            return $this->connection;
        }

        // Attempt to create connection with retries
        $this->currentRetryAttempt = 0;
        $lastException = null;

        while ($this->currentRetryAttempt <= $this->maxRetryAttempts) {
            try {
                // Attempt connection through circuit breaker
                $connection = $this->circuitBreaker->call(function() {
                    return $this->createConnection();
                });

                if ($connection !== null) {
                    $this->connection = $connection;
                    $this->stats['successful_connections']++;
                    $this->stats['last_connection_time'] = date('Y-m-d H:i:s');

                    SecureLogger::info('Database connection established successfully', [
                        'attempt' => $this->currentRetryAttempt + 1,
                        'total_attempts' => $this->maxRetryAttempts + 1
                    ]);

                    return $this->connection;
                }
            } catch (\Exception $e) {
                $lastException = $e;
                $this->stats['failed_connections']++;
                $this->stats['last_failure_time'] = date('Y-m-d H:i:s');
                $this->stats['last_failure_reason'] = $e->getMessage();

                // Log the failure
                SecureLogger::error('Database connection attempt failed', [
                    'attempt' => $this->currentRetryAttempt + 1,
                    'error' => $e->getMessage(),
                    'will_retry' => $this->currentRetryAttempt < $this->maxRetryAttempts
                ]);

                // If not the last attempt, wait before retrying
                if ($this->currentRetryAttempt < $this->maxRetryAttempts) {
                    $this->stats['total_retries']++;
                    $this->waitBeforeRetry();
                }
            }

            $this->currentRetryAttempt++;
        }

        // All attempts failed
        SecureLogger::critical('All database connection attempts failed', [
            'total_attempts' => $this->maxRetryAttempts + 1,
            'stats' => $this->stats
        ]);

        throw new RuntimeException(
            'Failed to establish database connection after ' . ($this->maxRetryAttempts + 1) . ' attempts',
            500,
            $lastException
        );
    }

    /**
     * Create a new database connection
     *
     * @return PDO New database connection
     * @throws PDOException If connection fails
     */
    private function createConnection(): PDO {
        $this->stats['total_connections']++;

        // Get database configuration
        $databaseContext = DatabaseContext::getInstance();

        if ($databaseContext && $databaseContext->isInitialized()) {
            $dbHost = $databaseContext->getDbHost();
            $dbName = $databaseContext->getDbName();
            $dbUser = $databaseContext->getDbUser();
            $dbPass = $databaseContext->getDbPass();
        } else {
            // Fallback to global $database
            global $database;
            $dbHost = $database['dbHost'] ?? null;
            $dbName = $database['dbName'] ?? null;
            $dbUser = $database['dbUser'] ?? null;
            $dbPass = $database['dbPass'] ?? null;
        }

        // Validate configuration
        if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
            throw new RuntimeException("Database configuration incomplete");
        }

        // Create DSN with charset
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

        // PDO options with timeout
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            PDO::ATTR_TIMEOUT => $this->connectionTimeout
        ];

        // Create connection
        return new PDO($dsn, $dbUser, $dbPass, $options);
    }

    /**
     * Check if a connection is still valid
     *
     * @param PDO $connection Connection to check
     * @return bool True if connection is valid
     */
    private function isConnectionValid(PDO $connection): bool {
        try {
            // Simple ping query
            $stmt = $connection->query('SELECT 1');
            return $stmt !== false;
        } catch (\PDOException $e) {
            SecureLogger::debug('Connection validation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Wait before retrying with exponential backoff
     */
    private function waitBeforeRetry(): void {
        $delayMs = $this->baseRetryDelayMs * pow($this->backoffMultiplier, $this->currentRetryAttempt);
        $delayMs = min($delayMs, 30000); // Cap at 30 seconds

        SecureLogger::debug('Waiting before retry', [
            'delay_ms' => $delayMs,
            'attempt' => $this->currentRetryAttempt + 1
        ]);

        usleep($delayMs * 1000); // Convert to microseconds
    }

    /**
     * Get a connection from the pool (for future implementation)
     *
     * @return PDO|null Connection from pool or null if none available
     */
    public function getFromPool(): ?PDO {
        if (!$this->usePooling || empty($this->connectionPool)) {
            return null;
        }

        // Find a valid connection in the pool
        foreach ($this->connectionPool as $key => $conn) {
            if ($this->isConnectionValid($conn)) {
                // Remove from pool and return
                unset($this->connectionPool[$key]);
                $this->connectionPool = array_values($this->connectionPool);
                return $conn;
            }
        }

        return null;
    }

    /**
     * Return a connection to the pool (for future implementation)
     *
     * @param PDO $connection Connection to return to pool
     * @return bool True if added to pool, false if pool is full
     */
    public function returnToPool(PDO $connection): bool {
        if (!$this->usePooling || count($this->connectionPool) >= $this->maxPoolSize) {
            return false;
        }

        if ($this->isConnectionValid($connection)) {
            $this->connectionPool[] = $connection;
            return true;
        }

        return false;
    }

    /**
     * Close all connections and reset state
     */
    public function closeAll(): void {
        // Close main connection
        $this->connection = null;

        // Close pooled connections
        $this->connectionPool = [];

        // Reset circuit breaker
        $this->circuitBreaker->reset();

        SecureLogger::info('All database connections closed');
    }

    /**
     * Get connection statistics
     *
     * @return array Connection statistics
     */
    public function getStats(): array {
        return array_merge($this->stats, [
            'circuit_breaker_state' => $this->circuitBreaker->getState(),
            'pool_size' => count($this->connectionPool),
            'has_active_connection' => $this->connection !== null
        ]);
    }

    /**
     * Enable or disable connection pooling
     *
     * @param bool $enabled Whether to enable pooling
     * @param int $maxSize Maximum pool size
     */
    public function setPooling(bool $enabled, int $maxSize = 5): void {
        $this->usePooling = $enabled;
        $this->maxPoolSize = max(1, min($maxSize, 20)); // Cap between 1 and 20

        if (!$enabled) {
            $this->connectionPool = [];
        }

        SecureLogger::info('Connection pooling configuration updated', [
            'enabled' => $enabled,
            'max_size' => $this->maxPoolSize
        ]);
    }

    /**
     * Set retry configuration
     *
     * @param int $maxAttempts Maximum retry attempts
     * @param int $baseDelayMs Base retry delay in milliseconds
     * @param float $backoffMultiplier Exponential backoff multiplier
     */
    public function setRetryConfig(int $maxAttempts, int $baseDelayMs, float $backoffMultiplier = 2.0): void {
        $this->maxRetryAttempts = max(0, min($maxAttempts, 10)); // Cap between 0 and 10
        $this->baseRetryDelayMs = max(100, min($baseDelayMs, 10000)); // Cap between 100ms and 10s
        $this->backoffMultiplier = max(1.0, min($backoffMultiplier, 3.0)); // Cap between 1.0 and 3.0

        SecureLogger::info('Retry configuration updated', [
            'max_attempts' => $this->maxRetryAttempts,
            'base_delay_ms' => $this->baseRetryDelayMs,
            'backoff_multiplier' => $this->backoffMultiplier
        ]);
    }

    /**
     * Execute a query with automatic retry on failure
     *
     * @param callable $callback Query execution callback
     * @param int $maxAttempts Maximum attempts for this specific query
     * @return mixed Query result
     * @throws RuntimeException If all attempts fail
     */
    public function executeWithRetry(callable $callback, int $maxAttempts = null) {
        $maxAttempts = $maxAttempts ?? $this->maxRetryAttempts;
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxAttempts) {
            try {
                $connection = $this->getConnection();
                return $callback($connection);
            } catch (\PDOException $e) {
                $lastException = $e;

                SecureLogger::warning('Query execution failed, attempting retry', [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxAttempts + 1,
                    'error' => $e->getMessage()
                ]);

                // Force new connection on next attempt
                $this->connection = null;

                if ($attempt < $maxAttempts) {
                    usleep($this->baseRetryDelayMs * 1000 * pow($this->backoffMultiplier, $attempt));
                }
            }

            $attempt++;
        }

        throw new RuntimeException(
            'Query execution failed after ' . ($maxAttempts + 1) . ' attempts',
            500,
            $lastException
        );
    }
}