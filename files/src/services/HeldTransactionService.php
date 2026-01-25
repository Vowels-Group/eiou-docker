<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';
require_once __DIR__ . '/../core/Constants.php';

use Eiou\Contracts\HeldTransactionServiceInterface;

/**
 * Held Transaction Service
 *
 * Manages the lifecycle of transactions held pending resync completion.
 * When a transaction receives an invalid_previous_txid rejection, this service
 * coordinates with SyncService to resynchronize the transaction chain and then
 * resume the held transaction with the corrected previous_txid.
 *
 * Flow:
 * 1. Transaction rejected with invalid_previous_txid (receiver tells us expected previous_txid)
 * 2. holdTransactionForSync() stores transaction and initiates sync
 * 3. SyncService completes sync (including all transactions to maintain chain integrity)
 * 4. onSyncComplete() triggers processHeldTransactionsAfterSync()
 * 5. updatePreviousTxid() updates the transaction's previous_txid to the expected value
 * 6. resumeTransaction() sets status back to pending for reprocessing
 *
 * @package Services
 */
class HeldTransactionService implements HeldTransactionServiceInterface {
    /**
     * @var HeldTransactionRepository Held transaction repository
     */
    private $heldRepository;

    /**
     * @var TransactionRepository Transaction repository
     */
    private $transactionRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private $utilityContainer;

    /**
     * @var UserContext Current user data
     */
    private $currentUser;

    /**
     * @var TransactionPayload Transaction payload builder
     */
    private $transactionPayload;

    /**
     * @var SyncService|null Sync service for transaction chain sync
     */
    private ?SyncService $syncService = null;

    /**
     * Set the sync service (setter injection for circular dependency)
     *
     * @param SyncService $service Sync service
     */
    public function setSyncService(SyncService $service): void {
        $this->syncService = $service;
    }

    /**
     * Get the sync service (must be injected via setSyncService)
     *
     * @return SyncService
     * @throws RuntimeException If sync service was not injected
     */
    private function getSyncService(): SyncService {
        if ($this->syncService === null) {
            throw new RuntimeException('SyncService not injected. Call setSyncService() or ensure ServiceContainer::wireCircularDependencies() is called.');
        }
        return $this->syncService;
    }

    /**
     * Constructor
     *
     * @param HeldTransactionRepository $heldRepository Held transaction repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility service container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        $heldRepository,
        $transactionRepository,
        $utilityContainer,
        $currentUser
    ) {
        $this->heldRepository = $heldRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currentUser = $currentUser;

        require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
        $this->transactionPayload = new TransactionPayload($this->currentUser, $this->utilityContainer);
    }

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
    public function holdTransactionForSync(array $transaction, string $contactPubkey, ?string $expectedTxid = null): array {
        $result = [
            'held' => false,
            'sync_initiated' => false,
            'error' => null
        ];

        try {
            if (!isset($transaction['txid'])) {
                $result['error'] = 'Missing transaction txid';
                return $result;
            }

            $txid = $transaction['txid'];
            $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);

            // Check if transaction is already held
            if ($this->heldRepository->isTransactionHeld($txid)) {
                SecureLogger::info("Transaction already held", [
                    'txid' => $txid,
                    'contact_hash' => $contactPubkeyHash
                ]);
                $result['held'] = true;
                return $result;
            }

            // Determine transaction type
            $transactionType = ($transaction['memo'] ?? 'standard') === 'standard' ? 'standard' : 'p2p';

            // Get original previous_txid from transaction
            $originalPreviousTxid = $transaction['previous_txid'] ?? null;

            // Store transaction in held_transactions table
            $heldData = [
                'contact_pubkey_hash' => $contactPubkeyHash,
                'txid' => $txid,
                'original_previous_txid' => $originalPreviousTxid,
                'expected_previous_txid' => $expectedTxid,
                'transaction_type' => $transactionType,
                'hold_reason' => 'invalid_previous_txid',
                'sync_status' => 'not_started',
                'retry_count' => 0
            ];

            $inserted = $this->heldRepository->holdTransaction(
                $contactPubkeyHash,
                $txid,
                $originalPreviousTxid,
                $expectedTxid,
                $transactionType
            );
            if (!$inserted) {
                $result['error'] = 'Failed to insert held transaction';
                return $result;
            }

            $result['held'] = true;

            // Check if sync is already in progress for this contact
            if ($this->heldRepository->isSyncInProgress($contactPubkeyHash)) {
                SecureLogger::info("Sync already in progress for contact", [
                    'contact_hash' => $contactPubkeyHash,
                    'txid' => $txid
                ]);
                return $result;
            }

            // Update sync status to in_progress
            $started = $this->heldRepository->markSyncStarted($contactPubkeyHash);

            // Initiate sync through SyncService
            $syncService = $this->getSyncService();
            $contactAddress = $transaction['receiver_address'] ?? null;

            if (!$contactAddress) {
                SecureLogger::error("Cannot initiate sync: missing receiver_address", [
                    'txid' => $txid,
                    'contact_hash' => $contactPubkeyHash
                ]);
                $this->heldRepository->markSyncFailed($contactPubkeyHash);
                $result['error'] = 'Missing receiver address for sync';
                return $result;
            }

            $syncResult = $syncService->syncTransactionChain($contactAddress, $contactPubkey, $expectedTxid);

            if ($syncResult['success']) {
                SecureLogger::info("Sync initiated successfully", [
                    'contact_hash' => $contactPubkeyHash,
                    'synced_count' => $syncResult['synced_count']
                ]);
                $result['sync_initiated'] = true;

                // Mark sync as completed if successful
                $this->onSyncComplete($contactPubkey, true, $syncResult['synced_count']);
            } else {
                SecureLogger::warning("Sync initiation failed", [
                    'contact_hash' => $contactPubkeyHash,
                    'error' => $syncResult['error']
                ]);
                $this->heldRepository->markSyncFailed($contactPubkeyHash);
                $result['error'] = $syncResult['error'];
            }

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            SecureLogger::logException($e, [
                'method' => 'holdTransactionForSync',
                'txid' => $transaction['txid'] ?? 'unknown'
            ]);
        }

        return $result;
    }

    /**
     * Check if transactions should be held for this contact
     *
     * Returns true if sync is currently in progress, indicating that
     * new transactions should be queued rather than sent immediately.
     *
     * @param string $contactPubkey Contact's public key
     * @return bool True if sync in progress, false otherwise
     */
    public function shouldHoldTransactions(string $contactPubkey): bool {
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);
        return $this->heldRepository->isSyncInProgress($contactPubkeyHash);
    }

    /**
     * Process held transactions after sync completes
     *
     * Updates the previous_txid for held transactions to the expected value
     * from the rejection response, then resumes them for reprocessing.
     *
     * When a transaction is rejected for invalid_previous_txid, the receiver
     * tells us what previous_txid it expected. After syncing the chain,
     * we update the held transaction's previous_txid to match and resume it.
     *
     * @param string $contactPubkey Contact's public key
     * @return array Result with keys: resumed_count (int), failed_count (int)
     */
    public function processHeldTransactionsAfterSync(string $contactPubkey): array {
        $result = [
            'resumed_count' => 0,
            'failed_count' => 0
        ];

        try {
            $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);

            // Verify chain integrity before processing held transactions
            // This ensures the sync actually completed successfully
            $chainIntegrity = $this->transactionRepository->verifyChainIntegrity(
                $this->currentUser->getPublicKey(),
                $contactPubkey
            );

            if (!$chainIntegrity['valid']) {
                SecureLogger::warning("Chain integrity check failed after sync, cannot resume held transactions", [
                    'contact_pubkey_hash' => $contactPubkeyHash,
                    'gaps' => $chainIntegrity['gaps'] ?? [],
                    'broken_txids' => $chainIntegrity['broken_txids'] ?? []
                ]);
                // Don't return - still try to process, but log the warning
                // Some transactions may still be recoverable
            }

            // Get all held transactions for this contact that completed sync
            $heldTransactions = $this->heldRepository->getHeldTransactionsForContact($contactPubkeyHash, Constants::STATUS_COMPLETED);

            if (empty($heldTransactions)) {
                return $result;
            }

            foreach ($heldTransactions as $held) {
                $txid = $held['txid'];
                $expectedPreviousTxid = $held['expected_previous_txid'] ?? null;

                // Update the previous_txid to the expected value from the rejection
                $updated = $this->updatePreviousTxid($txid, $contactPubkey, $expectedPreviousTxid);

                if ($updated) {
                    // Re-sign the transaction with the new previous_txid
                    // This is critical because previous_txid is part of the signed payload
                    $resigned = $this->resignTransaction($txid);

                    if (!$resigned) {
                        $result['failed_count']++;
                        SecureLogger::warning("Failed to re-sign held transaction after previous_txid update", [
                            'txid' => $txid,
                            'expected_previous_txid' => $expectedPreviousTxid
                        ]);
                        // Release the held transaction to prevent infinite retry loops
                        // Mark as failed but don't leave it stuck
                        $this->heldRepository->markAsFailed($txid, 'resign_failed');
                        continue;
                    }

                    // Resume the transaction for reprocessing
                    $resumeResult = $this->resumeTransaction($txid);

                    if ($resumeResult['success']) {
                        $result['resumed_count']++;

                        // Mark held transaction as resolved (release it)
                        $this->heldRepository->releaseTransaction($txid);

                        SecureLogger::info("Held transaction resumed with corrected previous_txid and re-signed", [
                            'txid' => $txid,
                            'original_previous_txid' => $held['original_previous_txid'] ?? null,
                            'expected_previous_txid' => $expectedPreviousTxid,
                            'new_previous_txid' => $resumeResult['new_previous_txid']
                        ]);
                    } else {
                        $result['failed_count']++;
                        SecureLogger::warning("Failed to resume held transaction", [
                            'txid' => $txid,
                            'error' => $resumeResult['error']
                        ]);
                        // Release the held transaction to prevent infinite retry loops
                        $this->heldRepository->markAsFailed($txid, 'resume_failed: ' . ($resumeResult['error'] ?? 'unknown'));
                    }
                } else {
                    $result['failed_count']++;
                    SecureLogger::warning("Failed to update previous_txid for held transaction", [
                        'txid' => $txid,
                        'expected_previous_txid' => $expectedPreviousTxid
                    ]);
                    // Release the held transaction to prevent infinite retry loops
                    $this->heldRepository->markAsFailed($txid, 'update_previous_txid_failed');
                }
            }

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'method' => 'processHeldTransactionsAfterSync',
                'contact_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPubkey)
            ]);
        }

        return $result;
    }

    /**
     * Update the previous_txid for a held transaction
     *
     * Uses the expected_previous_txid from the rejection response, or falls back
     * to looking up the correct value from the transaction chain after sync.
     *
     * When a transaction is rejected for invalid_previous_txid, the receiver
     * tells us what previous_txid it expected. This method updates the held
     * transaction's previous_txid to match that expected value.
     *
     * @param string $txid Transaction ID
     * @param string $contactPubkey Contact's public key
     * @param string|null $expectedPreviousTxid The expected txid from rejection response
     * @return bool True if updated successfully
     */
    public function updatePreviousTxid(string $txid, string $contactPubkey, ?string $expectedPreviousTxid = null): bool {
        try {
            // Use expected_previous_txid from rejection if available, otherwise look up from chain
            $correctPreviousTxid = $expectedPreviousTxid;

            if ($correctPreviousTxid === null) {
                // Fallback: get from transaction chain, excluding the held transaction itself
                $correctPreviousTxid = $this->transactionRepository->getPreviousTxid(
                    $this->currentUser->getPublicKey(),
                    $contactPubkey,
                    $txid
                );
            }

            // Update the transaction's previous_txid
            $updated = $this->transactionRepository->updatePreviousTxid($txid, $correctPreviousTxid);

            if ($updated) {
                // Verify the update was persisted by re-reading from database
                $verifyTx = $this->transactionRepository->getByTxid($txid);
                $verifiedPreviousTxid = $verifyTx['previous_txid'] ?? 'NOT_FOUND';

                SecureLogger::info("Updated previous_txid for held transaction", [
                    'txid' => $txid,
                    'expected_from_rejection' => $expectedPreviousTxid,
                    'set_to' => $correctPreviousTxid,
                    'verified_in_db' => $verifiedPreviousTxid,
                    'match' => ($verifiedPreviousTxid === $correctPreviousTxid)
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'method' => 'updatePreviousTxid',
                'txid' => $txid
            ]);
            return false;
        }
    }

    /**
     * Resume a held transaction for reprocessing
     *
     * Sets the transaction status back to 'pending' so it will be picked up
     * by the next processing cycle and re-attempted with the corrected previous_txid.
     * The previous_txid should already be updated by updatePreviousTxid().
     *
     * @param string $txid Transaction ID
     * @return array Result with keys: success (bool), new_previous_txid (string|null), error (string|null)
     */
    public function resumeTransaction(string $txid): array {
        $result = [
            'success' => false,
            'new_previous_txid' => null,
            'error' => null
        ];

        try {
            // Get the transaction to verify it exists and get updated previous_txid
            $transaction = $this->transactionRepository->getByTxid($txid);

            if (!$transaction) {
                $result['error'] = 'Transaction not found';
                return $result;
            }

            // Update status to pending for reprocessing
            $updated = $this->transactionRepository->updateStatus($txid, Constants::STATUS_PENDING, true);

            if ($updated) {
                $result['success'] = true;
                $result['new_previous_txid'] = $transaction['previous_txid'] ?? null;

                SecureLogger::info("Transaction resumed for reprocessing", [
                    'txid' => $txid,
                    'previous_txid' => $result['new_previous_txid']
                ]);
            } else {
                $result['error'] = 'Failed to update transaction status';
            }

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            SecureLogger::logException($e, [
                'method' => 'resumeTransaction',
                'txid' => $txid
            ]);
        }

        return $result;
    }

    /**
     * Re-sign a transaction with updated previous_txid
     *
     * When a transaction's previous_txid is updated (e.g., after chain conflict resolution
     * or sync completion), the signature becomes invalid since previous_txid is part of
     * the signed payload. This method re-signs the transaction with the new previous_txid.
     *
     * @param string $txid Transaction ID
     * @return bool True if re-signing was successful
     */
    private function resignTransaction(string $txid): bool {
        try {
            // Get the transaction with updated previous_txid
            $transactionData = $this->transactionRepository->getByTxid($txid);

            if (!$transactionData) {
                SecureLogger::warning("Cannot resign: transaction not found", [
                    'txid' => $txid
                ]);
                return false;
            }

            // getByTxid returns an array of transactions
            $transaction = is_array($transactionData) && isset($transactionData[0]) ? $transactionData[0] : $transactionData;

            // Build the payload for signing based on memo type
            $memo = $transaction['memo'] ?? 'standard';

            if ($memo === 'standard') {
                $payload = $this->transactionPayload->buildStandardFromDatabase($transaction);
            } else {
                $payload = $this->transactionPayload->buildFromDatabase($transaction);
            }

            // Re-sign the transaction
            $transportUtility = $this->utilityContainer->getTransportUtility();
            $signResult = $transportUtility->signWithCapture($payload);

            if (!$signResult || !isset($signResult['signature']) || !isset($signResult['nonce'])) {
                SecureLogger::warning("Failed to re-sign transaction: signWithCapture returned invalid result", [
                    'txid' => $txid,
                    'has_result' => !empty($signResult)
                ]);
                return false;
            }

            // Update signature data in database
            $signatureUpdated = $this->transactionRepository->updateSignatureData(
                $txid,
                $signResult['signature'],
                $signResult['nonce']
            );

            if (!$signatureUpdated) {
                SecureLogger::warning("Failed to update signature data in database", [
                    'txid' => $txid
                ]);
                return false;
            }

            SecureLogger::info("Successfully re-signed transaction with updated previous_txid", [
                'txid' => $txid,
                'new_previous_txid' => $transaction['previous_txid'] ?? null,
                'new_nonce' => $signResult['nonce']
            ]);

            return true;

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'method' => 'resignTransaction',
                'txid' => $txid
            ]);
            return false;
        }
    }

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
    public function onSyncComplete(string $contactPubkey, bool $success, int $syncedCount = 0): void {
        try {
            $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);

            if ($success) {
                // Update sync status to completed
                $completed = $this->heldRepository->markSyncCompleted($contactPubkeyHash);

                SecureLogger::info("Sync completed, processing held transactions", [
                    'contact_hash' => $contactPubkeyHash,
                    'synced_count' => $syncedCount
                ]);

                // Process held transactions now that sync is complete
                $processResult = $this->processHeldTransactionsAfterSync($contactPubkey);

                SecureLogger::info("Held transaction processing complete", [
                    'contact_hash' => $contactPubkeyHash,
                    'resumed_count' => $processResult['resumed_count'],
                    'failed_count' => $processResult['failed_count']
                ]);
            } else {
                // Update sync status to failed
                $this->heldRepository->markSyncFailed($contactPubkeyHash);

                SecureLogger::warning("Sync failed for contact", [
                    'contact_hash' => $contactPubkeyHash
                ]);
            }

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'method' => 'onSyncComplete',
                'contact_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPubkey)
            ]);
        }
    }

    /**
     * Process held transactions ready to resume
     *
     * Finds transactions with sync_status='completed' and processes them.
     * This can be called periodically to handle any transactions that weren't
     * immediately processed after sync completion.
     *
     * Updates the previous_txid to the expected value from the rejection
     * response before resuming the transaction.
     *
     * @param int $limit Maximum number of transactions to process
     * @return array Result with keys: processed_count, resumed_count, failed_count
     */
    public function processHeldTransactions(int $limit = 10): array {
        $result = [
            'processed_count' => 0,
            'resumed_count' => 0,
            'failed_count' => 0
        ];

        try {
            // Get held transactions that are ready to resume (sync completed)
            $readyTransactions = $this->heldRepository->getTransactionsToResume($limit);

            if (empty($readyTransactions)) {
                return $result;
            }

            foreach ($readyTransactions as $held) {
                $result['processed_count']++;
                $txid = $held['txid'];
                $contactPubkeyHash = $held['contact_pubkey_hash'];
                $expectedPreviousTxid = $held['expected_previous_txid'] ?? null;

                // Get contact pubkey from the transaction record
                $transaction = $this->transactionRepository->getByTxid($txid);
                if (!$transaction) {
                    $result['failed_count']++;
                    SecureLogger::warning("Transaction not found for held transaction", [
                        'txid' => $txid
                    ]);
                    continue;
                }

                $contactPubkey = $transaction['receiver_public_key'];

                // Update previous_txid to the expected value from the rejection
                $updated = $this->updatePreviousTxid($txid, $contactPubkey, $expectedPreviousTxid);

                if ($updated) {
                    // Re-sign the transaction with the new previous_txid
                    // This is critical because previous_txid is part of the signed payload
                    $resigned = $this->resignTransaction($txid);

                    if (!$resigned) {
                        $result['failed_count']++;
                        SecureLogger::warning("Failed to re-sign held transaction after previous_txid update", [
                            'txid' => $txid,
                            'expected_previous_txid' => $expectedPreviousTxid
                        ]);
                        continue;
                    }

                    // Resume transaction
                    $resumeResult = $this->resumeTransaction($txid);

                    if ($resumeResult['success']) {
                        $result['resumed_count']++;
                        $this->heldRepository->releaseTransaction($txid);

                        SecureLogger::info("Held transaction resumed with corrected previous_txid and re-signed", [
                            'txid' => $txid,
                            'original_previous_txid' => $held['original_previous_txid'] ?? null,
                            'expected_previous_txid' => $expectedPreviousTxid,
                            'new_previous_txid' => $resumeResult['new_previous_txid']
                        ]);
                    } else {
                        $result['failed_count']++;
                        SecureLogger::warning("Failed to resume held transaction", [
                            'txid' => $txid,
                            'error' => $resumeResult['error'] ?? 'unknown'
                        ]);
                    }
                } else {
                    $result['failed_count']++;
                    SecureLogger::warning("Failed to update previous_txid for held transaction", [
                        'txid' => $txid,
                        'expected_previous_txid' => $expectedPreviousTxid
                    ]);
                }
            }

            if ($result['resumed_count'] > 0) {
                SecureLogger::info("Processed held transactions batch", $result);
            }

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'method' => 'processHeldTransactions'
            ]);
        }

        return $result;
    }

    /**
     * Get statistics about held transactions
     *
     * Returns counts and status information for monitoring and debugging.
     *
     * @return array Statistics including total, by_status, by_contact
     */
    public function getStatistics(): array {
        try {
            $stats = [
                'total' => 0,
                'by_status' => [
                    'not_started' => 0,
                    'in_progress' => 0,
                    'completed' => 0,
                    'failed' => 0
                ],
                'by_reason' => [
                    'invalid_previous_txid' => 0,
                    'sync_in_progress' => 0
                ],
                'oldest_held' => null,
                'newest_held' => null
            ];

            // Get all held transactions
            $allHeld = $this->heldRepository->getAll();

            if (empty($allHeld)) {
                return $stats;
            }

            $stats['total'] = count($allHeld);

            // Count by status and reason
            $oldestTime = null;
            $newestTime = null;

            foreach ($allHeld as $held) {
                $status = $held['sync_status'] ?? 'not_started';
                $reason = $held['hold_reason'] ?? 'invalid_previous_txid';

                if (isset($stats['by_status'][$status])) {
                    $stats['by_status'][$status]++;
                }

                if (isset($stats['by_reason'][$reason])) {
                    $stats['by_reason'][$reason]++;
                }

                // Track oldest and newest
                $heldAt = strtotime($held['held_at'] ?? 'now');
                if ($oldestTime === null || $heldAt < $oldestTime) {
                    $oldestTime = $heldAt;
                    $stats['oldest_held'] = $held['held_at'];
                }
                if ($newestTime === null || $heldAt > $newestTime) {
                    $newestTime = $heldAt;
                    $stats['newest_held'] = $held['held_at'];
                }
            }

            return $stats;

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'method' => 'getStatistics'
            ]);
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}
