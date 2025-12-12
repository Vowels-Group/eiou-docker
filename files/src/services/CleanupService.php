<?php
# Copyright 2025

/**
 * Cleanup Service
 *
 * Handles all business logic for cleanup management.
 *
 * @package Services
 */
class CleanupService {
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
     * @var TimeUtilityService Time utility service 
     */
    private TimeUtilityService $timeUtility;


    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * Constructor
     * @param P2pRepository $p2pRepository P2P repository
     * @param Rp2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->timeUtility = $utilityContainer->getTimeUtility();
        $this->currentUser = $currentUser;
    }

    /**
     * Check if there are any messages that will expire and process them
     *
     * This function retrieves all expired P2P messages from the database
     * (those that have exceeded their expiration time) and marks them as expired.
     *
     * @return int Number of expired messages processed
     * @throws PDOException If database query fails
     */
    public function processCleanupMessages(): int {
        try {
            // Get current microtime for accurate comparison with stored expiration values
            $currentMicrotime = $this->timeUtility->getCurrentMicrotime();

            // Get P2P messages that have already expired (SQL filters by expiration < currentMicrotime)
            $expiredMessages = $this->p2pRepository->getExpiredP2p($currentMicrotime);

            // Process each expired message
            foreach ($expiredMessages as $message) {
                $this->expireMessage($message);
            }

            return count($expiredMessages);
        } catch (PDOException $e) {
            error_log("Error processing cleanup messages: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Expire requests
     *
     * @param array $message The request data
     * @return void
     */
    public function expireMessage($message){
        // Expire the p2p request
        $this->p2pRepository->updateStatus($message['hash'], 'expired');
        output(outputP2pExpired($message),'SILENT');

        // Cancel transaction if exists
        if($this->transactionRepository->getByMemo($message['hash'])){
            $this->transactionRepository->updateStatus($message['hash'], 'cancelled');
            output(outputTransactionExpired($message),'SILENT');
        }
    }
}