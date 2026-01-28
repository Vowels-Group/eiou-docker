<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';
require_once __DIR__ . '/../contracts/ChainVerificationServiceInterface.php';
require_once __DIR__ . '/../contracts/SyncServiceInterface.php';
require_once __DIR__ . '/../database/TransactionChainRepository.php';

/**
 * Chain Verification Service
 *
 * Handles verification of transaction chain integrity before new transactions
 * are created. Coordinates with SyncService to repair chains when gaps exist.
 */
class ChainVerificationService implements ChainVerificationServiceInterface {

    /** @var TransactionChainRepository */
    private TransactionChainRepository $transactionChainRepository;

    /** @var UserContext */
    private UserContext $currentUser;

    /** @var SecureLogger */
    private SecureLogger $secureLogger;

    /** @var SyncServiceInterface|null Sync service (setter injected for circular dependency) */
    private ?SyncServiceInterface $syncService = null;

    /**
     * Constructor
     *
     * @param TransactionChainRepository $transactionChainRepository Transaction chain repository
     * @param UserContext $currentUser Current user context
     * @param SecureLogger $secureLogger Secure logger instance
     */
    public function __construct(
        TransactionChainRepository $transactionChainRepository,
        UserContext $currentUser,
        SecureLogger $secureLogger
    ) {
        $this->transactionChainRepository = $transactionChainRepository;
        $this->currentUser = $currentUser;
        $this->secureLogger = $secureLogger;
    }

    /**
     * Set the sync service for chain repair operations
     *
     * @param SyncServiceInterface $syncService The sync service instance
     */
    public function setSyncService(SyncServiceInterface $syncService): void {
        $this->syncService = $syncService;
    }

    /**
     * Verify sender's local chain integrity and sync if needed
     *
     * @param string $contactAddress The contact's network address
     * @param string $contactPublicKey The contact's public key
     * @return array Result with success, synced, and error keys
     */
    public function verifySenderChainAndSync(string $contactAddress, string $contactPublicKey): array {
        $result = ['success' => true, 'synced' => false, 'error' => null];

        // Verify local chain integrity
        $chainStatus = $this->transactionChainRepository->verifyChainIntegrity(
            $this->currentUser->getPublicKey(),
            $contactPublicKey
        );

        // If chain is valid or empty, we're good to go
        if ($chainStatus['valid']) {
            return $result;
        }

        // Chain has gaps - need to sync
        output(outputSyncChainIntegrityFailed(count($chainStatus['gaps'])), 'SILENT');

        $this->secureLogger->info("Sender-side chain verification detected gaps, triggering sync", [
            'contact_address' => $contactAddress,
            'gap_count' => count($chainStatus['gaps']),
            'transaction_count' => $chainStatus['transaction_count']
        ]);

        // Sync service required for chain repair
        if ($this->syncService === null) {
            $result['success'] = false;
            $result['error'] = 'Sync service not available to repair chain';
            return $result;
        }

        // Perform sync to repair chain
        $syncResult = $this->syncService->syncTransactionChain($contactAddress, $contactPublicKey);
        $result['synced'] = true;

        if (!$syncResult['success']) {
            // Sync failed - check if chain is now valid anyway
            $recheckStatus = $this->transactionChainRepository->verifyChainIntegrity(
                $this->currentUser->getPublicKey(),
                $contactPublicKey
            );

            if (!$recheckStatus['valid']) {
                $result['success'] = false;
                $result['error'] = 'Failed to repair transaction chain: ' . ($syncResult['error'] ?? 'unknown error');
                return $result;
            }
        }

        output(outputSyncChainRepaired(), 'SILENT');
        return $result;
    }
}
