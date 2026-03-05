<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Chain Verification Service Interface
 *
 * Defines the contract for verifying transaction chain integrity
 * and triggering synchronization when gaps are detected.
 *
 * This service is responsible for ensuring the local transaction chain
 * is valid before new transactions are created. When chain gaps are
 * detected, it coordinates with the SyncService to repair the chain.
 *
 * Verification Flow:
 * 1. Check local chain integrity for the sender-contact pair
 * 2. If chain is valid or empty, proceed with transaction
 * 3. If gaps exist, trigger sync to repair the chain
 * 4. Re-verify chain after sync to confirm repair
 */
interface ChainVerificationServiceInterface
{
    /**
     * Verify sender's local chain integrity and sync if needed
     *
     * Checks the transaction chain between the current user and a contact
     * for integrity (no gaps, proper linking). If gaps are detected,
     * triggers a sync operation to repair the chain before proceeding
     * with a new transaction.
     *
     * @param string $contactAddress The contact's network address
     * @param string $contactPublicKey The contact's public key
     * @return array Result with:
     *   - success: bool - Whether chain is ready for new transaction
     *   - synced: bool - Whether a sync was performed
     *   - error: string|null - Error message if failed
     */
    public function verifySenderChainAndSync(string $contactAddress, string $contactPublicKey, ?string $currency = null): array;

    /**
     * Set the sync trigger for chain repair operations (interface for loose coupling)
     *
     * Uses setter injection to avoid circular dependency between
     * ChainVerificationService and SyncService.
     *
     * @param SyncTriggerInterface $sync The sync trigger interface
     * @return void
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void;
}
