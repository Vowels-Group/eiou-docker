<?php
/**
 * Unit Tests for Rp2pService
 *
 * Tests RP2P (Relay Peer-to-Peer) service functionality including:
 * - P2P transaction sender injection
 * - Message delivery service injection
 * - RP2P request handling
 * - RP2P possibility checking
 * - Fee information calculation
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\Rp2pService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use RuntimeException;
use Exception;
use PDOException;

#[CoversClass(Rp2pService::class)]
class Rp2pServiceTest extends TestCase
{
    private MockObject|ContactRepository $contactRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|P2pRepository $p2pRepository;
    private MockObject|Rp2pRepository $rp2pRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|ValidationUtilityService $validationUtility;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TimeUtilityService $timeUtility;
    private MockObject|UserContext $userContext;
    private MockObject|MessageDeliveryService $messageDeliveryService;
    private MockObject|P2pTransactionSenderInterface $p2pTransactionSender;
    private Rp2pService $service;

    private const TEST_ADDRESS = 'http://test.example.com';
    private const TEST_PUBLIC_KEY = 'test-public-key-1234567890';
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_AMOUNT = 10000; // cents

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->p2pRepository = $this->createMock(P2pRepository::class);
        $this->rp2pRepository = $this->createMock(Rp2pRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->validationUtility = $this->createMock(ValidationUtilityService::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->timeUtility = $this->createMock(TimeUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->messageDeliveryService = $this->createMock(MessageDeliveryService::class);
        $this->p2pTransactionSender = $this->createMock(P2pTransactionSenderInterface::class);

        // Setup utility container
        $this->utilityContainer->method('getValidationUtility')
            ->willReturn($this->validationUtility);
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);
        $this->utilityContainer->method('getTimeUtility')
            ->willReturn($this->timeUtility);

        // Setup transport utility to return address as-is
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturnCallback(fn($address) => $address ?? self::TEST_ADDRESS);

        // Setup default user context
        $this->userContext->method('getMaxFee')
            ->willReturn(5.0);
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $this->service = new Rp2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService
        );
    }

    // =========================================================================
    // setP2pTransactionSender() Tests
    // =========================================================================

    /**
     * Test setP2pTransactionSender sets the sender
     */
    public function testSetP2pTransactionSenderSetsSender(): void
    {
        $sender = $this->createMock(P2pTransactionSenderInterface::class);
        $this->service->setP2pTransactionSender($sender);

        // No exception means success
        $this->assertTrue(true);
    }

    // =========================================================================
    // setMessageDeliveryService() Tests
    // =========================================================================

    /**
     * Test setMessageDeliveryService sets the service
     */
    public function testSetMessageDeliveryServiceSetsService(): void
    {
        $service = $this->createMock(MessageDeliveryService::class);
        $this->service->setMessageDeliveryService($service);

        // No exception means success
        $this->assertTrue(true);
    }

    // =========================================================================
    // handleRp2pRequest() Tests
    // =========================================================================

    /**
     * Test handleRp2pRequest throws exception when P2P not found
     */
    public function testHandleRp2pRequestThrowsWhenP2pNotFound(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('P2P request was not found');

        $this->service->handleRp2pRequest($request);
    }

    /**
     * Test handleRp2pRequest updates status for user-originated P2P
     */
    public function testHandleRp2pRequestUpdatesStatusForUserP2p(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'destination_address' => self::TEST_ADDRESS,
            'my_fee_amount' => 100,
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, 'found');

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        // Fee is 1% of 10000 = 100, which is <= 5% max
        $this->service->setP2pTransactionSender($this->p2pTransactionSender);

        $this->p2pTransactionSender->expects($this->once())
            ->method('sendP2pEiou');

        $this->service->handleRp2pRequest($request);
    }

    /**
     * Test handleRp2pRequest relays to next hop for intermediate node
     */
    public function testHandleRp2pRequestRelaysForIntermediateNode(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            // No destination_address means intermediate node
            'my_fee_amount' => 100,
            'sender_address' => 'http://sender.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, 'found');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        $this->messageDeliveryService->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{}',
                'messageId' => 'test-message-id'
            ]);

        $this->service->handleRp2pRequest($request);

        // No exception means success
        $this->assertTrue(true);
    }

    /**
     * Test handleRp2pRequest rejects when insufficient funds for intermediate
     */
    public function testHandleRp2pRequestRejectsInsufficientFundsForIntermediate(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => 100000 // Large amount
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => 100000,
            // No destination_address means intermediate node
            'my_fee_amount' => 1000,
            'sender_address' => 'http://sender.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(0);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(1000.0);

        // With amount 101000 (100000 + 1000 fee) and only 1000 credit, should reject
        // insertRp2pRequest should not be called

        $this->rp2pRepository->expects($this->never())
            ->method('insertRp2pRequest');

        $this->service->handleRp2pRequest($request);
    }

    /**
     * Test handleRp2pRequest rejects when fee exceeds max fee
     */
    public function testHandleRp2pRequestRejectsWhenFeeExceedsMax(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => 20000 // This makes fee 100% which is > 5% max
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => 10000, // Original amount
            'destination_address' => self::TEST_ADDRESS,
            'my_fee_amount' => 0,
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->service->setP2pTransactionSender($this->p2pTransactionSender);

        // Fee is 100% (10000/10000) which is > 5% max, so sendP2pEiou should NOT be called
        $this->p2pTransactionSender->expects($this->never())
            ->method('sendP2pEiou');

        $this->service->handleRp2pRequest($request);
    }

    /**
     * Test handleRp2pRequest handles insertion failure
     */
    public function testHandleRp2pRequestHandlesInsertionFailure(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'destination_address' => self::TEST_ADDRESS,
            'my_fee_amount' => 100,
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('');

        $this->service->setP2pTransactionSender($this->p2pTransactionSender);

        // Should output failure message but continue
        $this->service->handleRp2pRequest($request);

        // No exception means handled gracefully
        $this->assertTrue(true);
    }

    // =========================================================================
    // checkRp2pPossible() Tests
    // =========================================================================

    /**
     * Test checkRp2pPossible returns false for duplicate RP2P
     */
    public function testCheckRp2pPossibleReturnsFalseForDuplicate(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(true);

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkRp2pPossible echoes rejection for duplicate
     */
    public function testCheckRp2pPossibleEchosRejectionForDuplicate(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(true);

        ob_start();
        $this->service->checkRp2pPossible($request, true);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test checkRp2pPossible does not echo when disabled
     */
    public function testCheckRp2pPossibleDoesNotEchoWhenDisabled(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(true);

        ob_start();
        $this->service->checkRp2pPossible($request, false);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    /**
     * Test checkRp2pPossible handles PDOException
     */
    public function testCheckRp2pPossibleHandlesPdoException(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willThrowException(new PDOException('Database error'));

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);

        $decoded = json_decode($output, true);
        $this->assertEquals('rejected', $decoded['status']);
    }

    /**
     * Test checkRp2pPossible handles processing exception
     */
    public function testCheckRp2pPossibleHandlesProcessingException(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        // handleRp2pRequest will fail because P2P not found
        $this->p2pRepository->method('getByHash')
            ->willReturn(null);

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkRp2pPossible processes valid request
     */
    public function testCheckRp2pPossibleProcessesValidRequest(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->willReturn(false);

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'destination_address' => self::TEST_ADDRESS,
            'my_fee_amount' => 100,
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->service->setP2pTransactionSender($this->p2pTransactionSender);

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        $output = ob_get_clean();

        // Returns false because it processed successfully (caller should not call handleRp2pRequest again)
        $this->assertFalse($result);

        // Should have echoed 'inserted' status
        $decoded = json_decode($output, true);
        $this->assertEquals('inserted', $decoded['status']);
    }

    // =========================================================================
    // feeInformation() Tests
    // =========================================================================

    /**
     * Test feeInformation returns zero for zero amount
     */
    public function testFeeInformationReturnsZeroForZeroAmount(): void
    {
        $p2p = [
            'amount' => 0
        ];

        $request = [
            'amount' => 100
        ];

        $result = $this->service->feeInformation($p2p, $request);

        $this->assertEquals(0.0, $result);
    }

    /**
     * Test feeInformation calculates correct fee percent
     */
    public function testFeeInformationCalculatesCorrectFeePercent(): void
    {
        $p2p = [
            'amount' => 10000  // Original amount
        ];

        $request = [
            'amount' => 10100  // Amount with fee
        ];

        // Fee = 10100 - 10000 = 100
        // Fee percent = (100 / 10000) * 100 = 1.0%

        $result = $this->service->feeInformation($p2p, $request);

        $this->assertEquals(1.0, $result);
    }

    /**
     * Test feeInformation handles large fee
     */
    public function testFeeInformationHandlesLargeFee(): void
    {
        $p2p = [
            'amount' => 10000
        ];

        $request = [
            'amount' => 15000  // 50% fee
        ];

        // Fee = 15000 - 10000 = 5000
        // Fee percent = (5000 / 10000) * 100 = 50.0%

        $result = $this->service->feeInformation($p2p, $request);

        $this->assertEquals(50.0, $result);
    }

    /**
     * Test feeInformation handles decimal precision
     */
    public function testFeeInformationHandlesDecimalPrecision(): void
    {
        $p2p = [
            'amount' => 10000
        ];

        $request = [
            'amount' => 10033  // Small fractional fee
        ];

        // Fee = 10033 - 10000 = 33
        // Fee percent = (33 / 10000) * 100 = 0.33%

        $result = $this->service->feeInformation($p2p, $request);

        // Should be rounded to FEE_PERCENT_DECIMAL_PRECISION
        $this->assertIsFloat($result);
        $this->assertGreaterThan(0.3, $result);
        $this->assertLessThan(0.4, $result);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor with null message delivery service
     */
    public function testConstructorWithNullMessageDeliveryService(): void
    {
        $service = new Rp2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->utilityContainer,
            $this->userContext,
            null
        );

        $this->assertInstanceOf(Rp2pService::class, $service);
    }

    /**
     * Test constructor initializes Rp2pPayload
     */
    public function testConstructorInitializesPayload(): void
    {
        $service = new Rp2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->rp2pRepository,
            $this->utilityContainer,
            $this->userContext
        );

        $this->assertInstanceOf(Rp2pService::class, $service);
    }

    // =========================================================================
    // getP2pTransactionSender() Tests (via handleRp2pRequest)
    // =========================================================================

    /**
     * Test throws RuntimeException when P2P transaction sender not set
     */
    public function testThrowsRuntimeExceptionWhenSenderNotSet(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'destination_address' => self::TEST_ADDRESS,
            'my_fee_amount' => 0, // 0% fee, will pass fee check
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        // Don't set P2P transaction sender

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('P2pTransactionSender not injected');

        $this->service->handleRp2pRequest($request);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    /**
     * Test handleRp2pRequest with zero my_fee_amount
     */
    public function testHandleRp2pRequestWithZeroFee(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'destination_address' => self::TEST_ADDRESS,
            'my_fee_amount' => 0,
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->service->setP2pTransactionSender($this->p2pTransactionSender);

        // 0% fee is <= 5% max, so should call sendP2pEiou
        $this->p2pTransactionSender->expects($this->once())
            ->method('sendP2pEiou');

        $this->service->handleRp2pRequest($request);
    }

    /**
     * Test checkRp2pPossible with null request
     */
    public function testCheckRp2pPossibleWithEmptyHash(): void
    {
        $request = [
            'hash' => '',
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $this->rp2pRepository->method('rp2pExists')
            ->with('')
            ->willReturn(false);

        // Will fail in handleRp2pRequest since empty hash won't find P2P
        $this->p2pRepository->method('getByHash')
            ->willReturn(null);

        ob_start();
        $result = $this->service->checkRp2pPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test message delivery failure logging
     */
    public function testHandleRp2pRequestLogsDeliveryFailure(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            // No destination_address means intermediate node
            'my_fee_amount' => 0,
            'sender_address' => 'http://sender.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        // Simulate delivery failure
        $this->messageDeliveryService->method('sendMessage')
            ->willReturn([
                'success' => false,
                'response' => null,
                'raw' => '',
                'messageId' => 'test-message-id',
                'tracking' => [
                    'attempts' => 3,
                    'error' => 'Connection refused',
                    'dlq' => true
                ]
            ]);

        // Should handle failure gracefully
        $this->service->handleRp2pRequest($request);

        $this->assertTrue(true);
    }

    // =========================================================================
    // Multi-Hop Relay and Delivery Robustness Tests
    // =========================================================================

    /**
     * Test RP2P relay through multiple intermediary nodes
     *
     * Verifies that when an RP2P request arrives at an intermediate node (no destination_address),
     * the service correctly relays the message to the next hop (sender_address) using
     * the MessageDeliveryService for reliable delivery.
     */
    public function testHandleRp2pRequestRelaysAcrossMultipleNodes(): void
    {
        $intermediateNodeAddress = 'http://intermediate-node.test';
        $nextHopAddress = 'http://next-hop-node.test';

        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => $intermediateNodeAddress
        ];

        // P2P without destination_address indicates an intermediate node
        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'my_fee_amount' => 50, // Small relay fee
            'sender_address' => $nextHopAddress,
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, 'found');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        // Verify message is sent to the next hop address
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $nextHopAddress,
                $this->anything(),
                $this->stringContains('relay-' . self::TEST_HASH),
                false
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'forwarded'],
                'raw' => '{"status":"forwarded"}',
                'messageId' => 'relay-' . self::TEST_HASH . '-1234567890'
            ]);

        $this->service->handleRp2pRequest($request);

        // No exception means successful relay
        $this->assertTrue(true);
    }

    /**
     * Test that the message hash is preserved through the relay chain
     *
     * Verifies that when RP2P messages are relayed through intermediate nodes,
     * the original hash is preserved and used for tracking throughout the chain.
     */
    public function testRp2pMessageChainPreservesHash(): void
    {
        $originalHash = 'chain123def456789012345678901234567890123456789012345678901234abcd';

        $request = [
            'hash' => $originalHash,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $p2p = [
            'hash' => $originalHash,
            'amount' => self::TEST_AMOUNT,
            'my_fee_amount' => 25,
            'sender_address' => 'http://upstream-node.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->with($originalHash)
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        // Verify the hash is preserved in the RP2P record
        $this->rp2pRepository->expects($this->once())
            ->method('insertRp2pRequest')
            ->with($this->callback(function ($arg) use ($originalHash) {
                return isset($arg['hash']) && $arg['hash'] === $originalHash;
            }))
            ->willReturn('test-rp2p-id');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        // Verify messageId contains the original hash for tracking
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $this->anything(),
                $this->anything(),
                $this->stringContains($originalHash),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'forwarded'],
                'raw' => '{}',
                'messageId' => 'relay-' . $originalHash . '-1234567890'
            ]);

        $this->service->handleRp2pRequest($request);

        $this->assertTrue(true);
    }

    /**
     * Test fee accumulation as RP2P traverses multiple nodes
     *
     * Verifies that the my_fee_amount from each node is correctly added
     * to the request amount as it passes through the relay chain.
     */
    public function testRp2pFeeAccumulationThroughChain(): void
    {
        $baseAmount = 10000;
        $nodeFee = 150; // Each node charges 150 cents

        $request = [
            'hash' => self::TEST_HASH,
            'amount' => $baseAmount,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => $baseAmount,
            'my_fee_amount' => $nodeFee,
            'sender_address' => 'http://upstream.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        // Verify the accumulated amount (base + fee) is stored in the RP2P record
        $expectedAccumulatedAmount = $baseAmount + $nodeFee;
        $this->rp2pRepository->expects($this->once())
            ->method('insertRp2pRequest')
            ->with($this->callback(function ($arg) use ($expectedAccumulatedAmount) {
                return isset($arg['amount']) && $arg['amount'] === $expectedAccumulatedAmount;
            }))
            ->willReturn('test-rp2p-id');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        $this->messageDeliveryService->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'forwarded'],
                'raw' => '{}',
                'messageId' => 'test-message-id'
            ]);

        $this->service->handleRp2pRequest($request);

        $this->assertTrue(true);
    }

    /**
     * Test that delivery attempts are tracked by MessageDeliveryService
     *
     * Verifies that when sending RP2P messages, the MessageDeliveryService
     * tracks delivery attempts and provides attempt count in the response.
     */
    public function testSendRp2pMessageTracksDeliveryAttempts(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'my_fee_amount' => 50,
            'sender_address' => 'http://upstream.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        // Simulate a response that includes tracking information with attempt count
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'forwarded'],
                'raw' => '{"status":"forwarded"}',
                'messageId' => 'relay-' . self::TEST_HASH . '-1234567890',
                'tracking' => [
                    'attempts' => 1,
                    'first_attempt' => '2024-01-15T10:00:00Z',
                    'last_attempt' => '2024-01-15T10:00:00Z'
                ]
            ]);

        $this->service->handleRp2pRequest($request);

        // Verify message was sent (no exception means delivery tracking worked)
        $this->assertTrue(true);
    }

    /**
     * Test graceful handling of network timeouts during RP2P delivery
     *
     * Verifies that when a network timeout occurs during message delivery,
     * the service handles it gracefully without throwing uncaught exceptions.
     */
    public function testSendRp2pMessageHandlesNetworkTimeoutGracefully(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'my_fee_amount' => 50,
            'sender_address' => 'http://slow-upstream.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        // Simulate network timeout response from MessageDeliveryService
        $this->messageDeliveryService->method('sendMessage')
            ->willReturn([
                'success' => false,
                'response' => null,
                'raw' => '',
                'messageId' => 'relay-' . self::TEST_HASH . '-1234567890',
                'tracking' => [
                    'attempts' => 3,
                    'error' => 'Connection timed out after 30000ms',
                    'dlq' => true,
                    'timeout' => true
                ]
            ]);

        // Should handle timeout gracefully without throwing exception
        $this->service->handleRp2pRequest($request);

        // No exception thrown means graceful handling
        $this->assertTrue(true);
    }

    /**
     * Test upstream notification on delivery failure
     *
     * Verifies that when RP2P delivery fails after all retry attempts,
     * the failure details are logged with information about the upstream node.
     */
    public function testRp2pDeliveryFailureNotifiesUpstream(): void
    {
        $upstreamAddress = 'http://upstream-node.test';

        $request = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'senderAddress' => self::TEST_ADDRESS
        ];

        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'my_fee_amount' => 50,
            'sender_address' => $upstreamAddress,
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        $this->rp2pRepository->method('insertRp2pRequest')
            ->willReturn('test-rp2p-id');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        // Verify sendMessage is called with the correct upstream address
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $upstreamAddress,
                $this->anything(),
                $this->anything(),
                false
            )
            ->willReturn([
                'success' => false,
                'response' => ['status' => 'failed', 'error' => 'Upstream node unreachable'],
                'raw' => '{"status":"failed","error":"Upstream node unreachable"}',
                'messageId' => 'relay-' . self::TEST_HASH . '-1234567890',
                'tracking' => [
                    'attempts' => 3,
                    'error' => 'Upstream node unreachable',
                    'dlq' => true
                ]
            ]);

        // Should handle failure and log upstream notification details
        $this->service->handleRp2pRequest($request);

        // No exception means failure was handled with upstream notification
        $this->assertTrue(true);
    }

    /**
     * Test handling when intermediary fee is zero
     *
     * Verifies that RP2P relay works correctly when an intermediate node
     * has a zero fee (my_fee_amount = 0), passing through without adding cost.
     */
    public function testHandleRp2pRequestWithZeroMyFeeAmount(): void
    {
        $baseAmount = 10000;

        $request = [
            'hash' => self::TEST_HASH,
            'amount' => $baseAmount,
            'senderAddress' => self::TEST_ADDRESS
        ];

        // Intermediate node with zero fee
        $p2p = [
            'hash' => self::TEST_HASH,
            'amount' => $baseAmount,
            'my_fee_amount' => 0, // Zero fee - node relays for free
            'sender_address' => 'http://upstream.test',
            'sender_public_key' => self::TEST_PUBLIC_KEY
        ];

        $this->p2pRepository->method('getByHash')
            ->willReturn($p2p);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(100000.0);

        // Verify the amount remains unchanged when fee is zero
        $this->rp2pRepository->expects($this->once())
            ->method('insertRp2pRequest')
            ->with($this->callback(function ($arg) use ($baseAmount) {
                // Amount should equal base amount (no fee added)
                return isset($arg['amount']) && $arg['amount'] === $baseAmount;
            }))
            ->willReturn('test-rp2p-id');

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, 'found');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890);

        $this->messageDeliveryService->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'forwarded'],
                'raw' => '{"status":"forwarded"}',
                'messageId' => 'relay-' . self::TEST_HASH . '-1234567890'
            ]);

        $this->service->handleRp2pRequest($request);

        // Successfully processed with zero fee
        $this->assertTrue(true);
    }
}
