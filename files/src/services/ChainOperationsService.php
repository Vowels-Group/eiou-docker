<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';
require_once __DIR__ . '/../contracts/ChainOperationsInterface.php';
require_once __DIR__ . '/../contracts/SyncServiceInterface.php';
require_once __DIR__ . '/../database/TransactionChainRepository.php';
require_once __DIR__ . '/../database/TransactionRepository.php';
require_once __DIR__ . '/../core/Constants.php';

/**
 * Chain Operations Service
 *
 * Encapsulates chain verification and repair logic that multiple services need.
 * Provides a centralized abstraction for transaction chain operations including:
 * - Chain integrity verification between user and contact
 * - Previous txid resolution for new transactions
 * - Chain repair coordination through SyncService
 *
 * This service acts as a facade over TransactionChainRepository and coordinates
 * with SyncService when chain repair is needed, using setter injection to avoid
 * circular dependencies.
 *
 * Usage:
 * - ChainVerificationService uses this for verifySenderChainAndSync
 * - HeldTransactionService uses this for verifying chain after sync
 * - TransactionService uses this for getting correct previous_txid
 * - TransactionProcessingService uses this for chain validation
 */
class ChainOperationsService implements ChainOperationsInterface
{
    /**
     * @var TransactionChainRepository Transaction chain repository for chain data access
     */
    private TransactionChainRepository $transactionChainRepository;

    /**
     * @var TransactionRepository Transaction repository for previous txid lookup
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var UserContext Current user context
     */
    private UserContext $currentUser;

    /**
     * @var SecureLogger Secure logger instance
     */
    private SecureLogger $secureLogger;

    /**
     * @var SyncServiceInterface|null Sync service (setter injected for circular dependency)
     */
    private ?SyncServiceInterface $syncService = null;

    /**
     * Constructor
     *
     * @param TransactionChainRepository $transactionChainRepository Transaction chain repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UserContext $currentUser Current user context
     * @param SecureLogger $secureLogger Secure logger instance
     */
    public function __construct(
        TransactionChainRepository $transactionChainRepository,
        TransactionRepository $transactionRepository,
        UserContext $currentUser,
        SecureLogger $secureLogger
    ) {
        $this->transactionChainRepository = $transactionChainRepository;
        $this->transactionRepository = $transactionRepository;
        $this->currentUser = $currentUser;
        $this->secureLogger = $secureLogger;
    }

    /**
     * Set the sync service for chain repair operations
     *
     * Uses setter injection to avoid circular dependency between
     * ChainOperationsService and SyncService.
     *
     * @param SyncServiceInterface $syncService The sync service instance
     * @return void
     */
    public function setSyncService(SyncServiceInterface $syncService): void
    {
        $this->syncService = $syncService;
    }

    /**
     * Verify chain integrity between two parties
     *
     * Checks that the transaction chain between the current user and
     * a contact is complete (no gaps, proper linking). Returns detailed
     * information about the chain state including any detected gaps.
     *
     * Delegates to TransactionChainRepository::verifyChainIntegrity() for
     * the actual verification logic.
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
    public function verifyChainIntegrity(string $userPubkey, string $contactPubkey): array
    {
        try {
            $chainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                $userPubkey,
                $contactPubkey
            );

            $this->secureLogger->debug("Chain integrity verification completed", [
                'valid' => $chainStatus['valid'],
                'transaction_count' => $chainStatus['transaction_count'],
                'gap_count' => count($chainStatus['gaps'] ?? [])
            ]);

            return $chainStatus;

        } catch (Exception $e) {
            $this->secureLogger->logException($e, [
                'method' => 'verifyChainIntegrity',
                'user_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $userPubkey),
                'contact_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPubkey)
            ]);

            // Return a safe default indicating invalid chain state
            return [
                'valid' => false,
                'has_transactions' => false,
                'transaction_count' => 0,
                'gaps' => [],
                'broken_txids' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get the correct previous txid for a new transaction
     *
     * Determines the correct previous_txid value for a new transaction
     * in the chain between the current user and a contact. Returns null
     * if this would be the first transaction in the chain.
     *
     * Uses TransactionRepository::getPreviousTxid() which finds the most
     * recent transaction between the two parties based on timestamp.
     *
     * @param string $userPubkey User's public key
     * @param string $contactPubkey Contact's public key
     * @return string|null The correct previous_txid or null if first transaction
     */
    public function getCorrectPreviousTxid(string $userPubkey, string $contactPubkey): ?string
    {
        try {
            $previousTxid = $this->transactionRepository->getPreviousTxid(
                $userPubkey,
                $contactPubkey
            );

            $this->secureLogger->debug("Previous txid lookup completed", [
                'has_previous' => $previousTxid !== null,
                'previous_txid' => $previousTxid ? substr($previousTxid, 0, 16) . '...' : null
            ]);

            return $previousTxid;

        } catch (Exception $e) {
            $this->secureLogger->logException($e, [
                'method' => 'getCorrectPreviousTxid',
                'user_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $userPubkey),
                'contact_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPubkey)
            ]);

            // Return null to indicate this should be treated as first transaction
            // (safe default that won't break chain, but may need sync later)
            return null;
        }
    }

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
    public function repairChainIfNeeded(string $contactAddress, string $contactPubkey): array
    {
        $result = [
            'success' => false,
            'was_valid' => false,
            'repair_attempted' => false,
            'synced_count' => 0,
            'error' => null
        ];

        try {
            $userPubkey = $this->currentUser->getPublicKey();

            // Step 1: Verify current chain integrity
            $chainStatus = $this->verifyChainIntegrity($userPubkey, $contactPubkey);

            // If chain is valid or empty, no repair needed
            if ($chainStatus['valid']) {
                $result['success'] = true;
                $result['was_valid'] = true;

                $this->secureLogger->debug("Chain repair check: chain is already valid", [
                    'contact_address' => $contactAddress,
                    'transaction_count' => $chainStatus['transaction_count']
                ]);

                return $result;
            }

            // Chain has gaps - need to attempt repair via sync
            $result['was_valid'] = false;
            $gapCount = count($chainStatus['gaps'] ?? []);

            $this->secureLogger->info("Chain repair needed: gaps detected", [
                'contact_address' => $contactAddress,
                'gap_count' => $gapCount,
                'transaction_count' => $chainStatus['transaction_count'],
                'broken_txids' => $chainStatus['broken_txids'] ?? []
            ]);

            // Output for CLI awareness (if output function exists)
            if (function_exists('output') && function_exists('outputSyncChainIntegrityFailed')) {
                output(outputSyncChainIntegrityFailed($gapCount), 'SILENT');
            }

            // Step 2: Check if sync service is available
            if ($this->syncService === null) {
                $result['error'] = 'Sync service not available to repair chain';

                $this->secureLogger->warning("Chain repair failed: sync service not injected", [
                    'contact_address' => $contactAddress,
                    'gap_count' => $gapCount
                ]);

                return $result;
            }

            // Step 3: Attempt repair via sync
            $result['repair_attempted'] = true;

            $syncResult = $this->syncService->syncTransactionChain(
                $contactAddress,
                $contactPubkey
            );

            $result['synced_count'] = $syncResult['synced_count'] ?? 0;

            if ($syncResult['success']) {
                // Step 4: Re-verify chain after sync
                $recheckStatus = $this->verifyChainIntegrity($userPubkey, $contactPubkey);

                if ($recheckStatus['valid']) {
                    $result['success'] = true;

                    $this->secureLogger->info("Chain repair successful", [
                        'contact_address' => $contactAddress,
                        'synced_count' => $result['synced_count'],
                        'transaction_count' => $recheckStatus['transaction_count']
                    ]);

                    // Output for CLI awareness
                    if (function_exists('output') && function_exists('outputSyncChainRepaired')) {
                        output(outputSyncChainRepaired(), 'SILENT');
                    }
                } else {
                    $result['error'] = 'Chain still has gaps after sync: ' . count($recheckStatus['gaps'] ?? []) . ' gaps remaining';

                    $this->secureLogger->warning("Chain repair incomplete: gaps remain after sync", [
                        'contact_address' => $contactAddress,
                        'synced_count' => $result['synced_count'],
                        'remaining_gaps' => $recheckStatus['gaps'] ?? []
                    ]);
                }
            } else {
                // Sync failed - check if chain is now valid anyway
                $recheckStatus = $this->verifyChainIntegrity($userPubkey, $contactPubkey);

                if ($recheckStatus['valid']) {
                    // Chain is valid despite sync reporting failure
                    $result['success'] = true;

                    $this->secureLogger->info("Chain valid after sync (despite sync failure)", [
                        'contact_address' => $contactAddress,
                        'sync_error' => $syncResult['error'] ?? 'unknown'
                    ]);
                } else {
                    $result['error'] = 'Failed to repair transaction chain: ' . ($syncResult['error'] ?? 'unknown error');

                    $this->secureLogger->warning("Chain repair failed", [
                        'contact_address' => $contactAddress,
                        'sync_error' => $syncResult['error'] ?? 'unknown',
                        'remaining_gaps' => count($recheckStatus['gaps'] ?? [])
                    ]);
                }
            }

        } catch (Exception $e) {
            $result['error'] = 'Exception during chain repair: ' . $e->getMessage();

            $this->secureLogger->logException($e, [
                'method' => 'repairChainIfNeeded',
                'contact_address' => $contactAddress
            ]);
        }

        return $result;
    }
}
