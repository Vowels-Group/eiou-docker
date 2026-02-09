<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Database\MessageDeliveryRepository;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Database\DeliveryMetricsRepository;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Core\UserContext;
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
    private const SUCCESS_STATUSES = ['received', 'inserted', 'forwarded', 'accepted', 'acknowledged', 'completed', 'warning', 'updated', 'already_relayed'];

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
        int $maxRetries = 5,
        int $baseDelay = 2,
        float $jitterFactor = 0.2,
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
                'nonce' => $sendResult['nonce']
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
                if ($status === 'rejected') {
                    $this->deliveryRepository->markFailed(
                        $messageType,
                        $messageId,
                        'Rejected: ' . ($decodedResponse['message'] ?? 'No reason')
                    );
                    return [
                        'success' => false,
                        'stage' => 'rejected',
                        'message' => $decodedResponse['message'] ?? 'Message rejected by recipient',
                        'response' => $decodedResponse,
                        'retry' => false,
                        'attempts' => 1,
                        'async' => true
                    ];
                }

                // Transport error - mark for retry with specific error message
                if ($status === 'error') {
                    $lastError = $decodedResponse['message'] ?? 'Transport error';
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
                    'nonce' => $sendResult['nonce']
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
                    if ($status === 'rejected') {
                        $this->deliveryRepository->markFailed(
                            $messageType,
                            $messageId,
                            'Rejected: ' . ($decodedResponse['message'] ?? 'No reason')
                        );
                        return [
                            'success' => false,
                            'stage' => 'rejected',
                            'message' => $decodedResponse['message'] ?? 'Message rejected by recipient',
                            'response' => $decodedResponse,
                            'retry' => false,
                            'attempts' => $attempt + 1
                        ];
                    }

                    // Transport error - extract specific error message for retry
                    if ($status === 'error') {
                        $lastError = $decodedResponse['message'] ?? 'Transport error';
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

            // Update retry count in database
            $this->deliveryRepository->incrementRetry($messageType, $messageId, 0, $lastError);

            // If we haven't exhausted retries, wait and try again
            if ($attempt < $this->maxRetries) {
                $delay = $this->calculateRetryDelay($attempt);

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
        if (in_array($status, ['accepted', 'acknowledged', 'completed'], true)) {
            return true;
        }

        // 'inserted' and 'forwarded' complete for specific message types
        if (in_array($status, ['inserted', 'forwarded'], true)) {
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
        if (in_array($status, ['received', 'inserted', 'forwarded'], true)) {
            return $status;
        }
        // Default fallback
        return 'received';
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
            'stage' => 'failed',
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
    public function processRetryQueue(int $limit = 10): array {
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

        return $delivery['delivery_stage'] === 'failed' ||
               ((int) $delivery['retry_count'] >= (int) $delivery['max_retries'] &&
                !in_array($delivery['delivery_stage'], ['completed', 'received', 'inserted', 'forwarded']));
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
            'is_failed' => $delivery['delivery_stage'] === 'failed',
            'is_completed' => $delivery['delivery_stage'] === 'completed',
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
    public function getDlqAlertStatus(int $alertThreshold = 10): array {
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
            // Call the resend callback with the payload
            $result = $resendCallback($item['payload'], $item['recipient_address']);

            if ($result['success'] ?? false) {
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
        } catch (Exception $e) {
            $this->dlqRepository->returnToPending($dlqId);

            $this->logException($e, [
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
    public function cleanup(int $deliveryDays = 30, int $dlqDays = 90): array {
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
        if ($currentStage === 'failed') {
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
