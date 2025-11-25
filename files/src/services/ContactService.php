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
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact Repository
     * @param AddressRepository $addressRepository Address Repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param InputValidator $inputValidator InputValidator Util
     * @param SecureLogger $secureLogger SecureLogger Util
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        AddressRepository $addressRepository,
        BalanceRepository $balanceRepository,
        UtilityServiceContainer $utilityContainer,
        InputValidator $inputValidator,
        SecureLogger $secureLogger,
        UserContext $currentUser
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

        require_once '/etc/eiou/src/schemas/payloads/ContactPayload.php';
        $this->contactPayload = new ContactPayload($this->currentUser,$this->utilityContainer);

        require_once '/etc/eiou/src/schemas/payloads/MessagePayload.php';
        $this->messagePayload = new MessagePayload($this->currentUser,$this->utilityContainer);
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
        // Validate and sanitize address
        $addressValidation =  $this->inputValidator->validateAddress($data[2] ?? '');
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            output("Invalid Address: " . $addressValidation['error'],'ERROR');
            exit(1);
        }
        $address = $addressValidation['value'];

        if(in_array($address,$this->currentUser->getUserAddresses())){
            output("Cannot add yourself as a contact");
            exit(1);
        }

        // Validate and sanitize contact name
        $nameValidation =  $this->inputValidator->validateContactName($data[3] ?? '');
        if (!$nameValidation['valid']) {
            $this->secureLogger->warning("Invalid contact name", [
                'name' => $data[3] ?? 'empty',
                'error' => $nameValidation['error']
            ]);
            output("Invalid name: " . $nameValidation['error'],'ERROR');
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
            output("Invalid Fee: " .$feeValidation['error'], 'ERROR');
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
            output("Invalid credit: " . $creditValidation['error'], 'ERROR');
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
            output("Invalid currency: " . $currencyValidation['error'], 'ERROR');
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
            // Contact was blocked after user sent contact request
            if($contact['name']){
                // Unblock contact and add values
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    output(outputContactUnblockedAndOverwritten());
                } else{
                    output(outputContactUnblockedAndOverwrittenFailure());
                }
            }
            // Contact was blocked when user received contact request
            else{
                if($this->contactRepository->updateUnblockContact($contact['pubkey'], $name, $fee, $credit, $currency)){
                    output(outputContactUnblockedAndAdded());
                } else{
                    output(outputContactUnblockedAndAddedFailure());
                }
                // Send message of successful contact acceptance back to original contact requester
                $this->transportUtility->send($address, $this->messagePayload->buildContactIsAccepted($address));
            }
        }
        elseif($contact['status'] === 'pending'){
            // if pending with name (contact was inserted by user for contact request)
            if($contact['name']){
                // This contact was already sent a contact request, but has not yet responded to user (try resynching)
                output(returnContactRequestAlreadyInserted());
                // Resynch contact using SynchService directly
                $succesfullSynch = Application::getInstance()->services->getSynchService()->synchSingleContact($address, 'ECHO');
            } else{
                // If contact already exists with an address, it's a contact request, skip sending a message
                if ($this->acceptContact($contact['pubkey'], $name, $fee, $credit, $currency)) {
                    // Send message of successful contact acceptance back to original contact requester
                    $this->transportUtility->send($address, $this->messagePayload->buildContactIsAccepted($address));
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
        $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($address);
        // TO DO CHECK IF exists address type


        // Check if the response indicates successful acceptance
        $responseData = json_decode($this->transportUtility->send($address, $payload), true);
        if (isset($responseData['status'])){
            $senderPublicKey = $responseData['senderPublicKey'];
            // Contact request was received (initial insert on their end as pending, awaiting acceptance)
            if($responseData['status'] === 'received'){
                // Insert contact on our end with returned pubkey as pending (awaiting acceptance)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);
                    output(returnContactCreationSuccessful());
                } else{
                    output(returnContactCreationFailed());
                    exit(1);
                }
            } 
            // Our contact pubkey exists on their end, but not provided address 
            //  we are known under a different address or transport type
            elseif($responseData['status'] === 'updated'){
                if($this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative)){
                    output(outputContactUpdatedAddress());
                } else{
                    output(outputContactUpdatedAddressFailure());
                }
            } 
            // Our contact pubkey and adress both exist on their end (Case when we delete the contact and try re-adding it)
            elseif($responseData['status'] === 'warning'){
                // Insert contact and try re-synching (inquiry about acceptance status)
                if ($this->contactRepository->insertContact($senderPublicKey, $name, $fee, $credit, $currency)) {
                    $this->addressRepository->insertAddress($senderPublicKey, $transportIndexAssociative);
                    $this->balanceRepository->insertInitialContactBalances($senderPublicKey, $currency);
                    // Resynch contact
                    if(Application::getInstance()->services->getSynchService()->synchSingleContact($address, 'SILENT')){
                        // TO DO ALSO SYNCH BALANCES
                        output(returnContactCreationSuccessful());
                    }
                }
            } 
            // Our contact request could not be processed on their end
            elseif($responseData['status'] === 'rejection'){
                // If not accepted, show error and display the response
                output(returnContactRejected($responseData));
                output(outputFailedContactRequest($payload), 'SILENT');
                exit(1);
            }
        } else{
            // Case when sending to an adress that does not exist at all (or is experiencing downtime)
            output(outputFailedContactInteraction());
            output(outputFailedContactRequest($payload), 'SILENT');
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

        if(isset($data[2])){
            $nameValidation =  $this->inputValidator->validateContactName($data[2]);
            if (!$nameValidation['valid']) {
                $this->secureLogger->warning("Invalid contact name", [
                    'name' => $data[2] ?? 'empty',
                    'error' => $nameValidation['error']
                ]);
                output("Invalid name: " . $nameValidation['error'],'ERROR');
                exit(1);
            }
            $name = $nameValidation['value'];
        }
        $searchTerm = $name ?? null;

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
        $amountValidation = $this->inputValidator->validateArgvAmount($data, 3);
        if (!$amountValidation['valid']) {
            $this->secureLogger->warning("Invalid parameter amount", [
                'value' => $data,
                'error' => $amountValidation['error']
            ]);
            output(("Invalid parameter amount: " . $amountValidation['error']),'ERROR');
            exit(0);
        }

        if ($this->transportUtility->isAddress($data[2])) {
            $addressValidation = $this->inputValidator->validateAddress($data[2] ?? '');
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $data[2] ?? 'empty',
                    'error' => $addressValidation['error']
                ]);
                output("Invalid Address: " . $addressValidation['error'],'ERROR');
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
            output(returnContactDetails($contactResult));
        } else {
            output(returnContactNotFound());
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
    public function isAcceptedContact(string $pubkey): bool {
        return $this->contactRepository->isAcceptedContact($pubkey);
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
     * @param string $address Contact address
     * @return bool Success status
     */
    public function blockContact(string $address): bool {
        $addressValidation =  $this->inputValidator->validateAddress($address);
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            output("Invalid Address: " . $addressValidation['error'],'ERROR');
            exit(1);
        }
        $address = $addressValidation['value'];
        $transportIndex = $this->transportUtility->determineTransportType($address);
        return $this->contactRepository->blockContact($transportIndex, $address);
    }

    /**
     * Unblock a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function unblockContact(string $address): bool {
        $addressValidation =  $this->inputValidator->validateAddress($address);
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            output("Invalid Address: " . $addressValidation['error'],'ERROR');
            exit(1);
        }
        $address = $addressValidation['value'];
        
        $transportIndex = $this->transportUtility->determineTransportType($address);
        return $this->contactRepository->unblockContact($transportIndex, $address);
    }

    /**
     * Delete a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function deleteContact(string $address): bool {
        $addressValidation =  $this->inputValidator->validateAddress($address);
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            output("Invalid Address: " . $addressValidation['error'],'ERROR');
            exit(1);
        }
        $address = $addressValidation['value'];
        $pubkey = $this->contactRepository->getContactPubkey($address);
        $deletedContact = $this->contactRepository->deleteContact($pubkey);
        $deletedAddress = $this->addressRepository->deleteByPubkey($pubkey);
        $deletedBalance = $this->balanceRepository->deleteByPubkey($pubkey);
        return $deletedContact && $deletedAddress && $deletedBalance;
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
        return $this->contactRepository->updateStatus($this->contactRepository->getContactPubkey($address), $status);
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
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(string $address): ?string {
        return $this->contactRepository->lookupNameByAddress($address);
    }
}