<?php
# Copyright 2025

/**
 * Acknowledgment Service
 *
 * Implements the 3-stage message acknowledgment protocol for transaction reliability.
 * Manages message acknowledgment tracking, retry logic, and dead letter queue.
 *
 * Issue: #139 - Transaction Reliability & Message Handling System
 *
 * Three Stages:
 * 1. ACK_RECEIVED: Message arrived at recipient node
 * 2. ACK_PROCESSED: Message validated and stored in database
 * 3. ACK_CONFIRMED: Message forwarded or final destination processed
 *
 * @package Services
 */

class AcknowledgmentService {
    /**
     * @var AcknowledgmentRepository Repository for ACK data
     */
    private AcknowledgmentRepository $ackRepository;

    /**
     * @var UserContext Current user context
     */
    private UserContext $currentUser;

    /**
     * @var UtilityServiceContainer Utility services container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var TransportUtilityService Transport utility service
     */
    private TransportUtilityService $transportUtility;

    /**
     * Constructor
     *
     * @param AcknowledgmentRepository $ackRepository ACK repository
     * @param UserContext $currentUser Current user context
     * @param UtilityServiceContainer $utilityContainer Utility container
     */
    public function __construct(
        AcknowledgmentRepository $ackRepository,
        UserContext $currentUser,
        UtilityServiceContainer $utilityContainer
    ) {
        $this->ackRepository = $ackRepository;
        $this->currentUser = $currentUser;
        $this->utilityContainer = $utilityContainer;
        $this->transportUtility = $this->utilityContainer->getTransportUtility();
    }

    /**
     * Generate deterministic message ID from message content
     *
     * @param array $message Message data
     * @return string Message ID (SHA-256 hash)
     */
    public function generateMessageId(array $message): string {
        $canonical = [
            'sender' => $message['senderAddress'] ?? '',
            'receiver' => $message['receiverAddress'] ?? $this->currentUser->getCurrentAddress(),
            'type' => $message['typeMessage'] ?? '',
            'timestamp' => $message['timestamp'] ?? time(),
            'content_hash' => hash('sha256', json_encode($message))
        ];

        // Sort keys for deterministic output
        ksort($canonical);

        return hash('sha256', json_encode($canonical));
    }

    /**
     * Generate message hash (SHA-256 of full message)
     *
     * @param array $message Message data
     * @return string Message hash
     */
    private function generateMessageHash(array $message): string {
        return hash('sha256', json_encode($message));
    }

    /**
     * Record Stage 1: Message Received
     *
     * @param string $messageId Message ID
     * @param array $metadata Message metadata
     * @return bool Success status
     */
    public function recordReceived(string $messageId, array $metadata): bool {
        // Check if already exists (duplicate prevention)
        if ($this->ackRepository->isDuplicate($messageId)) {
            error_log("[AcknowledgmentService] Duplicate message detected: $messageId");
            return false;
        }

        $data = [
            'message_id' => $messageId,
            'message_hash' => $metadata['message_hash'] ?? '',
            'message_type' => $metadata['type'] ?? 'transaction',
            'sender_address' => $metadata['sender'] ?? '',
            'receiver_address' => $this->currentUser->getCurrentAddress(),
            'stage' => 'received',
            'received_at' => date('Y-m-d H:i:s'),
            'max_retries' => $metadata['max_retries'] ?? 5
        ];

        $result = $this->ackRepository->create($data);

        if ($result) {
            error_log("[AcknowledgmentService] ACK_RECEIVED recorded for: $messageId");
            return true;
        }

        return false;
    }

    /**
     * Record Stage 2: Message Processed (validated and stored)
     *
     * @param string $messageId Message ID
     * @param array $metadata Processing metadata (e.g., txid, db reference)
     * @return bool Success status
     */
    public function recordProcessed(string $messageId, array $metadata): bool {
        $updateData = [];

        if (isset($metadata['related_txid'])) {
            $updateData['related_txid'] = $metadata['related_txid'];
        }

        if (isset($metadata['related_p2p_hash'])) {
            $updateData['related_p2p_hash'] = $metadata['related_p2p_hash'];
        }

        $success = $this->ackRepository->updateStage($messageId, 'processed', $updateData);

        if ($success) {
            error_log("[AcknowledgmentService] ACK_PROCESSED recorded for: $messageId");
        }

        return $success;
    }

    /**
     * Record Stage 3: Message Confirmed (forwarded or final processing complete)
     *
     * @param string $messageId Message ID
     * @param array $metadata Confirmation metadata
     * @return bool Success status
     */
    public function recordConfirmed(string $messageId, array $metadata): bool {
        $success = $this->ackRepository->updateStage($messageId, 'confirmed', $metadata);

        if ($success) {
            error_log("[AcknowledgmentService] ACK_CONFIRMED recorded for: $messageId");
        }

        return $success;
    }

    /**
     * Record message failure
     *
     * @param string $messageId Message ID
     * @param string $reason Failure reason
     * @return bool Success status
     */
    public function recordFailed(string $messageId, string $reason): bool {
        $success = $this->ackRepository->updateStage($messageId, 'failed', [
            'failure_reason' => $reason
        ]);

        if ($success) {
            error_log("[AcknowledgmentService] ACK_FAILED recorded for: $messageId - Reason: $reason");
        }

        return $success;
    }

    /**
     * Build ACK_RECEIVED response
     *
     * @param array $message Original message
     * @return string JSON response
     */
    public function buildReceivedAck(array $message): string {
        $messageId = $this->generateMessageId($message);

        return json_encode([
            'status' => 'acknowledged',
            'ack_stage' => 'received',
            'message_id' => $messageId,
            'receiver' => $this->currentUser->getCurrentAddress(),
            'timestamp' => time()
        ]);
    }

    /**
     * Build ACK_PROCESSED response
     *
     * @param array $message Original message
     * @param string $dbReference Database reference (txid or hash)
     * @return string JSON response
     */
    public function buildProcessedAck(array $message, string $dbReference): string {
        $messageId = $this->generateMessageId($message);

        return json_encode([
            'status' => 'acknowledged',
            'ack_stage' => 'processed',
            'message_id' => $messageId,
            'db_reference' => $dbReference,
            'receiver' => $this->currentUser->getCurrentAddress(),
            'timestamp' => time()
        ]);
    }

    /**
     * Build ACK_CONFIRMED response
     *
     * @param array $message Original message
     * @param array $confirmation Confirmation details
     * @return string JSON response
     */
    public function buildConfirmedAck(array $message, array $confirmation): string {
        $messageId = $this->generateMessageId($message);

        return json_encode([
            'status' => 'acknowledged',
            'ack_stage' => 'confirmed',
            'message_id' => $messageId,
            'confirmation' => $confirmation,
            'receiver' => $this->currentUser->getCurrentAddress(),
            'timestamp' => time()
        ]);
    }

    /**
     * Validate ACK response from remote node
     *
     * @param array $ackResponse ACK response data
     * @return bool True if valid ACK
     */
    public function validateAckResponse(array $ackResponse): bool {
        if (!isset($ackResponse['status']) || $ackResponse['status'] !== 'acknowledged') {
            return false;
        }

        if (!isset($ackResponse['ack_stage']) ||
            !in_array($ackResponse['ack_stage'], ['received', 'processed', 'confirmed'])) {
            return false;
        }

        if (!isset($ackResponse['message_id'])) {
            return false;
        }

        return true;
    }

    /**
     * Check if ACK is expected for this message
     *
     * @param string $messageId Message ID
     * @return bool True if ACK tracking exists
     */
    public function isAckExpected(string $messageId): bool {
        $record = $this->ackRepository->getByMessageId($messageId);
        return $record !== null;
    }

    /**
     * Schedule message retry
     *
     * @param string $messageId Message ID
     * @return bool Success status
     */
    public function scheduleRetry(string $messageId): bool {
        $success = $this->ackRepository->incrementRetryCount($messageId);

        if ($success) {
            error_log("[AcknowledgmentService] Retry scheduled for: $messageId");
        }

        return $success;
    }

    /**
     * Get messages ready for retry
     *
     * @param int $limit Maximum number of messages
     * @return array Array of messages to retry
     */
    public function getMessagesForRetry(int $limit = 100): array {
        return $this->ackRepository->getRetryQueue($limit);
    }

    /**
     * Execute retry for a message
     *
     * @param string $messageId Message ID
     * @return bool Success status
     */
    public function executeRetry(string $messageId): bool {
        $record = $this->ackRepository->getByMessageId($messageId);

        if (!$record) {
            error_log("[AcknowledgmentService] Cannot retry - message not found: $messageId");
            return false;
        }

        // Check if max retries exceeded
        if ($record['retry_count'] >= $record['max_retries']) {
            error_log("[AcknowledgmentService] Max retries exceeded for: $messageId");
            $this->moveToDeadLetter($messageId, 'Max retries exceeded');
            return false;
        }

        try {
            // Attempt to resend based on current stage
            // This is a placeholder - actual implementation depends on message type
            error_log("[AcknowledgmentService] Executing retry #{$record['retry_count']} for: $messageId");

            // Schedule next retry if this one fails
            $this->scheduleRetry($messageId);

            return true;
        } catch (Exception $e) {
            error_log("[AcknowledgmentService] Retry failed for $messageId: {$e->getMessage()}");
            $this->scheduleRetry($messageId);
            return false;
        }
    }

    /**
     * Move message to dead letter queue
     *
     * @param string $messageId Message ID
     * @param string $reason Failure reason
     * @return bool Success status
     */
    public function moveToDeadLetter(string $messageId, string $reason): bool {
        $success = $this->ackRepository->markAsDeadLetter($messageId, $reason);

        if ($success) {
            error_log("[AcknowledgmentService] Moved to DLQ: $messageId - Reason: $reason");
        }

        return $success;
    }

    /**
     * Get dead letter queue messages
     *
     * @param int $limit Maximum number of messages (0 = no limit)
     * @return array Array of dead letter messages
     */
    public function getDeadLetterMessages(int $limit = 0): array {
        return $this->ackRepository->getDeadLetterQueue($limit);
    }

    /**
     * Reprocess message from dead letter queue
     *
     * @param string $messageId Message ID
     * @return bool Success status
     */
    public function reprocessDeadLetter(string $messageId): bool {
        $success = $this->ackRepository->reprocessDeadLetter($messageId);

        if ($success) {
            error_log("[AcknowledgmentService] Reprocessing from DLQ: $messageId");
        }

        return $success;
    }

    /**
     * Get delivery statistics
     *
     * @param int $hoursBack Number of hours to look back (default: 24)
     * @return array Delivery statistics
     */
    public function getDeliveryStats(int $hoursBack = 24): array {
        return $this->ackRepository->getDeliveryStats($hoursBack);
    }

    /**
     * Get retry statistics
     *
     * @param int $hoursBack Number of hours to look back (default: 24)
     * @return array Retry statistics
     */
    public function getRetryStats(int $hoursBack = 24): array {
        return $this->ackRepository->getRetryStats($hoursBack);
    }

    /**
     * Get failure rate for given time period
     *
     * @param int $hoursBack Number of hours to look back (default: 24)
     * @return float Failure rate as percentage (0.0 to 100.0)
     */
    public function getFailureRate(int $hoursBack = 24): float {
        return $this->ackRepository->getFailureRate($hoursBack);
    }

    /**
     * Check if message is duplicate
     *
     * @param string $messageId Message ID
     * @return bool True if duplicate
     */
    public function isDuplicate(string $messageId): bool {
        return $this->ackRepository->isDuplicate($messageId);
    }

    /**
     * Get duplicate rejection response
     *
     * @param string $messageId Message ID
     * @return string JSON response
     */
    public function buildDuplicateRejection(string $messageId): string {
        $record = $this->ackRepository->getByMessageId($messageId);

        return json_encode([
            'status' => 'rejected',
            'reason' => 'duplicate',
            'message_id' => $messageId,
            'original_stage' => $record['stage'] ?? 'unknown',
            'original_timestamp' => $record['created_at'] ?? null
        ]);
    }

    /**
     * Clean up old acknowledged messages
     *
     * @param int $daysOld Number of days to keep (default: 30)
     * @return int Number of deleted records
     */
    public function cleanupOldRecords(int $daysOld = 30): int {
        return $this->ackRepository->cleanupOldRecords($daysOld);
    }
}
