<?php
# Copyright 2025

require_once __DIR__ . '/MessageDeliveryService.php';

/**
 * RP2P Service
 *
 * Handles all business logic for R peer-to-peer payment routing.
 * Integrates with MessageDeliveryService for reliable message delivery
 * with tracking, retry logic, and dead letter queue support.
 *
 * @package Services
 */
class RP2pService {
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
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var Rp2pPayload payload builder for Rp2p
     */
    private Rp2pPayload $rp2pPayload;

    /**
     * @var MessageDeliveryService|null Message delivery service for reliable delivery
     */
    private ?MessageDeliveryService $messageDeliveryService = null;

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
     */
    public function __construct(
        ContactRepository $contactRepository,
        BalanceRepository $balanceRepository,
        P2pRepository $p2pRepository,
        RP2pRepository $rp2pRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser,
        ?MessageDeliveryService $messageDeliveryService = null
    ) {
        $this->contactRepository = $contactRepository;
        $this->balanceRepository = $balanceRepository;
        $this->p2pRepository = $p2pRepository;
        $this->rp2pRepository = $rp2pRepository;
        $this->utilityContainer = $utilityContainer;
        $this->validationUtility = $this->utilityContainer->getValidationUtility();
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
        $this->currentUser = $currentUser;
        $this->messageDeliveryService = $messageDeliveryService;

        require_once '/etc/eiou/src/schemas/payloads/Rp2pPayload.php';
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
        $messageId = 'rp2p-' . $hash . '-' . time();

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
                    // Send transaction through rp2p chain using TransactionService directly
                    Application::getInstance()->services->getTransactionService()->sendP2pEiou($request);
                } else {
                    output(outputFeeRejection(), 'SILENT');
                }
            } else{
                // Send rp2p messages onwards to sender of p2p with delivery tracking
                $rP2pPayload = $this->rp2pPayload->build($request); // Build rp2p payload
                $this->p2pRepository->updateStatus($request['hash'], 'found');  // Update the p2p request status to found

                // Use tracked delivery for reliable message sending
                $sendResult = $this->sendRp2pMessage($p2p['sender_address'], $rP2pPayload, $request['hash']);
                $response = $sendResult['response'];

                if ($sendResult['success']) {
                    // Mark delivery as forwarded since we successfully sent to next hop (using MessageDeliveryService directly)
                    if ($this->messageDeliveryService !== null) {
                        $this->messageDeliveryService->updateStageToForwarded('rp2p', $sendResult['messageId'], $p2p['sender_address']);
                    }
                    output(outputRp2pResponse($response), 'SILENT');
                } else {
                    // Log delivery failure details
                    $trackingResult = $sendResult['tracking'] ?? [];
                    $attempts = $trackingResult['attempts'] ?? 'unknown';
                    $lastError = $trackingResult['error'] ?? 'No response received';

                    if (class_exists('SecureLogger')) {
                        SecureLogger::warning("RP2P message delivery failed", [
                            'hash' => $request['hash'],
                            'sender_address' => $p2p['sender_address'],
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
                    echo  $this->rp2pPayload->buildRejection($request);
                }
                return false;
            }
            if($echo){
                // Return 'inserted' status since the RP2P will be stored in the database
                echo  $this->rp2pPayload->buildInserted($request);
            }
            return true;
        } catch (PDOException $e) {
            // Handle database error
            error_log("Error retrieving existence of RP2P by hash" . $e->getMessage());
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
     * Return fee percent of request and output fee information into the log
     *
     * @param array $p2p The p2p request data from the database
     * @param array $request The transaction request data
     * @return float Fee percent of request
    */
    public function feeInformation(array $p2p, array $request): float {
        $feeAmount = $request['amount'] - $p2p['amount'];
        $feePercent = round(($feeAmount / $p2p['amount']) * Constants::FEE_CONVERSION_FACTOR,Constants::FEE_PERCENT_DECIMAL_PRECISION);
        output(outputFeeInformation($feePercent,$request,$this->currentUser->getMaxFee()), 'SILENT'); // output fee information into the log
        return $feePercent;
    }
}