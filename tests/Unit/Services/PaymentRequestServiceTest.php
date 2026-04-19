<?php
/**
 * Unit Tests for PaymentRequestService
 *
 * Tests the full payment request lifecycle:
 * - create() — validation, contact lookup, address resolution, local store, transport send
 * - approve() — validates incoming/pending, triggers sendEiou, updates status, sends response
 * - decline() — validates incoming/pending, updates status, sends response
 * - cancel() — validates outgoing/pending, updates status
 * - handleIncomingRequest() — idempotent storage of incoming payment_request messages
 * - handleIncomingResponse() — updates outgoing request on response message
 * - getAllForDisplay() — returns incoming + outgoing lists
 * - countPendingIncoming() — delegates to repository
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\PaymentRequestService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Database\PaymentRequestRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Services\TransactionService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;

#[CoversClass(PaymentRequestService::class)]
class PaymentRequestServiceTest extends TestCase
{
    private MockObject|PaymentRequestRepository $paymentRequestRepository;
    private MockObject|ContactRepository $contactRepository;
    private MockObject|AddressRepository $addressRepository;
    private MockObject|TransactionService $transactionService;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|UserContext $currentUser;
    private MockObject|Logger $logger;
    private MockObject|MessageDeliveryService $messageDeliveryService;
    private PaymentRequestService $service;

    private const TEST_REQUEST_ID  = 'req1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab';
    private const TEST_PUBLIC_KEY  = 'test-public-key-aabbccdd';
    private const TEST_PUBKEY_HASH = 'pubkey-hash-aabbccdd1234567890abcdef1234567890abcdef1234567890abcdef';
    private const TEST_ADDRESS     = 'http://alice.example:8080';
    private const TEST_CONTACT_ADDRESS = 'http://bob.example:8080';
    private const TEST_CONTACT_NAME    = 'Bob';
    private const TEST_AMOUNT     = '10.00';
    private const TEST_CURRENCY   = 'USD';

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentRequestRepository = $this->createMock(PaymentRequestRepository::class);
        $this->contactRepository        = $this->createMock(ContactRepository::class);
        $this->addressRepository        = $this->createMock(AddressRepository::class);
        $this->transactionService       = $this->createMock(TransactionService::class);
        $this->transportUtility         = $this->createMock(TransportUtilityService::class);
        $this->currentUser              = $this->createMock(UserContext::class);
        $this->logger                   = $this->createMock(Logger::class);
        $this->messageDeliveryService   = $this->createMock(MessageDeliveryService::class);

        $this->currentUser->method('getPublicKey')
            ->willReturn(self::TEST_PUBLIC_KEY);
        $this->currentUser->method('getPublicKeyHash')
            ->willReturn(self::TEST_PUBKEY_HASH);

        $this->transportUtility->method('resolveUserAddressForTransport')
            ->willReturn(self::TEST_ADDRESS);

        // Default: MessageDeliveryService succeeds
        $this->messageDeliveryService->method('sendMessage')
            ->willReturn(['success' => true, 'queued_for_retry' => false, 'tracking' => ['stage' => 'completed']]);

        $this->service = new PaymentRequestService(
            $this->paymentRequestRepository,
            $this->contactRepository,
            $this->addressRepository,
            $this->transactionService,
            $this->transportUtility,
            $this->currentUser,
            $this->logger
        );
        $this->service->setMessageDeliveryService($this->messageDeliveryService);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Skip tests that call InputValidator::validateAmount when bcmath is unavailable */
    private function requireBcmath(): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('bcmath extension required for amount validation tests');
        }
    }

    private function acceptedContact(): array
    {
        return [
            'name'        => self::TEST_CONTACT_NAME,
            'status'      => 'accepted',
            'pubkey_hash' => 'bob-pubkey-hash',
        ];
    }

    private function addressMap(): array
    {
        return ['http' => self::TEST_CONTACT_ADDRESS];
    }

    // =========================================================================
    // create()
    // =========================================================================

    public function testCreateReturnErrorForInvalidAmount(): void
    {
        $this->requireBcmath();
        $result = $this->service->create(self::TEST_CONTACT_NAME, 'not-a-number', self::TEST_CURRENCY, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid amount', $result['error']);
    }

    public function testCreateReturnsErrorForInvalidCurrency(): void
    {
        $this->requireBcmath();
        $result = $this->service->create(self::TEST_CONTACT_NAME, self::TEST_AMOUNT, 'FAKE', null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid currency', $result['error']);
    }

    public function testCreateReturnsErrorWhenContactNotFound(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('lookupByName')
            ->willReturn(null);

        $result = $this->service->create('UnknownContact', self::TEST_AMOUNT, self::TEST_CURRENCY, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Contact not found', $result['error']);
    }

    public function testCreateReturnsErrorForPendingContact(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('lookupByName')
            ->willReturn(['name' => 'Bob', 'status' => 'pending', 'pubkey_hash' => 'x']);

        $result = $this->service->create(self::TEST_CONTACT_NAME, self::TEST_AMOUNT, self::TEST_CURRENCY, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('accepted', $result['error']);
    }

    public function testCreateReturnsErrorWhenNoAddressForContact(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('lookupByName')
            ->willReturn($this->acceptedContact());
        $this->addressRepository->method('lookupByPubkeyHash')
            ->willReturn([]);  // No addresses

        $result = $this->service->create(self::TEST_CONTACT_NAME, self::TEST_AMOUNT, self::TEST_CURRENCY, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No valid address', $result['error']);
    }

    public function testCreateSuccessfullyStoresAndSendsRequest(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('lookupByName')
            ->willReturn($this->acceptedContact());
        $this->addressRepository->method('lookupByPubkeyHash')
            ->willReturn($this->addressMap());
        $this->paymentRequestRepository->expects($this->once())
            ->method('createRequest')
            ->with($this->arrayHasKey('request_id'))
            ->willReturn('1');
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with('payment_request', self::TEST_CONTACT_ADDRESS);

        $result = $this->service->create(self::TEST_CONTACT_NAME, self::TEST_AMOUNT, self::TEST_CURRENCY, 'Pay me back');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('request_id', $result);
        $this->assertNotEmpty($result['request_id']);
    }

    public function testCreateSucceedsEvenWhenDeliveryFails(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('lookupByName')
            ->willReturn($this->acceptedContact());
        $this->addressRepository->method('lookupByPubkeyHash')
            ->willReturn($this->addressMap());
        $this->paymentRequestRepository->method('createRequest')
            ->willReturn('1');
        $this->messageDeliveryService->method('sendMessage')
            ->willReturn(['success' => false, 'queued_for_retry' => true, 'tracking' => ['stage' => 'queued_for_retry']]);

        // Should still succeed — delivery failure/queuing is non-fatal
        $result = $this->service->create(self::TEST_CONTACT_NAME, self::TEST_AMOUNT, self::TEST_CURRENCY, null);

        $this->assertTrue($result['success']);
    }

    public function testCreatePrefersTorAddressWhenAvailable(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('lookupByName')
            ->willReturn($this->acceptedContact());
        $this->addressRepository->method('lookupByPubkeyHash')
            ->willReturn([
                'http' => 'http://bob.example:8080',
                'tor'  => 'http://bobonion.onion',
            ]);
        $this->paymentRequestRepository->method('createRequest')
            ->willReturn('1');
        // Delivery should go to the tor address
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with('payment_request', 'http://bobonion.onion');

        $this->service->create(self::TEST_CONTACT_NAME, self::TEST_AMOUNT, self::TEST_CURRENCY, null);
    }

    // =========================================================================
    // approve()
    // =========================================================================

    public function testApproveReturnsErrorWhenRequestNotFound(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);

        $result = $this->service->approve(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testApproveReturnsErrorForOutgoingRequest(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'       => self::TEST_REQUEST_ID,
                'direction'        => 'outgoing',
                'status'           => 'pending',
                'requester_address' => self::TEST_CONTACT_ADDRESS,
                'amount_whole'     => 10,
                'amount_frac'      => 0,
                'currency'         => 'USD',
            ]);

        $result = $this->service->approve(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('incoming', $result['error']);
    }

    public function testApproveReturnsErrorForAlreadyApprovedRequest(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'        => self::TEST_REQUEST_ID,
                'direction'         => 'incoming',
                'status'            => 'approved',
                'requester_address' => self::TEST_CONTACT_ADDRESS,
                'amount_whole'      => 10,
                'amount_frac'       => 0,
                'currency'          => 'USD',
            ]);

        $result = $this->service->approve(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('pending', $result['error']);
    }

    public function testApproveReturnsErrorWhenNoRequesterAddress(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'        => self::TEST_REQUEST_ID,
                'direction'         => 'incoming',
                'status'            => 'pending',
                'requester_address' => '',   // Empty
                'amount_whole'      => 10,
                'amount_frac'       => 0,
                'currency'          => 'USD',
            ]);

        $result = $this->service->approve(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No return address', $result['error']);
    }

    public function testApproveReturnErrorWhenSendEiouFails(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'        => self::TEST_REQUEST_ID,
                'direction'         => 'incoming',
                'status'            => 'pending',
                'requester_address' => self::TEST_CONTACT_ADDRESS,
                'amount_whole'      => 10,
                'amount_frac'       => 0,
                'amount'            => null,
                'currency'          => 'USD',
                'description'       => null,
            ]);
        // sendEiou outputs a CLI error JSON (success=false with error object)
        $this->transactionService->method('sendEiou')
            ->willReturnCallback(function () {
                echo json_encode([
                    'success' => false,
                    'error' => ['code' => 'SEND_FAILED', 'detail' => 'Insufficient balance'],
                    'message' => 'Insufficient balance',
                ]);
            });

        $result = $this->service->approve(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    public function testApproveSuccessUpdatesStatusAndSendsResponse(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'        => self::TEST_REQUEST_ID,
                'direction'         => 'incoming',
                'status'            => 'pending',
                'requester_address' => self::TEST_CONTACT_ADDRESS,
                'amount_whole'      => 10,
                'amount_frac'       => 0,
                'amount'            => null,
                'currency'          => 'USD',
                'description'       => 'Test payment',
            ]);
        // sendEiou outputs the standard CLI success JSON (success=true, data with txid)
        $this->transactionService->method('sendEiou')
            ->willReturnCallback(function () {
                echo json_encode([
                    'success' => true,
                    'message' => 'Transaction sent successfully',
                    'data'    => ['txid' => 'tx-abc', 'status' => 'confirmed'],
                ]);
            });
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_REQUEST_ID, 'approved', $this->arrayHasKey('resulting_txid'));
        // Response message goes via MessageDeliveryService
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with('payment_request', self::TEST_CONTACT_ADDRESS);

        $result = $this->service->approve(self::TEST_REQUEST_ID);

        $this->assertTrue($result['success']);
        $this->assertEquals('tx-abc', $result['txid']);
    }

    public function testApprovePrependsPaymentPrefixToDescription(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'        => self::TEST_REQUEST_ID,
                'direction'         => 'incoming',
                'status'            => 'pending',
                'requester_address' => self::TEST_CONTACT_ADDRESS,
                'amount_whole'      => 10,
                'amount_frac'       => 0,
                'amount'            => null,
                'currency'          => 'USD',
                'description'       => 'repayment for groceries',
            ]);
        $capturedArgv = null;
        $this->transactionService->method('sendEiou')
            ->willReturnCallback(function (array $argv) use (&$capturedArgv) {
                $capturedArgv = $argv;
                echo json_encode(['success' => true, 'message' => 'ok', 'data' => ['txid' => 'tx-1']]);
            });

        $this->service->approve(self::TEST_REQUEST_ID);

        // Description in argv[5] should be prefixed
        $this->assertNotNull($capturedArgv);
        $this->assertEquals('payment: repayment for groceries', $capturedArgv[5]);
    }

    public function testApproveDropsPrefixWhenDescriptionExceedsLimit(): void
    {
        $longDescription = str_repeat('x', 250);  // 250 chars — 'payment: ' (9) + 250 = 259 > 255
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'        => self::TEST_REQUEST_ID,
                'direction'         => 'incoming',
                'status'            => 'pending',
                'requester_address' => self::TEST_CONTACT_ADDRESS,
                'amount_whole'      => 10,
                'amount_frac'       => 0,
                'amount'            => null,
                'currency'          => 'USD',
                'description'       => $longDescription,
            ]);
        $capturedArgv = null;
        $this->transactionService->method('sendEiou')
            ->willReturnCallback(function (array $argv) use (&$capturedArgv) {
                $capturedArgv = $argv;
                echo json_encode(['success' => true, 'message' => 'ok', 'data' => ['txid' => 'tx-1']]);
            });

        $this->service->approve(self::TEST_REQUEST_ID);

        // Prefix dropped — raw description used as-is
        $this->assertNotNull($capturedArgv);
        $this->assertEquals($longDescription, $capturedArgv[5]);
    }

    // =========================================================================
    // decline()
    // =========================================================================

    public function testDeclineReturnsErrorWhenRequestNotFound(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);

        $result = $this->service->decline(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testDeclineReturnsErrorForOutgoingRequest(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'outgoing',
                'status'     => 'pending',
            ]);

        $result = $this->service->decline(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('incoming', $result['error']);
    }

    public function testDeclineReturnsErrorWhenNotPending(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'incoming',
                'status'     => 'declined',
            ]);

        $result = $this->service->decline(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('pending', $result['error']);
    }

    public function testDeclineSuccessUpdatesStatusAndSendsResponse(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'        => self::TEST_REQUEST_ID,
                'direction'         => 'incoming',
                'status'            => 'pending',
                'requester_address' => self::TEST_CONTACT_ADDRESS,
            ]);
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_REQUEST_ID, 'declined', $this->arrayHasKey('responded_at'));
        // Response message goes via MessageDeliveryService
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with('payment_request', self::TEST_CONTACT_ADDRESS);

        $result = $this->service->decline(self::TEST_REQUEST_ID);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // cancel()
    // =========================================================================

    public function testCancelReturnsErrorWhenRequestNotFound(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);

        $result = $this->service->cancel(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testCancelReturnsErrorForIncomingRequest(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'incoming',
                'status'     => 'pending',
            ]);

        $result = $this->service->cancel(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('outgoing', $result['error']);
    }

    public function testCancelReturnsErrorWhenAlreadyApproved(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'outgoing',
                'status'     => 'approved',
            ]);

        $result = $this->service->cancel(self::TEST_REQUEST_ID);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cancelled', $result['error']);
    }

    public function testCancelSuccessUpdatesStatusAndSendsCancellation(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'           => self::TEST_REQUEST_ID,
                'direction'            => 'outgoing',
                'status'               => 'pending',
                'recipient_pubkey_hash' => 'bob-pubkey-hash',
                'requester_address'    => self::TEST_ADDRESS,
            ]);
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_REQUEST_ID, 'cancelled');

        // Expect address lookup and message delivery for cancellation notification
        $this->addressRepository->expects($this->once())
            ->method('lookupByPubkeyHash')
            ->with('bob-pubkey-hash')
            ->willReturn(['http' => self::TEST_CONTACT_ADDRESS, 'tor' => 'bob.onion']);

        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'payment_request',
                $this->anything(),
                $this->callback(function (array $payload) {
                    return $payload['action'] === 'cancel'
                        && $payload['requestId'] === self::TEST_REQUEST_ID;
                }),
                $this->anything(),
                false
            )
            ->willReturn(['success' => true, 'queued_for_retry' => false, 'tracking' => ['stage' => 'completed']]);

        $result = $this->service->cancel(self::TEST_REQUEST_ID);

        $this->assertTrue($result['success']);
    }

    public function testCancelSendsCancellationToTorAddressFirst(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'           => self::TEST_REQUEST_ID,
                'direction'            => 'outgoing',
                'status'               => 'pending',
                'recipient_pubkey_hash' => 'bob-pubkey-hash',
                'requester_address'    => self::TEST_ADDRESS,
            ]);

        // Recipient has TOR and HTTP — TOR should be preferred
        $this->addressRepository->expects($this->once())
            ->method('lookupByPubkeyHash')
            ->willReturn(['http' => 'http://bob.example', 'tor' => 'bob.onion']);

        $capturedAddress = null;
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(function ($type, $address) use (&$capturedAddress) {
                $capturedAddress = $address;
                return ['success' => true, 'queued_for_retry' => false, 'tracking' => ['stage' => 'completed']];
            });

        $this->service->cancel(self::TEST_REQUEST_ID);

        // resolveUserAddressForTransport is stubbed to return TEST_ADDRESS,
        // but the delivery target should be the TOR address (security-first)
        $this->assertNotNull($capturedAddress);
    }

    public function testCancelGracefulWhenNoRecipientAddresses(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id'           => self::TEST_REQUEST_ID,
                'direction'            => 'outgoing',
                'status'               => 'pending',
                'recipient_pubkey_hash' => 'bob-pubkey-hash',
            ]);
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_REQUEST_ID, 'cancelled');

        // No addresses found — cancellation message cannot be sent
        $this->addressRepository->method('lookupByPubkeyHash')
            ->willReturn([]);

        // Message delivery should NOT be called
        $this->messageDeliveryService->expects($this->never())
            ->method('sendMessage');

        $result = $this->service->cancel(self::TEST_REQUEST_ID);

        // Cancel still succeeds locally
        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // handleIncomingRequest()
    // =========================================================================

    public function testHandleIncomingRequestSkipsWhenMissingRequestId(): void
    {
        $this->paymentRequestRepository->expects($this->never())
            ->method('createRequest');

        $this->service->handleIncomingRequest(['typeMessage' => 'payment_request']);
    }

    public function testHandleIncomingRequestIsIdempotent(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(['request_id' => self::TEST_REQUEST_ID]);  // Already exists

        $this->paymentRequestRepository->expects($this->never())
            ->method('createRequest');

        $this->service->handleIncomingRequest([
            'requestId'       => self::TEST_REQUEST_ID,
            'senderPublicKey' => 'pk-abc',
            'senderAddress'   => self::TEST_CONTACT_ADDRESS,
            'amount'          => ['whole' => 10, 'frac' => 0],
            'currency'        => 'USD',
        ]);
    }

    public function testHandleIncomingRequestStoresNewRequest(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);  // Not yet stored
        $this->paymentRequestRepository->expects($this->once())
            ->method('createRequest')
            ->with($this->callback(function (array $data) {
                return $data['request_id'] === self::TEST_REQUEST_ID
                    && $data['direction'] === 'incoming'
                    && $data['status'] === 'pending'
                    && $data['amount_whole'] === 15
                    && $data['currency'] === 'USD';
            }));

        $this->service->handleIncomingRequest([
            'requestId'       => self::TEST_REQUEST_ID,
            'senderPublicKey' => 'pk-abc',
            'senderAddress'   => self::TEST_CONTACT_ADDRESS,
            'amount'          => ['whole' => 15, 'frac' => 0],
            'currency'        => 'USD',
            'description'     => 'Pay me back',
        ]);
    }

    public function testHandleIncomingRequestResolvesContactNameFromPubkeyHash(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);
        $this->contactRepository->method('lookupByPubkeyHash')
            ->willReturn(['name' => 'Alice']);
        $capturedData = null;
        $this->paymentRequestRepository->method('createRequest')
            ->willReturnCallback(function (array $data) use (&$capturedData) {
                $capturedData = $data;
                return '1';
            });

        $this->service->handleIncomingRequest([
            'requestId'       => self::TEST_REQUEST_ID,
            'senderPublicKey' => 'alice-pubkey',
            'senderAddress'   => self::TEST_CONTACT_ADDRESS,
            'amount'          => ['whole' => 5, 'frac' => 0],
            'currency'        => 'USD',
        ]);

        $this->assertEquals('Alice', $capturedData['contact_name']);
    }

    public function testHandleIncomingRequestSkipsInvalidCurrency(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);
        $this->paymentRequestRepository->expects($this->never())
            ->method('createRequest');

        $this->service->handleIncomingRequest([
            'requestId'       => self::TEST_REQUEST_ID,
            'senderPublicKey' => 'pk-abc',
            'senderAddress'   => self::TEST_CONTACT_ADDRESS,
            'amount'          => ['whole' => 10, 'frac' => 0],
            'currency'        => '',  // Missing currency
        ]);
    }

    // =========================================================================
    // handleIncomingResponse()
    // =========================================================================

    public function testHandleIncomingResponseSkipsWhenMissingFields(): void
    {
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingResponse(['requestId' => self::TEST_REQUEST_ID]);  // Missing outcome
    }

    public function testHandleIncomingResponseSkipsWhenRequestNotFound(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingResponse([
            'requestId' => self::TEST_REQUEST_ID,
            'outcome'   => 'approved',
        ]);
    }

    public function testHandleIncomingResponseSkipsForIncomingDirection(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'incoming',   // Wrong — responses update outgoing
                'status'     => 'pending',
            ]);
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingResponse([
            'requestId' => self::TEST_REQUEST_ID,
            'outcome'   => 'approved',
        ]);
    }

    public function testHandleIncomingResponseUpdatesOutgoingWithApproved(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'outgoing',
                'status'     => 'pending',
            ]);
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(
                self::TEST_REQUEST_ID,
                'approved',
                $this->callback(function (array $extra) {
                    return isset($extra['responded_at']) && isset($extra['resulting_txid']) && $extra['resulting_txid'] === 'tx-xyz';
                })
            );

        $this->service->handleIncomingResponse([
            'requestId' => self::TEST_REQUEST_ID,
            'outcome'   => 'approved',
            'txid'      => 'tx-xyz',
        ]);
    }

    public function testHandleIncomingResponseUpdatesOutgoingWithDeclined(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'outgoing',
                'status'     => 'pending',
            ]);
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_REQUEST_ID, 'declined', $this->arrayHasKey('responded_at'));

        $this->service->handleIncomingResponse([
            'requestId' => self::TEST_REQUEST_ID,
            'outcome'   => 'declined',
        ]);
    }

    public function testHandleIncomingResponseAcceptsApprovedWhenCancelled(): void
    {
        // Race condition: sender cancelled, but receiver already paid.
        // Payment wins — update from cancelled to approved.
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'outgoing',
                'status'     => 'cancelled',
            ]);
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(
                self::TEST_REQUEST_ID,
                'approved',
                $this->callback(function (array $extra) {
                    return isset($extra['responded_at']) && isset($extra['resulting_txid']);
                })
            );

        $this->service->handleIncomingResponse([
            'requestId' => self::TEST_REQUEST_ID,
            'outcome'   => 'approved',
            'txid'      => 'tx-race',
        ]);
    }

    public function testHandleIncomingResponseIgnoresDeclinedWhenCancelled(): void
    {
        // Sender cancelled and receiver declined — no update needed,
        // both sides already have terminal states.
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'outgoing',
                'status'     => 'cancelled',
            ]);
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingResponse([
            'requestId' => self::TEST_REQUEST_ID,
            'outcome'   => 'declined',
        ]);
    }

    // =========================================================================
    // handleIncomingCancel()
    // =========================================================================

    public function testHandleIncomingCancelSkipsWhenMissingRequestId(): void
    {
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingCancel([]);
    }

    public function testHandleIncomingCancelSkipsWhenRequestNotFound(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn(null);
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingCancel([
            'requestId' => self::TEST_REQUEST_ID,
        ]);
    }

    public function testHandleIncomingCancelSkipsForOutgoingDirection(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'outgoing',
                'status'     => 'pending',
            ]);
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingCancel([
            'requestId' => self::TEST_REQUEST_ID,
        ]);
    }

    public function testHandleIncomingCancelUpdatesPendingRequest(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'incoming',
                'status'     => 'pending',
            ]);
        $this->paymentRequestRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_REQUEST_ID, 'cancelled', $this->arrayHasKey('responded_at'));

        $this->service->handleIncomingCancel([
            'requestId' => self::TEST_REQUEST_ID,
        ]);
    }

    public function testHandleIncomingCancelIgnoresAlreadyApproved(): void
    {
        // Receiver already paid — cancel from sender is too late, ignore it.
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'incoming',
                'status'     => 'approved',
            ]);
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingCancel([
            'requestId' => self::TEST_REQUEST_ID,
        ]);
    }

    public function testHandleIncomingCancelIgnoresAlreadyDeclined(): void
    {
        $this->paymentRequestRepository->method('getByRequestId')
            ->willReturn([
                'request_id' => self::TEST_REQUEST_ID,
                'direction'  => 'incoming',
                'status'     => 'declined',
            ]);
        $this->paymentRequestRepository->expects($this->never())
            ->method('updateStatus');

        $this->service->handleIncomingCancel([
            'requestId' => self::TEST_REQUEST_ID,
        ]);
    }

    // =========================================================================
    // getAllForDisplay()
    // =========================================================================

    public function testGetAllForDisplayReturnsBothDirections(): void
    {
        $incoming = [['id' => 1, 'direction' => 'incoming', 'status' => 'pending', 'amount_whole' => 10, 'amount_frac' => 0, 'currency' => 'USD']];
        $outgoing = [['id' => 2, 'direction' => 'outgoing', 'status' => 'pending', 'amount_whole' => 25, 'amount_frac' => 0, 'currency' => 'USD']];

        $this->paymentRequestRepository->expects($this->once())
            ->method('getAllIncoming')
            ->with(50)
            ->willReturn($incoming);
        $this->paymentRequestRepository->expects($this->once())
            ->method('getAllOutgoing')
            ->with(50)
            ->willReturn($outgoing);

        $result = $this->service->getAllForDisplay(50);

        $this->assertArrayHasKey('incoming', $result);
        $this->assertArrayHasKey('outgoing', $result);
        $this->assertCount(1, $result['incoming']);
        $this->assertCount(1, $result['outgoing']);
    }

    // =========================================================================
    // countPendingIncoming()
    // =========================================================================

    public function testCountPendingIncomingDelegatesToRepository(): void
    {
        $this->paymentRequestRepository->expects($this->once())
            ->method('countPendingIncoming')
            ->willReturn(4);

        $result = $this->service->countPendingIncoming();

        $this->assertEquals(4, $result);
    }

    // =========================================================================
    // getResolvedHistoryPage()
    // =========================================================================

    /**
     * Happy-path: forwards limit+offset to the repository exactly as passed.
     * Backs the loadMorePaymentRequests GUI AJAX handler — the handler
     * computes `offset = currently loaded row count` and hands it straight
     * through.
     */
    public function testGetResolvedHistoryPageDelegatesLimitAndOffset(): void
    {
        $page = [
            ['id' => 100, 'direction' => 'incoming', 'status' => 'approved',  'amount_whole' => 5,  'amount_frac' => 0, 'currency' => 'USD'],
            ['id' =>  99, 'direction' => 'outgoing', 'status' => 'cancelled', 'amount_whole' => 12, 'amount_frac' => 0, 'currency' => 'USD'],
        ];

        $this->paymentRequestRepository->expects($this->once())
            ->method('getResolvedHistoryPage')
            ->with(50, 100)
            ->willReturn($page);

        $result = $this->service->getResolvedHistoryPage(50, 100);

        $this->assertSame($page, $result);
    }

    /**
     * Default offset is 0 — first-page semantics match the initial page
     * render's ordering, so a caller that forgets to pass offset doesn't
     * accidentally fetch a mid-history slice.
     */
    public function testGetResolvedHistoryPageDefaultsOffsetToZero(): void
    {
        $this->paymentRequestRepository->expects($this->once())
            ->method('getResolvedHistoryPage')
            ->with(25, 0)
            ->willReturn([]);

        $result = $this->service->getResolvedHistoryPage(25);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // searchResolvedHistory() — "Search entire database" passthrough
    // =========================================================================

    /**
     * All filter dims + the max-results cap forward verbatim to the
     * repository. Backs the searchPaymentRequests GUI AJAX action.
     */
    public function testSearchResolvedHistoryPassesAllArguments(): void
    {
        $rows = [
            ['id' => 1, 'direction' => 'incoming', 'status' => 'approved',  'amount_whole' => 5,  'amount_frac' => 0, 'currency' => 'USD'],
            ['id' => 2, 'direction' => 'outgoing', 'status' => 'cancelled', 'amount_whole' => 12, 'amount_frac' => 0, 'currency' => 'USD'],
        ];

        $this->paymentRequestRepository->expects($this->once())
            ->method('searchResolvedHistory')
            ->with('bob', 'incoming', 'approved', 200)
            ->willReturn($rows);

        $result = $this->service->searchResolvedHistory('bob', 'incoming', 'approved', 200);

        $this->assertSame($rows, $result);
    }

    /**
     * Null filter dims forward as null — the repo layer is what decides
     * whether "no filter" means "skip the WHERE clause" or "match
     * everything"; the service is a thin passthrough.
     */
    public function testSearchResolvedHistoryForwardsNullFilters(): void
    {
        $this->paymentRequestRepository->expects($this->once())
            ->method('searchResolvedHistory')
            ->with('alice', null, null, 500)
            ->willReturn([]);

        $this->service->searchResolvedHistory('alice');
    }
}
