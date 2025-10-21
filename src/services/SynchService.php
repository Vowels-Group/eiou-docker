<?php
# Copyright 2025

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
     * @param P2pRepository $p2pRepository P2P repository
     * @param Rp2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        ContactRepository $contactRepository,
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        UserContext $currentUser
    ) {
        $this->contactRepository = $contactRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->currentUser = $currentUser;
        $this->contactPayload = new ContactPayload($this->currentUser);
        $this->transactionPayload = new TransactionPayload($this->currentUser);
        $this->messagePayload = new MessagePayload($this->currentUser);
    }

    /**
     * Handler for synch through user-input
     *
     * @param array $argv Command line arguments
     */
    function sych($argv): void{   
        if(isset($argv[2])){
            $argument = strtolower($argv[2]);
            if($argument === 'contacts'){
                $this->synchAllContacts();
            } elseif($argument === 'transactions'){
                $this->synchAllTransactions();
            }
        } else{
            $this->synchAll();
        }
    }

    /**
     * Synch all possible entities
     *
     */
    function synchAll(): void{
        // Synch both contacts and transactions
        $this->synchAllContacts();
        $this->synchAllTransactions();
    }

    /**
     * Synch all contacts
     *
     */
    function synchAllContacts(): void{
        // Synch all contacts
        $contacts = $this->contactRepository->getAllAddresses();
        foreach ($contacts as $contact) {
            $this->synchSingleContact($contact);
        }
    }

    /**
     * Synch contact
     *
     * @param string $contactAddress Contact Address
     * @param string $echo 'ECHO' (to user & log) or 'SILENT' (only to log) 
     * @return bool True if syched succesfully, false otherwise
     */
    function synchSingleContact($contactAddress, $echo='SILENT'): bool{
        // Synch specific contact based on address
        $contact = $this->contactRepository->getContactByAddress($contactAddress); // Get contact from database
        if($contact['status'] === 'pending'){
            output(outputSynchContactDueToPendingStatus($contactAddress),$echo);
            // If the contact is still pending then inquire with contact
            $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
            $synchResponse = json_decode(send($contactAddress, $messagePayload),true);
            $status = $synchResponse['status'];
            $reason = $synchResponse['reason'] ?? NULL;
            if($status === 'accepted'){
                // If you are accepted as a contact by the contact in question then update accordingly 
                $this->contactRepository->updateStatus($contactAddress, $status);
                output(outputContactSuccesfullySynched($contactAddress),$echo);
                return true;
            } elseif($status === 'rejected' && $reason === 'unknown'){
                // If no database existence of contact request on their end, resend contact request
                $contactPayload = $this->contactPayload->buildCreateRequest();
                $responseData = json_decode(send($contactAddress, $contactPayload), true);
                if(isset($responseData['status']) && ($responseData['status'] === 'accepted')){
                    // Contact received our contact request, needs to be accepted by other user first
                    //   If acceptance is automatic then able to check through following inquiry
                    //   Otherwise would need to inquire again down the line (through synch or otherwise)
                    $messagePayload = $this->messagePayload->buildContactIsAcceptedInquiry($contactAddress);
                    $synchResponse = send($contactAddress, $messagePayload);
                    if($status === 'accepted'){
                        $this->contactRepository->updateStatus($contactAddress, $status);
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
     */
    function synchAllTransactions(): void {
        // Synch all transactions
    }

    /**
     * Synch contact
     *
     * @return bool True if syched succesfully, false otherwise
     */
    function synchTransaction(): bool {
        // Synch specific
        return true;
    }


}