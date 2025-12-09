<?php
# Copyright 2025

require_once __DIR__ . '/../database/MessageDeliveryRepository.php';
require_once __DIR__ . '/../database/DeadLetterQueueRepository.php';

/**
 * Message Delivery Service
 *
 * Handles reliable message delivery with multi-stage acknowledgments,
 * exponential backoff retry, and dead letter queue integration.
 *
 * Implements the Transaction Reliability & Message Handling System (Issue #139).
 *
 * @package Services
 */
class MessageDeliveryService {
    /**
     * @var MessageDeliveryRepository Message delivery repository
     */
    private MessageDeliveryRepository $deliveryRepository;

    /**
     * @var DeadLetterQueueRepository Dead letter queue repository
     */
    private DeadLetterQueueRepository $dlqRepository;

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
     * Constructor
     *
     * @param MessageDeliveryRepository $deliveryRepository Delivery repository
     * @param DeadLetterQueueRepository $dlqRepository DLQ repository
     * @param TransportUtilityService $transportUtility Transport service
     * @param UserContext $currentUser Current user
     * @param int $maxRetries Maximum retries (default: 5)
     * @param int $baseDelay Base delay in seconds (default: 2)
     * @param float $jitterFactor Jitter factor (default: 0.2)
     */
    public function __construct(
        MessageDeliveryRepository $deliveryRepository,
        DeadLetterQueueRepository $dlqRepository,
        TransportUtilityService $transportUtility,
        UserContext $currentUser,
        int $maxRetries = 5,
        int $baseDelay = 2,
        float $jitterFactor = 0.2
    ) {
        $this->deliveryRepository = $deliveryRepository;
        $this->dlqRepository = $dlqRepository;
        $this->transportUtility = $transportUtility;
        $this->currentUser = $currentUser;
        $this->maxRetries = $maxRetries;
        $this->baseDelay = $baseDelay;
        $this->jitterFactor = $jitterFactor;
    }

    /**
     * Send a message with delivery tracking
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
        // Create or get existing delivery record
        if (!$this->deliveryRepository->deliveryExists($messageType, $messageId)) {
            $this->deliveryRepository->createDelivery(
                $messageType,
                $messageId,
                $recipient,
                'pending',
                $this->maxRetries
            );
        }

        // Update stage to sent
        $this->deliveryRepository->updateStage($messageType, $messageId, 'sent');

        // Attempt delivery
        $response = $this->transportUtility->send($recipient, $payload);
        $decodedResponse = json_decode($response, true);

        // Process response
        return $this->processDeliveryResponse(
            $messageType,
            $messageId,
            $recipient,
            $payload,
            $decodedResponse,
            $response
        );
    }

    /**
     * Process the delivery response and update tracking
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $recipient Recipient address
     * @param array $payload Original payload
     * @param array|null $response Decoded response
     * @param string $rawResponse Raw response string
     * @return array Processed result
     */
    private function processDeliveryResponse(
        string $messageType,
        string $messageId,
        string $recipient,
        array $payload,
        ?array $response,
        string $rawResponse
    ): array {
        // No response or connection failure
        if ($response === null || empty($rawResponse)) {
            return $this->handleDeliveryFailure(
                $messageType,
                $messageId,
                $recipient,
                $payload,
                'No response received from recipient'
            );
        }

        // Check for multi-stage acknowledgment
        $status = $response['status'] ?? null;

        switch ($status) {
            case 'received':
                // Stage 1: Message received
                $this->deliveryRepository->updateStage($messageType, $messageId, 'received', $rawResponse);
                return [
                    'success' => true,
                    'stage' => 'received',
                    'message' => 'Message received by recipient',
                    'response' => $response
                ];

            case 'inserted':
                // Stage 2: Stored in database
                $this->deliveryRepository->updateStage($messageType, $messageId, 'inserted', $rawResponse);
                return [
                    'success' => true,
                    'stage' => 'inserted',
                    'message' => 'Message stored in recipient database',
                    'response' => $response
                ];

            case 'forwarded':
                // Stage 3: Forwarded to next hop
                $this->deliveryRepository->updateStage($messageType, $messageId, 'forwarded', $rawResponse);
                return [
                    'success' => true,
                    'stage' => 'forwarded',
                    'message' => 'Message forwarded to next hop',
                    'response' => $response
                ];

            case 'accepted':
                // Final acceptance - mark completed
                $this->deliveryRepository->markCompleted($messageType, $messageId);
                return [
                    'success' => true,
                    'stage' => 'completed',
                    'message' => 'Message accepted by recipient',
                    'response' => $response
                ];

            case 'rejected':
                // Explicit rejection - don't retry
                $this->deliveryRepository->markFailed($messageType, $messageId, 'Rejected: ' . ($response['message'] ?? 'No reason'));
                return [
                    'success' => false,
                    'stage' => 'rejected',
                    'message' => $response['message'] ?? 'Message rejected by recipient',
                    'response' => $response,
                    'retry' => false
                ];

            default:
                // Unknown response - treat as failure, may retry
                return $this->handleDeliveryFailure(
                    $messageType,
                    $messageId,
                    $recipient,
                    $payload,
                    'Unknown response status: ' . ($status ?? 'null')
                );
        }
    }

    /**
     * Handle delivery failure with retry logic
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @param string $recipient Recipient address
     * @param array $payload Message payload
     * @param string $error Error description
     * @return array Failure result
     */
    private function handleDeliveryFailure(
        string $messageType,
        string $messageId,
        string $recipient,
        array $payload,
        string $error
    ): array {
        // Get current delivery record
        $delivery = $this->deliveryRepository->getByMessage($messageType, $messageId);
        $retryCount = $delivery ? (int) $delivery['retry_count'] : 0;

        // Check if we've exhausted retries
        if ($retryCount >= $this->maxRetries) {
            // Move to Dead Letter Queue
            $this->dlqRepository->addToQueue(
                $messageType,
                $messageId,
                $payload,
                $recipient,
                $retryCount,
                $error
            );

            $this->deliveryRepository->markFailed($messageType, $messageId, $error);

            if (class_exists('SecureLogger')) {
                SecureLogger::error("Message delivery exhausted all retries, moved to DLQ", [
                    'message_type' => $messageType,
                    'message_id' => $messageId,
                    'recipient' => $recipient,
                    'retry_count' => $retryCount,
                    'error' => $error
                ]);
            }

            return [
                'success' => false,
                'stage' => 'failed',
                'message' => 'Message delivery failed after ' . $retryCount . ' retries, moved to DLQ',
                'error' => $error,
                'retry' => false,
                'dlq' => true
            ];
        }

        // Calculate exponential backoff delay with jitter
        $delay = $this->calculateRetryDelay($retryCount);

        // Schedule retry
        $this->deliveryRepository->incrementRetry($messageType, $messageId, $delay, $error);

        if (class_exists('SecureLogger')) {
            SecureLogger::warning("Message delivery failed, scheduling retry", [
                'message_type' => $messageType,
                'message_id' => $messageId,
                'retry_count' => $retryCount + 1,
                'next_retry_delay' => $delay,
                'error' => $error
            ]);
        }

        return [
            'success' => false,
            'stage' => 'retry_scheduled',
            'message' => 'Delivery failed, retry scheduled',
            'error' => $error,
            'retry' => true,
            'retry_count' => $retryCount + 1,
            'retry_delay' => $delay
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

        // Cap at 5 minutes maximum
        return min($delay, 300);
    }

    /**
     * Process messages ready for retry
     *
     * @param int $limit Maximum messages to process
     * @return int Number of messages processed
     */
    public function processRetryQueue(int $limit = 10): int {
        $messages = $this->deliveryRepository->getMessagesForRetry($limit);
        $processed = 0;

        foreach ($messages as $delivery) {
            // Rebuild payload - this would need to be fetched from original source
            // For now, we'll just log and mark for manual intervention
            if (class_exists('SecureLogger')) {
                SecureLogger::info("Processing retry for message", [
                    'message_type' => $delivery['message_type'],
                    'message_id' => $delivery['message_id'],
                    'retry_count' => $delivery['retry_count']
                ]);
            }

            // Note: Actual retry would need the original payload
            // This is handled by the respective service (TransactionService, P2pService)
            $processed++;
        }

        return $processed;
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
            if (!$this->dlqRepository->existsByOriginalId($delivery['message_id'])) {
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

        // Mark as retrying
        $this->dlqRepository->markRetrying($dlqId);

        try {
            // Call the resend callback with the payload
            $result = $resendCallback($item['payload'], $item['recipient_address']);

            if ($result['success'] ?? false) {
                $this->dlqRepository->markResolved($dlqId);
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

            if (class_exists('SecureLogger')) {
                SecureLogger::logException($e, [
                    'context' => 'dlq_retry',
                    'dlq_id' => $dlqId
                ]);
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

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
            'timestamp' => microtime(true),
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
            if (class_exists('SecureLogger')) {
                SecureLogger::warning("Cannot update stage: delivery record not found", [
                    'message_type' => $messageType,
                    'message_id' => $messageId
                ]);
            }
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
                    'timestamp' => microtime(true),
                    'previous_stage' => $currentStage
                ])
            );

            if (class_exists('SecureLogger')) {
                SecureLogger::info("Delivery stage updated to 'inserted'", [
                    'message_type' => $messageType,
                    'message_id' => $messageId,
                    'previous_stage' => $currentStage
                ]);
            }

            $currentOrder = $insertedOrder;
        }

        // Mark as completed if requested and not already completed
        if ($markCompleted && $currentOrder < $completedOrder) {
            $this->deliveryRepository->markCompleted($messageType, $messageId);

            if (class_exists('SecureLogger')) {
                SecureLogger::info("Delivery marked as 'completed'", [
                    'message_type' => $messageType,
                    'message_id' => $messageId
                ]);
            }
        }

        return true;
    }
}
