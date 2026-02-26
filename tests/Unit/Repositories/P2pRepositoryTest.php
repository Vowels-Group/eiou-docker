<?php
/**
 * Unit Tests for P2pRepository
 *
 * Tests P2P repository database operations with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\P2pRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;
use PDOException;
use ReflectionClass;

#[CoversClass(P2pRepository::class)]
class P2pRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private P2pRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new P2pRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('p2p', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new P2pRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // isCompletedByHash() Tests
    // =========================================================================

    /**
     * Test isCompletedByHash returns true when P2P is completed
     */
    public function testIsCompletedByHashReturnsTrueWhenCompleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'hash' => 'abc123',
                'status' => Constants::STATUS_COMPLETED
            ]);

        $result = $this->repository->isCompletedByHash('abc123');

        $this->assertTrue($result);
    }

    /**
     * Test isCompletedByHash returns false when P2P is not completed
     */
    public function testIsCompletedByHashReturnsFalseWhenNotCompleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'hash' => 'abc123',
                'status' => Constants::STATUS_PENDING
            ]);

        $result = $this->repository->isCompletedByHash('abc123');

        $this->assertFalse($result);
    }

    /**
     * Test isCompletedByHash returns false when P2P not found
     */
    public function testIsCompletedByHashReturnsFalseWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->isCompletedByHash('nonexistent');

        $this->assertFalse($result);
    }

    // =========================================================================
    // p2pExists() Tests
    // =========================================================================

    /**
     * Test p2pExists returns true when P2P exists
     */
    public function testP2pExistsReturnsTrueWhenExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 1]);

        $result = $this->repository->p2pExists('hash_123');

        $this->assertTrue($result);
    }

    /**
     * Test p2pExists returns false when P2P does not exist
     */
    public function testP2pExistsReturnsFalseWhenNotExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 0]);

        $result = $this->repository->p2pExists('nonexistent_hash');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getByHash() Tests
    // =========================================================================

    /**
     * Test getByHash returns P2P record when found
     */
    public function testGetByHashReturnsP2pWhenFound(): void
    {
        $expectedP2p = [
            'id' => 1,
            'hash' => 'abc123',
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'pending'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE hash = :hash'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedP2p);

        $result = $this->repository->getByHash('abc123');

        $this->assertEquals($expectedP2p, $result);
    }

    /**
     * Test getByHash returns null when not found
     */
    public function testGetByHashReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getByHash('nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // insertP2pRequest() Tests
    // =========================================================================

    /**
     * Test insertP2pRequest inserts record successfully
     */
    public function testInsertP2pRequestInsertsSuccessfully(): void
    {
        $request = [
            'hash' => 'hash_123',
            'salt' => 'salt_abc',
            'time' => 1234567890,
            'expiration' => 1234568190,
            'currency' => 'USD',
            'amount' => 1000,
            'feeAmount' => 10,
            'requestLevel' => 1,
            'maxRequestLevel' => 6,
            'senderPublicKey' => 'pubkey_xyz',
            'senderAddress' => 'addr_123'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('42');

        $result = $this->repository->insertP2pRequest($request, 'dest_addr', 'Test description');

        $decoded = json_decode($result, true);
        $this->assertEquals('received', $decoded['status']);
        $this->assertEquals('p2p recorded successfully', $decoded['message']);
    }

    /**
     * Test insertP2pRequest returns rejected on failure
     */
    public function testInsertP2pRequestReturnsRejectedOnFailure(): void
    {
        $request = [
            'hash' => 'hash_123',
            'salt' => 'salt_abc',
            'time' => 1234567890,
            'expiration' => 1234568190,
            'currency' => 'USD',
            'amount' => 1000,
            'requestLevel' => 1,
            'maxRequestLevel' => 6,
            'senderPublicKey' => 'pubkey_xyz',
            'senderAddress' => 'addr_123'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->insertP2pRequest($request);

        $decoded = json_decode($result, true);
        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals('Failed to record p2p', $decoded['message']);
    }

    // =========================================================================
    // getExpiringP2pMessages() Tests
    // =========================================================================

    /**
     * Test getExpiringP2pMessages returns non-final status messages
     */
    public function testGetExpiringP2pMessagesReturnsNonFinalMessages(): void
    {
        $expectedMessages = [
            ['id' => 1, 'hash' => 'hash1', 'status' => 'pending'],
            ['id' => 2, 'hash' => 'hash2', 'status' => 'sent']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("status NOT IN ('completed', 'expired', 'cancelled')"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 5, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedMessages);

        $result = $this->repository->getExpiringP2pMessages(5);

        $this->assertEquals($expectedMessages, $result);
    }

    /**
     * Test getExpiringP2pMessages returns empty array on exception
     */
    public function testGetExpiringP2pMessagesReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getExpiringP2pMessages();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // getQueuedP2pMessages() Tests
    // =========================================================================

    /**
     * Test getQueuedP2pMessages returns queued messages
     */
    public function testGetQueuedP2pMessagesReturnsQueuedMessages(): void
    {
        $expectedMessages = [
            ['id' => 1, 'hash' => 'hash1', 'status' => 'queued']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE status = :status'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedMessages);

        $result = $this->repository->getQueuedP2pMessages('queued', 5);

        $this->assertEquals($expectedMessages, $result);
    }

    /**
     * Test getQueuedP2pMessages returns empty array on exception
     */
    public function testGetQueuedP2pMessagesReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getQueuedP2pMessages();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // getCountP2pMessagesWithStatus() Tests
    // =========================================================================

    /**
     * Test getCountP2pMessagesWithStatus returns count
     */
    public function testGetCountP2pMessagesWithStatusReturnsCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT count(*)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(15);

        $result = $this->repository->getCountP2pMessagesWithStatus('queued');

        $this->assertEquals(15, $result);
    }

    /**
     * Test getCountP2pMessagesWithStatus returns 0 when no results
     */
    public function testGetCountP2pMessagesWithStatusReturnsZeroWhenNoResults(): void
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
            ->method('fetchColumn')
            ->willReturn(null);

        $result = $this->repository->getCountP2pMessagesWithStatus('nonexistent');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getCreditInP2p() Tests
    // =========================================================================

    /**
     * Test getCreditInP2p returns total amount on hold
     */
    public function testGetCreditInP2pReturnsTotalAmount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SUM(amount)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['total_amount' => 5000]);

        $result = $this->repository->getCreditInP2p('pubkey_123');

        $this->assertEquals(5000, $result);
    }

    /**
     * Test getCreditInP2p returns 0 when no P2P on hold
     */
    public function testGetCreditInP2pReturnsZeroWhenNoP2pOnHold(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['total_amount' => null]);

        $result = $this->repository->getCreditInP2p('pubkey_no_p2p');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getUserTotalEarnings() Tests
    // =========================================================================

    /**
     * Test getUserTotalEarnings returns total fee earnings
     */
    public function testGetUserTotalEarningsReturnsTotalFees(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SUM(my_fee_amount)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(250.50);

        $result = $this->repository->getUserTotalEarnings();

        $this->assertEquals(250.50, $result);
    }

    /**
     * Test getUserTotalEarnings returns 0 when no earnings
     */
    public function testGetUserTotalEarningsReturnsZeroWhenNoEarnings(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(null);

        $result = $this->repository->getUserTotalEarnings();

        $this->assertEquals(0.00, $result);
    }

    // =========================================================================
    // updateIncomingTxid() Tests
    // =========================================================================

    /**
     * Test updateIncomingTxid updates txid successfully
     */
    public function testUpdateIncomingTxidUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateIncomingTxid('hash_123', 'txid_456');

        $this->assertTrue($result);
    }

    // =========================================================================
    // updateOutgoingTxid() Tests
    // =========================================================================

    /**
     * Test updateOutgoingTxid updates txid successfully
     */
    public function testUpdateOutgoingTxidUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateOutgoingTxid('hash_123', 'txid_789');

        $this->assertTrue($result);
    }

    // =========================================================================
    // updateStatus() Tests
    // =========================================================================

    /**
     * Test updateStatus updates status without completion timestamp
     */
    public function testUpdateStatusUpdatesWithoutCompletionTimestamp(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateStatus('hash_123', 'sent', false);

        $this->assertTrue($result);
    }

    /**
     * Test updateStatus updates status with completion timestamp
     */
    public function testUpdateStatusUpdatesWithCompletionTimestamp(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('completed_at = CURRENT_TIMESTAMP'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->updateStatus('hash_123', Constants::STATUS_COMPLETED, true);

        $this->assertTrue($result);
    }

    // =========================================================================
    // getByStatus() Tests
    // =========================================================================

    /**
     * Test getByStatus returns P2P messages with specified status
     */
    public function testGetByStatusReturnsMessagesWithStatus(): void
    {
        $expectedMessages = [
            ['id' => 1, 'hash' => 'hash1', 'status' => 'pending'],
            ['id' => 2, 'hash' => 'hash2', 'status' => 'pending']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedMessages);

        $result = $this->repository->getByStatus('pending');

        $this->assertEquals($expectedMessages, $result);
    }

    // =========================================================================
    // getBySenderAddress() Tests
    // =========================================================================

    /**
     * Test getBySenderAddress returns P2P messages for sender
     */
    public function testGetBySenderAddressReturnsMessages(): void
    {
        $expectedMessages = [
            ['id' => 1, 'sender_address' => 'addr_123', 'amount' => 1000]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedMessages);

        $result = $this->repository->getBySenderAddress('addr_123');

        $this->assertEquals($expectedMessages, $result);
    }

    // =========================================================================
    // getByDestinationAddress() Tests
    // =========================================================================

    /**
     * Test getByDestinationAddress returns P2P messages for destination
     */
    public function testGetByDestinationAddressReturnsMessages(): void
    {
        $expectedMessages = [
            ['id' => 1, 'destination_address' => 'dest_456', 'amount' => 2000]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedMessages);

        $result = $this->repository->getByDestinationAddress('dest_456');

        $this->assertEquals($expectedMessages, $result);
    }

    // =========================================================================
    // getExpiredP2p() Tests
    // =========================================================================

    /**
     * Test getExpiredP2p returns expired messages
     */
    public function testGetExpiredP2pReturnsExpiredMessages(): void
    {
        $expectedMessages = [
            ['id' => 1, 'hash' => 'hash1', 'expiration' => 1000000000],
            ['id' => 2, 'hash' => 'hash2', 'expiration' => 1000000100]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('expiration < :currentTime'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':currentTime', 1000000200, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedMessages);

        $result = $this->repository->getExpiredP2p(1000000200);

        $this->assertEquals($expectedMessages, $result);
    }

    /**
     * Test getExpiredP2p with limit parameter
     */
    public function testGetExpiredP2pWithLimitParameter(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('LIMIT :limit'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getExpiredP2p(1000000000, 10);

        $this->assertEquals([], $result);
    }

    /**
     * Test getExpiredP2p returns empty array on exception
     */
    public function testGetExpiredP2pReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getExpiredP2p(1000000000);

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // getStatistics() Tests
    // =========================================================================

    /**
     * Test getStatistics returns P2P statistics
     */
    public function testGetStatisticsReturnsP2pStatistics(): void
    {
        $expectedStats = [
            'total_count' => 100,
            'total_amount' => 100000,
            'average_amount' => 1000.0,
            'unique_senders' => 25,
            'completed_count' => 75,
            'queued_count' => 10,
            'sent_count' => 5,
            'expired_count' => 8,
            'cancelled_count' => 2
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('COUNT(*)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getStatistics();

        $this->assertEquals($expectedStats, $result);
    }

    /**
     * Test getStatistics returns empty array on failure
     */
    public function testGetStatisticsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStatistics();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // updateDestinationAddress() Tests
    // =========================================================================

    /**
     * Test updateDestinationAddress updates successfully
     */
    public function testUpdateDestinationAddressUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateDestinationAddress('hash_123', 'new_dest_addr');

        $this->assertTrue($result);
    }

    // =========================================================================
    // updateFeeAmount() Tests
    // =========================================================================

    /**
     * Test updateFeeAmount updates successfully
     */
    public function testUpdateFeeAmountUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateFeeAmount('hash_123', 15.50);

        $this->assertTrue($result);
    }

    // =========================================================================
    // updateDescription() Tests
    // =========================================================================

    /**
     * Test updateDescription updates successfully
     */
    public function testUpdateDescriptionUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateDescription('hash_123', 'New description');

        $this->assertTrue($result);
    }

    // =========================================================================
    // deleteOldExpired() Tests
    // =========================================================================

    /**
     * Test deleteOldExpired deletes old expired records
     */
    public function testDeleteOldExpiredDeletesOldRecords(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("status = 'expired'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 30, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(25);

        $result = $this->repository->deleteOldExpired(30);

        $this->assertEquals(25, $result);
    }

    /**
     * Test deleteOldExpired returns 0 on exception
     */
    public function testDeleteOldExpiredReturnsZeroOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Delete failed'));

        $result = $this->repository->deleteOldExpired();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Allowed Columns Tests
    // =========================================================================

    /**
     * Test allowed columns are properly defined
     */
    public function testAllowedColumnsAreDefined(): void
    {
        $reflection = new ReflectionClass($this->repository);
        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);
        $allowedColumns = $property->getValue($this->repository);

        $expectedColumns = [
            'id', 'hash', 'salt', 'time', 'expiration', 'currency', 'amount',
            'my_fee_amount', 'destination_address', 'destination_pubkey',
            'destination_signature', 'request_level', 'max_request_level',
            'sender_public_key', 'sender_address', 'sender_signature',
            'description', 'status', 'created_at', 'incoming_txid',
            'outgoing_txid', 'completed_at'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $allowedColumns);
        }
        // Two-phase selection column
        $this->assertContains('contacts_relayed_count', $allowedColumns);
    }

    // =========================================================================
    // updateContactsRelayedCount() Tests
    // =========================================================================

    /**
     * Test updateContactsRelayedCount updates the relayed count
     */
    public function testUpdateContactsRelayedCountSuccess(): void
    {
        $this->pdo->method('prepare')
            ->willReturn($this->stmt);
        $this->stmt->method('execute')
            ->willReturn(true);
        $this->stmt->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateContactsRelayedCount('test-hash', 3);

        $this->assertTrue($result);
    }

    // =========================================================================
    // getTrackingCounts() Tests
    // =========================================================================

    /**
     * Test getTrackingCounts returns contacts_relayed_count in result
     */
    public function testGetTrackingCountsIncludesRelayedCount(): void
    {
        $expectedResult = [
            'contacts_sent_count' => 2,
            'contacts_responded_count' => 1,
            'contacts_relayed_count' => 1,
            'fast' => 0,
        ];

        $this->pdo->method('prepare')
            ->willReturn($this->stmt);
        $this->stmt->method('execute')
            ->willReturn(true);
        $this->stmt->method('fetch')
            ->willReturn($expectedResult);

        $result = $this->repository->getTrackingCounts('test-hash');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('contacts_relayed_count', $result);
        $this->assertEquals(1, $result['contacts_relayed_count']);
        $this->assertEquals(2, $result['contacts_sent_count']);
        $this->assertEquals(1, $result['contacts_responded_count']);
    }

    /**
     * Test getTrackingCounts returns null when hash not found
     */
    public function testGetTrackingCountsReturnsNullWhenNotFound(): void
    {
        $this->pdo->method('prepare')
            ->willReturn($this->stmt);
        $this->stmt->method('execute')
            ->willReturn(true);
        $this->stmt->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getTrackingCounts('nonexistent-hash');

        $this->assertNull($result);
    }

    // =========================================================================
    // getAwaitingApprovalList() Tests
    // =========================================================================

    /**
     * Test getAwaitingApprovalList returns originator records
     */
    public function testGetAwaitingApprovalListReturnsOriginatorRecords(): void
    {
        $expectedRows = [
            [
                'hash' => 'abc123',
                'amount' => 1000,
                'currency' => 'USD',
                'destination_address' => 'http://bob:8080',
                'my_fee_amount' => 10,
                'rp2p_amount' => 1010,
                'fast' => 1,
                'created_at' => '2026-02-26 10:00:00',
            ],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->callback(function ($query) {
                return strpos($query, 'status = :status') !== false
                    && strpos($query, 'destination_address IS NOT NULL') !== false
                    && strpos($query, 'ORDER BY created_at ASC') !== false;
            }))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':status', Constants::STATUS_AWAITING_APPROVAL, PDO::PARAM_STR);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRows);

        $result = $this->repository->getAwaitingApprovalList();

        $this->assertCount(1, $result);
        $this->assertEquals('abc123', $result[0]['hash']);
        $this->assertEquals('http://bob:8080', $result[0]['destination_address']);
    }

    /**
     * Test getAwaitingApprovalList returns empty array on failure
     */
    public function testGetAwaitingApprovalListReturnsEmptyOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->getAwaitingApprovalList();

        $this->assertEmpty($result);
    }
}
