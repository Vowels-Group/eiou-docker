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
use Eiou\Core\SplitAmount;
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

        // getContactBalance now sums live + archive in two directions —
        // four prepare() calls total. The archive table is intentionally
        // missing in this fixture, so prepare() throws on
        // transactions_archive and the source's per-helper try/catch
        // falls back to zero contribution from archive. Tests that
        // exercise live-only behavior just override the live ->stmt.
        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
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
        // Two live prepare calls (sent + recv); archive throws
        // courtesy of setUp's callback. fetch() returns the sent
        // total then the recv total in order.
        $this->stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['sum_whole' => 5000, 'sum_frac' => 0],   // sent-live
                ['sum_whole' => 8000, 'sum_frac' => 0],   // recv-live
            );

        // Balance = received - sent = 8000 - 5000 = 3000
        $result = $this->repository->getContactBalance('user-pubkey-123', 'contact-pubkey-456');

        $this->assertInstanceOf(SplitAmount::class, $result);
        $this->assertEquals(3000, $result->whole);
        $this->assertEquals(0, $result->frac);
    }

    public function testGetContactBalanceReturnsZeroWhenNoTransactions(): void
    {
        // setUp's prepare callback returns the same $this->stmt for live
        // and throws for archive. With sent and received both summing
        // to zero the resulting balance is zero.
        $this->stmt->method('fetch')
            ->willReturn(['sum_whole' => 0, 'sum_frac' => 0]);

        $result = $this->repository->getContactBalance('user', 'contact');

        $this->assertInstanceOf(SplitAmount::class, $result);
        $this->assertTrue($result->isZero());
    }

    public function testGetContactBalanceReturnsNegativeBalance(): void
    {
        // sumAmountsFromTable is called four times (sent-live, recv-
        // live, sent-archive, recv-archive). The archive calls throw
        // and contribute zero. We stub fetch() with consecutive return
        // values so the two live calls land in the right buckets.
        $this->stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['sum_whole' => 10000, 'sum_frac' => 0],   // sent-live
                ['sum_whole' => 3000,  'sum_frac' => 0],   // recv-live
            );

        // Balance = received - sent = 3000 - 10000 = -7000
        $result = $this->repository->getContactBalance('user', 'contact');

        $this->assertInstanceOf(SplitAmount::class, $result);
        $this->assertEquals(-7000, $result->whole);
        $this->assertEquals(0, $result->frac);
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
                'currency' => 'USD',
                'total_sent_whole' => 1000,
                'total_sent_frac' => 0,
                'total_received_whole' => 2000,
                'total_received_frac' => 0
            ],
            [
                'contact_hash' => hash(Constants::HASH_ALGORITHM, 'contact2'),
                'currency' => 'USD',
                'total_sent_whole' => 500,
                'total_sent_frac' => 0,
                'total_received_whole' => 500,
                'total_received_frac' => 0
            ]
        ];

        // setUp's callback already returns $this->stmt for live SQL and
        // throws on archive — getAllContactBalances also queries the
        // archive UNION-ALL view, so the same archive-throws contract
        // applies. fetch() yields the balance rows for the live result
        // set then `false` to terminate.
        $this->stmt->method('execute');
        $fetchIndex = 0;
        $this->stmt->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturnCallback(function() use (&$fetchIndex, $balanceData) {
                $row = $balanceData[$fetchIndex] ?? false;
                $fetchIndex++;
                return $row;
            });

        $result = $this->repository->getAllContactBalances($userPubkey, $contactPubkeys);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('contact1', $result);
        $this->assertArrayHasKey('contact2', $result);
        $this->assertArrayHasKey('contact3', $result);
        $this->assertInstanceOf(SplitAmount::class, $result['contact1']['USD']);
        $this->assertEquals(1000, $result['contact1']['USD']->whole); // 2000 - 1000
        $this->assertInstanceOf(SplitAmount::class, $result['contact2']['USD']);
        $this->assertTrue($result['contact2']['USD']->isZero()); // 500 - 500
        $this->assertEquals([], $result['contact3']); // Default for missing
    }

    public function testGetAllContactBalancesReturnsZerosOnQueryFailure(): void
    {
        $contactPubkeys = ['contact1', 'contact2'];

        // setUp's callback returns $this->stmt for live; we make
        // execute() throw to simulate the query failure mode. Archive
        // throws too (callback above), so the result is empty arrays
        // for every contact.
        $this->stmt->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getAllContactBalances('user', $contactPubkeys);

        $this->assertEquals(['contact1' => [], 'contact2' => []], $result);
    }

    // =========================================================================
    // Balance Status Filtering Tests
    // =========================================================================

    public function testGetContactBalanceQueryFiltersCompletedStatus(): void
    {
        // Override setUp's callback so we can both verify the SQL
        // contains the status filter AND keep the archive-throws
        // contract.
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new TransactionContactRepository($this->pdo);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $this->assertStringContainsString("status = 'completed'", $sql);
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['sum_whole' => 5000, 'sum_frac' => 0],
                ['sum_whole' => 8000, 'sum_frac' => 0],
            );

        $this->repository->getContactBalance('user', 'contact');
    }

    public function testGetAllContactBalancesQueryFiltersCompletedStatus(): void
    {
        // Override setUp's callback so we can assert the SQL body
        // contains both the UNION ALL and the completed-status filter,
        // while keeping the archive-throws contract.
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new TransactionContactRepository($this->pdo);

        $this->pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $this->assertStringContainsString('UNION ALL', $sql);
            $this->assertStringContainsString("status = 'completed'", $sql);
            if (str_contains($sql, 'transactions_archive')) {
                throw new PDOException('No archive table in this fixture');
            }
            return $this->stmt;
        });
        $this->stmt->method('execute');
        $this->stmt->method('fetch')->willReturn(false);

        $this->repository->getAllContactBalances('user', ['contact1']);
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
                'amount_whole' => 1000,
                'amount_frac' => 0,
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
        $senderHash = hash(Constants::HASH_ALGORITHM, 'sender-pubkey');

        // Mock UserContext — our public key is the receiver
        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('receiver-pubkey');

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

        $result = $this->repository->contactTransactionExistsForReceiver($senderHash);

        $this->assertTrue($result);
    }

    public function testContactTransactionExistsForReceiverReturnsFalse(): void
    {
        // Mock UserContext — our public key is the receiver
        $userContext = $this->createMock(UserContext::class);
        $userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('receiver-pubkey');

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
