<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\ContactManagementServiceInterface;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Core\Constants;
use Eiou\Core\ErrorCodes;
use Eiou\Core\UserContext;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactRepository;
use Eiou\Exceptions\FatalServiceException;
use Eiou\Exceptions\ValidationServiceException;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Utils\InputValidator;
use Eiou\Utils\SecureLogger;

/**
 * Contact Management Service
 *
 * Handles CRUD and status management operations for contacts.
 * Delegates P2P exchange and synchronization logic to ContactSyncService.
 *
 * SECTION INDEX:
 * - Properties & Constructor............. Line ~40
 * - Add Contact Operations............... Line ~120
 * - Lookup Methods....................... Line ~180
 * - Existence Checks..................... Line ~280
 * - Status Management.................... Line ~320
 * - Contact Updates...................... Line ~500
 * - Repository Wrappers.................. Line ~600
 */
class ContactManagementService implements ContactManagementServiceInterface
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * @var ContactRepository Contact repository instance
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
     * @var InputValidator Input validator
     */
    private InputValidator $inputValidator;

    /**
     * @var SecureLogger Secure logger
     */
    private SecureLogger $secureLogger;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var ContactSyncServiceInterface|null Contact sync service for P2P exchange operations
     */
    private ?ContactSyncServiceInterface $contactSyncService = null;

    // =========================================================================
    // CONSTRUCTOR & DEPENDENCY INJECTION
    // =========================================================================

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param AddressRepository $addressRepository Address repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param UtilityServiceContainer $utilityContainer Utility container
     * @param InputValidator $inputValidator Input validator
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        AddressRepository $addressRepository,
        BalanceRepository $balanceRepository,
        UtilityServiceContainer $utilityContainer,
        InputValidator $inputValidator,
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->balanceRepository = $balanceRepository;
        $this->utilityContainer = $utilityContainer;
        $this->inputValidator = $inputValidator;
        $this->currentUser = $currentUser;
        $this->transportUtility = $this->utilityContainer->getTransportUtility($this->currentUser);
        $this->secureLogger = new SecureLogger();
    }

    /**
     * Set the contact sync service (breaks circular dependency)
     *
     * @param ContactSyncServiceInterface $syncService Contact sync service
     * @return void
     */
    public function setContactSyncService(ContactSyncServiceInterface $syncService): void
    {
        $this->contactSyncService = $syncService;
    }

    /**
     * Get the contact sync service
     *
     * @return ContactSyncServiceInterface
     * @throws \RuntimeException If sync service was not injected
     */
    private function getContactSyncService(): ContactSyncServiceInterface
    {
        if ($this->contactSyncService === null) {
            throw new \RuntimeException(
                'ContactSyncService not injected. Call setContactSyncService() or ensure ServiceContainer properly injects the dependency.'
            );
        }
        return $this->contactSyncService;
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
    public function addContact(array $data, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        // Validate and sanitize address
        $addressValidation = $this->inputValidator->validateAddress($data[2] ?? '');
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            $output->error("Invalid Address: " . $addressValidation['error'], ErrorCodes::INVALID_ADDRESS, 400);
            return;
        }
        $address = $addressValidation['value'];

        if (in_array($address, $this->currentUser->getUserAddresses())) {
            $output->error("Cannot add yourself as a contact", ErrorCodes::SELF_CONTACT, 400);
            return;
        }

        // Validate and sanitize contact name
        $nameValidation = $this->inputValidator->validateContactName($data[3] ?? '');
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
        $feeValidation = $this->inputValidator->validateFeePercent($data[4] ?? 0);
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
        $creditValidation = $this->inputValidator->validateCreditLimit($data[5] ?? 0);
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
        $currencyValidation = $this->inputValidator->validateCurrency($data[6] ?? 'USD');
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

        // Delegate to sync service for P2P exchange handling
        $syncService = $this->getContactSyncService();
        if ($contact) {
            $syncService->handleExistingContact($contact, $address, $name, $fee, $credit, $currency, $output);
        } else {
            $syncService->handleNewContact($address, $name, $fee, $credit, $currency, $output);
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
    public function acceptContact(string $pubkey, string $name, float $fee, float $credit, string $currency): bool
    {
        $success = $this->contactRepository->acceptContact($pubkey, $name, $fee, $credit, $currency);
        if ($success) {
            // Addresses already saved, just need to add initial contact balances
            $this->balanceRepository->insertInitialContactBalances($pubkey, $currency);
        }
        return $success;
    }

    // =========================================================================
    // LOOKUP METHODS
    // =========================================================================

    /**
     * Lookup contact information
     *
     * @param mixed $request Request data (name or address)
     * @return array|null Contact information or null
     */
    public function lookupContactInfo($request): ?array
    {
        // Lookup information
        $lookupResultByName = $this->lookupByName($request);
        if (!$lookupResultByName) {
            $lookupResultByAddress = null;
            $transportIndex = $this->transportUtility->determineTransportType($request);
            if ($transportIndex) {
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
        if (isset($lookupResult['http'])) {
            $data['http'] = $lookupResult['http'];
        }
        if (isset($lookupResult['https'])) {
            $data['https'] = $lookupResult['https'];
        }
        if (isset($lookupResult['tor'])) {
            $data['tor'] = $lookupResult['tor'];
        }
        if (isset($lookupResult['status'])) {
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
    public function lookupByName(string $name): ?array
    {
        return $this->contactRepository->lookupByName($name);
    }

    /**
     * Lookup contact by address
     *
     * @param string $transportIndex Address type, i.e. http, https, tor
     * @param string $address Contact address
     * @return array|null Contact data or null
     */
    public function lookupByAddress(string $transportIndex, string $address): ?array
    {
        return $this->contactRepository->lookupByAddress($transportIndex, $address);
    }

    /**
     * Search contacts
     *
     * @param array $data Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function searchContacts(array $data, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        // Lookup contact based on their name
        if (isset($data[2])) {
            $nameValidation = $this->inputValidator->validateContactName($data[2]);
            if (!$nameValidation['valid']) {
                $this->secureLogger->warning("Invalid contact name", [
                    'name' => $data[2] ?? 'empty',
                    'error' => $nameValidation['error']
                ]);
                throw new ValidationServiceException(
                    "Invalid name: " . $nameValidation['error'],
                    ErrorCodes::INVALID_NAME,
                    'name',
                    400
                );
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
                    echo "\t" . $contact['name'] . " - " . ($contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? 'No address') . " (" . $contact['status'] . ")\n";
                }
                echo "Found " . count($results) . " contact(s)\n";
            }
        } else {
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
    public function viewContact(array $data, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        // View contact information
        $amountValidation = $this->inputValidator->validateArgvAmount($data, 3);
        if (!$amountValidation['valid']) {
            $this->secureLogger->warning("Invalid parameter amount", [
                'value' => $data,
                'error' => $amountValidation['error']
            ]);
            throw new ValidationServiceException(
                "Invalid parameter amount: " . $amountValidation['error'],
                ErrorCodes::INVALID_PARAMS,
                'parameters',
                400
            );
        }

        if ($this->transportUtility->isAddress($data[2])) {
            $addressValidation = $this->inputValidator->validateAddress($data[2] ?? '');
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $data[2] ?? 'empty',
                    'error' => $addressValidation['error']
                ]);
                throw new ValidationServiceException(
                    "Invalid Address: " . $addressValidation['error'],
                    ErrorCodes::INVALID_ADDRESS,
                    'address',
                    400
                );
            }
            $address = $addressValidation['value'];
            $transportIndex = $this->transportUtility->determineTransportType($address);
            $contactResult = $this->contactRepository->getContactByAddress($transportIndex, $address);
        } else {
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
    // EXISTENCE CHECKS
    // =========================================================================

    /**
     * Check if contact exists
     *
     * @param string $address Contact address
     * @return bool True if exists
     */
    public function contactExists(string $address): bool
    {
        $transportIndex = $this->transportUtility->determineTransportType($address);
        if ($transportIndex === null) {
            return false;
        }
        return $this->contactRepository->contactExists($transportIndex, $address);
    }

    /**
     * Check if contact exists through pubkey
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if exists
     */
    public function contactExistsPubkey(string $pubkey): bool
    {
        return $this->contactRepository->contactExistsPubkey($pubkey);
    }

    /**
     * Check if contact is accepted
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if accepted
     */
    public function isAcceptedContactPubkey(string $pubkey): bool
    {
        return $this->contactRepository->isAcceptedContactPubkey($pubkey);
    }

    /**
     * Check if contact is not blocked
     *
     * @param string $pubkey Contact pubkey
     * @return bool True if not blocked
     */
    public function isNotBlocked(string $pubkey): bool
    {
        return $this->contactRepository->isNotBlocked($pubkey);
    }

    // =========================================================================
    // STATUS MANAGEMENT
    // =========================================================================

    /**
     * Block a contact
     *
     * @param string|null $addressOrName Contact address or name
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     * @throws ValidationServiceException For invalid input
     * @throws FatalServiceException For operation failures
     */
    public function blockContact(?string $addressOrName, ?CliOutputManager $output = null): bool
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($addressOrName === null) {
            throw new ValidationServiceException(
                "Address or name is required",
                ErrorCodes::MISSING_IDENTIFIER,
                'addressOrName',
                400
            );
        }

        // Check if it's an HTTP, HTTPS, or Tor address
        if ($this->transportUtility->isAddress($addressOrName)) {
            $addressValidation = $this->inputValidator->validateAddress($addressOrName);
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $addressOrName,
                    'error' => $addressValidation['error']
                ]);
                throw new ValidationServiceException(
                    "Invalid Address: " . $addressValidation['error'],
                    ErrorCodes::INVALID_ADDRESS,
                    'address',
                    400
                );
            }
            $address = $addressValidation['value'];
            $transportIndex = $this->transportUtility->determineTransportType($address);
            // Check if contact exists before attempting to block
            if (!$this->contactRepository->contactExists($transportIndex, $address)) {
                throw new ValidationServiceException(
                    "Contact not found for address: " . $address,
                    ErrorCodes::CONTACT_NOT_FOUND,
                    'address',
                    404
                );
            }
        } else {
            // Check if the name yields an address
            $contact = $this->contactRepository->lookupByName($addressOrName);
            if (!$contact) {
                throw new ValidationServiceException(
                    "Contact not found with name: " . $addressOrName,
                    ErrorCodes::CONTACT_NOT_FOUND,
                    'name',
                    404
                );
            }
            $address = $this->transportUtility->fallbackTransportAddress($contact);
            if (!$address) {
                throw new FatalServiceException(
                    "Contact has no valid address",
                    ErrorCodes::NO_ADDRESS,
                    ['name' => $addressOrName],
                    500
                );
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
            throw new FatalServiceException(
                "Failed to block contact",
                ErrorCodes::BLOCK_FAILED,
                ['address' => $address],
                500
            );
        }
    }

    /**
     * Unblock a contact
     *
     * @param string|null $addressOrName Contact address or name
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     * @throws ValidationServiceException For invalid input
     * @throws FatalServiceException For operation failures
     */
    public function unblockContact(?string $addressOrName, ?CliOutputManager $output = null): bool
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($addressOrName === null) {
            throw new ValidationServiceException(
                "Address or name is required",
                ErrorCodes::MISSING_IDENTIFIER,
                'addressOrName',
                400
            );
        }

        // Check if it's an HTTP, HTTPS, or Tor address
        if ($this->transportUtility->isAddress($addressOrName)) {
            $addressValidation = $this->inputValidator->validateAddress($addressOrName);
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $addressOrName,
                    'error' => $addressValidation['error']
                ]);
                throw new ValidationServiceException(
                    "Invalid Address: " . $addressValidation['error'],
                    ErrorCodes::INVALID_ADDRESS,
                    'address',
                    400
                );
            }
            $address = $addressValidation['value'];
            $transportIndex = $this->transportUtility->determineTransportType($address);
            // Check if contact exists before attempting to unblock
            if (!$this->contactRepository->contactExists($transportIndex, $address)) {
                throw new ValidationServiceException(
                    "Contact not found for address: " . $address,
                    ErrorCodes::CONTACT_NOT_FOUND,
                    'address',
                    404
                );
            }
        } else {
            // Check if the name yields an address
            $contact = $this->contactRepository->lookupByName($addressOrName);
            if (!$contact) {
                throw new ValidationServiceException(
                    "Contact not found with name: " . $addressOrName,
                    ErrorCodes::CONTACT_NOT_FOUND,
                    'name',
                    404
                );
            }
            $address = $this->transportUtility->fallbackTransportAddress($contact);
            if (!$address) {
                throw new FatalServiceException(
                    "Contact has no valid address",
                    ErrorCodes::NO_ADDRESS,
                    ['name' => $addressOrName],
                    500
                );
            }
            $transportIndex = $this->transportUtility->determineTransportType($address);
        }

        if ($this->contactRepository->unblockContact($transportIndex, $address)) {
            $output->success("Contact unblocked successfully", [
                'address' => $address,
                'status' => 'unblocked'
            ]);
            return true;
        } else {
            throw new FatalServiceException(
                "Failed to unblock contact",
                ErrorCodes::UNBLOCK_FAILED,
                ['address' => $address],
                500
            );
        }
    }

    /**
     * Delete a contact
     *
     * @param string|null $addressOrName Contact address or name
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return bool Success status
     * @throws ValidationServiceException For invalid input
     * @throws FatalServiceException For operation failures
     */
    public function deleteContact(?string $addressOrName, ?CliOutputManager $output = null): bool
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($addressOrName === null) {
            throw new ValidationServiceException(
                "Address or name is required",
                ErrorCodes::MISSING_IDENTIFIER,
                'addressOrName',
                400
            );
        }

        // Check if it's an HTTP, HTTPS, or Tor address
        if ($this->transportUtility->isAddress($addressOrName)) {
            $addressValidation = $this->inputValidator->validateAddress($addressOrName);
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $addressOrName,
                    'error' => $addressValidation['error']
                ]);
                throw new ValidationServiceException(
                    "Invalid Address: " . $addressValidation['error'],
                    ErrorCodes::INVALID_ADDRESS,
                    'address',
                    400
                );
            }
            $address = $addressValidation['value'];
            $transportIndex = $this->transportUtility->determineTransportType($address);
            // Check if contact exists before attempting to delete
            if (!$this->contactRepository->contactExists($transportIndex, $address)) {
                throw new ValidationServiceException(
                    "Contact not found for address: " . $address,
                    ErrorCodes::CONTACT_NOT_FOUND,
                    'address',
                    404
                );
            }
        } else {
            // Check if the name yields an address
            $contact = $this->contactRepository->lookupByName($addressOrName);
            if (!$contact) {
                throw new ValidationServiceException(
                    "Contact not found with name: " . $addressOrName,
                    ErrorCodes::CONTACT_NOT_FOUND,
                    'name',
                    404
                );
            }
            $address = $this->transportUtility->fallbackTransportAddress($contact);
            if (!$address) {
                throw new FatalServiceException(
                    "Contact has no valid address",
                    ErrorCodes::NO_ADDRESS,
                    ['name' => $addressOrName],
                    500
                );
            }
        }

        $pubkey = $this->getContactPubkey($address);

        if ($pubkey === null) {
            throw new ValidationServiceException(
                "Contact not found for address: " . $address,
                ErrorCodes::CONTACT_NOT_FOUND,
                'address',
                404
            );
        }

        if ($this->contactRepository->deleteContact($pubkey) && $this->addressRepository->deleteByPubkey($pubkey) && $this->balanceRepository->deleteByPubkey($pubkey)) {
            $output->success("Contact deleted successfully", [
                'address' => $address,
                'deleted' => true
            ]);
            return true;
        } else {
            throw new FatalServiceException(
                "Failed to delete contact",
                ErrorCodes::DELETE_FAILED,
                ['address' => $address],
                500
            );
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
    public function updateContact(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        $address = $argv[2] ?? null;
        $field = isset($argv[3]) ? strtolower($argv[3]) : null;
        $value = $argv[4] ?? null;
        $value2 = $argv[5] ?? null;
        $value3 = $argv[6] ?? null;

        // Validate address or name
        if (!$address) {
            $output->error("Address or name is required", ErrorCodes::MISSING_ADDRESS, 400);
            return;
        }

        // Try to determine if input is an address (http/https/tor) or a name
        $transportIndex = $this->transportUtility->determineTransportType($address);
        $contact = null;

        // Only lookup by address if we have a valid transport type
        if ($transportIndex !== null) {
            $contact = $this->contactRepository->lookupByAddress($transportIndex, $address);
        }

        if (!$contact) {
            // Try by name (input was either a name or address lookup returned no result)
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

    /**
     * Update contact status
     *
     * @param string $address Contact address
     * @param string $status New status
     * @return bool Success status
     */
    public function updateStatus(string $address, string $status): bool
    {
        $pubkey = $this->getContactPubkey($address);
        if ($pubkey === null) {
            return false;
        }
        return $this->contactRepository->updateStatus($pubkey, $status);
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
    public function getAllAddresses(?string $exclude = null): array
    {
        return $this->addressRepository->getAllAddresses($exclude);
    }

    /**
     * Get credit limit for a contact
     *
     * @param string $senderPublicKey Sender's public key
     * @return float Credit limit
     */
    public function getCreditLimit(string $senderPublicKey): float
    {
        return $this->contactRepository->getCreditLimit($senderPublicKey);
    }

    /**
     * Get contact public key
     *
     * @param string $address Contact address
     * @return string|null Contact pubkey or null
     */
    public function getContactPubkey(string $address): ?string
    {
        $transportIndex = $this->transportUtility->determineTransportType($address);
        if ($transportIndex === null) {
            return null;
        }
        return $this->contactRepository->getContactPubkey($transportIndex, $address);
    }

    /**
     * Check for new contact requests since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewContactRequests($lastCheckTime): bool
    {
        return $this->contactRepository->checkForNewContactRequests($lastCheckTime);
    }

    /**
     * Get all contacts
     *
     * @return array Array of contacts
     */
    public function getAllContacts(): array
    {
        return $this->contactRepository->getAllContacts();
    }

    /**
     * Retrieve all contacts pubkeys
     *
     * @return array Array of contacts with only their pubkey
     */
    public function getAllContactsPubkeys(): array
    {
        return $this->contactRepository->getAllContactsPubkeys();
    }

    /**
     * Retrieve all accepted contacts
     *
     * @return array Array of accepted contacts
     */
    public function getAcceptedContacts(): array
    {
        return $this->contactRepository->getAcceptedContacts();
    }

    /**
     * Get pending contact requests
     *
     * @return array Array of (non-user initiated) pending contacts
     */
    public function getPendingContactRequests(): array
    {
        return $this->contactRepository->getPendingContactRequests();
    }

    /**
     * Get user initiated pending contact requests
     *
     * @return array Array of user initiated pending contacts
     */
    public function getUserPendingContactRequests(): array
    {
        return $this->contactRepository->getUserPendingContactRequests();
    }

    /**
     * Get all blocked contacts
     *
     * @return array Array of blocked contacts
     */
    public function getBlockedContacts(): array
    {
        return $this->contactRepository->getBlockedContacts();
    }

    /**
     * Lookup contact addresses by name
     *
     * @param string $name Contact name
     * @return string|null Contact addresses or null
     */
    public function lookupAddressesByName(string $name): ?string
    {
        return $this->contactRepository->lookupAddressesByName($name);
    }

    /**
     * Get all accepted contact addresses for P2P routing
     *
     * Returns addresses of contacts with 'accepted' status for use in
     * P2P message broadcasting.
     *
     * @return array List of accepted contact addresses
     */
    public function getAllAcceptedAddresses(): array
    {
        return $this->contactRepository->getAllAcceptedAddresses();
    }

    /**
     * Lookup contact name by address
     *
     * @param string|null $transportIndex Address type, i.e. http, https, tor (null returns null gracefully)
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function lookupNameByAddress(?string $transportIndex, string $address): ?string
    {
        return $this->contactRepository->lookupNameByAddress($transportIndex, $address);
    }

    /**
     * Get all available address types from the database schema
     *
     * @return array Array of address type names (e.g., ['http', 'tor'])
     */
    public function getAllAddressTypes(): array
    {
        return $this->addressRepository->getAllAddressTypes();
    }
}
