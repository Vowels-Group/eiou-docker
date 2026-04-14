<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Database\MessageDeliveryRepository;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Database\DeliveryMetricsRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Events\DeliveryEvents;
use Eiou\Events\EventDispatcher;
use Eiou\Utils\Logger;
use Exception;

/**
 * Message Delivery Service
 *
 * Handles reliable message delivery with multi-stage acknowledgments,
 * exponential backoff retry, and dead letter queue integration.
 *
 * Implements the Transaction Reliability & Message Handling System.
 *
 * SECTION INDEX:
 * - Constants & Properties............... Line ~33
 * - Constructor.......................... Line ~103
 * - Debugging & Logging.................. Line ~142
 * - Message Sending...................... Line ~214
 * - Delivery Processing.................. Line ~512
 * - Status & Result Helpers.............. Line ~705
 * - Metrics.............................. Line ~801
 * - Retry Queue Processing............... Line ~935
 * - DLQ & Statistics..................... Line ~1085
 * - Acknowledgment Building.............. Line ~1256
 * - Maintenance & Stage Updates.......... Line ~1322
 */
class MessageDeliveryService implements MessageDeliveryServiceInterface {

    // =========================================================================
    // CONSTANTS & PROPERTIES
    // =========================================================================

    /**
     * Success response statuses that indicate delivery was successful
     * Includes contact-specific statuses: 'warning' (contact already exists), 'updated' (address updated)
     */
    private const SUCCESS_STATUSES = Constants::DELIVERY_SUCCESS_STATUSES;

    /**
     * Message types that complete on 'inserted' or 'forwarded' status
     */
    private const COMPLETION_MESSAGE_TYPES = ['rp2p', 'p2p', 'transaction'];

    /**
     * Maximum retry delay cap in seconds (5 minutes)
     */
    private const MAX_RETRY_DELAY_SECONDS = 300;

    /**
     * @var MessageDeliveryRepository Message delivery repository
     */
    private MessageDeliveryRepository $deliveryRepository;

    /**
     * @var DeadLetterQueueRepository Dead letter queue repository
     */
    private DeadLetterQueueRepository $dlqRepository;

    /**
     * @var DeliveryMetricsRepository Delivery metrics repository
     */
    private DeliveryMetricsRepository $metricsRepository;

    /**
     * @var TransportUtilityService Transport utility service
     */
    private TransportUtilityService $transportUtility;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var int Base delay in seconds for exponential backoff
     */
    private int $baseDelay;

    /**
     * @var int Maximum retries before moving to DLQ
     */
    private int $maxRetries;

    /**
     * @var float Jitter factor for retry delay (0.0 - 1.0)
     */
    private float $jitterFactor;

    /**
     * @var TimeUtilityService Time utility service
     */
    private TimeUtilityService $timeUtility;

    /**
     * @var int Start time for delivery time tracking (microtime as int)
     */
    private int $deliveryStartTime = 0;

    /**
     * @var TransactionRepository|null Setter-injected — optional, only needed for
     *      DLQ retry of `message_type='transaction'` items. Late injection avoids
     *      a circular dep with TransactionService.
     */
    private ?TransactionRepository $transactionRepository = null;

    /**
     * @var TransactionChainRepository|null Setter-injected — used when refreshing
     *      a DLQ transaction's previousTxid to the current chain head.
     */
    private ?TransactionChainRepository $transactionChainRepository = null;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    /**
     * Constructor
     *
     * @param MessageDeliveryRepository $deliveryRepository Delivery repository
     * @param DeadLetterQueueRepository $dlqRepository DLQ repository
     * @param DeliveryMetricsRepository $metricsRepository metrics repository
     * @param TransportUtilityService $transportUtility Transport service
     * @param TimeUtilityService $timeUtility Time utility service
     * @param UserContext $currentUser Current user
     * @param int $maxRetries Maximum retries (default: 5)
     * @param int $baseDelay Base delay in seconds (default: 2)
     * @param float $jitterFactor Jitter factor (default: 0.2)
     */
    public function __construct(
        MessageDeliveryRepository $deliveryRepository,
        DeadLetterQueueRepository $dlqRepository,
        DeliveryMetricsRepository $metricsRepository,
        TransportUtilityService $transportUtility,
        TimeUtilityService $timeUtility,
        UserContext $currentUser,
        int $maxRetries = Constants::DELIVERY_MAX_RETRIES,
        int $baseDelay = Constants::DELIVERY_BASE_DELAY_SECONDS,
        float $jitterFactor = Constants::DELIVERY_JITTER_FACTOR,
    ) {
        $this->deliveryRepository = $deliveryRepository;
        $this->dlqRepository = $dlqRepository;
        $this->metricsRepository = $metricsRepository;
        $this->transportUtility = $transportUtility;
        $this->timeUtility = $timeUtility;
        $this->currentUser = $currentUser;
        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
        $this->jitterFactor = $jitterFactor;
    }

    // =========================================================================
    // DEBUGGING & LOGGING
    // =========================================================================

    /**
     * Output debug message for message delivery events
     *
     * @param string $message Message to output
     */
    private function debugOutput(string $message): void {
        if (function_exists('output')) {
            output($message, 'SILENT');
        }
    }

    /**
     * Emit a debug event by calling a named output function if it exists
     *
     * @param string $functionName Name of the output function (e.g., 'outputMessageDeliveryCreated')
     * @param array $params Parameters to pass to the function
     */
    private function emitDebugEvent(string $functionName, array $params): void {
        if (function_exists($functionName)) {
            $this->debugOutput(call_user_func_array($functionName, $params));
        }
    }

    /**
     * Check if a response status indicates successful delivery
     *
     * @param string|null $status Response status to check
     * @return bool True if status indicates success
     */
    private function isSuccessStatus(?string $status): bool {
        return $status !== null && in_array($status, self::SUCCESS_STATUSES, true);
    }

    /**
     * Check if a message type should complete on 'inserted' or 'forwarded' status
     *
     * @param string $messageType Type of message
     * @return bool True if message type completes on intermediate stages
     */
    private function shouldCompleteOnIntermediateStage(string $messageType): bool {
        return in_array($messageType, self::COMPLETION_MESSAGE_TYPES, true);
    }

    /**
     * Log a message using Logger if available
     *
     * @param string $level Log level (info, warning, error)
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void {
        if (class_exists(Logger::class)) {
            Logger::getInstance()->$level($message, $context);
        }
    }

    /**
     * Log an exception using Logger if available
     *
     * @param Exception $e The exception to log
     * @param array $context Additional context
     */
    private function logException(Exception $e, array $context = []): void {
        if (class_exists(Logger::class)) {
            Logger::getInstance()->logException($e, $context);
        }
    }

    // =========================================================================
    // MESSAGE SENDING
    // =========================================================================

    /**
     * Send a message with delivery tracking (unified interface)
     *
     * This is the main entry point for sending messages with delivery tracking.
     * Consolidates the common pattern used by P2pService, Rp2pService,
     * TransactionService, and ContactService.
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string|null $messageId Optional unique message ID (auto-generated if null)
     * @param bool $async Use async (non-blocking) delivery (default: false for sync with retries)
     * @return array Response with 'success', 'response', 'raw', 'tracking', and 'messageId' keys
     */
    public function sendMessage(
        string $messageType,
        string $address,
        array $payload,
        ?string $messageId = null,
        bool $async = false
    ): array {
        // Generate message ID if not provided - use hash from payload if available
        if ($messageId === null) {
            $messageId = $payload['hash'] ?? hash('sha256', json_encode($payload) . $this->timeUtility->getCurrentMicrotime());
        }

        // Use async or sync delivery based on parameter
        if ($async) {
            $result = $this->sendWithTrackingAsync(
                $messageType,
                $messageId,
                $address,
                $payload
            );
        } else {
            $result = $this->sendWithTracking(
                $messageType,
                $messageId,
                $address,
                $payload
            );
        }

        // Extract response from tracking result
        $response = $result['response'] ?? null;
        $rawResponse = $response ? json_encode($response) : '';

        // For async sends, check if queued for retry
        $isQueuedForRetry = ($result['stage'] ?? '') === 'queued_for_retry';

        $this->log('info', "Message sent via unified sendMessage", [
            'message_type' => $messageType,
            'address' => $address,
            'message_id' => $messageId,
            'stage' => $result['stage'] ?? 'unknown',
            'success' => $result['success'] ?? false,
            'async' => $async,
            'queued_for_retry' => $isQueuedForRetry
        ]);

        return [
            'success' => $result['success'] ?? false,
            'response' => $response,
            'raw' => $rawResponse,
            'tracking' => $result,
            'messageId' => $messageId,
            'queued_for_retry' => $isQueuedForRetry,
            'signing_data' => $result['signing_data'] ?? null
        ];
    }

    /**
     * Send a message with delivery tracking - non-blocking (async) version
     *
     * Attempts to deliver a message ONCE without blocking on retries.
     * If the first attempt fails, the message is stored for background retry
     * processing via processRetryQueue(). This allows P2P broadcast loops
     * to continue without waiting for the full retry cycle.
     *
     * Use this method when sending multiple messages in a loop (e.g., P2P broadcasts)
     * to prevent blocking on individual message delivery failures.
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Unique identifier (txid, hash, etc.)
     * @param string $recipient Recipient address
     * @param array $payload Message payload
     * @return array Response with status and delivery info (immediate result, no retries)
     */
    public function sendWithTrackingAsync(
        string $messageType,
        string $messageId,
        string $recipient,
        array $payload
    ): array {
        // Start tracking delivery time
        $this->deliveryStartTime = $this->timeUtility->getCurrentMicrotime();

        // Create or get existing delivery record
        if (!$this->deliveryRepository->deliveryExists($messageType, $messageId)) {
            // Store payload with the delivery record for potential background retry
            $this->deliveryRepository->createDelivery(
                $messageType,
                $messageId,
                $recipient,
                'pending',
                $this->maxRetries,
                $payload
            );

            $this->emitDebugEvent('outputMessageDeliveryCreated', [$messageType, $messageId, $recipient]);
        } else {
            // Update payload if record already exists
            $this->deliveryRepository->updatePayload($messageType, $messageId, $payload);
        }

        // Perform single delivery attempt (non-blocking)
        return $this->attemptSingleDelivery(
            $messageType,
            $messageId,
            $recipient,
            $payload
        );
    }

    /**
     * Attempt a single delivery without retries (for async/non-blocking sends)
     *
     * If the delivery fails, the message is left in 'sent' state with retry_count=0
     * so it can be picked up by processRetryQueue() for background retry.
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $recipient Recipient address
     * @param array $payload Message payload
     * @return array Result of the single attempt
     */
    private function attemptSingleDelivery(
        string $messageType,
        string $messageId,
        string $recipient,
        array $payload
    ): array {
        // Update stage to sent
        $this->deliveryRepository->updateStage($messageType, $messageId, 'sent');

        $this->log('info', "Attempting async message delivery (single attempt)", [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'recipient' => $recipient
        ]);

        try {
            // Attempt delivery with signing data capture
            $sendResult = $this->transportUtility->send($recipient, $payload, true);
            $response = $sendResult['response'];
            $signingData = [
                'signature' => $sendResult['signature'],
                'nonce' => $sendResult['nonce'],
                'signed_message' => $sendResult['signed_message'] ?? null
            ];
            $decodedResponse = json_decode($response, true);

            // Check for successful response
            if ($decodedResponse !== null && !empty($response)) {
                $status = $decodedResponse['status'] ?? null;

                if ($this->isSuccessStatus($status)) {
                    $result = $this->processSuccessfulDelivery(
                        $messageType,
                        $messageId,
                        $status,
                        $decodedResponse,
                        $response,
                        0 // First attempt
                    );
                    $result['attempts'] = 1;
                    $result['async'] = true;
                    $result['signing_data'] = $signingData;
                    return $result;
                }

                // Explicit rejection - don't retry
                if ($status === Constants::DELIVERY_REJECTED) {
                    $this->deliveryRepository->markFailed(
                        $messageType,
                        $messageId,
                        'Rejected: ' . ($decodedResponse['message'] ?? 'No reason')
                    );
                    return [
                        'success' => false,
                        'stage' => Constants::DELIVERY_REJECTED,
                        'message' => $decodedResponse['message'] ?? 'Message rejected by recipient',
                        'response' => $decodedResponse,
                        'retry' => false,
                        'attempts' => 1,
                        'async' => true
                    ];
                }

                // Transport error or maintenance mode - mark for retry with specific error message
                if ($status === Constants::DELIVERY_ERROR || $status === Constants::DELIVERY_MAINTENANCE) {
                    $lastError = $decodedResponse['error']['message']
                        ?? $decodedResponse['message']
                        ?? ($status === Constants::DELIVERY_MAINTENANCE ? 'Recipient node in maintenance mode' : 'Transport error');
                } else {
                    // Unknown status - mark for background retry
                    $lastError = 'Unknown response status: ' . ($status ?? 'null');
                }
            } else {
                // No response - mark for background retry
                $lastError = 'No response received from recipient';
            }

        } catch (Exception $e) {
            $lastError = 'Transport exception: ' . $e->getMessage();

            $this->logException($e, [
                'context' => 'async_delivery_attempt',
                'message_type' => $messageType,
                'message_id' => $messageId
            ]);
        }

        // First attempt failed - mark for background retry (don't block)
        // Keep retry_count at 0 so processRetryQueue() will pick it up
        $this->deliveryRepository->incrementRetry($messageType, $messageId, 0, $lastError);

        $this->emitDebugEvent('outputMessageDeliveryQueuedForRetry', [$messageType, $messageId, $this->maxRetries]);

        $this->log('warning', "Async delivery failed, queued for background retry", [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'error' => $lastError
        ]);

        // Return immediately - do not block waiting for retries
        return [
            'success' => false,
            'stage' => 'queued_for_retry',
            'message' => 'First delivery attempt failed, queued for background retry',
            'error' => $lastError,
            'retry' => true,
            'attempts' => 1,
            'max_retries' => $this->maxRetries,
            'async' => true
        ];
    }

    /**
     * Send multiple messages in parallel with delivery tracking.
     *
     * Three phases:
     * 1. Prepare: Create delivery records and update stage to 'sent'
     * 2. Transport: Call transportUtility->sendBatch() for parallel curl_multi
     * 3. Process: Handle each response using existing delivery processing logic
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param array $sends Array of sends, each with 'messageId', 'recipient', 'payload' keys
     * @return array<string, array> Results keyed by recipient address, same structure as sendMessage()
     */
    public function sendBatchAsync(string $messageType, array $sends): array {
        if (empty($sends)) {
            return [];
        }

        $this->deliveryStartTime = $this->timeUtility->getCurrentMicrotime();

        // Phase 1: Prepare - create delivery records
        $recipientMap = []; // recipient => send data
        foreach ($sends as $send) {
            $messageId = $send['messageId'];
            $recipient = $send['recipient'];
            $payload = $send['payload'];
            $recipientMap[$recipient] = $send;

            if (!$this->deliveryRepository->deliveryExists($messageType, $messageId)) {
                $this->deliveryRepository->createDelivery(
                    $messageType,
                    $messageId,
                    $recipient,
                    'pending',
                    $this->maxRetries,
                    $payload
                );
                $this->emitDebugEvent('outputMessageDeliveryCreated', [$messageType, $messageId, $recipient]);
            } else {
                $this->deliveryRepository->updatePayload($messageType, $messageId, $payload);
            }

            $this->deliveryRepository->updateStage($messageType, $messageId, 'sent');
        }

        // Phase 2: Transport - parallel send via curl_multi
        // All sends share the same payload structure (P2P broadcast), signed per-recipient
        $recipients = array_keys($recipientMap);
        $firstPayload = $sends[0]['payload'];
        $transportResults = $this->transportUtility->sendBatch($recipients, $firstPayload);

        // Phase 3: Process - handle each response
        $results = [];
        foreach ($transportResults as $recipient => $transportResult) {
            $send = $recipientMap[$recipient];
            $messageId = $send['messageId'];
            $response = $transportResult['response'];
            $signingData = [
                'signature' => $transportResult['signature'],
                'nonce' => $transportResult['nonce'],
                'signed_message' => $transportResult['signed_message'] ?? null
            ];

            $decodedResponse = json_decode($response, true);

            try {
                if ($decodedResponse !== null && !empty($response)) {
                    $status = $decodedResponse['status'] ?? null;

                    if ($this->isSuccessStatus($status)) {
                        $result = $this->processSuccessfulDelivery(
                            $messageType,
                            $messageId,
                            $status,
                            $decodedResponse,
                            $response,
                            0
                        );
                        $result['attempts'] = 1;
                        $result['async'] = true;
                        $result['signing_data'] = $signingData;

                        $results[$recipient] = [
                            'success' => true,
                            'response' => $decodedResponse,
                            'raw' => $response,
                            'tracking' => $result,
                            'messageId' => $messageId,
                            'queued_for_retry' => false,
                            'signing_data' => $signingData
                        ];
                        continue;
                    }

                    if ($status === Constants::DELIVERY_REJECTED) {
                        $this->deliveryRepository->markFailed(
                            $messageType,
                            $messageId,
                            'Rejected: ' . ($decodedResponse['message'] ?? 'No reason')
                        );
                        $results[$recipient] = [
                            'success' => false,
                            'response' => $decodedResponse,
                            'raw' => $response,
                            'tracking' => [
                                'success' => false,
                                'stage' => Constants::DELIVERY_REJECTED,
                                'message' => $decodedResponse['message'] ?? 'Message rejected by recipient',
                                'response' => $decodedResponse,
                                'retry' => false,
                                'attempts' => 1,
                                'async' => true
                            ],
                            'messageId' => $messageId,
                            'queued_for_retry' => false,
                            'signing_data' => null
                        ];
                        continue;
                    }

                    if ($status === Constants::DELIVERY_ERROR || $status === Constants::DELIVERY_MAINTENANCE) {
                        $lastError = $decodedResponse['error']['message']
                            ?? $decodedResponse['message']
                            ?? ($status === Constants::DELIVERY_MAINTENANCE ? 'Recipient node in maintenance mode' : 'Transport error');
                    } else {
                        $lastError = 'Unknown response status: ' . ($status ?? 'null');
                    }
                } else {
                    $lastError = 'No response received from recipient';
                }
            } catch (\Exception $e) {
                $lastError = 'Transport exception: ' . $e->getMessage();
                $this->logException($e, [
                    'context' => 'batch_delivery_processing',
                    'message_type' => $messageType,
                    'message_id' => $messageId
                ]);
            }

            // Failed - queue for background retry
            $this->deliveryRepository->incrementRetry($messageType, $messageId, 0, $lastError);

            $results[$recipient] = [
                'success' => false,
                'response' => $decodedResponse ?? null,
                'raw' => $response,
                'tracking' => [
                    'success' => false,
                    'stage' => 'queued_for_retry',
                    'message' => 'First delivery attempt failed, queued for background retry',
                    'error' => $lastError,
                    'retry' => true,
                    'attempts' => 1,
                    'async' => true
                ],
                'messageId' => $messageId,
                'queued_for_retry' => true,
                'signing_data' => null
            ];
        }

        return $results;
    }

    /**
     * Send a message with delivery tracking and synchronous retry
     *
     * Attempts to deliver a message with automatic retries using exponential backoff.
     * Will retry up to maxRetries times before moving to the Dead Letter Queue.
     * All retries happen synchronously within this method call.
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Unique identifier (txid, hash, etc.)
     * @param string $recipient Recipient address
     * @param array $payload Message payload
     * @return array Response with status and delivery info
     */
    public function sendWithTracking(
        string $messageType,
        string $messageId,
        string $recipient,
        array $payload
    ): array {
        // Start tracking delivery time
        $this->deliveryStartTime = $this->timeUtility->getCurrentMicrotime();

        // Create or get existing delivery record
        if (!$this->deliveryRepository->deliveryExists($messageType, $messageId)) {
            // Store payload with the delivery record
            $this->deliveryRepository->createDelivery(
                $messageType,
                $messageId,
                $recipient,
                'pending',
                $this->maxRetries,
                $payload
            );

            $this->emitDebugEvent('outputMessageDeliveryCreated', [$messageType, $messageId, $recipient]);
        } else {
            // Update payload if record already exists
            $this->deliveryRepository->updatePayload($messageType, $messageId, $payload);
        }

        // Lock the delivery record against processRetryQueue for the entire sync retry process.
        // createDelivery() leaves next_retry_at = NULL, which processRetryQueue immediately
        // treats as eligible — causing a parallel retry chain alongside this sync loop.
        // This lock covers the initial NULL window; incrementRetry() inside the loop
        // maintains the lock with per-attempt delay + a delivery-time buffer.
        $this->deliveryRepository->lockForProcessing($messageType, $messageId);

        // Perform synchronous delivery with retries
        return $this->attemptDeliveryWithRetries(
            $messageType,
            $messageId,
            $recipient,
            $payload
        );
    }

    // =========================================================================
    // DELIVERY PROCESSING
    // =========================================================================

    /**
     * Attempt delivery with synchronous retries
     *
     * Implements exponential backoff retry logic. Will attempt delivery
     * up to maxRetries times, sleeping between attempts according to
     * the calculated backoff delay.
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $recipient Recipient address
     * @param array $payload Message payload
     * @return array Final result after all attempts
     */
    private function attemptDeliveryWithRetries(
        string $messageType,
        string $messageId,
        string $recipient,
        array $payload
    ): array {
        $lastError = '';

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            // Update stage to sent
            $this->deliveryRepository->updateStage($messageType, $messageId, 'sent');

            $this->log('info', "Attempting message delivery", [
                'message_type' => $messageType,
                'message_id' => $messageId,
                'recipient' => $recipient,
                'attempt' => $attempt + 1,
                'max_attempts' => $this->maxRetries + 1
            ]);

            try {
                // Attempt delivery with signing data capture
                $sendResult = $this->transportUtility->send($recipient, $payload, true);
                $response = $sendResult['response'];
                $signingData = [
                    'signature' => $sendResult['signature'],
                    'nonce' => $sendResult['nonce'],
                    'signed_message' => $sendResult['signed_message'] ?? null
                ];
                $decodedResponse = json_decode($response, true);

                // Check for successful response
                if ($decodedResponse !== null && !empty($response)) {
                    $status = $decodedResponse['status'] ?? null;

                    // Success cases - don't retry
                    if ($this->isSuccessStatus($status)) {
                        $result = $this->processSuccessfulDelivery(
                            $messageType,
                            $messageId,
                            $status,
                            $decodedResponse,
                            $response,
                            $attempt
                        );
                        $result['attempts'] = $attempt + 1;
                        $result['signing_data'] = $signingData;
                        return $result;
                    }

                    // Explicit rejection - don't retry
                    if ($status === Constants::DELIVERY_REJECTED) {
                        $this->deliveryRepository->markFailed(
                            $messageType,
                            $messageId,
                            'Rejected: ' . ($decodedResponse['message'] ?? 'No reason')
                        );
                        return [
                            'success' => false,
                            'stage' => Constants::DELIVERY_REJECTED,
                            'message' => $decodedResponse['message'] ?? 'Message rejected by recipient',
                            'response' => $decodedResponse,
                            'retry' => false,
                            'attempts' => $attempt + 1
                        ];
                    }

                    // Tor cooldown — don't burn a retry, defer to processRetryQueue
                    if (($decodedResponse['error_code'] ?? null) === 'TOR_COOLDOWN') {
                        $cooldownSeconds = $this->currentUser->getTorCircuitCooldownSeconds();

                        // Use lockForProcessing to defer without incrementing retry_count.
                        // This sets next_retry_at so processRetryQueue picks it up after
                        // the cooldown expires, without counting against max retries.
                        $this->deliveryRepository->lockForProcessing(
                            $messageType,
                            $messageId,
                            $cooldownSeconds
                        );

                        $this->log('info', "Delivery deferred: Tor address in cooldown", [
                            'message_type' => $messageType,
                            'message_id' => $messageId,
                            'attempt' => $attempt + 1,
                            'cooldown_seconds' => $cooldownSeconds,
                        ]);

                        // Break out of retry loop — processRetryQueue will pick it up
                        // after the cooldown expires (next_retry_at set above)
                        return [
                            'success' => false,
                            'stage' => 'deferred',
                            'message' => 'Tor address in cooldown, delivery deferred',
                            'retry' => true,
                            'attempts' => $attempt + 1,
                            'deferred_seconds' => $cooldownSeconds
                        ];
                    }

                    // Transport error or maintenance mode - extract specific error message for retry
                    if ($status === Constants::DELIVERY_ERROR || $status === Constants::DELIVERY_MAINTENANCE) {
                        $lastError = $decodedResponse['error']['message']
                            ?? $decodedResponse['message']
                            ?? ($status === Constants::DELIVERY_MAINTENANCE ? 'Recipient node in maintenance mode' : 'Transport error');
                    } else {
                        // Unknown status - treat as failure, may retry
                        $lastError = 'Unknown response status: ' . ($status ?? 'null');
                    }
                } else {
                    // No response or empty response
                    $lastError = 'No response received from recipient';
                }

            } catch (Exception $e) {
                $lastError = 'Transport exception: ' . $e->getMessage();

                $this->logException($e, [
                    'context' => 'delivery_attempt',
                    'message_type' => $messageType,
                    'message_id' => $messageId,
                    'attempt' => $attempt + 1
                ]);
            }

            // Calculate delay before updating the DB.
            $delay = ($attempt < $this->maxRetries) ? $this->calculateRetryDelay($attempt) : 0;

            // Set next_retry_at = now + delay + delivery buffer so the lock covers BOTH the
            // sleep window AND the following delivery attempt window (i.e. the time between
            // waking up and calling incrementRetry again on the next iteration).
            // Without the buffer, next_retry_at would expire during the delivery attempt,
            // allowing processRetryQueue to claim and duplicate the retry chain.
            $deliveryBuffer = Constants::TOR_TRANSPORT_TIMEOUT_SECONDS * 2; // 90s worst-case delivery
            $this->deliveryRepository->incrementRetry($messageType, $messageId, $delay + $deliveryBuffer, $lastError);

            // If we haven't exhausted retries, wait and try again
            if ($attempt < $this->maxRetries) {
                $this->emitDebugEvent('outputMessageDeliveryRetry', [
                    $messageType,
                    $messageId,
                    $attempt + 1,
                    $this->maxRetries,
                    $delay
                ]);

                $this->log('warning', "Delivery attempt failed, retrying", [
                    'message_type' => $messageType,
                    'message_id' => $messageId,
                    'attempt' => $attempt + 1,
                    'next_attempt' => $attempt + 2,
                    'delay_seconds' => $delay,
                    'error' => $lastError
                ]);

                // Sleep for the backoff delay
                sleep($delay);
            }
        }

        // All retries exhausted - move to DLQ
        return $this->handleExhaustedRetries(
            $messageType,
            $messageId,
            $recipient,
            $payload,
            $lastError
        );
    }

    /**
     * Process a successful delivery response
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $status Response status
     * @param array $response Decoded response
     * @param string $rawResponse Raw response string
     * @param int $retryCount Current retry count for metrics
     * @return array Success result
     */
    private function processSuccessfulDelivery(
        string $messageType,
        string $messageId,
        string $status,
        array $response,
        string $rawResponse,
        int $retryCount = 0
    ): array {
        // Get current delivery for previous stage tracking
        $currentDelivery = $this->deliveryRepository->getByMessage($messageType, $messageId);
        $previousStage = $currentDelivery ? $currentDelivery['delivery_stage'] : 'pending';

        // Calculate delivery time for metrics
        $deliveryTimeMs = $this->deliveryStartTime > 0
            ? (int) (($this->timeUtility->getCurrentMicrotime() - $this->deliveryStartTime) / 10)
            : 0;

        // Determine if this status should complete delivery for this message type
        $shouldComplete = $this->shouldStatusCompleteDelivery($status, $messageType);
        $result = $this->buildDeliveryResult($status, $messageType, $shouldComplete, $response, $previousStage);

        // Update database and emit debug events
        if ($shouldComplete) {
            $this->deliveryRepository->markCompleted($messageType, $messageId);
            $this->emitDebugEvent('outputMessageDeliveryCompleted', [$messageType, $messageId]);
        } else {
            $targetStage = $this->mapStatusToStage($status);
            $this->deliveryRepository->updateStage($messageType, $messageId, $targetStage, $rawResponse);
            $this->emitDebugEvent('outputMessageDeliveryStageUpdated', [$messageType, $messageId, $previousStage, $targetStage]);
        }

        // Record metrics for successful delivery
        $this->recordDeliveryMetric($messageType, true, $deliveryTimeMs, $retryCount);

        return $result;
    }

    // =========================================================================
    // STATUS & RESULT HELPERS
    // =========================================================================

    /**
     * Determine if a status should complete delivery for a message type
     *
     * Completion rules:
     * - 'accepted', 'acknowledged', 'completed': Always complete
     * - 'inserted', 'forwarded': Complete for p2p/rp2p/transaction types
     * - 'received': Never completes (intermediate stage)
     *
     * @param string $status Response status
     * @param string $messageType Type of message
     * @return bool True if delivery should be marked complete
     */
    private function shouldStatusCompleteDelivery(string $status, string $messageType): bool {
        // These statuses always complete delivery
        if (in_array($status, [Constants::STATUS_ACCEPTED, Constants::DELIVERY_ACKNOWLEDGED, Constants::DELIVERY_COMPLETED], true)) {
            return true;
        }

        // 'inserted' and 'forwarded' complete for specific message types
        if (in_array($status, [Constants::DELIVERY_INSERTED, Constants::DELIVERY_FORWARDED], true)) {
            return $this->shouldCompleteOnIntermediateStage($messageType);
        }

        return false;
    }

    /**
     * Map a response status to its corresponding delivery stage
     *
     * @param string $status Response status
     * @return string Delivery stage name
     */
    private function mapStatusToStage(string $status): string {
        // Most statuses map directly to stages
        if (in_array($status, [Constants::DELIVERY_RECEIVED, Constants::DELIVERY_INSERTED, Constants::DELIVERY_FORWARDED], true)) {
            return $status;
        }
        // Default fallback
        return Constants::DELIVERY_RECEIVED;
    }

    /**
     * Build the delivery result array based on status and completion state
     *
     * @param string $status Response status
     * @param string $messageType Type of message
     * @param bool $isCompleted Whether delivery is completed
     * @param array $response Original response
     * @param string $previousStage Previous delivery stage (for context)
     * @return array Result array with success, stage, message, and response
     */
    private function buildDeliveryResult(
        string $status,
        string $messageType,
        bool $isCompleted,
        array $response,
        string $previousStage
    ): array {
        // Status-specific messages for completed deliveries
        $completionMessages = [
            'accepted' => 'Message accepted by recipient',
            'acknowledged' => 'Completion message acknowledged by recipient',
            'completed' => 'Inquiry confirmed - transaction completed by recipient',
            'inserted' => strtoupper($messageType) . ' message inserted by recipient - delivery complete',
            'forwarded' => strtoupper($messageType) . ' message forwarded by recipient - delivery complete',
        ];

        // Messages for non-completed stage updates
        $stageMessages = [
            'received' => 'Message received by recipient',
            'inserted' => 'Message stored in recipient database',
            'forwarded' => 'Message forwarded to next hop',
        ];

        if ($isCompleted) {
            return [
                'success' => true,
                'stage' => 'completed',
                'message' => $completionMessages[$status] ?? 'Message delivery completed',
                'response' => $response
            ];
        }

        $targetStage = $this->mapStatusToStage($status);
        return [
            'success' => true,
            'stage' => $targetStage,
            'message' => $stageMessages[$targetStage] ?? 'Message delivered with status: ' . $status,
            'response' => $response
        ];
    }

    // =========================================================================
    // METRICS
    // =========================================================================

    /**
     * Record a delivery metric
     *
     * @param string $messageType Type of message
     * @param bool $isDelivered Whether the message was delivered
     * @param int $deliveryTimeMs Delivery time in milliseconds
     * @param int $retryCount Number of retries
     */
    private function recordDeliveryMetric(
        string $messageType,
        bool $isDelivered,
        int $deliveryTimeMs = 0,
        int $retryCount = 0
    ): void {
        if ($this->metricsRepository === null) {
            return;
        }

        try {
            $this->metricsRepository->recordDeliveryEvent(
                $messageType,
                $isDelivered,
                $deliveryTimeMs,
                $retryCount
            );

            // Also record to 'all' type for aggregate metrics
            $this->metricsRepository->recordDeliveryEvent(
                'all',
                $isDelivered,
                $deliveryTimeMs,
                $retryCount
            );
        } catch (Exception $e) {
            // Metrics recording should not fail the delivery
            $this->log('warning', "Failed to record delivery metric", [
                'message_type' => $messageType,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle exhausted retries - move to DLQ
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $recipient Recipient address
     * @param array $payload Message payload
     * @param string $lastError Last error message
     * @return array Failure result
     */
    private function handleExhaustedRetries(
        string $messageType,
        string $messageId,
        string $recipient,
        array $payload,
        string $lastError
    ): array {
        // Get current retry count from database
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);
        $retryCount = $delivery ? (int) $delivery['retry_count'] : $this->maxRetries;

        // Calculate delivery time for metrics
        $deliveryTimeMs = $this->deliveryStartTime > 0
            ? (int) (($this->timeUtility->getCurrentMicrotime() - $this->deliveryStartTime) / 10)
            : 0;

        // Move to Dead Letter Queue
        $this->dlqRepository->addToQueue(
            $messageType,
            $messageId,
            $payload,
            $recipient,
            $retryCount,
            $lastError
        );

        // Mark as failed in delivery tracking
        $this->deliveryRepository->markFailed($messageType, $messageId, $lastError);

        // Debug output for failure and DLQ
        $this->emitDebugEvent('outputMessageDeliveryFailed', [$messageType, $messageId, $lastError]);
        $this->emitDebugEvent('outputMessageDeliveryMovedToDlq', [$messageType, $messageId, $retryCount]);

        // Record metrics for failed delivery
        $this->recordDeliveryMetric($messageType, false, $deliveryTimeMs, $retryCount);

        $this->log('error', "Message delivery exhausted all retries, moved to DLQ", [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'recipient' => $recipient,
            'retry_count' => $retryCount,
            'error' => $lastError
        ]);

        return [
            'success' => false,
            'stage' => Constants::DELIVERY_FAILED,
            'message' => 'Message delivery failed after ' . ($this->maxRetries + 1) . ' attempts',
            'error' => $lastError,
            'retry' => false,
            'dlq' => true,
            'attempts' => $this->maxRetries + 1,
            'retry_count' => $retryCount
        ];
    }

    /**
     * Calculate retry delay using exponential backoff with jitter
     *
     * Formula: delay = baseDelay * (2 ^ retryCount) + random_jitter
     *
     * @param int $retryCount Current retry count (0-based)
     * @return int Delay in seconds
     */
    private function calculateRetryDelay(int $retryCount): int {
        // Exponential backoff: 2, 4, 8, 16, 32 seconds base
        $exponentialDelay = $this->baseDelay * pow(2, $retryCount);

        // Add jitter (±20% by default)
        $jitter = $exponentialDelay * $this->jitterFactor * (mt_rand(-100, 100) / 100);

        // Calculate final delay (minimum 1 second)
        $delay = max(1, (int) round($exponentialDelay + $jitter));

        // Cap at maximum delay
        return min($delay, self::MAX_RETRY_DELAY_SECONDS);
    }

    // =========================================================================
    // RETRY QUEUE PROCESSING
    // =========================================================================

    /**
     * Process messages ready for retry (asynchronous/background processing)
     *
     * NOTE: With the new synchronous retry implementation, this method is primarily
     * used for background processing of messages that may have been left in a
     * pending/sent state (e.g., due to server restart during retry).
     *
     * For normal operations, retries happen synchronously within sendWithTracking.
     *
     * @param int $limit Maximum messages to process
     * @return array Results with processed count and details
     */
    public function processRetryQueue(int $limit = Constants::DELIVERY_RETRY_BATCH_SIZE): array {
        $messages = $this->deliveryRepository->getMessagesForRetry($limit);
        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'no_payload' => 0,
            'details' => []
        ];

        foreach ($messages as $delivery) {
            $messageType = $delivery['message_type'];
            $messageId = $delivery['message_id'];
            $recipient = $delivery['recipient_address'];
            $retryCount = (int) $delivery['retry_count'];

            // Atomically claim this message — skip if another worker beat us to it
            if (!$this->deliveryRepository->claimForRetry($messageType, $messageId)) {
                $this->log('info', "Skipping message already claimed by another worker", [
                    'message_type' => $messageType,
                    'message_id' => $messageId
                ]);
                continue;
            }

            // Get stored payload
            $payload = null;
            if (!empty($delivery['payload'])) {
                $payload = json_decode($delivery['payload'], true);
            }

            if ($payload === null || !is_array($payload)) {
                // No payload available - cannot retry, move to DLQ
                $this->log('warning', "Cannot retry message: no payload stored", [
                    'message_type' => $messageType,
                    'message_id' => $messageId,
                    'retry_count' => $retryCount
                ]);

                // Move to DLQ since we can't retry
                $this->dlqRepository->addToQueue(
                    $messageType,
                    $messageId,
                    ['note' => 'Payload not available for retry'],
                    $recipient,
                    $retryCount,
                    'No payload stored for retry'
                );
                $this->deliveryRepository->markFailed($messageType, $messageId, 'No payload stored for retry');

                $results['no_payload']++;
                $results['details'][] = [
                    'message_id' => $messageId,
                    'status' => 'no_payload',
                    'error' => 'No payload stored'
                ];
                continue;
            }

            $this->log('info', "Processing queued message for retry", [
                'message_type' => $messageType,
                'message_id' => $messageId,
                'retry_count' => $retryCount,
                'recipient' => $recipient
            ]);

            // Use the synchronous retry mechanism (will attempt remaining retries)
            $result = $this->attemptDeliveryWithRetries(
                $messageType,
                $messageId,
                $recipient,
                $payload
            );

            $results['processed']++;

            if ($result['success']) {
                $results['succeeded']++;

                // Dispatch delivery completed event for post-delivery processing.
                // This allows message-type-specific services (e.g., ContactSyncService)
                // to run post-delivery logic when a retried message succeeds.
                EventDispatcher::getInstance()->dispatch(DeliveryEvents::RETRY_DELIVERY_COMPLETED, [
                    'message_type' => $messageType,
                    'message_id' => $messageId,
                    'recipient_address' => $recipient,
                    'response' => $result['response'] ?? [],
                    'signing_data' => $result['signing_data'] ?? null,
                    'stored_payload' => $payload,
                ]);

                $results['details'][] = [
                    'message_id' => $messageId,
                    'status' => 'success',
                    'stage' => $result['stage'] ?? 'unknown',
                    'attempts' => $result['attempts'] ?? 1
                ];
            } else {
                // Check if this was moved to DLQ (exhausted retries)
                if (isset($result['dlq']) && $result['dlq']) {
                    $results['failed']++;
                    $results['details'][] = [
                        'message_id' => $messageId,
                        'status' => 'exhausted',
                        'error' => $result['error'] ?? 'Max retries exceeded',
                        'attempts' => $result['attempts'] ?? $this->maxRetries + 1
                    ];
                } else {
                    $results['details'][] = [
                        'message_id' => $messageId,
                        'status' => $result['stage'] ?? 'failed',
                        'error' => $result['error'] ?? 'Unknown error'
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Check if a message has exhausted all retries
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool True if retries are exhausted
     */
    public function hasExhaustedRetries(string $messageType, string $messageId): bool {
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);

        if (!$delivery) {
            return false;
        }

        return (int) $delivery['retry_count'] >= (int) $delivery['max_retries'];
    }

    /**
     * Check if a message delivery has failed (either exhausted or explicitly failed)
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool True if delivery has failed
     */
    public function hasDeliveryFailed(string $messageType, string $messageId): bool {
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);

        if (!$delivery) {
            return false;
        }

        return $delivery['delivery_stage'] === Constants::DELIVERY_FAILED ||
               ((int) $delivery['retry_count'] >= (int) $delivery['max_retries'] &&
                !in_array($delivery['delivery_stage'], [Constants::DELIVERY_COMPLETED, Constants::DELIVERY_RECEIVED, Constants::DELIVERY_INSERTED, Constants::DELIVERY_FORWARDED]));
    }

    // =========================================================================
    // DLQ & STATISTICS
    // =========================================================================

    /**
     * Get the current delivery status for a message
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return array|null Delivery status or null if not found
     */
    public function getDeliveryStatus(string $messageType, string $messageId): ?array {
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);

        if (!$delivery) {
            return null;
        }

        return [
            'stage' => $delivery['delivery_stage'],
            'retry_count' => (int) $delivery['retry_count'],
            'max_retries' => (int) $delivery['max_retries'],
            'last_error' => $delivery['last_error'],
            'next_retry_at' => $delivery['next_retry_at'],
            'is_failed' => $delivery['delivery_stage'] === Constants::DELIVERY_FAILED,
            'is_completed' => $delivery['delivery_stage'] === Constants::DELIVERY_COMPLETED,
            'retries_exhausted' => (int) $delivery['retry_count'] >= (int) $delivery['max_retries']
        ];
    }

    /**
     * Process exhausted retries and move to DLQ
     *
     * @return int Number of messages moved to DLQ
     */
    public function processExhaustedRetries(): int {
        $exhausted = $this->deliveryRepository->getExhaustedRetries();
        $moved = 0;

        foreach ($exhausted as $delivery) {
            // Only move to DLQ if not already there
            if (!$this->dlqRepository->existsByMessageId($delivery['message_id'])) {
                $this->dlqRepository->addToQueue(
                    $delivery['message_type'],
                    $delivery['message_id'],
                    ['note' => 'Payload not available - exceeded retries'],
                    $delivery['recipient_address'],
                    $delivery['retry_count'],
                    $delivery['last_error'] ?? 'Exceeded maximum retries'
                );

                $this->deliveryRepository->markFailed(
                    $delivery['message_type'],
                    $delivery['message_id'],
                    'Moved to DLQ after exhausting retries'
                );

                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Mark all deliveries matching a hash pattern as completed
     *
     * Used to mark all P2P broadcast deliveries as completed when the
     * transaction completes. Delegates to MessageDeliveryRepository.
     *
     * @param string $messageType Type of message ('p2p' or 'rp2p')
     * @param string $hash The P2P hash to match
     * @return int Number of updated records
     */
    public function markCompletedByHash(string $messageType, string $hash): int {
        return $this->deliveryRepository->markCompletedByHash($messageType, $hash);
    }

    /**
     * Get delivery statistics
     *
     * @return array Statistics including success rate
     */
    public function getDeliveryStatistics(): array {
        $stats = $this->deliveryRepository->getStatistics();

        // Calculate success rate
        $total = (int) ($stats['total_count'] ?? 0);
        $completed = (int) ($stats['completed_count'] ?? 0);

        $stats['success_rate'] = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
        $stats['failure_rate'] = $total > 0 ? round((($stats['failed_count'] ?? 0) / $total) * 100, 2) : 0;

        return $stats;
    }

    /**
     * Get DLQ alert information
     *
     * @param int $alertThreshold Number of pending items to trigger alert
     * @return array Alert information
     */
    /**
     * Late-inject the transaction repository. Needed only when refreshing a
     * stale transaction DLQ payload before retry.
     */
    public function setTransactionRepository(TransactionRepository $repo): void {
        $this->transactionRepository = $repo;
    }

    /**
     * Late-inject the transaction chain repository.
     */
    public function setTransactionChainRepository(TransactionChainRepository $repo): void {
        $this->transactionChainRepository = $repo;
    }

    /**
     * Refresh a stale transaction DLQ payload's previousTxid and time against
     * current DB state. Updates the tx row (previous_txid + time), overwrites
     * the DLQ row's payload, and returns the updated $dlqItem. Returns null if
     * dependencies aren't wired or the payload isn't refreshable — caller then
     * falls back to retrying the original payload unchanged.
     *
     * The peer's signature verification will use the refreshed previousTxid +
     * time because the transport layer's send() re-signs every payload.
     */
    private function refreshTransactionDlqPayload(array $dlqItem): ?array {
        if ($this->transactionRepository === null || $this->transactionChainRepository === null) {
            return null;
        }

        // DeadLetterQueueRepository::getById auto-decodes the payload JSON
        // column into an array. Fall back to decoding a string for robustness
        // against upstream changes or callers that bypass getById.
        $rawPayload = $dlqItem['payload'] ?? null;
        $payload = is_array($rawPayload)
            ? $rawPayload
            : (is_string($rawPayload) ? json_decode($rawPayload, true) : null);
        if (!is_array($payload) || empty($payload['txid']) || empty($payload['senderPublicKey']) || empty($payload['receiverPublicKey'])) {
            return null;
        }

        $txid = $payload['txid'];
        $currency = $payload['currency'] ?? null;
        $storedPrevTxid = $payload['previousTxid'] ?? null;

        // Ask the DB what the current chain head looks like from this sender's
        // perspective, excluding this txid itself so we don't circularly point
        // at ourselves.
        $currentPrevTxid = $this->transactionRepository->getPreviousTxid(
            $payload['senderPublicKey'],
            $payload['receiverPublicKey'],
            $txid,
            $currency
        );

        $freshTime = $this->timeUtility->getCurrentMicrotime();
        $prevChanged = ($currentPrevTxid !== $storedPrevTxid);

        // Always refresh time on retry so peer freshness checks don't trip on
        // a long-dwelling DLQ entry; only update DB prev_txid if it actually
        // changed.
        $payload['time'] = $freshTime;
        if ($prevChanged) {
            $payload['previousTxid'] = $currentPrevTxid;
            $this->transactionChainRepository->updatePreviousTxid($txid, $currentPrevTxid);
        }
        $this->transactionRepository->updateTime($txid, $freshTime);

        // DB column holds the JSON-encoded string; keep in-memory form as an
        // array so it matches what getById returns to callers.
        $newPayloadJson = json_encode($payload);
        $this->dlqRepository->updatePayload((int) $dlqItem['id'], $newPayloadJson);

        Logger::getInstance()->info("DLQ transaction payload refreshed before retry", [
            'dlq_id' => $dlqItem['id'],
            'txid' => substr($txid, 0, 16) . '...',
            'prev_changed' => $prevChanged,
            'old_prev_txid' => $storedPrevTxid ? substr($storedPrevTxid, 0, 16) . '...' : null,
            'new_prev_txid' => $currentPrevTxid ? substr($currentPrevTxid, 0, 16) . '...' : null
        ]);

        $dlqItem['payload'] = $payload;
        return $dlqItem;
    }

    public function getDlqAlertStatus(int $alertThreshold = Constants::DLQ_ALERT_THRESHOLD): array {
        $pendingCount = $this->dlqRepository->getPendingCount();
        $stats = $this->dlqRepository->getStatistics();

        return [
            'pending_count' => $pendingCount,
            'alert_triggered' => $pendingCount >= $alertThreshold,
            'threshold' => $alertThreshold,
            'statistics' => $stats
        ];
    }

    /**
     * Retry a message from the DLQ
     *
     * @param int $dlqId DLQ item ID
     * @param callable $resendCallback Callback to resend the message
     * @return array Result of retry attempt
     */
    public function retryFromDlq(int $dlqId, callable $resendCallback): array {
        $item = $this->dlqRepository->getById($dlqId);

        if (!$item) {
            return [
                'success' => false,
                'error' => 'DLQ item not found'
            ];
        }

        $this->emitDebugEvent('outputDeadLetterQueueRetry', [$dlqId, $item['message_type'], $item['message_id']]);

        // Mark as retrying
        $this->dlqRepository->markRetrying($dlqId);

        try {
            // Transaction DLQ entries may have gone stale while sitting in DLQ:
            // the chain can have advanced (new outbound txs) or a chain drop
            // may have re-wired the link past a missing transaction since the
            // original send. Refresh previousTxid + time against current DB
            // state so the retry signs and ships the correct chain link.
            // Transport layer re-signs on every send(), so updating the inner
            // payload is sufficient — no manual re-sign needed here.
            //
            // The refresh is best-effort; if it throws, log and fall through to
            // sending the original payload rather than stranding the item in
            // 'retrying'. This keeps the retry resilient to transient DB errors.
            if (($item['message_type'] ?? null) === 'transaction') {
                try {
                    $refreshed = $this->refreshTransactionDlqPayload($item);
                    if ($refreshed !== null) {
                        $item = $refreshed;
                    }
                } catch (\Throwable $refreshError) {
                    Logger::getInstance()->warning("DLQ transaction payload refresh failed — proceeding with original payload", [
                        'dlq_id' => $dlqId,
                        'message_id' => $item['message_id'] ?? 'unknown',
                        'error' => $refreshError->getMessage()
                    ]);
                }
            }

            // Call the resend callback with the (possibly refreshed) payload
            $result = $resendCallback($item['payload'], $item['recipient_address']);

            if ($result['success'] ?? false) {
                // Persist the freshly-signed envelope back to the transactions
                // table. Transport's send() re-signs on every call, so the DB's
                // sender_signature/nonce would otherwise drift out of sync with
                // what we actually put on the wire — and future chain sync
                // responses would serve a signature that doesn't verify against
                // the current previous_txid + time values. Callers that want
                // this sync should include 'signing_data' in their success
                // return (keys: signature, nonce, signed_message).
                if (($item['message_type'] ?? null) === 'transaction'
                    && $this->transactionRepository !== null
                    && !empty($result['signing_data']['signature'])
                    && !empty($result['signing_data']['nonce'])) {
                    // getById returns payload as an array (auto-decoded),
                    // but fall back to decoding a string for robustness.
                    $payloadData = is_array($item['payload'] ?? null)
                        ? $item['payload']
                        : (is_string($item['payload'] ?? null) ? json_decode($item['payload'], true) : null);
                    $txid = is_array($payloadData) ? ($payloadData['txid'] ?? null) : null;
                    if ($txid !== null) {
                        $this->transactionRepository->updateSignatureData(
                            $txid,
                            $result['signing_data']['signature'],
                            $result['signing_data']['nonce'],
                            $result['signing_data']['signed_message'] ?? null
                        );
                    }
                }

                $this->dlqRepository->markResolved($dlqId);
                $this->emitDebugEvent('outputDeadLetterQueueResolved', [$dlqId, $item['message_type'], $item['message_id']]);

                return [
                    'success' => true,
                    'message' => 'Message successfully resent from DLQ'
                ];
            } else {
                // Return to pending for another attempt
                $this->dlqRepository->returnToPending($dlqId);
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Resend failed'
                ];
            }
        } catch (\Throwable $e) {
            // Widened to Throwable so PHP Errors (e.g. type errors, undefined
            // methods introduced by stale opcache) also reset the row from
            // 'retrying' back to 'pending' instead of stranding it.
            $this->dlqRepository->returnToPending($dlqId);

            $this->logException($e instanceof Exception ? $e : new Exception($e->getMessage(), 0), [
                'context' => 'dlq_retry',
                'dlq_id' => $dlqId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    // =========================================================================
    // ACKNOWLEDGMENT BUILDING
    // =========================================================================

    /**
     * Build multi-stage acknowledgment response
     *
     * @param string $stage Acknowledgment stage
     * @param string|null $messageId Optional message ID
     * @param string|null $additionalInfo Additional info
     * @return string JSON response
     */
    public function buildAcknowledgment(string $stage, ?string $messageId = null, ?string $additionalInfo = null): string {
        $response = [
            'status' => $stage,
            'timestamp' => $this->timeUtility->getCurrentMicrotime(),
            'senderAddress' => $this->currentUser->getHttpAddress(),
            'senderPublicKey' => $this->currentUser->getPublicKey()
        ];

        if ($messageId !== null) {
            $response['messageId'] = $messageId;
        }

        if ($additionalInfo !== null) {
            $response['message'] = $additionalInfo;
        }

        return json_encode($response);
    }

    /**
     * Acknowledge message received
     *
     * @param string $messageId Message identifier
     * @return string JSON acknowledgment
     */
    public function acknowledgeReceived(string $messageId): string {
        return $this->buildAcknowledgment('received', $messageId, 'Message received');
    }

    /**
     * Acknowledge message inserted into database
     *
     * @param string $messageId Message identifier
     * @return string JSON acknowledgment
     */
    public function acknowledgeInserted(string $messageId): string {
        return $this->buildAcknowledgment('inserted', $messageId, 'Message stored in database');
    }

    /**
     * Acknowledge message forwarded to next hop
     *
     * @param string $messageId Message identifier
     * @param string|null $nextHop Address of next hop
     * @return string JSON acknowledgment
     */
    public function acknowledgeForwarded(string $messageId, ?string $nextHop = null): string {
        $info = 'Message forwarded';
        if ($nextHop) {
            $info .= ' to ' . $nextHop;
        }
        return $this->buildAcknowledgment('forwarded', $messageId, $info);
    }

    // =========================================================================
    // MAINTENANCE & STAGE UPDATES
    // =========================================================================

    /**
     * Cleanup old records
     *
     * @param int $deliveryDays Days to keep delivery records
     * @param int $dlqDays Days to keep resolved DLQ records
     * @return array Cleanup results
     */
    public function cleanup(int $deliveryDays = Constants::CLEANUP_DELIVERY_RETENTION_DAYS, int $dlqDays = Constants::CLEANUP_DLQ_RETENTION_DAYS): array {
        return [
            'delivery_deleted' => $this->deliveryRepository->deleteOldRecords($deliveryDays),
            'dlq_deleted' => $this->dlqRepository->deleteOldRecords($dlqDays)
        ];
    }

    /**
     * Update delivery stage to 'forwarded' after successfully forwarding a message
     *
     * This method is called after an intermediary has successfully forwarded a
     * message to the next hop in the chain. It updates the stage to 'forwarded'.
     *
     * Stage progression is enforced to prevent regression:
     * pending -> sent -> received -> inserted -> forwarded -> completed
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Message identifier
     * @param string|null $nextHop Optional address of next hop for logging
     * @return bool Success status
     */
    public function updateStageToForwarded(
        string $messageType,
        string $messageId,
        ?string $nextHop = null
    ): bool {
        // Get current delivery record
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);

        if (!$delivery) {
            $this->log('warning', "Cannot update stage to forwarded: delivery record not found", [
                'message_type' => $messageType,
                'message_id' => $messageId
            ]);
            return false;
        }

        $currentStage = $delivery['delivery_stage'];

        // Define stage order for progression checking
        $stageOrder = [
            'pending' => 0,
            'sent' => 1,
            'received' => 2,
            'inserted' => 3,
            'forwarded' => 4,
            'completed' => 5,
            'failed' => 6
        ];

        $currentOrder = $stageOrder[$currentStage] ?? -1;
        $forwardedOrder = $stageOrder['forwarded'];

        // Update to 'forwarded' only if current stage is before 'forwarded'
        if ($currentOrder < $forwardedOrder) {
            $responseData = [
                'message' => 'Message forwarded to next hop',
                'timestamp' => $this->timeUtility->getCurrentMicrotime(),
                'previous_stage' => $currentStage
            ];
            if ($nextHop !== null) {
                $responseData['next_hop'] = $nextHop;
            }

            $this->deliveryRepository->updateStage(
                $messageType,
                $messageId,
                'forwarded',
                json_encode($responseData)
            );

            $this->log('info', "Delivery stage updated to 'forwarded'", [
                'message_type' => $messageType,
                'message_id' => $messageId,
                'previous_stage' => $currentStage,
                'next_hop' => $nextHop
            ]);

            return true;
        }

        return false;
    }

    /**
     * Mark a delivery as completed
     *
     * This method marks a delivery as completed when the entire message flow
     * has finished successfully (e.g., when a P2P transaction completes).
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Message identifier
     * @return bool Success status
     */
    public function markDeliveryCompleted(
        string $messageType,
        string $messageId
    ): bool {
        // Get current delivery record
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);

        if (!$delivery) {
            $this->log('warning', "Cannot mark completed: delivery record not found", [
                'message_type' => $messageType,
                'message_id' => $messageId
            ]);
            return false;
        }

        $currentStage = $delivery['delivery_stage'];

        // Don't mark as completed if already failed
        if ($currentStage === Constants::DELIVERY_FAILED) {
            return false;
        }

        $this->deliveryRepository->markCompleted($messageType, $messageId);

        $this->log('info', "Delivery marked as 'completed'", [
            'message_type' => $messageType,
            'message_id' => $messageId,
            'previous_stage' => $currentStage
        ]);

        return true;
    }

    /**
     * Update delivery stage after local database operation
     *
     * This method is called after a message has been successfully stored locally
     * (e.g., contact inserted into database). It updates the stage to 'inserted'
     * and optionally marks the delivery as 'completed'.
     *
     * Stage progression is enforced to prevent regression:
     * pending -> sent -> received -> inserted -> forwarded -> completed
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Message identifier
     * @param bool $markCompleted Whether to mark as completed after inserting
     * @return bool Success status
     */
    public function updateStageAfterLocalInsert(
        string $messageType,
        string $messageId,
        bool $markCompleted = false
    ): bool {
        // Get current delivery record
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);

        if (!$delivery) {
            $this->log('warning', "Cannot update stage: delivery record not found", [
                'message_type' => $messageType,
                'message_id' => $messageId
            ]);
            return false;
        }

        $currentStage = $delivery['delivery_stage'];

        // Define stage order for progression checking
        $stageOrder = [
            'pending' => 0,
            'sent' => 1,
            'received' => 2,
            'inserted' => 3,
            'forwarded' => 4,
            'completed' => 5,
            'failed' => 6
        ];

        $currentOrder = $stageOrder[$currentStage] ?? -1;
        $insertedOrder = $stageOrder['inserted'];
        $completedOrder = $stageOrder['completed'];

        // Update to 'inserted' only if current stage is before 'inserted'
        if ($currentOrder < $insertedOrder) {
            $this->deliveryRepository->updateStage(
                $messageType,
                $messageId,
                'inserted',
                json_encode([
                    'message' => 'Data stored in local database',
                    'timestamp' => $this->timeUtility->getCurrentMicrotime(),
                    'previous_stage' => $currentStage
                ])
            );

            $this->log('info', "Delivery stage updated to 'inserted'", [
                'message_type' => $messageType,
                'message_id' => $messageId,
                'previous_stage' => $currentStage
            ]);

            $currentOrder = $insertedOrder;
        }

        // Mark as completed if requested and not already completed
        if ($markCompleted && $currentOrder < $completedOrder) {
            $this->deliveryRepository->markCompleted($messageType, $messageId);

            $this->log('info', "Delivery marked as 'completed'", [
                'message_type' => $messageType,
                'message_id' => $messageId
            ]);
        }

        return true;
    }
}
