<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Sync Trigger Interface
 *
 * Minimal interface for sync operations needed by other services.
 * This interface exists to break circular dependencies - services
 * that need to trigger sync operations can depend on this interface
 * rather than the full SyncService.
 *
 * Used by:
 * - ChainVerificationService (to repair chain gaps)
 * - TransactionService (to sync before sending)
 * - ContactService (to sync re-added contacts)
 * - SendOperationService (to sync contacts before sending)
 * - HeldTransactionService (to sync transaction chains)
 * - TransactionProcessingService (to sync after chain conflicts)
 * - TransactionValidationService (proactive sync before validation)
 *
 * @see SyncServiceInterface for the full sync service contract
 * @see SyncServiceProxy for the lazy-loading proxy implementation
 */
interface SyncTriggerInterface
{
    /**
     * Synchronize a transaction chain for a contact.
     *
     * Called when a transaction is rejected due to invalid_previous_txid
     * or when proactive chain verification detects gaps.
     * Requests missing transactions from the contact and inserts them locally.
     *
     * @param string $contactAddress The contact's address
     * @param string $contactPublicKey The contact's public key
     * @param string|null $expectedTxid Optional expected transaction ID
     * @return array The sync result including:
     *   - success: bool - Whether sync completed successfully
     *   - synced_count: int - Number of transactions synced
     *   - latest_txid: string|null - Latest transaction ID from sync
     *   - error: string|null - Error message if failed
     */
    public function syncTransactionChain(string $contactAddress, string $contactPublicKey, ?string $expectedTxid = null): array;

    /**
     * Synchronize the balance for a specific contact.
     *
     * Recalculates the balance between the current user and a specific contact
     * based on their transaction history.
     *
     * @param string $contactPubkey The contact's public key
     * @return array Result with success status and synced currencies
     */
    public function syncContactBalance(string $contactPubkey): array;

    /**
     * Synchronize a single contact.
     *
     * Handles pending -> accepted status transitions and contact request resends.
     *
     * @param mixed $contactAddress The contact address to sync
     * @param string $echo Echo mode ('SILENT' for no output, 'ECHO' for output)
     * @return bool True if sync was successful
     */
    public function syncSingleContact($contactAddress, $echo = 'SILENT'): bool;

    /**
     * Full sync for a re-added contact.
     *
     * Performs a complete sync for a contact that was deleted and then re-added.
     * This includes syncing the transaction chain (with signature verification)
     * and recalculating balances from the transaction history.
     *
     * @param string $contactAddress Contact's address
     * @param string $contactPublicKey Contact's public key
     * @return array Result with success status and sync details
     */
    public function syncReaddedContact(string $contactAddress, string $contactPublicKey): array;

    /**
     * Handle incoming transaction sync request from a contact
     *
     * Called by MessageService when receiving a sync request. Processes the
     * request and outputs JSON response to the contact.
     *
     * @param array $request The sync request data containing contact info and sync parameters
     * @return void Outputs JSON response directly
     */
    public function handleTransactionSyncRequest(array $request): void;

    /**
     * Verify a transaction signature using the sender's public key
     *
     * Used during chain conflict resolution to verify that a transaction
     * with a different previous_txid has a valid signature before accepting.
     *
     * @param array $tx Transaction data with sender_signature and signature_nonce
     * @return bool True if signature is valid, false otherwise
     */
    public function verifyTransactionSignaturePublic(array $tx): bool;
}
