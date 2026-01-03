<?php
# Copyright 2025 The Vowels Company

require_once __DIR__ . '/../cli/CliOutputManager.php';
require_once __DIR__ . '/../core/ErrorCodes.php';

/**
 * Sync Service
 *
 * Handles all business logic for sync management.
 *
 * @package Services
 */
class SyncService {
    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var AddressRepository Address repository instance
     */
    private AddressRepository $addressRepository;

    /**
     * @var P2pRepository P2P repository instance
     */
    private P2pRepository $p2pRepository;

     /**
     * @var Rp2pRepository RP2P repository instance
     */
    private Rp2pRepository $rp2pRepository;

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

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
     * @var MessagePayload payload builder for messages
     */
    private MessagePayload $messagePayload;

    /**
     * Constructor
     * @param ContactRepository $contactRepository Contact repository
     * @param AddressRepository $addressRepository Address Repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param Rp2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
         AddressRepository $addressRepository,
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        BalanceRepository $balanceRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->balanceRepository = $balanceRepository;
        $this->utilityContainer = $utilityContainer;
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;
       
        require_once '/etc/eiou/src/schemas/payloads/ContactPayload.php';
        $this->contactPayload = new ContactPayload($this->currentUser,$this->utilityContainer);
       
        require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
        $this->transactionPayload = new TransactionPayload($this->currentUser,$this->utilityContainer);
      
        require_once '/etc/eiou/src/schemas/payloads/MessagePayload.php';
        $this->messagePayload = new MessagePayload($this->currentUser,$this->utilityContainer);
    }

    /**
     * Handler for sync through user-input
     *
     * @param array $argv Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function sync($argv, ?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        if(isset($argv[2])){
            $argument = strtolower($argv[2]);
            if($argument === 'contacts'){
                $this->syncAllContacts($output);
            } elseif($argument === 'transactions'){
                $this->syncAllTransactions($output);
            } elseif($argument === 'balances'){
                $this->syncAllBalances($output);
            } else {
                $output->error("Invalid sync type. Use 'contacts', 'transactions', or 'balances'", ErrorCodes::INVALID_SYNC_TYPE, 400, [
                    'valid_types' => ['contacts', 'transactions', 'balances']
                ]);
            }
        } else{
            $this->syncAll($output);
        }
    }

    /**
     * Sync all possible entities
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function syncAll(?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        // Sync both contacts and transactions
        $contactResults = $this->syncAllContactsInternal();
        $transactionResults = $this->syncAllTransactionsInternal();
        // Balances only synced after transactions synced!
        $balanceResults = $this->syncAllBalancesInternal();

        $output->success("Sync completed", [
            'contacts' => $contactResults,
            'transactions' => $transactionResults,
            'balances' => $balanceResults
        ], "Synced contacts, transactions and balances");
    }

    /**
     * Sync all contacts
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function syncAllContacts(?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        $results = $this->syncAllContactsInternal();

        $output->success("Contacts synced", $results, "Contact synchronization completed");
    }

    /**
     * Internal method to sync all contacts and return results
     *
     * @return array Sync results
     */
    private function syncAllContactsInternal(): array{
        $contacts = $this->addressRepository->getAllAddresses();
        $results = [
            'total' => count($contacts),
            'synced' => 0,
            'failed' => 0,
            'details' => []
        ];

        foreach ($contacts as $contact) {
            $address = $contact['http'] ?? $contact['tor'] ?? null;
            if ($address) {
                $success = $this->syncSingleContact($address, 'SILENT');
                if ($success) {
                    $results['synced']++;
                    $results['details'][] = ['address' => $address, 'status' => 'synced'];
                } else {
                    $results['failed']++;
                    $results['details'][] = ['address' => $address, 'status' => 'failed'];
                }
            }
        }

        return $results;
    }

    /**
     * Sync contact
     *
     * @param string $contactAddress Contact Address
     * @param string $echo 'ECHO' (to user & log) or 'SILENT' (only to log)
     * @return bool True if synced successfully, false otherwise
     */
    public function syncSingleContact($contactAddress, $echo='SILENT'): bool{
        // Sync specific contact based on address
        $transportIndex = $this->transportUtility->determineTransportType($contactAddress);
        $contact = $this->contactRepository->getContactByAddress($transportIndex, $contactAddress); // Get contact from database
        if($contact['status'] === 'pending'){
            output(outputSyncContactDueToPendingStatus($contactAddress),$echo);
            // If the contact is still pending then inquire with contact
            $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
            $syncResponse = json_decode($this->transportUtility->send($contactAddress, $messagePayload),true);
            $status = $syncResponse['status'];
            $reason = $syncResponse['reason'] ?? NULL;
            if($status === 'accepted'){
                $senderPublicKey = $syncResponse['senderPublicKey'];
                $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
                $transportIndexAssociative = $this->transportUtility->determineTransportTypeAssociative($contactAddress);

                // If you are accepted as a contact by the contact in question then update accordingly
                $this->contactRepository->updateStatus($senderPublicKey, $status);
                $this->addressRepository->updateContactFields($senderPublicKeyHash, $transportIndexAssociative);

                // Complete the contact transaction (update status from 'sent' to 'completed')
                $this->transactionRepository->completeContactTransaction($senderPublicKey);

                output(outputContactSuccesfullySynced($contactAddress),$echo);
                return true;
            } elseif($status === 'rejected' && $reason === 'unknown'){

                // If no database existence of contact request on their end, resend contact request
                $contactPayload = $this->contactPayload->buildCreateRequest($contactAddress);
                $responseData = json_decode($this->transportUtility->send($contactAddress, $contactPayload), true);
                if(isset($responseData['status']) && ($responseData['status'] === 'accepted')){
                    // Contact received our contact request, needs to be accepted by other user first
                    //   If acceptance is automatic then able to check through following inquiry
                    //   Otherwise would need to inquire again down the line (through sync or otherwise)
                    $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
                    $syncResponse = $this->transportUtility->send($contactAddress, $messagePayload);
                    if($status === 'accepted'){
                        $this->contactRepository->updateStatus($transportIndex, $contactAddress, $status);
                        output(outputContactSuccesfullySynced($contactAddress),$echo);
                        return true;
                    }
                }
            }
            // Contact did not respond immediately
            output(outputContactNoResponseSync(),$echo);
            return false;
        } elseif($contact['status'] === 'accepted'){
            // If contact needs no syncing
            //output(outputContactNoNeedSync($contactAddress),'SILENT');
            return true;
        }
        return true;
    }

    /**
     * Sync all transactions
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function syncAllTransactions(?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        $results = $this->syncAllTransactionsInternal();

        $output->success("Transactions synced", $results, "Transaction synchronization completed");
    }

    /**
     * Internal method to sync all transactions and return results
     *
     * @return array Sync results
     */
    private function syncAllTransactionsInternal(): array {
        $contacts = $this->addressRepository->getAllAddresses();
        $results = [
            'total' => count($contacts),
            'synced' => 0,
            'failed' => 0,
            'total_transactions' => 0,
            'details' => []
        ];

        foreach ($contacts as $contact) {
            $address = $contact['http'] ?? $contact['tor'] ?? null;
            $pubkey = $contact['pubkey'] ?? null;

            if (!$address || !$pubkey) {
                continue;
            }

            $syncResult = $this->syncTransactionChain($address, $pubkey);

            if ($syncResult['success']) {
                $results['synced']++;
                $results['total_transactions'] += $syncResult['synced_count'];
                $results['details'][] = [
                    'address' => $address,
                    'status' => 'synced',
                    'transactions' => $syncResult['synced_count']
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'address' => $address,
                    'status' => 'failed',
                    'error' => $syncResult['error']
                ];
            }
        }

        return $results;
    }

    /**
     * Sync singular (specific) Transaction
     *
     * @return bool True if synced successfully, false otherwise
     */
    public function syncTransaction(): bool {
        // Sync specific
        return true;
    }

    /**
     * Sync transaction chain with a specific contact
     *
     * Called when a transaction is rejected due to invalid_previous_txid.
     * Requests missing transactions from the contact and inserts them locally.
     *
     * @param string $contactAddress Contact's address
     * @param string $contactPublicKey Contact's public key
     * @param string|null $expectedTxid The txid the contact expected (from rejection)
     * @return array Result with success, synced_count, latest_txid, error
     */
    public function syncTransactionChain(string $contactAddress, string $contactPublicKey, ?string $expectedTxid = null): array {
        $result = [
            'success' => false,
            'synced_count' => 0,
            'latest_txid' => null,
            'error' => null
        ];

        try {
            // Get our latest known txid with this contact
            $lastKnownTxid = $this->transactionRepository->getPreviousTxid(
                $this->currentUser->getPublicKey(),
                $contactPublicKey
            );

            // Build and send sync request
            $syncRequest = $this->messagePayload->buildTransactionSyncRequest(
                $contactAddress,
                $contactPublicKey,
                $lastKnownTxid
            );

            output("Requesting transaction chain sync with {$contactAddress}", 'SILENT');

            $syncResponse = json_decode(
                $this->transportUtility->send($contactAddress, $syncRequest),
                true
            );

            if (!$syncResponse || !isset($syncResponse['status'])) {
                $result['error'] = 'Invalid sync response';
                return $result;
            }

            if ($syncResponse['status'] === 'rejected') {
                $result['error'] = $syncResponse['reason'] ?? 'Sync rejected';
                return $result;
            }

            if ($syncResponse['status'] !== 'accepted' || !isset($syncResponse['transactions'])) {
                $result['error'] = 'Unexpected sync response';
                return $result;
            }

            // Process the received transactions
            $transactions = $syncResponse['transactions'];
            $syncedCount = 0;

            foreach ($transactions as $tx) {
                // Check if transaction already exists
                if ($this->transactionRepository->transactionExistsTxid($tx['txid'])) {
                    continue;
                }

                // Insert the missing transaction
                $insertData = [
                    'senderAddress' => $tx['sender_address'],
                    'senderPublicKey' => $tx['sender_public_key'],
                    'receiverAddress' => $tx['receiver_address'],
                    'receiverPublicKey' => $tx['receiver_public_key'],
                    'amount' => $tx['amount'],
                    'currency' => $tx['currency'],
                    'txid' => $tx['txid'],
                    'previousTxid' => $tx['previous_txid'] ?? null,
                    'memo' => $tx['memo'] ?? 'standard',
                    'description' => $tx['description'] ?? null,
                    'status' => 'completed'
                ];

                // Determine type based on sender
                $userAddresses = $this->currentUser->getUserAddresses();
                $type = in_array($tx['sender_address'], $userAddresses) ? 'sent' : 'received';

                $this->transactionRepository->insertTransaction($insertData, $type);
                $syncedCount++;
            }

            $result['success'] = true;
            $result['synced_count'] = $syncedCount;
            $result['latest_txid'] = $syncResponse['latestTxid'] ?? null;

            output("Transaction chain sync completed: {$syncedCount} transactions synced", 'SILENT');

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            SecureLogger::logException($e, [
                'method' => 'syncTransactionChain',
                'contact' => $contactAddress
            ]);
        }

        return $result;
    }

    /**
     * Handle incoming transaction sync request
     *
     * Called from MessageService when receiving a sync request.
     * Returns transactions between the user and requesting contact.
     *
     * @param array $request The sync request data
     * @return void Outputs JSON response
     */
    public function handleTransactionSyncRequest(array $request): void {
        $senderAddress = $request['senderAddress'];
        $senderPublicKey = $request['senderPublicKey'];
        $lastKnownTxid = $request['lastKnownTxid'] ?? null;

        // Verify the sender is a known contact
        if (!$this->contactRepository->contactExistsPubkey($senderPublicKey)) {
            echo $this->messagePayload->buildTransactionSyncRejection($senderAddress, 'unknown_contact');
            return;
        }

        try {
            // Get all transactions between user and this contact
            $transactions = $this->transactionRepository->getTransactionsBetweenPubkeys(
                $this->currentUser->getPublicKey(),
                $senderPublicKey
            );

            // Filter to only include transactions after lastKnownTxid if provided
            $filteredTransactions = [];
            $foundLastKnown = ($lastKnownTxid === null);

            foreach ($transactions as $tx) {
                if (!$foundLastKnown) {
                    if ($tx['txid'] === $lastKnownTxid) {
                        $foundLastKnown = true;
                    }
                    continue;
                }
                // Include only necessary fields for security
                $filteredTransactions[] = [
                    'txid' => $tx['txid'],
                    'previous_txid' => $tx['previous_txid'],
                    'sender_address' => $tx['sender_address'],
                    'sender_public_key' => $tx['sender_public_key'],
                    'receiver_address' => $tx['receiver_address'],
                    'receiver_public_key' => $tx['receiver_public_key'],
                    'amount' => $tx['amount'],
                    'currency' => $tx['currency'],
                    'memo' => $tx['memo'],
                    'timestamp' => $tx['timestamp'],
                    'status' => $tx['status']
                ];
            }

            // Get latest txid
            $latestTxid = !empty($transactions) ? $transactions[0]['txid'] : null;

            echo $this->messagePayload->buildTransactionSyncResponse(
                $senderAddress,
                $filteredTransactions,
                $latestTxid
            );

        } catch (Exception $e) {
            SecureLogger::logException($e, [
                'method' => 'handleTransactionSyncRequest',
                'sender' => $senderAddress
            ]);
            echo $this->messagePayload->buildTransactionSyncRejection($senderAddress, 'internal_error');
        }
    }

    /**
     * Sync all balances
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function syncAllBalances(?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        $results = $this->syncAllBalancesInternal();

        $output->success("Balances synced", $results, "Balance synchronization completed");
    }

    /**
     * Internal method to sync all balances and return results
     *
     * @return array Sync results
     */
    private function syncAllBalancesInternal(): array {
        // Get all contacts with their pubkeys
        $contacts = $this->contactRepository->getAllContactsPubkeys();

        $results = [
            'total_contacts' => count($contacts),
            'synced' => 0,
            'failed' => 0,
            'details' => []
        ];

        // Get the user's addresses to determine transaction direction
        $userAddresses = $this->currentUser->getUserAddresses();

        foreach ($contacts as $contactPubkey) {
            try {
                // Get all transactions between user and this contact
                $userPubkey = $this->currentUser->getPublicKey();
                $transactions = $this->transactionRepository->getTransactionsBetweenPubkeys($userPubkey, $contactPubkey);

                // Calculate balances from transactions
                $balancesByCurrency = [];

                foreach ($transactions as $transaction) {
                    $currency = $transaction['currency'];

                    // Initialize currency if not exists
                    if (!isset($balancesByCurrency[$currency])) {
                        $balancesByCurrency[$currency] = [
                            'received' => 0,
                            'sent' => 0
                        ];
                    }

                    // Determine if user sent or received this transaction
                    if (in_array($transaction['sender_address'], $userAddresses)) {
                        // User sent this transaction
                        $balancesByCurrency[$currency]['sent'] += $transaction['amount'];
                    } elseif (in_array($transaction['receiver_address'], $userAddresses)) {
                        // User received this transaction
                        $balancesByCurrency[$currency]['received'] += $transaction['amount'];
                    }
                }

                // Update or insert balances for each currency
                $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);

                foreach ($balancesByCurrency as $currency => $amounts) {
                    // Check if balance record exists
                    $existingBalance = $this->balanceRepository->getContactBalance($contactPubkey, $currency);

                    if ($existingBalance && count($existingBalance) > 0) {
                        // Update existing balance - use raw SQL to set exact values instead of incrementing
                       $this->balanceRepository->updateBothDirectionBalance($amounts, $contactPubkeyHash, $currency);
                    } else {
                        // Insert new balance record
                        $this->balanceRepository->insertBalance(
                            $contactPubkey,
                            $amounts['received'],
                            $amounts['sent'],
                            $currency
                        );
                    }
                }

                $results['synced']++;
                $results['details'][] = [
                    'contact_pubkey_hash' => $contactPubkeyHash,
                    'status' => 'synced',
                    'currencies' => array_keys($balancesByCurrency)
                ];

            } catch (Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'contact_pubkey' => $contactPubkey,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}