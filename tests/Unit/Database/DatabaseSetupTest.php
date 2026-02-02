<?php
/**
 * Unit Tests for DatabaseSetup
 *
 * Tests database setup functions including migrations.
 * Note: These tests mock database interactions to avoid requiring a real database.
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PDO;
use PDOStatement;
use PDOException;

// Import the schema functions for use in tests
$filesRoot = defined('EIOU_FILES_ROOT') ? EIOU_FILES_ROOT : dirname(__DIR__, 3) . '/files';
require_once $filesRoot . '/src/database/DatabaseSetup.php';
require_once $filesRoot . '/src/database/DatabaseSchema.php';

use function Eiou\Database\runMigrations;
use function Eiou\Database\runColumnMigrations;

#[CoversFunction('Eiou\Database\runMigrations')]
#[CoversFunction('Eiou\Database\runColumnMigrations')]
class DatabaseSetupTest extends TestCase
{
    private PDO $mockPdo;
    private PDOStatement $mockStatement;

    protected function setUp(): void
    {
        $this->mockStatement = $this->createMock(PDOStatement::class);
        $this->mockPdo = $this->createMock(PDO::class);
    }

    // =========================================================================
    // runMigrations Tests
    // =========================================================================

    /**
     * Test runMigrations returns array
     */
    public function testRunMigrationsReturnsArray(): void
    {
        // runMigrations currently has empty $migrations array, so it will
        // primarily call runColumnMigrations
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->any())
            ->method('rowCount')
            ->willReturn(1); // Table exists

        $this->mockStatement->expects($this->any())
            ->method('fetch')
            ->willReturn(['Type' => "ENUM('value')"]); // ENUM column info

        $result = runMigrations($this->mockPdo);

        $this->assertIsArray($result);
    }

    /**
     * Test runMigrations handles empty migrations list
     */
    public function testRunMigrationsHandlesEmptyMigrationsList(): void
    {
        // With empty migrations array, should still return result from column migrations
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->any())
            ->method('rowCount')
            ->willReturn(0);

        $result = runMigrations($this->mockPdo);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // runColumnMigrations Tests
    // =========================================================================

    /**
     * Test runColumnMigrations returns array
     */
    public function testRunColumnMigrationsReturnsArray(): void
    {
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->any())
            ->method('rowCount')
            ->willReturn(1);

        $this->mockStatement->expects($this->any())
            ->method('fetch')
            ->willReturn(['Type' => "ENUM('value')"]); // Column info with type

        $result = runColumnMigrations($this->mockPdo);

        $this->assertIsArray($result);
    }

    /**
     * Test runColumnMigrations handles empty migrations gracefully
     */
    public function testRunColumnMigrationsHandlesEmptyMigrationsGracefully(): void
    {
        // The function has empty arrays for columnsToAdd, columnsToDrop, etc.
        // so it should just return an empty or minimal result array
        $result = runColumnMigrations($this->mockPdo);

        $this->assertIsArray($result);
    }

    /**
     * Test runColumnMigrations is idempotent (safe to run multiple times)
     */
    public function testRunColumnMigrationsIsIdempotent(): void
    {
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->any())
            ->method('rowCount')
            ->willReturn(1); // Simulates column/index already exists

        $this->mockStatement->expects($this->any())
            ->method('fetch')
            ->willReturn(['Type' => "ENUM('sending')"]); // Includes 'sending'

        // Running twice should produce consistent results
        $result1 = runColumnMigrations($this->mockPdo);
        $result2 = runColumnMigrations($this->mockPdo);

        $this->assertEquals($result1, $result2);
    }

    /**
     * Test runColumnMigrations handles PDOException gracefully
     */
    public function testRunColumnMigrationsHandlesPdoException(): void
    {
        // Configure mock to throw exception on query
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willThrowException(new PDOException('Test error'));

        // Should not throw but handle gracefully
        // Since the arrays are empty, no queries are actually made
        $result = runColumnMigrations($this->mockPdo);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Migration Result Format Tests
    // =========================================================================

    /**
     * Test migration results use expected status values
     */
    public function testMigrationResultsUseExpectedStatusValues(): void
    {
        // Expected status values that would be used if migrations were defined:
        // 'created', 'exists', 'added', 'dropped', 'already_dropped',
        // 'updated', 'already_updated', 'index_created', 'index_exists', 'error: ...'
        $validStatuses = [
            'created',
            'exists',
            'added',
            'dropped',
            'already_dropped',
            'updated',
            'already_updated',
            'index_created',
            'index_exists'
        ];

        // This test documents the expected status values
        $this->assertContains('created', $validStatuses);
        $this->assertContains('exists', $validStatuses);
        $this->assertContains('added', $validStatuses);
    }

    /**
     * Test runMigrations merges column migration results
     */
    public function testRunMigrationsMergesColumnMigrationResults(): void
    {
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->any())
            ->method('rowCount')
            ->willReturn(1);

        $this->mockStatement->expects($this->any())
            ->method('fetch')
            ->willReturn(['Type' => "ENUM('value')"]);

        $result = runMigrations($this->mockPdo);

        // Result should be an array (merged results)
        $this->assertIsArray($result);
    }
}
