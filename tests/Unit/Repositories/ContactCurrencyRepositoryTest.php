<?php
/**
 * Unit Tests for ContactCurrencyRepository
 *
 * Tests contact currency configuration operations including:
 * - Inserting currency configurations
 * - Getting currency config by pubkey hash and currency
 * - Getting all currencies for a contact
 * - Checking if a contact has a currency
 * - Getting credit limit and fee percent
 * - Updating currency configurations
 * - Upserting currency configurations (insert or update)
 * - Deleting currency configurations (single and all)
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Core\SplitAmount;
use PDO;
use PDOStatement;

#[CoversClass(ContactCurrencyRepository::class)]
class ContactCurrencyRepositoryTest extends TestCase
{
    private ContactCurrencyRepository $repository;
    private PDO $pdo;
    private PDOStatement $stmt;

    private const TEST_PUBKEY_HASH = '5a9f3e8b1c2d4f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a';
    private const TEST_CURRENCY = 'USD';
    private const TEST_FEE_PERCENT = 250;
    private const TEST_CREDIT_LIMIT_WHOLE = 50000;
    private const TEST_CREDIT_LIMIT_FRAC = 0;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new ContactCurrencyRepository($this->pdo);
    }

    // =========================================================================
    // Table name and primary key verification
    // =========================================================================

    public function testTableNameIsContactCurrencies(): void
    {
        $reflection = new \ReflectionProperty(ContactCurrencyRepository::class, 'tableName');
        $reflection->setAccessible(true);
        $this->assertEquals('contact_currencies', $reflection->getValue($this->repository));
    }

    public function testPrimaryKeyIsPubkeyHash(): void
    {
        $reflection = new \ReflectionProperty(ContactCurrencyRepository::class, 'primaryKey');
        $reflection->setAccessible(true);
        $this->assertEquals('pubkey_hash', $reflection->getValue($this->repository));
    }

    // =========================================================================
    // insertCurrencyConfig
    // =========================================================================

    public function testInsertCurrencyConfigSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('INSERT'),
                $this->stringContains('contact_currencies'),
                $this->stringContains('pubkey_hash'),
                $this->stringContains('currency'),
                $this->stringContains('fee_percent'),
                $this->stringContains('credit_limit_whole'),
                $this->stringContains('credit_limit_frac')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->insertCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            self::TEST_FEE_PERCENT,
            new SplitAmount(self::TEST_CREDIT_LIMIT_WHOLE, self::TEST_CREDIT_LIMIT_FRAC)
        );

        $this->assertTrue($result);
    }

    public function testInsertCurrencyConfigFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->insertCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            self::TEST_FEE_PERCENT,
            new SplitAmount(self::TEST_CREDIT_LIMIT_WHOLE, self::TEST_CREDIT_LIMIT_FRAC)
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // getCurrencyConfig
    // =========================================================================

    public function testGetCurrencyConfigReturnsData(): void
    {
        $dbRow = [
            'currency' => 'USD',
            'fee_percent' => 250,
            'credit_limit_whole' => 50000,
            'credit_limit_frac' => 0,
            'status' => 'accepted',
            'direction' => 'incoming'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT'),
                $this->stringContains('currency'),
                $this->stringContains('fee_percent'),
                $this->stringContains('credit_limit'),
                $this->stringContains('WHERE pubkey_hash')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($dbRow);

        $result = $this->repository->getCurrencyConfig(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertIsArray($result);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals(250, $result['fee_percent']);
        $this->assertInstanceOf(SplitAmount::class, $result['credit_limit']);
        $this->assertEquals(50000, $result['credit_limit']->whole);
    }

    public function testGetCurrencyConfigReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT'))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getCurrencyConfig(self::TEST_PUBKEY_HASH, 'NONEXISTENT');

        $this->assertNull($result);
    }

    public function testGetCurrencyConfigReturnsNullOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->getCurrencyConfig(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertNull($result);
    }

    // =========================================================================
    // getContactCurrencies
    // =========================================================================

    public function testGetContactCurrenciesReturnsData(): void
    {
        $expectedResult = [
            ['currency' => 'USD', 'fee_percent' => 250, 'credit_limit_whole' => 50000, 'credit_limit_frac' => 0, 'status' => 'accepted', 'direction' => 'incoming'],
            ['currency' => 'EUR', 'fee_percent' => 300, 'credit_limit_whole' => 40000, 'credit_limit_frac' => 0, 'status' => 'accepted', 'direction' => 'incoming']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT'),
                $this->stringContains('WHERE pubkey_hash')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedResult);

        $result = $this->repository->getContactCurrencies(self::TEST_PUBKEY_HASH);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('USD', $result[0]['currency']);
        $this->assertEquals('EUR', $result[1]['currency']);
    }

    public function testGetContactCurrenciesReturnsEmptyWhenNoneFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getContactCurrencies(self::TEST_PUBKEY_HASH);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetContactCurrenciesReturnsEmptyOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->getContactCurrencies(self::TEST_PUBKEY_HASH);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // hasCurrency
    // =========================================================================

    public function testHasCurrencyReturnsTrueWhenExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT 1'),
                $this->stringContains('WHERE pubkey_hash'),
                $this->stringContains('currency')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['1' => 1]);

        $result = $this->repository->hasCurrency(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertTrue($result);
    }

    public function testHasCurrencyReturnsFalseWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->hasCurrency(self::TEST_PUBKEY_HASH, 'NONEXISTENT');

        $this->assertFalse($result);
    }

    public function testHasCurrencyReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->hasCurrency(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getCreditLimit
    // =========================================================================

    public function testGetCreditLimitReturnsValue(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT credit_limit'),
                $this->stringContains('WHERE pubkey_hash')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['credit_limit_whole' => 50000, 'credit_limit_frac' => 0]);

        $result = $this->repository->getCreditLimit(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertInstanceOf(SplitAmount::class, $result);
        $this->assertEquals(50000, $result->whole);
        $this->assertEquals(0, $result->frac);
    }

    public function testGetCreditLimitReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getCreditLimit(self::TEST_PUBKEY_HASH, 'NONEXISTENT');

        $this->assertNull($result);
    }

    public function testGetCreditLimitReturnsNullOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->getCreditLimit(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertNull($result);
    }

    // =========================================================================
    // getFeePercent
    // =========================================================================

    public function testGetFeePercentReturnsValue(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SELECT fee_percent'),
                $this->stringContains('WHERE pubkey_hash')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['fee_percent' => 250]);

        $result = $this->repository->getFeePercent(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertEquals(250, $result);
    }

    public function testGetFeePercentReturnsZeroWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getFeePercent(self::TEST_PUBKEY_HASH, 'NONEXISTENT');

        $this->assertEquals(0, $result);
    }

    public function testGetFeePercentReturnsZeroOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->getFeePercent(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // updateCurrencyConfig
    // =========================================================================

    public function testUpdateCurrencyConfigSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('UPDATE'),
                $this->stringContains('contact_currencies'),
                $this->stringContains('SET'),
                $this->stringContains('fee_percent'),
                $this->stringContains('WHERE pubkey_hash')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->updateCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            ['fee_percent' => 300]
        );

        $this->assertTrue($result);
    }

    public function testUpdateCurrencyConfigWithMultipleFields(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('UPDATE'),
                $this->stringContains('fee_percent'),
                $this->stringContains('credit_limit')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->updateCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            ['fee_percent' => 300, 'credit_limit' => new SplitAmount(60000, 0)]
        );

        $this->assertTrue($result);
    }

    public function testUpdateCurrencyConfigReturnsFalseForEmptyFields(): void
    {
        // No valid fields provided - should return false without querying
        $result = $this->repository->updateCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            []
        );

        $this->assertFalse($result);
    }

    public function testUpdateCurrencyConfigReturnsFalseForInvalidFields(): void
    {
        // Only invalid field names - should return false without querying
        $result = $this->repository->updateCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            ['invalid_field' => 999]
        );

        $this->assertFalse($result);
    }

    public function testUpdateCurrencyConfigFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->updateCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            ['fee_percent' => 300]
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // upsertCurrencyConfig
    // =========================================================================

    public function testUpsertCurrencyConfigSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('INSERT INTO'),
                $this->stringContains('contact_currencies'),
                $this->stringContains('ON DUPLICATE KEY UPDATE')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->upsertCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            self::TEST_FEE_PERCENT,
            new SplitAmount(self::TEST_CREDIT_LIMIT_WHOLE, self::TEST_CREDIT_LIMIT_FRAC)
        );

        $this->assertTrue($result);
    }

    public function testUpsertCurrencyConfigFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->upsertCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            self::TEST_FEE_PERCENT,
            new SplitAmount(self::TEST_CREDIT_LIMIT_WHOLE, self::TEST_CREDIT_LIMIT_FRAC)
        );

        $this->assertFalse($result);
    }

    public function testUpsertCurrencyConfigBindsCorrectParams(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO'))
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

        $this->repository->upsertCurrencyConfig(
            self::TEST_PUBKEY_HASH,
            self::TEST_CURRENCY,
            self::TEST_FEE_PERCENT,
            new SplitAmount(self::TEST_CREDIT_LIMIT_WHOLE, self::TEST_CREDIT_LIMIT_FRAC)
        );

        $this->assertEquals(self::TEST_PUBKEY_HASH, $boundValues[':pubkey_hash']);
        $this->assertEquals(self::TEST_CURRENCY, $boundValues[':currency']);
        $this->assertEquals(self::TEST_FEE_PERCENT, $boundValues[':fee_percent']);
        $this->assertEquals(self::TEST_CREDIT_LIMIT_WHOLE, $boundValues[':credit_limit_whole']);
        $this->assertEquals(self::TEST_CREDIT_LIMIT_FRAC, $boundValues[':credit_limit_frac']);
    }

    // =========================================================================
    // deleteAllForContact
    // =========================================================================

    public function testDeleteAllForContactSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE'))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('rowCount')
            ->willReturn(3);

        $result = $this->repository->deleteAllForContact(self::TEST_PUBKEY_HASH);

        $this->assertTrue($result);
    }

    public function testDeleteAllForContactReturnsFalseWhenNoneFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE'))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteAllForContact('nonexistent-hash');

        $this->assertFalse($result);
    }

    public function testDeleteAllForContactReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->deleteAllForContact(self::TEST_PUBKEY_HASH);

        $this->assertFalse($result);
    }

    // =========================================================================
    // deleteCurrencyConfig
    // =========================================================================

    public function testDeleteCurrencyConfigSuccess(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('DELETE'),
                $this->stringContains('WHERE pubkey_hash'),
                $this->stringContains('currency')
            ))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->deleteCurrencyConfig(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertTrue($result);
    }

    public function testDeleteCurrencyConfigReturnsFalseWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE'))
            ->willReturn($this->stmt);

        $this->stmt->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteCurrencyConfig(self::TEST_PUBKEY_HASH, 'NONEXISTENT');

        $this->assertFalse($result);
    }

    public function testDeleteCurrencyConfigReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Connection error'));

        $result = $this->repository->deleteCurrencyConfig(self::TEST_PUBKEY_HASH, self::TEST_CURRENCY);

        $this->assertFalse($result);
    }
}
