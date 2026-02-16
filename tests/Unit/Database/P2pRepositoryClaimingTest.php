<?php
/**
 * Unit Tests for P2pRepository Claiming Methods
 *
 * Tests P2P repository claiming functionality including:
 * - Atomic claiming of queued P2Ps
 * - Detection of stuck sending P2Ps
 * - Recovery of stuck P2Ps with dead workers
 * - Clearing sending metadata after processing
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\P2pRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(P2pRepository::class)]
class P2pRepositoryClaimingTest extends TestCase
{
    private P2pRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_PID = 12345;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new P2pRepository($this->mockPdo);
    }

    // =========================================================================
    // claimQueuedP2p() Tests
    // =========================================================================

    /**
     * Test claimQueuedP2p returns true when claim succeeds (rowCount=1)
     */
    public function testClaimQueuedP2pReturnsTrueOnSuccess(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->claimQueuedP2p(self::TEST_HASH, self::TEST_PID);

        $this->assertTrue($result);
    }

    /**
     * Test claimQueuedP2p returns false when already claimed (rowCount=0)
     */
    public function testClaimQueuedP2pReturnsFalseWhenAlreadyClaimed(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->claimQueuedP2p(self::TEST_HASH, self::TEST_PID);

        $this->assertFalse($result);
    }

    /**
     * Test claimQueuedP2p returns false on PDOException
     */
    public function testClaimQueuedP2pReturnsFalseOnPdoException(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->claimQueuedP2p(self::TEST_HASH, self::TEST_PID);

        $this->assertFalse($result);
    }

    /**
     * Test claimQueuedP2p binds correct parameters
     */
    public function testClaimQueuedP2pBindsCorrectParameters(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE'))
            ->willReturn($this->mockStmt);

        $bindCalls = [];
        $this->mockStmt->method('bindValue')
            ->willReturnCallback(function ($param, $value, $type = null) use (&$bindCalls) {
                $bindCalls[$param] = ['value' => $value, 'type' => $type];
                return true;
            });
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(1);

        $this->repository->claimQueuedP2p(self::TEST_HASH, self::TEST_PID);

        $this->assertEquals(Constants::STATUS_SENDING, $bindCalls[':newStatus']['value']);
        $this->assertEquals(self::TEST_PID, $bindCalls[':pid']['value']);
        $this->assertEquals(self::TEST_HASH, $bindCalls[':hash']['value']);
        $this->assertEquals(Constants::STATUS_QUEUED, $bindCalls[':currentStatus']['value']);
    }

    /**
     * Test claimQueuedP2p uses atomic WHERE clause with status check
     */
    public function testClaimQueuedP2pUsesAtomicWhereClause(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('WHERE hash = :hash AND status = :currentStatus'),
                $this->stringContains('SET status = :newStatus')
            ))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(1);

        $this->repository->claimQueuedP2p(self::TEST_HASH, self::TEST_PID);
    }

    // =========================================================================
    // getStuckSendingP2ps() Tests
    // =========================================================================

    /**
     * Test getStuckSendingP2ps returns stuck records
     */
    public function testGetStuckSendingP2psReturnsStuckRecords(): void
    {
        $expectedRecords = [
            [
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_SENDING,
                'sending_started_at' => '2026-01-01 00:00:00.000000',
                'sending_worker_pid' => self::TEST_PID,
            ],
            [
                'hash' => 'def456abc789012345678901234567890123456789012345678901234567efgh',
                'status' => Constants::STATUS_SENDING,
                'sending_started_at' => '2026-01-01 00:01:00.000000',
                'sending_worker_pid' => 99999,
            ],
        ];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($expectedRecords);

        $result = $this->repository->getStuckSendingP2ps(300);

        $this->assertCount(2, $result);
        $this->assertEquals(self::TEST_HASH, $result[0]['hash']);
        $this->assertEquals(Constants::STATUS_SENDING, $result[0]['status']);
    }

    /**
     * Test getStuckSendingP2ps returns empty array when no stuck P2Ps exist
     */
    public function testGetStuckSendingP2psReturnsEmptyArrayWhenNone(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getStuckSendingP2ps(300);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getStuckSendingP2ps returns empty array on PDOException
     */
    public function testGetStuckSendingP2psReturnsEmptyArrayOnPdoException(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->getStuckSendingP2ps(300);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getStuckSendingP2ps binds status and timeout parameters
     */
    public function testGetStuckSendingP2psBindsCorrectParameters(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);

        $bindCalls = [];
        $this->mockStmt->method('bindValue')
            ->willReturnCallback(function ($param, $value, $type = null) use (&$bindCalls) {
                $bindCalls[$param] = ['value' => $value, 'type' => $type];
                return true;
            });
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $this->repository->getStuckSendingP2ps(600);

        $this->assertEquals(Constants::STATUS_SENDING, $bindCalls[':status']['value']);
        $this->assertEquals(600, $bindCalls[':timeout']['value']);
        $this->assertEquals(PDO::PARAM_INT, $bindCalls[':timeout']['type']);
    }

    /**
     * Test getStuckSendingP2ps uses default timeout from Constants
     */
    public function testGetStuckSendingP2psUsesDefaultTimeout(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);

        $bindCalls = [];
        $this->mockStmt->method('bindValue')
            ->willReturnCallback(function ($param, $value, $type = null) use (&$bindCalls) {
                $bindCalls[$param] = ['value' => $value, 'type' => $type];
                return true;
            });
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $this->repository->getStuckSendingP2ps();

        $this->assertEquals(Constants::P2P_SENDING_TIMEOUT_SECONDS, $bindCalls[':timeout']['value']);
    }

    // =========================================================================
    // recoverStuckP2p() Tests
    // =========================================================================

    /**
     * Test recoverStuckP2p returns false when P2P is not found
     */
    public function testRecoverStuckP2pReturnsFalseWhenNotFound(): void
    {
        // getByHash uses AbstractRepository::execute() which calls $this->pdo->prepare()
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(false);

        $result = $this->repository->recoverStuckP2p(self::TEST_HASH);

        $this->assertFalse($result);
    }

    /**
     * Test recoverStuckP2p returns false when P2P status is not 'sending'
     */
    public function testRecoverStuckP2pReturnsFalseWhenStatusNotSending(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_QUEUED,
                'sending_worker_pid' => null,
            ]);

        $result = $this->repository->recoverStuckP2p(self::TEST_HASH);

        $this->assertFalse($result);
    }

    /**
     * Test recoverStuckP2p returns false when P2P status is completed
     */
    public function testRecoverStuckP2pReturnsFalseWhenStatusCompleted(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_COMPLETED,
                'sending_worker_pid' => null,
            ]);

        $result = $this->repository->recoverStuckP2p(self::TEST_HASH);

        $this->assertFalse($result);
    }

    /**
     * Test recoverStuckP2p succeeds when worker PID is zero (no PID recorded)
     *
     * When sending_worker_pid is 0 or null, the worker is treated as dead
     * and the P2P should be recovered to queued status.
     */
    public function testRecoverStuckP2pSucceedsWhenWorkerPidIsZero(): void
    {
        $mockStmtSelect = $this->createMock(PDOStatement::class);
        $mockStmtUpdate = $this->createMock(PDOStatement::class);

        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturnCallback(function ($query) use ($mockStmtSelect, $mockStmtUpdate, &$callCount) {
                $callCount++;
                // First prepare is for getByHash (SELECT), second is for the UPDATE
                if ($callCount === 1) {
                    return $mockStmtSelect;
                }
                return $mockStmtUpdate;
            });

        // SELECT: return a P2P in 'sending' status with PID 0
        $mockStmtSelect->method('execute')
            ->willReturn(true);
        $mockStmtSelect->method('fetch')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_SENDING,
                'sending_worker_pid' => 0,
            ]);

        // UPDATE: simulate successful recovery
        $mockStmtUpdate->method('bindValue')
            ->willReturn(true);
        $mockStmtUpdate->method('execute')
            ->willReturn(true);
        $mockStmtUpdate->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->recoverStuckP2p(self::TEST_HASH);

        $this->assertTrue($result);
    }

    /**
     * Test recoverStuckP2p UPDATE sets correct status and clears metadata
     */
    public function testRecoverStuckP2pSetsCorrectStatusAndClearsMetadata(): void
    {
        $mockStmtSelect = $this->createMock(PDOStatement::class);
        $mockStmtUpdate = $this->createMock(PDOStatement::class);

        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturnCallback(function ($query) use ($mockStmtSelect, $mockStmtUpdate, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $mockStmtSelect;
                }
                return $mockStmtUpdate;
            });

        $mockStmtSelect->method('execute')
            ->willReturn(true);
        $mockStmtSelect->method('fetch')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_SENDING,
                'sending_worker_pid' => 0,
            ]);

        $bindCalls = [];
        $mockStmtUpdate->method('bindValue')
            ->willReturnCallback(function ($param, $value, $type = null) use (&$bindCalls) {
                $bindCalls[$param] = ['value' => $value, 'type' => $type];
                return true;
            });
        $mockStmtUpdate->method('execute')
            ->willReturn(true);
        $mockStmtUpdate->method('rowCount')
            ->willReturn(1);

        $this->repository->recoverStuckP2p(self::TEST_HASH);

        $this->assertEquals(Constants::STATUS_QUEUED, $bindCalls[':newStatus']['value']);
        $this->assertEquals(self::TEST_HASH, $bindCalls[':hash']['value']);
        $this->assertEquals(Constants::STATUS_SENDING, $bindCalls[':currentStatus']['value']);
    }

    /**
     * Test recoverStuckP2p returns false on PDOException during UPDATE
     */
    public function testRecoverStuckP2pReturnsFalseOnPdoExceptionDuringUpdate(): void
    {
        $mockStmtSelect = $this->createMock(PDOStatement::class);
        $mockStmtUpdate = $this->createMock(PDOStatement::class);

        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturnCallback(function ($query) use ($mockStmtSelect, $mockStmtUpdate, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $mockStmtSelect;
                }
                return $mockStmtUpdate;
            });

        $mockStmtSelect->method('execute')
            ->willReturn(true);
        $mockStmtSelect->method('fetch')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_SENDING,
                'sending_worker_pid' => 0,
            ]);

        $mockStmtUpdate->method('bindValue')
            ->willReturn(true);
        $mockStmtUpdate->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->recoverStuckP2p(self::TEST_HASH);

        $this->assertFalse($result);
    }

    /**
     * Test recoverStuckP2p returns false when UPDATE rowCount is 0
     * (concurrent recovery by another worker)
     */
    public function testRecoverStuckP2pReturnsFalseWhenUpdateRowCountIsZero(): void
    {
        $mockStmtSelect = $this->createMock(PDOStatement::class);
        $mockStmtUpdate = $this->createMock(PDOStatement::class);

        $callCount = 0;
        $this->mockPdo->method('prepare')
            ->willReturnCallback(function ($query) use ($mockStmtSelect, $mockStmtUpdate, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $mockStmtSelect;
                }
                return $mockStmtUpdate;
            });

        $mockStmtSelect->method('execute')
            ->willReturn(true);
        $mockStmtSelect->method('fetch')
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => Constants::STATUS_SENDING,
                'sending_worker_pid' => 0,
            ]);

        $mockStmtUpdate->method('bindValue')
            ->willReturn(true);
        $mockStmtUpdate->method('execute')
            ->willReturn(true);
        $mockStmtUpdate->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->recoverStuckP2p(self::TEST_HASH);

        $this->assertFalse($result);
    }

    // =========================================================================
    // clearSendingMetadata() Tests
    // =========================================================================

    /**
     * Test clearSendingMetadata returns true on success
     */
    public function testClearSendingMetadataReturnsTrueOnSuccess(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);

        $result = $this->repository->clearSendingMetadata(self::TEST_HASH);

        $this->assertTrue($result);
    }

    /**
     * Test clearSendingMetadata returns false when execute fails
     */
    public function testClearSendingMetadataReturnsFalseOnFailure(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->clearSendingMetadata(self::TEST_HASH);

        $this->assertFalse($result);
    }

    /**
     * Test clearSendingMetadata query sets columns to NULL
     */
    public function testClearSendingMetadataQuerySetsColumnsToNull(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('sending_started_at = NULL'),
                $this->stringContains('sending_worker_pid = NULL'),
                $this->stringContains('WHERE hash = :hash')
            ))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);

        $this->repository->clearSendingMetadata(self::TEST_HASH);
    }
}
