<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

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
 */
class TransactionService {
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
                echo $this->utilPayload->buildInsufficientBalance($availableFunds, $requestedAmount, $creditLimit, 0, $request['currency']);
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

            // If we (receiver) have no record of the previous txid that the sender claims exists,
            // this means we may have lost data and should proactively sync with the sender.
            // This handles the case where Contact B lost all transactions and Contact A sends
            // a transaction with a prev_id that Contact B doesn't have.
            if ($expectedTxid === null && $receivedPreviousTxid !== null) {
                SecureLogger::info("Receiver has no transaction history with sender but sender has prev_id - triggering proactive sync", [
                    'sender' => $request['senderAddress'] ?? 'unknown',
                    'received_previous_txid' => $receivedPreviousTxid
                ]);

                // Proactively sync with the sender to recover missing transactions
                try {
                    $syncService = Application::getInstance()->services->getSyncService();
                    $syncResult = $syncService->syncTransactionChain(
                        $request['senderAddress'],
                        $request['senderPublicKey']
                    );

                    if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
                        SecureLogger::info("Proactive sync successful, retrying transaction validation", [
                            'synced_count' => $syncResult['synced_count']
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

                            // All checks passed after sync - accept and process
                            if ($echo) {
                                echo $this->transactionPayload->buildAcceptance($request);
                            }
                            $this->processTransaction($request);
                            return true;
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
                    $existingTx = $this->transactionRepository->getByTxid($request['txid']);
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
                        $syncService = Application::getInstance()->services->getSyncService();
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
                                return true;
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
            if($echo){
                echo $this->transactionPayload->buildAcceptance($request);
            }
            return true;
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
     * @return int Number of processed transactions
     */
    public function processPendingTransactions(): int {
        // Process pending transactions in database
        $pendingMessages = $this->transactionRepository->getPendingTransactions();

        // Process each pending message
        foreach ($pendingMessages as $message) {
            $memo = $message['memo'];
            $txid = $message['txid'];

            // If direct transaction
            if($memo === 'standard'){
                // If you're sending the direct transaction
                if($message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address'])){
                    $payload = $this->transactionPayload->buildStandardFromDatabase($message);

                    // Log the payload being sent (for debugging held transaction resumes)
                    SecureLogger::info("Sending standard transaction", [
                        'txid' => $txid,
                        'previous_txid_in_db' => $message['previous_txid'] ?? 'NULL',
                        'previous_txid_in_payload' => $payload['previousTxid'] ?? 'NULL',
                        'receiver' => $message['receiver_address']
                    ]);

                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_SENT, true);

                    // Send with delivery tracking
                    $sendResult = $this->sendTransactionMessage($message['receiver_address'], $payload, $txid);
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
                    } elseif($response && $response['status'] === Constants::STATUS_REJECTED){
                        // Check if rejection is due to invalid_previous_txid - attempt sync before falling back to P2P
                        if (isset($response['reason']) && $response['reason'] === 'invalid_previous_txid') {
                            output('Transaction rejected due to invalid_previous_txid, holding for sync...', 'SILENT');

                            // Use HeldTransactionService if available
                            if ($this->heldTransactionService !== null) {
                                $holdResult = $this->heldTransactionService->holdTransactionForSync(
                                    $message,
                                    $message['receiver_public_key'],
                                    $response['expected_txid'] ?? null
                                );

                                if ($holdResult['held']) {
                                    output('Transaction held pending sync completion', 'SILENT');
                                    continue; // Transaction will be resumed after sync completes
                                }
                            }

                            // Fallback to existing sync behavior if holding failed
                            output('Attempting immediate sync...', 'SILENT');
                            $syncService = Application::getInstance()->services->getSyncService();
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
                                output('Sync failed or no transactions to sync, falling back to P2P', 'SILENT');
                            }
                        }

                        $this->transactionRepository->updateStatus($txid, Constants::STATUS_REJECTED, true);
                        output(outputIssueTransactionTryP2p($response),'SILENT');
                        // Send P2P request for failed direct transaction using P2pService directly
                        Application::getInstance()->services->getP2pService()->sendP2pRequestFromFailedDirectTransaction($message);
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
            } else{
                // If p2p transaction
                $this->processP2pTransaction($message, $memo, $txid);
            }
        }

        return isset($pendingMessages) ? count($pendingMessages) : 0;
    }

    /**
     * Process P2P transaction
     *
     * @param array $message Transaction message
     * @param string $memo Transaction memo
     * @param string $txid Transaction ID
     */
    private function processP2pTransaction(array $message, string $memo, string $txid): void {
        // If you're sending the transaction
        if($message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address'])){
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
            $this->transactionRepository->updateStatus($memo, Constants::STATUS_SENT);
            output(outputSendTransactionOnwards($message),'SILENT');

            // Send with delivery tracking
            // Use relay- prefix for forwarded transactions, send- for original sends
            $sendResult = $this->sendTransactionMessage($message['receiver_address'], $payload, $txid, $isRelay);
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
            } elseif($response && $response['status'] === Constants::STATUS_REJECTED){
                // Check if rejection is due to invalid_previous_txid - attempt sync
                if (isset($response['reason']) && $response['reason'] === 'invalid_previous_txid') {
                    output('P2P transaction rejected due to invalid_previous_txid, holding for sync...', 'SILENT');

                    // Use HeldTransactionService if available
                    if ($this->heldTransactionService !== null) {
                        $holdResult = $this->heldTransactionService->holdTransactionForSync(
                            $message,
                            $message['receiver_public_key'],
                            $response['expected_txid'] ?? null
                        );

                        if ($holdResult['held']) {
                            output('P2P transaction held pending sync completion', 'SILENT');
                            return; // Transaction will be resumed after sync completes
                        }
                    }

                    // Fallback to existing sync behavior if holding failed
                    output('Attempting immediate sync...', 'SILENT');
                    $syncService = Application::getInstance()->services->getSyncService();
                    $syncResult = $syncService->syncTransactionChain(
                        $message['receiver_address'],
                        $message['receiver_public_key']
                    );

                    if ($syncResult['success'] && $syncResult['synced_count'] > 0) {
                        // Sync successful - also sync balances to ensure consistency
                        output('Sync successful, ' . $syncResult['synced_count'] . ' transactions synced. Syncing balances...', 'SILENT');

                        // Sync balances after transaction chain sync
                        $syncService->syncContactBalance($message['receiver_public_key']);

                        output('Balances synced. Will retry transaction...', 'SILENT');
                        // Revert status to pending for retry
                        $this->transactionRepository->updateStatus($memo, Constants::STATUS_PENDING);
                        $this->p2pRepository->updateStatus($memo, 'found');
                        return; // Exit to allow retry on next processing cycle
                    } else {
                        output('Sync failed or no transactions to sync', 'SILENT');
                    }
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
        }
    }

    /**
     * Send eIOU
     *
     * @param array|null $request Request data
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function sendEiou(?array $request = null, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Handler for sending eIOU through user Input
        if ($request === null) {
            global $data;
            $request = $data;
        }

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
        $contactService = Application::getInstance()->services->getContactService();
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
                    $syncResult = Application::getInstance()->services->getSyncService()->syncSingleContact($contactInfo[$transportIndex],'SILENT');
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
     * @param array $request Request data
     * @param array $contactInfo Contact information
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function handleDirectRoute(array $request, $contactInfo, ?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        try {
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
            Application::getInstance()->services->getP2pService()->sendP2pRequest($request);

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
                'transactions' => $transactions
            ]);
        }
        return $contactsWithBalances;
    }

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
     * Calculate total sent by user
     *
     * @param string $publicKey User's public key
     * @return float Total amount sent
     */
    public function calculateTotalSentToContact(string $publicKey): float {
        return $this->transactionRepository->calculateTotalSentToContact($publicKey);
    }

    /**
     * Calculate total received by user
     *
     * @param string $publicKey User's public key
     * @return float Total amount received
     */
    public function calculateTotalReceivedFromContact(string $publicKey): float {
        return $this->transactionRepository->calculateTotalReceivedFromContact($publicKey);
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
