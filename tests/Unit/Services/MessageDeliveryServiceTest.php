<?php
/**
 * Unit Tests for MessageDeliveryService
 *
 * Tests message delivery functionality including:
 * - Sending messages with tracking (sync and async)
 * - Retry logic with exponential backoff
 * - Dead letter queue handling
 * - Delivery status tracking
 * - Acknowledgment building
 * - Metrics recording
 * - Maintenance operations
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\MessageDeliveryService;
use Eiou\Database\MessageDeliveryRepository;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Database\DeliveryMetricsRepository;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Core\UserContext;
use Exception;

#[CoversClass(MessageDeliveryService::class)]
class MessageDeliveryServiceTest extends TestCase
{
    private MockObject|MessageDeliveryRepository $deliveryRepository;
    private MockObject|DeadLetterQueueRepository $dlqRepository;
    private MockObject|DeliveryMetricsRepository $metricsRepository;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TimeUtilityService $timeUtility;
    private MockObject|UserContext $userContext;
    private MessageDeliveryService $service;

    /**
     * Test constants
     */
    private const TEST_MESSAGE_ID = 'test-message-id-abc123def456789012345678901234567890123456789012345678901234';
    private const TEST_RECIPIENT = 'http://test.example.com';
    private const TEST_USER_HTTP_ADDRESS = 'http://user.example.com';
    private const TEST_USER_PUBLIC_KEY = 'test-user-public-key-123456789012345678901234567890';
    private const TEST_MICROTIME = 1234567890123456;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deliveryRepository = $this->createMock(MessageDeliveryRepository::class);
        $this->dlqRepository = $this->createMock(DeadLetterQueueRepository::class);
        $this->metricsRepository = $this->createMock(DeliveryMetricsRepository::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->timeUtility = $this->createMock(TimeUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);

        // Default time utility mock behavior
        $this->timeUtility->method('getCurrentMicrotime')
            ->willReturn(self::TEST_MICROTIME);

        // Default user context mock behavior
        $this->userContext->method('getHttpAddress')
            ->willReturn(self::TEST_USER_HTTP_ADDRESS);
        $this->userContext->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBLIC_KEY);

        $this->service = new MessageDeliveryService(
            $this->deliveryRepository,
            $this->dlqRepository,
            $this->metricsRepository,
            $this->transportUtility,
            $this->timeUtility,
            $this->userContext,
            5,    // maxRetries
            2,    // baseDelay
            0.2   // jitterFactor
        );
    }

    // =========================================================================
    // sendMessage() Tests
    // =========================================================================

    /**
     * Test sendMessage with successful sync delivery
     *
     * Note: updateStage is called multiple times - first with 'sent' before delivery,
     * then possibly again with the response status stage.
     */
    public function testSendMessageWithSuccessfulSyncDelivery(): void
    {
        $payload = ['type' => 'transaction', 'amount' => 100];

        $this->deliveryRepository->expects($this->once())
            ->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->expects($this->once())
            ->method('createDelivery');

        // updateStage may be called multiple times: first 'sent', then response stage
        $this->deliveryRepository->expects($this->atLeastOnce())
            ->method('updateStage');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'received']),
                'signature' => 'test-signature',
                'nonce' => 12345
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'pending']);

        $result = $this->service->sendMessage(
            'transaction',
            self::TEST_RECIPIENT,
            $payload,
            self::TEST_MESSAGE_ID
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(self::TEST_MESSAGE_ID, $result['messageId']);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('tracking', $result);
    }

    /**
     * Test sendMessage with async mode
     */
    public function testSendMessageWithAsyncMode(): void
    {
        $payload = ['type' => 'p2p', 'hash' => 'test-hash'];

        $this->deliveryRepository->expects($this->once())
            ->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->expects($this->once())
            ->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'accepted']),
                'signature' => 'test-signature',
                'nonce' => 12345
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $result = $this->service->sendMessage(
            'p2p',
            self::TEST_RECIPIENT,
            $payload,
            null,
            true // async
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['messageId']);
        $this->assertArrayHasKey('tracking', $result);
    }

    /**
     * Test sendMessage generates message ID from hash if available
     */
    public function testSendMessageGeneratesMessageIdFromHash(): void
    {
        $testHash = 'payload-hash-123456';
        $payload = ['type' => 'transaction', 'hash' => $testHash];

        $this->deliveryRepository->expects($this->once())
            ->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->expects($this->once())
            ->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'received']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'pending']);

        $result = $this->service->sendMessage(
            'transaction',
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertEquals($testHash, $result['messageId']);
    }

    // =========================================================================
    // sendWithTracking() Tests
    // =========================================================================

    /**
     * Test sendWithTracking creates delivery record when not exists
     */
    public function testSendWithTrackingCreatesDeliveryRecord(): void
    {
        $payload = ['type' => 'contact', 'data' => 'test'];

        $this->deliveryRepository->expects($this->once())
            ->method('deliveryExists')
            ->with('contact', self::TEST_MESSAGE_ID)
            ->willReturn(false);

        $this->deliveryRepository->expects($this->once())
            ->method('createDelivery')
            ->with('contact', self::TEST_MESSAGE_ID, self::TEST_RECIPIENT, 'pending', 5, $payload);

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'received']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'pending']);

        $result = $this->service->sendWithTracking(
            'contact',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
    }

    /**
     * Test sendWithTracking updates payload when record exists
     */
    public function testSendWithTrackingUpdatesPayloadWhenExists(): void
    {
        $payload = ['type' => 'transaction', 'amount' => 200];

        $this->deliveryRepository->expects($this->once())
            ->method('deliveryExists')
            ->willReturn(true);

        $this->deliveryRepository->expects($this->once())
            ->method('updatePayload')
            ->with('transaction', self::TEST_MESSAGE_ID, $payload);

        $this->deliveryRepository->expects($this->never())
            ->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'acknowledged']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $result = $this->service->sendWithTracking(
            'transaction',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['stage']);
    }

    /**
     * Test sendWithTracking handles rejection without retry
     */
    public function testSendWithTrackingHandlesRejection(): void
    {
        $payload = ['type' => 'transaction'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode([
                    'status' => 'rejected',
                    'message' => 'Insufficient balance'
                ]),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->expects($this->once())
            ->method('markFailed');

        $result = $this->service->sendWithTracking(
            'transaction',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('rejected', $result['stage']);
        $this->assertFalse($result['retry']);
    }

    /**
     * Test sendWithTracking moves to DLQ after exhausted retries
     */
    public function testSendWithTrackingMovesToDlqAfterExhaustedRetries(): void
    {
        $payload = ['type' => 'p2p'];

        // Create service with 0 max retries for quick test
        $service = new MessageDeliveryService(
            $this->deliveryRepository,
            $this->dlqRepository,
            $this->metricsRepository,
            $this->transportUtility,
            $this->timeUtility,
            $this->userContext,
            0,    // maxRetries = 0 means only 1 attempt
            1,    // baseDelay
            0     // no jitter
        );

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'error', 'message' => 'Network error']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent', 'retry_count' => 1]);

        $this->dlqRepository->expects($this->once())
            ->method('addToQueue');

        $this->deliveryRepository->expects($this->once())
            ->method('markFailed');

        $result = $service->sendWithTracking(
            'p2p',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('failed', $result['stage']);
        $this->assertTrue($result['dlq']);
    }

    // =========================================================================
    // sendWithTrackingAsync() Tests
    // =========================================================================

    /**
     * Test sendWithTrackingAsync returns immediately on failure
     */
    public function testSendWithTrackingAsyncReturnsImmediatelyOnFailure(): void
    {
        $payload = ['type' => 'rp2p'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => '',
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->expects($this->once())
            ->method('incrementRetry')
            ->with('rp2p', self::TEST_MESSAGE_ID, 0, 'No response received from recipient');

        $result = $this->service->sendWithTrackingAsync(
            'rp2p',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('queued_for_retry', $result['stage']);
        $this->assertTrue($result['async']);
        $this->assertEquals(1, $result['attempts']);
    }

    /**
     * Test sendWithTrackingAsync succeeds on first attempt
     */
    public function testSendWithTrackingAsyncSucceedsOnFirstAttempt(): void
    {
        $payload = ['type' => 'p2p'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'inserted']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $result = $this->service->sendWithTrackingAsync(
            'p2p',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['stage']);
        $this->assertTrue($result['async']);
    }

    /**
     * Test sendWithTrackingAsync handles transport exception
     */
    public function testSendWithTrackingAsyncHandlesTransportException(): void
    {
        $payload = ['type' => 'transaction'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willThrowException(new Exception('Connection timeout'));

        $this->deliveryRepository->expects($this->once())
            ->method('incrementRetry')
            ->with('transaction', self::TEST_MESSAGE_ID, 0, 'Transport exception: Connection timeout');

        $result = $this->service->sendWithTrackingAsync(
            'transaction',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('queued_for_retry', $result['stage']);
        $this->assertStringContainsString('Connection timeout', $result['error']);
    }

    // =========================================================================
    // processRetryQueue() Tests
    // =========================================================================

    /**
     * Test processRetryQueue with no messages
     */
    public function testProcessRetryQueueWithNoMessages(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getMessagesForRetry')
            ->with(10)
            ->willReturn([]);

        $result = $this->service->processRetryQueue(10);

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['succeeded']);
        $this->assertEquals(0, $result['failed']);
    }

    /**
     * Test processRetryQueue moves message without payload to DLQ
     */
    public function testProcessRetryQueueMovesNoPayloadToDlq(): void
    {
        $messages = [[
            'message_type' => 'transaction',
            'message_id' => self::TEST_MESSAGE_ID,
            'recipient_address' => self::TEST_RECIPIENT,
            'retry_count' => 2,
            'payload' => null
        ]];

        $this->deliveryRepository->expects($this->once())
            ->method('getMessagesForRetry')
            ->willReturn($messages);

        $this->dlqRepository->expects($this->once())
            ->method('addToQueue')
            ->with(
                'transaction',
                self::TEST_MESSAGE_ID,
                ['note' => 'Payload not available for retry'],
                self::TEST_RECIPIENT,
                2,
                'No payload stored for retry'
            );

        $this->deliveryRepository->expects($this->once())
            ->method('markFailed')
            ->with('transaction', self::TEST_MESSAGE_ID, 'No payload stored for retry');

        $result = $this->service->processRetryQueue();

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(1, $result['no_payload']);
    }

    /**
     * Test processRetryQueue successfully retries message
     */
    public function testProcessRetryQueueSuccessfullyRetriesMessage(): void
    {
        $payload = ['type' => 'transaction', 'amount' => 100];
        $messages = [[
            'message_type' => 'transaction',
            'message_id' => self::TEST_MESSAGE_ID,
            'recipient_address' => self::TEST_RECIPIENT,
            'retry_count' => 1,
            'payload' => json_encode($payload)
        ]];

        $this->deliveryRepository->expects($this->once())
            ->method('getMessagesForRetry')
            ->willReturn($messages);

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'acknowledged']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $result = $this->service->processRetryQueue();

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['succeeded']);
        $this->assertEquals(0, $result['failed']);
    }

    // =========================================================================
    // hasExhaustedRetries() Tests
    // =========================================================================

    /**
     * Test hasExhaustedRetries returns true when retries exhausted
     */
    public function testHasExhaustedRetriesReturnsTrueWhenExhausted(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->with('transaction', self::TEST_MESSAGE_ID)
            ->willReturn([
                'retry_count' => 5,
                'max_retries' => 5
            ]);

        $result = $this->service->hasExhaustedRetries('transaction', self::TEST_MESSAGE_ID);

        $this->assertTrue($result);
    }

    /**
     * Test hasExhaustedRetries returns false when retries remain
     */
    public function testHasExhaustedRetriesReturnsFalseWhenRetriesRemain(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn([
                'retry_count' => 2,
                'max_retries' => 5
            ]);

        $result = $this->service->hasExhaustedRetries('transaction', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    /**
     * Test hasExhaustedRetries returns false when delivery not found
     */
    public function testHasExhaustedRetriesReturnsFalseWhenNotFound(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(null);

        $result = $this->service->hasExhaustedRetries('transaction', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    // =========================================================================
    // hasDeliveryFailed() Tests
    // =========================================================================

    /**
     * Test hasDeliveryFailed returns true when stage is failed
     */
    public function testHasDeliveryFailedReturnsTrueWhenStageFailed(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn([
                'delivery_stage' => 'failed',
                'retry_count' => 3,
                'max_retries' => 5
            ]);

        $result = $this->service->hasDeliveryFailed('transaction', self::TEST_MESSAGE_ID);

        $this->assertTrue($result);
    }

    /**
     * Test hasDeliveryFailed returns true when retries exhausted and not in success stage
     */
    public function testHasDeliveryFailedReturnsTrueWhenExhaustedAndNotCompleted(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn([
                'delivery_stage' => 'sent',
                'retry_count' => 5,
                'max_retries' => 5
            ]);

        $result = $this->service->hasDeliveryFailed('transaction', self::TEST_MESSAGE_ID);

        $this->assertTrue($result);
    }

    /**
     * Test hasDeliveryFailed returns false when completed
     */
    public function testHasDeliveryFailedReturnsFalseWhenCompleted(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn([
                'delivery_stage' => 'completed',
                'retry_count' => 5,
                'max_retries' => 5
            ]);

        $result = $this->service->hasDeliveryFailed('transaction', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getDeliveryStatus() Tests
    // =========================================================================

    /**
     * Test getDeliveryStatus returns null when not found
     */
    public function testGetDeliveryStatusReturnsNullWhenNotFound(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(null);

        $result = $this->service->getDeliveryStatus('transaction', self::TEST_MESSAGE_ID);

        $this->assertNull($result);
    }

    /**
     * Test getDeliveryStatus returns correct status array
     */
    public function testGetDeliveryStatusReturnsCorrectStatusArray(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn([
                'delivery_stage' => 'sent',
                'retry_count' => 2,
                'max_retries' => 5,
                'last_error' => 'Connection failed',
                'next_retry_at' => '2025-01-01 12:00:00'
            ]);

        $result = $this->service->getDeliveryStatus('transaction', self::TEST_MESSAGE_ID);

        $this->assertEquals('sent', $result['stage']);
        $this->assertEquals(2, $result['retry_count']);
        $this->assertEquals(5, $result['max_retries']);
        $this->assertEquals('Connection failed', $result['last_error']);
        $this->assertFalse($result['is_failed']);
        $this->assertFalse($result['is_completed']);
        $this->assertFalse($result['retries_exhausted']);
    }

    // =========================================================================
    // processExhaustedRetries() Tests
    // =========================================================================

    /**
     * Test processExhaustedRetries moves exhausted to DLQ
     */
    public function testProcessExhaustedRetriesMovesToDlq(): void
    {
        $exhaustedDeliveries = [
            [
                'message_type' => 'transaction',
                'message_id' => 'txid-1',
                'recipient_address' => self::TEST_RECIPIENT,
                'retry_count' => 5,
                'last_error' => 'Max retries exceeded'
            ],
            [
                'message_type' => 'p2p',
                'message_id' => 'p2p-1',
                'recipient_address' => self::TEST_RECIPIENT,
                'retry_count' => 5,
                'last_error' => 'Timeout'
            ]
        ];

        $this->deliveryRepository->expects($this->once())
            ->method('getExhaustedRetries')
            ->willReturn($exhaustedDeliveries);

        $this->dlqRepository->expects($this->exactly(2))
            ->method('existsByMessageId')
            ->willReturn(false);

        $this->dlqRepository->expects($this->exactly(2))
            ->method('addToQueue');

        $this->deliveryRepository->expects($this->exactly(2))
            ->method('markFailed');

        $result = $this->service->processExhaustedRetries();

        $this->assertEquals(2, $result);
    }

    /**
     * Test processExhaustedRetries skips already in DLQ
     */
    public function testProcessExhaustedRetriesSkipsAlreadyInDlq(): void
    {
        $exhaustedDeliveries = [
            [
                'message_type' => 'transaction',
                'message_id' => 'txid-1',
                'recipient_address' => self::TEST_RECIPIENT,
                'retry_count' => 5,
                'last_error' => 'Error'
            ]
        ];

        $this->deliveryRepository->expects($this->once())
            ->method('getExhaustedRetries')
            ->willReturn($exhaustedDeliveries);

        $this->dlqRepository->expects($this->once())
            ->method('existsByMessageId')
            ->with('txid-1')
            ->willReturn(true);

        $this->dlqRepository->expects($this->never())
            ->method('addToQueue');

        $result = $this->service->processExhaustedRetries();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // markCompletedByHash() Tests
    // =========================================================================

    /**
     * Test markCompletedByHash delegates to repository
     */
    public function testMarkCompletedByHashDelegatesToRepository(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('markCompletedByHash')
            ->with('p2p', 'test-hash-123')
            ->willReturn(3);

        $result = $this->service->markCompletedByHash('p2p', 'test-hash-123');

        $this->assertEquals(3, $result);
    }

    // =========================================================================
    // getDeliveryStatistics() Tests
    // =========================================================================

    /**
     * Test getDeliveryStatistics returns statistics with success rate
     */
    public function testGetDeliveryStatisticsReturnsStatisticsWithSuccessRate(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getStatistics')
            ->willReturn([
                'total_count' => 100,
                'completed_count' => 80,
                'failed_count' => 10,
                'pending_count' => 10
            ]);

        $result = $this->service->getDeliveryStatistics();

        $this->assertEquals(100, $result['total_count']);
        $this->assertEquals(80, $result['success_rate']);
        $this->assertEquals(10, $result['failure_rate']);
    }

    /**
     * Test getDeliveryStatistics handles zero total
     */
    public function testGetDeliveryStatisticsHandlesZeroTotal(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getStatistics')
            ->willReturn([
                'total_count' => 0,
                'completed_count' => 0,
                'failed_count' => 0
            ]);

        $result = $this->service->getDeliveryStatistics();

        $this->assertEquals(0, $result['success_rate']);
        $this->assertEquals(0, $result['failure_rate']);
    }

    // =========================================================================
    // getDlqAlertStatus() Tests
    // =========================================================================

    /**
     * Test getDlqAlertStatus returns alert status
     */
    public function testGetDlqAlertStatusReturnsAlertStatus(): void
    {
        $this->dlqRepository->expects($this->once())
            ->method('getPendingCount')
            ->willReturn(15);

        $this->dlqRepository->expects($this->once())
            ->method('getStatistics')
            ->willReturn(['total_count' => 20]);

        $result = $this->service->getDlqAlertStatus(10);

        $this->assertEquals(15, $result['pending_count']);
        $this->assertTrue($result['alert_triggered']);
        $this->assertEquals(10, $result['threshold']);
    }

    /**
     * Test getDlqAlertStatus no alert when below threshold
     */
    public function testGetDlqAlertStatusNoAlertBelowThreshold(): void
    {
        $this->dlqRepository->expects($this->once())
            ->method('getPendingCount')
            ->willReturn(5);

        $this->dlqRepository->expects($this->once())
            ->method('getStatistics')
            ->willReturn([]);

        $result = $this->service->getDlqAlertStatus(10);

        $this->assertFalse($result['alert_triggered']);
    }

    // =========================================================================
    // retryFromDlq() Tests
    // =========================================================================

    /**
     * Test retryFromDlq returns error when item not found
     */
    public function testRetryFromDlqReturnsErrorWhenNotFound(): void
    {
        $this->dlqRepository->expects($this->once())
            ->method('getById')
            ->with(123)
            ->willReturn(null);

        $result = $this->service->retryFromDlq(123, fn() => ['success' => true]);

        $this->assertFalse($result['success']);
        $this->assertEquals('DLQ item not found', $result['error']);
    }

    /**
     * Test retryFromDlq handles successful retry
     */
    public function testRetryFromDlqHandlesSuccessfulRetry(): void
    {
        $dlqItem = [
            'message_type' => 'transaction',
            'message_id' => self::TEST_MESSAGE_ID,
            'payload' => ['type' => 'send'],
            'recipient_address' => self::TEST_RECIPIENT
        ];

        $this->dlqRepository->expects($this->once())
            ->method('getById')
            ->with(123)
            ->willReturn($dlqItem);

        $this->dlqRepository->expects($this->once())
            ->method('markRetrying')
            ->with(123);

        $this->dlqRepository->expects($this->once())
            ->method('markResolved')
            ->with(123);

        $result = $this->service->retryFromDlq(123, fn($payload, $recipient) => ['success' => true]);

        $this->assertTrue($result['success']);
        $this->assertEquals('Message successfully resent from DLQ', $result['message']);
    }

    /**
     * Test retryFromDlq handles failed retry
     */
    public function testRetryFromDlqHandlesFailedRetry(): void
    {
        $dlqItem = [
            'message_type' => 'transaction',
            'message_id' => self::TEST_MESSAGE_ID,
            'payload' => [],
            'recipient_address' => self::TEST_RECIPIENT
        ];

        $this->dlqRepository->expects($this->once())
            ->method('getById')
            ->willReturn($dlqItem);

        $this->dlqRepository->expects($this->once())
            ->method('markRetrying');

        $this->dlqRepository->expects($this->once())
            ->method('returnToPending')
            ->with(123);

        $result = $this->service->retryFromDlq(123, fn() => ['success' => false, 'error' => 'Still failing']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Still failing', $result['error']);
    }

    /**
     * Test retryFromDlq handles exception in callback
     */
    public function testRetryFromDlqHandlesExceptionInCallback(): void
    {
        $dlqItem = [
            'message_type' => 'transaction',
            'message_id' => self::TEST_MESSAGE_ID,
            'payload' => [],
            'recipient_address' => self::TEST_RECIPIENT
        ];

        $this->dlqRepository->expects($this->once())
            ->method('getById')
            ->willReturn($dlqItem);

        $this->dlqRepository->expects($this->once())
            ->method('markRetrying');

        $this->dlqRepository->expects($this->once())
            ->method('returnToPending');

        $result = $this->service->retryFromDlq(123, function () {
            throw new Exception('Callback error');
        });

        $this->assertFalse($result['success']);
        $this->assertEquals('Callback error', $result['error']);
    }

    // =========================================================================
    // buildAcknowledgment() Tests
    // =========================================================================

    /**
     * Test buildAcknowledgment returns valid JSON
     */
    public function testBuildAcknowledgmentReturnsValidJson(): void
    {
        $result = $this->service->buildAcknowledgment('received', self::TEST_MESSAGE_ID, 'Test message');

        $decoded = json_decode($result, true);

        $this->assertNotNull($decoded);
        $this->assertEquals('received', $decoded['status']);
        $this->assertEquals(self::TEST_MESSAGE_ID, $decoded['messageId']);
        $this->assertEquals('Test message', $decoded['message']);
        $this->assertEquals(self::TEST_USER_HTTP_ADDRESS, $decoded['senderAddress']);
        $this->assertEquals(self::TEST_USER_PUBLIC_KEY, $decoded['senderPublicKey']);
        $this->assertEquals(self::TEST_MICROTIME, $decoded['timestamp']);
    }

    /**
     * Test buildAcknowledgment without optional parameters
     */
    public function testBuildAcknowledgmentWithoutOptionalParameters(): void
    {
        $result = $this->service->buildAcknowledgment('inserted');

        $decoded = json_decode($result, true);

        $this->assertEquals('inserted', $decoded['status']);
        $this->assertArrayNotHasKey('messageId', $decoded);
        $this->assertArrayNotHasKey('message', $decoded);
    }

    // =========================================================================
    // acknowledgeReceived() Tests
    // =========================================================================

    /**
     * Test acknowledgeReceived returns proper acknowledgment
     */
    public function testAcknowledgeReceivedReturnsProperAcknowledgment(): void
    {
        $result = $this->service->acknowledgeReceived(self::TEST_MESSAGE_ID);

        $decoded = json_decode($result, true);

        $this->assertEquals('received', $decoded['status']);
        $this->assertEquals(self::TEST_MESSAGE_ID, $decoded['messageId']);
        $this->assertEquals('Message received', $decoded['message']);
    }

    // =========================================================================
    // acknowledgeInserted() Tests
    // =========================================================================

    /**
     * Test acknowledgeInserted returns proper acknowledgment
     */
    public function testAcknowledgeInsertedReturnsProperAcknowledgment(): void
    {
        $result = $this->service->acknowledgeInserted(self::TEST_MESSAGE_ID);

        $decoded = json_decode($result, true);

        $this->assertEquals('inserted', $decoded['status']);
        $this->assertEquals(self::TEST_MESSAGE_ID, $decoded['messageId']);
        $this->assertEquals('Message stored in database', $decoded['message']);
    }

    // =========================================================================
    // acknowledgeForwarded() Tests
    // =========================================================================

    /**
     * Test acknowledgeForwarded returns proper acknowledgment
     */
    public function testAcknowledgeForwardedReturnsProperAcknowledgment(): void
    {
        $result = $this->service->acknowledgeForwarded(self::TEST_MESSAGE_ID, 'http://next-hop.example.com');

        $decoded = json_decode($result, true);

        $this->assertEquals('forwarded', $decoded['status']);
        $this->assertEquals(self::TEST_MESSAGE_ID, $decoded['messageId']);
        $this->assertStringContainsString('http://next-hop.example.com', $decoded['message']);
    }

    /**
     * Test acknowledgeForwarded without next hop
     */
    public function testAcknowledgeForwardedWithoutNextHop(): void
    {
        $result = $this->service->acknowledgeForwarded(self::TEST_MESSAGE_ID);

        $decoded = json_decode($result, true);

        $this->assertEquals('Message forwarded', $decoded['message']);
    }

    // =========================================================================
    // cleanup() Tests
    // =========================================================================

    /**
     * Test cleanup delegates to repositories
     */
    public function testCleanupDelegatesToRepositories(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('deleteOldRecords')
            ->with(30)
            ->willReturn(10);

        $this->dlqRepository->expects($this->once())
            ->method('deleteOldRecords')
            ->with(90)
            ->willReturn(5);

        $result = $this->service->cleanup(30, 90);

        $this->assertEquals(10, $result['delivery_deleted']);
        $this->assertEquals(5, $result['dlq_deleted']);
    }

    // =========================================================================
    // updateStageToForwarded() Tests
    // =========================================================================

    /**
     * Test updateStageToForwarded updates stage correctly
     */
    public function testUpdateStageToForwardedUpdatesStageCorrectly(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(['delivery_stage' => 'inserted']);

        $this->deliveryRepository->expects($this->once())
            ->method('updateStage')
            ->with('transaction', self::TEST_MESSAGE_ID, 'forwarded', $this->anything());

        $result = $this->service->updateStageToForwarded(
            'transaction',
            self::TEST_MESSAGE_ID,
            'http://next.example.com'
        );

        $this->assertTrue($result);
    }

    /**
     * Test updateStageToForwarded returns false when already at forwarded
     */
    public function testUpdateStageToForwardedReturnsFalseWhenAlreadyForwarded(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(['delivery_stage' => 'forwarded']);

        $this->deliveryRepository->expects($this->never())
            ->method('updateStage');

        $result = $this->service->updateStageToForwarded('transaction', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    /**
     * Test updateStageToForwarded returns false when delivery not found
     */
    public function testUpdateStageToForwardedReturnsFalseWhenNotFound(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(null);

        $result = $this->service->updateStageToForwarded('transaction', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    // =========================================================================
    // markDeliveryCompleted() Tests
    // =========================================================================

    /**
     * Test markDeliveryCompleted marks delivery as completed
     */
    public function testMarkDeliveryCompletedMarksAsCompleted(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(['delivery_stage' => 'forwarded']);

        $this->deliveryRepository->expects($this->once())
            ->method('markCompleted')
            ->with('transaction', self::TEST_MESSAGE_ID);

        $result = $this->service->markDeliveryCompleted('transaction', self::TEST_MESSAGE_ID);

        $this->assertTrue($result);
    }

    /**
     * Test markDeliveryCompleted returns false when already failed
     */
    public function testMarkDeliveryCompletedReturnsFalseWhenFailed(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(['delivery_stage' => 'failed']);

        $this->deliveryRepository->expects($this->never())
            ->method('markCompleted');

        $result = $this->service->markDeliveryCompleted('transaction', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    /**
     * Test markDeliveryCompleted returns false when not found
     */
    public function testMarkDeliveryCompletedReturnsFalseWhenNotFound(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(null);

        $result = $this->service->markDeliveryCompleted('transaction', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    // =========================================================================
    // updateStageAfterLocalInsert() Tests
    // =========================================================================

    /**
     * Test updateStageAfterLocalInsert updates to inserted
     */
    public function testUpdateStageAfterLocalInsertUpdatesToInserted(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(['delivery_stage' => 'received']);

        $this->deliveryRepository->expects($this->once())
            ->method('updateStage')
            ->with('contact', self::TEST_MESSAGE_ID, 'inserted', $this->anything());

        $result = $this->service->updateStageAfterLocalInsert('contact', self::TEST_MESSAGE_ID);

        $this->assertTrue($result);
    }

    /**
     * Test updateStageAfterLocalInsert marks completed when requested
     */
    public function testUpdateStageAfterLocalInsertMarksCompletedWhenRequested(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $this->deliveryRepository->expects($this->once())
            ->method('updateStage');

        $this->deliveryRepository->expects($this->once())
            ->method('markCompleted')
            ->with('contact', self::TEST_MESSAGE_ID);

        $result = $this->service->updateStageAfterLocalInsert('contact', self::TEST_MESSAGE_ID, true);

        $this->assertTrue($result);
    }

    /**
     * Test updateStageAfterLocalInsert returns false when not found
     */
    public function testUpdateStageAfterLocalInsertReturnsFalseWhenNotFound(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(null);

        $result = $this->service->updateStageAfterLocalInsert('contact', self::TEST_MESSAGE_ID);

        $this->assertFalse($result);
    }

    /**
     * Test updateStageAfterLocalInsert skips update when already at or past inserted
     */
    public function testUpdateStageAfterLocalInsertSkipsWhenAlreadyInserted(): void
    {
        $this->deliveryRepository->expects($this->once())
            ->method('getByMessage')
            ->willReturn(['delivery_stage' => 'forwarded']);

        $this->deliveryRepository->expects($this->never())
            ->method('updateStage');

        $result = $this->service->updateStageAfterLocalInsert('contact', self::TEST_MESSAGE_ID);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Success Status Processing Tests
    // =========================================================================

    /**
     * Test p2p message completes on inserted status
     */
    public function testP2pMessageCompletesOnInsertedStatus(): void
    {
        $payload = ['type' => 'p2p'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'inserted']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $this->deliveryRepository->expects($this->once())
            ->method('markCompleted');

        $result = $this->service->sendWithTracking(
            'p2p',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['stage']);
    }

    /**
     * Test transaction message completes on forwarded status
     */
    public function testTransactionMessageCompletesOnForwardedStatus(): void
    {
        $payload = ['type' => 'transaction'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'forwarded']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $this->deliveryRepository->expects($this->once())
            ->method('markCompleted');

        $result = $this->service->sendWithTracking(
            'transaction',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['stage']);
    }

    /**
     * Test contact message does not complete on inserted status
     *
     * Note: updateStage is called multiple times - first with 'sent' before delivery,
     * then with 'inserted' after receiving response. We verify markCompleted is never
     * called and the final result stage is 'inserted'.
     */
    public function testContactMessageDoesNotCompleteOnInsertedStatus(): void
    {
        $payload = ['type' => 'contact'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'inserted']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        // Key assertion: markCompleted should never be called for 'inserted' status
        $this->deliveryRepository->expects($this->never())
            ->method('markCompleted');

        // updateStage is called multiple times: first 'sent', then 'inserted'
        $this->deliveryRepository->expects($this->atLeastOnce())
            ->method('updateStage');

        $result = $this->service->sendWithTracking(
            'contact',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('inserted', $result['stage']);
    }

    /**
     * Test warning status is treated as success
     */
    public function testWarningStatusIsTreatedAsSuccess(): void
    {
        $payload = ['type' => 'contact'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'warning', 'message' => 'Contact already exists']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $result = $this->service->sendWithTracking(
            'contact',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
    }

    /**
     * Test accepted status completes delivery
     */
    public function testAcceptedStatusCompletesDelivery(): void
    {
        $payload = ['type' => 'contact'];

        $this->deliveryRepository->method('deliveryExists')
            ->willReturn(false);

        $this->deliveryRepository->method('createDelivery');

        $this->transportUtility->expects($this->once())
            ->method('send')
            ->willReturn([
                'response' => json_encode(['status' => 'accepted']),
                'signature' => 'sig',
                'nonce' => 123
            ]);

        $this->deliveryRepository->method('getByMessage')
            ->willReturn(['delivery_stage' => 'sent']);

        $this->deliveryRepository->expects($this->once())
            ->method('markCompleted');

        $result = $this->service->sendWithTracking(
            'contact',
            self::TEST_MESSAGE_ID,
            self::TEST_RECIPIENT,
            $payload
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('completed', $result['stage']);
    }
}
