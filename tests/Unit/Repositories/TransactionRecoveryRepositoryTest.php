<?php
/**
 * Unit Tests for TransactionRecoveryRepository
 *
 * Tests transaction recovery repository operations including
 * pending transaction retrieval, claiming, stuck transaction detection,
 * and recovery operations.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(TransactionRecoveryRepository::class)]
class TransactionRecoveryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private TransactionRecoveryRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new TransactionRecoveryRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorSetsPdoConnection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repo = new TransactionRecoveryRepository($pdo);

        $this->assertSame($pdo, $repo->getPdo());
    }

    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('transactions', $this->repository->getTableName());
    }

    // =========================================================================
    // getPendingTransactions() Tests
    // =========================================================================

    public function testGetPendingTransactionsReturnsLimitedResults(): void
    {
        $pendingTransactions = [
            ['txid' => 'tx1', 'status' => 'pending', 'amount' => 1000],
            ['txid' => 'tx2', 'status' => 'pending', 'amount' => 2000],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("WHERE status = 'pending'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 5, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($pendingTransactions);

        $result = $this->repository->getPendingTransactions(5);

        $this->assertCount(2, $result);
        $this->assertEquals('tx1', $result[0]['txid']);
    }

    public function testGetPendingTransactionsUsesDefaultLimit(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 5, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getPendingTransactions();
    }

    public function testGetPendingTransactionsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getPendingTransactions();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // claimPendingTransaction() Tests
    // =========================================================================

    public function testClaimPendingTransactionReturnsTrueOnSuccess(): void
    {
        $txid = 'pending-tx-123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('UPDATE transactions'),
                $this->stringContains('SET status = :new_status'),
                $this->stringContains('WHERE txid = :txid')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue')
            ->willReturnCallback(function($key, $value) {
                if ($key === ':new_status') {
                    $this->assertEquals(Constants::STATUS_SENDING, $value);
                }
                if ($key === ':current_status') {
                    $this->assertEquals(Constants::STATUS_PENDING, $value);
                }
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->claimPendingTransaction($txid);

        $this->assertTrue($result);
    }

    public function testClaimPendingTransactionReturnsFalseWhenAlreadyClaimed(): void
    {
        $txid = 'already-claimed-tx';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->claimPendingTransaction($txid);

        $this->assertFalse($result);
    }

    public function testClaimPendingTransactionReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->claimPendingTransaction('tx-123');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getStuckSendingTransactions() Tests
    // =========================================================================

    public function testGetStuckSendingTransactionsUsesDefaultTimeout(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('sending_started_at < :cutoff_time'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnCallback(function($key, $value) {
                if ($key === ':status') {
                    $this->assertEquals(Constants::STATUS_SENDING, $value);
                }
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getStuckSendingTransactions();
    }

    public function testGetStuckSendingTransactionsWithCustomTimeout(): void
    {
        $customTimeout = 60; // 60 seconds

        $stuckTransactions = [
            ['txid' => 'stuck-tx-1', 'status' => 'sending', 'sending_started_at' => '2024-01-01 10:00:00'],
            ['txid' => 'stuck-tx-2', 'status' => 'sending', 'sending_started_at' => '2024-01-01 10:01:00'],
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
            ->willReturn($stuckTransactions);

        $result = $this->repository->getStuckSendingTransactions($customTimeout);

        $this->assertCount(2, $result);
    }

    public function testGetStuckSendingTransactionsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStuckSendingTransactions(60);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // recoverStuckTransaction() Tests
    // =========================================================================

    public function testRecoverStuckTransactionResetsToPending(): void
    {
        $txid = 'stuck-tx';

        // Mock for getting current recovery count
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->expects($this->once())
            ->method('bindValue')
            ->with(':txid', $txid);
        $selectStmt->expects($this->once())
            ->method('execute');
        $selectStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['recovery_count' => 1]);

        // Mock for update query
        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->expects($this->exactly(4))
            ->method('bindValue');
        $updateStmt->expects($this->once())
            ->method('execute');
        $updateStmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $callCount = 0;
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function($query) use (&$callCount, $selectStmt, $updateStmt) {
                $callCount++;
                return $callCount === 1 ? $selectStmt : $updateStmt;
            });

        $result = $this->repository->recoverStuckTransaction($txid);

        $this->assertTrue($result['recovered']);
        $this->assertFalse($result['needs_review']);
        $this->assertEquals(2, $result['recovery_count']);
    }

    public function testRecoverStuckTransactionMarksForManualReviewWhenExceedsRetries(): void
    {
        $txid = 'stuck-tx';
        $maxRetries = 3;

        // Current count is already at max
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->expects($this->once())
            ->method('execute');
        $selectStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['recovery_count' => 3]);

        // Update to mark as failed + needs_manual_review
        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->expects($this->exactly(3))
            ->method('bindValue')
            ->willReturnCallback(function($key, $value) {
                if ($key === ':status') {
                    $this->assertEquals(Constants::STATUS_FAILED, $value);
                }
                return true;
            });
        $updateStmt->expects($this->once())
            ->method('execute');

        $callCount = 0;
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function() use (&$callCount, $selectStmt, $updateStmt) {
                $callCount++;
                return $callCount === 1 ? $selectStmt : $updateStmt;
            });

        $result = $this->repository->recoverStuckTransaction($txid, $maxRetries);

        $this->assertFalse($result['recovered']);
        $this->assertTrue($result['needs_review']);
        $this->assertEquals(4, $result['recovery_count']);
    }

    public function testRecoverStuckTransactionReturnsDefaultsWhenNotFound(): void
    {
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->expects($this->once())
            ->method('execute');
        $selectStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($selectStmt);

        $result = $this->repository->recoverStuckTransaction('nonexistent-tx');

        $this->assertFalse($result['recovered']);
        $this->assertFalse($result['needs_review']);
        $this->assertEquals(0, $result['recovery_count']);
    }

    public function testRecoverStuckTransactionUsesDefaultMaxRetries(): void
    {
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->expects($this->once())
            ->method('execute');
        $selectStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['recovery_count' => 0]);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->expects($this->once())
            ->method('execute');
        $updateStmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $callCount = 0;
        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function() use (&$callCount, $selectStmt, $updateStmt) {
                $callCount++;
                return $callCount === 1 ? $selectStmt : $updateStmt;
            });

        $result = $this->repository->recoverStuckTransaction('tx-123');

        // Should use Constants::RECOVERY_MAX_RETRY_COUNT as default
        $this->assertTrue($result['recovered']);
    }

    // =========================================================================
    // getTransactionsNeedingReview() Tests
    // =========================================================================

    public function testGetTransactionsNeedingReviewReturnsMatchingRecords(): void
    {
        $reviewTransactions = [
            ['txid' => 'review-tx-1', 'needs_manual_review' => 1, 'recovery_count' => 4],
            ['txid' => 'review-tx-2', 'needs_manual_review' => 1, 'recovery_count' => 5],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE needs_manual_review = 1'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($reviewTransactions);

        $result = $this->repository->getTransactionsNeedingReview();

        $this->assertCount(2, $result);
    }

    public function testGetTransactionsNeedingReviewReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getTransactionsNeedingReview();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // markAsSent() Tests
    // =========================================================================

    public function testMarkAsSentReturnsTrueOnSuccess(): void
    {
        $txid = 'sending-tx';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('SET status = :new_status'),
                $this->stringContains('sending_started_at = NULL')
            ))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(3))
            ->method('bindValue')
            ->willReturnCallback(function($key, $value) {
                if ($key === ':new_status') {
                    $this->assertEquals(Constants::STATUS_SENT, $value);
                }
                if ($key === ':current_status') {
                    $this->assertEquals(Constants::STATUS_SENDING, $value);
                }
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->markAsSent($txid);

        $this->assertTrue($result);
    }

    public function testMarkAsSentReturnsFalseWhenNoRowsUpdated(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->markAsSent('nonexistent-tx');

        $this->assertFalse($result);
    }

    public function testMarkAsSentReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->markAsSent('tx-123');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getInProgressTransactions() Tests
    // =========================================================================

    public function testGetInProgressTransactionsReturnsEmptyWhenNoUserAddresses(): void
    {
        $repository = $this->getMockBuilder(TransactionRecoveryRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn(null);

        $result = $repository->getInProgressTransactions();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetInProgressTransactionsReturnsTransactions(): void
    {
        $userAddresses = ['http://user.example'];

        $inProgressTransactions = [
            [
                'txid' => 'tx1',
                'tx_type' => 'standard',
                'status' => 'pending',
                'phase' => 'pending',
                'is_held' => 0
            ],
            [
                'txid' => 'tx2',
                'tx_type' => 'p2p',
                'status' => 'sent',
                'phase' => 'route_search',
                'is_held' => 0
            ]
        ];

        $repository = $this->getMockBuilder(TransactionRecoveryRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn($userAddresses);

        // Mock checking for held_transactions table
        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false); // Table doesn't exist

        $mainStmt = $this->createMock(PDOStatement::class);
        $mainStmt->expects($this->once())
            ->method('execute');
        $mainStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($inProgressTransactions);

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($checkStmt);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($mainStmt);

        $result = $repository->getInProgressTransactions();

        $this->assertCount(2, $result);
    }

    public function testGetInProgressTransactionsUsesCustomLimit(): void
    {
        $userAddresses = ['http://user.example'];

        $repository = $this->getMockBuilder(TransactionRecoveryRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn($userAddresses);

        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $mainStmt = $this->createMock(PDOStatement::class);
        $mainStmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function($params) {
                // Last param should be the limit (20)
                return end($params) === 20;
            }));
        $mainStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($checkStmt);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($mainStmt);

        $repository->getInProgressTransactions(20);
    }

    public function testGetInProgressTransactionsReturnsEmptyArrayOnFailure(): void
    {
        $userAddresses = ['http://user.example'];

        $repository = $this->getMockBuilder(TransactionRecoveryRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn($userAddresses);

        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $mainStmt = $this->createMock(PDOStatement::class);
        $mainStmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($checkStmt);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($mainStmt);

        $result = $repository->getInProgressTransactions();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetInProgressTransactionsHandlesHeldTransactionsTable(): void
    {
        $userAddresses = ['http://user.example'];

        $repository = $this->getMockBuilder(TransactionRecoveryRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getUserAddressesOrNull'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getUserAddressesOrNull')
            ->willReturn($userAddresses);

        // Table exists
        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['name' => 'held_transactions']);

        $mainStmt = $this->createMock(PDOStatement::class);
        $mainStmt->expects($this->once())
            ->method('execute');
        $mainStmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($checkStmt);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('held_transactions'))
            ->willReturn($mainStmt);

        $repository->getInProgressTransactions();
    }
}
