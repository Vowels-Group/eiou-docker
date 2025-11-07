<?php
# Copyright 2025

/**
 * ProcessManager - Graceful process lifecycle management
 *
 * Coordinates graceful shutdown of EIOU processes with timeout handling and
 * force termination. Works with SignalHandler to provide complete process
 * lifecycle management compatible with existing AbstractMessageProcessor architecture.
 *
 * Key Features:
 * - Graceful shutdown coordination with configurable timeout
 * - Force kill after timeout (prevents zombie processes)
 * - Process status tracking and monitoring
 * - Shutdown stage progression with logging
 * - Integration with existing lockfile mechanism
 * - Compatible with multiple processor types
 * - Cleanup callback registration
 *
 * Shutdown Stages:
 * 1. RUNNING - Normal operation
 * 2. SHUTDOWN_REQUESTED - Signal received, beginning graceful shutdown
 * 3. SHUTDOWN_IN_PROGRESS - Cleanup handlers executing
 * 4. SHUTDOWN_TIMEOUT - Timeout exceeded, force kill imminent
 * 5. SHUTDOWN_COMPLETE - Clean shutdown completed
 * 6. FORCED_TERMINATION - Process force killed after timeout
 *
 * Usage:
 * ```php
 * $manager = ProcessManager::getInstance();
 * $manager->setLockfile('/tmp/myprocess.lock');
 * $manager->setShutdownTimeout(30); // 30 seconds max
 *
 * // Register cleanup callbacks
 * $manager->registerCleanupHandler(function() {
 *     // Close connections, flush buffers, etc.
 * }, 10); // Priority 10
 *
 * // Start process lifecycle
 * $manager->start();
 *
 * // Main loop
 * while ($manager->isRunning()) {
 *     // Do work...
 *     $manager->checkShutdown(); // Check for shutdown signals
 * }
 *
 * // Cleanup
 * $manager->shutdown();
 * ```
 *
 * @package Services
 */

require_once(__DIR__ . "/SignalHandler.php");
require_once(__DIR__ . "/../utils/SecureLogger.php");
require_once(__DIR__ . "/../core/Constants.php");

class ProcessManager {
    /**
     * @var ProcessManager|null Singleton instance
     */
    private static ?ProcessManager $instance = null;

    /**
     * @var string|null Lockfile path for single-instance enforcement
     */
    private ?string $lockfile = null;

    /**
     * @var int|null Process ID
     */
    private ?int $pid = null;

    /**
     * @var SignalHandler Signal handler instance
     */
    private SignalHandler $signalHandler;

    /**
     * @var string Current shutdown stage
     */
    private string $stage = self::STAGE_RUNNING;

    /**
     * @var int Timestamp when shutdown started (seconds)
     */
    private int $shutdownStartTime = 0;

    /**
     * @var int Maximum seconds to wait for graceful shutdown before force kill
     */
    private int $shutdownTimeout = 30;

    /**
     * @var array Cleanup handlers [priority => [callbacks]]
     */
    private array $cleanupHandlers = [];

    /**
     * @var string Process name for logging
     */
    private string $processName = 'Process';

    /**
     * Process lifecycle stages
     */
    const STAGE_RUNNING = 'RUNNING';
    const STAGE_SHUTDOWN_REQUESTED = 'SHUTDOWN_REQUESTED';
    const STAGE_SHUTDOWN_IN_PROGRESS = 'SHUTDOWN_IN_PROGRESS';
    const STAGE_SHUTDOWN_TIMEOUT = 'SHUTDOWN_TIMEOUT';
    const STAGE_SHUTDOWN_COMPLETE = 'SHUTDOWN_COMPLETE';
    const STAGE_FORCED_TERMINATION = 'FORCED_TERMINATION';

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->pid = getmypid();
        $this->signalHandler = SignalHandler::getInstance();

        // Register with signal handler
        $this->signalHandler->registerHandler(
            [$this, 'handleShutdownSignal'],
            100 // High priority - we coordinate shutdown
        );

        SecureLogger::info("ProcessManager initialized", [
            'pid' => $this->pid,
            'timeout' => $this->shutdownTimeout
        ]);
    }

    /**
     * Get singleton instance
     *
     * @return ProcessManager
     */
    public static function getInstance(): ProcessManager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set process name for logging
     *
     * @param string $name Process name (e.g., "Transaction Processor", "P2P Processor")
     * @return self For method chaining
     */
    public function setProcessName(string $name): self {
        $this->processName = $name;
        SecureLogger::debug("Process name set", ['name' => $name]);
        return $this;
    }

    /**
     * Set lockfile path
     *
     * Enables single-instance enforcement. Lockfile will be created on start()
     * and removed on shutdown().
     *
     * @param string $lockfile Absolute path to lockfile
     * @return self For method chaining
     */
    public function setLockfile(string $lockfile): self {
        $this->lockfile = $lockfile;
        SecureLogger::debug("Lockfile configured", ['lockfile' => $lockfile]);
        return $this;
    }

    /**
     * Set graceful shutdown timeout
     *
     * After this many seconds, the process will be force killed if still running.
     *
     * @param int $seconds Timeout in seconds (default: 30)
     * @return self For method chaining
     */
    public function setShutdownTimeout(int $seconds): self {
        if ($seconds < 1) {
            throw new InvalidArgumentException("Shutdown timeout must be at least 1 second");
        }

        $this->shutdownTimeout = $seconds;
        SecureLogger::debug("Shutdown timeout configured", ['timeout_seconds' => $seconds]);
        return $this;
    }

    /**
     * Register a cleanup handler
     *
     * Cleanup handlers are invoked during shutdown in priority order (highest first).
     * Use these to flush buffers, close connections, save state, etc.
     *
     * @param callable $callback Cleanup function: fn(): void
     * @param int $priority Higher priorities execute first (default: 50)
     * @return self For method chaining
     */
    public function registerCleanupHandler(callable $callback, int $priority = 50): self {
        if (!isset($this->cleanupHandlers[$priority])) {
            $this->cleanupHandlers[$priority] = [];
        }

        $this->cleanupHandlers[$priority][] = $callback;

        // Keep handlers sorted by priority (descending)
        krsort($this->cleanupHandlers);

        SecureLogger::debug("Cleanup handler registered", [
            'priority' => $priority,
            'total_handlers' => $this->getTotalCleanupHandlerCount()
        ]);

        return $this;
    }

    /**
     * Get total number of cleanup handlers
     *
     * @return int Handler count
     */
    private function getTotalCleanupHandlerCount(): int {
        $count = 0;
        foreach ($this->cleanupHandlers as $callbacks) {
            $count += count($callbacks);
        }
        return $count;
    }

    /**
     * Start the process lifecycle
     *
     * - Checks for existing instances (if lockfile configured)
     * - Creates lockfile with current PID
     * - Installs signal handlers
     * - Sets stage to RUNNING
     *
     * @throws RuntimeException If another instance is running or lockfile cannot be created
     * @return void
     */
    public function start(): void {
        SecureLogger::info("Starting process lifecycle", [
            'process' => $this->processName,
            'pid' => $this->pid,
            'lockfile' => $this->lockfile
        ]);

        // Check for single instance (if lockfile configured)
        if ($this->lockfile !== null) {
            $this->checkSingleInstance();
            $this->createLockfile();
        }

        // Install signal handlers
        $this->signalHandler->install();

        // Set stage to running
        $this->stage = self::STAGE_RUNNING;

        SecureLogger::info("Process started successfully", [
            'process' => $this->processName,
            'pid' => $this->pid,
            'stage' => $this->stage
        ]);
    }

    /**
     * Check if another instance is already running
     *
     * @throws RuntimeException If another instance detected
     * @return void
     */
    private function checkSingleInstance(): void {
        if (!file_exists($this->lockfile)) {
            return;
        }

        $existingPid = trim(file_get_contents($this->lockfile));

        // Check if the process is still running
        if ($existingPid && file_exists("/proc/$existingPid")) {
            $message = "Another instance is already running";
            SecureLogger::error($message, [
                'lockfile' => $this->lockfile,
                'existing_pid' => $existingPid,
                'current_pid' => $this->pid
            ]);

            throw new RuntimeException("$message (PID: $existingPid)");
        }

        // Stale lockfile - remove it
        SecureLogger::warning("Removing stale lockfile", [
            'lockfile' => $this->lockfile,
            'stale_pid' => $existingPid
        ]);

        unlink($this->lockfile);
    }

    /**
     * Create lockfile with current PID
     *
     * @throws RuntimeException If lockfile cannot be created
     * @return void
     */
    private function createLockfile(): void {
        $written = file_put_contents($this->lockfile, $this->pid);

        if ($written === false) {
            $message = "Failed to create lockfile";
            SecureLogger::error($message, [
                'lockfile' => $this->lockfile,
                'pid' => $this->pid
            ]);

            throw new RuntimeException("$message: {$this->lockfile}");
        }

        SecureLogger::debug("Lockfile created", [
            'lockfile' => $this->lockfile,
            'pid' => $this->pid
        ]);
    }

    /**
     * Check if process is running
     *
     * Returns false if shutdown has been requested or is in progress.
     *
     * @return bool True if process should continue running
     */
    public function isRunning(): bool {
        return $this->stage === self::STAGE_RUNNING;
    }

    /**
     * Check for shutdown signals and handle timeout
     *
     * This should be called regularly in the main process loop.
     * Handles shutdown progression and timeout enforcement.
     *
     * @return void
     */
    public function checkShutdown(): void {
        // Dispatch any pending signals
        $this->signalHandler->dispatch();

        // If shutdown not requested, nothing to do
        if (!$this->signalHandler->shouldShutdown()) {
            return;
        }

        // Handle shutdown stages
        switch ($this->stage) {
            case self::STAGE_RUNNING:
                // Shutdown just requested - begin graceful shutdown
                $this->beginShutdown();
                break;

            case self::STAGE_SHUTDOWN_REQUESTED:
            case self::STAGE_SHUTDOWN_IN_PROGRESS:
                // Check if timeout exceeded
                $elapsed = time() - $this->shutdownStartTime;

                if ($elapsed >= $this->shutdownTimeout) {
                    $this->handleShutdownTimeout();
                } else {
                    // Log progress periodically (every 5 seconds)
                    if ($elapsed % 5 === 0) {
                        SecureLogger::debug("Shutdown in progress", [
                            'stage' => $this->stage,
                            'elapsed_seconds' => $elapsed,
                            'timeout_seconds' => $this->shutdownTimeout,
                            'remaining_seconds' => $this->shutdownTimeout - $elapsed
                        ]);
                    }
                }
                break;

            case self::STAGE_SHUTDOWN_TIMEOUT:
                // Timeout already handled, force kill imminent
                break;

            case self::STAGE_SHUTDOWN_COMPLETE:
            case self::STAGE_FORCED_TERMINATION:
                // Shutdown complete
                break;
        }
    }

    /**
     * Begin graceful shutdown
     *
     * Transitions to SHUTDOWN_REQUESTED stage and starts timeout timer.
     *
     * @return void
     */
    private function beginShutdown(): void {
        $this->stage = self::STAGE_SHUTDOWN_REQUESTED;
        $this->shutdownStartTime = time();

        SecureLogger::info("Graceful shutdown initiated", [
            'process' => $this->processName,
            'pid' => $this->pid,
            'stage' => $this->stage,
            'timeout_seconds' => $this->shutdownTimeout,
            'timestamp' => date(Constants::DISPLAY_DATE_FORMAT)
        ]);
    }

    /**
     * Handle shutdown timeout
     *
     * Called when graceful shutdown exceeds timeout.
     * Logs warning and prepares for force termination.
     *
     * @return void
     */
    private function handleShutdownTimeout(): void {
        $this->stage = self::STAGE_SHUTDOWN_TIMEOUT;

        SecureLogger::warning("Shutdown timeout exceeded - force kill will occur", [
            'process' => $this->processName,
            'pid' => $this->pid,
            'timeout_seconds' => $this->shutdownTimeout,
            'stage' => $this->stage
        ]);

        // Attempt force kill
        $this->forceKill();
    }

    /**
     * Force kill the process
     *
     * Sends SIGKILL to current process after timeout exceeded.
     * This is the last resort when graceful shutdown fails.
     *
     * @return void
     */
    private function forceKill(): void {
        $this->stage = self::STAGE_FORCED_TERMINATION;

        SecureLogger::critical("Force killing process", [
            'process' => $this->processName,
            'pid' => $this->pid,
            'stage' => $this->stage
        ]);

        // Try to clean up lockfile before kill
        $this->removeLockfile();

        // Send SIGKILL to self
        posix_kill($this->pid, SIGKILL);

        // If we're still here, log failure (shouldn't reach this)
        SecureLogger::error("Force kill failed - process still running", [
            'pid' => $this->pid
        ]);
    }

    /**
     * Handle shutdown signal callback
     *
     * Called by SignalHandler when a shutdown signal is received.
     *
     * @param int $signal Signal number
     * @return void
     */
    public function handleShutdownSignal(int $signal): void {
        $signalNames = [
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
            SIGQUIT => 'SIGQUIT'
        ];

        $signalName = $signalNames[$signal] ?? "UNKNOWN($signal)";

        SecureLogger::info("Shutdown signal received in ProcessManager", [
            'process' => $this->processName,
            'signal' => $signalName,
            'pid' => $this->pid,
            'current_stage' => $this->stage
        ]);

        // Transition to shutdown requested if still running
        if ($this->stage === self::STAGE_RUNNING) {
            $this->beginShutdown();
        }
    }

    /**
     * Perform graceful shutdown
     *
     * Executes cleanup handlers in priority order and removes lockfile.
     * Call this explicitly when process is ready to exit.
     *
     * @return void
     */
    public function shutdown(): void {
        // Prevent re-entry
        if ($this->stage === self::STAGE_SHUTDOWN_COMPLETE ||
            $this->stage === self::STAGE_FORCED_TERMINATION) {
            return;
        }

        $this->stage = self::STAGE_SHUTDOWN_IN_PROGRESS;

        SecureLogger::info("Executing graceful shutdown", [
            'process' => $this->processName,
            'pid' => $this->pid,
            'stage' => $this->stage,
            'cleanup_handlers' => $this->getTotalCleanupHandlerCount()
        ]);

        // Execute cleanup handlers in priority order
        $this->executeCleanupHandlers();

        // Remove lockfile
        $this->removeLockfile();

        // Restore signal handlers
        $this->signalHandler->restore();

        // Mark shutdown complete
        $this->stage = self::STAGE_SHUTDOWN_COMPLETE;

        SecureLogger::info("Graceful shutdown completed", [
            'process' => $this->processName,
            'pid' => $this->pid,
            'stage' => $this->stage
        ]);
    }

    /**
     * Execute all cleanup handlers
     *
     * Handlers are called in priority order (highest first).
     * Exceptions are caught to prevent cascade failures.
     *
     * @return void
     */
    private function executeCleanupHandlers(): void {
        $totalHandlers = $this->getTotalCleanupHandlerCount();

        if ($totalHandlers === 0) {
            SecureLogger::debug("No cleanup handlers registered");
            return;
        }

        SecureLogger::info("Executing cleanup handlers", [
            'handler_count' => $totalHandlers
        ]);

        $handlerIndex = 0;
        foreach ($this->cleanupHandlers as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $handlerIndex++;

                // Check for timeout
                if ($this->shutdownStartTime > 0) {
                    $elapsed = time() - $this->shutdownStartTime;
                    if ($elapsed >= $this->shutdownTimeout) {
                        SecureLogger::warning("Cleanup handler execution aborted due to timeout", [
                            'executed' => "$handlerIndex/$totalHandlers",
                            'elapsed_seconds' => $elapsed,
                            'timeout_seconds' => $this->shutdownTimeout
                        ]);
                        $this->handleShutdownTimeout();
                        return;
                    }
                }

                try {
                    SecureLogger::debug("Executing cleanup handler", [
                        'index' => "$handlerIndex/$totalHandlers",
                        'priority' => $priority
                    ]);

                    $callback();

                    SecureLogger::debug("Cleanup handler completed", [
                        'index' => "$handlerIndex/$totalHandlers"
                    ]);

                } catch (Throwable $e) {
                    // Don't let handler exceptions break cleanup sequence
                    SecureLogger::error("Cleanup handler threw exception", [
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

        SecureLogger::info("All cleanup handlers completed", [
            'handlers_executed' => $totalHandlers
        ]);
    }

    /**
     * Remove lockfile
     *
     * @return void
     */
    private function removeLockfile(): void {
        if ($this->lockfile === null || !file_exists($this->lockfile)) {
            return;
        }

        if (unlink($this->lockfile)) {
            SecureLogger::debug("Lockfile removed", [
                'lockfile' => $this->lockfile
            ]);
        } else {
            SecureLogger::warning("Failed to remove lockfile", [
                'lockfile' => $this->lockfile
            ]);
        }
    }

    /**
     * Get current process status
     *
     * Returns diagnostic information about process state.
     *
     * @return array Status information
     */
    public function getStatus(): array {
        $status = [
            'process' => $this->processName,
            'pid' => $this->pid,
            'stage' => $this->stage,
            'is_running' => $this->isRunning(),
            'lockfile' => $this->lockfile,
            'shutdown_timeout_seconds' => $this->shutdownTimeout,
            'cleanup_handlers' => $this->getTotalCleanupHandlerCount(),
            'signal_handler_status' => $this->signalHandler->getStatus()
        ];

        if ($this->shutdownStartTime > 0) {
            $elapsed = time() - $this->shutdownStartTime;
            $status['shutdown_elapsed_seconds'] = $elapsed;
            $status['shutdown_remaining_seconds'] = max(0, $this->shutdownTimeout - $elapsed);
        }

        return $status;
    }

    /**
     * Reset process manager state (for testing)
     *
     * @return void
     */
    public function reset(): void {
        SecureLogger::warning("ProcessManager state reset", [
            'previous_stage' => $this->stage,
            'pid' => $this->pid
        ]);

        $this->stage = self::STAGE_RUNNING;
        $this->shutdownStartTime = 0;
        $this->cleanupHandlers = [];

        // Also reset signal handler
        $this->signalHandler->reset();
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
        // If process is still running, perform shutdown
        if ($this->isRunning()) {
            SecureLogger::warning("ProcessManager destructor: forcing shutdown");
            $this->shutdown();
        }
    }
}
