<?php
# Copyright 2025

/**
 * Contact Service
 *
 * Handles all business logic for contact management.
 *
 * @package Services
 */



class ContactService {
    /**
     * @var ContactRepository Contact Repository instance
     */
    private ContactRepository $contactRepository;

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
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact Repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
        ) 
    {
        $this->contactRepository = $contactRepository;
        $this->utilityContainer = $utilityContainer;
        $this->currentUser = $currentUser;
        $this->transportUtility = $this->utilityContainer->getTransportUtility($this->currentUser);

        require_once '/etc/eiou/src/schemas/payloads/ContactPayload.php';
        $this->contactPayload = new ContactPayload($this->currentUser,$this->utilityContainer);
    }

    /**
     * Add a contact
     *
     * This method validates all input data using InputValidator and Security classes
     * to ensure data integrity and prevent injection attacks.
     *
     * @param array $data Command line arguments
     * @return void
     */
    public function addContact(array $data): void {
        // Import security and validation classes
        require_once __DIR__ . '/../utils/InputValidator.php';
        require_once __DIR__ . '/../utils/Security.php';

        // Validate and sanitize address
        $addressValidation = InputValidator::validateAddress($data[2] ?? '');
        if (!$addressValidation['valid']) {
            SecureLogger::warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            output("Invalid Address: " . $addressValidation['error'],'ERROR');
            exit(1);
        }
        $address = $addressValidation['value'];

        // Validate and sanitize contact name
        $nameValidation = InputValidator::validateContactName($data[3] ?? '');
        if (!$nameValidation['valid']) {
            SecureLogger::warning("Invalid contact name", [
                'name' => $data[3] ?? 'empty',
                'error' => $nameValidation['error']
            ]);
            output("Invalid name: " . $nameValidation['error'],'ERROR');
            exit(1);
        }
        $name = $nameValidation['value'];

        // Validate fee percentage
        $feeValidation = InputValidator::validateFeePercent($data[4] ?? 0);
        if (!$feeValidation['valid']) {
            SecureLogger::warning("Invalid fee percentage", [
                'fee' => $data[4] ?? 'empty',
                'error' => $feeValidation['error']
            ]);
            output("Invalid Fee: " .$feeValidation['error'], 'ERROR');
            exit(1);
        }
        $fee = $feeValidation['value'] * Constants::FEE_CONVERSION_FACTOR;

        // Validate credit limit
        $creditValidation = InputValidator::validateCreditLimit($data[5] ?? 0);
        if (!$creditValidation['valid']) {
            SecureLogger::warning("Invalid credit limit", [
                'credit' => $data[5] ?? 'empty',
                'error' => $creditValidation['error']
            ]);
            output("Invalid credit: " . $creditValidation['error'], 'ERROR');
            exit(1);
        }
        $credit = $creditValidation['value'] * Constants::CREDIT_CONVERSION_FACTOR;

        // Validate currency
        $currencyValidation = InputValidator::validateCurrency($data[6] ?? 'USD');
        if (!$currencyValidation['valid']) {
            SecureLogger::warning("Invalid currency", [
                'currency' => $data[6] ?? 'empty',
                'error' => $currencyValidation['error']
            ]);
            output("Invalid currency: " . $currencyValidation['error'], 'ERROR');
            exit(1);
        }
        $currency = $currencyValidation['value'];

        // Log successful validation
        SecureLogger::info("Contact addition validated", [
            'address_type' => $addressValidation['type'] ?? 'unknown',
            'name_length' => strlen($name)
        ]);

        // Get contact if exists in database in some form
        $contact = $this->contactRepository->getContactByAddress($address);

        if($contact){
            $this->handleExistingContact($contact, $address, $name, $fee, $credit, $currency);
        } else{
            $this->handleNewContact($address, $name, $fee, $credit, $currency);
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
     */
    private function handleExistingContact(array $contact, string $address, string $name, float $fee, float $credit, string $currency): void {
        // Check if contact is already an accepted contact
        if($contact['status'] === 'accepted'){
            output(returnContactExists(),'WARNING');
        }
        // Check if contact was blocked
        elseif($contact['status'] === 'blocked'){
            // Contact was blocked after user accepted contact request
            if($contact['name']){
                // Unblock contact and add values
                if($this->contactRepository->updateUnblockContact($address, $name, $fee, $credit, $currency)){
                    output(outputContactUnblockedAndOverwritten());
                } else{
                    output(outputContactUnblockedAndOverwrittenFailure());
                }
            }
            // Contact was blocked when user received contact request
            else{
                if($this->contactRepository->updateUnblockContact($address, $name, $fee, $credit, $currency)){
                    output(outputContactUnblockedAndAdded());
                } else{
                    output(outputContactUnblockedAndAddedFailure());
                }
                // Send message of successful contact acceptance back to original contact requester
                $this->transportUtility->send($address, $this->contactPayload->buildAccepted($address));
            }
        }
        elseif($contact['status'] === 'pending'){
            // if pending with name
            if($contact['name']){
                // This contact was already sent a contact request, but has not yet responded to user (try resynching)
                output(returnContactRequestAlreadyInserted());
                // Resynch contact using SynchService directly
                $succesfullSynch = ServiceContainer::getInstance()->getSynchService()->synchSingleContact($address, 'ECHO');
            } else{
                // If contact already exists with an address, it's a contact request, skip sending a message
                if ($this->acceptContact($address, $name, $fee, $credit, $currency)) {
                    // Send message of successful contact acceptance back to original contact requester
                    $this->transportUtility->send($address, $this->contactPayload->buildAccepted($address));
                    output(outputSendContactAcceptedSuccesfullyMessage($address),'SILENT');
                    output(returnContactAccepted());
                }
                else {
                    output(returnContactAcceptanceFailed(), 'ERROR');
                    exit(1);
                }
            }
        }
    }

    /**
     * Handle new contact creation
     *
     * @param string $address Contact address
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     */
    private function handleNewContact(string $address, string $name, float $fee, float $credit, string $currency): void {
        // Build the payload array
        $payload = $this->contactPayload->buildCreateRequest($address);

        // Check if the response indicates successful acceptance
        $responseData = json_decode($this->transportUtility->send($address, $payload), true);

        if (isset($responseData['status']) && ($responseData['status'] === 'accepted' || $responseData['status'] === 'warning')) {
            // Check if the response status is a warning
            if ($responseData['status'] === 'warning') {
                output(returnContactCreationWarning($responseData['message']));
                // Insert into database
                if ($this->contactRepository->insertContact($address, $responseData['senderPublicKey'], $name, $fee, $credit, $currency)) {
                    // Sync newly created contact using SynchService directly (default SILENT)
                    if(ServiceContainer::getInstance()->getSynchService()->synchSingleContact($address, 'SILENT')){
                        output(returnContactCreationSuccessful());
                    }
                }
            } else{
                // Insert into database
                if ($this->contactRepository->insertContact($address, $responseData['senderPublicKey'], $name, $fee, $credit, $currency)) {
                    output(returnContactCreationSuccessful());
                } else{
                    output(returnContactCreationFailed());
                    exit(1);
                }
            }
        } else {
            // If not accepted, show error and display the response
            output(returnContactRejected($responseData));
            output(outputFailedContactRequest($payload), 'SILENT');
            exit(1);
        }
    }

    /**
     * Accept a contact request
     *
     * @param string $address Contact address
     * @param string $name Contact name
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @param string $currency Currency code
     * @return bool Success status
     */
    public function acceptContact(string $address, string $name, float $fee, float $credit, string $currency): bool {
        return $this->contactRepository->acceptContact($address, $name, $fee, $credit, $currency);
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

        // Check if contact already exists
        if ($this->contactRepository->contactExistsPubkey($senderPublicKey)) {
            $contactInfo = $this->contactRepository->lookupByPubkey($senderPublicKey);
            $transportIndex = $this->transportUtility->determineDatabaseIndexTransportType($senderAddress);
            if($contactInfo[$transportIndex] === $senderAddress){
                // Address already exists
                return $this->contactPayload->buildAlreadyExists($senderAddress);
            } else{
                // Add unknown prior address to contact
                if($this->contactRepository->updateContactFields($senderPublicKey,[$transportIndex => $senderAddress])){
                    return $this->contactPayload->buildUpdated($senderAddress);
                } else{
                    return $this->contactPayload->buildRejection($senderAddress);
                }
            }
        } else{
            return $this->contactRepository->addPendingContact($senderAddress, $senderPublicKey);
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
        $lookupResultByAddress = $this->lookupByAddress($request);
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
        if (isset($lookupResult['address'])){
            $data['receiverAddress'] = $lookupResult['address'];
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
     * @param string $address Contact address
     * @return array|null Contact data or null
     */
    public function lookupByAddress(string $address): ?array {
        return $this->contactRepository->lookupByAddress($address);
    }

    /**
     * Search contacts
     *
     * @param array $data Command line arguments
     * @return void
     */
    public function searchContacts(array $data): void {
        // Lookup contact based on their name
        $searchTerm = $data[2] ?? null;

        if ($results = $this->contactRepository->searchContacts($searchTerm)) {
            output(returnContactSearchResults($results));
        } else{
            output(returnContactSearchNoResults());
        }
    }

    /**
     * View contact details
     *
     * @param array $data Command line arguments
     * @return void
     */
    public function viewContact(array $data): void {
        // View contact information
        if (count($data) >= 3) {
            // Check if is a HTTP or TOR address
            if ($this->transportUtility->isAddress($data[2])) {
                $address = $data[2];
            } else{
                // Check if the name yields an address
                $contactResult = $this->lookupByName($data[2]);
                $address = $contactResult['address'] ?? null;
            }

            if ($result = $this->contactRepository->getContactByAddress($address)) {
                output(returnContactDetails($result));
            } else {
                output(returnContactNotFound());
            }
        } else {
            output(returnContactReadInvalidInput());
            exit(1);
        }
    }

    /**
     * Check if contact exists
     *
     * @param string $address Contact address
     * @return bool True if exists
     */
    public function contactExists(string $address): bool {
        return $this->contactRepository->contactExists($address);
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
     * @param string $address Contact address
     * @return bool True if accepted
     */
    public function isAcceptedContact(string $address): bool {
        return $this->contactRepository->isAcceptedContact($address);
    }

    /**
     * Check if contact is not blocked
     *
     * @param string $address Contact address
     * @return bool True if not blocked
     */
    public function isNotBlocked(string $address): bool {
        return $this->contactRepository->isNotBlocked($address);
    }

    /**
     * Block a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function blockContact(string $address): bool {
        return $this->contactRepository->blockContact($address);
    }

    /**
     * Unblock a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function unblockContact(string $address): bool {
        return $this->contactRepository->unblockContact($address);
    }

    /**
     * Delete a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function deleteContact(string $address): bool {
        return $this->contactRepository->deleteContact($address);
    }

    /**
     * Update specific contact fields through CLI interaction
     *
     * @param array $argv Command line arguments
     */
    public function updateContact(array $argv) {
        return $this->contactRepository->updateContact($argv);
    }

    /**
     * Get all contact addresses
     *
     * @param string|null $exclude Address to exclude
     * @return array Array of addresses
     */
    public function getAllAddresses(?string $exclude = null): array {
        return $this->contactRepository->getAllAddresses($exclude);
    }

    /**
     * Update contact status
     *
     * @param string $address Contact address
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(string $address, string $status): bool {
        return $this->contactRepository->updateStatus($address, $status);
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
     * @return array|null Array with pubkey or null
     */
    public function getContactPubkey(string $address): ?array {
        return $this->contactRepository->getContactPubkey($address);
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
     * Lookup contact address by name
     *
     * @param string $name Contact name
     * @return string|null Contact address or null
     */
    public function lookupAddressByName(string $name): ?string {
        return $this->contactRepository->lookupAddressByName($name);
    }

    /**
     * Lookup contact name by address
     *
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(string $address): ?string {
        return $this->contactRepository->lookupNameByAddress($address);
    }
}
