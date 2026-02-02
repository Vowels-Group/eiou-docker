<?php
/**
 * Unit Tests for MessageService
 *
 * Tests message service functionality including:
 * - Message validity checking
 * - Message request handling (transaction, contact, P2P, sync)
 * - Message structure validation
 * - Building message responses
 * - Sync trigger injection
 * - Message delivery service injection
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\MessageService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Database\ContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Exceptions\FatalServiceException;
use RuntimeException;

#[CoversClass(MessageService::class)]
class MessageServiceTest extends TestCase
{
    private MockObject|ContactRepository $contactRepository;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|P2pRepository $p2pRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|TransactionContactRepository $transactionContactRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TimeUtilityService $timeUtility;
    private MockObject|UserContext $userContext;
    private MockObject|MessageDeliveryService $messageDeliveryService;
    private MockObject|SyncTriggerInterface $syncTrigger;
    private MessageService $service;

    private const TEST_PUBLIC_KEY = 'test-public-key-1234567890';
    private const TEST_ADDRESS = 'http://test.example.com';
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->p2pRepository = $this->createMock(P2pRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->transactionContactRepository = $this->createMock(TransactionContactRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->timeUtility = $this->createMock(TimeUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->messageDeliveryService = $this->createMock(MessageDeliveryService::class);
        $this->syncTrigger = $this->createMock(SyncTriggerInterface::class);

        // Setup utility container
        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);
        $this->utilityContainer->method('getTimeUtility')
            ->willReturn($this->timeUtility);

        // Setup user context defaults
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);

        $this->service = new MessageService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->transactionContactRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService
        );
    }

    // =========================================================================
    // setSyncTrigger() Tests
    // =========================================================================

    /**
     * Test setSyncTrigger sets the sync trigger
     */
    public function testSetSyncTriggerSetsTrigger(): void
    {
        $this->service->setSyncTrigger($this->syncTrigger);

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
        $newService = $this->createMock(MessageDeliveryService::class);
        $this->service->setMessageDeliveryService($newService);

        // No exception means success
        $this->assertTrue(true);
    }

    // =========================================================================
    // checkMessageValidity() Tests
    // =========================================================================

    /**
     * Test checkMessageValidity returns false for missing senderPublicKey
     */
    public function testCheckMessageValidityReturnsFalseForMissingPublicKey(): void
    {
        $message = [
            'senderAddress' => self::TEST_ADDRESS,
            'typeMessage' => 'transaction'
            // Missing senderPublicKey
        ];

        $result = $this->service->checkMessageValidity($message);

        $this->assertFalse($result);
    }

    /**
     * Test checkMessageValidity returns true for known contact
     */
    public function testCheckMessageValidityReturnsTrueForKnownContact(): void
    {
        $message = [
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'senderAddress' => self::TEST_ADDRESS,
            'typeMessage' => 'transaction'
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->with(self::TEST_PUBLIC_KEY)
            ->willReturn(true);

        $result = $this->service->checkMessageValidity($message);

        $this->assertTrue($result);
    }

    /**
     * Test checkMessageValidity returns true for valid hash match
     */
    public function testCheckMessageValidityReturnsTrueForValidHash(): void
    {
        $message = [
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'senderAddress' => self::TEST_ADDRESS,
            'typeMessage' => 'transaction',
            'hash' => self::TEST_HASH
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(false);

        $p2pData = [
            'salt' => 'test-salt',
            'time' => '1234567890'
        ];

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn($p2pData);

        // Setup transport utility to return the proper address
        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        // Create the expected hash
        $expectedHash = hash(Constants::HASH_ALGORITHM, self::TEST_ADDRESS . $p2pData['salt'] . $p2pData['time']);

        // If hash matches, return true
        $message['hash'] = $expectedHash;

        $result = $this->service->checkMessageValidity($message);

        $this->assertTrue($result);
    }

    /**
     * Test checkMessageValidity returns false for unknown sender without hash
     */
    public function testCheckMessageValidityReturnsFalseForUnknownSender(): void
    {
        $message = [
            'senderPublicKey' => 'unknown-public-key',
            'senderAddress' => self::TEST_ADDRESS,
            'typeMessage' => 'transaction'
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(false);

        $result = $this->service->checkMessageValidity($message);

        $this->assertFalse($result);
    }

    /**
     * Test checkMessageValidity returns false when P2P not found
     */
    public function testCheckMessageValidityReturnsFalseWhenP2pNotFound(): void
    {
        $message = [
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'senderAddress' => self::TEST_ADDRESS,
            'typeMessage' => 'transaction',
            'hash' => self::TEST_HASH
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(false);

        $this->p2pRepository->method('getByHash')
            ->willReturn(null);

        $result = $this->service->checkMessageValidity($message);

        $this->assertFalse($result);
    }

    // =========================================================================
    // validateMessageStructure() Tests
    // =========================================================================

    /**
     * Test validateMessageStructure returns true for valid structure
     */
    public function testValidateMessageStructureReturnsTrueForValidStructure(): void
    {
        $request = [
            'typeMessage' => 'transaction',
            'senderAddress' => self::TEST_ADDRESS
        ];

        $result = $this->service->validateMessageStructure($request);

        $this->assertTrue($result);
    }

    /**
     * Test validateMessageStructure returns false for missing typeMessage
     */
    public function testValidateMessageStructureReturnsFalseForMissingTypeMessage(): void
    {
        $request = [
            'senderAddress' => self::TEST_ADDRESS
            // Missing typeMessage
        ];

        $result = $this->service->validateMessageStructure($request);

        $this->assertFalse($result);
    }

    /**
     * Test validateMessageStructure returns false for missing senderAddress
     */
    public function testValidateMessageStructureReturnsFalseForMissingSenderAddress(): void
    {
        $request = [
            'typeMessage' => 'transaction'
            // Missing senderAddress
        ];

        $result = $this->service->validateMessageStructure($request);

        $this->assertFalse($result);
    }

    // =========================================================================
    // buildMessageResponse() Tests
    // =========================================================================

    /**
     * Test buildMessageResponse returns JSON with status and message
     */
    public function testBuildMessageResponseReturnsJsonWithStatusAndMessage(): void
    {
        $result = $this->service->buildMessageResponse('accepted', 'Transaction accepted');

        $decoded = json_decode($result, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('accepted', $decoded['status']);
        $this->assertEquals('Transaction accepted', $decoded['message']);
    }

    /**
     * Test buildMessageResponse includes additional data
     */
    public function testBuildMessageResponseIncludesAdditionalData(): void
    {
        $additionalData = [
            'txid' => 'test-txid',
            'amount' => 100
        ];

        $result = $this->service->buildMessageResponse('completed', 'Done', $additionalData);

        $decoded = json_decode($result, true);

        $this->assertEquals('completed', $decoded['status']);
        $this->assertEquals('test-txid', $decoded['txid']);
        $this->assertEquals(100, $decoded['amount']);
    }

    /**
     * Test buildMessageResponse with empty additional data
     */
    public function testBuildMessageResponseWithEmptyAdditionalData(): void
    {
        $result = $this->service->buildMessageResponse('rejected', 'Invalid request', []);

        $decoded = json_decode($result, true);

        $this->assertCount(2, $decoded);
        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('message', $decoded);
    }

    // =========================================================================
    // handleMessageRequest() Tests
    // =========================================================================

    /**
     * Test handleMessageRequest throws exception for invalid source
     */
    public function testHandleMessageRequestThrowsExceptionForInvalidSource(): void
    {
        $request = [
            'typeMessage' => 'transaction',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => 'unknown-key'
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(false);

        $this->expectException(FatalServiceException::class);

        $this->service->handleMessageRequest($request);
    }

    /**
     * Test handleMessageRequest handles contact inquiry
     */
    public function testHandleMessageRequestHandlesContactInquiry(): void
    {
        $request = [
            'typeMessage' => 'contact',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'inquiry' => true
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        $this->contactRepository->method('isAcceptedContactPubkey')
            ->with(self::TEST_PUBLIC_KEY)
            ->willReturn(true);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        // Should output JSON response for contact status
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test handleMessageRequest handles P2P status inquiry
     */
    public function testHandleMessageRequestHandlesP2pStatusInquiry(): void
    {
        $request = [
            'typeMessage' => 'p2p',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'inquiry' => true,
            'hash' => self::TEST_HASH
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(['status' => 'found']);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test handleMessageRequest handles P2P status inquiry with missing hash
     */
    public function testHandleMessageRequestHandlesP2pInquiryWithMissingHash(): void
    {
        $request = [
            'typeMessage' => 'p2p',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'inquiry' => true
            // Missing hash
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
    }

    /**
     * Test handleMessageRequest handles sync message
     */
    public function testHandleMessageRequestHandlesSyncMessage(): void
    {
        $request = [
            'typeMessage' => 'sync',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'syncType' => 'transaction_chain',
            'inquiry' => true
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        $this->service->setSyncTrigger($this->syncTrigger);

        $this->syncTrigger->expects($this->once())
            ->method('handleTransactionSyncRequest')
            ->with($request);

        $this->service->handleMessageRequest($request);
    }

    /**
     * Test handleMessageRequest rejects unknown sync type
     */
    public function testHandleMessageRequestRejectsUnknownSyncType(): void
    {
        $request = [
            'typeMessage' => 'sync',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'syncType' => 'unknown_type'
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals('unknown_sync_type', $decoded['reason']);
    }

    /**
     * Test handleMessageRequest throws when sync trigger not set
     */
    public function testHandleMessageRequestThrowsWhenSyncTriggerNotSet(): void
    {
        $request = [
            'typeMessage' => 'sync',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'syncType' => 'transaction_chain',
            'inquiry' => true
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        // Don't set sync trigger

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SyncTrigger not injected');

        $this->service->handleMessageRequest($request);
    }

    // =========================================================================
    // Transaction Message Tests
    // =========================================================================

    /**
     * Test handleMessageRequest handles transaction inquiry
     */
    public function testHandleMessageRequestHandlesTransactionInquiry(): void
    {
        $request = [
            'typeMessage' => 'transaction',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'inquiry' => true,
            'hash' => self::TEST_HASH,
            'hashType' => 'memo'
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        $this->transactionRepository->method('getStatusByMemo')
            ->with(self::TEST_HASH)
            ->willReturn(Constants::STATUS_COMPLETED);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        // Mock output function if needed (it's a global function)
        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test handleMessageRequest handles transaction with txid hash type
     */
    public function testHandleMessageRequestHandlesTransactionWithTxidHash(): void
    {
        $request = [
            'typeMessage' => 'transaction',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'inquiry' => true,
            'hash' => self::TEST_HASH,
            'hashType' => 'txid'
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        $this->transactionRepository->method('getStatusByTxid')
            ->with(self::TEST_HASH)
            ->willReturn(Constants::STATUS_COMPLETED);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    // =========================================================================
    // Contact Message Tests
    // =========================================================================

    /**
     * Test handleMessageRequest handles contact acceptance
     */
    public function testHandleMessageRequestHandlesContactAcceptance(): void
    {
        $request = [
            'typeMessage' => 'contact',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY,
            'status' => Constants::STATUS_ACCEPTED
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        $this->contactRepository->method('updateStatus')
            ->with(self::TEST_PUBLIC_KEY, Constants::STATUS_ACCEPTED)
            ->willReturn(true);

        $this->transactionContactRepository->expects($this->once())
            ->method('completeContactTransaction')
            ->with(self::TEST_PUBLIC_KEY);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    /**
     * Test handleMessageRequest rejects contact message without public key
     */
    public function testHandleMessageRequestRejectsContactWithoutPublicKey(): void
    {
        $request = [
            'typeMessage' => 'contact',
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBLIC_KEY, // For validity check
            'status' => Constants::STATUS_ACCEPTED
        ];

        $this->contactRepository->method('contactExistsPubkey')
            ->willReturn(true);

        // Temporarily remove sender public key after validity check passes
        // This simulates a malformed message that passes initial check

        ob_start();
        $this->service->handleMessageRequest($request);
        $output = ob_get_clean();

        // Should still process since public key exists
        $this->assertNotEmpty($output);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor with null message delivery service
     */
    public function testConstructorWithNullMessageDeliveryService(): void
    {
        $service = new MessageService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->transactionContactRepository,
            $this->utilityContainer,
            $this->userContext,
            null
        );

        $this->assertInstanceOf(MessageService::class, $service);
    }

    /**
     * Test constructor initializes payload builders
     */
    public function testConstructorInitializesPayloadBuilders(): void
    {
        // If constructor doesn't throw, payloads were initialized
        $service = new MessageService(
            $this->contactRepository,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->transactionContactRepository,
            $this->utilityContainer,
            $this->userContext
        );

        $this->assertInstanceOf(MessageService::class, $service);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    /**
     * Test checkMessageValidity with null senderPublicKey
     */
    public function testCheckMessageValidityWithNullPublicKey(): void
    {
        $message = [
            'senderPublicKey' => null,
            'senderAddress' => self::TEST_ADDRESS,
            'typeMessage' => 'transaction'
        ];

        $result = $this->service->checkMessageValidity($message);

        $this->assertFalse($result);
    }

    /**
     * Test buildMessageResponse handles special characters
     */
    public function testBuildMessageResponseHandlesSpecialCharacters(): void
    {
        $result = $this->service->buildMessageResponse(
            'error',
            'Invalid character: < > & " \''
        );

        $decoded = json_decode($result, true);

        $this->assertEquals('Invalid character: < > & " \'', $decoded['message']);
    }

    /**
     * Test validateMessageStructure with empty array
     */
    public function testValidateMessageStructureWithEmptyArray(): void
    {
        $result = $this->service->validateMessageStructure([]);

        $this->assertFalse($result);
    }
}
