<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Contracts\SyncServiceInterface;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\HeldTransactionRepository;
use Eiou\Events\EventDispatcher;
use Eiou\Events\SyncEvents;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Schemas\Payloads\ContactPayload;
use Eiou\Schemas\Payloads\TransactionPayload;
use Eiou\Schemas\Payloads\MessagePayload;
use RuntimeException;
use Exception;

/**
 * Sync Service
 *
 * Handles all business logic for sync management.
 *
 * SECTION INDEX:
 * - Properties & Constructor............. Line ~14
 * - CLI Sync Entry Points................ Line ~122
 * - Contact Sync Operations.............. Line ~169
 * - Transaction Sync Operations.......... Line ~274
 * - Chain Conflict Resolution............ Line ~652
 * - Sync Request Handling................ Line ~870
 * - Signature Verification............... Line ~970
 * - Balance Sync Operations.............. Line ~1177
 * - Bidirectional Sync................... Line ~1444
 */
class SyncService implements SyncServiceInterface, SyncTriggerInterface {

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
     * @var HeldTransactionService|null Held transaction service for sync notifications
     */
    private ?HeldTransactionService $heldTransactionService = null;

    /**
     * @var TransactionChainRepository Transaction chain repository instance
     */
    private TransactionChainRepository $transactionChainRepository;

    /**
     * @var TransactionContactRepository Transaction contact repository instance
     */
    private TransactionContactRepository $transactionContactRepository;

    /**
     * Set the held transaction service (setter injection for circular dependency)
     *
     * @param HeldTransactionService $service Held transaction service
     */
    public function setHeldTransactionService(HeldTransactionService $service): void {
        $this->heldTransactionService = $service;
    }

    /**
     * Get the held transaction service (must be injected via setHeldTransactionService)
     *
     * @return HeldTransactionService
     * @throws RuntimeException If held transaction service was not injected
     */
    private function getHeldTransactionService(): HeldTransactionService {
        if ($this->heldTransactionService === null) {
            throw new RuntimeException('HeldTransactionService not injected. Call setHeldTransactionService() or ensure ServiceContainer::wireCircularDependencies() is called.');
        }
        return $this->heldTransactionService;
    }

    /**
     * Constructor
     * @param ContactRepository $contactRepository Contact repository
     * @param AddressRepository $addressRepository Address Repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param Rp2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param TransactionChainRepository $transactionChainRepository Transaction chain repository
     * @param TransactionContactRepository $transactionContactRepository Transaction contact repository
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
        TransactionChainRepository $transactionChainRepository,
        TransactionContactRepository $transactionContactRepository,
        BalanceRepository $balanceRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->transactionChainRepository = $transactionChainRepository;
        $this->transactionContactRepository = $transactionContactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->utilityContainer = $utilityContainer;
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;

        $this->contactPayload = new ContactPayload($this->currentUser, $this->utilityContainer);
        $this->transactionPayload = new TransactionPayload($this->currentUser, $this->utilityContainer);
        $this->messagePayload = new MessagePayload($this->currentUser, $this->utilityContainer);
    }

    // =========================================================================
    // CLI SYNC ENTRY POINTS
    // =========================================================================

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

    // =========================================================================
    // CONTACT SYNC OPERATIONS
    // =========================================================================

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
            $address = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null;
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
        if ($transportIndex === null) {
            output("Invalid contact address format: $contactAddress", $echo);
            return false;
        }
        $contact = $this->contactRepository->getContactByAddress($transportIndex, $contactAddress); // Get contact from database
        if (!$contact || $contact['status'] === Constants::CONTACT_STATUS_PENDING){
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
                $this->transactionContactRepository->completeContactTransaction($senderPublicKey);

                output(outputContactSuccesfullySynced($contactAddress),$echo);

                // Dispatch contact synced event
                EventDispatcher::getInstance()->dispatch(SyncEvents::CONTACT_SYNCED, [
                    'contact_pubkey' => $senderPublicKey,
                    'contact_address' => $contactAddress,
                    'status' => $status,
                    'was_pending' => true
                ]);

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

                        // Dispatch contact synced event
                        EventDispatcher::getInstance()->dispatch(SyncEvents::CONTACT_SYNCED, [
                            'contact_pubkey' => $senderPublicKey ?? null,
                            'contact_address' => $contactAddress,
                            'status' => $status,
                            'was_pending' => true
                        ]);

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

    // =========================================================================
    // TRANSACTION SYNC OPERATIONS
    // =========================================================================

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
            'chain_gaps' => 0,
            'details' => []
        ];

        foreach ($contacts as $contact) {
            $address = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null;
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

                $detail = [
                    'address' => $address,
                    'status' => 'synced',
                    'transactions' => $syncResult['synced_count']
                ];

                // Report chain gaps that persist after sync
                if (isset($syncResult['chain_valid']) && !$syncResult['chain_valid']) {
                    $gapCount = count($syncResult['chain_gaps'] ?? []);
                    $results['chain_gaps'] += $gapCount;
                    $detail['chain_gaps'] = $gapCount;
                    $detail['status'] = 'synced_with_gaps';
                    $detail['message'] = "Chain has {$gapCount} gap(s) - both sides missing same transactions. Use 'chaindrop' to resolve.";
                }

                $results['details'][] = $detail;
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
     * SECURITY: If signature verification fails for any transaction, syncing
     * is immediately stopped. The failing transaction and all subsequent
     * transactions are NOT inserted to prevent forged transaction injection.
     *
     * CHAIN CONFLICT RESOLUTION: When both parties create transactions simultaneously
     * with the same previous_txid (chain fork), deterministic ordering is used:
     * - The transaction with the lexicographically lower txid "wins"
     * - The "losing" transaction updates its previous_txid to point to the winner
     * - This ensures both parties converge to the same chain order
     *
     * @param string $contactAddress Contact's address
     * @param string $contactPublicKey Contact's public key
     * @param string|null $expectedTxid The txid the contact expected (from rejection)
     * @return array Result with:
     *   - success: bool - Whether sync completed successfully
     *   - synced_count: int - Number of transactions synced
     *   - latest_txid: string|null - Latest transaction ID from sync
     *   - error: string|null - Error message if failed
     *   - signature_failure: bool - True if stopped due to signature failure
     *   - failed_txid: string|null - TXID that failed verification
     *   - failed_sender: string|null - Sender address of failed transaction
     *   - synced_before_failure: int - Transactions synced before failure
     *   - conflicts_resolved: int - Number of chain conflicts resolved
     */
    public function syncTransactionChain(string $contactAddress, string $contactPublicKey, ?string $expectedTxid = null): array {
        $result = [
            'success' => false,
            'synced_count' => 0,
            'latest_txid' => null,
            'error' => null,
            'signature_failure' => false,
            'failed_txid' => null,
            'failed_sender' => null,
            'synced_before_failure' => 0,
            'conflicts_resolved' => 0
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
                EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_FAILED, [
                    'contact_pubkey' => $contactPublicKey,
                    'contact_address' => $contactAddress,
                    'error' => 'Invalid sync response',
                    'error_code' => 'invalid_response'
                ]);
                return $result;
            }

            if ($syncResponse['status'] === Constants::STATUS_REJECTED) {
                $result['error'] = $syncResponse['reason'] ?? 'Sync rejected';
                EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_FAILED, [
                    'contact_pubkey' => $contactPublicKey,
                    'contact_address' => $contactAddress,
                    'error' => $result['error'],
                    'error_code' => 'sync_rejected'
                ]);
                return $result;
            }

            if ($syncResponse['status'] !== Constants::STATUS_ACCEPTED || !isset($syncResponse['transactions'])) {
                $result['error'] = 'Unexpected sync response';
                EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_FAILED, [
                    'contact_pubkey' => $contactPublicKey,
                    'contact_address' => $contactAddress,
                    'error' => 'Unexpected sync response',
                    'error_code' => 'unexpected_response'
                ]);
                return $result;
            }

            // Process the received transactions
            $transactions = $syncResponse['transactions'];
            $syncedCount = 0;
            $conflictsResolved = 0;

            // Get pubkey hashes for conflict detection
            $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());
            $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);

            foreach ($transactions as $tx) {
                // Check if transaction already exists
                if ($this->transactionRepository->transactionExistsTxid($tx['txid'])) {
                    continue;
                }

                // Check for chain conflict: do we have a different transaction with the same previous_txid?
                // When both parties create transactions simultaneously, they may reference the same
                // previous_txid. We resolve this deterministically using lexicographic txid comparison.
                $remotePreviousTxid = $tx['previous_txid'] ?? null;
                $localLoserToResign = null;  // Track local transaction that needs re-signing

                if ($remotePreviousTxid !== null) {
                    $localConflict = $this->transactionChainRepository->getLocalTransactionByPreviousTxid(
                        $remotePreviousTxid,
                        $userPubkeyHash,
                        $contactPubkeyHash
                    );

                    if ($localConflict !== null && $localConflict['txid'] !== $tx['txid']) {
                        // Chain conflict detected! Two transactions claim the same previous_txid
                        $conflictResult = $this->resolveChainConflict($localConflict, $tx);

                        if ($conflictResult['resolved']) {
                            $conflictsResolved++;

                            // Dispatch chain conflict resolved event
                            EventDispatcher::getInstance()->dispatch(SyncEvents::CHAIN_CONFLICT_RESOLVED, [
                                'contact_pubkey' => $contactPublicKey,
                                'local_txid' => $localConflict['txid'],
                                'remote_txid' => $tx['txid'],
                                'winner' => $conflictResult['winner'],
                                'previous_txid' => $remotePreviousTxid
                            ]);

                            if ($conflictResult['winner'] === 'remote') {
                                // Remote transaction wins - we need to re-sign our local loser
                                // First insert the remote winner, then update our local loser
                                output("Chain conflict: remote wins. Will re-sign local transaction {$localConflict['txid']}", 'SILENT');
                                $localLoserToResign = $localConflict;  // Save for re-signing after insert
                            } else {
                                // Local transaction wins - but we still insert the remote transaction
                                // Both transactions have valid signatures with their original previous_txid.
                                // Chain ordering is determined at query time using lexicographic txid comparison.
                                // The remote sender will need to re-sign their transaction to point to ours,
                                // but for now we accept it so the transactions are not lost.
                                output("Chain conflict: local wins. Inserting remote transaction {$tx['txid']} with original previous_txid", 'SILENT');

                                Logger::getInstance()->info("Inserting remote transaction that lost chain conflict", [
                                    'remote_txid' => $tx['txid'],
                                    'local_winner_txid' => $localConflict['txid'],
                                    'shared_previous_txid' => $remotePreviousTxid,
                                    'note' => 'Both transactions stored with original previous_txid, ordering by lexicographic txid'
                                ]);
                                // Don't skip - insert the transaction even though it lost the conflict
                            }
                        } else {
                            Logger::getInstance()->warning("Failed to resolve chain conflict", [
                                'local_txid' => $localConflict['txid'],
                                'remote_txid' => $tx['txid'],
                                'previous_txid' => $remotePreviousTxid
                            ]);
                        }
                    }
                }

                // Verify transaction signature before inserting
                // CRITICAL: Verify with ORIGINAL previous_txid since that's what was signed
                // NOTE: For sync recovery, we continue processing even if some transactions fail
                // verification. This allows partial recovery of valid transactions while
                // logging invalid ones for investigation.
                if (!$this->verifyTransactionSignature($tx)) {
                    // Log the failure with full details for security audit
                    Logger::getInstance()->warning("Sync: Transaction signature verification failed - skipping", [
                        'txid' => $tx['txid'] ?? 'unknown',
                        'sender_address' => $tx['sender_address'] ?? 'unknown',
                        'sender_public_key_hash' => isset($tx['sender_public_key'])
                            ? hash(Constants::HASH_ALGORITHM, $tx['sender_public_key'])
                            : 'unknown',
                        'receiver_address' => $tx['receiver_address'] ?? 'unknown',
                        'amount' => $tx['amount'] ?? 'unknown',
                        'currency' => $tx['currency'] ?? 'unknown',
                        'has_signature' => !empty($tx['sender_signature']),
                        'has_nonce' => !empty($tx['signature_nonce']),
                        'synced_so_far' => $syncedCount,
                        'contact_address' => $contactAddress
                    ]);

                    // Track signature failures but continue processing
                    if (!isset($result['signature_failures'])) {
                        $result['signature_failures'] = [];
                    }
                    $result['signature_failures'][] = [
                        'txid' => $tx['txid'] ?? 'unknown',
                        'sender' => $tx['sender_address'] ?? 'unknown'
                    ];

                    // CONTINUE processing - skip this transaction but process remaining ones
                    // This allows partial sync recovery instead of complete failure
                    continue;
                }

                // Verify recipient signature for accepted/completed transactions
                if (!$this->verifyRecipientSignature($tx)) {
                    Logger::getInstance()->warning("Sync: Recipient signature verification failed - skipping", [
                        'txid' => $tx['txid'] ?? 'unknown',
                        'status' => $tx['status'] ?? 'unknown',
                        'has_recipient_signature' => !empty($tx['recipient_signature'])
                    ]);

                    if (!isset($result['recipient_signature_failures'])) {
                        $result['recipient_signature_failures'] = [];
                    }
                    $result['recipient_signature_failures'][] = [
                        'txid' => $tx['txid'] ?? 'unknown',
                        'status' => $tx['status'] ?? 'unknown'
                    ];

                    continue;
                }

                // Insert the missing transaction (only reached if signature is valid)
                // IMPORTANT: Always insert with ORIGINAL previous_txid to preserve signature validity.
                // When there's a chain conflict (two transactions with same previous_txid),
                // both are stored with their original values. Chain ordering is handled at query
                // time using lexicographic txid comparison - lower txid comes first in the chain.
                $insertData = [
                    'senderAddress' => $tx['sender_address'],
                    'senderPublicKey' => $tx['sender_public_key'],
                    'receiverAddress' => $tx['receiver_address'],
                    'receiverPublicKey' => $tx['receiver_public_key'],
                    'amount' => $tx['amount'],
                    'currency' => $tx['currency'],
                    'txid' => $tx['txid'],
                    'previousTxid' => $tx['previous_txid'] ?? null,  // Keep original for signature validity
                    'memo' => $tx['memo'] ?? 'standard',
                    'description' => $tx['description'] ?? null,
                    'status' => Constants::STATUS_COMPLETED,
                    // Include signature data for future verification
                    'signature' => $tx['sender_signature'] ?? null,
                    'nonce' => $tx['signature_nonce'] ?? null,
                    'recipientSignature' => $tx['recipient_signature'] ?? null,
                    'time' => $tx['time'] ?? null
                ];

                // Determine type based on sender
                $userAddresses = $this->currentUser->getUserAddresses();
                $type = in_array($tx['sender_address'], $userAddresses) ? Constants::TX_TYPE_SENT : Constants::TX_TYPE_RECEIVED;

                $this->transactionRepository->insertTransaction($insertData, $type);
                $syncedCount++;

                // If remote won a chain conflict, re-sign our local loser to point to the winner
                if ($localLoserToResign !== null) {
                    $resigned = $this->resignLocalTransaction($localLoserToResign, $tx['txid']);
                    if ($resigned) {
                        output("Re-signed local transaction {$localLoserToResign['txid']} to chain after {$tx['txid']}", 'SILENT');
                    } else {
                        Logger::getInstance()->warning("Failed to re-sign local transaction after conflict resolution", [
                            'local_txid' => $localLoserToResign['txid'],
                            'winner_txid' => $tx['txid']
                        ]);
                    }
                }
            }

            $result['success'] = true;
            $result['synced_count'] = $syncedCount;
            $result['latest_txid'] = $syncResponse['latestTxid'] ?? null;
            $result['signature_failure'] = false;
            $result['conflicts_resolved'] = $conflictsResolved;

            // Verify chain integrity after sync to detect remaining gaps
            // When both sides are missing the same transactions, sync exchanges nothing
            // but the chain still has internal gaps that need to be reported
            $chainStatus = $this->transactionChainRepository->verifyChainIntegrity(
                $this->currentUser->getPublicKey(),
                $contactPublicKey
            );

            $result['chain_valid'] = $chainStatus['valid'];
            $result['chain_gaps'] = $chainStatus['gaps'];
            $result['chain_broken_txids'] = $chainStatus['broken_txids'];

            if (!$chainStatus['valid']) {
                $gapCount = count($chainStatus['gaps']);
                output("Transaction chain sync completed but {$gapCount} gap(s) remain - both sides missing same transactions", 'SILENT');

                Logger::getInstance()->warning("Chain gaps remain after sync - mutual gap detected", [
                    'contact_address' => $contactAddress,
                    'gap_count' => $gapCount,
                    'gaps' => $chainStatus['gaps'],
                    'broken_txids' => $chainStatus['broken_txids'],
                    'synced_count' => $syncedCount
                ]);

                // Dispatch chain gap detected event
                EventDispatcher::getInstance()->dispatch(SyncEvents::CHAIN_GAP_DETECTED, [
                    'contact_pubkey' => $contactPublicKey,
                    'contact_address' => $contactAddress,
                    'gap_count' => $gapCount,
                    'gaps' => $chainStatus['gaps'],
                    'broken_txids' => $chainStatus['broken_txids']
                ]);
            } else {
                output("Transaction chain sync completed: {$syncedCount} transactions synced, {$conflictsResolved} conflicts resolved", 'SILENT');
            }

            // Dispatch sync completed event
            EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_COMPLETED, [
                'contact_pubkey' => $contactPublicKey,
                'contact_address' => $contactAddress,
                'synced_count' => $syncedCount,
                'success' => true,
                'chain_valid' => $chainStatus['valid'],
                'chain_gaps' => count($chainStatus['gaps'])
            ]);

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            Logger::getInstance()->logException($e, [
                'method' => 'syncTransactionChain',
                'contact' => $contactAddress
            ]);

            // Dispatch sync failed event
            EventDispatcher::getInstance()->dispatch(SyncEvents::SYNC_FAILED, [
                'contact_pubkey' => $contactPublicKey,
                'contact_address' => $contactAddress,
                'error' => $e->getMessage(),
                'error_code' => 'sync_exception'
            ]);
        }

        // Notify HeldTransactionService of sync completion
        try {
            $heldService = $this->getHeldTransactionService();
            $heldService->onSyncComplete(
                $contactPublicKey,
                $result['success'],
                $result['synced_count']
            );
        } catch (Exception $e) {
            // Log but don't fail - held transaction notification is non-critical
            Logger::getInstance()->debug("Could not notify HeldTransactionService of sync completion", [
                'contact' => $contactPublicKey,
                'error' => $e->getMessage()
            ]);
        }

        return $result;
    }

    // =========================================================================
    // CHAIN CONFLICT RESOLUTION
    // =========================================================================

    /**
     * Resolve a chain conflict between two transactions claiming the same previous_txid
     *
     * When both parties create transactions simultaneously that reference the same
     * previous transaction, we need a deterministic way to decide ordering.
     *
     * Resolution algorithm:
     * 1. Compare txids lexicographically (string comparison)
     * 2. The transaction with the LOWER txid "wins" and keeps its previous_txid
     * 3. The "losing" transaction must update its previous_txid to point to the winner
     *
     * This ensures both parties will independently arrive at the same chain order
     * without requiring additional communication.
     *
     * @param array $localTx The local transaction data
     * @param array $remoteTx The remote transaction data
     * @return array Result with:
     *   - resolved: bool - Whether conflict was resolved
     *   - winner: string - 'local' or 'remote' indicating which transaction won
     *   - loser_txid: string - The txid of the losing transaction
     *   - winner_txid: string - The txid of the winning transaction
     */
    private function resolveChainConflict(array $localTx, array $remoteTx): array {
        $result = [
            'resolved' => false,
            'winner' => null,
            'loser_txid' => null,
            'winner_txid' => null
        ];

        $localTxid = $localTx['txid'];
        $remoteTxid = $remoteTx['txid'];

        // Lexicographic comparison: lower txid wins
        $comparison = strcmp($localTxid, $remoteTxid);

        if ($comparison < 0) {
            // Local transaction has lower txid - it wins
            $result['winner'] = 'local';
            $result['winner_txid'] = $localTxid;
            $result['loser_txid'] = $remoteTxid;
        } elseif ($comparison > 0) {
            // Remote transaction has lower txid - it wins
            $result['winner'] = 'remote';
            $result['winner_txid'] = $remoteTxid;
            $result['loser_txid'] = $localTxid;
        } else {
            // Txids are identical - this shouldn't happen with proper txid generation
            // but if it does, we can't resolve the conflict
            Logger::getInstance()->error("Chain conflict with identical txids - cannot resolve", [
                'txid' => $localTxid
            ]);
            return $result;
        }

        $result['resolved'] = true;

        Logger::getInstance()->info("Chain conflict resolved deterministically", [
            'local_txid' => $localTxid,
            'remote_txid' => $remoteTxid,
            'winner' => $result['winner'],
            'shared_previous_txid' => $localTx['previous_txid'] ?? 'null'
        ]);

        return $result;
    }

    /**
     * Re-sign a local transaction with a new previous_txid
     *
     * When a chain conflict is resolved and our local transaction loses,
     * we need to update its previous_txid to point to the winner and
     * re-sign it (since we have the private key for our own transactions).
     *
     * After successful re-signing, any held transaction record is released
     * to prevent HeldTransactionService from overwriting our correct previous_txid.
     *
     * @param array $localTx The local transaction data from database
     * @param string $newPreviousTxid The winner's txid to point to
     * @return bool True if re-signing was successful
     */
    private function resignLocalTransaction(array $localTx, string $newPreviousTxid): bool {
        try {
            // Update the local transaction's previous_txid in database first
            $updated = $this->transactionChainRepository->updatePreviousTxid($localTx['txid'], $newPreviousTxid);

            if (!$updated) {
                Logger::getInstance()->warning("Failed to update previous_txid for local transaction", [
                    'txid' => $localTx['txid'],
                    'new_previous_txid' => $newPreviousTxid
                ]);
                return false;
            }

            // Get the updated transaction from database
            $updatedTxResult = $this->transactionRepository->getByTxid($localTx['txid']);
            if (!$updatedTxResult || empty($updatedTxResult)) {
                Logger::getInstance()->warning("Could not retrieve updated transaction for re-signing", [
                    'txid' => $localTx['txid']
                ]);
                return false;
            }
            // Unwrap array - getByTxid() returns array of transactions
            $updatedTx = $updatedTxResult[0];

            // Build the payload for signing based on memo type
            $memo = $updatedTx['memo'] ?? 'standard';
            if ($memo === 'standard') {
                $payload = $this->transactionPayload->buildStandardFromDatabase($updatedTx);
            } else {
                $payload = $this->transactionPayload->buildFromDatabase($updatedTx);
            }

            // Re-sign the transaction
            $signResult = $this->transportUtility->signWithCapture($payload);

            if (!$signResult) {
                Logger::getInstance()->warning("Failed to re-sign local transaction", [
                    'txid' => $localTx['txid']
                ]);
                return false;
            }

            // Update the transaction with new signature and nonce
            $signatureUpdated = $this->transactionRepository->updateSignatureData(
                $localTx['txid'],
                $signResult['signature'],
                $signResult['nonce']
            );

            if (!$signatureUpdated) {
                Logger::getInstance()->warning("Failed to update signature for local transaction", [
                    'txid' => $localTx['txid']
                ]);
                return false;
            }

            // Update the timestamp to reflect when the re-signing occurred
            $timestampUpdated = $this->transactionRepository->updateTimestamp($localTx['txid']);
            if (!$timestampUpdated) {
                Logger::getInstance()->warning("Failed to update timestamp for re-signed transaction", [
                    'txid' => $localTx['txid']
                ]);
                // Don't return false - this is not critical
            }

            // Set status to PENDING so the transaction gets re-sent to the contact
            // This mirrors the HeldTransactionService approach: after re-signing,
            // processPendingTransactions() will pick up the transaction and send it
            $statusUpdated = $this->transactionRepository->updateStatus(
                $localTx['txid'],
                Constants::STATUS_PENDING,
                true  // isTxid = true
            );

            if (!$statusUpdated) {
                Logger::getInstance()->warning("Failed to set status to pending for re-signed transaction", [
                    'txid' => $localTx['txid']
                ]);
                // Don't return false - signature was updated, status is secondary
            }

            // Release any held transaction record to prevent HeldTransactionService
            // from overwriting our correct previous_txid with the stale expected_previous_txid
            // from the original rejection
            $this->releaseHeldTransaction($localTx['txid']);

            Logger::getInstance()->info("Successfully re-signed local transaction after chain conflict", [
                'txid' => $localTx['txid'],
                'old_previous_txid' => $localTx['previous_txid'] ?? 'null',
                'new_previous_txid' => $newPreviousTxid,
                'new_nonce' => $signResult['nonce'],
                'status_set_to_pending' => $statusUpdated,
                'timestamp_updated' => $timestampUpdated
            ]);

            return true;

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, [
                'method' => 'resignLocalTransaction',
                'txid' => $localTx['txid']
            ]);
            return false;
        }
    }

    /**
     * Release a held transaction record
     *
     * After chain conflict resolution re-signs a transaction correctly,
     * we need to release any held transaction record to prevent
     * HeldTransactionService from overwriting our correct previous_txid.
     *
     * @param string $txid Transaction ID
     * @return bool True if released or not found, false on error
     */
    private function releaseHeldTransaction(string $txid): bool {
        try {
            $heldRepository = new HeldTransactionRepository();

            // Check if transaction is held
            if (!$heldRepository->isTransactionHeld($txid)) {
                return true; // Not held, nothing to release
            }

            // Release it to prevent HeldTransactionService from overwriting
            $released = $heldRepository->releaseTransaction($txid);

            if ($released) {
                Logger::getInstance()->info("Released held transaction after chain conflict resolution", [
                    'txid' => $txid
                ]);
            } else {
                Logger::getInstance()->warning("Failed to release held transaction", [
                    'txid' => $txid
                ]);
            }

            return $released;

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, [
                'method' => 'releaseHeldTransaction',
                'txid' => $txid
            ]);
            return false;
        }
    }

    // =========================================================================
    // SYNC REQUEST HANDLING
    // =========================================================================

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

            // Filter to only include transactions NEWER than lastKnownTxid if provided
            // Transactions are ordered by timestamp DESC (newest first), so we collect
            // all transactions until we hit the lastKnownTxid
            // Cancelled/rejected/completed transactions are included, but pending/sending
            // are excluded since they haven't been signed yet (signature added after send)
            $filteredTransactions = [];

            foreach ($transactions as $tx) {
                // If we hit the lastKnownTxid, stop - requester already has this and older
                if ($lastKnownTxid !== null && $tx['txid'] === $lastKnownTxid) {
                    break;
                }

                // Skip transactions that haven't been sent yet - they won't have signatures
                // Transactions are inserted before sending, so pending/sending status means
                // the signature hasn't been added to the database yet
                $status = $tx['status'] ?? '';
                if ($status === Constants::STATUS_PENDING || $status === Constants::STATUS_SENDING) {
                    continue;
                }

                // Include necessary fields for security and signature verification
                $txData = [
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
                    'time' => $tx['time'] ?? null,
                    'status' => $tx['status'],
                    // Include signature data for verification
                    'sender_signature' => $tx['sender_signature'] ?? null,
                    'signature_nonce' => $tx['signature_nonce'] ?? null,
                    'recipient_signature' => $tx['recipient_signature'] ?? null
                ];

                // Privacy: Only include description for contact or standard (direct) transactions
                // P2P transactions (memo is a hash) should NOT have descriptions shared during sync
                // as the description is only meant for the end recipient, not intermediaries
                $memo = $tx['memo'] ?? '';
                if ($memo === 'contact' || $memo === 'standard') {
                    $txData['description'] = $tx['description'] ?? null;
                } else {
                    // For P2P transactions, explicitly set description to null
                    $txData['description'] = null;
                }

                $filteredTransactions[] = $txData;
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
            Logger::getInstance()->logException($e, [
                'method' => 'handleTransactionSyncRequest',
                'sender' => $senderAddress
            ]);
            echo $this->messagePayload->buildTransactionSyncRejection($senderAddress, 'internal_error');
        }
    }

    // =========================================================================
    // SIGNATURE VERIFICATION
    // =========================================================================

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
            Logger::getInstance()->debug("Transaction missing signature data for verification", [
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
        // Note: tx_type is not included in sync response, so we use memo to detect contact transactions
        $memo = $tx['memo'] ?? null;
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
            Logger::getInstance()->warning("Invalid sender public key for transaction signature verification", [
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
            // Enhanced debug logging for signature verification failures
            Logger::getInstance()->warning("Transaction signature verification failed", [
                'txid' => $tx['txid'] ?? 'unknown',
                'sender' => $tx['sender_address'] ?? 'unknown',
                'verify_result' => $verified,
                'memo' => $tx['memo'] ?? 'unknown',
                'reconstructed_message' => $messageContent,
                'signature_present' => !empty($tx['sender_signature']),
                'nonce' => $tx['signature_nonce'] ?? 'unknown',
                'tx_time' => $tx['time'] ?? 'NULL',
                'tx_time_type' => gettype($tx['time'] ?? null),
                'tx_amount' => $tx['amount'] ?? 'NULL',
                'tx_amount_type' => gettype($tx['amount'] ?? null),
                'tx_description' => $tx['description'] ?? 'NULL',
                'signature_first_20' => substr($tx['sender_signature'] ?? '', 0, 20) . '...',
                'public_key_first_50' => substr($tx['sender_public_key'] ?? '', 0, 50) . '...'
            ]);
        }

        return $verified === 1;
    }

    /**
     * Public wrapper for transaction signature verification
     *
     * Used by TransactionService to verify signatures during chain conflict resolution.
     * When a duplicate transaction is received with a different previous_txid, we need
     * to verify the new signature before accepting the update.
     *
     * @param array $tx Transaction data with sender_signature and signature_nonce
     * @return bool True if signature is valid, false otherwise
     */
    public function verifyTransactionSignaturePublic(array $tx): bool {
        return $this->verifyTransactionSignature($tx);
    }

    /**
     * Verify recipient signature for a transaction
     *
     * Verifies that the transaction was acknowledged and signed by the recipient.
     * This signature is only expected for transactions with status 'accepted' or 'completed'.
     * Transactions that were cancelled, rejected, or expired will not have recipient signatures.
     *
     * The recipient signature is created by the receiver signing the same message content
     * that the sender signed (transaction fields + nonce).
     *
     * @param array $tx Transaction data with recipient_signature
     * @return bool True if signature is valid or not required, false if required but invalid
     */
    private function verifyRecipientSignature(array $tx): bool {
        $status = $tx['status'] ?? '';

        // Recipient signature only required for accepted/completed transactions
        // Cancelled, rejected, expired, pending, sending, sent transactions don't have recipient signatures
        if (!in_array($status, [Constants::STATUS_ACCEPTED, Constants::STATUS_COMPLETED])) {
            return true; // Not required for this status
        }

        // For accepted/completed, recipient_signature is required
        if (empty($tx['recipient_signature'])) {
            Logger::getInstance()->warning("Transaction missing required recipient signature", [
                'txid' => $tx['txid'] ?? 'unknown',
                'status' => $status
            ]);
            return false;
        }

        // Reconstruct the message that was signed (same as sender signature)
        $messageContent = $this->reconstructSignedMessage($tx);
        if ($messageContent === null) {
            Logger::getInstance()->debug("Could not reconstruct message for recipient signature verification", [
                'txid' => $tx['txid'] ?? 'unknown'
            ]);
            return false;
        }

        // Get receiver's public key
        $receiverPublicKey = $tx['receiver_public_key'] ?? null;
        if (empty($receiverPublicKey)) {
            Logger::getInstance()->debug("Missing receiver public key for recipient signature verification", [
                'txid' => $tx['txid'] ?? 'unknown'
            ]);
            return false;
        }

        // Get the public key resource
        $publicKeyResource = openssl_pkey_get_public($receiverPublicKey);
        if ($publicKeyResource === false) {
            Logger::getInstance()->warning("Invalid receiver public key for recipient signature verification", [
                'txid' => $tx['txid'] ?? 'unknown'
            ]);
            return false;
        }

        // Verify the signature
        $verified = openssl_verify(
            $messageContent,
            base64_decode($tx['recipient_signature']),
            $publicKeyResource
        );

        if ($verified !== 1) {
            Logger::getInstance()->warning("Recipient signature verification failed", [
                'txid' => $tx['txid'] ?? 'unknown',
                'status' => $status,
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
                Logger::getInstance()->debug("Missing field for message reconstruction", [
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

        // Include 'time' field - ALWAYS include to match how buildStandardFromDatabase() builds the payload
        // buildStandardFromDatabase() uses: 'time' => $data['time'] ?? null
        // This means 'time' is ALWAYS present in the signed message (even if null)
        // We must include it in reconstruction to match the original signed message
        // Note: For standard transactions, time should always be present since prepareStandardTransactionData sets it
        // For P2P transactions, time comes from the request
        if (isset($tx['time']) && $tx['time'] !== null) {
            $messageContent['time'] = (int)$tx['time'];
        } else {
            // Include null time to match buildStandardFromDatabase() behavior
            $messageContent['time'] = null;
        }

        $messageContent['receiverAddress'] = $tx['receiver_address'];
        $messageContent['receiverPublicKey'] = $tx['receiver_public_key'];
        $messageContent['amount'] = (int)$tx['amount'];
        $messageContent['currency'] = $tx['currency'];
        $messageContent['txid'] = $tx['txid'];
        $messageContent['previousTxid'] = $tx['previous_txid'] ?? null;
        $memo = $tx['memo'] ?? 'standard';
        $messageContent['memo'] = $memo;

        // NOTE: Description is NOT included in the signed message
        // It's transmitted separately in the envelope for privacy (P2P intermediaries don't see it)
        // and stored separately in the database

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
            Logger::getInstance()->debug("Missing nonce for contact message reconstruction", [
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

    // =========================================================================
    // BALANCE SYNC OPERATIONS
    // =========================================================================

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
                // Only count completed transactions towards balance
                if ($transaction['status'] !== Constants::STATUS_COMPLETED) {
                    continue;
                }

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

            // Dispatch balance synced event
            EventDispatcher::getInstance()->dispatch(SyncEvents::BALANCE_SYNCED, [
                'contact_pubkey' => $contactPubkey,
                'currencies' => array_keys($balancesByCurrency),
                'success' => true
            ]);

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            Logger::getInstance()->logException($e, [
                'method' => 'syncContactBalance',
                'contact_pubkey_hash' => hash(Constants::HASH_ALGORITHM, $contactPubkey)
            ]);

            // Dispatch balance sync failed event
            EventDispatcher::getInstance()->dispatch(SyncEvents::BALANCE_SYNCED, [
                'contact_pubkey' => $contactPubkey,
                'currencies' => [],
                'success' => false,
                'error' => $e->getMessage()
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
                Logger::getInstance()->info("Transaction chain sync for re-added contact", [
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
            Logger::getInstance()->logException($e, [
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
                    // Only count completed transactions towards balance
                    if ($transaction['status'] !== Constants::STATUS_COMPLETED) {
                        continue;
                    }

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

    // =========================================================================
    // BIDIRECTIONAL SYNC
    // =========================================================================

    /**
     * Perform bidirectional sync negotiation with a contact
     *
     * When both parties may have incomplete chains, this method
     * exchanges chain state summaries and allows both parties to share missing
     * transactions with each other.
     *
     * Protocol:
     * 1. Get local chain state summary (txid list)
     * 2. Request remote chain state summary
     * 3. Compare lists to find transactions each side is missing
     * 4. Exchange missing transactions in both directions
     *
     * @param string $contactAddress Contact's address
     * @param string $contactPublicKey Contact's public key
     * @return array Result with:
     *   - success: bool - Whether sync completed
     *   - received_count: int - Transactions received from contact
     *   - sent_count: int - Transactions we provided to contact
     *   - error: string|null - Error message if failed
     */
    public function bidirectionalSync(string $contactAddress, string $contactPublicKey): array {
        $result = [
            'success' => false,
            'received_count' => 0,
            'sent_count' => 0,
            'local_missing' => [],
            'remote_missing' => [],
            'error' => null
        ];

        try {
            // Step 1: Get local chain state summary
            $localState = $this->transactionChainRepository->getChainStateSummary(
                $this->currentUser->getPublicKey(),
                $contactPublicKey
            );

            output(outputSyncLocalChainState($localState['transaction_count']), 'SILENT');

            // Step 2: Request remote chain state via sync negotiation request
            $negotiationRequest = $this->messagePayload->buildSyncNegotiationRequest(
                $contactAddress,
                $contactPublicKey,
                $localState['txid_list']
            );

            $negotiationResponse = json_decode(
                $this->transportUtility->send($contactAddress, $negotiationRequest),
                true
            );

            if (!$negotiationResponse || $negotiationResponse['status'] !== Constants::STATUS_ACCEPTED) {
                // Fallback to standard sync if remote doesn't support bidirectional
                output(outputSyncBidirectionalFallback(), 'SILENT');
                $standardSyncResult = $this->syncTransactionChain($contactAddress, $contactPublicKey);
                $result['success'] = $standardSyncResult['success'];
                $result['received_count'] = $standardSyncResult['synced_count'];
                return $result;
            }

            // Step 3: Process the negotiation response
            $remoteTxids = $negotiationResponse['txid_list'] ?? [];
            $remoteTransactions = $negotiationResponse['transactions'] ?? [];

            // Find transactions we're missing that remote has
            $localTxidSet = array_flip($localState['txid_list']);
            $remoteTxidSet = array_flip($remoteTxids);

            // Transactions remote has that we don't
            $localMissing = [];
            foreach ($remoteTxids as $txid) {
                if (!isset($localTxidSet[$txid])) {
                    $localMissing[] = $txid;
                }
            }

            // Transactions we have that remote doesn't
            $remoteMissing = [];
            foreach ($localState['txid_list'] as $txid) {
                if (!isset($remoteTxidSet[$txid])) {
                    $remoteMissing[] = $txid;
                }
            }

            $result['local_missing'] = $localMissing;
            $result['remote_missing'] = $remoteMissing;

            output(outputSyncBidirectionalMissing(count($localMissing), count($remoteMissing)), 'SILENT');

            // Step 4: Process transactions we received (that we were missing)
            $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());
            $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);

            foreach ($remoteTransactions as $tx) {
                // Skip if we already have this transaction
                if ($this->transactionRepository->transactionExistsTxid($tx['txid'])) {
                    continue;
                }

                // Verify signature before inserting
                if (!$this->verifyTransactionSignature($tx)) {
                    Logger::getInstance()->warning("Bidirectional sync: Signature verification failed", [
                        'txid' => $tx['txid'] ?? 'unknown',
                        'sender' => $tx['sender_address'] ?? 'unknown'
                    ]);
                    continue;
                }

                // Verify recipient signature for accepted/completed transactions
                if (!$this->verifyRecipientSignature($tx)) {
                    Logger::getInstance()->warning("Bidirectional sync: Recipient signature verification failed", [
                        'txid' => $tx['txid'] ?? 'unknown',
                        'status' => $tx['status'] ?? 'unknown'
                    ]);
                    continue;
                }

                // Determine transaction type
                $txType = ($tx['sender_public_key'] ?? '') === $this->currentUser->getPublicKey()
                    ? Constants::TX_TYPE_SENT
                    : Constants::TX_TYPE_RECEIVED;

                // Insert the transaction
                $this->transactionRepository->insertTransaction($tx, $txType);
                $result['received_count']++;
            }

            // Step 5: If remote is missing transactions, they'll request them
            // via their own sync - we just record what they're missing
            $result['sent_count'] = count($remoteMissing);

            $result['success'] = true;

            // Sync balances after transaction sync
            $this->syncContactBalance($contactPublicKey);

            output(outputSyncBidirectionalCompleted($result['received_count'], $result['sent_count']), 'SILENT');

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            Logger::getInstance()->logException($e, [
                'method' => 'bidirectionalSync',
                'contact' => $contactAddress
            ]);
        }

        return $result;
    }

    /**
     * Handle incoming sync negotiation request
     *
     * Responds to bidirectional sync negotiation requests.
     * Compares local chain state with requester's list and returns
     * both our txid list and any transactions the requester is missing.
     *
     * @param array $request The sync negotiation request
     * @return void Outputs JSON response
     */
    public function handleSyncNegotiationRequest(array $request): void {
        $senderAddress = $request['senderAddress'];
        $senderPublicKey = $request['senderPublicKey'];
        $remoteTxidList = $request['txid_list'] ?? [];

        // Verify the sender is a known contact
        if (!$this->contactRepository->contactExistsPubkey($senderPublicKey)) {
            echo $this->messagePayload->buildSyncNegotiationRejection($senderAddress, 'unknown_contact');
            return;
        }

        try {
            // Get our local chain state
            $localState = $this->transactionChainRepository->getChainStateSummary(
                $this->currentUser->getPublicKey(),
                $senderPublicKey
            );

            // Find transactions they're missing (we have but they don't)
            $remoteTxidSet = array_flip($remoteTxidList);
            $transactionsToSend = [];

            foreach ($localState['txid_list'] as $txid) {
                if (!isset($remoteTxidSet[$txid])) {
                    // They don't have this transaction - include it in response
                    $tx = $this->transactionRepository->getByTxid($txid);
                    if ($tx && count($tx) > 0) {
                        $txData = $tx[0]; // getByTxid returns array

                        // Skip transactions that haven't been sent yet - they won't have signatures
                        $status = $txData['status'] ?? '';
                        if ($status === Constants::STATUS_PENDING || $status === Constants::STATUS_SENDING) {
                            continue;
                        }

                        $transactionsToSend[] = [
                            'txid' => $txData['txid'],
                            'previous_txid' => $txData['previous_txid'],
                            'sender_address' => $txData['sender_address'],
                            'sender_public_key' => $txData['sender_public_key'],
                            'receiver_address' => $txData['receiver_address'],
                            'receiver_public_key' => $txData['receiver_public_key'],
                            'amount' => $txData['amount'],
                            'currency' => $txData['currency'],
                            'memo' => $txData['memo'],
                            'timestamp' => $txData['timestamp'],
                            'time' => $txData['time'] ?? null,
                            'status' => $txData['status'],
                            'sender_signature' => $txData['sender_signature'] ?? null,
                            'signature_nonce' => $txData['signature_nonce'] ?? null,
                            'recipient_signature' => $txData['recipient_signature'] ?? null,
                            'description' => ($txData['memo'] === 'contact' || $txData['memo'] === 'standard')
                                ? ($txData['description'] ?? null)
                                : null
                        ];
                    }
                }
            }

            // Return our txid list and any transactions they're missing
            echo $this->messagePayload->buildSyncNegotiationResponse(
                $senderAddress,
                $localState['txid_list'],
                $transactionsToSend
            );

        } catch (Exception $e) {
            Logger::getInstance()->logException($e, [
                'method' => 'handleSyncNegotiationRequest',
                'sender' => $senderAddress
            ]);
            echo $this->messagePayload->buildSyncNegotiationRejection($senderAddress, 'internal_error');
        }
    }
}