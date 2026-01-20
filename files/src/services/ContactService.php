<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../cli/CliOutputManager.php';
require_once __DIR__ . '/MessageDeliveryService.php';
require_once __DIR__ . '/../core/ErrorCodes.php';

/**
 * Contact Service
 *
 * Handles all business logic for contact management.
 * Integrates with MessageDeliveryService for reliable message delivery
 * with tracking, retry logic, and dead letter queue support.
 *
 * @package Services
 *
 * SECTION INDEX:
 * - Properties & Constructor............. Line ~20
 * - Contact Transaction Helpers.......... Line ~149
 * - Message Sending...................... Line ~307
 * - Add Contact Operations............... Line ~348
 * - Accept Contact....................... Line ~858
 * - Contact Creation Handler............. Line ~873
 * - Contact Lookup & Search.............. Line ~970
 * - Contact Existence Checks............. Line ~1148
 * - Contact Status Management............ Line ~1190
 * - Contact Updates...................... Line ~1354
 * - Repository Wrappers.................. Line ~1437
 */
class ContactService {

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * @var ContactRepository Contact Repository instance
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
     * @var ContactPayload payload builder for contacts
     */
    private ContactPayload $contactPayload;

    /**
     * @var MessagePayload payload builder for messages
     */
    private MessagePayload $messagePayload;

    /**
     * @var MessageDeliveryService|null Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

    /**
     * @var TransactionRepository Transaction repository for contact transactions
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var SyncService|null Sync service for contact synchronization
     */
    private ?SyncService $syncService = null;

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
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact Repository
     * @param AddressRepository $addressRepository Address Repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param InputValidator $inputValidator InputValidator Util
     * @param SecureLogger $secureLogger SecureLogger Util
     * @param UserContext $currentUser Current user data
     * @param TransactionRepository $transactionRepository Transaction Repository
     * @param MessageDeliveryService|null $messageDeliveryService Optional delivery service for tracking
     */
    public function __construct(
        ContactRepository $contactRepository,
        AddressRepository $addressRepository,
        BalanceRepository $balanceRepository,
        UtilityServiceContainer $utilityContainer,
        InputValidator $inputValidator,
        SecureLogger $secureLogger,
        UserContext $currentUser,
        TransactionRepository $transactionRepository,
        ?MessageDeliveryService $messageDeliveryService = null
        )
    {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->balanceRepository = $balanceRepository;
        $this->utilityContainer = $utilityContainer;
        $this->inputValidator = $inputValidator;
        $this->secureLogger = $secureLogger;
        $this->currentUser = $currentUser;
        $this->transactionRepository = $transactionRepository;
        $this->transportUtility = $this->utilityContainer->getTransportUtility($this->currentUser);
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->messageDeliveryService = $messageDeliveryService;

        require_once '/etc/eiou/src/schemas/payloads/ContactPayload.php';
        $this->contactPayload = new ContactPayload($this->currentUser,$this->utilityContainer);

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

    // =========================================================================
    // CONTACT TRANSACTION HELPERS
    // =========================================================================

    /**
     * Create unique transaction ID for contact requests
     *
     * For contact transactions, amount is always 0, so txid is generated from:
     * senderPublicKey + receiverPublicKey + 0 + time
     *
     * @param string $receiverPublicKey The receiver's public key
     * @param string $time Timestamp
     * @return string The generated transaction ID (SHA-256 hash)
     */
    private function createContactTxid(string $receiverPublicKey, string $time): string {
        return hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey() . $receiverPublicKey . '0' . $time);
    }

    /**
     * Check if a contact transaction already exists for the given receiver
     *
     * @param string $receiverPublicKey The public key of the contact
     * @return bool True if contact transaction exists
     */
    private function contactTransactionExists(string $receiverPublicKey): bool {
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $receiverPublicKey);
        return $this->transactionRepository->contactTransactionExistsForReceiver($receiverPublicKeyHash);
    }

    /**
     * Insert a contact transaction after receiving the public key from a contact
     *
     * Creates a contact transaction with amount=0 to record the contact request
     * as the first transaction between users. Used by the sender of the contact request.
     *
     * The txid should come from the receiver's response to ensure both parties
     * have matching txids for the contact transaction.
     *
     * @param string $receiverPublicKey The public key of the contact
     * @param string $receiverAddress The address of the contact
     * @param string $currency The currency for the transaction
     * @param string|null $txid The txid from the receiver's response
     * @return string|null The txid on success, null on failure
     */
    private function insertContactTransaction(string $receiverPublicKey, string $receiverAddress, string $currency, ?string $txid = null): ?string {
        // Use provided txid from receiver, or generate locally as fallback
        $time = $this->timeUtility->getCurrentMicrotime();
        $txid = $txid ?? $this->createContactTxid($receiverPublicKey, $time);

        // Build transaction data with status 'sent' (will move to 'completed' upon acceptance)
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($receiverAddress);
        $transactionData = [
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'receiverAddress' => $receiverAddress,
            'receiverPublicKey' => $receiverPublicKey,
            'amount' => 0,
            'currency' => $currency,
            'status' => Constants::STATUS_SENT,
            'txid' => $txid,
            'time' => $time,
            'memo' => 'contact',
            'description' => 'Contact request transaction'
            // NOTE: endRecipientAddress and initialSenderAddress are NOT included here
            // They are added via updateTrackingFields() after insert
        ];

        // Insert the contact transaction as 'sent' type
        $result = $this->transactionRepository->insertTransaction($transactionData, Constants::TX_TYPE_SENT);

        if ($result !== false) {
            // Update tracking fields after insert (these are NOT part of signed payload)
            // Contact transactions are direct - both parties know sender and recipient
            $this->transactionRepository->updateTrackingFields(
                $txid,
                $receiverAddress,  // endRecipientAddress
                $myAddress  // initialSenderAddress
            );
            return $txid;
        }

        return null;
    }

    /**
     * Insert a received contact transaction when we receive a contact request
     *
     * Creates a contact transaction with amount=0 from the perspective of the receiver.
     * The transaction is created with status 'accepted' (pending user acceptance) and
     * moves to 'completed' when the user explicitly accepts the contact request.
     *
     * The receiver generates the txid and returns it so it can be included in the
     * response for the sender to use, ensuring both parties have matching txids.
     *
     * @param string $senderPublicKey The public key of the contact who sent the request
     * @param string $senderAddress The address of the contact who sent the request
     * @param string $currency The currency for the transaction
     * @param string|null $signature The sender's signature from the incoming request
     * @param int|null $nonce The signature nonce from the incoming request
     * @return string|null The txid on success, null on failure
     */
    private function insertReceivedContactTransaction(string $senderPublicKey, string $senderAddress, string $currency = 'USD', ?string $signature = null, ?int $nonce = null): ?string {
        // Generate time and txid on receiver side
        $time = $this->timeUtility->getCurrentMicrotime();

        // Generate txid using sender's public key + receiver's public key + 0 + time
        $txid = hash(Constants::HASH_ALGORITHM, $senderPublicKey . $this->currentUser->getPublicKey() . '0' . $time);

        // Build transaction data with status 'accepted' (pending user acceptance, will move to 'completed')
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($senderAddress);
        $transactionData = [
            'senderAddress' => $senderAddress,
            'senderPublicKey' => $senderPublicKey,
            'receiverAddress' => $myAddress,
            'receiverPublicKey' => $this->currentUser->getPublicKey(),
            'amount' => 0,
            'currency' => $currency,
            'status' => Constants::STATUS_ACCEPTED,
            'txid' => $txid,
            'time' => $time,
            'memo' => 'contact',
            'description' => 'Contact request transaction',
            // Sender's signature data for future sync verification
            'signature' => $signature,
            'nonce' => $nonce
            // NOTE: endRecipientAddress and initialSenderAddress are NOT included here
            // They are added via updateTrackingFields() after insert
        ];

        // Insert the contact transaction with 'accepted' status
        // Second parameter is transaction type: 'received' (we are receiving a contact request)
        $result = $this->transactionRepository->insertTransaction($transactionData, Constants::TX_TYPE_RECEIVED);

        if ($result !== false) {
            // Update tracking fields after insert (these are NOT part of signed payload)
            // Contact transactions are direct - both parties know sender and recipient
            $this->transactionRepository->updateTrackingFields(
                $txid,
                $myAddress,  // endRecipientAddress
                $senderAddress  // initialSenderAddress
            );
        }

        // Return the txid so caller can include it in the response
        return $result !== false ? $txid : null;
    }

    /**
     * Complete a received contact transaction when user accepts the contact request
     *
     * Updates the contact transaction status from 'accepted' to 'completed'.
     * This is called from the receiver's perspective when they accept an incoming request.
     *
     * @param string $senderPublicKey The public key of the contact who sent the request
     * @return bool True if transaction was updated successfully
     */
    private function completeReceivedContactTransaction(string $senderPublicKey): bool {
        return $this->transactionRepository->completeReceivedContactTransaction($senderPublicKey);
    }

    // =========================================================================
    // MESSAGE SENDING
    // =========================================================================

    /**
     * Send a contact message with optional delivery tracking
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Falls back to direct transport
     * if delivery service is not configured.
     *
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string|null $messageId Optional unique message ID for tracking
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    private function sendContactMessage(string $address, array $payload, ?string $messageId = null, bool $async = true): array {
        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // async=true: Non-blocking, queues for retry if first attempt fails
            // async=false: Blocking, waits for response (required for acceptance messages
            //              to ensure the sender's status is updated before returning)
            return $this->messageDeliveryService->sendMessage(
                'contact',
                $address,
                $payload,
                $messageId,
                $async
            );
        }

        // Fall back to direct transport when MessageDeliveryService not available
        if ($messageId === null) {
            $messageId = hash('sha256', json_encode($payload) . $this->timeUtility->getCurrentMicrotime());
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

    // =========================================================================
    // ADD CONTACT OPERATIONS
    // =========================================================================

    /**
     * Add a contact
     *
     * This method validates all input data using InputValidator and Security classes
     * to ensure data integrity and prevent injection attacks.
     *
     * @param array $data Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function addContact(array $data, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Validate and sanitize address
        $addressValidation =  $this->inputValidator->validateAddress($data[2] ?? '');
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            $output->error("Invalid Address: " . $addressValidation['error'], ErrorCodes::INVALID_ADDRESS, 400);
            return;
        }
        $address = $addressValidation['value'];

        if(in_array($address,$this->currentUser->getUserAddresses())){
            $output->error("Cannot add yourself as a contact", ErrorCodes::SELF_CONTACT, 400);
            return;
        }

        // Validate and sanitize contact name
        $nameValidation =  $this->inputValidator->validateContactName($data[3] ?? '');
        if (!$nameValidation['valid']) {
            $this->secureLogger->warning("Invalid contact name", [
                'name' => $data[3] ?? 'empty',
                'error' => $nameValidation['error']
            ]);
            $output->error("Invalid name: " . $nameValidation['error'], ErrorCodes::INVALID_NAME, 400);
            return;
        }
        $name = $nameValidation['value'];

        // Validate fee percentage
        $feeValidation =  $this->inputValidator->validateFeePercent($data[4] ?? 0);
        if (!$feeValidation['valid']) {
            $this->secureLogger->warning("Invalid fee percentage", [
                'fee' => $data[4] ?? 'empty',
                'error' => $feeValidation['error']
            ]);
            $output->error("Invalid Fee: " . $feeValidation['error'], ErrorCodes::INVALID_FEE, 400);
            return;
        }
        $fee = $feeValidation['value'] * Constants::FEE_CONVERSION_FACTOR;

        // Validate credit limit
        $creditValidation =  $this->inputValidator->validateCreditLimit($data[5] ?? 0);
        if (!$creditValidation['valid']) {
            $this->secureLogger->warning("Invalid credit limit", [
                'credit' => $data[5] ?? 'empty',
                'error' => $creditValidation['error']
            ]);
            $output->error("Invalid credit: " . $creditValidation['error'], ErrorCodes::INVALID_CREDIT, 400);
            return;
        }
        $credit = $creditValidation['value'] * Constants::CREDIT_CONVERSION_FACTOR;

        // Validate currency
        $currencyValidation =  $this->inputValidator->validateCurrency($data[6] ?? 'USD');
        if (!$currencyValidation['valid']) {
            $this->secureLogger->warning("Invalid currency", [
                'currency' => $data[6] ?? 'empty',
                'error' => $currencyValidation['error']
            ]);
            $output->error("Invalid currency: " . $currencyValidation['error'], ErrorCodes::INVALID_CURRENCY, 400);
            return;
        }
        $currency = $currencyValidation['value'];

        // Log successful validation
        $this->secureLogger->info("Contact addition validated", [
            'address_type' => $addressValidation['type'] ?? 'unknown',
            'name_length' => strlen($name)
        ]);

        // Get contact if exists in database in some form
        $transportIndex = $this->transportUtility->determineTransportType($address);
        $contact = $this->contactRepository->getContactByAddress($transportIndex, $address);

        if($contact){
            $this->handleExistingContact($contact, $address, $name, $fee, $credit, $currency, $output);
        } else{
            $this->handleNewContact($address, $name, $fee, $credit, $currency, $output);
        }
    }

    /**
     * Handle existing contact addition
     *
     * @param array $contact Existing contact data
     * @param string $address Contact address
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    private function handleExistingContact(array $contact, string $address, string $name, float $fee, float $credit, string $currency, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Build contact data for JSON response
        $contactData = [
            'address' => $address,
            'name' => $name,
            'fee' => $fee / Constants::FEE_CONVERSION_FACTOR,
            'credit' => $credit / Constants::CREDIT_CONVERSION_FACTOR,
            'currency' => $currency,
            'status' => $contact['status']
        ];

        // Check if contact is already an accepted contact
        if($contact['status'] === Constants::CONTACT_STATUS_ACCEPTED){
            $output->error("Contact " . $address . " already exists ", ErrorCodes::CONTACT_EXISTS, 409, ['contact' => $contactData]);
        }
        // Check if contact was blocked
        elseif($contact['status'] === Constants::CONTACT_STATUS_BLOCKED){
            // Contact was blocked after user sent contact request
            if($contact['name']){
                // Unblock contact and add values
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    $output->success("Contact" . $address . "unblocked and updated", $contactData, "Contact unblocked and updated successfully");
                } else{
                    $output->error("Failed to unblock and update contact " . $address, ErrorCodes::UNBLOCK_FAILED, 500, ['contact' => $contactData]);
                }
            }
            // Contact was blocked when user received contact request
            else{
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    // Send message of successful contact acceptance back to original contact requester with tracking
                    // Message ID format: unblock-accept-{hash} (message_type 'contact' provides context)
                    $acceptPayload = $this->messagePayload->buildContactIsAccepted($address);
                    $messageId = 'unblock-accept-' . hash('sha256', $address . $contact['pubkey'] . $this->timeUtility->getCurrentMicrotime());
                    $sendResult = $this->sendContactMessage($address, $acceptPayload, $messageId);

                    // For acceptance messages, we update stages based on our local operations (using MessageDeliveryService directly)
                    // Stage progression: pending -> sent -> received (from transport) -> inserted (local) -> completed
                    if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Complete the received contact transaction (update status from 'accepted' to 'completed')
                    $this->completeReceivedContactTransaction($contact['pubkey']);

                    $output->success("Contact " . $address . " unblocked and added", $contactData, "Contact unblocked and added successfully");
                } else{
                    $output->error("Failed to unblock and add contact " . $address, ErrorCodes::UNBLOCK_ADD_FAILED, 500, ['contact' => $contactData]);
                }
            }
        }
        elseif($contact['status'] === Constants::CONTACT_STATUS_PENDING){
            // if pending with name (contact was inserted by user for contact request)
            if($contact['name']){
                // This contact was already sent a contact request, but has not yet responded to user (try resyncing)
                // Use full sync chain for wallet restoration scenarios: Contact -> Transactions -> Balances
                $syncService = $this->getSyncService();
                $syncResult = $syncService->syncReaddedContact($address, $contact['pubkey']);

                if ($syncResult['success'] && $syncResult['contact_synced']) {
                    $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                    $contactData['sync'] = [
                        'transactions_synced' => $syncResult['transactions_synced'],
                        'balances_synced' => $syncResult['balances_synced'],
                        'currencies' => $syncResult['currencies']
                    ];
                    $output->success("Contact request already sent, synced successfully with " . $address, $contactData, "Contact synced");
                } elseif ($syncResult['contact_synced']) {
                    // Contact status synced but transactions/balances may have failed
                    $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                    $output->success("Contact synced, transaction sync may be incomplete", $contactData, "Partial sync");
                } else {
                    $output->info("Contact request already sent, awaiting response from " . $address, $contactData);
                }
            } else{
                // If contact already exists with an address, it's a contact request, skip sending a message
                if ($this->acceptContact($contact['pubkey'], $name, $fee, $credit, $currency)) {
                    // Send message of successful contact acceptance back to original contact requester with tracking
                    // Message ID format: accept-{hash} (message_type 'contact' provides context)
                    // IMPORTANT: Use sync delivery (async=false) to ensure the original requester updates
                    // their contact status to 'accepted' before we return success. This prevents race conditions
                    // when multiple contacts are added in rapid succession.
                    $acceptPayload = $this->messagePayload->buildContactIsAccepted($address);
                    $messageId = 'accept-' . hash('sha256', $address . $contact['pubkey'] . $this->timeUtility->getCurrentMicrotime());
                    $sendResult = $this->sendContactMessage($address, $acceptPayload, $messageId, false); // sync delivery

                    // Log if acceptance message delivery failed
                    if (!$sendResult['success']) {
                        SecureLogger::warning("Contact acceptance message delivery failed", [
                            'recipient_address' => $address,
                            'message_id' => $messageId,
                            'error' => $sendResult['tracking']['error'] ?? 'unknown'
                        ]);
                    }

                    // For acceptance messages, we update stages based on our local operations (using MessageDeliveryService directly)
                    // The acceptance message was sent and our local DB was updated (acceptContact above)
                    // Stage progression: pending -> sent -> received (from transport) -> inserted (local) -> completed
                    if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Complete the received contact transaction (update status from 'accepted' to 'completed')
                    $this->completeReceivedContactTransaction($contact['pubkey']);

                    $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                    $output->success("Contact request accepted from " . $address, $contactData, "Contact accepted successfully");
                }
                else {
                    $output->error("Failed to accept contact request from " . $address, ErrorCodes::ACCEPT_FAILED, 500, ['contact' => $contactData]);
                    return;
                }
            }
        }
    }

    /**
     * Handle new contact creation
     *
     * Uses MessageDeliveryService for reliable message delivery when available.
     *
     * @param string $address Contact address
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    private function handleNewContact(string $address, string $name, float $fee, float $credit, string $currency, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Build contact data for JSON response
        $contactData = [
            'address' => $address,
            'name' => $name,
            'fee' => $fee / Constants::FEE_CONVERSION_FACTOR,
            'credit' => $credit / Constants::CREDIT_CONVERSION_FACTOR,
            'currency' => $currency
        ];

        // Build the payload array
        $payload = $this->contactPayload->buildCreateRequest($address);
        $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($address);  // Address already passed validation before

        // Generate unique message ID for contact creation tracking
        // Message ID format: create-{hash} (message_type 'contact' provides context)
        $messageId = 'create-' . hash('sha256', $address . $this->currentUser->getPublicKey() . $this->timeUtility->getCurrentMicrotime());

        // Send contact creation request with delivery tracking
        $sendResult = $this->sendContactMessage($address, $payload, $messageId);
        $responseData = $sendResult['response'];

        if (isset($responseData['status'])){
            $senderPublicKey = $responseData['senderPublicKey'];
            $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);

            // Check if we already have this contact stored locally (under a different address)
            // This handles the case where user adds a known contact via new address type
            // OR the case where they sent us a request while we were sending ours
            $existingLocalContact = $this->contactRepository->getContactByPubkey($senderPublicKey);
            if ($existingLocalContact) {
                // Update the address with new transport type
                $this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative);

                if ($this->messageDeliveryService !== null) {
                    $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                }

                // If contact is pending without name (we received their request), accept with our provided values
                if ($existingLocalContact['status'] === Constants::CONTACT_STATUS_PENDING && $existingLocalContact['name'] === null) {
                    // Accept the contact with the user-provided name/fee/credit values
                    if ($this->acceptContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                        // Send acceptance message back to them
                        $acceptPayload = $this->messagePayload->buildContactIsAccepted($address);
                        $acceptMessageId = 'accept-' . hash('sha256', $address . $senderPublicKey . $this->timeUtility->getCurrentMicrotime());
                        $sendResult = $this->sendContactMessage($address, $acceptPayload, $acceptMessageId, false);

                        if (!$sendResult['success']) {
                            SecureLogger::warning("Contact acceptance message delivery failed", [
                                'recipient_address' => $address,
                                'message_id' => $acceptMessageId,
                                'error' => $sendResult['tracking']['error'] ?? 'unknown'
                            ]);
                        }

                        // Complete the received contact transaction
                        $this->completeReceivedContactTransaction($senderPublicKey);

                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $contactData['pubkey'] = $senderPublicKey;
                        $output->success("Contact request accepted from " . $address, $contactData, "Contact accepted successfully");
                        return;
                    }
                }

                // Contact exists with name or non-pending status - just report the update
                $contactData['status'] = $existingLocalContact['status'];
                $contactData['pubkey'] = $senderPublicKey;
                $output->success("Contact address updated for " . $name, $contactData, "New address type added to existing contact");
                return;
            }

            // Contact request was received (initial insert on their end as pending, awaiting acceptance)
            if($responseData['status'] === 'received'){
                // Insert contact on our end with returned pubkey as pending (awaiting acceptance)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                    }

                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    // Insert contact transaction (first transaction between users, amount=0)
                    // Use the txid from the response to ensure both parties have matching txids
                    $txid = $responseData['txid'] ?? null;
                    $this->insertContactTransaction($senderPublicKey, $address, $currency, $txid);

                    // Store signature data for future sync verification
                    $signingData = $sendResult['signing_data'] ?? null;
                    if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                        $this->transactionRepository->updateSignatureData(
                            $txid,
                            $signingData['signature'],
                            $signingData['nonce']
                        );
                    }

                    // Update delivery stage: received -> inserted -> completed (using MessageDeliveryService directly)
                    // Contact request phase is complete (awaiting acceptance is a separate phase)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                    $contactData['pubkey'] = $senderPublicKey;
                    $output->success("Contact request sent successfully to " . $address, $contactData, "Contact request sent, awaiting acceptance");
                } else{
                    $output->error("Failed to create contact with " . $address, ErrorCodes::CONTACT_CREATE_FAILED, 500, ['contact' => $contactData]);
                    return;
                }
            }
            // Our contact pubkey exists on their end, but not provided address
            // we are known under a different address or transport type
            // Note: If contact existed locally, we would have returned early above
            // So reaching here means contact was deleted locally - need to re-insert and sync
            elseif($responseData['status'] === 'updated'){
                $senderAddress = $responseData['senderAddress'];
                // Contact was deleted locally - re-insert and sync
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                    }

                    // Insert initial balances - will be updated by full sync below
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    if (!$this->contactTransactionExists($senderPublicKey)) {
                        $txid = $this->insertContactTransaction($senderPublicKey, $address, $currency);

                        // Store signature data for future sync verification
                        $signingData = $sendResult['signing_data'] ?? null;
                        if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                            $this->transactionRepository->updateSignatureData(
                                $txid,
                                $signingData['signature'],
                                $signingData['nonce']
                            );
                        }
                    }

                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Full sync for re-added contact: sync contact status, transaction chain, and balances
                    $syncService = $this->getSyncService();
                    $syncResult = $syncService->syncReaddedContact($address, $senderPublicKey);

                    if ($syncResult['success']) {
                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $contactData['pubkey'] = $senderPublicKey;
                        $contactData['sync'] = [
                            'transactions_synced' => $syncResult['transactions_synced'],
                            'balances_synced' => $syncResult['balances_synced'],
                            'currencies' => $syncResult['currencies']
                        ];
                        $output->success("Contact re-added and fully synced with " . $address, $contactData, "Contact created with transaction and balance sync");
                    } else {
                        $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                        $output->success("Contact re-added, awaiting sync with " . $address, $contactData, "Contact created, sync pending");
                    }
                } else {
                    $output->error("Failed to re-add contact with " . $address, ErrorCodes::CONTACT_CREATE_FAILED, 500, ['contact' => $contactData]);
                }
            }
            // Our contact pubkey and address both exist on their end (Case when we delete the contact and try re-adding it)
            elseif($responseData['status'] === Constants::DELIVERY_WARNING){
                // Insert contact and perform full sync (transactions + balances)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);

                    // Store any additional addresses from senderAddresses if present
                    if (isset($responseData['senderAddresses']) && is_array($responseData['senderAddresses'])) {
                        $this->addressRepository->updateContactFields($senderPublicKeyHash, $responseData['senderAddresses']);
                    }

                    // Insert initial balances - will be updated by full sync below
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    // Insert contact transaction only if one doesn't already exist
                    // (contact may have been deleted but transaction still exists in history)
                    if (!$this->contactTransactionExists($senderPublicKey)) {
                        $txid = $this->insertContactTransaction($senderPublicKey, $address, $currency);

                        // Store signature data for future sync verification
                        $signingData = $sendResult['signing_data'] ?? null;
                        if ($txid && $signingData && isset($signingData['signature']) && isset($signingData['nonce'])) {
                            $this->transactionRepository->updateSignatureData(
                                $txid,
                                $signingData['signature'],
                                $signingData['nonce']
                            );
                        }
                    }

                    // Update delivery stage: warning -> inserted -> completed (using MessageDeliveryService directly)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Full sync for re-added contact: sync contact status, transaction chain, and balances
                    // If contact still has transaction chain on their end, resync from original contact transaction
                    // through all known transactions (verifying signatures) and finally sync balances
                    $syncService = $this->getSyncService();
                    $syncResult = $syncService->syncReaddedContact($address, $senderPublicKey);

                    if ($syncResult['success']) {
                        $contactData['status'] = Constants::CONTACT_STATUS_ACCEPTED;
                        $contactData['pubkey'] = $senderPublicKey;
                        $contactData['sync'] = [
                            'transactions_synced' => $syncResult['transactions_synced'],
                            'balances_synced' => $syncResult['balances_synced'],
                            'currencies' => $syncResult['currencies']
                        ];
                        $output->success("Contact re-added and fully synced with " . $address, $contactData, "Contact created with transaction and balance sync");
                    } else {
                        $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                        $output->success("Contact re-added, awaiting sync with " . $address, $contactData, "Contact created, sync pending");
                    }
                }
            }
            // Our contact request could not be processed on their end
            elseif($responseData['status'] === 'rejection'){
                $output->error("Contact request rejected by " . $address . " : " . ($responseData['reason'] ?? 'Unknown reason'), ErrorCodes::CONTACT_REJECTED, 403, [
                    'contact' => $contactData,
                    'response' => $responseData
                ]);
                return;
            }
        } else{
            // No immediate response - check if message was queued for background retry
            // This is expected behavior for async mode over slow Tor connections
            if ($sendResult['queued_for_retry'] ?? false) {
                // Message is being retried in the background by the message processor
                // Insert contact locally as pending so user can see it in their contact list
                $contactData['status'] = Constants::CONTACT_STATUS_PENDING;
                $contactData['delivery_status'] = 'queued_for_retry';
                $output->success(
                    "Contact request sent to " . $address . ". Awaiting response (message being delivered in background).",
                    $contactData,
                    "Contact request sent, delivery in progress"
                );
                return;
            }

            // Message delivery failed completely (not queued for retry)
            // Tracking results are nested inside 'tracking' key from sendContactMessage
            $trackingResult = $sendResult['tracking'] ?? [];
            $attempts = $trackingResult['attempts'] ?? 'unknown';
            $lastError = $trackingResult['error'] ?? 'No response received';

            $output->error(
                "Failed to reach contact address after " . $attempts . " attempts. " .
                "Address " . $address . " may not exist or is offline.",
                ErrorCodes::CONTACT_UNREACHABLE,
                null,
                [
                    'contact' => $contactData,
                    'attempts' => $attempts,
                    'last_error' => $lastError,
                    'moved_to_dlq' => $trackingResult['dlq'] ?? false
                ]
            );
            return;
        }
    }

    // =========================================================================
    // ACCEPT CONTACT
    // =========================================================================

    /**
     * Accept a contact request
     *
     * @param string $pubkey Contact pubkey
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @return bool Success status
     */
    public function acceptContact(string $pubkey, string $name, float $fee, float $credit, string $currency): bool {
        $success = $this->contactRepository->acceptContact($pubkey, $name, $fee, $credit, $currency);
        if($success){
            // Addresses already saved, just need to add initial contact balances
            $this->balanceRepository->insertInitialContactBalances($pubkey, $currency);
        }
        return $success;
    }

    // =========================================================================
    // CONTACT CREATION HANDLER
    // =========================================================================

    /**
     * Handle contact creation request (incoming)
     *
     * @param array $request Request data
     * @return string Response payload
     */
    public function handleContactCreation(array $request): string {
        $senderAddress = $request['senderAddress'];
        $senderPublicKey = $request['senderPublicKey'];
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($senderAddress);

        // Extract sender's signature data for storing with the contact transaction
        $signature = $request['signature'] ?? null;
        $nonce = isset($request['nonce']) ? (int)$request['nonce'] : null;

        // Get our own (the responder's) addresses to include in response
        // This allows the requester to store all our known addresses
        $myAddresses = $this->currentUser->getUserLocaters();

        // Check if contact already exists
        if ($this->contactRepository->contactExistsPubkey($senderPublicKey)) {
            $contactAddresses = $this->addressRepository->lookupByPubkeyHash($senderPublicKeyHash);
            $transportIndex = $this->transportUtility->determineTransportType($senderAddress);
            if($contactAddresses[$transportIndex] === $senderAddress){
                // Address already exists - check contact status for re-add scenario
                // When a deleted contact re-adds us, we may have them as 'pending'
                // (they added us before but we never accepted, then they deleted and re-added)
                $existingContact = $this->contactRepository->getContactByPubkey($senderPublicKey);
                if ($existingContact && $existingContact['status'] === Constants::CONTACT_STATUS_PENDING) {
                    // Contact exists as pending - check if we have the contact transaction
                    // If they're re-adding us and we don't have their contact tx, we need to create one
                    $hasContactTx = $this->transactionRepository->contactTransactionExistsForReceiver(
                        $senderPublicKeyHash
                    );

                    if (!$hasContactTx) {
                        // Create the contact transaction on our side (they already have one on their end)
                        $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, 'USD', $signature, $nonce);
                        // Return 'received' with txid so sender can sync
                        return $this->contactPayload->buildReceived($senderAddress, null, $txid);
                    }

                    // Contact exists as pending with contact transaction - treat as re-confirmation
                    // Return 'received' so sender handles it like a new contact (no sync attempt)
                    // Don't include other addresses for pending contacts (privacy)
                    return $this->contactPayload->buildReceived($senderAddress);
                }
                // Contact is accepted or other status - return warning (already exists)
                // Include all our known addresses so sender can store them (re-add scenario)
                return $this->contactPayload->buildAlreadyExists($senderAddress, $myAddresses);
            } else{
                // Address unknown prior but pubkey exists (known contact, unknown address)
                // Check contact status - if pending, treat as re-confirmation with new address
                $existingContact = $this->contactRepository->getContactByPubkey($senderPublicKey);
                if ($existingContact && $existingContact['status'] === Constants::CONTACT_STATUS_PENDING) {
                    // Contact is pending - update their address
                    if($this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative)){
                        // Check if we have the contact transaction
                        $hasContactTx = $this->transactionRepository->contactTransactionExistsForReceiver(
                            $senderPublicKeyHash
                        );

                        if (!$hasContactTx) {
                            // Create the contact transaction on our side
                            $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, 'USD', $signature, $nonce);
                            return $this->contactPayload->buildReceived($senderAddress, null, $txid);
                        }

                        // Return 'received' so sender handles it like a new contact request
                        return $this->contactPayload->buildReceived($senderAddress);
                    }
                }
                // Contact is accepted - update address and return 'updated'
                // Include all our known addresses so sender can store them (re-add scenario)
                if($this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative)){
                    return $this->contactPayload->buildUpdated($senderAddress, $myAddresses);
                } else{
                    // Unable to update contact
                    return $this->contactPayload->buildRejection($senderAddress);
                }
            }
        } else{
            // Contact request is brand new, no prior users exist in any form
            if($this->contactRepository->addPendingContact($senderPublicKey) && $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative)){
                // Insert received contact transaction with status 'accepted' (pending user acceptance)
                // This creates the contact transaction on the receiver's side
                // Receiver generates txid and includes it in response for sender to use
                $txid = $this->insertReceivedContactTransaction($senderPublicKey, $senderAddress, 'USD', $signature, $nonce);
                return $this->contactPayload->buildReceived($senderAddress, null, $txid);
            } else{
                // Unable to insert contact
                return $this->contactPayload->buildRejection($senderAddress);
            }
        }
    }

    // =========================================================================
    // CONTACT LOOKUP & SEARCH
    // =========================================================================

    /**
     * Lookup contact information
     *
     * @param mixed $request Request data (name or address)
     * @return array|null Contact information or null
     */
    public function lookupContactInfo($request): ?array {
        // Lookup information
        $lookupResultByName = $this->lookupByName($request);
        if(!$lookupResultByName){
            $lookupResultByAddress = null;
            $transportIndex = $this->transportUtility->determineTransportType($request);
            if($transportIndex){
                $lookupResultByAddress = $this->lookupByAddress($transportIndex, $request);
            }
        }
        
        $lookupResult = $lookupResultByName ?? $lookupResultByAddress;

        if (isset($lookupResult['name'])) {
            $data['receiverName'] = $lookupResult['name'];
        }
        if (isset($lookupResult['pubkey'])) {
            $data['receiverPublicKey'] = $lookupResult['pubkey'];
        }
        if (isset($lookupResult['pubkey_hash'])) {
            $data['receiverPublicKeyHash'] = $lookupResult['pubkey_hash'];
        }
        if (isset($lookupResult['http'])){
            $data['http'] = $lookupResult['http'];
        } 
        if (isset($lookupResult['tor'])){
            $data['tor'] = $lookupResult['tor'];
        }  
        if (isset($lookupResult['status'])){
            $data['status'] = $lookupResult['status'];
        }

        return isset($data) ? $data : null;
    }

    /**
     * Lookup contact by name
     *
     * @param string $name Contact name
     * @return array|null Contact data or null
     */
    public function lookupByName(string $name): ?array {
        return $this->contactRepository->lookupByName($name);
    }

    /**
     * Lookup contact by address
     *
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string $address Contact address
     * @return array|null Contact data or null
     */
    public function lookupByAddress(string $transportIndex, string $address): ?array {
        return $this->contactRepository->lookupByAddress($transportIndex, $address);
    }

    /**
     * Search contacts
     *
     * @param array $data Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function searchContacts(array $data, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Lookup contact based on their name
        if(isset($data[2])){
            $nameValidation =  $this->inputValidator->validateContactName($data[2]);
            if (!$nameValidation['valid']) {
                $this->secureLogger->warning("Invalid contact name", [
                    'name' => $data[2] ?? 'empty',
                    'error' => $nameValidation['error']
                ]);
                $output->error("Invalid name: " . $nameValidation['error'], ErrorCodes::INVALID_NAME, 400);
                exit(1);
            }
            $name = $nameValidation['value'];
        }
        $searchTerm = $name ?? null;

        if ($results = $this->contactRepository->searchContacts($searchTerm)) {
            if ($output->isJsonMode()) {
                $output->success("Found " . count($results) . " contact(s)", [
                    'search_term' => $searchTerm,
                    'count' => count($results),
                    'contacts' => $results
                ]);
            } else {
                echo "Search Results:\n";
                foreach ($results as $contact) {
                    echo "\t" . $contact['name'] . " - " . ($contact['http'] ?? $contact['tor'] ?? 'No address') . " (" . $contact['status'] . ")\n";
                }
                echo "Found " . count($results) . " contact(s)\n";
            }
        } else{
            $output->success("No contacts found", [
                'search_term' => $searchTerm,
                'count' => 0,
                'contacts' => []
            ], "No contacts match the search criteria");
        }
    }

    /**
     * View contact details
     *
     * @param array $data Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function viewContact(array $data, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // View contact information
        $amountValidation = $this->inputValidator->validateArgvAmount($data, 3);
        if (!$amountValidation['valid']) {
            $this->secureLogger->warning("Invalid parameter amount", [
                'value' => $data,
                'error' => $amountValidation['error']
            ]);
            $output->error("Invalid parameter amount: " . $amountValidation['error'], ErrorCodes::INVALID_PARAMS, 400);
            exit(0);
        }

        if ($this->transportUtility->isAddress($data[2])) {
            $addressValidation = $this->inputValidator->validateAddress($data[2] ?? '');
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $data[2] ?? 'empty',
                    'error' => $addressValidation['error']
                ]);
                $output->error("Invalid Address: " . $addressValidation['error'], ErrorCodes::INVALID_ADDRESS, 400);
                exit(1);
            }
            $address = $addressValidation['value'];
            $transportIndex = $this->transportUtility->determineTransportType($address);
            $contactResult = $this->contactRepository->getContactByAddress($transportIndex, $address);
        } else{
            // Check if the name yields an address
            $contactResult = $this->lookupByName($data[2]);
        }

        if ($contactResult) {
            if ($output->isJsonMode()) {
                $output->success("Contact found", [
                    'contact' => [
                        'name' => $contactResult['name'] ?? null,
                        'http' => $contactResult['http'] ?? null,
                        'tor' => $contactResult['tor'] ?? null,
                        'pubkey' => $contactResult['pubkey'] ?? null,
                        'status' => $contactResult['status'] ?? null,
                        'fee_percent' => isset($contactResult['fee_percent']) ? $contactResult['fee_percent'] / Constants::FEE_CONVERSION_FACTOR : null,
                        'credit_limit' => isset($contactResult['credit_limit']) ? $contactResult['credit_limit'] / Constants::CREDIT_CONVERSION_FACTOR : null,
                        'currency' => $contactResult['currency'] ?? null
                    ]
                ]);
            } else {
                echo "Contact Details:\n";
                echo "\tName: " . ($contactResult['name'] ?? 'N/A') . "\n";
                if (isset($contactResult['http'])) echo "\tHTTP: " . $contactResult['http'] . "\n";
                if (isset($contactResult['tor'])) echo "\tTor: " . $contactResult['tor'] . "\n";
                echo "\tStatus: " . ($contactResult['status'] ?? 'N/A') . "\n";
                if (isset($contactResult['fee_percent'])) echo "\tFee: " . ($contactResult['fee_percent'] / Constants::FEE_CONVERSION_FACTOR) . "%\n";
                if (isset($contactResult['credit_limit'])) echo "\tCredit Limit: " . ($contactResult['credit_limit'] / Constants::CREDIT_CONVERSION_FACTOR) . "\n";
                if (isset($contactResult['currency'])) echo "\tCurrency: " . $contactResult['currency'] . "\n";
            }
        } else {
            $output->error("Contact not found", ErrorCodes::CONTACT_NOT_FOUND, 404, ['query' => $data[2] ?? null]);
        }
    }

    // =========================================================================
    // CONTACT EXISTENCE CHECKS
    // =========================================================================

    /**
     * Check if contact exists
     *
     * @param string $address Contact address
     * @return bool True if exists
     */
    public function contactExists(string $address): bool {
        $transportIndex = $this->transportUtility->determineTransportType($address);
        return $this->contactRepository->contactExists($transportIndex, $address);
    }

    /**
     * Check if contact exists through pubkey
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if exists
     */
    public function contactExistsPubkey(string $pubkey): bool {
        return $this->contactRepository->contactExistsPubkey($pubkey);
    }

    /**
     * Check if contact is accepted
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if accepted
     */
    public function isAcceptedContactPubkey(string $pubkey): bool {
        return $this->contactRepository->isAcceptedContactPubkey($pubkey);
    }

    /**
     * Check if contact is not blocked
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if not blocked
     */
    public function isNotBlocked(string $pubkey): bool {
        return $this->contactRepository->isNotBlocked($pubkey);
    }

    // =========================================================================
    // CONTACT STATUS MANAGEMENT
    // =========================================================================

    /**
     * Block a contact
     *
     * @param string|null $addressOrName Contact address or name
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     */
    public function blockContact(?string $addressOrName, ?CliOutputManager $output = null): bool {
        $output = $output ?? CliOutputManager::getInstance();

        if ($addressOrName === null) {
            $output->error("Address or name is required", ErrorCodes::MISSING_IDENTIFIER, 400);
            return false;
        }

        // Check if it's a HTTP or Tor address
        if ($this->transportUtility->isAddress($addressOrName)) {
            $addressValidation = $this->inputValidator->validateAddress($addressOrName);
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $addressOrName,
                    'error' => $addressValidation['error']
                ]);
                $output->error("Invalid Address: " . $addressValidation['error'], ErrorCodes::INVALID_ADDRESS, 400);
                return false;
            }
            $address = $addressValidation['value'];
            $transportIndex = $this->transportUtility->determineTransportType($address);
        } else {
            // Check if the name yields an address
            $contact = $this->contactRepository->lookupByName($addressOrName);
            if (!$contact) {
                $output->error("Contact not found with name: " . $addressOrName, ErrorCodes::CONTACT_NOT_FOUND, 404);
                return false;
            }
            $address = $this->transportUtility->fallbackTransportAddress($contact);
            if (!$address) {
                $output->error("Contact has no valid address", ErrorCodes::NO_ADDRESS, 500);
                return false;
            }
            $transportIndex = $this->transportUtility->determineTransportType($address);
        }

        if ($this->contactRepository->blockContact($transportIndex, $address)) {
            $output->success("Contact blocked successfully", [
                'address' => $address,
                'status' => Constants::CONTACT_STATUS_BLOCKED
            ]);
            return true;
        } else {
            $output->error("Failed to block contact", ErrorCodes::BLOCK_FAILED, 500, ['address' => $address]);
            return false;
        }
    }

    /**
     * Unblock a contact
     *
     * @param string|null $addressOrName Contact address or name
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     */
    public function unblockContact(?string $addressOrName, ?CliOutputManager $output = null): bool {
        $output = $output ?? CliOutputManager::getInstance();

        if ($addressOrName === null) {
            $output->error("Address or name is required", ErrorCodes::MISSING_IDENTIFIER, 400);
            return false;
        }

        // Check if it's a HTTP or Tor address
        if ($this->transportUtility->isAddress($addressOrName)) {
            $addressValidation = $this->inputValidator->validateAddress($addressOrName);
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $addressOrName,
                    'error' => $addressValidation['error']
                ]);
                $output->error("Invalid Address: " . $addressValidation['error'], ErrorCodes::INVALID_ADDRESS, 400);
                return false;
            }
            $address = $addressValidation['value'];
        } else {
            // Check if the name yields an address
            $contact = $this->contactRepository->lookupByName($addressOrName);
            if (!$contact) {
                $output->error("Contact not found with name: " . $addressOrName, ErrorCodes::CONTACT_NOT_FOUND, 404);
                return false;
            }
            $address = $this->transportUtility->fallbackTransportAddress($contact);
            if (!$address) {
                $output->error("Contact has no valid address", ErrorCodes::NO_ADDRESS, 500);
                return false;
            }
        }

        $transportIndex = $this->transportUtility->determineTransportType($address);

        if ($this->contactRepository->unblockContact($transportIndex, $address)) {
            $output->success("Contact unblocked successfully", [
                'address' => $address,
                'status' => 'unblocked'
            ]);
            return true;
        } else {
            $output->error("Failed to unblock contact", ErrorCodes::UNBLOCK_FAILED, 500, ['address' => $address]);
            return false;
        }
    }

    /**
     * Delete a contact
     *
     * @param string|null $addressOrName Contact address or name
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     */
    public function deleteContact(?string $addressOrName, ?CliOutputManager $output = null): bool {
        $output = $output ?? CliOutputManager::getInstance();

        if ($addressOrName === null) {
            $output->error("Address or name is required", ErrorCodes::MISSING_IDENTIFIER, 400);
            return false;
        }

        // Check if it's a HTTP or Tor address
        if ($this->transportUtility->isAddress($addressOrName)) {
            $addressValidation = $this->inputValidator->validateAddress($addressOrName);
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $addressOrName,
                    'error' => $addressValidation['error']
                ]);
                $output->error("Invalid Address: " . $addressValidation['error'], ErrorCodes::INVALID_ADDRESS, 400);
                return false;
            }
            $address = $addressValidation['value'];
        } else {
            // Check if the name yields an address
            $contact = $this->contactRepository->lookupByName($addressOrName);
            if (!$contact) {
                $output->error("Contact not found with name: " . $addressOrName, ErrorCodes::CONTACT_NOT_FOUND, 404);
                return false;
            }
            $address = $this->transportUtility->fallbackTransportAddress($contact);
            if (!$address) {
                $output->error("Contact has no valid address", ErrorCodes::NO_ADDRESS, 500);
                return false;
            }
        }

        $pubkey = $this->getContactPubkey($address);

        if ($this->contactRepository->deleteContact($pubkey) && $this->addressRepository->deleteByPubkey($pubkey) && $this->balanceRepository->deleteByPubkey($pubkey)) {
            $output->success("Contact deleted successfully", [
                'address' => $address,
                'deleted' => true
            ]);
            return true;
        } else {
            $output->error("Failed to delete contact", ErrorCodes::DELETE_FAILED, 500, ['address' => $address]);
            return false;
        }
    }

    // =========================================================================
    // CONTACT UPDATES
    // =========================================================================

    /**
     * Update specific contact fields through CLI interaction
     *
     * @param array $argv Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function updateContact(array $argv, ?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        $address = $argv[2] ?? null;
        $field = isset($argv[3]) ? strtolower($argv[3]) : null;
        $value = $argv[4] ?? null;
        $value2 = $argv[5] ?? null;
        $value3 = $argv[6] ?? null;

        // Validate address
        if (!$address) {
            $output->error("Address is required", ErrorCodes::MISSING_ADDRESS, 400);
            return;
        }
        $transportIndex = $this->transportUtility->determineTransportType($address);
        $contact = $this->contactRepository->lookupByAddress($transportIndex,$address);
        if (!$contact) {
            // Try by name
            $contact = $this->contactRepository->lookupByName($address);
        }

        if (!$contact) {
            $output->error("Contact not found: $address", ErrorCodes::CONTACT_NOT_FOUND, 404);
            return;
        }

        // Validate field
        if (!in_array($field, ['name', 'fee', 'credit', 'all'])) {
            $output->error("Invalid field. Must be one of: name, fee, credit, all", ErrorCodes::INVALID_FIELD, 400, [
                'valid_fields' => ['name', 'fee', 'credit', 'all']
            ]);
            return;
        }

        // Validate values
        if (!$value || ($field === 'all' && (!$value2 || !$value3))) {
            $output->error("Insufficient parameters for update", ErrorCodes::MISSING_PARAMS, 400, [
                'field' => $field,
                'usage' => $field === 'all'
                    ? 'update [address] all [name] [fee] [credit]'
                    : "update [address] $field [value]"
            ]);
            return;
        }

        // Build update fields
        $updateFields = [];
        $updateData = ['address' => $address, 'field' => $field];

        if ($field === 'name') {
            $updateFields['name'] = $value;
            $updateData['name'] = $value;
        } elseif ($field === 'fee') {
            $updateFields['fee_percent'] = $value * Constants::FEE_CONVERSION_FACTOR;
            $updateData['fee'] = $value;
        } elseif ($field === 'credit') {
            $updateFields['credit_limit'] = $value * Constants::CREDIT_CONVERSION_FACTOR;
            $updateFields['currency'] = Constants::TRANSACTION_DEFAULT_CURRENCY;
            $updateData['credit'] = $value;
        } elseif ($field === 'all') {
            $updateFields['name'] = $value;
            $updateFields['fee_percent'] = $value2 * Constants::FEE_CONVERSION_FACTOR;
            $updateFields['credit_limit'] = $value3 * Constants::CREDIT_CONVERSION_FACTOR;
            $updateFields['currency'] = Constants::TRANSACTION_DEFAULT_CURRENCY;
            $updateData['name'] = $value;
            $updateData['fee'] = $value2;
            $updateData['credit'] = $value3;
        }

        // Perform update
        if ($this->contactRepository->updateContactFields($contact['pubkey'], $updateFields)) {
            $output->success("Contact updated successfully", $updateData);
        } else {
            $output->error("Failed to update contact", ErrorCodes::UPDATE_FAILED, 500, $updateData);
        }
    }

    // =========================================================================
    // REPOSITORY WRAPPERS
    // =========================================================================

    /**
     * Get all contact addresses
     *
     * @param string|null $exclude Address to exclude
     * @return array Array of addresses
     */
    public function getAllAddresses(?string $exclude = null): array {
        return $this->addressRepository->getAllAddresses($exclude);
    }

    /**
     * Update contact status
     *
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string $address Contact address
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(string $address, string $status): bool {
        return $this->contactRepository->updateStatus($this->getContactPubkey($address), $status);
    }

    /**
     * Get credit limit for a contact
     *
     * @param string $senderPublicKey Sender's public key
     * @return float Credit limit
     */
    public function getCreditLimit(string $senderPublicKey): float {
        return $this->contactRepository->getCreditLimit($senderPublicKey);
    }

    /**
     * Get contact public key
     *
     * @param string $address Contact address
     * @return string|null Array with pubkey or null
     */
    public function getContactPubkey(string $address): ?string {
        $transportIndex = $this->transportUtility->determineTransportType($address);
        return $this->contactRepository->getContactPubkey($transportIndex, $address);
    }

    /**
     * Check for new contact requests since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewContactRequests($lastCheckTime): bool{
        return $this->contactRepository->checkForNewContactRequests($lastCheckTime);
    }

    /**
     * Get all contacts
     *
     * @return array Array of contacts
     */
    public function getAllContacts(): array {
        return $this->contactRepository->getAllContacts();
    }

    /**
     * Retrieve all contacts pubkeys
     *
     * @return array Array of contacts with only their pubkey
     */
    public function getAllContactsPubkeys(): array {
         return $this->contactRepository->getAllContactsPubkeys();
    }

    /**
     * Retrieve all accepted contacts 
     *
     * @return array Array of accepted contacts
     */
    public function getAcceptedContacts(){
        return $this->contactRepository->getAcceptedContacts();
    }

    /**
     * Get pending contact requests
     *
     * @return array Array of (non-user initiated) pending contacts
     */
    public function getPendingContactRequests(): array {
        return $this->contactRepository->getPendingContactRequests();
    }

        /**
     * Get user initiated pending contact requests
     *
     * @return array Array of user initiated pending contacts
     */
    public function getUserPendingContactRequests(): array{
        return $this->contactRepository->getUserPendingContactRequests();
    }

    /**
     * Get all blocked contacts
     *
     * @return array Array of blocked contacts
     */
    public function getBlockedContacts(): array {
        return $this->contactRepository->getBlockedContacts();
    }

    /**
     * Lookup contact addresses by name
     *
     * @param string $name Contact name
     * @return string|null Contact addresses or null
     */
    public function lookupAddressesByName(string $name): ?string {
        return $this->contactRepository->lookupAddressesByName($name);
    }

    /**
     * Lookup contact name by address
     *
     * @param string|null $transportIndex Address type, i.e. http, tor (null returns null gracefully)
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(?string $transportIndex, string $address): ?string {
        return $this->contactRepository->lookupNameByAddress($transportIndex, $address);
    }

    /**
     * Get all available address types from the database schema
     *
     * @return array Array of address type names (e.g., ['http', 'tor'])
     */
    public function getAllAddressTypes(): array {
        return $this->addressRepository->getAllAddressTypes();
    }
}