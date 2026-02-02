<?php
/**
 * Unit Tests for AbstractRepository
 *
 * Tests the base repository functionality including CRUD operations,
 * column validation, transaction handling, and error logging.
 * Uses mocked PDO and PDOStatement to isolate database operations.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\AbstractRepository;
use PDO;
use PDOStatement;
use PDOException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(AbstractRepository::class)]
class AbstractRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private ConcreteTestRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->repository = new ConcreteTestRepository($this->pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor accepts PDO via dependency injection
     */
    public function testConstructorAcceptsPdoViaDependencyInjection(): void
    {
        $pdo = $this->createMock(PDO::class);
        $repository = new ConcreteTestRepository($pdo);

        $this->assertSame($pdo, $repository->getPdo());
    }

    /**
     * Test getPdo returns the injected PDO instance
     */
    public function testGetPdoReturnsInjectedInstance(): void
    {
        $this->assertSame($this->pdo, $this->repository->getPdo());
    }

    /**
     * Test getTableName returns correct table name
     */
    public function testGetTableNameReturnsCorrectTableName(): void
    {
        $this->assertEquals('test_table', $this->repository->getTableName());
    }

    // =========================================================================
    // Column Validation Tests
    // =========================================================================

    /**
     * Test isValidColumn returns true for valid column
     */
    public function testIsValidColumnReturnsTrueForValidColumn(): void
    {
        $result = $this->repository->publicIsValidColumn('id');
        $this->assertTrue($result);
    }

    /**
     * Test isValidColumn returns true for valid column (case insensitive)
     */
    public function testIsValidColumnIsCaseInsensitive(): void
    {
        $result = $this->repository->publicIsValidColumn('ID');
        $this->assertTrue($result);

        $result = $this->repository->publicIsValidColumn('Name');
        $this->assertTrue($result);
    }

    /**
     * Test isValidColumn returns false for invalid column
     */
    public function testIsValidColumnReturnsFalseForInvalidColumn(): void
    {
        $result = $this->repository->publicIsValidColumn('invalid_column');
        $this->assertFalse($result);
    }

    /**
     * Test validateColumn throws exception for invalid column
     */
    public function testValidateColumnThrowsExceptionForInvalidColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid column name: 'invalid_column' not in whitelist");

        $this->repository->publicValidateColumn('invalid_column');
    }

    /**
     * Test validateColumn does not throw for valid column
     */
    public function testValidateColumnDoesNotThrowForValidColumn(): void
    {
        // Should not throw
        $this->repository->publicValidateColumn('id');
        $this->repository->publicValidateColumn('name');
        $this->repository->publicValidateColumn('value');

        $this->assertTrue(true); // Assert that we got here without exception
    }

    /**
     * Test validateColumns throws exception for any invalid column
     */
    public function testValidateColumnsThrowsExceptionForAnyInvalidColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->publicValidateColumns(['id', 'invalid_column', 'name']);
    }

    /**
     * Test validateColumns passes for all valid columns
     */
    public function testValidateColumnsPassesForAllValidColumns(): void
    {
        // Should not throw
        $this->repository->publicValidateColumns(['id', 'name', 'value']);

        $this->assertTrue(true);
    }

    // =========================================================================
    // Execute Tests
    // =========================================================================

    /**
     * Test execute prepares and executes query with parameters
     */
    public function testExecutePreparesAndExecutesQueryWithParameters(): void
    {
        $query = "SELECT * FROM test_table WHERE id = :id";
        $params = [':id' => 1];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($query)
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':id', 1);

        $this->stmt->expects($this->once())
            ->method('execute');

        $result = $this->repository->publicExecute($query, $params);

        $this->assertSame($this->stmt, $result);
    }

    /**
     * Test execute returns false on PDOException
     */
    public function testExecuteReturnsFalseOnPdoException(): void
    {
        $query = "SELECT * FROM test_table";

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Database error'));

        $result = $this->repository->publicExecute($query);

        $this->assertFalse($result);
    }

    /**
     * Test execute handles multiple parameters
     */
    public function testExecuteHandlesMultipleParameters(): void
    {
        $query = "SELECT * FROM test_table WHERE id = :id AND name = :name";
        $params = [':id' => 1, ':name' => 'test'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($query)
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $result = $this->repository->publicExecute($query, $params);

        $this->assertSame($this->stmt, $result);
    }

    // =========================================================================
    // FindById Tests
    // =========================================================================

    /**
     * Test findById returns row when found
     */
    public function testFindByIdReturnsRowWhenFound(): void
    {
        $expectedRow = ['id' => 1, 'name' => 'Test', 'value' => 100];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':id', 1);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRow);

        $result = $this->repository->publicFindById(1);

        $this->assertEquals($expectedRow, $result);
    }

    /**
     * Test findById returns null when not found
     */
    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->publicFindById(999);

        $this->assertNull($result);
    }

    /**
     * Test findById returns null on query failure
     */
    public function testFindByIdReturnsNullOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->publicFindById(1);

        $this->assertNull($result);
    }

    // =========================================================================
    // FindByColumn Tests
    // =========================================================================

    /**
     * Test findByColumn returns row when found
     */
    public function testFindByColumnReturnsRowWhenFound(): void
    {
        $expectedRow = ['id' => 1, 'name' => 'Test', 'value' => 100];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRow);

        $result = $this->repository->publicFindByColumn('name', 'Test');

        $this->assertEquals($expectedRow, $result);
    }

    /**
     * Test findByColumn throws exception for invalid column
     */
    public function testFindByColumnThrowsExceptionForInvalidColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->publicFindByColumn('invalid_column', 'value');
    }

    /**
     * Test findByColumn returns null when not found
     */
    public function testFindByColumnReturnsNullWhenNotFound(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->publicFindByColumn('name', 'NonExistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // FindManyByColumn Tests
    // =========================================================================

    /**
     * Test findManyByColumn returns multiple rows
     */
    public function testFindManyByColumnReturnsMultipleRows(): void
    {
        $expectedRows = [
            ['id' => 1, 'name' => 'Test', 'value' => 100],
            ['id' => 2, 'name' => 'Test', 'value' => 200],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', 'Test');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRows);

        $result = $this->repository->publicFindManyByColumn('name', 'Test');

        $this->assertEquals($expectedRows, $result);
    }

    /**
     * Test findManyByColumn with limit parameter
     */
    public function testFindManyByColumnWithLimit(): void
    {
        $expectedRows = [
            ['id' => 1, 'name' => 'Test', 'value' => 100],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRows);

        $result = $this->repository->publicFindManyByColumn('name', 'Test', 1);

        $this->assertEquals($expectedRows, $result);
    }

    /**
     * Test findManyByColumn returns empty array on failure
     */
    public function testFindManyByColumnReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->publicFindManyByColumn('name', 'Test');

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // FindAll Tests
    // =========================================================================

    /**
     * Test findAll returns all rows without limit
     */
    public function testFindAllReturnsAllRowsWithoutLimit(): void
    {
        $expectedRows = [
            ['id' => 1, 'name' => 'Test1', 'value' => 100],
            ['id' => 2, 'name' => 'Test2', 'value' => 200],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRows);

        $result = $this->repository->publicFindAll();

        $this->assertEquals($expectedRows, $result);
    }

    /**
     * Test findAll with limit and offset
     */
    public function testFindAllWithLimitAndOffset(): void
    {
        $expectedRows = [
            ['id' => 2, 'name' => 'Test2', 'value' => 200],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedRows);

        $result = $this->repository->publicFindAll(1, 1);

        $this->assertEquals($expectedRows, $result);
    }

    /**
     * Test findAll returns empty array on failure
     */
    public function testFindAllReturnsEmptyArrayOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->publicFindAll();

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // Insert Tests
    // =========================================================================

    /**
     * Test insert creates new row and returns last insert ID
     */
    public function testInsertCreatesNewRowAndReturnsLastInsertId(): void
    {
        $data = ['name' => 'NewRow', 'value' => 300];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('5');

        $result = $this->repository->publicInsert($data);

        $this->assertEquals('5', $result);
    }

    /**
     * Test insert throws exception for invalid column
     */
    public function testInsertThrowsExceptionForInvalidColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $data = ['invalid_column' => 'value'];
        $this->repository->publicInsert($data);
    }

    /**
     * Test insert returns false on query failure
     */
    public function testInsertReturnsFalseOnQueryFailure(): void
    {
        $data = ['name' => 'NewRow', 'value' => 300];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->publicInsert($data);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Update Tests
    // =========================================================================

    /**
     * Test update modifies rows and returns affected count
     */
    public function testUpdateModifiesRowsAndReturnsAffectedCount(): void
    {
        $data = ['name' => 'UpdatedName'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->publicUpdate($data, 'id', 1);

        $this->assertEquals(1, $result);
    }

    /**
     * Test update throws exception for invalid where column
     */
    public function testUpdateThrowsExceptionForInvalidWhereColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $data = ['name' => 'UpdatedName'];
        $this->repository->publicUpdate($data, 'invalid_column', 'value');
    }

    /**
     * Test update throws exception for invalid data column
     */
    public function testUpdateThrowsExceptionForInvalidDataColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $data = ['invalid_column' => 'value'];
        $this->repository->publicUpdate($data, 'id', 1);
    }

    /**
     * Test update returns -1 on query failure
     */
    public function testUpdateReturnsNegativeOneOnQueryFailure(): void
    {
        $data = ['name' => 'UpdatedName'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Update failed'));

        $result = $this->repository->publicUpdate($data, 'id', 1);

        $this->assertEquals(-1, $result);
    }

    // =========================================================================
    // Delete Tests
    // =========================================================================

    /**
     * Test delete removes rows and returns deleted count
     */
    public function testDeleteRemovesRowsAndReturnsDeletedCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', 1);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->publicDelete('id', 1);

        $this->assertEquals(1, $result);
    }

    /**
     * Test delete throws exception for invalid column
     */
    public function testDeleteThrowsExceptionForInvalidColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->publicDelete('invalid_column', 'value');
    }

    /**
     * Test delete returns -1 on query failure
     */
    public function testDeleteReturnsNegativeOneOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Delete failed'));

        $result = $this->repository->publicDelete('id', 1);

        $this->assertEquals(-1, $result);
    }

    // =========================================================================
    // Count Tests
    // =========================================================================

    /**
     * Test count returns total row count without column filter
     */
    public function testCountReturnsTotalRowCountWithoutColumnFilter(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 10]);

        $result = $this->repository->publicCount();

        $this->assertEquals(10, $result);
    }

    /**
     * Test count returns filtered row count with column filter
     */
    public function testCountReturnsFilteredRowCountWithColumnFilter(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', 'Test');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 5]);

        $result = $this->repository->publicCount('name', 'Test');

        $this->assertEquals(5, $result);
    }

    /**
     * Test count returns 0 on query failure
     */
    public function testCountReturnsZeroOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Count failed'));

        $result = $this->repository->publicCount();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Exists Tests
    // =========================================================================

    /**
     * Test exists returns true when row exists
     */
    public function testExistsReturnsTrueWhenRowExists(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 1]);

        $result = $this->repository->publicExists('id', 1);

        $this->assertTrue($result);
    }

    /**
     * Test exists returns false when row does not exist
     */
    public function testExistsReturnsFalseWhenRowDoesNotExist(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->willReturn(['count' => 0]);

        $result = $this->repository->publicExists('id', 999);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Transaction Tests
    // =========================================================================

    /**
     * Test beginTransaction starts database transaction
     */
    public function testBeginTransactionStartsDatabaseTransaction(): void
    {
        $this->pdo->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(true);

        $result = $this->repository->publicBeginTransaction();

        $this->assertTrue($result);
    }

    /**
     * Test beginTransaction returns false on failure
     */
    public function testBeginTransactionReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('beginTransaction')
            ->willThrowException(new PDOException('Transaction error'));

        $result = $this->repository->publicBeginTransaction();

        $this->assertFalse($result);
    }

    /**
     * Test commit commits database transaction
     */
    public function testCommitCommitsDatabaseTransaction(): void
    {
        $this->pdo->expects($this->once())
            ->method('commit')
            ->willReturn(true);

        $result = $this->repository->publicCommit();

        $this->assertTrue($result);
    }

    /**
     * Test commit returns false on failure
     */
    public function testCommitReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('commit')
            ->willThrowException(new PDOException('Commit error'));

        $result = $this->repository->publicCommit();

        $this->assertFalse($result);
    }

    /**
     * Test rollback rolls back database transaction
     */
    public function testRollbackRollsBackDatabaseTransaction(): void
    {
        $this->pdo->expects($this->once())
            ->method('rollBack')
            ->willReturn(true);

        $result = $this->repository->publicRollback();

        $this->assertTrue($result);
    }

    /**
     * Test rollback returns false on failure
     */
    public function testRollbackReturnsFalseOnFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('rollBack')
            ->willThrowException(new PDOException('Rollback error'));

        $result = $this->repository->publicRollback();

        $this->assertFalse($result);
    }

    // =========================================================================
    // DecodeJsonFields Tests
    // =========================================================================

    /**
     * Test decodeJsonFields decodes single row JSON field
     */
    public function testDecodeJsonFieldsDecodesSingleRowJsonField(): void
    {
        $results = [
            'id' => 1,
            'name' => 'Test',
            'metadata' => '{"key": "value", "count": 10}'
        ];

        $decoded = $this->repository->publicDecodeJsonFields($results, 'metadata');

        $this->assertIsArray($decoded['metadata']);
        $this->assertEquals('value', $decoded['metadata']['key']);
        $this->assertEquals(10, $decoded['metadata']['count']);
    }

    /**
     * Test decodeJsonFields decodes multiple rows JSON fields
     */
    public function testDecodeJsonFieldsDecodesMultipleRowsJsonFields(): void
    {
        $results = [
            ['id' => 1, 'data' => '{"value": 100}'],
            ['id' => 2, 'data' => '{"value": 200}'],
        ];

        $decoded = $this->repository->publicDecodeJsonFields($results, 'data');

        $this->assertIsArray($decoded[0]['data']);
        $this->assertEquals(100, $decoded[0]['data']['value']);
        $this->assertIsArray($decoded[1]['data']);
        $this->assertEquals(200, $decoded[1]['data']['value']);
    }

    /**
     * Test decodeJsonFields handles multiple field names
     */
    public function testDecodeJsonFieldsHandlesMultipleFieldNames(): void
    {
        $results = [
            'id' => 1,
            'config' => '{"enabled": true}',
            'settings' => '{"theme": "dark"}'
        ];

        $decoded = $this->repository->publicDecodeJsonFields($results, ['config', 'settings']);

        $this->assertIsArray($decoded['config']);
        $this->assertTrue($decoded['config']['enabled']);
        $this->assertIsArray($decoded['settings']);
        $this->assertEquals('dark', $decoded['settings']['theme']);
    }

    /**
     * Test decodeJsonFields handles non-string field gracefully
     */
    public function testDecodeJsonFieldsHandlesNonStringFieldGracefully(): void
    {
        $results = [
            'id' => 1,
            'data' => ['already' => 'decoded']
        ];

        $decoded = $this->repository->publicDecodeJsonFields($results, 'data');

        // Should remain unchanged since it's already an array
        $this->assertEquals(['already' => 'decoded'], $decoded['data']);
    }
}

/**
 * Concrete implementation of AbstractRepository for testing
 *
 * This class exposes protected methods as public for testing purposes.
 */
class ConcreteTestRepository extends AbstractRepository
{
    protected array $allowedColumns = ['id', 'name', 'value', 'metadata', 'config', 'settings', 'data'];

    public function __construct(?PDO $pdo = null)
    {
        // Skip parent constructor to avoid UserContext dependency
        $this->pdo = $pdo;
        $this->tableName = 'test_table';
        $this->primaryKey = 'id';
    }

    // Public wrappers for protected methods
    public function publicIsValidColumn(string $column): bool
    {
        return $this->isValidColumn($column);
    }

    public function publicValidateColumn(string $column): void
    {
        $this->validateColumn($column);
    }

    public function publicValidateColumns(array $columns): void
    {
        $this->validateColumns($columns);
    }

    public function publicExecute(string $query, array $params = [])
    {
        return $this->execute($query, $params);
    }

    public function publicFindById($id): ?array
    {
        return $this->findById($id);
    }

    public function publicFindByColumn(string $column, $value): ?array
    {
        return $this->findByColumn($column, $value);
    }

    public function publicFindManyByColumn(string $column, $value, int $limit = 0): array
    {
        return $this->findManyByColumn($column, $value, $limit);
    }

    public function publicFindAll(int $limit = 0, int $offset = 0): array
    {
        return $this->findAll($limit, $offset);
    }

    public function publicInsert(array $data)
    {
        return $this->insert($data);
    }

    public function publicUpdate(array $data, string $whereColumn, $whereValue): int
    {
        return $this->update($data, $whereColumn, $whereValue);
    }

    public function publicDelete(string $column, $value): int
    {
        return $this->delete($column, $value);
    }

    public function publicCount(?string $column = null, $value = null): int
    {
        return $this->count($column, $value);
    }

    public function publicExists(string $column, $value): bool
    {
        return $this->exists($column, $value);
    }

    public function publicBeginTransaction(): bool
    {
        return $this->beginTransaction();
    }

    public function publicCommit(): bool
    {
        return $this->commit();
    }

    public function publicRollback(): bool
    {
        return $this->rollback();
    }

    public function publicDecodeJsonFields(array &$results, $fields): array
    {
        return $this->decodeJsonFields($results, $fields);
    }
}
