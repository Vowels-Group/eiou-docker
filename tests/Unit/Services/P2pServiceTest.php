<?php
/**
 * Unit Tests for P2pService
 *
 * Tests P2P service functionality including:
 * - Request level checking
 * - Available funds checking
 * - P2P possibility checking
 * - P2P request handling
 * - Contact and self matching
 * - P2P request data preparation
 * - Queued message processing
 * - P2P level re-adjustment
 * - P2P request sending
 * - Statistics and status methods
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\P2pService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use InvalidArgumentException;
use PDOException;

#[CoversClass(P2pService::class)]
class P2pServiceTest extends TestCase
{
    private MockObject|ContactRepository $contactRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|P2pRepository $p2pRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|ValidationUtilityService $validationUtility;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TimeUtilityService $timeUtility;
    private MockObject|CurrencyUtilityService $currencyUtility;
    private MockObject|UserContext $userContext;
    private MockObject|MessageDeliveryService $messageDeliveryService;
    private P2pService $service;

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
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->validationUtility = $this->createMock(ValidationUtilityService::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->timeUtility = $this->createMock(TimeUtilityService::class);
        $this->currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->messageDeliveryService = $this->createMock(MessageDeliveryService::class);

        // Setup utility container
        $this->utilityContainer->method('getValidationUtility')
            ->willReturn($this->validationUtility);
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);
        $this->utilityContainer->method('getCurrencyUtility')
            ->willReturn($this->currencyUtility);
        $this->utilityContainer->method('getTimeUtility')
            ->willReturn($this->timeUtility);

        // Setup default user context
        $this->userContext->method('getDefaultFee')
            ->willReturn(1.0);
        $this->userContext->method('getMinimumFee')
            ->willReturn(10);
        $this->userContext->method('getMaxP2pLevel')
            ->willReturn(3);
        $this->userContext->method('getMaxFee')
            ->willReturn(5.0);
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $this->service = new P2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService
        );
    }

    // =========================================================================
    // setMessageDeliveryService() Tests
    // =========================================================================

    /**
     * Test setMessageDeliveryService sets the service
     */
    public function testSetMessageDeliveryServiceSetsService(): void
    {
        $newService = $this->createMock(MessageDeliveryService::class);
        $this->service->setMessageDeliveryService($newService);

        // No exception means success
        $this->assertTrue(true);
    }

    // =========================================================================
    // checkRequestLevel() Tests
    // =========================================================================

    /**
     * Test checkRequestLevel returns false for missing requestLevel
     */
    public function testCheckRequestLevelReturnsFalseForMissingRequestLevel(): void
    {
        $request = [
            'maxRequestLevel' => 5
            // Missing requestLevel
        ];

        ob_start();
        $result = $this->service->checkRequestLevel($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkRequestLevel returns false for missing maxRequestLevel
     */
    public function testCheckRequestLevelReturnsFalseForMissingMaxLevel(): void
    {
        $request = [
            'requestLevel' => 1
            // Missing maxRequestLevel
        ];

        ob_start();
        $result = $this->service->checkRequestLevel($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkRequestLevel returns true for valid request
     */
    public function testCheckRequestLevelReturnsTrueForValidRequest(): void
    {
        $request = [
            'requestLevel' => 1,
            'maxRequestLevel' => 5
        ];

        $this->validationUtility->method('validateRequestLevel')
            ->with($request)
            ->willReturn(true);

        $result = $this->service->checkRequestLevel($request);

        $this->assertTrue($result);
    }

    /**
     * Test checkRequestLevel returns false when validation fails
     */
    public function testCheckRequestLevelReturnsFalseWhenValidationFails(): void
    {
        $request = [
            'requestLevel' => 10,
            'maxRequestLevel' => 5
        ];

        $this->validationUtility->method('validateRequestLevel')
            ->willReturn(false);

        ob_start();
        $result = $this->service->checkRequestLevel($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    // =========================================================================
    // checkAvailableFunds() Tests
    // =========================================================================

    /**
     * Test checkAvailableFunds returns false for missing required fields
     */
    public function testCheckAvailableFundsReturnsFalseForMissingFields(): void
    {
        $request = [
            // Missing senderAddress and senderPublicKey
        ];

        $result = $this->service->checkAvailableFunds($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFunds returns true when user is end recipient
     */
    public function testCheckAvailableFundsReturnsTrueWhenEndRecipient(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'amount' => self::TEST_AMOUNT
        ];

        // User is the end recipient (hash matches)
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        // Mock matchYourselfP2P to return true
        $expectedHash = hash(Constants::HASH_ALGORITHM, self::TEST_ADDRESS . 'test-salt' . '1234567890');
        $request['hash'] = $expectedHash;

        $result = $this->service->checkAvailableFunds($request);

        $this->assertTrue($result);
    }

    /**
     * Test checkAvailableFunds returns false when insufficient funds
     */
    public function testCheckAvailableFundsReturnsFalseWhenInsufficientFunds(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'amount' => 100000 // Large amount
        ];

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://other.address');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(0);

        $this->p2pRepository->method('getCreditInP2p')
            ->willReturn(0);

        $this->contactRepository->method('getCreditLimit')
            ->willReturn(1000);

        $this->contactRepository->method('lookupByAddress')
            ->willReturn(['fee_percent' => 1.0]);

        $this->currencyUtility->method('calculateFee')
            ->willReturn(1000);

        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);

        $result = $this->service->checkAvailableFunds($request);

        $this->assertFalse($result);
    }

    /**
     * Test checkAvailableFunds throws PDOException on database error
     */
    public function testCheckAvailableFundsThrowsPdoException(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'amount' => self::TEST_AMOUNT
        ];

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://other.address');
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willThrowException(new PDOException('Database error'));

        $this->expectException(PDOException::class);

        $this->service->checkAvailableFunds($request);
    }

    // =========================================================================
    // calculateRequestedAmount() Tests
    // =========================================================================

    /**
     * Test calculateRequestedAmount calculates amount plus fee
     */
    public function testCalculateRequestedAmountCalculatesTotal(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'amount' => 10000
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepository->method('lookupByAddress')
            ->willReturn(['fee_percent' => 1.0]);

        $this->currencyUtility->method('calculateFee')
            ->with(10000, 1.0, 10)
            ->willReturn(100);

        $result = $this->service->calculateRequestedAmount($request);

        $this->assertEquals(10100, $result);
    }

    /**
     * Test calculateRequestedAmount uses default fee for unknown contact
     */
    public function testCalculateRequestedAmountUsesDefaultFee(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'amount' => 10000
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepository->method('lookupByAddress')
            ->willReturn(null);

        $this->currencyUtility->method('calculateFee')
            ->with(10000, 1.0, 10) // Uses default fee from userContext
            ->willReturn(100);

        $result = $this->service->calculateRequestedAmount($request);

        $this->assertEquals(10100, $result);
    }

    // =========================================================================
    // checkP2pPossible() Tests
    // =========================================================================

    /**
     * Test checkP2pPossible returns false for blocked contact
     */
    public function testCheckP2pPossibleReturnsFalseForBlockedContact(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $this->contactRepository->method('isNotBlocked')
            ->with(self::TEST_PUBLIC_KEY)
            ->willReturn(false);

        ob_start();
        $result = $this->service->checkP2pPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkP2pPossible returns false for duplicate P2P
     */
    public function testCheckP2pPossibleReturnsFalseForDuplicate(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $this->contactRepository->method('isNotBlocked')
            ->willReturn(true);
        $this->validationUtility->method('validateRequestLevel')
            ->willReturn(true);
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);

        $this->p2pRepository->method('p2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(true);

        ob_start();
        $result = $this->service->checkP2pPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkP2pPossible echoes response when echo=true
     */
    public function testCheckP2pPossibleEchosResponse(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $this->contactRepository->method('isNotBlocked')
            ->willReturn(false);

        ob_start();
        $this->service->checkP2pPossible($request, true);
        $output = ob_get_clean();

        // Should have output JSON
        $this->assertNotEmpty($output);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test checkP2pPossible does not echo when echo=false
     */
    public function testCheckP2pPossibleDoesNotEchoWhenDisabled(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $this->contactRepository->method('isNotBlocked')
            ->willReturn(false);

        ob_start();
        $this->service->checkP2pPossible($request, false);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    // =========================================================================
    // matchYourselfP2P() Tests
    // =========================================================================

    /**
     * Test matchYourselfP2P returns true when hash matches primary address
     */
    public function testMatchYourselfP2pReturnsTrueForPrimaryMatch(): void
    {
        $address = self::TEST_ADDRESS;
        $salt = 'test-salt';
        $time = '1234567890';
        $hash = hash(Constants::HASH_ALGORITHM, $address . $salt . $time);

        $request = [
            'hash' => $hash,
            'salt' => $salt,
            'time' => $time
        ];

        $result = $this->service->matchYourselfP2P($request, $address);

        $this->assertTrue($result);
    }

    /**
     * Test matchYourselfP2P returns true when hash matches alternate address
     */
    public function testMatchYourselfP2pReturnsTrueForAlternateMatch(): void
    {
        $primaryAddress = 'http://primary.test';
        $alternateAddress = 'http://alternate.test';
        $salt = 'test-salt';
        $time = '1234567890';
        $hash = hash(Constants::HASH_ALGORITHM, $alternateAddress . $salt . $time);

        $request = [
            'hash' => $hash,
            'salt' => $salt,
            'time' => $time
        ];

        $this->userContext->method('getUserLocaters')
            ->willReturn([
                'http' => $primaryAddress,
                'https' => $alternateAddress
            ]);

        $result = $this->service->matchYourselfP2P($request, $primaryAddress);

        $this->assertTrue($result);
    }

    /**
     * Test matchYourselfP2P returns false when no match
     */
    public function testMatchYourselfP2pReturnsFalseForNoMatch(): void
    {
        $request = [
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890'
        ];

        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);

        $result = $this->service->matchYourselfP2P($request, 'http://other.test');

        $this->assertFalse($result);
    }

    // =========================================================================
    // matchContact() Tests
    // =========================================================================

    /**
     * Test matchContact returns null when no contacts match
     */
    public function testMatchContactReturnsNullWhenNoMatch(): void
    {
        $request = [
            'sender_address' => self::TEST_ADDRESS,
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890'
        ];

        $this->contactRepository->method('getAllContacts')
            ->willReturn([]);
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $this->service->matchContact($request);

        $this->assertNull($result);
    }

    /**
     * Test matchContact returns contact when hash matches
     */
    public function testMatchContactReturnsContactWhenMatched(): void
    {
        $contactAddress = 'http://contact.test';
        $salt = 'test-salt';
        $time = '1234567890';
        $hash = hash(Constants::HASH_ALGORITHM, $contactAddress . $salt . $time);

        $request = [
            'sender_address' => self::TEST_ADDRESS,
            'hash' => $hash,
            'salt' => $salt,
            'time' => $time
        ];

        $contact = [
            'name' => 'Test Contact',
            'http' => $contactAddress,
            'pubkey' => self::TEST_PUBLIC_KEY
        ];

        $this->contactRepository->method('getAllContacts')
            ->willReturn([$contact]);
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $this->service->matchContact($request);

        $this->assertIsArray($result);
        $this->assertEquals('Test Contact', $result['name']);
    }

    // =========================================================================
    // prepareP2pRequestData() Tests
    // =========================================================================

    /**
     * Test prepareP2pRequestData throws for missing receiver address
     */
    public function testPrepareP2pRequestDataThrowsForMissingAddress(): void
    {
        $request = ['eiou', 'send'];  // Missing [2] which is address

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Receiver address is not set');

        $this->service->prepareP2pRequestData($request);
    }

    /**
     * Test prepareP2pRequestData throws for missing amount
     */
    public function testPrepareP2pRequestDataThrowsForMissingAmount(): void
    {
        $request = ['eiou', 'send', self::TEST_ADDRESS];  // Missing [3] which is amount

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount is required');

        $this->service->prepareP2pRequestData($request);
    }

    /**
     * Test prepareP2pRequestData throws for invalid amount
     */
    public function testPrepareP2pRequestDataThrowsForInvalidAmount(): void
    {
        $request = ['eiou', 'send', self::TEST_ADDRESS, '-100'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid amount');

        $this->service->prepareP2pRequestData($request);
    }

    /**
     * Test prepareP2pRequestData returns valid data structure
     */
    public function testPrepareP2pRequestDataReturnsValidStructure(): void
    {
        $request = ['eiou', 'send', self::TEST_ADDRESS, '100.00'];

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn('1234567890123456');

        $result = $this->service->prepareP2pRequestData($request);

        $this->assertArrayHasKey('txType', $result);
        $this->assertArrayHasKey('receiverAddress', $result);
        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('salt', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('minRequestLevel', $result);
        $this->assertArrayHasKey('maxRequestLevel', $result);

        $this->assertEquals('p2p', $result['txType']);
        $this->assertEquals(self::TEST_ADDRESS, $result['receiverAddress']);
        $this->assertEquals(10000, $result['amount']); // 100.00 * 100
    }

    // =========================================================================
    // reAdjustP2pLevel() Tests
    // =========================================================================

    /**
     * Test reAdjustP2pLevel adjusts level based on user config
     */
    public function testReAdjustP2pLevelAdjustsLevel(): void
    {
        $request = [
            'requestLevel' => 1,
            'maxRequestLevel' => 10
        ];

        // maxP2pLevel = 3 (from setUp)
        // requestLevel + maxP2pLevel = 1 + 3 = 4
        // Since 10 > 4, should return 4

        $result = $this->service->reAdjustP2pLevel($request);

        $this->assertEquals(4, $result);
    }

    /**
     * Test reAdjustP2pLevel keeps level when lower
     */
    public function testReAdjustP2pLevelKeepsLowerLevel(): void
    {
        $request = [
            'requestLevel' => 1,
            'maxRequestLevel' => 3
        ];

        // maxP2pLevel = 3
        // requestLevel + maxP2pLevel = 1 + 3 = 4
        // Since 3 < 4, should return 3

        $result = $this->service->reAdjustP2pLevel($request);

        $this->assertEquals(3, $result);
    }

    // =========================================================================
    // processQueuedP2pMessages() Tests
    // =========================================================================

    /**
     * Test processQueuedP2pMessages returns 0 for empty queue
     */
    public function testProcessQueuedP2pMessagesReturnsZeroForEmptyQueue(): void
    {
        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([]);

        $result = $this->service->processQueuedP2pMessages();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Delegation Methods Tests
    // =========================================================================

    /**
     * Test getByHash delegates to repository
     */
    public function testGetByHashDelegatesToRepository(): void
    {
        $expected = ['hash' => self::TEST_HASH, 'status' => 'sent'];

        $this->p2pRepository->expects($this->once())
            ->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn($expected);

        $result = $this->service->getByHash(self::TEST_HASH);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test updateStatus delegates to repository
     */
    public function testUpdateStatusDelegatesToRepository(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_COMPLETED, true)
            ->willReturn(true);

        $result = $this->service->updateStatus(self::TEST_HASH, Constants::STATUS_COMPLETED, true);

        $this->assertTrue($result);
    }

    /**
     * Test updateIncomingTxid delegates to repository
     */
    public function testUpdateIncomingTxidDelegatesToRepository(): void
    {
        $txid = 'incoming-txid';

        $this->p2pRepository->expects($this->once())
            ->method('updateIncomingTxid')
            ->with(self::TEST_HASH, $txid)
            ->willReturn(true);

        $result = $this->service->updateIncomingTxid(self::TEST_HASH, $txid);

        $this->assertTrue($result);
    }

    /**
     * Test updateOutgoingTxid delegates to repository
     */
    public function testUpdateOutgoingTxidDelegatesToRepository(): void
    {
        $txid = 'outgoing-txid';

        $this->p2pRepository->expects($this->once())
            ->method('updateOutgoingTxid')
            ->with(self::TEST_HASH, $txid)
            ->willReturn(true);

        $result = $this->service->updateOutgoingTxid(self::TEST_HASH, $txid);

        $this->assertTrue($result);
    }

    /**
     * Test getCreditInP2p delegates to repository
     */
    public function testGetCreditInP2pDelegatesToRepository(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('getCreditInP2p')
            ->with(self::TEST_PUBLIC_KEY)
            ->willReturn(5000.0);

        $result = $this->service->getCreditInP2p(self::TEST_PUBLIC_KEY);

        $this->assertEquals(5000.0, $result);
    }

    /**
     * Test getUserTotalEarnings delegates to repository
     */
    public function testGetUserTotalEarningsDelegatesToRepository(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('getUserTotalEarnings')
            ->willReturn('1234.56');

        $result = $this->service->getUserTotalEarnings();

        $this->assertEquals('1234.56', $result);
    }

    /**
     * Test getStatistics delegates to repository
     */
    public function testGetStatisticsDelegatesToRepository(): void
    {
        $expected = [
            'total' => 100,
            'completed' => 80,
            'pending' => 15,
            'cancelled' => 5
        ];

        $this->p2pRepository->expects($this->once())
            ->method('getStatistics')
            ->willReturn($expected);

        $result = $this->service->getStatistics();

        $this->assertEquals($expected, $result);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor with null message delivery service
     */
    public function testConstructorWithNullMessageDeliveryService(): void
    {
        $service = new P2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            null
        );

        $this->assertInstanceOf(P2pService::class, $service);
    }

    /**
     * Test constructor initializes payload builders
     */
    public function testConstructorInitializesPayloads(): void
    {
        $service = new P2pService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext
        );

        $this->assertInstanceOf(P2pService::class, $service);
    }

    // =========================================================================
    // sendP2pRequest() Tests
    // =========================================================================

    /**
     * Test sendP2pRequest throws for invalid address with no matching contact
     */
    public function testSendP2pRequestThrowsForInvalidAddress(): void
    {
        $data = ['eiou', 'send', 'not-an-address', '100.00'];

        $this->transportUtility->method('isAddress')
            ->with('not-an-address')
            ->willReturn(false);

        $this->contactRepository->method('lookupAddressesByName')
            ->with('not-an-address')
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not an address nor existing contact');

        $this->service->sendP2pRequest($data);
    }

    /**
     * Test sendP2pRequest resolves contact name to address
     */
    public function testSendP2pRequestResolvesContactName(): void
    {
        $data = ['eiou', 'send', 'ContactName', '100.00'];

        $this->transportUtility->method('isAddress')
            ->willReturn(false);

        $this->contactRepository->method('lookupAddressesByName')
            ->with('ContactName')
            ->willReturn(['http' => self::TEST_ADDRESS]);

        $this->transportUtility->method('fallbackTransportAddress')
            ->willReturn(self::TEST_ADDRESS);

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn('1234567890123456');

        $this->transportUtility->method('jitter')
            ->willReturnCallback(fn($val) => $val);

        $this->p2pRepository->expects($this->once())
            ->method('insertP2pRequest');

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus');

        $this->service->sendP2pRequest($data);

        // No exception means success
        $this->assertTrue(true);
    }
}
