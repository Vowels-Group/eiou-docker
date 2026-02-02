<?php
/**
 * Unit Tests for TransactionRecoveryService
 *
 * Tests transaction recovery service functionality including stuck transaction
 * recovery, manual resolution, and recovery statistics.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\TransactionRecoveryService;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Core\Constants;
use Exception;

#[CoversClass(TransactionRecoveryService::class)]
class TransactionRecoveryServiceTest extends TestCase
{
    private TransactionRepository $transactionRepository;
    private TransactionRecoveryRepository $transactionRecoveryRepository;
    private TransactionRecoveryService $service;

    protected function setUp(): void
    {
        // Create mock objects for all dependencies
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->transactionRecoveryRepository = $this->createMock(TransactionRecoveryRepository::class);

        $this->service = new TransactionRecoveryService(
            $this->transactionRepository,
            $this->transactionRecoveryRepository
        );
    }

    // =========================================================================
    // recoverStuckTransactions() Tests
    // =========================================================================

    /**
     * Test recoverStuckTransactions with no stuck transactions
     */
    public function testRecoverStuckTransactionsWithNoStuckTransactionsReturnsEmptyResults(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->with(0)
            ->willReturn([]);

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(0, $result['recovered']);
        $this->assertEquals(0, $result['needs_review']);
        $this->assertEquals(0, $result['already_recovered']);
        $this->assertEquals(0, $result['errors']);
        $this->assertEmpty($result['transactions']);
    }

    /**
     * Test recoverStuckTransactions with transactions that can be recovered
     */
    public function testRecoverStuckTransactionsWithRecoverableTransactions(): void
    {
        $stuckTransactions = [
            [
                'txid' => 'txid-123',
                'sending_started_at' => '2025-01-01 10:00:00'
            ],
            [
                'txid' => 'txid-456',
                'sending_started_at' => '2025-01-01 10:05:00'
            ]
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn($stuckTransactions);

        $this->transactionRecoveryRepository->expects($this->exactly(2))
            ->method('recoverStuckTransaction')
            ->willReturnCallback(function ($txid) {
                return [
                    'recovered' => true,
                    'needs_review' => false,
                    'recovery_count' => 1
                ];
            });

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(2, $result['recovered']);
        $this->assertEquals(0, $result['needs_review']);
        $this->assertEquals(0, $result['errors']);
        $this->assertCount(2, $result['transactions']);
        $this->assertEquals('recovered', $result['transactions'][0]['action']);
        $this->assertEquals('recovered', $result['transactions'][1]['action']);
    }

    /**
     * Test recoverStuckTransactions with transactions needing review (exceeded max retries)
     */
    public function testRecoverStuckTransactionsWithTransactionsNeedingReview(): void
    {
        $stuckTransactions = [
            [
                'txid' => 'txid-maxed-out',
                'sending_started_at' => '2025-01-01 09:00:00'
            ]
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn($stuckTransactions);

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('recoverStuckTransaction')
            ->with('txid-maxed-out', 0)
            ->willReturn([
                'recovered' => false,
                'needs_review' => true,
                'recovery_count' => 4
            ]);

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(0, $result['recovered']);
        $this->assertEquals(1, $result['needs_review']);
        $this->assertEquals(0, $result['errors']);
        $this->assertCount(1, $result['transactions']);
        $this->assertEquals('needs_review', $result['transactions'][0]['action']);
        $this->assertEquals(4, $result['transactions'][0]['recovery_count']);
    }

    /**
     * Test recoverStuckTransactions with already recovered transaction
     */
    public function testRecoverStuckTransactionsWithAlreadyRecoveredTransaction(): void
    {
        $stuckTransactions = [
            [
                'txid' => 'txid-already-done',
                'sending_started_at' => '2025-01-01 09:30:00'
            ]
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn($stuckTransactions);

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('recoverStuckTransaction')
            ->willReturn([
                'recovered' => false,
                'needs_review' => false,
                'recovery_count' => 2
            ]);

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(0, $result['recovered']);
        $this->assertEquals(0, $result['needs_review']);
        $this->assertEquals(1, $result['already_recovered']);
        $this->assertEquals(0, $result['errors']);
        $this->assertEquals('already_recovered', $result['transactions'][0]['action']);
    }

    /**
     * Test recoverStuckTransactions handles exceptions gracefully for individual transactions
     */
    public function testRecoverStuckTransactionsHandlesIndividualTransactionExceptions(): void
    {
        $stuckTransactions = [
            [
                'txid' => 'txid-error',
                'sending_started_at' => '2025-01-01 10:00:00'
            ],
            [
                'txid' => 'txid-ok',
                'sending_started_at' => '2025-01-01 10:05:00'
            ]
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn($stuckTransactions);

        $this->transactionRecoveryRepository->expects($this->exactly(2))
            ->method('recoverStuckTransaction')
            ->willReturnCallback(function ($txid) {
                if ($txid === 'txid-error') {
                    throw new Exception('Database connection lost');
                }
                return [
                    'recovered' => true,
                    'needs_review' => false,
                    'recovery_count' => 1
                ];
            });

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(1, $result['recovered']);
        $this->assertEquals(1, $result['errors']);
        $this->assertCount(2, $result['transactions']);
        $this->assertEquals('error', $result['transactions'][0]['action']);
        $this->assertEquals('Database connection lost', $result['transactions'][0]['error']);
        $this->assertEquals('recovered', $result['transactions'][1]['action']);
    }

    /**
     * Test recoverStuckTransactions handles top-level exceptions gracefully
     */
    public function testRecoverStuckTransactionsHandlesTopLevelException(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willThrowException(new Exception('Database unavailable'));

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(0, $result['recovered']);
        $this->assertEquals(0, $result['needs_review']);
        $this->assertEquals(1, $result['errors']);
        $this->assertEmpty($result['transactions']);
    }

    /**
     * Test recoverStuckTransactions with custom timeout and maxRetries
     */
    public function testRecoverStuckTransactionsWithCustomTimeoutAndMaxRetries(): void
    {
        $customTimeout = 120;
        $customMaxRetries = 5;

        $stuckTransactions = [
            [
                'txid' => 'txid-custom',
                'sending_started_at' => '2025-01-01 10:00:00'
            ]
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->with($customTimeout)
            ->willReturn($stuckTransactions);

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('recoverStuckTransaction')
            ->with('txid-custom', $customMaxRetries)
            ->willReturn([
                'recovered' => true,
                'needs_review' => false,
                'recovery_count' => 1
            ]);

        $result = $this->service->recoverStuckTransactions($customTimeout, $customMaxRetries);

        $this->assertEquals(1, $result['recovered']);
    }

    /**
     * Test recoverStuckTransactions handles missing sending_started_at
     */
    public function testRecoverStuckTransactionsWithMissingSendingStartedAt(): void
    {
        $stuckTransactions = [
            [
                'txid' => 'txid-no-timestamp'
                // sending_started_at is missing
            ]
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn($stuckTransactions);

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('recoverStuckTransaction')
            ->willReturn([
                'recovered' => true,
                'needs_review' => false,
                'recovery_count' => 1
            ]);

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(1, $result['recovered']);
        $this->assertEquals('unknown', $result['transactions'][0]['sending_started_at']);
    }

    // =========================================================================
    // getTransactionsNeedingReview() Tests
    // =========================================================================

    /**
     * Test getTransactionsNeedingReview returns transactions from repository
     */
    public function testGetTransactionsNeedingReviewReturnsTransactions(): void
    {
        $expectedTransactions = [
            ['txid' => 'review-1', 'recovery_count' => 4],
            ['txid' => 'review-2', 'recovery_count' => 5]
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getTransactionsNeedingReview')
            ->willReturn($expectedTransactions);

        $result = $this->service->getTransactionsNeedingReview();

        $this->assertCount(2, $result);
        $this->assertEquals('review-1', $result[0]['txid']);
        $this->assertEquals('review-2', $result[1]['txid']);
    }

    /**
     * Test getTransactionsNeedingReview returns empty array when none exist
     */
    public function testGetTransactionsNeedingReviewReturnsEmptyArrayWhenNone(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getTransactionsNeedingReview')
            ->willReturn([]);

        $result = $this->service->getTransactionsNeedingReview();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getTransactionsNeedingReview handles exception gracefully
     */
    public function testGetTransactionsNeedingReviewHandlesException(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getTransactionsNeedingReview')
            ->willThrowException(new Exception('Query failed'));

        $result = $this->service->getTransactionsNeedingReview();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // resolveTransaction() Tests
    // =========================================================================

    /**
     * Test resolveTransaction with valid 'retry' action
     */
    public function testResolveTransactionWithRetryAction(): void
    {
        $txid = 'txid-to-retry';
        $transaction = ['txid' => $txid, 'status' => 'failed'];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with($txid)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with($txid, Constants::STATUS_PENDING, true)
            ->willReturn(true);

        $result = $this->service->resolveTransaction($txid, 'retry', 'Manual retry requested');

        $this->assertTrue($result['success']);
        $this->assertEquals('Transaction reset to pending for retry', $result['message']);
    }

    /**
     * Test resolveTransaction with valid 'cancel' action
     */
    public function testResolveTransactionWithCancelAction(): void
    {
        $txid = 'txid-to-cancel';
        $transaction = ['txid' => $txid, 'status' => 'failed'];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with($txid)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with($txid, Constants::STATUS_CANCELLED, true)
            ->willReturn(true);

        $result = $this->service->resolveTransaction($txid, 'cancel', 'User requested cancellation');

        $this->assertTrue($result['success']);
        $this->assertEquals('Transaction cancelled', $result['message']);
    }

    /**
     * Test resolveTransaction with valid 'complete' action
     */
    public function testResolveTransactionWithCompleteAction(): void
    {
        $txid = 'txid-to-complete';
        $transaction = ['txid' => $txid, 'status' => 'failed'];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with($txid)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with($txid, Constants::STATUS_COMPLETED, true)
            ->willReturn(true);

        $result = $this->service->resolveTransaction($txid, 'complete', 'Verified externally');

        $this->assertTrue($result['success']);
        $this->assertEquals('Transaction marked as completed', $result['message']);
    }

    /**
     * Test resolveTransaction with invalid action
     */
    public function testResolveTransactionWithInvalidAction(): void
    {
        $txid = 'txid-invalid-action';

        // Should not call getByTxid because validation fails first
        $this->transactionRepository->expects($this->never())
            ->method('getByTxid');

        $result = $this->service->resolveTransaction($txid, 'invalid_action');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid action. Must be one of: retry, cancel, complete', $result['message']);
    }

    /**
     * Test resolveTransaction with non-existent transaction
     */
    public function testResolveTransactionWithNonExistentTransaction(): void
    {
        $txid = 'txid-does-not-exist';

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with($txid)
            ->willReturn(null);

        $result = $this->service->resolveTransaction($txid, 'retry');

        $this->assertFalse($result['success']);
        $this->assertEquals('Transaction not found', $result['message']);
    }

    /**
     * Test resolveTransaction with empty transaction result
     */
    public function testResolveTransactionWithEmptyTransactionResult(): void
    {
        $txid = 'txid-empty-result';

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with($txid)
            ->willReturn([]);

        $result = $this->service->resolveTransaction($txid, 'retry');

        $this->assertFalse($result['success']);
        $this->assertEquals('Transaction not found', $result['message']);
    }

    /**
     * Test resolveTransaction handles exceptions
     */
    public function testResolveTransactionHandlesException(): void
    {
        $txid = 'txid-exception';
        $transaction = ['txid' => $txid, 'status' => 'failed'];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->willThrowException(new Exception('Database write failed'));

        $result = $this->service->resolveTransaction($txid, 'retry');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Error resolving transaction', $result['message']);
        $this->assertStringContainsString('Database write failed', $result['message']);
    }

    /**
     * Test resolveTransaction without reason (optional parameter)
     */
    public function testResolveTransactionWithoutReason(): void
    {
        $txid = 'txid-no-reason';
        $transaction = ['txid' => $txid, 'status' => 'failed'];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->willReturn(true);

        $result = $this->service->resolveTransaction($txid, 'cancel');

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // isRecoveryNeeded() Tests
    // =========================================================================

    /**
     * Test isRecoveryNeeded returns true when stuck transactions exist
     */
    public function testIsRecoveryNeededReturnsTrueWhenStuckTransactionsExist(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->with(0)
            ->willReturn([
                ['txid' => 'stuck-1'],
                ['txid' => 'stuck-2']
            ]);

        $result = $this->service->isRecoveryNeeded();

        $this->assertTrue($result);
    }

    /**
     * Test isRecoveryNeeded returns false when no stuck transactions
     */
    public function testIsRecoveryNeededReturnsFalseWhenNoStuckTransactions(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->with(0)
            ->willReturn([]);

        $result = $this->service->isRecoveryNeeded();

        $this->assertFalse($result);
    }

    /**
     * Test isRecoveryNeeded returns true on exception (safe default)
     */
    public function testIsRecoveryNeededReturnsTrueOnException(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willThrowException(new Exception('Database error'));

        $result = $this->service->isRecoveryNeeded();

        // Safe default: assume recovery might be needed if check fails
        $this->assertTrue($result);
    }

    /**
     * Test isRecoveryNeeded with single stuck transaction
     */
    public function testIsRecoveryNeededWithSingleStuckTransaction(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn([['txid' => 'single-stuck']]);

        $result = $this->service->isRecoveryNeeded();

        $this->assertTrue($result);
    }

    // =========================================================================
    // getRecoveryStatistics() Tests
    // =========================================================================

    /**
     * Test getRecoveryStatistics returns correct counts
     */
    public function testGetRecoveryStatisticsReturnsCorrectCounts(): void
    {
        $stuckSending = [
            ['txid' => 'stuck-1'],
            ['txid' => 'stuck-2'],
            ['txid' => 'stuck-3']
        ];

        $needsReview = [
            ['txid' => 'review-1'],
            ['txid' => 'review-2']
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn($stuckSending);

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getTransactionsNeedingReview')
            ->willReturn($needsReview);

        $result = $this->service->getRecoveryStatistics();

        $this->assertEquals(3, $result['stuck_sending']);
        $this->assertEquals(2, $result['needs_review']);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result['timestamp']);
    }

    /**
     * Test getRecoveryStatistics with zero counts
     */
    public function testGetRecoveryStatisticsWithZeroCounts(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn([]);

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getTransactionsNeedingReview')
            ->willReturn([]);

        $result = $this->service->getRecoveryStatistics();

        $this->assertEquals(0, $result['stuck_sending']);
        $this->assertEquals(0, $result['needs_review']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test getRecoveryStatistics handles exceptions
     */
    public function testGetRecoveryStatisticsHandlesException(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willThrowException(new Exception('Statistics query failed'));

        $result = $this->service->getRecoveryStatistics();

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Statistics query failed', $result['error']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test getRecoveryStatistics handles exception from getTransactionsNeedingReview
     */
    public function testGetRecoveryStatisticsHandlesNeedsReviewException(): void
    {
        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn([['txid' => 'stuck-1']]);

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getTransactionsNeedingReview')
            ->willThrowException(new Exception('Review query failed'));

        $result = $this->service->getRecoveryStatistics();

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Review query failed', $result['error']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    // =========================================================================
    // Mixed scenario Tests
    // =========================================================================

    /**
     * Test recoverStuckTransactions with mixed outcomes
     */
    public function testRecoverStuckTransactionsWithMixedOutcomes(): void
    {
        $stuckTransactions = [
            ['txid' => 'txid-recover', 'sending_started_at' => '2025-01-01 10:00:00'],
            ['txid' => 'txid-review', 'sending_started_at' => '2025-01-01 10:01:00'],
            ['txid' => 'txid-already', 'sending_started_at' => '2025-01-01 10:02:00'],
            ['txid' => 'txid-error', 'sending_started_at' => '2025-01-01 10:03:00']
        ];

        $this->transactionRecoveryRepository->expects($this->once())
            ->method('getStuckSendingTransactions')
            ->willReturn($stuckTransactions);

        $callCount = 0;
        $this->transactionRecoveryRepository->expects($this->exactly(4))
            ->method('recoverStuckTransaction')
            ->willReturnCallback(function ($txid) use (&$callCount) {
                $callCount++;
                switch ($txid) {
                    case 'txid-recover':
                        return ['recovered' => true, 'needs_review' => false, 'recovery_count' => 1];
                    case 'txid-review':
                        return ['recovered' => false, 'needs_review' => true, 'recovery_count' => 4];
                    case 'txid-already':
                        return ['recovered' => false, 'needs_review' => false, 'recovery_count' => 2];
                    case 'txid-error':
                        throw new Exception('Recovery failed for this transaction');
                }
            });

        $result = $this->service->recoverStuckTransactions();

        $this->assertEquals(1, $result['recovered']);
        $this->assertEquals(1, $result['needs_review']);
        $this->assertEquals(1, $result['already_recovered']);
        $this->assertEquals(1, $result['errors']);
        $this->assertCount(4, $result['transactions']);
    }
}
