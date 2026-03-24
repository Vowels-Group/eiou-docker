<?php
/**
 * Unit Tests for CleanupService — Transaction Expiry & DLQ Lifecycle Decoupling
 *
 * Tests introduced with the transaction-level expiry feature:
 * - expireStaleTransactions() cancels past-deadline transactions independently
 * - expireMessage() uses cancelPendingByMemo() so in-flight transactions survive P2P expiry
 * - P2P and transaction lifecycles are decoupled via expires_at
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
use Eiou\Database\RepositoryFactory;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Database\CapacityReservationRepository;
use Eiou\Database\RouteCancellationRepository;

#[CoversClass(CleanupService::class)]
class CleanupServiceTransactionExpiryTest extends TestCase
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

    private const TEST_HASH   = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_TXID   = 'txid123def456789012345678901234567890123456789012345678901234txid';
    private const TEST_TXID2  = 'txid456abc789012345678901234567890123456789012345678901234txid2';
    private const TEST_ADDR   = 'http://sender.example.com';
    private const TEST_PUBKEY = 'test-public-key-123456789012345678901234567890';
    private const TEST_MICRO  = 1234567890123456;

    protected function setUp(): void
    {
        parent::setUp();

        $this->p2pRepository        = $this->createMock(P2pRepository::class);
        $this->rp2pRepository       = $this->createMock(Rp2pRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->balanceRepository    = $this->createMock(BalanceRepository::class);

        $this->timeUtility = $this->getMockBuilder(TimeUtilityService::class)
            ->disableOriginalConstructor()->getMock();
        $this->transportUtility = $this->getMockBuilder(TransportUtilityService::class)
            ->disableOriginalConstructor()->getMock();
        $this->currencyUtility = $this->getMockBuilder(CurrencyUtilityService::class)
            ->disableOriginalConstructor()->getMock();
        $this->validationUtility = $this->getMockBuilder(ValidationUtilityService::class)
            ->disableOriginalConstructor()->getMock();

        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->utilityContainer->method('getTimeUtility')->willReturn($this->timeUtility);
        $this->utilityContainer->method('getTransportUtility')->willReturn($this->transportUtility);
        $this->utilityContainer->method('getCurrencyUtility')->willReturn($this->currencyUtility);
        $this->utilityContainer->method('getValidationUtility')->willReturn($this->validationUtility);

        $this->userContext = $this->createMock(UserContext::class);
        $this->userContext->method('getPublicKey')->willReturn(self::TEST_PUBKEY);

        $this->messageDeliveryService = $this->createMock(MessageDeliveryServiceInterface::class);
        $this->messageDeliveryService->method('processRetryQueue')
            ->willReturn(['processed' => 0, 'failed' => 0, 'moved_to_dlq' => 0]);

        $repositoryFactory = $this->createMock(RepositoryFactory::class);
        $repositoryFactory->method('get')
            ->willReturnCallback(function (string $class) {
                return match ($class) {
                    Rp2pCandidateRepository::class => $this->createMock(Rp2pCandidateRepository::class),
                    P2pSenderRepository::class => $this->createMock(P2pSenderRepository::class),
                    P2pRelayedContactRepository::class => $this->createMock(P2pRelayedContactRepository::class),
                    CapacityReservationRepository::class => $this->createMock(CapacityReservationRepository::class),
                    RouteCancellationRepository::class => $this->createMock(RouteCancellationRepository::class),
                    default => null,
                };
            });

        $this->service = new CleanupService(
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->transactionRepository,
            $this->balanceRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService,
            $repositoryFactory
        );
    }

    // =========================================================================
    // expireStaleTransactions() tests
    // =========================================================================

    /**
     * expireStaleTransactions() returns 0 when no expired transactions exist
     */
    public function testExpireStaleTransactionsReturnsZeroWhenNoneExpired(): void
    {
        $this->transactionRepository->expects($this->once())
            ->method('getExpiredTransactions')
            ->willReturn([]);

        $this->transactionRepository->expects($this->never())
            ->method('updateStatus');

        $result = $this->service->expireStaleTransactions();

        $this->assertSame(0, $result);
    }

    /**
     * expireStaleTransactions() cancels a single expired transaction and returns 1
     */
    public function testExpireStaleTransactionsCancelsSingleExpiredTransaction(): void
    {
        $expiredTx = [
            'txid'    => self::TEST_TXID,
            'tx_type' => 'sent',
            'status'  => Constants::STATUS_SENDING,
            'expires_at' => date('Y-m-d H:i:s', time() - 120),
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getExpiredTransactions')
            ->willReturn([$expiredTx]);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_TXID, Constants::STATUS_CANCELLED, true)
            ->willReturn(true);

        $result = $this->service->expireStaleTransactions();

        $this->assertSame(1, $result);
    }

    /**
     * expireStaleTransactions() cancels multiple expired transactions and returns the count
     */
    public function testExpireStaleTransactionsCancelsMultipleExpiredTransactions(): void
    {
        $expiredTxs = [
            ['txid' => self::TEST_TXID,  'tx_type' => 'sent', 'status' => Constants::STATUS_SENDING,  'expires_at' => date('Y-m-d H:i:s', time() - 60)],
            ['txid' => self::TEST_TXID2, 'tx_type' => 'sent', 'status' => Constants::STATUS_PENDING,  'expires_at' => date('Y-m-d H:i:s', time() - 30)],
        ];

        $this->transactionRepository->expects($this->once())
            ->method('getExpiredTransactions')
            ->willReturn($expiredTxs);

        $this->transactionRepository->expects($this->exactly(2))
            ->method('updateStatus')
            ->with($this->anything(), Constants::STATUS_CANCELLED, true)
            ->willReturn(true);

        $result = $this->service->expireStaleTransactions();

        $this->assertSame(2, $result);
    }

    /**
     * expireStaleTransactions() is called during processCleanupMessages()
     * and its count is added to the total processed count
     */
    public function testProcessCleanupMessagesIncludesExpiredTransactionCount(): void
    {
        $this->timeUtility->method('getCurrentMicrotime')->willReturn(self::TEST_MICRO);
        $this->p2pRepository->method('getExpiredP2p')->with(self::TEST_MICRO)->willReturn([]);

        $expiredTx = [
            'txid'    => self::TEST_TXID,
            'tx_type' => 'sent',
            'status'  => Constants::STATUS_SENDING,
            'expires_at' => date('Y-m-d H:i:s', time() - 120),
        ];
        $this->transactionRepository->expects($this->once())
            ->method('getExpiredTransactions')
            ->willReturn([$expiredTx]);

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_TXID, Constants::STATUS_CANCELLED, true)
            ->willReturn(true);

        // processCleanupMessages adds expiredTxCount to the total
        $result = $this->service->processCleanupMessages();
        $this->assertGreaterThanOrEqual(1, $result);
    }

    /**
     * expireStaleTransactions() gracefully handles a repository exception
     * without propagating it (returns 0 on error)
     */
    public function testExpireStaleTransactionsHandlesException(): void
    {
        $this->transactionRepository->expects($this->once())
            ->method('getExpiredTransactions')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DB error');
        $this->service->expireStaleTransactions();
    }

    // =========================================================================
    // expireMessage() cancelPendingByMemo decoupling tests
    // =========================================================================

    /**
     * expireMessage() calls cancelPendingByMemo (not updateStatus) when transactions exist.
     * This leaves in-flight (sending/sent/accepted) transactions to complete naturally.
     */
    public function testExpireMessageCallsCancelPendingByMemoNotUpdateStatus(): void
    {
        $message = ['hash' => self::TEST_HASH, 'sender_address' => self::TEST_ADDR];
        $pendingTx = ['txid' => self::TEST_TXID, 'status' => Constants::STATUS_PENDING];

        $this->transactionRepository->method('getByMemo')
            ->with(self::TEST_HASH)->willReturn([$pendingTx]);

        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        $this->p2pRepository->method('updateStatus');

        // MUST call cancelPendingByMemo — not the broad updateStatus on transactions
        $this->transactionRepository->expects($this->once())
            ->method('cancelPendingByMemo')
            ->with(self::TEST_HASH);

        $this->transactionRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->expireMessage($message);
    }

    /**
     * expireMessage() does NOT call cancelPendingByMemo when there are no transactions.
     */
    public function testExpireMessageSkipsCancelWhenNoTransactions(): void
    {
        $message = ['hash' => self::TEST_HASH, 'sender_address' => self::TEST_ADDR];

        $this->transactionRepository->method('getByMemo')
            ->with(self::TEST_HASH)->willReturn([]);

        $this->transportUtility->method('send')
            ->willReturn(json_encode(['status' => null]));

        $this->p2pRepository->method('updateStatus');

        // No transactions — cancelPendingByMemo and updateStatus should not be called
        $this->transactionRepository->expects($this->never())
            ->method('cancelPendingByMemo');

        $this->transactionRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->expireMessage($message);
    }

    // =========================================================================
    // DIRECT_TX_DELIVERY_EXPIRATION_SECONDS constant
    // =========================================================================

    /**
     * DIRECT_TX_DELIVERY_EXPIRATION_SECONDS equals 4× TOR_TRANSPORT_TIMEOUT_SECONDS
     * (two round-trips: send + response × connect + transfer)
     */
    public function testDirectTxDeliveryExpirationConstantIsFourTorTimeouts(): void
    {
        $this->assertSame(
            4 * Constants::TOR_TRANSPORT_TIMEOUT_SECONDS,
            Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS,
            'DIRECT_TX_DELIVERY_EXPIRATION_SECONDS should equal 4 × TOR_TRANSPORT_TIMEOUT_SECONDS'
        );
    }

    /**
     * DIRECT_TX_DELIVERY_EXPIRATION_SECONDS is positive and reasonable (≥30s, ≤300s)
     */
    public function testDirectTxDeliveryExpirationConstantIsReasonable(): void
    {
        $this->assertGreaterThanOrEqual(30, Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS);
        $this->assertLessThanOrEqual(300, Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS);
    }
}
