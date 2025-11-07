#!/usr/bin/env php
<?php
# Copyright 2025

/**
 * Retry Processor
 *
 * Background processor that handles scheduled message retries with exponential backoff.
 * This script should be run periodically (e.g., every minute via cron).
 *
 * Usage:
 *   php retryProcessor.php [--limit=N] [--once]
 *
 * Options:
 *   --limit=N   Process at most N messages (default: 100)
 *   --once      Run once and exit (default: continuous with sleep)
 *   --help      Show this help message
 *
 * @package Processors
 */

// Require core files
require_once '/etc/eiou/src/core/Constants.php';
require_once '/etc/eiou/src/core/UserContext.php';
require_once '/etc/eiou/src/database/pdo.php';
require_once '/etc/eiou/src/services/ServiceContainer.php';
require_once '/etc/eiou/src/utils/SecureLogger.php';

/**
 * Parse command line arguments
 *
 * @param array $argv Command line arguments
 * @return array Parsed options
 */
function parseArguments(array $argv): array {
    $options = [
        'limit' => 100,
        'once' => false,
        'help' => false
    ];

    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--once') {
            $options['once'] = true;
        } elseif (preg_match('/^--limit=(\d+)$/', $arg, $matches)) {
            $options['limit'] = (int)$matches[1];
        }
    }

    return $options;
}

/**
 * Show help message
 */
function showHelp(): void {
    echo <<<HELP
Retry Processor - Handle scheduled message retries with exponential backoff

Usage:
  php retryProcessor.php [OPTIONS]

Options:
  --limit=N   Process at most N messages per iteration (default: 100)
  --once      Run once and exit (default: continuous loop)
  --help      Show this help message

Examples:
  php retryProcessor.php --limit=50 --once
  php retryProcessor.php

Cron example (run every minute):
  * * * * * /usr/bin/php /etc/eiou/src/processors/retryProcessor.php --once >> /var/log/eiou/retry.log 2>&1

HELP;
}

/**
 * Process retry queue
 *
 * @param ServiceContainer $container Service container
 * @param int $limit Maximum messages to process
 * @return array Processing statistics
 */
function processRetryQueue(ServiceContainer $container, int $limit): array {
    $retryService = $container->getRetryService();
    $transactionService = $container->getTransactionService();
    $p2pService = $container->getP2pService();
    $rp2pService = $container->getRp2pService();
    $transportUtility = $container->getUtilityContainer()->getTransportUtility();
    $logger = $container->getLogger();

    $stats = [
        'processed' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    // Get messages ready for retry
    $messages = $retryService->getMessagesReadyForRetry($limit);

    $logger->info("Retry processor: Found {count} messages ready for retry", [
        'count' => count($messages)
    ]);

    foreach ($messages as $message) {
        $stats['processed']++;
        $messageId = $message['message_id'];
        $messageType = $message['message_type'];
        $recipientAddress = $message['recipient_address'];
        $attemptNumber = $message['attempt_number'] + 1;

        try {
            $logger->info("Retrying message", [
                'message_id' => $messageId,
                'type' => $messageType,
                'attempt' => $attemptNumber,
                'recipient' => $recipientAddress
            ]);

            // Retrieve the original message payload
            $payload = null;
            switch ($messageType) {
                case 'transaction':
                    $txData = $transactionService->getByTxid($messageId);
                    if ($txData) {
                        // Rebuild transaction payload
                        require_once '/etc/eiou/src/schemas/payloads/TransactionPayload.php';
                        $transactionPayload = new TransactionPayload(
                            $container->getCurrentUser(),
                            $container->getUtilityContainer()
                        );
                        $payload = $transactionPayload->buildFromDatabase($txData);
                    }
                    break;

                case 'p2p':
                    $p2pData = $p2pService->getByHash($messageId);
                    if ($p2pData) {
                        // Rebuild P2P payload
                        require_once '/etc/eiou/src/schemas/payloads/P2pPayload.php';
                        $p2pPayload = new P2pPayload(
                            $container->getCurrentUser(),
                            $container->getUtilityContainer()
                        );
                        $payload = $p2pPayload->buildFromDatabase($p2pData);
                    }
                    break;

                case 'rp2p':
                    $rp2pData = $rp2pService->getByHash($messageId);
                    if ($rp2pData) {
                        // Rebuild RP2P payload
                        require_once '/etc/eiou/src/schemas/payloads/Rp2pPayload.php';
                        $rp2pPayload = new Rp2pPayload(
                            $container->getCurrentUser(),
                            $container->getUtilityContainer()
                        );
                        $payload = $rp2pPayload->build($rp2pData);
                    }
                    break;
            }

            if (!$payload) {
                $logger->warning("Could not rebuild payload for retry", [
                    'message_id' => $messageId,
                    'type' => $messageType
                ]);
                $stats['skipped']++;
                continue;
            }

            // Attempt to send the message (with retry disabled to avoid recursion)
            $response = $transportUtility->send($recipientAddress, $payload, false);

            // Check if the response indicates success
            $responseData = json_decode($response, true);
            $isSuccess = $responseData && isset($responseData['status'])
                && in_array($responseData['status'], ['accepted', 'completed', 'found']);

            if ($isSuccess) {
                // Mark as completed
                $retryService->markCompleted($messageId);
                $stats['succeeded']++;

                $logger->info("Retry succeeded", [
                    'message_id' => $messageId,
                    'attempt' => $attemptNumber
                ]);
            } else {
                // Check if we should retry again
                if ($retryService->shouldRetry($messageId)) {
                    // Schedule next retry
                    $retryService->recordRetryAttempt(
                        $messageId,
                        $messageType,
                        $recipientAddress,
                        $attemptNumber,
                        "Retry failed: " . ($responseData['message'] ?? 'Unknown error'),
                        'scheduled'
                    );

                    $logger->warning("Retry failed, rescheduling", [
                        'message_id' => $messageId,
                        'attempt' => $attemptNumber
                    ]);
                } else {
                    // Max retries exceeded
                    $retryService->markFailed($messageId, "Max retries exceeded");
                    $stats['failed']++;

                    $logger->error("Retry permanently failed", [
                        'message_id' => $messageId,
                        'attempt' => $attemptNumber
                    ]);
                }
            }

        } catch (Exception $e) {
            // Handle retry failure
            $logger->error("Exception during retry", [
                'message_id' => $messageId,
                'attempt' => $attemptNumber,
                'error' => $e->getMessage()
            ]);

            // Check if we should retry again
            if ($retryService->shouldRetry($messageId)) {
                $retryService->recordRetryAttempt(
                    $messageId,
                    $messageType,
                    $recipientAddress,
                    $attemptNumber,
                    $e->getMessage(),
                    'scheduled'
                );
            } else {
                $retryService->markFailed($messageId, "Max retries exceeded: " . $e->getMessage());
                $stats['failed']++;
            }
        }
    }

    return $stats;
}

/**
 * Main execution
 */
function main(): void {
    global $argv;

    // Parse command line arguments
    $options = parseArguments($argv);

    if ($options['help']) {
        showHelp();
        exit(0);
    }

    // Initialize logger
    $logger = new SecureLogger();
    $logger->init(Constants::LOG_FILE_APP, Constants::LOG_LEVEL);

    $logger->info("Retry processor started", [
        'limit' => $options['limit'],
        'mode' => $options['once'] ? 'single' : 'continuous'
    ]);

    // Get service container
    $container = ServiceContainer::getInstance();

    if ($options['once']) {
        // Run once and exit
        $stats = processRetryQueue($container, $options['limit']);

        $logger->info("Retry processor completed", $stats);

        echo sprintf(
            "Processed: %d, Succeeded: %d, Failed: %d, Skipped: %d\n",
            $stats['processed'],
            $stats['succeeded'],
            $stats['failed'],
            $stats['skipped']
        );

        exit(0);
    } else {
        // Continuous mode with sleep
        echo "Retry processor running in continuous mode. Press Ctrl+C to stop.\n";

        while (true) {
            try {
                $stats = processRetryQueue($container, $options['limit']);

                $logger->info("Retry processor iteration completed", $stats);

                // Sleep for 10 seconds before next iteration
                sleep(10);
            } catch (Exception $e) {
                $logger->error("Error in retry processor loop", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                // Sleep longer on error to avoid tight error loop
                sleep(30);
            }
        }
    }
}

// Run main function
main();
