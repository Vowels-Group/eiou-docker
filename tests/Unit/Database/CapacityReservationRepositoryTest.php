<?php
/**
 * Unit Tests for CapacityReservationRepository
 *
 * Tests capacity reservation repository functionality including:
 * - Constructor configuration
 * - Creating reservations
 * - Querying total reserved amounts
 * - Releasing reservations by hash and contact
 * - Committing reservations
 * - Getting active reservations
 * - Cleaning up old records
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\CapacityReservationRepository;
use PDO;
use PDOStatement;

#[CoversClass(CapacityReservationRepository::class)]
class CapacityReservationRepositoryTest extends TestCase
{
    private CapacityReservationRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_PUBKEY_HASH = 'pubkey-hash-1234567890abcdef1234567890abcdef1234567890abcdef12345678';
    private const TEST_CURRENCY = 'USD';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new CapacityReservationRepository($this->mockPdo);
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

        $this->assertEquals('capacity_reservations', $property->getValue($this->repository));
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
    // createReservation() Tests
    // =========================================================================

    /**
     * Test createReservation executes insert and returns true on success
     */
    public function testCreateReservationExecutesInsert(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);

        $result = $this->repository->createReservation(
            self::TEST_HASH,
            self::TEST_PUBKEY_HASH,
            1000,
            1050,
            self::TEST_CURRENCY
        );

        $this->assertTrue($result);
    }

    // =========================================================================
    // getTotalReservedForPubkey() Tests
    // =========================================================================

    /**
     * Test getTotalReservedForPubkey returns sum of active reservations
     */
    public function testGetTotalReservedForPubkeyReturnsSumOfActive(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['total' => 5000]);

        $result = $this->repository->getTotalReservedForPubkey(self::TEST_PUBKEY_HASH);

        $this->assertEquals(5000, $result);
    }

    /**
     * Test getTotalReservedForPubkey returns zero when no active reservations
     */
    public function testGetTotalReservedForPubkeyReturnsZeroWhenNone(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['total' => 0]);

        $result = $this->repository->getTotalReservedForPubkey(self::TEST_PUBKEY_HASH);

        $this->assertEquals(0, $result);
    }

    /**
     * Test getTotalReservedForPubkey with currency filter
     */
    public function testGetTotalReservedForPubkeyWithCurrencyFilter(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('currency = :currency'))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetch')
            ->willReturn(['total' => 3000]);

        $result = $this->repository->getTotalReservedForPubkey(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertEquals(3000, $result);
    }

    // =========================================================================
    // releaseByHash() Tests
    // =========================================================================

    /**
     * Test releaseByHash updates active reservations to released
     */
    public function testReleaseByHashUpdatesActiveReservations(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(2);

        $result = $this->repository->releaseByHash(self::TEST_HASH);

        $this->assertEquals(2, $result);
    }

    /**
     * Test releaseByHash returns zero when no active reservations exist
     */
    public function testReleaseByHashReturnsZeroWhenNoneActive(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->releaseByHash(self::TEST_HASH);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // releaseByHashAndContact() Tests
    // =========================================================================

    /**
     * Test releaseByHashAndContact updates a specific reservation
     */
    public function testReleaseByHashAndContactUpdatesSpecificReservation(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->releaseByHashAndContact(
            self::TEST_HASH,
            self::TEST_PUBKEY_HASH,
            'cancelled'
        );

        $this->assertTrue($result);
    }

    // =========================================================================
    // commitByHash() Tests
    // =========================================================================

    /**
     * Test commitByHash updates active reservations to committed
     */
    public function testCommitByHashUpdatesActiveToCommitted(): void
    {
        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('rowCount')
            ->willReturn(3);

        $result = $this->repository->commitByHash(self::TEST_HASH);

        $this->assertEquals(3, $result);
    }

    // =========================================================================
    // getActiveByHash() Tests
    // =========================================================================

    /**
     * Test getActiveByHash returns active records for a hash
     */
    public function testGetActiveByHashReturnsActiveRecords(): void
    {
        $expectedRecords = [
            ['id' => 1, 'hash' => self::TEST_HASH, 'status' => 'active', 'total_amount' => 1000],
            ['id' => 2, 'hash' => self::TEST_HASH, 'status' => 'active', 'total_amount' => 2000],
        ];

        $this->mockPdo->method('prepare')
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')
            ->willReturn(true);
        $this->mockStmt->method('fetchAll')
            ->willReturn($expectedRecords);

        $result = $this->repository->getActiveByHash(self::TEST_HASH);

        $this->assertCount(2, $result);
        $this->assertEquals(1000, $result[0]['total_amount']);
        $this->assertEquals(2000, $result[1]['total_amount']);
    }

    // =========================================================================
    // deleteOldRecords() Tests
    // =========================================================================

    /**
     * Test deleteOldRecords removes released and committed records
     */
    public function testDeleteOldRecordsRemovesReleasedAndCommitted(): void
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
            ->willReturn(10);

        $result = $this->repository->deleteOldRecords(7);

        $this->assertEquals(10, $result);
    }
}
