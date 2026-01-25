<?php
# Copyright 2025-2026 Vowels Group, LLC

use SyncService;

/**
 * Held Transaction Service Interface
 *
 * Defines the contract for managing transactions held pending resync completion.
 *
 * Flow:
 * 1. Transaction rejected with invalid_previous_txid (receiver tells us expected previous_txid)
 * 2. holdTransactionForSync() stores transaction and initiates sync
 * 3. SyncService completes sync (including all transactions to maintain chain integrity)
 * 4. onSyncComplete() triggers processHeldTransactionsAfterSync()
 * 5. updatePreviousTxid() updates the transaction's previous_txid to the expected value
 * 6. resumeTransaction() sets status back to pending for reprocessing
 *
 * @package Eiou\Contracts
 */
interface HeldTransactionServiceInterface
{
    /**
     * Set the sync service (setter injection for circular dependency)
     *
     * @param SyncService $service Sync service
     * @return void
     */
    public function setSyncService(SyncService $service): void;

    /**
     * Hold a transaction that received invalid_previous_txid rejection
     *
     * Stores the transaction in held_transactions table and initiates sync
     * if not already in progress for this contact.
     *
     * @param array $transaction Transaction data including txid, receiver_public_key
     * @param string $contactPubkey Contact's public key
     * @param string|null $expectedTxid The txid the contact expected (from rejection)
     * @return array Result with keys: held (bool), sync_initiated (bool), error (string|null)
     */
    public function holdTransactionForSync(array $transaction, string $contactPubkey, ?string $expectedTxid = null): array;

    /**
     * Check if transactions should be held for this contact
     *
     * Returns true if sync is currently in progress, indicating that
     * new transactions should be queued rather than sent immediately.
     *
     * @param string $contactPubkey Contact's public key
     * @return bool True if sync in progress, false otherwise
     */
    public function shouldHoldTransactions(string $contactPubkey): bool;

    /**
     * Process held transactions after sync completes
     *
     * Updates the previous_txid for held transactions to the expected value
     * from the rejection response, then resumes them for reprocessing.
     *
     * @param string $contactPubkey Contact's public key
     * @return array Result with keys: resumed_count (int), failed_count (int)
     */
    public function processHeldTransactionsAfterSync(string $contactPubkey): array;

    /**
     * Update the previous_txid for a held transaction
     *
     * Uses the expected_previous_txid from the rejection response, or falls back
     * to looking up the correct value from the transaction chain after sync.
     *
     * @param string $txid Transaction ID
     * @param string $contactPubkey Contact's public key
     * @param string|null $expectedPreviousTxid The expected txid from rejection response
     * @return bool True if updated successfully
     */
    public function updatePreviousTxid(string $txid, string $contactPubkey, ?string $expectedPreviousTxid = null): bool;

    /**
     * Resume a held transaction for reprocessing
     *
     * Sets the transaction status back to 'pending' so it will be picked up
     * by the next processing cycle and re-attempted with the corrected previous_txid.
     *
     * @param string $txid Transaction ID
     * @return array Result with keys: success (bool), new_previous_txid (string|null), error (string|null)
     */
    public function resumeTransaction(string $txid): array;

    /**
     * Callback invoked by SyncService when sync completes
     *
     * Updates the sync status for all held transactions with this contact
     * and triggers processing if sync was successful.
     *
     * @param string $contactPubkey Contact's public key
     * @param bool $success Whether sync completed successfully
     * @param int $syncedCount Number of transactions synced
     * @return void
     */
    public function onSyncComplete(string $contactPubkey, bool $success, int $syncedCount = 0): void;

    /**
     * Process held transactions ready to resume
     *
     * Finds transactions with sync_status='completed' and processes them.
     * This can be called periodically to handle any transactions that weren't
     * immediately processed after sync completion.
     *
     * @param int $limit Maximum number of transactions to process
     * @return array Result with keys: processed_count, resumed_count, failed_count
     */
    public function processHeldTransactions(int $limit = 10): array;

    /**
     * Get statistics about held transactions
     *
     * Returns counts and status information for monitoring and debugging.
     *
     * @return array Statistics including total, by_status, by_reason, oldest_held, newest_held
     */
    public function getStatistics(): array;
}
