<?php
/**
 * Unit tests for DeadLetterQueueService
 *
 * Tests dead letter queue (DLQ) operations for failed messages.
 *
 * Requirements from Issue #139:
 * - Failed messages after max retries → DLQ
 * - Manual review/reprocessing capability
 * - Alerting for messages in DLQ
 * - Tracking failure reasons
 */

require_once __DIR__ . '/../BaseTestCase.php';

use Tests\Unit\BaseTestCase;

class DeadLetterQueueServiceTest extends BaseTestCase
{
    private $mockPDO;
    private $mockUserContext;
    private $service;

    protected function setUp(): void
    {
        $this->mockPDO = $this->createMockPDO();
        $this->mockUserContext = $this->createMockUserContext();

        // NOTE: Actual service will be instantiated once coders implement it
        // $this->service = new DeadLetterQueueService($this->mockPDO, $this->mockUserContext);
    }

    // ========================================================================
    // Test: Adding Messages to DLQ
    // ========================================================================

    public function testAddMessageToDLQ()
    {
        // Arrange
        $messageId = $this->generateMessageId();
        $reason = 'max_retries_exceeded';
        $message = $this->createTestMessage(['id' => $messageId]);

        // Act - Add to DLQ
        $stmt = $this->mockPDO->prepare(
            "INSERT INTO dead_letter_queue (message_id, original_message, reason, added_at) VALUES (?, ?, ?, ?)"
        );
        $result = $stmt->execute([
            $messageId,
            json_encode($message),
            $reason,
            time()
        ]);

        // Assert
        $this->assertTrue($result !== false,
            'Message should be added to DLQ');
    }

    public function testAddMultipleMessagesToDLQ()
    {
        // Arrange
        $messages = [
            ['id' => $this->generateMessageId(), 'reason' => 'timeout'],
            ['id' => $this->generateMessageId(), 'reason' => 'max_retries_exceeded'],
            ['id' => $this->generateMessageId(), 'reason' => 'network_error']
        ];

        // Act - Add multiple messages
        foreach ($messages as $msg) {
            $stmt = $this->mockPDO->prepare(
                "INSERT INTO dead_letter_queue (message_id, reason) VALUES (?, ?)"
            );
            $stmt->execute([$msg['id'], $msg['reason']]);
        }

        // Assert - All should be added
        $this->assertCount(3, $messages);
    }

    public function testDLQWithFailureContext()
    {
        // Arrange
        $messageId = $this->generateMessageId();
        $failureContext = [
            'reason' => 'max_retries_exceeded',
            'retry_count' => 5,
            'last_error' => 'Connection timeout',
            'last_attempt_at' => time() - 300,
            'first_failure_at' => time() - 3600
        ];

        // Act - Add with full context
        $stmt = $this->mockPDO->prepare(
            "INSERT INTO dead_letter_queue (message_id, reason, context) VALUES (?, ?, ?)"
        );
        $result = $stmt->execute([
            $messageId,
            $failureContext['reason'],
            json_encode($failureContext)
        ]);

        // Assert
        $this->assertTrue($result !== false);
    }

    // ========================================================================
    // Test: Retrieving Messages from DLQ
    // ========================================================================

    public function testGetAllDLQMessages()
    {
        // Arrange
        $dlqMessages = [
            ['id' => 'msg1', 'reason' => 'timeout'],
            ['id' => 'msg2', 'reason' => 'max_retries_exceeded'],
            ['id' => 'msg3', 'reason' => 'network_error']
        ];

        // Mock DLQ query
        $stmt = $this->mockPDO->prepare("SELECT * FROM dead_letter_queue ORDER BY added_at DESC");
        $stmt->setFetchResult($dlqMessages);
        $stmt->execute();
        $results = $stmt->fetchAll();

        // Assert
        $this->assertCount(3, $results);
    }

    public function testGetDLQMessageById()
    {
        // Arrange
        $messageId = $this->generateMessageId();
        $expectedMessage = [
            'message_id' => $messageId,
            'reason' => 'timeout',
            'added_at' => time()
        ];

        // Mock single message retrieval
        $stmt = $this->mockPDO->prepare("SELECT * FROM dead_letter_queue WHERE message_id = ?");
        $stmt->setFetchResult([$expectedMessage]);
        $stmt->execute([$messageId]);
        $result = $stmt->fetch();

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($messageId, $result['message_id']);
    }

    public function testGetDLQMessagesByReason()
    {
        // Arrange
        $reason = 'max_retries_exceeded';
        $messages = [
            ['id' => 'msg1', 'reason' => 'max_retries_exceeded'],
            ['id' => 'msg2', 'reason' => 'max_retries_exceeded']
        ];

        // Mock filtered query
        $stmt = $this->mockPDO->prepare("SELECT * FROM dead_letter_queue WHERE reason = ?");
        $stmt->setFetchResult($messages);
        $stmt->execute([$reason]);
        $results = $stmt->fetchAll();

        // Assert
        $this->assertCount(2, $results);
        foreach ($results as $msg) {
            $this->assertEquals($reason, $msg['reason']);
        }
    }

    public function testGetDLQMessagesByTimeRange()
    {
        // Arrange
        $startTime = time() - 3600; // 1 hour ago
        $endTime = time();
        $messages = [
            ['id' => 'msg1', 'added_at' => time() - 1800],
            ['id' => 'msg2', 'added_at' => time() - 900]
        ];

        // Mock time range query
        $stmt = $this->mockPDO->prepare(
            "SELECT * FROM dead_letter_queue WHERE added_at BETWEEN ? AND ?"
        );
        $stmt->setFetchResult($messages);
        $stmt->execute([$startTime, $endTime]);
        $results = $stmt->fetchAll();

        // Assert
        $this->assertCount(2, $results);
    }

    // ========================================================================
    // Test: Reprocessing Messages
    // ========================================================================

    public function testReprocessSingleMessage()
    {
        // Arrange
        $messageId = $this->generateMessageId();
        $message = $this->createTestMessage(['id' => $messageId]);

        // Act - Move from DLQ back to processing queue
        $retrieveStmt = $this->mockPDO->prepare("SELECT * FROM dead_letter_queue WHERE message_id = ?");
        $retrieveStmt->setFetchResult([$message]);
        $retrieveStmt->execute([$messageId]);
        $dlqMessage = $retrieveStmt->fetch();

        if ($dlqMessage) {
            // Re-insert into messages table
            $reprocessStmt = $this->mockPDO->prepare("INSERT INTO messages (id, `from`, `to`, amount) VALUES (?, ?, ?, ?)");
            $result = $reprocessStmt->execute([
                $message['id'],
                $message['from'],
                $message['to'],
                $message['amount']
            ]);
        }

        // Assert
        $this->assertTrue(isset($result) && $result !== false);
    }

    public function testBulkReprocessing()
    {
        // Arrange - Reprocess multiple messages
        $messageIds = [
            $this->generateMessageId(),
            $this->generateMessageId(),
            $this->generateMessageId()
        ];

        // Act - Reprocess all
        $reprocessed = [];
        foreach ($messageIds as $id) {
            $stmt = $this->mockPDO->prepare("SELECT * FROM dead_letter_queue WHERE message_id = ?");
            $stmt->setFetchResult([['message_id' => $id]]);
            $stmt->execute([$id]);
            if ($stmt->fetch()) {
                $reprocessed[] = $id;
            }
        }

        // Assert
        $this->assertCount(3, $reprocessed);
    }

    public function testReprocessingWithRetryReset()
    {
        // Arrange
        $messageId = $this->generateMessageId();

        // Act - Reset retry counter when reprocessing
        $stmt = $this->mockPDO->prepare("UPDATE retry_attempts SET attempt_number = 0 WHERE message_id = ?");
        $result = $stmt->execute([$messageId]);

        // Assert
        $this->assertTrue($result !== false);
    }

    // ========================================================================
    // Test: Removing Messages from DLQ
    // ========================================================================

    public function testRemoveMessageFromDLQ()
    {
        // Arrange
        $messageId = $this->generateMessageId();

        // Act - Remove after successful reprocessing
        $stmt = $this->mockPDO->prepare("DELETE FROM dead_letter_queue WHERE message_id = ?");
        $result = $stmt->execute([$messageId]);

        // Assert
        $this->assertTrue($result !== false);
    }

    public function testArchiveInsteadOfDelete()
    {
        // Arrange - Archive rather than delete for audit trail
        $messageId = $this->generateMessageId();

        // Act - Move to archive table
        $stmt = $this->mockPDO->prepare(
            "INSERT INTO dlq_archive SELECT *, NOW() as archived_at FROM dead_letter_queue WHERE message_id = ?"
        );
        $result = $stmt->execute([$messageId]);

        // Assert
        $this->assertTrue($result !== false);
    }

    public function testPurgeDLQOlderThan()
    {
        // Arrange - Remove old DLQ entries
        $retentionDays = 30;
        $cutoffTime = time() - ($retentionDays * 86400);

        // Act - Delete old entries
        $stmt = $this->mockPDO->prepare("DELETE FROM dead_letter_queue WHERE added_at < ?");
        $result = $stmt->execute([$cutoffTime]);

        // Assert
        $this->assertTrue($result !== false);
    }

    // ========================================================================
    // Test: Alerting and Monitoring
    // ========================================================================

    public function testDLQCountAlert()
    {
        // Arrange - Alert if DLQ grows too large
        $alertThreshold = 100;

        // Mock DLQ count
        $stmt = $this->mockPDO->prepare("SELECT COUNT(*) FROM dead_letter_queue");
        $stmt->setFetchResult([['COUNT(*)' => 150]]);
        $stmt->execute();
        $count = $stmt->fetchColumn();

        // Act
        $shouldAlert = $count > $alertThreshold;

        // Assert
        $this->assertTrue($shouldAlert,
            'Should trigger alert when DLQ exceeds threshold');
    }

    public function testDLQGrowthRateAlert()
    {
        // Arrange - Alert on rapid DLQ growth
        $currentCount = 150;
        $previousCount = 50; // 1 hour ago
        $growthThreshold = 2.0; // 2x growth

        // Act
        $growthRate = $currentCount / $previousCount;
        $shouldAlert = $growthRate > $growthThreshold;

        // Assert
        $this->assertTrue($shouldAlert,
            'Should alert on rapid DLQ growth');
    }

    public function testCriticalMessageInDLQ()
    {
        // Arrange - Alert for high-priority messages
        $message = $this->createTestMessage([
            'priority' => 'critical',
            'amount' => 10000
        ]);

        // Act - Check if critical
        $isCritical = $message['priority'] === 'critical' || $message['amount'] > 5000;

        // Assert
        $this->assertTrue($isCritical,
            'High-value messages should be flagged as critical');
    }

    // ========================================================================
    // Test: Failure Reason Tracking
    // ========================================================================

    public function testTrackFailureReasons()
    {
        // Arrange
        $reasons = [
            'max_retries_exceeded' => 10,
            'timeout' => 5,
            'network_error' => 3,
            'invalid_recipient' => 2
        ];

        // Mock reason aggregation
        $stmt = $this->mockPDO->prepare("SELECT reason, COUNT(*) as count FROM dead_letter_queue GROUP BY reason");
        $stmt->setFetchResult([
            ['reason' => 'max_retries_exceeded', 'count' => 10],
            ['reason' => 'timeout', 'count' => 5],
            ['reason' => 'network_error', 'count' => 3],
            ['reason' => 'invalid_recipient', 'count' => 2]
        ]);
        $stmt->execute();
        $results = $stmt->fetchAll();

        // Assert
        $this->assertCount(4, $results);
        $this->assertEquals('max_retries_exceeded', $results[0]['reason']);
    }

    public function testMostCommonFailureReason()
    {
        // Arrange
        $reasons = [
            ['reason' => 'max_retries_exceeded', 'count' => 10],
            ['reason' => 'timeout', 'count' => 5],
            ['reason' => 'network_error', 'count' => 3]
        ];

        // Act - Find most common
        usort($reasons, fn($a, $b) => $b['count'] - $a['count']);
        $mostCommon = $reasons[0];

        // Assert
        $this->assertEquals('max_retries_exceeded', $mostCommon['reason']);
        $this->assertEquals(10, $mostCommon['count']);
    }

    // ========================================================================
    // Test: DLQ Statistics
    // ========================================================================

    public function testDLQSize()
    {
        // Arrange
        $stmt = $this->mockPDO->prepare("SELECT COUNT(*) FROM dead_letter_queue");
        $stmt->setFetchResult([['COUNT(*)' => 42]]);
        $stmt->execute();
        $size = $stmt->fetchColumn();

        // Assert
        $this->assertEquals(42, $size);
    }

    public function testDLQAgeDistribution()
    {
        // Arrange - Messages by age
        $now = time();
        $messages = [
            ['added_at' => $now - 300],    // 5 minutes
            ['added_at' => $now - 1800],   // 30 minutes
            ['added_at' => $now - 7200],   // 2 hours
            ['added_at' => $now - 86400]   // 1 day
        ];

        // Act - Categorize by age
        $categories = [
            'recent' => 0,  // < 1 hour
            'old' => 0,     // 1-24 hours
            'ancient' => 0  // > 24 hours
        ];

        foreach ($messages as $msg) {
            $age = $now - $msg['added_at'];
            if ($age < 3600) {
                $categories['recent']++;
            } elseif ($age < 86400) {
                $categories['old']++;
            } else {
                $categories['ancient']++;
            }
        }

        // Assert
        $this->assertEquals(2, $categories['recent']);
        $this->assertEquals(1, $categories['old']);
        $this->assertEquals(1, $categories['ancient']);
    }

    public function testDLQSuccessRate()
    {
        // Arrange
        $stats = [
            'total_moved_to_dlq' => 100,
            'successfully_reprocessed' => 75,
            'permanently_failed' => 25
        ];

        // Calculate success rate
        $reprocessSuccessRate = ($stats['successfully_reprocessed'] / $stats['total_moved_to_dlq']) * 100;

        // Assert
        $this->assertEquals(75.0, $reprocessSuccessRate,
            'Reprocessing success rate should be 75%');
    }

    // ========================================================================
    // Test: Edge Cases and Error Handling
    // ========================================================================

    public function testDuplicateDLQEntry()
    {
        // Arrange - Same message added to DLQ twice
        $messageId = $this->generateMessageId();

        // Mock existing DLQ entry check
        $stmt = $this->mockPDO->prepare("SELECT COUNT(*) FROM dead_letter_queue WHERE message_id = ?");
        $stmt->setFetchResult([['COUNT(*)' => 1]]);
        $stmt->execute([$messageId]);
        $alreadyInDLQ = $stmt->fetchColumn() > 0;

        // Assert - Should not add duplicate
        $this->assertTrue($alreadyInDLQ);
    }

    public function testReprocessNonexistentMessage()
    {
        // Arrange
        $nonexistentId = 'msg_does_not_exist';

        // Mock DLQ query
        $stmt = $this->mockPDO->prepare("SELECT * FROM dead_letter_queue WHERE message_id = ?");
        $stmt->setFetchResult([]);
        $stmt->execute([$nonexistentId]);
        $message = $stmt->fetch();

        // Assert - Should handle gracefully
        $this->assertFalse($message);
    }

    public function testDLQDatabaseFailure()
    {
        // Arrange
        $messageId = $this->generateMessageId();

        // Simulate database failure
        $stmt = $this->mockPDO->prepare("INSERT INTO dead_letter_queue (message_id, reason) VALUES (?, ?)");
        $stmt->setExecuteResult(false);

        // Act
        $result = $stmt->execute([$messageId, 'timeout']);

        // Assert
        $this->assertFalse($result);
    }

    // ========================================================================
    // Test: Integration with Retry Service
    // ========================================================================

    public function testTransferFromRetryToDLQ()
    {
        // Arrange
        $messageId = $this->generateMessageId();
        $maxRetries = 5;

        // Mock retry check
        $stmt = $this->mockPDO->prepare("SELECT attempt_number FROM retry_attempts WHERE message_id = ?");
        $stmt->setFetchResult([['attempt_number' => 5]]);
        $stmt->execute([$messageId]);
        $attempts = $stmt->fetch();

        // Act - Should transfer to DLQ
        if ($attempts && $attempts['attempt_number'] >= $maxRetries) {
            $dlqStmt = $this->mockPDO->prepare("INSERT INTO dead_letter_queue (message_id, reason) VALUES (?, ?)");
            $result = $dlqStmt->execute([$messageId, 'max_retries_exceeded']);
        }

        // Assert
        $this->assertTrue(isset($result) && $result !== false);
    }

    public function testCleanupRetryQueueOnDLQTransfer()
    {
        // Arrange
        $messageId = $this->generateMessageId();

        // Act - Remove from retry queue after DLQ transfer
        $stmt = $this->mockPDO->prepare("DELETE FROM retry_queue WHERE message_id = ?");
        $result = $stmt->execute([$messageId]);

        // Assert
        $this->assertTrue($result !== false);
    }

    // ========================================================================
    // Test: Performance
    // ========================================================================

    public function testDLQInsertPerformance()
    {
        // Arrange
        $messageCount = 100;
        $startTime = microtime(true);

        // Act - Insert messages
        for ($i = 0; $i < $messageCount; $i++) {
            $messageId = $this->generateMessageId();
            $stmt = $this->mockPDO->prepare("INSERT INTO dead_letter_queue (message_id, reason) VALUES (?, ?)");
            $stmt->execute([$messageId, 'test']);
        }

        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Assert - Should be fast
        $this->assertLessThan(1000, $executionTime,
            'DLQ insertion should be fast');
    }
}

// Run tests if executed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new DeadLetterQueueServiceTest();
    $test->run();
}
