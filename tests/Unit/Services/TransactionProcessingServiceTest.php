<?php
/**
 * Unit Tests for TransactionProcessingService
 *
 * Tests core transaction processing logic including:
 * - Incoming transaction processing
 * - Pending transaction processing
 * - Direct transaction handling
 * - P2P transaction handling
 * - Atomic claiming pattern
 * - Transaction message sending
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\TransactionProcessingService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionRecoveryRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Schemas\Payloads\TransactionPayload;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;
use Eiou\Core\Constants;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Core\SplitAmount;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\HeldTransactionServiceInterface;
use Eiou\Events\EventDispatcher;
use Eiou\Events\P2pEvents;
use Eiou\Events\TransactionEvents;
use RuntimeException;
use InvalidArgumentException;

#[CoversClass(TransactionProcessingService::class)]
class TransactionProcessingServiceTest extends TestCase
{
    private TransactionProcessingService $service;
    private TransactionRepository $mockTransactionRepo;
    private TransactionRecoveryRepository $mockRecoveryRepo;
    private TransactionChainRepository $mockChainRepo;
    private P2pRepository $mockP2pRepo;
    private Rp2pRepository $mockRp2pRepo;
    private BalanceRepository $mockBalanceRepo;
    private TransactionPayload $mockTransactionPayload;
    private TransportUtilityService $mockTransportUtility;
    private TimeUtilityService $mockTimeUtility;
    private UserContext $mockUserContext;
    private Logger $mockLogger;
    private MessageDeliveryService $mockMessageDeliveryService;
    private SyncTriggerInterface $mockSyncTrigger;
    private P2pServiceInterface $mockP2pService;
    private HeldTransactionServiceInterface $mockHeldTransactionService;

    protected function setUp(): void
    {
        // Event subscriptions from previous tests must not leak, or the
        // dispatch assertions below will see cross-test interference.
        EventDispatcher::resetInstance();
        $this->mockTransactionRepo = $this->createMock(TransactionRepository::class);
        $this->mockRecoveryRepo = $this->createMock(TransactionRecoveryRepository::class);
        $this->mockChainRepo = $this->createMock(TransactionChainRepository::class);
        $this->mockP2pRepo = $this->createMock(P2pRepository::class);
        $this->mockRp2pRepo = $this->createMock(Rp2pRepository::class);
        $this->mockBalanceRepo = $this->createMock(BalanceRepository::class);
        $this->mockTransactionPayload = $this->createMock(TransactionPayload::class);
        $this->mockTransportUtility = $this->createMock(TransportUtilityService::class);
        $this->mockTimeUtility = $this->createMock(TimeUtilityService::class);
        $this->mockUserContext = $this->createMock(UserContext::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->mockMessageDeliveryService = $this->createMock(MessageDeliveryService::class);
        $this->mockSyncTrigger = $this->createMock(SyncTriggerInterface::class);
        $this->mockP2pService = $this->createMock(P2pServiceInterface::class);
        $this->mockHeldTransactionService = $this->createMock(HeldTransactionServiceInterface::class);

        $this->service = new TransactionProcessingService(
            $this->mockTransactionRepo,
            $this->mockRecoveryRepo,
            $this->mockChainRepo,
            $this->mockP2pRepo,
            $this->mockRp2pRepo,
            $this->mockBalanceRepo,
            $this->mockTransactionPayload,
            $this->mockTransportUtility,
            $this->mockTimeUtility,
            $this->mockUserContext,
            $this->mockLogger,
            $this->mockMessageDeliveryService,
            $this->mockSyncTrigger
        );
    }

    // =========================================================================
    // Constructor and Setter Tests
    // =========================================================================

    /**
     * Test service instantiation with all dependencies
     */
    public function testServiceInstantiationWithAllDependencies(): void
    {
        $service = new TransactionProcessingService(
            $this->mockTransactionRepo,
            $this->mockRecoveryRepo,
            $this->mockChainRepo,
            $this->mockP2pRepo,
            $this->mockRp2pRepo,
            $this->mockBalanceRepo,
            $this->mockTransactionPayload,
            $this->mockTransportUtility,
            $this->mockTimeUtility,
            $this->mockUserContext,
            $this->mockLogger,
            $this->mockMessageDeliveryService,
            $this->mockSyncTrigger
        );

        $this->assertInstanceOf(TransactionProcessingService::class, $service);
    }

    /**
     * Test service instantiation without optional message delivery service
     */
    public function testServiceInstantiationWithoutOptionalMessageDeliveryService(): void
    {
        $service = new TransactionProcessingService(
            $this->mockTransactionRepo,
            $this->mockRecoveryRepo,
            $this->mockChainRepo,
            $this->mockP2pRepo,
            $this->mockRp2pRepo,
            $this->mockBalanceRepo,
            $this->mockTransactionPayload,
            $this->mockTransportUtility,
            $this->mockTimeUtility,
            $this->mockUserContext,
            $this->mockLogger,
            null,
            $this->mockSyncTrigger
        );

        $this->assertInstanceOf(TransactionProcessingService::class, $service);
    }

    /**
     * Test syncTrigger is injected via constructor
     */
    public function testSyncTriggerIsInjectedViaConstructor(): void
    {
        // syncTrigger is now passed as a constructor argument (already done in setUp)
        // Verify it was set by using reflection
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('syncTrigger');
        $property->setAccessible(true);
        $this->assertSame($this->mockSyncTrigger, $property->getValue($this->service));
    }

    /**
     * Test setP2pService sets the P2P service
     */
    public function testSetP2pServiceSetsTheP2pService(): void
    {
        $this->service->setP2pService($this->mockP2pService);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test setHeldTransactionService sets the held transaction service
     */
    public function testSetHeldTransactionServiceSetsTheHeldTransactionService(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Test setUtilityContainer sets the utility container
     */
    public function testSetUtilityContainerSetsTheUtilityContainer(): void
    {
        $mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $mockUtilityContainer->expects($this->once())
            ->method('getTimeUtility')
            ->willReturn($this->mockTimeUtility);

        $this->service->setUtilityContainer($mockUtilityContainer);
    }

    // =========================================================================
    // processTransaction Tests
    // =========================================================================

    /**
     * Test processTransaction with missing required fields throws exception
     *
     * Note: This test is skipped because Logger is now injectable but test needs rework to verify logging behavior.
     */
    public function testProcessTransactionWithMissingRequiredFieldsThrowsException(): void
    {
        // processTransaction requires 'memo' and 'senderAddress' keys
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Missing required fields in transaction request', $this->anything());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid transaction request structure');

        $this->service->processTransaction(['someKey' => 'value']);
    }

    /**
     * Test processTransaction with standard memo processes standard incoming
     */
    public function testProcessTransactionWithStandardMemoProcessesStandardIncoming(): void
    {
        $request = [
            'memo' => 'standard',
            'senderAddress' => 'http://sender.example.com',
            'txid' => 'test-txid-12345',
            'receiverAddress' => 'http://receiver.example.com',
            'receiverPublicKey' => 'receiver-public-key',
            'amount' => '10.00',
            'currency' => 'USD',
            'senderPublicKey' => 'sender-public-key',
        ];

        $firedTxReceived = null;
        EventDispatcher::getInstance()->subscribe(
            TransactionEvents::TRANSACTION_RECEIVED,
            function (array $data) use (&$firedTxReceived) { $firedTxReceived = $data; }
        );

        $this->mockTransportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with('http://sender.example.com')
            ->willReturn('http://user.example.com');

        $this->mockTransactionPayload->expects($this->once())
            ->method('generateRecipientSignature')
            ->with($request)
            ->willReturn('recipient-signature');

        $this->mockTransactionRepo->expects($this->once())
            ->method('insertTransaction')
            ->with(
                $this->callback(function ($req) {
                    return $req['recipientSignature'] === 'recipient-signature';
                }),
                'received'
            );

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateTrackingFields');

        $this->service->processTransaction($request);

        $this->assertNotNull($firedTxReceived, 'TRANSACTION_RECEIVED must fire after the incoming tx is persisted');
        $this->assertSame('test-txid-12345', $firedTxReceived['txid']);
        $this->assertSame('10.00', $firedTxReceived['amount']);
        $this->assertSame('USD', $firedTxReceived['currency']);
        $this->assertSame('sender-public-key', $firedTxReceived['sender_pubkey']);
    }

    /**
     * Test processTransaction with P2P memo processes P2P incoming
     */
    public function testProcessTransactionWithP2pMemoProcessesP2pIncoming(): void
    {
        $request = [
            'memo' => 'p2p-hash-12345',
            'senderAddress' => 'http://sender.example.com',
            'txid' => 'test-txid-12345',
            'amount' => '5.00',
            'currency' => 'USD',
            'senderPublicKey' => 'upstream-pk',
        ];

        $this->mockRp2pRepo->expects($this->once())
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn(['hash' => 'p2p-hash-12345']);

        $this->mockTransactionRepo->expects($this->once())
            ->method('insertTransaction')
            ->with($request, 'relay')
            ->willReturn('{"status":"success"}');

        // Relay leg should fire P2P_RECEIVED but NOT P2P_COMPLETED — only
        // the end-recipient branch completes the route.
        $firedReceived = null;
        $firedCompleted = false;
        EventDispatcher::getInstance()->subscribe(P2pEvents::P2P_RECEIVED,
            function (array $data) use (&$firedReceived) { $firedReceived = $data; });
        EventDispatcher::getInstance()->subscribe(P2pEvents::P2P_COMPLETED,
            function () use (&$firedCompleted) { $firedCompleted = true; });

        $this->service->processTransaction($request);

        $this->assertNotNull($firedReceived, 'P2P_RECEIVED must fire for a relay leg');
        $this->assertSame('p2p-hash-12345', $firedReceived['p2p_id']);
        $this->assertSame('test-txid-12345', $firedReceived['txid']);
        $this->assertFalse($firedCompleted, 'P2P_COMPLETED must NOT fire for a relay leg — only end-recipient');
    }

    /**
     * End-recipient incoming P2P leg must fire BOTH P2P_RECEIVED and
     * P2P_COMPLETED. The distinction from the relay path: rp2p row is
     * absent (so the first branch's `isset($rP2pResult)` is false) and
     * matchYourselfTransaction resolves true because the memo hash matches
     * one of our own addresses + the P2P salt/time.
     */
    public function testProcessTransactionWithP2pMemoEndRecipientFiresBothEvents(): void
    {
        $salt = 'deterministic-salt';
        $time = '99999';
        $myAddress = 'http://me.example.com';
        $memo = hash('sha256', $myAddress . $salt . $time);

        $request = [
            'memo' => $memo,
            'senderAddress' => 'http://upstream.example.com',
            'txid' => 'final-txid-xyz',
            'amount' => '25.00',
            'currency' => 'USD',
            'senderPublicKey' => 'originator-pk',
        ];

        $this->mockRp2pRepo->method('getByHash')->with($memo)->willReturn(null);
        $this->mockP2pRepo->method('getByHash')->with($memo)->willReturn([
            'hash' => $memo,
            'salt' => $salt,
            'time' => $time,
        ]);
        $this->mockTransportUtility->method('resolveUserAddressForTransport')->willReturn($myAddress);
        $this->mockTransactionPayload->method('generateRecipientSignature')->willReturn('sig');
        $this->mockTransactionRepo->method('insertTransaction')->willReturn('{"ok":true}');

        $eventsFired = [];
        EventDispatcher::getInstance()->subscribe(P2pEvents::P2P_RECEIVED,
            function ($data) use (&$eventsFired) { $eventsFired[] = 'received'; });
        EventDispatcher::getInstance()->subscribe(P2pEvents::P2P_COMPLETED,
            function ($data) use (&$eventsFired) { $eventsFired[] = 'completed'; });

        $this->service->processTransaction($request);

        // Order matters: RECEIVED fires before COMPLETED so a subscriber that
        // counts "leg arrivals" isn't double-counted when it also listens for
        // completions.
        $this->assertSame(['received', 'completed'], $eventsFired);
    }

    // =========================================================================
    // processPendingTransactions Tests
    // =========================================================================

    /**
     * Test processPendingTransactions with no pending transactions returns zero
     */
    public function testProcessPendingTransactionsWithNoPendingTransactionsReturnsZero(): void
    {
        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([]);

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(0, $result);
    }

    /**
     * Test processPendingTransactions processes standard outgoing transaction
     */
    public function testProcessPendingTransactionsProcessesStandardOutgoingTransaction(): void
    {
        $this->service->setP2pService($this->mockP2pService);
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://user.example.com',
            'receiver_address' => 'http://receiver.example.com',
            'sender_public_key' => 'sender-public-key',
            'receiver_public_key' => 'receiver-public-key',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        // Is sender
        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        // Claim transaction
        $this->mockRecoveryRepo->expects($this->once())
            ->method('claimPendingTransaction')
            ->with('test-txid-12345')
            ->willReturn(true);

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildStandardFromDatabase')
            ->willReturn(['type' => 'send']);

        $this->mockTimeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => Constants::STATUS_ACCEPTED, 'recipientSignature' => 'sig'],
                'signing_data' => ['signature' => 'sender-sig', 'nonce' => 12345]
            ]);

        $this->mockRecoveryRepo->expects($this->once())
            ->method('markAsSent')
            ->with('test-txid-12345');

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with('test-txid-12345', Constants::STATUS_ACCEPTED, true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateSignatureData')
            ->with('test-txid-12345', 'sender-sig', 12345);

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateRecipientSignature')
            ->with('test-txid-12345', 'sig');

        // handleAcceptedTransaction re-fetches the tx so the dispatched
        // TRANSACTION_SENT event carries amount/currency/recipient — stub
        // the re-fetch and assert the event fires.
        $this->mockTransactionRepo->method('getByTxid')->with('test-txid-12345')->willReturn([
            'amount' => '10.00',
            'currency' => 'USD',
            'receiver_address' => 'http://receiver.example.com',
        ]);

        $firedSent = null;
        EventDispatcher::getInstance()->subscribe(TransactionEvents::TRANSACTION_SENT,
            function ($data) use (&$firedSent) { $firedSent = $data; });

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
        $this->assertNotNull($firedSent, 'TRANSACTION_SENT must fire after successful delivery');
        $this->assertSame('test-txid-12345', $firedSent['txid']);
        $this->assertSame('10.00', $firedSent['amount']);
        $this->assertSame('USD', $firedSent['currency']);
        $this->assertSame('http://receiver.example.com', $firedSent['recipient_address']);
    }

    /**
     * TRANSACTION_FAILED fires only when delivery is exhausted and the
     * message moves to the DLQ — transient failures that will retry
     * aren't a terminal failure so the event stays silent. Pins the
     * "fires only once per terminal failure" contract documented in
     * TransactionEvents::TRANSACTION_FAILED.
     */
    public function testProcessPendingTransactionsFiresTransactionFailedOnDlqExhaustion(): void
    {
        $this->service->setP2pService($this->mockP2pService);
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'failed-txid',
            'sender_address' => 'http://user.example.com',
            'receiver_address' => 'http://dead.example.com',
            'sender_public_key' => 'sender-pk',
            'receiver_public_key' => 'receiver-pk',
            'amount' => 500,
            'currency' => 'USD',
        ];

        $this->mockRecoveryRepo->method('getPendingTransactions')->willReturn([$pendingMessage]);
        $this->mockTransportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');
        $this->mockRecoveryRepo->method('claimPendingTransaction')->willReturn(true);
        $this->mockTransactionPayload->method('buildStandardFromDatabase')->willReturn(['type' => 'send']);
        $this->mockTimeUtility->method('getCurrentMicrotime')->willReturn(1);

        // Delivery fails with DLQ flag set — this is the "attempts
        // exhausted" terminal state the event documents. Response is
        // a minimal non-null array so the outputter doesn't choke on
        // null; the failure path is driven by success=false + dlq flag.
        $this->mockMessageDeliveryService->method('sendMessage')->willReturn([
            'success' => false,
            'response' => ['status' => 'error'],
            'tracking' => ['dlq' => true, 'attempts' => 5, 'error' => 'recipient unreachable'],
        ]);

        $firedFailed = null;
        $firedSent = false;
        EventDispatcher::getInstance()->subscribe(TransactionEvents::TRANSACTION_FAILED,
            function ($data) use (&$firedFailed) { $firedFailed = $data; });
        EventDispatcher::getInstance()->subscribe(TransactionEvents::TRANSACTION_SENT,
            function () use (&$firedSent) { $firedSent = true; });

        $this->service->processPendingTransactions();

        $this->assertNotNull($firedFailed, 'TRANSACTION_FAILED must fire after DLQ-exhausted delivery');
        $this->assertSame('failed-txid', $firedFailed['txid']);
        $this->assertSame(5, $firedFailed['attempts']);
        $this->assertStringContainsString('recipient unreachable', $firedFailed['error']);
        $this->assertFalse($firedSent, 'TRANSACTION_SENT must NOT fire when delivery failed');
    }

    /**
     * Test processPendingTransactions skips already claimed transaction
     *
     * Note: The service counts loop iterations (attempted transactions),
     * not successful processing. When a claim fails, sendMessage is never called
     * but the counter still increments for the loop iteration.
     */
    public function testProcessPendingTransactionsSkipsAlreadyClaimedTransaction(): void
    {
        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://user.example.com',
            'receiver_address' => 'http://receiver.example.com'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        // Claim fails - already claimed
        $this->mockRecoveryRepo->expects($this->once())
            ->method('claimPendingTransaction')
            ->with('test-txid-12345')
            ->willReturn(false);

        // Should not send - this is the key assertion
        $this->mockMessageDeliveryService->expects($this->never())
            ->method('sendMessage');

        $result = $this->service->processPendingTransactions();

        // Service counts loop iterations, not successful processing
        // The transaction is "skipped" in that sendMessage is never called
        $this->assertEquals(1, $result);
    }

    /**
     * Test processPendingTransactions handles rejected transaction with invalid_previous_txid
     */
    public function testProcessPendingTransactionsHandlesRejectedTransactionWithInvalidPreviousTxid(): void
    {
        $this->service->setP2pService($this->mockP2pService);
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://user.example.com',
            'receiver_address' => 'http://receiver.example.com',
            'receiver_public_key' => 'receiver-public-key',
            'sender_public_key' => 'sender-public-key',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        $this->mockRecoveryRepo->expects($this->once())
            ->method('claimPendingTransaction')
            ->willReturn(true);

        $this->mockTransactionPayload->expects($this->any())
            ->method('buildStandardFromDatabase')
            ->willReturn(['type' => 'send']);

        $this->mockTimeUtility->expects($this->any())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        // Transaction rejected with invalid_previous_txid
        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => [
                    'status' => Constants::STATUS_REJECTED,
                    'reason' => 'invalid_previous_txid',
                    'expected_txid' => 'expected-txid-12345'
                ]
            ]);

        $this->mockRecoveryRepo->expects($this->once())
            ->method('markAsSent');

        // Try to update and resign
        $this->mockChainRepo->expects($this->once())
            ->method('updatePreviousTxid')
            ->with('test-txid-12345', 'expected-txid-12345')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->any())
            ->method('getByTxid')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->once())
            ->method('signWithCapture')
            ->willReturn(['signature' => 'new-sig', 'nonce' => 54321]);

        $this->mockTransactionRepo->expects($this->atLeastOnce())
            ->method('updateSignatureData');

        $this->mockTransactionRepo->expects($this->atLeastOnce())
            ->method('updateStatus');

        // No fallback to P2P needed since resign succeeded
        // Mock syncTransactionChain to avoid undefined key warning
        $this->mockSyncTrigger->expects($this->any())
            ->method('syncTransactionChain')
            ->willReturn(['success' => false, 'synced_count' => 0]);

        $result = $this->service->processPendingTransactions();

        // Result is 0 because when inline retry succeeds, processOutgoingDirect returns 'break'
        // which causes the loop to break BEFORE incrementing processedCount
        $this->assertEquals(0, $result);
    }

    /**
     * Test processPendingTransactions processes incoming direct transaction
     */
    public function testProcessPendingTransactionsProcessesIncomingDirectTransaction(): void
    {
        $amount = new SplitAmount(1000, 0);
        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://sender.example.com',
            'receiver_address' => 'http://user.example.com',
            'sender_public_key' => 'sender-public-key',
            'amount' => $amount,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        // Is NOT sender (is receiver)
        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com'); // User address

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with('test-txid-12345', Constants::STATUS_COMPLETED, true);

        $this->mockBalanceRepo->expects($this->once())
            ->method('updateBalance')
            ->with('sender-public-key', 'received', $amount, 'USD');

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildCompleted')
            ->willReturn(['type' => 'completed']);

        $this->mockTimeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'transaction',
                'http://sender.example.com',
                ['type' => 'completed'],
                $this->stringContains('completion-response'),
                false
            )
            ->willReturn(['success' => true, 'response' => ['status' => 'ok']]);

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
    }

    // =========================================================================
    // P2P Transaction Processing Tests
    // =========================================================================

    /**
     * Test processPendingTransactions processes P2P outgoing transaction
     */
    public function testProcessPendingTransactionsProcessesP2pOutgoingTransaction(): void
    {
        $this->service->setP2pService($this->mockP2pService);
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $pendingMessage = [
            'memo' => 'p2p-hash-12345',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://user.example.com',
            'receiver_address' => 'http://receiver.example.com',
            'sender_public_key' => 'sender-public-key',
            'receiver_public_key' => 'receiver-public-key',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        $this->mockRecoveryRepo->expects($this->once())
            ->method('claimPendingTransaction')
            ->willReturn(true);

        $this->mockRp2pRepo->expects($this->once())
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn(['time' => time()]);

        $this->mockP2pRepo->expects($this->once())
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn(['destination_address' => 'http://final-recipient.example.com']);

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildFromDatabase')
            ->willReturn(['type' => 'send']);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateStatus')
            ->with('p2p-hash-12345', Constants::STATUS_PAID);

        $this->mockTimeUtility->expects($this->once())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => Constants::STATUS_ACCEPTED, 'recipientSignature' => 'sig'],
                'signing_data' => ['signature' => 'sender-sig', 'nonce' => 12345]
            ]);

        $this->mockRecoveryRepo->expects($this->once())
            ->method('markAsSent');

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with('test-txid-12345', Constants::STATUS_ACCEPTED, true);

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
    }

    /**
     * Test processIncomingP2p relay updates sender_address when actual sender differs from stored P2P sender
     */
    public function testProcessIncomingP2pRelayUpdatesSenderAddressWhenDifferent(): void
    {
        $pendingMessage = [
            'memo' => 'p2p-hash-12345',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://actual-sender.example.com',
            'receiver_address' => 'http://user.example.com',
            'sender_public_key' => 'sender-public-key',
            'receiver_public_key' => 'receiver-public-key',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        // Not the sender (incoming transaction)
        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        // Not the end recipient — matchYourselfTransaction returns false
        // getByHash is called by matchYourselfTransaction AND by our new sender_address check
        $this->mockP2pRepo->expects($this->exactly(2))
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn([
                'hash' => 'p2p-hash-12345',
                'salt' => 'test-salt',
                'time' => '12345',
                'sender_address' => 'http://original-sender.example.com',
            ]);

        // Relay path: rp2pRepository->getByHash returns null so matchYourselfTransaction
        // is called. For the relay to fire, rp2pRepository must NOT match (processP2pIncoming
        // from processTransaction), but we go through processPendingTransactions which calls
        // processP2pTransaction -> processIncomingP2p directly.
        // In processIncomingP2p, matchYourselfTransaction must return false for relay path.
        // matchYourselfTransaction hashes address+salt+time and compares to memo — won't match.

        $this->mockUserContext->expects($this->any())
            ->method('getUserLocaters')
            ->willReturn(['http://user.example.com']);

        // Relay branch: updateIncomingTxid called
        $this->mockP2pRepo->expects($this->once())
            ->method('updateIncomingTxid')
            ->with('p2p-hash-12345', 'test-txid-12345');

        // Key assertion: updateSenderAddress should be called because actual sender differs
        $this->mockP2pRepo->expects($this->once())
            ->method('updateSenderAddress')
            ->with('p2p-hash-12345', 'http://actual-sender.example.com');

        // Relay continues: rp2p fetch, build forwarding, insert transaction
        $this->mockRp2pRepo->expects($this->once())
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn(['hash' => 'p2p-hash-12345', 'time' => time()]);

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildForwarding')
            ->willReturn([
                'memo' => 'p2p-hash-12345',
                'txid' => 'forwarded-txid',
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://next-hop.example.com'
            ]);

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildFromDatabase')
            ->willReturn(['type' => 'relay']);

        $this->mockTransactionRepo->expects($this->any())
            ->method('insertTransaction')
            ->willReturn('{"status":"success"}');

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with('p2p-hash-12345', Constants::STATUS_ACCEPTED);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateOutgoingTxid');

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
    }

    /**
     * Test processIncomingP2p relay does NOT call updateSenderAddress when sender matches
     */
    public function testProcessIncomingP2pRelayDoesNotUpdateSenderAddressWhenSame(): void
    {
        $pendingMessage = [
            'memo' => 'p2p-hash-12345',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://original-sender.example.com',
            'receiver_address' => 'http://user.example.com',
            'sender_public_key' => 'sender-public-key',
            'receiver_public_key' => 'receiver-public-key',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        $this->mockP2pRepo->expects($this->exactly(2))
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn([
                'hash' => 'p2p-hash-12345',
                'salt' => 'test-salt',
                'time' => '12345',
                'sender_address' => 'http://original-sender.example.com',
            ]);

        $this->mockUserContext->expects($this->any())
            ->method('getUserLocaters')
            ->willReturn(['http://user.example.com']);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateIncomingTxid')
            ->with('p2p-hash-12345', 'test-txid-12345');

        // Key assertion: updateSenderAddress should NOT be called
        $this->mockP2pRepo->expects($this->never())
            ->method('updateSenderAddress');

        $this->mockRp2pRepo->expects($this->once())
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn(['hash' => 'p2p-hash-12345', 'time' => time()]);

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildForwarding')
            ->willReturn([
                'memo' => 'p2p-hash-12345',
                'txid' => 'forwarded-txid',
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://next-hop.example.com'
            ]);

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildFromDatabase')
            ->willReturn(['type' => 'relay']);

        $this->mockTransactionRepo->expects($this->any())
            ->method('insertTransaction')
            ->willReturn('{"status":"success"}');

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with('p2p-hash-12345', Constants::STATUS_ACCEPTED);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateOutgoingTxid');

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
    }

    // =========================================================================
    // End Recipient sender_address Update Tests
    // =========================================================================

    /**
     * Test processIncomingP2p end recipient updates sender_address when actual sender differs
     *
     * In multi-path routing, the P2P may arrive from one node (e.g. A6) but the
     * transaction follows the RP2P-selected route through a different node (e.g. A7).
     * The end recipient must update sender_address so trace functions reflect the
     * actual transaction route.
     */
    public function testProcessIncomingP2pEndRecipientUpdatesSenderAddressWhenDifferent(): void
    {
        $salt = 'test-salt';
        $time = '12345';
        $userAddress = 'http://user.example.com';
        $memo = hash('sha256', $userAddress . $salt . $time);

        $pendingMessage = [
            'memo' => $memo,
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://actual-sender.example.com',
            'receiver_address' => $userAddress,
            'sender_public_key' => 'sender-public-key',
            'receiver_public_key' => 'receiver-public-key',
            'amount' => new SplitAmount(1000, 0),
            'currency' => 'USD',
            'description' => 'test payment',
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        // Not the sender (incoming transaction)
        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn($userAddress);

        // P2P record: sender_address differs from actual transaction sender
        // Called by matchYourselfTransaction AND by our sender_address update check
        $this->mockP2pRepo->expects($this->exactly(2))
            ->method('getByHash')
            ->with($memo)
            ->willReturn([
                'hash' => $memo,
                'salt' => $salt,
                'time' => $time,
                'sender_address' => 'http://original-p2p-sender.example.com',
            ]);

        $this->mockUserContext->expects($this->any())
            ->method('getUserLocaters')
            ->willReturn([$userAddress]);

        // End recipient branch: status updates
        $this->mockP2pRepo->expects($this->once())
            ->method('updateStatus')
            ->with($memo, Constants::STATUS_COMPLETED, true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with($memo, Constants::STATUS_COMPLETED);

        $this->mockBalanceRepo->expects($this->once())
            ->method('updateBalance')
            ->with('sender-public-key', 'received', $this->isInstanceOf(SplitAmount::class), 'USD');

        $this->mockP2pRepo->expects($this->once())
            ->method('updateIncomingTxid')
            ->with($memo, 'test-txid-12345');

        // Key assertion: updateSenderAddress SHOULD be called
        $this->mockP2pRepo->expects($this->once())
            ->method('updateSenderAddress')
            ->with($memo, 'http://actual-sender.example.com');

        // Completion message
        $this->mockTransactionPayload->expects($this->once())
            ->method('buildCompleted')
            ->willReturn(['type' => 'completed']);

        $this->mockMessageDeliveryService->expects($this->once())
            ->method('markCompletedByHash')
            ->with('p2p', $memo);

        $this->mockTimeUtility->expects($this->any())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => ['status' => 'ok']]);

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
    }

    /**
     * Test processIncomingP2p end recipient does NOT update sender_address when it matches
     */
    public function testProcessIncomingP2pEndRecipientDoesNotUpdateSenderAddressWhenSame(): void
    {
        $salt = 'test-salt';
        $time = '12345';
        $userAddress = 'http://user.example.com';
        $memo = hash('sha256', $userAddress . $salt . $time);

        $pendingMessage = [
            'memo' => $memo,
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://original-sender.example.com',
            'receiver_address' => $userAddress,
            'sender_public_key' => 'sender-public-key',
            'receiver_public_key' => 'receiver-public-key',
            'amount' => new SplitAmount(1000, 0),
            'currency' => 'USD',
            'description' => 'test payment',
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn($userAddress);

        // P2P record: sender_address MATCHES message sender_address
        $this->mockP2pRepo->expects($this->exactly(2))
            ->method('getByHash')
            ->with($memo)
            ->willReturn([
                'hash' => $memo,
                'salt' => $salt,
                'time' => $time,
                'sender_address' => 'http://original-sender.example.com',
            ]);

        $this->mockUserContext->expects($this->any())
            ->method('getUserLocaters')
            ->willReturn([$userAddress]);

        $this->mockP2pRepo->expects($this->once())
            ->method('updateStatus')
            ->with($memo, Constants::STATUS_COMPLETED, true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with($memo, Constants::STATUS_COMPLETED);

        $this->mockBalanceRepo->expects($this->once())
            ->method('updateBalance');

        $this->mockP2pRepo->expects($this->once())
            ->method('updateIncomingTxid');

        // Key assertion: updateSenderAddress should NOT be called
        $this->mockP2pRepo->expects($this->never())
            ->method('updateSenderAddress');

        $this->mockTransactionPayload->expects($this->once())
            ->method('buildCompleted')
            ->willReturn(['type' => 'completed']);

        $this->mockMessageDeliveryService->expects($this->any())
            ->method('markCompletedByHash');

        $this->mockTimeUtility->expects($this->any())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => ['status' => 'ok']]);

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
    }

    // =========================================================================
    // Exception Handling Tests
    // =========================================================================

    /**
     * Test getSyncTrigger throws RuntimeException when not injected
     */
    public function testGetSyncTriggerThrowsRuntimeExceptionWhenNotInjected(): void
    {
        // Create service WITHOUT syncTrigger (pass null explicitly)
        $serviceWithoutSync = new TransactionProcessingService(
            $this->mockTransactionRepo,
            $this->mockRecoveryRepo,
            $this->mockChainRepo,
            $this->mockP2pRepo,
            $this->mockRp2pRepo,
            $this->mockBalanceRepo,
            $this->mockTransactionPayload,
            $this->mockTransportUtility,
            $this->mockTimeUtility,
            $this->mockUserContext,
            $this->mockLogger,
            $this->mockMessageDeliveryService,
            null
        );
        $serviceWithoutSync->setP2pService($this->mockP2pService);

        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://user.example.com',
            'receiver_address' => 'http://receiver.example.com',
            'receiver_public_key' => 'receiver-public-key',
            'sender_public_key' => 'sender-public-key',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        $this->mockRecoveryRepo->expects($this->once())
            ->method('claimPendingTransaction')
            ->willReturn(true);

        $this->mockTransactionPayload->expects($this->any())
            ->method('buildStandardFromDatabase')
            ->willReturn(['type' => 'send']);

        $this->mockTimeUtility->expects($this->any())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        // Transaction rejected with invalid_previous_txid
        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => [
                    'status' => Constants::STATUS_REJECTED,
                    'reason' => 'invalid_previous_txid',
                    'expected_txid' => null
                ]
            ]);

        $this->mockRecoveryRepo->expects($this->once())
            ->method('markAsSent');

        // Should throw when trying to get sync trigger
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SyncTrigger not injected');

        $serviceWithoutSync->processPendingTransactions();
    }

    /**
     * Test getP2pService throws RuntimeException when not injected
     */
    public function testGetP2pServiceThrowsRuntimeExceptionWhenNotInjected(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);
        // Don't set P2P service

        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://user.example.com',
            'receiver_address' => 'http://receiver.example.com',
            'receiver_public_key' => 'receiver-public-key',
            'sender_public_key' => 'sender-public-key',
            'amount' => 1000,
            'currency' => 'USD'
        ];

        $this->mockRecoveryRepo->expects($this->once())
            ->method('getPendingTransactions')
            ->willReturn([$pendingMessage]);

        $this->mockTransportUtility->expects($this->any())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://user.example.com');

        $this->mockRecoveryRepo->expects($this->once())
            ->method('claimPendingTransaction')
            ->willReturn(true);

        $this->mockTransactionPayload->expects($this->any())
            ->method('buildStandardFromDatabase')
            ->willReturn(['type' => 'send']);

        $this->mockTimeUtility->expects($this->any())
            ->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        // Transaction rejected - needs P2P fallback
        $this->mockMessageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => [
                    'status' => Constants::STATUS_REJECTED,
                    'reason' => 'some_reason'
                ]
            ]);

        $this->mockRecoveryRepo->expects($this->once())
            ->method('markAsSent');

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus');

        // Should throw when trying to get P2P service for fallback
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('P2pService not injected');

        $this->service->processPendingTransactions();
    }
}
