<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

require_once __DIR__ . '/../utils/InputValidator.php';
require_once __DIR__ . '/../utils/SecureLogger.php';
require_once __DIR__ . '/MessageDeliveryService.php';

/**
 * P2P Service
 *
 * Handles all business logic for peer-to-peer payment routing.
 * Integrates with MessageDeliveryService for reliable message delivery
 * with tracking, retry logic, and dead letter queue support.
 *
 * @package Services
 */
class P2pService {
    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var BalanceRepository Balance repository instance
     */
    private BalanceRepository $balanceRepository;

    /**
     * @var P2pRepository P2P repository instance
     */
    private P2pRepository $p2pRepository;

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

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
     * @var CurrencyUtilityService Currency utility service
     */
    private CurrencyUtilityService $currencyUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var P2pPayload payload builder for p2p
     */
    private P2pPayload $p2pPayload;

    /**
     * @var Rp2pPayload payload builder for Rp2p
     */
    private Rp2pPayload $rp2pPayload;

    /**
     * @var UtilPayload payload builder for utility
     */
    private UtilPayload $utilPayload;

    /**
     * @var MessageDeliveryService|null Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

    /**
     * @var SecureLogger Logger instance
     */
    private SecureLogger $secureLogger;

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     * @param MessageDeliveryService|null $messageDeliveryService Optional delivery service for tracking
     */
    public function __construct(
        ContactRepository $contactRepository,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null
    ) {
        $this->contactRepository = $contactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->validationUtility = $this->utilityContainer->getValidationUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->currencyUtility = $this->utilityContainer->getCurrencyUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;
        $this->secureLogger = new SecureLogger();

        require_once '/etc/eiou/src/schemas/payloads/P2pPayload.php';
        $this->p2pPayload = new P2pPayload($this->currentUser, $this->utilityContainer);

        require_once '/etc/eiou/src/schemas/payloads/Rp2pPayload.php';
        $this->rp2pPayload = new Rp2pPayload($this->currentUser, $this->utilityContainer);

        require_once '/etc/eiou/src/schemas/payloads/UtilPayload.php';
        $this->utilPayload = new UtilPayload($this->currentUser, $this->utilityContainer);
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
     * Send a P2P message with optional delivery tracking (non-blocking)
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Uses async (non-blocking)
     * delivery to prevent P2P broadcast loops from getting stuck waiting on
     * retries. Failed sends are queued for background retry.
     *
     * @param string $messageType Type of P2P message ('p2p' or 'rp2p')
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string|null $messageId Optional unique message ID for tracking (uses hash if available)
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    private function sendP2pMessage(string $messageType, string $address, array $payload, ?string $messageId = null): array {
        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // Use async=true for non-blocking delivery (allows P2P broadcast loops to continue)
            return $this->messageDeliveryService->sendMessage(
                $messageType,
                $address,
                $payload,
                $messageId,
                true // async
            );
        }

        // Fall back to direct transport when MessageDeliveryService not available
        if ($messageId === null) {
            $messageId = $payload['hash'] ?? hash('sha256', json_encode($payload) . $this->timeUtility->getCurrentMicrotime());
        }

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
     * Check if P2P request level is valid
     *
     * @param array $request The P2P request data
     * @return bool True if request level is valid, false otherwise
     */
    public function checkRequestLevel(array $request): bool {
        // Validate input
        if (!isset($request['requestLevel']) || !isset($request['maxRequestLevel'])) {
            SecureLogger::warning("Missing requestLevel or maxRequestLevel in request", [
                'method' => 'checkRequestLevel',
                'request_keys' => array_keys($request)
            ]);
            echo $this->utilPayload->buildInvalidRequestLevel($request);
            return false;
        }

        // Check validity of p2p request
        if (!$this->validationUtility->validateRequestLevel($request)) {
            echo $this->utilPayload->buildInvalidRequestLevel($request);
            return false;
        }
        return true;
    }

    /**
     * Check if sender has sufficient available funds for P2P request
     *
     * @param array $request The P2P request data
     * @return bool True if funds are available, false otherwise
     */
    public function checkAvailableFunds(array $request): bool {
        try {
            // Validate required fields
            if (!isset($request['senderAddress'], $request['senderPublicKey'])) {
                SecureLogger::warning("Missing required fields in P2P request for funds check", [
                    'method' => 'checkAvailableFunds',
                    'request_keys' => array_keys($request)
                ]);
                return false;
            }

            // Check if p2p's destination is not to user (i.e. you are an intermediary and not the end-recipient)
            if (!$this->matchYourselfP2P($request, $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']))) {
                // Check if sender has enough 'credit' to facilitate eIOU
                $requestedAmount = $this->calculateRequestedAmount($request);
                $availableFunds = $this->validationUtility->calculateAvailableFunds($request);

                $fundsOnHold = $this->p2pRepository->getCreditInP2p($request['senderPublicKey']);
                $creditLimit = $this->contactRepository->getCreditLimit($request['senderPublicKey']);

                if (($availableFunds + $creditLimit) < ($requestedAmount + $fundsOnHold)) {
                    echo $this->utilPayload->buildInsufficientBalance($availableFunds, $requestedAmount, $creditLimit, $fundsOnHold);
                    return false;
                }
            }
            // If you are the end-recipient you do not need to pay
            return true;
        } catch (PDOException $e) {
            // Use SecureLogger's exception logging
            SecureLogger::logException($e, [
                'method' => 'checkAvailableFunds',
                'context' => 'p2p_funds_validation'
            ]);
            throw $e;
        }
    }

    /**
     * Caculate total amount required for p2p (amount + fee)
     *
     * @param array $request The P2P request data
     * @return int Total amount needed for p2p transaction
     */
    public function calculateRequestedAmount($request): int {
         // Calculate total amount needed for p2p through user
        $address = $request['senderAddress'];
        $transportIndex = $this->transportUtility->determineTransportType($address);
        $senderContact = $this->contactRepository->lookupByAddress($transportIndex, $address);
        $fee = ($senderContact ? $senderContact['fee_percent'] : $this->currentUser->getDefaultFee()); 
        return $request['amount'] + $this->currencyUtility->calculateFee($request['amount'], $fee, $this->currentUser->getMinimumFee());
    }

    /**
     * Check P2P is possible
     *
     * @param array $request Request data
     * @return bool True if P2P possible, False otherwise.
     */
    public function checkP2pPossible(array $request, $echo = true) : bool{
        $senderAddress = $request['senderAddress'];
        $pubkey = $request['senderPublicKey'];
        // Check if User is not blocked
        if(!$this->contactRepository->isNotBlocked($pubkey)){
            if($echo){
                echo $this->p2pPayload->buildRejection($request, 'contact_blocked');
            }
            return false;
        }
        // Check if P2P message has not reached max intermediary hop amount
        elseif(!$this->checkRequestLevel($request)){
            return false;
        }
        // Check if Contact has enough funds for P2P without fees
        elseif(!$this->checkAvailableFunds($request)){
            if($echo){
                echo $this->p2pPayload->buildRejection($request, 'insufficient_funds');
            }
            return false;
        }

        // Check if P2P already exists for hash in database
        try{
            if($this->p2pRepository->p2pExists($request['hash'])){
                //If P2P already exists
                if($echo){
                    echo $this->p2pPayload->buildRejection($request, 'duplicate');
                }
                return false;
            }
            if($echo){
                // Return 'inserted' status since the P2P will be stored in the database
                echo $this->p2pPayload->buildInserted($request);
            }
            return true;
        } catch (PDOException $e) {
            // Handle database error
            SecureLogger::error("Error retrieving existence of P2P by hash", ['error' => $e->getMessage()]);
            if($echo){
                echo json_encode([
                    "status" => "rejected",
                    "message" => "Could not retrieve existence of P2P with receiver"
                ]);
            }
            return false;
        }
    }

    /**
     * Handle incoming P2P request
     *
     * Uses MessageDeliveryService for reliable rp2p response delivery when
     * the P2P destination matches the user.
     *
     * @param array $request The P2P request data
     * @return void
     */
    public function handleP2pRequest(array $request): void {
        try {
            // Validate required fields
            if (!isset($request['senderAddress'], $request['hash'], $request['amount'])) {
                SecureLogger::warning("Missing required fields in P2P request");
                throw new InvalidArgumentException("Invalid P2P request structure");
            }

            // Handler for p2p requests
            $myAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

            // Check if p2p's destination is to user
            if ($this->matchYourselfP2P($request, $myAddress)) {
                $request['status'] = 'found';
                $this->p2pRepository->insertP2pRequest($request, $myAddress);

                // Build and send corresponding rp2p request payload to sender of p2p with delivery tracking
                // Message ID format: response-{hash} (message_type 'rp2p' provides context)
                $rP2pPayload = $this->rp2pPayload->build($request);
                $messageId = 'response-' . $request['hash'];
                $sendResult = $this->sendP2pMessage('rp2p', $request['senderAddress'], $rP2pPayload, $messageId);
                $response = $sendResult['response'];

                // Update delivery stage after local insert (using MessageDeliveryService directly)
                if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                    $this->messageDeliveryService->updateStageAfterLocalInsert('rp2p', $messageId, false);
                }

                output(outputRp2pTransactionResponse($response), 'SILENT');
            } else {
                // Calculate fees
                $requestedAmount = $this->calculateRequestedAmount($request);
                $request['feeAmount'] = $requestedAmount - $request['amount'];
                $request['maxRequestLevel'] = $this->reAdjustP2pLevel($request); // Change (remaining) RequestLevel if need be based on user config

                $this->p2pRepository->insertP2pRequest($request, NULL);
                $this->p2pRepository->updateStatus($request['hash'], Constants::STATUS_QUEUED);
            }
        } catch (PDOException $e) {
            SecureLogger::logException($e, 'ERROR');
            throw $e;
        } catch (Exception $e) {
            SecureLogger::error("Error in handleP2pRequest", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if the P2P's end-recipient is a contact of user
     *
     * @param array $request Request data
     * @return array|null Contact data of corresponding user, null otherwise.
     */
    public function matchContact($request): ?array {
        // Check if contact matches transactions end-recipient
        $contacts = $this->contactRepository->getAllContacts();
        // Check if end recipient of request in contacts
        $senderAddress = $request['sender_address'];
        $transportIndex = $this->transportUtility->determineTransportType($senderAddress);

        // Get all address types dynamically from database schema
        $addressTypes = $this->transportUtility->getAllAddressTypes();
        // Move primary transport to front for performance (most likely match)
        if ($transportIndex && in_array($transportIndex, $addressTypes)) {
            $addressTypes = array_merge([$transportIndex], array_diff($addressTypes, [$transportIndex]));
        }

        foreach ($contacts as $contact) {
            // Check all address types for this contact
            foreach ($addressTypes as $addrType) {
                if (!empty($contact[$addrType])) {
                    $contactHash = hash(Constants::HASH_ALGORITHM, $contact[$addrType] . $request['salt'] . $request['time']);
                    if ($contactHash === $request['hash']) {
                        output(outputContactMatched($contactHash), 'SILENT');
                        return $contact;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Check if the P2P's end-recipient is user
     *
     * @param array $request Request data
     * @param string $address Address 
     * @return bool True if user corresponds, False otherwise.
     */
    public function matchYourselfP2P($request, $address){
        // Check if p2p end recipient is user
        // First check the provided address (most likely match)
        if (hash(Constants::HASH_ALGORITHM, $address . $request['salt'] . $request['time']) === $request['hash']) {
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
            if (hash(Constants::HASH_ALGORITHM, $userAddress . $request['salt'] . $request['time']) === $request['hash']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare P2P request data from user input
     *
     * @param array $request The request array from user input
     * @return array Prepared P2P request data
     */
    public function prepareP2pRequestData(array $request): array {
        // Build initial p2p request payload
        output(outputPrepareP2pData($request), 'SILENT');

        // Check if the address of the recipient was supplied
        if (!isset($request[2])) {
            output(outputReceiverAddressNotSet($request), 'SILENT');
            die;
        }

        // Validate amount using InputValidator
        if (!isset($request[3])) {
            throw new InvalidArgumentException("Amount is required for P2P request");
        }

        $validation = InputValidator::validateAmount($request[3], Constants::TRANSACTION_DEFAULT_CURRENCY);
        if (!$validation['valid']) {
            throw new InvalidArgumentException("Invalid amount for P2P request: " . $validation['error']);
        }
        $validatedAmount = $validation['value'];

        // Initial data preparation
        $data['txType'] = 'p2p';
        $data['receiverAddress'] = $request[2];

        $data['time'] = $this->timeUtility->getCurrentMicrotime();
        $data['amount'] = round($validatedAmount * Constants::TRANSACTION_USD_CONVERSION_FACTOR); // Convert to cents
        $data['currency'] = Constants::TRANSACTION_DEFAULT_CURRENCY; // Default to USD

        // Additional data preparation - Use cryptographically secure random
        try {
            $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
        } catch (Exception $e) {
            SecureLogger::error("Failed to generate random salt", ['error' => $e->getMessage()]);
            throw new RuntimeException("Failed to generate secure random data");
        }

        $data['hash'] = hash(Constants::HASH_ALGORITHM, $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
        output(outputGeneratedP2pHash($data['hash']), 'SILENT');
        output(outputP2pComponents($data), 'SILENT');

        $data['minRequestLevel'] = abs(
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANGE_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANGE_HIGH) -
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH)
        ) + rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH); // Calculate random lower bound for request level
        $data['maxRequestLevel'] = $data['minRequestLevel'] + $this->transportUtility->jitter($this->currentUser->getMaxP2pLevel()); // Add upper bound to request level, using users max

        return $data;
    }

    /**
     * Prepare P2P request from failed transaction data
     *
     * @param array $message Transaction message
     * @return array Prepared P2P request data
     */
    public function prepareP2pRequestFromFailedTransactionData(array $message): array {
        // Build initial p2p payload from failed direct Transaction
        $data['txType'] = 'p2p';
        $data['receiverAddress'] = $message['receiver_address'];

        $data['time'] = $this->timeUtility->getCurrentMicrotime();
        $data['amount'] = $message['amount'];
        $data['currency'] = $message['currency'];

        // Additional data preparation - Use cryptographically secure random
        try {
            $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
        } catch (Exception $e) {
            SecureLogger::error("Failed to generate random salt", ['error' => $e->getMessage()]);
            throw new RuntimeException("Failed to generate secure random data");
        }

        $data['hash'] = hash(Constants::HASH_ALGORITHM, $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
        output(outputGeneratedP2pHash($data['hash']), 'SILENT');
        output(outputP2pComponents($data), 'SILENT');

        $data['minRequestLevel'] = abs(
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANGE_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANGE_HIGH) -
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH)
        ) + rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH); // Calculate random lower bound for request level
        $data['maxRequestLevel'] = $data['minRequestLevel'] + $this->transportUtility->jitter($this->currentUser->getMaxP2pLevel()); // Add upper bound to request level, using users max

        return $data;
    }

    /**
     * Process queued P2P messages
     *
     * Uses MessageDeliveryService for reliable P2P message delivery with
     * tracking, retry logic, and dead letter queue support.
     *
     * @return int Number of processed messages
     */
    public function processQueuedP2pMessages(): int {
        // Select queued messages from the p2p table (with status queued)
        $queuedMessages = $this->p2pRepository->getQueuedP2pMessages();
        if($queuedMessages !== []){
            $contacts = $this->contactRepository->getAllAcceptedAddresses(); // Retrieve all accepted contact addresses to send p2p request
            $contactsCount = count($contacts); // Count amount of contacts to send p2p request
        }
        // Process each queued message
        foreach ($queuedMessages as $message) {
            // Get transport type for forwarding/sending
            $transportIndex = $this->transportUtility->determineTransportType($message['sender_address']);
            $p2pPayload = $this->p2pPayload->buildFromDatabase($message); // Build p2p request payload
            $p2pHash = $message['hash'];

            // Check if user is NOT the original sender of the p2p and has a direct contact link to end-recipient
            // If this is the case then send p2p directly
            // Message ID format: direct-{hash}-{contactHash} (message_type 'p2p' provides context)
            if(!isset($message['destination_address']) && $matchedContact = $this->matchContact($message)){
                // Send directly to matched contact with delivery tracking
                $contactHash = substr(hash('sha256', $matchedContact[$transportIndex]), 0, 8);
                $messageId = 'direct-' . $p2pHash . '-' . $contactHash;
                $sendResult = $this->sendP2pMessage('p2p', $matchedContact[$transportIndex], $p2pPayload, $messageId);
                $response = $sendResult['response'];

                if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                    // Mark as forwarded stage since we're routing to the destination
                    $this->messageDeliveryService->updateStageToForwarded('p2p', $messageId, $matchedContact[$transportIndex]);
                }

                output(outputP2pSendResult($response),'SILENT');
            } else{
                $contactsToSend = $contactsCount; // Reset sendable contact count
                $sentMessages = 0;
                $successfulSends = [];

                // Send p2p request to all accepted contacts
                foreach ($contacts as $contact) {
                    $contactAddress = $contact[$transportIndex]; // Get similar contact address to message
                    // Do not send message if contact has not similar transport mode (HTTP goes over HTTP, TOR over TOR etc.)
                    if(!$contactAddress){
                        $contactsToSend -= 1;
                        continue;
                    }
                    // Do not send message to original sender
                    if($message['sender_address'] === $contactAddress){
                        $contactsToSend -= 1;
                        continue;
                    }
                    // Do not send p2p to contact (end-recipient), if direct transaction failed due to insufficient funds
                    if(isset($message['destination_address']) && $message['destination_address'] === $contactAddress){
                        $contactsToSend -= 1;
                        continue;
                    }

                    // Send with delivery tracking - use unique ID per contact to track each send
                    // Message ID format: broadcast-{p2pHash}-{contactHash} (message_type 'p2p' provides context)
                    $contactHash = substr(hash('sha256', $contactAddress), 0, 8);
                    $messageId = 'broadcast-' . $p2pHash . '-' . $contactHash;
                    $sendResult = $this->sendP2pMessage('p2p', $contactAddress, $p2pPayload, $messageId);
                    $response = $sendResult['response'];

                    // If rejection from sole possible contact then cancel p2p immediately
                    if($response['status'] === Constants::STATUS_REJECTED && $contactsToSend === 1){
                        $this->p2pRepository->updateStatus($p2pHash, Constants::STATUS_CANCELLED);
                        $contactsToSend -= 1;
                        continue;
                    }

                    $sentMessages += 1;
                    if ($sendResult['success']) {
                        $successfulSends[] = ['messageId' => $messageId, 'nextHop' => $contactAddress];
                    }
                    output(outputP2pResponse($response),'SILENT');
                }

                // Update delivery stages to 'forwarded' for successful sends (using MessageDeliveryService directly)
                if ($this->messageDeliveryService !== null) {
                    foreach ($successfulSends as $sendInfo) {
                        $this->messageDeliveryService->updateStageToForwarded('p2p', $sendInfo['messageId'], $sendInfo['nextHop']);
                    }
                }

                if(isset($message['destination_address']) && $contactsToSend > 0){
                    output(outputSendP2PToAmountContacts($sentMessages), 'SILENT');
                    //Inform user (in debug) about expected response time
                    $httpExpectedResponseTime = $this->currentUser->getMaxP2pLevel(); // Use maxP2pLevel seconds for http
                    $torExpectedResponseTime = 5 * 2 * $this->currentUser->getMaxP2pLevel(); //5 seconds for a tor request, 2 times for a round trip, multiplied by maxP2pLevel
                    output(outputResponseTransactionTimes($httpExpectedResponseTime, $torExpectedResponseTime), 'SILENT');
                }

                // Cancel the message due to no viable contacts to send to (user is dead-end)
                if($sentMessages === 0){
                    output(outputNoViableRouteP2p($p2pHash,'SILENT'));
                    $this->p2pRepository->updateStatus($p2pHash, Constants::STATUS_CANCELLED);
                    continue;
                }
            }

            $this->p2pRepository->updateStatus($p2pHash, Constants::STATUS_SENT);
        }
        return isset($queuedMessages) ? count($queuedMessages) : 0;
    }


    /**
     * Adjust remaining p2p chain length based on intermediary contact's config of maxP2pLevel 
     *
     * @param array $data Request data
     * @return int (adjusted) Level of Request
     */
    public function reAdjustP2pLevel($request): int {
        $maxP2p = $this->currentUser->getMaxP2pLevel();
        if($request['maxRequestLevel'] > $request['requestLevel'] + $maxP2p){
            return $request['requestLevel'] + $maxP2p;
        } else{
            return $request['maxRequestLevel'];
        }
    }

    /**
     * Send P2P request
     *
     * @param array $data Request data
     * @return void
     */
    public function sendP2pRequest(array $data): void {
        // Check if a valid address format was supplied, if not look up the address in the case of a contact re-routing
        if ($this->transportUtility->isAddress($data[2])) {
            $address = $data[2];
        } else{
            // Check if contact exists by Name supplied, if not then cannot send the p2p request
            $contactAddresses = $this->contactRepository->lookupAddressesByName($data[2]);
            if($contactAddresses){
                $address = $this->transportUtility->fallbackTransportAddress($contactAddresses);
                if($address){
                    $data[2] = $address;
                }     
            } else{
                output(outputAdressOrContactIssue($data),'SILENT');
                die;
            }
        }

        $p2pPayload = $this->p2pPayload->build($this->prepareP2pRequestData($data));
        output(outputInsertingP2pRequest($address), 'SILENT');
        // Privacy: Store description locally but don't include in P2P payload sent to relays
        $description = isset($data[5]) && !empty($data[5]) ? $data[5] : null;
        $this->p2pRepository->insertP2pRequest($p2pPayload, $address, $description);
        $this->p2pRepository->updateStatus($p2pPayload['hash'], Constants::STATUS_QUEUED);
    }

    /**
     * Send P2P request from failed direct transaction
     *
     * @param array $message Transaction message
     * @return void
     */
    public function sendP2pRequestFromFailedDirectTransaction(array $message): void {
        // Create p2p version of failed direct transaction
        $p2pPayload = $this->p2pPayload->build($this->prepareP2pRequestFromFailedTransactionData($message));
        output(outputInsertingP2pRequest($message['receiver_address']), 'SILENT');
        $this->p2pRepository->insertP2pRequest($p2pPayload, $message['receiver_address']);
        $this->p2pRepository->updateStatus($p2pPayload['hash'], Constants::STATUS_QUEUED);
    }

    /**
     * Get P2P by hash
     *
     * @param string $hash P2P hash
     * @return array|null P2P data or null
     */
    public function getByHash(string $hash): ?array {
        return $this->p2pRepository->getByHash($hash);
    }

    /**
     * Update P2P status
     *
     * @param string $hash P2P hash
     * @param string $status New status
     * @param bool $completed Whether to set completed timestamp
     * @return bool Success status
     */
    public function updateStatus(string $hash, string $status, bool $completed = false): bool {
        return $this->p2pRepository->updateStatus($hash, $status, $completed);
    }

    /**
     * Update incoming transaction ID
     *
     * @param string $hash P2P hash
     * @param string $txid Transaction ID
     * @return bool Success status
     */
    public function updateIncomingTxid(string $hash, string $txid): bool {
        return $this->p2pRepository->updateIncomingTxid($hash, $txid);
    }

    /**
     * Update outgoing transaction ID
     *
     * @param string $hash P2P hash
     * @param string $txid Transaction ID
     * @return bool Success status
     */
    public function updateOutgoingTxid(string $hash, string $txid): bool {
        return $this->p2pRepository->updateOutgoingTxid($hash, $txid);
    }

    /**
     * Get credit currently on hold in P2P
     *
     * @param string $pubkey Sender pubkey
     * @return float Total amount on hold
     */
    public function getCreditInP2p(string $pubkey): float {
        return $this->p2pRepository->getCreditInP2p($pubkey);
    }

    /**
     * Get users total earnings
     *
     * @return string Earnings Balance 
     */
    public function getUserTotalEarnings() {
        return $this->p2pRepository->getUserTotalEarnings();
    }
    

    /**
     * Get P2P statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        return $this->p2pRepository->getStatistics();
    }
}
