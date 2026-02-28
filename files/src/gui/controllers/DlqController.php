<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Includes\Session;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Services\Utilities\TransportUtilityService;

/**
 * Dead Letter Queue Controller
 *
 * Handles AJAX POST requests for DLQ management actions:
 * - dlqRetry: Retry a failed message by re-sending to the original recipient
 * - dlqAbandon: Mark a DLQ item as abandoned (no further retry)
 */
class DlqController
{
    private const SUCCESS_STATUSES = [
        'received', 'inserted', 'forwarded', 'accepted',
        'acknowledged', 'completed', 'warning', 'updated', 'already_relayed'
    ];

    private Session $session;
    private DeadLetterQueueRepository $dlqRepository;
    private MessageDeliveryServiceInterface $messageDeliveryService;
    private TransportUtilityService $transportUtility;

    public function __construct(
        Session $session,
        DeadLetterQueueRepository $dlqRepository,
        MessageDeliveryServiceInterface $messageDeliveryService,
        TransportUtilityService $transportUtility
    ) {
        $this->session = $session;
        $this->dlqRepository = $dlqRepository;
        $this->messageDeliveryService = $messageDeliveryService;
        $this->transportUtility = $transportUtility;
    }

    public function routeAction(): void
    {
        $action = $_POST['action'] ?? '';

        if ($action === 'dlqRetry') {
            $this->handleRetry();
        } elseif ($action === 'dlqAbandon') {
            $this->handleAbandon();
        }
    }

    private function handleRetry(): void
    {
        header('Content-Type: application/json');

        if (!$this->session->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $dlqId = isset($_POST['dlq_id']) ? (int)$_POST['dlq_id'] : 0;
        if ($dlqId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid DLQ item ID']);
            exit;
        }

        // Verify item exists and is a retryable type before attempting
        $item = $this->dlqRepository->getById($dlqId);
        if (!$item) {
            echo json_encode(['success' => false, 'error' => 'DLQ item not found']);
            exit;
        }
        if (in_array($item['message_type'], ['p2p', 'rp2p'], true)) {
            echo json_encode([
                'success' => false,
                'error' => 'P2P and relay messages cannot be retried — they are time-sensitive routing messages that expire quickly. Abandon them instead.'
            ]);
            exit;
        }

        $successStatuses = self::SUCCESS_STATUSES;
        $transportUtility = $this->transportUtility;

        $result = $this->messageDeliveryService->retryFromDlq(
            $dlqId,
            function (array $payload, string $recipient) use ($transportUtility, $successStatuses): array {
                try {
                    $sendResult = $transportUtility->send($recipient, $payload, true);
                    $response = is_array($sendResult) ? ($sendResult['response'] ?? '') : $sendResult;
                    $decoded = json_decode($response, true);
                    $status = $decoded['status'] ?? null;

                    if ($status && in_array($status, $successStatuses, true)) {
                        return ['success' => true];
                    }

                    return ['success' => false, 'error' => 'Recipient returned: ' . ($status ?? 'no response')];
                } catch (\Exception $e) {
                    return ['success' => false, 'error' => $e->getMessage()];
                }
            }
        );

        echo json_encode($result);
        exit;
    }

    private function handleAbandon(): void
    {
        header('Content-Type: application/json');

        if (!$this->session->validateCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }

        $dlqId = isset($_POST['dlq_id']) ? (int)$_POST['dlq_id'] : 0;
        if ($dlqId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid DLQ item ID']);
            exit;
        }

        $success = $this->dlqRepository->markAbandoned($dlqId, 'Manually abandoned via GUI');

        echo json_encode([
            'success' => $success,
            'error' => $success ? null : 'Failed to abandon item'
        ]);
        exit;
    }
}
