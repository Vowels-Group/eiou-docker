<?php
# Copyright 2025

/**
 * Abstract base class for message processors
 *
 * Provides common functionality for all message processing daemons including:
 * - Adaptive polling
 * - Statistics tracking
 * - Signal handling
 * - Lockfile management
 * - Logging
 *
 */

require_once(__DIR__ . "/../utils/AdaptivePoller.php");
require_once(__DIR__ . "/../core/Constants.php");

abstract class AbstractMessageProcessor {
    protected AdaptivePoller $poller;
    protected int $totalProcessed = 0;
    protected int $lastLogTime;
    protected bool $shouldStop = false;
    protected string $lockfile;
    protected int $logInterval;
    protected array $pollerConfig;

    /**
     * @var int Maximum graceful shutdown time in seconds
     */
    protected int $shutdownTimeout = 30;

    /**
     * @var float|null Timestamp when shutdown was initiated
     */
    protected ?float $shutdownStartTime = null;

    /**
     * @var bool Flag to track if shutdown is in progress
     */
    protected bool $shutdownInProgress = false;

    /**
     * @var int Count of shutdown signals received (to handle duplicates)
     */
    protected int $shutdownSignalCount = 0;

    /**
     * @var PDO|null Database connection for cleanup
     */
    protected ?PDO $pdo = null;

    /**
     * Constructor
     *
     * @param array $pollerConfig Configuration for adaptive polling
     * @param string $lockfile Path to lockfile for ensuring single instance
     * @param int $logInterval Seconds between statistics logging
     * @param int $shutdownTimeout Maximum shutdown time in seconds
     */
    public function __construct(array $pollerConfig, string $lockfile, int $logInterval = 60, int $shutdownTimeout = 30) {
        $this->pollerConfig = $pollerConfig;
        $this->lockfile = $lockfile;
        $this->logInterval = $logInterval;
        $this->lastLogTime = time();
        $this->shutdownTimeout = $shutdownTimeout;

        // Set up signal handlers for graceful shutdown
        pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
        pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
        pcntl_signal(SIGHUP, [$this, 'handleReloadSignal']);
        pcntl_signal(SIGQUIT, [$this, 'handleQuitSignal']);
    }

    /**
     * Initialize the processor
     *
     * Sets up adaptive poller and ensures single instance
     */
    public function initialize(): void {
        $this->checkSingleInstance();
        $this->poller = new AdaptivePoller($this->pollerConfig);
        $this->logStartup();
    }

    /**
     * Main processing loop
     *
     * Continuously processes messages until shutdown signal received
     */
    public function run(): void {
        $this->initialize();

        while (!$this->shouldStop) {
            // Check for signals
            pcntl_signal_dispatch();

            // Check for shutdown timeout
            if ($this->shutdownInProgress && $this->hasShutdownTimedOut()) {
                $this->handleShutdownTimeout();
                break;
            }

            // Process messages (implemented by concrete classes)
            $before = microtime(true);
            $processed = $this->processMessages();
            $hadWork = $processed > 0;

            if ($hadWork) {
                $this->totalProcessed += $processed;
            }

            // Log statistics periodically
            $this->maybeLogStatistics();

            // Adaptive polling wait
            $this->poller->wait(0, $hadWork);
        }

        $this->shutdown();
    }

    /**
     * Handle shutdown timeout - force exit after graceful shutdown exceeds timeout
     */
    protected function handleShutdownTimeout(): void {
        $elapsed = round(microtime(true) - $this->shutdownStartTime, 2);

        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "WARNING: Graceful shutdown timed out after {$elapsed}s, forcing exit...\n";

        // Log timeout event
        if (class_exists('SecureLogger')) {
            SecureLogger::warning("Processor shutdown timeout - forcing exit", [
                'processor' => $this->getProcessorName(),
                'pid' => getmypid(),
                'timeout' => $this->shutdownTimeout,
                'elapsed' => $elapsed
            ]);
        }

        // Minimal cleanup - just remove lockfile
        if (file_exists($this->lockfile)) {
            unlink($this->lockfile);
        }

        // Force exit with error code
        exit(124); // Exit code 124 commonly indicates timeout
    }

    /**
     * Check and ensure only one instance is running
     *
     * @throws Exception if another instance is already running
     */
    protected function checkSingleInstance(): void {
        if (file_exists($this->lockfile)) {
            $pid = trim(file_get_contents($this->lockfile));

            // Check if the process is still running
            if ($pid && file_exists("/proc/$pid")) {
                $message = "Another instance is already running (PID: $pid)";
                SecureLogger::warning($message, ['lockfile' => $this->lockfile, 'pid' => $pid]);
                echo $message . "\n";
                exit(1); // Exit with error code - another instance is running
            }

            // Stale lockfile, remove it
            SecureLogger::info("Removing stale lockfile", ['lockfile' => $this->lockfile, 'old_pid' => $pid]);
            unlink($this->lockfile);
        }

        // Create new lockfile with current PID
        file_put_contents($this->lockfile, getmypid());
    }

    /**
     * Log startup message
     */
    protected function logStartup(): void {
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo $this->getProcessorName() . " processor started with adaptive polling\n";
    }

    /**
     * Log statistics if enough time has passed
     */
    protected function maybeLogStatistics(): void {
        if (time() - $this->lastLogTime >= $this->logInterval) {
            $stats = $this->poller->getStats();

            $logMessage = $this->getProcessorName() === 'Cleanup'
                ? 'Cleaned'
                : 'Processed';

            echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
            echo "{$logMessage}: {$this->totalProcessed}, ";
            echo "Interval: {$stats['current_interval_ms']}ms, ";
            echo "Empty cycles: {$stats['consecutive_empty']}";

            // Additional stats for non-cleanup processors
            if ($this->getProcessorName() !== 'Cleanup') {
                echo ", Success cycles: {$stats['consecutive_success']}";
            }

            echo "\n";

            $this->lastLogTime = time();
            $this->totalProcessed = 0;
        }
    }

    /**
     * Handle shutdown signals (SIGTERM, SIGINT)
     *
     * @param int $signal The signal received
     */
    public function handleShutdownSignal(int $signal): void {
        $this->shutdownSignalCount++;

        // Ignore duplicate signals if shutdown already in progress
        if ($this->shutdownInProgress) {
            echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
            echo "Shutdown already in progress (signal #{$this->shutdownSignalCount}), ignoring...\n";
            return;
        }

        $this->shutdownInProgress = true;
        $this->shutdownStartTime = microtime(true);

        $signalName = $signal === SIGTERM ? 'SIGTERM' : ($signal === SIGINT ? 'SIGINT' : "signal $signal");
        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Received {$signalName}, initiating graceful shutdown (timeout: {$this->shutdownTimeout}s)...\n";

        // Log to SecureLogger if available
        if (class_exists('SecureLogger')) {
            SecureLogger::info("Processor shutdown initiated", [
                'processor' => $this->getProcessorName(),
                'signal' => $signalName,
                'pid' => getmypid(),
                'timeout' => $this->shutdownTimeout
            ]);
        }

        $this->shouldStop = true;
    }

    /**
     * Handle reload signals (SIGHUP)
     *
     * @param int $signal The signal received
     */
    public function handleReloadSignal(int $signal): void {
        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Received SIGHUP, reloading configuration...\n";

        // Log reload event
        if (class_exists('SecureLogger')) {
            SecureLogger::info("Processor configuration reload requested", [
                'processor' => $this->getProcessorName(),
                'pid' => getmypid()
            ]);
        }

        // Subclasses can override onReload() to implement specific reload behavior
        $this->onReload();
    }

    /**
     * Handle quit signal (SIGQUIT) - for debugging with core dump
     *
     * @param int $signal The signal received
     */
    public function handleQuitSignal(int $signal): void {
        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Received SIGQUIT, forcing immediate shutdown for debug...\n";

        // Log quit event
        if (class_exists('SecureLogger')) {
            SecureLogger::warning("Processor SIGQUIT received - debug shutdown", [
                'processor' => $this->getProcessorName(),
                'pid' => getmypid(),
                'total_processed' => $this->totalProcessed
            ]);
        }

        // Minimal cleanup - just remove lockfile
        if (file_exists($this->lockfile)) {
            unlink($this->lockfile);
        }

        // Exit with signal for potential core dump
        exit(128 + SIGQUIT);
    }

    /**
     * Hook for subclasses to implement configuration reload
     * Called when SIGHUP is received
     */
    protected function onReload(): void {
        // Default implementation does nothing
        // Subclasses can override to reload configuration
    }

    /**
     * Check if shutdown has timed out
     *
     * @return bool True if shutdown has exceeded timeout
     */
    protected function hasShutdownTimedOut(): bool {
        if ($this->shutdownStartTime === null) {
            return false;
        }

        $elapsed = microtime(true) - $this->shutdownStartTime;
        return $elapsed >= $this->shutdownTimeout;
    }

    /**
     * Comprehensive cleanup on shutdown
     * Performs all cleanup procedures in order
     */
    protected function shutdown(): void {
        $startTime = microtime(true);

        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Starting shutdown cleanup for {$this->getProcessorName()} processor...\n";

        // 1. Log shutdown event
        if (class_exists('SecureLogger')) {
            SecureLogger::info("Processor shutdown cleanup started", [
                'processor' => $this->getProcessorName(),
                'pid' => getmypid(),
                'total_processed_session' => $this->totalProcessed
            ]);
        }

        // 2. Close database connections
        $this->closeDatabaseConnections();

        // 3. Flush buffers to disk
        $this->flushBuffers();

        // 4. Release file locks and remove lockfile
        $this->releaseLocks();

        // 5. Clear temporary files
        $this->clearTemporaryFiles();

        // 6. Call subclass-specific cleanup
        $this->onShutdown();

        // 7. Calculate shutdown duration
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // 8. Final log message
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "{$this->getProcessorName()} processor stopped (cleanup took {$duration}ms)\n";

        if (class_exists('SecureLogger')) {
            SecureLogger::info("Processor shutdown complete", [
                'processor' => $this->getProcessorName(),
                'pid' => getmypid(),
                'cleanup_duration_ms' => $duration
            ]);
        }
    }

    /**
     * Close database connections
     */
    protected function closeDatabaseConnections(): void {
        if ($this->pdo !== null) {
            $this->pdo = null;
            echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
            echo "Database connections closed\n";
        }
    }

    /**
     * Flush all buffers to disk
     */
    protected function flushBuffers(): void {
        // Flush PHP output buffers
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Flush file system buffers
        clearstatcache(true);
    }

    /**
     * Release file locks and remove lockfile
     */
    protected function releaseLocks(): void {
        if (file_exists($this->lockfile)) {
            unlink($this->lockfile);
            echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
            echo "Lockfile removed: {$this->lockfile}\n";
        }
    }

    /**
     * Clear temporary files created by this processor
     * Subclasses can override to add specific temporary file cleanup
     */
    protected function clearTemporaryFiles(): void {
        // Default implementation clears processor-specific temp files
        $tempPattern = sys_get_temp_dir() . '/' . strtolower($this->getProcessorName()) . '_*';
        $tempFiles = glob($tempPattern);

        if ($tempFiles) {
            foreach ($tempFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
            echo "Cleared " . count($tempFiles) . " temporary files\n";
        }
    }

    /**
     * Hook for subclasses to implement additional shutdown cleanup
     * Called during shutdown sequence
     */
    protected function onShutdown(): void {
        // Default implementation does nothing
        // Subclasses can override for specific cleanup
    }

    /**
     * Set database connection for cleanup tracking
     *
     * @param PDO $pdo Database connection
     */
    public function setDatabaseConnection(PDO $pdo): void {
        $this->pdo = $pdo;
    }

    /**
     * Process messages - implemented by concrete classes
     *
     * @return int Number of messages processed
     */
    abstract protected function processMessages(): int;

    /**
     * Get the processor name for logging
     *
     * @return string Processor name (e.g., "Cleanup", "Transaction", "P2P")
     */
    abstract protected function getProcessorName(): string;
}
