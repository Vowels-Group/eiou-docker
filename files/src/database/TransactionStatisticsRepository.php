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
        // Sum of counts across live + archive so stats don't drop archived
        // rows. Archive missing (v9→v10 transitional) → live-only result.
        try {
            $live = $this->pdo->query("SELECT COUNT(*) FROM {$this->tableName}")->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve total transaction count (live)", $e);
            return 0;
        }
        $archive = 0;
        try {
            $archive = (int) $this->pdo->query("SELECT COUNT(*) FROM transactions_archive")->fetchColumn();
        } catch (PDOException $e) {
            // Archive missing — live count is correct on its own.
        }
        return (int) $live + $archive;
    }

    /**
     * Get transactions grouped by type with counts and totals
     *
     * @return array Array of ['type' => string, 'count' => int, 'total' => int]
     */
    public function getTypeStatistics(): array
    {
        // Per-type aggregates merged across live + archive. Each side
        // groups independently; merge sums by `type` key in PHP.
        $liveRows = $this->fetchTypeAggregate($this->tableName);
        if ($liveRows === null) {
            return [];
        }
        $archiveRows = $this->fetchTypeAggregate('transactions_archive') ?? [];

        $byType = [];
        foreach ([$liveRows, $archiveRows] as $rows) {
            foreach ($rows as $r) {
                $t = $r['type'];
                if (!isset($byType[$t])) {
                    $byType[$t] = ['count' => 0, 'whole' => 0, 'frac' => 0];
                }
                $byType[$t]['count'] += (int) $r['count'];
                $byType[$t]['whole'] += (int) $r['total_whole'];
                $byType[$t]['frac']  += (int) $r['total_frac'];
            }
        }

        return array_map(
            fn($type, $agg) => [
                'type'  => $type,
                'count' => $agg['count'],
                'total' => self::sumCarry($agg['whole'], $agg['frac']),
            ],
            array_keys($byType),
            array_values($byType)
        );
    }

    /**
     * Private helper: run the per-type aggregate against a single table.
     * Returns null on live-side failure (caller aborts), empty array on
     * archive-side failure (caller just skips the archive contribution).
     */
    private function fetchTypeAggregate(string $table): ?array
    {
        $sql = "SELECT type, COUNT(*) as count,
                    SUM(amount_whole) AS total_whole, SUM(amount_frac) AS total_frac
                FROM {$table} GROUP BY type";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if ($table === $this->tableName) {
                $this->logError("Failed to retrieve type statistics (live)", $e);
                return null;
            }
            return []; // archive missing — skip
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
        $live    = $this->fetchByTypeRow($this->tableName, $type);
        if ($live === null) {
            return [];
        }
        $archive = $this->fetchByTypeRow('transactions_archive', $type) ?? ['count' => 0, 'total_whole' => 0, 'total_frac' => 0];

        $count = (int) ($live['count'] ?? 0) + (int) ($archive['count'] ?? 0);
        $whole = (int) ($live['total_whole'] ?? 0) + (int) ($archive['total_whole'] ?? 0);
        $frac  = (int) ($live['total_frac']  ?? 0) + (int) ($archive['total_frac']  ?? 0);
        if ($count === 0) {
            return [];
        }
        return [
            'type'  => $type,
            'count' => $count,
            'total' => self::sumCarry($whole, $frac),
        ];
    }

    /**
     * Private helper: fetch the single aggregate row for a given type
     * against a single table. Returns null on failure (archive missing
     * etc.).
     */
    private function fetchByTypeRow(string $table, string $type): ?array
    {
        try {
            $sql = "SELECT type, COUNT(*) as count,
                        SUM(amount_whole) AS total_whole, SUM(amount_frac) AS total_frac
                    FROM {$table} WHERE type = :type";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':type' => $type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Get count of transactions for a specific type
     *
     * @param string $type Transaction type
     * @return int Count
     */
    public function getCountByType(string $type): int
    {
        $live = 0;
        $stmt = $this->execute(
            "SELECT COUNT(*) FROM {$this->tableName} WHERE type = :type",
            [':type' => $type]
        );
        if ($stmt) {
            $live = (int) ($stmt->fetchColumn() ?: 0);
        }
        $archive = 0;
        try {
            $archiveStmt = $this->pdo->prepare("SELECT COUNT(*) FROM transactions_archive WHERE type = :type");
            $archiveStmt->execute([':type' => $type]);
            $archive = (int) ($archiveStmt->fetchColumn() ?: 0);
        } catch (PDOException $e) {
            // Archive missing — live-only is correct.
        }
        return $live + $archive;
    }

    /**
     * Get comprehensive transaction statistics
     *
     * @return array Statistics with counts, totals, averages, unique counts
     */
    public function getOverallStatistics(): array
    {
        // Single aggregate against a UNION ALL of live + archive. The
        // `COUNT(DISTINCT sender_address)` and `COUNT(DISTINCT receiver_address)`
        // columns deduplicate naturally across the combined subquery —
        // something the two-query PHP-merge approach can't do cheaply.
        // Archive missing (v9→v10 transitional) → retry with live-only.
        $inner = "SELECT amount_whole, amount_frac, sender_address, receiver_address, status FROM {$this->tableName}
                  UNION ALL
                  SELECT amount_whole, amount_frac, sender_address, receiver_address, status FROM transactions_archive";
        $aggregate = "SELECT
                        COUNT(*) as total_count,
                        SUM(amount_whole) AS total_amount_whole, SUM(amount_frac) AS total_amount_frac,
                        COUNT(DISTINCT sender_address) as unique_senders,
                        COUNT(DISTINCT receiver_address) as unique_receivers,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
                      FROM ({INNER}) AS combined";

        $stmt = null;
        try {
            $stmt = $this->pdo->prepare(str_replace('{INNER}', $inner, $aggregate));
            $stmt->execute();
        } catch (PDOException $e) {
            // Fall back to live-only if the UNION fails (archive missing or similar).
            try {
                $fallbackInner = "SELECT amount_whole, amount_frac, sender_address, receiver_address, status FROM {$this->tableName}";
                $stmt = $this->pdo->prepare(str_replace('{INNER}', $fallbackInner, $aggregate));
                $stmt->execute();
            } catch (PDOException $e2) {
                $this->logError("Failed to retrieve overall statistics", $e2);
                return [];
            }
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
        // Sum live + archive (archive rows are all status='completed' by
        // construction, so only the 'completed' call returns nonzero from
        // archive; kept generic for consistency).
        $liveStmt = $this->execute(
            "SELECT COUNT(*) as count,
                    SUM(amount_whole) AS total_amount_whole, SUM(amount_frac) AS total_amount_frac
             FROM {$this->tableName} WHERE status = :status",
            [':status' => $status]
        );
        if (!$liveStmt) {
            return [];
        }
        $live = $liveStmt->fetch(PDO::FETCH_ASSOC) ?: ['count' => 0, 'total_amount_whole' => 0, 'total_amount_frac' => 0];

        $archive = ['count' => 0, 'total_amount_whole' => 0, 'total_amount_frac' => 0];
        try {
            $aStmt = $this->pdo->prepare(
                "SELECT COUNT(*) as count,
                        SUM(amount_whole) AS total_amount_whole, SUM(amount_frac) AS total_amount_frac
                 FROM transactions_archive WHERE status = :status"
            );
            $aStmt->execute([':status' => $status]);
            $archive = $aStmt->fetch(PDO::FETCH_ASSOC) ?: $archive;
        } catch (PDOException $e) {
            // Archive missing — live-only is correct.
        }

        $count = (int) $live['count'] + (int) $archive['count'];
        $whole = (int) ($live['total_amount_whole'] ?? 0) + (int) ($archive['total_amount_whole'] ?? 0);
        $frac  = (int) ($live['total_amount_frac']  ?? 0) + (int) ($archive['total_amount_frac']  ?? 0);

        // Re-assemble in the shape the original query produced, so the
        // caller's downstream code still reads $result['count'] /
        // $result['total_amount_*'].
        $result = [
            'count' => $count,
            'total_amount_whole' => $whole,
            'total_amount_frac'  => $frac,
        ];
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
