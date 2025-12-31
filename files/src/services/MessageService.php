<?php
# Copyright 2025 The Vowels Company

require_once __DIR__ . '/../utils/SecureLogger.php';

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
     * @var TimeUtilityService Time utility service
     */
    private TimeUtilityService $timeUtility;

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
     * @var MessageDeliveryService Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

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
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;
       
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
     * Set the message delivery service (for lazy initialization)
     *
     * @param MessageDeliveryService $service Message delivery service
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void {
        $this->messageDeliveryService = $service;
    }

    /**
     * Send a message with optional delivery tracking
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Falls back to direct transport
     * if delivery service is not configured.
     *
     * Note: Message subtypes sent from MessageService are encoded in the message ID:
     * - 'inquiry': Direct inquiry to end-recipient (no forwarding) - ID: tx-inquiry-{hash}-{timestamp}
     * - 'completion-relay': Transaction completion relayed through chain - ID: tx-completion-relay-{hash}-{timestamp}
     *
     * All messages use 'transaction' as the message_type for database storage.
     *
     * @param string $messageSubtype Message subtype for message ID (e.g., 'inquiry', 'completion')
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string|null $hash Hash for message ID generation
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    private function sendMessage(string $messageSubtype, string $address, array $payload, ?string $hash = null): array {
        // Generate unique message ID for tracking
        // Format: {subtype}-{hash}-{timestamp} (message_type 'transaction' provides context)
        $hashPart = $hash ?? hash('sha256', json_encode($payload));
        $messageId = $messageSubtype . '-' . $hashPart . '-' . $this->timeUtility->getCurrentMicrotime();

        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // Use sync delivery (async=false) for message service operations
            // Message type is always 'transaction', with subtype encoded in message ID
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
     * Processes contact status update messages (e.g., acceptance notifications)
     * and returns appropriate acknowledgment for delivery tracking.
     *
     * @param array $decodedMessage Decoded message data
     * @return void
     */
    private function handleContactMessageRequest(array $decodedMessage): void {
        // Handle contact request status update messages
        $status = $decodedMessage['status'];
        $senderAddress = $decodedMessage['senderAddress'];
        $senderPublicKey = $decodedMessage['senderPublicKey'];

        if($status === 'accepted'){
            output(outputContactRequestWasAccepted($senderAddress),'SILENT');
            $this->contactRepository->updateStatus($senderPublicKey, $status);

            // Complete the contact transaction (update status from 'sent' to 'completed')
            $this->transactionRepository->completeContactTransaction($senderPublicKey);

            // Return acknowledgment for delivery tracking
            // This confirms the acceptance message was received and processed
            echo $this->messagePayload->buildContactAcceptanceAcknowledgment($senderAddress);
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

        // Check if this is a cascading inquiry (for P2P chains)
        if (isset($decodedMessage['cascading']) && $decodedMessage['cascading'] === true) {
            $this->handleCascadingInquiry($decodedMessage);
            return;
        }

        // Store description from inquiry if provided (for P2P transactions)
        // The original sender includes the description in the inquiry so end-recipient can store it
        if (isset($decodedMessage['description']) && $decodedMessage['description'] !== null && isset($decodedMessage['hash'])) {
            $hash = $decodedMessage['hash'];
            // Update description in p2p table
            $this->p2pRepository->updateDescription($hash, $decodedMessage['description']);
            // Update description in transaction table (using memo/hash)
            $this->transactionRepository->updateDescription($hash, $decodedMessage['description'], false);
        }

        echo $this->messagePayload->buildTransactionCompletedCorrectly($decodedMessage);
    }

    /**
     * Handle cascading inquiry for P2P transaction chain
     *
     * When an intermediary receives a cascading inquiry:
     * 1. Check if local P2P is completed
     * 2. If completed, check if we have destination_address (we're not end-recipient)
     * 3. If we have destination, we're intermediary - forward inquiry to next hop
     * 4. Relay response back to sender
     *
     * Chain flow: A->B->C->D
     * - B receives from A, forwards to C, relays C's response to A
     * - C receives from B, forwards to D, relays D's response to B
     * - D receives from C, responds 'completed'
     *
     * @param array $decodedMessage Decoded message data
     * @return void
     */
    private function handleCascadingInquiry(array $decodedMessage): void {
        require_once '/etc/eiou/src/database/Rp2pRepository.php';
        $rp2pRepository = new Rp2pRepository();

        $hash = $decodedMessage['hash'];

        // Get local P2P record
        $p2p = $this->p2pRepository->getByHash($hash);

        if (!$p2p) {
            // No P2P record found - we never received this transaction
            echo json_encode([
                'status' => 'unknown',
                'message' => 'Transaction not found',
                'chain_status' => 'broken',
                'failed_at' => 'intermediary_no_record'
            ]);
            return;
        }

        // Check local status
        if ($p2p['status'] !== 'completed') {
            // Our leg of the chain isn't complete
            echo json_encode([
                'status' => 'pending',
                'message' => 'Transaction not completed at this hop',
                'chain_status' => 'incomplete',
                'failed_at' => 'intermediary_not_completed',
                'current_status' => $p2p['status']
            ]);
            return;
        }

        // Check if we're the end-recipient or an intermediary
        $isEndRecipient = empty($p2p['destination_address']);

        if ($isEndRecipient) {
            // We're the end-recipient - respond with completed status
            $status = $this->transactionRepository->getStatusByMemo($hash);
            if ($status !== null) {
                echo $this->messagePayload->buildTransactionStatusResponse($decodedMessage, $status);
            } else {
                echo $this->messagePayload->buildTransactionNotFound($decodedMessage);
            }
            return;
        }

        // We're an intermediary - forward inquiry to next hop
        $nextHop = $rp2pRepository->getChainIntermediaryContact($hash);

        if (!$nextHop) {
            // Chain broken - we have no record of forwarding this
            echo json_encode([
                'status' => 'error',
                'message' => 'Next hop not found',
                'chain_status' => 'broken',
                'failed_at' => 'intermediary_no_next_hop'
            ]);
            return;
        }

        // Forward inquiry to next hop
        $forwardedInquiry = $this->messagePayload->buildTransactionCompletedInquiry($decodedMessage);

        try {
            $response = json_decode(
                $this->transportUtility->send($nextHop['address'], $forwardedInquiry),
                true
            );

            if (!$response) {
                // Next hop didn't respond
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Next hop not responding',
                    'chain_status' => 'broken',
                    'failed_at' => 'intermediary_forwarding_failed'
                ]);
                return;
            }

            // Relay response back to sender
            echo json_encode($response);

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to forward inquiry: ' . $e->getMessage(),
                'chain_status' => 'broken',
                'failed_at' => 'intermediary_exception'
            ]);
        }
    }

    /**
     * Handle transaction message request
     *
     * Processes transaction completion messages and returns acknowledgments
     * to enable proper delivery tracking stages.
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
                        // This is a direct message (no forwarding) - completes on 'inserted' status
                        // Subtype 'inquiry' creates message_id: tx-inquiry-{hash}-{timestamp}
                        // Include description from p2p table so end-recipient can store it
                        if (isset($p2p['description']) && $p2p['description'] !== null) {
                            $decodedMessage['description'] = $p2p['description'];
                        }
                        $completedTransactionInquiry = $this->messagePayload->buildTransactionCompletedInquiry($decodedMessage);
                        $sendResult = $this->sendMessage('inquiry', $p2p['destination_address'], $completedTransactionInquiry, $hash);
                        $response = $sendResult['response'];
                        output(outputTransactionInquiryResponse($response),'SILENT');

                        if($response['status'] === 'completed'){
                            $this->p2pRepository->updateStatus($hash,'completed',true);
                            $this->transactionRepository->updateStatus($hash,'completed');
                            $this->balanceRepository->updateBalanceGivenTransactions($transactions);
                            output(outputTransactionP2pSentSuccesfully($p2p),'SILENT');

                            // Store description from completion message if provided
                            if (isset($response['description']) && $response['description'] !== null) {
                                $this->transactionRepository->updateDescription($hash, $response['description'], false);
                            }

                            // Mark all P2P delivery records for this hash as completed
                            $this->markP2pDeliveriesCompleted($hash);
                        }
                    } else{
                        $this->p2pRepository->updateStatus($hash,'completed',true);
                        $this->transactionRepository->updateStatus($hash,'completed');
                        $this->balanceRepository->updateBalanceGivenTransactions($transactions);

                        // Mark all P2P delivery records for this hash as completed
                        $this->markP2pDeliveriesCompleted($hash);

                        // Send transaction completion message onwards (relayed through chain)
                        // This is a relay message - completes on 'forwarded' or 'inserted' status
                        // Subtype 'completion-relay' creates message_id: tx-completion-relay-{hash}-{timestamp}
                        $payloadTransactionCompleted =  $this->transactionPayload->buildCompleted($decodedMessage);
                        output(outputSendTransactionCompletionMessageOnwards($payloadTransactionCompleted,$p2p['sender_address']),'SILENT');
                        $sendResult = $this->sendMessage('completion-relay', $p2p['sender_address'], $payloadTransactionCompleted, $hash);
                    }
                }
                // Return acknowledgment for P2P completion message delivery tracking
                echo $this->messagePayload->buildTransactionCompletionAcknowledgment($decodedMessage);
            } elseif($decodedMessage['hashType'] === 'txid'){
                // End recipient (contact) sent us direct confirmation, thus transaction completed successfully
                // Singular direct transaction
                $transaction = $this->transactionRepository->getByTxid($hash);
                if($transaction){
                    $this->transactionRepository->updateStatus($hash,'completed',true);
                    $this->balanceRepository->updateBalanceGivenTransactions($transaction);
                    output(outputTransactionDirectSentSuccesfully($decodedMessage),'SILENT');

                    // Store description from completion message if provided
                    if (isset($decodedMessage['description']) && $decodedMessage['description'] !== null) {
                        $this->transactionRepository->updateDescription($hash, $decodedMessage['description'], true);
                    }
                }
                // Return acknowledgment for direct transaction completion message delivery tracking
                echo $this->messagePayload->buildTransactionCompletionAcknowledgment($decodedMessage);
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
            SecureLogger::warning("Message structure invalid: missing 'typeMessage' field");
            return false;
        }

        if (!isset($request['senderAddress'])) {
            SecureLogger::warning("Message structure invalid: missing 'senderAddress' field");
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

    /**
     * Mark all P2P delivery records for a hash as completed
     *
     * When a P2P transaction completes, this marks all related message_delivery
     * records (both direct-{hash} and broadcast-{hash}-{contactHash})
     * as completed. Delegates to MessageDeliveryService.
     *
     * @param string $hash The P2P hash (memo)
     * @return void
     */
    private function markP2pDeliveriesCompleted(string $hash): void {
        if ($this->messageDeliveryService === null) {
            return;
        }

        // Mark both 'p2p' and 'rp2p' message types as completed via MessageDeliveryService
        $p2pCount = $this->messageDeliveryService->markCompletedByHash('p2p', $hash);
        $rp2pCount = $this->messageDeliveryService->markCompletedByHash('rp2p', $hash);

        if ($p2pCount > 0 || $rp2pCount > 0) {
            if (function_exists('output')) {
                output("[MessageDelivery] Marked P2P deliveries completed: p2p={$p2pCount}, rp2p={$rp2pCount} for hash={$hash}\n", 'SILENT');
            }
        }
    }
}
