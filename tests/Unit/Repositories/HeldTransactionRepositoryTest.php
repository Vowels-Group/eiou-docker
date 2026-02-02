<?php
/**
 * Unit Tests for HeldTransactionRepository
 *
 * Tests held transaction repository database operations with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Database\HeldTransactionRepository;
use PDO;
use PDOStatement;
use PDOException;
use ReflectionClass;

#[CoversClass(HeldTransactionRepository::class)]
class HeldTransactionRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private HeldTransactionRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new HeldTransactionRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('held_transactions', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new HeldTransactionRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // holdTransaction() Tests
    // =========================================================================

    /**
     * Test holdTransaction inserts record successfully
     */
    public function testHoldTransactionInsertsRecordSuccessfully(): void
    {
        $contactPubkeyHash = 'abc123def456';
        $txid = 'txid_789xyz';
        $originalPreviousTxid = 'prev_txid_456';
        $expectedPreviousTxid = 'expected_txid_123';
        $transactionType = 'standard';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('42');

        $result = $this->repository->holdTransaction(
            $contactPubkeyHash,
            $txid,
            $originalPreviousTxid,
            $expectedPreviousTxid,
            $transactionType
        );

        $this->assertEquals(42, $result);
    }

    /**
     * Test holdTransaction with null optional parameters
     */
    public function testHoldTransactionWithNullOptionalParameters(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->holdTransaction(
            'contact_hash',
            'txid_123',
            null,
            null,
            'standard'
        );

        $this->assertEquals(1, $result);
    }

    /**
     * Test holdTransaction returns false on failure
     */
    public function testHoldTransactionReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->holdTransaction(
            'contact_hash',
            'txid_123',
            'prev_txid'
        );

        $this->assertFalse($result);
    }

    // =========================================================================
    // hasHeldTransactions() Tests
    // =========================================================================

    /**
     * Test hasHeldTransactions returns true when transactions exist
     */
    public function testHasHeldTransactionsReturnsTrueWhenTransactionsExist(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 3]);

        $result = $this->repository->hasHeldTransactions('contact_hash_123');

        $this->assertTrue($result);
    }

    /**
     * Test hasHeldTransactions returns false when no transactions exist
     */
    public function testHasHeldTransactionsReturnsFalseWhenNoTransactionsExist(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 0]);

        $result = $this->repository->hasHeldTransactions('contact_hash_123');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getHeldTransactionsForContact() Tests
    // =========================================================================

    /**
     * Test getHeldTransactionsForContact returns transactions without status filter
     */
    public function testGetHeldTransactionsForContactReturnsTransactionsWithoutStatusFilter(): void
    {
        $expectedTransactions = [
            ['id' => 1, 'txid' => 'tx1', 'contact_pubkey_hash' => 'hash1'],
            ['id' => 2, 'txid' => 'tx2', 'contact_pubkey_hash' => 'hash1']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransactions);

        $result = $this->repository->getHeldTransactionsForContact('hash1');

        $this->assertEquals($expectedTransactions, $result);
    }

    /**
     * Test getHeldTransactionsForContact with sync status filter
     */
    public function testGetHeldTransactionsForContactWithSyncStatusFilter(): void
    {
        $expectedTransactions = [
            ['id' => 1, 'txid' => 'tx1', 'sync_status' => 'completed']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransactions);

        $result = $this->repository->getHeldTransactionsForContact('hash1', 'completed');

        $this->assertEquals($expectedTransactions, $result);
    }

    /**
     * Test getHeldTransactionsForContact returns empty array on failure
     */
    public function testGetHeldTransactionsForContactReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getHeldTransactionsForContact('hash1', 'completed');

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // isTransactionHeld() Tests
    // =========================================================================

    /**
     * Test isTransactionHeld returns true when transaction is held
     */
    public function testIsTransactionHeldReturnsTrueWhenHeld(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 1]);

        $result = $this->repository->isTransactionHeld('txid_123');

        $this->assertTrue($result);
    }

    /**
     * Test isTransactionHeld returns false when transaction not held
     */
    public function testIsTransactionHeldReturnsFalseWhenNotHeld(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 0]);

        $result = $this->repository->isTransactionHeld('txid_123');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getByTxid() Tests
    // =========================================================================

    /**
     * Test getByTxid returns transaction data when found
     */
    public function testGetByTxidReturnsTransactionWhenFound(): void
    {
        $expectedTransaction = [
            'id' => 1,
            'txid' => 'txid_123',
            'contact_pubkey_hash' => 'hash_abc',
            'sync_status' => 'completed'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransaction);

        $result = $this->repository->getByTxid('txid_123');

        $this->assertEquals($expectedTransaction, $result);
    }

    /**
     * Test getByTxid returns null when not found
     */
    public function testGetByTxidReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getByTxid('nonexistent_txid');

        $this->assertNull($result);
    }

    // =========================================================================
    // updateSyncStatusForContact() Tests
    // =========================================================================

    /**
     * Test updateSyncStatusForContact updates status successfully
     */
    public function testUpdateSyncStatusForContactUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(3);

        $result = $this->repository->updateSyncStatusForContact('contact_hash', 'in_progress');

        $this->assertEquals(3, $result);
    }

    /**
     * Test updateSyncStatusForContact returns -1 on failure
     */
    public function testUpdateSyncStatusForContactReturnsNegativeOneOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->updateSyncStatusForContact('contact_hash', 'in_progress');

        $this->assertEquals(-1, $result);
    }

    // =========================================================================
    // markReadyToResume() Tests
    // =========================================================================

    /**
     * Test markReadyToResume delegates to updateSyncStatusForContact with 'completed'
     */
    public function testMarkReadyToResumeSetsStatusToCompleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SET sync_status = :status"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(2);

        $result = $this->repository->markReadyToResume('contact_hash');

        $this->assertEquals(2, $result);
    }

    // =========================================================================
    // getTransactionsToResume() Tests
    // =========================================================================

    /**
     * Test getTransactionsToResume returns completed transactions
     */
    public function testGetTransactionsToResumeReturnsCompletedTransactions(): void
    {
        $expectedTransactions = [
            ['id' => 1, 'txid' => 'tx1', 'sync_status' => 'completed'],
            ['id' => 2, 'txid' => 'tx2', 'sync_status' => 'completed']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("WHERE sync_status = 'completed'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 10, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransactions);

        $result = $this->repository->getTransactionsToResume(10);

        $this->assertEquals($expectedTransactions, $result);
    }

    /**
     * Test getTransactionsToResume returns empty array on exception
     */
    public function testGetTransactionsToResumeReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getTransactionsToResume();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // releaseTransaction() Tests
    // =========================================================================

    /**
     * Test releaseTransaction deletes transaction and returns true
     */
    public function testReleaseTransactionDeletesAndReturnsTrue(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->releaseTransaction('txid_123');

        $this->assertTrue($result);
    }

    /**
     * Test releaseTransaction returns false when no rows deleted
     */
    public function testReleaseTransactionReturnsFalseWhenNoRowsDeleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->releaseTransaction('nonexistent_txid');

        $this->assertFalse($result);
    }

    // =========================================================================
    // releaseAllForContact() Tests
    // =========================================================================

    /**
     * Test releaseAllForContact deletes all transactions for contact
     */
    public function testReleaseAllForContactDeletesAllTransactions(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(5);

        $result = $this->repository->releaseAllForContact('contact_hash');

        $this->assertEquals(5, $result);
    }

    // =========================================================================
    // isSyncInProgress() Tests
    // =========================================================================

    /**
     * Test isSyncInProgress returns true when sync is in progress
     */
    public function testIsSyncInProgressReturnsTrueWhenInProgress(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("sync_status = 'in_progress'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 2]);

        $result = $this->repository->isSyncInProgress('contact_hash');

        $this->assertTrue($result);
    }

    /**
     * Test isSyncInProgress returns false when no sync in progress
     */
    public function testIsSyncInProgressReturnsFalseWhenNotInProgress(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->isSyncInProgress('contact_hash');

        $this->assertFalse($result);
    }

    // =========================================================================
    // markSyncStarted() Tests
    // =========================================================================

    /**
     * Test markSyncStarted updates status from not_started to in_progress
     */
    public function testMarkSyncStartedUpdatesStatusToInProgress(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SET sync_status = 'in_progress'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->markSyncStarted('contact_hash');

        $this->assertTrue($result);
    }

    /**
     * Test markSyncStarted returns false when no rows updated
     */
    public function testMarkSyncStartedReturnsFalseWhenNoRowsUpdated(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->markSyncStarted('contact_hash');

        $this->assertFalse($result);
    }

    // =========================================================================
    // markSyncCompleted() Tests
    // =========================================================================

    /**
     * Test markSyncCompleted updates status to completed
     */
    public function testMarkSyncCompletedUpdatesStatusToCompleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SET sync_status = 'completed'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(2);

        $result = $this->repository->markSyncCompleted('contact_hash');

        $this->assertTrue($result);
    }

    // =========================================================================
    // markSyncFailed() Tests
    // =========================================================================

    /**
     * Test markSyncFailed updates status to failed
     */
    public function testMarkSyncFailedUpdatesStatusToFailed(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("SET sync_status = 'failed'"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->markSyncFailed('contact_hash');

        $this->assertTrue($result);
    }

    // =========================================================================
    // incrementRetry() Tests
    // =========================================================================

    /**
     * Test incrementRetry increments retry count
     */
    public function testIncrementRetryIncrementsCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('retry_count = retry_count + 1'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->incrementRetry('txid_123');

        $this->assertTrue($result);
    }

    /**
     * Test incrementRetry returns false when no rows updated
     */
    public function testIncrementRetryReturnsFalseWhenNoRowsUpdated(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->incrementRetry('nonexistent_txid');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getExhaustedRetries() Tests
    // =========================================================================

    /**
     * Test getExhaustedRetries returns transactions with exhausted retries
     */
    public function testGetExhaustedRetriesReturnsExhaustedTransactions(): void
    {
        $expectedTransactions = [
            ['id' => 1, 'txid' => 'tx1', 'retry_count' => 3, 'max_retries' => 3]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('retry_count >= max_retries'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 10, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransactions);

        $result = $this->repository->getExhaustedRetries(10);

        $this->assertEquals($expectedTransactions, $result);
    }

    /**
     * Test getExhaustedRetries returns empty array on exception
     */
    public function testGetExhaustedRetriesReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getExhaustedRetries();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // cleanupOldRecords() Tests
    // =========================================================================

    /**
     * Test cleanupOldRecords deletes old completed and failed records
     */
    public function testCleanupOldRecordsDeletesOldRecords(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("sync_status IN ('completed', 'failed')"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(15);

        $result = $this->repository->cleanupOldRecords(7);

        $this->assertEquals(15, $result);
    }

    /**
     * Test cleanupOldRecords returns -1 on failure
     */
    public function testCleanupOldRecordsReturnsNegativeOneOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Delete failed'));

        $result = $this->repository->cleanupOldRecords();

        $this->assertEquals(-1, $result);
    }

    // =========================================================================
    // getAll() Tests
    // =========================================================================

    /**
     * Test getAll returns all held transactions
     */
    public function testGetAllReturnsAllHeldTransactions(): void
    {
        $expectedTransactions = [
            ['id' => 1, 'txid' => 'tx1'],
            ['id' => 2, 'txid' => 'tx2'],
            ['id' => 3, 'txid' => 'tx3']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransactions);

        $result = $this->repository->getAll();

        $this->assertEquals($expectedTransactions, $result);
    }

    /**
     * Test getAll with limit parameter
     */
    public function testGetAllWithLimitParameter(): void
    {
        $expectedTransactions = [
            ['id' => 1, 'txid' => 'tx1']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('LIMIT'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedTransactions);

        $result = $this->repository->getAll(1);

        $this->assertCount(1, $result);
    }

    // =========================================================================
    // Allowed Columns Tests
    // =========================================================================

    /**
     * Test allowed columns are properly defined
     */
    public function testAllowedColumnsAreDefined(): void
    {
        $reflection = new ReflectionClass($this->repository);
        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);
        $allowedColumns = $property->getValue($this->repository);

        $expectedColumns = [
            'id', 'contact_pubkey_hash', 'txid', 'original_previous_txid',
            'expected_previous_txid', 'transaction_type', 'hold_reason',
            'sync_status', 'retry_count', 'max_retries', 'held_at',
            'last_sync_attempt', 'next_retry_at', 'resolved_at'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $allowedColumns);
        }
    }
}
