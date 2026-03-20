<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\SplitAmount;
use PDO;
use PDOException;

/**
 * Transaction Statistics Repository
 *
 * Handles statistics and aggregation queries for transactions.
 * Extracted from TransactionRepository to reduce class size and
 * separate concerns (data access vs statistics/reporting).
 *
 * @package Database\Repository
 */
class TransactionStatisticsRepository extends AbstractRepository
{
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'tx_type', 'type', 'status', 'sender_address', 'sender_public_key',
        'sender_public_key_hash', 'receiver_address', 'receiver_public_key',
        'receiver_public_key_hash', 'amount_whole', 'amount_frac', 'currency', 'timestamp', 'txid',
        'previous_txid', 'sender_signature', 'recipient_signature', 'signature_nonce',
        'time', 'memo', 'description', 'initial_sender_address', 'end_recipient_address',
        'sending_started_at', 'recovery_count', 'needs_manual_review'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null)
    {
        parent::__construct($pdo);
        $this->tableName = 'transactions';
        $this->primaryKey = 'id';
    }

    /**
     * Get total count of all transactions
     *
     * @return int Total count
     */
    public function getTotalCount(): int
    {
        $query = "SELECT COUNT(*) FROM {$this->tableName}";
        $stmt = $this->pdo->prepare($query);
        try {
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve total transaction count", $e);
            return 0;
        }
    }

    /**
     * Get transactions grouped by type with counts and totals
     *
     * @return array Array of ['type' => string, 'count' => int, 'total' => int]
     */
    public function getTypeStatistics(): array
    {
        $query = "SELECT type, COUNT(*) as count,
                    SUM(amount_whole) AS total_whole, SUM(amount_frac) AS total_frac
                  FROM {$this->tableName} GROUP BY type";
        $stmt = $this->pdo->prepare($query);
        try {
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(function ($row) {
                return [
                    'type' => $row['type'],
                    'count' => $row['count'],
                    'total' => self::sumCarry((int)$row['total_whole'], (int)$row['total_frac']),
                ];
            }, $rows);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve type statistics", $e);
            return [];
        }
    }

    /**
     * Get statistics for a specific transaction type
     *
     * @param string $type Transaction type
     * @return array ['type' => string, 'count' => int, 'total' => int] or empty
     */
    public function getStatisticsByType(string $type): array
    {
        $query = "SELECT type, COUNT(*) as count,
                    SUM(amount_whole) AS total_whole, SUM(amount_frac) AS total_frac
                  FROM {$this->tableName} WHERE type = :type";
        $stmt = $this->execute($query, [':type' => $type]);

        if (!$stmt) {
            return [];
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return [];
        }
        return [
            'type' => $result['type'],
            'count' => $result['count'],
            'total' => self::sumCarry((int)($result['total_whole'] ?? 0), (int)($result['total_frac'] ?? 0)),
        ];
    }

    /**
     * Get count of transactions for a specific type
     *
     * @param string $type Transaction type
     * @return int Count
     */
    public function getCountByType(string $type): int
    {
        $query = "SELECT COUNT(*) FROM {$this->tableName} WHERE type = :type";
        $stmt = $this->execute($query, [':type' => $type]);

        if (!$stmt) {
            return 0;
        }

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Get comprehensive transaction statistics
     *
     * @return array Statistics with counts, totals, averages, unique counts
     */
    public function getOverallStatistics(): array
    {
        $query = "SELECT
                    COUNT(*) as total_count,
                    SUM(amount_whole) AS total_amount_whole, SUM(amount_frac) AS total_amount_frac,
                    COUNT(DISTINCT sender_address) as unique_senders,
                    COUNT(DISTINCT receiver_address) as unique_receivers,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
                  FROM {$this->tableName}";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return [];
        }
        return [
            'total_count' => $result['total_count'],
            'total_amount' => self::sumCarry((int)($result['total_amount_whole'] ?? 0), (int)($result['total_amount_frac'] ?? 0)),
            'unique_senders' => $result['unique_senders'],
            'unique_receivers' => $result['unique_receivers'],
            'completed_count' => $result['completed_count'],
            'pending_count' => $result['pending_count'],
        ];
    }

    /**
     * Get statistics filtered by status
     *
     * @param string $status Transaction status
     * @return array Statistics for the given status
     */
    public function getStatisticsByStatus(string $status): array
    {
        $query = "SELECT
                    COUNT(*) as count,
                    SUM(amount_whole) AS total_amount_whole, SUM(amount_frac) AS total_amount_frac
                  FROM {$this->tableName}
                  WHERE status = :status";

        $stmt = $this->execute($query, [':status' => $status]);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return [];
        }
        return [
            'count' => $result['count'],
            'total_amount' => self::sumCarry((int)($result['total_amount_whole'] ?? 0), (int)($result['total_amount_frac'] ?? 0)),
        ];
    }

    /**
     * Get statistics filtered by currency
     *
     * @param string $currency Currency code
     * @return array Statistics for the given currency
     */
    public function getStatisticsByCurrency(string $currency): array
    {
        $query = "SELECT
                    COUNT(*) as count,
                    SUM(amount_whole) AS total_amount_whole, SUM(amount_frac) AS total_amount_frac
                  FROM {$this->tableName}
                  WHERE currency = :currency";

        $stmt = $this->execute($query, [':currency' => $currency]);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return [];
        }
        return [
            'count' => $result['count'],
            'total_amount' => self::sumCarry((int)($result['total_amount_whole'] ?? 0), (int)($result['total_amount_frac'] ?? 0)),
        ];
    }

    /**
     * Get transaction counts grouped by date
     *
     * @param int $days Number of days to look back
     * @return array Daily counts
     */
    public function getDailyTransactionCounts(int $days = 30): array
    {
        $query = "SELECT DATE(timestamp) as date, COUNT(*) as count
                  FROM {$this->tableName}
                  WHERE timestamp >= DATE_SUB(NOW(), INTERVAL :days DAY)
                  GROUP BY DATE(timestamp)
                  ORDER BY date DESC";

        $stmt = $this->execute($query, [':days' => $days]);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Handle carry from summed fractional parts
     *
     * @param int $sumWhole Summed whole parts
     * @param int $sumFrac Summed fractional parts (may exceed FRAC_MODULUS)
     * @return SplitAmount Properly normalized SplitAmount
     */
    private static function sumCarry(int $sumWhole, int $sumFrac): SplitAmount {
        $carry = intdiv($sumFrac, SplitAmount::FRAC_MODULUS);
        $frac = $sumFrac % SplitAmount::FRAC_MODULUS;
        return new SplitAmount($sumWhole + $carry, $frac);
    }
}
