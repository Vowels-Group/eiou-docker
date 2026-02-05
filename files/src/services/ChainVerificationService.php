<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\ChainVerificationServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Database\TransactionChainRepository;
use Eiou\Core\UserContext;
use RuntimeException;

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

    /** @var Logger */
    private Logger $secureLogger;

    /**
     * @var SyncTriggerInterface|null Sync trigger for chain repair
     */
    private ?SyncTriggerInterface $syncTrigger = null;

    /**
     * Constructor
     *
     * @param TransactionChainRepository $transactionChainRepository Transaction chain repository
     * @param UserContext $currentUser Current user context
     * @param Logger $secureLogger Secure logger instance
     */
    public function __construct(
        TransactionChainRepository $transactionChainRepository,
        UserContext $currentUser,
        Logger $secureLogger
    ) {
        $this->transactionChainRepository = $transactionChainRepository;
        $this->currentUser = $currentUser;
        $this->secureLogger = $secureLogger;
    }

    /**
     * Set the sync trigger (accepts interface for loose coupling)
     *
     * @param SyncTriggerInterface $sync Sync trigger (can be proxy or actual service)
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void {
        $this->syncTrigger = $sync;
    }

    /**
     * Get the sync trigger (throws if not injected)
     *
     * @return SyncTriggerInterface
     * @throws RuntimeException If sync trigger not injected
     */
    private function getSyncTrigger(): SyncTriggerInterface {
        if ($this->syncTrigger === null) {
            throw new RuntimeException('SyncTrigger not injected. Call setSyncTrigger() or ensure ServiceContainer properly injects the dependency.');
        }
        return $this->syncTrigger;
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

        // Perform sync to repair chain (will throw if sync trigger not injected)
        $syncResult = $this->getSyncTrigger()->syncTransactionChain($contactAddress, $contactPublicKey);
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
