<?php
/**
 * Unit Tests for DeadLetterQueueRepository
 *
 * Tests dead letter queue repository operations including adding to queue,
 * status management, statistics, and cleanup with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\DeadLetterQueueRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(DeadLetterQueueRepository::class)]
class DeadLetterQueueRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private DeadLetterQueueRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new DeadLetterQueueRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('dead_letter_queue', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new DeadLetterQueueRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // addToQueue() Tests
    // =========================================================================

    /**
     * Test addToQueue inserts failed message to queue
     */
    public function testAddToQueueInsertsFailedMessage(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->addToQueue(
            'transaction',
            'msg-123',
            ['txid' => 'tx-123', 'amount' => 1000],
            'http://recipient.example.com',
            3,
            'Connection timeout'
        );

        $this->assertEquals('1', $result);
    }

    /**
     * Test addToQueue returns false on insert failure
     */
    public function testAddToQueueReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->addToQueue(
            'transaction',
            'msg-123',
            ['txid' => 'tx-123'],
            'http://recipient.example.com',
            3,
            'Connection timeout'
        );

        $this->assertFalse($result);
    }

    /**
     * Test addToQueue with different message types
     */
    public function testAddToQueueWithDifferentMessageTypes(): void
    {
        $messageTypes = ['transaction', 'p2p', 'rp2p', 'contact'];

        foreach ($messageTypes as $type) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new DeadLetterQueueRepository($pdo);

            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->atLeastOnce())
                ->method('bindValue');

            $stmt->expects($this->once())
                ->method('execute')
                ->willReturn(true);

            $pdo->expects($this->once())
                ->method('lastInsertId')
                ->willReturn('1');

            $result = $repository->addToQueue(
                $type,
                "msg-$type",
                ['data' => 'test'],
                'http://example.com',
                1,
                'Test failure'
            );

            $this->assertEquals('1', $result, "Failed for message type: $type");
        }
    }

    // =========================================================================
    // getPendingItems() Tests
    // =========================================================================

    /**
     * Test getPendingItems returns pending items
     */
    public function testGetPendingItemsReturnsPendingItems(): void
    {
        $pendingItems = [
            [
                'id' => 1,
                'message_type' => 'transaction',
                'message_id' => 'msg-1',
                'payload' => '{"txid":"tx-1"}',
                'status' => 'pending'
            ],
            [
                'id' => 2,
                'message_type' => 'p2p',
                'message_id' => 'msg-2',
                'payload' => '{"hash":"hash-1"}',
                'status' => 'pending'
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 50, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($pendingItems);

        $result = $this->repository->getPendingItems();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test getPendingItems with custom limit
     */
    public function testGetPendingItemsWithCustomLimit(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 10, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getPendingItems(10);

        $this->assertIsArray($result);
    }

    /**
     * Test getPendingItems returns empty array on exception
     */
    public function testGetPendingItemsReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->getPendingItems();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getById() Tests
    // =========================================================================

    /**
     * Test getById returns item by ID
     */
    public function testGetByIdReturnsItemById(): void
    {
        $item = [
            'id' => 1,
            'message_type' => 'transaction',
            'message_id' => 'msg-1',
            'payload' => '{"txid":"tx-1"}',
            'status' => 'pending'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($item);

        $result = $this->repository->getById(1);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
    }

    /**
     * Test getById returns null when not found
     */
    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getById(999);

        $this->assertNull($result);
    }

    // =========================================================================
    // getByMessageType() Tests
    // =========================================================================

    /**
     * Test getByMessageType returns items by type
     */
    public function testGetByMessageTypeReturnsItemsByType(): void
    {
        $items = [
            ['id' => 1, 'message_type' => 'transaction', 'payload' => '{}'],
            ['id' => 2, 'message_type' => 'transaction', 'payload' => '{}']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($items);

        $result = $this->repository->getByMessageType('transaction');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test getByMessageType with status filter
     */
    public function testGetByMessageTypeWithStatusFilter(): void
    {
        $items = [
            ['id' => 1, 'message_type' => 'transaction', 'status' => 'pending', 'payload' => '{}']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(3))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($items);

        $result = $this->repository->getByMessageType('transaction', 'pending');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * Test getByMessageType returns empty array on exception
     */
    public function testGetByMessageTypeReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->getByMessageType('transaction');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // markRetrying() Tests
    // =========================================================================

    /**
     * Test markRetrying updates status to retrying
     */
    public function testMarkRetryingUpdatesStatus(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->markRetrying(1);

        $this->assertTrue($result);
    }

    /**
     * Test markRetrying returns false on failure
     */
    public function testMarkRetryingReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->repository->markRetrying(1);

        $this->assertFalse($result);
    }

    // =========================================================================
    // markResolved() Tests
    // =========================================================================

    /**
     * Test markResolved updates status to resolved
     */
    public function testMarkResolvedUpdatesStatus(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->markResolved(1);

        $this->assertTrue($result);
    }

    /**
     * Test markResolved returns false on failure
     */
    public function testMarkResolvedReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->repository->markResolved(1);

        $this->assertFalse($result);
    }

    // =========================================================================
    // markAbandoned() Tests
    // =========================================================================

    /**
     * Test markAbandoned updates status to abandoned
     */
    public function testMarkAbandonedUpdatesStatus(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->markAbandoned(1);

        $this->assertTrue($result);
    }

    /**
     * Test markAbandoned with custom reason
     */
    public function testMarkAbandonedWithCustomReason(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->markAbandoned(1, 'Recipient unreachable');

        $this->assertTrue($result);
    }

    /**
     * Test markAbandoned returns false on failure
     */
    public function testMarkAbandonedReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->repository->markAbandoned(1);

        $this->assertFalse($result);
    }

    // =========================================================================
    // returnToPending() Tests
    // =========================================================================

    /**
     * Test returnToPending updates status to pending and increments retry count
     */
    public function testReturnToPendingUpdatesStatusAndIncrementsRetryCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->returnToPending(1);

        $this->assertTrue($result);
    }

    /**
     * Test returnToPending returns false on failure
     */
    public function testReturnToPendingReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('Database error'));

        $result = $this->repository->returnToPending(1);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getStatistics() Tests
    // =========================================================================

    /**
     * Test getStatistics returns statistics array
     */
    public function testGetStatisticsReturnsStatisticsArray(): void
    {
        $stats = [
            'total_count' => 10,
            'pending_count' => 5,
            'retrying_count' => 2,
            'resolved_count' => 2,
            'abandoned_count' => 1,
            'message_types' => 3,
            'avg_retries' => 2.5
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($stats);

        $result = $this->repository->getStatistics();

        $this->assertIsArray($result);
        $this->assertEquals(10, $result['total_count']);
        $this->assertEquals(5, $result['pending_count']);
    }

    /**
     * Test getStatistics returns empty array on failure
     */
    public function testGetStatisticsReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->getStatistics();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getStatisticsByType() Tests
    // =========================================================================

    /**
     * Test getStatisticsByType returns statistics grouped by message type
     */
    public function testGetStatisticsByTypeReturnsGroupedStatistics(): void
    {
        $stats = [
            [
                'message_type' => 'transaction',
                'total_count' => 5,
                'pending_count' => 3,
                'resolved_count' => 2,
                'abandoned_count' => 0
            ],
            [
                'message_type' => 'p2p',
                'total_count' => 3,
                'pending_count' => 1,
                'resolved_count' => 1,
                'abandoned_count' => 1
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($stats);

        $result = $this->repository->getStatisticsByType();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test getStatisticsByType returns empty array on failure
     */
    public function testGetStatisticsByTypeReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $result = $this->repository->getStatisticsByType();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getPendingCount() Tests
    // =========================================================================

    /**
     * Test getPendingCount returns count of pending items
     */
    public function testGetPendingCountReturnsCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 7]);

        $result = $this->repository->getPendingCount();

        $this->assertEquals(7, $result);
    }

    /**
     * Test getPendingCount returns zero when no pending items
     */
    public function testGetPendingCountReturnsZeroWhenNoPendingItems(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->getPendingCount();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // existsByMessageId() Tests
    // =========================================================================

    /**
     * Test existsByMessageId returns true when item exists
     */
    public function testExistsByMessageIdReturnsTrueWhenExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 1]);

        $result = $this->repository->existsByMessageId('msg-123');

        $this->assertTrue($result);
    }

    /**
     * Test existsByMessageId returns false when item does not exist
     */
    public function testExistsByMessageIdReturnsFalseWhenNotExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->existsByMessageId('nonexistent-msg');

        $this->assertFalse($result);
    }

    // =========================================================================
    // deleteOldRecords() Tests
    // =========================================================================

    /**
     * Test deleteOldRecords deletes old resolved/abandoned records
     */
    public function testDeleteOldRecordsDeletesOldRecords(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 90, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(5);

        $result = $this->repository->deleteOldRecords();

        $this->assertEquals(5, $result);
    }

    /**
     * Test deleteOldRecords with custom days parameter
     */
    public function testDeleteOldRecordsWithCustomDays(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 30, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(10);

        $result = $this->repository->deleteOldRecords(30);

        $this->assertEquals(10, $result);
    }

    /**
     * Test deleteOldRecords returns zero on exception
     */
    public function testDeleteOldRecordsReturnsZeroOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->deleteOldRecords();

        $this->assertEquals(0, $result);
    }

    /**
     * Test deleteOldRecords returns zero when no records deleted
     */
    public function testDeleteOldRecordsReturnsZeroWhenNoRecordsDeleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteOldRecords();

        $this->assertEquals(0, $result);
    }
}
