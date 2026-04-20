<?php
/**
 * Unit Tests for TransactionRepository
 *
 * Tests transaction repository constants and configuration.
 * Note: Full database tests require integration testing with Docker.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;

#[CoversClass(TransactionRepository::class)]
class TransactionRepositoryTest extends TestCase
{
    // =========================================================================
    // getPreviousTxid() / getPreviousTxidsByCurrency() — cancelled/rejected filter
    //
    // Cancelled-while-pending rows are sender-local (never signed / never
    // delivered). Rejected rows are also excluded from sync responses. New
    // transactions must NOT use either as their previous_txid or the peer
    // never sees the link target, producing a permanent chain gap.
    // =========================================================================

    public function testGetPreviousTxidFiltersCancelledAndRejected(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("status NOT IN ('cancelled', 'rejected')"))
            ->willReturn($stmt);

        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['txid' => 'tx-prev']);

        $repo = new TransactionRepository($pdo);
        $result = $repo->getPreviousTxid('sender-pub', 'receiver-pub');

        $this->assertEquals('tx-prev', $result);
    }

    public function testGetPreviousTxidOrdersByTimestampDesc(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('ORDER BY timestamp DESC LIMIT 1'))
            ->willReturn($stmt);

        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $repo = new TransactionRepository($pdo);
        $repo->getPreviousTxid('sender-pub', 'receiver-pub');
    }

    public function testGetPreviousTxidsByCurrencyFiltersCancelledAndRejected(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("status NOT IN ('cancelled', 'rejected')"))
            ->willReturn($stmt);

        $stmt->method('execute')->willReturn(true);
        // Return USD row then end-of-results
        $stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['currency' => 'USD', 'txid' => 'tx-usd'],
                false
            );

        $repo = new TransactionRepository($pdo);
        $result = $repo->getPreviousTxidsByCurrency('sender-pub', 'receiver-pub');

        $this->assertArrayHasKey('USD', $result);
        $this->assertEquals('tx-usd', $result['USD']);
    }

    /**
     * Test transaction status constants are defined
     */
    public function testTransactionStatusConstantsAreDefined(): void
    {
        $this->assertEquals('pending', Constants::STATUS_PENDING);
        $this->assertEquals('completed', Constants::STATUS_COMPLETED);
        $this->assertEquals('accepted', Constants::STATUS_ACCEPTED);
        $this->assertEquals('rejected', Constants::STATUS_REJECTED);
    }

    /**
     * Test transaction type constants
     */
    public function testTransactionTypeConstants(): void
    {
        $this->assertEquals('sent', Constants::TX_TYPE_SENT);
        $this->assertEquals('received', Constants::TX_TYPE_RECEIVED);
    }

    /**
     * Test hash length constant for txid validation
     */
    public function testHashLengthConstant(): void
    {
        // SHA-256 produces 64 character hex string
        $this->assertEquals(64, Constants::VALIDATION_HASH_LENGTH_SHA256);
    }

    /**
     * Test transaction max amount constant
     */
    public function testTransactionMaxAmountConstant(): void
    {
        $this->assertIsInt(Constants::TRANSACTION_MAX_AMOUNT);
        $this->assertGreaterThan(0, Constants::TRANSACTION_MAX_AMOUNT);
        // Should be large enough for practical use
        $this->assertGreaterThanOrEqual(1000000, Constants::TRANSACTION_MAX_AMOUNT);
    }

    /**
     * Test display date format constant
     */
    public function testDisplayDateFormatConstant(): void
    {
        $this->assertNotEmpty(Constants::DISPLAY_DATE_FORMAT);

        // Verify it's a valid date format by testing it
        $testDate = date(Constants::DISPLAY_DATE_FORMAT);
        $this->assertNotEmpty($testDate);
    }

    // =========================================================================
    // #863 phase 4: sync-dedup archive-awareness
    //
    // Without these, a counterparty that still has our archived txs would
    // re-push them via sync into our live table, causing duplicate-key
    // violations on the next archival run.
    // =========================================================================

    public function testTransactionExistsTxidChecksLiveFirst(): void
    {
        // Live returns true → archive query should not happen (short-circuit)
        // Live path goes through AbstractRepository::count() which expects
        // the result row to have a 'count' column — returning 1 here means
        // the row exists, so we short-circuit and never hit archive.
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['count' => 1]);  // live has 1 matching row
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);

        $repo = new TransactionRepository($pdo);
        $this->assertTrue($repo->transactionExistsTxid('tx1'));
    }

    public function testTransactionExistsTxidFallsThroughToArchiveWhenLiveMisses(): void
    {
        // Live miss (count=0) → archive check → fetchColumn returns truthy → true
        $pdo = $this->createMock(PDO::class);
        $liveStmt = $this->createMock(PDOStatement::class);
        $liveStmt->method('execute')->willReturn(true);
        $liveStmt->method('fetch')->willReturn(['count' => 0]);

        $archiveStmt = $this->createMock(PDOStatement::class);
        $archiveStmt->method('execute')->willReturn(true);
        $archiveStmt->method('fetchColumn')->willReturn('1');

        $pdo->expects($this->exactly(2))->method('prepare')
            ->willReturnOnConsecutiveCalls($liveStmt, $archiveStmt);

        $repo = new TransactionRepository($pdo);
        $this->assertTrue($repo->transactionExistsTxid('tx-archived'));
    }

    public function testTransactionExistsTxidReturnsFalseWhenNeitherHasIt(): void
    {
        $pdo = $this->createMock(PDO::class);
        $liveStmt = $this->createMock(PDOStatement::class);
        $liveStmt->method('execute')->willReturn(true);
        $liveStmt->method('fetch')->willReturn(['count' => 0]);

        $archiveStmt = $this->createMock(PDOStatement::class);
        $archiveStmt->method('execute')->willReturn(true);
        $archiveStmt->method('fetchColumn')->willReturn(false);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($liveStmt, $archiveStmt);

        $repo = new TransactionRepository($pdo);
        $this->assertFalse($repo->transactionExistsTxid('tx-unknown'));
    }

    public function testTransactionExistsTxidArchiveMissingFallsBackToLiveOnly(): void
    {
        // v9→v10 transitional: archive table doesn't exist yet. Live miss
        // + archive PDOException should return false (not blow up).
        $pdo = $this->createMock(PDO::class);
        $liveStmt = $this->createMock(PDOStatement::class);
        $liveStmt->method('execute')->willReturn(true);
        $liveStmt->method('fetch')->willReturn(['count' => 0]);

        $archiveStmt = $this->createMock(PDOStatement::class);
        $archiveStmt->method('execute')
            ->willThrowException(new \PDOException('Table transactions_archive not found'));

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($liveStmt, $archiveStmt);

        $repo = new TransactionRepository($pdo);
        $this->assertFalse($repo->transactionExistsTxid('tx'));
    }

    public function testGetStatusByTxidFallsThroughToArchive(): void
    {
        // Live returns null (not found) → archive returns 'completed'
        $pdo = $this->createMock(PDO::class);

        $liveStmt = $this->createMock(PDOStatement::class);
        $liveStmt->method('execute')->willReturn(true);
        $liveStmt->method('fetchColumn')->willReturn(false);  // live miss

        $archiveStmt = $this->createMock(PDOStatement::class);
        $archiveStmt->method('execute')->willReturn(true);
        $archiveStmt->method('fetchColumn')->willReturn('completed');

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($liveStmt, $archiveStmt);

        $repo = new TransactionRepository($pdo);
        $this->assertSame('completed', $repo->getStatusByTxid('tx-archived'));
    }
}
