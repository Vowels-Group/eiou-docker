<?php
# Copyright 2025

/**
 * GUI Service
 *
 * Handles all business logic for GUI management.
 *
 * @package Services
 */
class GuiService {
    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var P2pRepository P2P repository instance
     */
    private P2pRepository $p2pRepository;

    /**
     * @var RP2pRepository RP2P repository instance
     */
    private RP2pRepository $rp2pRepository;

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

    /**
     * @var ContactService Contact service class
     */
    private ContactService $contactService;

    /**
     * @var TransactionService Transaciton service class
     */
    private TransactionService $transactionService;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param RP2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param ContactService $contactService Contact Service Class
     * @param TransactionService $transactionService
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        P2pRepository $p2pRepository,
        RP2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        ContactService $contactService,
        TransactionService $transactionService,
        UserContext $currentUser  
    ) {
        $this->contactRepository = $contactRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->contactService = $contactService;
        $this->transactionService = $transactionService;
        $this->currentUser = $currentUser;
    }


    /**
     * Convert Contact Information back to proper units for display
     *
     * @param array $contacts Contact Information
     * @return array Converted contact information
     */
    public function contactConversion($contacts): array {
        // If no contacts, return empty array
        if (empty($contacts)) {
            return [];
        }

        // Extract all pubkeys for batch processing
        $pubkeys = array_column($contacts, 'pubkey');

        // Get all balances in a single optimized query
        $balances = $this->getAllContactBalances($this->currentUser->getPublicKey(), $pubkeys);

        // Build result array with balances
        $contactsWithBalances = [];
        foreach($contacts as $contact){
            // Get pre-calculated balance from batch query result
            $balance = $balances[$contact['pubkey']] ?? 0;

            $fee_percent = $contact['fee_percent'];
            $credit_limit = $contact['credit_limit'];


            $contactsWithBalances[] = [
                'name' => $contact['name'],
                'address' => $contact['address'],
                'balance' =>  $balance ? convertQuantityCurrency($balance) : $balance,
                'fee' =>  $fee_percent ? convertQuantityCurrency($fee_percent) : $fee_percent,
                'credit_limit' =>  $credit_limit ? convertQuantityCurrency($credit_limit) : $credit_limit,
                'currency' => $contact['currency']
            ];

        }
        return $contactsWithBalances;
    }

     /**
     * Parse contact output to a general format type
     *
     * @param string output from EIOU
     * @return array Parsed output
     */
    public function parseContactOutput($output) {
        $output = trim($output);
        
        // Success messages
        if (str_contains(strtolower($output), 'contact accepted.') !== false) {
            return ['message' => $output, 'type' => 'contact-accepted'];
        }

        // General success message
        if(str_contains(strtolower($output), 'success') !== false) {
            return ['message' => $output, 'type' => 'success'];
        }
        
        // Warning messages
        if (str_contains(strtolower($output), 'already been added or accepted') !== false) {
            return ['message' => $output, 'type' => 'warning'];
        }
        if (str_contains(strtolower($output), 'warning:') !== false) {
            return ['message' => $output, 'type' => 'warning'];
        }
        
        // Error messages
        if (str_contains(strtolower($output), 'failed') !== false) {
            return ['message' => $output . ' Please try again.', 'type' => 'error'];
        }
        if (str_contains(strtolower($output), 'not accepted by the recipient') !== false) {
            return ['message' => $output . ' Please try again or contact the recipient directly.', 'type' => 'error'];
        }
        if (str_contains(strtolower($output), 'not found') !== false) {
            return ['message' => $output, 'type' => 'error'];
        }
        if (str_contains(strtolower($output), 'no results found.') !== false) {
            return ['message' => $output, 'type' => 'error'];
        }
        
        // Generic error detection
        if (str_contains(strtolower($output), 'error') !== false || str_contains(strtolower($output), 'failed') !== false) {
            return ['message' => $output, 'type' => 'error'];
        }
        
        // Default success case
        return ['message' => $output, 'type' => 'success'];
    }


    // Contact Service Helper
    /**
     * Add a contact
     *
     * @param array $data Command line arguments
     * @return void
     */
    public function addContact($argv): void {
        $this->contactService->addContact($argv);
    }

    // Contact Repository Helpers
    /**
     * Delete a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function deleteContact($argv): bool {
        return $this->contactRepository->deleteContact($argv);
    }

    /**
     * Block a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function blockContact($argv): bool {
        return $this->contactRepository->blockContact($argv);
    }

    /**
     * Unblock a contact
     *
     * @param string $address Contact address
     * @return bool Success status
     */
    public function unblockContact($argv): bool {
        return $this->contactRepository->unblockContact($argv);
    }

    /**
     * Update specific contact fields through CLI interaction
     *
     * @param array $argv Command line arguments
     */
    public function updateContact($argv){
        return $this->contactRepository->updateContact($argv);
    }

    public function getAcceptedContacts(){
        return $this->contactRepository->getAcceptedContacts();
    }

    /**
     * Get all contacts
     *
     * @return array Array of contacts
     */
    public function getAllContacts(): array{
        return $this->contactRepository->getAllContactsInfo();
    }
    /**
     * Get all blocked contacts
     *
     * @return array Array of contacts
     */
    public function getBlockedContacts(): array {
        return $this->contactRepository->getBlockedContacts();
    }

    /**
     * Get pending contact requests
     *
     * @return array Array of pending contacts
     */
    public function getPendingContacts(): array {
        return $this->contactRepository->getPendingContactRequests();
    }

    /**
     * Get user initiated pending contact requests
     *
     * @return array Array of pending contacts
     */
    public function getUserPendingContacts(): array{
        return $this->contactRepository->getUserPendingContactRequests();
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
     * Lookup contact name by address
     *
     * @param string $address Contact address
     * @return string|null Contact name or null
     */
    public function getContactNameByAddress($address): ?string {
        return $this->contactRepository->lookupNameByAddress($address);
    }

    // Transaction Service Helper
    /**
     * Send eIOU
     *
     * @param array|null $request Request data
     * @return void|null
     */
    public function sendEiou(?array $request = null){
        return $this->transactionService->sendEiou($request);
    }

    // Transaction Repository Helpers
    /**
     * Get transaction history with limit
     *
     * @param int $limit
     * @return array
     */
    public function getTransactionHistory($limit = 10): array {
         return $this->transactionRepository->getTransactionHistory($limit);
    }

    /**
     * Check for new transactions since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewTransactions($lastCheckTime){
         return $this->transactionRepository->checkForNewTransactions($lastCheckTime);
    }

    /**
     * Get contact balance (optimized single query)
     *
     * @param string $userPubkey
     * @param string $contactPubkey
     * @return int Balance in cents
     */
    public function getContactBalance($userPubkey, $contactPubkey): int {
        return $this->transactionRepository->getContactBalance($userPubkey, $contactPubkey);
    }

    /**
     * Get all contact balances in a single optimized query (fixes N+1 problem)
     *
     * @param string $userPubkey
     * @param array $contactPubkeys
     * @return array Associative array of pubkey => balance
     */
    public function getAllContactBalances($userPubkey, $contactPubkeys): array {
        return $this->transactionRepository->getAllContactBalances($userPubkey, $contactPubkeys);
    }

    /**
     * Get users current balance
     *
     * @return string Balance 
     */
    public function getUserTotalBalance(): string {
        return $this->transactionRepository->getUserTotalBalance();
    }
}