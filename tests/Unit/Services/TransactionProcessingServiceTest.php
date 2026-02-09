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
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\HeldTransactionServiceInterface;
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
            $this->mockMessageDeliveryService
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
            $this->mockMessageDeliveryService
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
            null
        );

        $this->assertInstanceOf(TransactionProcessingService::class, $service);
    }

    /**
     * Test setSyncTrigger sets the sync trigger
     */
    public function testSetSyncTriggerSetsTheSyncTrigger(): void
    {
        $this->service->setSyncTrigger($this->mockSyncTrigger);
        $this->expectNotToPerformAssertions();
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
        $this->expectNotToPerformAssertions();
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
        $this->markTestSkipped('Logger is now injectable but test needs rework to verify logging behavior');
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
            'receiverPublicKey' => 'receiver-public-key'
        ];

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
    }

    /**
     * Test processTransaction with P2P memo processes P2P incoming
     */
    public function testProcessTransactionWithP2pMemoProcessesP2pIncoming(): void
    {
        $request = [
            'memo' => 'p2p-hash-12345',
            'senderAddress' => 'http://sender.example.com',
            'txid' => 'test-txid-12345'
        ];

        $this->mockRp2pRepo->expects($this->once())
            ->method('getByHash')
            ->with('p2p-hash-12345')
            ->willReturn(['hash' => 'p2p-hash-12345']);

        $this->mockTransactionRepo->expects($this->once())
            ->method('insertTransaction')
            ->with($request, 'relay')
            ->willReturn('{"status":"success"}');

        $this->service->processTransaction($request);
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
        $this->service->setSyncTrigger($this->mockSyncTrigger);
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

        $result = $this->service->processPendingTransactions();

        $this->assertEquals(1, $result);
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
        $this->service->setSyncTrigger($this->mockSyncTrigger);
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
        $pendingMessage = [
            'memo' => 'standard',
            'txid' => 'test-txid-12345',
            'sender_address' => 'http://sender.example.com',
            'receiver_address' => 'http://user.example.com',
            'sender_public_key' => 'sender-public-key',
            'amount' => 1000,
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
            ->with('sender-public-key', 'received', 1000, 'USD');

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
        $this->service->setSyncTrigger($this->mockSyncTrigger);
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
    // Exception Handling Tests
    // =========================================================================

    /**
     * Test getSyncTrigger throws RuntimeException when not injected
     */
    public function testGetSyncTriggerThrowsRuntimeExceptionWhenNotInjected(): void
    {
        $this->service->setP2pService($this->mockP2pService);
        // Don't set sync trigger

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

        $this->service->processPendingTransactions();
    }

    /**
     * Test getP2pService throws RuntimeException when not injected
     */
    public function testGetP2pServiceThrowsRuntimeExceptionWhenNotInjected(): void
    {
        $this->service->setSyncTrigger($this->mockSyncTrigger);
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
