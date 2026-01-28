<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Chain Operations Interface
 *
 * Defines the contract for chain verification and repair operations.
 * This service encapsulates chain-related operations that multiple
 * services need, including integrity verification, previous txid
 * resolution, and chain repair coordination.
 *
 * Design Rationale:
 * - Centralizes chain verification logic to avoid duplication
 * - Provides a clean abstraction over TransactionChainRepository
 * - Coordinates with SyncService for chain repair without tight coupling
 * - Enables consistent chain handling across TransactionService,
 *   HeldTransactionService, ChainVerificationService, etc.
 */
interface ChainOperationsInterface
{
    /**
     * Verify chain integrity between two parties
     *
     * Checks that the transaction chain between the current user and
     * a contact is complete (no gaps, proper linking). Returns detailed
     * information about the chain state including any detected gaps.
     *
     * @param string $userPubkey User's public key
     * @param string $contactPubkey Contact's public key
     * @return array Result with:
     *   - valid: bool - Whether chain is complete and valid
     *   - has_transactions: bool - Whether any transactions exist
     *   - transaction_count: int - Total transaction count
     *   - gaps: array - List of missing previous_txid values
     *   - broken_txids: array - Transactions with missing previous_txid
     */
    public function verifyChainIntegrity(string $userPubkey, string $contactPubkey): array;

    /**
     * Get the correct previous txid for a new transaction
     *
     * Determines the correct previous_txid value for a new transaction
     * in the chain between the current user and a contact. Returns null
     * if this would be the first transaction in the chain.
     *
     * @param string $userPubkey User's public key
     * @param string $contactPubkey Contact's public key
     * @return string|null The correct previous_txid or null if first transaction
     */
    public function getCorrectPreviousTxid(string $userPubkey, string $contactPubkey): ?string;

    /**
     * Repair chain if needed by triggering sync
     *
     * Verifies chain integrity and, if gaps are detected, coordinates
     * with SyncService to repair the chain. Returns the result of the
     * verification and any repair operations performed.
     *
     * This method requires SyncService to be set via setSyncService()
     * before being called with actual repair needs.
     *
     * @param string $contactAddress Contact's network address (for sync)
     * @param string $contactPubkey Contact's public key
     * @return array Result with:
     *   - success: bool - Whether chain is now valid (or was already valid)
     *   - was_valid: bool - Whether chain was valid before any repair
     *   - repair_attempted: bool - Whether repair was attempted
     *   - synced_count: int - Number of transactions synced (if repair attempted)
     *   - error: string|null - Error message if repair failed
     */
    public function repairChainIfNeeded(string $contactAddress, string $contactPubkey): array;

    /**
     * Set the sync service for chain repair operations
     *
     * Uses setter injection to avoid circular dependency between
     * ChainOperationsService and SyncService.
     *
     * @param SyncServiceInterface $syncService The sync service instance
     * @return void
     */
    public function setSyncService(SyncServiceInterface $syncService): void;
}
