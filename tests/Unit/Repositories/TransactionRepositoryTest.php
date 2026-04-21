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
    // Sync-dedup archive-awareness.
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

    public function testGetByTxidFallsThroughToArchiveWhenLiveMisses(): void
    {
        // handleSyncNegotiationRequest calls getByTxid to fetch a tx the
        // remote is missing. If we only have it in archive, we must still
        // return it so the sync response contains it (otherwise remote
        // stays permanently missing the archived tx).
        $pdo = $this->createMock(PDO::class);

        $liveStmt = $this->createMock(PDOStatement::class);
        $liveStmt->method('execute')->willReturn(true);
        $liveStmt->method('fetchAll')->willReturn([]);  // live miss (empty result)

        $archiveRow = ['txid' => 'tx-archived', 'status' => 'completed', 'amount_whole' => 0, 'amount_frac' => 0];
        $archiveStmt = $this->createMock(PDOStatement::class);
        $archiveStmt->method('execute')->willReturn(true);
        $archiveStmt->method('fetchAll')->willReturn([$archiveRow]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($liveStmt, $archiveStmt);

        $repo = new TransactionRepository($pdo);
        $result = $repo->getByTxid('tx-archived');

        $this->assertNotNull($result);
        $this->assertSame('tx-archived', $result[0]['txid']);
    }

    public function testGetByTxidLiveHitShortCircuits(): void
    {
        // Live hit → should NOT query archive.
        $pdo = $this->createMock(PDO::class);

        $liveStmt = $this->createMock(PDOStatement::class);
        $liveStmt->method('execute')->willReturn(true);
        $liveStmt->method('fetchAll')->willReturn([
            ['txid' => 'tx-live', 'status' => 'completed', 'amount_whole' => 0, 'amount_frac' => 0]
        ]);

        $pdo->expects($this->once())->method('prepare')->willReturn($liveStmt);

        $repo = new TransactionRepository($pdo);
        $result = $repo->getByTxid('tx-live');
        $this->assertSame('tx-live', $result[0]['txid']);
    }

    // =========================================================================
    // getIncomingSince() — regression for live-notifications endpoint.
    // The schema stores amount as amount_whole + amount_frac; there is no
    // single `amount` column. An earlier draft of this method selected
    // `amount` directly, which MySQL rejected at execute time with "Unknown
    // column 'amount'". The try/catch swallowed the PDOException and returned
    // [], so the live-notif endpoint's transaction toasts silently never
    // fired. Tests here pin the SELECT against the correct columns and
    // verify the PHP post-processing fills in the `amount` float the endpoint
    // consumer expects.
    // =========================================================================

    public function testGetIncomingSinceSelectsAmountSplitColumnsNotAggregateAmount(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $userContext = $this->createMock(\Eiou\Core\UserContext::class);
        $userContext->method('getUserAddresses')->willReturn(['alice.onion']);

        $capturedQuery = null;
        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function ($q) use (&$capturedQuery, $stmt) {
                $capturedQuery = $q;
                return $stmt;
            });
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        $repo = new TransactionRepository($pdo);
        $reflection = new \ReflectionClass($repo);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($repo, $userContext);

        $repo->getIncomingSince(0, 25);

        $this->assertNotNull($capturedQuery);
        $this->assertStringContainsString('amount_whole', $capturedQuery);
        $this->assertStringContainsString('amount_frac', $capturedQuery);
        // Guard against a `amount,` or `amount ` reappearing — the schema
        // has no such column. Word-boundary regex avoids a false positive on
        // `amount_whole` / `amount_frac`.
        $this->assertDoesNotMatchRegularExpression(
            '/\bamount\b(?!_)/',
            $capturedQuery,
            'getIncomingSince must not select the non-existent `amount` column'
        );
    }

    public function testGetIncomingSinceCollapsesSplitAmountToFloat(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $userContext = $this->createMock(\Eiou\Core\UserContext::class);
        $userContext->method('getUserAddresses')->willReturn(['alice.onion']);

        $pdo->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willReturn(true);
        // One whole dollar + a fractional piece — the endpoint reads `amount`
        // as a display float, so the repo's post-processing must collapse the
        // pair rather than returning them untouched.
        $stmt->method('fetchAll')->willReturn([[
            'txid' => 'tx-1',
            'type' => 'received',
            'status' => 'completed',
            'amount_whole' => 42,
            'amount_frac' => 50000000, // SplitAmount uses 10^8 scale → 0.50
            'currency' => 'USD',
            'sender_address' => 'bob.onion',
            'receiver_address' => 'alice.onion',
            'timestamp' => '2026-04-21 12:00:00',
            'description' => 'Payment',
        ]]);

        $repo = new TransactionRepository($pdo);
        $reflection = new \ReflectionClass($repo);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($repo, $userContext);

        $result = $repo->getIncomingSince(0, 25);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('amount', $result[0]);
        $this->assertEqualsWithDelta(42.50, $result[0]['amount'], 0.001);
    }

    public function testGetIncomingSinceEmptyOnNoUserAddresses(): void
    {
        $pdo = $this->createMock(PDO::class);
        $userContext = $this->createMock(\Eiou\Core\UserContext::class);
        $userContext->method('getUserAddresses')->willReturn([]);

        // Short-circuit before any query runs.
        $pdo->expects($this->never())->method('prepare');

        $repo = new TransactionRepository($pdo);
        $reflection = new \ReflectionClass($repo);
        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($repo, $userContext);

        $this->assertSame([], $repo->getIncomingSince(0, 25));
    }
}
