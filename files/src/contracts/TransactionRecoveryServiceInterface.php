<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Transaction Recovery Service Interface
 *
 * Defines the contract for recovering transactions that were interrupted mid-processing.
 *
 * Recovery Flow:
 * 1. On startup, recoverStuckTransactions() is called
 * 2. Finds all transactions in 'sending' status that exceeded the timeout
 * 3. For each stuck transaction:
 *    a. If recovery_count < max_retries: Reset to 'pending' for retry
 *    b. If recovery_count >= max_retries: Mark for manual review
 * 4. Logs all recovery actions for audit trail
 */
interface TransactionRecoveryServiceInterface
{
    /**
     * Run recovery process for stuck transactions
     *
     * Called at startup to recover any transactions that were interrupted
     * while being sent. This ensures no transaction is lost due to crashes.
     *
     * @param int $timeoutSeconds Override timeout (default from Constants)
     * @param int $maxRetries Override max retries (default from Constants)
     * @return array Recovery results with statistics including:
     *               - recovered (int): Count of successfully recovered transactions
     *               - needs_review (int): Count of transactions marked for manual review
     *               - already_recovered (int): Count of transactions already in recovered state
     *               - errors (int): Count of errors during recovery
     *               - transactions (array): Details of each processed transaction
     */
    public function recoverStuckTransactions(int $timeoutSeconds = 0, int $maxRetries = 0): array;

    /**
     * Get transactions that need manual review
     *
     * Returns all transactions marked as needing manual intervention
     * due to exceeding recovery attempts or other issues.
     *
     * @return array Transactions needing review
     */
    public function getTransactionsNeedingReview(): array;

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
     * @return array Result with 'success' (bool) and 'message' (string) keys
     */
    public function resolveTransaction(string $txid, string $action, ?string $reason = null): array;

    /**
     * Check if recovery is needed
     *
     * Quick check to determine if there are any transactions requiring recovery.
     * Useful for startup to decide if full recovery process should run.
     *
     * @return bool True if recovery is needed
     */
    public function isRecoveryNeeded(): bool;

    /**
     * Get recovery statistics
     *
     * Returns statistics about transaction recovery state.
     *
     * @return array Statistics including:
     *               - stuck_sending (int): Count of transactions stuck in sending status
     *               - needs_review (int): Count of transactions needing manual review
     *               - timestamp (string): When statistics were gathered
     */
    public function getRecoveryStatistics(): array;
}
