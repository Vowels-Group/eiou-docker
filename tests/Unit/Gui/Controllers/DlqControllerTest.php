<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Gui\Controllers\DlqController;
use Eiou\Gui\Includes\Session;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Contracts\MessageDeliveryServiceInterface;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\Constants;

/**
 * Unit tests for DlqController
 *
 * Covers:
 * - Constructor / instantiation
 * - extractTxidFromMessageId() (via reflection) — txid parsing from DLQ message_id
 * - handleRetry() transaction expires_at refresh and status reset
 * - handleRetry() rejection of p2p/rp2p message types
 */
#[CoversClass(DlqController::class)]
class DlqControllerTest extends TestCase
{
    private MockObject|Session $session;
    private MockObject|DeadLetterQueueRepository $dlqRepository;
    private MockObject|MessageDeliveryServiceInterface $messageDeliveryService;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TransactionRepository $transactionRepository;
    private DlqController $controller;

    private const TEST_TXID     = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_DLQ_ID   = 42;
    private const TEST_MICROTIME = '1706478900123456';
    private const TEST_RECIPIENT = 'http://relay.example.com';
    private const TEST_CSRF     = 'test-csrf-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->session              = $this->createMock(Session::class);
        $this->dlqRepository        = $this->createMock(DeadLetterQueueRepository::class);
        $this->messageDeliveryService = $this->createMock(MessageDeliveryServiceInterface::class);
        $this->transportUtility     = $this->getMockBuilder(TransportUtilityService::class)
            ->disableOriginalConstructor()->getMock();
        $this->transactionRepository = $this->createMock(TransactionRepository::class);

        $this->controller = new DlqController(
            $this->session,
            $this->dlqRepository,
            $this->messageDeliveryService,
            $this->transportUtility,
            $this->transactionRepository
        );
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    #[Test]
    public function constructorAcceptsDependencies(): void
    {
        $this->assertInstanceOf(DlqController::class, $this->controller);
    }

    // =========================================================================
    // extractTxidFromMessageId() — via reflection
    // =========================================================================

    /**
     * @return string|null
     */
    private function callExtractTxid(string $messageId): ?string
    {
        $method = new \ReflectionMethod(DlqController::class, 'extractTxidFromMessageId');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $messageId);
    }

    #[Test]
    public function extractTxidParsesStandardSendFormat(): void
    {
        $messageId = 'send-' . self::TEST_TXID . '-' . self::TEST_MICROTIME;
        $result = $this->callExtractTxid($messageId);
        $this->assertSame(self::TEST_TXID, $result);
    }

    #[Test]
    public function extractTxidParsesRelayFormat(): void
    {
        $messageId = 'relay-' . self::TEST_TXID . '-' . self::TEST_MICROTIME;
        $result = $this->callExtractTxid($messageId);
        $this->assertSame(self::TEST_TXID, $result);
    }

    #[Test]
    public function extractTxidReturnsNullForUnknownPrefix(): void
    {
        $result = $this->callExtractTxid('contact-abc123-9999999');
        $this->assertNull($result);
    }

    #[Test]
    public function extractTxidReturnsNullForMissingMicrotimeSuffix(): void
    {
        // No trailing -digits — the regex won't strip anything, so result equals input
        $result = $this->callExtractTxid('send-' . self::TEST_TXID);
        $this->assertNull($result);
    }

    #[Test]
    public function extractTxidReturnsNullForEmptyString(): void
    {
        $result = $this->callExtractTxid('');
        $this->assertNull($result);
    }

    // =========================================================================
    // handleRetry() — transaction expires_at refresh
    // =========================================================================

    #[Test]
    public function retryTransactionItemRefreshesExpiresAtBeforeSend(): void
    {
        $_POST = [
            'action'     => 'dlqRetry',
            'dlq_id'     => (string)self::TEST_DLQ_ID,
            'csrf_token' => self::TEST_CSRF,
        ];

        $this->session->method('validateCSRFToken')->willReturn(true);

        $dlqItem = [
            'id'               => self::TEST_DLQ_ID,
            'message_type'     => 'transaction',
            'message_id'       => 'send-' . self::TEST_TXID . '-' . self::TEST_MICROTIME,
            'recipient_address'=> self::TEST_RECIPIENT,
            'payload'          => ['type' => 'send', 'txid' => self::TEST_TXID],
            'status'           => 'pending',
        ];
        $this->dlqRepository->method('getById')->with(self::TEST_DLQ_ID)->willReturn($dlqItem);

        // Transaction is in 'sending' (not cancelled) — no status reset needed
        $this->transactionRepository->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn(['txid' => self::TEST_TXID, 'status' => Constants::STATUS_SENDING]);

        // expires_at MUST be refreshed to a future datetime
        $this->transactionRepository->expects($this->once())
            ->method('setExpiresAt')
            ->with(
                self::TEST_TXID,
                $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
            );

        // Status should NOT be changed since it's not 'cancelled'
        $this->transactionRepository->expects($this->never())
            ->method('updateStatus');

        // retryFromDlq will be called via messageDeliveryService
        $this->messageDeliveryService->method('retryFromDlq')
            ->willReturn(['success' => true]);

        ob_start();
        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // exit() calls are expected after JSON response
        }
        ob_end_clean();
    }

    #[Test]
    public function retryTransactionItemResetsCancelledStatusToSending(): void
    {
        $_POST = [
            'action'     => 'dlqRetry',
            'dlq_id'     => (string)self::TEST_DLQ_ID,
            'csrf_token' => self::TEST_CSRF,
        ];

        $this->session->method('validateCSRFToken')->willReturn(true);

        $dlqItem = [
            'id'               => self::TEST_DLQ_ID,
            'message_type'     => 'transaction',
            'message_id'       => 'send-' . self::TEST_TXID . '-' . self::TEST_MICROTIME,
            'recipient_address'=> self::TEST_RECIPIENT,
            'payload'          => ['type' => 'send', 'txid' => self::TEST_TXID],
            'status'           => 'pending',
        ];
        $this->dlqRepository->method('getById')->with(self::TEST_DLQ_ID)->willReturn($dlqItem);

        // Transaction was expired-and-cancelled — must be reset to 'sending'
        $this->transactionRepository->method('getByTxid')
            ->with(self::TEST_TXID)
            ->willReturn(['txid' => self::TEST_TXID, 'status' => Constants::STATUS_CANCELLED]);

        $this->transactionRepository->method('setExpiresAt');

        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with(self::TEST_TXID, Constants::STATUS_SENDING, true);

        $this->messageDeliveryService->method('retryFromDlq')
            ->willReturn(['success' => true]);

        ob_start();
        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // exit() expected
        }
        ob_end_clean();
    }

    #[Test]
    public function retryRejectsP2pMessageType(): void
    {
        $_POST = [
            'action'     => 'dlqRetry',
            'dlq_id'     => (string)self::TEST_DLQ_ID,
            'csrf_token' => self::TEST_CSRF,
        ];

        $this->session->method('validateCSRFToken')->willReturn(true);

        $dlqItem = [
            'id'           => self::TEST_DLQ_ID,
            'message_type' => 'p2p',
            'message_id'   => 'p2p-hash-123',
            'status'       => 'pending',
        ];
        $this->dlqRepository->method('getById')->willReturn($dlqItem);

        // Neither expires_at nor status should be touched for p2p
        $this->transactionRepository->expects($this->never())->method('setExpiresAt');
        $this->transactionRepository->expects($this->never())->method('updateStatus');
        $this->messageDeliveryService->expects($this->never())->method('retryFromDlq');

        ob_start();
        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // exit() expected
        }
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success'] ?? true);
    }

    #[Test]
    public function retryRejectsRp2pMessageType(): void
    {
        $_POST = [
            'action'     => 'dlqRetry',
            'dlq_id'     => (string)self::TEST_DLQ_ID,
            'csrf_token' => self::TEST_CSRF,
        ];

        $this->session->method('validateCSRFToken')->willReturn(true);

        $dlqItem = [
            'id'           => self::TEST_DLQ_ID,
            'message_type' => 'rp2p',
            'message_id'   => 'rp2p-hash-456',
            'status'       => 'pending',
        ];
        $this->dlqRepository->method('getById')->willReturn($dlqItem);

        $this->messageDeliveryService->expects($this->never())->method('retryFromDlq');

        ob_start();
        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // exit() expected
        }
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['success'] ?? true);
    }
}
