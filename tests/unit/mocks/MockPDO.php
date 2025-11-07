<?php
/**
 * Mock PDO class for unit testing
 *
 * Provides a minimal PDO implementation that doesn't require a real database connection.
 * Used to test database interactions in isolation.
 */

namespace Tests\Unit\Mocks;

class MockPDO extends \PDO
{
    private array $queries = [];
    private array $preparedStatements = [];
    private $lastInsertId = 1;

    public function __construct()
    {
        // Override parent constructor to avoid requiring DSN
    }

    public function prepare($query, $options = []): MockPDOStatement
    {
        $stmt = new MockPDOStatement($query);
        $this->preparedStatements[] = $stmt;
        return $stmt;
    }

    public function exec($query): int|false
    {
        $this->queries[] = $query;
        return 1; // Number of affected rows
    }

    public function query($query, $fetchMode = null, ...$fetchModeArgs): MockPDOStatement|false
    {
        $this->queries[] = $query;
        $stmt = new MockPDOStatement($query);
        $stmt->setFetchResult([]);
        return $stmt;
    }

    public function lastInsertId($name = null): string|false
    {
        return (string)$this->lastInsertId++;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    // Test helper methods
    public function getExecutedQueries(): array
    {
        return $this->queries;
    }

    public function clearQueries(): void
    {
        $this->queries = [];
    }

    public function getPreparedStatements(): array
    {
        return $this->preparedStatements;
    }

    public function setNextInsertId(int $id): void
    {
        $this->lastInsertId = $id;
    }
}

class MockPDOStatement extends \PDOStatement
{
    private string $queryString;
    private array $boundParams = [];
    private $fetchResult = [];
    private int $fetchIndex = 0;
    private bool $executeResult = true;

    public function __construct(string $query)
    {
        $this->queryString = $query;
    }

    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = null, $driverOptions = null): bool
    {
        $this->boundParams[$param] = &$var;
        return true;
    }

    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool
    {
        $this->boundParams[$param] = $value;
        return true;
    }

    public function execute($params = null): bool
    {
        if ($params !== null) {
            $this->boundParams = array_merge($this->boundParams, $params);
        }
        return $this->executeResult;
    }

    public function fetch($mode = \PDO::FETCH_ASSOC, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0): mixed
    {
        if ($this->fetchIndex >= count($this->fetchResult)) {
            return false;
        }
        return $this->fetchResult[$this->fetchIndex++];
    }

    public function fetchAll($mode = \PDO::FETCH_ASSOC, ...$args): array
    {
        return $this->fetchResult;
    }

    public function fetchColumn($column = 0): mixed
    {
        $row = $this->fetch(\PDO::FETCH_NUM);
        return $row ? $row[$column] : false;
    }

    public function rowCount(): int
    {
        return count($this->fetchResult);
    }

    // Test helper methods
    public function setFetchResult(array $result): void
    {
        $this->fetchResult = $result;
        $this->fetchIndex = 0;
    }

    public function setExecuteResult(bool $result): void
    {
        $this->executeResult = $result;
    }

    public function getBoundParams(): array
    {
        return $this->boundParams;
    }

    public function getQueryString(): string
    {
        return $this->queryString;
    }
}
