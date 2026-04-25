<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\ContactManagementServiceInterface;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Core\Constants;
use Eiou\Core\ErrorCodes;
use Eiou\Core\SplitAmount;
use Eiou\Core\UserContext;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactRepository;
use Eiou\Events\ContactEvents;
use Eiou\Events\EventDispatcher;
use Eiou\Exceptions\FatalServiceException;
use Eiou\Exceptions\ValidationServiceException;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Utils\InputValidator;
use Eiou\Database\RepositoryFactory;
use Eiou\Utils\Logger;

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
     * @var Logger Secure logger
     */
    private Logger $secureLogger;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var ContactSyncServiceInterface|null Contact sync service for P2P exchange operations
     */
    private ?ContactSyncServiceInterface $contactSyncService = null;

    /**
     * @var SyncTriggerInterface|null Sync trigger for balance recalculation
     */
    private ?SyncTriggerInterface $syncTrigger = null;

    /**
     * @var ContactCreditRepository|null Contact credit repository for initial credit creation
     */
    private ?ContactCreditRepository $contactCreditRepository = null;

    /**
     * @var \Eiou\Database\ContactCurrencyRepository|null Contact currency repository for multi-currency support
     */
    private ?\Eiou\Database\ContactCurrencyRepository $contactCurrencyRepository = null;

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
        UserContext $currentUser,
        RepositoryFactory $repositoryFactory,
        SyncTriggerInterface $syncTrigger
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->balanceRepository = $balanceRepository;
        $this->utilityContainer = $utilityContainer;
        $this->inputValidator = $inputValidator;
        $this->currentUser = $currentUser;
        $this->transportUtility = $this->utilityContainer->getTransportUtility($this->currentUser);
        $this->secureLogger = Logger::getInstance();
        $this->contactCreditRepository = $repositoryFactory->get(\Eiou\Database\ContactCreditRepository::class);
        $this->contactCurrencyRepository = $repositoryFactory->get(\Eiou\Database\ContactCurrencyRepository::class);
        $this->syncTrigger = $syncTrigger;
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
     * Add a contact — dispatcher for the three semantically distinct branches.
     *
     * Validates inputs once and routes to one of:
     *   - addCurrencyToExisting()  : contact is already accepted and the
     *                                requested currency is new for them.
     *   - acceptIncoming()         : contact row already exists (incoming
     *                                pending request, blocked, etc.).
     *   - createOutgoing()         : no contact row — start an outbound
     *                                contact request.
     *
     * The new top-level CLI namespace (`eiou contact add` / `accept` /
     * `currency add`) calls the branch methods directly. This dispatcher
     * is retained because it remains the entry point for the legacy
     * `eiou add` shim and the existing controllers.
     *
     * @param array $data Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function addContact(array $data, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        $validated = $this->validateAddContactInput($data, $output);
        if ($validated === null) {
            return;
        }

        $contact = $this->contactRepository->getContactByAddress(
            $validated['transportIndex'],
            $validated['address']
        );

        $currencyAlreadyExists = false;
        if ($contact && $this->contactCurrencyRepository !== null) {
            $pubkeyHash = hash(Constants::HASH_ALGORITHM, $contact['pubkey']);
            $currencyAlreadyExists = $this->contactCurrencyRepository->hasCurrency(
                $pubkeyHash,
                $validated['currency']
            );
        }

        if ($contact
            && $contact['status'] === Constants::CONTACT_STATUS_ACCEPTED
            && !$currencyAlreadyExists
        ) {
            $this->addCurrencyToExisting($validated, $contact, $output);
            return;
        }

        if ($contact) {
            $this->acceptIncoming($validated, $contact, $output);
            return;
        }

        $this->createOutgoing($validated, $output);
    }

    /**
     * Validate and sanitise the argv-shaped `add contact` input.
     *
     * Errors are written to $output and the method returns null. On success
     * returns a normalised struct with everything the branch methods need.
     *
     * @return array{
     *     address: string,
     *     name: string,
     *     fee: int|float,
     *     credit: SplitAmount,
     *     currency: string,
     *     transportIndex: string,
     *     requestedCreditLimit: SplitAmount|null,
     *     description: string|null
     * }|null
     */
    private function validateAddContactInput(array $data, CliOutputManager $output): ?array
    {
        $addressValidation = $this->inputValidator->validateAddress($data[2] ?? '');
        if (!$addressValidation['valid']) {
            $this->secureLogger->warning("Invalid contact address", [
                'address' => $data[2] ?? 'empty',
                'error' => $addressValidation['error']
            ]);
            $output->error("Invalid Address: " . $addressValidation['error'], ErrorCodes::INVALID_ADDRESS, 400);
            return null;
        }
        $address = $addressValidation['value'];

        if (in_array($address, $this->currentUser->getUserAddresses())) {
            $output->error("Cannot add yourself as a contact", ErrorCodes::SELF_CONTACT, 400);
            return null;
        }

        $nameValidation = $this->inputValidator->validateContactName($data[3] ?? '');
        if (!$nameValidation['valid']) {
            $this->secureLogger->warning("Invalid contact name", [
                'name' => $data[3] ?? 'empty',
                'error' => $nameValidation['error']
            ]);
            $output->error("Invalid name: " . $nameValidation['error'], ErrorCodes::INVALID_NAME, 400);
            return null;
        }
        $name = $nameValidation['value'];

        $feeValidation = $this->inputValidator->validateFeePercent($data[4] ?? 0);
        if (!$feeValidation['valid']) {
            $this->secureLogger->warning("Invalid fee percentage", [
                'fee' => $data[4] ?? 'empty',
                'error' => $feeValidation['error']
            ]);
            $output->error("Invalid Fee: " . $feeValidation['error'], ErrorCodes::INVALID_FEE, 400);
            return null;
        }
        $fee = CurrencyUtilityService::exactMajorToMinor($feeValidation['value'], Constants::FEE_CONVERSION_FACTOR);

        $creditValidation = $this->inputValidator->validateCreditLimit($data[5] ?? 0);
        if (!$creditValidation['valid']) {
            $this->secureLogger->warning("Invalid credit limit", [
                'credit' => $data[5] ?? 'empty',
                'error' => $creditValidation['error']
            ]);
            $output->error("Invalid credit: " . $creditValidation['error'], ErrorCodes::INVALID_CREDIT, 400);
            return null;
        }

        $currencyValidation = $this->inputValidator->validateCurrency($data[6] ?? 'USD');
        if (!$currencyValidation['valid']) {
            $this->secureLogger->warning("Invalid currency", [
                'currency' => $data[6] ?? 'empty',
                'error' => $currencyValidation['error']
            ]);
            $output->error("Invalid currency: " . $currencyValidation['error'], ErrorCodes::INVALID_CURRENCY, 400);
            return null;
        }
        $currency = $currencyValidation['value'];
        $credit = SplitAmount::from($creditValidation['value']);

        $allowedCurrencies = $this->currentUser->getAllowedCurrencies();
        if (!in_array($currency, $allowedCurrencies)) {
            $allowedCurrencies[] = $currency;
            $newValue = implode(',', $allowedCurrencies);
            $this->currentUser->set('allowedCurrencies', $newValue);
            $configFile = '/etc/eiou/config/defaultconfig.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?? [];
                $config['allowedCurrencies'] = $newValue;
                file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }

        $this->secureLogger->info("Contact addition validated", [
            'address_type' => $addressValidation['type'] ?? 'unknown',
            'name_length' => strlen($name)
        ]);

        $transportIndex = $this->transportUtility->determineTransportType($address);
        if ($transportIndex === null) {
            $this->secureLogger->warning("Could not determine transport type for address", [
                'address_length' => strlen($address)
            ]);
            $output->error("Invalid address: could not determine transport type", ErrorCodes::INVALID_ADDRESS, 400);
            return null;
        }

        // Optional requested credit limit (what we'd like the contact to set for us)
        // Position: $data[7] = requested credit limit, $data[8] = description
        // Use NULL or empty string to skip the requested credit limit
        $requestedCreditLimit = null;
        $rawArg7 = $data[7] ?? null;
        if ($rawArg7 !== null && $rawArg7 !== '--json' && $rawArg7 !== '' && strtoupper($rawArg7) !== 'NULL') {
            $requestedCreditValidation = $this->inputValidator->validateCreditLimit($rawArg7);
            if ($requestedCreditValidation['valid']) {
                $requestedCreditLimit = SplitAmount::from($requestedCreditValidation['value']);
            }
        }

        // Optional description/message for the contact request (always at index 8)
        $description = $data[8] ?? null;
        if ($description === '--json' || $description === null || $description === '') {
            $description = null;
        }

        return [
            'address' => $address,
            'name' => $name,
            'fee' => $fee,
            'credit' => $credit,
            'currency' => $currency,
            'transportIndex' => $transportIndex,
            'requestedCreditLimit' => $requestedCreditLimit,
            'description' => $description,
        ];
    }

    /**
     * Initiate an outbound contact request.
     *
     * Used when no local contact row exists yet for the target address.
     * Sends the P2P contact request via ContactSyncService::handleNewContact().
     *
     * @param array $validated  Output of validateAddContactInput()
     */
    public function createOutgoing(array $validated, CliOutputManager $output): void
    {
        $this->getContactSyncService()->handleNewContact(
            $validated['address'],
            $validated['name'],
            $validated['fee'],
            $validated['credit'],
            $validated['currency'],
            $output,
            $validated['description'],
            $validated['requestedCreditLimit'],
        );
    }

    /**
     * Accept (or otherwise update) a contact request that already has a row
     * in the local database — typically an incoming pending request.
     *
     * Delegates the P2P exchange to ContactSyncService::handleExistingContact()
     * which decides whether to send an acceptance, retry an update, etc.
     *
     * @param array $validated  Output of validateAddContactInput()
     * @param array $contact    The existing contact row (must not be null)
     */
    public function acceptIncoming(array $validated, array $contact, CliOutputManager $output): void
    {
        $this->getContactSyncService()->handleExistingContact(
            $contact,
            $validated['address'],
            $validated['name'],
            $validated['fee'],
            $validated['credit'],
            $validated['currency'],
            $output,
            $validated['description'],
            $validated['requestedCreditLimit'],
        );
    }

    /**
     * Propose adding a new currency to an already-accepted contact.
     *
     * Persists the local contact_currency row and sends a P2P request so the
     * remote side is notified and can accept the new currency.
     *
     * @param array $validated  Output of validateAddContactInput()
     * @param array $contact    The existing contact row (status must be accepted)
     */
    public function addCurrencyToExisting(array $validated, array $contact, CliOutputManager $output): void
    {
        if (!$this->addCurrencyToContact(
            $contact['pubkey'],
            $validated['currency'],
            $validated['fee'],
            $validated['credit']
        )) {
            $output->error(
                "Failed to add currency {$validated['currency']} to contact {$validated['name']}. Currency may already exist or contact is not accepted.",
                ErrorCodes::CONTACT_EXISTS,
                409
            );
            return;
        }

        // Send P2P request so the remote side is notified of the new currency.
        $this->getContactSyncService()->handleNewContact(
            $validated['address'],
            $validated['name'],
            $validated['fee'],
            $validated['credit'],
            $validated['currency'],
            $output,
            $validated['description'],
            $validated['requestedCreditLimit'],
        );
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

            // Create contact currency configuration entry
            $pubkeyHash = null;
            if ($this->contactCurrencyRepository !== null) {
                try {
                    $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);
                    $this->contactCurrencyRepository->upsertCurrencyConfig(
                        $pubkeyHash,
                        $currency,
                        (int) $fee,
                        $credit instanceof SplitAmount ? $credit : SplitAmount::from($credit)
                    );
                } catch (\Exception $e) {
                    $this->secureLogger->warning("Failed to create contact currency config", [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Recalculate balances from existing transactions (wallet restore scenario:
            // transactions were synced during ping but balances are still zero)
            if ($this->syncTrigger !== null) {
                try {
                    $this->syncTrigger->syncContactBalance($pubkey);
                } catch (\Exception $e) {
                    $this->secureLogger->warning("Failed to sync contact balance after acceptance", [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Calculate and store actual available credit (not 0) now that balances
            // and credit_limit are set. For new contacts: equals credit_limit.
            // For re-added contacts with prior transactions: reflects real balance.
            if ($this->contactCreditRepository !== null) {
                try {
                    $pubkeyHash = $pubkeyHash ?? hash(Constants::HASH_ALGORITHM, $pubkey);
                    $sentBalance = $this->balanceRepository->getContactSentBalance($pubkey, $currency);
                    $receivedBalance = $this->balanceRepository->getContactReceivedBalance($pubkey, $currency);
                    $balance = $sentBalance->subtract($receivedBalance);

                    $creditLimit = SplitAmount::zero();
                    if ($this->contactCurrencyRepository !== null) {
                        $creditLimit = $this->contactCurrencyRepository->getCreditLimit($pubkeyHash, $currency) ?? SplitAmount::zero();
                    }

                    $availableCredit = $balance->add($creditLimit);
                    $this->contactCreditRepository->upsertAvailableCredit(
                        $pubkeyHash,
                        $availableCredit,
                        $currency
                    );
                } catch (\Exception $e) {
                    $this->secureLogger->warning("Failed to store initial available credit", [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            EventDispatcher::getInstance()->dispatch(ContactEvents::CONTACT_ACCEPTED, [
                'pubkey' => $pubkey,
                'name' => $name,
                'currency' => $currency,
                'fee' => $fee,
                'credit' => $credit,
            ]);
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
        foreach ($this->getAllAddressTypes() as $type) {
            if (isset($lookupResult[$type])) {
                $data[$type] = $lookupResult[$type];
            }
        }
        if (isset($lookupResult['status'])) {
            $data['status'] = $lookupResult['status'];
        }

        return isset($data) ? $data : null;
    }

    /**
     * Lookup contact information with disambiguation for duplicate names
     *
     * When multiple contacts share the same name, displays a numbered list
     * and prompts the user to choose (CLI interactive mode) or returns a
     * multiple_matches error (JSON mode).
     *
     * @param mixed $request Request data (name or address)
     * @param CliOutputManager|null $output Output manager for interactive prompt / JSON error
     * @return array|null Contact information or null
     */
    public function lookupContactInfoWithDisambiguation($request, ?CliOutputManager $output = null): ?array
    {
        $output = $output ?? CliOutputManager::getInstance();

        // Try name lookup first - check for multiple matches
        $allMatches = $this->contactRepository->lookupAllByName($request);

        if (count($allMatches) === 1) {
            // Single match - return as normal
            return $this->lookupContactInfo($request);
        }

        if (count($allMatches) > 1) {
            $addressTypes = $this->getAllAddressTypes();

            if ($output->isJsonMode()) {
                // JSON mode: return error with match data for API/GUI callers
                $contacts = [];
                foreach ($allMatches as $match) {
                    $contact = ['name' => $match['name'] ?? null];
                    foreach ($addressTypes as $type) {
                        $contact[$type] = $match[$type] ?? null;
                    }
                    $contact['status'] = $match['status'] ?? null;
                    $contacts[] = $contact;
                }
                $output->error(
                    "Multiple contacts found with name: " . $request,
                    ErrorCodes::MULTIPLE_MATCHES,
                    409,
                    ['multiple_matches' => $contacts, 'count' => count($allMatches)]
                );
                return null;
            }

            // Interactive CLI mode: display numbered list and prompt
            echo "\nMultiple contacts found with name \"" . $request . "\":\n";
            foreach ($allMatches as $i => $match) {
                $address = $this->transportUtility->fallbackTransportAddress($match) ?? 'unknown';
                echo "\t[" . ($i + 1) . "] " . ($match['name'] ?? 'N/A') . " - " . $address . "\n";
            }
            echo "\t[0] Cancel\n";
            echo "Choose a contact (0-" . count($allMatches) . "): ";

            $choice = trim(fgets(STDIN));
            if (!is_numeric($choice) || (int)$choice < 1 || (int)$choice > count($allMatches)) {
                echo "Cancelled.\n";
                return null;
            }

            $selected = $allMatches[(int)$choice - 1];
            return $this->buildContactInfoFromResult($selected);
        }

        // No name matches - fall through to address lookup (existing behavior)
        return $this->lookupContactInfo($request);
    }

    /**
     * Build contact info array from a raw database result
     *
     * @param array $lookupResult Raw contact data from repository
     * @return array|null Structured contact info
     */
    private function buildContactInfoFromResult(array $lookupResult): ?array
    {
        $data = [];
        if (isset($lookupResult['name'])) {
            $data['receiverName'] = $lookupResult['name'];
        }
        if (isset($lookupResult['pubkey'])) {
            $data['receiverPublicKey'] = $lookupResult['pubkey'];
        }
        if (isset($lookupResult['pubkey_hash'])) {
            $data['receiverPublicKeyHash'] = $lookupResult['pubkey_hash'];
        }
        foreach ($this->getAllAddressTypes() as $type) {
            if (isset($lookupResult[$type])) {
                $data[$type] = $lookupResult[$type];
            }
        }
        if (isset($lookupResult['status'])) {
            $data['status'] = $lookupResult['status'];
        }
        return !empty($data) ? $data : null;
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
     * Lookup all contacts matching a name (for disambiguation).
     *
     * @param string $name Contact name (case-insensitive)
     * @return array Array of matching contacts (empty if none)
     */
    public function lookupAllByName(string $name): array
    {
        return $this->contactRepository->lookupAllByName($name);
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
    /**
     * Argv-shape entry kept for the GUI controller and any other caller that
     * still hands us a CLI-style positional array. New code should call
     * searchContactsByQuery() directly with a typed query.
     */
    public function searchContacts(array $data, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();
        $this->searchContactsByQuery($data[2] ?? null, $output);
    }

    /**
     * Search contacts by (partial) name.
     *
     * Typed entry point — `eiou contact search [query]` calls this directly.
     * Pass null/empty for an unfiltered listing.
     *
     * @param string|null $query Optional partial name to filter by.
     */
    public function searchContactsByQuery(?string $query, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        $searchTerm = null;
        if ($query !== null && $query !== '') {
            $nameValidation = $this->inputValidator->validateContactName($query);
            if (!$nameValidation['valid']) {
                $this->secureLogger->warning("Invalid contact name", [
                    'name' => $query,
                    'error' => $nameValidation['error']
                ]);
                throw new ValidationServiceException(
                    "Invalid name: " . $nameValidation['error'],
                    ErrorCodes::INVALID_NAME,
                    'name',
                    400
                );
            }
            $searchTerm = $nameValidation['value'];
        }

        if ($results = $this->contactRepository->searchContacts($searchTerm)) {
            $addressTypes = $this->getAllAddressTypes();

            // Collect all pubkey hashes for batch lookup (avoids N+1 queries)
            $hashToCurrency = [];
            foreach ($results as $result) {
                $hash = $result['pubkey_hash'] ?? '';
                if ($hash) {
                    $hashToCurrency[$hash] = $result['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                }
            }
            $allHashes = array_keys($hashToCurrency);

            // Batch load credits and balances in 2 queries instead of 2*N
            $creditsByHash = [];
            if ($this->contactCreditRepository !== null && !empty($allHashes)) {
                try {
                    $creditsByHash = $this->contactCreditRepository->getAvailableCreditsForHashes($allHashes);
                } catch (\Exception $e) {
                    $this->secureLogger->warning("Batch credit lookup failed: " . $e->getMessage());
                }
            }

            // Group hashes by currency for batch balance lookup
            $currencyGroups = [];
            foreach ($hashToCurrency as $hash => $currency) {
                $currencyGroups[$currency][] = $hash;
            }
            $balancesByHash = [];
            foreach ($currencyGroups as $currency => $hashes) {
                try {
                    $balancesByHash += $this->balanceRepository->getBalancesForPubkeyHashes($hashes, $currency);
                } catch (\Exception $e) {
                    $this->secureLogger->warning("Batch balance lookup failed: " . $e->getMessage());
                }
            }

            // Enrich results from pre-loaded data
            foreach ($results as &$result) {
                $result['my_available_credit'] = null;
                $result['their_available_credit'] = null;
                $hash = $result['pubkey_hash'] ?? '';
                if ($hash) {
                    // My available credit (from contact_credit table, received via pong)
                    if (isset($creditsByHash[$hash])) {
                        $result['my_available_credit'] = $creditsByHash[$hash]['available_credit']->toMajorUnits();
                    }
                    // Their available credit (calculated: sent - received + credit_limit)
                    if (isset($balancesByHash[$hash])) {
                        $b = $balancesByHash[$hash];
                        $sent = $b['sent'] ?? SplitAmount::zero();
                        $received = $b['received'] ?? SplitAmount::zero();
                        $creditLimit = $result['credit_limit'] ?? SplitAmount::zero();
                        $result['their_available_credit'] = $sent->subtract($received)->add($creditLimit)->toMajorUnits();
                    }
                }
            }
            unset($result);

            if ($output->isJsonMode()) {
                $contacts = [];
                foreach ($results as $result) {
                    $contact = ['name' => $result['name'] ?? null];
                    foreach ($addressTypes as $type) {
                        $contact[$type] = $result[$type] ?? null;
                    }
                    $contact['status'] = $result['status'] ?? null;
                    $contact['my_available_credit'] = $result['my_available_credit'];
                    $contact['their_available_credit'] = $result['their_available_credit'];
                    $contacts[] = $contact;
                }
                $output->success("Found " . count($results) . " contact(s)", [
                    'search_term' => $searchTerm,
                    'count' => count($results),
                    'contacts' => $contacts
                ]);
            } else {
                echo "Search Results:\n";
                foreach ($results as $i => $contact) {
                    echo "\n\t[" . ($i + 1) . "] Name: " . ($contact['name'] ?? 'N/A') . "\n";
                    foreach ($addressTypes as $type) {
                        if (isset($contact[$type])) echo "\t    " . ucfirst($type) . ": " . $contact[$type] . "\n";
                    }
                    echo "\t    Status: " . ($contact['status'] ?? 'N/A') . "\n";
                    $contactCurrency = $contact['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                    if ($contact['my_available_credit'] !== null) echo "\t    Your Available Credit: " . number_format($contact['my_available_credit'], Constants::getDisplayDecimals()) . "\n";
                    if ($contact['their_available_credit'] !== null) echo "\t    Their Available Credit: " . number_format($contact['their_available_credit'], Constants::getDisplayDecimals()) . "\n";
                }
                echo "\nFound " . count($results) . " contact(s)\n";
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
    /**
     * Argv-shape entry kept for the GUI controller and any other caller that
     * still hands us a CLI-style positional array. New code should call
     * viewContactByIdentifier() directly with a typed identifier.
     */
    public function viewContact(array $data, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

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

        $this->viewContactByIdentifier((string) $data[2], $output);
    }

    /**
     * View contact information by name or address.
     *
     * Typed entry point — the new `eiou contact view <id>` CLI calls this
     * directly without round-tripping through argv. Behaviour is identical
     * to viewContact(); only the parameter shape differs.
     *
     * @param string $identifier Contact name or address
     */
    public function viewContactByIdentifier(string $identifier, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->transportUtility->isAddress($identifier)) {
            $addressValidation = $this->inputValidator->validateAddress($identifier);
            if (!$addressValidation['valid']) {
                $this->secureLogger->warning("Invalid contact address", [
                    'address' => $identifier ?: 'empty',
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
            // Check if the name yields a contact
            $contactResult = $this->lookupByName($identifier);
        }

        if ($contactResult) {
            // Get per-currency configurations from contact_currencies
            $currencies = [];
            if ($this->contactCurrencyRepository !== null && !empty($contactResult['pubkey_hash'])) {
                $currencies = $this->contactCurrencyRepository->getContactCurrencies($contactResult['pubkey_hash']);
            }

            // My available credit with them (from contact_credit table, received via pong)
            $myAvailableCredit = null;
            if ($this->contactCreditRepository !== null && !empty($contactResult['pubkey_hash'])) {
                try {
                    $creditData = $this->contactCreditRepository->getAvailableCredit($contactResult['pubkey_hash']);
                    if ($creditData !== null) {
                        $creditCurrency = $creditData['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                        $myAvailableCredit = $creditData['available_credit']->toMajorUnits();
                    }
                } catch (\Exception $e) {
                    // Non-critical — skip available credit display
                }
            }

            // Their available credit with me per currency (calculated: sent - received + credit_limit)
            $theirAvailableCredit = null;
            if (!empty($contactResult['pubkey_hash']) && !empty($currencies)) {
                try {
                    $firstCurrency = $currencies[0]['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                    $balanceData = $this->balanceRepository->getContactBalanceByPubkeyHash($contactResult['pubkey_hash'], $firstCurrency);
                    if ($balanceData && count($balanceData) > 0) {
                        $b = $balanceData[0];
                        $sent = $b['sent'] ?? SplitAmount::zero();
                        $received = $b['received'] ?? SplitAmount::zero();
                        $creditLimit = $currencies[0]['credit_limit'] ?? SplitAmount::zero();
                        $theirAvailableCredit = $sent->subtract($received)->add($creditLimit)->toMajorUnits();
                    }
                } catch (\Exception $e) {
                    // Non-critical
                }
            }

            if ($output->isJsonMode()) {
                $contact = ['name' => $contactResult['name'] ?? null];
                foreach ($this->getAllAddressTypes() as $type) {
                    $contact[$type] = $contactResult[$type] ?? null;
                }
                $contact['pubkey'] = $contactResult['pubkey'] ?? null;
                $contact['status'] = $contactResult['status'] ?? null;
                $contact['my_available_credit'] = $myAvailableCredit;
                $contact['their_available_credit'] = $theirAvailableCredit;
                $contact['currencies'] = array_map(function ($c) {
                    return [
                        'currency' => $c['currency'],
                        'fee_percent' => $c['fee_percent'] / Constants::FEE_CONVERSION_FACTOR,
                        'credit_limit' => $c['credit_limit']->toMajorUnits(),
                        'status' => $c['status'] ?? null,
                        'direction' => $c['direction'] ?? null,
                    ];
                }, $currencies);
                $output->success("Contact found", ['contact' => $contact]);
            } else {
                echo "Contact Details:\n";
                echo "\tName: " . ($contactResult['name'] ?? 'N/A') . "\n";
                foreach ($this->getAllAddressTypes() as $type) {
                    if (isset($contactResult[$type])) echo "\t" . ucfirst($type) . ": " . $contactResult[$type] . "\n";
                }
                echo "\tStatus: " . ($contactResult['status'] ?? 'N/A') . "\n";
                if (!empty($currencies)) {
                    echo "\tCurrencies:\n";
                    foreach ($currencies as $c) {
                        $cur = $c['currency'];
                        $fee = $c['fee_percent'] / Constants::FEE_CONVERSION_FACTOR;
                        $credit = $c['credit_limit']->toMajorUnits();
                        echo "\t  {$cur}: Fee {$fee}%, Credit Limit " . number_format($credit, Constants::getDisplayDecimals()) . "\n";
                    }
                }
                if ($myAvailableCredit !== null) echo "\tYour Available Credit: " . number_format($myAvailableCredit, Constants::getDisplayDecimals()) . "\n";
                if ($theirAvailableCredit !== null) echo "\tTheir Available Credit: " . number_format($theirAvailableCredit, Constants::getDisplayDecimals()) . "\n";
            }
        } else {
            $output->error("Contact not found", ErrorCodes::CONTACT_NOT_FOUND, 404, ['query' => $identifier]);
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
            // Look up the contact's pubkey so subscribers have a stable id
            // (name/address can change or differ across nodes; pubkey won't).
            $blockedContact = $this->contactRepository->lookupByAddress($transportIndex, $address);
            EventDispatcher::getInstance()->dispatch(ContactEvents::CONTACT_BLOCKED, [
                'pubkey' => $blockedContact['pubkey'] ?? '',
                'address' => $address,
            ]);

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
     * Argv-shape entry kept for the GUI controller and any other caller that
     * still hands us a CLI-style positional array. New code should call
     * updateContactField() directly with typed parameters.
     */
    public function updateContact(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        // Filter out flags (--json, etc.) from positional args
        $positional = [];
        foreach ($argv as $i => $arg) {
            if ($i < 2) { $positional[] = $arg; continue; }
            if (strpos($arg, '--') === 0) { continue; }
            $positional[] = $arg;
        }
        $identifier = $positional[2] ?? '';
        $field = isset($positional[3]) ? strtolower($positional[3]) : '';
        $values = array_slice($positional, 4);

        $this->updateContactField((string) $identifier, (string) $field, $values, $output);
    }

    /**
     * Update one or more contact fields by name or address.
     *
     * Typed entry point — the new `eiou contact update <id> <field> <values…>`
     * CLI calls this directly. `$field` is one of `name|fee|credit|all`;
     * `$values` carries the remaining positional arguments in the same order
     * the legacy CLI accepts them:
     *
     *   name:   [<new-name>]
     *   fee:    [<value> <currency>]
     *   credit: [<value> <currency>]
     *   all:    [<name> <fee> <credit> [<currency>]]
     *
     * @param string $identifier Contact name or address.
     * @param string $field      One of name|fee|credit|all.
     * @param array  $values     Field-specific positional values.
     */
    public function updateContactField(string $identifier, string $field, array $values, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        $address = $identifier;
        $value = $values[0] ?? null;
        $value2 = $values[1] ?? null;
        $value3 = $values[2] ?? null;
        $value4 = $values[3] ?? null;

        if ($address === '') {
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
            // Try by name with disambiguation for duplicate names
            $allMatches = $this->contactRepository->lookupAllByName($address);

            if (count($allMatches) === 1) {
                $contact = $allMatches[0];
            } elseif (count($allMatches) > 1) {
                $addressTypes = $this->getAllAddressTypes();

                if ($output->isJsonMode()) {
                    $contacts = [];
                    foreach ($allMatches as $match) {
                        $c = ['name' => $match['name'] ?? null];
                        foreach ($addressTypes as $type) {
                            $c[$type] = $match[$type] ?? null;
                        }
                        $c['status'] = $match['status'] ?? null;
                        $contacts[] = $c;
                    }
                    $output->error(
                        "Multiple contacts found with name: " . $address,
                        ErrorCodes::MULTIPLE_MATCHES,
                        409,
                        ['multiple_matches' => $contacts, 'count' => count($allMatches)]
                    );
                    return;
                }

                // Interactive CLI: prompt user to choose
                echo "\nMultiple contacts found with name \"" . $address . "\":\n";
                foreach ($allMatches as $i => $match) {
                    $addr = $this->transportUtility->fallbackTransportAddress($match) ?? 'unknown';
                    echo "\t[" . ($i + 1) . "] " . ($match['name'] ?? 'N/A') . " - " . $addr . "\n";
                }
                echo "\t[0] Cancel\n";
                echo "Choose a contact (0-" . count($allMatches) . "): ";

                $choice = trim(fgets(STDIN));
                if (!is_numeric($choice) || (int)$choice < 1 || (int)$choice > count($allMatches)) {
                    echo "Cancelled.\n";
                    return;
                }
                $contact = $allMatches[(int)$choice - 1];
            }
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

        // Validate values — currency is required for fee/credit, optional for all (defaults to contact's currency)
        $insufficientParams = false;
        if ($field === 'name' && !$value) {
            $insufficientParams = true;
        } elseif ($field === 'fee' && (!$value || !$value2)) {
            $insufficientParams = true;
        } elseif ($field === 'credit' && (!$value || !$value2)) {
            $insufficientParams = true;
        } elseif ($field === 'all' && (!$value || !$value2 || !$value3)) {
            $insufficientParams = true;
        }
        if ($insufficientParams) {
            $usages = [
                'name' => 'update [address] name [name]',
                'fee' => 'update [address] fee [value] [currency]',
                'credit' => 'update [address] credit [value] [currency]',
                'all' => 'update [address] all [name] [fee] [credit] [currency]',
            ];
            $output->error("Insufficient parameters for update", ErrorCodes::MISSING_PARAMS, 400, [
                'field' => $field,
                'usage' => $usages[$field] ?? "update [address] $field [value]"
            ]);
            return;
        }

        // Validate name if being updated
        if ($field === 'name' || $field === 'all') {
            $nameValue = ($field === 'all') ? $value : $value;
            $nameValidation = $this->inputValidator->validateContactName($nameValue);
            if (!$nameValidation['valid']) {
                $this->secureLogger->warning("Invalid contact name in update", [
                    'name' => $nameValue,
                    'error' => $nameValidation['error']
                ]);
                $output->error("Invalid name: " . $nameValidation['error'], ErrorCodes::INVALID_NAME, 400);
                return;
            }
        }

        // Build update fields
        $updateFields = [];
        $updateData = ['address' => $address, 'field' => $field];

        // Validate and resolve currency for fee/credit updates
        $currency = null;
        if ($field === 'fee' || $field === 'credit') {
            $currency = strtoupper($value2);
        } elseif ($field === 'all') {
            if ($value4) {
                $currency = strtoupper($value4);
            } else {
                // Default to first accepted currency from contact_currencies
                $currency = Constants::TRANSACTION_DEFAULT_CURRENCY;
                if ($this->contactCurrencyRepository !== null) {
                    $pubkeyHash = hash(Constants::HASH_ALGORITHM, $contact['pubkey']);
                    $contactCurrencies = $this->contactCurrencyRepository->getContactCurrencies($pubkeyHash);
                    if (!empty($contactCurrencies)) {
                        $currency = $contactCurrencies[0]['currency'];
                    }
                }
            }
        }
        if ($currency !== null) {
            $currencyValidation = $this->inputValidator->validateCurrency($currency);
            if (!$currencyValidation['valid']) {
                $output->error("Invalid currency: " . $currencyValidation['error'], ErrorCodes::INVALID_FIELD, 400);
                return;
            }
            $currency = $currencyValidation['value'];
        }

        if ($field === 'name') {
            $updateFields['name'] = $value;
            $updateData['name'] = $value;
        } elseif ($field === 'fee') {
            $updateData['fee'] = $value;
            $updateData['currency'] = $currency;
        } elseif ($field === 'credit') {
            $updateData['credit'] = $value;
            $updateData['currency'] = $currency;
        } elseif ($field === 'all') {
            $updateFields['name'] = $value;
            $updateData['name'] = $value;
            $updateData['fee'] = $value2;
            $updateData['credit'] = $value3;
            $updateData['currency'] = $currency;
        }

        // Update name in contacts table if changed
        $contactUpdateOk = true;
        if (!empty($updateFields)) {
            $contactUpdateOk = $this->contactRepository->updateContactFields($contact['pubkey'], $updateFields);
        }

        // Update fee/credit in contact_currencies table
        if ($contactUpdateOk) {
            if ($this->contactCurrencyRepository !== null && $currency !== null) {
                $pubkeyHash = hash(Constants::HASH_ALGORITHM, $contact['pubkey']);
                $currencyFields = [];
                if ($field === 'fee' || $field === 'all') {
                    $feeValue = ($field === 'fee') ? $value : $value2;
                    $currencyFields['fee_percent'] = CurrencyUtilityService::exactMajorToMinor($feeValue, Constants::FEE_CONVERSION_FACTOR);
                }
                if ($field === 'credit' || $field === 'all') {
                    $creditValue = ($field === 'credit') ? $value : $value3;
                    $currencyFields['credit_limit'] = SplitAmount::from($creditValue);
                }
                if (!empty($currencyFields)) {
                    $this->contactCurrencyRepository->updateCurrencyConfig($pubkeyHash, $currency, $currencyFields);
                }
            }
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
     * @param string $currency Currency code
     * @return SplitAmount Credit limit
     */
    public function getCreditLimit(string $senderPublicKey, string $currency = Constants::TRANSACTION_DEFAULT_CURRENCY): SplitAmount
    {
        return $this->contactRepository->getCreditLimit($senderPublicKey, $currency);
    }

    /**
     * Add a new currency to an existing accepted contact
     *
     * Creates rows in contact_currencies, balances, and contact_credit
     * for the new currency relationship.
     *
     * @param string $pubkey Contact's public key
     * @param string $currency Currency code
     * @param float $fee Fee percentage
     * @param float $credit Credit limit
     * @return bool True on success
     */
    public function addCurrencyToContact(string $pubkey, string $currency, float|string $fee, float|string $credit): bool
    {
        // Verify contact is accepted
        if (!$this->contactRepository->isAcceptedContactPubkey($pubkey)) {
            return false;
        }

        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        // Check if currency already exists for this contact (outgoing direction)
        if ($this->contactCurrencyRepository !== null && $this->contactCurrencyRepository->hasCurrency($pubkeyHash, $currency, 'outgoing')) {
            return false;
        }

        // Insert into contact_currencies as outgoing (we are adding this currency)
        if ($this->contactCurrencyRepository !== null) {
            $feeMinor = CurrencyUtilityService::exactMajorToMinor((float) $fee, Constants::FEE_CONVERSION_FACTOR);
            $creditSplit = SplitAmount::from($credit);
            $this->contactCurrencyRepository->insertCurrencyConfig($pubkeyHash, $currency, $feeMinor, $creditSplit, 'pending', 'outgoing');
        }

        // Create initial balance entries for the new currency
        $this->balanceRepository->insertInitialContactBalances($pubkey, $currency);

        // Calculate and store available credit for the new currency
        if ($this->contactCreditRepository !== null) {
            try {
                $sentBalance = $this->balanceRepository->getContactSentBalance($pubkey, $currency);
                $receivedBalance = $this->balanceRepository->getContactReceivedBalance($pubkey, $currency);
                $balance = $sentBalance->subtract($receivedBalance);
                $creditLimit = $this->contactCurrencyRepository !== null
                    ? ($this->contactCurrencyRepository->getCreditLimit($pubkeyHash, $currency) ?? SplitAmount::zero())
                    : SplitAmount::zero();
                $this->contactCreditRepository->upsertAvailableCredit(
                    $pubkeyHash,
                    $balance->add($creditLimit),
                    $currency
                );
            } catch (\Exception $e) {
                $this->secureLogger->warning("Failed to store initial credit for new currency", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return true;
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
     * Paginated accepted contacts. Thin passthrough to the repository;
     * pagination semantics live there (LIMIT/OFFSET + stable name-sort).
     *
     * @param int $limit  Max rows per page
     * @param int $offset Zero-based offset
     */
    public function getAcceptedContactsPage(int $limit, int $offset = 0): array
    {
        return $this->contactRepository->getAcceptedContactsPage($limit, $offset);
    }

    /**
     * Fetch accepted, user-pending, and blocked contacts in a single DB query
     * and return them grouped by status. Replaces the pattern of calling
     * getAcceptedContacts + getUserPendingContactRequests + getBlockedContacts
     * separately on the same page render.
     *
     * @return array{accepted: array, user_pending: array, blocked: array}
     */
    public function getContactsGroupedByStatus(): array
    {
        return $this->contactRepository->getContactsGroupedByStatus();
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
     * @return array|null Contact addresses or null
     */
    public function lookupAddressesByName(string $name): ?array
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
    public function getAllAcceptedAddresses(?string $currency = null): array
    {
        return $this->contactRepository->getAllAcceptedAddresses($currency);
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
