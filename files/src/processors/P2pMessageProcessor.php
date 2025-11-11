<?php
# Copyright 2025

/**
 * P2P Message Processor
 *
 * Processes queued P2P messages with fast polling for time-critical routing.
 *
 */

require_once(__DIR__ . "/AbstractMessageProcessor.php");
require_once(__DIR__ . "/../services/ServiceContainer.php");

class P2pMessageProcessor extends AbstractMessageProcessor {
    private $p2pService;

    /**
     * Constructor
     *
     * @param array $pollerConfig Configuration for adaptive polling
     * @param string $lockfile Path to lockfile (default: /tmp/p2pmessages_lock.pid)
     */
    public function __construct(array $pollerConfig = null, string $lockfile = null) {
        // Default configuration for P2P (fast polling)
        if ($pollerConfig === null) {
            $pollerConfig = [
                'min_interval_ms' => Constants::P2P_MIN_INTERVAL_MS ?: 100,  // 100ms
                'max_interval_ms' => Constants::P2P_MAX_INTERVAL_MS ?: 5000, // 5 seconds
                'idle_interval_ms' => Constants::P2P_IDLE_INTERVAL_MS ?: 2000, // 2 seconds
                'adaptive' => Constants::P2P_ADAPTIVE_POLLING !== 'false',
            ];
        }

        if ($lockfile === null) {
            $lockfile = '/tmp/p2pmessages_lock.pid';
        }

        // P2P logs every minute (60 seconds)
        parent::__construct($pollerConfig, $lockfile, 60);

        // Get the P2P service
        $this->p2pService = ServiceContainer::getInstance()->getP2pService();
    }

    /**
     * Process queued P2P messages
     *
     * @return int Number of P2P messages processed
     */
    protected function processMessages(): int {
        return $this->p2pService->processQueuedP2pMessages();
    }

    /**
     * Get the processor name for logging
     *
     * @return string "P2P"
     */
    protected function getProcessorName(): string {
        return 'P2P';
    }
}
