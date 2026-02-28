<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Includes\Session;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\Constants;

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
    private TransactionRepository $transactionRepository;

    public function __construct(
        Session $session,
        DeadLetterQueueRepository $dlqRepository,
        MessageDeliveryServiceInterface $messageDeliveryService,
        TransportUtilityService $transportUtility,
        TransactionRepository $transactionRepository
    ) {
        $this->session = $session;
        $this->dlqRepository = $dlqRepository;
        $this->messageDeliveryService = $messageDeliveryService;
        $this->transportUtility = $transportUtility;
        $this->transactionRepository = $transactionRepository;
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

        // For transaction type: refresh expires_at and reset cancelled status before retrying.
        // The rp2p route persists in relay nodes until their retention period (independent of P2P expiry),
        // so re-sending the signed payload to the first hop can still succeed.
        if ($item['message_type'] === 'transaction') {
            $txid = $this->extractTxidFromMessageId($item['message_id']);
            if ($txid !== null) {
                $newExpiresAt = date('Y-m-d H:i:s', time() + Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS);
                $this->transactionRepository->setExpiresAt($txid, $newExpiresAt);
                // Reset cancelled-due-to-expiry transactions so they show as in-progress during retry
                $tx = $this->transactionRepository->getByTxid($txid);
                if ($tx && $tx['status'] === Constants::STATUS_CANCELLED) {
                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_SENDING, true);
                }
            }
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

    /**
     * Extract the txid from a transaction DLQ message_id.
     * Format: "send-{txid}-{microtime}" or "relay-{txid}-{microtime}"
     *
     * @param string $messageId DLQ message identifier
     * @return string|null Extracted txid, or null if not parseable
     */
    private function extractTxidFromMessageId(string $messageId): ?string
    {
        foreach (['send-', 'relay-'] as $prefix) {
            if (strncmp($messageId, $prefix, strlen($prefix)) === 0) {
                $withoutPrefix = substr($messageId, strlen($prefix));
                // Strip trailing -microtime (last numeric segment after final dash)
                $txid = preg_replace('/-\d+$/', '', $withoutPrefix);
                return ($txid !== '' && $txid !== $withoutPrefix) ? $txid : null;
            }
        }
        return null;
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
