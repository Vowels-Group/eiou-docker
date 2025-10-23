<?php
# Copyright 2025

/**
 * Cleanup Message Processor
 *
 * Processes cleanup messages for expired P2P and transaction messages.
 * Uses slower polling intervals as cleanup is less time-critical.
 *
 * Issue #106: Refactored from cleanupMessages.php
 */

require_once(__DIR__ . "/AbstractMessageProcessor.php");
require_once(__DIR__ . "/../services/ServiceContainer.php");

class CleanupMessageProcessor extends AbstractMessageProcessor {
    private $cleanupService;

    /**
     * Constructor
     *
     * @param array $pollerConfig Configuration for adaptive polling
     * @param string $lockfile Path to lockfile (default: /tmp/cleanupmessages_lock.pid)
     */
    public function __construct(array $pollerConfig = null, string $lockfile = null) {
        // Default configuration for cleanup (slower polling)
        if ($pollerConfig === null) {
            $pollerConfig = [
                'min_interval_ms' => Constants::CLEANUP_MIN_INTERVAL_MS ?: 1000,   // 1 second
                'max_interval_ms' => Constants::CLEANUP_MAX_INTERVAL_MS ?: 30000,  // 30 seconds
                'idle_interval_ms' => Constants::CLEANUP_IDLE_INTERVAL_MS ?: 10000, // 10 seconds
                'adaptive' => Constants::CLEANUP_ADAPTIVE_POLLING !== 'false',
            ];
        }

        if ($lockfile === null) {
            $lockfile = '/tmp/cleanupmessages_lock.pid';
        }

        // Cleanup logs every 5 minutes (300 seconds)
        parent::__construct($pollerConfig, $lockfile, 300);

        // Get the cleanup service
        $this->cleanupService = ServiceContainer::getInstance()->getCleanupService();
    }

    /**
     * Process cleanup messages
     *
     * @return int Number of messages cleaned up
     */
    protected function processMessages(): int {
        return $this->cleanupService->processCleanupMessages();
    }

    /**
     * Get the processor name for logging
     *
     * @return string "Cleanup"
     */
    protected function getProcessorName(): string {
        return 'Cleanup';
    }
}
