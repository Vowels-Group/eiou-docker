<?php
/**
 * Unit Tests for CliDlqService
 *
 * Tests CLI DLQ management functionality including:
 * - Listing DLQ items with status filtering
 * - Retrying failed message delivery
 * - Abandoning undeliverable messages
 * - Extracting txid from DLQ message IDs
 *
 * Created as part of ARCH-04 refactor (extracted from CliService).
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\CliDlqService;
use Eiou\Database\TransactionRepository;
use Eiou\Database\DeadLetterQueueRepository;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\Constants;
use Eiou\Cli\CliOutputManager;

#[CoversClass(CliDlqService::class)]
class CliDlqServiceTest extends TestCase
{
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|DeadLetterQueueRepository $dlqRepository;
    private MockObject|CliOutputManager $outputManager;
    /** @var MockObject&\Eiou\Services\MessageDeliveryService */
    private $messageDeliveryService;
    private CliDlqService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->dlqRepository = $this->createMock(DeadLetterQueueRepository::class);
        $this->outputManager = $this->createMock(CliOutputManager::class);
        $this->messageDeliveryService = $this->createMock(\Eiou\Services\MessageDeliveryService::class);

        $this->service = new CliDlqService(
            $this->transportUtility,
            $this->transactionRepository
        );

        $this->service->setDeadLetterQueueRepository($this->dlqRepository);
        $this->service->setMessageDeliveryService($this->messageDeliveryService);
    }

    // =========================================================================
    // displayDlqItems() Tests
    // =========================================================================

    /**
     * Test displayDlqItems shows items in text mode
     */
    public function testDisplayDlqItemsShowsItems(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(false);

        $this->dlqRepository->method('getItems')
            ->willReturn([
                [
                    'id' => 1,
                    'message_type' => 'transaction',
                    'status' => 'pending',
                    'retry_count' => 2,
                    'created_at' => '2026-03-10 12:00:00',
                    'recipient_address' => 'http://bob:8080',
                    'failure_reason' => 'Connection refused',
                ],
            ]);

        $this->dlqRepository->method('getStatistics')
            ->willReturn([
                'pending' => 1,
                'retrying' => 0,
                'resolved' => 5,
                'abandoned' => 2,
            ]);

        ob_start();
        $this->service->displayDlqItems(['eiou', 'dlq'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('Dead Letter Queue', $output);
        $this->assertStringContainsString('Pending: 1', $output);
        $this->assertStringContainsString('transaction', $output);
        $this->assertStringContainsString('Connection refused', $output);
    }

    /**
     * Test displayDlqItems with empty list
     */
    public function testDisplayDlqItemsEmpty(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(false);

        $this->dlqRepository->method('getItems')->willReturn([]);
        $this->dlqRepository->method('getStatistics')
            ->willReturn(['pending' => 0, 'retrying' => 0, 'resolved' => 0, 'abandoned' => 0]);

        ob_start();
        $this->service->displayDlqItems(['eiou', 'dlq'], $this->outputManager);
        $output = ob_get_clean();

        $this->assertStringContainsString('No items', $output);
    }

    /**
     * Test displayDlqItems in JSON mode
     */
    public function testDisplayDlqItemsJsonMode(): void
    {
        $this->outputManager->method('isJsonMode')->willReturn(true);

        $items = [['id' => 1, 'message_type' => 'transaction', 'status' => 'pending']];
        $stats = ['pending' => 1, 'retrying' => 0, 'resolved' => 0, 'abandoned' => 0];

        $this->dlqRepository->method('getItems')->willReturn($items);
        $this->dlqRepository->method('getStatistics')->willReturn($stats);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                'DLQ items',
                $this->callback(function ($data) {
                    return isset($data['items']) && isset($data['statistics']);
                })
            );

        $this->service->displayDlqItems(['eiou', 'dlq'], $this->outputManager);
    }

    /**
     * Test displayDlqItems rejects invalid status filter
     */
    public function testDisplayDlqItemsInvalidStatusFilter(): void
    {
        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Invalid status filter'),
                $this->anything()
            );

        $this->service->displayDlqItems(['eiou', 'dlq', '--status=bogus'], $this->outputManager);
    }

    /**
     * Test displayDlqItems errors when repository not set
     */
    public function testDisplayDlqItemsMissingDependencies(): void
    {
        $service = new CliDlqService($this->transportUtility, $this->transactionRepository);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('not available'),
                $this->anything()
            );

        $service->displayDlqItems(['eiou', 'dlq'], $this->outputManager);
    }

    // =========================================================================
    // retryDlqItem() Tests
    // =========================================================================

    /**
     * Test retryDlqItem successfully re-sends a transaction
     */
    public function testRetryDlqItemSuccess(): void
    {
        $this->dlqRepository->method('getById')
            ->with(42)
            ->willReturn([
                'id' => 42,
                'message_type' => 'transaction',
                'message_id' => 'send-txid123-1678901234',
                'status' => 'pending',
                'recipient_address' => 'http://bob:8080',
                'payload' => ['type' => 'transaction', 'data' => 'test'],
            ]);

        // getByTxid returns a list of rows (fetchAll) — the unwrap in
        // retryDlqItem should pick up $rows[0]['status']
        $this->transactionRepository->method('getByTxid')
            ->with('txid123')
            ->willReturn([['txid' => 'txid123', 'status' => Constants::STATUS_CANCELLED]]);

        $this->transactionRepository->expects($this->once())
            ->method('setExpiresAt');

        // Cancelled status is recognised through the list unwrap, so
        // status flip to SENDING still fires
        $this->transactionRepository->expects($this->once())
            ->method('updateStatus')
            ->with('txid123', Constants::STATUS_SENDING, true);

        // CLI now delegates to MessageDeliveryService::retryFromDlq
        $this->messageDeliveryService->expects($this->once())
            ->method('retryFromDlq')
            ->with(42, $this->isType('callable'))
            ->willReturn(['success' => true, 'message' => 'Message successfully resent from DLQ']);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('successfully re-sent'),
                $this->anything(),
                $this->anything()
            );

        $this->service->retryDlqItem(['eiou', 'dlq', 'retry', '42'], $this->outputManager);
    }

    /**
     * Test retryDlqItem blocks P2P message types
     */
    public function testRetryDlqItemBlocksP2pMessages(): void
    {
        $this->dlqRepository->method('getById')
            ->with(42)
            ->willReturn([
                'id' => 42,
                'message_type' => 'p2p',
                'status' => 'pending',
            ]);

        $this->transportUtility->expects($this->never())->method('send');

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('cannot be retried'),
                $this->anything()
            );

        $this->service->retryDlqItem(['eiou', 'dlq', 'retry', '42'], $this->outputManager);
    }

    /**
     * Test retryDlqItem returns to pending on send failure
     */
    /**
     * Regression: TransactionRepository::getByTxid returns a list of rows
     * (fetchAll). Earlier code accessed $tx['status'] directly on that list
     * and triggered "Undefined array key status" on every retry. Verify the
     * unwrap path picks up the first row's status correctly.
     */
    public function testRetryDlqItemUnwrapsGetByTxidListForCancelledCheck(): void
    {
        $this->dlqRepository->method('getById')
            ->with(42)
            ->willReturn([
                'id' => 42,
                'message_type' => 'transaction',
                'message_id' => 'send-txid456-1678901234',
                'status' => 'pending',
                'recipient_address' => 'http://bob:8080',
                'payload' => ['type' => 'transaction'],
            ]);

        // Return shape matches real fetchAll — a list, not a flat row
        $this->transactionRepository->method('getByTxid')
            ->with('txid456')
            ->willReturn([['txid' => 'txid456', 'status' => Constants::STATUS_COMPLETED]]);

        $this->transactionRepository->expects($this->once())->method('setExpiresAt');
        // Status is 'completed', not 'cancelled', so updateStatus must NOT fire
        $this->transactionRepository->expects($this->never())->method('updateStatus');

        $this->messageDeliveryService->method('retryFromDlq')
            ->willReturn(['success' => true]);

        $this->service->retryDlqItem(['eiou', 'dlq', 'retry', '42'], $this->outputManager);
    }

    public function testRetryDlqItemSendFailure(): void
    {
        $this->dlqRepository->method('getById')
            ->with(42)
            ->willReturn([
                'id' => 42,
                'message_type' => 'sync',
                'message_id' => 'sync-123',
                'status' => 'pending',
                'recipient_address' => 'http://bob:8080',
                'payload' => ['type' => 'sync'],
            ]);

        // Delegated path: MessageDeliveryService owns markRetrying / returnToPending.
        // The CLI surface just reports the failure returned from retryFromDlq.
        $this->messageDeliveryService->expects($this->once())
            ->method('retryFromDlq')
            ->willReturn(['success' => false, 'error' => 'Delivery failed: Connection refused']);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Connection refused'),
                $this->anything(),
                $this->anything()
            );

        $this->service->retryDlqItem(['eiou', 'dlq', 'retry', '42'], $this->outputManager);
    }

    /**
     * Test retryDlqItem errors when item not found
     */
    public function testRetryDlqItemNotFound(): void
    {
        $this->dlqRepository->method('getById')->with(99)->willReturn(null);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('not found'),
                $this->anything(),
                $this->anything()
            );

        $this->service->retryDlqItem(['eiou', 'dlq', 'retry', '99'], $this->outputManager);
    }

    /**
     * Test retryDlqItem errors when missing ID
     */
    public function testRetryDlqItemMissingId(): void
    {
        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('ID is required'),
                $this->anything()
            );

        $this->service->retryDlqItem(['eiou', 'dlq', 'retry'], $this->outputManager);
    }

    // =========================================================================
    // abandonDlqItem() Tests
    // =========================================================================

    /**
     * Test abandonDlqItem marks item as abandoned
     */
    public function testAbandonDlqItemSuccess(): void
    {
        $this->dlqRepository->method('getById')
            ->with(42)
            ->willReturn([
                'id' => 42,
                'message_type' => 'p2p',
                'status' => 'pending',
                'recipient_address' => 'http://bob:8080',
            ]);

        $this->dlqRepository->expects($this->once())
            ->method('markAbandoned')
            ->with(42, 'Manually abandoned via CLI')
            ->willReturn(true);

        $this->outputManager->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('abandoned'),
                $this->anything(),
                $this->anything()
            );

        $this->service->abandonDlqItem(['eiou', 'dlq', 'abandon', '42'], $this->outputManager);
    }

    /**
     * Test abandonDlqItem rejects already abandoned items
     */
    public function testAbandonDlqItemAlreadyAbandoned(): void
    {
        $this->dlqRepository->method('getById')
            ->with(42)
            ->willReturn([
                'id' => 42,
                'message_type' => 'p2p',
                'status' => 'abandoned',
            ]);

        $this->outputManager->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('already abandoned'),
                $this->anything()
            );

        $this->service->abandonDlqItem(['eiou', 'dlq', 'abandon', '42'], $this->outputManager);
    }

    // =========================================================================
    // extractTxidFromDlqMessageId() Tests
    // =========================================================================

    /**
     * Test extractTxidFromDlqMessageId with send prefix
     */
    public function testExtractTxidFromSendMessageId(): void
    {
        $result = $this->service->extractTxidFromDlqMessageId('send-txid123abc-1678901234');
        $this->assertEquals('txid123abc', $result);
    }

    /**
     * Test extractTxidFromDlqMessageId with relay prefix
     */
    public function testExtractTxidFromRelayMessageId(): void
    {
        $result = $this->service->extractTxidFromDlqMessageId('relay-txid456def-9876543210');
        $this->assertEquals('txid456def', $result);
    }

    /**
     * Test extractTxidFromDlqMessageId returns null for unknown format
     */
    public function testExtractTxidFromUnknownFormat(): void
    {
        $result = $this->service->extractTxidFromDlqMessageId('unknown-format');
        $this->assertNull($result);
    }
}
