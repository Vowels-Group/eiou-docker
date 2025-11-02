<?php
# Copyright 2025

/**
 * Transaction Service
 *
 * Handles all business logic for transaction management.
 *
 * @package Services
 */
class TransactionService {
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
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

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
     * Constructor
     *
     * @param P2pRepository $p2pRepository P2p repository
     * @param Rp2pRepository $rp2pRepository Rp2p repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param ContactRepository $contactRepository Contact repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        ContactRepository $contactRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->contactRepository = $contactRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currencyUtility = $this->utilityContainer->getCurrencyUtility();
        $this->validationUtility = $this->utilityContainer->getValidationUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;
       
        require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
        $this->transactionPayload = new TransactionPayload($this->currentUser,$this->utilityContainer);
      
        require_once '/etc/eiou/src/schemas/payloads/UtilPayload.php';
        $this->utilPayload = new UtilPayload($this->currentUser,$this->utilityContainer);
    }

    /**
     * Check if previous transaction ID is valid
     *
     * @param array $request The transaction request data
     * @return bool True if previous txid is valid or not required, false otherwise
     */
    public function checkPreviousTxid(array $request): bool {
        try {
            // Validate required fields
            if (!isset($request['senderPublicKey'], $request['receiverAddress'])) {
                error_log("Missing required fields for previous txid check");
                return false;
            }

            // If a previous transaction exists, verify the previousTxid matches
            if (isset($request['previousTxid']) && $previousTxResult = $this->transactionRepository->getPreviousTxid($request['senderPublicKey'], $request['receiverAddress'])) {
                if ($previousTxResult !== $request['previousTxid']) {
                    echo $this->utilPayload->buildInvalidTransactionId($previousTxResult, $request);
                    return false;
                }
            }
            return true;
        } catch (PDOException $e) {
            error_log("Database error in checkPreviousTxid: " . $e->getMessage());
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
                error_log("Missing required fields for funds check");
                return false;
            }

            // Validate amount is numeric and positive
            if (!is_numeric($request['amount']) || $request['amount'] <= 0) {
                error_log("Invalid amount in transaction request: " . $request['amount']);
                return false;
            }

            // Check if there is enough funds to complete the transaction
            $totalSent = $this->transactionRepository->calculateTotalSentByUser($request['senderPublicKey']);
            $totalReceived = $this->transactionRepository->calculateTotalReceived($request['senderPublicKey']);
            $currentBalance = $totalReceived - $totalSent;

            // Get credit limit of sender
            $creditLimit = $this->contactRepository->getCreditLimit($request['senderPublicKey']);

            // Check if sender has sufficient balance or credit limit
            $requiredAmount = $request['amount'];
            $availableFunds = $currentBalance + $creditLimit;

            if ($availableFunds > $requiredAmount) {
                return true;
            } else {
                echo $this->utilPayload->buildInsufficientBalance($availableFunds, $requiredAmount, $creditLimit, 0, $request['currency']);
                return false;
            }
        } catch (PDOException $e) {
            error_log("Database error in checkAvailableFundsTransaction: " . $e->getMessage());
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
        // Check if Transaction already exists for memo in database and is a valid successor of previous txids
        // Check if Transaction is a valid successor of previous txids
        $senderAddress = $request['senderAddress'];
        $transportIndex = $this->transportUtility->determineDatabaseIndexTransportType($senderAddress);
        if(!$this->contactRepository->isNotBlocked($transportIndex, $senderAddress) || !$this->checkPreviousTxid($request) || !$this->checkAvailableFundsTransaction($request)){
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
                // if transaction already exists
                if($echo){
                    echo $this->transactionPayload->buildRejection($request);
                }
                return false;
            } 
            if($echo){
                echo $this->transactionPayload->buildAcceptance($request);            
            }
            return true;  
        } catch (PDOException $e) {
            // Handle database error
            error_log("Error retrieving existence of Transaction by memo/txid" . $e->getMessage());
            if($echo){
                echo json_encode([
                    "status" => "rejected",
                    "message" => "Could not retrieve existence of Transaction with receiver"
                ]);
            }
            return false;
        }
    }

    /**
     * Fix previous transaction ID to avoid duplicates
     *
     * @param string $senderPubKey Sender's public key
     * @param string $receiverPubKey Receiver's public key
     * @return string|null Previous transaction ID
     */
    public function fixPreviousTxid(string $senderPubKey, string $receiverPubKey): ?string {
        // Make sure that the previous transactions txid in the chain is not already being used as a previous_txid for another transaction
        $prevID = $this->transactionRepository->getPreviousTxid($senderPubKey, $receiverPubKey);

        while($prevID && $this->transactionRepository->existingPreviousTxid($prevID)){
            $prevID = $this->transactionRepository->getPreviousTxid($senderPubKey, $receiverPubKey);
            usleep(Constants::TIME_MICROSECONDS_PER_MILLISECOND); // Sleep for 1ms
        }

        return $prevID;
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
     * @return string The generated transaction ID
     */
    public function createUniqueDatabaseTxid(array $data): string {
        // Create unique Txid for transactions (from database values)
        $txid = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $data['receiver_public_key'] . $data['amount'] . $data['time']);
        return $txid;
    }

    /**
     * Check if the Transaction end-recipient is user
     *
     * @param array $request Request data
     * @param string $address Address 
     * @return bool True if user corresponds, False otherwise.
     */
    public function matchYourselfTransaction($request,$address){
        // Check if transaction end recipient is user
        $p2pRequest = $this->p2pRepository->getByHash($request['memo']);
        if( hash(Constants::HASH_ALGORITHM, $address . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
            return true;
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
        $data['time'] = $this->utilityContainer->getTimeUtility()->getCurrentMicrotime();
        $data['amount'] = round($request[3] * Constants::TRANSACTION_USD_CONVERSION_FACTOR); // Convert to cents
        $data['currency'] = $request[4] ?? Constants::TRANSACTION_DEFAULT_CURRENCY; // Get currency or default to USD
        $data['memo'] = 'standard';

        $transportIndex = $this->transportUtility->determineDatabaseIndexTransportType($request[2]);

        // Additional data preparation
        $data['receiverAddress'] = $contactInfo[$transportIndex];
        $data['receiverPublicKey'] = $contactInfo['receiverPublicKey'];
        $data['txid'] = $this->createUniqueTxid($data);
        $data['previousTxid'] = $this->fixPreviousTxid($this->currentUser->getPublicKey(), $contactInfo['receiverPublicKey']);

        return $data;
    }

    /**
     * Prepare P2P transaction data
     *
     * @param array $request Request data
     * @return array Prepared transaction data
     */
    public function prepareP2pTransactionData(array $request): array {
        // Prepare data for p2p transaction
        $data['time'] = $request['time'];

        // Send transaction back to rp2p sender
        $data['receiverAddress'] = $request['senderAddress'];
        $data['receiverPublicKey'] = $request['senderPublicKey'];

        $data['amount'] = $request['amount'];
        $data['currency'] = $request['currency'];
        $data['txid'] = $this->createUniqueTxid($data);
        $data['previousTxid'] = $this->fixPreviousTxid($this->currentUser->getPublicKey(), $request['senderPublicKey']);
        $data['memo'] = $request['hash'];

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
                error_log("Missing required fields in transaction request");
                throw new InvalidArgumentException("Invalid transaction request structure");
            }

            // Process incoming transactions
            if ($request['memo'] === 'standard') {
                // If direct transaction
                $insertTransactionResponse = $this->transactionRepository->insertTransaction($request);
            } else {
                // If p2p type transaction
                $memo = $request['memo'];
                $rP2pResult = $this->rp2pRepository->getByHash($memo);
                // Check if precursors to transactions exist and correspond
                if (isset($rP2pResult) && $memo === $rP2pResult['hash']) {
                    $request['txid'] = $this->createUniqueTxid($request);
                    $request['previousTxid'] = $this->fixPreviousTxid($this->currentUser->getPublicKey(), $request['senderPublicKey']);
                    $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($request), true);
                    output(outputTransactionInsertion($insertTransactionResponse));
                } elseif ($this->matchYourselfTransaction($request, $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']))) {
                    // If Transaction is for end-recipient
                    $request['previousTxid'] = $this->fixPreviousTxid($request['senderPublicKey'], $request['receiverPublicKey']);
                    $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($request), true);
                    output(outputTransactionInsertion($insertTransactionResponse));
                }
            }
        } catch (PDOException $e) {
            error_log("Database error in processTransaction: " . $e->getMessage());
            throw $e;
        } catch (Exception $e) {
            error_log("Error in processTransaction: " . $e->getMessage());
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
                if($message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address'])){
                    $payload = $this->transactionPayload->buildFromDatabase($message);
                    $this->transactionRepository->updateStatus($txid,'sent',true);
                    $response = json_decode($this->transportUtility->send($message['receiver_address'], $payload),true);
                    output(outputTransactionInquiryResponse($response),'SILENT');

                    if($response['status'] === 'accepted'){
                        $this->transactionRepository->updateStatus($txid,'accepted',true);
                    } elseif($response['status'] === 'rejected'){
                        $this->transactionRepository->updateStatus($txid,'rejected',true);
                        output(outputIssueTransactionTryP2p($response),'SILENT');
                        // Send P2P request for failed direct transaction using P2pService directly
                        ServiceContainer::getInstance()->getP2pService()->sendP2pRequestFromFailedDirectTransaction($message);
                    }
                } else{
                    $this->transactionRepository->updateStatus($txid,'completed',true);
                    output(outputTransactionAmountReceived($message),'SILENT');
                    $payloadTransactionCompleted = $this->transactionPayload->buildCompleted($message);
                    output(outputSendTransactionCompletionMessageTxid($message),'SILENT');
                    $response = $this->transportUtility->send($message['sender_address'],$payloadTransactionCompleted);
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
        if($message['sender_address'] == $this->transportUtility->resolveUserAddressForTransport($message['sender_address'])){
            $rp2p = $this->rp2pRepository->getByHash($memo);
            $message['time'] = $rp2p['time'];

            // If sending transaction forwards
            $payload = $this->transactionPayload->buildFromDatabase($message);
            $this->p2pRepository->updateStatus($memo,'paid');
            $this->transactionRepository->updateStatus($memo,'sent');
            output(outputSendTransactionOnwards($message),'SILENT');
            $response = json_decode($this->transportUtility->send($message['receiver_address'], $payload),true);

            if($response['status'] === 'accepted'){
                $this->transactionRepository->updateStatus($txid,'accepted');
            } elseif($response['status'] === 'rejected' ){
                $this->p2pRepository->updateStatus($memo,'cancelled');
                $this->transactionRepository->updateStatus($memo,'rejected');
            }
            output(outputTransactionResponse($response),'SILENT');
        } else{
            // If receiving transaction
            if(!$this->matchYourselfTransaction($message,$this->transportUtility->resolveUserAddressForTransport($message['sender_address']))) {
                // If not end-recipient of transaction
                $this->transactionRepository->updateStatus($memo,'accepted');
                $this->p2pRepository->updateIncomingTxid($message['memo'], $message['txid']);

                // Create new transaction, from received prior transaction, for sending onwards to sender of rp2p
                $rp2p = $this->rp2pRepository->getByHash($message['memo']);
                $data = $this->transactionPayload->buildForwarding($message,$rp2p);
                $this->p2pRepository->updateOutgoingTxid($data['memo'], $data['txid']);

                $payload = $this->transactionPayload->buildFromDatabase($data);
                $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($payload),true);
                output(outputTransactionInsertion($insertTransactionResponse));
            } else{
                // If end-recipient of transaction
                $this->p2pRepository->updateStatus($memo,'completed',true);
                $this->transactionRepository->updateStatus($memo,'completed');
                $this->p2pRepository->updateIncomingTxid($message['memo'], $message['txid']);
                output(outputTransactionAmountReceived($message),'SILENT');
                $payloadTransactionCompleted = $this->transactionPayload->buildCompleted($message);
                output(outputSendTransactionCompletionMessageMemo($message),'SILENT');
                $response = $this->transportUtility->send($message['sender_address'],$payloadTransactionCompleted);
            }
        }
    }

    /**
     * Send eIOU
     *
     * @param array|null $request Request data
     * @return void
     */
    public function sendEiou(?array $request = null): void {
        // Handler for sending eIOU through user Input
        if ($request === null) {
            global $data;
            $request = $data;
        }

        // Enhanced validation using InputValidator for transaction data
        require_once __DIR__ . '/../utils/InputValidator.php';

        // Validate Parameter count
        if (isset($request)) {
            $amountValidation = InputValidator::validateArgvAmount($request, 4);
            if (!$amountValidation['valid']) {
                SecureLogger::warning("Invalid parameter amount", [
                    'value' => $request,
                    'error' => $amountValidation['error']
                ]);
                output(("Invalid parameter amount: " . $amountValidation['error']),'ERROR');
                exit(0);
            }
        }

        // Validate and sanitize amount if provided
        if (isset($request[3])) {
            $amountValidation = InputValidator::validateAmount($request[3], $request[4] ?? 'USD');
            if (!$amountValidation['valid']) {
                SecureLogger::warning("Invalid transaction amount", [
                    'amount' => $request[3],
                    'error' => $amountValidation['error']
                ]);
                output(("Invalid amount: " . $amountValidation['error']),'ERROR');
                exit(0);
            }
            $request[3] = $amountValidation['value'];
        }

        // Validate currency if provided
        if (isset($request[4])) {
            $currencyValidation = InputValidator::validateCurrency($request[4]);
            if (!$currencyValidation['valid']) {
                SecureLogger::warning("Invalid currency code", [
                    'currency' => $request[4],
                    'error' => $currencyValidation['error']
                ]);
                output("Invalid currency: " . $currencyValidation['error'],'ERROR');
                exit(0);
            }
            $request[4] = $currencyValidation['value'];
        }

        // Check if any contacts for eIOU
        if(!$this->contactRepository->getAllAddresses()){
            output(outputNoContactsForTransaction($request));
            exit(0);
        }

        // If receiver's public key is in contacts, prepare a transaction to send directly to them
        $contactService = ServiceContainer::getInstance()->getContactService();
        if ($contactInfo = $contactService->lookupContactInfo($request[2])) {
            if($contactInfo['status'] === 'accepted'){
                // Contact is accepted
                $this->handleDirectRoute($request, $contactInfo);
            }elseif($contactInfo['status'] === 'pending'){
                // Contact is still pending, try a resynch otherwise send through p2p if possible
                $transportIndex = $this->transportUtility->determineDatabaseIndexTransportType($senderAddress);
                $synchResult = ServiceContainer::getInstance()->getSynchService()->synchSingleContact($contactInfo[$transportIndex],'SILENT');
                if($synchResult){
                    $this->handleDirectRoute($request, $contactInfo);
                } else{
                    $this->handleP2pRoute($request);
                }
            } elseif($contactInfo['status'] === 'blocked'){
                // Contact is blocked, do not send anything
                output(outputContactBlockedNoTransaction(),'SILENT');
            }  
        } else {
            // Contact not found, try sending through p2p network
            $this->handleP2pRoute($request);
        }
    }

    /**
     * Send Direct eIOU
     *
     * @param array $request Request data
     * @param array $contactInfo Contact information
     * @return void
     */
    public function handleDirectRoute(array $request, $contactInfo): void{
        output(outputLookedUpContactInfo($contactInfo), 'SILENT');

        // Data preparation for eIOU
        $data = $this->prepareStandardTransactionData($request, $contactInfo);
        
        // Prepare transaction payload from data
        $payload = $this->transactionPayload->build($data);
        $this->transactionRepository->insertTransaction($payload);

        output(outputSendTransaction($payload));
    }

    /**
     * Send out p2p message to find route to contact for sending a eIOU
     *
     * @param array $request Request data
     * @return void
     */
    public function handleP2pRoute(array $request): void{
        output(outputContactNotFoundTryP2p($request), 'SILENT');
        // Send P2P request when contact not found using P2pService directly
        ServiceContainer::getInstance()->getP2pService()->sendP2pRequest($request);
        output(outputSendP2p($request));
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

        // Create data to send back to rp2p sender
        $data = $this->prepareP2pTransactionData($request);

        // Prepare transaction payload
        $payload = $this->transactionPayload->build($data);
        $this->transactionRepository->insertTransaction($payload);
        $this->p2pRepository->updateOutgoingTxid($data['memo'], $data['txid']);
    }

    /**
     * Convert Contact Information back to proper units for display
     *
     * @param array $contacts Contact Information
     * @return array Converted contact information
     */
    public function contactBalanceConversion($contacts): array {
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
        foreach($contacts as $contact){
            // Get pre-calculated balance from batch query result
            $balance = $balances[$contact['pubkey']] ?? 0;

            $fee_percent = $contact['fee_percent'];
            $credit_limit = $contact['credit_limit'];

            $contactsWithBalances[] = [
                'name' => $contact['name'],
                'address' => $contact['address'],
                'balance' =>  $balance ? $this->currencyUtility->convertCentsToDollars($balance) : $balance,
                'fee' =>  $fee_percent ? $this->currencyUtility->convertCentsToDollars($fee_percent) : $fee_percent,
                'credit_limit' =>  $credit_limit ? $this->currencyUtility->convertCentsToDollars($credit_limit) : $credit_limit,
                'currency' => $contact['currency']
            ];

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
    public function calculateTotalSent(string $publicKey): float {
        return $this->transactionRepository->calculateTotalSentByUser($publicKey);
    }

    /**
     * Calculate total received by user
     *
     * @param string $publicKey User's public key
     * @return float Total amount received
     */
    public function calculateTotalReceived(string $publicKey): float {
        return $this->transactionRepository->calculateTotalReceivedByUser($publicKey);
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
}
