<?php
/**
 * Unit Tests for P2pSenderRepository
 *
 * Tests P2P sender repository functionality including:
 * - Sender insertion
 * - Querying senders by hash
 * - Checking sender existence
 * - Deleting senders by hash
 * - Cleaning up old records
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\P2pSenderRepository;
use PDO;
use PDOStatement;

#[CoversClass(P2pSenderRepository::class)]
class P2pSenderRepositoryTest extends TestCase
{
    private P2pSenderRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_ADDRESS = 'http://test.example.com';
    private const TEST_PUBLIC_KEY = 'test-public-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new P2pSenderRepository($this->mockPdo);
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

        $this->assertEquals('p2p_senders', $property->getValue($this->repository));
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
    // insertSender() Tests
    // =========================================================================

    /**
     * Test insertSender returns last insert ID on success
     */
    public function testInsertSenderReturnsIdOnSuccess(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockPdo->method('lastInsertId')
            ->willReturn('42');

        $result = $this->repository->insertSender(
            self::TEST_HASH,
            self::TEST_ADDRESS,
            self::TEST_PUBLIC_KEY
        );

        $this->assertEquals('42', $result);
    }

    /**
     * Test insertSender returns false on duplicate (INSERT IGNORE)
     */
    public function testInsertSenderReturnsFalseOnDuplicate(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        // lastInsertId returns '0' when INSERT IGNORE skips a duplicate
        $this->mockPdo->method('lastInsertId')
            ->willReturn('0');

        $result = $this->repository->insertSender(
            self::TEST_HASH,
            self::TEST_ADDRESS,
            self::TEST_PUBLIC_KEY
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // getSendersByHash() Tests
    // =========================================================================

    /**
     * Test getSendersByHash returns senders for a hash
     */
    public function testGetSendersByHashReturnsSenders(): void
    {
        $expectedSenders = [
            ['id' => 1, 'hash' => self::TEST_HASH, 'sender_address' => 'http://sender1.test', 'sender_public_key' => 'key1'],
            ['id' => 2, 'hash' => self::TEST_HASH, 'sender_address' => 'http://sender2.test', 'sender_public_key' => 'key2'],
        ];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($expectedSenders);

        $result = $this->repository->getSendersByHash(self::TEST_HASH);

        $this->assertCount(2, $result);
        $this->assertEquals('http://sender1.test', $result[0]['sender_address']);
        $this->assertEquals('http://sender2.test', $result[1]['sender_address']);
    }

    /**
     * Test getSendersByHash returns empty array when no senders exist
     */
    public function testGetSendersByHashReturnsEmptyArrayWhenNone(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getSendersByHash(self::TEST_HASH);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // senderExists() Tests
    // =========================================================================

    /**
     * Test senderExists returns true when sender exists
     */
    public function testSenderExistsReturnsTrueWhenExists(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['count' => 1]);

        $result = $this->repository->senderExists(self::TEST_HASH, self::TEST_ADDRESS);

        $this->assertTrue($result);
    }

    /**
     * Test senderExists returns false when sender does not exist
     */
    public function testSenderExistsReturnsFalseWhenNotExists(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['count' => 0]);

        $result = $this->repository->senderExists(self::TEST_HASH, self::TEST_ADDRESS);

        $this->assertFalse($result);
    }

    // =========================================================================
    // deleteSendersByHash() Tests
    // =========================================================================

    /**
     * Test deleteSendersByHash delegates to delete method
     */
    public function testDeleteSendersByHashReturnsCount(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(3);

        $result = $this->repository->deleteSendersByHash(self::TEST_HASH);

        $this->assertEquals(3, $result);
    }

    // =========================================================================
    // deleteOldRecords() Tests
    // =========================================================================

    /**
     * Test deleteOldRecords returns count of deleted records
     */
    public function testDeleteOldRecordsReturnsDeletedCount(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('bindValue')
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(5);

        $result = $this->repository->deleteOldRecords(1);

        $this->assertEquals(5, $result);
    }

    /**
     * Test deleteOldRecords accepts custom days parameter
     */
    public function testDeleteOldRecordsAcceptsCustomDays(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 7, PDO::PARAM_INT)
            ->willReturn(true);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(2);

        $result = $this->repository->deleteOldRecords(7);

        $this->assertEquals(2, $result);
    }

    // =========================================================================
    // Allowed Columns Tests
    // =========================================================================

    /**
     * Test allowed columns include expected fields
     */
    public function testAllowedColumnsIncludeExpectedFields(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);
        $columns = $property->getValue($this->repository);

        $this->assertContains('id', $columns);
        $this->assertContains('hash', $columns);
        $this->assertContains('sender_address', $columns);
        $this->assertContains('sender_public_key', $columns);
        $this->assertContains('created_at', $columns);
    }
}
