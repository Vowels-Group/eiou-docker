<?php
# Copyright 2025

require_once __DIR__ . '/../cli/CliOutputManager.php';

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
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
         AddressRepository $addressRepository,
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->addressRepository = $addressRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
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
            } else {
                $output->error("Invalid sync type. Use 'contacts' or 'transactions'", 'INVALID_SYNC_TYPE', 400, [
                    'valid_types' => ['contacts', 'transactions']
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

        $output->success("Sync completed", [
            'contacts' => $contactResults,
            'transactions' => $transactionResults
        ], "Synced contacts and transactions");
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
                output(outputContactSuccesfullysynced($contactAddress),$echo);
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
                        output(outputContactSuccesfullysynced($contactAddress),$echo);
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
        // Sync all transactions - placeholder for future implementation
        return [
            'total' => 0,
            'synced' => 0,
            'message' => 'Transaction sync not yet implemented'
        ];
    }

    /**
     * Sync transaction
     *
     * @return bool True if synced successfully, false otherwise
     */
    public function syncTransaction(): bool {
        // Sync specific
        return true;
    }
}