<?php
/**
 * Unit Tests for DeliveryMetricsRepository
 *
 * Tests delivery metrics repository operations including recording metrics,
 * delivery events, aggregation, and cleanup with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\DeliveryMetricsRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(DeliveryMetricsRepository::class)]
class DeliveryMetricsRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private DeliveryMetricsRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new DeliveryMetricsRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('delivery_metrics', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new DeliveryMetricsRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // recordMetric() Tests
    // =========================================================================

    /**
     * Test recordMetric inserts metric record
     */
    public function testRecordMetricInsertsMetricRecord(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->recordMetric(
            'transaction',
            '2024-01-01 00:00:00',
            '2024-01-01 00:59:59',
            100,
            95,
            5,
            150,
            0.5
        );

        $this->assertEquals('1', $result);
    }

    /**
     * Test recordMetric with default values
     */
    public function testRecordMetricWithDefaultValues(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->recordMetric(
            'transaction',
            '2024-01-01 00:00:00',
            '2024-01-01 00:59:59',
            100,
            95,
            5
        );

        $this->assertEquals('1', $result);
    }

    /**
     * Test recordMetric returns false on failure
     */
    public function testRecordMetricReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->recordMetric(
            'transaction',
            '2024-01-01 00:00:00',
            '2024-01-01 00:59:59',
            100,
            95,
            5
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // recordDeliveryEvent() Tests
    // =========================================================================

    /**
     * Test recordDeliveryEvent creates new record when none exists
     */
    public function testRecordDeliveryEventCreatesNewRecordWhenNoneExists(): void
    {
        // First query: check for existing record
        // Second query: insert new record
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(null); // No existing record

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->recordDeliveryEvent(
            'transaction',
            true,
            100,
            1
        );

        $this->assertTrue($result);
    }

    /**
     * Test recordDeliveryEvent updates existing record
     */
    public function testRecordDeliveryEventUpdatesExistingRecord(): void
    {
        $existingRecord = [
            'id' => 1,
            'total_sent' => 10,
            'total_delivered' => 9,
            'total_failed' => 1,
            'avg_delivery_time_ms' => 100,
            'avg_retry_count' => 0.5
        ];

        // First query: check for existing record
        // Second query: update record
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($existingRecord);

        $result = $this->repository->recordDeliveryEvent(
            'transaction',
            true,
            150,
            0
        );

        $this->assertTrue($result);
    }

    /**
     * Test recordDeliveryEvent with failed delivery
     */
    public function testRecordDeliveryEventWithFailedDelivery(): void
    {
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(null);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->recordDeliveryEvent(
            'transaction',
            false, // Failed delivery
            0,
            3
        );

        $this->assertTrue($result);
    }

    /**
     * Test recordDeliveryEvent with different message types
     */
    public function testRecordDeliveryEventWithDifferentMessageTypes(): void
    {
        $messageTypes = ['transaction', 'p2p', 'rp2p', 'contact', 'all'];

        foreach ($messageTypes as $type) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new DeliveryMetricsRepository($pdo);

            $pdo->expects($this->exactly(2))
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->atLeastOnce())
                ->method('bindValue');

            $stmt->expects($this->exactly(2))
                ->method('execute')
                ->willReturn(true);

            $stmt->expects($this->once())
                ->method('fetch')
                ->with(PDO::FETCH_ASSOC)
                ->willReturn(null);

            $pdo->expects($this->once())
                ->method('lastInsertId')
                ->willReturn('1');

            $result = $repository->recordDeliveryEvent($type, true, 100, 0);

            $this->assertTrue($result, "Failed for message type: $type");
        }
    }

    // =========================================================================
    // getMetricsForPeriod() Tests
    // =========================================================================

    /**
     * Test getMetricsForPeriod returns metrics for period
     */
    public function testGetMetricsForPeriodReturnsMetrics(): void
    {
        $metrics = [
            [
                'id' => 1,
                'message_type' => 'transaction',
                'period_start' => '2024-01-01 00:00:00',
                'period_end' => '2024-01-01 00:59:59',
                'total_sent' => 100,
                'total_delivered' => 95
            ],
            [
                'id' => 2,
                'message_type' => 'transaction',
                'period_start' => '2024-01-01 01:00:00',
                'period_end' => '2024-01-01 01:59:59',
                'total_sent' => 80,
                'total_delivered' => 78
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($metrics);

        $result = $this->repository->getMetricsForPeriod(
            '2024-01-01 00:00:00',
            '2024-01-01 23:59:59'
        );

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test getMetricsForPeriod with message type filter
     */
    public function testGetMetricsForPeriodWithMessageTypeFilter(): void
    {
        $metrics = [
            [
                'id' => 1,
                'message_type' => 'transaction',
                'total_sent' => 100,
                'total_delivered' => 95
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($metrics);

        $result = $this->repository->getMetricsForPeriod(
            '2024-01-01 00:00:00',
            '2024-01-01 23:59:59',
            'transaction'
        );

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Test getMetricsForPeriod returns empty array when no data
     */
    public function testGetMetricsForPeriodReturnsEmptyArrayWhenNoData(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getMetricsForPeriod(
            '2024-01-01 00:00:00',
            '2024-01-01 23:59:59'
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getAggregatedMetrics() Tests
    // =========================================================================

    /**
     * Test getAggregatedMetrics returns aggregated data
     */
    public function testGetAggregatedMetricsReturnsAggregatedData(): void
    {
        $aggregated = [
            'total_sent' => 500,
            'total_delivered' => 480,
            'total_failed' => 20,
            'avg_delivery_time_ms' => 150,
            'avg_retry_count' => 0.5
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($aggregated);

        $result = $this->repository->getAggregatedMetrics(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59'
        );

        $this->assertIsArray($result);
        $this->assertEquals(500, $result['total_sent']);
        $this->assertEquals(480, $result['total_delivered']);
        $this->assertArrayHasKey('success_rate', $result);
        $this->assertEquals(96.0, $result['success_rate']); // 480/500 * 100
    }

    /**
     * Test getAggregatedMetrics with message type filter
     */
    public function testGetAggregatedMetricsWithMessageTypeFilter(): void
    {
        $aggregated = [
            'total_sent' => 100,
            'total_delivered' => 98,
            'total_failed' => 2,
            'avg_delivery_time_ms' => 100,
            'avg_retry_count' => 0.2
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($aggregated);

        $result = $this->repository->getAggregatedMetrics(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59',
            'transaction'
        );

        $this->assertIsArray($result);
        $this->assertEquals(98.0, $result['success_rate']); // 98/100 * 100
    }

    /**
     * Test getAggregatedMetrics returns defaults when no data
     */
    public function testGetAggregatedMetricsReturnsDefaultsWhenNoData(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(null);

        $result = $this->repository->getAggregatedMetrics(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59'
        );

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['total_sent']);
        $this->assertEquals(0, $result['total_delivered']);
        $this->assertEquals(0, $result['total_failed']);
        $this->assertEquals(0, $result['avg_delivery_time_ms']);
        $this->assertEquals(0, $result['avg_retry_count']);
        $this->assertEquals(0, $result['success_rate']);
    }

    /**
     * Test getAggregatedMetrics calculates success rate correctly with zero sent
     */
    public function testGetAggregatedMetricsCalculatesSuccessRateWithZeroSent(): void
    {
        $aggregated = [
            'total_sent' => 0,
            'total_delivered' => 0,
            'total_failed' => 0,
            'avg_delivery_time_ms' => 0,
            'avg_retry_count' => 0
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($aggregated);

        $result = $this->repository->getAggregatedMetrics(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59'
        );

        $this->assertEquals(0, $result['success_rate']);
    }

    // =========================================================================
    // getRecentMetrics() Tests
    // =========================================================================

    /**
     * Test getRecentMetrics returns last 24 hours metrics
     */
    public function testGetRecentMetricsReturnsLast24HoursMetrics(): void
    {
        $aggregated = [
            'total_sent' => 1000,
            'total_delivered' => 950,
            'total_failed' => 50,
            'avg_delivery_time_ms' => 120,
            'avg_retry_count' => 0.3
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($aggregated);

        $result = $this->repository->getRecentMetrics();

        $this->assertIsArray($result);
        $this->assertEquals(1000, $result['total_sent']);
        $this->assertEquals(95.0, $result['success_rate']);
    }

    /**
     * Test getRecentMetrics with message type filter
     */
    public function testGetRecentMetricsWithMessageTypeFilter(): void
    {
        $aggregated = [
            'total_sent' => 200,
            'total_delivered' => 190,
            'total_failed' => 10,
            'avg_delivery_time_ms' => 100,
            'avg_retry_count' => 0.2
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($aggregated);

        $result = $this->repository->getRecentMetrics('p2p');

        $this->assertIsArray($result);
        $this->assertEquals(200, $result['total_sent']);
    }

    // =========================================================================
    // getMetricsByType() Tests
    // =========================================================================

    /**
     * Test getMetricsByType returns metrics grouped by type
     */
    public function testGetMetricsByTypeReturnsGroupedMetrics(): void
    {
        $metrics = [
            [
                'message_type' => 'transaction',
                'total_sent' => 500,
                'total_delivered' => 490,
                'total_failed' => 10,
                'avg_delivery_time_ms' => 100,
                'avg_retry_count' => 0.2
            ],
            [
                'message_type' => 'p2p',
                'total_sent' => 200,
                'total_delivered' => 180,
                'total_failed' => 20,
                'avg_delivery_time_ms' => 150,
                'avg_retry_count' => 0.5
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($metrics);

        $result = $this->repository->getMetricsByType(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59'
        );

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('success_rate', $result[0]);
        $this->assertArrayHasKey('success_rate', $result[1]);
    }

    /**
     * Test getMetricsByType returns empty array when no data
     */
    public function testGetMetricsByTypeReturnsEmptyArrayWhenNoData(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getMetricsByType(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59'
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // deleteOldRecords() Tests
    // =========================================================================

    /**
     * Test deleteOldRecords deletes old records
     */
    public function testDeleteOldRecordsDeletesOldRecords(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 90, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(25);

        $result = $this->repository->deleteOldRecords();

        $this->assertEquals(25, $result);
    }

    /**
     * Test deleteOldRecords with custom days parameter
     */
    public function testDeleteOldRecordsWithCustomDays(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 30, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(100);

        $result = $this->repository->deleteOldRecords(30);

        $this->assertEquals(100, $result);
    }

    /**
     * Test deleteOldRecords returns zero on exception
     */
    public function testDeleteOldRecordsReturnsZeroOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->deleteOldRecords();

        $this->assertEquals(0, $result);
    }

    /**
     * Test deleteOldRecords returns zero when no records deleted
     */
    public function testDeleteOldRecordsReturnsZeroWhenNoRecordsDeleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteOldRecords();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Edge Cases Tests
    // =========================================================================

    /**
     * Test success rate calculation with 100% delivery
     */
    public function testSuccessRateCalculationWithPerfectDelivery(): void
    {
        $aggregated = [
            'total_sent' => 100,
            'total_delivered' => 100,
            'total_failed' => 0,
            'avg_delivery_time_ms' => 50,
            'avg_retry_count' => 0
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($aggregated);

        $result = $this->repository->getAggregatedMetrics(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59'
        );

        $this->assertEquals(100.0, $result['success_rate']);
    }

    /**
     * Test success rate calculation with 0% delivery
     */
    public function testSuccessRateCalculationWithZeroDelivery(): void
    {
        $aggregated = [
            'total_sent' => 100,
            'total_delivered' => 0,
            'total_failed' => 100,
            'avg_delivery_time_ms' => 0,
            'avg_retry_count' => 3
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($aggregated);

        $result = $this->repository->getAggregatedMetrics(
            '2024-01-01 00:00:00',
            '2024-01-31 23:59:59'
        );

        $this->assertEquals(0.0, $result['success_rate']);
    }

    /**
     * Test recordDeliveryEvent average calculation
     */
    public function testRecordDeliveryEventAverageCalculation(): void
    {
        // Existing record has 10 messages with avg delivery time of 100ms
        // New delivery is 200ms
        // Expected new average: (100 * 10 + 200) / 11 = 109.09...
        $existingRecord = [
            'id' => 1,
            'total_sent' => 10,
            'total_delivered' => 10,
            'total_failed' => 0,
            'avg_delivery_time_ms' => 100,
            'avg_retry_count' => 0
        ];

        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($existingRecord);

        $result = $this->repository->recordDeliveryEvent(
            'transaction',
            true,
            200,
            0
        );

        $this->assertTrue($result);
    }
}
