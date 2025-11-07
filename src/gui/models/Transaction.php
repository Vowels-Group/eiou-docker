<?php
/**
 * Transaction Model
 *
 * Represents transaction data and provides transaction-related operations
 *
 * Copyright 2025
 */

namespace Eiou\Gui\Models;

require_once __DIR__ . '/../../services/ServiceContainer.php';

use ServiceContainer;

/**
 * Transaction Model
 *
 * Handles transaction operations and data management
 */
class Transaction
{
    /**
     * @var ServiceContainer Service container for accessing services
     */
    private ServiceContainer $serviceContainer;

    /**
     * @var array Transaction data cache
     */
    private array $transactions = [];

    /**
     * Transaction types
     */
    public const TYPE_SEND = 'send';
    public const TYPE_RECEIVE = 'receive';
    public const TYPE_RELAY = 'relay';

    /**
     * Transaction status
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Constructor
     *
     * @param ServiceContainer $serviceContainer Service container
     */
    public function __construct(ServiceContainer $serviceContainer)
    {
        $this->serviceContainer = $serviceContainer;
    }

    /**
     * Get all transactions
     *
     * @param int $limit Maximum number of transactions to return
     * @param int $offset Offset for pagination
     * @return array List of transactions
     */
    public function getAllTransactions(int $limit = 100, int $offset = 0): array
    {
        $cacheKey = "all_{$limit}_{$offset}";

        if (isset($this->transactions[$cacheKey])) {
            return $this->transactions[$cacheKey];
        }

        $transactionService = $this->serviceContainer->getTransactionService();
        $transactions = $transactionService->getAllTransactions($limit, $offset);

        $this->transactions[$cacheKey] = $transactions;
        return $transactions;
    }

    /**
     * Get transaction by ID
     *
     * @param string $transactionId Transaction ID
     * @return array|null Transaction data or null if not found
     */
    public function getTransaction(string $transactionId): ?array
    {
        $transactionService = $this->serviceContainer->getTransactionService();
        return $transactionService->getTransaction($transactionId);
    }

    /**
     * Get recent transactions
     *
     * @param int $count Number of recent transactions to retrieve
     * @return array Recent transactions
     */
    public function getRecentTransactions(int $count = 10): array
    {
        return $this->getAllTransactions($count, 0);
    }

    /**
     * Get transactions by type
     *
     * @param string $type Transaction type (send, receive, relay)
     * @param int $limit Maximum number of transactions
     * @return array Filtered transactions
     */
    public function getTransactionsByType(string $type, int $limit = 100): array
    {
        $allTransactions = $this->getAllTransactions($limit);

        return array_filter($allTransactions, function ($transaction) use ($type) {
            return ($transaction['type'] ?? '') === $type;
        });
    }

    /**
     * Get sent transactions
     *
     * @param int $limit Maximum number of transactions
     * @return array Sent transactions
     */
    public function getSentTransactions(int $limit = 100): array
    {
        return $this->getTransactionsByType(self::TYPE_SEND, $limit);
    }

    /**
     * Get received transactions
     *
     * @param int $limit Maximum number of transactions
     * @return array Received transactions
     */
    public function getReceivedTransactions(int $limit = 100): array
    {
        return $this->getTransactionsByType(self::TYPE_RECEIVE, $limit);
    }

    /**
     * Get relay transactions
     *
     * @param int $limit Maximum number of transactions
     * @return array Relay transactions
     */
    public function getRelayTransactions(int $limit = 100): array
    {
        return $this->getTransactionsByType(self::TYPE_RELAY, $limit);
    }

    /**
     * Send eIOU transaction
     *
     * @param string $recipient Recipient address or contact name
     * @param float $amount Amount to send
     * @param string $currency Currency code
     * @return array Result with 'success' and 'message' keys
     */
    public function send(string $recipient, float $amount, string $currency = 'USD'): array
    {
        $argv = ['eiou', 'send', $recipient, $amount, $currency];
        $transactionService = $this->serviceContainer->getTransactionService();

        ob_start();
        try {
            $transactionService->sendEiou($argv);
            $output = ob_get_clean();

            $this->clearCache();

            $success = strpos($output, 'ERROR') === false && strpos($output, 'Failed') === false;

            return [
                'success' => $success,
                'message' => trim($output)
            ];
        } catch (\Exception $e) {
            ob_end_clean();
            return [
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get transactions for a specific contact
     *
     * @param string $contactAddress Contact address
     * @param int $limit Maximum number of transactions
     * @return array Transactions with the contact
     */
    public function getTransactionsForContact(string $contactAddress, int $limit = 100): array
    {
        $allTransactions = $this->getAllTransactions($limit);

        return array_filter($allTransactions, function ($transaction) use ($contactAddress) {
            $from = $transaction['from'] ?? '';
            $to = $transaction['to'] ?? '';

            return $from === $contactAddress || $to === $contactAddress;
        });
    }

    /**
     * Get transactions within date range
     *
     * @param int $startTimestamp Start timestamp
     * @param int $endTimestamp End timestamp
     * @param int $limit Maximum number of transactions
     * @return array Filtered transactions
     */
    public function getTransactionsByDateRange(
        int $startTimestamp,
        int $endTimestamp,
        int $limit = 100
    ): array {
        $allTransactions = $this->getAllTransactions($limit);

        return array_filter($allTransactions, function ($transaction) use ($startTimestamp, $endTimestamp) {
            $timestamp = $transaction['timestamp'] ?? 0;
            return $timestamp >= $startTimestamp && $timestamp <= $endTimestamp;
        });
    }

    /**
     * Get transactions by status
     *
     * @param string $status Transaction status
     * @param int $limit Maximum number of transactions
     * @return array Filtered transactions
     */
    public function getTransactionsByStatus(string $status, int $limit = 100): array
    {
        $allTransactions = $this->getAllTransactions($limit);

        return array_filter($allTransactions, function ($transaction) use ($status) {
            return ($transaction['status'] ?? '') === $status;
        });
    }

    /**
     * Get pending transactions
     *
     * @param int $limit Maximum number of transactions
     * @return array Pending transactions
     */
    public function getPendingTransactions(int $limit = 100): array
    {
        return $this->getTransactionsByStatus(self::STATUS_PENDING, $limit);
    }

    /**
     * Get transaction statistics
     *
     * @param string $currency Currency code (optional filter)
     * @return array Statistics including total sent, received, count
     */
    public function getStatistics(string $currency = ''): array
    {
        $allTransactions = $this->getAllTransactions(1000);

        $stats = [
            'total_sent' => 0.0,
            'total_received' => 0.0,
            'total_relay' => 0.0,
            'count_sent' => 0,
            'count_received' => 0,
            'count_relay' => 0,
            'total_count' => 0
        ];

        foreach ($allTransactions as $transaction) {
            $type = $transaction['type'] ?? '';
            $amount = $transaction['amount'] ?? 0.0;
            $txCurrency = $transaction['currency'] ?? 'USD';

            // Filter by currency if specified
            if ($currency && $txCurrency !== $currency) {
                continue;
            }

            $stats['total_count']++;

            switch ($type) {
                case self::TYPE_SEND:
                    $stats['total_sent'] += $amount;
                    $stats['count_sent']++;
                    break;

                case self::TYPE_RECEIVE:
                    $stats['total_received'] += $amount;
                    $stats['count_received']++;
                    break;

                case self::TYPE_RELAY:
                    $stats['total_relay'] += $amount;
                    $stats['count_relay']++;
                    break;
            }
        }

        return $stats;
    }

    /**
     * Calculate total earnings (received + relay fees)
     *
     * @param string $currency Currency code (optional filter)
     * @return float Total earnings
     */
    public function getTotalEarnings(string $currency = ''): float
    {
        $stats = $this->getStatistics($currency);
        return $stats['total_received'] + $stats['total_relay'];
    }

    /**
     * Search transactions by query
     *
     * @param string $query Search query (searches in addresses, amounts, etc.)
     * @param int $limit Maximum number of results
     * @return array Matching transactions
     */
    public function search(string $query, int $limit = 100): array
    {
        $allTransactions = $this->getAllTransactions($limit);
        $query = strtolower($query);

        return array_filter($allTransactions, function ($transaction) use ($query) {
            $from = strtolower($transaction['from'] ?? '');
            $to = strtolower($transaction['to'] ?? '');
            $amount = (string)($transaction['amount'] ?? '');
            $transactionId = strtolower($transaction['id'] ?? '');

            return str_contains($from, $query) ||
                   str_contains($to, $query) ||
                   str_contains($amount, $query) ||
                   str_contains($transactionId, $query);
        });
    }

    /**
     * Validate transaction data
     *
     * @param array $data Transaction data to validate
     * @return array Validation result with 'valid' and 'errors' keys
     */
    public function validate(array $data): array
    {
        require_once __DIR__ . '/../../utils/InputValidator.php';

        $errors = [];

        // Validate recipient
        if (isset($data['recipient'])) {
            $addressResult = \InputValidator::validateAddress($data['recipient']);
            $nameResult = \InputValidator::validateContactName($data['recipient']);

            if (!$addressResult['valid'] && !$nameResult['valid']) {
                $errors['recipient'] = 'Invalid recipient: must be a valid address or contact name';
            }
        } else {
            $errors['recipient'] = 'Recipient is required';
        }

        // Validate amount
        if (isset($data['amount']) && isset($data['currency'])) {
            $result = \InputValidator::validateAmount($data['amount'], $data['currency']);
            if (!$result['valid']) {
                $errors['amount'] = $result['error'];
            }
        } elseif (!isset($data['amount'])) {
            $errors['amount'] = 'Amount is required';
        }

        // Validate currency
        if (isset($data['currency'])) {
            $result = \InputValidator::validateCurrency($data['currency']);
            if (!$result['valid']) {
                $errors['currency'] = $result['error'];
            }
        } else {
            $errors['currency'] = 'Currency is required';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Clear transaction cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->transactions = [];
    }

    /**
     * Get transaction count
     *
     * @return int Total number of transactions
     */
    public function getCount(): int
    {
        $allTransactions = $this->getAllTransactions();
        return count($allTransactions);
    }

    /**
     * Format transaction for display
     *
     * @param array $transaction Transaction data
     * @return array Formatted transaction
     */
    public function formatForDisplay(array $transaction): array
    {
        return [
            'id' => $transaction['id'] ?? '',
            'type' => $transaction['type'] ?? '',
            'from' => $this->formatAddress($transaction['from'] ?? ''),
            'to' => $this->formatAddress($transaction['to'] ?? ''),
            'amount' => number_format($transaction['amount'] ?? 0, 2),
            'currency' => $transaction['currency'] ?? 'USD',
            'timestamp' => date('Y-m-d H:i:s', $transaction['timestamp'] ?? 0),
            'status' => $transaction['status'] ?? 'unknown'
        ];
    }

    /**
     * Format address for display (shorten if too long)
     *
     * @param string $address Full address
     * @return string Formatted address
     */
    private function formatAddress(string $address): string
    {
        if (strlen($address) > 20) {
            return substr($address, 0, 8) . '...' . substr($address, -8);
        }
        return $address;
    }
}
