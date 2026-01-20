<?php
# Copyright 2025-2026 Vowels Group, LLC

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
     * @var BalanceRepository Balance repository instance
     */
    private BalanceRepository $balanceRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var TimeUtilityService Time utility service
     */
    private TimeUtilityService $timeUtility;

    /**
     * @var TransportUtilityService Transport utility service
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var MessagePayload payload builder for messages
     */
    private MessagePayload $messagePayload;

    /**
     * Constructor
     * @param P2pRepository $p2pRepository P2P repository
     * @param Rp2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        BalanceRepository $balanceRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->balanceRepository = $balanceRepository;
        $this->utilityContainer = $utilityContainer;
        $this->timeUtility = $utilityContainer->getTimeUtility();
        $this->transportUtility = $utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;

        require_once '/etc/eiou/src/schemas/payloads/MessagePayload.php';
        $this->messagePayload = new MessagePayload($this->currentUser, $this->utilityContainer);
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
     * Expire requests with P2P chain completion check
     *
     * Before expiring a P2P, this method checks if the transaction chain was actually
     * completed but the completion message was lost (e.g., ended up in dead letter queue).
     *
     * The check follows this order:
     * 1. Check locally if a completed transaction already exists for this P2P hash
     * 2. If not found locally, query the P2P sender to check their completion status
     * 3. If sender reports completed, mark as completed and sync transactions
     * 4. Only expire if no completion evidence is found
     *
     * @param array $message The request data
     * @return void
     */
    public function expireMessage($message): void {
        $hash = $message['hash'];
        $senderAddress = $message['sender_address'];

        // Step 1: Check locally if transaction already completed
        $transactions = $this->transactionRepository->getByMemo($hash);
        $hasCompletedTransaction = false;

        if ($transactions) {
            foreach ($transactions as $transaction) {
                if ($transaction['status'] === Constants::STATUS_COMPLETED) {
                    $hasCompletedTransaction = true;
                    break;
                }
            }
        }

        // If we already have a completed transaction locally, just update P2P status
        if ($hasCompletedTransaction) {
            $this->p2pRepository->updateStatus($hash, Constants::STATUS_COMPLETED, true);
            if (function_exists('output')) {
                output("P2P {$hash} marked completed: found local completed transaction", 'SILENT');
            }
            SecureLogger::info("P2P completion recovered from local transaction", [
                'hash' => $hash,
                'recovery_method' => 'local_check'
            ]);
            return;
        }

        // Step 2: Query the P2P sender about their status
        $senderStatus = $this->checkP2pStatusWithSender($senderAddress, $hash);

        if ($senderStatus === Constants::STATUS_COMPLETED) {
            // Sender has completion - sync the transaction and mark as completed
            $this->syncAndCompleteP2p($message);
            if (function_exists('output')) {
                output("P2P {$hash} marked completed: sender confirmed completion", 'SILENT');
            }
            SecureLogger::info("P2P completion recovered via sender inquiry", [
                'hash' => $hash,
                'sender_address' => $senderAddress,
                'recovery_method' => 'sender_inquiry'
            ]);
            return;
        }

        // Step 3: No completion evidence found - proceed with expiration
        $this->p2pRepository->updateStatus($hash, Constants::STATUS_EXPIRED);
        if (function_exists('output') && function_exists('outputP2pExpired')) {
            output(outputP2pExpired($message), 'SILENT');
        }

        // Cancel associated transactions if they exist
        if ($transactions) {
            $this->transactionRepository->updateStatus($hash, Constants::STATUS_CANCELLED);
            if (function_exists('output') && function_exists('outputTransactionExpired')) {
                output(outputTransactionExpired($message), 'SILENT');
            }
        }
    }

    /**
     * Check P2P status with the sender
     *
     * Sends an inquiry to the P2P sender to check if they have a completed status
     * for this P2P hash. This helps recover from lost completion messages.
     *
     * @param string $senderAddress The P2P sender's address
     * @param string $hash The P2P hash to inquire about
     * @return string|null The status from sender, or null if inquiry failed
     */
    private function checkP2pStatusWithSender(string $senderAddress, string $hash): ?string {
        try {
            $inquiryPayload = $this->messagePayload->buildP2pStatusInquiry($senderAddress, $hash);
            $response = json_decode(
                $this->transportUtility->send($senderAddress, $inquiryPayload),
                true
            );

            if ($response && isset($response['status'])) {
                SecureLogger::debug("P2P status inquiry response", [
                    'hash' => $hash,
                    'sender_address' => $senderAddress,
                    'response_status' => $response['status']
                ]);
                return $response['status'];
            }

            return null;
        } catch (Exception $e) {
            SecureLogger::warning("P2P status inquiry failed", [
                'hash' => $hash,
                'sender_address' => $senderAddress,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Sync and complete a P2P after confirming completion with sender
     *
     * When the P2P sender reports a completed status, this method:
     * 1. Updates the P2P status to completed
     * 2. Completes any associated transactions
     * 3. Updates the balance sheet
     *
     * @param array $message The P2P message data
     * @return void
     */
    private function syncAndCompleteP2p(array $message): void {
        $hash = $message['hash'];

        // Update P2P status to completed
        $this->p2pRepository->updateStatus($hash, Constants::STATUS_COMPLETED, true);

        // Complete associated transactions and update balances
        $transactions = $this->transactionRepository->getByMemo($hash);
        if ($transactions) {
            $this->transactionRepository->updateStatus($hash, Constants::STATUS_COMPLETED);
            $this->balanceRepository->updateBalanceGivenTransactions($transactions);
        }
    }

    /**
     * Cancel a transaction
     *
     * Marks the transaction as cancelled. The previous_txid chain is
     * preserved unchanged to maintain transaction history integrity.
     *
     * @param string $txid The transaction ID to cancel
     * @return bool True if cancellation was successful
     */
    public function cancelTransaction(string $txid): bool {
        $transaction = $this->transactionRepository->getByTxid($txid);
        if (!$transaction || empty($transaction)) {
            return false;
        }

        // Update status to cancelled
        return $this->transactionRepository->updateStatus($txid, Constants::STATUS_CANCELLED, true);
    }
}