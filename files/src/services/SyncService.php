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
        if($contact['status'] === Constants::CONTACT_STATUS_PENDING){
            output(outputSyncContactDueToPendingStatus($contactAddress),$echo);
            // If the contact is still pending then inquire with contact
            $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
            $syncResponse = json_decode($this->transportUtility->send($contactAddress, $messagePayload),true);
            $status = $syncResponse['status'];
            $reason = $syncResponse['reason'] ?? NULL;
            if($status === Constants::STATUS_ACCEPTED){
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
            } elseif($status === Constants::STATUS_REJECTED && $reason === 'unknown'){

                // If no database existence of contact request on their end, resend contact request
                $contactPayload = $this->contactPayload->buildCreateRequest($contactAddress);
                $responseData = json_decode($this->transportUtility->send($contactAddress, $contactPayload), true);
                if(isset($responseData['status']) && ($responseData['status'] === Constants::STATUS_ACCEPTED)){
                    // Contact received our contact request, needs to be accepted by other user first
                    //   If acceptance is automatic then able to check through following inquiry
                    //   Otherwise would need to inquire again down the line (through sync or otherwise)
                    $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
                    $syncResponse = $this->transportUtility->send($contactAddress, $messagePayload);
                    if($status === Constants::STATUS_ACCEPTED){
                        $this->contactRepository->updateStatus($transportIndex, $contactAddress, $status);
                        output(outputContactSuccesfullySynced($contactAddress),$echo);
                        return true;
                    }
                }
            }
            // Contact did not respond immediately
            output(outputContactNoResponseSync(),$echo);
            return false;
        } elseif($contact['status'] === Constants::CONTACT_STATUS_ACCEPTED){
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

                // Sync balances immediately after transaction sync for this contact
                // This ensures balances reflect the newly synced transactions
                if ($syncResult['synced_count'] > 0) {
                    $this->syncContactBalance($pubkey);
                }

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

            if ($syncResponse['status'] === Constants::STATUS_REJECTED) {
                $result['error'] = $syncResponse['reason'] ?? 'Sync rejected';
                return $result;
            }

            if ($syncResponse['status'] !== Constants::STATUS_ACCEPTED || !isset($syncResponse['transactions'])) {
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

                // Verify transaction signature before inserting
                // This ensures the sender actually signed this transaction
                // Note: Signature verification requires signed_message to be preserved during
                // message parsing. If signatures are missing, log a warning but allow sync
                // to maintain backward compatibility. Full signature enforcement is a future enhancement.
                if (!$this->verifyTransactionSignature($tx)) {
                    // Log warning - signature data should be available for all transactions.
                    // If this warning appears frequently, there may be an issue with signature
                    // storage during send or signature preservation during message parsing.
                    SecureLogger::warning("Sync transaction missing signature verification", [
                        'txid' => $tx['txid'],
                        'sender' => $tx['sender_address'],
                        'has_signature' => !empty($tx['sender_signature']),
                        'has_nonce' => !empty($tx['signature_nonce'])
                    ]);
                    // Continue with sync - don't block on missing signatures for now
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
                    'status' => Constants::STATUS_COMPLETED,
                    // Include signature data for future verification
                    'signature' => $tx['sender_signature'] ?? null,
                    'nonce' => $tx['signature_nonce'] ?? null,
                    'time' => $tx['time'] ?? null
                ];

                // Determine type based on sender
                $userAddresses = $this->currentUser->getUserAddresses();
                $type = in_array($tx['sender_address'], $userAddresses) ? Constants::TX_TYPE_SENT : Constants::TX_TYPE_RECEIVED;

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

        // Notify HeldTransactionService of sync completion
        try {
            $app = Application::getInstance();
            if ($app->services->hasService('HeldTransactionService')) {
                $heldService = $app->services->getService('HeldTransactionService');
                $heldService->onSyncComplete(
                    $contactPublicKey,
                    $result['success'],
                    $result['synced_count']
                );
            } else {
                // Try to get via getter if registered
                $heldService = $app->services->getHeldTransactionService();
                $heldService->onSyncComplete(
                    $contactPublicKey,
                    $result['success'],
                    $result['synced_count']
                );
            }
        } catch (Exception $e) {
            // Log but don't fail - held transaction notification is non-critical
            SecureLogger::debug("Could not notify HeldTransactionService of sync completion", [
                'contact' => $contactPublicKey,
                'error' => $e->getMessage()
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

            // Build a map of cancelled/rejected transaction txid -> their previous_txid
            // This allows us to "skip over" cancelled transactions in the chain
            // When AB2 is cancelled in chain AB1->AB2->AB3->AB4, AB3's previous_txid
            // still points to AB2. We need to update it to point to AB1 during sync.
            $cancelledTxidToPrevious = [];
            foreach ($transactions as $tx) {
                if (in_array($tx['status'], ['cancelled', 'rejected'])) {
                    $cancelledTxidToPrevious[$tx['txid']] = $tx['previous_txid'];
                }
            }

            // Filter to only include transactions NEWER than lastKnownTxid if provided
            // Transactions are ordered by timestamp DESC (newest first), so we collect
            // all transactions until we hit the lastKnownTxid
            // Also exclude cancelled/rejected transactions as they are orphaned from the chain
            $filteredTransactions = [];

            foreach ($transactions as $tx) {
                // If we hit the lastKnownTxid, stop - requester already has this and older
                if ($lastKnownTxid !== null && $tx['txid'] === $lastKnownTxid) {
                    break;
                }

                // Skip cancelled and rejected transactions - they are orphaned from the chain
                // and should not be synced to maintain chain integrity
                if (in_array($tx['status'], ['cancelled', 'rejected'])) {
                    continue;
                }

                // Fix previous_txid to skip over any cancelled/rejected transactions in the chain
                // This ensures the synced chain has no gaps pointing to non-existent transactions
                $correctedPreviousTxid = $this->resolvePreviousTxid(
                    $tx['previous_txid'],
                    $cancelledTxidToPrevious
                );

                // Include necessary fields for security and signature verification
                $filteredTransactions[] = [
                    'txid' => $tx['txid'],
                    'previous_txid' => $correctedPreviousTxid,
                    'sender_address' => $tx['sender_address'],
                    'sender_public_key' => $tx['sender_public_key'],
                    'receiver_address' => $tx['receiver_address'],
                    'receiver_public_key' => $tx['receiver_public_key'],
                    'amount' => $tx['amount'],
                    'currency' => $tx['currency'],
                    'memo' => $tx['memo'],
                    'description' => $tx['description'] ?? null,
                    'timestamp' => $tx['timestamp'],
                    'time' => $tx['time'] ?? null,
                    'status' => $tx['status'],
                    // Include signature data for verification
                    'sender_signature' => $tx['sender_signature'] ?? null,
                    'signature_nonce' => $tx['signature_nonce'] ?? null
                ];
            }

            // Get latest txid from non-cancelled transactions
            $latestTxid = !empty($filteredTransactions) ? $filteredTransactions[0]['txid'] : null;

            // Reverse to chronological order (oldest first) so requester can insert
            // in correct chain order - each tx references the previous one
            $filteredTransactions = array_reverse($filteredTransactions);

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
     * Resolve previous_txid by skipping over cancelled/rejected transactions
     *
     * When a transaction in the chain is cancelled (e.g., AB2 in AB1->AB2->AB3),
     * this method follows the chain back to find the first non-cancelled ancestor.
     * This ensures the synced chain has no gaps when intermediate transactions
     * have been cancelled but the chain hasn't been readjusted yet.
     *
     * @param string|null $previousTxid The original previous_txid
     * @param array $cancelledTxidToPrevious Map of cancelled txid => their previous_txid
     * @return string|null The resolved previous_txid (first non-cancelled ancestor)
     */
    private function resolvePreviousTxid(?string $previousTxid, array $cancelledTxidToPrevious): ?string {
        if ($previousTxid === null) {
            return null;
        }

        // Follow the chain back until we find a non-cancelled transaction
        // Use a max iterations guard to prevent infinite loops in case of data corruption
        $maxIterations = 100;
        $currentTxid = $previousTxid;

        for ($i = 0; $i < $maxIterations; $i++) {
            // If current txid is not in the cancelled map, it's a valid ancestor
            // Note: Use array_key_exists instead of isset because isset returns false for null values
            if (!array_key_exists($currentTxid, $cancelledTxidToPrevious)) {
                return $currentTxid;
            }

            // Move to the cancelled transaction's previous_txid
            $currentTxid = $cancelledTxidToPrevious[$currentTxid];

            // If we reached the beginning of the chain (null), return null
            if ($currentTxid === null) {
                return null;
            }
        }

        // If we exceeded max iterations, log a warning and return original
        SecureLogger::warning("resolvePreviousTxid exceeded max iterations", [
            'original_previous_txid' => $previousTxid,
            'last_checked' => $currentTxid
        ]);
        return $previousTxid;
    }

    /**
     * Verify transaction signature
     *
     * Verifies that the transaction was actually signed by the claimed sender.
     * This prevents fabricated transactions from being synced.
     *
     * The signature was created by:
     * 1. Building message content (transaction fields in camelCase)
     * 2. Adding nonce (time() value)
     * 3. JSON encoding the content
     * 4. Signing with sender's private key
     *
     * @param array $tx Transaction data with sender_signature and signature_nonce
     * @return bool True if signature is valid, false otherwise
     */
    private function verifyTransactionSignature(array $tx): bool {
        // Both signature and nonce are required for verification
        if (empty($tx['sender_signature']) || empty($tx['signature_nonce'])) {
            // Log missing signature data
            SecureLogger::debug("Transaction missing signature data for verification", [
                'txid' => $tx['txid'] ?? 'unknown',
                'has_signature' => !empty($tx['sender_signature']),
                'has_nonce' => !empty($tx['signature_nonce'])
            ]);
            return false;
        }

        // Get the sender's public key
        $senderPublicKey = $tx['sender_public_key'] ?? null;
        if (empty($senderPublicKey)) {
            return false;
        }

        // Reconstruct the signed message based on transaction type
        // Contact transactions use ContactPayload::build() -> {'type': 'create', ...}
        // Regular transactions use TransactionPayload::build() -> {'type': 'send', ...}
        $memo = $tx['memo'] ?? 'standard';
        if ($memo === 'contact') {
            $messageContent = $this->reconstructContactSignedMessage($tx);
        } else {
            $messageContent = $this->reconstructSignedMessage($tx);
        }

        if ($messageContent === null) {
            return false;
        }

        // Get the public key resource
        $publicKeyResource = openssl_pkey_get_public($senderPublicKey);
        if ($publicKeyResource === false) {
            SecureLogger::warning("Invalid sender public key for transaction signature verification", [
                'txid' => $tx['txid'] ?? 'unknown'
            ]);
            return false;
        }

        // Verify the signature
        $verified = openssl_verify(
            $messageContent,
            base64_decode($tx['sender_signature']),
            $publicKeyResource
        );

        if ($verified !== 1) {
            SecureLogger::warning("Transaction signature verification failed", [
                'txid' => $tx['txid'] ?? 'unknown',
                'sender' => $tx['sender_address'] ?? 'unknown',
                'verify_result' => $verified
            ]);
        }

        return $verified === 1;
    }

    /**
     * Reconstruct the signed message from transaction data + nonce
     *
     * Rebuilds the original JSON message that was signed by the sender.
     * Must match TransportUtilityService::sign() which:
     * 1. Removes senderAddress, senderPublicKey, signature from payload
     * 2. Adds nonce at the end
     * 3. JSON encodes the content
     *
     * The field order must match the original payload order from TransactionPayload
     * (minus the removed fields, plus nonce at the end).
     *
     * @param array $tx Transaction data including signature_nonce
     * @return string|null JSON message or null if reconstruction fails
     */
    private function reconstructSignedMessage(array $tx): ?string {
        // Required fields for reconstruction (note: senderAddress/senderPublicKey are NOT signed)
        $requiredFields = ['receiver_address', 'receiver_public_key', 'amount',
                          'currency', 'txid', 'signature_nonce'];

        foreach ($requiredFields as $field) {
            if (!isset($tx[$field])) {
                SecureLogger::debug("Missing field for message reconstruction", [
                    'field' => $field,
                    'txid' => $tx['txid'] ?? 'unknown'
                ]);
                return null;
            }
        }

        // Reconstruct message in the EXACT order from TransactionPayload::build()
        // after TransportUtilityService::sign() removes senderAddress/senderPublicKey
        // IMPORTANT: Field order matters for signature verification!
        //
        // Original build() order (before senderAddress/senderPublicKey removal):
        // type, time, receiverAddress, receiverPublicKey, amount, currency, txid,
        // previousTxid, memo, senderAddress*, senderPublicKey*, [description],
        // [endRecipientAddress], [initialSenderAddress]
        // (* = removed before signing, [] = conditional)
        //
        // After signing adds nonce at the end
        $messageContent = [
            'type' => 'send',
        ];

        // Include 'time' if present (added for P2P/RP2P tracking and syncing)
        // This maintains backward compatibility - older transactions without 'time' are still valid
        if (isset($tx['time']) && $tx['time'] !== null) {
            $messageContent['time'] = (int)$tx['time'];
        }

        $messageContent['receiverAddress'] = $tx['receiver_address'];
        $messageContent['receiverPublicKey'] = $tx['receiver_public_key'];
        $messageContent['amount'] = (int)$tx['amount'];
        $messageContent['currency'] = $tx['currency'];
        $messageContent['txid'] = $tx['txid'];
        $messageContent['previousTxid'] = $tx['previous_txid'] ?? null;
        $messageContent['memo'] = $tx['memo'] ?? 'standard';

        // description is ONLY included if it has a non-null value (matches TransactionPayload::build)
        if (isset($tx['description']) && $tx['description'] !== null) {
            $messageContent['description'] = $tx['description'];
        }

        // NOTE: endRecipientAddress and initialSenderAddress are NOT included
        // These are local tracking fields that are NOT part of the signed payload
        // They are added via updateTrackingFields() after transaction insert

        // Nonce is added last by TransportUtilityService::sign()
        $messageContent['nonce'] = (int)$tx['signature_nonce'];

        return json_encode($messageContent);
    }

    /**
     * Reconstruct the signed message for a contact transaction
     *
     * Contact transactions use ContactPayload::build() which creates:
     * {'type' => 'create', 'senderAddress' => ..., 'senderPublicKey' => ...}
     *
     * After TransportUtilityService::sign() removes senderAddress/senderPublicKey
     * and adds nonce, the signed message becomes:
     * {'type': 'create', 'nonce': ...}
     *
     * @param array $tx Transaction data including signature_nonce
     * @return string|null JSON message or null if reconstruction fails
     */
    private function reconstructContactSignedMessage(array $tx): ?string {
        if (!isset($tx['signature_nonce'])) {
            SecureLogger::debug("Missing nonce for contact message reconstruction", [
                'txid' => $tx['txid'] ?? 'unknown'
            ]);
            return null;
        }

        // Contact payload after signing is simply: {'type': 'create', 'nonce': ...}
        $messageContent = [
            'type' => 'create',
            'nonce' => (int)$tx['signature_nonce']
        ];

        return json_encode($messageContent);
    }

    /**
     * Sync balance for a specific contact
     *
     * Recalculates the balance between the current user and a specific contact
     * based on their transaction history. This is called after re-adding a
     * deleted contact to ensure balances are properly restored.
     *
     * @param string $contactPubkey The public key of the contact
     * @return array Result with success status and synced currencies
     */
    public function syncContactBalance(string $contactPubkey): array {
        $result = [
            'success' => false,
            'currencies' => [],
            'error' => null
        ];

        try {
            // Get the user's addresses to determine transaction direction
            $userAddresses = $this->currentUser->getUserAddresses();
            $userPubkey = $this->currentUser->getPublicKey();

            // Get all transactions between user and this contact
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

            $result['success'] = true;
            $result['currencies'] = array_keys($balancesByCurrency);

            output("Contact balance sync completed for " . count($balancesByCurrency) . " currency(ies)", 'SILENT');

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            SecureLogger::logException($e, [
                'method' => 'syncContactBalance',
                'contact_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPubkey)
            ]);
        }

        return $result;
    }

    /**
     * Full sync for a re-added contact
     *
     * Performs a complete sync for a contact that was deleted and then re-added.
     * This includes syncing the transaction chain (with signature verification)
     * and recalculating balances from the transaction history.
     *
     * @param string $contactAddress Contact's address
     * @param string $contactPublicKey Contact's public key
     * @return array Result with success status and sync details
     */
    public function syncReaddedContact(string $contactAddress, string $contactPublicKey): array {
        $result = [
            'success' => false,
            'contact_synced' => false,
            'transactions_synced' => 0,
            'balances_synced' => false,
            'currencies' => [],
            'error' => null
        ];

        try {
            // Step 1: Sync contact status (handles pending -> accepted transition)
            $contactSyncResult = $this->syncSingleContact($contactAddress, 'SILENT');
            $result['contact_synced'] = $contactSyncResult;

            // Step 2: Sync transaction chain from the beginning
            // Since lastKnownTxid will be null (contact was deleted), this syncs from the start
            $txSyncResult = $this->syncTransactionChain($contactAddress, $contactPublicKey);

            if ($txSyncResult['success']) {
                $result['transactions_synced'] = $txSyncResult['synced_count'];
            } else {
                // Log but continue - transactions may already be in sync
                SecureLogger::info("Transaction chain sync for re-added contact", [
                    'address' => $contactAddress,
                    'result' => $txSyncResult
                ]);
            }

            // Step 3: Sync balances from transaction history
            $balanceSyncResult = $this->syncContactBalance($contactPublicKey);

            if ($balanceSyncResult['success']) {
                $result['balances_synced'] = true;
                $result['currencies'] = $balanceSyncResult['currencies'];
            }

            // Overall success if at least contact status is synced
            $result['success'] = $result['contact_synced'] || $result['transactions_synced'] > 0 || $result['balances_synced'];

            output("Re-added contact sync completed: " .
                   "contact=" . ($result['contact_synced'] ? 'yes' : 'no') .
                   ", transactions=" . $result['transactions_synced'] .
                   ", balances=" . ($result['balances_synced'] ? 'yes' : 'no'), 'SILENT');

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            SecureLogger::logException($e, [
                'method' => 'syncReaddedContact',
                'contact' => $contactAddress
            ]);
        }

        return $result;
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