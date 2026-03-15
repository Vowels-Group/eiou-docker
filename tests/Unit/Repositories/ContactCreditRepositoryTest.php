<?php
/**
 * Unit Tests for ContactCreditRepository
 *
 * Tests contact credit table operations including:
 * - Getting available credit by pubkey hash
 * - Upserting available credit (insert and update)
 * - Creating initial credit entries for new contacts
 * - Deleting credit entries
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\ContactCreditRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;

#[CoversClass(ContactCreditRepository::class)]
class ContactCreditRepositoryTest extends TestCase
{
    private ContactCreditRepository $repository;
    private PDO $pdo;
    private PDOStatement $stmt;

    private const TEST_PUBKEY = 'test-public-key-abc123';
    private const TEST_PUBKEY_HASH = '5a9f3e8b1c2d4f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a'; // placeholder

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new ContactCreditRepository($this->pdo);
    }

    public function testGetAvailableCreditReturnsData(): void
    {
        $expectedResult = ['available_credit' => 5000, 'currency' => 'USD'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT available_credit, currency'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedResult);

        $result = $this->repository->getAvailableCredit('some-pubkey-hash');

        $this->assertIsArray($result);
        $this->assertEquals(5000, $result['available_credit']);
        $this->assertEquals('USD', $result['currency']);
    }

    public function testGetAvailableCreditReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT available_credit'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getAvailableCredit('nonexistent-hash');

        $this->assertNull($result);
    }

    public function testGetAvailableCreditReturnsNullOnFailure(): void
    {
        // When prepare throws PDOException, execute() catches it and returns false
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->getAvailableCredit('some-hash');

        $this->assertNull($result);
    }

    public function testUpsertAvailableCreditSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('INSERT INTO'),
                $this->stringContains('contact_credit'),
                $this->stringContains('ON DUPLICATE KEY UPDATE')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->upsertAvailableCredit('some-pubkey-hash', 5000, 'USD');

        $this->assertTrue($result);
    }

    public function testUpsertAvailableCreditFailure(): void
    {
        // When prepare throws PDOException, execute() catches it and returns false
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->upsertAvailableCredit('some-pubkey-hash', 5000, 'USD');

        $this->assertFalse($result);
    }

    public function testCreateInitialCreditComputesPubkeyHash(): void
    {
        $publicKey = 'test-contact-public-key-xyz789';
        $expectedHash = hash(Constants::HASH_ALGORITHM, $publicKey);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('INSERT INTO'),
                $this->stringContains('ON DUPLICATE KEY UPDATE')
            ))
            ->willReturn($this->stmt);

        // AbstractRepository uses bindValue() for each param, then execute() with no args
        $boundValues = [];
        $this->stmt->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundValues) {
                $boundValues[$key] = $value;
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->createInitialCredit($publicKey, 'USD');

        $this->assertTrue($result);
        $this->assertEquals($expectedHash, $boundValues[':pubkey_hash']);
        $this->assertEquals(0, $boundValues[':available_credit']);
        $this->assertEquals('USD', $boundValues[':currency']);
    }

    public function testDeleteByPubkeyHashSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->deleteByPubkeyHash('some-pubkey-hash');

        $this->assertTrue($result);
    }

    public function testDeleteByPubkeyHashNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteByPubkeyHash('nonexistent-hash');

        $this->assertFalse($result);
    }

    public function testTableNameIsContactCredit(): void
    {
        // Verify the repository is configured for the correct table
        $reflection = new \ReflectionProperty(ContactCreditRepository::class, 'tableName');
        $reflection->setAccessible(true);
        $this->assertEquals('contact_credit', $reflection->getValue($this->repository));
    }

    public function testPrimaryKeyIsPubkeyHash(): void
    {
        // Verify the primary key is set correctly
        $reflection = new \ReflectionProperty(ContactCreditRepository::class, 'primaryKey');
        $reflection->setAccessible(true);
        $this->assertEquals('pubkey_hash', $reflection->getValue($this->repository));
    }

    public function testGetTotalAvailableCreditByCurrencyReturnsSums(): void
    {
        $expectedResult = [
            ['currency' => 'USD', 'total_available_credit' => 15000],
            ['currency' => 'EUR', 'total_available_credit' => 8000]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SUM(available_credit)'),
                $this->stringContains('GROUP BY currency')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedResult);

        $result = $this->repository->getTotalAvailableCreditByCurrency();

        $this->assertCount(2, $result);
        $this->assertEquals('USD', $result[0]['currency']);
        $this->assertEquals(15000, $result[0]['total_available_credit']);
        $this->assertEquals('EUR', $result[1]['currency']);
        $this->assertEquals(8000, $result[1]['total_available_credit']);
    }

    public function testGetTotalAvailableCreditByCurrencyReturnsEmptyOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->getTotalAvailableCreditByCurrency();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetTotalAvailableCreditByCurrencyReturnsEmptyWhenNoData(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getTotalAvailableCreditByCurrency();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAvailableCreditWithCurrencyParam(): void
    {
        $expectedResult = ['available_credit' => 3000, 'currency' => 'USD'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('pubkey_hash'),
                $this->stringContains('currency')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedResult);

        $result = $this->repository->getAvailableCredit('hash', 'USD');

        $this->assertIsArray($result);
        $this->assertEquals(3000, $result['available_credit']);
        $this->assertEquals('USD', $result['currency']);
    }

    public function testGetAvailableCreditAllCurrenciesReturnsMultiple(): void
    {
        $expectedResult = [
            ['available_credit' => 5000, 'currency' => 'USD'],
            ['available_credit' => 3000, 'currency' => 'EUR']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT available_credit, currency'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedResult);

        $result = $this->repository->getAvailableCreditAllCurrencies('some-pubkey-hash');

        $this->assertCount(2, $result);
        $this->assertEquals('USD', $result[0]['currency']);
        $this->assertEquals(5000, $result[0]['available_credit']);
        $this->assertEquals('EUR', $result[1]['currency']);
        $this->assertEquals(3000, $result[1]['available_credit']);
    }

    public function testGetAvailableCreditAllCurrenciesReturnsEmptyWhenNone(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getAvailableCreditAllCurrencies('some-pubkey-hash');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAvailableCreditAllCurrenciesReturnsEmptyOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->getAvailableCreditAllCurrencies('some-hash');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // upsertAvailableCreditIfNewer() Tests
    // =========================================================================

    public function testUpsertAvailableCreditIfNewerSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('INSERT INTO'),
                $this->stringContains('ON DUPLICATE KEY UPDATE'),
                $this->stringContains('IF(')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        // Microtime integer: e.g. 17417499042270 = 2025-03-12 ~14:05:04
        $result = $this->repository->upsertAvailableCreditIfNewer('some-pubkey-hash', 5000, 'USD', 17417499042270);

        $this->assertTrue($result);
    }

    public function testUpsertAvailableCreditIfNewerFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->upsertAvailableCreditIfNewer('some-pubkey-hash', 5000, 'USD', 17417499042270);

        $this->assertFalse($result);
    }

    public function testUpsertAvailableCreditIfNewerIncludesTimestampParams(): void
    {
        $microtimeInt = 17417499042270;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $boundValues = [];
        $this->stmt->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundValues) {
                $boundValues[$key] = $value;
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->repository->upsertAvailableCreditIfNewer('test-hash', 9000, 'EUR', $microtimeInt);

        // All three timestamp params should be the same converted value
        $this->assertEquals($boundValues[':updated_at'], $boundValues[':updated_at2']);
        $this->assertEquals($boundValues[':updated_at'], $boundValues[':updated_at3']);
        $this->assertEquals('test-hash', $boundValues[':pubkey_hash']);
        $this->assertEquals(9000, $boundValues[':available_credit']);
        $this->assertEquals('EUR', $boundValues[':currency']);
    }

    // =========================================================================
    // microtimeIntToTimestamp() Tests
    // =========================================================================

    public function testMicrotimeIntToTimestampFormat(): void
    {
        // microtimeIntToTimestamp is private static, use reflection
        $method = new \ReflectionMethod(ContactCreditRepository::class, 'microtimeIntToTimestamp');
        $method->setAccessible(true);

        // Known value: 1741749904 seconds = some date, remainder 2270 = 227000 microseconds
        $result = $method->invoke(null, 17417499042270);

        // Should be a valid timestamp format: YYYY-MM-DD HH:MM:SS.UUUUUU
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', $result);
        // Microseconds should be remainder * 100 = 2270 * 100 = 227000
        $this->assertStringEndsWith('.227000', $result);
    }

    public function testMicrotimeIntToTimestampZeroRemainder(): void
    {
        $method = new \ReflectionMethod(ContactCreditRepository::class, 'microtimeIntToTimestamp');
        $method->setAccessible(true);

        // Exact second boundary (no fractional part)
        $result = $method->invoke(null, 17417499040000);

        $this->assertStringEndsWith('.000000', $result);
    }

    public function testMicrotimeIntToTimestampMaxRemainder(): void
    {
        $method = new \ReflectionMethod(ContactCreditRepository::class, 'microtimeIntToTimestamp');
        $method->setAccessible(true);

        // Maximum remainder: 9999 (just under 1 second)
        $result = $method->invoke(null, 17417499049999);

        // 9999 * 100 = 999900 microseconds
        $this->assertStringEndsWith('.999900', $result);
    }
}
