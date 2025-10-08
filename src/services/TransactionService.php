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
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

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
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param ContactRepository $contactRepository Contact repository
     * @param array $currentUser Current user data
     */
    public function __construct(
        TransactionRepository $transactionRepository,
        ContactRepository $contactRepository,
        array $currentUser = []
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->contactRepository = $contactRepository;
        $this->currentUser = $currentUser;
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
                    echo buildInvalidTransactionIDPayload($previousTxResult, $request);
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
                echo buildInsufficientBalancePayload($availableFunds, $requiredAmount, $creditLimit, 0, $request['currency']);
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
    function checkTransactionPossible(array $request, $echo = true) : bool{
        // Check if Transaction already exists for memo in database and is a valid successor of previous txids
        // Check if Transaction is a valid successor of previous txids
       
        if(!$this->contactRepository->isNotBlocked($request['senderAddress']) || !checkPreviousTxid($request) || !checkAvailableFundsTransaction($request)){
            return false;
        }
        // Check if Transaction already exists for txid or memo in database
        try{
            $memo = $request['memo'];
            if($memo === "standard"){
                // If direct transaction
                $results = getTransactionByTxid($request['txid']);
            } else{
                // If p2p based transaction
                $results = getTransactionByMemo($memo);
            }
            if($results){
                // if transaction already exists
                if($echo){
                    echo buildSendRejectionPayload($request);
                }
                return false;
            } 
            if($echo){
                echo buildSendAcceptancePayload($request);            
            }
            return true;  
        } catch (PDOException $e) {
            // Handle database error
            error_log("Error retrieving existence of Transaction by memo" . $e->getMessage());
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
            usleep(1000); // Sleep for 1ms
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
        $txid = hash('sha256', $this->currentUser['public'] . $data['receiverPublicKey'] . $data['amount'] . $data['time']);
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
        $txid = hash('sha256', $this->currentUser['public'] . $data['receiver_public_key'] . $data['amount'] . $data['time']);
        return $txid;
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
        $data['time'] = returnMicroTime();
        $data['amount'] = round($request[3] * 100); // Convert to cents
        $data['currency'] = $request[4] ?? 'USD'; // Get currency or default to USD
        $data['memo'] = 'standard';

        // Additional data preparation
        $data['receiverAddress'] = $contactInfo['receiverAddress'];
        $data['receiverPublicKey'] = $contactInfo['receiverPublicKey'];
        $data['txid'] = $this->createUniqueTxid($data);
        $data['previousTxid'] = $this->fixPreviousTxid($this->currentUser['public'], $contactInfo['receiverPublicKey']);

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
        $data['previousTxid'] = $this->fixPreviousTxid($this->currentUser['public'], $request['senderPublicKey']);
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
                $rP2pResult = checkRp2pExists($memo);

                // Check if precursors to transactions exist and correspond
                if (isset($rP2pResult) && $memo === $rP2pResult['hash']) {
                    $request['txid'] = $this->createUniqueTxid($request);
                    $request['previousTxid'] = $this->fixPreviousTxid($this->currentUser['public'], $request['senderPublicKey']);
                    $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($request), true);
                    output(outputTransactionInsertion($insertTransactionResponse));
                } elseif (matchYourselfTransaction($request, resolveUserAddressForTransport($request['senderAddress']))) {
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
                if($message['sender_address'] == resolveUserAddressForTransport($message['sender_address'])){
                    $payload = buildSendDatabasePayload($message);
                    $this->transactionRepository->updateStatus($txid,'sent',true);
                    $response = json_decode(send($message['receiver_address'], $payload),true);
                    output(outputTransactionInquiryResponse($response),'SILENT');

                    if($response['status'] === 'accepted'){
                        $this->transactionRepository->updateStatus($txid,'accepted',true);
                    } elseif($response['status'] === 'rejected'){
                        $this->transactionRepository->updateStatus($txid,'rejected',true);
                        output(outputIssueTransactionTryP2p($response),'SILENT');
                        sendP2pRequestFromFailedDirectTransaction($message);
                    }
                } else{
                    $this->transactionRepository->updateStatus($txid,'completed',true);
                    output(outputTransactionAmountReceived($message),'SILENT');
                    $payloadTransactionCompleted = buildSendCompletedPayload($message);
                    output(outputSendTransactionCompletionMessageTxid($message),'SILENT');
                    $response = send($message['sender_address'],$payloadTransactionCompleted);
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
        if($message['sender_address'] == resolveUserAddressForTransport($message['sender_address'])){
            $rp2p = checkRp2pExists($memo);
            $message['time'] = $rp2p['time'];

            // If sending transaction forwards
            $payload = buildSendDatabasePayload($message);
            updateP2pRequestStatus($memo,'paid');
            $this->transactionRepository->updateStatus($memo,'sent');
            output(outputSendTransactionOnwards($message),'SILENT');
            $response = json_decode(send($message['receiver_address'], $payload),true);

            if($response['status'] === 'accepted'){
                $this->transactionRepository->updateStatus($txid,'accepted');
            } elseif($response['status'] === 'rejected' ){
                updateP2pRequestStatus($memo,'cancelled');
                $this->transactionRepository->updateStatus($memo,'rejected');
            }
            output(outputTransactionResponse($response),'SILENT');
        } else{
            // If receiving transaction
            if(!matchYourselfTransaction($message,resolveUserAddressForTransport($message['sender_address']))) {
                // If not end-recipient of transaction
                $this->transactionRepository->updateStatus($memo,'accepted');
                updateIncomingP2pTxid($message['memo'], $message['txid']);

                // Create new transaction, from received prior transaction, for sending onwards to sender of rp2p
                $data = buildForwardingTransactionPayload($message);
                updateOutgoingP2pTxid($data['memo'], $data['txid']);

                $payload = buildSendDatabasePayload($data);
                $insertTransactionResponse = json_decode($this->transactionRepository->insertTransaction($payload),true);
                output(outputTransactionInsertion($insertTransactionResponse));
            } else{
                // If end-recipient of transaction
                updateP2pRequestStatus($memo,'completed',true);
                $this->transactionRepository->updateStatus($memo,'completed');
                updateIncomingP2pTxid($message['memo'], $message['txid']);
                output(outputTransactionAmountReceived($message),'SILENT');
                $payloadTransactionCompleted = buildSendCompletedPayload($message);
                output(outputSendTransactionCompletionMessageMemo($message),'SILENT');
                $response = send($message['sender_address'],$payloadTransactionCompleted);
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

        # Check if request is correctly formatted
        if(!validateSendRequest($request)){
            exit(0);
        }

        // Check if any contacts for eIOU
        if(!$this->contactRepository->getAllAddresses()){
            output(outputNoContactsForTransaction($request));
            exit(0);
        }

        // If receiver's public key is in contacts, prepare a transaction to send directly to them
        $contactService = ServiceContainer::getInstance()->getContactService();
        if ($contactInfo = $contactService->lookupContactInfo($request[2])) {
            output(outputLookedUpContactInfo($contactInfo), 'SILENT');

            // Data preparation for eIOU
            $data = $this->prepareStandardTransactionData($request,$contactInfo);

            // Prepare transaction payload from data
            $payload = buildSendPayload($data);

            $this->transactionRepository->insertTransaction($payload);
            output(outputSendTransaction($payload));
        } else {
            output(outputContactNotFoundTryP2p($request), 'SILENT');
            sendP2pRequest($request);
            output(outputSendP2p($request));
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

        // Create data to send back to rp2p sender
        $data = $this->prepareP2pTransactionData($request);

        // Prepare transaction payload
        $payload = buildSendPayload($data);
        $this->transactionRepository->insertTransaction($payload);

        updateOutgoingP2pTxid($data['memo'], $data['txid']);
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
     * Get transaction statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        return $this->transactionRepository->getStatistics();
    }
}
