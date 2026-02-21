<?php
/**
 * Unit Tests for BalanceRepository
 *
 * Tests balance repository functionality including balance retrieval,
 * updates, insertions, and transaction-based balance modifications.
 * Uses mocked PDO and PDOStatement to isolate database operations.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\BalanceRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(BalanceRepository::class)]
class BalanceRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private BalanceRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);

        // Create repository with mocked PDO using anonymous class to bypass constructor dependencies
        $this->repository = $this->createRepositoryWithMockedPdo($this->pdo);
    }

    /**
     * Create repository instance with mocked PDO, bypassing constructor dependencies
     */
    private function createRepositoryWithMockedPdo(PDO $pdo): BalanceRepository
    {
        return new TestableBalanceRepository($pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test repository sets correct table name
     */
    public function testRepositorySetsCorrectTableName(): void
    {
        $this->assertEquals('balances', $this->repository->getTableName());
    }

    /**
     * Test repository uses pubkey_hash as primary key
     */
    public function testRepositoryUsesPubkeyHashAsPrimaryKey(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('primaryKey');
        $property->setAccessible(true);

        $this->assertEquals('pubkey_hash', $property->getValue($this->repository));
    }

    /**
     * Test allowed columns include expected fields
     */
    public function testAllowedColumnsIncludeExpectedFields(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);
        $allowedColumns = $property->getValue($this->repository);

        $this->assertContains('id', $allowedColumns);
        $this->assertContains('pubkey_hash', $allowedColumns);
        $this->assertContains('received', $allowedColumns);
        $this->assertContains('sent', $allowedColumns);
        $this->assertContains('currency', $allowedColumns);
    }

    // =========================================================================
    // deleteByPubkey Tests
    // =========================================================================

    /**
     * Test deleteByPubkey removes balance and returns true
     */
    public function testDeleteByPubkeyRemovesBalanceAndReturnsTrue(): void
    {
        $pubkey = 'test-public-key';
        $expectedHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', $expectedHash);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->deleteByPubkey($pubkey);

        $this->assertTrue($result);
    }

    /**
     * Test deleteByPubkey returns false when no rows deleted
     */
    public function testDeleteByPubkeyReturnsFalseWhenNoRowsDeleted(): void
    {
        $pubkey = 'nonexistent-key';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteByPubkey($pubkey);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getAllBalances Tests
    // =========================================================================

    /**
     * Test getAllBalances returns all balance records
     */
    public function testGetAllBalancesReturnsAllBalanceRecords(): void
    {
        $expectedBalances = [
            ['pubkey_hash' => 'hash1', 'received' => 1000, 'sent' => 500, 'currency' => 'USD'],
            ['pubkey_hash' => 'hash2', 'received' => 2000, 'sent' => 800, 'currency' => 'USD'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedBalances);

        $result = $this->repository->getAllBalances();

        $this->assertEquals($expectedBalances, $result);
    }

    /**
     * Test getAllBalances returns null on query failure
     */
    public function testGetAllBalancesReturnsNullOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getAllBalances();

        $this->assertNull($result);
    }

    /**
     * Test getAllBalances returns null when no records
     */
    public function testGetAllBalancesReturnsNullWhenNoRecords(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getAllBalances();

        $this->assertNull($result);
    }

    // =========================================================================
    // getContactBalance Tests
    // =========================================================================

    /**
     * Test getContactBalance returns balance for contact and currency
     */
    public function testGetContactBalanceReturnsBalanceForContactAndCurrency(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';
        $expectedBalance = [
            ['received' => 1000, 'sent' => 300]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedBalance);

        $result = $this->repository->getContactBalance($pubkey, $currency);

        $this->assertEquals($expectedBalance, $result);
    }

    /**
     * Test getContactBalance returns null when not found
     */
    public function testGetContactBalanceReturnsNullWhenNotFound(): void
    {
        $pubkey = 'nonexistent-key';
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getContactBalance($pubkey, $currency);

        $this->assertNull($result);
    }

    // =========================================================================
    // getContactBalanceByPubkeyHash Tests
    // =========================================================================

    /**
     * Test getContactBalanceByPubkeyHash returns balance for pubkey hash
     */
    public function testGetContactBalanceByPubkeyHashReturnsBalance(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $currency = 'USD';
        $expectedBalance = [
            ['received' => 500, 'sent' => 100]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedBalance);

        $result = $this->repository->getContactBalanceByPubkeyHash($pubkeyHash, $currency);

        $this->assertEquals($expectedBalance, $result);
    }

    /**
     * Test getContactBalanceByPubkeyHash uses default USD currency
     */
    public function testGetContactBalanceByPubkeyHashUsesDefaultCurrency(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $expectedBalance = [
            ['received' => 500, 'sent' => 100]
        ];

        $boundParams = [];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundParams) {
                $boundParams[$key] = $value;
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedBalance);

        $this->repository->getContactBalanceByPubkeyHash($pubkeyHash);

        $this->assertEquals('USD', $boundParams[':currency']);
    }

    // =========================================================================
    // getCurrentContactBalance Tests
    // =========================================================================

    /**
     * Test getCurrentContactBalance calculates received minus sent
     */
    public function testGetCurrentContactBalanceCalculatesReceivedMinusSent(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';

        // Create a partial mock to mock the received/sent methods
        $repository = $this->getMockBuilder(TestableBalanceRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getContactReceivedBalance', 'getContactSentBalance'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with($pubkey, $currency)
            ->willReturn(1000);

        $repository->expects($this->once())
            ->method('getContactSentBalance')
            ->with($pubkey, $currency)
            ->willReturn(300);

        $result = $repository->getCurrentContactBalance($pubkey, $currency);

        $this->assertEquals(700, $result);
    }

    /**
     * Test getCurrentContactBalance can return negative balance
     */
    public function testGetCurrentContactBalanceCanReturnNegativeBalance(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';

        $repository = $this->getMockBuilder(TestableBalanceRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getContactReceivedBalance', 'getContactSentBalance'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->willReturn(500);

        $repository->expects($this->once())
            ->method('getContactSentBalance')
            ->willReturn(1000);

        $result = $repository->getCurrentContactBalance($pubkey, $currency);

        $this->assertEquals(-500, $result);
    }

    // =========================================================================
    // getContactReceivedBalance Tests
    // =========================================================================

    /**
     * Test getContactReceivedBalance returns received amount
     */
    public function testGetContactReceivedBalanceReturnsReceivedAmount(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(1500);

        $result = $this->repository->getContactReceivedBalance($pubkey, $currency);

        $this->assertEquals(1500, $result);
    }

    /**
     * Test getContactReceivedBalance returns zero on failure
     */
    public function testGetContactReceivedBalanceReturnsZeroOnFailure(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getContactReceivedBalance($pubkey, $currency);

        $this->assertEquals(0, $result);
    }

    /**
     * Test getContactReceivedBalance returns zero when no result
     */
    public function testGetContactReceivedBalanceReturnsZeroWhenNoResult(): void
    {
        $pubkey = 'nonexistent-key';
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(false);

        $result = $this->repository->getContactReceivedBalance($pubkey, $currency);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getContactSentBalance Tests
    // =========================================================================

    /**
     * Test getContactSentBalance returns sent amount
     */
    public function testGetContactSentBalanceReturnsSentAmount(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(800);

        $result = $this->repository->getContactSentBalance($pubkey, $currency);

        $this->assertEquals(800, $result);
    }

    /**
     * Test getContactSentBalance returns zero on failure
     */
    public function testGetContactSentBalanceReturnsZeroOnFailure(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getContactSentBalance($pubkey, $currency);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getContactBalances Tests
    // =========================================================================

    /**
     * Test getContactBalances returns all currency balances for contact
     */
    public function testGetContactBalancesReturnsAllCurrencyBalances(): void
    {
        $pubkey = 'test-public-key';
        $expectedBalances = [
            ['received' => 1000, 'sent' => 300, 'currency' => 'USD'],
            ['received' => 500, 'sent' => 100, 'currency' => 'EUR'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedBalances);

        $result = $this->repository->getContactBalances($pubkey);

        $this->assertEquals($expectedBalances, $result);
    }

    /**
     * Test getContactBalances returns null when no balances
     */
    public function testGetContactBalancesReturnsNullWhenNoBalances(): void
    {
        $pubkey = 'nonexistent-key';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getContactBalances($pubkey);

        $this->assertNull($result);
    }

    // =========================================================================
    // getContactBalancesCurrency Tests
    // =========================================================================

    /**
     * Test getContactBalancesCurrency returns balances for specific currency
     */
    public function testGetContactBalancesCurrencyReturnsBalancesForCurrency(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';
        $expectedBalances = [
            ['received' => 1000, 'sent' => 300, 'currency' => 'USD']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedBalances);

        $result = $this->repository->getContactBalancesCurrency($pubkey, $currency);

        $this->assertEquals($expectedBalances, $result);
    }

    // =========================================================================
    // getUserBalance Tests
    // =========================================================================

    /**
     * Test getUserBalance returns total balance grouped by currency
     */
    public function testGetUserBalanceReturnsTotalBalanceGroupedByCurrency(): void
    {
        $expectedBalances = [
            ['currency' => 'USD', 'total_balance' => 2500],
            ['currency' => 'EUR', 'total_balance' => 1200],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedBalances);

        $result = $this->repository->getUserBalance();

        $this->assertEquals($expectedBalances, $result);
    }

    /**
     * Test getUserBalance returns null on query failure
     */
    public function testGetUserBalanceReturnsNullOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getUserBalance();

        $this->assertNull($result);
    }

    /**
     * Test getUserBalance returns null when no balances
     */
    public function testGetUserBalanceReturnsNullWhenNoBalances(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getUserBalance();

        $this->assertNull($result);
    }

    // =========================================================================
    // getUserBalanceCurrency Tests
    // =========================================================================

    /**
     * Test getUserBalanceCurrency returns total balance for currency
     */
    public function testGetUserBalanceCurrencyReturnsTotalBalanceForCurrency(): void
    {
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':currency', $currency);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(5000);

        $result = $this->repository->getUserBalanceCurrency($currency);

        $this->assertEquals(5000, $result);
    }

    /**
     * Test getUserBalanceCurrency returns zero on failure
     */
    public function testGetUserBalanceCurrencyReturnsZeroOnFailure(): void
    {
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getUserBalanceCurrency($currency);

        $this->assertEquals(0, $result);
    }

    /**
     * Test getUserBalanceCurrency returns zero when no result
     */
    public function testGetUserBalanceCurrencyReturnsZeroWhenNoResult(): void
    {
        $currency = 'EUR';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(false);

        $result = $this->repository->getUserBalanceCurrency($currency);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // getUserBalanceContact Tests
    // =========================================================================

    /**
     * Test getUserBalanceContact returns balance for contact grouped by currency
     */
    public function testGetUserBalanceContactReturnsBalanceGroupedByCurrency(): void
    {
        $pubkey = 'test-public-key';
        $expectedBalances = [
            ['currency' => 'USD', 'total_balance' => 700],
            ['currency' => 'EUR', 'total_balance' => 400],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedBalances);

        $result = $this->repository->getUserBalanceContact($pubkey);

        $this->assertEquals($expectedBalances, $result);
    }

    // =========================================================================
    // insertBalance Tests
    // =========================================================================

    /**
     * Test insertBalance creates new balance record
     */
    public function testInsertBalanceCreatesNewBalanceRecord(): void
    {
        $pubkey = 'test-public-key';
        $receivedAmount = 1000;
        $sentAmount = 0;
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->insertBalance($pubkey, $receivedAmount, $sentAmount, $currency);

        $this->assertTrue($result);
    }

    /**
     * Test insertBalance returns false on failure
     */
    public function testInsertBalanceReturnsFalseOnFailure(): void
    {
        $pubkey = 'test-public-key';
        $receivedAmount = 1000;
        $sentAmount = 0;
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->insertBalance($pubkey, $receivedAmount, $sentAmount, $currency);

        $this->assertFalse($result);
    }

    /**
     * Test insertBalance hashes pubkey correctly
     */
    public function testInsertBalanceHashesPubkeyCorrectly(): void
    {
        $pubkey = 'test-public-key';
        $expectedHash = hash(Constants::HASH_ALGORITHM, $pubkey);
        $boundParams = [];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundParams) {
                $boundParams[$key] = $value;
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $this->repository->insertBalance($pubkey, 100, 0, 'USD');

        $this->assertEquals($expectedHash, $boundParams[':pubkey_hash']);
    }

    // =========================================================================
    // insertInitialContactBalances Tests
    // =========================================================================

    /**
     * Test insertInitialContactBalances creates zero balance record
     */
    public function testInsertInitialContactBalancesCreatesZeroBalanceRecord(): void
    {
        $pubkey = 'test-public-key';
        $currency = 'USD';
        $boundParams = [];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundParams) {
                $boundParams[$key] = $value;
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $this->repository->insertInitialContactBalances($pubkey, $currency);

        $this->assertEquals(0, $boundParams[':received']);
        $this->assertEquals(0, $boundParams[':sent']);
    }

    // =========================================================================
    // updateBalance Tests
    // =========================================================================

    /**
     * Test updateBalance updates sent balance
     */
    public function testUpdateBalanceUpdatesSentBalance(): void
    {
        $pubkey = 'test-public-key';
        $direction = 'sent';
        $amount = 500;
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(3))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $result = $this->repository->updateBalance($pubkey, $direction, $amount, $currency);

        $this->assertTrue($result);
    }

    /**
     * Test updateBalance updates received balance
     */
    public function testUpdateBalanceUpdatesReceivedBalance(): void
    {
        $pubkey = 'test-public-key';
        $direction = 'received';
        $amount = 1000;
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(3))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $result = $this->repository->updateBalance($pubkey, $direction, $amount, $currency);

        $this->assertTrue($result);
    }

    /**
     * Test updateBalance returns false for invalid direction
     */
    public function testUpdateBalanceReturnsFalseForInvalidDirection(): void
    {
        $pubkey = 'test-public-key';
        $direction = 'invalid';
        $amount = 500;
        $currency = 'USD';

        $result = $this->repository->updateBalance($pubkey, $direction, $amount, $currency);

        $this->assertFalse($result);
    }

    /**
     * Test updateBalance returns false on query failure
     */
    public function testUpdateBalanceReturnsFalseOnQueryFailure(): void
    {
        $pubkey = 'test-public-key';
        $direction = 'sent';
        $amount = 500;
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->updateBalance($pubkey, $direction, $amount, $currency);

        $this->assertFalse($result);
    }

    // =========================================================================
    // updateBothDirectionBalance Tests
    // =========================================================================

    /**
     * Test updateBothDirectionBalance updates both received and sent
     */
    public function testUpdateBothDirectionBalanceUpdatesBothValues(): void
    {
        $amounts = ['received' => 1500, 'sent' => 800];
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $result = $this->repository->updateBothDirectionBalance($amounts, $contactPubkeyHash, $currency);

        $this->assertTrue($result);
    }

    /**
     * Test updateBothDirectionBalance returns false on failure
     */
    public function testUpdateBothDirectionBalanceReturnsFalseOnFailure(): void
    {
        $amounts = ['received' => 1500, 'sent' => 800];
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $currency = 'USD';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->updateBothDirectionBalance($amounts, $contactPubkeyHash, $currency);

        $this->assertFalse($result);
    }
}

/**
 * Named testable subclass to bypass AbstractRepository constructor dependencies.
 * Anonymous classes cannot be used with getMockBuilder due to '@' in class names.
 */
class TestableBalanceRepository extends BalanceRepository
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->tableName = 'balances';
        $this->primaryKey = 'pubkey_hash';
        $this->allowedColumns = ['id', 'pubkey_hash', 'received', 'sent', 'currency'];
    }
}
