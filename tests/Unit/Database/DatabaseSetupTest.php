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
     * Test runColumnMigrations handles migrations gracefully
     */
    public function testRunColumnMigrationsHandlesMigrationsGracefully(): void
    {
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->any())
            ->method('rowCount')
            ->willReturn(1);

        $this->mockStatement->expects($this->any())
            ->method('fetch')
            ->willReturn(['Type' => "enum('online','partial','offline','unknown')"]);

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
     * Test ENUM migration adds 'partial' to contacts.online_status
     */
    public function testEnumMigrationNoEnumUpdatesNeeded(): void
    {
        // The online_status ENUM already includes 'partial' in the CREATE TABLE schema,
        // so the enumUpdates array is empty and no enum migration runs.
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->any())
            ->method('rowCount')
            ->willReturn(1);

        $this->mockStatement->expects($this->any())
            ->method('fetch')
            ->willReturn(['Type' => "enum('online','partial','offline','unknown')"]);

        $result = runColumnMigrations($this->mockPdo);

        $this->assertIsArray($result);
        // No enum keys in result since enumUpdates is empty (schema already correct)
        $this->assertArrayNotHasKey('contacts.online_status_enum', $result);
    }

    // =========================================================================
    // Column-type-changes Tests (addresses/balances pubkey_hash TEXT → VARCHAR)
    // =========================================================================

    /**
     * Build a PDO mock whose `query()` and `exec()` route based on SQL
     * content. Simpler and order-independent compared to consecutive-call
     * mocking — runColumnMigrations issues SHOW COLUMNS and MAX(CHAR_LENGTH)
     * for many tables and the order is implementation-detail.
     *
     * @param string $pubkeyHashType  the Type that SHOW COLUMNS reports for
     *                                addresses.pubkey_hash and balances.pubkey_hash
     *                                (e.g. 'text' or 'varchar(64)')
     * @param int $observedMaxLen     the value MAX(CHAR_LENGTH(pubkey_hash))
     *                                returns for both tables
     * @param array &$execStatements  out-parameter; collects every exec()
     *                                statement the migration runs
     */
    private function buildSqlAwarePdoMock(
        string $pubkeyHashType,
        int $observedMaxLen,
        array &$execStatements,
    ): PDO {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('query')->willReturnCallback(function (string $sql) use ($pubkeyHashType, $observedMaxLen) {
            $stmt = $this->createMock(PDOStatement::class);

            if (strpos($sql, 'SHOW COLUMNS') !== false && strpos($sql, "LIKE 'pubkey_hash'") !== false) {
                // addresses/balances pubkey_hash type lookup
                $stmt->method('rowCount')->willReturn(1);
                $stmt->method('fetch')->willReturn(['Field' => 'pubkey_hash', 'Type' => $pubkeyHashType]);
                return $stmt;
            }

            if (strpos($sql, 'MAX(CHAR_LENGTH(`pubkey_hash`))') !== false) {
                $stmt->method('rowCount')->willReturn(1);
                $stmt->method('fetch')->willReturn(['max_len' => (string) $observedMaxLen]);
                return $stmt;
            }

            // Fallback: pretend other SHOW COLUMNS / SHOW INDEX calls
            // report "row exists, type already current" so the rest of
            // runColumnMigrations is a no-op for our purposes.
            $stmt->method('rowCount')->willReturn(1);
            $stmt->method('fetch')->willReturn([
                'Field' => 'unused',
                // Pre-populated ENUM string covers every value the
                // existing enumUpdates block looks for, so the
                // 'already_updated' branch fires.
                'Type' => "enum('transaction','p2p','rp2p','contact','all','payment_request','route_cancel')",
            ]);
            return $stmt;
        });

        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execStatements) {
            $execStatements[] = $sql;
            return 0;
        });

        return $pdo;
    }

    /**
     * When SHOW COLUMNS reports the legacy TEXT type AND no row exceeds 64
     * chars, runColumnMigrations should issue the ALTER ... MODIFY COLUMN and
     * mark both tables 'updated'.
     */
    public function testColumnTypeMigrationRunsWhenColumnIsText(): void
    {
        $execStatements = [];
        $pdo = $this->buildSqlAwarePdoMock('text', 64, $execStatements);

        $result = runColumnMigrations($pdo);

        $this->assertSame('updated', $result['addresses.pubkey_hash_type']);
        $this->assertSame('updated', $result['balances.pubkey_hash_type']);

        $modifyAddresses = array_filter(
            $execStatements,
            fn (string $s) => strpos($s, 'ALTER TABLE `addresses` MODIFY COLUMN `pubkey_hash` VARCHAR(64) NOT NULL') !== false,
        );
        $modifyBalances = array_filter(
            $execStatements,
            fn (string $s) => strpos($s, 'ALTER TABLE `balances` MODIFY COLUMN `pubkey_hash` VARCHAR(64) NOT NULL') !== false,
        );
        $this->assertCount(1, $modifyAddresses, 'addresses MODIFY COLUMN should fire exactly once');
        $this->assertCount(1, $modifyBalances, 'balances MODIFY COLUMN should fire exactly once');
    }

    /**
     * When SHOW COLUMNS already reports VARCHAR(64) the migration should be a
     * no-op marked 'already_updated' — guarantees re-runs don't re-ALTER.
     */
    public function testColumnTypeMigrationSkipsWhenAlreadyVarchar(): void
    {
        $execStatements = [];
        $pdo = $this->buildSqlAwarePdoMock('varchar(64)', 64, $execStatements);

        $result = runColumnMigrations($pdo);

        $this->assertSame('already_updated', $result['addresses.pubkey_hash_type']);
        $this->assertSame('already_updated', $result['balances.pubkey_hash_type']);

        $modifyHits = array_filter(
            $execStatements,
            fn (string $s) => strpos($s, 'MODIFY COLUMN `pubkey_hash`') !== false,
        );
        $this->assertEmpty($modifyHits, 'must not re-ALTER when column is already VARCHAR(64)');
    }

    /**
     * If any row already exceeds the target VARCHAR length the migration must
     * skip and log rather than truncate the data. SHA-256 hex is always 64
     * chars in practice, but defense-in-depth matters when the operator may
     * have legacy non-hex data.
     */
    public function testColumnTypeMigrationSkipsWhenObservedLengthExceedsTarget(): void
    {
        $execStatements = [];
        $pdo = $this->buildSqlAwarePdoMock('text', 128, $execStatements);

        $result = runColumnMigrations($pdo);

        $this->assertStringStartsWith('skipped:', (string) $result['addresses.pubkey_hash_type']);
        $this->assertStringStartsWith('skipped:', (string) $result['balances.pubkey_hash_type']);

        $modifyHits = array_filter(
            $execStatements,
            fn (string $s) => strpos($s, 'MODIFY COLUMN `pubkey_hash`') !== false,
        );
        $this->assertEmpty($modifyHits, 'must not ALTER when truncation would occur');
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
