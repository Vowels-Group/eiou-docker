<?php
/**
 * Unit Tests for TransactionContactRepository
 *
 * Tests contact-related transaction repository operations including
 * contact balances, transaction lookups, and contact status updates.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionContactRepository;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(TransactionContactRepository::class)]
class TransactionContactRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private TransactionContactRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new TransactionContactRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorSetsPdoConnection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repo = new TransactionContactRepository($pdo);

        $this->assertSame($pdo, $repo->getPdo());
    }

    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('transactions', $this->repository->getTableName());
    }

    // =========================================================================
    // getContactBalance() Tests
    // =========================================================================

    public function testGetContactBalanceCalculatesBalanceCorrectly(): void
    {
        $userPubkey = 'user-pubkey-123';
        $contactPubkey = 'contact-pubkey-456';

        // First query returns sent amount
        $sentStmt = $this->createMock(PDOStatement::class);
        $sentStmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function($params) {
                return count($params) === 2;
            }));
        $sentStmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['sent' => 5000]);

        // Second query returns received amount
        $receivedStmt = $this->createMock(PDOStatement::class);
        $receivedStmt->expects($this->once())
            ->method('execute');
        $receivedStmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['received' => 8000]);

        $callCount = 0;
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function() use (&$callCount, $sentStmt, $receivedStmt) {
                $callCount++;
                return $callCount === 1 ? $sentStmt : $receivedStmt;
            });

        $result = $this->repository->getContactBalance($userPubkey, $contactPubkey);

        // Balance = received - sent = 8000 - 5000 = 3000
        $this->assertEquals(3000, $result);
    }

    public function testGetContactBalanceReturnsZeroWhenNoTransactions(): void
    {
        $sentStmt = $this->createMock(PDOStatement::class);
        $sentStmt->expects($this->once())
            ->method('execute');
        $sentStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['sent' => 0]);

        $receivedStmt = $this->createMock(PDOStatement::class);
        $receivedStmt->expects($this->once())
            ->method('execute');
        $receivedStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['received' => 0]);

        $callCount = 0;
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function() use (&$callCount, $sentStmt, $receivedStmt) {
                $callCount++;
                return $callCount === 1 ? $sentStmt : $receivedStmt;
            });

        $result = $this->repository->getContactBalance('user', 'contact');

        $this->assertEquals(0, $result);
    }

    public function testGetContactBalanceReturnsNegativeBalance(): void
    {
        // User sent more than received
        $sentStmt = $this->createMock(PDOStatement::class);
        $sentStmt->expects($this->once())
            ->method('execute');
        $sentStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['sent' => 10000]);

        $receivedStmt = $this->createMock(PDOStatement::class);
        $receivedStmt->expects($this->once())
            ->method('execute');
        $receivedStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['received' => 3000]);

        $callCount = 0;
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function() use (&$callCount, $sentStmt, $receivedStmt) {
                $callCount++;
                return $callCount === 1 ? $sentStmt : $receivedStmt;
            });

        $result = $this->repository->getContactBalance('user', 'contact');

        // Balance = 3000 - 10000 = -7000
        $this->assertEquals(-7000, $result);
    }

    // =========================================================================
    // getAllContactBalances() Tests
    // =========================================================================

    public function testGetAllContactBalancesReturnsEmptyForEmptyContacts(): void
    {
        $result = $this->repository->getAllContactBalances('user-pubkey', []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAllContactBalancesReturnsBalancesForAllContacts(): void
    {
        $userPubkey = 'user-pubkey';
        $contactPubkeys = ['contact1', 'contact2', 'contact3'];

        $balanceData = [
            [
                'contact_hash' => hash(Constants::HASH_ALGORITHM, 'contact1'),
                'total_sent' => 1000,
                'total_received' => 2000
            ],
            [
                'contact_hash' => hash(Constants::HASH_ALGORITHM, 'contact2'),
                'total_sent' => 500,
                'total_received' => 500
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UNION ALL'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $fetchIndex = 0;
        $this->stmt->expects($this->exactly(3))
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturnCallback(function() use (&$fetchIndex, $balanceData) {
                $result = $balanceData[$fetchIndex] ?? false;
                $fetchIndex++;
                return $result;
            });

        $result = $this->repository->getAllContactBalances($userPubkey, $contactPubkeys);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('contact1', $result);
        $this->assertArrayHasKey('contact2', $result);
        $this->assertArrayHasKey('contact3', $result);
        $this->assertEquals(1000, $result['contact1']); // 2000 - 1000
        $this->assertEquals(0, $result['contact2']); // 500 - 500
        $this->assertEquals(0, $result['contact3']); // Default for missing
    }

    public function testGetAllContactBalancesReturnsZerosOnQueryFailure(): void
    {
        $contactPubkeys = ['contact1', 'contact2'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getAllContactBalances('user', $contactPubkeys);

        $this->assertEquals(['contact1' => 0, 'contact2' => 0], $result);
    }

    // =========================================================================
    // getTransactionsWithContact() Tests
    // =========================================================================

    public function testGetTransactionsWithContactReturnsEmptyForEmptyAddresses(): void
    {
        // Create a partial mock to control getUserAddressesOrNull
        $repository = $this->getMockBuilder(TransactionContactRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn(null);

        $result = $repository->getTransactionsWithContact(['http://contact.example']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetTransactionsWithContactFiltersEmptyContactAddresses(): void
    {
        $repository = $this->getMockBuilder(TransactionContactRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn(['http://user.example']);

        // All empty contact addresses
        $result = $repository->getTransactionsWithContact(['', null, '']);

        $this->assertEmpty($result);
    }

    public function testGetTransactionsWithContactReturnsTransactions(): void
    {
        $userAddresses = ['http://user.example'];
        $contactAddresses = ['http://contact.example'];

        $transactions = [
            [
                'txid' => 'tx1',
                'tx_type' => 'standard',
                'status' => 'completed',
                'sender_address' => 'http://user.example',
                'receiver_address' => 'http://contact.example',
                'amount' => 1000,
                'currency' => 'USD',
                'timestamp' => '2024-01-01 12:00:00',
                'memo' => 'test',
                'description' => ''
            ]
        ];

        $repository = $this->getMockBuilder(TransactionContactRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn($userAddresses);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT txid, tx_type, status'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($transactions);

        $result = $repository->getTransactionsWithContact($contactAddresses);

        $this->assertIsArray($result);
    }

    public function testGetTransactionsWithContactUsesCustomLimit(): void
    {
        $repository = $this->getMockBuilder(TransactionContactRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn(['http://user.example']);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        // The limit should be the last parameter in the execute params
        $this->stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function($params) {
                // Last param should be the limit (10)
                return end($params) === 10;
            }));

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $repository->getTransactionsWithContact(['http://contact.example'], 10);
    }

    // =========================================================================
    // contactTransactionExistsForReceiver() Tests
    // =========================================================================

    public function testContactTransactionExistsForReceiverReturnsTrue(): void
    {
        $receiverHash = hash(Constants::HASH_ALGORITHM, 'receiver-pubkey');

        // Mock UserContext
        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('sender-pubkey');

        // Use reflection to set the currentUser property
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($this->repository, $userContext);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("tx_type = 'contact'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['1']);

        $result = $this->repository->contactTransactionExistsForReceiver($receiverHash);

        $this->assertTrue($result);
    }

    public function testContactTransactionExistsForReceiverReturnsFalse(): void
    {
        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('sender-pubkey');

        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($this->repository, $userContext);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->contactTransactionExistsForReceiver('some-hash');

        $this->assertFalse($result);
    }

    // =========================================================================
    // completeContactTransaction() Tests
    // =========================================================================

    public function testCompleteContactTransactionReturnsTrueOnSuccess(): void
    {
        $contactPublicKey = 'contact-pubkey';

        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($this->repository, $userContext);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SET status = 'completed'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->completeContactTransaction($contactPublicKey);

        $this->assertTrue($result);
    }

    public function testCompleteContactTransactionReturnsFalseWhenNoRowsUpdated(): void
    {
        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($this->repository, $userContext);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->completeContactTransaction('contact-pubkey');

        $this->assertFalse($result);
    }

    // =========================================================================
    // completeReceivedContactTransaction() Tests
    // =========================================================================

    public function testCompleteReceivedContactTransactionReturnsTrueOnSuccess(): void
    {
        $senderPublicKey = 'sender-pubkey';

        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($this->repository, $userContext);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains("SET status = 'completed'"),
                $this->stringContains("AND status = 'accepted'")
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->completeReceivedContactTransaction($senderPublicKey);

        $this->assertTrue($result);
    }

    public function testCompleteReceivedContactTransactionReturnsFalseWhenNoMatch(): void
    {
        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('user-pubkey');

        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($this->repository, $userContext);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->completeReceivedContactTransaction('sender-pubkey');

        $this->assertFalse($result);
    }
}
