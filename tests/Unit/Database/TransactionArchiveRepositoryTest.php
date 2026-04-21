<?php
/**
 * Unit Tests for TransactionArchiveRepository
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionArchiveRepository;
use PDO;
use PDOStatement;

#[CoversClass(TransactionArchiveRepository::class)]
class TransactionArchiveRepositoryTest extends TestCase
{
    private TransactionArchiveRepository $repository;
    private PDO $mockPdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockPdo    = $this->createMock(PDO::class);
        $this->repository = new TransactionArchiveRepository($this->mockPdo);
    }

    public function testTableNameIsArchive(): void
    {
        $ref = new \ReflectionClass($this->repository);
        $prop = $ref->getProperty('tableName');
        $prop->setAccessible(true);
        $this->assertEquals('transactions_archive', $prop->getValue($this->repository));
    }

    // ---- eligibility --------------------------------------------------------

    public function testFindEligibleBilateralPairsReturnsAssocRows(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['hash_a' => 'aaaa', 'hash_b' => 'bbbb'],
            ['hash_a' => 'cccc', 'hash_b' => 'dddd'],
        ]);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $pairs = $this->repository->findEligibleBilateralPairs(180, 10000);

        $this->assertCount(2, $pairs);
        $this->assertSame('aaaa', $pairs[0]['hash_a']);
        $this->assertSame('dddd', $pairs[1]['hash_b']);
    }

    public function testFindEligibleLiveIdsForPairReturnsIntArray(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['7', '12', '19']);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $ids = $this->repository->findEligibleLiveIdsForPair('aaaa', 'bbbb', 180, 500);

        $this->assertSame([7, 12, 19], $ids);
    }

    // ---- atomic move --------------------------------------------------------

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

        $this->assertSame(3, $this->repository->moveRows([1, 2, 3]));
    }

    public function testMoveRowsRollsBackOnInsertDeleteMismatch(): void
    {
        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);
        $insertStmt->method('rowCount')->willReturn(3);

        $deleteStmt = $this->createMock(PDOStatement::class);
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(2); // concurrent update snuck in

        $this->mockPdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->mockPdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->mockPdo->expects($this->never())->method('commit');
        $this->mockPdo->method('prepare')
            ->willReturnOnConsecutiveCalls($insertStmt, $deleteStmt);

        $this->assertSame(0, $this->repository->moveRows([1, 2, 3]));
    }

    // ---- checkpoint table ---------------------------------------------------

    public function testGetCheckpointReturnsNullWhenAbsent(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $this->assertNull($this->repository->getCheckpoint('aaaa', 'bbbb'));
    }

    public function testGetCheckpointReturnsRowWhenPresent(): void
    {
        $row = [
            'user_public_key_hash'        => 'aaaa',
            'contact_public_key_hash'     => 'bbbb',
            'archived_count'              => 7,
            'archived_txid_hash'          => 'deadbeef',
            'highest_archived_timestamp'  => '2026-04-01 01:00:00.000000',
            'highest_archived_time'       => 1711929600,
            'last_verified_gap_free_at'   => '2026-04-20 01:30:00.000000',
        ];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($row);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $got = $this->repository->getCheckpoint('aaaa', 'bbbb');

        $this->assertSame($row, $got);
    }

    public function testUpsertCheckpointBindsCanonicalPairOrder(): void
    {
        // Pass in reverse order — repo should canonicalize so both orderings
        // land on the same row.
        $stmt = $this->createMock(PDOStatement::class);

        // Capture the bound params to verify canonicalization
        $capturedParams = null;
        $stmt->method('execute')->willReturnCallback(function ($params) use (&$capturedParams) {
            $capturedParams = $params;
            return true;
        });
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $this->repository->upsertCheckpoint(
            'zzzz',  // "bigger" hash passed first
            'aaaa',  // "smaller" hash passed second
            '2026-04-20 01:30:00.000000',
            1711929600,
            42,
            'deadbeef'
        );

        // Canonical order is (LEAST, GREATEST) == (aaaa, zzzz)
        $this->assertSame('aaaa', $capturedParams[':a']);
        $this->assertSame('zzzz', $capturedParams[':b']);
        $this->assertSame(42, $capturedParams[':cnt']);
        $this->assertSame('deadbeef', $capturedParams[':hash']);
    }

    public function testComputeArchivedTxidHashIsStableForSortedInput(): void
    {
        // Fetch returns txids in order; the SQL already sorts ASC. Hash should
        // be deterministic given the same input set.
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn(['tx1', 'tx2', 'tx3']);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $hash1 = $this->repository->computeArchivedTxidHash('aaaa', 'bbbb');
        $this->assertSame(hash('sha256', "tx1\ntx2\ntx3"), $hash1);
    }

    public function testComputeArchivedTxidHashEmptyReturnsEmptyStringHash(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $this->assertSame(hash('sha256', ''), $this->repository->computeArchivedTxidHash('aaaa', 'bbbb'));
    }

    public function testCountArchivedForPairReturnsInt(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('13');
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $this->assertSame(13, $this->repository->countArchivedForPair('aaaa', 'bbbb'));
    }

    public function testGetArchiveHeadForPairReturnsNullWhenNoRows(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $this->assertNull($this->repository->getArchiveHeadForPair('aaaa', 'bbbb'));
    }

    public function testGetArchiveHeadForPairReturnsMetaWhenPresent(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'highest_archived_timestamp' => '2026-04-20 01:30:00.123456',
            'highest_archived_time'      => '1711929600',
        ]);
        $this->mockPdo->method('prepare')->willReturn($stmt);

        $meta = $this->repository->getArchiveHeadForPair('aaaa', 'bbbb');
        $this->assertSame('2026-04-20 01:30:00.123456', $meta['highest_archived_timestamp']);
        $this->assertSame(1711929600, $meta['highest_archived_time']);
    }

    // ---- operator visibility -----------------------------------------------

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
        $stmt->method('fetchColumn')->willReturn('2026-04-20 01:30:00.000000');
        $this->mockPdo->method('query')->willReturn($stmt);

        $this->assertSame(
            '2026-04-20 01:30:00.000000',
            $this->repository->getLatestArchivedAt()
        );
    }
}
