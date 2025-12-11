<?php
# Copyright 2025

require_once __DIR__ . '/../cli/CliOutputManager.php';
require_once __DIR__ . '/MessageDeliveryService.php';

/**
 * Contact Service
 *
 * Handles all business logic for contact management.
 * Integrates with MessageDeliveryService for reliable message delivery
 * with tracking, retry logic, and dead letter queue support.
 *
 * @package Services
 */



class ContactService {
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
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact Repository
     * @param AddressRepository $addressRepository Address Repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param InputValidator $inputValidator InputValidator Util
     * @param SecureLogger $secureLogger SecureLogger Util
     * @param UserContext $currentUser Current user data
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
    private function sendContactMessage(string $address, array $payload, ?string $messageId = null): array {
        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // Use sync delivery (async=false) for contact messages to ensure reliability
            return $this->messageDeliveryService->sendMessage(
                'contact',
                $address,
                $payload,
                $messageId,
                false // sync
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
            $output->error("Invalid Address: " . $addressValidation['error'], 'INVALID_ADDRESS', 400);
            exit(1);
        }
        $address = $addressValidation['value'];

        if(in_array($address,$this->currentUser->getUserAddresses())){
            $output->error("Cannot add yourself as a contact", 'SELF_CONTACT', 400);
            exit(1);
        }

        // Validate and sanitize contact name
        $nameValidation =  $this->inputValidator->validateContactName($data[3] ?? '');
        if (!$nameValidation['valid']) {
            $this->secureLogger->warning("Invalid contact name", [
                'name' => $data[3] ?? 'empty',
                'error' => $nameValidation['error']
            ]);
            $output->error("Invalid name: " . $nameValidation['error'], 'INVALID_NAME', 400);
            exit(1);
        }
        $name = $nameValidation['value'];

        // Validate fee percentage
        $feeValidation =  $this->inputValidator->validateFeePercent($data[4] ?? 0);
        if (!$feeValidation['valid']) {
            $this->secureLogger->warning("Invalid fee percentage", [
                'fee' => $data[4] ?? 'empty',
                'error' => $feeValidation['error']
            ]);
            $output->error("Invalid Fee: " . $feeValidation['error'], 'INVALID_FEE', 400);
            exit(1);
        }
        $fee = $feeValidation['value'] * Constants::FEE_CONVERSION_FACTOR;

        // Validate credit limit
        $creditValidation =  $this->inputValidator->validateCreditLimit($data[5] ?? 0);
        if (!$creditValidation['valid']) {
            $this->secureLogger->warning("Invalid credit limit", [
                'credit' => $data[5] ?? 'empty',
                'error' => $creditValidation['error']
            ]);
            $output->error("Invalid credit: " . $creditValidation['error'], 'INVALID_CREDIT', 400);
            exit(1);
        }
        $credit = $creditValidation['value'] * Constants::CREDIT_CONVERSION_FACTOR;

        // Validate currency
        $currencyValidation =  $this->inputValidator->validateCurrency($data[6] ?? 'USD');
        if (!$currencyValidation['valid']) {
            $this->secureLogger->warning("Invalid currency", [
                'currency' => $data[6] ?? 'empty',
                'error' => $currencyValidation['error']
            ]);
            $output->error("Invalid currency: " . $currencyValidation['error'], 'INVALID_CURRENCY', 400);
            exit(1);
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
        if($contact['status'] === 'accepted'){
            $output->error("Contact " . $address . " already exists ", 'CONTACT_EXISTS', 409, ['contact' => $contactData]);
        }
        // Check if contact was blocked
        elseif($contact['status'] === 'blocked'){
            // Contact was blocked after user sent contact request
            if($contact['name']){
                // Unblock contact and add values
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    $output->success("Contact" . $address . "unblocked and updated", $contactData, "Contact unblocked and updated successfully");
                } else{
                    $output->error("Failed to unblock and update contact " . $address, 'UNBLOCK_FAILED', 500, ['contact' => $contactData]);
                }
            }
            // Contact was blocked when user received contact request
            else{
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    // Send message of successful contact acceptance back to original contact requester with tracking
                    $acceptPayload = $this->messagePayload->buildContactIsAccepted($address);
                    $messageId = 'contact-unblock-accept-' . hash('sha256', $address . $contact['pubkey'] . $this->timeUtility->getCurrentMicrotime());
                    $sendResult = $this->sendContactMessage($address, $acceptPayload, $messageId);

                    // For acceptance messages, we update stages based on our local operations (using MessageDeliveryService directly)
                    // Stage progression: pending -> sent -> received (from transport) -> inserted (local) -> completed
                    if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    $output->success("Contact " . $address . " unblocked and added", $contactData, "Contact unblocked and added successfully");
                } else{
                    $output->error("Failed to unblock and add contact " . $address, 'UNBLOCK_ADD_FAILED', 500, ['contact' => $contactData]);
                }
            }
        }
        elseif($contact['status'] === 'pending'){
            // if pending with name (contact was inserted by user for contact request)
            if($contact['name']){
                // This contact was already sent a contact request, but has not yet responded to user (try resynching)
                // Resynch contact using SynchService directly
                $succesfullSynch = Application::getInstance()->services->getSynchService()->synchSingleContact($address, 'SILENT');
                if ($succesfullSynch) {
                    $contactData['status'] = 'accepted';
                    $output->success("Contact request already sent, synched successfully with " . $address, $contactData, "Contact synched");
                } else {
                    $output->info("Contact request already sent, awaiting response from " . $address, $contactData);
                }
            } else{
                // If contact already exists with an address, it's a contact request, skip sending a message
                if ($this->acceptContact($contact['pubkey'], $name, $fee, $credit, $currency)) {
                    // Send message of successful contact acceptance back to original contact requester with tracking
                    $acceptPayload = $this->messagePayload->buildContactIsAccepted($address);
                    $messageId = 'contact-accept-' . hash('sha256', $address . $contact['pubkey'] . $this->timeUtility->getCurrentMicrotime());
                    $sendResult = $this->sendContactMessage($address, $acceptPayload, $messageId);

                    // For acceptance messages, we update stages based on our local operations (using MessageDeliveryService directly)
                    // The acceptance message was sent and our local DB was updated (acceptContact above)
                    // Stage progression: pending -> sent -> received (from transport) -> inserted (local) -> completed
                    if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    $contactData['status'] = 'accepted';
                    $output->success("Contact request accepted from " . $address, $contactData, "Contact accepted successfully");
                }
                else {
                    $output->error("Failed to accept contact request from " . $address, 'ACCEPT_FAILED', 500, ['contact' => $contactData]);
                    exit(1);
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
        $messageId = 'contact-create-' . hash('sha256', $address . $this->currentUser->getPublicKey() . $this->timeUtility->getCurrentMicrotime());

        // Send contact creation request with delivery tracking
        $sendResult = $this->sendContactMessage($address, $payload, $messageId);
        $responseData = $sendResult['response'];

        if (isset($responseData['status'])){
            $senderPublicKey = $responseData['senderPublicKey'];
            // Contact request was received (initial insert on their end as pending, awaiting acceptance)
            if($responseData['status'] === 'received'){
                // Insert contact on our end with returned pubkey as pending (awaiting acceptance)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    // Update delivery stage: received -> inserted -> completed (using MessageDeliveryService directly)
                    // Contact request phase is complete (awaiting acceptance is a separate phase)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    $contactData['status'] = 'pending';
                    $contactData['pubkey'] = $senderPublicKey;
                    $output->success("Contact request sent successfully to " . $address, $contactData, "Contact request sent, awaiting acceptance");
                } else{
                    $output->error("Failed to create contact with " . $address, 'CONTACT_CREATE_FAILED', 500, ['contact' => $contactData]);
                    exit(1);
                }
            }
            // Our contact pubkey exists on their end, but not provided address
            //  we are known under a different address or transport type
            elseif($responseData['status'] === 'updated'){
                $senderAddress = $responseData['senderAddress'];
                $senderPublicKey = $responseData['senderPublicKey'];
                $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
                // Update contact address on our end
                if($this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative)){
                    // Update delivery stage: updated -> inserted -> completed (using MessageDeliveryService directly)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    $contactData['status'] = 'updated';
                    $contactData['updated_address'] = $senderAddress;
                    $output->success("Contact address updated with " . $address, $contactData, "Contact address updated successfully");
                } else{
                    $output->error("Failed to update contact address with " . $address, 'ADDRESS_UPDATE_FAILED', 500, ['contact' => $contactData]);
                }
            }
            // Our contact pubkey and adress both exist on their end (Case when we delete the contact and try re-adding it)
            elseif($responseData['status'] === 'warning'){
                // Insert contact and try re-synching (inquiry about acceptance status)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);

                    // Update delivery stage: warning -> inserted -> completed (using MessageDeliveryService directly)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageAfterLocalInsert('contact', $messageId, true);
                    }

                    // Resynch contact
                    if(Application::getInstance()->services->getSynchService()->synchSingleContact($address, 'SILENT')){
                        $contactData['status'] = 'accepted';
                        $contactData['pubkey'] = $responseData['senderPublicKey'];
                        $output->success("Contact re-added and synched with " . $address, $contactData, "Contact created successfully");
                    } else {
                        $contactData['status'] = 'pending';
                        $output->success("Contact re-added, awaiting sync with " . $address, $contactData, "Contact created, sync pending");
                    }
                }
            }
            // Our contact request could not be processed on their end
            elseif($responseData['status'] === 'rejection'){
                $output->error("Contact request rejected by " . $address . " : " . ($responseData['reason'] ?? 'Unknown reason'), 'CONTACT_REJECTED', 403, [
                    'contact' => $contactData,
                    'response' => $responseData
                ]);
                exit(1);
            }
        } else{
            // No valid response - MessageDeliveryService has already exhausted all retries
            // (retries happen synchronously within sendWithTracking)
            // Tracking results are nested inside 'tracking' key from sendContactMessage
            $trackingResult = $sendResult['tracking'] ?? [];
            $attempts = $trackingResult['attempts'] ?? 'unknown';
            $lastError = $trackingResult['error'] ?? 'No response received';

            $output->error(
                "Failed to reach contact address after " . $attempts . " attempts. " .
                "Address " . $address . " may not exist or is offline.",
                'CONTACT_UNREACHABLE',
                503,
                [
                    'contact' => $contactData,
                    'attempts' => $attempts,
                    'last_error' => $lastError,
                    'moved_to_dlq' => $trackingResult['dlq'] ?? false
                ]
            );
            exit(1);
        }
    }

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
        // Check if contact already exists
        if ($this->contactRepository->contactExistsPubkey($senderPublicKey)) {
            $contactAddresses = $this->addressRepository->lookupByPubkeyHash($senderPublicKeyHash);
            $transportIndex = $this->transportUtility->determineTransportType($senderAddress);
            if($contactAddresses[$transportIndex] === $senderAddress){
                // Address already exists (Not a new contact)
                return $this->contactPayload->buildAlreadyExists($senderAddress);
            } else{
                // Address unknown prior but pubkey exists (known contact, unknown address)
                if($this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative)){
                    return $this->contactPayload->buildUpdated($senderAddress);
                } else{
                    // Unable to update contact
                    return $this->contactPayload->buildRejection($senderAddress);
                }
            }
        } else{
            // Contact request is brand new, no prior users exist in any form
            if($this->contactRepository->addPendingContact($senderPublicKey) && $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative)){
                return $this->contactPayload->buildReceived($senderAddress);
            } else{
                // Unable to insert contact
                return $this->contactPayload->buildRejection($senderAddress);
            }
        }
    }

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
                $output->error("Invalid name: " . $nameValidation['error'], 'INVALID_NAME', 400);
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
            $output->error("Invalid parameter amount: " . $amountValidation['error'], 'INVALID_PARAMS', 400);
            exit(0);
        }

        if ($this->transportUtility->isAddress($data[2])) {
            $addressValidation = $this->inputValidator->validateAddress($data[2] ?? '');
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $data[2] ?? 'empty',
                    'error' => $addressValidation['error']
                ]);
                $output->error("Invalid Address: " . $addressValidation['error'], 'INVALID_ADDRESS', 400);
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
            $output->error("Contact not found", 'CONTACT_NOT_FOUND', 404, ['query' => $data[2] ?? null]);
        }
    }

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

    /**
     * Block a contact
     *
     * @param string|null $address Contact address
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     */
    public function blockContact(?string $address, ?CliOutputManager $output = null): bool {
        $output = $output ?? CliOutputManager::getInstance();

        if ($address === null) {
            $output->error("Address is required", 'MISSING_ADDRESS', 400);
            exit(1);
        }

        $addressValidation =  $this->inputValidator->validateAddress($address);
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $address,
                'error' => $addressValidation['error']
            ]);
            $output->error("Invalid Address: " . $addressValidation['error'], 'INVALID_ADDRESS', 400);
            exit(1);
        }
        $address = $addressValidation['value'];
        $transportIndex = $this->transportUtility->determineTransportType($address);

        if ($this->contactRepository->blockContact($transportIndex, $address)) {
            $output->success("Contact blocked successfully", [
                'address' => $address,
                'status' => 'blocked'
            ]);
            return true;
        } else {
            $output->error("Failed to block contact", 'BLOCK_FAILED', 500, ['address' => $address]);
            return false;
        }
    }

    /**
     * Unblock a contact
     *
     * @param string|null $address Contact address
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     */
    public function unblockContact(?string $address, ?CliOutputManager $output = null): bool {
        $output = $output ?? CliOutputManager::getInstance();

        if ($address === null) {
            $output->error("Address is required", 'MISSING_ADDRESS', 400);
            exit(1);
        }

        $addressValidation =  $this->inputValidator->validateAddress($address);
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $address,
                'error' => $addressValidation['error']
            ]);
            $output->error("Invalid Address: " . $addressValidation['error'], 'INVALID_ADDRESS', 400);
            exit(1);
        }
        $address = $addressValidation['value'];

        $transportIndex = $this->transportUtility->determineTransportType($address);

        if ($this->contactRepository->unblockContact($transportIndex, $address)) {
            $output->success("Contact unblocked successfully", [
                'address' => $address,
                'status' => 'unblocked'
            ]);
            return true;
        } else {
            $output->error("Failed to unblock contact", 'UNBLOCK_FAILED', 500, ['address' => $address]);
            return false;
        }
    }

    /**
     * Delete a contact
     *
     * @param string|null $address Contact address
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     */
    public function deleteContact(?string $address, ?CliOutputManager $output = null): bool {
        $output = $output ?? CliOutputManager::getInstance();

        if ($address === null) {
            $output->error("Address is required", 'MISSING_ADDRESS', 400);
            exit(1);
        }

        $addressValidation =  $this->inputValidator->validateAddress($address);
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $address,
                'error' => $addressValidation['error']
            ]);
            $output->error("Invalid Address: " . $addressValidation['error'], 'INVALID_ADDRESS', 400);
            exit(1);
        }
        $address = $addressValidation['value'];
        $pubkey = $this->getContactPubkey($address);

        if ($this->contactRepository->deleteContact($pubkey) && $this->addressRepository->deleteByPubkey($pubkey) && $this->balanceRepository->deleteByPubkey($pubkey)) {
            $output->success("Contact deleted successfully", [
                'address' => $address,
                'deleted' => true
            ]);
            return true;
        } else {
            $output->error("Failed to delete contact", 'DELETE_FAILED', 500, ['address' => $address]);
            return false;
        }
    }

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
            $output->error("Address is required", 'MISSING_ADDRESS', 400);
            return;
        }
        $transportIndex = $this->transportUtility->determineTransportType($address);
        $contact = $this->contactRepository->lookupByAddress($transportIndex,$address);
        if (!$contact) {
            // Try by name
            $contact = $this->contactRepository->lookupByName($address);
        }

        if (!$contact) {
            $output->error("Contact not found: $address", 'CONTACT_NOT_FOUND', 404);
            return;
        }

        // Validate field
        if (!in_array($field, ['name', 'fee', 'credit', 'all'])) {
            $output->error("Invalid field. Must be one of: name, fee, credit, all", 'INVALID_FIELD', 400, [
                'valid_fields' => ['name', 'fee', 'credit', 'all']
            ]);
            return;
        }

        // Validate values
        if (!$value || ($field === 'all' && (!$value2 || !$value3))) {
            $output->error("Insufficient parameters for update", 'MISSING_PARAMS', 400, [
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
            $output->error("Failed to update contact", 'UPDATE_FAILED', 500, $updateData);
        }
    }

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
     * @param string $transportIndex Address type, i.e. http, tor
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(string $transportIndex, string $address): ?string {
        return $this->contactRepository->lookupNameByAddress($transportIndex, $address);
    }
}