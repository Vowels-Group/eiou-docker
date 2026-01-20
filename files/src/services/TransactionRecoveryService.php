<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';
require_once __DIR__ . '/../core/Constants.php';

/**
 * Transaction Recovery Service
 *
 * Handles recovery of transactions that were interrupted mid-processing.
 * This service runs at startup to detect and recover transactions stuck
 * in the 'sending' status, preventing duplicates and ensuring reliability.
 *
 * Recovery Flow:
 * 1. On startup, recoverStuckTransactions() is called
 * 2. Finds all transactions in 'sending' status that exceeded the timeout
 * 3. For each stuck transaction:
 *    a. If recovery_count < max_retries: Reset to 'pending' for retry
 *    b. If recovery_count >= max_retries: Mark for manual review
 * 4. Logs all recovery actions for audit trail
 *
 * The 'sending' status is a transient state indicating the transaction
 * processor has claimed the transaction and is actively sending it.
 * If the process crashes during this window, recovery handles cleanup.
 *
 * @package Services
 */
class TransactionRecoveryService {
    /**
     * @var TransactionRepository Transaction repository
     */
    private $transactionRepository;

    /**
     * @var SecureLogger Logger instance
     */
    private $logger;

    /**
     * Constructor
     *
     * @param TransactionRepository $transactionRepository Transaction repository
     */
    public function __construct($transactionRepository) {
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * Run recovery process for stuck transactions
     *
     * Called at startup to recover any transactions that were interrupted
     * while being sent. This ensures no transaction is lost due to crashes.
     *
     * @param int $timeoutSeconds Override timeout (default from Constants)
     * @param int $maxRetries Override max retries (default from Constants)
     * @return array Recovery results with statistics
     */
    public function recoverStuckTransactions(int $timeoutSeconds = 0, int $maxRetries = 0): array {
        $results = [
            'recovered' => 0,
            'needs_review' => 0,
            'already_recovered' => 0,
            'errors' => 0,
            'transactions' => []
        ];

        SecureLogger::info("Starting transaction recovery process", [
            'timeout_seconds' => $timeoutSeconds ?: Constants::RECOVERY_SENDING_TIMEOUT_SECONDS,
            'max_retries' => $maxRetries ?: Constants::RECOVERY_MAX_RETRY_COUNT
        ]);

        try {
            // Get all transactions stuck in 'sending' status
            $stuckTransactions = $this->transactionRepository->getStuckSendingTransactions($timeoutSeconds);

            if (empty($stuckTransactions)) {
                SecureLogger::info("No stuck transactions found during recovery");
                return $results;
            }

            SecureLogger::warning("Found stuck transactions requiring recovery", [
                'count' => count($stuckTransactions)
            ]);

            foreach ($stuckTransactions as $transaction) {
                $txid = $transaction['txid'];
                $sendingStartedAt = $transaction['sending_started_at'] ?? 'unknown';

                try {
                    $recoveryResult = $this->transactionRepository->recoverStuckTransaction($txid, $maxRetries);

                    $txResult = [
                        'txid' => $txid,
                        'sending_started_at' => $sendingStartedAt,
                        'recovery_count' => $recoveryResult['recovery_count'],
                        'action' => null
                    ];

                    if ($recoveryResult['recovered']) {
                        $results['recovered']++;
                        $txResult['action'] = 'recovered';
                        SecureLogger::info("Transaction recovered successfully", [
                            'txid' => $txid,
                            'recovery_count' => $recoveryResult['recovery_count']
                        ]);
                    } elseif ($recoveryResult['needs_review']) {
                        $results['needs_review']++;
                        $txResult['action'] = 'needs_review';
                        SecureLogger::warning("Transaction marked for manual review", [
                            'txid' => $txid,
                            'recovery_count' => $recoveryResult['recovery_count']
                        ]);
                    } else {
                        $results['already_recovered']++;
                        $txResult['action'] = 'already_recovered';
                    }

                    $results['transactions'][] = $txResult;

                } catch (Exception $e) {
                    $results['errors']++;
                    SecureLogger::logException($e, [
                        'context' => 'transaction_recovery',
                        'txid' => $txid
                    ]);
                    $results['transactions'][] = [
                        'txid' => $txid,
                        'sending_started_at' => $sendingStartedAt,
                        'action' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }

            SecureLogger::info("Transaction recovery process completed", [
                'recovered' => $results['recovered'],
                'needs_review' => $results['needs_review'],
                'errors' => $results['errors']
            ]);

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'context' => 'transaction_recovery_startup'
            ]);
            $results['errors']++;
        }

        return $results;
    }

    /**
     * Get transactions that need manual review
     *
     * Returns all transactions marked as needing manual intervention
     * due to exceeding recovery attempts or other issues.
     *
     * @return array Transactions needing review
     */
    public function getTransactionsNeedingReview(): array {
        try {
            return $this->transactionRepository->getTransactionsNeedingReview();
        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'context' => 'get_transactions_needing_review'
            ]);
            return [];
        }
    }

    /**
     * Manually resolve a transaction marked for review
     *
     * Allows an administrator to resolve a stuck transaction by either:
     * - 'retry': Force another retry attempt
     * - 'cancel': Cancel the transaction
     * - 'complete': Mark as completed (if external verification confirms delivery)
     *
     * @param string $txid Transaction ID
     * @param string $action Action to take: 'retry', 'cancel', 'complete'
     * @param string|null $reason Optional reason for the action (for audit log)
     * @return array Result with 'success' and 'message' keys
     */
    public function resolveTransaction(string $txid, string $action, ?string $reason = null): array {
        $result = [
            'success' => false,
            'message' => ''
        ];

        try {
            $validActions = ['retry', 'cancel', 'complete'];
            if (!in_array($action, $validActions)) {
                $result['message'] = 'Invalid action. Must be one of: ' . implode(', ', $validActions);
                return $result;
            }

            // Get the transaction first
            $transaction = $this->transactionRepository->getByTxid($txid);
            if (!$transaction || empty($transaction)) {
                $result['message'] = 'Transaction not found';
                return $result;
            }

            switch ($action) {
                case 'retry':
                    // Reset to pending with recovery count unchanged
                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_PENDING, true);
                    $result['success'] = true;
                    $result['message'] = 'Transaction reset to pending for retry';
                    break;

                case 'cancel':
                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_CANCELLED, true);
                    $result['success'] = true;
                    $result['message'] = 'Transaction cancelled';
                    break;

                case 'complete':
                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_COMPLETED, true);
                    $result['success'] = true;
                    $result['message'] = 'Transaction marked as completed';
                    break;
            }

            // Log the resolution
            SecureLogger::info("Transaction manually resolved", [
                'txid' => $txid,
                'action' => $action,
                'reason' => $reason
            ]);

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'context' => 'resolve_transaction',
                'txid' => $txid,
                'action' => $action
            ]);
            $result['message'] = 'Error resolving transaction: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Check if recovery is needed
     *
     * Quick check to determine if there are any transactions requiring recovery.
     * Useful for startup to decide if full recovery process should run.
     *
     * @return bool True if recovery is needed
     */
    public function isRecoveryNeeded(): bool {
        try {
            // Use a minimal timeout check (e.g., 0 seconds) to find any transactions in 'sending' status
            $stuckTransactions = $this->transactionRepository->getStuckSendingTransactions(0);
            return !empty($stuckTransactions);
        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'context' => 'check_recovery_needed'
            ]);
            // If we can't check, assume recovery might be needed
            return true;
        }
    }

    /**
     * Get recovery statistics
     *
     * Returns statistics about transaction recovery state.
     *
     * @return array Statistics including counts by status
     */
    public function getRecoveryStatistics(): array {
        try {
            $stats = [
                'stuck_sending' => count($this->transactionRepository->getStuckSendingTransactions()),
                'needs_review' => count($this->transactionRepository->getTransactionsNeedingReview()),
                'timestamp' => date('Y-m-d H:i:s')
            ];

            return $stats;
        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'context' => 'get_recovery_statistics'
            ]);
            return [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}
