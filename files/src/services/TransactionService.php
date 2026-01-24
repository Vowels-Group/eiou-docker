<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/InputValidator.php';
require_once __DIR__ . '/../cli/CliOutputManager.php';
require_once __DIR__ . '/MessageDeliveryService.php';
require_once __DIR__ . '/../core/ErrorCodes.php';

/**
 * Transaction Service
 *
 * Handles all business logic for transaction management.
 * Integrates with MessageDeliveryService for reliable message delivery
 * with tracking, retry logic, and dead letter queue support.
 *
 * @package Services
 *
 * SECTION INDEX:
 * - Properties.......................... Line ~20
 * - Constructor & Dependency Injection.. Line ~126
 * - Locking & Synchronization........... Line ~212
 * - Chain Verification & Sync........... Line ~281
 * - Message Sending..................... Line ~361
 * - Transaction Validation.............. Line ~400
 * - ID & Hash Generation................ Line ~778
 * - Transaction Matching & Fees......... Line ~823
 * - Transaction Data Preparation........ Line ~868
 * - Transaction Processing.............. Line ~953
 * - Send Operations..................... Line ~1567
 * - Balance & Contact Operations........ Line ~1863
 * - Repository Wrappers................. Line ~1923
 */
class TransactionService {

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var AddressRepository Address repository instance
     */
    private AddressRepository $addressRepository;

    /**
     * @var BalanceRepository Balance repository instance
     */
    private BalanceRepository $balanceRepository;

    /**
     * @var P2pRepository P2p repository instance
     */
    private P2pRepository $p2pRepository;

    /**
     * @var Rp2pRepository Rp2p repository instance
     */
    private Rp2pRepository $rp2pRepository;

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var CurrencyUtilityService Currecy utility service 
     */
    private CurrencyUtilityService $currencyUtility;

    /**
     * @var ValidationUtilityService Validation utility service 
     */
    private ValidationUtilityService $validationUtility;

    /**
     * @var TransportUtilityService Transport utility service 
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var TimeUtilityService Time utility service
     */
    private TimeUtilityService $timeUtility;

    /**
     * @var InputValidator InputValidator
     */
    private InputValidator $inputValidator;

    /**
     * @var SecureLogger SecureLogger
     */
    private SecureLogger $secureLogger;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var TransactionPayload payload builder for transactions
     */
    private TransactionPayload $transactionPayload;

    /**
     * @var UtilPayload payload builder for utility
     */
    private UtilPayload $utilPayload;

    /**
     * @var MessageDeliveryService|null Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

    /**
     * @var HeldTransactionService|null Held transaction service for pending sync
     */
    private ?HeldTransactionService $heldTransactionService = null;

    /**
     * @var SyncService|null Sync service for transaction chain synchronization
     */
    private ?SyncService $syncService = null;

    /**
     * @var P2pService|null P2P service for peer-to-peer transactions
     */
    private ?P2pService $p2pService = null;

    /**
     * @var ContactService|null Contact service for contact operations
     */
    private ?ContactService $contactService = null;

    /**
     * Set the sync service (setter injection for circular dependency)
     *
     * @param SyncService $service Sync service
     */
    public function setSyncService(SyncService $service): void {
        $this->syncService = $service;
    }

    /**
     * Get the sync service with fallback to Application singleton
     *
     * @return SyncService
     */
    private function getSyncService(): SyncService {
        if ($this->syncService === null) {
            $this->syncService = Application::getInstance()->services->getSyncService();
        }
        return $this->syncService;
    }

    /**
     * Set the P2P service (setter injection for circular dependency)
     *
     * @param P2pService $service P2P service
     */
    public function setP2pService(P2pService $service): void {
        $this->p2pService = $service;
    }

    /**
     * Get the P2P service with fallback to Application singleton
     *
     * @return P2pService
     */
    private function getP2pService(): P2pService {
        if ($this->p2pService === null) {
            $this->p2pService = Application::getInstance()->services->getP2pService();
        }
        return $this->p2pService;
    }

    /**
     * Set the contact service (setter injection for circular dependency)
     *
     * @param ContactService $service Contact service
     */
    public function setContactService(ContactService $service): void {
        $this->contactService = $service;
    }

    /**
     * Get the contact service with fallback to Application singleton
     *
     * @return ContactService
     */
    private function getContactService(): ContactService {
        if ($this->contactService === null) {
            $this->contactService = Application::getInstance()->services->getContactService();
        }
        return $this->contactService;
    }

    // =========================================================================
    // CONSTRUCTOR & DEPENDENCY INJECTION
    // =========================================================================

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param AddressRepository $addressRepository Address Repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param P2pRepository $p2pRepository P2p repository
     * @param Rp2pRepository $rp2pRepository Rp2p repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param InputValidator $inputValidator InputValidator
     * @param SecureLogger $secureLogger SecureLogger
     * @param UserContext $currentUser Current user data
     * @param MessageDeliveryService|null $messageDeliveryService Optional delivery service for tracking
     * @param HeldTransactionService|null $heldTransactionService Optional Held transaction service for pending sync
     * 
     */
    public function __construct(
        ContactRepository $contactRepository,
        AddressRepository $addressRepository,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        InputValidator $inputValidator,
        SecureLogger $secureLogger,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null,
        ?HeldTransactionService $heldTransactionService = null
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currencyUtility = $this->utilityContainer->getCurrencyUtility();
        $this->validationUtility = $this->utilityContainer->getValidationUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->inputValidator = $inputValidator;
        $this->secureLogger = $secureLogger;
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;
        $this->heldTransactionService = $heldTransactionService;

        require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
        $this->transactionPayload = new TransactionPayload($this->currentUser,$this->utilityContainer);

        require_once '/etc/eiou/src/schemas/payloads/UtilPayload.php';
        $this->utilPayload = new UtilPayload($this->currentUser,$this->utilityContainer);
    }

    /**
     * Set the message delivery service (for lazy initialization)
     *
     * @param MessageDeliveryService $service Message delivery service
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void {
        $this->messageDeliveryService = $service;
    }

    /**
     * Set the held transaction service (for lazy initialization)
     *
     * @param HeldTransactionService $service Held transaction service
     */
    public function setHeldTransactionService(HeldTransactionService $service): void {
        $this->heldTransactionService = $service;
    }

    // =========================================================================
    // LOCKING & SYNCHRONIZATION
    // =========================================================================

    /**
     * @var array Per-contact send locks to serialize simultaneous sends
     */
    private static array $contactSendLocks = [];

    /**
     * Acquire a lock for sending to a specific contact
     *
     * Prevents race conditions when sending multiple transactions
     * simultaneously to the same contact. Uses file-based locking for persistence
     * across request boundaries.
     *
     * @param string $contactPubkeyHash Hash of contact's public key
     * @param int $timeout Maximum time to wait for lock (seconds)
     * @return bool True if lock acquired, false if timeout
     */
    private function acquireContactSendLock(string $contactPubkeyHash, int $timeout = 30): bool {
        $lockFile = sys_get_temp_dir() . '/eiou_send_lock_' . $contactPubkeyHash . '.lock';

        // Try to open existing file or create new one
        $lockHandle = @fopen($lockFile, 'c');

        // If file exists but we can't open it (permission issue), try to delete and recreate
        if (!$lockHandle && file_exists($lockFile)) {
            @unlink($lockFile);
            $lockHandle = @fopen($lockFile, 'c');
        }

        if (!$lockHandle) {
            $this->secureLogger->warning("Failed to create lock file", [
                'contact_hash' => substr($contactPubkeyHash, 0, 16),
                'lock_file' => $lockFile
            ]);
            return false;
        }

        // Make the lock file world-writable so both CLI (root) and Apache (www-data) can use it
        @chmod($lockFile, 0666);

        $startTime = time();
        while (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (time() - $startTime >= $timeout) {
                fclose($lockHandle);
                $this->secureLogger->warning("Timeout acquiring send lock", [
                    'contact_hash' => substr($contactPubkeyHash, 0, 16),
                    'timeout' => $timeout
                ]);
                return false;
            }
            usleep(100000); // Wait 100ms before retrying
        }

        // Store the handle for later release
        self::$contactSendLocks[$contactPubkeyHash] = $lockHandle;
        return true;
    }

    /**
     * Release a contact send lock
     *
     * @param string $contactPubkeyHash Hash of contact's public key
     */
    private function releaseContactSendLock(string $contactPubkeyHash): void {
        if (isset(self::$contactSendLocks[$contactPubkeyHash])) {
            $lockHandle = self::$contactSendLocks[$contactPubkeyHash];
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            unset(self::$contactSendLocks[$contactPubkeyHash]);
        }
    }

    // =========================================================================
    // CHAIN VERIFICATION & SYNC
    // =========================================================================

    /**
     * Verify sender's local chain integrity and sync if needed
     *
     * Before creating a new transaction, verify that the local
     * transaction chain with the contact is complete. If gaps are detected,
     * trigger a sync to repair the chain before sending.
     *
     * @param string $contactAddress Contact's address
     * @param string $contactPublicKey Contact's public key
     * @return array Result with:
     *   - success: bool - Whether chain is ready for new transaction
     *   - synced: bool - Whether a sync was performed
     *   - error: string|null - Error message if failed
     */
    public function verifySenderChainAndSync(string $contactAddress, string $contactPublicKey): array {
        $result = [
            'success' => true,
            'synced' => false,
            'error' => null
        ];

        // Verify local chain integrity
        $chainStatus = $this->transactionRepository->verifyChainIntegrity(
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

        // Try to sync if sync service is available
        if ($this->syncService === null) {
            // Attempt to get sync service using getter with fallback
            try {
                $syncServiceRef = $this->getSyncService();
            } catch (\Exception $e) {
                $result['success'] = false;
                $result['error'] = 'Sync service not available to repair chain';
                return $result;
            }
        }

        // Perform sync to repair chain
        $syncResult = $this->syncService->syncTransactionChain($contactAddress, $contactPublicKey);
        $result['synced'] = true;

        if (!$syncResult['success']) {
            // Sync failed - check if chain is now valid anyway
            $recheckStatus = $this->transactionRepository->verifyChainIntegrity(
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

    // =========================================================================
    // MESSAGE SENDING
    // =========================================================================

    /**
     * Send a transaction message with optional delivery tracking
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Falls back to direct transport
     * if delivery service is not configured.
     *
     * Message ID format varies by transaction type:
     * - Original send: send-{txid}-{timestamp} (user initiated the transaction)
     * - Relay: relay-{txid}-{timestamp} (user is forwarding for another party)
     * - Special formats: {prefix}-{txid}-{timestamp} (e.g., completion-response)
     *
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string $txid Transaction ID for tracking
     * @param bool $isRelay Whether this is a relay (forwarding) vs original send
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    private function sendTransactionMessage(string $address, array $payload, string $txid, bool $isRelay = false): array {
        // Generate unique message ID for tracking
        // Format: {prefix}-{txid}-{timestamp} (message_type 'transaction' provides context)
        // Use relay- prefix for forwarded transactions, send- for original sends
        // If txid already contains a prefix (e.g., completion-response-), use it as-is
        $hasPrefix = strpos($txid, '-') !== false;
        $prefix = $hasPrefix ? '' : ($isRelay ? 'relay-' : 'send-');
        $messageId = $prefix . $txid . '-' . $this->timeUtility->getCurrentMicrotime();

        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // Use sync delivery (async=false) for transactions to ensure reliability
            return $this->messageDeliveryService->sendMessage(
                'transaction',
                $address,
                $payload,
                $messageId,
                false // sync
            );
        }

        // Fall back to direct transport when MessageDeliveryService not available
        $rawResponse = $this->transportUtility->send($address, $payload);
        $response = json_decode($rawResponse, true);

        return [
            'success' => $response !== null && isset($response['status']),
            'response' => $response,
            'raw' => $rawResponse,
            'messageId' => $messageId
        ];
    }

    // =========================================================================
    // TRANSACTION VALIDATION
    // =========================================================================

    /**
     * Check if previous transaction ID is valid
     *
     * @param array $request The transaction request data
     * @return bool True if previous txid is valid or not required, false otherwise
     */
    public function checkPreviousTxid(array $request): bool {
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
    public function checkAvailableFundsTransaction(array $request): bool {
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
     * @param array|null $request Request data
     * @return bool True if Transaction possible, False otherwise.
     */
    public function checkTransactionPossible(array $request, $echo = true) : bool{
        $senderAddress = $request['senderAddress'];
        $pubkey = $request['senderPublicKey'];
        // Check if User is not blocked
        if(!$this->contactRepository->isNotBlocked($pubkey)){
            if($echo){
                echo $this->transactionPayload->buildRejection($request, 'contact_blocked');
            }
            return false;
        }
        // Check if transaction is a valid successor of previous txids
        elseif(!$this->checkPreviousTxid($request)){
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
                                $this->processTransaction($request);
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

            if($echo){
                echo $this->transactionPayload->buildRejection($request, 'invalid_previous_txid', $expectedTxid);
            }
            return false;
        }
        // Check if Contact has enough funds for Transaction
        elseif(!$this->checkAvailableFundsTransaction($request)){
            if($echo){
                echo $this->transactionPayload->buildRejection($request, 'insufficient_funds');
            }
            return false;
        }
        // Check if Transaction already exists for txid or memo in database
        try{
            $memo = $request['memo'];
            if($memo === "standard"){
                // If direct transaction
                $exists = $this->transactionRepository->transactionExistsTxid($request['txid']);
            } else{
                // If p2p based transaction
                $exists = $this->transactionRepository->transactionExistsMemo($memo);
            }
            if($exists){
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
                            $updated = $this->transactionRepository->updateChainConflictResolution(
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
                if($echo){
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
                $this->processTransaction($request);
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
            if($echo){
                echo json_encode(ErrorHandler::createErrorResponse(
                    "Could not retrieve existence of Transaction with receiver",
                    500
                ));
            }
            return false;
        }
    }

    // =========================================================================
    // ID & HASH GENERATION
    // =========================================================================

    /**
     * Create unique transaction ID from transaction data
     *
     * @param array $data Transaction data including receiverPublicKey, amount, and time
     * @return string The generated transaction ID (SHA-256 hash)
     */
    public function createUniqueTxid(array $data): string {
        // Validate required fields
        if (!isset($data['receiverPublicKey'], $data['amount'], $data['time'])) {
            throw new InvalidArgumentException("Missing required fields for txid creation");
        }

        // Create Txid for transactions
        $txid = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $data['receiverPublicKey'] . $data['amount'] . $data['time']);
        return $txid;
    }

    /**
     * Create unique database transaction ID
     *
     * @param array $data Database transaction data
     * @param array $rp2p Database rp2p data
     * @return string The generated transaction ID
     */
    public function createUniqueDatabaseTxid(array $data, array $rp2p): string {
        // Create unique Txid for transactions (from database values)
        $txid = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $rp2p['sender_public_key'] . $data['amount'] . $rp2p['time']);
        return $txid;
    }

    /**
     * Create contact request hash (memo)
     *
     * The hash for contact requests is: address + salt + time
     *
     * @param string $receiverAddress The receiver's address
     * @param string $salt Random salt value
     * @param string $time Timestamp
     * @return string The generated hash (SHA-256)
     */
    public function createContactHash(string $receiverAddress, string $salt, string $time): string {
        return hash(Constants::HASH_ALGORITHM, $receiverAddress . $salt . $time);
    }

    /**
     * Check if the Transaction end-recipient is user
     *
     * @param array $request Request data
     * @param string $address Address 
     * @return bool True if user corresponds, False otherwise.
     */
    public function matchYourselfTransaction($request, $address){
        // Check if transaction end recipient is user
        $p2pRequest = $this->p2pRepository->getByHash($request['memo']);

        // First check the provided address (most likely match)
        if (hash(Constants::HASH_ALGORITHM, $address . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
            return true;
        }

        // If primary address didn't match, check all user addresses
        // This handles cases where message was wrapped/forwarded over different networks
        // getUserLocaters() returns addresses mapped by type (e.g., ['http' => '...', 'tor' => '...'])
        $allAddresses = $this->currentUser->getUserLocaters();

        foreach ($allAddresses as $userAddress) {
            // Skip if this is the same address we already checked
            if ($userAddress === $address) {
                continue;
            }
            if (hash(Constants::HASH_ALGORITHM, $userAddress . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove users transaction fee from request
     *
     * @param array $request The request data
     * @return float Amount left over after fee removal
    */
    public function removeTransactionFee(array $request): float{
        $p2p = $this->p2pRepository->getByHash($request['memo']);
        return $request['amount'] - $p2p['my_fee_amount'];
    }

    /**
     * Prepare standard transaction data
     *
     * @param array $request Request data
     * @param array $contactInfo Contact information
     * @return array Prepared transaction data
     */
    public function prepareStandardTransactionData(array $request, array $contactInfo): array {
        // Prepare initial data payload for direct transaction
        output(outputPrepareSendData($request), 'SILENT');

        $data['txType'] = 'standard';
        $data['time'] = $this->timeUtility->getCurrentMicrotime();
        $data['amount'] = round($request[3] * Constants::TRANSACTION_USD_CONVERSION_FACTOR); // Convert to cents
        $data['currency'] = $request[4] ?? Constants::TRANSACTION_DEFAULT_CURRENCY; // Get currency or default to USD
        $data['memo'] = 'standard';
        $data['description'] = $request[5] ?? null; // Optional description (only shared with end recipient)

        // Determine Transport Type (fallback on other if needed)
        $transportIndex = $this->transportUtility->fallbackTransportType($request[2],$contactInfo);
        if ($transportIndex === null) {
            throw new \InvalidArgumentException("No viable transport mode found for recipient");
        }
        // Additional data preparation
        $data['receiverAddress'] = $contactInfo[$transportIndex];
        $data['receiverPublicKey'] = $contactInfo['receiverPublicKey'];
        $data['txid'] = $this->createUniqueTxid($data);

        // Include previous_txid in outgoing transaction for chain validation
        $data['previousTxid'] = $this->transactionRepository->getPreviousTxid(
            $this->currentUser->getPublicKey(),
            $data['receiverPublicKey']
        );

        // Populate address tracking fields for direct transactions
        // User is the original sender: end_recipient is receiver, initial_sender is own address
        $data['end_recipient_address'] = $data['receiverAddress'];
        $data['initial_sender_address'] = $this->transportUtility->resolveUserAddressForTransport($data['receiverAddress']);

        return $data;
    }

    /**
     * Prepare P2P transaction data
     *
     * @param array $request The RP2P request data
     * @param string|null $description Optional description (only included for final recipient)
     * @return array Prepared transaction data
     */
    public function prepareP2pTransactionData(array $request, ?string $description = null): array {
        // Prepare data for p2p transaction
        $data['time'] = $request['time'];

        // Send transaction back to rp2p sender
        $data['receiverAddress'] = $request['senderAddress'];
        $data['receiverPublicKey'] = $request['senderPublicKey'];

        $data['amount'] = $request['amount'];
        $data['currency'] = $request['currency'];
        $data['txid'] = $this->createUniqueTxid($data);
        $data['memo'] = $request['hash'];

        // Include previous_txid in outgoing transaction for chain validation
        $data['previousTxid'] = $this->transactionRepository->getPreviousTxid(
            $this->currentUser->getPublicKey(),
            $data['receiverPublicKey']
        );

        // Privacy: Description only sent to final recipient, not to relay nodes
        if ($description !== null) {
            $data['description'] = $description;
        }

        // Populate address tracking fields for P2P transactions
        // Set end_recipient from p2p.destination_address
        $p2p = $this->p2pRepository->getByHash($request['hash']);
        if ($p2p && isset($p2p['destination_address'])) {
            $data['end_recipient_address'] = $p2p['destination_address'];
        }

        // Set initial_sender_address to own address (original sender perspective)
        $data['initial_sender_address'] = $this->transportUtility->resolveUserAddressForTransport($data['receiverAddress']);

        return $data;
    }

    // =========================================================================
    // TRANSACTION PROCESSING
    // =========================================================================

    /**
     * Process incoming transaction request
     *
     * @param array $request The transaction request data
     * @return void
     */
    public function processTransaction(array $request): void {
        try {
            // Validate required fields
            if (!isset($request['memo'], $request['senderAddress'])) {
                $this->secureLogger->error("Missing required fields in transaction request", [
                    'request_keys' => array_keys($request)
                ]);
                throw new InvalidArgumentException("Invalid transaction request structure");
            }

            // Process incoming transactions
            if ($request['memo'] === 'standard') {
                // If direct transaction - receiver knows both sender and recipient
                // end_recipient is myself (receiver), initial_sender is the sender
                $myAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

                // Generate recipient signature if not already present
                // This signature proves the receiver accepted the transaction
                if (!isset($request['recipientSignature'])) {
                    $request['recipientSignature'] = $this->transactionPayload->generateRecipientSignature($request);
                }

                $insertTransactionResponse = $this->transactionRepository->insertTransaction($request,'received');

                // Update tracking fields after insert (these are NOT part of signed payload)
                $this->transactionRepository->updateTrackingFields(
                    $request['txid'],
                    $myAddress,  // endRecipientAddress
                    $request['senderAddress']  // initialSenderAddress
                );
            } else {
                // If p2p type transaction
                $memo = $request['memo'];
                $rP2pResult = $this->rp2pRepository->getByHash($memo);
                // Check if precursors to transactions exist and correspond
                if (isset($rP2pResult) && $memo === $rP2pResult['hash']) {
                    // Relay transaction - leave address fields NULL (privacy-preserving)
                    // Relay doesn't know the original sender or final recipient
                    // Use the sender's txid from the incoming request (not regenerated)
                    $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($request,'relay'), true);
                    output(outputTransactionInsertion($insertTransactionResponse));
                } elseif ($this->matchYourselfTransaction($request, $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']))) {
                    // If Transaction is for end-recipient
                    // end_recipient is myself, initial_sender will be updated via inquiry message later
                    $myAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

                    // Generate recipient signature if not already present
                    // This signature proves the receiver accepted the transaction
                    if (!isset($request['recipientSignature'])) {
                        $request['recipientSignature'] = $this->transactionPayload->generateRecipientSignature($request);
                    }

                    $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($request,'received'), true);
                    output(outputTransactionInsertion($insertTransactionResponse));

                    // Update tracking fields after insert (initial_sender set later via inquiry)
                    $this->transactionRepository->updateTrackingFields(
                        $request['txid'],
                        $myAddress,  // endRecipientAddress
                        null  // initialSenderAddress - will be set when inquiry message arrives
                    );
                }
            }
        } catch (PDOException $e) {
            // Use SecureLogger's exception logging
            SecureLogger::logException($e, [
                'method' => 'processTransaction',
                'context' => 'transaction_processing'
            ]);
            throw $e;
        } catch (Exception $e) {
            // Use SecureLogger's exception logging
            SecureLogger::logException($e, [
                'method' => 'processTransaction',
                'context' => 'transaction_processing'
            ]);
            throw $e;
        }
    }

    /**
     * Process pending transactions
     *
     * Uses atomic claiming to prevent duplicate processing:
     * 1. Atomically claim transaction (PENDING -> SENDING)
     * 2. If claim fails, skip (another process is handling it)
     * 3. Send the transaction
     * 4. Update status based on result (SENDING -> SENT/ACCEPTED/REJECTED)
     *
     * If process crashes while SENDING, TransactionRecoveryService will
     * recover the transaction on next startup.
     *
     * @return int Number of processed transactions
     */
    public function processPendingTransactions(): int {
        // Process pending transactions in database
        $pendingMessages = $this->transactionRepository->getPendingTransactions();
        $processedCount = 0;

        // Process each pending message
        foreach ($pendingMessages as $message) {
            $memo = $message['memo'];
            $txid = $message['txid'];

            // If direct transaction
            if($memo === 'standard'){
                // If you're sending the direct transaction
                if($message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address'])){

                    // ATOMIC CLAIM: Prevent duplicate processing by claiming the transaction
                    // This atomically changes status from PENDING to SENDING
                    // If another process already claimed it, claimPendingTransaction returns false
                    if (!$this->transactionRepository->claimPendingTransaction($txid)) {
                        SecureLogger::info("Transaction already claimed by another process, skipping", [
                            'txid' => $txid
                        ]);
                        continue; // Skip - another process is handling this
                    }

                    $payload = $this->transactionPayload->buildStandardFromDatabase($message);

                    // Log the payload being sent (for debugging held transaction resumes)
                    SecureLogger::info("Sending standard transaction (claimed)", [
                        'txid' => $txid,
                        'previous_txid_in_db' => $message['previous_txid'] ?? 'NULL',
                        'previous_txid_in_payload' => $payload['previousTxid'] ?? 'NULL',
                        'receiver' => $message['receiver_address']
                    ]);

                    // Transaction is now in SENDING status - actually send it
                    // If we crash here, TransactionRecoveryService will handle recovery
                    $sendResult = $this->sendTransactionMessage($message['receiver_address'], $payload, $txid);

                    // Mark as sent (SENDING -> SENT) after successful send attempt
                    $this->transactionRepository->markAsSent($txid);
                    $response = $sendResult['response'];
                    output(outputTransactionInquiryResponse($response),'SILENT');

                    if($response && $response['status'] === Constants::STATUS_ACCEPTED){
                        $this->transactionRepository->updateStatus($txid, Constants::STATUS_ACCEPTED, true);

                        // Store signature data for future sync verification
                        $signingData = $sendResult['signing_data'] ?? null;
                        if ($signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                            $this->transactionRepository->updateSignatureData(
                                $txid,
                                $signingData['signature'],
                                $signingData['nonce']
                            );
                        }

                        // Store recipient signature for future sync verification
                        if (isset($response['recipientSignature'])) {
                            $this->transactionRepository->updateRecipientSignature(
                                $txid,
                                $response['recipientSignature']
                            );
                        }
                    } elseif($response && $response['status'] === Constants::STATUS_REJECTED){
                        // Check if rejection is due to invalid_previous_txid - attempt inline retry first
                        if (isset($response['reason']) && $response['reason'] === 'invalid_previous_txid') {
                            $expectedTxid = $response['expected_txid'] ?? null;

                            // Fast path: If receiver provides expected_txid, try immediate re-sign and retry
                            // This handles simultaneous sends efficiently without needing sync
                            if ($expectedTxid !== null) {
                                output(outputSyncInlineRetryAttempt(), 'SILENT');

                                // Update previous_txid to the expected value
                                $updatedPrevTxid = $this->transactionRepository->updatePreviousTxid($txid, $expectedTxid);

                                if ($updatedPrevTxid) {
                                    // Re-fetch the updated transaction
                                    $updatedMessageData = $this->transactionRepository->getByTxid($txid);
                                    // getByTxid returns an array of transactions - extract first element
                                    $updatedMessage = is_array($updatedMessageData) && isset($updatedMessageData[0])
                                        ? $updatedMessageData[0]
                                        : $updatedMessageData;
                                    if ($updatedMessage) {
                                        // Re-build the payload with new previous_txid
                                        $newPayload = $this->transactionPayload->buildStandardFromDatabase($updatedMessage);

                                        // Re-sign the transaction
                                        $transportUtility = $this->utilityContainer->getTransportUtility();
                                        $signResult = $transportUtility->signWithCapture($newPayload);

                                        if ($signResult && isset($signResult['signature']) && isset($signResult['nonce'])) {
                                            // Update signature in database
                                            $this->transactionRepository->updateSignatureData(
                                                $txid,
                                                $signResult['signature'],
                                                $signResult['nonce']
                                            );

                                            // Reset status to pending so it will be picked up on next cycle
                                            $this->transactionRepository->updateStatus($txid, Constants::STATUS_PENDING, true);

                                            output(outputSyncInlineRetrySuccess(), 'SILENT');

                                            // Also trigger sync to recover missing transactions that expected_txid references
                                            // This ensures local chain is complete, not just the outgoing pointer
                                            $syncService = $this->getSyncService();
                                            $syncResult = $syncService->syncTransactionChain(
                                                $message['receiver_address'],
                                                $message['receiver_public_key']
                                            );
                                            if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
                                                output(outputSyncTransactionsSynced($syncResult['synced_count']), 'SILENT');
                                            }

                                            // Use break to stop processing this batch - dependent transactions
                                            // have stale previous_txid references. Next cycle will process correctly.
                                            break;
                                        }
                                    }
                                }
                                output(outputSyncInlineRetryFailed(), 'SILENT');
                            }

                            // Fallback to hold/sync flow if inline retry didn't work
                            output(outputSyncHoldingForSync(), 'SILENT');

                            // Use HeldTransactionService if available
                            if ($this->heldTransactionService !== null) {
                                $holdResult = $this->heldTransactionService->holdTransactionForSync(
                                    $message,
                                    $message['receiver_public_key'],
                                    $expectedTxid
                                );

                                if ($holdResult['held']) {
                                    output(outputSyncHeld(), 'SILENT');
                                    continue; // Transaction will be resumed after sync completes
                                }
                            }

                            // Fallback to existing sync behavior if holding failed
                            output('Attempting immediate sync...', 'SILENT');
                            $syncService = $this->getSyncService();
                            $syncResult = $syncService->syncTransactionChain(
                                $message['receiver_address'],
                                $message['receiver_public_key']
                            );

                            if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
                                // Sync successful - also sync balances to ensure consistency
                                output('Sync successful, ' . $syncResult['synced_count'] . ' transactions synced. Syncing balances...', 'SILENT');

                                // Sync balances after transaction chain sync
                                $syncService->syncContactBalance($message['receiver_public_key']);

                                output('Balances synced. Retrying transaction...', 'SILENT');
                                // Transaction remains pending and will be retried
                                continue;
                            } else {
                                // Sync failed or no transactions to sync - fall back to P2P
                                output(outputSyncFallbackP2p(), 'SILENT');
                            }
                        }

                        $this->transactionRepository->updateStatus($txid, Constants::STATUS_REJECTED, true);
                        output(outputIssueTransactionTryP2p($response),'SILENT');
                        // Send P2P request for failed direct transaction using P2pService directly
                        $this->getP2pService()->sendP2pRequestFromFailedDirectTransaction($message);
                    } elseif(!$sendResult['success']) {
                        // Message delivery failed after retries
                        $trackingResult = $sendResult['tracking'] ?? [];
                        $this->secureLogger->warning("Transaction delivery failed", [
                            'txid' => $txid,
                            'attempts' => $trackingResult['attempts'] ?? 'unknown',
                            'error' => $trackingResult['error'] ?? 'Unknown error',
                            'moved_to_dlq' => $trackingResult['dlq'] ?? false
                        ]);
                    }
                }
                // If you received the direct transaction
                else{
                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_COMPLETED, true);
                    $this->balanceRepository->updateBalance($message['sender_public_key'], 'received', $message['amount'], $message['currency']);
                    output(outputTransactionAmountReceived($message),'SILENT');

                // Ensure description is available for completion message
                // If not in transaction record, fetch from p2p table
                if (!isset($message['description']) || $message['description'] === null) {
                    $p2p = $this->p2pRepository->getByHash($memo);
                    if ($p2p && isset($p2p['description'])) {
                        $message['description'] = $p2p['description'];
                    }
                }

                $payloadTransactionCompleted = $this->transactionPayload->buildCompleted($message);
                    output(outputSendTransactionCompletionMessageTxid($message),'SILENT');

                    // Send completion message with delivery tracking
                    // Format: completion-response-{txid}-{timestamp} (responding to direct transaction received)
                    // Note: The sender's delivery record is marked complete when they receive this completion response
                    $this->sendTransactionMessage($message['sender_address'], $payloadTransactionCompleted, 'completion-response-' . $txid);
                }
                $processedCount++;
            } else{
                // If p2p transaction - also needs atomic claiming
                if ($this->processP2pTransaction($message, $memo, $txid)) {
                    $processedCount++;
                }
            }
        }

        return $processedCount;
    }

    /**
     * Process P2P transaction
     *
     * Uses atomic claiming to prevent duplicate processing for outgoing transactions.
     *
     * @param array $message Transaction message
     * @param string $memo Transaction memo
     * @param string $txid Transaction ID
     * @return bool True if transaction was processed, false if skipped
     */
    private function processP2pTransaction(array $message, string $memo, string $txid): bool {
        // If you're sending the transaction
        if($message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address'])){

            // ATOMIC CLAIM: Prevent duplicate processing
            if (!$this->transactionRepository->claimPendingTransaction($txid)) {
                SecureLogger::info("P2P transaction already claimed by another process, skipping", [
                    'txid' => $txid,
                    'memo' => $memo
                ]);
                return false; // Skip - another process is handling this
            }

            $rp2p = $this->rp2pRepository->getByHash($memo);
            $message['time'] = $rp2p['time'];

            // Check if user is original sender (has destination_address) or intermediary (relay)
            // Original sender: destination_address is set when P2P request was created
            // Intermediary: destination_address is NULL when forwarding P2P request
            $p2p = $this->p2pRepository->getByHash($memo);
            $isRelay = !isset($p2p['destination_address']) || $p2p['destination_address'] === null;

            // Populate address tracking fields only if original sender
            if (!$isRelay && isset($p2p['destination_address'])) {
                // Original sender: Set both fields
                $message['end_recipient_address'] = $p2p['destination_address'];
                $message['initial_sender_address'] = $this->transportUtility->resolveUserAddressForTransport($message['sender_address']);
            }
            // Relay/Intermediary: Leave fields NULL (privacy-preserving)

            // If sending transaction forwards
            $payload = $this->transactionPayload->buildFromDatabase($message);
            $this->p2pRepository->updateStatus($memo, Constants::STATUS_PAID);
            output(outputSendTransactionOnwards($message),'SILENT');

            // Send with delivery tracking
            // Use relay- prefix for forwarded transactions, send- for original sends
            $sendResult = $this->sendTransactionMessage($message['receiver_address'], $payload, $txid, $isRelay);

            // Mark as sent after send attempt (SENDING -> SENT)
            $this->transactionRepository->markAsSent($txid);
            $response = $sendResult['response'];

            if($response && $response['status'] === Constants::STATUS_ACCEPTED){
                $this->transactionRepository->updateStatus($txid, Constants::STATUS_ACCEPTED);

                // Store signature data for future sync verification
                $signingData = $sendResult['signing_data'] ?? null;
                if ($signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                    $this->transactionRepository->updateSignatureData(
                        $txid,
                        $signingData['signature'],
                        $signingData['nonce']
                    );
                }

                // Store recipient signature for future sync verification
                if (isset($response['recipientSignature'])) {
                    $this->transactionRepository->updateRecipientSignature(
                        $txid,
                        $response['recipientSignature']
                    );
                }
            } elseif($response && $response['status'] === Constants::STATUS_REJECTED){
                // Check if rejection is due to invalid_previous_txid - attempt inline retry first
                if (isset($response['reason']) && $response['reason'] === 'invalid_previous_txid') {
                    $expectedTxid = $response['expected_txid'] ?? null;

                    // Fast path: If receiver provides expected_txid, try immediate re-sign and retry
                    // This handles simultaneous sends efficiently without needing sync
                    if ($expectedTxid !== null) {
                        output(outputSyncP2pInlineRetryAttempt(), 'SILENT');

                        // Update previous_txid to the expected value
                        $updatedPrevTxid = $this->transactionRepository->updatePreviousTxid($txid, $expectedTxid);

                        if ($updatedPrevTxid) {
                            // Re-fetch the updated transaction
                            $updatedMessageData = $this->transactionRepository->getByTxid($txid);
                            // getByTxid returns an array of transactions - extract first element
                            $updatedMessage = is_array($updatedMessageData) && isset($updatedMessageData[0])
                                ? $updatedMessageData[0]
                                : $updatedMessageData;
                            if ($updatedMessage) {
                                // Re-build the payload with new previous_txid (use buildFromDatabase for P2P)
                                $newPayload = $this->transactionPayload->buildFromDatabase($updatedMessage);

                                // Re-sign the transaction
                                $transportUtility = $this->utilityContainer->getTransportUtility();
                                $signResult = $transportUtility->signWithCapture($newPayload);

                                if ($signResult && isset($signResult['signature']) && isset($signResult['nonce'])) {
                                    // Update signature in database
                                    $this->transactionRepository->updateSignatureData(
                                        $txid,
                                        $signResult['signature'],
                                        $signResult['nonce']
                                    );

                                    output(outputSyncP2pInlineRetrySuccess(), 'SILENT');

                                    // IMMEDIATE RETRY: Send the re-signed transaction now instead of waiting
                                    // This prevents P2P expiration from cancelling the transaction between cycles
                                    output('Inline retry: Immediately resending transaction with updated previous_txid...', 'SILENT');

                                    // Re-build payload with updated signature from database
                                    $retryMessageData = $this->transactionRepository->getByTxid($txid);
                                    $retryMessage = is_array($retryMessageData) && isset($retryMessageData[0])
                                        ? $retryMessageData[0]
                                        : $retryMessageData;

                                    if ($retryMessage) {
                                        $retryPayload = $this->transactionPayload->buildFromDatabase($retryMessage);
                                        $retrySendResult = $this->sendTransactionMessage($retryMessage['receiver_address'], $retryPayload, $txid, $isRelay);
                                        $retryResponse = $retrySendResult['response'];

                                        if ($retryResponse && $retryResponse['status'] === Constants::STATUS_ACCEPTED) {
                                            output('Inline retry: Transaction accepted!', 'SILENT');
                                            $this->transactionRepository->updateStatus($txid, Constants::STATUS_ACCEPTED, true);

                                            // Store signature data for future sync verification
                                            $retrySigData = $retrySendResult['signing_data'] ?? null;
                                            if ($retrySigData && isset($retrySigData['signature']) && isset($retrySigData['nonce'])) {
                                                $this->transactionRepository->updateSignatureData(
                                                    $txid,
                                                    $retrySigData['signature'],
                                                    $retrySigData['nonce']
                                                );
                                            }

                                            // Store recipient signature for future sync verification
                                            if (isset($retryResponse['recipientSignature'])) {
                                                $this->transactionRepository->updateRecipientSignature(
                                                    $txid,
                                                    $retryResponse['recipientSignature']
                                                );
                                            }
                                            return true; // Transaction accepted after inline retry
                                        } elseif ($retryResponse && $retryResponse['status'] === Constants::STATUS_REJECTED) {
                                            output('Inline retry: Still rejected after update, reason: ' . ($retryResponse['reason'] ?? 'unknown'), 'SILENT');
                                            // Fall through to sync/hold flow below
                                        } else {
                                            output('Inline retry: Send failed, falling back to sync flow', 'SILENT');
                                            // Fall through to sync/hold flow below
                                        }
                                    }
                                }
                            }
                        }
                        output(outputSyncInlineRetryFailed(), 'SILENT');
                    }

                    // Fallback to hold/sync flow if inline retry didn't work
                    output(outputSyncP2pHoldingForSync(), 'SILENT');

                    // Use HeldTransactionService if available
                    if ($this->heldTransactionService !== null) {
                        $holdResult = $this->heldTransactionService->holdTransactionForSync(
                            $message,
                            $message['receiver_public_key'],
                            $expectedTxid
                        );

                        if ($holdResult['held']) {
                            output(outputSyncHeld(), 'SILENT');
                            return true; // Transaction will be resumed after sync completes
                        }
                    }

                    // Fallback to existing sync behavior if holding failed
                    output('Attempting immediate sync...', 'SILENT');
                    $syncService = $this->getSyncService();
                    $syncResult = $syncService->syncTransactionChain(
                        $message['receiver_address'],
                        $message['receiver_public_key']
                    );

                    if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
                        // Sync successful - also sync balances to ensure consistency
                        output('Sync successful, ' . $syncResult['synced_count'] . ' transactions synced. Syncing balances...', 'SILENT');

                        // Sync balances after transaction chain sync
                        $syncService->syncContactBalance($message['receiver_public_key']);

                        // CRITICAL: After sync, update previous_txid to correct value
                        // The sync brought in missing transactions, so getPreviousTxid now returns correct value
                        $correctPrevTxid = $this->transactionRepository->getPreviousTxid(
                            $this->currentUser->getPublicKey(),
                            $message['receiver_public_key']
                        );

                        if ($correctPrevTxid !== null) {
                            // Update the transaction's previous_txid
                            $this->transactionRepository->updatePreviousTxid($txid, $correctPrevTxid);

                            // Re-fetch and re-sign the transaction with updated previous_txid
                            $updatedMessageData = $this->transactionRepository->getByTxid($txid);
                            $updatedMessage = is_array($updatedMessageData) && isset($updatedMessageData[0])
                                ? $updatedMessageData[0]
                                : $updatedMessageData;

                            if ($updatedMessage) {
                                // Re-build payload with correct previous_txid
                                $newPayload = $this->transactionPayload->buildFromDatabase($updatedMessage);

                                // Re-sign the transaction
                                $transportUtility = $this->utilityContainer->getTransportUtility();
                                $signResult = $transportUtility->signWithCapture($newPayload);

                                if ($signResult && isset($signResult['signature']) && isset($signResult['nonce'])) {
                                    // Update signature in database
                                    $this->transactionRepository->updateSignatureData(
                                        $txid,
                                        $signResult['signature'],
                                        $signResult['nonce']
                                    );
                                    output('Transaction re-signed with correct previous_txid after sync', 'SILENT');

                                    // IMMEDIATE RETRY after sync: Send the re-signed transaction now
                                    // This prevents P2P expiration from cancelling the transaction between cycles
                                    output('Sync retry: Immediately resending transaction after sync...', 'SILENT');

                                    $syncRetryPayload = $this->transactionPayload->buildFromDatabase($updatedMessage);
                                    $syncRetrySendResult = $this->sendTransactionMessage($updatedMessage['receiver_address'], $syncRetryPayload, $txid, $isRelay);
                                    $syncRetryResponse = $syncRetrySendResult['response'];

                                    if ($syncRetryResponse && $syncRetryResponse['status'] === Constants::STATUS_ACCEPTED) {
                                        output('Sync retry: Transaction accepted!', 'SILENT');
                                        $this->transactionRepository->updateStatus($txid, Constants::STATUS_ACCEPTED, true);

                                        // Store signature data for future sync verification
                                        $syncRetrySigData = $syncRetrySendResult['signing_data'] ?? null;
                                        if ($syncRetrySigData && isset($syncRetrySigData['signature']) && isset($syncRetrySigData['nonce'])) {
                                            $this->transactionRepository->updateSignatureData(
                                                $txid,
                                                $syncRetrySigData['signature'],
                                                $syncRetrySigData['nonce']
                                            );

                                        }

                                        // Store recipient signature for future sync verification
                                        if (isset($syncRetryResponse['recipientSignature'])) {
                                            $this->transactionRepository->updateRecipientSignature(
                                                $txid,
                                                $syncRetryResponse['recipientSignature']
                                            );
                                        }
                                        return true; // Transaction accepted after sync retry
                                    } else {
                                        output('Sync retry: Send failed or rejected, marking transaction accordingly', 'SILENT');
                                        // Fall through to cancellation below
                                    }
                                }
                            }
                        }
                    } else {
                        output('Sync failed or no transactions to sync', 'SILENT');
                    }

                    // If we got here after sync, it means the immediate retry didn't succeed
                    // Fall through to cancellation
                }

                $this->p2pRepository->updateStatus($memo, Constants::STATUS_CANCELLED);
                $this->transactionRepository->updateStatus($memo, Constants::STATUS_REJECTED);
            } elseif(!$sendResult['success']) {
                // Message delivery failed after retries
                $trackingResult = $sendResult['tracking'] ?? [];
                $this->secureLogger->warning("P2P transaction delivery failed", [
                    'txid' => $txid,
                    'memo' => $memo,
                    'attempts' => $trackingResult['attempts'] ?? 'unknown',
                    'error' => $trackingResult['error'] ?? 'Unknown error',
                    'moved_to_dlq' => $trackingResult['dlq'] ?? false
                ]);
            }
            output(outputTransactionResponse($response),'SILENT');
            return true; // Transaction was processed (sent)
        }
         // If receiving transaction
        else{
            // If not end-recipient of transaction
            if(!$this->matchYourselfTransaction($message,$this->transportUtility->resolveUserAddressForTransport($message['sender_address']))) {
                $this->transactionRepository->updateStatus($memo, Constants::STATUS_ACCEPTED);
                $this->p2pRepository->updateIncomingTxid($message['memo'], $message['txid']);

                // Create new transaction, from received prior transaction, for sending onwards to sender of rp2p
                $rp2p = $this->rp2pRepository->getByHash($message['memo']);


                $data = $this->transactionPayload->buildForwarding($message, $rp2p);
                $payload = $this->transactionPayload->buildFromDatabase($data);

                $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($payload,'relay'),true);

                $this->p2pRepository->updateOutgoingTxid($data['memo'], $data['txid']);
                output(outputTransactionInsertion($insertTransactionResponse));
            }
             // If end-recipient of transaction
            else{
                $this->p2pRepository->updateStatus($memo, Constants::STATUS_COMPLETED, true);
                $this->transactionRepository->updateStatus($memo, Constants::STATUS_COMPLETED);
                $this->balanceRepository->updateBalance($message['sender_public_key'], 'received', $message['amount'], $message['currency']);
                $this->p2pRepository->updateIncomingTxid($message['memo'], $message['txid']);
                output(outputTransactionAmountReceived($message),'SILENT');

                // Ensure description is available for completion message
                // If not in transaction record, fetch from p2p table
                if (!isset($message['description']) || $message['description'] === null) {
                    $p2p = $this->p2pRepository->getByHash($memo);
                    if ($p2p && isset($p2p['description'])) {
                        $message['description'] = $p2p['description'];
                    }
                }

                $payloadTransactionCompleted = $this->transactionPayload->buildCompleted($message);
                output(outputSendTransactionCompletionMessageMemo($message),'SILENT');

                // Mark the P2P delivery chain as completed since transaction was received
                // Uses pattern matching to find message_ids like direct-{hash}-{contactHash}
                if ($this->messageDeliveryService !== null) {
                    $this->messageDeliveryService->markCompletedByHash('p2p', $memo);
                }

                // Send completion message with delivery tracking
                // Format: completion-response-{txid}-{timestamp} (P2P end-recipient responding with completion)
                $this->sendTransactionMessage($message['sender_address'], $payloadTransactionCompleted, 'completion-response-' . $txid);
            }
            return true; // Transaction was processed (received)
        }
        return false; // Should not reach here
    }

    // =========================================================================
    // SEND OPERATIONS
    // =========================================================================

    /**
     * Send eIOU
     *
     * @param array $request Request data (argv-style array with recipient, amount, currency)
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function sendEiou(array $request, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Build transaction data for JSON response
        $txData = [
            'recipient' => $request[2] ?? null,
            'amount' => $request[3] ?? null,
            'currency' => $request[4] ?? 'USD',
            'description' => $request[5] ?? null
        ];

        // Validate Parameter count
        if (isset($request)) {
            $amountValidation = $this->inputValidator->validateArgvAmount($request, 4);
            if (!$amountValidation['valid']) {
                $this->secureLogger->warning("Invalid parameter amount", [
                    'value' => $request,
                    'error' => $amountValidation['error']
                ]);
                $output->error("Invalid parameter amount: " . $amountValidation['error'], ErrorCodes::INVALID_PARAMS, 400);
                return;
            }
        }

        // Validate address/name if provided
        if (isset($request[2])) {
            $addressValidation = $this->inputValidator->validateAddress($request[2]);
            $nameValidation = $this->inputValidator->validateContactName($request[2]);
            if (!$addressValidation['valid']){
                if (!$nameValidation['valid']) {
                    $this->secureLogger->warning("Invalid Address/name", [
                        'value' => $request[2],
                        'error' => $addressValidation['error'] . " / " . $nameValidation['error']
                    ]);
                    $output->error("Invalid Address/name: " . $addressValidation['error'], ErrorCodes::INVALID_RECIPIENT, 400);
                    return;
                }
            }

            // Check if recipient is one of user's own addresses (self-send prevention)
            if ($addressValidation['valid']) {
                $selfSendValidation = $this->inputValidator->validateNotSelfSend($request[2], $this->currentUser);
                if (!$selfSendValidation['valid']) {
                    $this->secureLogger->warning("Self-send transaction attempted", [
                        'recipient' => $request[2],
                        'error' => $selfSendValidation['error']
                    ]);
                    $output->error("Cannot send to yourself: " . $selfSendValidation['error'], ErrorCodes::SELF_SEND, 400);
                    return;
                }
            }
        }

        // Validate and sanitize amount if provided
        if (isset($request[3])) {
            $amountValidation = $this->inputValidator->validateAmount($request[3], $request[4] ?? 'USD');
            if (!$amountValidation['valid']) {
                $this->secureLogger->warning("Invalid transaction amount", [
                    'amount' => $request[3],
                    'error' => $amountValidation['error']
                ]);
                $output->error("Invalid amount: " . $amountValidation['error'], ErrorCodes::INVALID_AMOUNT, 400);
                return;
            }
            $request[3] = $amountValidation['value'];
            $txData['amount'] = $request[3];
        }

        // Validate currency if provided
        if (isset($request[4])) {
            $currencyValidation = $this->inputValidator->validateCurrency($request[4]);
            if (!$currencyValidation['valid']) {
                $this->secureLogger->warning("Invalid currency code", [
                    'currency' => $request[4],
                    'error' => $currencyValidation['error']
                ]);
                $output->error("Invalid currency: " . $currencyValidation['error'], ErrorCodes::INVALID_CURRENCY, 400);
                return;
            }
            $request[4] = $currencyValidation['value'];
            $txData['currency'] = $request[4];
        }

        // Check if any contacts for eIOU
        if(!$this->addressRepository->getAllAddresses()){
            $output->error("No contacts available for transaction", 'NO_CONTACTS', 400, $txData);
            return;
        }

        // If receiver's public key is in contacts, prepare a transaction to send directly to them
        $contactService = $this->getContactService();
        if ($contactInfo = $contactService->lookupContactInfo($request[2])) {
            if($contactInfo['status'] === Constants::CONTACT_STATUS_ACCEPTED){
                // Contact is accepted
                $this->handleDirectRoute($request, $contactInfo, $output);
            }elseif($contactInfo['status'] === Constants::CONTACT_STATUS_PENDING){
                // Contact is still pending, try a resync otherwise send through p2p if possible

                // Determine Transport Type (fallback on other if needed)
                $transportIndex = $this->transportUtility->fallbackTransportType($request[2],$contactInfo);
                if ($transportIndex === null) {
                    // No viable transport mode found, try P2P
                    $this->handleP2pRoute($request, $output);
                } else {
                    $syncResult = $this->getSyncService()->syncSingleContact($contactInfo[$transportIndex],'SILENT');
                    if($syncResult){
                        $this->handleDirectRoute($request, $contactInfo, $output);
                    } else{
                        $this->handleP2pRoute($request, $output);
                    }
                }
            } elseif($contactInfo['status'] === Constants::CONTACT_STATUS_BLOCKED){
                // Contact is blocked, do not send anything
                $output->error("Cannot send to blocked contact", 'CONTACT_BLOCKED', 403, $txData);
            }
        } else {
            // Contact not found, try sending through p2p network
            $this->handleP2pRoute($request, $output);
        }
    }

    /**
     * Send Direct eIOU
     *
     * Verifies sender-side chain integrity before sending.
     * Uses per-contact locking to serialize simultaneous sends.
     *
     * @param array $request Request data
     * @param array $contactInfo Contact information
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function handleDirectRoute(array $request, $contactInfo, ?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        // Acquire per-contact lock to serialize simultaneous sends
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactInfo['receiverPublicKey']);
        if (!$this->acquireContactSendLock($contactPubkeyHash)) {
            $output->error(
                "Cannot send transaction: Another transaction to this contact is in progress",
                ErrorCodes::TRANSACTION_IN_PROGRESS ?? 'TRANSACTION_IN_PROGRESS',
                429,
                ['recipient' => $request[2] ?? null]
            );
            return;
        }

        try {
            // Determine transport type for chain verification
            $transportIndex = $this->transportUtility->fallbackTransportType($request[2] ?? '', $contactInfo);
            $contactAddress = $transportIndex !== null ? ($contactInfo[$transportIndex] ?? null) : null;

            // Verify sender-side chain integrity before creating transaction
            if ($contactAddress !== null && isset($contactInfo['receiverPublicKey'])) {
                $chainVerification = $this->verifySenderChainAndSync($contactAddress, $contactInfo['receiverPublicKey']);
                if (!$chainVerification['success']) {
                    $output->error(
                        "Cannot send transaction: " . ($chainVerification['error'] ?? 'Chain verification failed'),
                        ErrorCodes::CHAIN_INTEGRITY_FAILED,
                        500,
                        ['recipient' => $request[2] ?? null, 'synced' => $chainVerification['synced']]
                    );
                    return;
                }
                if ($chainVerification['synced']) {
                    output(outputSyncChainRepairedBeforeSend(), 'SILENT');
                }
            }

            // Data preparation for eIOU
            $data = $this->prepareStandardTransactionData($request, $contactInfo);
        } catch (\InvalidArgumentException $e) {
            // No viable transport mode found
            $output->error(
                "Cannot send transaction: " . $e->getMessage(),
                ErrorCodes::NO_VIABLE_TRANSPORT,
                400,
                ['recipient' => $request[2] ?? null]
            );
            return;
        } finally {
            // Always release lock after operation completes
            $this->releaseContactSendLock($contactPubkeyHash);
        }

        // Prepare transaction payload from data
        $payload = $this->transactionPayload->build($data);
        $this->transactionRepository->insertTransaction($payload, Constants::TX_TYPE_SENT);

        // Update tracking fields after insert (these are NOT part of signed payload)
        $this->transactionRepository->updateTrackingFields(
            $data['txid'],
            $data['end_recipient_address'] ?? null,
            $data['initial_sender_address'] ?? null
        );

        // Build response data
        $txResponse = [
            'status' => Constants::STATUS_SENT,
            'type' => 'direct',
            'recipient' => $contactInfo['receiverName'] ?? $request[2],
            'recipient_address' => $data['receiverAddress'] ?? null,
            'amount' => ($data['amount'] ?? 0) / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
            'currency' => $data['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY,
            'description' => $data['description'] ?? null,
            'txid' => $data['txid'] ?? null,
            'timestamp' => $data['time'] ?? null
        ];

        $output->success("Transaction sent successfully to " . $data['receiverAddress'] , $txResponse, "Direct transaction initiated");
    }

    /**
     * Send out p2p message to find route to contact for sending a eIOU
     *
     * @param array $request Request data
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function handleP2pRoute(array $request, ?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        // Build transaction data for response
        $txData = [
            'recipient' => $request[2] ?? null,
            'amount' => $request[3] ?? null,
            'currency' => $request[4] ?? 'USD',
            'description' => $request[5] ?? null
        ];

        try {
            // Send P2P request when contact not found using P2pService directly
            $this->getP2pService()->sendP2pRequest($request);

            // Build response data
            $txResponse = array_merge($txData, [
                'status' => Constants::STATUS_PENDING,
                'type' => 'p2p',
                'message' => 'P2P route discovery initiated'
            ]);

            $output->success("Searching for route via P2P network to " . $request[2], $txResponse, "Searching for route to recipient via P2P network");
        } catch (\InvalidArgumentException $e) {
            // Contact/address not found - return proper error for GUI
            $output->error(
                "Recipient not found: " . ($request[2] ?? 'unknown') . " is not a valid address or existing contact",
                ErrorCodes::INVALID_RECIPIENT,
                400,
                $txData
            );
        }
    }

    /**
     * Send P2P eIOU
     *
     * @param array $request Request data
     * @return void
     */
    public function sendP2pEiou(array $request): void {
        // Handler for sending transactions upon successfully receiving route to end-recipient
        output(outputP2pEiouSend($request),'SILENT');

        // Retrieve description from P2P table (privacy: only sent to final recipient)
        $p2p = $this->p2pRepository->getByHash($request['hash']);
        $description = $p2p['description'] ?? null;

        // Create data to send back to rp2p sender
        $data = $this->prepareP2pTransactionData($request, $description);

        // Prepare transaction payload
        $payload = $this->transactionPayload->build($data);
        $this->transactionRepository->insertTransaction($payload, Constants::TX_TYPE_SENT);
        $this->p2pRepository->updateOutgoingTxid($data['memo'], $data['txid']);

        // Update tracking fields after insert (these are NOT part of signed payload)
        $this->transactionRepository->updateTrackingFields(
            $data['txid'],
            $data['end_recipient_address'] ?? null,
            $data['initial_sender_address'] ?? null
        );
    }

    // =========================================================================
    // BALANCE & CONTACT OPERATIONS
    // =========================================================================

    /**
     * Convert Contact Information back to proper units for display
     *
     * @param array $contacts Contact Information
     * @param int $transactionLimit Maximum number of transactions to fetch per contact
     * @return array Converted contact information
     */
    public function contactBalanceConversion($contacts, int $transactionLimit = 5): array {
        // If no contacts, return empty array
        if (empty($contacts)) {
            return [];
        }

        // Extract all pubkeys for batch processing
        $pubkeys = array_column($contacts, 'pubkey');

        // Get all balances in a single optimized query
        $balances = $this->transactionRepository->getAllContactBalances($this->currentUser->getPublicKey(), $pubkeys);

        // Build result array with balances
        $contactsWithBalances = [];
        
        $addressTypes = $this->addressRepository->getAllAddressTypes();
        
        foreach($contacts as $contact){
            // Get pre-calculated balance from batch query result
            $balance = $balances[$contact['pubkey']] ?? 0;

            $fee_percent = $contact['fee_percent'];
            $credit_limit = $contact['credit_limit'];

            // Add all addresses
            $addressesAssociative = [];
            $contactAddresses = [];
            foreach($addressTypes as $addressType){
                $addr = $contact[$addressType] ?? '';
                $addressesAssociative[$addressType] = $addr;
                if (!empty($addr)) {
                    $contactAddresses[] = $addr;
                }
            }

            // Get recent transactions with this contact
            $transactions = $this->transactionRepository->getTransactionsWithContact($contactAddresses, $transactionLimit);

            $contactsWithBalances[] = array_merge($addressesAssociative,[
                'name' => $contact['name'],
                'balance' =>  $balance ? $this->currencyUtility->convertCentsToDollars($balance) : $balance,
                'fee' =>  $fee_percent ? $this->currencyUtility->convertCentsToDollars($fee_percent) : $fee_percent,
                'credit_limit' =>  $credit_limit ? $this->currencyUtility->convertCentsToDollars($credit_limit) : $credit_limit,
                'currency' => $contact['currency'],
                'pubkey' => $contact['pubkey'] ?? '',
                'contact_id' => $contact['contact_id'] ?? '',
                'transactions' => $transactions,
                'online_status' => $contact['online_status'] ?? 'unknown',
                'valid_chain' => $contact['valid_chain'] ?? null
            ]);
        }
        return $contactsWithBalances;
    }

    // =========================================================================
    // REPOSITORY WRAPPERS
    // =========================================================================

    /**
     * Get transaction by txid
     *
     * @param string $txid Transaction ID
     * @return array|null Transaction data or null
     */
    public function getByTxid(string $txid): ?array {
        return $this->transactionRepository->getByTxid($txid);
    }

    /**
     * Get transaction by memo
     *
     * @param string $memo Transaction memo
     * @return array|null Transaction data or null
     */
    public function getByMemo(string $memo): ?array {
        return $this->transactionRepository->getByMemo($memo);
    }

    /**
     * Update transaction status
     *
     * @param string $identifier Transaction memo or txid
     * @param string $status New status
     * @param bool $isTxid True if identifier is txid
     * @return bool Success status
     */
    public function updateStatus(string $identifier, string $status, bool $isTxid = false): bool {
        return $this->transactionRepository->updateStatus($identifier, $status, $isTxid);
    }

    /**
     * Get all sent transactions
     * 
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactions(int $limit): array {
        return $this->transactionRepository->getSentUserTransactions($limit); 
    }

    /**
     * Get all sent transactions to specific address
     *
     * @param string $receiverAddress Address of transaction recipient
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactionsAddress(string $receiverAddress, int $limit): array {
        return $this->transactionRepository->getSentUserTransactionsAddress($receiverAddress, $limit); 
    }


    /**
     * Get all received transactions
     * 
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactions(int $limit): array {
        return $this->transactionRepository->getReceivedUserTransactions($limit); 
    }

    /**
     * Get all received transactions
     *
     * @param string $senderAddress Address of transaction sender
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactionsAddress(string $senderAddress, int $limit): array {
        return $this->transactionRepository->getReceivedUserTransactionsAddress($senderAddress, $limit); 
    }

    /**
     * Get users current balance
     *
     * @return string Balance 
     */
    public function getUserTotalBalance() {
          return $this->transactionRepository->getUserTotalBalance();
    }
    
    /**
     * Get contact balance (optimized single query)
     *
     * @param string $userPubkey
     * @param string $contactPubkey
     * @return int Balance in cents
     */
    public function getContactBalance(string $userPubkey, string $contactPubkey): int {
        return $this->transactionRepository->getContactBalance($userPubkey,$contactPubkey); 
    }

    /**
     * Get all contact balances 
     *
     * @param string $userPubkey
     * @param array $contactPubkeys
     * @return array Associative array of pubkey => balance
     */
    public function getAllContactBalances(string $userPubkey, array $contactPubkeys): array {
        return $this->transactionRepository->getAllContactBalances($userPubkey,$contactPubkeys); 
    }

    /**
     * Check for new transactions since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewTransactions($lastCheckTime){
         return $this->transactionRepository->checkForNewTransactions($lastCheckTime);
    }

    /**
     * Get transaction history with limit
     *
     * @param int $limit
     * @return array
     */
    public function getTransactionHistory(int $limit = 10): array
    {
        return $this->transactionRepository->getTransactionHistory($limit);
    }
    
    /**
     * Get transaction statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        return $this->transactionRepository->getStatistics();
    }

    /**
     * Get pending/in-progress transactions for GUI display
     * Returns transactions that are pending, sent, or in progress (not completed)
     *
     * @param int $limit Maximum number of transactions to return
     * @return array Array of pending transaction data
     */
    public function getInProgressTransactions(int $limit = 10): array {
        return $this->transactionRepository->getInProgressTransactions($limit);
    }
}
