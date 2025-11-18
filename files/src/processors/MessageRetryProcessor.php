<?php
/**
 * Message Retry Processor
 *
 * Background processor that handles message retries with exponential backoff.
 * Should be run as a cron job (every minute recommended).
 *
 * Usage: php /etc/eiou/src/processors/MessageRetryProcessor.php
 *
 * Copyright 2025
 */

require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/../services/MessageReliabilityService.php';

class MessageRetryProcessor {
    /**
     * @var MessageReliabilityService Reliability service
     */
    private MessageReliabilityService $reliabilityService;

    /**
     * Constructor
     */
    public function __construct() {
        $this->reliabilityService = new MessageReliabilityService();
    }

    /**
     * Process pending retries
     *
     * @return array Processing results
     */
    public function process(): array {
        $pendingMessages = $this->reliabilityService->getPendingRetries();
        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'moved_to_dlq' => 0
        ];

        foreach ($pendingMessages as $message) {
            $results['processed']++;

            try {
                // Attempt to retry the message
                $success = $this->retryMessage($message);

                if ($success) {
                    $results['succeeded']++;
                    $this->reliabilityService->markMessageInserted($message['message_hash']);
                } else {
                    // Check if max retries reached
                    if ($message['retry_count'] >= 4) { // 5 total attempts (0-4)
                        $this->reliabilityService->moveToDeadLetterQueue(
                            $message['message_hash'],
                            'Max retries exceeded'
                        );
                        $results['moved_to_dlq']++;
                    } else {
                        // Increment retry count
                        $backoffSeconds = $this->reliabilityService->incrementRetry($message['message_hash']);
                        $results['failed']++;
                    }
                }
            } catch (Exception $e) {
                error_log("Retry processor error: " . $e->getMessage());
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Retry sending a message
     *
     * @param array $message Message data
     * @return bool Success status
     */
    private function retryMessage(array $message): bool {
        // Parse message data
        $messageData = json_decode($message['message_data'], true);

        if (!$messageData) {
            error_log("Invalid message data for hash: " . $message['message_hash']);
            return false;
        }

        try {
            // Load transport utility
            require_once __DIR__ . '/../services/utilities/UtilityServiceContainer.php';
            require_once __DIR__ . '/../core/UserContext.php';

            $userContext = UserContext::getInstance();
            $utilityContainer = new UtilityServiceContainer($userContext);
            $transportUtility = $utilityContainer->getTransportUtility();

            // Attempt to send message
            $response = $transportUtility->sendMessage(
                $message['receiver_address'],
                $message['message_data']
            );

            // Check if send was successful
            if ($response && isset($response['success']) && $response['success']) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            error_log("Failed to retry message: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Run cleanup of old records
     *
     * @return int Number of cleaned records
     */
    public function cleanup(): int {
        return $this->reliabilityService->cleanOldRecords(30);
    }
}

// Run if executed directly
if (php_sapi_name() === 'cli') {
    $processor = new MessageRetryProcessor();

    // Process retries
    $results = $processor->process();

    echo "Message Retry Processor Results:\n";
    echo "  Processed: {$results['processed']}\n";
    echo "  Succeeded: {$results['succeeded']}\n";
    echo "  Failed: {$results['failed']}\n";
    echo "  Moved to DLQ: {$results['moved_to_dlq']}\n";

    // Run cleanup once per day (check if hour is 3 AM)
    if (date('H') === '03') {
        $cleaned = $processor->cleanup();
        echo "  Cleaned old records: {$cleaned}\n";
    }

    // Get statistics
    $stats = (new MessageReliabilityService())->getStatistics();
    echo "\nCurrent Statistics:\n";
    echo "  Success Rate: " . number_format($stats['success_rate'], 2) . "%\n";
    echo "  Dead Letter Queue: {$stats['dead_letter_count']}\n";
}
