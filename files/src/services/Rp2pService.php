<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\Rp2pServiceInterface;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Schemas\Payloads\Rp2pPayload;
use PDOException;
use Exception;
use RuntimeException;

/**
 * RP2P Service
 *
 * Handles all business logic for R peer-to-peer payment routing.
 * Integrates with MessageDeliveryService for reliable message delivery
 * with tracking, retry logic, and dead letter queue support.
 */
class Rp2pService implements Rp2pServiceInterface {
    /**
     * @var ContactRepository Contact repository instance
     */
    private ContactRepository $contactRepository;

    /**
     * @var BalanceRepository Balance repository instance
     */
    private BalanceRepository $balanceRepository;

    /**
     * @var P2pRepository P2P repository instance
     */
    private P2pRepository $p2pRepository;

    /**
     * @var RP2pRepository RP2P repository instance
     */
    private RP2pRepository $rp2pRepository;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var ValidationUtilityService Validation utility service
     */
    private ValidationUtilityService $validationUtility;

    /**
     * @var TransportUtilityService Transport utility service
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var TimeUtilityService Time utility service
     */
    private TimeUtilityService $timeUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var Rp2pPayload payload builder for Rp2p
     */
    private Rp2pPayload $rp2pPayload;

    /**
     * @var Rp2pCandidateRepository|null Repository for rp2p candidates in best-fee mode
     */
    private ?Rp2pCandidateRepository $rp2pCandidateRepository = null;

    /**
     * @var P2pSenderRepository|null Repository for tracking P2P senders (multi-path support)
     */
    private ?P2pSenderRepository $p2pSenderRepository = null;

    /**
     * @var P2pRelayedContactRepository|null Repository for tracking already_relayed contacts (two-phase selection)
     */
    private ?P2pRelayedContactRepository $p2pRelayedContactRepository = null;

    /**
     * @var MessageDeliveryService|null Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

    /**
     * @var P2pTransactionSenderInterface|null P2P transaction sender for sending P2P transactions
     *
     * This uses P2pTransactionSenderInterface instead of TransactionService directly
     * to break the circular dependency: TransactionService -> Rp2pService -> TransactionService.
     * By depending on the minimal interface, Rp2pService only needs the sendP2pEiou() method.
     */
    private ?P2pTransactionSenderInterface $p2pTransactionSender = null;

    /**
     * Set the P2P transaction sender (setter injection to break circular dependency)
     *
     * This method accepts P2pTransactionSenderInterface, which breaks the circular
     * dependency between Rp2pService and TransactionService. SendOperationService
     * implements P2pTransactionSenderInterface, so it can be passed here.
     *
     * @param P2pTransactionSenderInterface $sender P2P transaction sender
     */
    public function setP2pTransactionSender(P2pTransactionSenderInterface $sender): void {
        $this->p2pTransactionSender = $sender;
    }

    /**
     * Get the P2P transaction sender (must be injected via setP2pTransactionSender)
     *
     * @return P2pTransactionSenderInterface
     * @throws RuntimeException If P2P transaction sender was not injected
     */
    private function getP2pTransactionSender(): P2pTransactionSenderInterface {
        if ($this->p2pTransactionSender === null) {
            throw new RuntimeException('P2pTransactionSender not injected. Call setP2pTransactionSender() or ensure ServiceContainer::wireCircularDependencies() is called.');
        }
        return $this->p2pTransactionSender;
    }

    /**
     * Constructor
     *
     * @param ContactRepository $contactRepository Contact repository
     * @param BalanceRepository $balanceRepository Balance repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param RP2pRepository $rp2pRepository RP2P repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     * @param MessageDeliveryService|null $messageDeliveryService Optional delivery service for tracking
     * @param Rp2pCandidateRepository|null $rp2pCandidateRepository Optional repository for best-fee candidates
     * @param P2pSenderRepository|null $p2pSenderRepository Optional repository for multi-path sender tracking
     */
    public function __construct(
        ContactRepository $contactRepository,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        RP2pRepository $rp2pRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null,
        ?Rp2pCandidateRepository $rp2pCandidateRepository = null,
        ?P2pSenderRepository $p2pSenderRepository = null
    ) {
        $this->contactRepository = $contactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->utilityContainer = $utilityContainer;
        $this->validationUtility = $this->utilityContainer->getValidationUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;
        $this->rp2pCandidateRepository = $rp2pCandidateRepository;
        $this->p2pSenderRepository = $p2pSenderRepository;

        $this->rp2pPayload = new Rp2pPayload($this->currentUser,$this->utilityContainer);
    }

    /**
     * Set the message delivery service (for lazy initialization)
     *
     * @param MessageDeliveryService $service Message delivery service
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void {
        $this->messageDeliveryService = $service;
    }

    /**
     * Set the P2pRelayedContactRepository for two-phase best-fee selection
     *
     * @param P2pRelayedContactRepository $repository
     * @return void
     */
    public function setP2pRelayedContactRepository(P2pRelayedContactRepository $repository): void {
        $this->p2pRelayedContactRepository = $repository;
    }

    /**
     * Send an RP2P message with optional delivery tracking
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Falls back to direct transport
     * if delivery service is not configured.
     *
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string $hash RP2P hash for tracking
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    private function sendRp2pMessage(string $address, array $payload, string $hash): array {
        // Generate unique message ID for tracking
        // Format: relay-{hash}-{timestamp} (message_type 'rp2p' provides context)
        $messageId = 'relay-' . $hash . '-' . $this->timeUtility->getCurrentMicrotime();

        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // Use sync delivery (async=false) since RP2P messages are typically direct responses
            return $this->messageDeliveryService->sendMessage(
                'rp2p',
                $address,
                $payload,
                $messageId,
                false // sync
            );
        }

        // Fall back to direct transport when MessageDeliveryService not available
        $rawResponse = $this->transportUtility->send($address, $payload);
        $response = json_decode($rawResponse, true);

        return [
            'success' => $response !== null && isset($response['status']),
            'response' => $response,
            'raw' => $rawResponse,
            'messageId' => $messageId
        ];
    }

    /**
     * Handle incoming RP2P request
     *
     * @param array $request The RP2P request data
     * @return void
     */
    public function handleRp2pRequest(array $request): void {
        // Check if corresponding p2p exists 
        $p2p = $this->p2pRepository->getByHash($request['hash']);
        if(!$p2p){
            throw new Exception('P2P request was not found for the given hash.');
        }else{
            if(isset($p2p['destination_address'])) {
                $this->p2pRepository->updateStatus($request['hash'], 'found');
            }
            // Add users fee to request
            $request['amount'] += $p2p['my_fee_amount'];

            //Check if previous (intermediary) sender of p2p can afford to send eIOU with fees through you
            if(!isset($p2p['destination_address'])) {
                $availableFunds =  $this->validationUtility->calculateAvailableFunds($p2p);
                $creditLimit = $this->contactRepository->getCreditLimit($p2p['sender_public_key']);
                if(($creditLimit + $availableFunds) < $request['amount']){
                    output(outputP2pUnableToAffordRp2p($p2p,$request), 'SILENT');
                    return;
                }
            }

            // Save rp2p response 
            $insertResult = $this->rp2pRepository->insertRp2pRequest($request);
            if (!$insertResult) {
                output(outputRp2pInsertionFailure($request), 'SILENT');
            }
            // Check if original p2p was sent by user
            if(isset($p2p['destination_address'])) {
                $feePercent = $this->feeInformation($p2p,$request); // Get fee percent and output fee information in  log
                
                // Check if the fee percent is below the set maximum fee percent the user would pay
                if ($feePercent <= $this->currentUser->getMaxFee()) {
                    // Send transaction through rp2p chain using P2pTransactionSenderInterface
                    $this->getP2pTransactionSender()->sendP2pEiou($request);
                } else {
                    output(outputFeeRejection(), 'SILENT');
                }
            } else{
                // Send rp2p back to ALL upstream senders (multi-path support)
                $this->p2pRepository->updateStatus($request['hash'], 'found');  // Update the p2p request status to found

                // Base amount before this node's fee — used for per-sender fee calculation.
                // Each sender may have a different fee relationship with this node.
                $baseAmount = $request['amount'] - ($p2p['my_fee_amount'] ?? 0);

                // Get all senders from p2p_senders table (multi-path tracking)
                $senders = $this->p2pSenderRepository
                    ? $this->p2pSenderRepository->getSendersByHash($request['hash'])
                    : [];

                // Always include the original sender from the p2p record.
                // p2p_senders may be empty (legacy) or may not include the first
                // sender if it was stored before the p2p_senders tracking was added.
                $originalSender = $p2p['sender_address'];
                if (empty($senders)) {
                    $senders = [['sender_address' => $originalSender]];
                } else {
                    $senderAddresses = array_column($senders, 'sender_address');
                    if (!in_array($originalSender, $senderAddresses)) {
                        $senders[] = ['sender_address' => $originalSender];
                    }
                }

                $currencyUtility = $this->utilityContainer->getCurrencyUtility();
                $defaultFee = $this->currentUser->getDefaultFee();
                $minimumFee = $this->currentUser->getMinimumFee();

                foreach ($senders as $sender) {
                    // Calculate per-sender fee: each sender has a different fee
                    // relationship with this node (per-contact fee setting).
                    $senderAddress = $sender['sender_address'];
                    $transportIndex = $this->transportUtility->determineTransportType($senderAddress);
                    $senderContact = ($transportIndex !== null)
                        ? $this->contactRepository->lookupByAddress($transportIndex, $senderAddress)
                        : null;
                    $feePercent = $senderContact ? $senderContact['fee_percent'] : $defaultFee;
                    $senderFee = $currencyUtility->calculateFee($p2p['amount'], $feePercent, $minimumFee);

                    // Build per-sender RP2P payload with the correct fee
                    $senderRequest = $request;
                    $senderRequest['amount'] = $baseAmount + $senderFee;
                    $rP2pPayload = $this->rp2pPayload->build($senderRequest);

                    // Use tracked delivery for reliable message sending
                    $sendResult = $this->sendRp2pMessage($senderAddress, $rP2pPayload, $request['hash']);
                    $response = $sendResult['response'];

                    if ($sendResult['success']) {
                        output(outputRp2pResponse($response), 'SILENT');
                    } else {
                        // Log delivery failure details
                        $trackingResult = $sendResult['tracking'] ?? [];
                        $attempts = $trackingResult['attempts'] ?? 'unknown';
                        $lastError = $trackingResult['error'] ?? 'No response received';

                        if (class_exists(Logger::class)) {
                            Logger::getInstance()->warning("RP2P message delivery failed", [
                                'hash' => $request['hash'],
                                'sender_address' => $sender['sender_address'],
                                'attempts' => $attempts,
                                'error' => $lastError,
                                'moved_to_dlq' => $trackingResult['dlq'] ?? false
                            ]);
                        }

                        output(outputRp2pResponse($response ?? ['status' => 'failed', 'error' => $lastError]), 'SILENT');
                    }
                }
            }
        }
    }

    /**
     * Check Rp2p Possible
     *
     * @param array|null $request Request data
     * @return bool True if RP2P possible, False otherwise.
     */
    public function checkRp2pPossible($request, $echo = true){
        // Check if RP2P already exists for hash in database
        try{
            if($this->rp2pRepository->rp2pExists($request['hash'])){
              //If RP2P already exists
                if($echo){
                    echo  $this->rp2pPayload->buildRejection($request, 'duplicate');
                }
                return false;
            }

            // Check if P2P is in best-fee mode (fast=0).
            // Every node collects RP2P candidates from its downstream contacts,
            // picks the best, and forwards it upstream. This builds the optimal
            // fee route hop-by-hop backwards through the network.
            $p2p = $this->p2pRepository->getByHash($request['hash']);
            if ($p2p && !((int)($p2p['fast'] ?? 1))
                && $this->rp2pCandidateRepository !== null
            ) {
                // Best-fee mode: store as candidate instead of processing immediately
                try {
                    $this->handleRp2pCandidate($request, $p2p);
                    if ($echo) {
                        echo $this->rp2pPayload->buildInserted($request);
                    }
                    return false;
                } catch (Exception $e) {
                    Logger::getInstance()->logException($e, [
                        'method' => 'checkRp2pPossible',
                        'context' => 'rp2p_candidate_storage_failed'
                    ]);
                    if ($echo) {
                        echo $this->rp2pPayload->buildRejection($request, 'processing_error');
                    }
                    return false;
                }
            }

            // Fast mode (default): process RP2P immediately
            // IMPORTANT: Storage MUST succeed before acceptance is sent
            // to prevent false positives from acceptance-before-storage bug
            // (follows same pattern as TransactionService.checkTransactionPossible)
            try {
                $this->handleRp2pRequest($request);
                if($echo){
                    // Return 'inserted' status AFTER the RP2P has been stored in the database
                    echo  $this->rp2pPayload->buildInserted($request);
                }
                // Return false to prevent caller from calling handleRp2pRequest again
                return false;
            } catch (Exception $e) {
                Logger::getInstance()->logException($e, [
                    'method' => 'checkRp2pPossible',
                    'context' => 'rp2p_processing_failed'
                ]);
                if($echo){
                    echo  $this->rp2pPayload->buildRejection($request, 'processing_error');
                }
                return false;
            }
        } catch (PDOException $e) {
            // Handle database error
            Logger::getInstance()->error("Error retrieving existence of RP2P by hash", ['error' => $e->getMessage()]);
            if($echo){
                echo json_encode([
                    "status" => "rejected",
                    "message" => "Could not retrieve existence of RP2P with receiver"
                ]);
            }
            return false;
        }
    }

    /**
     * Handle an RP2P response as a candidate in best-fee mode
     *
     * Stores the rp2p response as a candidate rather than processing immediately.
     * Increments the responded count and triggers best-fee selection when all
     * contacts have responded.
     *
     * @param array $request The RP2P request data
     * @param array $p2p The corresponding P2P record from database
     * @return void
     */
    public function handleRp2pCandidate(array $request, array $p2p): void {
        // Add user's fee to the rp2p amount (same as handleRp2pRequest)
        $feeAmount = $p2p['my_fee_amount'] ?? 0;
        $request['amount'] += $feeAmount;

        // Check if previous sender can afford the rp2p amount (same validation as handleRp2pRequest)
        if (!isset($p2p['destination_address'])) {
            $availableFunds = $this->validationUtility->calculateAvailableFunds($p2p);
            $creditLimit = $this->contactRepository->getCreditLimit($p2p['sender_public_key']);
            if (($creditLimit + $availableFunds) < $request['amount']) {
                output(outputP2pUnableToAffordRp2p($p2p, $request), 'SILENT');
                return;
            }
        }

        // Store as candidate
        $this->rp2pCandidateRepository->insertCandidate($request, $feeAmount);

        // Atomically increment responded count
        $this->p2pRepository->incrementContactsRespondedCount($request['hash']);

        // If the P2P already expired (hop-wait elapsed) at a relay node, select
        // immediately since cleanup already ran and won't re-process this P2P.
        // Relay nodes (no destination_address) may have dead paths that never respond,
        // so we pick the best candidate as soon as any arrives after expiration.
        // Originators (have destination_address) fall through to the tracking check
        // below, which waits for all contacts to respond before selecting.
        if ($p2p['status'] === Constants::STATUS_EXPIRED && !isset($p2p['destination_address'])) {
            $this->selectAndForwardBestRp2p($request['hash']);
            return;
        }

        // Two-phase best-fee selection logic:
        // Phase 1: all inserted contacts responded → send best candidate to relayed contacts
        // Phase 2: all contacts (inserted + relayed) responded → re-select from ALL candidates
        // No relayed contacts: select immediately when all inserted responded (current behavior)
        $tracking = $this->p2pRepository->getTrackingCounts($request['hash']);
        if (!$tracking) {
            return;
        }

        $sentCount = (int) $tracking['contacts_sent_count'];
        $relayedCount = (int) ($tracking['contacts_relayed_count'] ?? 0);
        $respondedCount = (int) $tracking['contacts_responded_count'];

        // Phase 2 trigger: ALL contacts (inserted + relayed) responded
        if ($relayedCount > 0 && $respondedCount >= $sentCount + $relayedCount) {
            $this->selectAndForwardBestRp2p($request['hash']);
            return;
        }

        // Phase 1 trigger: all inserted contacts responded, relayed contacts exist
        if ($relayedCount > 0 && $respondedCount >= $sentCount) {
            $this->sendBestCandidateToRelayedContacts($request['hash']);
            return;
        }

        // No relayed contacts: select immediately when all inserted responded (current behavior)
        if ($relayedCount === 0 && $respondedCount >= $sentCount) {
            $this->selectAndForwardBestRp2p($request['hash']);
        }
    }

    /**
     * Phase 1: Send the best candidate to all already_relayed contacts
     *
     * Called when all inserted contacts have responded but relayed contacts
     * haven't yet. Sends the current best candidate as a standard RP2P to
     * break the mutual deadlock — relayed contacts receive it, incorporate
     * it into their own selection, and respond back for phase 2.
     *
     * @param string $hash The P2P hash
     * @return void
     */
    private function sendBestCandidateToRelayedContacts(string $hash): void
    {
        if ($this->rp2pCandidateRepository === null || $this->p2pRelayedContactRepository === null) {
            // Fallback: no relayed contact tracking, select immediately
            $this->selectAndForwardBestRp2p($hash);
            return;
        }

        // Guard: if already processed, skip
        if ($this->rp2pRepository->rp2pExists($hash)) {
            $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);
            return;
        }

        $bestCandidate = $this->rp2pCandidateRepository->getBestCandidate($hash);
        if (!$bestCandidate) {
            return; // No candidates yet, wait for more
        }

        // Build RP2P payload from best candidate.
        // The candidate amount includes this node's fee (added by handleRp2pCandidate).
        // We send it as-is: the relayed contact won't route it back to us (that
        // would be a cycle), and even if it did, the round-trip fee (ours + theirs)
        // makes the path clearly suboptimal so we'd never select it. Our fee must
        // be present for paths that continue through the relayed contact to other
        // upstream nodes — those routes legitimately include our hop.
        $request = [
            'hash' => $bestCandidate['hash'],
            'time' => $bestCandidate['time'],
            'amount' => (int) $bestCandidate['amount'],
            'currency' => $bestCandidate['currency'],
            'senderPublicKey' => $bestCandidate['sender_public_key'],
            'senderAddress' => $bestCandidate['sender_address'],
            'signature' => $bestCandidate['sender_signature'],
        ];
        $rp2pPayload = $this->rp2pPayload->build($request);

        // Send to all already_relayed contacts
        $relayedContacts = $this->p2pRelayedContactRepository->getRelayedContactsByHash($hash);
        foreach ($relayedContacts as $contact) {
            $this->sendRp2pMessage($contact['contact_address'], $rp2pPayload, $hash);
        }

        Logger::getInstance()->info("Phase 1: sent best candidate to relayed contacts", [
            'hash' => $hash,
            'relayed_contacts' => count($relayedContacts),
            'best_amount' => $bestCandidate['amount'],
        ]);
    }

    /**
     * Re-check best-fee selection after broadcast updates tracking counts
     *
     * Called by P2pService after setting the actual contacts_sent_count,
     * to handle RP2P responses that arrived during the broadcast loop
     * but couldn't trigger selection because the ceiling count was too high.
     *
     * @param string $hash P2P hash to check
     * @return void
     */
    public function checkBestFeeSelection(string $hash): void {
        if ($this->rp2pCandidateRepository === null) {
            return;
        }

        // Don't re-check if already selected
        if ($this->rp2pRepository->rp2pExists($hash)) {
            return;
        }

        $tracking = $this->p2pRepository->getTrackingCounts($hash);
        if (!$tracking) {
            return;
        }

        $sentCount = (int) $tracking['contacts_sent_count'];
        $relayedCount = (int) ($tracking['contacts_relayed_count'] ?? 0);
        $respondedCount = (int) $tracking['contacts_responded_count'];

        // Phase 2: ALL contacts (inserted + relayed) responded
        if ($relayedCount > 0 && $respondedCount >= $sentCount + $relayedCount) {
            $this->selectAndForwardBestRp2p($hash);
            return;
        }

        // Phase 1: all inserted contacts responded, relayed contacts exist
        if ($relayedCount > 0 && $respondedCount >= $sentCount) {
            $this->sendBestCandidateToRelayedContacts($hash);
            return;
        }

        // No relayed contacts: all inserted responded
        if ($relayedCount === 0 && $respondedCount >= $sentCount) {
            $this->selectAndForwardBestRp2p($hash);
        }
    }

    /**
     * Select the best (lowest fee) RP2P candidate and process it
     *
     * Called when all contacts have responded in best-fee mode, or when
     * the P2P is about to expire with pending candidates.
     *
     * @param string $hash The P2P hash
     * @return void
     */
    public function selectAndForwardBestRp2p(string $hash): void {
        if ($this->rp2pCandidateRepository === null) {
            return;
        }

        // Prevent duplicate forwarding: if we already processed an rp2p for this hash, skip
        if ($this->rp2pRepository->rp2pExists($hash)) {
            $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);
            return;
        }

        $bestCandidate = $this->rp2pCandidateRepository->getBestCandidate($hash);
        if (!$bestCandidate) {
            Logger::getInstance()->warning("No rp2p candidates found for best-fee selection", ['hash' => $hash]);
            return;
        }

        Logger::getInstance()->info("Best-fee route selected", [
            'hash' => $hash,
            'amount' => $bestCandidate['amount'],
            'fee_amount' => $bestCandidate['fee_amount'],
            'sender_address' => $bestCandidate['sender_address'],
            'total_candidates' => $this->rp2pCandidateRepository->getCandidateCount($hash),
        ]);

        // Convert candidate back to rp2p request format for handleRp2pRequest
        $request = [
            'hash' => $bestCandidate['hash'],
            'time' => $bestCandidate['time'],
            'amount' => (int) $bestCandidate['amount'],
            'currency' => $bestCandidate['currency'],
            'senderPublicKey' => $bestCandidate['sender_public_key'],
            'senderAddress' => $bestCandidate['sender_address'],
            'signature' => $bestCandidate['sender_signature'],
        ];

        // Process the best candidate via normal rp2p handling
        // Note: handleRp2pRequest adds the fee again, but the candidate amount
        // already includes our fee from handleRp2pCandidate, so we need to
        // subtract it before passing to handleRp2pRequest
        $p2p = $this->p2pRepository->getByHash($hash);
        if ($p2p) {
            $request['amount'] -= ($p2p['my_fee_amount'] ?? 0);
        }

        $this->handleRp2pRequest($request);

        // Clean up all candidates for this hash
        $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);
    }

    /**
     * Return fee percent of request and output fee information into the log
     *
     * @param array $p2p The p2p request data from the database
     * @param array $request The transaction request data
     * @return float Fee percent of request
    */
    public function feeInformation(array $p2p, array $request): float {
        if ($p2p['amount'] == 0) {
            return 0.0;
        }
        $feeAmount = $request['amount'] - $p2p['amount'];
        $feePercent = round(($feeAmount / $p2p['amount']) * Constants::FEE_CONVERSION_FACTOR, Constants::FEE_PERCENT_DECIMAL_PRECISION);
        output(outputFeeInformation($feePercent,$request,$this->currentUser->getMaxFee()), 'SILENT'); // output fee information into the log
        return $feePercent;
    }
}