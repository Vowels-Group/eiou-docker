<?php
/**
 * Unit Tests for CleanupService Cascade Cancel Notification
 *
 * Tests cascade cancel notification handling in CleanupService including:
 * - expireMessage sends cancel notification for relay nodes (no destination_address)
 * - expireMessage does NOT send cancel notification for originators (has destination_address)
 * - expireMessage handles null p2pService gracefully
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
use Eiou\Contracts\P2pServiceInterface;
use Exception;

#[CoversClass(CleanupService::class)]
class CleanupServiceCascadeCancelTest extends TestCase
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

    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_SENDER_ADDRESS = 'http://sender.example.com';
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
    // expireMessage cascade cancel for relay node Tests
    // =========================================================================

    /**
     * Test expireMessage sends cancel notification for relay node (no destination_address)
     *
     * When a relay node's P2P expires with no completion evidence, it should
     * send a cancel notification upstream via p2pService->sendCancelNotificationForHash()
     * so the upstream node can count this as a responded contact.
     */
    public function testExpireMessageSendsCancelNotificationForRelayNode(): void
    {
        $p2pService = $this->createMock(P2pServiceInterface::class);
        $this->service->setP2pService($p2pService);

        // Relay node message: NO destination_address
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            // No 'destination_address' key = relay node
        ];

        // No completed local transaction
        $this->transactionRepository->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([]);

        // Sender inquiry returns null (no completion)
        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        // KEY ASSERTION: Should send cancel notification upstream
        $p2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with(self::TEST_HASH);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage does NOT send cancel notification for originator (has destination_address)
     *
     * Originators have no upstream sender to notify, so cancel notification
     * should NOT be sent when destination_address is present.
     */
    public function testExpireMessageDoesNotSendCancelNotificationForOriginator(): void
    {
        $p2pService = $this->createMock(P2pServiceInterface::class);
        $this->service->setP2pService($p2pService);

        // Originator message: HAS destination_address
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            'destination_address' => 'http://destination.test', // Originator
        ];

        // No completed local transaction
        $this->transactionRepository->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([]);

        // Sender inquiry returns null (no completion)
        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        // KEY ASSERTION: Should NOT send cancel notification (originator has no upstream)
        $p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage does not crash when p2pService is not set (null check)
     *
     * When p2pService is not injected via setP2pService(), the expiration
     * should proceed normally without attempting cancel notification.
     */
    public function testExpireMessageHandlesNullP2pServiceGracefully(): void
    {
        // Do NOT call setP2pService — p2pService is null

        // Relay node message (would normally trigger cancel notification)
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            // No destination_address = relay node
        ];

        // No completed local transaction
        $this->transactionRepository->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([]);

        // Sender inquiry returns null
        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should still expire the P2P without crashing
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        // Should not crash (no exception expected)
        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage does NOT send cancel notification when P2P is completed locally
     *
     * If a completed transaction exists locally, the P2P is marked completed
     * and the cancel notification path is never reached.
     */
    public function testExpireMessageDoesNotSendCancelWhenCompletedLocally(): void
    {
        $p2pService = $this->createMock(P2pServiceInterface::class);
        $this->service->setP2pService($p2pService);

        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            // No destination_address = relay node
        ];

        // Completed transaction found locally
        $this->transactionRepository->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([
                ['txid' => 'txid-123', 'status' => Constants::STATUS_COMPLETED]
            ]);

        // Should update P2P to completed (not expired)
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED, true);

        // Cancel notification should NOT be sent (P2P is completed, not expired)
        $p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        // Sender inquiry should NOT be called (early return on local completion)
        $this->transportUtility->expects($this->never())
            ->method('send');

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage does NOT send cancel notification when sender reports completed
     *
     * If the sender inquiry reveals a completed status, the P2P is synced and
     * completed. The cancel notification path is never reached.
     */
    public function testExpireMessageDoesNotSendCancelWhenSenderReportsCompleted(): void
    {
        $p2pService = $this->createMock(P2pServiceInterface::class);
        $this->service->setP2pService($p2pService);

        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            // No destination_address = relay node
        ];

        $pendingTransaction = [
            'txid' => 'txid-123',
            'status' => Constants::STATUS_PENDING,
        ];

        // First call: no completed locally; second call: after sync
        $this->transactionRepository->expects($this->exactly(2))
            ->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([$pendingTransaction]);

        // Sender reports completed
        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => Constants::STATUS_COMPLETED]));

        // Should mark as completed (not expired)
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED, true);

        // Cancel notification should NOT be sent (P2P is completed)
        $p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage sends cancel notification and also cancels transactions
     *
     * When a relay node P2P expires with pending transactions, both the cancel
     * notification should be sent AND the transactions should be cancelled.
     */
    public function testExpireMessageSendsCancelAndCancelsTransactions(): void
    {
        $p2pService = $this->createMock(P2pServiceInterface::class);
        $this->service->setP2pService($p2pService);

        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            // No destination_address = relay node
        ];

        $pendingTransaction = [
            'txid' => 'txid-123',
            'status' => Constants::STATUS_PENDING,
        ];

        // Pending transaction exists locally (not completed)
        $this->transactionRepository->method('getByMemo')
            ->with(self::TEST_HASH)
            ->willReturn([$pendingTransaction]);

        // Sender inquiry returns null (no completion)
        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        // Should expire the P2P
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_EXPIRED);

        // Should send cancel notification upstream
        $p2pService->expects($this->once())
            ->method('sendCancelNotificationForHash')
            ->with(self::TEST_HASH);

        // Should also cancel the pending transaction
        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_CANCELLED);

        $this->service->expireMessage($message);
    }

    /**
     * Test expireMessage does NOT send cancel when best-fee candidate selection occurs
     *
     * When the P2P is in best-fee mode and candidates exist, the expiration
     * triggers candidate selection instead. Cancel notification should NOT be sent.
     */
    public function testExpireMessageDoesNotSendCancelWhenBestFeeSelectionTriggered(): void
    {
        $p2pService = $this->createMock(P2pServiceInterface::class);
        $rp2pService = $this->createMock(\Eiou\Contracts\Rp2pServiceInterface::class);
        $rp2pCandidateRepo = $this->createMock(\Eiou\Database\Rp2pCandidateRepository::class);

        $this->service->setP2pService($p2pService);
        $this->service->setRp2pService($rp2pService);
        $this->service->setRp2pCandidateRepository($rp2pCandidateRepo);

        // Relay node in best-fee mode
        $message = [
            'hash' => self::TEST_HASH,
            'sender_address' => self::TEST_SENDER_ADDRESS,
            'fast' => 0, // best-fee mode
            // No destination_address = relay node
        ];

        // No completed local transaction
        $this->transactionRepository->method('getByMemo')
            ->willReturn([]);

        // Candidates exist
        $rp2pCandidateRepo->expects($this->once())
            ->method('getCandidateCount')
            ->with(self::TEST_HASH)
            ->willReturn(2);

        // Should trigger best-fee selection
        $rp2pService->expects($this->once())
            ->method('selectAndForwardBestRp2p')
            ->with(self::TEST_HASH);

        // Should mark as 'found' (not expired)
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, 'found');

        // Cancel notification should NOT be sent (best-fee route found)
        $p2pService->expects($this->never())
            ->method('sendCancelNotificationForHash');

        // Sender inquiry should NOT be called (early return on candidate selection)
        $this->transportUtility->expects($this->never())
            ->method('send');

        $this->service->expireMessage($message);
    }
}
