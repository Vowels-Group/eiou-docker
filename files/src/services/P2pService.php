<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\InputValidator;
use Eiou\Utils\Logger;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Schemas\Payloads\P2pPayload;
use Eiou\Schemas\Payloads\Rp2pPayload;
use Eiou\Schemas\Payloads\UtilPayload;
use PDOException;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * P2P Service
 *
 * Handles all business logic for peer-to-peer payment routing.
 * Integrates with MessageDeliveryService for reliable message delivery
 * with tracking, retry logic, and dead letter queue support.
 */
class P2pService implements P2pServiceInterface {
    /**
     * @var ContactServiceInterface Contact service instance
     */
    private ContactServiceInterface $contactService;

    /**
     * @var BalanceRepository Balance repository instance
     */
    private BalanceRepository $balanceRepository;

    /**
     * @var P2pRepository P2P repository instance
     */
    private P2pRepository $p2pRepository;

    /**
     * @var TransactionRepository Transaction repository instance
     */
    private TransactionRepository $transactionRepository;

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
     * @var CurrencyUtilityService Currency utility service
     */
    private CurrencyUtilityService $currencyUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var P2pPayload payload builder for p2p
     */
    private P2pPayload $p2pPayload;

    /**
     * @var Rp2pPayload payload builder for Rp2p
     */
    private Rp2pPayload $rp2pPayload;

    /**
     * @var UtilPayload payload builder for utility
     */
    private UtilPayload $utilPayload;

    /**
     * @var MessageDeliveryService|null Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

    /**
     * @var Logger Logger instance
     */
    private Logger $secureLogger;

    /**
     * @var P2pSenderRepository|null Repository for tracking P2P senders (multi-path support)
     */
    private ?P2pSenderRepository $p2pSenderRepository = null;

    /**
     * @var P2pRelayedContactRepository|null Repository for tracking already_relayed contacts (two-phase selection)
     */
    private ?P2pRelayedContactRepository $p2pRelayedContactRepository = null;

    /**
     * @var Rp2pRepository|null RP2P repository for forwarding existing RP2P to late P2P senders
     */
    private ?Rp2pRepository $rp2pRepository = null;

    /**
     * @var Rp2pService|null RP2P service for re-checking best-fee selection after broadcast completes
     */
    private ?Rp2pService $rp2pService = null;

    /**
     * Constructor
     *
     * @param ContactServiceInterface $contactService Contact service
     * @param BalanceRepository $balanceRepository Balance repository
     * @param P2pRepository $p2pRepository P2P repository
     * @param TransactionRepository $transactionRepository Transaction repository
     * @param UtilityServiceContainer $utilityContainer Utility Container
     * @param UserContext $currentUser Current user data
     * @param MessageDeliveryService|null $messageDeliveryService Optional delivery service for tracking
     * @param P2pSenderRepository|null $p2pSenderRepository Optional repository for multi-path sender tracking
     */
    public function __construct(
        ContactServiceInterface $contactService,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        TransactionRepository $transactionRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null,
        ?P2pSenderRepository $p2pSenderRepository = null
    ) {
        $this->contactService = $contactService;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->transactionRepository = $transactionRepository;
        $this->utilityContainer = $utilityContainer;
        $this->validationUtility = $this->utilityContainer->getValidationUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->currencyUtility = $this->utilityContainer->getCurrencyUtility();
        $this->timeUtility = $this->utilityContainer->getTimeUtility();
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;
        $this->p2pSenderRepository = $p2pSenderRepository;
        $this->secureLogger = Logger::getInstance();

        $this->p2pPayload = new P2pPayload($this->currentUser, $this->utilityContainer);
        $this->rp2pPayload = new Rp2pPayload($this->currentUser, $this->utilityContainer);
        $this->utilPayload = new UtilPayload($this->currentUser, $this->utilityContainer);
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
     * Set the Rp2pRepository for forwarding existing RP2P to late P2P senders
     *
     * @param Rp2pRepository $repository
     * @return void
     */
    public function setRp2pRepository(Rp2pRepository $repository): void {
        $this->rp2pRepository = $repository;
    }

    /**
     * Set the Rp2pService for re-checking best-fee selection after broadcast
     *
     * @param Rp2pService $service
     * @return void
     */
    public function setRp2pService(Rp2pService $service): void {
        $this->rp2pService = $service;
    }

    /**
     * Send a P2P message with optional delivery tracking (non-blocking)
     *
     * Uses MessageDeliveryService.sendMessage() when available for reliable delivery
     * with retry logic and dead letter queue support. Uses async (non-blocking)
     * delivery to prevent P2P broadcast loops from getting stuck waiting on
     * retries. Failed sends are queued for background retry.
     *
     * @param string $messageType Type of P2P message ('p2p' or 'rp2p')
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string|null $messageId Optional unique message ID for tracking (uses hash if available)
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    private function sendP2pMessage(string $messageType, string $address, array $payload, ?string $messageId = null): array {
        // Use unified sendMessage() from MessageDeliveryService if available
        if ($this->messageDeliveryService !== null) {
            // Use async=true for non-blocking delivery (allows P2P broadcast loops to continue)
            return $this->messageDeliveryService->sendMessage(
                $messageType,
                $address,
                $payload,
                $messageId,
                true // async
            );
        }

        // Fall back to direct transport when MessageDeliveryService not available
        if ($messageId === null) {
            $messageId = $payload['hash'] ?? hash('sha256', json_encode($payload) . $this->timeUtility->getCurrentMicrotime());
        }

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
     * Check if P2P request level is valid
     *
     * @param array $request The P2P request data
     * @return bool True if request level is valid, false otherwise
     */
    public function checkRequestLevel(array $request): bool {
        // Validate input
        if (!isset($request['requestLevel']) || !isset($request['maxRequestLevel'])) {
            Logger::getInstance()->warning("Missing requestLevel or maxRequestLevel in request", [
                'method' => 'checkRequestLevel',
                'request_keys' => array_keys($request)
            ]);
            echo $this->utilPayload->buildInvalidRequestLevel($request);
            return false;
        }

        // Check validity of p2p request
        if (!$this->validationUtility->validateRequestLevel($request)) {
            echo $this->utilPayload->buildInvalidRequestLevel($request);
            return false;
        }
        return true;
    }

    /**
     * Check if sender has sufficient available funds for P2P request
     *
     * @param array $request The P2P request data
     * @return bool True if funds are available, false otherwise
     * @throws PDOException When database query fails
     */
    public function checkAvailableFunds(array $request): bool {
        try {
            // Validate required fields
            if (!isset($request['senderAddress'], $request['senderPublicKey'])) {
                Logger::getInstance()->warning("Missing required fields in P2P request for funds check", [
                    'method' => 'checkAvailableFunds',
                    'request_keys' => array_keys($request)
                ]);
                return false;
            }

            // Check if p2p's destination is not to user (i.e. you are an intermediary and not the end-recipient)
            if (!$this->matchYourselfP2P($request, $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']))) {
                // Check if sender has enough 'credit' to facilitate eIOU
                $requestedAmount = $this->calculateRequestedAmount($request);
                $availableFunds = $this->validationUtility->calculateAvailableFunds($request);

                $fundsOnHold = $this->p2pRepository->getCreditInP2p($request['senderPublicKey']);
                $creditLimit = $this->contactService->getCreditLimit($request['senderPublicKey']);

                if (($availableFunds + $creditLimit) < ($requestedAmount + $fundsOnHold)) {
                    // Note: Do NOT echo here - the caller (checkP2pPossible) handles the response
                    // Echoing here would cause duplicate JSON output breaking response parsing
                    return false;
                }
            }
            // If you are the end-recipient you do not need to pay
            return true;
        } catch (PDOException $e) {
            // Use Logger's exception logging
            Logger::getInstance()->logException($e, [
                'method' => 'checkAvailableFunds',
                'context' => 'p2p_funds_validation'
            ]);
            throw $e;
        }
    }

    /**
     * Calculate total amount required for p2p (amount + fee)
     *
     * @param array $request The P2P request data
     * @return int Total amount needed for p2p transaction
     */
    public function calculateRequestedAmount(array $request): int {
         // Calculate total amount needed for p2p through user
        $address = $request['senderAddress'];
        $transportIndex = $this->transportUtility->determineTransportType($address);
        $senderContact = null;
        if ($transportIndex !== null) {
            $senderContact = $this->contactService->lookupByAddress($transportIndex, $address);
        }
        $fee = ($senderContact ? $senderContact['fee_percent'] : $this->currentUser->getDefaultFee());
        return $request['amount'] + $this->currencyUtility->calculateFee($request['amount'], $fee, $this->currentUser->getMinimumFee());
    }

    /**
     * Check P2P is possible
     *
     * @param array $request Request data
     * @param bool $echo Whether to echo rejection response (default: true)
     * @return bool True if P2P possible, false otherwise
     */
    public function checkP2pPossible(array $request, bool $echo = true): bool {
        $senderAddress = $request['senderAddress'];
        $pubkey = $request['senderPublicKey'];
        // Check if User is not blocked
        if(!$this->contactService->isNotBlocked($pubkey)){
            if($echo){
                echo $this->p2pPayload->buildRejection($request, 'contact_blocked');
            }
            return false;
        }
        // Check if P2P message has not reached max intermediary hop amount
        elseif(!$this->checkRequestLevel($request)){
            return false;
        }
        // Check if Contact has enough funds for P2P without fees
        elseif(!$this->checkAvailableFunds($request)){
            if($echo){
                echo $this->p2pPayload->buildRejection($request, 'insufficient_funds');
            }
            return false;
        }

        // Check if P2P already exists for hash in database
        try{
            if($this->p2pRepository->p2pExists($request['hash'])){
                // Record this additional sender so RP2P is sent back to all paths
                $this->p2pSenderRepository?->insertSender(
                    $request['hash'], $request['senderAddress'], $request['senderPublicKey']
                );

                // If this node already has an RP2P for this hash (either as destination or
                // relay that already completed selection), send it to the new sender immediately.
                // Without this, late P2P senders never receive an RP2P and their upstream
                // relay nodes must wait for hop-wait expiration — missing optimal routes.
                $existingP2p = $this->p2pRepository->getByHash($request['hash']);
                if ($existingP2p
                    && $existingP2p['status'] === 'found'
                    && isset($existingP2p['destination_address'])
                ) {
                    $myAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);
                    if ($this->matchYourselfP2P($request, $myAddress)) {
                        // We are the destination - send rp2p to this new sender
                        $rP2pPayload = $this->rp2pPayload->build($request);
                        $contactHash = substr(hash('sha256', $request['senderAddress']), 0, 8);
                        $messageId = 'response-' . $request['hash'] . '-' . $contactHash;
                        $this->sendP2pMessage('rp2p', $request['senderAddress'], $rP2pPayload, $messageId);
                    }
                } elseif ($this->rp2pRepository !== null) {
                    // Relay node: if we already selected and forwarded an RP2P for this hash,
                    // send it to the new sender so their upstream cascade gets a response
                    $existingRp2p = $this->rp2pRepository->getByHash($request['hash']);
                    if ($existingRp2p) {
                        $rp2pPayload = $this->rp2pPayload->buildFromDatabase($existingRp2p);
                        $contactHash = substr(hash('sha256', $request['senderAddress']), 0, 8);
                        $messageId = 'relay-rp2p-' . $request['hash'] . '-' . $contactHash;
                        $this->sendP2pMessage('rp2p', $request['senderAddress'], $rp2pPayload, $messageId);
                    }
                }

                // P2P already exists via another route - inform sender with already_relayed
                // This allows the sender to count this as a responded contact in best-fee mode
                if($echo){
                    echo $this->p2pPayload->buildAlreadyRelayed($request);
                }
                return false;
            }

            // All validations passed - process P2P and echo acceptance
            // IMPORTANT: Storage MUST succeed before acceptance is sent
            // to prevent false positives from acceptance-before-storage bug
            // (follows same pattern as TransactionService.checkTransactionPossible)
            try {
                $this->handleP2pRequest($request);
                if($echo){
                    // Return 'inserted' status AFTER the P2P has been stored in the database
                    echo $this->p2pPayload->buildInserted($request);
                }
                // Return false to prevent caller from calling handleP2pRequest again
                return false;
            } catch (Exception $e) {
                Logger::getInstance()->logException($e, [
                    'method' => 'checkP2pPossible',
                    'context' => 'p2p_processing_failed'
                ]);
                if($echo){
                    echo $this->p2pPayload->buildRejection($request, 'processing_error');
                }
                return false;
            }
        } catch (PDOException $e) {
            // Handle database error
            Logger::getInstance()->error("Error retrieving existence of P2P by hash", ['error' => $e->getMessage()]);
            if($echo){
                echo json_encode([
                    "status" => "rejected",
                    "message" => "Could not retrieve existence of P2P with receiver"
                ]);
            }
            return false;
        }
    }

    /**
     * Handle incoming P2P request
     *
     * Uses MessageDeliveryService for reliable rp2p response delivery when
     * the P2P destination matches the user.
     *
     * @param array $request The P2P request data
     * @return void
     * @throws InvalidArgumentException When required fields are missing in P2P request
     * @throws PDOException When database operation fails
     * @throws Exception When general processing error occurs
     */
    public function handleP2pRequest(array $request): void {
        try {
            // Validate required fields
            if (!isset($request['senderAddress'], $request['hash'], $request['amount'])) {
                Logger::getInstance()->warning("Missing required fields in P2P request");
                throw new InvalidArgumentException("Invalid P2P request structure");
            }

            // Handler for p2p requests
            $myAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

            // Check if p2p's destination is to user
            if ($this->matchYourselfP2P($request, $myAddress)) {
                $request['status'] = 'found';
                $this->p2pRepository->insertP2pRequest($request, $myAddress);

                // Build and send corresponding rp2p request payload to sender of p2p with delivery tracking
                // Message ID format: response-{hash} (message_type 'rp2p' provides context)
                $rP2pPayload = $this->rp2pPayload->build($request);
                $messageId = 'response-' . $request['hash'];
                $sendResult = $this->sendP2pMessage('rp2p', $request['senderAddress'], $rP2pPayload, $messageId);
                $response = $sendResult['response'];

                // Update delivery stage after local insert (using MessageDeliveryService directly)
                if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                    $this->messageDeliveryService->updateStageAfterLocalInsert('rp2p', $messageId, false);
                }

                output(outputRp2pTransactionResponse($response), 'SILENT');
            } else {
                // Calculate fees
                $requestedAmount = $this->calculateRequestedAmount($request);
                $request['feeAmount'] = $requestedAmount - $request['amount'];
                $request['maxRequestLevel'] = $this->reAdjustP2pLevel($request); // Change (remaining) RequestLevel if need be based on user config

                // In best-fee mode, scale relay expiration proportionally to remaining hops.
                // Deeper nodes (high requestLevel, few remaining hops) expire sooner,
                // guaranteeing downstream nodes always expire before upstream ones.
                // This cascades best-fee selection from leaves back to originator.
                $hopWait = (int) ($request['hopWait'] ?? 0);
                if ($hopWait > 0 && !($request['fast'] ?? true)) {
                    $remainingHops = max(1, (int)($request['maxRequestLevel'] ?? 1) - (int)($request['requestLevel'] ?? 0));
                    $scaledWait = $hopWait * $remainingHops;
                    $now = $this->timeUtility->getCurrentMicrotime();
                    $scaledExpiration = $now + $this->timeUtility->convertMicrotimeToInt((float) $scaledWait);

                    // Cap relay expiration to upstream's expiration minus hopWait buffer.
                    // This ensures each relay expires before its upstream node, preserving
                    // the cascade ordering required for best-fee selection to propagate
                    // from leaves back to the originator.
                    $upstreamExpiration = (int) ($request['expiration'] ?? 0);
                    if ($upstreamExpiration > 0 && $scaledExpiration >= $upstreamExpiration) {
                        $hopWaitMicrotime = $this->timeUtility->convertMicrotimeToInt((float) $hopWait);
                        $cappedExpiration = $upstreamExpiration - $hopWaitMicrotime;
                        $minExpiration = $now + $this->timeUtility->convertMicrotimeToInt(
                            (float) Constants::P2P_HOP_PROCESSING_BUFFER_SECONDS
                        );
                        $request['expiration'] = max($minExpiration, $cappedExpiration);
                    } else {
                        $request['expiration'] = $scaledExpiration;
                    }
                }

                $this->p2pRepository->insertP2pRequest($request, NULL);
                // Record the first sender for multi-path RP2P delivery
                $this->p2pSenderRepository?->insertSender(
                    $request['hash'], $request['senderAddress'], $request['senderPublicKey']
                );
                $this->p2pRepository->updateStatus($request['hash'], Constants::STATUS_QUEUED);
            }
        } catch (PDOException $e) {
            Logger::getInstance()->logException($e, [], 'ERROR');
            throw $e;
        } catch (Exception $e) {
            Logger::getInstance()->error("Error in handleP2pRequest", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if the P2P's end-recipient is a contact of user
     *
     * @param array $request Request data
     * @return array|null Contact data of corresponding user, null otherwise.
     */
    public function matchContact($request): ?array {
        // Check if contact matches transactions end-recipient
        $contacts = $this->contactService->getAllContacts();
        // Check if end recipient of request in contacts
        $senderAddress = $request['sender_address'];
        $transportIndex = $this->transportUtility->determineTransportType($senderAddress);

        // Get all address types dynamically from database schema
        $addressTypes = $this->transportUtility->getAllAddressTypes();
        // Move primary transport to front for performance (most likely match)
        if ($transportIndex && in_array($transportIndex, $addressTypes)) {
            $addressTypes = array_merge([$transportIndex], array_diff($addressTypes, [$transportIndex]));
        }

        foreach ($contacts as $contact) {
            // Check all address types for this contact
            foreach ($addressTypes as $addrType) {
                if (!empty($contact[$addrType])) {
                    $contactHash = hash(Constants::HASH_ALGORITHM, $contact[$addrType] . $request['salt'] . $request['time']);
                    if ($contactHash === $request['hash']) {
                        output(outputContactMatched($contactHash), 'SILENT');
                        return $contact;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Check if the P2P's end-recipient is user
     *
     * @param array $request Request data
     * @param string $address Address
     * @return bool True if user corresponds, false otherwise
     */
    public function matchYourselfP2P(array $request, string $address): bool {
        // Check if p2p end recipient is user
        // First check the provided address (most likely match)
        if (hash(Constants::HASH_ALGORITHM, $address . $request['salt'] . $request['time']) === $request['hash']) {
            return true;
        }

        // If primary address didn't match, check all user addresses
        // This handles cases where message was wrapped/forwarded over different networks
        // getUserLocaters() returns addresses mapped by type (e.g., ['http' => '...', 'tor' => '...'])
        $allAddresses = $this->currentUser->getUserLocaters();

        foreach ($allAddresses as $userAddress) {
            // Skip if this is the same address we already checked
            if ($userAddress === $address) {
                continue;
            }
            if (hash(Constants::HASH_ALGORITHM, $userAddress . $request['salt'] . $request['time']) === $request['hash']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare P2P request data from user input
     *
     * @param array $request The request array from user input
     * @return array Prepared P2P request data
     * @throws InvalidArgumentException When receiver address is not set, amount is missing, or amount is invalid
     * @throws RuntimeException When secure random data generation fails
     */
    public function prepareP2pRequestData(array $request): array {
        // Build initial p2p request payload
        output(outputPrepareP2pData($request), 'SILENT');

        // Check if the address of the recipient was supplied
        if (!isset($request[2])) {
            output(outputReceiverAddressNotSet($request), 'SILENT');
            throw new \InvalidArgumentException("Receiver address is not set");
        }

        // Validate amount using InputValidator
        if (!isset($request[3])) {
            throw new InvalidArgumentException("Amount is required for P2P request");
        }

        $validation = InputValidator::validateAmount($request[3], Constants::TRANSACTION_DEFAULT_CURRENCY);
        if (!$validation['valid']) {
            throw new InvalidArgumentException("Invalid amount for P2P request: " . $validation['error']);
        }
        $validatedAmount = $validation['value'];

        // Initial data preparation
        $data['txType'] = 'p2p';
        $data['receiverAddress'] = $request[2];

        $data['time'] = $this->timeUtility->getCurrentMicrotime();
        $data['amount'] = round($validatedAmount * Constants::TRANSACTION_USD_CONVERSION_FACTOR); // Convert to cents
        $data['currency'] = Constants::TRANSACTION_DEFAULT_CURRENCY; // Default to USD

        // Additional data preparation - Use cryptographically secure random
        try {
            $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to generate random salt", ['error' => $e->getMessage()]);
            throw new RuntimeException("Failed to generate secure random data");
        }

        $data['hash'] = hash(Constants::HASH_ALGORITHM, $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
        output(outputGeneratedP2pHash($data['hash']), 'SILENT');
        output(outputP2pComponents($data), 'SILENT');

        // Request level randomization for network traffic analysis prevention
        // Uses overlapping random distributions: abs(rand(300,700) - rand(200,500)) + rand(1,10)
        // This produces unpredictable but bounded values, preventing attackers from
        // correlating request patterns across the P2P network. See Constants.php for details.
        $data['minRequestLevel'] = abs(
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANGE_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANGE_HIGH) -
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH)
        ) + rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH);
        $data['maxRequestLevel'] = $data['minRequestLevel'] + $this->transportUtility->jitter($this->currentUser->getMaxP2pLevel());

        // Thread fast flag from user request (default: true for backward compatibility)
        $data['fast'] = (int)($request['fast'] ?? true);

        return $data;
    }

    /**
     * Prepare P2P request from failed transaction data
     *
     * @param array $message Transaction message
     * @return array Prepared P2P request data
     * @throws RuntimeException When secure random data generation fails
     */
    public function prepareP2pRequestFromFailedTransactionData(array $message): array {
        // Build initial p2p payload from failed direct Transaction
        $data['txType'] = 'p2p';
        $data['receiverAddress'] = $message['receiver_address'];

        $data['time'] = $this->timeUtility->getCurrentMicrotime();
        $data['amount'] = $message['amount'];
        $data['currency'] = $message['currency'];

        // Additional data preparation - Use cryptographically secure random
        try {
            $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to generate random salt", ['error' => $e->getMessage()]);
            throw new RuntimeException("Failed to generate secure random data");
        }

        $data['hash'] = hash(Constants::HASH_ALGORITHM, $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
        output(outputGeneratedP2pHash($data['hash']), 'SILENT');
        output(outputP2pComponents($data), 'SILENT');

        // Request level randomization for network traffic analysis prevention
        // See prepareP2pRequest() and Constants.php for algorithm details.
        $data['minRequestLevel'] = abs(
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANGE_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANGE_HIGH) -
            rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH)
        ) + rand(Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH);
        $data['maxRequestLevel'] = $data['minRequestLevel'] + $this->transportUtility->jitter($this->currentUser->getMaxP2pLevel());

        return $data;
    }

    /**
     * Process queued P2P messages
     *
     * Uses MessageDeliveryService for reliable P2P message delivery with
     * tracking, retry logic, and dead letter queue support.
     *
     * @return int Number of processed messages
     */
    public function processQueuedP2pMessages(): int {
        // Select queued messages from the p2p table (with status queued)
        $queuedMessages = $this->p2pRepository->getQueuedP2pMessages();
        if($queuedMessages !== []){
            $contacts = $this->contactService->getAllAcceptedAddresses(); // Retrieve all accepted contact addresses to send p2p request
            $contactsCount = count($contacts); // Count amount of contacts to send p2p request
        }
        // Process each queued message
        foreach ($queuedMessages as $message) {
            // Get transport type for forwarding/sending
            $transportIndex = $this->transportUtility->determineTransportType($message['sender_address']);
            $p2pPayload = $this->p2pPayload->buildFromDatabase($message); // Build p2p request payload
            $p2pHash = $message['hash'];

            // Check if user is NOT the original sender of the p2p and has a direct contact link to end-recipient
            // If this is the case then send p2p directly
            // Message ID format: direct-{hash}-{contactHash} (message_type 'p2p' provides context)
            if(!isset($message['destination_address']) && $matchedContact = $this->matchContact($message)){
                // Send directly to matched contact with delivery tracking
                $contactHash = substr(hash('sha256', $matchedContact[$transportIndex]), 0, 8);
                $messageId = 'direct-' . $p2pHash . '-' . $contactHash;
                $sendResult = $this->sendP2pMessage('p2p', $matchedContact[$transportIndex], $p2pPayload, $messageId);
                $response = $sendResult['response'];

                if ($sendResult['success'] && $this->messageDeliveryService !== null) {
                    // Mark as forwarded stage since we're routing to the destination
                    $this->messageDeliveryService->updateStageToForwarded('p2p', $messageId, $matchedContact[$transportIndex]);
                }

                // Track sent count for best-fee mode: the matched contact will send
                // an RP2P response, so we expect exactly 1 response.
                // Both 'inserted' (new P2P) and 'already_relayed' (P2P exists via
                // another path) contacts track this sender and will forward RP2P back.
                if (isset($response['status']) && ($response['status'] === 'inserted' || $response['status'] === 'already_relayed')) {
                    $this->p2pRepository->updateContactsSentCount($p2pHash, 1);
                }

                output(outputP2pSendResult($response),'SILENT');
            } else{
                $contactsToSend = $contactsCount; // Reset sendable contact count
                $sentMessages = 0;
                $acceptedContacts = 0; // Contacts that accepted P2P as new ('inserted')
                $relayedContacts = 0; // Contacts that returned 'already_relayed' (two-phase selection)
                $successfulSends = [];

                // Set contacts_sent_count ceiling BEFORE broadcasting to prevent race condition.
                // RP2P responses can arrive via HTTP handler while we're still sending to contacts.
                // Without this, contacts_sent_count defaults to 0 and the first RP2P triggers
                // immediate best-fee selection (responded >= 0), ignoring later/better paths.
                // The ceiling is updated to the actual count after the loop completes.
                $this->p2pRepository->updateContactsSentCount($p2pHash, $contactsCount);

                // Send p2p request to all accepted contacts
                foreach ($contacts as $contact) {
                    $contactAddress = $contact[$transportIndex]; // Get similar contact address to message
                    // Do not send message if contact has not similar transport mode (HTTP goes over HTTP, TOR over TOR etc.)
                    if(!$contactAddress){
                        $contactsToSend -= 1;
                        continue;
                    }
                    // Do not send message to original sender
                    if($message['sender_address'] === $contactAddress){
                        $contactsToSend -= 1;
                        continue;
                    }
                    // Do not send p2p to contact (end-recipient), if direct transaction failed due to insufficient funds
                    if(isset($message['destination_address']) && $message['destination_address'] === $contactAddress){
                        $contactsToSend -= 1;
                        continue;
                    }

                    // Send with delivery tracking - use unique ID per contact to track each send
                    // Message ID format: broadcast-{p2pHash}-{contactHash} (message_type 'p2p' provides context)
                    $contactHash = substr(hash('sha256', $contactAddress), 0, 8);
                    $messageId = 'broadcast-' . $p2pHash . '-' . $contactHash;
                    $sendResult = $this->sendP2pMessage('p2p', $contactAddress, $p2pPayload, $messageId);
                    $response = $sendResult['response'];

                    // If rejection from sole possible contact then cancel p2p immediately
                    if($response['status'] === Constants::STATUS_REJECTED && $contactsToSend === 1){
                        $this->p2pRepository->updateStatus($p2pHash, Constants::STATUS_CANCELLED);
                        $contactsToSend -= 1;
                        continue;
                    }

                    $sentMessages += 1;
                    // Track contacts for best-fee response counting.
                    // 'inserted': contact accepted P2P as new — will forward RP2P back after its cascade.
                    // 'already_relayed': contact received P2P from another path and runs its own cascade
                    //   on an independent timer. Two-phase selection: phase 1 selects from inserted contacts,
                    //   sends result to relayed contacts; phase 2 re-selects after relayed contacts respond.
                    if ($response['status'] === 'inserted') {
                        $acceptedContacts += 1;
                    } elseif ($response['status'] === 'already_relayed') {
                        $relayedContacts += 1;
                        $this->p2pRelayedContactRepository?->insertRelayedContact($p2pHash, $contactAddress);
                    }
                    if ($sendResult['success']) {
                        $successfulSends[] = ['messageId' => $messageId, 'nextHop' => $contactAddress];
                    }
                    output(outputP2pResponse($response),'SILENT');
                }

                // Update delivery stages to 'forwarded' for successful sends (using MessageDeliveryService directly)
                if ($this->messageDeliveryService !== null) {
                    foreach ($successfulSends as $sendInfo) {
                        $this->messageDeliveryService->updateStageToForwarded('p2p', $sendInfo['messageId'], $sendInfo['nextHop']);
                    }
                }

                if(isset($message['destination_address']) && $contactsToSend > 0){
                    output(outputSendP2PToAmountContacts($sentMessages), 'SILENT');
                    //Inform user (in debug) about expected response time
                    $httpExpectedResponseTime = $this->currentUser->getMaxP2pLevel(); // Use maxP2pLevel seconds for http
                    $torExpectedResponseTime = 5 * 2 * $this->currentUser->getMaxP2pLevel(); //5 seconds for a tor request, 2 times for a round trip, multiplied by maxP2pLevel
                    output(outputResponseTransactionTimes($httpExpectedResponseTime, $torExpectedResponseTime), 'SILENT');
                }

                // Cancel the message due to no viable contacts to send to (user is dead-end)
                if($sentMessages === 0){
                    output(outputNoViableRouteP2p($p2pHash,'SILENT'));
                    $this->p2pRepository->updateStatus($p2pHash, Constants::STATUS_CANCELLED);
                    continue;
                }

                // Update contacts_sent_count from ceiling to actual accepted count
                $this->p2pRepository->updateContactsSentCount($p2pHash, $acceptedContacts);
                if ($relayedContacts > 0) {
                    $this->p2pRepository->updateContactsRelayedCount($p2pHash, $relayedContacts);
                }

                // Re-check best-fee selection: RP2Ps may have arrived during broadcast
                // and been stored as candidates without triggering selection (ceiling was
                // higher than actual count). Now that the real count is set, check if
                // enough responses have arrived to trigger selection.
                $this->rp2pService?->checkBestFeeSelection($p2pHash);
            }

            $this->p2pRepository->updateStatus($p2pHash, Constants::STATUS_SENT);
        }
        return isset($queuedMessages) ? count($queuedMessages) : 0;
    }


    /**
     * Adjust remaining p2p chain length based on intermediary contact's config of maxP2pLevel 
     *
     * @param array $data Request data
     * @return int (adjusted) Level of Request
     */
    public function reAdjustP2pLevel($request): int {
        $maxP2p = $this->currentUser->getMaxP2pLevel();
        if($request['maxRequestLevel'] > $request['requestLevel'] + $maxP2p){
            return $request['requestLevel'] + $maxP2p;
        } else{
            return $request['maxRequestLevel'];
        }
    }

    /**
     * Send P2P request
     *
     * @param array $data Request data
     * @return void
     * @throws InvalidArgumentException When address is invalid and no matching contact exists
     */
    public function sendP2pRequest(array $data): void {
        // Check if a valid address format was supplied, if not look up the address in the case of a contact re-routing
        if ($this->transportUtility->isAddress($data[2])) {
            $address = $data[2];
        } else{
            // Check if contact exists by Name supplied, if not then cannot send the p2p request
            $contactAddresses = $this->contactService->lookupAddressesByName($data[2]);
            if($contactAddresses){
                $address = $this->transportUtility->fallbackTransportAddress($contactAddresses);
                if($address){
                    $data[2] = $address;
                }     
            } else{
                output(outputAdressOrContactIssue($data),'SILENT');
                throw new \InvalidArgumentException("Not an address nor existing contact with name: " . $data[2]);
            }
        }

        $p2pPayload = $this->p2pPayload->build($this->prepareP2pRequestData($data));
        output(outputInsertingP2pRequest($address), 'SILENT');
        // Privacy: Store description locally but don't include in P2P payload sent to relays
        $description = isset($data[5]) && !empty($data[5]) && strncmp($data[5], '--', 2) !== 0 ? $data[5] : null;
        $this->p2pRepository->insertP2pRequest($p2pPayload, $address, $description);
        $this->p2pRepository->updateStatus($p2pPayload['hash'], Constants::STATUS_QUEUED);
    }

    /**
     * Send P2P request from failed direct transaction
     *
     * @param array $message Transaction message
     * @return void
     */
    public function sendP2pRequestFromFailedDirectTransaction(array $message): void {
        // Create p2p version of failed direct transaction
        $p2pPayload = $this->p2pPayload->build($this->prepareP2pRequestFromFailedTransactionData($message));
        output(outputInsertingP2pRequest($message['receiver_address']), 'SILENT');
        $this->p2pRepository->insertP2pRequest($p2pPayload, $message['receiver_address']);
        $this->p2pRepository->updateStatus($p2pPayload['hash'], Constants::STATUS_QUEUED);
    }

    /**
     * Get P2P by hash
     *
     * @param string $hash P2P hash
     * @return array|null P2P data or null
     */
    public function getByHash(string $hash): ?array {
        return $this->p2pRepository->getByHash($hash);
    }

    /**
     * Update P2P status
     *
     * @param string $hash P2P hash
     * @param string $status New status
     * @param bool $completed Whether to set completed timestamp
     * @return bool Success status
     */
    public function updateStatus(string $hash, string $status, bool $completed = false): bool {
        return $this->p2pRepository->updateStatus($hash, $status, $completed);
    }

    /**
     * Update incoming transaction ID
     *
     * @param string $hash P2P hash
     * @param string $txid Transaction ID
     * @return bool Success status
     */
    public function updateIncomingTxid(string $hash, string $txid): bool {
        return $this->p2pRepository->updateIncomingTxid($hash, $txid);
    }

    /**
     * Update outgoing transaction ID
     *
     * @param string $hash P2P hash
     * @param string $txid Transaction ID
     * @return bool Success status
     */
    public function updateOutgoingTxid(string $hash, string $txid): bool {
        return $this->p2pRepository->updateOutgoingTxid($hash, $txid);
    }

    /**
     * Get credit currently on hold in P2P
     *
     * @param string $pubkey Sender pubkey
     * @return float Total amount on hold
     */
    public function getCreditInP2p(string $pubkey): float {
        return $this->p2pRepository->getCreditInP2p($pubkey);
    }

    /**
     * Get users total earnings
     *
     * @return string Earnings Balance
     */
    public function getUserTotalEarnings(): string {
        return $this->p2pRepository->getUserTotalEarnings();
    }
    

    /**
     * Get P2P statistics
     *
     * @return array Statistics array
     */
    public function getStatistics(): array {
        return $this->p2pRepository->getStatistics();
    }
}
