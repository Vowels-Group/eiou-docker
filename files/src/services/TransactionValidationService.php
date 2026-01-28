<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';
require_once __DIR__ . '/../utils/InputValidator.php';
require_once __DIR__ . '/../core/Constants.php';
require_once __DIR__ . '/../core/ErrorHandler.php';
require_once __DIR__ . '/../contracts/TransactionValidationServiceInterface.php';
require_once __DIR__ . '/../contracts/SyncServiceInterface.php';

/**
 * Transaction Validation Service
 *
 * Handles all validation logic for transaction processing including:
 * - Previous transaction ID validation for chain integrity
 * - Available funds checking for transaction authorization
 * - Full transaction possibility validation with proactive sync
 *
 * This service is extracted from TransactionService as part of the God Class
 * refactoring effort.
 *
 * SECTION INDEX:
 * - Properties.......................... Line ~30
 * - Constructor & Dependency Injection.. Line ~85
 * - Validation Methods.................. Line ~140
 */
class TransactionValidationService implements TransactionValidationServiceInterface
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var ValidationUtilityService Validation utility service
     */
    private ValidationUtilityService $validationUtility;

    /**
     * @var InputValidator Input validator
     */
    private InputValidator $inputValidator;

    /**
     * @var TransactionPayload Transaction payload builder
     */
    private TransactionPayload $transactionPayload;

    /**
     * @var UserContext Current user context
     */
    private UserContext $currentUser;

    /**
     * @var SecureLogger Secure logger instance
     */
    private SecureLogger $secureLogger;

    /**
     * @var SyncServiceInterface|null Sync service (setter injection)
     */
    private ?SyncServiceInterface $syncService = null;

    /**
     * @var TransactionChainRepository Transaction chain repository
     */
    private TransactionChainRepository $transactionChainRepository;

    /**
     * @var TransactionServiceInterface|null Transaction service for processing
     */
    private ?TransactionServiceInterface $transactionService = null;

    // =========================================================================
    // CONSTRUCTOR & DEPENDENCY INJECTION
    // =========================================================================

    /**
     * Constructor
     *
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param ContactRepository $contactRepository Contact repository
     * @param ValidationUtilityService $validationUtility Validation utility service
     * @param InputValidator $inputValidator Input validator
     * @param TransactionPayload $transactionPayload Transaction payload builder
     * @param UserContext $currentUser Current user context
     * @param SecureLogger $secureLogger Secure logger
     */
    public function __construct(
        TransactionRepository $transactionRepository,
        ContactRepository $contactRepository,
        ValidationUtilityService $validationUtility,
        InputValidator $inputValidator,
        TransactionPayload $transactionPayload,
        UserContext $currentUser,
        SecureLogger $secureLogger
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->contactRepository = $contactRepository;
        $this->validationUtility = $validationUtility;
        $this->inputValidator = $inputValidator;
        $this->transactionPayload = $transactionPayload;
        $this->currentUser = $currentUser;
        $this->secureLogger = $secureLogger;

        // Initialize TransactionChainRepository
        require_once __DIR__ . '/../database/TransactionChainRepository.php';
        $this->transactionChainRepository = new TransactionChainRepository();
    }

    /**
     * Set the sync service (setter injection for circular dependency)
     *
     * @param SyncServiceInterface $syncService Sync service instance
     * @return void
     */
    public function setSyncService(SyncServiceInterface $syncService): void
    {
        $this->syncService = $syncService;
    }

    /**
     * Get the sync service (must be injected via setSyncService)
     *
     * @return SyncServiceInterface
     * @throws RuntimeException If sync service was not injected
     */
    private function getSyncService(): SyncServiceInterface
    {
        if ($this->syncService === null) {
            throw new RuntimeException(
                'SyncService not injected. Call setSyncService() or ensure ServiceContainer::wireCircularDependencies() is called.'
            );
        }
        return $this->syncService;
    }

    /**
     * Set the transaction service (setter injection for processing)
     *
     * @param TransactionServiceInterface $transactionService Transaction service
     * @return void
     */
    public function setTransactionService(TransactionServiceInterface $transactionService): void
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Get the transaction service (must be injected via setTransactionService)
     *
     * @return TransactionServiceInterface
     * @throws RuntimeException If transaction service was not injected
     */
    private function getTransactionService(): TransactionServiceInterface
    {
        if ($this->transactionService === null) {
            throw new RuntimeException(
                'TransactionService not injected. Call setTransactionService() or ensure ServiceContainer::wireCircularDependencies() is called.'
            );
        }
        return $this->transactionService;
    }

    // =========================================================================
    // VALIDATION METHODS
    // =========================================================================

    /**
     * Check if previous transaction ID is valid
     *
     * @param array $request The transaction request data
     * @return bool True if previous txid is valid or not required, false otherwise
     */
    public function checkPreviousTxid(array $request): bool
    {
        try {
            // Validate required fields - need receiverPublicKey for chain validation
            if (!isset($request['senderPublicKey'], $request['receiverPublicKey'])) {
                $this->secureLogger->error("Missing required fields for previous txid check", [
                    'request_keys' => array_keys($request)
                ]);
                return false;
            }

            // Get the expected previous txid from our transaction records
            // getPreviousTxid expects public keys (not addresses) to hash and compare
            $expectedPreviousTxid = $this->transactionRepository->getPreviousTxid(
                $request['senderPublicKey'],
                $request['receiverPublicKey']
            );

            // Get the previous txid from the incoming request (may be NULL for first tx or if sender lost data)
            $receivedPreviousTxid = $request['previousTxid'] ?? null;

            // Both must match exactly:
            // - NULL === NULL: Valid first transaction between parties
            // - "txid_abc" === "txid_abc": Valid continuation of chain
            // - NULL !== "txid_abc": Invalid - sender lost data, needs resync
            // - "txid_abc" !== "txid_xyz": Invalid - chain mismatch, needs resync
            if ($expectedPreviousTxid !== $receivedPreviousTxid) {
                $this->secureLogger->warning("Previous txid mismatch detected", [
                    'expected' => $expectedPreviousTxid,
                    'received' => $receivedPreviousTxid
                ]);
                return false;
            }

            return true;
        } catch (PDOException $e) {
            // Use SecureLogger's exception logging
            SecureLogger::logException($e, [
                'method' => 'checkPreviousTxid',
                'context' => 'transaction_validation'
            ]);
            throw $e;
        }
    }

    /**
     * Check if sender has sufficient available funds for transaction
     *
     * @param array $request The transaction request data
     * @return bool True if sufficient funds are available, false otherwise
     */
    public function checkAvailableFundsTransaction(array $request): bool
    {
        try {
            // Validate required fields
            if (!isset($request['senderPublicKey'], $request['amount'], $request['currency'])) {
                $this->secureLogger->error("Missing required fields for funds check", [
                    'request_keys' => array_keys($request)
                ]);
                return false;
            }

            // Validate amount using InputValidator
            $validation = InputValidator::validateAmount($request['amount'], $request['currency']);
            if (!$validation['valid']) {
                $this->secureLogger->error("Invalid amount in transaction request", [
                    'amount' => $request['amount'],
                    'error' => $validation['error']
                ]);
                return false;
            }
            // Use validated and sanitized amount
            $request['amount'] = $validation['value'];

            // Check if there is enough funds to complete the transaction (sufficient balance or credit limit)
            $availableFunds = $this->validationUtility->calculateAvailableFunds($request);
            $creditLimit = $this->contactRepository->getCreditLimit($request['senderPublicKey']);
            $requestedAmount = $request['amount'];

            if (($availableFunds + $creditLimit) < $requestedAmount) {
                // Note: Do NOT echo here - the caller (checkTransactionPossible) handles the response
                // Echoing here would cause duplicate JSON output breaking response parsing
                return false;
            }
            return true;
        } catch (PDOException $e) {
            // Use SecureLogger's exception logging
            SecureLogger::logException($e, [
                'method' => 'checkAvailableFundsTransaction',
                'context' => 'transaction_funds_validation'
            ]);
            throw $e;
        }
    }

    /**
     * Check Transaction is Possible
     *
     * Performs comprehensive validation and processes the transaction if valid.
     * This method contains proactive sync logic to handle chain mismatches.
     *
     * @param array $request Request data
     * @param bool $echo Whether to echo responses (default: true)
     * @return bool True if Transaction possible, False otherwise.
     */
    public function checkTransactionPossible(array $request, $echo = true): bool
    {
        $senderAddress = $request['senderAddress'];
        $pubkey = $request['senderPublicKey'];

        // Check if User is not blocked
        if (!$this->contactRepository->isNotBlocked($pubkey)) {
            if ($echo) {
                echo $this->transactionPayload->buildRejection($request, 'contact_blocked');
            }
            return false;
        }

        // Check if transaction is a valid successor of previous txids
        if (!$this->checkPreviousTxid($request)) {
            // Include expected_txid in rejection to help sender resync
            $expectedTxid = $this->transactionRepository->getPreviousTxid(
                $request['senderPublicKey'],
                $request['receiverPublicKey']
            );
            $receivedPreviousTxid = $request['previousTxid'] ?? null;

            // Log the mismatch details for debugging
            SecureLogger::warning("Rejecting transaction: invalid_previous_txid", [
                'txid' => $request['txid'] ?? 'unknown',
                'received_previous_txid' => $receivedPreviousTxid ?? 'NULL',
                'expected_previous_txid' => $expectedTxid ?? 'NULL',
                'sender' => $request['senderAddress'] ?? 'unknown'
            ]);

            // Proactive sync: If we (receiver) have a chain mismatch with the sender,
            // we may have lost data and should proactively sync with the sender.
            // This handles multiple scenarios:
            // 1. Receiver has no transactions (expectedTxid === null) but sender has prev_id
            // 2. Receiver has some transactions but is missing the most recent ones
            // 3. Receiver has a gap in the chain (missing middle transactions)
            //
            // In all cases where the chains don't match, attempt sync to recover.
            $shouldSync = false;
            $syncReason = '';

            if ($expectedTxid === null && $receivedPreviousTxid !== null) {
                // Case 1: Receiver has no transaction history with sender
                $shouldSync = true;
                $syncReason = 'receiver_has_no_history';
            } elseif ($expectedTxid !== null && $receivedPreviousTxid !== null && $expectedTxid !== $receivedPreviousTxid) {
                // Case 2/3: Receiver has different chain state than sender
                // Check if the received_previous_txid exists locally - if not, we're missing transactions
                $receivedPrevTxExists = $this->transactionRepository->transactionExistsTxid($receivedPreviousTxid);
                if (!$receivedPrevTxExists) {
                    $shouldSync = true;
                    $syncReason = 'receiver_missing_transactions';
                } else {
                    // Case 4: Chain fork detected - received_previous_txid exists but isn't our chain head
                    // This happens during simultaneous sends when both parties create transactions
                    // on top of the same base transaction. We need to sync and resolve the fork.
                    $shouldSync = true;
                    $syncReason = 'chain_fork_detected';
                }
            }

            if ($shouldSync) {
                SecureLogger::info("Chain mismatch detected - triggering proactive sync", [
                    'sender' => $request['senderAddress'] ?? 'unknown',
                    'received_previous_txid' => $receivedPreviousTxid,
                    'expected_previous_txid' => $expectedTxid,
                    'sync_reason' => $syncReason
                ]);

                // Proactively sync with the sender to recover missing transactions
                try {
                    $syncService = $this->getSyncService();
                    $syncResult = $syncService->syncTransactionChain(
                        $request['senderAddress'],
                        $request['senderPublicKey']
                    );

                    if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
                        SecureLogger::info("Proactive sync successful, retrying transaction validation", [
                            'synced_count' => $syncResult['synced_count'],
                            'sync_reason' => $syncReason
                        ]);

                        // Also sync balances after recovering transactions
                        $syncService->syncContactBalance($request['senderPublicKey']);

                        // Re-check the previous txid after sync
                        if ($this->checkPreviousTxid($request)) {
                            // Sync fixed the chain issue, now run remaining validations
                            // Check funds
                            if (!$this->checkAvailableFundsTransaction($request)) {
                                if ($echo) {
                                    echo $this->transactionPayload->buildRejection($request, 'insufficient_funds');
                                }
                                return false;
                            }

                            // Check for duplicate
                            $memo = $request['memo'];
                            if ($memo === "standard") {
                                $exists = $this->transactionRepository->transactionExistsTxid($request['txid']);
                            } else {
                                $exists = $this->transactionRepository->transactionExistsMemo($memo);
                            }
                            if ($exists) {
                                if ($echo) {
                                    echo $this->transactionPayload->buildRejection($request, 'duplicate');
                                }
                                return false;
                            }

                            // All checks passed after sync - process first, then accept
                            // IMPORTANT: Storage MUST succeed before acceptance is sent
                            // to prevent chain divergence from acceptance-before-storage bug
                            try {
                                // Generate recipient signature before processing so it's stored and can be returned
                                if (!isset($request['recipientSignature'])) {
                                    $request['recipientSignature'] = $this->transactionPayload->generateRecipientSignature($request);
                                }
                                $this->getTransactionService()->processTransaction($request);
                                if ($echo) {
                                    echo $this->transactionPayload->buildAcceptance($request);
                                }
                                // Return false to prevent caller from calling processTransaction again
                                return false;
                            } catch (Exception $e) {
                                SecureLogger::logException($e, [
                                    'method' => 'checkTransactionPossible',
                                    'context' => 'sync_transaction_processing_failed'
                                ]);
                                if ($echo) {
                                    echo $this->transactionPayload->buildRejection($request, 'processing_error');
                                }
                                return false;
                            }
                        }
                    }
                } catch (Exception $e) {
                    SecureLogger::logException($e, [
                        'method' => 'checkTransactionPossible',
                        'context' => 'proactive_sync_attempt'
                    ]);
                }
            }

            if ($echo) {
                echo $this->transactionPayload->buildRejection($request, 'invalid_previous_txid', $expectedTxid);
            }
            return false;
        }

        // Check if Contact has enough funds for Transaction
        if (!$this->checkAvailableFundsTransaction($request)) {
            if ($echo) {
                echo $this->transactionPayload->buildRejection($request, 'insufficient_funds');
            }
            return false;
        }

        // Check if Transaction already exists for txid or memo in database
        try {
            $memo = $request['memo'];
            if ($memo === "standard") {
                // If direct transaction
                $exists = $this->transactionRepository->transactionExistsTxid($request['txid']);
            } else {
                // If p2p based transaction
                $exists = $this->transactionRepository->transactionExistsMemo($memo);
            }
            if ($exists) {
                // Transaction with this txid already exists - check if this is a chain conflict resolution update
                // When both parties send transactions simultaneously with the same previous_txid,
                // the loser re-signs their transaction with the new previous_txid pointing to the winner.
                // We need to accept this update if the signature is valid.
                if ($memo === "standard") {
                    $existingTxData = $this->transactionRepository->getByTxid($request['txid']);
                    // getByTxid returns an array of transactions - extract first element
                    $existingTx = is_array($existingTxData) && isset($existingTxData[0])
                        ? $existingTxData[0]
                        : $existingTxData;
                    $newPreviousTxid = $request['previousTxid'] ?? null;
                    $existingPreviousTxid = $existingTx['previous_txid'] ?? null;

                    // If previous_txid is different, this might be a chain conflict resolution
                    if ($existingTx && $newPreviousTxid !== $existingPreviousTxid) {
                        SecureLogger::info("Received transaction with different previous_txid - checking for chain conflict resolution", [
                            'txid' => $request['txid'],
                            'existing_previous_txid' => $existingPreviousTxid,
                            'new_previous_txid' => $newPreviousTxid
                        ]);

                        // Verify the new signature is valid
                        $syncService = $this->getSyncService();
                        $txForVerification = [
                            'txid' => $request['txid'],
                            'previous_txid' => $newPreviousTxid,
                            'sender_address' => $request['senderAddress'],
                            'sender_public_key' => $request['senderPublicKey'],
                            'receiver_address' => $request['receiverAddress'],
                            'receiver_public_key' => $request['receiverPublicKey'],
                            'amount' => $request['amount'],
                            'currency' => $request['currency'],
                            'memo' => $request['memo'],
                            'description' => $request['description'] ?? null,
                            'time' => $request['time'] ?? null,
                            'sender_signature' => $request['senderSignature'] ?? null,
                            'signature_nonce' => $request['signatureNonce'] ?? null
                        ];

                        if ($syncService->verifyTransactionSignaturePublic($txForVerification)) {
                            // Valid signature - update the existing transaction
                            $updated = $this->transactionChainRepository->updateChainConflictResolution(
                                $request['txid'],
                                $newPreviousTxid,
                                $request['senderSignature'],
                                $request['signatureNonce']
                            );

                            if ($updated) {
                                SecureLogger::info("Chain conflict resolution update accepted", [
                                    'txid' => $request['txid'],
                                    'old_previous_txid' => $existingPreviousTxid,
                                    'new_previous_txid' => $newPreviousTxid
                                ]);

                                if ($echo) {
                                    echo $this->transactionPayload->buildAcceptance($request);
                                }
                                // Return false to prevent processTransaction from being called
                                // The transaction has already been updated in the database
                                return false;
                            }
                        } else {
                            SecureLogger::warning("Chain conflict resolution rejected - invalid signature", [
                                'txid' => $request['txid']
                            ]);
                        }
                    }
                }

                // Regular duplicate - reject
                if ($echo) {
                    echo $this->transactionPayload->buildRejection($request, 'duplicate');
                }
                return false;
            }

            // All validations passed - process transaction and echo acceptance
            // IMPORTANT: Storage MUST succeed before acceptance is sent
            // to prevent chain divergence from acceptance-before-storage bug
            try {
                // Generate recipient signature before processing so it's stored and can be returned
                if (!isset($request['recipientSignature'])) {
                    $request['recipientSignature'] = $this->transactionPayload->generateRecipientSignature($request);
                }
                $this->getTransactionService()->processTransaction($request);
                if ($echo) {
                    echo $this->transactionPayload->buildAcceptance($request);
                }
                // Return false to prevent caller from calling processTransaction again
                return false;
            } catch (Exception $e) {
                SecureLogger::logException($e, [
                    'method' => 'checkTransactionPossible',
                    'context' => 'normal_transaction_processing_failed'
                ]);
                if ($echo) {
                    echo $this->transactionPayload->buildRejection($request, 'processing_error');
                }
                return false;
            }
        } catch (PDOException $e) {
            // Use SecureLogger's exception logging
            SecureLogger::logException($e, [
                'method' => 'checkTransactionPossible',
                'context' => 'transaction_existence_check'
            ]);
            if ($echo) {
                echo json_encode(ErrorHandler::createErrorResponse(
                    "Could not retrieve existence of Transaction with receiver",
                    500
                ));
            }
            return false;
        }
    }
}
