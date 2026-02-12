<?php
/**
 * Unit Tests for P2pRelayedContactRepository
 *
 * Tests P2P relayed contact repository functionality including:
 * - Relayed contact insertion
 * - Querying relayed contacts by hash
 * - Deleting relayed contacts by hash
 * - Cleaning up old records
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\P2pRelayedContactRepository;
use PDO;
use PDOStatement;

#[CoversClass(P2pRelayedContactRepository::class)]
class P2pRelayedContactRepositoryTest extends TestCase
{
    private P2pRelayedContactRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_ADDRESS = 'http://test.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new P2pRelayedContactRepository($this->mockPdo);
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

        $this->assertEquals('p2p_relayed_contacts', $property->getValue($this->repository));
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
    // insertRelayedContact() Tests
    // =========================================================================

    /**
     * Test insertRelayedContact executes INSERT IGNORE query
     */
    public function testInsertRelayedContactExecutesQuery(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT IGNORE'))
            ->willReturn($this->mockStmt);
        $this->mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->repository->insertRelayedContact(self::TEST_HASH, self::TEST_ADDRESS);
    }

    // =========================================================================
    // getRelayedContactsByHash() Tests
    // =========================================================================

    /**
     * Test getRelayedContactsByHash returns contacts for a hash
     */
    public function testGetRelayedContactsByHashReturnsContacts(): void
    {
        $expectedContacts = [
            ['id' => 1, 'hash' => self::TEST_HASH, 'contact_address' => 'http://contact1.test'],
            ['id' => 2, 'hash' => self::TEST_HASH, 'contact_address' => 'http://contact2.test'],
        ];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($expectedContacts);

        $result = $this->repository->getRelayedContactsByHash(self::TEST_HASH);

        $this->assertCount(2, $result);
        $this->assertEquals('http://contact1.test', $result[0]['contact_address']);
        $this->assertEquals('http://contact2.test', $result[1]['contact_address']);
    }

    /**
     * Test getRelayedContactsByHash returns empty array when no contacts exist
     */
    public function testGetRelayedContactsByHashReturnsEmptyArrayWhenNone(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getRelayedContactsByHash(self::TEST_HASH);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // deleteByHash() Tests
    // =========================================================================

    /**
     * Test deleteByHash returns count of deleted records
     */
    public function testDeleteByHashReturnsCount(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(3);

        $result = $this->repository->deleteByHash(self::TEST_HASH);

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
        $this->assertContains('contact_address', $columns);
        $this->assertContains('created_at', $columns);
    }
}
