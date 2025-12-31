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
     * Syncs transactions that are in 'sent' or 'pending' status by inquiring
     * with the counterparty about the transaction status.
     *
     * @return array Sync results
     */
    private function syncAllTransactionsInternal(): array {
        // Get all transactions where we are the sender and status is 'sent' or 'pending'
        // Exclude contact transactions (handled by contact sync)
        $userPubkey = $this->currentUser->getPublicKey();
        $transactions = $this->transactionRepository->getTransactionsBySenderPubkeyAndStatus(
            $userPubkey,
            ['sent', 'pending']
        );

        // Filter out contact transactions
        $transactions = array_filter($transactions, function($tx) {
            return $tx['tx_type'] !== 'contact';
        });

        $results = [
            'total' => count($transactions),
            'synced' => 0,
            'failed' => 0,
            'details' => []
        ];

        foreach ($transactions as $transaction) {
            $success = $this->syncSingleTransaction($transaction);
            if ($success) {
                $results['synced']++;
                $results['details'][] = [
                    'txid' => $transaction['txid'],
                    'status' => 'synced'
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'txid' => $transaction['txid'],
                    'status' => 'failed'
                ];
            }
        }

        return $results;
    }

    /**
     * Sync single transaction with cascading chain inquiry
     *
     * For P2P transactions, this sends the inquiry to the direct intermediary contact,
     * not to the end-recipient. The inquiry cascades through the chain:
     * A->B->C->D: A sends inquiry to B, B forwards to C, C forwards to D, D responds back
     *
     * @param array $transaction Transaction data
     * @return bool True if synced successfully, false otherwise
     */
    public function syncSingleTransaction(array $transaction): bool {
        $txType = $transaction['tx_type'];
        $status = $transaction['status'];

        // Only sync sent/pending transactions
        if (!in_array($status, ['sent', 'pending'])) {
            return true; // Already synced or not applicable
        }

        try {
            if ($txType === 'standard') {
                // Direct transaction to known contact - send inquiry directly to receiver
                return $this->syncDirectTransaction($transaction);
            } elseif ($txType === 'p2p') {
                // P2P transaction - use cascading inquiry through chain
                return $this->syncP2pTransaction($transaction);
            }

            return false;
        } catch (Exception $e) {
            if (function_exists('output')) {
                output("[SyncService] Transaction sync error: " . $e->getMessage(), 'SILENT');
            }
            return false;
        }
    }

    /**
     * Sync direct (standard) transaction
     *
     * @param array $transaction Transaction data
     * @return bool True if synced successfully
     */
    private function syncDirectTransaction(array $transaction): bool {
        $txid = $transaction['txid'];
        $receiverAddress = $transaction['receiver_address'];

        // Build inquiry payload
        $inquiryPayload = $this->messagePayload->buildTransactionCompletedInquiry([
            'hash' => $txid,
            'hashType' => 'txid'
        ]);

        // Send inquiry to receiver
        $response = json_decode(
            $this->transportUtility->send($receiverAddress, $inquiryPayload),
            true
        );

        if ($response && $response['status'] === 'completed') {
            $this->transactionRepository->updateStatus($txid, 'completed', true);
            if (function_exists('output') && function_exists('outputTransactionSuccesfullySynced')) {
                output(outputTransactionSuccesfullySynced($txid), 'SILENT');
            }
            return true;
        }

        return false;
    }

    /**
     * Sync P2P transaction using cascading chain inquiry
     *
     * Instead of sending inquiry directly to end-recipient (which we may not have as contact),
     * we send to our intermediary contact who forwards it through the chain.
     *
     * Chain example: A->B->C->D
     * - A sends inquiry to B (intermediary)
     * - B receives, checks local status, forwards to C
     * - C receives, checks local status, forwards to D
     * - D receives, checks local status, responds 'completed' back to C
     * - C marks complete, responds back to B
     * - B marks complete, responds back to A
     * - A marks complete
     *
     * @param array $transaction Transaction data
     * @return bool True if synced successfully
     */
    private function syncP2pTransaction(array $transaction): bool {
        $memo = $transaction['memo'];

        if (!$memo) {
            return false;
        }

        // Get P2P record
        $p2p = $this->p2pRepository->getByHash($memo);
        if (!$p2p) {
            return false;
        }

        // Check if we're the original sender (have destination_address set)
        $isOriginalSender = !empty($p2p['destination_address']);

        if ($isOriginalSender) {
            // Original sender: find intermediary contact from rp2p table
            $intermediary = $this->rp2pRepository->getChainIntermediaryContact($memo);

            if (!$intermediary) {
                // No intermediary found - transaction never propagated
                if (function_exists('output')) {
                    output("[SyncService] No intermediary found for P2P transaction {$memo}", 'SILENT');
                }
                return false;
            }

            // Build cascading inquiry payload
            $inquiryPayload = $this->messagePayload->buildTransactionCompletedInquiry([
                'hash' => $memo,
                'hashType' => 'memo',
                'description' => $p2p['description'] ?? null,
                'cascading' => true // Flag for intermediaries to forward
            ]);

            // Send inquiry to intermediary (not end-recipient)
            $response = json_decode(
                $this->transportUtility->send($intermediary['address'], $inquiryPayload),
                true
            );

            if ($response && $response['status'] === 'completed') {
                // Chain completed successfully
                $this->p2pRepository->updateStatus($memo, 'completed', true);
                $this->transactionRepository->updateStatus($memo, 'completed');

                // Get all transactions with this memo for balance update
                $transactions = $this->transactionRepository->getByMemo($memo);
                $this->balanceRepository->updateBalanceGivenTransactions($transactions);

                if (function_exists('output') && function_exists('outputTransactionP2pSentSuccesfully')) {
                    output(outputTransactionP2pSentSuccesfully($p2p), 'SILENT');
                }

                return true;
            } elseif ($response && isset($response['chain_status'])) {
                // Partial chain failure - intermediary responded but chain incomplete
                if (function_exists('output')) {
                    output("[SyncService] P2P chain incomplete at: " . ($response['failed_at'] ?? 'unknown'), 'SILENT');
                }
                return false;
            }

            return false;
        } else {
            // We're an intermediary/relay - check local status only
            // (sync is driven by original sender)
            return $p2p['status'] === 'completed';
        }
    }

    /**
     * Sync a specific transaction by txid
     *
     * @param string $txid Transaction ID to sync
     * @return bool True if synced successfully, false otherwise
     */
    public function syncTransactionByTxid(string $txid): bool {
        $transactions = $this->transactionRepository->getByTxid($txid);

        if (empty($transactions)) {
            return false;
        }

        // Get the first transaction with this txid
        $transaction = is_array($transactions) && isset($transactions[0]) ? $transactions[0] : $transactions;

        return $this->syncSingleTransaction($transaction);
    }

    /**
     * Sync a specific transaction by memo/hash
     *
     * @param string $memo Transaction memo/hash to sync
     * @return bool True if synced successfully, false otherwise
     */
    public function syncTransactionByMemo(string $memo): bool {
        $transactions = $this->transactionRepository->getByMemo($memo);

        if (empty($transactions)) {
            return false;
        }

        // Get the first transaction with this memo
        $transaction = is_array($transactions) && isset($transactions[0]) ? $transactions[0] : $transactions;

        return $this->syncSingleTransaction($transaction);
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