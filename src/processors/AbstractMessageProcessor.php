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
                die("Another instance is already running (PID: $pid)\n");
            }

            // Stale lockfile, remove it
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
     * Handle shutdown signals
     *
     * @param int $signal The signal received
     */
    public function handleShutdownSignal(int $signal): void {
        echo "\n[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Received shutdown signal ($signal), stopping gracefully...\n";
        $this->shouldStop = true;
    }

    /**
     * Cleanup on shutdown
     */
    protected function shutdown(): void {
        if (file_exists($this->lockfile)) {
            unlink($this->lockfile);
        }

        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo $this->getProcessorName() . " processor stopped\n";
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
