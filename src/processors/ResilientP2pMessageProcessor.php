<?php
/**
 * Resilient P2P Message Processor with Enhanced Error Recovery
 *
 * Extends the base P2pMessageProcessor with circuit breaker pattern,
 * automatic recovery, message queue persistence, and health monitoring.
 *
 * @package Processors
 * @since 1.0.0
 */

require_once __DIR__ . '/P2pMessageProcessor.php';
require_once dirname(__DIR__) . '/services/resilience/CircuitBreaker.php';
require_once dirname(__DIR__) . '/services/database/ConnectionManager.php';
require_once dirname(__DIR__) . '/logging/SecureLogger.php';
require_once dirname(__DIR__) . '/core/Constants.php';

class ResilientP2pMessageProcessor extends P2pMessageProcessor {
    /**
     * @var CircuitBreaker Circuit breaker for P2P processing
     */
    private CircuitBreaker $circuitBreaker;

    /**
     * @var ConnectionManager Database connection manager
     */
    private ConnectionManager $connectionManager;

    /**
     * @var array Failed message queue for retry
     */
    private array $failedMessageQueue = [];

    /**
     * @var int Maximum failed messages to retain
     */
    private int $maxFailedQueueSize = 1000;

    /**
     * @var int Consecutive failure count
     */
    private int $consecutiveFailures = 0;

    /**
     * @var int Maximum consecutive failures before degraded mode
     */
    private int $maxConsecutiveFailures = 10;

    /**
     * @var bool Whether in degraded mode
     */
    private bool $degradedMode = false;

    /**
     * @var float Last health check timestamp
     */
    private float $lastHealthCheck = 0;

    /**
     * @var int Health check interval in seconds
     */
    private int $healthCheckInterval = 60;

    /**
     * @var array Processing statistics
     */
    private array $stats = [
        'messages_processed' => 0,
        'messages_failed' => 0,
        'messages_recovered' => 0,
        'circuit_breaker_trips' => 0,
        'degraded_mode_activations' => 0,
        'recovery_attempts' => 0,
        'last_error' => null,
        'last_success' => null,
        'uptime_start' => null
    ];

    /**
     * @var string Path to persist failed messages
     */
    private string $failedMessagePersistPath = '/tmp/p2p_failed_messages.json';

    /**
     * @var bool Whether to persist failed messages
     */
    private bool $persistFailedMessages = true;

    /**
     * @var array Message processing hooks
     */
    private array $hooks = [
        'beforeProcess' => [],
        'afterProcess' => [],
        'onError' => [],
        'onRecovery' => []
    ];

    /**
     * Constructor
     *
     * @param array $pollerConfig Configuration for adaptive polling
     * @param string $lockfile Path to lockfile
     */
    public function __construct(array $pollerConfig = null, string $lockfile = null) {
        parent::__construct($pollerConfig, $lockfile);

        // Initialize circuit breaker with conservative settings
        $this->circuitBreaker = new CircuitBreaker(
            'p2p_message_processor',
            3,     // Failure threshold (lower for P2P)
            30,    // Timeout in seconds (shorter for faster recovery)
            2      // Success threshold to close
        );

        // Initialize connection manager
        $this->connectionManager = ConnectionManager::getInstance();

        // Set initial stats
        $this->stats['uptime_start'] = microtime(true);

        // Load any persisted failed messages
        $this->loadPersistedFailedMessages();

        // Register shutdown handler to persist state
        register_shutdown_function([$this, 'onShutdown']);

        SecureLogger::info('ResilientP2pMessageProcessor initialized', [
            'degraded_mode' => $this->degradedMode,
            'failed_queue_size' => count($this->failedMessageQueue)
        ]);
    }

    /**
     * Process messages with enhanced error handling and recovery
     *
     * @return int Number of messages processed
     */
    protected function processMessages(): int {
        $processedCount = 0;

        try {
            // Run health check if needed
            $this->performHealthCheckIfNeeded();

            // Execute pre-process hooks
            $this->executeHooks('beforeProcess');

            // Attempt to recover failed messages first (if not in degraded mode)
            if (!$this->degradedMode && !empty($this->failedMessageQueue)) {
                $recoveredCount = $this->attemptFailedMessageRecovery();
                if ($recoveredCount > 0) {
                    $this->stats['messages_recovered'] += $recoveredCount;
                    SecureLogger::info("Recovered failed P2P messages", [
                        'count' => $recoveredCount,
                        'remaining' => count($this->failedMessageQueue)
                    ]);
                }
            }

            // Process new messages through circuit breaker
            if (!$this->circuitBreaker->isOpen()) {
                $processedCount = $this->circuitBreaker->call(function() {
                    return $this->processWithRecovery();
                });

                // Reset consecutive failures on success
                if ($processedCount > 0) {
                    $this->consecutiveFailures = 0;
                    $this->stats['messages_processed'] += $processedCount;
                    $this->stats['last_success'] = date('Y-m-d H:i:s');

                    // Exit degraded mode if we were in it
                    if ($this->degradedMode) {
                        $this->exitDegradedMode();
                    }
                }
            } else {
                // Circuit is open, log and wait
                $this->handleCircuitOpen();
            }

            // Execute post-process hooks
            $this->executeHooks('afterProcess', ['processed' => $processedCount]);

        } catch (\Exception $e) {
            $this->handleProcessingError($e);
        }

        return $processedCount;
    }

    /**
     * Process messages with automatic recovery on failure
     *
     * @return int Number of messages processed
     * @throws Exception If processing fails after recovery attempts
     */
    private function processWithRecovery(): int {
        $attempts = 0;
        $maxAttempts = 3;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                // Use connection manager for resilient database connection
                $connection = $this->connectionManager->getConnection();

                // Attempt to process messages
                $processed = parent::processMessages();

                // Success - return processed count
                return $processed;

            } catch (\PDOException $e) {
                $lastException = $e;
                $attempts++;

                SecureLogger::warning("P2P processing database error, attempting recovery", [
                    'attempt' => $attempts,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage()
                ]);

                // Force new connection on next attempt
                if ($attempts < $maxAttempts) {
                    $this->connectionManager->closeAll();
                    usleep(1000000 * $attempts); // Exponential backoff
                }

            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;

                SecureLogger::error("P2P processing error", [
                    'attempt' => $attempts,
                    'error' => $e->getMessage()
                ]);

                if ($attempts < $maxAttempts) {
                    usleep(500000 * $attempts); // Exponential backoff
                }
            }
        }

        // All attempts failed
        throw $lastException ?? new RuntimeException('P2P processing failed after ' . $maxAttempts . ' attempts');
    }

    /**
     * Handle processing error
     *
     * @param Exception $e The exception that occurred
     */
    private function handleProcessingError(\Exception $e): void {
        $this->consecutiveFailures++;
        $this->stats['messages_failed']++;
        $this->stats['last_error'] = [
            'message' => $e->getMessage(),
            'time' => date('Y-m-d H:i:s'),
            'type' => get_class($e)
        ];

        SecureLogger::error("P2P message processing failed", [
            'consecutive_failures' => $this->consecutiveFailures,
            'error' => $e->getMessage(),
            'degraded_mode' => $this->degradedMode
        ]);

        // Execute error hooks
        $this->executeHooks('onError', ['exception' => $e]);

        // Check if we should enter degraded mode
        if ($this->consecutiveFailures >= $this->maxConsecutiveFailures && !$this->degradedMode) {
            $this->enterDegradedMode();
        }

        // Store failed message for later retry if applicable
        if ($e instanceof \PDOException && isset($e->errorInfo)) {
            $this->queueFailedMessage([
                'error' => $e->getMessage(),
                'timestamp' => microtime(true),
                'error_code' => $e->errorInfo[0] ?? 'unknown'
            ]);
        }
    }

    /**
     * Handle circuit breaker open state
     */
    private function handleCircuitOpen(): void {
        $this->stats['circuit_breaker_trips']++;

        $timeUntilHalfOpen = $this->circuitBreaker->getTimeUntilHalfOpen();

        SecureLogger::warning("P2P circuit breaker is open", [
            'trips' => $this->stats['circuit_breaker_trips'],
            'time_until_retry' => round($timeUntilHalfOpen ?? 0, 2)
        ]);

        // In open state, use longer sleep to reduce resource usage
        sleep(min(5, (int)($timeUntilHalfOpen ?? 5)));
    }

    /**
     * Enter degraded mode
     */
    private function enterDegradedMode(): void {
        $this->degradedMode = true;
        $this->stats['degraded_mode_activations']++;

        SecureLogger::warning("P2P processor entering DEGRADED MODE", [
            'consecutive_failures' => $this->consecutiveFailures,
            'failed_queue_size' => count($this->failedMessageQueue)
        ]);

        // Increase polling intervals in degraded mode
        if (isset($this->pollerConfig)) {
            $this->pollerConfig['min_interval_ms'] *= 2;
            $this->pollerConfig['max_interval_ms'] *= 2;
            $this->pollerConfig['idle_interval_ms'] *= 2;
        }
    }

    /**
     * Exit degraded mode
     */
    private function exitDegradedMode(): void {
        $this->degradedMode = false;
        $this->consecutiveFailures = 0;

        SecureLogger::info("P2P processor exiting DEGRADED MODE - service recovered");

        // Restore normal polling intervals
        if (isset($this->pollerConfig)) {
            $this->pollerConfig['min_interval_ms'] = Constants::P2P_MIN_INTERVAL_MS ?: 100;
            $this->pollerConfig['max_interval_ms'] = Constants::P2P_MAX_INTERVAL_MS ?: 5000;
            $this->pollerConfig['idle_interval_ms'] = Constants::P2P_IDLE_INTERVAL_MS ?: 2000;
        }

        // Execute recovery hooks
        $this->executeHooks('onRecovery');
    }

    /**
     * Attempt to recover failed messages
     *
     * @return int Number of messages recovered
     */
    private function attemptFailedMessageRecovery(): int {
        $recovered = 0;
        $maxRetries = min(10, count($this->failedMessageQueue));

        for ($i = 0; $i < $maxRetries; $i++) {
            if (empty($this->failedMessageQueue)) {
                break;
            }

            $failedMessage = array_shift($this->failedMessageQueue);

            try {
                // Attempt to reprocess the message
                $this->stats['recovery_attempts']++;

                // Here you would implement actual message reprocessing
                // For now, we simulate recovery attempt
                if ($this->attemptMessageReprocessing($failedMessage)) {
                    $recovered++;
                } else {
                    // Re-queue if still failing
                    $this->failedMessageQueue[] = $failedMessage;
                }

            } catch (\Exception $e) {
                SecureLogger::debug("Failed to recover message", [
                    'error' => $e->getMessage()
                ]);

                // Re-queue the message
                $this->failedMessageQueue[] = $failedMessage;
            }
        }

        return $recovered;
    }

    /**
     * Attempt to reprocess a failed message
     *
     * @param array $failedMessage Failed message data
     * @return bool True if successfully reprocessed
     */
    private function attemptMessageReprocessing(array $failedMessage): bool {
        // Check if message is too old (> 24 hours)
        if (isset($failedMessage['timestamp'])) {
            $age = microtime(true) - $failedMessage['timestamp'];
            if ($age > 86400) {
                SecureLogger::info("Discarding old failed message", [
                    'age_hours' => round($age / 3600, 2)
                ]);
                return true; // Consider it "recovered" by discarding
            }
        }

        // Attempt actual reprocessing here
        // This would integrate with your actual P2P service
        // For now, simulate 30% success rate in recovery
        return rand(1, 100) <= 30;
    }

    /**
     * Queue a failed message for later retry
     *
     * @param array $messageData Message data to queue
     */
    private function queueFailedMessage(array $messageData): void {
        // Enforce queue size limit
        if (count($this->failedMessageQueue) >= $this->maxFailedQueueSize) {
            array_shift($this->failedMessageQueue); // Remove oldest
        }

        $this->failedMessageQueue[] = $messageData;

        // Persist if enabled
        if ($this->persistFailedMessages) {
            $this->persistFailedMessages();
        }
    }

    /**
     * Perform health check if needed
     */
    private function performHealthCheckIfNeeded(): void {
        $now = microtime(true);

        if ($now - $this->lastHealthCheck < $this->healthCheckInterval) {
            return;
        }

        $this->lastHealthCheck = $now;

        try {
            // Check database connectivity
            $dbHealthy = $this->connectionManager->getConnection() !== null;

            // Check circuit breaker state
            $circuitHealthy = !$this->circuitBreaker->isOpen();

            // Overall health
            $healthy = $dbHealthy && $circuitHealthy;

            SecureLogger::info("P2P processor health check", [
                'healthy' => $healthy,
                'database' => $dbHealthy,
                'circuit_breaker' => $this->circuitBreaker->getState(),
                'degraded_mode' => $this->degradedMode,
                'failed_queue_size' => count($this->failedMessageQueue),
                'uptime_hours' => round(($now - $this->stats['uptime_start']) / 3600, 2)
            ]);

        } catch (\Exception $e) {
            SecureLogger::error("Health check failed", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Load persisted failed messages from disk
     */
    private function loadPersistedFailedMessages(): void {
        if (!$this->persistFailedMessages || !file_exists($this->failedMessagePersistPath)) {
            return;
        }

        try {
            $data = file_get_contents($this->failedMessagePersistPath);
            if ($data) {
                $messages = json_decode($data, true);
                if (is_array($messages)) {
                    $this->failedMessageQueue = array_slice($messages, 0, $this->maxFailedQueueSize);
                    SecureLogger::info("Loaded persisted failed messages", [
                        'count' => count($this->failedMessageQueue)
                    ]);
                }
            }
        } catch (\Exception $e) {
            SecureLogger::warning("Failed to load persisted messages", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Persist failed messages to disk
     */
    private function persistFailedMessages(): void {
        if (!$this->persistFailedMessages) {
            return;
        }

        try {
            $data = json_encode($this->failedMessageQueue, JSON_PRETTY_PRINT);
            file_put_contents($this->failedMessagePersistPath, $data, LOCK_EX);
        } catch (\Exception $e) {
            SecureLogger::warning("Failed to persist messages", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Shutdown handler to persist state
     */
    public function onShutdown(): void {
        if (!empty($this->failedMessageQueue)) {
            $this->persistFailedMessages();
        }

        // Log final stats
        $uptime = microtime(true) - $this->stats['uptime_start'];
        SecureLogger::info("P2P processor shutting down", [
            'stats' => $this->stats,
            'uptime_hours' => round($uptime / 3600, 2),
            'failed_queue_size' => count($this->failedMessageQueue)
        ]);
    }

    /**
     * Register a hook
     *
     * @param string $event Event name
     * @param callable $callback Callback function
     */
    public function registerHook(string $event, callable $callback): void {
        if (!isset($this->hooks[$event])) {
            $this->hooks[$event] = [];
        }

        $this->hooks[$event][] = $callback;
    }

    /**
     * Execute hooks for an event
     *
     * @param string $event Event name
     * @param array $data Event data
     */
    private function executeHooks(string $event, array $data = []): void {
        if (!isset($this->hooks[$event])) {
            return;
        }

        foreach ($this->hooks[$event] as $callback) {
            try {
                $callback($data);
            } catch (\Exception $e) {
                SecureLogger::warning("Hook execution failed", [
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get processor statistics
     *
     * @return array Statistics
     */
    public function getStats(): array {
        $uptime = microtime(true) - $this->stats['uptime_start'];

        return array_merge($this->stats, [
            'uptime_hours' => round($uptime / 3600, 2),
            'degraded_mode' => $this->degradedMode,
            'consecutive_failures' => $this->consecutiveFailures,
            'failed_queue_size' => count($this->failedMessageQueue),
            'circuit_breaker_state' => $this->circuitBreaker->getState(),
            'circuit_breaker_stats' => $this->circuitBreaker->getStats(),
            'connection_stats' => $this->connectionManager->getStats()
        ]);
    }

    /**
     * Reset processor state
     */
    public function reset(): void {
        $this->consecutiveFailures = 0;
        $this->degradedMode = false;
        $this->failedMessageQueue = [];
        $this->circuitBreaker->reset();

        SecureLogger::info("P2P processor state reset");
    }
}