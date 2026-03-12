<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\CleanupServiceInterface;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Contracts\ChainDropServiceInterface;
use Eiou\Contracts\Rp2pServiceInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Database\RepositoryFactory;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Database\CapacityReservationRepository;
use Eiou\Database\RouteCancellationRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Schemas\Payloads\MessagePayload;
use PDOException;
use Exception;

/**
 * Cleanup Service
 *
 * Handles all business logic for cleanup management.
 */
class CleanupService implements CleanupServiceInterface {
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
     * @var MessageDeliveryServiceInterface Message delivery service for retry queue processing
     */
    private MessageDeliveryServiceInterface $messageDeliveryService;

    /**
     * @var ChainDropServiceInterface|null Chain drop service for proposal expiration
     */
    private ?ChainDropServiceInterface $chainDropService = null;

    /**
     * @var Rp2pCandidateRepository|null Repository for rp2p candidates (best-fee mode)
     */
    private ?Rp2pCandidateRepository $rp2pCandidateRepository = null;

    /**
     * @var Rp2pServiceInterface|null Rp2p service for best-fee route selection
     */
    private ?Rp2pServiceInterface $rp2pService = null;

    /**
     * @var P2pSenderRepository|null Repository for cleaning up old P2P sender records
     */
    private ?P2pSenderRepository $p2pSenderRepository = null;

    /**
     * @var P2pRelayedContactRepository|null Repository for cleaning up old P2P relayed contact records
     */
    private ?P2pRelayedContactRepository $p2pRelayedContactRepository = null;

    /**
     * @var P2pServiceInterface|null P2P service for cascade cancel notification on expiration
     */
    private ?P2pServiceInterface $p2pService = null;

    /**
     * @var CapacityReservationRepository|null Capacity reservation repository
     */
    private ?CapacityReservationRepository $capacityReservationRepository = null;

    /**
     * @var RouteCancellationRepository|null Route cancellation repository
     */
    private ?RouteCancellationRepository $routeCancellationRepository = null;

    /**
     * Constructor
     * @param P2pRepository $p2pRepository P2P repository
     * @param Rp2pRepository $rp2pRepository RP2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     * @param MessageDeliveryServiceInterface $messageDeliveryService Message delivery service
     */
    public function __construct(
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        BalanceRepository $balanceRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        MessageDeliveryServiceInterface $messageDeliveryService,
        RepositoryFactory $repositoryFactory
    ) {
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->balanceRepository = $balanceRepository;
        $this->utilityContainer = $utilityContainer;
        $this->timeUtility = $utilityContainer->getTimeUtility();
        $this->transportUtility = $utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;

        $this->messagePayload = new MessagePayload($this->currentUser, $this->utilityContainer);

        $this->rp2pCandidateRepository = $repositoryFactory->get(\Eiou\Database\Rp2pCandidateRepository::class);
        $this->p2pSenderRepository = $repositoryFactory->get(\Eiou\Database\P2pSenderRepository::class);
        $this->p2pRelayedContactRepository = $repositoryFactory->get(\Eiou\Database\P2pRelayedContactRepository::class);
        $this->capacityReservationRepository = $repositoryFactory->get(\Eiou\Database\CapacityReservationRepository::class);
        $this->routeCancellationRepository = $repositoryFactory->get(\Eiou\Database\RouteCancellationRepository::class);
    }

    /**
     * Set the ChainDropService for proposal expiration
     * Uses setter injection to avoid circular dependencies
     *
     * @param ChainDropServiceInterface $chainDropService
     * @return void
     */
    public function setChainDropService(ChainDropServiceInterface $chainDropService): void
    {
        $this->chainDropService = $chainDropService;
    }

    /**
     * Set the Rp2pService for best-fee route selection on expiration
     *
     * @param Rp2pServiceInterface $rp2pService
     * @return void
     */
    public function setRp2pService(Rp2pServiceInterface $rp2pService): void
    {
        $this->rp2pService = $rp2pService;
    }

    /**
     * Set the P2pService for cascade cancel notification on P2P expiration
     *
     * @param P2pServiceInterface $p2pService
     * @return void
     */
    public function setP2pService(P2pServiceInterface $p2pService): void
    {
        $this->p2pService = $p2pService;
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
        $processed = 0;

        // Process expired P2P messages
        try {
            // Get current microtime for accurate comparison with stored expiration values
            $currentMicrotime = $this->timeUtility->getCurrentMicrotime();

            // Get P2P messages that have already expired (SQL filters by expiration < currentMicrotime)
            $expiredMessages = $this->p2pRepository->getExpiredP2p($currentMicrotime);

            // Process each expired message
            foreach ($expiredMessages as $message) {
                $this->expireMessage($message);
            }

            $processed += count($expiredMessages);
        } catch (PDOException $e) {
            Logger::getInstance()->error("Error processing cleanup messages", ['error' => $e->getMessage()]);
        }

        // Process message delivery retry queue (failed async sends awaiting retry)
        try {
            $retryResults = $this->messageDeliveryService->processRetryQueue(10);
            $processed += $retryResults['processed'];
        } catch (Exception $e) {
            Logger::getInstance()->error("Error processing retry queue", ['error' => $e->getMessage()]);
        }

        // Expire transactions that have passed their per-type delivery deadline.
        // P2P transactions: expires_at = p2p_expiry + DIRECT_TX_DELIVERY_EXPIRATION_SECONDS
        // Direct transactions: expires_at set only when directTxExpiration > 0 (user setting)
        // This runs independently of P2P expiry so the two lifecycles stay decoupled.
        try {
            $expiredTxCount = $this->expireStaleTransactions();
            $processed += $expiredTxCount;
        } catch (Exception $e) {
            Logger::getInstance()->error("Error expiring stale transactions", ['error' => $e->getMessage()]);
        }

        // Expire stale chain drop proposals (7-day timeout)
        try {
            if ($this->chainDropService !== null) {
                $expiredCount = $this->chainDropService->expireStaleProposals();
                $processed += $expiredCount;
            }
        } catch (Exception $e) {
            Logger::getInstance()->error("Error expiring chain drop proposals", ['error' => $e->getMessage()]);
        }

        // Clean up old P2P sender records (1-day retention)
        try {
            if ($this->p2pSenderRepository !== null) {
                $this->p2pSenderRepository->deleteOldRecords();
            }
        } catch (Exception $e) {
            Logger::getInstance()->error("Error cleaning up P2P sender records", ['error' => $e->getMessage()]);
        }

        // Clean up old P2P relayed contact records (1-day retention)
        try {
            if ($this->p2pRelayedContactRepository !== null) {
                $this->p2pRelayedContactRepository->deleteOldRecords(1);
            }
        } catch (Exception $e) {
            Logger::getInstance()->error("Error cleaning up P2P relayed contact records", ['error' => $e->getMessage()]);
        }

        // Release capacity reservations for expired/cancelled P2Ps (natural fallback)
        try {
            if ($this->capacityReservationRepository !== null) {
                $this->capacityReservationRepository->deleteOldRecords(7);
            }
        } catch (Exception $e) {
            Logger::getInstance()->error("Error cleaning up capacity reservations", ['error' => $e->getMessage()]);
        }

        // Clean up old route cancellation audit records
        try {
            if ($this->routeCancellationRepository !== null) {
                $this->routeCancellationRepository->deleteOldRecords(7);
            }
        } catch (Exception $e) {
            Logger::getInstance()->error("Error cleaning up route cancellation records", ['error' => $e->getMessage()]);
        }

        // Originator fallback: select best route for expired originator P2Ps
        // whose contacts never all responded (dead paths). After a grace period,
        // pick the best candidate we have rather than waiting indefinitely.
        try {
            if ($this->rp2pService !== null && $this->rp2pCandidateRepository !== null) {
                $staleOriginators = $this->p2pRepository->getExpiredOriginatorP2psWithCandidates($currentMicrotime);
                foreach ($staleOriginators as $p2p) {
                    $this->rp2pService->selectAndForwardBestRp2p($p2p['hash']);

                    // Respect the approval gate: don't override awaiting_approval with 'found'
                    $updatedP2p = $this->p2pRepository->getByHash($p2p['hash']);
                    if ($updatedP2p && ($updatedP2p['status'] ?? '') === Constants::STATUS_AWAITING_APPROVAL) {
                        Logger::getInstance()->info("Originator fallback: deferred to user approval", [
                            'hash' => $p2p['hash'],
                        ]);
                        $processed++;
                        continue;
                    }

                    $this->p2pRepository->updateStatus($p2p['hash'], 'found');
                    Logger::getInstance()->info("Originator fallback: best-fee selection for stale P2P", [
                        'hash' => $p2p['hash'],
                    ]);
                    $processed++;
                }
            }
        } catch (Exception $e) {
            Logger::getInstance()->error("Error processing originator fallback selection", ['error' => $e->getMessage()]);
        }

        return $processed;
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
            Logger::getInstance()->info("P2P completion recovered from local transaction", [
                'hash' => $hash,
                'recovery_method' => 'local_check'
            ]);
            return;
        }

        // Step 1.5: If P2P is in best-fee mode, select best candidate before expiring.
        // Both relay and originator nodes select immediately at expiration when candidates
        // exist. This avoids the originator waiting an extra grace period when one contact's
        // cascade is slow/dead. Double-selection is safe: selectAndForwardBestRp2p() has
        // an rp2pExists() guard that prevents duplicate processing.
        //
        // Skip if already awaiting_approval — the user must consent first. Let it expire
        // normally so the approval UI shows it as expired and candidates are cleaned up.
        $currentStatus = $message['status'] ?? '';
        if ($currentStatus !== Constants::STATUS_AWAITING_APPROVAL
            && !((int)($message['fast'] ?? 1))
            && $this->rp2pCandidateRepository !== null
            && $this->rp2pService !== null
        ) {
            $candidateCount = $this->rp2pCandidateRepository->getCandidateCount($hash);
            if ($candidateCount > 0) {
                // Select and forward best route before expiring
                $this->rp2pService->selectAndForwardBestRp2p($hash);

                // Respect the approval gate: if selectAndForwardBestRp2p set
                // awaiting_approval (originator with auto-accept off), don't
                // override with 'found' — the user must approve first.
                $updatedP2p = $this->p2pRepository->getByHash($hash);
                if ($updatedP2p && ($updatedP2p['status'] ?? '') === Constants::STATUS_AWAITING_APPROVAL) {
                    Logger::getInstance()->info("Best-fee selection deferred to user approval on expiration", [
                        'hash' => $hash,
                        'candidate_count' => $candidateCount,
                    ]);
                    return;
                }

                // Mark as 'found' to prevent re-processing by subsequent cleanup cycles
                $this->p2pRepository->updateStatus($hash, 'found');
                if (function_exists('output')) {
                    output("P2P {$hash} best-fee selection triggered on expiration ({$candidateCount} candidates)", 'SILENT');
                }
                Logger::getInstance()->info("Best-fee selection triggered on P2P expiration", [
                    'hash' => $hash,
                    'candidate_count' => $candidateCount,
                ]);
                // Don't expire - the P2P is now being processed via best route
                return;
            }
        }

        // Step 2: Query the P2P sender about their status
        $senderStatus = $this->checkP2pStatusWithSender($senderAddress, $hash);

        if ($senderStatus === Constants::STATUS_COMPLETED) {
            // Sender has completion - sync the transaction and mark as completed
            $this->syncAndCompleteP2p($message);
            if (function_exists('output')) {
                output("P2P {$hash} marked completed: sender confirmed completion", 'SILENT');
            }
            Logger::getInstance()->info("P2P completion recovered via sender inquiry", [
                'hash' => $hash,
                'sender_address' => $senderAddress,
                'recovery_method' => 'sender_inquiry'
            ]);
            return;
        }

        // Step 3: No completion evidence found - proceed with expiration
        $this->p2pRepository->updateStatus($hash, Constants::STATUS_EXPIRED);
        $this->capacityReservationRepository?->releaseByHash($hash, 'expired');
        if (function_exists('output') && function_exists('outputP2pExpired')) {
            output(outputP2pExpired($message), 'SILENT');
        }

        // Send cancel notification upstream so upstream nodes can trigger
        // selection immediately instead of waiting for their own expiration.
        // Only for relay nodes (no destination_address) — originators have no upstream.
        if (!isset($message['destination_address']) && $this->p2pService !== null) {
            $this->p2pService->sendCancelNotificationForHash($hash);
        }

        // Cancel only 'pending' transactions for this P2P hash.
        // Transactions already in-flight (sending/sent/accepted) are allowed to
        // complete naturally — they have an expires_at deadline of
        // p2p_expiry + DIRECT_TX_DELIVERY_EXPIRATION_SECONDS set at send time,
        // so expireStaleTransactions() will clean them up if they don't complete.
        if ($transactions) {
            $this->transactionRepository->cancelPendingByMemo($hash);
            if (function_exists('output') && function_exists('outputTransactionExpired')) {
                output(outputTransactionExpired($message), 'SILENT');
            }
        }
    }

    /**
     * Expire transactions that have passed their expires_at delivery deadline.
     *
     * P2P transactions have expires_at = p2p_expiry + DIRECT_TX_DELIVERY_EXPIRATION_SECONDS,
     * giving them a delivery window even after the parent P2P request expires.
     * Direct transactions have expires_at set only when the user configures
     * directTxExpiration > 0 (default: no expiry).
     *
     * @return int Number of transactions expired
     */
    public function expireStaleTransactions(): int {
        $expired = $this->transactionRepository->getExpiredTransactions();

        foreach ($expired as $tx) {
            $this->transactionRepository->updateStatus($tx['txid'], Constants::STATUS_CANCELLED, true);
            Logger::getInstance()->info("Transaction expired past delivery deadline", [
                'txid'       => $tx['txid'],
                'tx_type'    => $tx['tx_type'] ?? 'unknown',
                'status'     => $tx['status'],
                'expires_at' => $tx['expires_at'],
            ]);
        }

        return count($expired);
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
                Logger::getInstance()->debug("P2P status inquiry response", [
                    'hash' => $hash,
                    'sender_address' => $senderAddress,
                    'response_status' => $response['status']
                ]);
                return $response['status'];
            }

            return null;
        } catch (Exception $e) {
            Logger::getInstance()->warning("P2P status inquiry failed", [
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

        // Idempotency guard: skip if P2P already completed (balance already updated)
        $p2p = $this->p2pRepository->getByHash($hash);
        if ($p2p && $p2p['status'] === Constants::STATUS_COMPLETED) {
            Logger::getInstance()->info("syncAndCompleteP2p skipped: P2P already completed", ['hash' => $hash]);
            return;
        }

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