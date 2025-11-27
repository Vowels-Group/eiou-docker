<?php
# Copyright 2025

/**
 * Message Service
 *
 * Handles all business logic for message processing and validation.
 * Replaces procedural functions from src/functions/message.php
 *
 * @package Services
 */
class MessageService {
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
     * @var TransportUtilityService Transport utility service 
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var ContactPayload payload builder for contacts
     */
    private ContactPayload $contactPayload;

    /**
     * @var TransactionPayload payload builder for transactions
     */
    private TransactionPayload $transactionPayload;

    /**
     * @var UtilPayload payload builder for utility
     */
    private UtilPayload $utilPayload;

    /**
     * @var MessagePayload payload builder for messages
     */
    private MessagePayload $messagePayload;


    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;
       
        require_once '/etc/eiou/src/schemas/payloads/ContactPayload.php';
        $this->contactPayload = new ContactPayload($this->currentUser,$this->utilityContainer);
        
        require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
        $this->transactionPayload = new TransactionPayload($this->currentUser,$this->utilityContainer);
        
        require_once '/etc/eiou/src/schemas/payloads/UtilPayload.php';
        $this->utilPayload = new UtilPayload($this->currentUser,$this->utilityContainer);
       
        require_once '/etc/eiou/src/schemas/payloads/MessagePayload.php';
        $this->messagePayload = new MessagePayload($this->currentUser,$this->utilityContainer);
    }

    /**
     * Check if message is from a valid source
     *
     * @param array $decodedMessage Decoded message data
     * @return bool True if valid source
     */
    public function checkMessageValidity(array $decodedMessage): bool {
        // Check if message is from a valid source
        if($this->contactRepository->contactExistsPubkey($decodedMessage['senderPublicKey'])){
            // The source is a contact
            return true;
        } elseif(isset($decodedMessage['hash'])){
            $hash = $decodedMessage['hash'];
            $p2p = $this->p2pRepository->getByHash($hash);

            if($p2p){
                // Check if source is original sender for any messages related to transactions
                if($hash === hash(Constants::HASH_ALGORITHM, $this->transportUtility->resolveUserAddressForTransport($decodedMessage['senderAddress']) . $p2p['salt'] . $p2p['time'])){
                    return true;
                }
                return false;
            }
            // Potential Spam (hash is unknown)
            return false;
        }
        // Not a contact nor able to match source
        return false;
    }

    /**
     * Handle incoming message request
     *
     * Note: With the new payload structure, the message content is already decoded
     * by index.html before being passed here. The $request parameter contains
     * the merged content (message fields + senderAddress/senderPublicKey).
     *
     * @param array $request Request data (already decoded)
     * @return void
     */
    public function handleMessageRequest(array $request): void {
        // Check if message is from a known or logical source
        if(!$this->checkMessageValidity($request)){
            echo $this->utilPayload->buildInvalidSource($request);
            exit();
        }

        // Handle Transaction messages
        if($request['typeMessage'] === "transaction"){
            if(isset($request['inquiry']) && $request['inquiry']){
                $this->handleTransactionMessageInquiryRequest($request);
            } else{
                $this->handleTransactionMessageRequest($request);
            }
        }
        // Handle Contact messages
        elseif($request['typeMessage'] === "contact"){
            if(isset($request['inquiry']) && $request['inquiry']){
                $this->handleContactMessageInquiryRequest($request);
            } else{
                $this->handleContactMessageRequest($request);
            }
        }
    }

    /**
     * Handle contact message inquiry request
     *
     * @param array $decodedMessage Decoded message data
     * @return void
     */
    private function handleContactMessageInquiryRequest(array $decodedMessage): void {
        // Handle inquiry about contact request status
        $address = $decodedMessage['senderAddress'];
        $pubkey = $decodedMessage['senderPublicKey'];
        // Contact is already accepted
        if($this->contactRepository->isAcceptedContactPubkey($pubkey)){
            echo $this->messagePayload->buildContactIsAccepted($address,true);
        }
        // Contact is pending
        elseif($this->contactRepository->hasPendingContact($pubkey)){
            echo $this->messagePayload->buildContactIsNotYetAccepted($address);
        } else{
            echo $this->messagePayload->buildContactIsUnknown($address);
        }
    }

    /**
     * Handle contact message request
     *
     * @param array $decodedMessage Decoded message data
     * @return void
     */
    private function handleContactMessageRequest(array $decodedMessage): void {
        // Handle contact request status update messages
        $status = $decodedMessage['status'];
        if($status === 'accepted'){
            output(outputContactRequestWasAccepted($decodedMessage['senderAddress']),'SILENT');
            $this->contactRepository->updateStatus($decodedMessage['senderPublicKey'], $status);
        }
    }

    /**
     * Handle transaction message inquiry request
     *
     * @param array $decodedMessage Decoded message data
     * @return void
     */
    private function handleTransactionMessageInquiryRequest(array $decodedMessage): void {
        // Handle inquiry about transaction status
        output(outputHandleTransactionMessageResponse($decodedMessage),'SILENT');
        echo $this->messagePayload->buildTransactionCompletedCorrectly($decodedMessage);
    }

    /**
     * Handle transaction message request
     *
     * @param array $decodedMessage Decoded message data
     * @return void
     */
    private function handleTransactionMessageRequest(array $decodedMessage): void {
        // Handle incoming transaction messages
        $hash = $decodedMessage['hash']; // for direct transaction is equivalent to txid, otherwise equivalent to memo

        if($decodedMessage['status'] === 'completed'){
            // check if hash exists for p2p and check if hash exists for transaction
            if($decodedMessage['hashType'] === 'memo'){
                $p2p = $this->p2pRepository->getByHash($hash);
                // P2P has two transactions, one to you and one you send forwards (unless you are the end recipient, then only one transaction towards you)
                $transactions = $this->transactionRepository->getByMemo($hash);
                if($p2p && $transactions){
                    // Check if user was original sender of transaction
                    if(isset($p2p['destination_address'])){
                        // Send direct message inquiry to end recipient double checking if completion of transaction correct
                        $completedTransactionInquiry = $this->messagePayload->buildTransactionCompletedInquiry($decodedMessage);
                        $response = json_decode($this->transportUtility->send($p2p['destination_address'],$completedTransactionInquiry),true);
                        output(outputTransactionInquiryResponse($response),'SILENT');

                        if($response['status'] === 'completed'){
                            $this->p2pRepository->updateStatus($hash,'completed',true);
                            $this->transactionRepository->updateStatus($hash,'completed');
                            $this->balanceRepository->updateBalanceGivenTransactions($transactions);
                            output(outputTransactionP2pSentSuccesfully($p2p),'SILENT');
                        }
                    } else{
                        $this->p2pRepository->updateStatus($hash,'completed',true);
                        $this->transactionRepository->updateStatus($hash,'completed');
                        $this->balanceRepository->updateBalanceGivenTransactions($transactions);

                        // Send transaction completion message onwards
                        $payloadTransactionCompleted =  $this->transactionPayload->buildCompleted($decodedMessage);
                        output(outputSendTransactionCompletionMessageOnwards($payloadTransactionCompleted,$p2p['sender_address']),'SILENT');
                        $response = $this->transportUtility->send($p2p['sender_address'],$payloadTransactionCompleted);
                    }
                }
            } elseif($decodedMessage['hashType'] === 'txid'){
                // End recipient (contact) sent us direct confirmation, thus transaction completed successfully
                // Singular direct transaction
                $transaction = $this->transactionRepository->getByTxid($hash);
                if($transaction){
                    $this->transactionRepository->updateStatus($hash,'completed',true);
                    $this->balanceRepository->updateBalanceGivenTransactions($transaction);
                    output(outputTransactionDirectSentSuccesfully($decodedMessage),'SILENT');
                }
            }
        }
    }

    /**
     * Validate message structure
     *
     * Note: With the new payload structure, the message content is already decoded.
     * This method validates the merged request structure.
     *
     * @param array $request Request data (already decoded)
     * @return bool True if valid structure
     */
    public function validateMessageStructure(array $request): bool {
        if (!isset($request['typeMessage'])) {
            error_log("Message structure invalid: missing 'typeMessage' field");
            return false;
        }

        if (!isset($request['senderAddress'])) {
            error_log("Message structure invalid: missing 'senderAddress' field");
            return false;
        }

        return true;
    }

    /**
     * Build message response
     *
     * @param string $status Response status
     * @param string $message Response message
     * @param array $additionalData Additional data to include
     * @return string JSON response
     */
    public function buildMessageResponse(string $status, string $message, array $additionalData = []): string {
        $response = [
            'status' => $status,
            'message' => $message
        ];

        if (!empty($additionalData)) {
            $response = array_merge($response, $additionalData);
        }

        return json_encode($response);
    }
}
