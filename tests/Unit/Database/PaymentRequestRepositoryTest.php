<?php
/**
 * Unit Tests for PaymentRequestRepository
 *
 * Tests all database operations for the payment_requests table including:
 * - Creating request records
 * - Retrieving by request_id
 * - Listing pending incoming, all incoming, all outgoing
 * - Updating status
 * - Counting pending incoming
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PaymentRequestRepository;
use PDO;
use PDOStatement;

#[CoversClass(PaymentRequestRepository::class)]
class PaymentRequestRepositoryTest extends TestCase
{
    private PaymentRequestRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const TEST_REQUEST_ID = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
    private const TEST_PUBKEY_HASH = 'pubkey9abc1234567890abcdef1234567890abcdef1234567890abcdef123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPdo  = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new PaymentRequestRepository($this->mockPdo);
    }

    // =========================================================================
    // Constructor / configuration
    // =========================================================================

    public function testConstructorSetsTableName(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $prop = $reflection->getProperty('tableName');
        $prop->setAccessible(true);

        $this->assertEquals('payment_requests', $prop->getValue($this->repository));
    }

    public function testConstructorSetsSplitAmountColumns(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $prop = $reflection->getProperty('splitAmountColumns');
        $prop->setAccessible(true);

        $this->assertContains('amount', $prop->getValue($this->repository));
    }

    public function testAllowedColumnsIncludesRequiredFields(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $prop = $reflection->getProperty('allowedColumns');
        $prop->setAccessible(true);

        $allowed = $prop->getValue($this->repository);
        $this->assertContains('request_id', $allowed);
        $this->assertContains('direction', $allowed);
        $this->assertContains('status', $allowed);
        $this->assertContains('amount_whole', $allowed);
        $this->assertContains('amount_frac', $allowed);
        $this->assertContains('currency', $allowed);
    }

    // =========================================================================
    // createRequest()
    // =========================================================================

    public function testCreateRequestExecutesInsert(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockPdo->method('lastInsertId')
            ->willReturn('42');

        $result = $this->repository->createRequest([
            'request_id'   => self::TEST_REQUEST_ID,
            'direction'    => 'outgoing',
            'status'       => 'pending',
            'amount_whole' => 10,
            'amount_frac'  => 0,
            'currency'     => 'USD',
            'created_at'   => '2026-01-01 00:00:00',
        ]);

        $this->assertNotFalse($result);
    }

    // =========================================================================
    // getByRequestId()
    // =========================================================================

    public function testGetByRequestIdReturnsMatchingRow(): void
    {
        $expectedRow = [
            'id'         => 1,
            'request_id' => self::TEST_REQUEST_ID,
            'direction'  => 'incoming',
            'status'     => 'pending',
            'amount_whole' => 10,
            'amount_frac'  => 0,
            'currency'   => 'USD',
        ];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn($expectedRow);

        $result = $this->repository->getByRequestId(self::TEST_REQUEST_ID);

        $this->assertNotNull($result);
        $this->assertEquals(self::TEST_REQUEST_ID, $result['request_id']);
        $this->assertEquals('pending', $result['status']);
    }

    public function testGetByRequestIdReturnsNullWhenNotFound(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getByRequestId('nonexistent-id');

        $this->assertNull($result);
    }

    // =========================================================================
    // getPendingIncoming()
    // =========================================================================

    public function testGetPendingIncomingReturnsPendingRows(): void
    {
        $rows = [
            ['id' => 1, 'request_id' => self::TEST_REQUEST_ID, 'direction' => 'incoming', 'status' => 'pending', 'amount_whole' => 5, 'amount_frac' => 0, 'currency' => 'USD'],
            ['id' => 2, 'request_id' => 'req-456', 'direction' => 'incoming', 'status' => 'pending', 'amount_whole' => 20, 'amount_frac' => 0, 'currency' => 'USD'],
        ];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($rows);

        $result = $this->repository->getPendingIncoming();

        $this->assertCount(2, $result);
        $this->assertEquals('pending', $result[0]['status']);
    }

    public function testGetPendingIncomingReturnsEmptyArrayWhenNone(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getPendingIncoming();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getAllIncoming()
    // =========================================================================

    public function testGetAllIncomingReturnsRowsWithLimit(): void
    {
        $rows = [
            ['id' => 1, 'direction' => 'incoming', 'status' => 'pending', 'amount_whole' => 5, 'amount_frac' => 0, 'currency' => 'USD'],
            ['id' => 2, 'direction' => 'incoming', 'status' => 'approved', 'amount_whole' => 10, 'amount_frac' => 0, 'currency' => 'USD'],
        ];

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("direction = 'incoming'"))
            ->willReturn($this->mockStmt);
        $this->mockStmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 10, PDO::PARAM_INT);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($rows);

        $result = $this->repository->getAllIncoming(10);

        $this->assertCount(2, $result);
    }

    public function testGetAllIncomingUsesDefaultLimit(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 50, PDO::PARAM_INT);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $this->repository->getAllIncoming();
    }

    // =========================================================================
    // getAllOutgoing()
    // =========================================================================

    public function testGetAllOutgoingReturnsOutgoingRows(): void
    {
        $rows = [
            ['id' => 3, 'direction' => 'outgoing', 'status' => 'pending', 'amount_whole' => 25, 'amount_frac' => 0, 'currency' => 'USD'],
        ];

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("direction = 'outgoing'"))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue');
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($rows);

        $result = $this->repository->getAllOutgoing(50);

        $this->assertCount(1, $result);
        $this->assertEquals('outgoing', $result[0]['direction']);
    }

    // =========================================================================
    // updateStatus()
    // =========================================================================

    public function testUpdateStatusCallsUpdateWithStatusAndExtra(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateStatus(self::TEST_REQUEST_ID, 'approved', [
            'responded_at'   => '2026-01-02 00:00:00',
            'resulting_txid' => 'txid-abc123',
        ]);

        $this->assertTrue($result);
    }

    public function testUpdateStatusReturnsTrueEvenWhenNoRowsAffected(): void
    {
        // updateStatus uses update() which returns rowCount; >=0 means true
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->updateStatus(self::TEST_REQUEST_ID, 'cancelled');

        $this->assertTrue($result);
    }

    // =========================================================================
    // countPendingIncoming()
    // =========================================================================

    public function testCountPendingIncomingReturnsCount(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['count' => 3]);

        $result = $this->repository->countPendingIncoming();

        $this->assertEquals(3, $result);
    }

    public function testCountPendingIncomingReturnsZeroWhenNone(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['count' => 0]);

        $result = $this->repository->countPendingIncoming();

        $this->assertEquals(0, $result);
    }

    public function testCountPendingIncomingReturnsZeroOnQueryFailure(): void
    {
        $this->mockPdo->method('prepare')
            ->willThrowException(new \PDOException('DB error'));

        $result = $this->repository->countPendingIncoming();

        $this->assertEquals(0, $result);
    }
}
