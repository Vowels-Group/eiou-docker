<?php
# Copyright 2025 The Vowels Company

require_once __DIR__ . '/../utils/SecureLogger.php';

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
            SecureLogger::error("Error processing cleanup messages", ['error' => $e->getMessage()]);
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
        $transactions = $this->transactionRepository->getByMemo($message['hash']);
        if($transactions){
            foreach ($transactions as $transaction) {
                // Reorder the chain before marking as cancelled
                $this->reorderTransactionChain($transaction['txid']);
            }
            $this->transactionRepository->updateStatus($message['hash'], 'cancelled');
            output(outputTransactionExpired($message),'SILENT');
        }
    }

    /**
     * Cancel a transaction and reorder its chain
     *
     * When a transaction is cancelled directly (not through P2P expiration),
     * this method ensures the chain is properly reordered.
     *
     * @param string $txid The transaction ID to cancel
     * @return bool True if cancellation was successful
     */
    public function cancelTransaction(string $txid): bool {
        $transaction = $this->transactionRepository->getByTxid($txid);
        if (!$transaction || empty($transaction)) {
            return false;
        }

        // Reorder the chain before marking as cancelled
        $this->reorderTransactionChain($txid);

        // Update status to cancelled
        return $this->transactionRepository->updateStatus($txid, 'cancelled', true);
    }

    /**
     * Reorder transaction chain when a transaction is expired/cancelled
     *
     * When a transaction (A2) is expired/cancelled from a chain A1->A2->A3->A4,
     * the chain should become A1->A3->A4 (with A2 orphaned as A1->A2).
     *
     * This is done by:
     * 1. Finding transactions that reference the cancelled transaction as previous_txid
     * 2. Updating those transactions to point to the cancelled transaction's previous_txid
     *
     * @param string $txid The txid of the transaction being expired/cancelled
     * @return void
     */
    private function reorderTransactionChain(string $txid): void {
        try {
            // Get the transaction being cancelled
            $cancelledTx = $this->transactionRepository->getByTxid($txid);
            if (!$cancelledTx || empty($cancelledTx)) {
                return;
            }

            // Get the first result (getByTxid returns array)
            $cancelledTx = $cancelledTx[0];

            // Get the previous_txid of the cancelled transaction
            // This is what transactions pointing to cancelled tx should now point to
            $newPreviousTxid = $cancelledTx['previous_txid'];

            // Update all transactions that have the cancelled txid as their previous_txid
            // They should now point to the cancelled transaction's previous_txid
            $this->transactionRepository->updatePreviousTxidReferences($txid, $newPreviousTxid);

            SecureLogger::info("Transaction chain reordered", [
                'cancelled_txid' => $txid,
                'new_previous_txid' => $newPreviousTxid
            ]);
        } catch (Exception $e) {
            SecureLogger::error("Failed to reorder transaction chain", [
                'txid' => $txid,
                'error' => $e->getMessage()
            ]);
        }
    }
}