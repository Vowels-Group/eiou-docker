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
use Eiou\Core\SplitAmount;
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

        // Most methods now read live + archive (transactions +
        // transactions_archive). The archive table is intentionally
        // missing in this unit-test fixture — the source's try/catch
        // around the archive query treats that as "no archive
        // contribution" and the assertions only validate the live-side
        // total. We default both prepare() and query() to throw on the
        // archive table so individual tests don't have to repeat the
        // pattern. Tests that need the archive contribution can
        // override on the live ->stmt or set their own callback.
        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->pdo->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
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

        // getTotalCount() now sums live + archive via two
        // unprepared `pdo->query(...)->fetchColumn()` calls. Live
        // returns the count; archive throws (table missing in the
        // test fixture) and the source falls back to live-only.
        $this->pdo->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->stmt->method('fetchColumn')->willReturn($expectedCount);

        $this->assertEquals($expectedCount, $this->repository->getTotalCount());
    }

    public function testGetTotalCountReturnsZeroOnFailure(): void
    {
        // Live count throws → the function logs and returns 0
        // without attempting the archive query.
        $this->pdo->method('query')
            ->willThrowException(new PDOException('Query failed'));

        $this->assertEquals(0, $this->repository->getTotalCount());
    }

    public function testGetTotalCountReturnsZeroForEmptyTable(): void
    {
        // Live count returns 0; archive throws (missing table); 0 + 0 = 0.
        $this->pdo->method('query')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->stmt->method('fetchColumn')->willReturn(0);

        $this->assertEquals(0, $this->repository->getTotalCount());
    }

    // =========================================================================
    // getTypeStatistics() Tests
    // =========================================================================

    public function testGetTypeStatisticsReturnsGroupedData(): void
    {
        // Live + archive paths — archive table missing in fixture, so
        // its prepare throws and the source skips that contribution.
        $dbRows = [
            ['type' => 'standard', 'count' => 100, 'total_whole' => 500000, 'total_frac' => 0],
            ['type' => 'p2p', 'count' => 50, 'total_whole' => 250000, 'total_frac' => 0],
            ['type' => 'contact', 'count' => 25, 'total_whole' => 0, 'total_frac' => 0],
        ];

        // prepare()/query() globally configured in setUp — see helper.

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbRows);

        $result = $this->repository->getTypeStatistics();

        $this->assertCount(3, $result);
        $this->assertEquals('standard', $result[0]['type']);
        $this->assertEquals(100, $result[0]['count']);
        $this->assertInstanceOf(SplitAmount::class, $result[0]['total']);
        $this->assertEquals(500000, $result[0]['total']->whole);
    }

    public function testGetTypeStatisticsReturnsEmptyArrayOnFailure(): void
    {
        // prepare()/query() globally configured in setUp — see helper.

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getTypeStatistics();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetTypeStatisticsReturnsEmptyArrayForNoTransactions(): void
    {
        // prepare()/query() globally configured in setUp — see helper.

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
        $dbRow = [
            'type' => 'standard',
            'count' => 150,
            'total_whole' => 750000,
            'total_frac' => 0
        ];

        // prepare()/query() globally configured in setUp — see helper.
        // fetchByTypeRow now passes params directly to execute([':type'
        // => $type]) instead of calling bindValue separately, so we
        // stub execute() generically rather than asserting on bindValue.
        $this->stmt->method('execute');
        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbRow);

        $result = $this->repository->getStatisticsByType('standard');

        $this->assertEquals('standard', $result['type']);
        $this->assertEquals(150, $result['count']);
        $this->assertInstanceOf(SplitAmount::class, $result['total']);
        $this->assertEquals(750000, $result['total']->whole);
    }

    public function testGetStatisticsByTypeReturnsEmptyArrayWhenNotFound(): void
    {
        // prepare()/query() globally configured in setUp — see helper.

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
        // prepare()/query() globally configured in setUp — see helper.

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
        // getCountByType prepares twice: once via execute() helper for
        // the live count, once inline for the archive count. The archive
        // table is missing in the test fixture so we throw on the
        // transactions_archive prepare and let the source's try/catch
        // fall through to live-only.
        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->stmt->method('fetchColumn')->willReturn(75);

        $this->assertEquals(75, $this->repository->getCountByType('p2p'));
    }

    public function testGetCountByTypeReturnsZeroWhenNoneFound(): void
    {
        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->stmt->method('fetchColumn')->willReturn(false);

        $this->assertEquals(0, $this->repository->getCountByType('nonexistent'));
    }

    public function testGetCountByTypeReturnsZeroOnFailure(): void
    {
        // Live prepare succeeds but execute() throws → execute() helper
        // returns false → getCountByType skips the fetchColumn branch
        // and reads $live = 0. Archive prepare also throws so the
        // total stays at 0.
        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->stmt->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $this->assertEquals(0, $this->repository->getCountByType('standard'));
    }

    // =========================================================================
    // getOverallStatistics() Tests
    // =========================================================================

    public function testGetOverallStatisticsReturnsComprehensiveData(): void
    {
        $dbRow = [
            'total_count' => 500,
            'total_amount_whole' => 2500000,
            'total_amount_frac' => 0,
            'unique_senders' => 100,
            'unique_receivers' => 120,
            'completed_count' => 400,
            'pending_count' => 50
        ];

        // prepare()/query() globally configured in setUp — see helper.

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbRow);

        $result = $this->repository->getOverallStatistics();

        $this->assertEquals(500, $result['total_count']);
        $this->assertInstanceOf(SplitAmount::class, $result['total_amount']);
        $this->assertEquals(2500000, $result['total_amount']->whole);
        $this->assertEquals(100, $result['unique_senders']);
    }

    public function testGetOverallStatisticsReturnsEmptyArrayOnFailure(): void
    {
        // prepare()/query() globally configured in setUp — see helper.

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
            'total_amount_whole' => null,
            'total_amount_frac' => null,
            'unique_senders' => 0,
            'unique_receivers' => 0,
            'completed_count' => 0,
            'pending_count' => 0
        ];

        // prepare()/query() globally configured in setUp — see helper.

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn($emptyStats);

        $result = $this->repository->getOverallStatistics();

        $this->assertEquals(0, $result['total_count']);
        $this->assertInstanceOf(SplitAmount::class, $result['total_amount']);
        $this->assertTrue($result['total_amount']->isZero());
    }

    // =========================================================================
    // getStatisticsByStatus() Tests
    // =========================================================================

    public function testGetStatisticsByStatusReturnsFilteredData(): void
    {
        $dbRow = [
            'count' => 150,
            'total_amount_whole' => 750000,
            'total_amount_frac' => 0
        ];

        // prepare()/query() globally configured in setUp — see helper.

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':status', 'completed');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbRow);

        $result = $this->repository->getStatisticsByStatus('completed');

        $this->assertEquals(150, $result['count']);
        $this->assertInstanceOf(SplitAmount::class, $result['total_amount']);
        $this->assertEquals(750000, $result['total_amount']->whole);
    }

    public function testGetStatisticsByStatusHandlesDifferentStatuses(): void
    {
        $statuses = ['pending', 'completed', 'rejected', 'cancelled'];

        foreach ($statuses as $status) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute');
            $stmt->method('fetch')
                ->willReturn(['count' => 10, 'total_amount_whole' => 50000, 'total_amount_frac' => 0]);

            // Live prepare returns this row's stmt; archive prepare
            // throws because the table is missing in the fixture.
            $pdo = $this->createMock(PDO::class);
            $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($stmt) {
                if (str_contains($sql, 'transactions_archive')) {
                    throw new PDOException('No archive table in this fixture');
                }
                return $stmt;
            });

            $repo = new TransactionStatisticsRepository($pdo);
            $result = $repo->getStatisticsByStatus($status);

            $this->assertEquals(10, $result['count']);
        }
    }

    public function testGetStatisticsByStatusReturnsZeroShapeWhenNoneFound(): void
    {
        // prepare()/query() globally configured in setUp — see helper.

        $this->stmt->method('execute');
        $this->stmt->method('fetch')->willReturn(false);

        // The source used to return [] for "no rows"; now it always
        // returns the zero-filled count + total_amount shape so callers
        // don't have to special-case the empty bucket.
        $result = $this->repository->getStatisticsByStatus('unknown');

        $this->assertIsArray($result);
        $this->assertSame(0, (int) $result['count']);
        $this->assertInstanceOf(SplitAmount::class, $result['total_amount']);
        $this->assertEquals(0, $result['total_amount']->whole);
    }

    public function testGetStatisticsByStatusReturnsEmptyArrayOnFailure(): void
    {
        // prepare()/query() globally configured in setUp — see helper.

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
        $dbRow = [
            'count' => 200,
            'total_amount_whole' => 1000000,
            'total_amount_frac' => 0
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
            ->willReturn($dbRow);

        $result = $this->repository->getStatisticsByCurrency('USD');

        $this->assertEquals(200, $result['count']);
        $this->assertInstanceOf(SplitAmount::class, $result['total_amount']);
        $this->assertEquals(1000000, $result['total_amount']->whole);
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
                ->willReturn(['count' => 50, 'total_amount_whole' => 250000, 'total_amount_frac' => 0]);

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
        // prepare()/query() globally configured in setUp — see helper.

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

        // prepare()/query() globally configured in setUp — see helper.

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
        // prepare()/query() globally configured in setUp — see helper.

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
        // prepare()/query() globally configured in setUp — see helper.

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getDailyTransactionCounts();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetDailyTransactionCountsReturnsEmptyArrayForNoData(): void
    {
        // prepare()/query() globally configured in setUp — see helper.

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
