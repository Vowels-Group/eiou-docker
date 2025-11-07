<?php
# Copyright 2025

/**
 * Shutdown Integration Helper
 *
 * Provides integration utilities for connecting ShutdownCoordinator
 * with existing message processors and services.
 *
 * Usage in message processors:
 *
 *   // In processor constructor or initialization:
 *   $shutdownIntegration = new ShutdownIntegration();
 *   $shutdownIntegration->integrateWithApplication($app);
 *
 *   // In message processing loop:
 *   if ($shutdownIntegration->shouldStopProcessing()) {
 *       break; // Exit processing loop
 *   }
 *
 * @package Processors
 */

require_once dirname(__DIR__) . '/services/ShutdownCoordinator.php';

class ShutdownIntegration {
    /**
     * @var ShutdownCoordinator Shutdown coordinator instance
     */
    private ShutdownCoordinator $coordinator;

    /**
     * @var bool Whether to stop processing new messages
     */
    private bool $stopProcessing = false;

    /**
     * Constructor
     *
     * @param ShutdownCoordinator|null $coordinator Optional coordinator instance
     */
    public function __construct(?ShutdownCoordinator $coordinator = null) {
        if ($coordinator === null) {
            $this->coordinator = new ShutdownCoordinator();
        } else {
            $this->coordinator = $coordinator;
        }
    }

    /**
     * Get the shutdown coordinator instance
     *
     * @return ShutdownCoordinator
     */
    public function getCoordinator(): ShutdownCoordinator {
        return $this->coordinator;
    }

    /**
     * Integrate with Application instance
     *
     * Registers all necessary resources with shutdown coordinator.
     *
     * @param Application $app Application instance
     */
    public function integrateWithApplication(Application $app): void {
        // Register database connection if available
        if ($app->currentPdoLoaded()) {
            $this->coordinator->registerDatabaseConnection('main', $app->pdo ?? null);
        }

        // Register all processors
        foreach ($app->processors as $name => $processor) {
            $this->coordinator->registerProcessor($name, $processor);
        }

        // Register processor lockfiles
        foreach ($app->processors as $name => $processor) {
            if (isset($processor->lockfile) && file_exists($processor->lockfile)) {
                $this->coordinator->registerFileLock($processor->lockfile);
            }
        }

        // Set up progress reporting callback
        $this->coordinator->setProgressCallback(function($progress) {
            $this->logProgress($progress);
        });
    }

    /**
     * Integrate with ServiceContainer
     *
     * @param ServiceContainer $services Service container instance
     */
    public function integrateWithServiceContainer(ServiceContainer $services): void {
        // Register database connection
        $pdo = $services->getPdo();
        if ($pdo !== null) {
            $this->coordinator->registerDatabaseConnection('service_container', $pdo);
        }
    }

    /**
     * Register message processor with shutdown coordinator
     *
     * @param string $name Processor name
     * @param AbstractMessageProcessor $processor Processor instance
     */
    public function registerMessageProcessor(string $name, AbstractMessageProcessor $processor): void {
        $this->coordinator->registerProcessor($name, $processor);

        // Register lockfile if exists
        if (isset($processor->lockfile) && file_exists($processor->lockfile)) {
            $this->coordinator->registerFileLock($processor->lockfile);
        }
    }

    /**
     * Track message processing start
     *
     * Call this when starting to process a message to track in-flight status.
     *
     * @param string $messageId Unique message identifier
     * @param string $processorName Processor handling the message
     * @param array $messageData Message data
     */
    public function trackMessageStart(string $messageId, string $processorName, array $messageData): void {
        $this->coordinator->trackInFlightMessage($messageId, array_merge($messageData, [
            'processor' => $processorName,
        ]));
    }

    /**
     * Track message processing completion
     *
     * Call this when message processing is complete.
     *
     * @param string $messageId Message identifier
     */
    public function trackMessageComplete(string $messageId): void {
        $this->coordinator->completeInFlightMessage($messageId);
    }

    /**
     * Stop accepting new messages (called by shutdown coordinator)
     */
    public function stopAcceptingMessages(): void {
        $this->stopProcessing = true;
    }

    /**
     * Resume accepting new messages (rollback scenario)
     */
    public function resumeAcceptingMessages(): void {
        $this->stopProcessing = false;
    }

    /**
     * Check if should stop processing new messages
     *
     * @return bool True if should stop processing
     */
    public function shouldStopProcessing(): bool {
        return $this->stopProcessing || $this->coordinator->isShuttingDown();
    }

    /**
     * Check if in shutdown phase
     *
     * @return bool True if shutting down
     */
    public function isShuttingDown(): bool {
        return $this->coordinator->isShuttingDown();
    }

    /**
     * Get current shutdown phase
     *
     * @return string Current phase
     */
    public function getCurrentPhase(): string {
        return $this->coordinator->getCurrentPhase();
    }

    /**
     * Initiate graceful shutdown
     *
     * @return bool True if shutdown initiated successfully
     */
    public function initiateShutdown(): bool {
        return $this->coordinator->initiateShutdown();
    }

    /**
     * Get shutdown statistics
     *
     * @return array Statistics
     */
    public function getShutdownStats(): array {
        return $this->coordinator->getStats();
    }

    /**
     * Log shutdown progress
     *
     * @param array $progress Progress data
     */
    private function logProgress(array $progress): void {
        $timestamp = date(Constants::DISPLAY_DATE_FORMAT);
        $phase = $progress['phase'];
        $message = $progress['message'];
        $elapsed = round($progress['elapsed'], 2);

        echo "[{$timestamp}] Shutdown {$phase}: {$message} ({$elapsed}s)\n";

        // Log to file if logger available
        if (class_exists('SecureLogger')) {
            SecureLogger::info("Shutdown progress", $progress);
        }
    }

    /**
     * Create shutdown-aware message wrapper
     *
     * Wraps a message processing function with automatic in-flight tracking.
     *
     * @param callable $processor Message processing function
     * @param string $processorName Name of the processor
     * @return callable Wrapped function
     */
    public function wrapMessageProcessor(callable $processor, string $processorName): callable {
        return function($message) use ($processor, $processorName) {
            // Check if should stop processing
            if ($this->shouldStopProcessing()) {
                return 0; // No messages processed
            }

            // Generate message ID
            $messageId = $this->generateMessageId($message);

            // Track message start
            $this->trackMessageStart($messageId, $processorName, $message);

            try {
                // Process message
                $result = call_user_func($processor, $message);

                // Track completion
                $this->trackMessageComplete($messageId);

                return $result;

            } catch (Exception $e) {
                // Still mark as complete even on error
                $this->trackMessageComplete($messageId);
                throw $e;
            }
        };
    }

    /**
     * Generate unique message ID
     *
     * @param array $message Message data
     * @return string Unique identifier
     */
    private function generateMessageId(array $message): string {
        $data = json_encode($message);
        $hash = hash('sha256', $data . microtime(true));
        return substr($hash, 0, 16);
    }

    /**
     * Add temporary file for cleanup
     *
     * @param string $filepath Path to temporary file
     */
    public function registerTempFile(string $filepath): void {
        $this->coordinator->registerTempFile($filepath);
    }

    /**
     * Add file lock for release
     *
     * @param string $lockfile Path to lock file
     */
    public function registerFileLock(string $lockfile): void {
        $this->coordinator->registerFileLock($lockfile);
    }

    /**
     * Cleanup method for use in processor shutdown hooks
     */
    public function cleanup(): void {
        // This can be called from processor shutdown() methods
        // to ensure proper cleanup sequencing
    }
}
