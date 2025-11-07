<?php
# Copyright 2025

/**
 * Dead Letter Queue Service
 *
 * Handles messages that have failed all retry attempts.
 * Provides manual retry capability, failure analysis, and cleanup policies.
 *
 * Features:
 * - Store failed messages with full context
 * - Manual retry with tracking
 * - Failure pattern analysis
 * - Automatic cleanup of old resolved messages
 * - Alert on threshold exceeded
 *
 * @package Services
 */
class DeadLetterQueueService {
    /**
     * @var DeadLetterQueueRepository DLQ repository instance
     */
    private DeadLetterQueueRepository $dlqRepository;

    /**
     * @var MessageService Message service for retry attempts
     */
    private MessageService $messageService;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var SecureLogger Logger instance
     */
    private SecureLogger $logger;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

    /**
     * @var int Threshold for DLQ alert (number of failed messages)
     */
    private const ALERT_THRESHOLD = 100;

    /**
     * @var int Default retention period in days
     */
    private const DEFAULT_RETENTION_DAYS = 7;

    /**
     * Constructor
     *
     * @param DeadLetterQueueRepository $dlqRepository DLQ repository
     * @param MessageService $messageService Message service
     * @param UtilityServiceContainer $utilityContainer Utility container
     * @param SecureLogger $logger Logger instance
     * @param UserContext $currentUser Current user data
     */
    public function __construct(
        DeadLetterQueueRepository $dlqRepository,
        MessageService $messageService,
        UtilityServiceContainer $utilityContainer,
        SecureLogger $logger,
        UserContext $currentUser
    ) {
        $this->dlqRepository = $dlqRepository;
        $this->messageService = $messageService;
        $this->utilityContainer = $utilityContainer;
        $this->logger = $logger;
        $this->currentUser = $currentUser;
    }

    /**
     * Add a message to the dead letter queue
     *
     * @param array $messageData Original message data
     * @param string $failureReason Reason for failure
     * @param int $retryCount Number of retry attempts made
     * @param string|null $lastError Last error message
     * @return bool Success status
     */
    public function addMessage(
        array $messageData,
        string $failureReason,
        int $retryCount,
        ?string $lastError = null
    ): bool {
        // Prevent duplicate entries for same message
        if (isset($messageData['hash']) &&
            $this->dlqRepository->hasMessageByHash($messageData['hash'])) {
            $this->logger->log(
                'INFO',
                'Message already in DLQ',
                ['hash' => $messageData['hash']]
            );
            return false;
        }

        $result = $this->dlqRepository->addMessage(
            $messageData,
            $failureReason,
            $retryCount,
            $lastError
        );

        if ($result !== false) {
            $this->logger->log(
                'WARNING',
                'Message added to Dead Letter Queue',
                [
                    'dlq_id' => $result,
                    'message_type' => $messageData['typeMessage'] ?? 'unknown',
                    'sender' => $messageData['senderAddress'] ?? 'unknown',
                    'failure_reason' => $failureReason,
                    'retry_count' => $retryCount
                ]
            );

            // Check if we've exceeded the alert threshold
            $this->checkAlertThreshold();

            return true;
        }

        return false;
    }

    /**
     * Manually retry a message from the DLQ
     *
     * @param int $dlqId DLQ message ID
     * @return array Result with status and message
     */
    public function retryMessage(int $dlqId): array {
        $dlqMessage = $this->dlqRepository->getMessageById($dlqId);

        if (!$dlqMessage) {
            return [
                'success' => false,
                'message' => 'DLQ message not found'
            ];
        }

        // Decode original message
        $originalMessage = json_decode($dlqMessage['original_message'], true);

        if (!$originalMessage) {
            return [
                'success' => false,
                'message' => 'Failed to decode original message'
            ];
        }

        // Update status to retrying
        $this->dlqRepository->updateStatus($dlqId, 'retrying', 'Manual retry initiated');
        $this->dlqRepository->incrementManualRetryCount($dlqId);

        try {
            // Validate message structure
            if (!$this->messageService->validateMessageStructure(['message' => json_encode($originalMessage)])) {
                throw new Exception('Invalid message structure');
            }

            // Attempt to process the message
            $this->messageService->handleMessageRequest(['message' => json_encode($originalMessage)]);

            // If successful, mark as resolved
            $this->dlqRepository->updateStatus(
                $dlqId,
                'resolved',
                'Manual retry successful'
            );

            $this->logger->log(
                'INFO',
                'DLQ message retry successful',
                ['dlq_id' => $dlqId]
            );

            return [
                'success' => true,
                'message' => 'Message retry successful'
            ];

        } catch (Exception $e) {
            // Retry failed, mark back as failed
            $this->dlqRepository->updateStatus(
                $dlqId,
                'failed',
                'Manual retry failed: ' . $e->getMessage()
            );

            $this->logger->log(
                'ERROR',
                'DLQ message retry failed',
                [
                    'dlq_id' => $dlqId,
                    'error' => $e->getMessage()
                ]
            );

            return [
                'success' => false,
                'message' => 'Retry failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Bulk retry messages by criteria
     *
     * @param string|null $failureReason Filter by failure reason
     * @param string|null $messageType Filter by message type
     * @param int $limit Maximum number of messages to retry
     * @return array Results summary
     */
    public function bulkRetry(
        ?string $failureReason = null,
        ?string $messageType = null,
        int $limit = 10
    ): array {
        $messages = $this->dlqRepository->getMessages('failed', $messageType, $limit, 0);

        if ($failureReason !== null) {
            $messages = array_filter($messages, function($msg) use ($failureReason) {
                return $msg['failure_reason'] === $failureReason;
            });
        }

        $results = [
            'total' => count($messages),
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];

        foreach ($messages as $message) {
            $result = $this->retryMessage($message['id']);

            if ($result['success']) {
                $results['successful']++;
            } else {
                $results['failed']++;
            }

            $results['details'][] = [
                'dlq_id' => $message['id'],
                'success' => $result['success'],
                'message' => $result['message']
            ];
        }

        $this->logger->log(
            'INFO',
            'Bulk DLQ retry completed',
            $results
        );

        return $results;
    }

    /**
     * Analyze failure patterns in the DLQ
     *
     * @return array Analysis results
     */
    public function analyzeFailurePatterns(): array {
        $stats = $this->dlqRepository->getStatistics();

        $analysis = [
            'summary' => [
                'total_failed' => $stats['by_status']['failed'] ?? 0,
                'total_resolved' => $stats['by_status']['resolved'] ?? 0,
                'total_retrying' => $stats['by_status']['retrying'] ?? 0,
                'cleanup_eligible' => $stats['cleanup_eligible'] ?? 0
            ],
            'by_message_type' => $stats['by_type'] ?? [],
            'top_failure_reasons' => $stats['top_failures'] ?? [],
            'recommendations' => []
        ];

        // Generate recommendations based on patterns
        if (!empty($stats['top_failures'])) {
            foreach ($stats['top_failures'] as $failure) {
                if ($failure['count'] > 10) {
                    $analysis['recommendations'][] = [
                        'severity' => 'high',
                        'failure_reason' => $failure['failure_reason'],
                        'count' => $failure['count'],
                        'recommendation' => $this->getRecommendation($failure['failure_reason'])
                    ];
                }
            }
        }

        // Check if alert threshold is exceeded
        if ($analysis['summary']['total_failed'] > self::ALERT_THRESHOLD) {
            $analysis['alert'] = [
                'triggered' => true,
                'message' => sprintf(
                    'DLQ threshold exceeded: %d failed messages (threshold: %d)',
                    $analysis['summary']['total_failed'],
                    self::ALERT_THRESHOLD
                )
            ];
        }

        return $analysis;
    }

    /**
     * Get recommendation based on failure reason
     *
     * @param string $failureReason Failure reason
     * @return string Recommendation text
     */
    private function getRecommendation(string $failureReason): string {
        $recommendations = [
            'max_retries_exceeded' => 'Check network connectivity and contact availability. Consider increasing retry limits.',
            'invalid_source' => 'Review contact validation logic. May indicate spam or unauthorized access attempts.',
            'timeout' => 'Network latency issues detected. Check Tor/HTTP transport configuration.',
            'invalid_message_structure' => 'Message schema validation failing. Check for protocol version mismatches.',
            'database_error' => 'Database connectivity or constraint issues. Review database health and schema.',
            'signature_verification_failed' => 'Cryptographic signature issues. Check key management and message integrity.',
            'insufficient_balance' => 'Transaction amount exceeds available balance. Review balance calculation logic.',
            'contact_not_found' => 'Recipient not in contacts. May need to implement contact discovery retry logic.'
        ];

        return $recommendations[$failureReason] ?? 'Review logs and message details for specific failure cause.';
    }

    /**
     * Clean up old resolved/archived messages
     *
     * @param int|null $retentionDays Number of days to retain (default: 7)
     * @return int Number of deleted messages
     */
    public function cleanup(?int $retentionDays = null): int {
        $days = $retentionDays ?? self::DEFAULT_RETENTION_DAYS;
        $deletedCount = $this->dlqRepository->cleanupOldMessages($days);

        if ($deletedCount > 0) {
            $this->logger->log(
                'INFO',
                'DLQ cleanup completed',
                [
                    'deleted_count' => $deletedCount,
                    'retention_days' => $days
                ]
            );
        }

        return $deletedCount;
    }

    /**
     * Check if DLQ has exceeded alert threshold
     *
     * @return bool True if threshold exceeded
     */
    private function checkAlertThreshold(): bool {
        $failedCount = $this->dlqRepository->countByStatus('failed');

        if ($failedCount > self::ALERT_THRESHOLD) {
            $this->logger->log(
                'CRITICAL',
                'Dead Letter Queue threshold exceeded',
                [
                    'failed_count' => $failedCount,
                    'threshold' => self::ALERT_THRESHOLD,
                    'recommendation' => 'Immediate investigation required. Review failure patterns and system health.'
                ]
            );

            return true;
        }

        return false;
    }

    /**
     * Get DLQ messages with pagination
     *
     * @param string|null $status Filter by status
     * @param string|null $messageType Filter by message type
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @return array Paginated results with metadata
     */
    public function getMessages(
        ?string $status = null,
        ?string $messageType = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $offset = ($page - 1) * $perPage;
        $messages = $this->dlqRepository->getMessages($status, $messageType, $perPage, $offset);

        // Get total count for pagination
        $totalCount = $this->dlqRepository->countByStatus($status ?? 'failed');

        return [
            'messages' => $messages,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $perPage)
            ]
        ];
    }

    /**
     * Archive a message
     *
     * @param int $dlqId DLQ message ID
     * @return bool Success status
     */
    public function archiveMessage(int $dlqId): bool {
        $result = $this->dlqRepository->archiveMessage($dlqId);

        if ($result > 0) {
            $this->logger->log(
                'INFO',
                'DLQ message archived',
                ['dlq_id' => $dlqId]
            );
            return true;
        }

        return false;
    }

    /**
     * Bulk archive old messages
     *
     * @param int $olderThanDays Archive messages older than this many days
     * @return int Number of archived messages
     */
    public function bulkArchive(int $olderThanDays = 30): int {
        $count = $this->dlqRepository->bulkArchive('failed', $olderThanDays);

        if ($count > 0) {
            $this->logger->log(
                'INFO',
                'DLQ bulk archive completed',
                [
                    'archived_count' => $count,
                    'older_than_days' => $olderThanDays
                ]
            );
        }

        return $count;
    }

    /**
     * Get detailed message information
     *
     * @param int $dlqId DLQ message ID
     * @return array|null Message details or null if not found
     */
    public function getMessageDetails(int $dlqId): ?array {
        $message = $this->dlqRepository->getMessageById($dlqId);

        if (!$message) {
            return null;
        }

        // Decode original message for display
        $message['decoded_message'] = json_decode($message['original_message'], true);

        return $message;
    }

    /**
     * Get DLQ statistics
     *
     * @return array Statistics summary
     */
    public function getStatistics(): array {
        return $this->dlqRepository->getStatistics();
    }
}
