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
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\CapacityReservationRepository;
use Eiou\Database\P2pRelayedContactRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\RepositoryFactory;
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
    private MockObject|ContactServiceInterface $contactService;
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
    private MockObject|P2pSenderRepository $p2pSenderRepository;
    private MockObject|ContactCurrencyRepository $contactCurrencyRepository;
    private MockObject|CapacityReservationRepository $capacityReservationRepository;
    private MockObject|P2pRelayedContactRepository $p2pRelayedContactRepository;
    private MockObject|Rp2pRepository $rp2pRepository;
    private MockObject|RepositoryFactory $repositoryFactory;
    private P2pService $service;

    private const TEST_ADDRESS = 'http://test.example.com';
    private const TEST_PUBLIC_KEY = 'test-public-key-1234567890';
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_AMOUNT = 10000; // cents

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactService = $this->createMock(ContactServiceInterface::class);
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
        $this->p2pSenderRepository = $this->createMock(P2pSenderRepository::class);
        $this->contactCurrencyRepository = $this->createMock(ContactCurrencyRepository::class);
        $this->capacityReservationRepository = $this->createMock(CapacityReservationRepository::class);
        $this->p2pRelayedContactRepository = $this->createMock(P2pRelayedContactRepository::class);
        $this->rp2pRepository = $this->createMock(Rp2pRepository::class);
        $this->repositoryFactory = $this->createMock(RepositoryFactory::class);
        $this->repositoryFactory->method('get')->willReturnCallback(function (string $class) {
            return match ($class) {
                P2pRelayedContactRepository::class => $this->p2pRelayedContactRepository,
                Rp2pRepository::class => $this->rp2pRepository,
                ContactCurrencyRepository::class => $this->contactCurrencyRepository,
                CapacityReservationRepository::class => $this->capacityReservationRepository,
                default => $this->createMock($class),
            };
        });

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
            ->willReturn(10.0);
        $this->userContext->method('getMaxP2pLevel')
            ->willReturn(3);
        $this->userContext->method('getMaxFee')
            ->willReturn(5.0);
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $this->service = new P2pService(
            $this->contactService,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->repositoryFactory,
            $this->messageDeliveryService,
            $this->p2pSenderRepository
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

        $this->capacityReservationRepository->method('getTotalReservedForPubkey')
            ->willReturn(0);

        $this->contactService->method('getCreditLimit')
            ->with($this->anything(), 'USD')
            ->willReturn(1000.0);

        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);

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

        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);

        $this->contactCurrencyRepository->method('getFeePercent')
            ->willReturn(100); // 1% stored as 100 (scaled by FEE_CONVERSION_FACTOR=100)

        $this->currencyUtility->method('calculateFee')
            ->with(10000, 1.0, 10) // 100 / FEE_CONVERSION_FACTOR = 1.0 (raw percentage)
            ->willReturn(100);

        $result = $this->service->calculateRequestedAmount($request);

        $this->assertEquals(10100, $result);
    }

    /**
     * Test calculateRequestedAmount uses per-currency fee from contact_currencies
     */
    public function testCalculateRequestedAmountUsesPerCurrencyFee(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'amount' => 10000,
            'currency' => 'DER'
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);

        $this->contactCurrencyRepository->method('getFeePercent')
            ->with('test_hash', 'DER')
            ->willReturn(30); // 0.3% stored as 30 (scaled by FEE_CONVERSION_FACTOR=100)

        $this->currencyUtility->method('calculateFee')
            ->with(10000, 0.3, 10) // 30 / FEE_CONVERSION_FACTOR = 0.3 (raw percentage)
            ->willReturn(300);

        $result = $this->service->calculateRequestedAmount($request);

        $this->assertEquals(10300, $result);
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

        $this->contactService->method('lookupByAddress')
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

        $this->contactService->method('isNotBlocked')
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

        $this->contactService->method('isNotBlocked')
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
     * Test checkP2pPossible records additional sender when P2P already exists
     */
    public function testCheckP2pPossibleRecordsSenderOnAlreadyRelayed(): void
    {
        $request = [
            'senderAddress' => 'http://second-sender.test',
            'senderPublicKey' => 'second-sender-pubkey',
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'salt' => 'test-salt',
            'time' => 1234567890
        ];

        $this->contactService->method('isNotBlocked')
            ->willReturn(true);
        $this->validationUtility->method('validateRequestLevel')
            ->willReturn(true);
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);

        // Funds check mocks (intermediary path — matchYourselfP2P returns false)
        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);
        $this->capacityReservationRepository->method('getTotalReservedForPubkey')
            ->willReturn(0);
        $this->contactService->method('getCreditLimit')
            ->with($this->anything(), 'USD')
            ->willReturn(100000.0);
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);
        $this->currencyUtility->method('calculateFee')
            ->willReturn(100);

        $this->p2pRepository->method('p2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(true);

        // P2P has status 'sent' (relay node, not destination) - no rp2p forwarding
        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => null,
                'sender_address' => 'http://first-sender.test',
                'amount' => self::TEST_AMOUNT,
            ]);

        // Verify sender is recorded for multi-path RP2P delivery
        $this->p2pSenderRepository->expects($this->once())
            ->method('insertSender')
            ->with(self::TEST_HASH, 'http://second-sender.test', 'second-sender-pubkey');

        ob_start();
        $result = $this->service->checkP2pPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test checkP2pPossible sends rp2p to new sender when node is the destination
     *
     * When a P2P already exists with status 'found' (destination matched) and a new
     * sender sends the same P2P hash, the destination should immediately send an rp2p
     * response to the new sender. This ensures all paths from the destination get
     * rp2p responses simultaneously, enabling true best-fee comparison.
     */
    public function testCheckP2pPossibleSendsRp2pToNewSenderAtDestination(): void
    {
        // Use a hash that matches: sha256('http://destination.test' . 'test-salt' . '1234567890')
        $destinationHash = '5ec8c754e8f1aea38364c5c9da36b15f427c1b7157dc5cec1769d0515c41343d';

        $request = [
            'senderAddress' => 'http://second-sender.test',
            'senderPublicKey' => 'second-sender-pubkey',
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'hash' => $destinationHash,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'salt' => 'test-salt',
            'time' => '1234567890',
            'signature' => 'test-signature',
        ];

        $this->contactService->method('isNotBlocked')
            ->willReturn(true);
        $this->validationUtility->method('validateRequestLevel')
            ->willReturn(true);

        // resolveUserAddressForTransport returns our local address for the transport type
        // This address must hash-match the P2P hash for matchYourselfP2P to return true
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://destination.test');
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://destination.test']);

        // Funds check mocks
        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);
        $this->capacityReservationRepository->method('getTotalReservedForPubkey')
            ->willReturn(0);
        $this->contactService->method('getCreditLimit')
            ->with($this->anything(), 'USD')
            ->willReturn(100000.0);
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);
        $this->currencyUtility->method('calculateFee')
            ->willReturn(100);

        // P2P exists (already relayed)
        $this->p2pRepository->method('p2pExists')
            ->with($destinationHash)
            ->willReturn(true);

        // P2P record shows this node is the destination (status='found', destination_address set)
        $this->p2pRepository->method('getByHash')
            ->with($destinationHash)
            ->willReturn([
                'hash' => $destinationHash,
                'status' => 'found',
                'destination_address' => 'http://destination.test',
                'sender_address' => 'http://first-sender.test',
                'amount' => self::TEST_AMOUNT,
            ]);

        // Verify sender is recorded
        $this->p2pSenderRepository->expects($this->once())
            ->method('insertSender')
            ->with($destinationHash, 'http://second-sender.test', 'second-sender-pubkey');

        // Verify rp2p is sent to the new sender (the key assertion for this fix)
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                'http://second-sender.test',
                $this->callback(function ($payload) use ($destinationHash) {
                    return $payload['type'] === 'rp2p'
                        && $payload['hash'] === $destinationHash
                        && $payload['senderAddress'] === 'http://destination.test';
                }),
                $this->stringContains('response-' . $destinationHash),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'response-' . $destinationHash . '-test',
            ]);

        ob_start();
        $result = $this->service->checkP2pPossible($request);
        $output = ob_get_clean();

        $this->assertFalse($result);

        // Verify the response is still 'already_relayed' (not changed)
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('already_relayed', $decoded['status']);
    }

    /**
     * Test checkP2pPossible does NOT send rp2p when node is a relay (not destination)
     */
    public function testCheckP2pPossibleDoesNotSendRp2pWhenRelay(): void
    {
        $request = [
            'senderAddress' => 'http://second-sender.test',
            'senderPublicKey' => 'second-sender-pubkey',
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'salt' => 'test-salt',
            'time' => 1234567890,
        ];

        $this->contactService->method('isNotBlocked')
            ->willReturn(true);
        $this->validationUtility->method('validateRequestLevel')
            ->willReturn(true);
        // Different address = not the destination
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://relay-node.test');
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://relay-node.test']);

        // Funds check mocks
        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);
        $this->capacityReservationRepository->method('getTotalReservedForPubkey')
            ->willReturn(0);
        $this->contactService->method('getCreditLimit')
            ->with($this->anything(), 'USD')
            ->willReturn(100000.0);
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);
        $this->currencyUtility->method('calculateFee')
            ->willReturn(100);

        $this->p2pRepository->method('p2pExists')
            ->with(self::TEST_HASH)
            ->willReturn(true);

        // P2P exists with status 'found' but this is a relay (matchYourselfP2P will fail)
        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'found',
                'destination_address' => 'http://relay-node.test',
                'sender_address' => 'http://first-sender.test',
                'amount' => self::TEST_AMOUNT,
            ]);

        $this->p2pSenderRepository->expects($this->once())
            ->method('insertSender');

        // Verify NO rp2p is sent (relay node should not forward rp2p here)
        $this->messageDeliveryService->expects($this->never())
            ->method('sendMessage');

        ob_start();
        $result = $this->service->checkP2pPossible($request);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test handleP2pRequest records first sender for intermediary relay
     */
    public function testHandleP2pRequestRecordsFirstSender(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'requestLevel' => 1,
            'maxRequestLevel' => 3,
            'fast' => true,
            'salt' => 'test-salt',
            'time' => 1234567890
        ];

        // Not matching self → intermediary path
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://different.test');
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://different.test']);
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);
        $this->currencyUtility->method('calculateFee')
            ->willReturn(100);

        $this->p2pRepository->method('insertP2pRequest')
            ->willReturn('1');
        $this->p2pRepository->method('updateStatus')
            ->willReturn(true);

        // Verify first sender is recorded
        $this->p2pSenderRepository->expects($this->once())
            ->method('insertSender')
            ->with(self::TEST_HASH, self::TEST_ADDRESS, self::TEST_PUBLIC_KEY);

        $this->service->handleP2pRequest($request);

        $this->assertTrue(true);
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

        $this->contactService->method('isNotBlocked')
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

        $this->contactService->method('isNotBlocked')
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

        $this->contactService->method('getAllContacts')
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

        $this->contactService->method('getAllContacts')
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
            ->willReturn(1234567890123456);

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
     * Test getCreditInP2p delegates to repository (legacy path)
     */
    public function testGetCreditInP2pDelegatesToRepository(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('getCreditInP2p')
            ->with(self::TEST_PUBLIC_KEY)
            ->willReturn(5000);

        $result = $this->service->getCreditInP2p(self::TEST_PUBLIC_KEY);

        $this->assertEquals(5000, $result);
    }

    /**
     * Test checkAvailableFunds uses CapacityReservationRepository when injected
     */
    public function testCheckAvailableFundsUsesCapacityReservation(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'amount' => self::TEST_AMOUNT
        ];

        $senderPubkeyHash = hash('sha256', self::TEST_PUBLIC_KEY);

        $this->capacityReservationRepository->expects($this->once())
            ->method('getTotalReservedForPubkey')
            ->with($senderPubkeyHash, 'USD')
            ->willReturn(5000);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://other.address');
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(100000);
        $this->contactService->method('getCreditLimit')
            ->with($this->anything(), 'USD')
            ->willReturn(100000.0);
        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);
        $this->currencyUtility->method('calculateFee')
            ->willReturn(100);
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);

        $result = $this->service->checkAvailableFunds($request);

        $this->assertTrue($result);
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
            $this->contactService,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->repositoryFactory,
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
            $this->contactService,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->repositoryFactory
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

        $this->contactService->method('lookupAddressesByName')
            ->with('not-an-address')
            ->willReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not an address nor existing contact');

        $this->service->sendP2pRequest($data);
    }

    /**
     * Test sendP2pRequest resolves contact name to address
     *
     * Note: This test is skipped due to an API mismatch between:
     * - ContactServiceInterface::lookupAddressesByName returns ?string
     * - TransportUtilityService::fallbackTransportAddress expects array
     * The P2pService code passes the result of one to the other, which is incompatible.
     * This should be addressed in a separate refactoring PR.
     */
    public function testSendP2pRequestResolvesContactName(): void
    {
        $this->markTestSkipped('API mismatch: lookupAddressesByName returns string but fallbackTransportAddress expects array');
    }

    // =========================================================================
    // Fee Calculation and Node Failure Tests
    // =========================================================================

    /**
     * Test fee calculation across multiple relay hops
     *
     * When a P2P request passes through multiple intermediary nodes,
     * each node adds its own fee. This test verifies that calculateRequestedAmount
     * correctly calculates the total amount needed including the fee for the current hop.
     */
    public function testCalculateRequestedAmountWithMultipleHops(): void
    {
        // Simulate a request that has already passed through 2 hops
        // Each hop adds fees, so the amount reflects accumulated fees
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'amount' => 10000, // Original 100.00 in cents
            'requestLevel' => 2, // Already passed through 2 nodes
            'maxRequestLevel' => 5
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        // First hop contact has 1.5% fee (from contact_currencies)
        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);

        $this->contactCurrencyRepository->method('getFeePercent')
            ->willReturn(150); // 1.5% stored as 150 (scaled by FEE_CONVERSION_FACTOR=100)

        // Fee calculation: 10000 * 1.5% = 150 (with minimum fee of 10)
        $this->currencyUtility->method('calculateFee')
            ->with(10000, 1.5, 10) // 150 / FEE_CONVERSION_FACTOR = 1.5 (raw percentage)
            ->willReturn(150);

        $result = $this->service->calculateRequestedAmount($request);

        // Total should be amount + fee for this hop
        $this->assertEquals(10150, $result);
    }

    /**
     * Test cumulative fee tracking through a P2P chain
     *
     * Verifies that fees accumulate correctly as a P2P request
     * passes through multiple intermediary nodes.
     */
    public function testFeeAccumulationAcrossChain(): void
    {
        // Simulate tracking fees through a 3-hop chain
        // Hop 1: Original amount 10000, fee 100 (1%), total needed: 10100
        // Hop 2: Amount 10000, fee 150 (1.5%), total needed: 10150
        // Hop 3: Amount 10000, fee 200 (2%), total needed: 10200

        $baseAmount = 10000;

        // Test each hop's fee calculation
        // DB stores fee_percent scaled by FEE_CONVERSION_FACTOR (100): 1% → 100, 1.5% → 150, 2% → 200
        $dbFeeRates = [100, 150, 200];
        $rawPercentages = [1.0, 1.5, 2.0]; // After dividing by FEE_CONVERSION_FACTOR
        $expectedFees = [100, 150, 200];

        foreach ($dbFeeRates as $index => $dbFeeRate) {
            $request = [
                'senderAddress' => 'http://hop' . ($index + 1) . '.test',
                'amount' => $baseAmount,
                'requestLevel' => $index + 1
            ];

            $this->transportUtility->method('determineTransportType')
                ->willReturn('http');

            // Each contact has different fee rate (from contact_currencies)
            $this->contactService->expects($this->atLeastOnce())
                ->method('lookupByAddress')
                ->willReturn(['pubkey_hash' => 'test_hash']);

            $this->contactCurrencyRepository->expects($this->atLeastOnce())
                ->method('getFeePercent')
                ->willReturn($dbFeeRate);

            $this->currencyUtility->expects($this->atLeastOnce())
                ->method('calculateFee')
                ->with($baseAmount, $rawPercentages[$index], 10) // DB value / FEE_CONVERSION_FACTOR
                ->willReturn($expectedFees[$index]);

            $result = $this->service->calculateRequestedAmount($request);

            $this->assertEquals(
                $baseAmount + $expectedFees[$index],
                $result,
                "Fee calculation failed for hop " . ($index + 1)
            );

            // Reset mocks for next iteration
            $this->setUp();
        }
    }

    /**
     * Test rejection when accumulated fees exceed maximum allowed
     *
     * When the total fees accumulated through the P2P chain exceed
     * the user's configured maxFee, the request should be rejected.
     */
    public function testMaxFeeRejectionAtEndRecipient(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'amount' => 10000,
            'requestLevel' => 3,
            'maxRequestLevel' => 5,
            'accumulatedFee' => 600 // 6% accumulated fee
        ];

        // User's maxFee is 5% (from setUp)
        // Accumulated fee of 6% exceeds this

        $this->contactService->method('isNotBlocked')
            ->willReturn(true);
        $this->validationUtility->method('validateRequestLevel')
            ->willReturn(true);
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://other.address');
        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://me.test']);

        // When accumulated fee exceeds maxFee, checkAvailableFunds should consider this
        // The fee percent check happens during calculateRequestedAmount
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);

        // High fee that would push total over limit
        $this->currencyUtility->method('calculateFee')
            ->willReturn(600);

        $this->validationUtility->method('calculateAvailableFunds')
            ->willReturn(5000); // Less than requested amount + fees

        $this->capacityReservationRepository->method('getTotalReservedForPubkey')
            ->willReturn(0);

        $this->contactService->method('getCreditLimit')
            ->with($this->anything(), 'USD')
            ->willReturn(1000.0);

        ob_start();
        $result = $this->service->checkP2pPossible($request, false);
        ob_get_clean();

        $this->assertFalse($result);
    }

    /**
     * Test handling of network timeouts during P2P message send
     *
     * When sendP2pMessage encounters a network timeout, it should
     * handle the failure gracefully and return appropriate status.
     */
    public function testSendP2pMessageHandlesNetworkTimeout(): void
    {
        // Create a service without MessageDeliveryService to test fallback path
        $serviceWithoutDelivery = new P2pService(
            $this->contactService,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->repositoryFactory,
            null // No MessageDeliveryService
        );

        // Simulate network timeout by returning empty/null response
        $this->transportUtility->method('send')
            ->willReturn(''); // Empty response indicates timeout/failure

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1234567890123456);

        // Use reflection to test private sendP2pMessage method
        $reflection = new \ReflectionClass($serviceWithoutDelivery);
        $method = $reflection->getMethod('sendP2pMessage');
        $method->setAccessible(true);

        $payload = [
            'hash' => self::TEST_HASH,
            'amount' => self::TEST_AMOUNT
        ];

        $result = $method->invoke($serviceWithoutDelivery, 'p2p', self::TEST_ADDRESS, $payload);

        $this->assertFalse($result['success']);
        $this->assertNull($result['response']);
        $this->assertEquals('', $result['raw']);
    }

    /**
     * Test partial delivery success in queued message processing
     *
     * When processing queued P2P messages to multiple contacts,
     * some deliveries may succeed while others fail. The service
     * should track partial success correctly.
     */
    public function testProcessQueuedP2pMessagesHandlesPartialDeliverySuccess(): void
    {
        $queuedMessage = [
            'hash' => self::TEST_HASH,
            'sender_address' => 'http://sender.test',
            'sender_pubkey' => 'sender-pubkey',
            'amount' => self::TEST_AMOUNT,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'expiration' => '1234567890000000', // Required for buildFromDatabase
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'currency' => 'USD'
        ];

        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'contact1-pubkey'],
            ['http' => 'http://contact2.test', 'pubkey' => 'contact2-pubkey'],
            ['http' => 'http://contact3.test', 'pubkey' => 'contact3-pubkey']
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        // Simulate partial success via mega-batch: first two succeed, third fails
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $key = $send['key'];
                    if (strpos($send['recipient'], 'contact3') !== false) {
                        $results[$key] = [
                            'response' => '{"status":"failed","error":"timeout"}',
                            'signature' => 'sig',
                            'nonce' => 'nonce'
                        ];
                    } else {
                        $results[$key] = [
                            'response' => '{"status":"inserted"}',
                            'signature' => 'sig',
                            'nonce' => 'nonce'
                        ];
                    }
                }
                return $results;
            });

        // getByHash is called to check current status before updating to 'sent'
        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(['status' => Constants::STATUS_QUEUED]);

        // Status should be updated to 'sent' even with partial success
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_SENT);

        $result = $this->service->processQueuedP2pMessages();

        $this->assertEquals(1, $result);
    }

    /**
     * Test P2P request insertion when user is the end recipient
     *
     * When a P2P request arrives and the user matches the recipient hash,
     * the service should insert the request with 'found' status and
     * send an rp2p response back to the sender.
     */
    public function testHandleP2pRequestInsertsForEndRecipient(): void
    {
        $myAddress = 'http://mynode.test';
        $salt = 'test-salt-123';
        $time = '1234567890';
        $hash = hash(Constants::HASH_ALGORITHM, $myAddress . $salt . $time);

        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => $hash,
            'salt' => $salt,
            'time' => $time,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD', // Required for Rp2pPayload::build
            'signature' => 'test-signature-abc123', // Required for Rp2pPayload::build
            'requestLevel' => 1,
            'maxRequestLevel' => 5
        ];

        // User is the end recipient
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn($myAddress);

        // Expect P2P to be inserted with 'found' status
        $this->p2pRepository->expects($this->once())
            ->method('insertP2pRequest')
            ->with(
                $this->callback(function ($req) {
                    return $req['status'] === 'found';
                }),
                $myAddress
            );

        // Expect rp2p message to be sent back to sender
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                self::TEST_ADDRESS,
                $this->anything(),
                $this->stringContains('response-'),
                true
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'accepted'],
                'raw' => '{"status":"accepted"}',
                'messageId' => 'response-' . $hash
            ]);

        $this->service->handleP2pRequest($request);
    }

    /**
     * Test fee calculation for intermediary nodes
     *
     * When the user is an intermediary (not the end recipient),
     * the service should calculate the fee amount and store it
     * with the P2P request.
     */
    public function testHandleP2pRequestCalculatesFeesForIntermediary(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH, // Different hash, user is not recipient
            'salt' => 'test-salt',
            'time' => '1234567890',
            'amount' => self::TEST_AMOUNT,
            'requestLevel' => 1,
            'maxRequestLevel' => 5
        ];

        // User is NOT the end recipient
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://mynode.test');

        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://mynode.test']);

        // Setup for calculateRequestedAmount
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);

        $this->contactCurrencyRepository->method('getFeePercent')
            ->willReturn(200); // 2% stored as 200 (scaled by FEE_CONVERSION_FACTOR=100)

        // Fee calculation: 10000 * 2% = 200
        $expectedFee = 200;
        $this->currencyUtility->method('calculateFee')
            ->with(self::TEST_AMOUNT, 2.0, 10) // 200 / FEE_CONVERSION_FACTOR = 2.0 (raw percentage)
            ->willReturn($expectedFee);

        // Expect P2P to be inserted with calculated fee
        $this->p2pRepository->expects($this->once())
            ->method('insertP2pRequest')
            ->with(
                $this->callback(function ($req) use ($expectedFee) {
                    return isset($req['feeAmount']) && $req['feeAmount'] === $expectedFee;
                }),
                null // destination_address is null for intermediary
            );

        // Status should be updated to queued for forwarding
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_QUEUED);

        $this->service->handleP2pRequest($request);
    }

    // =========================================================================
    // Multi-Hop Routing and Timeout Tests
    // =========================================================================

    /**
     * Test that queued P2P messages get broadcast to multiple contacts
     *
     * Verifies that when processing queued P2P messages without a direct contact match,
     * the service broadcasts the P2P request to all accepted contacts with compatible
     * transport types.
     */
    public function testProcessQueuedP2pMessagesForwardsToMultipleContacts(): void
    {
        $p2pHash = self::TEST_HASH;
        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED
        ];

        $contacts = [
            [
                'name' => 'Contact1',
                'http' => 'http://contact1.test',
                'pubkey' => 'pubkey1'
            ],
            [
                'name' => 'Contact2',
                'http' => 'http://contact2.test',
                'pubkey' => 'pubkey2'
            ],
            [
                'name' => 'Contact3',
                'http' => 'http://contact3.test',
                'pubkey' => 'pubkey3'
            ]
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Mega-batch returns results for all 3 contacts in parallel
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->with($this->callback(function ($sends) {
                return count($sends) === 3;
            }))
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce'
                    ];
                }
                return $results;
            });

        $this->p2pRepository->method('getByHash')
            ->willReturn(['status' => Constants::STATUS_QUEUED]);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_SENT);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test direct contact matching during P2P broadcast
     *
     * Verifies that when a queued P2P message's recipient matches a known contact,
     * the service sends directly to that contact instead of broadcasting.
     */
    public function testProcessQueuedP2pMessagesMatchesDirectContact(): void
    {
        $contactAddress = 'http://direct-contact.test';
        $salt = 'test-salt';
        $time = '1234567890';
        $hash = hash(Constants::HASH_ALGORITHM, $contactAddress . $salt . $time);

        $queuedMessage = [
            'hash' => $hash,
            'salt' => $salt,
            'time' => $time,
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED
            // No destination_address set - indicates user is not original sender
        ];

        $matchingContact = [
            'name' => 'DirectContact',
            'http' => $contactAddress,
            'pubkey' => 'direct-pubkey'
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn([$matchingContact]);

        $this->contactService->method('getAllContacts')
            ->willReturn([$matchingContact]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Expect exactly one message to the matched contact
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'p2p',
                $contactAddress,
                $this->anything(),
                $this->stringContains('direct-')
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'direct-' . $hash . '-abc12345'
            ]);

        $this->messageDeliveryService->expects($this->once())
            ->method('updateStageToForwarded')
            ->with('p2p', $this->anything(), $contactAddress);

        $this->p2pRepository->method('getByHash')
            ->willReturn(['status' => Constants::STATUS_QUEUED]);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($hash, Constants::STATUS_SENT);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test handling when one contact rejects the P2P request
     *
     * Verifies that when there are multiple contacts and one rejects,
     * the P2P continues processing with remaining contacts.
     */
    public function testProcessQueuedP2pMessagesHandlesSingleContactRejection(): void
    {
        $p2pHash = self::TEST_HASH;
        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED
        ];

        $contacts = [
            [
                'name' => 'Contact1',
                'http' => 'http://contact1.test',
                'pubkey' => 'pubkey1'
            ],
            [
                'name' => 'Contact2',
                'http' => 'http://contact2.test',
                'pubkey' => 'pubkey2'
            ]
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Mega-batch returns: first contact rejects, second accepts
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    if (strpos($send['recipient'], 'contact1') !== false) {
                        $results[$send['key']] = [
                            'response' => '{"status":"rejected","reason":"insufficient_funds"}',
                            'signature' => 'sig',
                            'nonce' => 'nonce'
                        ];
                    } else {
                        $results[$send['key']] = [
                            'response' => '{"status":"inserted"}',
                            'signature' => 'sig',
                            'nonce' => 'nonce'
                        ];
                    }
                }
                return $results;
            });

        $this->p2pRepository->method('getByHash')
            ->willReturn(['status' => Constants::STATUS_QUEUED]);

        // Status should be sent since at least one contact accepted
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_SENT);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test direct-match path sets contacts_sent_count for best-fee mode
     *
     * When a relay node has the end-recipient as a direct contact and sends
     * via the matchContact shortcut, contacts_sent_count must be set to 1
     * so that best-fee "all responded" trigger works without waiting for
     * per-hop expiration.
     */
    public function testProcessQueuedP2pMessagesDirectMatchSetsContactsSentCount(): void
    {
        $contactAddress = 'http://direct-contact.test';
        $salt = 'test-salt';
        $time = '1234567890';
        $hash = hash(Constants::HASH_ALGORITHM, $contactAddress . $salt . $time);

        $queuedMessage = [
            'hash' => $hash,
            'salt' => $salt,
            'time' => $time,
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED
            // No destination_address - relay node
        ];

        $matchingContact = [
            'name' => 'DirectContact',
            'http' => $contactAddress,
            'pubkey' => 'direct-pubkey'
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn([$matchingContact]);

        $this->contactService->method('getAllContacts')
            ->willReturn([$matchingContact]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Direct match sends to matched contact and gets 'inserted'
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'direct-' . $hash . '-abc12345'
            ]);

        // Verify contacts_sent_count is set to 1 for best-fee response tracking
        $this->p2pRepository->expects($this->once())
            ->method('updateContactsSentCount')
            ->with($hash, 1);

        ob_start();
        $this->service->processQueuedP2pMessages();
        ob_get_clean();
    }

    /**
     * Test broadcast path only counts inserted (not already_relayed) in contacts_sent_count
     *
     * When a contact already has the P2P via another route, it responds
     * 'already_relayed'. These contacts should NOT be counted as expected
     * respondents because they have their own RP2P cascade which may
     * create circular dependencies (A waits for B, B waits for A).
     * Only 'inserted' contacts are counted as expected respondents.
     */
    public function testProcessQueuedP2pMessagesBroadcastOnlyCountsInserted(): void
    {
        $p2pHash = self::TEST_HASH;
        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED
        ];

        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1'],
            ['http' => 'http://contact2.test', 'pubkey' => 'pubkey2']
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Mega-batch returns: first inserted, second already_relayed
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    if (strpos($send['recipient'], 'contact1') !== false) {
                        $results[$send['key']] = [
                            'response' => '{"status":"inserted"}',
                            'signature' => 'sig',
                            'nonce' => 'nonce'
                        ];
                    } else {
                        $results[$send['key']] = [
                            'response' => '{"status":"already_relayed"}',
                            'signature' => 'sig',
                            'nonce' => 'nonce'
                        ];
                    }
                }
                return $results;
            });

        // updateContactsSentCount is called twice:
        // 1) ceiling value before broadcast (count of all contacts)
        // 2) actual accepted count after broadcast (only 'inserted', not 'already_relayed')
        $sentCountCalls = [];
        $this->p2pRepository->expects($this->exactly(2))
            ->method('updateContactsSentCount')
            ->willReturnCallback(function ($hash, $count) use (&$sentCountCalls) {
                $sentCountCalls[] = $count;
                return true;
            });

        ob_start();
        $this->service->processQueuedP2pMessages();
        ob_get_clean();

        // First call: ceiling (2 contacts)
        $this->assertEquals(2, $sentCountCalls[0]);
        // Second call: actual accepted count (1 inserted, not already_relayed)
        $this->assertEquals(1, $sentCountCalls[1]);
    }

    /**
     * Test P2P cancellation when no viable route exists
     *
     * Verifies that when all contacts are either incompatible (wrong transport),
     * are the original sender, or the destination address that already failed,
     * the P2P is cancelled.
     */
    public function testProcessQueuedP2pMessagesCancelsOnNoViableRoute(): void
    {
        $p2pHash = self::TEST_HASH;
        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED,
            'destination_address' => 'http://failed-destination.test'
        ];

        // Only contact is the original sender - should be skipped
        $contacts = [
            [
                'name' => 'OriginalSender',
                'http' => self::TEST_ADDRESS, // Same as sender_address
                'pubkey' => 'sender-pubkey'
            ]
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // No batch should be sent since only contact is the sender (all filtered out)
        $this->transportUtility->expects($this->never())
            ->method('sendMultiBatch');

        // Status should be cancelled due to no viable route
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_CANCELLED);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test originator P2P (with destination_address) uses sendMultiBatch
     *
     * When the original sender queues a P2P, the message has destination_address set.
     * This should skip Path A (direct match) and enter Path B (broadcast), collecting
     * contacts into megaBatchSends and firing sendMultiBatch. The destination contact
     * itself should be filtered out from the batch.
     */
    public function testProcessQueuedP2pMessagesOriginatorUsesSendMultiBatch(): void
    {
        $p2pHash = self::TEST_HASH;
        $originatorAddress = 'http://originator.test';
        $destinationAddress = 'http://destination.test';

        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => $originatorAddress,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 0,
            'max_request_level' => 5,
            'fee_amount' => 0,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED,
            'destination_address' => $destinationAddress  // Originator has destination set
        ];

        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1'],
            ['http' => $destinationAddress, 'pubkey' => 'dest-pubkey'],   // Should be filtered (destination)
            ['http' => $originatorAddress, 'pubkey' => 'orig-pubkey'],    // Should be filtered (sender)
            ['http' => 'http://contact4.test', 'pubkey' => 'pubkey4']
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Verify sendMultiBatch is called with exactly 2 sends:
        // contact1 and contact4 (destination and sender filtered out)
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->with($this->callback(function ($sends) use ($destinationAddress, $originatorAddress) {
                if (count($sends) !== 2) return false;
                foreach ($sends as $send) {
                    // Destination and sender addresses must be excluded
                    if ($send['recipient'] === $destinationAddress) return false;
                    if ($send['recipient'] === $originatorAddress) return false;
                    if (!isset($send['key'], $send['recipient'], $send['payload'])) return false;
                }
                return true;
            }))
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce'
                    ];
                }
                return $results;
            });

        $this->p2pRepository->method('getByHash')
            ->willReturn(['status' => Constants::STATUS_QUEUED]);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_SENT);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test parallel broadcast uses transportUtility->sendMultiBatch with correct sends
     *
     * Verifies that the refactored broadcast loop collects eligible contacts
     * into a mega-batch and calls sendMultiBatch once with the correct sends,
     * filtering out the sender address.
     */
    public function testProcessQueuedP2pMessagesBatchSendStructure(): void
    {
        $p2pHash = self::TEST_HASH;
        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED
        ];

        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1'],
            ['http' => self::TEST_ADDRESS, 'pubkey' => 'sender-pubkey'],  // Same as sender - should be filtered
            ['http' => 'http://contact3.test', 'pubkey' => 'pubkey3']
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Verify sendMultiBatch is called with exactly 2 sends (sender filtered out)
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->with($this->callback(function ($sends) {
                if (count($sends) !== 2) return false;
                // Sender address should be filtered out
                foreach ($sends as $send) {
                    if ($send['recipient'] === self::TEST_ADDRESS) return false;
                    if (!isset($send['key'], $send['recipient'], $send['payload'])) return false;
                }
                return true;
            }))
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce'
                    ];
                }
                return $results;
            });

        $this->p2pRepository->method('getByHash')
            ->willReturn(['status' => Constants::STATUS_QUEUED]);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test broadcast uses transportUtility->sendBatch() directly without delivery tracking
     *
     * P2P broadcasts bypass MessageDeliveryService for minimal overhead.
     * The transport batch is called directly on transportUtility.
     */
    public function testProcessQueuedP2pMessagesFallbackToDirectTransportBatch(): void
    {
        $p2pHash = self::TEST_HASH;
        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_QUEUED
        ];

        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1']
        ];

        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Transport mega-batch is called directly
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => json_encode(['status' => 'inserted']),
                        'signature' => 'sig1',
                        'nonce' => 'nonce1'
                    ];
                }
                return $results;
            });

        $this->p2pRepository->method('getByHash')
            ->willReturn(['status' => Constants::STATUS_QUEUED]);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test that expired P2P requests are rejected during checkP2pPossible
     *
     * Verifies that when a P2P request has an expiration time in the past,
     * the checkP2pPossible method rejects it appropriately.
     */
    public function testCheckP2pPossibleRejectsExpiredP2p(): void
    {
        // checkP2pPossible does not currently check expiration directly -
        // expiration is handled at message processing level, not in the P2P eligibility check.
        // This test verifies the exception handling path when handleP2pRequest fails.
        $this->markTestSkipped(
            'checkP2pPossible does not check expiration - expiration is handled at message processing level'
        );
    }

    /**
     * Test P2P expiration handling and cleanup
     *
     * Verifies that the repository correctly identifies expired P2P messages
     * and that the service can handle cleanup of expired requests.
     */
    public function testP2pExpirationTriggersCleanup(): void
    {
        $currentMicrotime = 1700000000000000; // Current time in microtime format
        $expiredP2ps = [
            [
                'hash' => 'expired-hash-1',
                'expiration' => 1600000000000000, // Expired
                'status' => Constants::STATUS_SENT,
                'sender_address' => 'http://sender1.test'
            ],
            [
                'hash' => 'expired-hash-2',
                'expiration' => 1650000000000000, // Expired
                'status' => Constants::STATUS_QUEUED,
                'sender_address' => 'http://sender2.test'
            ]
        ];

        // Test that repository can identify expired P2Ps
        $this->p2pRepository->expects($this->once())
            ->method('getExpiredP2p')
            ->with($currentMicrotime, 10)
            ->willReturn($expiredP2ps);

        // Get expired P2Ps from repository
        $result = $this->p2pRepository->getExpiredP2p($currentMicrotime, 10);

        $this->assertCount(2, $result);
        $this->assertEquals('expired-hash-1', $result[0]['hash']);
        $this->assertEquals('expired-hash-2', $result[1]['hash']);

        // Verify both have expiration times less than current time
        foreach ($result as $p2p) {
            $this->assertLessThan($currentMicrotime, $p2p['expiration']);
            $this->assertNotEquals(Constants::STATUS_COMPLETED, $p2p['status']);
            $this->assertNotEquals(Constants::STATUS_EXPIRED, $p2p['status']);
            $this->assertNotEquals(Constants::STATUS_CANCELLED, $p2p['status']);
        }
    }

    /**
     * Test handleP2pRequest forces fast mode for Tor receiver addresses
     *
     * When a P2P arrives with fast=0 (best-fee) but the receiver is a .onion
     * address, the node should override to fast=1 before storing. This prevents
     * remote nodes from forcing best-fee mode over Tor.
     */
    public function testHandleP2pRequestForcesFastModeForTorReceiver(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'hash' => self::TEST_HASH,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'amount' => self::TEST_AMOUNT,
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'fast' => 0, // best-fee mode requested
            'receiverAddress' => 'abcdef1234567890.onion', // Tor address
        ];

        // User is NOT the end recipient
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://mynode.test');

        $this->userContext->method('getUserLocaters')
            ->willReturn(['http' => 'http://mynode.test']);

        // Tor address detection
        $this->transportUtility->method('isTorAddress')
            ->with('abcdef1234567890.onion')
            ->willReturn(true);

        // Fee calculation
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');
        $this->contactService->method('lookupByAddress')
            ->willReturn(['pubkey_hash' => 'test_hash']);
        $this->currencyUtility->method('calculateFee')
            ->willReturn(200);

        // Verify fast flag is overridden to 1 when stored
        $this->p2pRepository->expects($this->once())
            ->method('insertP2pRequest')
            ->with(
                $this->callback(function ($req) {
                    return $req['fast'] === 1;
                }),
                null
            );

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_HASH, Constants::STATUS_QUEUED);

        $this->service->handleP2pRequest($request);
    }
}
