<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

namespace Eiou\Contracts;

/**
 * Message Delivery Service Interface
 *
 * Defines the contract for reliable message delivery with multi-stage acknowledgments,
 * exponential backoff retry, and dead letter queue integration.
 *
 * Implements the Transaction Reliability & Message Handling System.
 *
 * @package Eiou\Contracts
 */
interface MessageDeliveryServiceInterface
{
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
     * @return array Response containing:
     *               - 'success' (bool): Whether delivery succeeded
     *               - 'response' (array|null): Decoded response from recipient
     *               - 'raw' (string): Raw response string
     *               - 'tracking' (array): Full tracking result
     *               - 'messageId' (string): Message identifier
     *               - 'queued_for_retry' (bool): Whether queued for background retry
     *               - 'signing_data' (array|null): Signature and nonce data
     */
    public function sendMessage(
        string $messageType,
        string $address,
        array $payload,
        ?string $messageId = null,
        bool $async = false
    ): array;

    /**
     * Send a message with delivery tracking - non-blocking (async) version
     *
     * Attempts to deliver a message ONCE without blocking on retries.
     * If the first attempt fails, the message is stored for background retry
     * processing via processRetryQueue().
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
    ): array;

    /**
     * Send a message with delivery tracking and synchronous retry
     *
     * Attempts to deliver a message with automatic retries using exponential backoff.
     * Will retry up to maxRetries times before moving to the Dead Letter Queue.
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
    ): array;

    /**
     * Process messages ready for retry (asynchronous/background processing)
     *
     * Used for background processing of messages left in pending/sent state.
     *
     * @param int $limit Maximum messages to process (default: 10)
     * @return array Results containing:
     *               - 'processed' (int): Total messages processed
     *               - 'succeeded' (int): Successfully delivered
     *               - 'failed' (int): Failed after all retries
     *               - 'no_payload' (int): Skipped due to missing payload
     *               - 'details' (array): Per-message details
     */
    public function processRetryQueue(int $limit = 10): array;

    /**
     * Check if a message has exhausted all retries
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool True if retries are exhausted
     */
    public function hasExhaustedRetries(string $messageType, string $messageId): bool;

    /**
     * Check if a message delivery has failed (either exhausted or explicitly failed)
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return bool True if delivery has failed
     */
    public function hasDeliveryFailed(string $messageType, string $messageId): bool;

    /**
     * Get the current delivery status for a message
     *
     * @param string $messageType Type of message
     * @param string $messageId Message identifier
     * @return array|null Delivery status containing:
     *                    - 'stage' (string): Current delivery stage
     *                    - 'retry_count' (int): Number of retries attempted
     *                    - 'max_retries' (int): Maximum allowed retries
     *                    - 'last_error' (string|null): Last error message
     *                    - 'next_retry_at' (string|null): Next retry timestamp
     *                    - 'is_failed' (bool): Whether delivery failed
     *                    - 'is_completed' (bool): Whether delivery completed
     *                    - 'retries_exhausted' (bool): Whether retries are exhausted
     */
    public function getDeliveryStatus(string $messageType, string $messageId): ?array;

    /**
     * Process exhausted retries and move to DLQ
     *
     * @return int Number of messages moved to DLQ
     */
    public function processExhaustedRetries(): int;

    /**
     * Mark all deliveries matching a hash pattern as completed
     *
     * Used to mark all P2P broadcast deliveries as completed when the
     * transaction completes.
     *
     * @param string $messageType Type of message ('p2p' or 'rp2p')
     * @param string $hash The P2P hash to match
     * @return int Number of updated records
     */
    public function markCompletedByHash(string $messageType, string $hash): int;

    /**
     * Get delivery statistics
     *
     * @return array Statistics including:
     *               - 'total_count' (int): Total deliveries
     *               - 'completed_count' (int): Completed deliveries
     *               - 'failed_count' (int): Failed deliveries
     *               - 'success_rate' (float): Percentage of successful deliveries
     *               - 'failure_rate' (float): Percentage of failed deliveries
     */
    public function getDeliveryStatistics(): array;

    /**
     * Get DLQ alert information
     *
     * @param int $alertThreshold Number of pending items to trigger alert (default: 10)
     * @return array Alert information containing:
     *               - 'pending_count' (int): Number of pending DLQ items
     *               - 'alert_triggered' (bool): Whether alert threshold exceeded
     *               - 'threshold' (int): Alert threshold
     *               - 'statistics' (array): DLQ statistics
     */
    public function getDlqAlertStatus(int $alertThreshold = 10): array;

    /**
     * Retry a message from the DLQ
     *
     * @param int $dlqId DLQ item ID
     * @param callable $resendCallback Callback to resend the message
     * @return array Result containing:
     *               - 'success' (bool): Whether retry succeeded
     *               - 'message' (string): Success message if successful
     *               - 'error' (string): Error message if failed
     */
    public function retryFromDlq(int $dlqId, callable $resendCallback): array;

    /**
     * Build multi-stage acknowledgment response
     *
     * @param string $stage Acknowledgment stage
     * @param string|null $messageId Optional message ID
     * @param string|null $additionalInfo Additional info
     * @return string JSON response
     */
    public function buildAcknowledgment(
        string $stage,
        ?string $messageId = null,
        ?string $additionalInfo = null
    ): string;

    /**
     * Acknowledge message received
     *
     * @param string $messageId Message identifier
     * @return string JSON acknowledgment
     */
    public function acknowledgeReceived(string $messageId): string;

    /**
     * Acknowledge message inserted into database
     *
     * @param string $messageId Message identifier
     * @return string JSON acknowledgment
     */
    public function acknowledgeInserted(string $messageId): string;

    /**
     * Acknowledge message forwarded to next hop
     *
     * @param string $messageId Message identifier
     * @param string|null $nextHop Address of next hop
     * @return string JSON acknowledgment
     */
    public function acknowledgeForwarded(string $messageId, ?string $nextHop = null): string;

    /**
     * Cleanup old records
     *
     * @param int $deliveryDays Days to keep delivery records (default: 30)
     * @param int $dlqDays Days to keep resolved DLQ records (default: 90)
     * @return array Cleanup results containing:
     *               - 'delivery_deleted' (int): Number of delivery records deleted
     *               - 'dlq_deleted' (int): Number of DLQ records deleted
     */
    public function cleanup(int $deliveryDays = 30, int $dlqDays = 90): array;

    /**
     * Update delivery stage to 'forwarded' after successfully forwarding a message
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
    ): bool;

    /**
     * Mark a delivery as completed
     *
     * Marks a delivery as completed when the entire message flow
     * has finished successfully.
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Message identifier
     * @return bool Success status
     */
    public function markDeliveryCompleted(
        string $messageType,
        string $messageId
    ): bool;

    /**
     * Update delivery stage after local database operation
     *
     * Called after a message has been successfully stored locally.
     * Stage progression is enforced to prevent regression.
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact)
     * @param string $messageId Message identifier
     * @param bool $markCompleted Whether to mark as completed after inserting (default: false)
     * @return bool Success status
     */
    public function updateStageAfterLocalInsert(
        string $messageType,
        string $messageId,
        bool $markCompleted = false
    ): bool;
}
