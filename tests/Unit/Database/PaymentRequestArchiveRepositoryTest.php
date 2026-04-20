<?php
/**
 * Unit Tests for PaymentRequestArchiveRepository
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PaymentRequestArchiveRepository;
use PDO;
use PDOStatement;

#[CoversClass(PaymentRequestArchiveRepository::class)]
class PaymentRequestArchiveRepositoryTest extends TestCase
{
    private PaymentRequestArchiveRepository $repository;
    private PDO $mockPdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockPdo    = $this->createMock(PDO::class);
        $this->repository = new PaymentRequestArchiveRepository($this->mockPdo);
    }

    public function testTableNameIsArchive(): void
    {
        $ref = new \ReflectionClass($this->repository);
        $prop = $ref->getProperty('tableName');
        $prop->setAccessible(true);
        $this->assertEquals('payment_requests_archive', $prop->getValue($this->repository));
    }

    public function testFindEligibleLiveIdsReturnsIntArray(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn(['5', '9', '11']);
        $stmt->method('execute')->willReturn(true);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $ids = $this->repository->findEligibleLiveIds(180, 500);

        $this->assertSame([5, 9, 11], $ids);
    }

    public function testFindEligibleLiveIdsHandlesEmpty(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('execute')->willReturn(true);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $this->assertSame([], $this->repository->findEligibleLiveIds(180, 500));
    }

    public function testMoveRowsNoopOnEmptyIdList(): void
    {
        $this->mockPdo->expects($this->never())->method('beginTransaction');
        $this->assertSame(0, $this->repository->moveRows([]));
    }

    public function testMoveRowsCommitsWhenInsertMatchesDelete(): void
    {
        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);
        $insertStmt->method('rowCount')->willReturn(3);

        $deleteStmt = $this->createMock(PDOStatement::class);
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(3);

        $this->mockPdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->mockPdo->expects($this->once())->method('commit')->willReturn(true);
        $this->mockPdo->expects($this->never())->method('rollBack');
        $this->mockPdo->method('prepare')
            ->willReturnOnConsecutiveCalls($insertStmt, $deleteStmt);

        $moved = $this->repository->moveRows([1, 2, 3]);

        $this->assertSame(3, $moved);
    }

    public function testMoveRowsRollsBackWhenInsertDeleteCountsMismatch(): void
    {
        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);
        $insertStmt->method('rowCount')->willReturn(3);

        $deleteStmt = $this->createMock(PDOStatement::class);
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(2); // mismatch — concurrent update snuck in

        $this->mockPdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->mockPdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->mockPdo->expects($this->never())->method('commit');
        $this->mockPdo->method('prepare')
            ->willReturnOnConsecutiveCalls($insertStmt, $deleteStmt);

        $this->assertSame(0, $this->repository->moveRows([1, 2, 3]));
    }

    public function testCountAllReturnsInt(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn('42');
        $this->mockPdo->method('query')->willReturn($stmt);

        $this->assertSame(42, $this->repository->countAll());
    }

    public function testGetLatestArchivedAtReturnsNullWhenEmpty(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(false);
        $this->mockPdo->method('query')->willReturn($stmt);

        $this->assertNull($this->repository->getLatestArchivedAt());
    }

    public function testGetLatestArchivedAtReturnsTimestamp(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn('2026-04-01 01:00:00.000000');
        $this->mockPdo->method('query')->willReturn($stmt);

        $this->assertSame(
            '2026-04-01 01:00:00.000000',
            $this->repository->getLatestArchivedAt()
        );
    }
}
