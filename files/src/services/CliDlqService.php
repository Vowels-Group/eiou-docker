<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Database\TransactionRepository;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Cli\CliOutputManager;

/**
 * CLI Dead Letter Queue Service
 *
 * Handles CLI commands for DLQ management:
 * - Listing DLQ items with status filtering
 * - Retrying failed message delivery
 * - Abandoning undeliverable messages
 *
 * Extracted from CliService (ARCH-04) to reduce God Class complexity.
 */
class CliDlqService
{
    private TransportUtilityService $transportUtility;
    private TransactionRepository $transactionRepository;
    private ?DeadLetterQueueRepository $dlqRepository = null;

    public function __construct(
        TransportUtilityService $transportUtility,
        TransactionRepository $transactionRepository
    ) {
        $this->transportUtility = $transportUtility;
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * Set the DLQ repository (optional, for CLI dlq commands)
     */
    public function setDeadLetterQueueRepository(DeadLetterQueueRepository $dlqRepository): void
    {
        $this->dlqRepository = $dlqRepository;
    }

    /**
     * List DLQ items
     *
     * Usage: eiou dlq [list] [--status=pending|retrying|resolved|abandoned|all]
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function displayDlqItems(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->dlqRepository === null) {
            $output->error('DLQ repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        // Parse optional --status flag
        $statusFilter = null;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--status=')) {
                $statusFilter = substr($arg, 9);
            }
        }

        $allowedStatuses = ['pending', 'retrying', 'resolved', 'abandoned'];
        if ($statusFilter !== null && $statusFilter !== 'all' && !in_array($statusFilter, $allowedStatuses, true)) {
            $output->error("Invalid status filter. Use: pending, retrying, resolved, abandoned, or all", ErrorCodes::VALIDATION_ERROR);
            return;
        }

        $items = $this->dlqRepository->getItems(
            ($statusFilter === 'all' || $statusFilter === null) ? null : $statusFilter,
            Constants::DLQ_BATCH_SIZE
        );

        $stats = $this->dlqRepository->getStatistics();

        if ($output->isJsonMode()) {
            $output->success('DLQ items', [
                'items'      => $items,
                'statistics' => $stats,
                'filter'     => $statusFilter ?? 'all',
            ]);
            return;
        }

        echo "Dead Letter Queue\n";
        echo str_repeat("=", 60) . "\n";
        echo sprintf(
            "Pending: %d  Retrying: %d  Resolved: %d  Abandoned: %d\n\n",
            $stats['pending'] ?? 0,
            $stats['retrying'] ?? 0,
            $stats['resolved'] ?? 0,
            $stats['abandoned'] ?? 0
        );

        if (empty($items)) {
            echo "No items" . ($statusFilter ? " with status '{$statusFilter}'" : "") . ".\n";
            return;
        }

        echo sprintf("%-5s %-12s %-10s %-6s %-16s %s\n", "ID", "Type", "Status", "Tries", "Added", "Recipient");
        echo str_repeat("-", 80) . "\n";
        foreach ($items as $item) {
            $ts = strtotime($item['created_at'] ?? '');
            $date = $ts ? date('m-d H:i', $ts) : '—';
            $recipient = $item['recipient_address'] ?? '';
            if (strlen($recipient) > 38) { $recipient = substr($recipient, 0, 35) . '...'; }
            echo sprintf(
                "%-5d %-12s %-10s %-6d %-16s %s\n",
                $item['id'],
                $item['message_type'] ?? '?',
                $item['status'] ?? '?',
                $item['retry_count'] ?? 0,
                $date,
                $recipient
            );
            if (!empty($item['failure_reason'])) {
                $reason = $item['failure_reason'];
                if (strlen($reason) > 70) { $reason = substr($reason, 0, 67) . '...'; }
                echo "      Reason: {$reason}\n";
            }
        }
        echo "\nUse 'eiou dlq retry <id>' to retry or 'eiou dlq abandon <id>' to abandon.\n";
    }

    /**
     * Retry a DLQ item by ID
     *
     * Usage: eiou dlq retry <id>
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function retryDlqItem(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->dlqRepository === null) {
            $output->error('DLQ repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $id = isset($argv[3]) ? (int)$argv[3] : 0;
        if ($id <= 0) {
            $output->error('DLQ item ID is required. Usage: eiou dlq retry <id>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $item = $this->dlqRepository->getById($id);
        if (!$item) {
            $output->error("DLQ item #{$id} not found", ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if (!in_array($item['status'], ['pending', 'retrying'], true)) {
            $output->error("Item #{$id} has status '{$item['status']}' and cannot be retried", ErrorCodes::VALIDATION_ERROR);
            return;
        }

        if (in_array($item['message_type'], ['p2p', 'rp2p'], true)) {
            $output->error(
                "P2P and relay messages (type '{$item['message_type']}') cannot be retried — they are time-sensitive routing messages that expire in " . Constants::P2P_DEFAULT_EXPIRATION_SECONDS . "s and are stale by the time they reach the DLQ. Use 'eiou dlq abandon {$id}' instead.",
                ErrorCodes::VALIDATION_ERROR
            );
            return;
        }

        // For transaction type: refresh expires_at and reset cancelled status before retrying.
        // The rp2p route persists in relay nodes until their retention period (independent of P2P expiry),
        // so re-sending the signed payload to the first hop can still succeed.
        if ($item['message_type'] === 'transaction') {
            $txid = $this->extractTxidFromDlqMessageId($item['message_id']);
            if ($txid !== null) {
                $newExpiresAt = date('Y-m-d H:i:s', time() + Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS);
                $this->transactionRepository->setExpiresAt($txid, $newExpiresAt);
                $tx = $this->transactionRepository->getByTxid($txid);
                if ($tx && $tx['status'] === Constants::STATUS_CANCELLED) {
                    $this->transactionRepository->updateStatus($txid, Constants::STATUS_SENDING, true);
                }
            }
        }

        $this->dlqRepository->markRetrying($id);

        $successStatuses = ['received', 'inserted', 'forwarded', 'accepted', 'acknowledged', 'completed', 'warning', 'updated', 'already_relayed'];
        $recipient = $item['recipient_address'];
        $payload   = $item['payload'];

        try {
            $sendResult = $this->transportUtility->send($recipient, $payload, true);
            $response   = is_array($sendResult) ? ($sendResult['response'] ?? '') : $sendResult;
            $decoded    = json_decode($response, true);
            $status     = $decoded['status'] ?? null;

            if ($status && in_array($status, $successStatuses, true)) {
                $this->dlqRepository->markResolved($id);
                $output->success("DLQ item #{$id} successfully re-sent", [
                    'id'             => $id,
                    'message_type'   => $item['message_type'],
                    'recipient'      => $recipient,
                    'response_status' => $status,
                ], "Message delivered to {$recipient}");
            } else {
                $this->dlqRepository->returnToPending($id);
                $errDetail = $status ? "Recipient returned: {$status}" : 'No response from recipient';
                $output->error("Retry failed — item returned to pending. {$errDetail}", ErrorCodes::GENERAL_ERROR);
            }
        } catch (\Throwable $e) {
            $this->dlqRepository->returnToPending($id);
            $output->error('Retry failed: ' . $e->getMessage(), ErrorCodes::GENERAL_ERROR, 500);
        }
    }

    /**
     * Extract the txid from a transaction DLQ message_id.
     * Format: "send-{txid}-{microtime}" or "relay-{txid}-{microtime}"
     *
     * @param string $messageId DLQ message identifier
     * @return string|null Extracted txid, or null if not parseable
     */
    public function extractTxidFromDlqMessageId(string $messageId): ?string
    {
        foreach (['send-', 'relay-'] as $prefix) {
            if (strncmp($messageId, $prefix, strlen($prefix)) === 0) {
                $withoutPrefix = substr($messageId, strlen($prefix));
                $txid = preg_replace('/-\d+$/', '', $withoutPrefix);
                return ($txid !== '' && $txid !== $withoutPrefix) ? $txid : null;
            }
        }
        return null;
    }

    /**
     * Abandon a DLQ item by ID
     *
     * Usage: eiou dlq abandon <id>
     *
     * @param array $argv CLI arguments
     * @param CliOutputManager|null $output Output manager
     */
    public function abandonDlqItem(array $argv, ?CliOutputManager $output = null): void
    {
        $output = $output ?? CliOutputManager::getInstance();

        if ($this->dlqRepository === null) {
            $output->error('DLQ repository not available', ErrorCodes::GENERAL_ERROR);
            return;
        }

        $id = isset($argv[3]) ? (int)$argv[3] : 0;
        if ($id <= 0) {
            $output->error('DLQ item ID is required. Usage: eiou dlq abandon <id>', ErrorCodes::MISSING_ARGUMENT);
            return;
        }

        $item = $this->dlqRepository->getById($id);
        if (!$item) {
            $output->error("DLQ item #{$id} not found", ErrorCodes::NOT_FOUND, 404);
            return;
        }

        if ($item['status'] === 'abandoned') {
            $output->error("Item #{$id} is already abandoned", ErrorCodes::VALIDATION_ERROR);
            return;
        }

        $success = $this->dlqRepository->markAbandoned($id, 'Manually abandoned via CLI');

        if ($success) {
            $output->success("DLQ item #{$id} abandoned", [
                'id'           => $id,
                'message_type' => $item['message_type'],
                'recipient'    => $item['recipient_address'],
            ], "Item #{$id} marked as abandoned");
        } else {
            $output->error("Failed to abandon item #{$id}", ErrorCodes::GENERAL_ERROR);
        }
    }
}
