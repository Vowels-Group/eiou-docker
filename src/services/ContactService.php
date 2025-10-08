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
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $repository;

    /**
     * @var array Current user data
     */
    private array $currentUser;

    /**
     * Constructor
     *
     * @param ContactRepository $repository Contact repository
     * @param array $currentUser Current user data
     */
    public function __construct(ContactRepository $repository, array $currentUser = []) {
        $this->repository = $repository;
        $this->currentUser = $currentUser;
    }

    /**
     * Add a contact
     *
     * @param array $data Command line arguments
     * @return void
     */
    public function addContact(array $data): void {
        // Assign command line arguments to variables
        $address = filter_var($data[2], FILTER_VALIDATE_REGEXP, array("options"=>array("regexp"=>"/^[a-zA-Z0-9]{56}$|^[a-z2-7]{56}\.onion$|^https?:\/\/[a-zA-Z0-9.-]+/")));
        $name = htmlspecialchars(trim($data[3]), ENT_QUOTES, 'UTF-8');
        $fee = filter_var($data[4], FILTER_VALIDATE_FLOAT) * 100;
        $credit = filter_var($data[5], FILTER_VALIDATE_FLOAT) * 100;
        $currency = htmlspecialchars(trim($data[6]), ENT_QUOTES, 'UTF-8');

        // Validate input
        if(!$address || !$name || !is_numeric($fee) || !is_numeric($credit) || !$currency) {
            output(returnContactAddInvalidInput(), 'ERROR');
            exit(1);
        }

        // Get contact if exists in database in some form
        $contact = $this->repository->getContactByAddress($address);

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
                if($this->repository->updateUnblockContact($address, $name, $fee, $credit, $currency)){
                    output(outputContactUnblockedAndOverwritten());
                } else{
                    output(outputContactUnblockedAndOverwrittenFailure());
                }
            }
            // Contact was blocked when user received contact request
            else{
                if($this->repository->updateUnblockContact($address, $name, $fee, $credit, $currency)){
                    output(outputContactUnblockedAndAdded());
                } else{
                    output(outputContactUnblockedAndAddedFailure());
                }
                // Send message of successful contact acceptance back to original contact requester
                send($address, buildContactIsAcceptedPayload($address));
            }
        }
        elseif($contact['status'] === 'pending'){
            // if pending with name
            if($contact['name']){
                // This contact was already sent a contact request, but has not yet responded to user (try resynching)
                output(returnContactRequestAlreadyInserted());
                $succesfullSynch = synchContact($address,'ECHO'); // resynch contact
            } else{
                // If contact already exists with an address, it's a contact request, skip sending a message
                if ($this->acceptContact($address, $name, $fee, $credit, $currency)) {
                    // Send message of successful contact acceptance back to original contact requester
                    send($address, buildContactIsAcceptedPayload($address));
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
        $payload = createContactPayload();

        // Determine if tor, else add http hostname
        if (preg_match('/\.onion$/', $address)) {
            $payload['senderAddress'] = $this->currentUser['torAddress'];
        } else {
            $payload['senderAddress'] = $this->currentUser['hostname'];
        }

        // Check if the response indicates successful acceptance
        $responseData = json_decode(send($address, $payload), true);

        if (isset($responseData['status']) && ($responseData['status'] === 'accepted' || $responseData['status'] === 'warning')) {
            // Check if the response status is a warning
            if ($responseData['status'] === 'warning') {
                output(returnContactCreationWarning($responseData['message']));
                // Insert into database
                if ($this->repository->insertContact($address, $responseData['myPublicKey'], $name, $fee, $credit, $currency)) {
                    if(synchContact($address)){
                        output(returnContactCreationSuccessful());
                    }
                }
            } else{
                // Insert into database
                if ($this->repository->insertContact($address, $responseData['myPublicKey'], $name, $fee, $credit, $currency)) {
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
        return $this->repository->acceptContact($address, $name, $fee, $credit, $currency);
    }

    /**
     * Handle contact creation request (incoming)
     *
     * @param array $request Request data
     * @return string Response payload
     */
    public function handleContactCreation(array $request): string {
        $address = $request['senderAddress'];
        $senderPublicKey = $request['senderPublicKey'];

        // Check if contact already exists
        if ($this->repository->contactExists($address)) {
            return buildContactAlreadyExistsPayload();
        } else{
            return $this->repository->addPendingContact($address, $senderPublicKey);
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
        $lookupResultByName = $this->lookupContactByName($request);
        $lookupResultByAddress = $this->lookupContactByAddress($request);
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

        return isset($data) ? $data : null;
    }

    /**
     * Lookup contact by name
     *
     * @param string $name Contact name
     * @return array|null Contact data or null
     */
    public function lookupContactByName(string $name): ?array {
        return $this->repository->lookupByName($name);
    }

    /**
     * Lookup contact by address
     *
     * @param string $address Contact address
     * @return array|null Contact data or null
     */
    public function lookupContactByAddress(string $address): ?array {
        return $this->repository->lookupByAddress($address);
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

        if ($results = $this->repository->searchContacts($searchTerm)) {
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
            if (isHttpAddress($data[2]) || isTorAddress($data[2])) {
                $address = $data[2];
            } else{
                // Check if the name yields an address
                $contactResult = $this->lookupContactByName($data[2]);
                $address = $contactResult['address'] ?? null;
            }

            if ($result = $this->repository->getContactByAddress($address)) {
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
        return $this->repository->contactExists($address);
    }

    /**
     * Check if contact is accepted
     *
     * @param string $address Contact address
     * @return bool True if accepted
     */
    public function isAcceptedContact(string $address): bool {
        return $this->repository->isAcceptedContact($address);
    }

    /**
     * Check if contact is not blocked
     *
     * @param string $address Contact address
     * @return bool True if not blocked
     */
    public function isNotBlocked(string $address): bool {
        return $this->repository->isNotBlocked($address);
    }

    /**
     * Block a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function blockContact(string $address): bool {
        return $this->repository->blockContact($address);
    }

    /**
     * Unblock a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function unblockContact(string $address): bool {
        return $this->repository->unblockContact($address);
    }

    /**
     * Delete a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function deleteContact(string $address): bool {
        return $this->repository->deleteContact($address);
    }

    /**
     * Get all contact addresses
     *
     * @param string|null $exclude Address to exclude
     * @return array Array of addresses
     */
    public function getAllAddresses(?string $exclude = null): array {
        return $this->repository->getAllAddresses($exclude);
    }

    /**
     * Update contact status
     *
     * @param string $address Contact address
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(string $address, string $status): bool {
        return $this->repository->updateStatus($address, $status);
    }

    /**
     * Get credit limit for a contact
     *
     * @param string $senderPublicKey Sender's public key
     * @return float Credit limit
     */
    public function getCreditLimit(string $senderPublicKey): float {
        return $this->repository->getCreditLimit($senderPublicKey);
    }

    /**
     * Get contact public key
     *
     * @param string $address Contact address
     * @return array|null Array with pubkey or null
     */
    public function getContactPubkey(string $address): ?array {
        return $this->repository->getContactPubkey($address);
    }

    /**
     * Get all contacts
     *
     * @return array Array of contacts
     */
    public function getAllContacts(): array {
        return $this->repository->getAllContacts();
    }

    /**
     * Get pending contact requests
     *
     * @return array Array of pending contacts
     */
    public function getPendingContactRequests(): array {
        return $this->repository->getPendingContactRequests();
    }

    /**
     * Lookup contact address by name
     *
     * @param string $name Contact name
     * @return string|null Contact address or null
     */
    public function lookupAddressByName(string $name): ?string {
        return $this->repository->lookupAddressByName($name);
    }

    /**
     * Lookup contact name by address
     *
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(string $address): ?string {
        return $this->repository->lookupNameByAddress($address);
    }
}
