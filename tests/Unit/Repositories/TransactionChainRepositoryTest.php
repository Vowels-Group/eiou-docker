<?php
/**
 * Unit Tests for TransactionChainRepository
 *
 * Tests transaction chain repository database operations including
 * chain integrity verification, sync state summaries, and chain
 * conflict resolution operations.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\TransactionChainRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(TransactionChainRepository::class)]
class TransactionChainRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private TransactionChainRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new TransactionChainRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorSetsPdoConnection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repo = new TransactionChainRepository($pdo);

        $this->assertSame($pdo, $repo->getPdo());
    }

    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('transactions', $this->repository->getTableName());
    }

    // =========================================================================
    // verifyChainIntegrity() Tests
    // =========================================================================

    public function testVerifyChainIntegrityReturnsValidForCompleteChain(): void
    {
        $userPublicKey = 'user-pubkey-123';
        $contactPublicKey = 'contact-pubkey-456';

        // Transactions with proper chain: tx1 -> tx2 -> tx3
        $transactions = [
            ['txid' => 'tx1', 'previous_txid' => null, 'status' => 'completed'],
            ['txid' => 'tx2', 'previous_txid' => 'tx1', 'status' => 'completed'],
            ['txid' => 'tx3', 'previous_txid' => 'tx2', 'status' => 'completed'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT txid, previous_txid, status'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($transactions);

        $result = $this->repository->verifyChainIntegrity($userPublicKey, $contactPublicKey);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['has_transactions']);
        $this->assertEquals(3, $result['transaction_count']);
        $this->assertEmpty($result['gaps']);
        $this->assertEmpty($result['broken_txids']);
    }

    public function testVerifyChainIntegrityDetectsGapsInChain(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';

        // Chain with gap: tx1 exists, tx2 missing, tx3 points to missing tx2
        $transactions = [
            ['txid' => 'tx1', 'previous_txid' => null, 'status' => 'completed'],
            ['txid' => 'tx3', 'previous_txid' => 'tx2', 'status' => 'completed'], // tx2 is missing
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($transactions);

        $result = $this->repository->verifyChainIntegrity($userPublicKey, $contactPublicKey);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['has_transactions']);
        $this->assertEquals(2, $result['transaction_count']);
        $this->assertContains('tx2', $result['gaps']);
        $this->assertContains('tx3', $result['broken_txids']);
    }

    public function testVerifyChainIntegrityReturnsValidForEmptyChain(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->verifyChainIntegrity($userPublicKey, $contactPublicKey);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['has_transactions']);
        $this->assertEquals(0, $result['transaction_count']);
    }

    public function testVerifyChainIntegritySkipsInFlightTransactionLinks(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';

        // Chain: tx1 (completed) → tx2 (sending, points to missing txX) → tx3 (completed, points to tx2)
        // tx2 is in-flight — its previous_txid link should NOT be checked (txX isn't synced yet)
        // tx3 references tx2's txid — tx2's txid should still be in the lookup set
        // Result: chain should be VALID (no false gaps in either direction)
        $transactions = [
            ['txid' => 'tx1', 'previous_txid' => null, 'status' => 'completed'],
            ['txid' => 'tx2', 'previous_txid' => 'txX', 'status' => 'sending'],  // in-flight, skip check
            ['txid' => 'tx3', 'previous_txid' => 'tx2', 'status' => 'completed'], // tx2 txid in lookup
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($transactions);

        $result = $this->repository->verifyChainIntegrity($userPublicKey, $contactPublicKey);

        $this->assertTrue($result['valid']);
        $this->assertEquals(2, $result['transaction_count']); // only settled count
        $this->assertEmpty($result['gaps']);
    }

    public function testVerifyChainIntegrityDetectsRealGapInSettledTransactions(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';

        // tx3 (completed) references tx2 which doesn't exist at all — real gap
        $transactions = [
            ['txid' => 'tx1', 'previous_txid' => null, 'status' => 'completed'],
            ['txid' => 'tx3', 'previous_txid' => 'tx2', 'status' => 'completed'], // tx2 missing entirely
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn($transactions);

        $result = $this->repository->verifyChainIntegrity($userPublicKey, $contactPublicKey);

        $this->assertFalse($result['valid']);
        $this->assertContains('tx2', $result['gaps']);
    }

    public function testVerifyChainIntegrityHandlesQueryFailure(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->verifyChainIntegrity($userPublicKey, $contactPublicKey);

        $this->assertTrue($result['valid']); // Default state
        $this->assertFalse($result['has_transactions']);
        $this->assertEquals(0, $result['transaction_count']);
    }

    // =========================================================================
    // getTransactionChain() Tests
    // =========================================================================

    public function testGetTransactionChainReturnsAllTransactions(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';

        $expectedTransactions = [
            ['txid' => 'tx1', 'amount' => 1000, 'status' => 'completed'],
            ['txid' => 'tx2', 'amount' => 2000, 'status' => 'completed'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT * FROM transactions'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransactions);

        $result = $this->repository->getTransactionChain($userPublicKey, $contactPublicKey);

        $this->assertCount(2, $result);
        $this->assertEquals('tx1', $result[0]['txid']);
    }

    public function testGetTransactionChainWithAfterTxidFiltersResults(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';
        $afterTxid = 'tx1';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE txid = :after_txid'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(5)) // 4 hash params + afterTxid
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $this->repository->getTransactionChain($userPublicKey, $contactPublicKey, $afterTxid);
    }

    public function testGetTransactionChainReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getTransactionChain('user', 'contact');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getChainStateSummary() Tests
    // =========================================================================

    public function testGetChainStateSummaryReturnsCompleteInfo(): void
    {
        $userPublicKey = 'user-pubkey';
        $contactPublicKey = 'contact-pubkey';

        $summaryData = [
            'transaction_count' => 10,
            'oldest_txid' => 'tx-oldest',
            'newest_txid' => 'tx-newest'
        ];

        $txidList = [
            ['txid' => 'tx1'],
            ['txid' => 'tx2'],
            ['txid' => 'tx3'],
        ];

        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(8)) // 4 for summary + 4 for txid list
            ->method('bindValue');

        $this->stmt->expects($this->exactly(2))
            ->method('execute');

        $fetchIndex = 0;
        $this->stmt->expects($this->exactly(5)) // 1 summary + 3 txids + 1 false terminator
            ->method('fetch')
            ->willReturnCallback(function() use (&$fetchIndex, $summaryData, $txidList) {
                $fetchIndex++;
                if ($fetchIndex === 1) {
                    return $summaryData;
                }
                // Return txid list items then false
                $listIndex = $fetchIndex - 2;
                return $txidList[$listIndex] ?? false;
            });

        $result = $this->repository->getChainStateSummary($userPublicKey, $contactPublicKey);

        $this->assertEquals(10, $result['transaction_count']);
        $this->assertEquals('tx-oldest', $result['oldest_txid']);
        $this->assertEquals('tx-newest', $result['newest_txid']);
        $this->assertIsArray($result['txid_list']);
    }

    public function testGetChainStateSummaryReturnsDefaultsOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getChainStateSummary('user', 'contact');

        $this->assertEquals(0, $result['transaction_count']);
        $this->assertNull($result['oldest_txid']);
        $this->assertNull($result['newest_txid']);
        $this->assertEmpty($result['txid_list']);
    }

    // =========================================================================
    // getByPreviousTxid() Tests
    // =========================================================================

    public function testGetByPreviousTxidReturnsMatchingTransactions(): void
    {
        $previousTxid = 'prev-tx-123';
        $expectedTransactions = [
            ['txid' => 'tx1', 'previous_txid' => $previousTxid],
            ['txid' => 'tx2', 'previous_txid' => $previousTxid],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE previous_txid = :previous_txid'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':previous_txid', $previousTxid);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransactions);

        $result = $this->repository->getByPreviousTxid($previousTxid);

        $this->assertCount(2, $result);
    }

    public function testGetByPreviousTxidReturnsNullWhenNotFound(): void
    {
        $previousTxid = 'nonexistent-txid';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $this->repository->getByPreviousTxid($previousTxid);

        $this->assertNull($result);
    }

    public function testGetByPreviousTxidReturnsNullOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getByPreviousTxid('some-txid');

        $this->assertNull($result);
    }

    // =========================================================================
    // getLocalTransactionByPreviousTxid() Tests
    // =========================================================================

    public function testGetLocalTransactionByPreviousTxidReturnsTransaction(): void
    {
        $previousTxid = 'prev-tx';
        $pubkeyHash1 = 'hash1';
        $pubkeyHash2 = 'hash2';
        $expectedTransaction = [
            'txid' => 'local-tx',
            'previous_txid' => $previousTxid,
            'sender_public_key_hash' => $pubkeyHash1
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE previous_txid = :previous_txid'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(5))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedTransaction);

        $result = $this->repository->getLocalTransactionByPreviousTxid($previousTxid, $pubkeyHash1, $pubkeyHash2);

        $this->assertEquals($expectedTransaction, $result);
    }

    public function testGetLocalTransactionByPreviousTxidReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getLocalTransactionByPreviousTxid('prev', 'hash1', 'hash2');

        $this->assertNull($result);
    }

    // =========================================================================
    // updatePreviousTxidReferences() Tests
    // =========================================================================

    public function testUpdatePreviousTxidReferencesReturnsRowCount(): void
    {
        $oldTxid = 'old-txid';
        $newPreviousTxid = 'new-prev-txid';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE transactions'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(3);

        $result = $this->repository->updatePreviousTxidReferences($oldTxid, $newPreviousTxid);

        $this->assertEquals(3, $result);
    }

    public function testUpdatePreviousTxidReferencesHandlesNullNewValue(): void
    {
        $oldTxid = 'old-txid';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnCallback(function($key, $value, $type = null) {
                if ($key === ':new_previous_txid') {
                    $this->assertNull($value);
                    $this->assertEquals(PDO::PARAM_NULL, $type);
                }
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updatePreviousTxidReferences($oldTxid, null);

        $this->assertEquals(1, $result);
    }

    public function testUpdatePreviousTxidReferencesReturnsZeroOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->updatePreviousTxidReferences('old', 'new');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // updatePreviousTxid() Tests
    // =========================================================================

    public function testUpdatePreviousTxidReturnsTrueOnSuccess(): void
    {
        $txid = 'target-txid';
        $newPreviousTxid = 'new-prev-txid';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE transactions SET'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updatePreviousTxid($txid, $newPreviousTxid);

        $this->assertTrue($result);
    }

    public function testUpdatePreviousTxidReturnsTrueWhenNoRowsUpdated(): void
    {
        // Returns true as long as rowCount >= 0 (success even if no match)
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->updatePreviousTxid('txid', 'new-prev');

        $this->assertTrue($result);
    }

    public function testUpdatePreviousTxidReturnsFalseOnError(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        // After exception, execute returns false, which makes update return -1
        $result = $this->repository->updatePreviousTxid('txid', 'new-prev');

        // Method returns affectedRows >= 0, and -1 >= 0 is false
        $this->assertFalse($result);
    }

    // =========================================================================
    // updateChainConflictResolution() Tests
    // =========================================================================

    public function testUpdateChainConflictResolutionUpdatesAllFields(): void
    {
        $txid = 'conflict-txid';
        $newPreviousTxid = 'winner-txid';
        $newSignature = 'new-signature-123';
        $newNonce = 12345;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('UPDATE transactions SET'))
            ->willReturn($this->stmt);

        // Should bind: previous_txid, sender_signature, signature_nonce, timestamp, and where_value
        $this->stmt->expects($this->exactly(5))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateChainConflictResolution(
            $txid,
            $newPreviousTxid,
            $newSignature,
            $newNonce
        );

        $this->assertTrue($result);
    }

    public function testUpdateChainConflictResolutionWithoutTimestamp(): void
    {
        $txid = 'conflict-txid';
        $newPreviousTxid = 'winner-txid';
        $newSignature = 'new-signature';
        $newNonce = 999;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        // Without timestamp update: previous_txid, sender_signature, signature_nonce, where_value
        $this->stmt->expects($this->exactly(4))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateChainConflictResolution(
            $txid,
            $newPreviousTxid,
            $newSignature,
            $newNonce,
            false // Don't update timestamp
        );

        $this->assertTrue($result);
    }

    public function testUpdateChainConflictResolutionHandlesNullPreviousTxid(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(5))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateChainConflictResolution(
            'txid',
            null, // null previous_txid
            'sig',
            100
        );

        $this->assertTrue($result);
    }

    public function testUpdateChainConflictResolutionReturnsFalseOnError(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->updateChainConflictResolution(
            'txid',
            'prev',
            'sig',
            100
        );

        $this->assertFalse($result);
    }
}
