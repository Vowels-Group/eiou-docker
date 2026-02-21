<?php
/**
 * Unit Tests for HeldTransactionService
 *
 * Tests the held transaction service functionality including:
 * - Holding transactions for sync
 * - Checking if transactions should be held
 * - Processing held transactions after sync
 * - Updating previous_txid
 * - Resuming transactions
 * - Event handling
 * - Statistics
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\HeldTransactionService;
use Eiou\Database\HeldTransactionRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;

#[CoversClass(HeldTransactionService::class)]
class HeldTransactionServiceTest extends TestCase
{
    private MockObject|HeldTransactionRepository $heldRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|TransactionChainRepository $transactionChainRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|UserContext $userContext;
    private HeldTransactionService $service;

    /**
     * Sample public keys for testing
     */
    private const TEST_USER_PUBKEY = 'test-user-public-key-123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890';
    private const TEST_CONTACT_PUBKEY = 'test-contact-public-key-987654321098765432109876543210987654321098765432109876543210987654321098765432109876543210987654321098765432109876543210';
    private const TEST_TXID = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_PREVIOUS_TXID = 'prev123def456789012345678901234567890123456789012345678901234prev';
    private const TEST_EXPECTED_TXID = 'expected123456789012345678901234567890123456789012345678901234exp';

    protected function setUp(): void
    {
        parent::setUp();

        $this->heldRepository = $this->createMock(HeldTransactionRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->transactionChainRepository = $this->createMock(TransactionChainRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->userContext = $this->createMock(UserContext::class);

        // Default UserContext mock behavior
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // Create service instance - this will trigger EventDispatcher subscription
        // We need to suppress the event subscription for isolated testing
        $this->service = new HeldTransactionService(
            $this->heldRepository,
            $this->transactionRepository,
            $this->transactionChainRepository,
            $this->utilityContainer,
            $this->userContext
        );
    }

    /**
     * Test holdTransactionForSync with valid transaction
     */
    public function testHoldTransactionForSyncWithValidTransaction(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_PREVIOUS_TXID,
            'receiver_public_key' => self::TEST_CONTACT_PUBKEY,
            'memo' => 'standard'
        ];

        $this->heldRepository->expects($this->once())
            ->method('isTransactionHeld')
            ->with(self::TEST_TXID)
            ->willReturn(false);

        $this->heldRepository->expects($this->once())
            ->method('holdTransaction')
            ->willReturn(1);

        $this->heldRepository->expects($this->once())
            ->method('isSyncInProgress')
            ->willReturn(false);

        $this->heldRepository->expects($this->once())
            ->method('markSyncStarted');

        $result = $this->service->holdTransactionForSync(
            $transaction,
            self::TEST_CONTACT_PUBKEY,
            self::TEST_EXPECTED_TXID
        );

        $this->assertTrue($result['held']);
        $this->assertNull($result['error']);
    }

    /**
     * Test holdTransactionForSync with missing txid
     */
    public function testHoldTransactionForSyncWithMissingTxid(): void
    {
        $transaction = [
            'previous_txid' => self::TEST_PREVIOUS_TXID,
            'receiver_public_key' => self::TEST_CONTACT_PUBKEY
            // Missing 'txid'
        ];

        $result = $this->service->holdTransactionForSync(
            $transaction,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['held']);
        $this->assertEquals('Missing transaction txid', $result['error']);
    }

    /**
     * Test holdTransactionForSync when transaction is already held
     */
    public function testHoldTransactionForSyncWhenAlreadyHeld(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_PREVIOUS_TXID
        ];

        $this->heldRepository->expects($this->once())
            ->method('isTransactionHeld')
            ->with(self::TEST_TXID)
            ->willReturn(true);

        // holdTransaction should NOT be called since already held
        $this->heldRepository->expects($this->never())
            ->method('holdTransaction');

        $result = $this->service->holdTransactionForSync(
            $transaction,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['held']);
        $this->assertNull($result['error']);
    }

    /**
     * Test holdTransactionForSync when sync is already in progress
     */
    public function testHoldTransactionForSyncWhenSyncAlreadyInProgress(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_PREVIOUS_TXID,
            'memo' => 'standard'
        ];

        $this->heldRepository->method('isTransactionHeld')
            ->willReturn(false);

        $this->heldRepository->method('holdTransaction')
            ->willReturn(1);

        $this->heldRepository->expects($this->once())
            ->method('isSyncInProgress')
            ->willReturn(true);

        // markSyncStarted should NOT be called since sync is already in progress
        $this->heldRepository->expects($this->never())
            ->method('markSyncStarted');

        $result = $this->service->holdTransactionForSync(
            $transaction,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['held']);
        $this->assertFalse($result['sync_initiated']);
        $this->assertNull($result['error']);
    }

    /**
     * Test holdTransactionForSync when insert fails
     */
    public function testHoldTransactionForSyncWhenInsertFails(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_PREVIOUS_TXID
        ];

        $this->heldRepository->method('isTransactionHeld')
            ->willReturn(false);

        $this->heldRepository->expects($this->once())
            ->method('holdTransaction')
            ->willReturn(false);

        $result = $this->service->holdTransactionForSync(
            $transaction,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['held']);
        $this->assertEquals('Failed to insert held transaction', $result['error']);
    }

    /**
     * Test shouldHoldTransactions returns true when sync in progress
     */
    public function testShouldHoldTransactionsReturnsTrueWhenSyncInProgress(): void
    {
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->heldRepository->expects($this->once())
            ->method('isSyncInProgress')
            ->with($contactPubkeyHash)
            ->willReturn(true);

        $result = $this->service->shouldHoldTransactions(self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result);
    }

    /**
     * Test shouldHoldTransactions returns false when no sync in progress
     */
    public function testShouldHoldTransactionsReturnsFalseWhenNoSync(): void
    {
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->heldRepository->expects($this->once())
            ->method('isSyncInProgress')
            ->with($contactPubkeyHash)
            ->willReturn(false);

        $result = $this->service->shouldHoldTransactions(self::TEST_CONTACT_PUBKEY);

        $this->assertFalse($result);
    }

    /**
     * Test resumeTransaction with valid transaction
     */
    public function testResumeTransactionWithValidTransaction(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_EXPECTED_TXID,
            'status' => Constants::STATUS_PENDING
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_TXID, Constants::STATUS_PENDING, true)
            ->willReturn(true);

        $result = $this->service->resumeTransaction(self::TEST_TXID);

        $this->assertTrue($result['success']);
        $this->assertEquals(self::TEST_EXPECTED_TXID, $result['new_previous_txid']);
        $this->assertNull($result['error']);
    }

    /**
     * Test resumeTransaction with missing transaction
     */
    public function testResumeTransactionWithMissingTransaction(): void
    {
        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn(null);

        $result = $this->service->resumeTransaction(self::TEST_TXID);

        $this->assertFalse($result['success']);
        $this->assertEquals('Transaction not found', $result['error']);
    }

    /**
     * Test resumeTransaction when status update fails
     */
    public function testResumeTransactionWhenUpdateFails(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_EXPECTED_TXID
        ];

        $this->transactionRepository->method('getByTxid')
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->willReturn(false);

        $result = $this->service->resumeTransaction(self::TEST_TXID);

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to update transaction status', $result['error']);
    }

    /**
     * Test getStatistics with no held transactions
     */
    public function testGetStatisticsWithNoHeldTransactions(): void
    {
        $this->heldRepository->expects($this->once())
            ->method('getAll')
            ->willReturn([]);

        $stats = $this->service->getStatistics();

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['by_status']['not_started']);
        $this->assertEquals(0, $stats['by_status']['in_progress']);
        $this->assertEquals(0, $stats['by_status']['completed']);
        $this->assertEquals(0, $stats['by_status']['failed']);
        $this->assertNull($stats['oldest_held']);
        $this->assertNull($stats['newest_held']);
    }

    /**
     * Test getStatistics with held transactions of various statuses
     */
    public function testGetStatisticsWithHeldTransactions(): void
    {
        $heldTransactions = [
            [
                'txid' => 'txid1',
                'sync_status' => 'not_started',
                'hold_reason' => 'invalid_previous_txid',
                'held_at' => '2025-01-01 10:00:00'
            ],
            [
                'txid' => 'txid2',
                'sync_status' => 'in_progress',
                'hold_reason' => 'invalid_previous_txid',
                'held_at' => '2025-01-01 11:00:00'
            ],
            [
                'txid' => 'txid3',
                'sync_status' => 'completed',
                'hold_reason' => 'sync_in_progress',
                'held_at' => '2025-01-01 12:00:00'
            ],
            [
                'txid' => 'txid4',
                'sync_status' => 'failed',
                'hold_reason' => 'invalid_previous_txid',
                'held_at' => '2025-01-01 09:00:00'
            ]
        ];

        $this->heldRepository->expects($this->once())
            ->method('getAll')
            ->willReturn($heldTransactions);

        $stats = $this->service->getStatistics();

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(1, $stats['by_status']['not_started']);
        $this->assertEquals(1, $stats['by_status']['in_progress']);
        $this->assertEquals(1, $stats['by_status']['completed']);
        $this->assertEquals(1, $stats['by_status']['failed']);
        $this->assertEquals(3, $stats['by_reason']['invalid_previous_txid']);
        $this->assertEquals(1, $stats['by_reason']['sync_in_progress']);
        $this->assertEquals('2025-01-01 09:00:00', $stats['oldest_held']);
        $this->assertEquals('2025-01-01 12:00:00', $stats['newest_held']);
    }

    /**
     * Test onSyncCompleted handles success
     */
    public function testOnSyncCompletedHandlesSuccess(): void
    {
        $eventData = [
            'contact_pubkey' => self::TEST_CONTACT_PUBKEY,
            'success' => true,
            'synced_count' => 5
        ];

        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->heldRepository->expects($this->once())
            ->method('markSyncCompleted')
            ->with($contactPubkeyHash);

        // For chain integrity check
        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn(['valid' => true]);

        // No held transactions with completed status to process
        $this->heldRepository->expects($this->once())
            ->method('getHeldTransactionsForContact')
            ->with($contactPubkeyHash, Constants::STATUS_COMPLETED)
            ->willReturn([]);

        $this->service->onSyncCompleted($eventData);

        // Test passes if no exception is thrown
        $this->assertTrue(true);
    }

    /**
     * Test onSyncCompleted handles failure
     */
    public function testOnSyncCompletedHandlesFailure(): void
    {
        $eventData = [
            'contact_pubkey' => self::TEST_CONTACT_PUBKEY,
            'success' => false,
            'synced_count' => 0
        ];

        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->heldRepository->expects($this->once())
            ->method('markSyncFailed')
            ->with($contactPubkeyHash);

        // markSyncCompleted should NOT be called on failure
        $this->heldRepository->expects($this->never())
            ->method('markSyncCompleted');

        $this->service->onSyncCompleted($eventData);

        // Test passes if no exception is thrown
        $this->assertTrue(true);
    }

    /**
     * Test onSyncCompleted handles missing contact_pubkey
     */
    public function testOnSyncCompletedHandlesMissingContactPubkey(): void
    {
        $eventData = [
            'success' => true,
            'synced_count' => 5
            // Missing 'contact_pubkey'
        ];

        // Neither markSyncCompleted nor markSyncFailed should be called
        $this->heldRepository->expects($this->never())
            ->method('markSyncCompleted');

        $this->heldRepository->expects($this->never())
            ->method('markSyncFailed');

        $this->service->onSyncCompleted($eventData);

        // Test passes if no exception is thrown
        $this->assertTrue(true);
    }

    /**
     * Test onSyncComplete with successful sync
     */
    public function testOnSyncCompleteWithSuccessfulSync(): void
    {
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->heldRepository->expects($this->once())
            ->method('markSyncCompleted')
            ->with($contactPubkeyHash);

        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn(['valid' => true]);

        $this->heldRepository->expects($this->once())
            ->method('getHeldTransactionsForContact')
            ->willReturn([]);

        $this->service->onSyncComplete(self::TEST_CONTACT_PUBKEY, true, 10);

        $this->assertTrue(true);
    }

    /**
     * Test onSyncComplete with failed sync
     */
    public function testOnSyncCompleteWithFailedSync(): void
    {
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->heldRepository->expects($this->once())
            ->method('markSyncFailed')
            ->with($contactPubkeyHash);

        $this->heldRepository->expects($this->never())
            ->method('markSyncCompleted');

        $this->service->onSyncComplete(self::TEST_CONTACT_PUBKEY, false, 0);

        $this->assertTrue(true);
    }

    /**
     * Test updatePreviousTxid with expected txid from rejection
     */
    public function testUpdatePreviousTxidWithExpectedTxid(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_EXPECTED_TXID
        ];

        $this->transactionChainRepository->expects($this->once())
            ->method('updatePreviousTxid')
            ->with(self::TEST_TXID, self::TEST_EXPECTED_TXID)
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn($transaction);

        $result = $this->service->updatePreviousTxid(
            self::TEST_TXID,
            self::TEST_CONTACT_PUBKEY,
            self::TEST_EXPECTED_TXID
        );

        $this->assertTrue($result);
    }

    /**
     * Test updatePreviousTxid falls back to getPreviousTxid when no expected txid
     */
    public function testUpdatePreviousTxidFallsBackToPreviousTxidLookup(): void
    {
        $lookupPreviousTxid = 'lookup-txid-from-chain';
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => $lookupPreviousTxid
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getPreviousTxid')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY, self::TEST_TXID)
            ->willReturn($lookupPreviousTxid);

        $this->transactionChainRepository->expects($this->once())
            ->method('updatePreviousTxid')
            ->with(self::TEST_TXID, $lookupPreviousTxid)
            ->willReturn(true);

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->willReturn($transaction);

        $result = $this->service->updatePreviousTxid(
            self::TEST_TXID,
            self::TEST_CONTACT_PUBKEY,
            null  // No expected txid, should fall back to lookup
        );

        $this->assertTrue($result);
    }

    /**
     * Test updatePreviousTxid returns false when update fails
     */
    public function testUpdatePreviousTxidReturnsFalseWhenUpdateFails(): void
    {
        $this->transactionChainRepository->expects($this->once())
            ->method('updatePreviousTxid')
            ->willReturn(false);

        $result = $this->service->updatePreviousTxid(
            self::TEST_TXID,
            self::TEST_CONTACT_PUBKEY,
            self::TEST_EXPECTED_TXID
        );

        $this->assertFalse($result);
    }

    /**
     * Test processHeldTransactions with no transactions to process
     */
    public function testProcessHeldTransactionsWithNoTransactions(): void
    {
        $this->heldRepository->expects($this->once())
            ->method('getTransactionsToResume')
            ->with(10)
            ->willReturn([]);

        $result = $this->service->processHeldTransactions(10);

        $this->assertEquals(0, $result['processed_count']);
        $this->assertEquals(0, $result['resumed_count']);
        $this->assertEquals(0, $result['failed_count']);
    }

    /**
     * Test processHeldTransactions when transaction not found
     */
    public function testProcessHeldTransactionsWhenTransactionNotFound(): void
    {
        $heldTransactions = [
            [
                'txid' => self::TEST_TXID,
                'contact_pubkey_hash' => hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY),
                'expected_previous_txid' => self::TEST_EXPECTED_TXID
            ]
        ];

        $this->heldRepository->expects($this->once())
            ->method('getTransactionsToResume')
            ->willReturn($heldTransactions);

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn(null);

        $result = $this->service->processHeldTransactions();

        $this->assertEquals(1, $result['processed_count']);
        $this->assertEquals(0, $result['resumed_count']);
        $this->assertEquals(1, $result['failed_count']);
    }

    /**
     * Test processHeldTransactionsAfterSync with valid chain
     */
    public function testProcessHeldTransactionsAfterSyncWithValidChain(): void
    {
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn(['valid' => true]);

        $this->heldRepository->expects($this->once())
            ->method('getHeldTransactionsForContact')
            ->with($contactPubkeyHash, Constants::STATUS_COMPLETED)
            ->willReturn([]);

        $result = $this->service->processHeldTransactionsAfterSync(self::TEST_CONTACT_PUBKEY);

        $this->assertEquals(0, $result['resumed_count']);
        $this->assertEquals(0, $result['failed_count']);
    }

    /**
     * Test processHeldTransactionsAfterSync logs warning when chain integrity fails
     */
    public function testProcessHeldTransactionsAfterSyncLogsWarningOnInvalidChain(): void
    {
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->transactionChainRepository->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'gaps' => ['gap1'],
                'broken_txids' => ['broken1']
            ]);

        // Code returns early when chain integrity fails (before fetching held transactions)
        $this->heldRepository->expects($this->never())
            ->method('getHeldTransactionsForContact');

        $result = $this->service->processHeldTransactionsAfterSync(self::TEST_CONTACT_PUBKEY);

        $this->assertEquals(0, $result['resumed_count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertTrue($result['chain_integrity_failed']);
    }

    /**
     * Test holdTransactionForSync with p2p transaction type
     */
    public function testHoldTransactionForSyncWithP2pType(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'previous_txid' => self::TEST_PREVIOUS_TXID,
            'memo' => 'p2p_transfer' // Non-standard memo means p2p type
        ];

        $this->heldRepository->method('isTransactionHeld')
            ->willReturn(false);

        $this->heldRepository->expects($this->once())
            ->method('holdTransaction')
            ->with(
                $this->anything(),
                self::TEST_TXID,
                self::TEST_PREVIOUS_TXID,
                self::TEST_EXPECTED_TXID,
                'p2p' // Should detect as p2p type
            )
            ->willReturn(1);

        $this->heldRepository->method('isSyncInProgress')
            ->willReturn(true);

        $result = $this->service->holdTransactionForSync(
            $transaction,
            self::TEST_CONTACT_PUBKEY,
            self::TEST_EXPECTED_TXID
        );

        $this->assertTrue($result['held']);
    }

    /**
     * Test hash algorithm constant is sha256
     */
    public function testHashAlgorithmIsSha256(): void
    {
        $this->assertEquals('sha256', Constants::HASH_ALGORITHM);
    }

    /**
     * Test contact pubkey is properly hashed
     */
    public function testContactPubkeyIsProperlyHashed(): void
    {
        $expectedHash = hash(Constants::HASH_ALGORITHM, self::TEST_CONTACT_PUBKEY);

        $this->heldRepository->expects($this->once())
            ->method('isSyncInProgress')
            ->with($expectedHash)
            ->willReturn(false);

        $this->service->shouldHoldTransactions(self::TEST_CONTACT_PUBKEY);
    }

    /**
     * Test getStatistics handles exception gracefully
     */
    public function testGetStatisticsHandlesExceptionGracefully(): void
    {
        $this->heldRepository->expects($this->once())
            ->method('getAll')
            ->willThrowException(new \Exception('Database error'));

        $stats = $this->service->getStatistics();

        $this->assertArrayHasKey('error', $stats);
        $this->assertEquals('Database error', $stats['error']);
    }
}
