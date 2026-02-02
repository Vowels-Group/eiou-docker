<?php
/**
 * Unit Tests for Rp2pRepository
 *
 * Tests RP2P (reverse P2P) repository database operations including
 * CRUD operations, hash lookups, statistics, and credit calculations.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Database\Rp2pRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(Rp2pRepository::class)]
class Rp2pRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private Rp2pRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new Rp2pRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorSetsPdoConnection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repo = new Rp2pRepository($pdo);

        $this->assertSame($pdo, $repo->getPdo());
    }

    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('rp2p', $this->repository->getTableName());
    }

    // =========================================================================
    // rp2pExists() Tests
    // =========================================================================

    public function testRp2pExistsReturnsTrueWhenHashExists(): void
    {
        $hash = 'abc123def456';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', $hash);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 1]);

        $result = $this->repository->rp2pExists($hash);

        $this->assertTrue($result);
    }

    public function testRp2pExistsReturnsFalseWhenHashNotExists(): void
    {
        $hash = 'nonexistent123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->rp2pExists($hash);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getByHash() Tests
    // =========================================================================

    public function testGetByHashReturnsDataWhenFound(): void
    {
        $hash = 'test-hash-123';
        $expectedData = [
            'id' => 1,
            'hash' => $hash,
            'time' => 1700000000,
            'amount' => 5000,
            'currency' => 'USD',
            'sender_public_key' => 'pubkey123',
            'sender_address' => 'http://test.example',
            'sender_signature' => 'sig123'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT * FROM rp2p WHERE hash = :hash'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':hash', $hash);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedData);

        $result = $this->repository->getByHash($hash);

        $this->assertEquals($expectedData, $result);
    }

    public function testGetByHashReturnsNullWhenNotFound(): void
    {
        $hash = 'nonexistent-hash';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getByHash($hash);

        $this->assertNull($result);
    }

    public function testGetByHashReturnsNullOnQueryFailure(): void
    {
        $hash = 'test-hash';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getByHash($hash);

        $this->assertNull($result);
    }

    // =========================================================================
    // insertRp2pRequest() Tests
    // =========================================================================

    public function testInsertRp2pRequestReturnsSuccessJson(): void
    {
        $request = [
            'hash' => 'new-hash-123',
            'time' => 1700000000,
            'amount' => 10000,
            'currency' => 'USD',
            'senderPublicKey' => 'pubkey-abc',
            'senderAddress' => 'http://sender.example',
            'signature' => 'signature-xyz'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO rp2p'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->insertRp2pRequest($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('received', $decoded['status']);
        $this->assertEquals('rp2p recorded successfully', $decoded['message']);
    }

    public function testInsertRp2pRequestReturnsRejectedJsonOnFailure(): void
    {
        $request = [
            'hash' => 'fail-hash',
            'time' => 1700000000,
            'amount' => 5000,
            'currency' => 'USD',
            'senderPublicKey' => 'pubkey',
            'senderAddress' => 'http://test.example',
            'signature' => 'sig'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->insertRp2pRequest($request);
        $decoded = json_decode($result, true);

        $this->assertEquals('rejected', $decoded['status']);
        $this->assertEquals('Failed to record rp2p', $decoded['message']);
    }

    // =========================================================================
    // getCreditInRp2p() Tests
    // =========================================================================

    public function testGetCreditInRp2pReturnsSumAmount(): void
    {
        $pubkey = 'test-pubkey';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SELECT SUM(amount) as total_amount"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':pubkey', $pubkey);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['total_amount' => 15000]);

        $result = $this->repository->getCreditInRp2p($pubkey);

        $this->assertEquals(15000.0, $result);
    }

    public function testGetCreditInRp2pReturnsZeroWhenNoResults(): void
    {
        $pubkey = 'empty-pubkey';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['total_amount' => null]);

        $result = $this->repository->getCreditInRp2p($pubkey);

        $this->assertEquals(0.0, $result);
    }

    public function testGetCreditInRp2pReturnsZeroOnQueryFailure(): void
    {
        $pubkey = 'test-pubkey';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getCreditInRp2p($pubkey);

        $this->assertEquals(0.0, $result);
    }

    // =========================================================================
    // getRecentRequests() Tests
    // =========================================================================

    public function testGetRecentRequestsReturnsLimitedResults(): void
    {
        $expectedRequests = [
            ['id' => 1, 'hash' => 'hash1', 'amount' => 1000],
            ['id' => 2, 'hash' => 'hash2', 'amount' => 2000],
            ['id' => 3, 'hash' => 'hash3', 'amount' => 3000],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('ORDER BY created_at DESC'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 3, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRequests);

        $result = $this->repository->getRecentRequests(3);

        $this->assertCount(3, $result);
        $this->assertEquals('hash1', $result[0]['hash']);
    }

    public function testGetRecentRequestsUsesDefaultLimit(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 10, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getRecentRequests();
    }

    public function testGetRecentRequestsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getRecentRequests();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getByTimeRange() Tests
    // =========================================================================

    public function testGetByTimeRangeReturnsMatchingRecords(): void
    {
        $startTime = 1700000000;
        $endTime = 1700100000;
        $expectedData = [
            ['id' => 1, 'time' => 1700050000, 'hash' => 'hash1'],
            ['id' => 2, 'time' => 1700060000, 'hash' => 'hash2'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE time >= :start_time AND time <= :end_time'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedData);

        $result = $this->repository->getByTimeRange($startTime, $endTime);

        $this->assertCount(2, $result);
    }

    public function testGetByTimeRangeReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getByTimeRange(1000, 2000);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getTotalAmountByAddress() Tests
    // =========================================================================

    public function testGetTotalAmountByAddressReturnsSummedAmount(): void
    {
        $address = 'http://test.example';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT SUM(amount) as total_amount'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['total_amount' => 25000]);

        $result = $this->repository->getTotalAmountByAddress($address);

        $this->assertEquals(25000.0, $result);
    }

    public function testGetTotalAmountByAddressReturnsZeroWhenNull(): void
    {
        $address = 'http://empty.example';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['total_amount' => null]);

        $result = $this->repository->getTotalAmountByAddress($address);

        $this->assertEquals(0.0, $result);
    }

    // =========================================================================
    // getStatistics() Tests
    // =========================================================================

    public function testGetStatisticsReturnsAggregatedData(): void
    {
        $expectedStats = [
            'total_count' => 100,
            'total_amount' => 500000,
            'average_amount' => 5000,
            'unique_senders' => 25,
            'earliest_request' => 1699000000,
            'latest_request' => 1700000000
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('COUNT(*) as total_count'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getStatistics();

        $this->assertEquals($expectedStats, $result);
    }

    public function testGetStatisticsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStatistics();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // deleteOldRecords() Tests
    // =========================================================================

    public function testDeleteOldRecordsReturnsDeletedCount(): void
    {
        $deletedCount = 15;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM rp2p'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 30, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn($deletedCount);

        $result = $this->repository->deleteOldRecords(30);

        $this->assertEquals(15, $result);
    }

    public function testDeleteOldRecordsReturnsZeroOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Delete failed'));

        $result = $this->repository->deleteOldRecords(7);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getByCurrency() Tests
    // =========================================================================

    public function testGetByCurrencyReturnsMatchingRecords(): void
    {
        $currency = 'USD';
        $expectedRecords = [
            ['id' => 1, 'currency' => 'USD', 'amount' => 1000],
            ['id' => 2, 'currency' => 'USD', 'amount' => 2000],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE currency = :value'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', $currency);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRecords);

        $result = $this->repository->getByCurrency($currency);

        $this->assertCount(2, $result);
    }

    // =========================================================================
    // countByAddress() Tests
    // =========================================================================

    public function testCountByAddressReturnsCount(): void
    {
        $address = 'http://test.example';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT COUNT(*) as count'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 5]);

        $result = $this->repository->countByAddress($address);

        $this->assertEquals(5, $result);
    }

    // =========================================================================
    // getByMinAmount() Tests
    // =========================================================================

    public function testGetByMinAmountReturnsRecordsAboveThreshold(): void
    {
        $minAmount = 10000.0;
        $expectedRecords = [
            ['id' => 1, 'amount' => 15000],
            ['id' => 2, 'amount' => 20000],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE amount >= :min_amount'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':min_amount', $minAmount);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRecords);

        $result = $this->repository->getByMinAmount($minAmount);

        $this->assertCount(2, $result);
    }

    public function testGetByMinAmountWithLimitAppliesLimit(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('LIMIT :limit'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getByMinAmount(1000.0, 5);
    }

    public function testGetByMinAmountReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getByMinAmount(1000.0);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // verifySignature() Tests
    // =========================================================================

    public function testVerifySignatureReturnsTrueWhenSignatureExists(): void
    {
        $hash = 'test-hash';
        $rp2pData = [
            'hash' => $hash,
            'sender_signature' => 'valid-signature-123'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($rp2pData);

        $result = $this->repository->verifySignature($hash);

        $this->assertTrue($result);
    }

    public function testVerifySignatureReturnsFalseWhenRecordNotFound(): void
    {
        $hash = 'nonexistent-hash';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->verifySignature($hash);

        $this->assertFalse($result);
    }

    public function testVerifySignatureReturnsFalseWhenSignatureEmpty(): void
    {
        $hash = 'test-hash';
        $rp2pData = [
            'hash' => $hash,
            'sender_signature' => ''
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($rp2pData);

        $result = $this->repository->verifySignature($hash);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getDuplicates() Tests
    // =========================================================================

    public function testGetDuplicatesReturnsHashesWithMultipleOccurrences(): void
    {
        $expectedDuplicates = [
            ['hash' => 'dup-hash-1', 'count' => 3],
            ['hash' => 'dup-hash-2', 'count' => 2],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('GROUP BY hash'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedDuplicates);

        $result = $this->repository->getDuplicates();

        $this->assertCount(2, $result);
        $this->assertEquals(3, $result[0]['count']);
    }

    public function testGetDuplicatesReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getDuplicates();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getBySenderAddress() Tests
    // =========================================================================

    public function testGetBySenderAddressReturnsMatchingRecords(): void
    {
        $address = 'http://sender.example';
        $expectedRecords = [
            ['id' => 1, 'sender_address' => $address],
            ['id' => 2, 'sender_address' => $address],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE sender_address = :value'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', $address);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRecords);

        $result = $this->repository->getBySenderAddress($address);

        $this->assertCount(2, $result);
    }

    public function testGetBySenderAddressWithLimitAppliesLimit(): void
    {
        $address = 'http://sender.example';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('LIMIT :limit'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getBySenderAddress($address, 5);
    }
}
