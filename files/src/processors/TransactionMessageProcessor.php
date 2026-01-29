<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Processors;

use Eiou\Core\Application;
use Eiou\Core\Constants;

/**
 * Transaction Message Processor
 *
 * Processes pending transaction messages with fast polling for time-critical transactions.
 *
 */
class TransactionMessageProcessor extends AbstractMessageProcessor {
    private $transactionService;

    /**
     * Constructor
     *
     * @param array $pollerConfig Configuration for adaptive polling
     * @param string $lockfile Path to lockfile (default: /tmp/transactionmessages_lock.pid)
     */
    public function __construct(array $pollerConfig = null, string $lockfile = null) {
        // Default configuration for transactions (fast polling)
        if ($pollerConfig === null) {
            $pollerConfig = [
                'min_interval_ms' => Constants::TRANSACTION_MIN_INTERVAL_MS ?: 100,  // 100ms
                'max_interval_ms' => Constants::TRANSACTION_MAX_INTERVAL_MS ?: 5000, // 5 seconds
                'idle_interval_ms' => Constants::TRANSACTION_IDLE_INTERVAL_MS ?: 2000, // 2 seconds
                'adaptive' => Constants::TRANSACTION_ADAPTIVE_POLLING !== 'false',
            ];
        }

        if ($lockfile === null) {
            $lockfile = '/tmp/transactionmessages_lock.pid';
        }

        // Transaction logs every minute (60 seconds)
        parent::__construct($pollerConfig, $lockfile, 60);

        // Get the transaction service
        $this->transactionService = Application::getInstance()->services->getTransactionService();
    }

    /**
     * Process pending transactions
     *
     * @return int Number of transactions processed
     */
    protected function processMessages(): int {
        return $this->transactionService->processPendingTransactions();
    }

    /**
     * Get the processor name for logging
     *
     * @return string "Transaction"
     */
    protected function getProcessorName(): string {
        return 'Transaction';
    }
}
