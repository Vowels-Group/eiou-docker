<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\Rp2pServiceInterface;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Schemas\Payloads\Rp2pPayload;
use Eiou\Contracts\RouteCancellationServiceInterface;
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
     * @var P2pServiceInterface|null P2P service for cascade cancel notification propagation
     */
    private ?P2pServiceInterface $p2pService = null;

    /**
     * @var ContactCurrencyRepository|null Repository for per-currency fee lookup
     */
    private ?ContactCurrencyRepository $contactCurrencyRepository = null;

    /**
     * @var RouteCancellationServiceInterface|null Route cancellation service
     */
    private ?RouteCancellationServiceInterface $routeCancellationService = null;

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
     * Set the P2pService for cascade cancel notification propagation
     *
     * @param P2pServiceInterface $service
     * @return void
     */
    public function setP2pService(P2pServiceInterface $service): void {
        $this->p2pService = $service;
    }

    /**
     * Set the RouteCancellationService
     *
     * @param RouteCancellationServiceInterface $service
     */
    public function setRouteCancellationService(RouteCancellationServiceInterface $service): void {
        $this->routeCancellationService = $service;
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
        ?P2pSenderRepository $p2pSenderRepository = null,
        RepositoryFactory $repositoryFactory = null
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
        if ($repositoryFactory !== null) {
            $this->p2pRelayedContactRepository = $repositoryFactory->get(\Eiou\Database\P2pRelayedContactRepository::class);
            $this->contactCurrencyRepository = $repositoryFactory->get(\Eiou\Database\ContactCurrencyRepository::class);
        }
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
            'success' => $response !== null && in_array($response['status'] ?? null, Constants::DELIVERY_SUCCESS_STATUSES, true),
            'response' => $response,
            'raw' => $rawResponse,
            'messageId' => $messageId
        ];
    }

    /**
     * Handle incoming RP2P request
     *
     * @param array $request The RP2P request data
     * @return bool True if the rp2p was successfully processed, false if rejected
     */
    public function handleRp2pRequest(array $request): bool {
        // Check if corresponding p2p exists
        $p2p = $this->p2pRepository->getByHash($request['hash']);
        if(!$p2p){
            throw new Exception('P2P request was not found for the given hash.');
        }

        // Only relay nodes charge fees — the originator (destination_address is set)
        // does not relay and must not add its own fee to the amount.
        if(!isset($p2p['destination_address'])) {
            // Recalculate fee on the accumulated RP2P amount (multiplicative/compounding fees).
            // Each relay charges its fee on the total including downstream fees, not the original base.
            // The exact rounded fee is saved to my_fee_amount so TransactionService::removeTransactionFee()
            // subtracts the identical value — preventing rounding discrepancies.
            $requestAmount = SplitAmount::from($request['amount']);
            $recalculatedFee = $this->calculateFeeForP2p($p2p, $requestAmount);
            $this->p2pRepository->updateFeeAmount($request['hash'], $recalculatedFee);
            $request['amount'] = $requestAmount->add($recalculatedFee);
        }

        //Check if previous (intermediary) sender of p2p can afford to send eIOU with fees through you
        if(!isset($p2p['destination_address'])) {
            $availableFunds = $this->validationUtility->calculateAvailableFunds($p2p);
            $creditLimit = $this->contactRepository->getCreditLimit($p2p['sender_public_key'], $p2p['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY);
            $totalAvailable = $availableFunds->add($creditLimit);
            $requestAmount = SplitAmount::from($request['amount']);
            if($totalAvailable->lt($requestAmount)){
                output(outputP2pUnableToAffordRp2p($p2p,$request), 'SILENT');
                return false;
            }
        }

        // Originator fee check — validate BEFORE inserting rp2p so rejected
        // candidates don't pollute the database and fallback can try the next one
        if(isset($p2p['destination_address'])) {
            $feePercent = $this->feeInformation($p2p,$request);
            if ($feePercent > $this->currentUser->getMaxFee()) {
                output(outputFeeRejection(), 'SILENT');
                return false;
            }
        }

        // Save rp2p response (only after all validation passes)
        $insertResult = $this->rp2pRepository->insertRp2pRequest($request);
        if (!$insertResult) {
            output(outputRp2pInsertionFailure($request), 'SILENT');
            return false;
        }

        // Check if original p2p was sent by user
        if(isset($p2p['destination_address'])) {
            // Manual approval gate: present route for user approval instead of auto-sending.
            // Applies to fast mode and best-fee auto-selection (called from selectAndForwardBestRp2p).
            // Best-fee manual approval is handled earlier in selectAndForwardBestRp2p() and never reaches here.
            if (!$this->currentUser->getAutoAcceptTransaction()) {
                $this->p2pRepository->setRp2pAmount($request['hash'], $request['amount']);
                $this->p2pRepository->updateStatus($request['hash'], Constants::STATUS_AWAITING_APPROVAL);
                Logger::getInstance()->info("P2P route awaiting user approval", [
                    'hash' => $request['hash'],
                    'amount' => $request['amount'],
                ]);
                return true;
            }
            $this->p2pRepository->updateStatus($request['hash'], 'found');
            $this->getP2pTransactionSender()->sendP2pEiou($request);
        } else{
                // Send rp2p back to ALL upstream senders (multi-path support)
                $this->p2pRepository->updateStatus($request['hash'], 'found');  // Update the p2p request status to found

                // Base amount before this node's fee — the accumulated downstream total.
                // Each sender may have a different fee relationship with this node,
                // so per-sender fees are recalculated on this accumulated base.
                $baseAmount = SplitAmount::from($request['amount'])->subtract($recalculatedFee);

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

                // Ensure relayed contacts are included in the senders list.
                // A contact may be in p2p_relayed_contacts (from our broadcast) but
                // not yet in p2p_senders (their broadcast to us hasn't arrived).
                // Without this, such contacts never receive the RP2P response.
                if ($this->p2pRelayedContactRepository !== null) {
                    $relayedContacts = $this->p2pRelayedContactRepository->getRelayedContactsByHash($request['hash']);
                    $senderAddresses = array_column($senders, 'sender_address');
                    foreach ($relayedContacts as $contact) {
                        if (!in_array($contact['contact_address'], $senderAddresses)) {
                            $senders[] = ['sender_address' => $contact['contact_address']];
                        }
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

                    // Fee is per-currency in contact_currencies table.
                    // defaultFee is raw percentage; DB getFeePercent() is scaled by FEE_CONVERSION_FACTOR.
                    $feePercent = $defaultFee;
                    if ($senderContact && isset($senderContact['pubkey_hash'])) {
                        $currency = $p2p['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                        $contactFee = $this->contactCurrencyRepository?->getFeePercent($senderContact['pubkey_hash'], $currency);
                        if ($contactFee !== null) {
                            $feePercent = $contactFee / Constants::FEE_CONVERSION_FACTOR;
                        }
                    }
                    $baseAmountSplit = SplitAmount::from($baseAmount);
                    $senderFee = $currencyUtility->calculateFee($baseAmountSplit, $feePercent, $minimumFee);

                    // Build per-sender RP2P payload with the correct fee
                    $senderRequest = $request;
                    $senderRequest['amount'] = $baseAmountSplit->add($senderFee);
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

        return true;
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
            // Handle cancel notification from downstream dead-end contact
            if (isset($request['cancelled']) && $request['cancelled'] === true) {
                $p2p = $this->p2pRepository->getByHash($request['hash']);
                // Run cancel cascade for all modes (fast and best-fee).
                // Both modes need response counting so relay nodes can detect
                // when ALL contacts are dead ends and propagate cancel upstream.
                if ($p2p) {
                    $this->handleCancelNotification($request, $p2p);
                }
                // Always echo a response so the sender doesn't get "No response
                // received from recipient" and retry futilely until DLQ.
                if ($echo) {
                    echo json_encode(['status' => 'received', 'message' => 'cancel notification processed']);
                }
                return false;
            }

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
                $accepted = $this->handleRp2pRequest($request);
                if (!$accepted) {
                    // RP2P rejected (fee too high or relay can't afford) — count
                    // this as a responded contact so the node can detect when ALL
                    // downstream paths are dead and cancel immediately instead
                    // of waiting for expiration timeout.
                    $fromAddress = $request['senderAddress'] ?? null;
                    $isFromRelayed = false;
                    if ($fromAddress && $this->p2pRelayedContactRepository !== null) {
                        $isFromRelayed = $this->p2pRelayedContactRepository->isRelayedContact($request['hash'], $fromAddress);
                    }
                    if ($isFromRelayed) {
                        $this->p2pRepository->incrementContactsRelayedRespondedCount($request['hash']);
                    } else {
                        $this->p2pRepository->incrementContactsRespondedCount($request['hash']);
                    }

                    // Check if all contacts have responded — cancel and propagate upstream
                    $tracking = $this->p2pRepository->getTrackingCounts($request['hash']);
                    if ($tracking) {
                        $sentCount = (int) $tracking['contacts_sent_count'];
                        $relayedCount = (int) ($tracking['contacts_relayed_count'] ?? 0);
                        $respondedCount = (int) $tracking['contacts_responded_count'];
                        $relayedRespondedCount = (int) ($tracking['contacts_relayed_responded_count'] ?? 0);

                        $currentStatus = $p2p['status'] ?? null;
                        if (($respondedCount + $relayedRespondedCount) >= ($sentCount + $relayedCount)
                            && $sentCount > 0
                            && $currentStatus !== Constants::STATUS_CANCELLED
                            && $currentStatus !== 'expired'
                        ) {
                            Logger::getInstance()->info("All downstream RP2P paths rejected, cancelling P2P", [
                                'hash' => $request['hash'],
                                'responded' => $respondedCount + $relayedRespondedCount,
                                'total' => $sentCount + $relayedCount,
                            ]);
                            $this->p2pRepository->updateStatus($request['hash'], Constants::STATUS_CANCELLED);
                            if ($this->p2pService !== null) {
                                $this->p2pService->sendCancelNotificationForHash($request['hash']);
                            }
                        }
                    }
                }
                if($echo){
                    if ($accepted) {
                        // Return 'inserted' status AFTER the RP2P has been stored in the database
                        echo  $this->rp2pPayload->buildInserted($request);
                    } else {
                        // Fee too high or relay can't afford — reject downstream
                        echo  $this->rp2pPayload->buildRejection($request, 'rejected');
                    }
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
        // Don't accept candidates if selection has already been made or P2P is terminal.
        // Allow candidates during awaiting_approval so late-arriving routes still appear
        // in the user's candidate list (the GUI fetches candidates via AJAX on each load).
        $status = $p2p['status'] ?? '';
        if (in_array($status, ['found', 'paid', 'completed', Constants::STATUS_CANCELLED], true)) {
            return;
        }

        // Recalculate fee on the accumulated RP2P amount (multiplicative/compounding fees).
        // Same logic as handleRp2pRequest — fee is based on accumulated total, not original base.
        $requestAmount = SplitAmount::from($request['amount']);
        $feeAmount = $this->calculateFeeForP2p($p2p, $requestAmount);
        $this->p2pRepository->updateFeeAmount($request['hash'], $feeAmount);
        $request['amount'] = $requestAmount->add($feeAmount);

        // Check if previous sender can afford the rp2p amount (same validation as handleRp2pRequest)
        if (!isset($p2p['destination_address'])) {
            $availableFunds = $this->validationUtility->calculateAvailableFunds($p2p);
            $creditLimit = $this->contactRepository->getCreditLimit($p2p['sender_public_key'], $p2p['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY);
            $totalAvailable = $availableFunds->add($creditLimit);
            $candidateAmount = SplitAmount::from($request['amount']);
            if ($totalAvailable->lt($candidateAmount)) {
                output(outputP2pUnableToAffordRp2p($p2p, $request), 'SILENT');
                return;
            }
        }

        // Store as candidate
        $this->rp2pCandidateRepository->insertCandidate($request, $feeAmount);

        // Classify the RP2P source to increment the correct response counter.
        // senderAddress is always the direct contact (each hop re-stamps the payload).
        // Two categories:
        //   1. Relayed downstream contact (in p2p_relayed_contacts) — Phase 2 response
        //   2. Inserted downstream contact (default) — Phase 1 response
        $fromAddress = $request['senderAddress'] ?? null;
        $isFromRelayed = false;
        if ($fromAddress && $this->p2pRelayedContactRepository !== null) {
            $isFromRelayed = $this->p2pRelayedContactRepository->isRelayedContact($request['hash'], $fromAddress);
        }

        if ($isFromRelayed) {
            $this->p2pRepository->incrementContactsRelayedRespondedCount($request['hash']);
        } else {
            $this->p2pRepository->incrementContactsRespondedCount($request['hash']);
        }

        // Don't trigger selection if the P2P hasn't been forwarded yet (still queued).
        // Store the candidate and count the response, but defer selection to the daemon's
        // checkBestFeeSelection call after it processes and forwards the queued P2P.
        if ($p2p['status'] === Constants::STATUS_QUEUED) {
            return;
        }

        // Don't re-trigger selection if already awaiting user approval.
        // The candidate was stored above and will appear when the GUI refreshes.
        if ($status === Constants::STATUS_AWAITING_APPROVAL) {
            return;
        }

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
        // Phase 1: all INSERTED contacts responded → send best candidate to relayed contacts (once)
        // Phase 2: all RELAYED contacts responded → select final best from ALL candidates
        // No relayed contacts: select immediately when all inserted responded
        $tracking = $this->p2pRepository->getTrackingCounts($request['hash']);
        if (!$tracking) {
            return;
        }

        $sentCount = (int) $tracking['contacts_sent_count'];
        $relayedCount = (int) ($tracking['contacts_relayed_count'] ?? 0);
        $insertedRespondedCount = (int) $tracking['contacts_responded_count'];
        $relayedRespondedCount = (int) ($tracking['contacts_relayed_responded_count'] ?? 0);
        $phase1Sent = (bool) ($tracking['phase1_sent'] ?? 0);

        // Phase 2 trigger: all propagated contacts (inserted + relayed) responded
        // The original P2P sender (upstream) is NOT counted — we send the result TO them
        if ($relayedCount > 0 && ($insertedRespondedCount + $relayedRespondedCount) >= ($sentCount + $relayedCount)) {
            $this->selectAndForwardBestRp2p($request['hash']);
            return;
        }

        // Phase 1 trigger: all inserted contacts responded, relayed contacts still pending
        // Only fires ONCE (phase1_sent guard prevents infinite loop between nodes)
        if ($relayedCount > 0 && $insertedRespondedCount >= $sentCount && !$phase1Sent) {
            $this->sendBestCandidateToRelayedContacts($request['hash']);
            return;
        }

        // No relayed contacts: select immediately when all inserted responded
        if ($relayedCount === 0 && $insertedRespondedCount >= $sentCount) {
            $this->selectAndForwardBestRp2p($request['hash']);
        }
    }

    /**
     * Handle a cancel notification from a downstream contact
     *
     * When a downstream contact cancels a P2P (dead-end or expired with no route),
     * it sends a cancel notification. This method counts it as a response and
     * triggers selection or cascade cancellation when all contacts have responded.
     *
     * @param array $request The cancel notification payload
     * @param array $p2p The corresponding P2P record
     * @return void
     */
    public function handleCancelNotification(array $request, array $p2p): void
    {
        // Don't process if P2P is already cancelled/expired — prevents feedback loop
        // where repeated cancel notifications keep incrementing counters and re-triggering
        // selection, which sends more cancel notifications upstream.
        $status = $p2p['status'] ?? null;
        if ($status === Constants::STATUS_CANCELLED || $status === 'expired') {
            return;
        }

        // Don't process if already selected/forwarded
        if ($this->rp2pRepository->rp2pExists($request['hash'])) {
            return;
        }

        // Classify the cancel source to increment the correct counter
        $fromAddress = $request['senderAddress'] ?? null;
        $isFromRelayed = false;
        if ($fromAddress && $this->p2pRelayedContactRepository !== null) {
            $isFromRelayed = $this->p2pRelayedContactRepository->isRelayedContact($request['hash'], $fromAddress);
        }

        if ($isFromRelayed) {
            $this->p2pRepository->incrementContactsRelayedRespondedCount($request['hash']);
        } else {
            $this->p2pRepository->incrementContactsRespondedCount($request['hash']);
        }

        Logger::getInstance()->info("Received cancel notification from downstream contact", [
            'hash' => $request['hash'],
            'from' => $fromAddress,
            'is_relayed' => $isFromRelayed,
        ]);

        // Don't trigger selection if the P2P hasn't been forwarded yet (still queued).
        // The P2P daemon will process and forward this P2P, setting the correct
        // contacts_sent_count. Cancel notifications arriving before forwarding would
        // see sentCount=0 and prematurely trigger cancellation. The daemon's
        // checkBestFeeSelection call after processing handles deferred responses.
        if ($status === Constants::STATUS_QUEUED) {
            return;
        }

        // Don't re-trigger selection if already awaiting user approval.
        // The cancel was counted above; no further action needed.
        if ($status === Constants::STATUS_AWAITING_APPROVAL) {
            return;
        }

        // Re-check selection trigger with updated counts (same logic as handleRp2pCandidate)
        $tracking = $this->p2pRepository->getTrackingCounts($request['hash']);
        if (!$tracking) {
            return;
        }

        $sentCount = (int) $tracking['contacts_sent_count'];
        $relayedCount = (int) ($tracking['contacts_relayed_count'] ?? 0);
        $insertedRespondedCount = (int) $tracking['contacts_responded_count'];
        $relayedRespondedCount = (int) ($tracking['contacts_relayed_responded_count'] ?? 0);
        $phase1Sent = (bool) ($tracking['phase1_sent'] ?? 0);

        // Phase 2: all contacts responded
        if ($relayedCount > 0 && ($insertedRespondedCount + $relayedRespondedCount) >= ($sentCount + $relayedCount)) {
            $this->selectAndForwardBestRp2p($request['hash']);
            return;
        }

        // Phase 1: all inserted contacts responded
        if ($relayedCount > 0 && $insertedRespondedCount >= $sentCount && !$phase1Sent) {
            $this->sendBestCandidateToRelayedContacts($request['hash']);
            return;
        }

        // No relayed contacts: all inserted responded
        if ($relayedCount === 0 && $insertedRespondedCount >= $sentCount) {
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

        // Mark Phase 1 as sent BEFORE sending to prevent re-triggering
        // (additional candidates arriving during delivery won't fire Phase 1 again)
        $this->p2pRepository->markPhase1Sent($hash);

        $bestCandidate = $this->rp2pCandidateRepository->getBestCandidate($hash);
        if (!$bestCandidate) {
            // All inserted contacts cancelled — notify relayed contacts
            // so they can count our response and break mutual deadlocks.
            $this->sendCancelToRelayedContacts($hash);
            return;
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
     * Phase 1 cancel: notify relayed contacts when all inserted contacts cancelled
     *
     * When all inserted contacts respond with cancels (zero RP2P candidates),
     * relayed contacts need to be notified so they can count our response and
     * potentially break mutual deadlocks. Without this, hub nodes with mutual
     * relayed references (e.g. A4↔A8) wait for each other indefinitely until
     * hop-wait expiration.
     *
     * This mirrors the Phase 2 pattern in selectAndForwardBestRp2p() where
     * zero candidates triggers cancel + upstream propagation.
     *
     * @param string $hash The P2P hash
     * @return void
     */
    private function sendCancelToRelayedContacts(string $hash): void
    {
        if ($this->p2pRelayedContactRepository === null) {
            return;
        }

        $relayedContacts = $this->p2pRelayedContactRepository->getRelayedContactsByHash($hash);
        foreach ($relayedContacts as $contact) {
            $cancelPayload = $this->rp2pPayload->buildCancelled($hash, $contact['contact_address']);
            $this->sendRp2pMessage($contact['contact_address'], $cancelPayload, $hash);
        }

        Logger::getInstance()->info("Phase 1: sent cancel to relayed contacts (no candidates)", [
            'hash' => $hash,
            'relayed_contacts' => count($relayedContacts),
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
        $insertedRespondedCount = (int) $tracking['contacts_responded_count'];
        $relayedRespondedCount = (int) ($tracking['contacts_relayed_responded_count'] ?? 0);
        $phase1Sent = (bool) ($tracking['phase1_sent'] ?? 0);

        // Phase 2: all propagated contacts (inserted + relayed) responded
        if ($relayedCount > 0 && ($insertedRespondedCount + $relayedRespondedCount) >= ($sentCount + $relayedCount)) {
            $this->selectAndForwardBestRp2p($hash);
            return;
        }

        // Phase 1: all inserted contacts responded, relayed contacts still pending (once only)
        if ($relayedCount > 0 && $insertedRespondedCount >= $sentCount && !$phase1Sent) {
            $this->sendBestCandidateToRelayedContacts($hash);
            return;
        }

        // No relayed contacts: all inserted responded
        if ($relayedCount === 0 && $insertedRespondedCount >= $sentCount) {
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

        $candidates = $this->rp2pCandidateRepository->getCandidatesByHash($hash);
        if (empty($candidates)) {
            // All contacts responded but zero viable candidates — cancel and propagate upstream.
            // Guard: only send cancel notification if P2P is not already cancelled
            // to prevent feedback loop from redundant cancel messages.
            $currentP2p = $this->p2pRepository->getByHash($hash);
            $currentStatus = $currentP2p ? ($currentP2p['status'] ?? null) : null;
            if ($currentStatus !== Constants::STATUS_CANCELLED && $currentStatus !== 'expired') {
                Logger::getInstance()->warning("No rp2p candidates found for best-fee selection, cancelling P2P", ['hash' => $hash]);
                $this->p2pRepository->updateStatus($hash, Constants::STATUS_CANCELLED);
                if ($this->p2pService !== null) {
                    $this->p2pService->sendCancelNotificationForHash($hash);
                }
            }
            return;
        }

        $totalCandidates = count($candidates);
        Logger::getInstance()->info("Best-fee route selection starting", [
            'hash' => $hash,
            'total_candidates' => $totalCandidates,
        ]);

        $p2p = $this->p2pRepository->getByHash($hash);

        // Manual approval + best-fee mode: present all candidates to the user
        // instead of auto-selecting the cheapest one.
        if ($p2p && !empty($p2p['destination_address'])
            && !$this->currentUser->getAutoAcceptTransaction()
            && (int) ($p2p['fast'] ?? 1) === 0
        ) {
            // Set rp2p_amount to the best (lowest) candidate's amount for summary display
            $bestAmount = (int) $candidates[0]['amount'];
            $this->p2pRepository->setRp2pAmount($hash, $bestAmount);
            $this->p2pRepository->updateStatus($hash, Constants::STATUS_AWAITING_APPROVAL);

            Logger::getInstance()->info("Best-fee candidates awaiting user selection", [
                'hash' => $hash,
                'total_candidates' => $totalCandidates,
                'best_amount' => $bestAmount,
            ]);

            // Do NOT delete candidates or insert into rp2p — user will choose
            return;
        }

        // Before forwarding upstream: ensure Phase 1 sent to relayed contacts.
        // Race condition: if a relayed contact's RP2P arrived before all inserted
        // contacts responded, Phase 2 triggers directly (skipping Phase 1).
        // Without this, the relayed contact never receives our best downstream
        // candidate and must rely on expiration fallback with potentially
        // sub-optimal candidates.
        $tracking = $this->p2pRepository->getTrackingCounts($hash);
        if ($tracking) {
            $relayedCount = (int) ($tracking['contacts_relayed_count'] ?? 0);
            $phase1Sent = (bool) ($tracking['phase1_sent'] ?? 0);
            if ($relayedCount > 0 && !$phase1Sent) {
                $this->sendBestCandidateToRelayedContacts($hash);
            }
        }

        // Try candidates from cheapest to most expensive (fallback on fee/affordability rejection)
        $success = false;
        foreach ($candidates as $index => $candidate) {
            // Convert candidate back to rp2p request format for handleRp2pRequest
            $request = [
                'hash' => $candidate['hash'],
                'time' => $candidate['time'],
                'amount' => (int) $candidate['amount'],
                'currency' => $candidate['currency'],
                'senderPublicKey' => $candidate['sender_public_key'],
                'senderAddress' => $candidate['sender_address'],
                'signature' => $candidate['sender_signature'],
            ];

            // handleRp2pRequest adds the fee again, but the candidate amount
            // already includes our fee from handleRp2pCandidate, so subtract it
            if ($p2p) {
                $reqAmt = SplitAmount::from($request['amount']);
                $feeAmt = SplitAmount::from($p2p['my_fee_amount']);
                $request['amount'] = $reqAmt->subtract($feeAmt);
            }

            if ($this->handleRp2pRequest($request)) {
                Logger::getInstance()->info("Best-fee route selected", [
                    'hash' => $hash,
                    'candidate' => ($index + 1) . '/' . $totalCandidates,
                    'amount' => $candidate['amount'],
                    'fee_amount' => $candidate['fee_amount'],
                    'sender_address' => $candidate['sender_address'],
                ]);
                $success = true;
                break;
            }

            Logger::getInstance()->info("Candidate rejected, trying next", [
                'hash' => $hash,
                'candidate' => ($index + 1) . '/' . $totalCandidates,
                'sender_address' => $candidate['sender_address'],
            ]);
        }

        if (!$success) {
            // All candidates failed fee/affordability checks — cancel and propagate upstream
            $currentP2p = $this->p2pRepository->getByHash($hash);
            $currentStatus = $currentP2p ? ($currentP2p['status'] ?? null) : null;
            if ($currentStatus !== Constants::STATUS_CANCELLED && $currentStatus !== 'expired') {
                Logger::getInstance()->warning("All rp2p candidates failed validation, cancelling P2P", [
                    'hash' => $hash,
                    'candidates_tried' => $totalCandidates,
                ]);
                $this->p2pRepository->updateStatus($hash, Constants::STATUS_CANCELLED);
                if ($this->p2pService !== null) {
                    $this->p2pService->sendCancelNotificationForHash($hash);
                }
            }
        }

        // Cancel unselected routes to release their reserved capacity immediately
        if ($success && $this->routeCancellationService !== null) {
            $selectedCandidateId = (string) ($candidates[$index]['id'] ?? $index);
            $unselectedCandidates = array_filter($candidates, fn($c, $i) => $i !== $index, ARRAY_FILTER_USE_BOTH);
            if (!empty($unselectedCandidates)) {
                $this->routeCancellationService->cancelUnselectedRoutes($hash, $selectedCandidateId, array_values($unselectedCandidates));
            }
        }

        // Clean up all candidates for this hash
        $this->rp2pCandidateRepository->deleteCandidatesByHash($hash);
    }

    /**
     * Calculate the fee this node charges for a P2P relay
     *
     * Looks up the per-contact fee rate for the P2P sender (falling back to
     * the user's default fee) and calculates the fee on the given accumulated
     * amount. Used during RP2P backtracking for multiplicative/compounding fees —
     * each relay charges its fee on the total including downstream fees, not the
     * original base amount.
     *
     * @param array $p2p The P2P record from database
     * @param int $amount The accumulated RP2P amount (downstream total) to calculate fee on
     * @return int The calculated fee in minor currency units (rounded)
     */
    private function calculateFeeForP2p(array $p2p, SplitAmount $amount): SplitAmount {
        $currencyUtility = $this->utilityContainer->getCurrencyUtility();
        $defaultFee = $this->currentUser->getDefaultFee();
        $minimumFee = $this->currentUser->getMinimumFee();

        // Look up per-contact fee for the P2P sender
        $feePercent = $defaultFee;
        $senderAddress = $p2p['sender_address'] ?? null;
        if ($senderAddress) {
            $transportIndex = $this->transportUtility->determineTransportType($senderAddress);
            $senderContact = ($transportIndex !== null)
                ? $this->contactRepository->lookupByAddress($transportIndex, $senderAddress)
                : null;

            if ($senderContact && isset($senderContact['pubkey_hash'])) {
                $currency = $p2p['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                $contactFee = $this->contactCurrencyRepository?->getFeePercent($senderContact['pubkey_hash'], $currency);
                if ($contactFee !== null) {
                    $feePercent = $contactFee / Constants::FEE_CONVERSION_FACTOR;
                }
            }
        }

        return $currencyUtility->calculateFee($amount, $feePercent, $minimumFee);
    }

    /**
     * Return fee percent of request and output fee information into the log
     *
     * @param array $p2p The p2p request data from the database
     * @param array $request The transaction request data
     * @return float Fee percent of request
    */
    public function feeInformation(array $p2p, array $request): float {
        $p2pAmount = SplitAmount::from($p2p['amount']);
        if ($p2pAmount->isZero()) {
            return 0.0;
        }
        $requestAmount = SplitAmount::from($request['amount']);
        $feeAmount = $requestAmount->subtract($p2pAmount);
        $feePercent = round(($feeAmount->toMajorUnits() / $p2pAmount->toMajorUnits()) * Constants::FEE_CONVERSION_FACTOR, Constants::FEE_PERCENT_DECIMAL_PRECISION);
        output(outputFeeInformation($feePercent,$request,$this->currentUser->getMaxFee()), 'SILENT');
        return $feePercent;
    }
}