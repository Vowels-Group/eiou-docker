<?php
# Copyright 2025

/**
 * P2P Service
 *
 * Handles all business logic for peer-to-peer payment routing.
 *
 * @package Services
 */
class P2pService {
    /**
     * @var P2pRepository P2P repository instance
     */
    private P2pRepository $p2pRepository;

    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var array Current user data
     */
    private array $currentUser;

    /**
     * Constructor
     *
     * @param P2pRepository $p2pRepository P2P repository
     * @param ContactRepository $contactRepository Contact repository
     * @param array $currentUser Current user data
     */
    public function __construct(
        P2pRepository $p2pRepository,
        ContactRepository $contactRepository,
        array $currentUser = []
    ) {
        $this->p2pRepository = $p2pRepository;
        $this->contactRepository = $contactRepository;
        $this->currentUser = $currentUser;
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
            error_log("Missing requestLevel or maxRequestLevel in request");
            echo buildInvalidRequestLevelPayload($request);
            return false;
        }

        // Check validity of p2p request
        if (!validateRequestLevel($request)) {
            echo buildInvalidRequestLevelPayload($request);
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
                error_log("Missing required fields in P2P request for funds check");
                return false;
            }

            // Check if p2p's destination is not to user (i.e. you are an intermediary and not the end-recipient)
            if (!matchYourselfP2P($request, resolveUserAddressForTransport($request['senderAddress']))) {
                // Check if sender has enough 'credit' to facilitate eIOU
                $requestedAmount = calculateRequestedAmount($request);
                $availableFunds = calculateAvailableFunds($request);
                $fundsOnHold = $this->p2pRepository->getCreditInP2p($request['senderAddress']);
                $creditLimit = $this->contactRepository->getCreditLimit($request['senderPublicKey']);

                if ($availableFunds < ($requestedAmount + $fundsOnHold)) {
                    echo buildInsufficientBalancePayload($availableFunds, $requestedAmount, $creditLimit, $fundsOnHold);
                    return false;
                }
            }
            // If you are the end-recipient you do not need to pay
            return true;
        } catch (PDOException $e) {
            error_log("Database error in checkAvailableFunds: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check P2P is possible
     *
     * @param array $request Request data
     * @return bool True if P2P possible, False otherwise.
     */
    public function checkP2pPossible(array $request, $echo = true) : bool{
        // Check if P2P already exists for hash in database, is valid and can be completed
        // & Check if P2P is valid and can be completed given credit of user requesting
        if(!$this->contactRepository->isNotBlocked($request['senderAddress']) || !checkRequestLevel($request) || !checkAvailableFunds($request)){
            return false; 
        }
        // Check if P2P already exists for hash in database
        try{
            if($this->p2pRepository->getByHash($request['hash'])){
                //If P2P already exists
                if($echo){
                    echo buildP2pRejectionPayload($request);
                }
                return false;
            } 
            if($echo){
                echo buildP2pAcceptancePayload($request);
            }
            return true;  
        } catch (PDOException $e) {
            // Handle database error
            error_log("Error retrieving existence of P2P by hash" , $e->getMessage());
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
     * @param array $request The P2P request data
     * @return void
     */
    public function handleP2pRequest(array $request): void {
        try {
            // Validate required fields
            if (!isset($request['senderAddress'], $request['hash'], $request['amount'])) {
                error_log("Missing required fields in P2P request: " . json_encode(Security::maskSensitiveData($request)));
                throw new InvalidArgumentException("Invalid P2P request structure");
            }

            // Handler for p2p requests
            $myAddress = resolveUserAddressForTransport($request['senderAddress']);

            // Check if p2p's destination is to user
            if (matchYourselfP2P($request, $myAddress)) {
                $request['status'] = 'found';
                $this->p2pRepository->insertP2pRequest($request, $myAddress);

                // Build and send corresponding rp2p request payload to sender of p2p
                $rP2pPayload = buildRp2pPayload($request);
                $response = json_decode(send($request['senderAddress'], $rP2pPayload), true);
                output(outputRp2pTransactionResponse($response), 'SILENT');
            } else {
                // Calculate fees
                $requestedAmount = calculateRequestedAmount($request);
                $request['feeAmount'] = $requestedAmount - $request['amount'];
                $request['maxRequestLevel'] = reAdjustP2pLevel($request); // Change (remaining) RequestLevel if need be based on user config

                $this->p2pRepository->insertP2pRequest($request, NULL);
                $this->p2pRepository->updateStatus($request['hash'], 'queued');
            }
        } catch (PDOException $e) {
            error_log("Database error in handleP2pRequest: " . $e->getMessage());
            throw $e;
        } catch (Exception $e) {
            error_log("Error in handleP2pRequest: " . $e->getMessage());
            throw $e;
        }
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

        // Validate amount
        if (!isset($request[3]) || !is_numeric($request[3]) || $request[3] <= 0) {
            throw new InvalidArgumentException("Invalid amount for P2P request");
        }

        // Initial data preparation
        $data['txType'] = 'p2p';
        $data['receiverAddress'] = $request[2];

        $data['time'] = returnMicroTime();
        $data['amount'] = round($request[3] * 100); // Convert to cents 100 (based on USD currency)
        $data['currency'] = 'USD'; // Default to USD

        // Additional data preparation - Use cryptographically secure random
        try {
            $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
        } catch (Exception $e) {
            error_log("Failed to generate random salt: " . $e->getMessage());
            throw new RuntimeException("Failed to generate secure random data");
        }

        $data['hash'] = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
        output(outputGeneratedP2pHash($data['hash']), 'SILENT');
        output(outputP2pComponents($data), 'SILENT');

        $data['minRequestLevel'] = abs(rand(300, 700) - rand(200, 500)) + rand(1, 10); // Calculate 'random' lower bound for request level
        $data['maxRequestLevel'] = $data['minRequestLevel'] + jitter($this->currentUser['maxP2pLevel']); // Add upper bound to request level, using users max

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

        $data['time'] = returnMicroTime();
        $data['amount'] = $message['amount'];
        $data['currency'] = $message['currency'];

        // Additional data preparation
        $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
        $data['hash'] = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
        output(outputGeneratedP2pHash($data['hash']), 'SILENT');
        output(outputP2pComponents($data), 'SILENT');

        $data['minRequestLevel'] = abs(rand(300, 700) - rand(200, 500)) + rand(1, 10); // Calculate 'random' lower bound for request level
        $data['maxRequestLevel'] = $data['minRequestLevel'] + jitter($this->currentUser['maxP2pLevel']); // Add upper bound to request level, using users max

        return $data;
    }

    /**
     * Process queued P2P messages
     *
     * @return int Number of processed messages
     */
    public function processQueuedP2pMessages(): int {
        // Select queued messages from the p2p table (with status queued)
        $queuedMessages = $this->p2pRepository->getQueuedP2pMessages();

        // Process each queued message
        foreach ($queuedMessages as $message) {
            $p2pPayload = buildP2pPayloadDatabase($message); // Build p2p request payload

            // Check if user is NOT the original sender of the p2p and has a direct contact link to end-recipient
            // If this is the case then send p2p directly
            if(!isset($message['destination_address']) && $matchedContact = matchContact($message)){
                $response = json_decode(send($matchedContact['address'], $p2pPayload),true);
                output(outputP2pSendResult($response),'SILENT');
            } else{
                // Retrieve contacts to send p2p request, excluding the sender
                $contacts = $this->contactRepository->getAllAddresses($message['sender_address']);
                // Count amount of contacts to send p2p request
                $contactsCount = countTorAndHttpAddresses($contacts);
                // Send p2p request to all contacts
                foreach ($contacts as $contact) {
                    if(!synchContact($contact)){
                        // If contact cannot be synched in case of pending contact status, skip sending p2p to this contact
                        continue;
                    }
                    // Do not send p2p to contact (end-recipient), if direct transaction failed due to insufficient funds
                    if(isset($message['destination_address']) && $contact === $message['destination_address']){
                        if(isTorAddress($message['destination_address'])){
                            $contactsCount['tor'] -= 1;
                        } else{
                            $contactsCount['http'] -= 1;
                        }
                        continue;
                    }
                    $response = json_decode(send($contact, $p2pPayload),true);
                    output(outputP2pResponse($response),'SILENT');
                }

                if(isset($message['destination_address'])){
                    output(outputSendP2PToAmountContacts($contactsCount), 'SILENT');
                    //Inform user (in debug) about expected response time
                    $httpExpectedResponseTime = $this->currentUser['maxP2pLevel']; // Use maxP2pLevel seconds for http
                    $torExpectedResponseTime = 5 * 2 * $this->currentUser['maxP2pLevel']; //5 seconds for a tor request, 2 times for a round trip, multiplied by maxP2pLevel
                    output(outputResponseTransactionTimes($httpExpectedResponseTime,$torExpectedResponseTime), 'SILENT');
                }
            }

            $this->p2pRepository->updateStatus($message['hash'], 'sent');
        }

        return isset($queuedMessages) ? count($queuedMessages) : 0;
    }

    /**
     * Send P2P request
     *
     * @param array $data Request data
     * @return void
     */
    public function sendP2pRequest(array $data): void {
        // Check if a valid address format was supplied, if not look up the address in the case of a contact re-routing
        if (isHttpAddress($data[2]) || isTorAddress($data[2])) {
            $address = $data[2];
        } else{
            // Check if contact exists by Name supplied, if not then cannot send the p2p request
            $contactAddress = $this->contactRepository->lookupAddressByName($data[2]);
            if($contactAddress){
                $address = $contactAddress;
                $data[2] = $address;
            } else{
                output(outputAdressOrContactIssue($data),'SILENT');
                die;
            }
        }

        $p2pPayload = buildP2pPayload($this->prepareP2pRequestData($data));
        output(outputInsertingP2pRequest($address), 'SILENT');
        $this->p2pRepository->insertP2pRequest($p2pPayload, $address);
        $this->p2pRepository->updateStatus($p2pPayload['hash'], 'queued');
    }

    /**
     * Send P2P request from failed direct transaction
     *
     * @param array $message Transaction message
     * @return void
     */
    public function sendP2pRequestFromFailedDirectTransaction(array $message): void {
        // Create p2p version of failed direct transaction
        $p2pPayload = buildP2pPayload($this->prepareP2pRequestFromFailedTransactionData($message));
        output(outputInsertingP2pRequest($message['receiver_address']), 'SILENT');
        $this->p2pRepository->insertP2pRequest($p2pPayload, $message['receiver_address']);
        $this->p2pRepository->updateStatus($p2pPayload['hash'], 'queued');
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
     * @param string $address Sender address
     * @return float Total amount on hold
     */
    public function getCreditInP2p(string $address): float {
        return $this->p2pRepository->getCreditInP2p($address);
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
