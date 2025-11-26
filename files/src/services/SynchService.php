<?php
# Copyright 2025

require_once __DIR__ . '/../cli/CliOutputManager.php';

/**
 * Synch Service
 *
 * Handles all business logic for synch management.
 *
 * @package Services
 */
class SynchService {
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
     * Handler for synch through user-input
     *
     * @param array $argv Command line arguments
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function sych($argv, ?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        if(isset($argv[2])){
            $argument = strtolower($argv[2]);
            if($argument === 'contacts'){
                $this->synchAllContacts($output);
            } elseif($argument === 'transactions'){
                $this->synchAllTransactions($output);
            } else {
                $output->error("Invalid sync type. Use 'contacts' or 'transactions'", 'INVALID_SYNC_TYPE', 400, [
                    'valid_types' => ['contacts', 'transactions']
                ]);
            }
        } else{
            $this->synchAll($output);
        }
    }

    /**
     * Synch all possible entities
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function synchAll(?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        // Synch both contacts and transactions
        $contactResults = $this->synchAllContactsInternal();
        $transactionResults = $this->synchAllTransactionsInternal();

        $output->success("Sync completed", [
            'contacts' => $contactResults,
            'transactions' => $transactionResults
        ], "Synched contacts and transactions");
    }

    /**
     * Synch all contacts
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function synchAllContacts(?CliOutputManager $output = null): void{
        $output = $output ?? CliOutputManager::getInstance();

        $results = $this->synchAllContactsInternal();

        $output->success("Contacts synced", $results, "Contact synchronization completed");
    }

    /**
     * Internal method to synch all contacts and return results
     *
     * @return array Sync results
     */
    private function synchAllContactsInternal(): array{
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
                $success = $this->synchSingleContact($address, 'SILENT');
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
     * Synch contact
     *
     * @param string $contactAddress Contact Address
     * @param string $echo 'ECHO' (to user & log) or 'SILENT' (only to log)
     * @return bool True if syched succesfully, false otherwise
     */
    public function synchSingleContact($contactAddress, $echo='SILENT'): bool{
        // Synch specific contact based on address
        $transportIndex = $this->transportUtility->determineTransportType($contactAddress);
        $contact = $this->contactRepository->getContactByAddress($transportIndex, $contactAddress); // Get contact from database
        if($contact['status'] === 'pending'){
            output(outputSynchContactDueToPendingStatus($contactAddress),$echo);
            // If the contact is still pending then inquire with contact
            $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
            $synchResponse = json_decode($this->transportUtility->send($contactAddress, $messagePayload),true);
            $status = $synchResponse['status'];
            $reason = $synchResponse['reason'] ?? NULL;
            if($status === 'accepted'){
                // If you are accepted as a contact by the contact in question then update accordingly 
                $this->contactRepository->updateStatus($transportIndex, $contactAddress, $status);
                output(outputContactSuccesfullySynched($contactAddress),$echo);
                return true;
            } elseif($status === 'rejected' && $reason === 'unknown'){
                // If no database existence of contact request on their end, resend contact request
                $contactPayload = $this->contactPayload->buildCreateRequest($contactAddress);
                $responseData = json_decode($this->transportUtility->send($contactAddress, $contactPayload), true);
                if(isset($responseData['status']) && ($responseData['status'] === 'accepted')){
                    // Contact received our contact request, needs to be accepted by other user first
                    //   If acceptance is automatic then able to check through following inquiry
                    //   Otherwise would need to inquire again down the line (through synch or otherwise)
                    $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
                    $synchResponse = $this->transportUtility->send($contactAddress, $messagePayload);
                    if($status === 'accepted'){
                        $this->contactRepository->updateStatus($transportIndex, $contactAddress, $status);
                        output(outputContactSuccesfullySynched($contactAddress),$echo);
                        return true;
                    }   
                } 
            } 
            // Contact did not respond immediately
            output(outputContactNoResponseSynch(),$echo);
            return false;
        } elseif($contact['status'] === 'accepted'){
            // If contact needs no synching
            //output(outputContactNoNeedSynch($contactAddress),'SILENT');
            return true;
        }
        return true;
    }

    /**
     * Synch all transactions
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function synchAllTransactions(?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        $results = $this->synchAllTransactionsInternal();

        $output->success("Transactions synced", $results, "Transaction synchronization completed");
    }

    /**
     * Internal method to synch all transactions and return results
     *
     * @return array Sync results
     */
    private function synchAllTransactionsInternal(): array {
        // Synch all transactions - placeholder for future implementation
        return [
            'total' => 0,
            'synced' => 0,
            'message' => 'Transaction sync not yet implemented'
        ];
    }

    /**
     * Synch contact
     *
     * @return bool True if syched succesfully, false otherwise
     */
    public function synchTransaction(): bool {
        // Synch specific
        return true;
    }


}