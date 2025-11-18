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
    protected ?int $shutdownStartTime = null;
    protected array $cleanupCallbacks = [];

    /**
     * Maximum shutdown time in seconds
     */
    protected const MAX_SHUTDOWN_TIME = 30;

    /**
     * Constructor
     *
     * @param array $pollerConfig Configuration for adaptive polling
     * @param string $lockfile Path to lockfile for ensuring single instance
     * @param int $logInterval Seconds between statistics logging
     */
    public function __construct(array $pollerConfig, string $lockfile, int $logInterval = 60) {
        $this->pollerConfig = $pollerConfig;
        $this->lockfile = $lockfile;
        $this->logInterval = $logInterval;
        $this->lastLogTime = time();

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

            // Check shutdown timeout
            if ($this->shutdownStartTime !== null) {
                $elapsed = time() - $this->shutdownStartTime;
                if ($elapsed > self::MAX_SHUTDOWN_TIME) {
                    echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
                    echo "Shutdown timeout exceeded ({$elapsed}s > " . self::MAX_SHUTDOWN_TIME . "s) - forcing exit\n";
                    $this->forceShutdown();
                    exit(1);
                }
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
        $signalName = ($signal === SIGTERM) ? 'SIGTERM' : 'SIGINT';

        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Received {$signalName} signal, initiating graceful shutdown...\n";

        $this->shouldStop = true;
        $this->shutdownStartTime = time();
    }

    /**
     * Handle reload signal (SIGHUP)
     *
     * @param int $signal The signal received
     */
    public function handleReloadSignal(int $signal): void {
        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Received SIGHUP signal, reloading configuration...\n";

        // Allow concrete classes to implement reload logic
        if (method_exists($this, 'reloadConfiguration')) {
            $this->reloadConfiguration();
        }
    }

    /**
     * Handle quit signal (SIGQUIT) - Quit with diagnostic info
     *
     * @param int $signal The signal received
     */
    public function handleQuitSignal(int $signal): void {
        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Received SIGQUIT signal, quitting with diagnostics...\n";

        $this->printDiagnostics();
        $this->shouldStop = true;
        $this->shutdownStartTime = time();
    }

    /**
     * Register cleanup callback
     *
     * @param callable $callback Cleanup function to call on shutdown
     * @param string $description Description of cleanup task
     */
    protected function registerCleanup(callable $callback, string $description = ''): void {
        $this->cleanupCallbacks[] = [
            'callback' => $callback,
            'description' => $description
        ];
    }

    /**
     * Execute all cleanup callbacks
     */
    protected function executeCleanupCallbacks(): void {
        if (empty($this->cleanupCallbacks)) {
            return;
        }

        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Executing " . count($this->cleanupCallbacks) . " cleanup callbacks...\n";

        foreach ($this->cleanupCallbacks as $cleanup) {
            try {
                $desc = $cleanup['description'] ?: 'unnamed cleanup';
                echo "  - {$desc}\n";

                call_user_func($cleanup['callback']);
            } catch (Exception $e) {
                echo "  ! Cleanup error: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Print diagnostic information
     */
    protected function printDiagnostics(): void {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "PROCESS DIAGNOSTICS - " . $this->getProcessorName() . "\n";
        echo str_repeat("=", 50) . "\n";
        echo "PID: " . getmypid() . "\n";
        echo "Total processed: {$this->totalProcessed}\n";
        echo "Should stop: " . ($this->shouldStop ? 'YES' : 'NO') . "\n";
        echo "Cleanup callbacks: " . count($this->cleanupCallbacks) . "\n";

        if (function_exists('memory_get_usage')) {
            echo "Memory usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
            echo "Peak memory: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";
        }

        echo "Uptime: " . (time() - $this->lastLogTime) . " seconds\n";
        echo str_repeat("=", 50) . "\n\n";
    }

    /**
     * Graceful shutdown with cleanup
     */
    protected function shutdown(): void {
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Shutting down {$this->getProcessorName()} processor...\n";

        // Execute cleanup callbacks
        $this->executeCleanupCallbacks();

        // Allow concrete classes to perform additional cleanup
        if (method_exists($this, 'cleanup')) {
            try {
                $this->cleanup();
            } catch (Exception $e) {
                echo "Cleanup error: " . $e->getMessage() . "\n";
            }
        }

        // Remove lockfile
        if (file_exists($this->lockfile)) {
            unlink($this->lockfile);
        }

        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo $this->getProcessorName() . " processor stopped gracefully\n";
    }

    /**
     * Force shutdown (after timeout)
     */
    protected function forceShutdown(): void {
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "FORCE SHUTDOWN - timeout exceeded\n";

        // Quick cleanup - don't wait for callbacks
        if (file_exists($this->lockfile)) {
            @unlink($this->lockfile);
        }
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
