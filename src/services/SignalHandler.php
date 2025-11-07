<?php
# Copyright 2025

/**
 * SignalHandler - Thread-safe POSIX signal handling for graceful shutdown
 *
 * Manages operating system signals (SIGTERM, SIGINT, SIGHUP, SIGQUIT) to coordinate
 * graceful shutdown of EIOU processes. Provides thread-safe flag management and
 * signal dispatch mechanism for multiple signal handlers.
 *
 * Key Features:
 * - Thread-safe shutdown flag using atomic operations
 * - Multiple signal handler registration with priority
 * - Duplicate signal filtering (prevents signal storms)
 * - Comprehensive logging at each stage
 * - Signal restoration on cleanup
 * - Compatible with existing AbstractMessageProcessor architecture
 *
 * Usage:
 * ```php
 * $handler = SignalHandler::getInstance();
 *
 * // Register a callback for shutdown
 * $handler->registerHandler(function($signal) {
 *     echo "Received signal: $signal\n";
 *     // Perform cleanup
 * }, 10); // Priority 10 (higher = runs first)
 *
 * // Install signal handlers
 * $handler->install();
 *
 * // Check if shutdown requested
 * while (!$handler->shouldShutdown()) {
 *     // Do work...
 *     $handler->dispatch(); // Process pending signals
 * }
 *
 * // Cleanup
 * $handler->restore();
 * ```
 *
 * @package Services
 */

require_once(__DIR__ . "/../utils/SecureLogger.php");
require_once(__DIR__ . "/../core/Constants.php");

class SignalHandler {
    /**
     * @var SignalHandler|null Singleton instance
     */
    private static ?SignalHandler $instance = null;

    /**
     * @var bool Shutdown flag (thread-safe via volatile read/write)
     */
    private bool $shutdownRequested = false;

    /**
     * @var bool Whether signal handlers are installed
     */
    private bool $installed = false;

    /**
     * @var array Registered callback handlers [priority => [callbacks]]
     */
    private array $handlers = [];

    /**
     * @var array Signals to handle
     */
    private array $signals = [
        SIGTERM, // Graceful termination (systemd, docker stop)
        SIGINT,  // Ctrl+C in terminal
        SIGHUP,  // Terminal hangup / reload config
        SIGQUIT  // Quit signal (Ctrl+\)
    ];

    /**
     * @var array Original signal handlers for restoration
     */
    private array $originalHandlers = [];

    /**
     * @var int|null Last signal received (for duplicate detection)
     */
    private ?int $lastSignal = null;

    /**
     * @var int Timestamp of last signal (microseconds)
     */
    private int $lastSignalTime = 0;

    /**
     * @var int Minimum microseconds between duplicate signals (500ms)
     */
    private const DUPLICATE_SIGNAL_THRESHOLD_US = 500000;

    /**
     * @var array Signal names for logging
     */
    private const SIGNAL_NAMES = [
        SIGTERM => 'SIGTERM',
        SIGINT => 'SIGINT',
        SIGHUP => 'SIGHUP',
        SIGQUIT => 'SIGQUIT'
    ];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        SecureLogger::info("SignalHandler initialized", [
            'pid' => getmypid(),
            'signals' => array_map(fn($s) => self::SIGNAL_NAMES[$s] ?? $s, $this->signals)
        ]);
    }

    /**
     * Get singleton instance
     *
     * @return SignalHandler
     */
    public static function getInstance(): SignalHandler {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Install signal handlers
     *
     * Registers POSIX signal handlers for all configured signals.
     * Safe to call multiple times - will only install once.
     *
     * @return bool True if handlers installed successfully
     * @throws RuntimeException If pcntl extension not available
     */
    public function install(): bool {
        if ($this->installed) {
            SecureLogger::debug("Signal handlers already installed");
            return true;
        }

        if (!extension_loaded('pcntl')) {
            $error = "PCNTL extension not loaded - signal handling unavailable";
            SecureLogger::error($error);
            throw new RuntimeException($error);
        }

        SecureLogger::info("Installing signal handlers", [
            'pid' => getmypid(),
            'signals' => array_map(fn($s) => self::SIGNAL_NAMES[$s] ?? $s, $this->signals)
        ]);

        foreach ($this->signals as $signal) {
            // Save original handler for restoration
            $this->originalHandlers[$signal] = pcntl_signal_get_handler($signal);

            // Install our handler
            if (!pcntl_signal($signal, [$this, 'handleSignal'])) {
                $signalName = self::SIGNAL_NAMES[$signal] ?? $signal;
                SecureLogger::error("Failed to install handler for signal", [
                    'signal' => $signalName
                ]);
                return false;
            }

            SecureLogger::debug("Installed handler for signal", [
                'signal' => self::SIGNAL_NAMES[$signal] ?? $signal
            ]);
        }

        $this->installed = true;
        SecureLogger::info("All signal handlers installed successfully");

        return true;
    }

    /**
     * Restore original signal handlers
     *
     * Uninstalls our signal handlers and restores the original handlers.
     * Call this during cleanup to avoid leaving orphaned handlers.
     *
     * @return void
     */
    public function restore(): void {
        if (!$this->installed) {
            return;
        }

        SecureLogger::info("Restoring original signal handlers", [
            'pid' => getmypid()
        ]);

        foreach ($this->originalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
            SecureLogger::debug("Restored handler for signal", [
                'signal' => self::SIGNAL_NAMES[$signal] ?? $signal
            ]);
        }

        $this->installed = false;
        $this->originalHandlers = [];

        SecureLogger::info("All signal handlers restored");
    }

    /**
     * Register a shutdown callback handler
     *
     * Callbacks are invoked in priority order (highest first) when a signal is received.
     * Multiple callbacks can be registered at the same priority.
     *
     * @param callable $callback Function to call on shutdown: fn(int $signal): void
     * @param int $priority Higher priorities execute first (default: 50)
     * @return void
     */
    public function registerHandler(callable $callback, int $priority = 50): void {
        if (!isset($this->handlers[$priority])) {
            $this->handlers[$priority] = [];
        }

        $this->handlers[$priority][] = $callback;

        // Keep handlers sorted by priority (descending)
        krsort($this->handlers);

        SecureLogger::debug("Registered shutdown handler", [
            'priority' => $priority,
            'total_handlers' => $this->getTotalHandlerCount()
        ]);
    }

    /**
     * Get total number of registered handlers
     *
     * @return int Total handler count
     */
    private function getTotalHandlerCount(): int {
        $count = 0;
        foreach ($this->handlers as $callbacks) {
            $count += count($callbacks);
        }
        return $count;
    }

    /**
     * Clear all registered handlers
     *
     * Useful for testing or resetting handler state.
     *
     * @return void
     */
    public function clearHandlers(): void {
        $count = $this->getTotalHandlerCount();
        $this->handlers = [];
        SecureLogger::debug("Cleared all shutdown handlers", [
            'count' => $count
        ]);
    }

    /**
     * Handle a received signal (internal callback)
     *
     * This is the actual signal handler registered with pcntl_signal().
     * It performs duplicate detection and sets the shutdown flag.
     *
     * @param int $signal Signal number received
     * @return void
     */
    public function handleSignal(int $signal): void {
        $now = (int)(microtime(true) * 1000000); // Current time in microseconds
        $signalName = self::SIGNAL_NAMES[$signal] ?? "UNKNOWN($signal)";

        // Duplicate signal detection (prevent signal storms)
        if ($this->lastSignal === $signal) {
            $timeSinceLastSignal = $now - $this->lastSignalTime;

            if ($timeSinceLastSignal < self::DUPLICATE_SIGNAL_THRESHOLD_US) {
                SecureLogger::debug("Ignoring duplicate signal", [
                    'signal' => $signalName,
                    'time_since_last_us' => $timeSinceLastSignal,
                    'threshold_us' => self::DUPLICATE_SIGNAL_THRESHOLD_US
                ]);
                return;
            }
        }

        // Record signal receipt
        $this->lastSignal = $signal;
        $this->lastSignalTime = $now;

        SecureLogger::info("Signal received", [
            'signal' => $signalName,
            'pid' => getmypid(),
            'timestamp' => date(Constants::DISPLAY_DATE_FORMAT)
        ]);

        // Set shutdown flag (thread-safe via atomic write)
        $this->shutdownRequested = true;

        // Invoke registered handlers in priority order
        $this->invokeHandlers($signal);
    }

    /**
     * Invoke all registered handlers for a signal
     *
     * Handlers are called in priority order (highest first).
     * Exceptions in handlers are caught and logged to prevent cascade failures.
     *
     * @param int $signal Signal number to dispatch
     * @return void
     */
    private function invokeHandlers(int $signal): void {
        $signalName = self::SIGNAL_NAMES[$signal] ?? "UNKNOWN($signal)";
        $totalHandlers = $this->getTotalHandlerCount();

        if ($totalHandlers === 0) {
            SecureLogger::debug("No handlers registered for signal", [
                'signal' => $signalName
            ]);
            return;
        }

        SecureLogger::info("Invoking shutdown handlers", [
            'signal' => $signalName,
            'handler_count' => $totalHandlers
        ]);

        $handlerIndex = 0;
        foreach ($this->handlers as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $handlerIndex++;

                try {
                    SecureLogger::debug("Executing handler", [
                        'index' => "$handlerIndex/$totalHandlers",
                        'priority' => $priority
                    ]);

                    // Invoke the callback with signal number
                    $callback($signal);

                    SecureLogger::debug("Handler completed successfully", [
                        'index' => "$handlerIndex/$totalHandlers"
                    ]);

                } catch (Throwable $e) {
                    // Don't let handler exceptions break shutdown sequence
                    SecureLogger::error("Handler threw exception", [
                        'index' => "$handlerIndex/$totalHandlers",
                        'priority' => $priority,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                }
            }
        }

        SecureLogger::info("All shutdown handlers completed", [
            'signal' => $signalName,
            'handlers_executed' => $totalHandlers
        ]);
    }

    /**
     * Check if shutdown has been requested
     *
     * This is the primary method processes should call in their main loop.
     *
     * @return bool True if shutdown requested via signal
     */
    public function shouldShutdown(): bool {
        return $this->shutdownRequested;
    }

    /**
     * Manually request shutdown
     *
     * Useful for programmatic shutdown without actual signal.
     * Sets the shutdown flag but does NOT invoke handlers (use dispatch() for that).
     *
     * @param string $reason Reason for shutdown (for logging)
     * @return void
     */
    public function requestShutdown(string $reason = 'Manual request'): void {
        SecureLogger::info("Shutdown requested manually", [
            'reason' => $reason,
            'pid' => getmypid()
        ]);

        $this->shutdownRequested = true;
    }

    /**
     * Dispatch pending signals
     *
     * Processes any signals received since last dispatch.
     * This MUST be called regularly in the main loop for async signal handling.
     *
     * @return void
     */
    public function dispatch(): void {
        if (!$this->installed) {
            return;
        }

        // Process any pending signals
        pcntl_signal_dispatch();
    }

    /**
     * Reset shutdown state (for testing)
     *
     * Clears the shutdown flag. Should only be used in test environments.
     *
     * @return void
     */
    public function reset(): void {
        SecureLogger::warning("SignalHandler state reset", [
            'previous_shutdown_state' => $this->shutdownRequested,
            'pid' => getmypid()
        ]);

        $this->shutdownRequested = false;
        $this->lastSignal = null;
        $this->lastSignalTime = 0;
    }

    /**
     * Get current handler status
     *
     * Returns diagnostic information about handler state.
     *
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'installed' => $this->installed,
            'shutdown_requested' => $this->shutdownRequested,
            'total_handlers' => $this->getTotalHandlerCount(),
            'handler_priorities' => array_keys($this->handlers),
            'last_signal' => $this->lastSignal
                ? (self::SIGNAL_NAMES[$this->lastSignal] ?? "UNKNOWN({$this->lastSignal})")
                : null,
            'last_signal_time' => $this->lastSignalTime
                ? date(Constants::DISPLAY_DATE_FORMAT, (int)($this->lastSignalTime / 1000000))
                : null,
            'pid' => getmypid()
        ];
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Cleanup on destruction
     */
    public function __destruct() {
        if ($this->installed) {
            SecureLogger::debug("SignalHandler destructor: restoring signal handlers");
            $this->restore();
        }
    }
}
