<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Delivery Metrics Repository
 *
 * Manages delivery metrics tracking for message delivery success/failure rates.
 * Supports the Transaction Reliability & Message Handling System.
 *
 * @package Database\Repository
 */
class DeliveryMetricsRepository extends AbstractRepository {
    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'period_start', 'period_end', 'message_type', 'total_sent',
        'total_delivered', 'total_failed', 'avg_delivery_time_ms',
        'avg_retry_count', 'created_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'delivery_metrics';
        $this->primaryKey = 'id';
    }

    /**
     * Record a metric entry for a time period
     *
     * @param string $messageType Type of message (transaction, p2p, rp2p, contact, all)
     * @param string $periodStart Start of the period (datetime string)
     * @param string $periodEnd End of the period (datetime string)
     * @param int $totalSent Total messages sent
     * @param int $totalDelivered Total messages delivered
     * @param int $totalFailed Total messages failed
     * @param int $avgDeliveryTimeMs Average delivery time in milliseconds
     * @param float $avgRetryCount Average retry count
     * @return int|false Insert ID or false on failure
     */
    public function recordMetric(
        string $messageType,
        string $periodStart,
        string $periodEnd,
        int $totalSent,
        int $totalDelivered,
        int $totalFailed,
        int $avgDeliveryTimeMs = 0,
        float $avgRetryCount = 0
    ) {
        $data = [
            'message_type' => $messageType,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_sent' => $totalSent,
            'total_delivered' => $totalDelivered,
            'total_failed' => $totalFailed,
            'avg_delivery_time_ms' => $avgDeliveryTimeMs,
            'avg_retry_count' => $avgRetryCount
        ];

        return $this->insert($data);
    }

    /**
     * Record a single delivery event for metrics aggregation
     *
     * @param string $messageType Type of message
     * @param bool $isDelivered Whether the message was delivered
     * @param int $deliveryTimeMs Delivery time in milliseconds
     * @param int $retryCount Number of retries
     * @return bool Success status
     */
    public function recordDeliveryEvent(
        string $messageType,
        bool $isDelivered,
        int $deliveryTimeMs = 0,
        int $retryCount = 0
    ): bool {
        // Get or create the current period record
        $periodStart = date('Y-m-d H:00:00'); // Hour-based periods
        $periodEnd = date('Y-m-d H:59:59');

        $query = "SELECT * FROM {$this->tableName}
                  WHERE message_type = :type
                    AND period_start = :start
                    AND period_end = :end
                  LIMIT 1";

        $stmt = $this->execute($query, [
            ':type' => $messageType,
            ':start' => $periodStart,
            ':end' => $periodEnd
        ]);

        $existing = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        if ($existing) {
            // Update existing record
            $newSent = (int) $existing['total_sent'] + 1;
            $newDelivered = (int) $existing['total_delivered'] + ($isDelivered ? 1 : 0);
            $newFailed = (int) $existing['total_failed'] + ($isDelivered ? 0 : 1);

            // Calculate new average delivery time
            $oldAvgTime = (int) $existing['avg_delivery_time_ms'];
            $oldCount = (int) $existing['total_sent'];
            $newAvgTime = $oldCount > 0
                ? (int) (($oldAvgTime * $oldCount + $deliveryTimeMs) / $newSent)
                : $deliveryTimeMs;

            // Calculate new average retry count
            $oldAvgRetry = (float) $existing['avg_retry_count'];
            $newAvgRetry = $oldCount > 0
                ? ($oldAvgRetry * $oldCount + $retryCount) / $newSent
                : $retryCount;

            $updateQuery = "UPDATE {$this->tableName}
                           SET total_sent = :sent,
                               total_delivered = :delivered,
                               total_failed = :failed,
                               avg_delivery_time_ms = :avg_time,
                               avg_retry_count = :avg_retry
                           WHERE id = :id";

            $stmt = $this->execute($updateQuery, [
                ':sent' => $newSent,
                ':delivered' => $newDelivered,
                ':failed' => $newFailed,
                ':avg_time' => $newAvgTime,
                ':avg_retry' => round($newAvgRetry, 2),
                ':id' => $existing['id']
            ]);

            return $stmt !== false;
        } else {
            // Create new record
            return $this->recordMetric(
                $messageType,
                $periodStart,
                $periodEnd,
                1,
                $isDelivered ? 1 : 0,
                $isDelivered ? 0 : 1,
                $deliveryTimeMs,
                $retryCount
            ) !== false;
        }
    }

    /**
     * Get metrics for a specific period
     *
     * @param string $periodStart Start of the period
     * @param string $periodEnd End of the period
     * @param string|null $messageType Optional message type filter
     * @return array Metrics array
     */
    public function getMetricsForPeriod(
        string $periodStart,
        string $periodEnd,
        ?string $messageType = null
    ): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE period_start >= :start AND period_end <= :end";
        $params = [':start' => $periodStart, ':end' => $periodEnd];

        if ($messageType !== null) {
            $query .= " AND message_type = :type";
            $params[':type'] = $messageType;
        }

        $query .= " ORDER BY period_start ASC";

        $stmt = $this->execute($query, $params);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Build aggregated metrics query
     *
     * @param bool $groupByType Whether to group by message type
     * @param string|null $messageType Optional message type filter (ignored if groupByType is true)
     * @return string SQL query string
     */
    private function buildAggregatedMetricsQuery(bool $groupByType = false, ?string $messageType = null): string {
        $selectFields = $groupByType ? "message_type,\n                    " : "";

        $query = "SELECT
                    {$selectFields}COALESCE(SUM(total_sent), 0) as total_sent,
                    COALESCE(SUM(total_delivered), 0) as total_delivered,
                    COALESCE(SUM(total_failed), 0) as total_failed,
                    COALESCE(AVG(avg_delivery_time_ms), 0) as avg_delivery_time_ms,
                    COALESCE(AVG(avg_retry_count), 0) as avg_retry_count
                  FROM {$this->tableName}
                  WHERE period_start >= :start AND period_end <= :end";

        if (!$groupByType && $messageType !== null) {
            $query .= " AND message_type = :type";
        }

        if ($groupByType) {
            $query .= " GROUP BY message_type";
        }

        return $query;
    }

    /**
     * Calculate success rate and add to result array
     *
     * @param array $result Result array with total_sent and total_delivered
     * @return array Result with added success_rate
     */
    private function addSuccessRate(array &$result): array {
        $totalSent = (int) ($result['total_sent'] ?? 0);
        $totalDelivered = (int) ($result['total_delivered'] ?? 0);
        $result['success_rate'] = $totalSent > 0
            ? round(($totalDelivered / $totalSent) * 100, 2)
            : 0;
        return $result;
    }

    /**
     * Get aggregated metrics for a time range
     *
     * @param string $periodStart Start of the period
     * @param string $periodEnd End of the period
     * @param string|null $messageType Optional message type filter
     * @return array Aggregated metrics
     */
    public function getAggregatedMetrics(
        string $periodStart,
        string $periodEnd,
        ?string $messageType = null
    ): array {
        $query = $this->buildAggregatedMetricsQuery(false, $messageType);
        $params = [':start' => $periodStart, ':end' => $periodEnd];

        if ($messageType !== null) {
            $params[':type'] = $messageType;
        }

        $stmt = $this->execute($query, $params);
        $result = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

        if (!$result) {
            return [
                'total_sent' => 0,
                'total_delivered' => 0,
                'total_failed' => 0,
                'avg_delivery_time_ms' => 0,
                'avg_retry_count' => 0,
                'success_rate' => 0
            ];
        }

        return $this->addSuccessRate($result);
    }

    /**
     * Get recent metrics (last 24 hours)
     *
     * @param string|null $messageType Optional message type filter
     * @return array Recent metrics
     */
    public function getRecentMetrics(?string $messageType = null): array {
        $periodStart = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $periodEnd = date('Y-m-d H:i:s');

        return $this->getAggregatedMetrics($periodStart, $periodEnd, $messageType);
    }

    /**
     * Get metrics grouped by message type
     *
     * @param string $periodStart Start of the period
     * @param string $periodEnd End of the period
     * @return array Metrics grouped by message type
     */
    public function getMetricsByType(string $periodStart, string $periodEnd): array {
        $query = $this->buildAggregatedMetricsQuery(true);

        $stmt = $this->execute($query, [':start' => $periodStart, ':end' => $periodEnd]);
        $results = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // Calculate success rates for each type
        foreach ($results as &$result) {
            $this->addSuccessRate($result);
        }

        return $results;
    }

    /**
     * Delete old metric records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = 90): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old metric records", $e);
            return 0;
        }
    }
}
