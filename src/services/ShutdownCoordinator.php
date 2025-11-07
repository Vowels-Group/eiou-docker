<?php
# Copyright 2025

/**
 * Shutdown Coordinator Service
 *
 * Manages graceful shutdown sequence for EIOU node with strict timing guarantees.
 * Coordinates message completion, resource cleanup, and proper state transitions.
 *
 * Shutdown Phases (30 second max total):
 * 1. INITIATING (0-1s): Stop accepting new messages, notify processors
 * 2. DRAINING (1-26s): Complete in-flight messages (max 25 seconds)
 * 3. CLOSING (26-29s): Close database connections, release locks (max 3 seconds)
 * 4. CLEANUP (29-31s): Final temp file cleanup, logging (max 2 seconds)
 * 5. SHUTDOWN (31s): Exit process
 *
 * @package Services
 */

require_once dirname(__DIR__) . '/core/Constants.php';
require_once dirname(__DIR__) . '/utils/SecureLogger.php';

class ShutdownCoordinator {
    /**
     * Shutdown phases with timing guarantees
     */
    const PHASE_IDLE = 'idle';
    const PHASE_INITIATING = 'initiating';
    const PHASE_DRAINING = 'draining';
    const PHASE_CLOSING = 'closing';
    const PHASE_CLEANUP = 'cleanup';
    const PHASE_SHUTDOWN = 'shutdown';
    const PHASE_FORCE = 'force';

    /**
     * Timing constants (seconds)
     */
    const TIMEOUT_INITIATING = 1;
    const TIMEOUT_DRAINING = 25;
    const TIMEOUT_CLOSING = 3;
    const TIMEOUT_CLEANUP = 2;
    const TIMEOUT_TOTAL = 31;
    const TIMEOUT_FORCE = 35; // Emergency hard exit

    /**
     * @var string Current shutdown phase
     */
    private string $currentPhase = self::PHASE_IDLE;

    /**
     * @var float Shutdown start timestamp
     */
    private float $shutdownStartTime = 0.0;

    /**
     * @var array Phase start timestamps
     */
    private array $phaseStartTimes = [];

    /**
     * @var array In-flight message tracking
     */
    private array $inFlightMessages = [];

    /**
     * @var array Registered processors for shutdown
     */
    private array $processors = [];

    /**
     * @var array Database connections to close
     */
    private array $dbConnections = [];

    /**
     * @var array File locks to release
     */
    private array $fileLocks = [];

    /**
     * @var array Temporary files to cleanup
     */
    private array $tempFiles = [];

    /**
     * @var SecureLogger Logger instance
     */
    private SecureLogger $logger;

    /**
     * @var array Shutdown statistics
     */
    private array $stats = [
        'messages_completed' => 0,
        'messages_abandoned' => 0,
        'connections_closed' => 0,
        'locks_released' => 0,
        'files_cleaned' => 0,
        'errors' => [],
    ];

    /**
     * @var bool Whether shutdown was forced
     */
    private bool $wasForced = false;

    /**
     * @var callable|null Progress callback for status reporting
     */
    private $progressCallback = null;

    /**
     * Constructor
     *
     * @param SecureLogger|null $logger Optional logger instance
     */
    public function __construct(?SecureLogger $logger = null) {
        if ($logger === null) {
            $this->logger = new SecureLogger();
            $this->logger->init(Constants::LOG_FILE_APP, Constants::LOG_LEVEL);
        } else {
            $this->logger = $logger;
        }

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
        }
    }

    /**
     * Handle shutdown signals
     *
     * @param int $signal Signal number
     */
    public function handleShutdownSignal(int $signal): void {
        $signalName = $signal === SIGTERM ? 'SIGTERM' : 'SIGINT';
        $this->logger->info("Received shutdown signal: {$signalName}");
        $this->initiateShutdown();
    }

    /**
     * Set progress callback for status reporting
     *
     * @param callable $callback Function to call with progress updates
     */
    public function setProgressCallback(callable $callback): void {
        $this->progressCallback = $callback;
    }

    /**
     * Register a message processor for shutdown coordination
     *
     * @param string $name Processor name
     * @param object $processor Processor instance with shutdown() method
     */
    public function registerProcessor(string $name, object $processor): void {
        if (!method_exists($processor, 'shutdown')) {
            $this->logger->warning("Processor {$name} does not have shutdown() method");
            return;
        }

        $this->processors[$name] = $processor;
        $this->logger->debug("Registered processor: {$name}");
    }

    /**
     * Register a database connection for cleanup
     *
     * @param string $name Connection name
     * @param PDO|null $connection PDO connection
     */
    public function registerDatabaseConnection(string $name, ?PDO $connection): void {
        if ($connection !== null) {
            $this->dbConnections[$name] = $connection;
            $this->logger->debug("Registered database connection: {$name}");
        }
    }

    /**
     * Register a file lock for release
     *
     * @param string $lockfile Path to lock file
     */
    public function registerFileLock(string $lockfile): void {
        if (file_exists($lockfile)) {
            $this->fileLocks[] = $lockfile;
            $this->logger->debug("Registered file lock: {$lockfile}");
        }
    }

    /**
     * Register temporary file for cleanup
     *
     * @param string $filepath Path to temporary file
     */
    public function registerTempFile(string $filepath): void {
        $this->tempFiles[] = $filepath;
        $this->logger->debug("Registered temp file: {$filepath}");
    }

    /**
     * Track an in-flight message
     *
     * @param string $messageId Unique message identifier
     * @param array $messageData Message data
     */
    public function trackInFlightMessage(string $messageId, array $messageData): void {
        $this->inFlightMessages[$messageId] = [
            'data' => $messageData,
            'started_at' => microtime(true),
            'processor' => $messageData['processor'] ?? 'unknown',
        ];
    }

    /**
     * Mark an in-flight message as completed
     *
     * @param string $messageId Message identifier
     */
    public function completeInFlightMessage(string $messageId): void {
        if (isset($this->inFlightMessages[$messageId])) {
            $duration = microtime(true) - $this->inFlightMessages[$messageId]['started_at'];
            $this->logger->debug("Message {$messageId} completed in {$duration}s");
            unset($this->inFlightMessages[$messageId]);
            $this->stats['messages_completed']++;
        }
    }

    /**
     * Get current shutdown phase
     *
     * @return string Current phase
     */
    public function getCurrentPhase(): string {
        return $this->currentPhase;
    }

    /**
     * Get shutdown statistics
     *
     * @return array Statistics
     */
    public function getStats(): array {
        return $this->stats;
    }

    /**
     * Check if shutdown is in progress
     *
     * @return bool True if shutting down
     */
    public function isShuttingDown(): bool {
        return $this->currentPhase !== self::PHASE_IDLE;
    }

    /**
     * Initiate graceful shutdown sequence
     *
     * @return bool True if shutdown initiated successfully
     */
    public function initiateShutdown(): bool {
        if ($this->isShuttingDown()) {
            $this->logger->warning("Shutdown already in progress");
            return false;
        }

        $this->shutdownStartTime = microtime(true);
        $this->logger->info("Initiating graceful shutdown");
        $this->reportProgress("Initiating graceful shutdown");

        try {
            // Execute shutdown phases
            $this->executePhaseInitiating();
            $this->executePhaseDraining();
            $this->executePhaseClosing();
            $this->executePhaseCleanup();
            $this->executePhaseShutdown();

            return true;
        } catch (Exception $e) {
            $this->logger->critical("Shutdown sequence failed: " . $e->getMessage());
            $this->stats['errors'][] = $e->getMessage();
            $this->executeForceShutdown();
            return false;
        }
    }

    /**
     * Phase 1: Initiating (0-1s)
     * Stop accepting new messages, notify processors
     */
    private function executePhaseInitiating(): void {
        $this->transitionToPhase(self::PHASE_INITIATING);
        $this->logger->info("Phase 1: Initiating shutdown");
        $this->reportProgress("Stopping new message acceptance");

        // Notify all processors to stop accepting new messages
        foreach ($this->processors as $name => $processor) {
            try {
                if (method_exists($processor, 'stopAcceptingMessages')) {
                    $processor->stopAcceptingMessages();
                    $this->logger->debug("Processor {$name} stopped accepting messages");
                }
            } catch (Exception $e) {
                $this->logger->error("Failed to stop processor {$name}: " . $e->getMessage());
                $this->stats['errors'][] = "Processor {$name}: " . $e->getMessage();
            }
        }

        // Wait for phase timeout or completion
        $this->waitForPhaseCompletion(self::TIMEOUT_INITIATING);
    }

    /**
     * Phase 2: Draining (1-26s)
     * Complete in-flight messages (max 25 seconds)
     */
    private function executePhaseDraining(): void {
        $this->transitionToPhase(self::PHASE_DRAINING);
        $this->logger->info("Phase 2: Draining in-flight messages");
        $this->reportProgress("Completing in-flight messages");

        $startTime = microtime(true);
        $timeout = self::TIMEOUT_DRAINING;
        $checkInterval = 0.5; // Check every 500ms

        while (microtime(true) - $startTime < $timeout) {
            // Check if all messages completed
            if (empty($this->inFlightMessages)) {
                $duration = microtime(true) - $startTime;
                $this->logger->info("All messages completed in {$duration}s");
                return;
            }

            // Report progress
            $remaining = count($this->inFlightMessages);
            $elapsed = round(microtime(true) - $startTime, 1);
            $this->reportProgress("Waiting for {$remaining} messages ({$elapsed}s elapsed)");

            // Wait before next check
            usleep($checkInterval * 1000000);

            // Check for forced shutdown
            if ($this->checkForceShutdownCondition()) {
                break;
            }
        }

        // Timeout reached - abandon remaining messages
        if (!empty($this->inFlightMessages)) {
            $abandoned = count($this->inFlightMessages);
            $this->stats['messages_abandoned'] = $abandoned;
            $this->logger->warning("Abandoned {$abandoned} in-flight messages due to timeout");
            $this->reportProgress("Timeout: Abandoned {$abandoned} messages");
        }
    }

    /**
     * Phase 3: Closing (26-29s)
     * Close database connections, release locks (max 3 seconds)
     */
    private function executePhaseClosing(): void {
        $this->transitionToPhase(self::PHASE_CLOSING);
        $this->logger->info("Phase 3: Closing connections and releasing locks");
        $this->reportProgress("Closing connections");

        $startTime = microtime(true);

        // Close database connections
        foreach ($this->dbConnections as $name => $connection) {
            try {
                if ($connection !== null) {
                    $connection = null; // PDO closes on null assignment
                    $this->stats['connections_closed']++;
                    $this->logger->debug("Closed database connection: {$name}");
                }
            } catch (Exception $e) {
                $this->logger->error("Failed to close connection {$name}: " . $e->getMessage());
                $this->stats['errors'][] = "Connection {$name}: " . $e->getMessage();
            }

            // Check timeout
            if (microtime(true) - $startTime > self::TIMEOUT_CLOSING) {
                $this->logger->warning("Connection closing timeout exceeded");
                break;
            }
        }

        // Release file locks
        foreach ($this->fileLocks as $lockfile) {
            try {
                if (file_exists($lockfile)) {
                    unlink($lockfile);
                    $this->stats['locks_released']++;
                    $this->logger->debug("Released lock: {$lockfile}");
                }
            } catch (Exception $e) {
                $this->logger->error("Failed to release lock {$lockfile}: " . $e->getMessage());
                $this->stats['errors'][] = "Lock {$lockfile}: " . $e->getMessage();
            }

            // Check timeout
            if (microtime(true) - $startTime > self::TIMEOUT_CLOSING) {
                $this->logger->warning("Lock release timeout exceeded");
                break;
            }
        }

        $this->waitForPhaseCompletion(self::TIMEOUT_CLOSING);
    }

    /**
     * Phase 4: Cleanup (29-31s)
     * Final temp file cleanup, logging (max 2 seconds)
     */
    private function executePhaseCleanup(): void {
        $this->transitionToPhase(self::PHASE_CLEANUP);
        $this->logger->info("Phase 4: Final cleanup");
        $this->reportProgress("Cleaning up temporary files");

        $startTime = microtime(true);

        // Clean up temporary files
        foreach ($this->tempFiles as $filepath) {
            try {
                if (file_exists($filepath)) {
                    unlink($filepath);
                    $this->stats['files_cleaned']++;
                    $this->logger->debug("Cleaned temp file: {$filepath}");
                }
            } catch (Exception $e) {
                $this->logger->error("Failed to clean file {$filepath}: " . $e->getMessage());
                $this->stats['errors'][] = "File {$filepath}: " . $e->getMessage();
            }

            // Check timeout
            if (microtime(true) - $startTime > self::TIMEOUT_CLEANUP) {
                $this->logger->warning("Cleanup timeout exceeded");
                break;
            }
        }

        // Log final statistics
        $this->logShutdownStats();

        $this->waitForPhaseCompletion(self::TIMEOUT_CLEANUP);
    }

    /**
     * Phase 5: Shutdown (31s)
     * Exit process cleanly
     */
    private function executePhaseShutdown(): void {
        $this->transitionToPhase(self::PHASE_SHUTDOWN);
        $this->logger->info("Phase 5: Shutdown complete");
        $this->reportProgress("Shutdown complete");

        $totalDuration = microtime(true) - $this->shutdownStartTime;
        $this->logger->info("Total shutdown duration: {$totalDuration}s");

        // Final progress report
        $this->reportProgress("Shutdown finished in {$totalDuration}s");
    }

    /**
     * Force shutdown when graceful shutdown fails
     */
    private function executeForceShutdown(): void {
        $this->transitionToPhase(self::PHASE_FORCE);
        $this->logger->critical("Forcing shutdown due to errors or timeout");
        $this->reportProgress("FORCE SHUTDOWN");

        $this->wasForced = true;

        // Log what we're abandoning
        if (!empty($this->inFlightMessages)) {
            $this->logger->critical("Forcing shutdown with " . count($this->inFlightMessages) . " messages in-flight");
        }

        // Final stats
        $this->logShutdownStats();
    }

    /**
     * Transition to a new shutdown phase
     *
     * @param string $phase New phase
     */
    private function transitionToPhase(string $phase): void {
        $this->currentPhase = $phase;
        $this->phaseStartTimes[$phase] = microtime(true);
        $this->logger->debug("Transitioned to phase: {$phase}");
    }

    /**
     * Wait for phase completion or timeout
     *
     * @param float $timeout Timeout in seconds
     */
    private function waitForPhaseCompletion(float $timeout): void {
        $phaseStart = $this->phaseStartTimes[$this->currentPhase];
        $elapsed = microtime(true) - $phaseStart;

        if ($elapsed < $timeout) {
            $remaining = $timeout - $elapsed;
            usleep($remaining * 1000000);
        } else {
            $this->logger->warning("Phase {$this->currentPhase} exceeded timeout ({$elapsed}s)");
        }
    }

    /**
     * Check if force shutdown condition is met
     *
     * @return bool True if should force shutdown
     */
    private function checkForceShutdownCondition(): bool {
        $totalElapsed = microtime(true) - $this->shutdownStartTime;

        if ($totalElapsed > self::TIMEOUT_FORCE) {
            $this->logger->critical("Force shutdown timeout exceeded ({$totalElapsed}s)");
            return true;
        }

        return false;
    }

    /**
     * Log shutdown statistics
     */
    private function logShutdownStats(): void {
        $stats = $this->getStats();
        $totalDuration = microtime(true) - $this->shutdownStartTime;

        $this->logger->info("Shutdown Statistics:", [
            'duration' => round($totalDuration, 2) . 's',
            'forced' => $this->wasForced,
            'messages_completed' => $stats['messages_completed'],
            'messages_abandoned' => $stats['messages_abandoned'],
            'connections_closed' => $stats['connections_closed'],
            'locks_released' => $stats['locks_released'],
            'files_cleaned' => $stats['files_cleaned'],
            'errors' => count($stats['errors']),
        ]);

        if (!empty($stats['errors'])) {
            $this->logger->error("Shutdown errors:", ['errors' => $stats['errors']]);
        }
    }

    /**
     * Report progress to callback if set
     *
     * @param string $message Progress message
     */
    private function reportProgress(string $message): void {
        if ($this->progressCallback !== null) {
            call_user_func($this->progressCallback, [
                'phase' => $this->currentPhase,
                'message' => $message,
                'stats' => $this->stats,
                'elapsed' => microtime(true) - $this->shutdownStartTime,
            ]);
        }
    }

    /**
     * Get time remaining in current phase
     *
     * @return float Seconds remaining (0 if exceeded)
     */
    public function getPhaseTimeRemaining(): float {
        if (!isset($this->phaseStartTimes[$this->currentPhase])) {
            return 0.0;
        }

        $elapsed = microtime(true) - $this->phaseStartTimes[$this->currentPhase];

        $timeouts = [
            self::PHASE_INITIATING => self::TIMEOUT_INITIATING,
            self::PHASE_DRAINING => self::TIMEOUT_DRAINING,
            self::PHASE_CLOSING => self::TIMEOUT_CLOSING,
            self::PHASE_CLEANUP => self::TIMEOUT_CLEANUP,
        ];

        $timeout = $timeouts[$this->currentPhase] ?? 0;
        $remaining = $timeout - $elapsed;

        return max(0.0, $remaining);
    }

    /**
     * Rollback on partial failures (best effort)
     *
     * @return bool True if rollback successful
     */
    public function rollback(): bool {
        $this->logger->warning("Attempting shutdown rollback");

        try {
            // Can't truly rollback a shutdown, but we can:
            // 1. Release any locks we acquired
            // 2. Log the failure state
            // 3. Attempt to re-enable message processing

            foreach ($this->processors as $name => $processor) {
                if (method_exists($processor, 'resumeAcceptingMessages')) {
                    try {
                        $processor->resumeAcceptingMessages();
                        $this->logger->info("Processor {$name} resumed accepting messages");
                    } catch (Exception $e) {
                        $this->logger->error("Failed to resume processor {$name}: " . $e->getMessage());
                    }
                }
            }

            $this->currentPhase = self::PHASE_IDLE;
            $this->logger->info("Shutdown rollback completed");
            return true;

        } catch (Exception $e) {
            $this->logger->critical("Rollback failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get count of in-flight messages
     *
     * @return int Number of messages still processing
     */
    public function getInFlightCount(): int {
        return count($this->inFlightMessages);
    }

    /**
     * Get in-flight message details
     *
     * @return array In-flight messages with details
     */
    public function getInFlightMessages(): array {
        return $this->inFlightMessages;
    }
}
