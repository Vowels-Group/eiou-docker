#!/usr/bin/env php
<?php
# Copyright 2025

/**
 * Process Acknowledgment Retries
 *
 * Cron job to process pending message acknowledgment retries.
 * Runs periodically to retry failed message deliveries using exponential backoff.
 *
 * Issue: #139 - Transaction Reliability & Message Handling System
 *
 * Usage: Run this script via cron every 30-60 seconds
 * Crontab example:
 *   */1 * * * * /etc/eiou/src/cron/processAckRetries.php
 *
 * @package Cron
 */

// Bootstrap the application
require_once '/etc/eiou/src/core/bootstrap.php';

// Prevent multiple simultaneous executions
$lockFile = '/tmp/eiou_ack_retry.lock';
$lockHandle = fopen($lockFile, 'w');

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    error_log("[ACK_RETRY_CRON] Another instance is already running");
    exit(0);
}

try {
    // Get service container
    $container = ServiceContainer::getInstance();
    $ackService = $container->getAcknowledgmentService();

    error_log("[ACK_RETRY_CRON] Starting retry processing");

    // Get messages ready for retry
    $retryQueue = $ackService->getMessagesForRetry(100);

    if (empty($retryQueue)) {
        error_log("[ACK_RETRY_CRON] No messages to retry");
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(0);
    }

    error_log("[ACK_RETRY_CRON] Found " . count($retryQueue) . " messages to retry");

    $successCount = 0;
    $failureCount = 0;
    $dlqCount = 0;

    foreach ($retryQueue as $message) {
        try {
            $messageId = $message['message_id'];
            $retryCount = $message['retry_count'];
            $maxRetries = $message['max_retries'];

            error_log("[ACK_RETRY_CRON] Processing $messageId (retry $retryCount/$maxRetries)");

            // Check if max retries exceeded
            if ($retryCount >= $maxRetries) {
                error_log("[ACK_RETRY_CRON] Max retries exceeded for $messageId - moving to DLQ");
                $ackService->moveToDeadLetter($messageId, 'Max retries exceeded');
                $dlqCount++;
                continue;
            }

            // Execute retry
            $success = $ackService->executeRetry($messageId);

            if ($success) {
                $successCount++;
                error_log("[ACK_RETRY_CRON] Successfully retried $messageId");
            } else {
                $failureCount++;
                error_log("[ACK_RETRY_CRON] Failed to retry $messageId");
            }

        } catch (Exception $e) {
            $failureCount++;
            error_log("[ACK_RETRY_CRON] Exception while processing {$message['message_id']}: {$e->getMessage()}");
            error_log("[ACK_RETRY_CRON] Stack trace: {$e->getTraceAsString()}");
        }
    }

    error_log("[ACK_RETRY_CRON] Completed: $successCount success, $failureCount failures, $dlqCount moved to DLQ");

} catch (Exception $e) {
    error_log("[ACK_RETRY_CRON] Fatal error: {$e->getMessage()}");
    error_log("[ACK_RETRY_CRON] Stack trace: {$e->getTraceAsString()}");
} finally {
    // Release lock
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit(0);
