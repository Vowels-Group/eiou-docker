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
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var ContactPayload payload builder for contact
     */
    private ContactPayload $contactPayload;

    /**
     * @var P2pPayload payload builder for P2P
     */
    private P2pPayload $p2pPayload;

    /**
     * @var Rp2pPayload payload builder for RP2P
     */
    private Rp2pPayload $rp2pPayload;

    /**
     * @var TransactionPayload payload builder for transactions
     */
    private TransactionPayload $transactionPayload;

    /**
     * @var UtilPayload payload builder for utility
     */
    private UtilPayload $utilPayload;

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param RP2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param ContactService $contactService Contact Service Class
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        P2pRepository $p2pRepository,
        RP2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        ContactService $contactService,
        UserContext $currentUser  
    ) {
        $this->contactRepository = $contactRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->contactService = $contactService;
        $this->currentUser = $currentUser;
        $this->contactPayload = new ContactPayload($this->currentUser);
        $this->p2pPayload = new P2pPayload($this->currentUser);
        $this->rp2pPayload = new Rp2pPayload($this->currentUser);
        $this->transactionPayload = new TransactionPayload($this->currentUser);
        $this->utilPayload = new UtilPayload($this->currentUser);
    }

    function contactConversion($contacts){
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

    function parseContactOutput($output) {
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

    function addContact($argv){
        return $this->contactService->addContact($argv);
    }

    function deleteContact($argv){
        return $this->contactRepository->deleteContact($argv);
    }

    function blockContact($argv){
        return $this->contactRepository->blockContact($argv);
    }

    function unblockContact($argv){
        return $this->contactRepository->unblockContact($argv);
    }

    function updateContact($argv){
        return $this->contactRepository->updateContact($argv);
    }

    function getAcceptedContacts(){
        return $this->contactRepository->getAcceptedContacts();
    }

    function getAllContacts(){
        return $this->contactRepository->getAllContactsInfo();
    }

    function getBlockedContacts() {
        return $this->contactRepository->getBlockedContacts();
    }

    function getPendingContacts(){
        return $this->contactRepository->getPendingContactRequests();
    }

    function getUserPendingContacts(){
        return $this->contactRepository->getUserPendingContactRequests();
    }

    function getTransactionHistory($limit = 10){
         return $this->transactionRepository->getTransactionHistory($limit);
    }

    function checkForNewTransactions($lastCheckTime){
         return $this->transactionRepository->checkForNewTransactions($lastCheckTime);
    }

    function getContactBalance($userPubkey, $contactPubkey){
        return $this->transactionRepository->getContactBalance($userPubkey, $contactPubkey);
    }

    function getAllContactBalances($userPubkey, $contactPubkeys){
        return $this->transactionRepository->getAllContactBalances($userPubkey, $contactPubkeys);
    }

    function checkForNewContactRequests($lastCheckTime){
        return $this->contactRepository->checkForNewContactRequests($lastCheckTime);
    }

    function getContactNameByAddress($address) {
        return $this->contactRepository->lookupNameByAddress($address);
    }

    function getUserTotalBalance(){
        return $this->transactionRepository->getUserTotalBalance();
    }
}