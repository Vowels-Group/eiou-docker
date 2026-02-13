<?php
/**
 * Unit Tests for P2pService Cascade Cancel Notification
 *
 * Tests the cascade cancel notification feature including:
 * - sendCancelNotificationForHash() public method
 * - Cancel notification routing based on originator vs relay node
 * - Multi-path sender cancel notification delivery
 * - Dead-end detection triggering cancel notification in processQueuedP2pMessages
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
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;

#[CoversClass(P2pService::class)]
class P2pServiceCascadeCancelTest extends TestCase
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
        $this->userContext->method('getP2pExpirationTime')
            ->willReturn(300);

        $this->service = new P2pService(
            $this->contactService,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService,
            $this->p2pSenderRepository
        );
    }

    // =========================================================================
    // sendCancelNotificationForHash() - Originator Node Tests
    // =========================================================================

    /**
     * Test that originator nodes (with destination_address set) do NOT send cancel upstream
     *
     * When a P2P has destination_address set, this node is the originator.
     * Originators have no upstream node to notify, so cancel should not be sent.
     */
    public function testSendCancelNotificationForHashDoesNotSendForOriginator(): void
    {
        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => 'http://destination.test', // Originator
                'sender_address' => 'http://originator.test',
                'amount' => self::TEST_AMOUNT,
            ]);

        // No messages should be sent for originator nodes
        $this->messageDeliveryService->expects($this->never())
            ->method('sendMessage');

        $this->service->sendCancelNotificationForHash(self::TEST_HASH);
    }

    /**
     * Test that sendCancelNotificationForHash returns early when P2P not found
     */
    public function testSendCancelNotificationForHashReturnsEarlyWhenP2pNotFound(): void
    {
        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        // No messages should be sent when P2P doesn't exist
        $this->messageDeliveryService->expects($this->never())
            ->method('sendMessage');

        $this->service->sendCancelNotificationForHash(self::TEST_HASH);
    }

    // =========================================================================
    // sendCancelNotificationForHash() - Relay Node Tests
    // =========================================================================

    /**
     * Test that relay nodes (no destination_address) send cancel to all senders in p2p_senders
     *
     * When a node is a relay (no destination_address), it should send cancel
     * notifications to every sender recorded in the p2p_senders table.
     */
    public function testSendCancelNotificationForHashSendsToAllSenders(): void
    {
        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => null, // Relay node
                'sender_address' => 'http://sender1.test',
                'amount' => self::TEST_AMOUNT,
            ]);

        $this->p2pSenderRepository->method('getSendersByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                ['sender_address' => 'http://sender1.test', 'sender_pubkey' => 'pubkey1'],
                ['sender_address' => 'http://sender2.test', 'sender_pubkey' => 'pubkey2'],
                ['sender_address' => 'http://sender3.test', 'sender_pubkey' => 'pubkey3'],
            ]);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://relay-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // Expect cancel sent to all 3 senders
        $this->messageDeliveryService->expects($this->exactly(3))
            ->method('sendMessage')
            ->with(
                'rp2p',
                $this->anything(),
                $this->callback(function ($payload) {
                    return $payload['type'] === 'rp2p'
                        && $payload['cancelled'] === true
                        && $payload['amount'] === 0
                        && $payload['hash'] === self::TEST_HASH;
                }),
                $this->stringContains('cancel-'),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-' . self::TEST_HASH . '-test',
            ]);

        $this->service->sendCancelNotificationForHash(self::TEST_HASH);
    }

    /**
     * Test that relay node sends cancel to original sender when p2p_senders is empty
     *
     * When p2p_senders table has no entries, the cancel notification should
     * fall back to the sender_address from the p2p record itself.
     */
    public function testSendCancelNotificationForHashFallsBackToOriginalSender(): void
    {
        $originalSender = 'http://original-sender.test';

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => null, // Relay node
                'sender_address' => $originalSender,
                'amount' => self::TEST_AMOUNT,
            ]);

        // Empty senders list
        $this->p2pSenderRepository->method('getSendersByHash')
            ->with(self::TEST_HASH)
            ->willReturn([]);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://relay-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // Expect exactly one cancel sent to the original sender
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $originalSender,
                $this->callback(function ($payload) {
                    return $payload['type'] === 'rp2p'
                        && $payload['cancelled'] === true
                        && $payload['hash'] === self::TEST_HASH;
                }),
                $this->stringContains('cancel-'),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-' . self::TEST_HASH . '-test',
            ]);

        $this->service->sendCancelNotificationForHash(self::TEST_HASH);
    }

    /**
     * Test that original sender is included even if not in p2p_senders table
     *
     * When p2p_senders has entries but the original sender_address from the
     * p2p record is not among them, it should be appended to the list.
     */
    public function testSendCancelNotificationForHashIncludesOriginalSenderWhenNotInSenders(): void
    {
        $originalSender = 'http://original-sender.test';

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => null, // Relay node
                'sender_address' => $originalSender,
                'amount' => self::TEST_AMOUNT,
            ]);

        // p2p_senders has one entry that is NOT the original sender
        $this->p2pSenderRepository->method('getSendersByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                ['sender_address' => 'http://other-sender.test', 'sender_pubkey' => 'other-pubkey'],
            ]);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://relay-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // Expect cancel sent to both: the sender in the table and the original sender
        $this->messageDeliveryService->expects($this->exactly(2))
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-' . self::TEST_HASH . '-test',
            ]);

        $this->service->sendCancelNotificationForHash(self::TEST_HASH);
    }

    /**
     * Test that original sender is NOT duplicated when already present in p2p_senders
     *
     * When p2p_senders already contains the original sender_address,
     * it should not be added again (no duplicate cancel notification).
     */
    public function testSendCancelNotificationForHashDoesNotDuplicateOriginalSender(): void
    {
        $originalSender = 'http://original-sender.test';

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => null, // Relay node
                'sender_address' => $originalSender,
                'amount' => self::TEST_AMOUNT,
            ]);

        // p2p_senders already includes the original sender
        $this->p2pSenderRepository->method('getSendersByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                ['sender_address' => $originalSender, 'sender_pubkey' => 'original-pubkey'],
                ['sender_address' => 'http://second-sender.test', 'sender_pubkey' => 'second-pubkey'],
            ]);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://relay-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // Expect exactly 2 (not 3) cancel messages: original + second, no duplicate
        $this->messageDeliveryService->expects($this->exactly(2))
            ->method('sendMessage')
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-' . self::TEST_HASH . '-test',
            ]);

        $this->service->sendCancelNotificationForHash(self::TEST_HASH);
    }

    /**
     * Test cancel notification without P2pSenderRepository (null repository)
     *
     * When P2pSenderRepository is not available (null), the cancel should
     * fall back to sending only to the original sender from the p2p record.
     */
    public function testSendCancelNotificationForHashWithNullSenderRepository(): void
    {
        $originalSender = 'http://original-sender.test';

        // Create service without P2pSenderRepository
        $serviceWithoutSenderRepo = new P2pService(
            $this->contactService,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService,
            null // No P2pSenderRepository
        );

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => null, // Relay node
                'sender_address' => $originalSender,
                'amount' => self::TEST_AMOUNT,
            ]);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://relay-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // Expect exactly one cancel sent to original sender (fallback when no sender repo)
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $originalSender,
                $this->callback(function ($payload) {
                    return $payload['type'] === 'rp2p'
                        && $payload['cancelled'] === true;
                }),
                $this->stringContains('cancel-'),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-' . self::TEST_HASH . '-test',
            ]);

        $serviceWithoutSenderRepo->sendCancelNotificationForHash(self::TEST_HASH);
    }

    // =========================================================================
    // processQueuedP2pMessages() - Dead-End Cancel Notification Tests
    // =========================================================================

    /**
     * Test that dead-end (sentMessages === 0) triggers cancel notification
     *
     * When processQueuedP2pMessages finds no viable contacts to forward to,
     * the P2P should be cancelled and cancel notifications sent upstream.
     */
    public function testProcessQueuedP2pMessagesDeadEndTriggersCancelNotification(): void
    {
        $p2pHash = self::TEST_HASH;
        $senderAddress = 'http://upstream-sender.test';

        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'expiration' => '1234567890000000',
            'sender_address' => $senderAddress,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'status' => Constants::STATUS_QUEUED,
        ];

        // Only contact is the original sender -- should be skipped
        $contacts = [
            [
                'name' => 'OriginalSender',
                'http' => $senderAddress,
                'pubkey' => 'sender-pubkey',
            ],
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

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://dead-end-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // After cancellation, getByHash is called to retrieve P2P for cancel notification
        $this->p2pRepository->method('getByHash')
            ->with($p2pHash)
            ->willReturn([
                'hash' => $p2pHash,
                'status' => Constants::STATUS_CANCELLED,
                'destination_address' => null, // Relay node
                'sender_address' => $senderAddress,
                'amount' => self::TEST_AMOUNT,
            ]);

        // p2p_senders returns the upstream sender
        $this->p2pSenderRepository->method('getSendersByHash')
            ->with($p2pHash)
            ->willReturn([
                ['sender_address' => $senderAddress, 'sender_pubkey' => self::TEST_PUBLIC_KEY],
            ]);

        // Status should be set to cancelled
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_CANCELLED);

        // Cancel notification should be sent upstream
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $senderAddress,
                $this->callback(function ($payload) use ($p2pHash) {
                    return $payload['type'] === 'rp2p'
                        && $payload['cancelled'] === true
                        && $payload['amount'] === 0
                        && $payload['hash'] === $p2pHash;
                }),
                $this->stringContains('cancel-'),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-' . $p2pHash . '-test',
            ]);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test that dead-end with empty contacts list triggers cancel notification
     *
     * When the user has zero accepted contacts, all P2Ps become dead-ends.
     */
    public function testProcessQueuedP2pMessagesEmptyContactsDeadEnd(): void
    {
        $p2pHash = self::TEST_HASH;
        $senderAddress = 'http://upstream.test';

        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'expiration' => '1234567890000000',
            'sender_address' => $senderAddress,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'status' => Constants::STATUS_QUEUED,
        ];

        // Empty contacts list
        $this->p2pRepository->method('getQueuedP2pMessages')
            ->willReturn([$queuedMessage]);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn([]);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://dead-end-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // P2P record for cancel notification
        $this->p2pRepository->method('getByHash')
            ->with($p2pHash)
            ->willReturn([
                'hash' => $p2pHash,
                'status' => Constants::STATUS_CANCELLED,
                'destination_address' => null,
                'sender_address' => $senderAddress,
                'amount' => self::TEST_AMOUNT,
            ]);

        $this->p2pSenderRepository->method('getSendersByHash')
            ->willReturn([
                ['sender_address' => $senderAddress, 'sender_pubkey' => self::TEST_PUBLIC_KEY],
            ]);

        // Status should be cancelled
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_CANCELLED);

        // Cancel notification should be sent
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $senderAddress,
                $this->callback(function ($payload) {
                    return $payload['cancelled'] === true;
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-test',
            ]);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test that dead-end cancel sends to multiple senders (multi-path P2P)
     *
     * When a relay node received the same P2P from multiple upstream senders
     * and then hits a dead-end, cancel notifications should be sent to ALL senders.
     */
    public function testProcessQueuedP2pMessagesDeadEndCancelToMultipleSenders(): void
    {
        $p2pHash = self::TEST_HASH;
        $sender1 = 'http://sender1.test';
        $sender2 = 'http://sender2.test';

        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'expiration' => '1234567890000000',
            'sender_address' => $sender1,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'status' => Constants::STATUS_QUEUED,
        ];

        // Only contact is sender1 -- should be skipped, leaving zero viable contacts
        $contacts = [
            [
                'name' => 'Sender1',
                'http' => $sender1,
                'pubkey' => 'pubkey1',
            ],
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

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://dead-end-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // P2P record for cancel notification
        $this->p2pRepository->method('getByHash')
            ->with($p2pHash)
            ->willReturn([
                'hash' => $p2pHash,
                'status' => Constants::STATUS_CANCELLED,
                'destination_address' => null,
                'sender_address' => $sender1,
                'amount' => self::TEST_AMOUNT,
            ]);

        // Multiple senders for this hash
        $this->p2pSenderRepository->method('getSendersByHash')
            ->with($p2pHash)
            ->willReturn([
                ['sender_address' => $sender1, 'sender_pubkey' => 'pubkey1'],
                ['sender_address' => $sender2, 'sender_pubkey' => 'pubkey2'],
            ]);

        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_CANCELLED);

        // Cancel sent to both senders
        $this->messageDeliveryService->expects($this->exactly(2))
            ->method('sendMessage')
            ->with(
                'rp2p',
                $this->anything(),
                $this->callback(function ($payload) use ($p2pHash) {
                    return $payload['type'] === 'rp2p'
                        && $payload['cancelled'] === true
                        && $payload['hash'] === $p2pHash;
                }),
                $this->stringContains('cancel-'),
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'cancel-' . $p2pHash . '-test',
            ]);

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    /**
     * Test that originator dead-end does NOT send cancel upstream
     *
     * When an originator node (destination_address is set) hits a dead-end,
     * cancel notification should NOT be sent because there is no upstream node.
     */
    public function testProcessQueuedP2pMessagesOriginatorDeadEndDoesNotSendCancel(): void
    {
        $p2pHash = self::TEST_HASH;

        $queuedMessage = [
            'hash' => $p2pHash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'expiration' => '1234567890000000',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'status' => Constants::STATUS_QUEUED,
            'destination_address' => 'http://target.test', // Originator
        ];

        // Only contact is the destination address -- should be skipped
        $contacts = [
            [
                'name' => 'Target',
                'http' => 'http://target.test',
                'pubkey' => 'target-pubkey',
            ],
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

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://originator-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // P2P record shows this is an originator (has destination_address)
        $this->p2pRepository->method('getByHash')
            ->with($p2pHash)
            ->willReturn([
                'hash' => $p2pHash,
                'status' => Constants::STATUS_CANCELLED,
                'destination_address' => 'http://target.test', // Originator
                'sender_address' => self::TEST_ADDRESS,
                'amount' => self::TEST_AMOUNT,
            ]);

        // Status should be cancelled
        $this->p2pRepository->expects($this->once())
            ->method('updateStatus')
            ->with($p2pHash, Constants::STATUS_CANCELLED);

        // No cancel notification should be sent upstream
        $this->messageDeliveryService->expects($this->never())
            ->method('sendMessage');

        ob_start();
        $result = $this->service->processQueuedP2pMessages();
        ob_get_clean();

        $this->assertEquals(1, $result);
    }

    // =========================================================================
    // Cancel Payload Verification Tests
    // =========================================================================

    /**
     * Test that cancel notification payload uses correct message ID format
     *
     * Cancel message IDs should follow the format: cancel-{hash}-{contactHash}
     */
    public function testCancelNotificationUsesCorrectMessageIdFormat(): void
    {
        $senderAddress = 'http://sender.test';
        $expectedContactHash = substr(hash('sha256', $senderAddress), 0, 8);
        $expectedMessageId = 'cancel-' . self::TEST_HASH . '-' . $expectedContactHash;

        $this->p2pRepository->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn([
                'hash' => self::TEST_HASH,
                'status' => 'sent',
                'destination_address' => null, // Relay node
                'sender_address' => $senderAddress,
                'amount' => self::TEST_AMOUNT,
            ]);

        $this->p2pSenderRepository->method('getSendersByHash')
            ->willReturn([
                ['sender_address' => $senderAddress, 'sender_pubkey' => 'pubkey1'],
            ]);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://relay-node.test');

        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(1700000000000000);

        // Verify message ID format
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'rp2p',
                $senderAddress,
                $this->anything(),
                $expectedMessageId,
                $this->anything()
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => $expectedMessageId,
            ]);

        $this->service->sendCancelNotificationForHash(self::TEST_HASH);
    }
}
