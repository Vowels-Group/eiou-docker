<?php
/**
 * Unit Tests for DebugRepository
 *
 * Tests debug repository operations including inserting debug entries,
 * retrieving entries, counting, pruning, and clearing with mocked PDO.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\DebugRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(DebugRepository::class)]
class DebugRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private DebugRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new DebugRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets table name correctly
     */
    public function testConstructorSetsTableName(): void
    {
        $this->assertEquals('debug', $this->repository->getTableName());
    }

    /**
     * Test constructor accepts PDO dependency injection
     */
    public function testConstructorAcceptsPdoDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new DebugRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    // =========================================================================
    // insertDebug() Tests
    // =========================================================================

    /**
     * Test insertDebug inserts debug entry successfully
     */
    public function testInsertDebugInsertsEntrySuccessfully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $data = [
            'level' => 'INFO',
            'message' => 'Test debug message',
            'context' => ['key' => 'value'],
            'file' => '/app/src/test.php',
            'line' => 42,
            'trace' => 'stack trace here'
        ];

        // Should not throw exception
        $this->repository->insertDebug($data);
        $this->assertTrue(true);
    }

    /**
     * Test insertDebug with minimal data uses defaults
     */
    public function testInsertDebugWithMinimalDataUsesDefaults(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $data = [];

        $this->repository->insertDebug($data);
        $this->assertTrue(true);
    }

    /**
     * Test insertDebug handles PDOException gracefully
     */
    public function testInsertDebugHandlesPdoExceptionGracefully(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $data = [
            'level' => 'ERROR',
            'message' => 'Test error message'
        ];

        // Should not throw exception, just log the error
        $this->repository->insertDebug($data);
        $this->assertTrue(true);
    }

    /**
     * Test insertDebug encodes context as JSON
     */
    public function testInsertDebugEncodesContextAsJson(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $jsonEncodedContext = null;

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$jsonEncodedContext) {
                if ($key === ':context') {
                    $jsonEncodedContext = $value;
                }
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $data = [
            'level' => 'INFO',
            'message' => 'Test message',
            'context' => ['user' => 'test', 'action' => 'login']
        ];

        $this->repository->insertDebug($data);

        $this->assertJson($jsonEncodedContext);
        $this->assertEquals($data['context'], json_decode($jsonEncodedContext, true));
    }

    // =========================================================================
    // getRecentDebugEntries() Tests
    // =========================================================================

    /**
     * Test getRecentDebugEntries returns recent entries
     */
    public function testGetRecentDebugEntriesReturnsRecentEntries(): void
    {
        $entries = [
            ['id' => 1, 'level' => 'INFO', 'message' => 'Entry 1', 'timestamp' => '2024-01-01 12:00:00'],
            ['id' => 2, 'level' => 'ERROR', 'message' => 'Entry 2', 'timestamp' => '2024-01-01 12:01:00']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 100, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($entries);

        $result = $this->repository->getRecentDebugEntries();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * Test getRecentDebugEntries with custom limit
     */
    public function testGetRecentDebugEntriesWithCustomLimit(): void
    {
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
            ->willReturn([]);

        $result = $this->repository->getRecentDebugEntries(50);

        $this->assertIsArray($result);
    }

    /**
     * Test getRecentDebugEntries returns empty array on exception
     */
    public function testGetRecentDebugEntriesReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->getRecentDebugEntries();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // clearDebugEntries() Tests
    // =========================================================================

    /**
     * Test clearDebugEntries clears all entries
     */
    public function testClearDebugEntriesClearsAllEntries(): void
    {
        $this->pdo->expects($this->once())
            ->method('exec')
            ->willReturn(10);

        $result = $this->repository->clearDebugEntries();

        $this->assertTrue($result);
    }

    /**
     * Test clearDebugEntries returns false on exception
     */
    public function testClearDebugEntriesReturnsFalseOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('exec')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->clearDebugEntries();

        $this->assertFalse($result);
    }

    // =========================================================================
    // getAllDebugEntries() Tests
    // =========================================================================

    /**
     * Test getAllDebugEntries returns all entries up to max limit
     */
    public function testGetAllDebugEntriesReturnsAllEntries(): void
    {
        $entries = [
            ['id' => 1, 'level' => 'INFO', 'message' => 'Entry 1'],
            ['id' => 2, 'level' => 'INFO', 'message' => 'Entry 2'],
            ['id' => 3, 'level' => 'INFO', 'message' => 'Entry 3']
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 10000, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($entries);

        $result = $this->repository->getAllDebugEntries();

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    /**
     * Test getAllDebugEntries with custom max entries
     */
    public function testGetAllDebugEntriesWithCustomMaxEntries(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':limit', 500, PDO::PARAM_INT);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->getAllDebugEntries(500);

        $this->assertIsArray($result);
    }

    /**
     * Test getAllDebugEntries returns empty array on exception
     */
    public function testGetAllDebugEntriesReturnsEmptyArrayOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->getAllDebugEntries();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getDebugEntryCount() Tests
    // =========================================================================

    /**
     * Test getDebugEntryCount returns count of entries
     */
    public function testGetDebugEntryCountReturnsCount(): void
    {
        $stmtMock = $this->createMock(PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(42);

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($stmtMock);

        $result = $this->repository->getDebugEntryCount();

        $this->assertEquals(42, $result);
    }

    /**
     * Test getDebugEntryCount returns zero on exception
     */
    public function testGetDebugEntryCountReturnsZeroOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('query')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->getDebugEntryCount();

        $this->assertEquals(0, $result);
    }

    /**
     * Test getDebugEntryCount returns zero when no entries
     */
    public function testGetDebugEntryCountReturnsZeroWhenNoEntries(): void
    {
        $stmtMock = $this->createMock(PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(0);

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($stmtMock);

        $result = $this->repository->getDebugEntryCount();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // pruneOldEntries() Tests
    // =========================================================================

    /**
     * Test pruneOldEntries keeps specified number of entries
     */
    public function testPruneOldEntriesKeepsSpecifiedNumber(): void
    {
        $thresholdStmt = $this->createMock(PDOStatement::class);
        $deleteStmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($thresholdStmt, $deleteStmt);

        $thresholdStmt->expects($this->once())
            ->method('bindValue')
            ->with(':offset', 99, PDO::PARAM_INT);

        $thresholdStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $thresholdStmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['id' => 50]);

        $deleteStmt->expects($this->once())
            ->method('bindValue')
            ->with(':threshold_id', 50, PDO::PARAM_INT);

        $deleteStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->pruneOldEntries(100);

        $this->assertTrue($result);
    }

    /**
     * Test pruneOldEntries with default keep count
     */
    public function testPruneOldEntriesWithDefaultKeepCount(): void
    {
        $thresholdStmt = $this->createMock(PDOStatement::class);
        $deleteStmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($thresholdStmt, $deleteStmt);

        $thresholdStmt->expects($this->once())
            ->method('bindValue')
            ->with(':offset', 99, PDO::PARAM_INT);

        $thresholdStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $thresholdStmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['id' => 100]);

        $deleteStmt->expects($this->once())
            ->method('bindValue');

        $deleteStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $result = $this->repository->pruneOldEntries();

        $this->assertTrue($result);
    }

    /**
     * Test pruneOldEntries does nothing when entries below threshold
     */
    public function testPruneOldEntriesDoesNothingWhenBelowThreshold(): void
    {
        $thresholdStmt = $this->createMock(PDOStatement::class);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($thresholdStmt);

        $thresholdStmt->expects($this->once())
            ->method('bindValue');

        $thresholdStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $thresholdStmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(null);

        $result = $this->repository->pruneOldEntries(100);

        $this->assertTrue($result);
    }

    /**
     * Test pruneOldEntries returns false on exception
     */
    public function testPruneOldEntriesReturnsFalseOnException(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->pruneOldEntries();

        $this->assertFalse($result);
    }

    // =========================================================================
    // Edge Cases Tests
    // =========================================================================

    /**
     * Test insertDebug with null context
     */
    public function testInsertDebugWithNullContext(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $data = [
            'level' => 'INFO',
            'message' => 'Test message',
            'context' => null
        ];

        $this->repository->insertDebug($data);
        $this->assertTrue(true);
    }

    /**
     * Test insertDebug with empty context array
     */
    public function testInsertDebugWithEmptyContextArray(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $data = [
            'level' => 'INFO',
            'message' => 'Test message',
            'context' => []
        ];

        $this->repository->insertDebug($data);
        $this->assertTrue(true);
    }

    /**
     * Test different log levels are accepted
     */
    public function testDifferentLogLevelsAreAccepted(): void
    {
        $logLevels = ['SILENT', 'ECHO', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];

        foreach ($logLevels as $level) {
            $pdo = $this->createMock(PDO::class);
            $stmt = $this->createMock(PDOStatement::class);
            $repository = new DebugRepository($pdo);

            $pdo->expects($this->once())
                ->method('prepare')
                ->willReturn($stmt);

            $stmt->expects($this->exactly(6))
                ->method('bindValue');

            $stmt->expects($this->once())
                ->method('execute')
                ->willReturn(true);

            $data = [
                'level' => $level,
                'message' => "Test $level message"
            ];

            $repository->insertDebug($data);
            $this->assertTrue(true, "Failed for log level: $level");
        }
    }

    /**
     * Test insertDebug with very long message
     */
    public function testInsertDebugWithVeryLongMessage(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $data = [
            'level' => 'INFO',
            'message' => str_repeat('A', 10000) // Very long message
        ];

        $this->repository->insertDebug($data);
        $this->assertTrue(true);
    }

    /**
     * Test insertDebug with complex context object
     */
    public function testInsertDebugWithComplexContextObject(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(6))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $data = [
            'level' => 'INFO',
            'message' => 'Complex context test',
            'context' => [
                'user' => [
                    'id' => 123,
                    'name' => 'Test User'
                ],
                'request' => [
                    'method' => 'POST',
                    'path' => '/api/test',
                    'params' => ['a' => 1, 'b' => 2]
                ],
                'nested' => [
                    'level1' => [
                        'level2' => [
                            'level3' => 'deep value'
                        ]
                    ]
                ]
            ]
        ];

        $this->repository->insertDebug($data);
        $this->assertTrue(true);
    }
}
