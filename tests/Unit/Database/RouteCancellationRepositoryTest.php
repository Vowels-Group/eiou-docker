<?php
/**
 * Unit Tests for RouteCancellationRepository
 *
 * Tests route cancellation repository functionality including:
 * - Constructor configuration
 * - Inserting cancellations
 * - Acknowledging cancellations
 * - Querying cancellations by hash
 * - Cleaning up old records
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\RouteCancellationRepository;
use PDO;
use PDOStatement;

#[CoversClass(RouteCancellationRepository::class)]
class RouteCancellationRepositoryTest extends TestCase
{
    private RouteCancellationRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_CONTACT_ADDRESS = 'http://contact.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new RouteCancellationRepository($this->mockPdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('tableName');
        $property->setAccessible(true);

        $this->assertEquals('route_cancellations', $property->getValue($this->repository));
    }

    /**
     * Test constructor sets primary key correctly
     */
    public function testConstructorSetsPrimaryKey(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('primaryKey');
        $property->setAccessible(true);

        $this->assertEquals('id', $property->getValue($this->repository));
    }

    // =========================================================================
    // insertCancellation() Tests
    // =========================================================================

    /**
     * Test insertCancellation executes insert and returns true on success
     */
    public function testInsertCancellationExecutesInsert(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);

        $result = $this->repository->insertCancellation(
            self::TEST_HASH,
            42,
            self::TEST_CONTACT_ADDRESS
        );

        $this->assertTrue($result);
    }

    // =========================================================================
    // acknowledge() Tests
    // =========================================================================

    /**
     * Test acknowledge updates status to acknowledged and returns true
     */
    public function testAcknowledgeUpdatesStatus(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->acknowledge(self::TEST_HASH, self::TEST_CONTACT_ADDRESS);

        $this->assertTrue($result);
    }

    /**
     * Test acknowledge returns false when no matching sent record found
     */
    public function testAcknowledgeReturnsFalseWhenNotFound(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->acknowledge(self::TEST_HASH, self::TEST_CONTACT_ADDRESS);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getCancellationsByHash() Tests
    // =========================================================================

    /**
     * Test getCancellationsByHash returns records for a hash
     */
    public function testGetCancellationsByHashReturnsRecords(): void
    {
        $expectedRecords = [
            ['id' => 1, 'hash' => self::TEST_HASH, 'candidate_id' => 10, 'contact_address' => 'http://node1.test', 'status' => 'sent'],
            ['id' => 2, 'hash' => self::TEST_HASH, 'candidate_id' => 20, 'contact_address' => 'http://node2.test', 'status' => 'acknowledged'],
        ];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($expectedRecords);

        $result = $this->repository->getCancellationsByHash(self::TEST_HASH);

        $this->assertCount(2, $result);
        $this->assertEquals('sent', $result[0]['status']);
        $this->assertEquals('acknowledged', $result[1]['status']);
    }

    /**
     * Test getCancellationsByHash returns empty array when no records exist
     */
    public function testGetCancellationsByHashReturnsEmptyWhenNone(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getCancellationsByHash(self::TEST_HASH);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // deleteOldRecords() Tests
    // =========================================================================

    /**
     * Test deleteOldRecords removes old records and returns count
     */
    public function testDeleteOldRecordsRemovesOldRecords(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 14, PDO::PARAM_INT)
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(8);

        $result = $this->repository->deleteOldRecords(14);

        $this->assertEquals(8, $result);
    }
}
