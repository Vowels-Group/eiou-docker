<?php
/**
 * Unit Tests for TransactionStatisticsRepository
 *
 * Tests transaction statistics repository operations including
 * counts, aggregations, and reporting queries.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionStatisticsRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(TransactionStatisticsRepository::class)]
class TransactionStatisticsRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private TransactionStatisticsRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new TransactionStatisticsRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorSetsPdoConnection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repo = new TransactionStatisticsRepository($pdo);

        $this->assertSame($pdo, $repo->getPdo());
    }

    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('transactions', $this->repository->getTableName());
    }

    // =========================================================================
    // getTotalCount() Tests
    // =========================================================================

    public function testGetTotalCountReturnsCount(): void
    {
        $expectedCount = 1234;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT COUNT(*)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn($expectedCount);

        $result = $this->repository->getTotalCount();

        $this->assertEquals($expectedCount, $result);
    }

    public function testGetTotalCountReturnsZeroOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getTotalCount();

        $this->assertEquals(0, $result);
    }

    public function testGetTotalCountReturnsZeroForEmptyTable(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(0);

        $result = $this->repository->getTotalCount();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getTypeStatistics() Tests
    // =========================================================================

    public function testGetTypeStatisticsReturnsGroupedData(): void
    {
        $expectedStats = [
            ['type' => 'standard', 'count' => 100, 'total' => 500000],
            ['type' => 'p2p', 'count' => 50, 'total' => 250000],
            ['type' => 'contact', 'count' => 25, 'total' => 0],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('GROUP BY type'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getTypeStatistics();

        $this->assertCount(3, $result);
        $this->assertEquals('standard', $result[0]['type']);
        $this->assertEquals(100, $result[0]['count']);
    }

    public function testGetTypeStatisticsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getTypeStatistics();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetTypeStatisticsReturnsEmptyArrayForNoTransactions(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getTypeStatistics();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getStatisticsByType() Tests
    // =========================================================================

    public function testGetStatisticsByTypeReturnsTypeData(): void
    {
        $expectedStats = [
            'type' => 'standard',
            'count' => 150,
            'total' => 750000
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE type = :type'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':type', 'standard');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getStatisticsByType('standard');

        $this->assertEquals($expectedStats, $result);
    }

    public function testGetStatisticsByTypeReturnsEmptyArrayWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getStatisticsByType('nonexistent');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetStatisticsByTypeReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStatisticsByType('standard');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getCountByType() Tests
    // =========================================================================

    public function testGetCountByTypeReturnsCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT COUNT(*)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':type', 'p2p');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(75);

        $result = $this->repository->getCountByType('p2p');

        $this->assertEquals(75, $result);
    }

    public function testGetCountByTypeReturnsZeroWhenNoneFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(false);

        $result = $this->repository->getCountByType('nonexistent');

        $this->assertEquals(0, $result);
    }

    public function testGetCountByTypeReturnsZeroOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getCountByType('standard');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getOverallStatistics() Tests
    // =========================================================================

    public function testGetOverallStatisticsReturnsComprehensiveData(): void
    {
        $expectedStats = [
            'total_count' => 500,
            'total_amount' => 2500000,
            'average_amount' => 5000,
            'unique_senders' => 100,
            'unique_receivers' => 120,
            'completed_count' => 400,
            'pending_count' => 50
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('COUNT(*) as total_count'),
                $this->stringContains('SUM(amount) as total_amount'),
                $this->stringContains('AVG(amount) as average_amount'),
                $this->stringContains('COUNT(DISTINCT sender_address)'),
                $this->stringContains('COUNT(DISTINCT receiver_address)')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getOverallStatistics();

        $this->assertEquals($expectedStats, $result);
        $this->assertEquals(500, $result['total_count']);
        $this->assertEquals(2500000, $result['total_amount']);
        $this->assertEquals(100, $result['unique_senders']);
    }

    public function testGetOverallStatisticsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getOverallStatistics();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetOverallStatisticsHandlesEmptyTable(): void
    {
        $emptyStats = [
            'total_count' => 0,
            'total_amount' => null,
            'average_amount' => null,
            'unique_senders' => 0,
            'unique_receivers' => 0,
            'completed_count' => 0,
            'pending_count' => 0
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn($emptyStats);

        $result = $this->repository->getOverallStatistics();

        $this->assertEquals(0, $result['total_count']);
        $this->assertNull($result['total_amount']);
    }

    // =========================================================================
    // getStatisticsByStatus() Tests
    // =========================================================================

    public function testGetStatisticsByStatusReturnsFilteredData(): void
    {
        $expectedStats = [
            'count' => 150,
            'total_amount' => 750000,
            'average_amount' => 5000
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE status = :status'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':status', 'completed');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getStatisticsByStatus('completed');

        $this->assertEquals(150, $result['count']);
        $this->assertEquals(750000, $result['total_amount']);
    }

    public function testGetStatisticsByStatusHandlesDifferentStatuses(): void
    {
        $statuses = ['pending', 'completed', 'rejected', 'cancelled'];

        foreach ($statuses as $status) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->expects($this->once())
                ->method('bindValue')
                ->with(':status', $status);
            $stmt->expects($this->once())
                ->method('execute');
            $stmt->expects($this->once())
                ->method('fetch')
                ->willReturn(['count' => 10, 'total_amount' => 50000, 'average_amount' => 5000]);

            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $repo = new TransactionStatisticsRepository($pdo);
            $result = $repo->getStatisticsByStatus($status);

            $this->assertEquals(10, $result['count']);
        }
    }

    public function testGetStatisticsByStatusReturnsEmptyArrayWhenNoneFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getStatisticsByStatus('unknown');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetStatisticsByStatusReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStatisticsByStatus('completed');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getStatisticsByCurrency() Tests
    // =========================================================================

    public function testGetStatisticsByCurrencyReturnsFilteredData(): void
    {
        $expectedStats = [
            'count' => 200,
            'total_amount' => 1000000,
            'average_amount' => 5000
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE currency = :currency'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':currency', 'USD');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getStatisticsByCurrency('USD');

        $this->assertEquals(200, $result['count']);
        $this->assertEquals(1000000, $result['total_amount']);
    }

    public function testGetStatisticsByCurrencyHandlesDifferentCurrencies(): void
    {
        $currencies = ['USD', 'EUR', 'GBP', 'JPY'];

        foreach ($currencies as $currency) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->expects($this->once())
                ->method('bindValue')
                ->with(':currency', $currency);
            $stmt->expects($this->once())
                ->method('execute');
            $stmt->expects($this->once())
                ->method('fetch')
                ->willReturn(['count' => 50, 'total_amount' => 250000, 'average_amount' => 5000]);

            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $repo = new TransactionStatisticsRepository($pdo);
            $result = $repo->getStatisticsByCurrency($currency);

            $this->assertEquals(50, $result['count']);
        }
    }

    public function testGetStatisticsByCurrencyReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStatisticsByCurrency('USD');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getDailyTransactionCounts() Tests
    // =========================================================================

    public function testGetDailyTransactionCountsReturnsDailyCounts(): void
    {
        $expectedCounts = [
            ['date' => '2024-01-15', 'count' => 25],
            ['date' => '2024-01-14', 'count' => 30],
            ['date' => '2024-01-13', 'count' => 22],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('DATE(timestamp) as date'),
                $this->stringContains('GROUP BY DATE(timestamp)'),
                $this->stringContains('ORDER BY date DESC')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 30);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedCounts);

        $result = $this->repository->getDailyTransactionCounts();

        $this->assertCount(3, $result);
        $this->assertEquals('2024-01-15', $result[0]['date']);
        $this->assertEquals(25, $result[0]['count']);
    }

    public function testGetDailyTransactionCountsUsesCustomDays(): void
    {
        $customDays = 7;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', $customDays);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getDailyTransactionCounts($customDays);
    }

    public function testGetDailyTransactionCountsUsesDefaultDays(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 30); // Default

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getDailyTransactionCounts();
    }

    public function testGetDailyTransactionCountsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getDailyTransactionCounts();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetDailyTransactionCountsReturnsEmptyArrayForNoData(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getDailyTransactionCounts();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
