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
     * @var DeduplicationService|null Deduplication service for cleanup
     */
    private ?DeduplicationService $deduplicationService;

    /**
     * Constructor
     * @param P2pRepository $p2pRepository P2P repository
     * @param Rp2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     * @param DeduplicationService|null $deduplicationService Deduplication service (optional)
     */
    public function __construct(
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        ?DeduplicationService $deduplicationService = null
    ) {
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->timeUtility = $utilityContainer->getTimeUtility();
        $this->currentUser = $currentUser;
        $this->deduplicationService = $deduplicationService;
    }

    /**
     * Check if there are any messages that will expire and process them
     *
     * This function retrieves all expiring P2P messages from the database and
     * expires those that have exceeded their expiration time.
     * Also cleans up expired deduplication records.
     *
     * @return int Number of expiring messages processed
     * @throws PDOException If database query fails
     */
    public function processCleanupMessages(): int {
        try {
            $expiringMessages = $this->p2pRepository->getExpiringP2pMessages();

            // Process each not completed message
            foreach ($expiringMessages as $message) {
                // Validate message structure
                if (!isset($message['expiration']) || !is_numeric($message['expiration'])) {
                    error_log("Invalid message expiration: " . json_encode($message));
                    continue;
                }

                // If no response after set amount of time, expire the p2p (and potential transaction)
                if ($this->timeUtility->getCurrentMicrotime() > $message['expiration']) {
                    $this->expireMessage($message);
                }
            }

            // Clean up expired deduplication records
            if ($this->deduplicationService !== null) {
                $cleanedCount = $this->deduplicationService->cleanupExpired();
                if ($cleanedCount > 0) {
                    output(outputDedupCleanup($cleanedCount), 'SILENT');
                }
            }

            return isset($expiringMessages) ? count($expiringMessages) : 0;
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