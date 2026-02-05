<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\MessageServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Schemas\Payloads\ContactPayload;
use Eiou\Schemas\Payloads\TransactionPayload;
use Eiou\Schemas\Payloads\UtilPayload;
use Eiou\Schemas\Payloads\MessagePayload;
use Eiou\Exceptions\FatalServiceException;
use RuntimeException;

/**
 * Message Service
 *
 * Handles all business logic for message processing and validation.
 * Replaces procedural functions from src/functions/message.php
 */
class MessageService implements MessageServiceInterface {
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
     * @var SyncTriggerInterface|null Sync trigger for handling sync requests
     */
    private ?SyncTriggerInterface $syncTrigger = null;

    /**
     * @var TransactionContactRepository Transaction contact repository for contact transaction operations
     */
    private TransactionContactRepository $transactionContactRepository;

    /**
     * Set the sync trigger (accepts interface for loose coupling)
     *
     * @param SyncTriggerInterface $sync Sync trigger (can be proxy or actual service)
     */
    public function setSyncTrigger(SyncTriggerInterface $sync): void {
        $this->syncTrigger = $sync;
    }

    /**
     * Get the sync trigger (must be injected via setSyncTrigger)
     *
     * @return SyncTriggerInterface
     * @throws RuntimeException If sync trigger was not injected
     */
    private function getSyncTrigger(): SyncTriggerInterface {
        if ($this->syncTrigger === null) {
            throw new RuntimeException('SyncTrigger not injected. Call setSyncTrigger() or ensure ServiceContainer properly injects the dependency.');
        }
        return $this->syncTrigger;
    }

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param TransactionContactRepository $transactionContactRepository Transaction contact repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     * @param MessageDeliveryService|null $messageDeliveryService Optional delivery service for tracking
     */
    public function __construct(
        ContactRepository $contactRepository,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        TransactionRepository $transactionRepository,
        TransactionContactRepository $transactionContactRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null
    ) {
        $this->contactRepository = $contactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->transactionContactRepository = $transactionContactRepository;
        $this->utilityContainer = $utilityContainer;
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;

        $this->contactPayload = new ContactPayload($this->currentUser,$this->utilityContainer);
        $this->transactionPayload = new TransactionPayload($this->currentUser,$this->utilityContainer);
        $this->utilPayload = new UtilPayload($this->currentUser,$this->utilityContainer);
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
        $senderPublicKey = $decodedMessage['senderPublicKey'] ?? null;
        $senderAddress = $decodedMessage['senderAddress'] ?? null;
        $typeMessage = $decodedMessage['typeMessage'] ?? 'unknown';

        // Validate senderPublicKey is present
        if ($senderPublicKey === null) {
            Logger::getInstance()->warning("Message validity check failed: missing senderPublicKey", [
                'sender_address' => $senderAddress,
                'type_message' => $typeMessage
            ]);
            return false;
        }

        // Check if message is from a valid source
        if($this->contactRepository->contactExistsPubkey($senderPublicKey)){
            // The source is a contact
            return true;
        } elseif(isset($decodedMessage['hash'])){
            $hash = $decodedMessage['hash'];
            $p2p = $this->p2pRepository->getByHash($hash);

            if($p2p){
                // Check if source is original sender for any messages related to transactions
                if($hash === hash(Constants::HASH_ALGORITHM, $this->transportUtility->resolveUserAddressForTransport($senderAddress) . $p2p['salt'] . $p2p['time'])){
                    return true;
                }
                return false;
            }
            // Potential Spam (hash is unknown)
            return false;
        }

        // Not a contact nor able to match source - log for debugging contact acceptance issues
        Logger::getInstance()->info("Message validity check failed: sender not in contacts", [
            'sender_address' => $senderAddress,
            'sender_public_key_hash' => substr(hash('sha256', $senderPublicKey), 0, 16),
            'type_message' => $typeMessage
        ]);
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
            throw new FatalServiceException(
                "Invalid message source",
                \Eiou\Core\ErrorCodes::UNAUTHORIZED,
                ['request_type' => $request['typeMessage'] ?? 'unknown'],
                401
            );
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
        // Handle P2P status messages
        elseif($request['typeMessage'] === "p2p"){
            if(isset($request['inquiry']) && $request['inquiry']){
                $this->handleP2pStatusInquiryRequest($request);
            }
        }
        // Handle Sync messages
        elseif($request['typeMessage'] === "sync"){
            $this->handleSyncMessageRequest($request);
        }
    }

    /**
     * Handle P2P status inquiry request
     *
     * Returns the status of a P2P transaction when queried.
     * Used by expiring P2P nodes to check if the chain was completed.
     *
     * @param array $decodedMessage Decoded message data
     * @return void
     */
    private function handleP2pStatusInquiryRequest(array $decodedMessage): void {
        $hash = $decodedMessage['hash'] ?? null;
        $senderAddress = $decodedMessage['senderAddress'];

        if (!$hash) {
            echo json_encode([
                'status' => Constants::STATUS_REJECTED,
                'message' => 'Missing P2P hash in inquiry',
                'senderAddress' => $this->transportUtility->resolveUserAddressForTransport($senderAddress),
                'senderPublicKey' => $this->currentUser->getPublicKey(),
            ]);
            return;
        }

        // Look up the P2P status
        $p2p = $this->p2pRepository->getByHash($hash);

        if ($p2p) {
            echo $this->messagePayload->buildP2pStatusResponse($senderAddress, $hash, $p2p['status']);
        } else {
            echo $this->messagePayload->buildP2pStatusResponse($senderAddress, $hash, 'not_found');
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
        $status = $decodedMessage['status'] ?? null;
        $senderAddress = $decodedMessage['senderAddress'] ?? null;
        $senderPublicKey = $decodedMessage['senderPublicKey'] ?? null;

        // Validate required fields
        if ($senderPublicKey === null) {
            Logger::getInstance()->warning("Contact message missing senderPublicKey", [
                'sender_address' => $senderAddress,
                'status' => $status
            ]);
            echo json_encode([
                'status' => Constants::STATUS_REJECTED,
                'message' => 'Missing sender public key'
            ]);
            return;
        }

        if($status === Constants::STATUS_ACCEPTED){
            output(outputContactRequestWasAccepted($senderAddress),'SILENT');

            // Update contact status to accepted and verify it succeeded
            $updateResult = $this->contactRepository->updateStatus($senderPublicKey, $status);

            if (!$updateResult) {
                Logger::getInstance()->warning("Failed to update contact status to accepted", [
                    'sender_address' => $senderAddress,
                    'sender_public_key_hash' => substr(hash('sha256', $senderPublicKey), 0, 16)
                ]);
            } else {
                Logger::getInstance()->info("Contact status updated to accepted", [
                    'sender_address' => $senderAddress
                ]);
            }

            // Complete the contact transaction (update status from 'sent' to 'completed')
            $this->transactionContactRepository->completeContactTransaction($senderPublicKey);

            // Return acknowledgment for delivery tracking
            // This confirms the acceptance message was received and processed
            echo $this->messagePayload->buildContactAcceptanceAcknowledgment($senderAddress);
        } else {
            // Log unexpected status for debugging
            Logger::getInstance()->info("Received contact message with non-accepted status", [
                'status' => $status,
                'sender_address' => $senderAddress
            ]);

            // Return acknowledgment with the received status
            echo json_encode([
                'status' => Constants::DELIVERY_RECEIVED,
                'message' => 'Contact message received',
                'senderAddress' => $this->transportUtility->resolveUserAddressForTransport($senderAddress),
                'senderPublicKey' => $this->currentUser->getPublicKey()
            ]);
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

        // Store description from inquiry if provided (for P2P transactions)
        // The original sender includes the description in the inquiry so end-recipient can store it
        if (isset($decodedMessage['description']) && $decodedMessage['description'] !== null && isset($decodedMessage['hash'])) {
            $hash = $decodedMessage['hash'];
            // Update description in p2p table
            $this->p2pRepository->updateDescription($hash, $decodedMessage['description']);
            // Update description in transaction table (using memo/hash)
            $this->transactionRepository->updateDescription($hash, $decodedMessage['description'], false);
        }

        // Extract and store initial_sender_address from inquiry message (for P2P transactions)
        // The original sender includes their address in the inquiry so end-recipient can track it
        if (isset($decodedMessage['hash'])) {
            $hash = $decodedMessage['hash'];
            $hashType = $decodedMessage['hashType'] ?? 'memo';

            // Only update for memo-based (P2P) transactions
            if ($hashType === 'memo') {
                // Use initialSenderAddress if explicitly provided, otherwise fall back to senderAddress
                $initialSender = $decodedMessage['initialSenderAddress'] ?? $decodedMessage['senderAddress'] ?? null;
                if ($initialSender !== null) {
                    // Update initial_sender_address in transaction table
                    $this->transactionRepository->updateInitialSenderAddress($hash, $initialSender);
                }
            }
        }

        // Look up actual transaction status based on hash type
        $hash = $decodedMessage['hash'];
        $hashType = $decodedMessage['hashType'] ?? 'memo';
        $status = null;

        if ($hashType === 'memo') {
            $status = $this->transactionRepository->getStatusByMemo($hash);
        } elseif ($hashType === 'txid') {
            $status = $this->transactionRepository->getStatusByTxid($hash);
        }

        // Build response based on actual transaction status
        if ($status !== null) {
            echo $this->messagePayload->buildTransactionStatusResponse($decodedMessage, $status);
        } else {
            echo $this->messagePayload->buildTransactionNotFound($decodedMessage);
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

        if($decodedMessage['status'] === Constants::STATUS_COMPLETED){
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
                        // Include original sender's address for end-recipient to track initial_sender_address
                        $decodedMessage['initialSenderAddress'] = $this->transportUtility->resolveUserAddressForTransport(
                            $p2p['destination_address']
                        );
                        $completedTransactionInquiry = $this->messagePayload->buildTransactionCompletedInquiry($decodedMessage);
                        $sendResult = $this->sendMessage('inquiry', $p2p['destination_address'], $completedTransactionInquiry, $hash);
                        $response = $sendResult['response'] ?? null;
                        output(outputTransactionInquiryResponse($response),'SILENT');

                        // Check inquiry response and handle based on actual transaction status
                        if ($sendResult['success'] && $response !== null && isset($response['status'])) {
                            $inquiryStatus = $response['status'];

                            if ($inquiryStatus === Constants::STATUS_COMPLETED) {
                                // Transaction confirmed completed at end-recipient
                                $this->p2pRepository->updateStatus($hash, Constants::STATUS_COMPLETED, true);
                                $this->transactionRepository->updateStatus($hash, Constants::STATUS_COMPLETED);
                                $this->balanceRepository->updateBalanceGivenTransactions($transactions);
                                output(outputTransactionP2pSentSuccesfully($p2p), 'SILENT');

                                // Store description from completion message if provided
                                if (isset($response['description']) && $response['description'] !== null) {
                                    $this->transactionRepository->updateDescription($hash, $response['description'], false);
                                }

                                // Mark all P2P delivery records for this hash as completed
                                $this->markP2pDeliveriesCompleted($hash);
                            } elseif ($inquiryStatus === 'not_found') {
                                // Transaction not found at end-recipient - potential issue
                                output('Transaction inquiry response: not_found at end-recipient for hash=' . $hash, 'SILENT');
                                // Do NOT mark as completed - keep current status for investigation
                            } elseif (in_array($inquiryStatus, [Constants::STATUS_PENDING, Constants::STATUS_SENT, Constants::STATUS_ACCEPTED], true)) {
                                // Transaction still in progress at end-recipient
                                output('Transaction inquiry response: status=' . $inquiryStatus . ' at end-recipient for hash=' . $hash, 'SILENT');
                                // Do NOT mark as completed - transaction not yet finalized
                            } else {
                                // Unknown status - log for investigation
                                output('Transaction inquiry response: unexpected status=' . $inquiryStatus . ' for hash=' . $hash, 'SILENT');
                            }
                        } elseif (!$sendResult['success']) {
                            // Inquiry failed to send - log the error but don't block the completion flow
                            output('Transaction inquiry to end-recipient failed: ' . ($sendResult['tracking']['error'] ?? 'Unknown error'), 'SILENT');
                        }
                    } else{
                        $this->p2pRepository->updateStatus($hash, Constants::STATUS_COMPLETED, true);
                        $this->transactionRepository->updateStatus($hash, Constants::STATUS_COMPLETED);
                        $this->balanceRepository->updateBalanceGivenTransactions($transactions);

                        // Mark all P2P delivery records for this hash as completed
                        $this->markP2pDeliveriesCompleted($hash);

                        // Send transaction completion message onwards (relayed through chain)
                        // This is a relay message - completes on 'forwarded' or 'inserted' status
                        // Subtype 'completion-relay' creates message_id: tx-completion-relay-{hash}-{timestamp}
                        $payloadTransactionCompleted =  $this->transactionPayload->buildCompleted($decodedMessage);
                        output(outputSendTransactionCompletionMessageOnwards($payloadTransactionCompleted,$p2p['sender_address']),'SILENT');
                        $sendResult = $this->sendMessage('completion-relay', $p2p['sender_address'], $payloadTransactionCompleted, $hash);

                        // Log completion-relay result (delivery service handles retries)
                        if (!$sendResult['success']) {
                            output('Completion-relay to ' . $p2p['sender_address'] . ' queued for retry: ' . ($sendResult['tracking']['error'] ?? 'delivery in progress'), 'SILENT');
                        }
                    }
                }
                // Return acknowledgment for P2P completion message delivery tracking
                echo $this->messagePayload->buildTransactionCompletionAcknowledgment($decodedMessage);
            } elseif($decodedMessage['hashType'] === 'txid'){
                // End recipient (contact) sent us direct confirmation, thus transaction completed successfully
                // Singular direct transaction
                $transaction = $this->transactionRepository->getByTxid($hash);
                if($transaction){
                    $this->transactionRepository->updateStatus($hash, Constants::STATUS_COMPLETED, true);
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
            Logger::getInstance()->warning("Message structure invalid: missing 'typeMessage' field");
            return false;
        }

        if (!isset($request['senderAddress'])) {
            Logger::getInstance()->warning("Message structure invalid: missing 'senderAddress' field");
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
     * Handle sync message request
     *
     * Processes transaction chain sync requests and delegates to SyncService.
     *
     * @param array $request The sync request data
     * @return void
     */
    private function handleSyncMessageRequest(array $request): void {
        $syncType = $request['syncType'] ?? null;

        if ($syncType === 'transaction_chain') {
            if (isset($request['inquiry']) && $request['inquiry']) {
                // This is a sync request - delegate to SyncTrigger
                $syncTrigger = $this->getSyncTrigger();
                $syncTrigger->handleTransactionSyncRequest($request);
            } else {
                // This is a sync response - should be handled by the requester
                // Log unexpected sync response
                output('Received unexpected sync response without pending request', 'SILENT');
                echo json_encode([
                    'status' => Constants::STATUS_REJECTED,
                    'reason' => 'no_pending_request',
                    'message' => 'No pending sync request for this response'
                ]);
            }
        } else {
            // Unknown sync type
            echo json_encode([
                'status' => Constants::STATUS_REJECTED,
                'reason' => 'unknown_sync_type',
                'message' => 'Sync type not supported: ' . ($syncType ?? 'null')
            ]);
        }
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
