<?php
/**
 * Unit Tests for CleanupService
 *
 * Tests cleanup service functionality including:
 * - Processing expired P2P messages
 * - Expiring messages with completion check
 * - Checking P2P status with sender
 * - Syncing and completing P2P transactions
 * - Cancelling transactions
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\CleanupService;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Contracts\Rp2pServiceInterface;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Schemas\Payloads\MessagePayload;
use PDOException;
use Exception;

#[CoversClass(CleanupService::class)]
class CleanupServiceTest extends TestCase
{
    private MockObject|P2pRepository $p2pRepository;
    private MockObject|Rp2pRepository $rp2pRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|TimeUtilityService $timeUtility;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|CurrencyUtilityService $currencyUtility;
    private MockObject|ValidationUtilityService $validationUtility;
    private MockObject|UserContext $userContext;
    private MockObject|MessageDeliveryServiceInterface $messageDeliveryService;
    private CleanupService $service;

    /**
     * Sample test data constants
     */
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_SENDER_ADDRESS = 'http://sender.example.com';
    private const TEST_TXID = 'txid123def456789012345678901234567890123456789012345678901234txid';
    private const TEST_MICROTIME = 1234567890123456;
    private const TEST_PUBLIC_KEY = 'test-public-key-123456789012345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->p2pRepository = $this->createMock(P2pRepository::class);
        $this->rp2pRepository = $this->createMock(Rp2pRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);

        // Create mocks of concrete utility service classes to satisfy BasePayload type hints
        $this->timeUtility = $this->getMockBuilder(TimeUtilityService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->transportUtility = $this->getMockBuilder(TransportUtilityService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->currencyUtility = $this->getMockBuilder(CurrencyUtilityService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->validationUtility = $this->getMockBuilder(ValidationUtilityService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->userContext = $this->createMock(UserContext::class);

        // Configure utility container to return mocked concrete services
        $this->utilityContainer->method('getTimeUtility')
            ->willReturn($this->timeUtility);
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);
        $this->utilityContainer->method('getCurrencyUtility')
            ->willReturn($this->currencyUtility);
        $this->utilityContainer->method('getValidationUtility')
            ->willReturn($this->validationUtility);

        // Default UserContext mock behavior
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $this->messageDeliveryService = $this->createMock(MessageDeliveryServiceInterface::class);

        // Default: retry queue returns no processed items
        $this->messageDeliveryService->method('processRetryQueue')
            ->willReturn(['processed' => 0, 'failed' => 0, 'moved_to_dlq' => 0]);

        $this->service = new CleanupService(
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->transactionRepository,
            $this->balanceRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService
        );
    }

    // =========================================================================
    // processCleanupMessages() Tests
    // =========================================================================

    /**
     * Test processCleanupMessages with no expired messages
     */
    public function testProcessCleanupMessagesWithNoExpiredMessages(): void
    {
        $this->timeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->expects($this->once())
            ->method('getExpiredP2p')
            ->with(self::TEST_MICROTIME)
            ->willReturn([]);

        $result = $this->service->processCleanupMessages();

        $this->assertEquals(0, $result);
    }

    /**
     * Test processCleanupMessages with single expired message
     */
    public function testProcessCleanupMessagesWithSingleExpiredMessage(): void
    {
        $expiredMessage = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $this->timeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->expects($this->once())
            ->method('getExpiredP2p')
            ->willReturn([$expiredMessage]);

        // No completed transaction found locally
        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([]);

        // Sender inquiry returns null (no completion)
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        $result = $this->service->processCleanupMessages();

        $this->assertEquals(1, $result);
    }

    /**
     * Test processCleanupMessages with multiple expired messages
     */
    public function testProcessCleanupMessagesWithMultipleExpiredMessages(): void
    {
        $expiredMessages = [
            ['hash' => 'hash1', 'sender_address' => 'http://sender1.example.com'],
            ['hash' => 'hash2', 'sender_address' => 'http://sender2.example.com'],
            ['hash' => 'hash3', 'sender_address' => 'http://sender3.example.com']
        ];

        $this->timeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->expects($this->once())
            ->method('getExpiredP2p')
            ->willReturn($expiredMessages);

        // No completed transactions found locally for any
        $this->transactionRepository->expects($this->exactly(3))
            ->method('getByMemo')
            ->willReturn([]);

        // Sender inquiries return null
        $this->transportUtility->expects($this->exactly(3))
            ->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire all P2Ps
        $this->p2pRepository->expects($this->exactly(3))
            ->method('updateStatus')
            ->with($this->anything(), Constants::STATUS_EXPIRED);

        $result = $this->service->processCleanupMessages();

        $this->assertEquals(3, $result);
    }

    /**
     * Test processCleanupMessages handles PDOException gracefully
     */
    public function testProcessCleanupMessagesHandlesPDOException(): void
    {
        $this->timeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->expects($this->once())
            ->method('getExpiredP2p')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->service->processCleanupMessages();

        $this->assertEquals(0, $result);
    }

    /**
     * Test processCleanupMessages calls processRetryQueue
     */
    public function testProcessCleanupMessagesCallsRetryQueue(): void
    {
        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->method('getExpiredP2p')
            ->willReturn([]);

        // Re-create service with specific retry queue expectation
        $messageDeliveryService = $this->createMock(MessageDeliveryServiceInterface::class);
        $messageDeliveryService->expects($this->once())
            ->method('processRetryQueue')
            ->with(10)
            ->willReturn(['processed' => 3, 'failed' => 0, 'moved_to_dlq' => 0]);

        $service = new CleanupService(
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->transactionRepository,
            $this->balanceRepository,
            $this->utilityContainer,
            $this->userContext,
            $messageDeliveryService
        );

        $result = $service->processCleanupMessages();

        $this->assertEquals(3, $result);
    }

    /**
     * Test processCleanupMessages combines expired and retry counts
     */
    public function testProcessCleanupMessagesCombinesExpiredAndRetryCounts(): void
    {
        $expiredMessages = [
            ['hash' => 'hash1', 'sender_address' => 'http://sender1.example.com'],
            ['hash' => 'hash2', 'sender_address' => 'http://sender2.example.com']
        ];

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->method('getExpiredP2p')
            ->willReturn($expiredMessages);

        $this->transactionRepository->method('getByMemo')
            ->willReturn([]);

        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Re-create service with retry returning 2 processed
        $messageDeliveryService = $this->createMock(MessageDeliveryServiceInterface::class);
        $messageDeliveryService->method('processRetryQueue')
            ->willReturn(['processed' => 2, 'failed' => 0, 'moved_to_dlq' => 0]);

        $service = new CleanupService(
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->transactionRepository,
            $this->balanceRepository,
            $this->utilityContainer,
            $this->userContext,
            $messageDeliveryService
        );

        $result = $service->processCleanupMessages();

        // 2 expired + 2 retried = 4
        $this->assertEquals(4, $result);
    }

    /**
     * Test processCleanupMessages handles retry queue exception gracefully
     */
    public function testProcessCleanupMessagesHandlesRetryQueueException(): void
    {
        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->method('getExpiredP2p')
            ->willReturn([]);

        // Re-create service with retry queue that throws
        $messageDeliveryService = $this->createMock(MessageDeliveryServiceInterface::class);
        $messageDeliveryService->method('processRetryQueue')
            ->willThrowException(new Exception('Retry queue error'));

        $service = new CleanupService(
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->transactionRepository,
            $this->balanceRepository,
            $this->utilityContainer,
            $this->userContext,
            $messageDeliveryService
        );

        $result = $service->processCleanupMessages();

        // Should return 0 - retry queue exception handled gracefully
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // expireMessage() Tests
    // =========================================================================

    /**
     * Test expireMessage with completed local transaction
     */
    public function testExpireMessageWithCompletedLocalTransaction(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $completedTransaction = [
            'txid' => self::TEST_TXID,
            'status' => Constants::STATUS_COMPLETED
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([$completedTransaction]);

        // Should update P2P status to completed
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED, true);

        // Transport should NOT be called since local transaction found
        $this->transportUtility->expects($this->never())
            ->method('send');

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage with pending local transaction
     */
    public function testExpireMessageWithPendingLocalTransactionChecksRemote(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $pendingTransaction = [
            'txid' => self::TEST_TXID,
            'status' => Constants::STATUS_PENDING
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([$pendingTransaction]);

        // Sender inquiry returns null (no completion)
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        // Should cancel the pending transaction
        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage when sender reports completed status
     */
    public function testExpireMessageWhenSenderReportsCompleted(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $pendingTransaction = [
            'txid' => self::TEST_TXID,
            'status' => Constants::STATUS_PENDING
        ];

        // First call: check local (no completed)
        // Second call: after sync
        $this->transactionRepository->expects($this->exactly(2))
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([$pendingTransaction]);

        // Sender inquiry returns completed
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode(['status' => Constants::STATUS_COMPLETED]));

        // Should update P2P to completed (from syncAndCompleteP2p)
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED, true);

        // Should complete the transaction
        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED);

        // Should update balance
        $this->balanceRepository->expects($this->once())
            ->method('updateBalanceGivenTransactions')
            ->with([$pendingTransaction]);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage with no local transaction
     */
    public function testExpireMessageWithNoLocalTransaction(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        // Sender inquiry returns null
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        // Should NOT call updateStatus on transactions since none exist
        $this->transactionRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage with empty local transactions array
     */
    public function testExpireMessageWithEmptyLocalTransactionsArray(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([]);

        // Sender inquiry returns null
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage when sender inquiry fails
     */
    public function testExpireMessageWhenSenderInquiryFails(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([]);

        // Sender inquiry throws exception
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willThrowException(new Exception('Connection failed'));

        // Should still expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage when sender returns invalid JSON
     */
    public function testExpireMessageWhenSenderReturnsInvalidJson(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->willReturn([]);

        // Sender returns invalid JSON
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn('invalid json response');

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage when sender returns response without status
     */
    public function testExpireMessageWhenSenderReturnsNoStatus(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->willReturn([]);

        // Sender returns valid JSON but no status field
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode(['message' => 'ok']));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage with multiple local transactions, one completed
     */
    public function testExpireMessageWithMultipleTransactionsOneCompleted(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $transactions = [
            ['txid' => 'txid1', 'status' => Constants::STATUS_PENDING],
            ['txid' => 'txid2', 'status' => Constants::STATUS_COMPLETED],
            ['txid' => 'txid3', 'status' => Constants::STATUS_CANCELLED]
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->willReturn($transactions);

        // Should update P2P to completed since one transaction is completed
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED, true);

        // Transport should NOT be called
        $this->transportUtility->expects($this->never())
            ->method('send');

        $this->service->expireMessage($message);
    }

    // =========================================================================
    // cancelTransaction() Tests
    // =========================================================================

    /**
     * Test cancelTransaction with valid transaction
     */
    public function testCancelTransactionWithValidTransaction(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'status' => Constants::STATUS_PENDING
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_TXID, Constants::STATUS_CANCELLED, true)
            ->willReturn(true);

        $result = $this->service->cancelTransaction(self::TEST_TXID);

        $this->assertTrue($result);
    }

    /**
     * Test cancelTransaction with non-existent transaction
     */
    public function testCancelTransactionWithNonExistentTransaction(): void
    {
        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn(null);

        // updateStatus should NOT be called
        $this->transactionRepository->expects($this->never())
            ->method('updateStatus');

        $result = $this->service->cancelTransaction(self::TEST_TXID);

        $this->assertFalse($result);
    }

    /**
     * Test cancelTransaction with empty transaction result
     */
    public function testCancelTransactionWithEmptyTransactionResult(): void
    {
        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn([]);

        // updateStatus should NOT be called
        $this->transactionRepository->expects($this->never())
            ->method('updateStatus');

        $result = $this->service->cancelTransaction(self::TEST_TXID);

        $this->assertFalse($result);
    }

    /**
     * Test cancelTransaction when update fails
     */
    public function testCancelTransactionWhenUpdateFails(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'status' => Constants::STATUS_PENDING
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->willReturn(false);

        $result = $this->service->cancelTransaction(self::TEST_TXID);

        $this->assertFalse($result);
    }

    /**
     * Test cancelTransaction with already cancelled transaction
     */
    public function testCancelTransactionWithAlreadyCancelledTransaction(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'status' => Constants::STATUS_CANCELLED
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_TXID, Constants::STATUS_CANCELLED, true)
            ->willReturn(true);

        $result = $this->service->cancelTransaction(self::TEST_TXID);

        $this->assertTrue($result);
    }

    /**
     * Test cancelTransaction with completed transaction
     */
    public function testCancelTransactionWithCompletedTransaction(): void
    {
        $transaction = [
            'txid' => self::TEST_TXID,
            'status' => Constants::STATUS_COMPLETED
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByTxid')
            ->willReturn($transaction);

        // The service will still attempt to cancel - business logic should
        // prevent this at a higher level if needed
        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_TXID, Constants::STATUS_CANCELLED, true)
            ->willReturn(true);

        $result = $this->service->cancelTransaction(self::TEST_TXID);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Edge Cases and Status Constants Tests
    // =========================================================================

    /**
     * Test that status constants are correctly defined
     */
    public function testStatusConstantsAreCorrectlyDefined(): void
    {
        $this->assertEquals('completed', Constants::STATUS_COMPLETED);
        $this->assertEquals('expired', Constants::STATUS_EXPIRED);
        $this->assertEquals('cancelled', Constants::STATUS_CANCELLED);
        $this->assertEquals('pending', Constants::STATUS_PENDING);
    }

    /**
     * Test expireMessage handles multiple completed transactions
     */
    public function testExpireMessageWithMultipleCompletedTransactions(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $transactions = [
            ['txid' => 'txid1', 'status' => Constants::STATUS_COMPLETED],
            ['txid' => 'txid2', 'status' => Constants::STATUS_COMPLETED]
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getByMemo')
            ->willReturn($transactions);

        // Should update P2P to completed on first completed transaction found
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED, true);

        $this->service->expireMessage($message);
    }

    /**
     * Test processCleanupMessages correctly passes microtime to repository
     */
    public function testProcessCleanupMessagesPassesMicrotimeToRepository(): void
    {
        $specificMicrotime = 9999999999999999;

        $this->timeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn($specificMicrotime);

        $this->p2pRepository->expects($this->once())
            ->method('getExpiredP2p')
            ->with($specificMicrotime)
            ->willReturn([]);

        $this->service->processCleanupMessages();
    }

    /**
     * Test expireMessage sender inquiry builds correct payload
     */
    public function testExpireMessageSenderInquiryIsAttempted(): void
    {
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS
        ];

        $this->transactionRepository->method('getByMemo')
            ->willReturn([]);

        // Verify that transport is called with sender address
        $this->transportUtility->expects($this->once())
            ->method('send')
            ->with(
                self::TEST_SENDER_ADDRESS,
                $this->anything()
            )
            ->willReturn(json_encode(['status' => null]));

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        $this->service->expireMessage($message);
    }

    // =========================================================================
    // Best-Fee expireMessage Tests (Step 1.5)
    // =========================================================================

    /**
     * Test expireMessage selects best candidate for relay node in best-fee mode
     *
     * Relay nodes (no destination_address) should select the best candidate
     * immediately at expiration and mark the P2P as 'found'.
     */
    public function testExpireMessageSelectsCandidateForRelayNode(): void
    {
        $rp2pService = $this->createMock(Rp2pServiceInterface::class);
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);

        $this->service->setRp2pService($rp2pService);
        $this->service->setRp2pCandidateRepository($rp2pCandidateRepo);

        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            'fast' => 0,
            // No destination_address = relay node
        ];

        // No completed local transaction
        $this->transactionRepository->method('getByMemo')
            ->willReturn([]);

        // Relay has candidates
        $rp2pCandidateRepo->expects($this->once())
            ->method('getCandidateCount')
            ->with(self::TEST_HASH)
            ->willReturn(2);

        // Should select best route
        $rp2pService->expects($this->once())
            ->method('selectAndForwardBestRp2p')
            ->with(self::TEST_HASH);

        // Should mark as 'found'
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, 'found');

        // Should NOT query sender (early return)
        $this->transportUtility->expects($this->never())
            ->method('send');

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage skips candidate selection for originator in best-fee mode
     *
     * Originators (have destination_address) should NOT select candidates at
     * expiration. They should expire normally so handleRp2pCandidate can collect
     * all responses from contacts.
     */
    public function testExpireMessageSkipsCandidateSelectionForOriginator(): void
    {
        $rp2pService = $this->createMock(Rp2pServiceInterface::class);
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);

        $this->service->setRp2pService($rp2pService);
        $this->service->setRp2pCandidateRepository($rp2pCandidateRepo);

        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            'fast' => 0,
            'destination_address' => 'http://destination.test', // Originator
        ];

        // No completed local transaction
        $this->transactionRepository->method('getByMemo')
            ->willReturn([]);

        // Should NOT check candidates (originator skips step 1.5)
        $rp2pCandidateRepo->expects($this->never())
            ->method('getCandidateCount');

        // Should NOT select route
        $rp2pService->expects($this->never())
            ->method('selectAndForwardBestRp2p');

        // Sender inquiry returns null
        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire normally
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        $this->service->expireMessage($message);
    }

    // =========================================================================
    // Originator Fallback Tests
    // =========================================================================

    /**
     * Test processCleanupMessages triggers originator fallback selection
     *
     * When rp2pService and rp2pCandidateRepository are set, and there are
     * expired originator P2Ps with candidates past the grace period,
     * the cleanup should trigger best-fee selection and mark as 'found'.
     */
    public function testProcessCleanupMessagesTriggersOriginatorFallback(): void
    {
        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->method('getExpiredP2p')
            ->willReturn([]);

        $rp2pService = $this->createMock(Rp2pServiceInterface::class);
        $rp2pCandidateRepo = $this->createMock(Rp2pCandidateRepository::class);

        $this->service->setRp2pService($rp2pService);
        $this->service->setRp2pCandidateRepository($rp2pCandidateRepo);

        $staleP2p = [
            'hash' => self::TEST_HASH,
            'destination_address' => 'http://destination.test',
            'fast' => 0,
            'status' => Constants::STATUS_EXPIRED,
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getExpiredOriginatorP2psWithCandidates')
            ->with(self::TEST_MICROTIME)
            ->willReturn([$staleP2p]);

        $rp2pService->expects($this->once())
            ->method('selectAndForwardBestRp2p')
            ->with(self::TEST_HASH);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, 'found');

        $result = $this->service->processCleanupMessages();

        $this->assertEquals(1, $result);
    }

    /**
     * Test processCleanupMessages skips originator fallback when no rp2pService
     *
     * When rp2pService is not set, the originator fallback should be skipped.
     */
    public function testProcessCleanupMessagesSkipsFallbackWithoutRp2pService(): void
    {
        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        $this->p2pRepository->method('getExpiredP2p')
            ->willReturn([]);

        // Do NOT set rp2pService or rp2pCandidateRepository

        // getExpiredOriginatorP2psWithCandidates should NOT be called
        $this->p2pRepository->expects($this->never())
            ->method('getExpiredOriginatorP2psWithCandidates');

        $result = $this->service->processCleanupMessages();

        $this->assertEquals(0, $result);
    }
}
