<?php
/**
 * Unit Tests for MessageDeliveryRepository
 *
 * Tests message delivery repository database operations with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\MessageDeliveryRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;
use PDOException;
use ReflectionClass;

#[CoversClass(MessageDeliveryRepository::class)]
class MessageDeliveryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private MessageDeliveryRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new MessageDeliveryRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('message_delivery', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new MessageDeliveryRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // createDelivery() Tests
    // =========================================================================

    /**
     * Test createDelivery inserts record with default values
     */
    public function testCreateDeliveryInsertsRecordWithDefaults(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->createDelivery(
            'transaction',
            'msg_123',
            'recipient@example.com'
        );

        $this->assertEquals('1', $result);
    }

    /**
     * Test createDelivery with all parameters
     */
    public function testCreateDeliveryWithAllParameters(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('42');

        $payload = ['txid' => 'tx_123', 'amount' => 1000];

        $result = $this->repository->createDelivery(
            'transaction',
            'msg_456',
            'recipient@example.com',
            Constants::DELIVERY_SENT,
            10,
            $payload
        );

        $this->assertEquals('42', $result);
    }

    /**
     * Test createDelivery returns false on failure
     */
    public function testCreateDeliveryReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->createDelivery('transaction', 'msg_123', 'recipient');

        $this->assertFalse($result);
    }

    // =========================================================================
    // updatePayload() Tests
    // =========================================================================

    /**
     * Test updatePayload updates payload successfully
     */
    public function testUpdatePayloadUpdatesSuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SET payload ='))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $payload = ['txid' => 'tx_456', 'amount' => 2000];

        $result = $this->repository->updatePayload('transaction', 'msg_123', $payload);

        $this->assertTrue($result);
    }

    /**
     * Test updatePayload returns false on failure
     */
    public function testUpdatePayloadReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->updatePayload('transaction', 'msg_123', ['data' => 'test']);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getPayload() Tests
    // =========================================================================

    /**
     * Test getPayload returns decoded payload
     */
    public function testGetPayloadReturnsDecodedPayload(): void
    {
        $payload = ['txid' => 'tx_123', 'amount' => 1000];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([
                'id' => 1,
                'message_type' => 'transaction',
                'message_id' => 'msg_123',
                'payload' => json_encode($payload)
            ]);

        $result = $this->repository->getPayload('transaction', 'msg_123');

        $this->assertEquals($payload, $result);
    }

    /**
     * Test getPayload returns null when delivery not found
     */
    public function testGetPayloadReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getPayload('transaction', 'nonexistent');

        $this->assertNull($result);
    }

    /**
     * Test getPayload returns null when payload is empty
     */
    public function testGetPayloadReturnsNullWhenPayloadEmpty(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn([
                'id' => 1,
                'payload' => null
            ]);

        $result = $this->repository->getPayload('transaction', 'msg_123');

        $this->assertNull($result);
    }

    // =========================================================================
    // getByMessage() Tests
    // =========================================================================

    /**
     * Test getByMessage returns delivery record
     */
    public function testGetByMessageReturnsDeliveryRecord(): void
    {
        $expectedRecord = [
            'id' => 1,
            'message_type' => 'transaction',
            'message_id' => 'msg_123',
            'recipient_address' => 'addr_abc',
            'delivery_stage' => 'pending'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE message_type = :type AND message_id = :id'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRecord);

        $result = $this->repository->getByMessage('transaction', 'msg_123');

        $this->assertEquals($expectedRecord, $result);
    }

    /**
     * Test getByMessage returns null when not found
     */
    public function testGetByMessageReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(false);

        $result = $this->repository->getByMessage('transaction', 'nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // updateStage() Tests
    // =========================================================================

    /**
     * Test updateStage updates delivery stage
     */
    public function testUpdateStageUpdatesDeliveryStage(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SET delivery_stage ='))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->updateStage('transaction', 'msg_123', Constants::DELIVERY_SENT);

        $this->assertTrue($result);
    }

    /**
     * Test updateStage with response parameter
     */
    public function testUpdateStageWithResponseParameter(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('last_response ='))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->updateStage(
            'transaction',
            'msg_123',
            Constants::DELIVERY_COMPLETED,
            'Success response'
        );

        $this->assertTrue($result);
    }

    /**
     * Test updateStage returns false on failure
     */
    public function testUpdateStageReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->updateStage('transaction', 'msg_123', Constants::DELIVERY_FAILED);

        $this->assertFalse($result);
    }

    // =========================================================================
    // incrementRetry() Tests
    // =========================================================================

    /**
     * Test incrementRetry increments count and sets next retry time
     */
    public function testIncrementRetryIncrementsCountAndSetsNextRetry(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('retry_count = retry_count + 1'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->incrementRetry('transaction', 'msg_123', 60, 'Connection timeout');

        $this->assertTrue($result);
    }

    /**
     * Test incrementRetry without last error
     */
    public function testIncrementRetryWithoutLastError(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->incrementRetry('transaction', 'msg_123', 30);

        $this->assertTrue($result);
    }

    // =========================================================================
    // getMessagesForRetry() Tests
    // =========================================================================

    /**
     * Test getMessagesForRetry returns messages ready for retry
     */
    public function testGetMessagesForRetryReturnsReadyMessages(): void
    {
        $expectedMessages = [
            ['id' => 1, 'message_id' => 'msg_1', 'retry_count' => 2],
            ['id' => 2, 'message_id' => 'msg_2', 'retry_count' => 1]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("delivery_stage IN ('pending', 'sent')"))
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
            ->willReturn($expectedMessages);

        $result = $this->repository->getMessagesForRetry(10);

        $this->assertEquals($expectedMessages, $result);
    }

    /**
     * Test getMessagesForRetry returns empty array on exception
     */
    public function testGetMessagesForRetryReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getMessagesForRetry();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // getExhaustedRetries() Tests
    // =========================================================================

    /**
     * Test getExhaustedRetries returns messages with exhausted retries
     */
    public function testGetExhaustedRetriesReturnsExhaustedMessages(): void
    {
        $expectedMessages = [
            ['id' => 1, 'message_id' => 'msg_1', 'retry_count' => 5, 'max_retries' => 5]
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('retry_count >= max_retries'))
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
            ->willReturn($expectedMessages);

        $result = $this->repository->getExhaustedRetries(10);

        $this->assertEquals($expectedMessages, $result);
    }

    /**
     * Test getExhaustedRetries returns empty array on exception
     */
    public function testGetExhaustedRetriesReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getExhaustedRetries();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // markFailed() Tests
    // =========================================================================

    /**
     * Test markFailed updates delivery stage to failed
     */
    public function testMarkFailedUpdatesStageToFailed(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->markFailed('transaction', 'msg_123', 'Max retries exceeded');

        $this->assertTrue($result);
    }

    // =========================================================================
    // markCompleted() Tests
    // =========================================================================

    /**
     * Test markCompleted updates delivery stage to completed
     */
    public function testMarkCompletedUpdatesStageToCompleted(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->markCompleted('transaction', 'msg_123');

        $this->assertTrue($result);
    }

    // =========================================================================
    // deliveryExists() Tests
    // =========================================================================

    /**
     * Test deliveryExists returns true when delivery exists
     */
    public function testDeliveryExistsReturnsTrueWhenExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT COUNT(*)'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 1]);

        $result = $this->repository->deliveryExists('transaction', 'msg_123');

        $this->assertTrue($result);
    }

    /**
     * Test deliveryExists returns false when delivery does not exist
     */
    public function testDeliveryExistsReturnsFalseWhenNotExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 0]);

        $result = $this->repository->deliveryExists('transaction', 'nonexistent');

        $this->assertFalse($result);
    }

    // =========================================================================
    // getStatistics() Tests
    // =========================================================================

    /**
     * Test getStatistics returns delivery statistics
     */
    public function testGetStatisticsReturnsDeliveryStatistics(): void
    {
        $expectedStats = [
            'total_count' => 100,
            'completed_count' => 85,
            'failed_count' => 10,
            'pending_count' => 5,
            'avg_retries' => 1.5,
            'max_retries_used' => 5
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SUM(CASE WHEN delivery_stage'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getStatistics();

        $this->assertEquals($expectedStats, $result);
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
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStatistics();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // getStatisticsByType() Tests
    // =========================================================================

    /**
     * Test getStatisticsByType returns statistics for specific type
     */
    public function testGetStatisticsByTypeReturnsTypeSpecificStatistics(): void
    {
        $expectedStats = [
            'total_count' => 50,
            'completed_count' => 45,
            'failed_count' => 3,
            'pending_count' => 2,
            'avg_retries' => 0.8
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE message_type = :type'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedStats);

        $result = $this->repository->getStatisticsByType('p2p');

        $this->assertEquals($expectedStats, $result);
    }

    /**
     * Test getStatisticsByType returns empty array on failure
     */
    public function testGetStatisticsByTypeReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getStatisticsByType('transaction');

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // markCompletedByHash() Tests
    // =========================================================================

    /**
     * Test markCompletedByHash updates deliveries matching hash pattern
     */
    public function testMarkCompletedByHashUpdatesMatchingDeliveries(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('message_id LIKE :pattern'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(3);

        $result = $this->repository->markCompletedByHash('p2p', 'abc123hash');

        $this->assertEquals(3, $result);
    }

    /**
     * Test markCompletedByHash returns 0 on exception
     */
    public function testMarkCompletedByHashReturnsZeroOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->markCompletedByHash('p2p', 'abc123hash');

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // deleteOldRecords() Tests
    // =========================================================================

    /**
     * Test deleteOldRecords deletes old completed and failed records
     */
    public function testDeleteOldRecordsDeletesOldRecords(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("delivery_stage IN ('completed', 'failed')"))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':days', 30, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(150);

        $result = $this->repository->deleteOldRecords(30);

        $this->assertEquals(150, $result);
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
            ->with(':days', 7, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(50);

        $result = $this->repository->deleteOldRecords(7);

        $this->assertEquals(50, $result);
    }

    /**
     * Test deleteOldRecords returns 0 on exception
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
            ->willThrowException(new PDOException('Delete failed'));

        $result = $this->repository->deleteOldRecords();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Allowed Columns Tests
    // =========================================================================

    /**
     * Test allowed columns are properly defined
     */
    public function testAllowedColumnsAreDefined(): void
    {
        $reflection = new ReflectionClass($this->repository);
        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);
        $allowedColumns = $property->getValue($this->repository);

        $expectedColumns = [
            'id', 'message_type', 'message_id', 'recipient_address', 'payload',
            'delivery_stage', 'retry_count', 'max_retries', 'next_retry_at',
            'last_error', 'last_response', 'created_at', 'updated_at'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $allowedColumns);
        }
    }
}
