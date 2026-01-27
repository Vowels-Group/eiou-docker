<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../utils/SecureLogger.php';

/**
 * Abstract Repository Base Class
 *
 * Provides common database operations for all repository classes.
 * Uses DatabaseConnection to access PDO instance instead of global variables.
 *
 * Key Features:
 * - Dependency injection ready
 * - Proper error handling and logging
 * - Prepared statement helpers
 * - Transaction support
 * - Common CRUD operations
 *
 * @abstract
 */

abstract class AbstractRepository {
    /**
     * @var PDO Database connection instance
     */
    protected $pdo;

    /**
     * @var string Table name for this repository
     */
    protected $tableName;

    /**
     * @var string Primary key column name
     */
    protected $primaryKey = 'id';

    /**
     * @var array Whitelist of allowed column names for this repository
     * Child classes MUST define this to enable column validation
     */
    protected array $allowedColumns = [];

    /**
     * @var UserContext object of user data
     */
    protected $currentUser;

    /**
     * Constructor - Initializes PDO connection
     *
     * @param PDO|null $pdo Optional PDO instance (for testing/DI)
     * @throws RuntimeException If database connection fails
     */
    public function __construct(?PDO $pdo = null) {
        if ($pdo !== null) {
            // Use injected PDO (useful for testing)
            $this->pdo = $pdo;
        } else {
            // Get PDO from DatabaseConnection helper
            require_once __DIR__ . '/Pdo.php';
            try {
                $this->pdo = createPDOConnection();
            } catch (RuntimeException $e) {
                SecureLogger::error("[" . static::class . "] Repository initialization failed", [
                    'error' => $e->getMessage()
                ]);
                throw new RuntimeException(
                    "Failed to initialize " . static::class . ": " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            }
        }

        if (!$this->pdo) {
            $errorMessage = "Failed to initialize repository: Database connection unavailable";
            SecureLogger::error("[" . static::class . "] " . $errorMessage);
            throw new RuntimeException($errorMessage);
        }
        $this->loadCurrentUser();
    }

    /**
     * Load current user
     */
    private function loadCurrentUser(): void {
        require_once '/etc/eiou/src/core/UserContext.php';
        $this->currentUser = UserContext::getInstance();
    }

    /**
     * Validate that a column name is in the allowed whitelist
     *
     * @param string $column Column name to validate
     * @return bool True if column is allowed
     */
    protected function isValidColumn(string $column): bool {
        // If no whitelist defined, warn but allow (backwards compatibility)
        if (empty($this->allowedColumns)) {
            SecureLogger::warning("[" . static::class . "] No column whitelist defined - column validation skipped", [
                'column' => $column
            ]);
            return true;
        }

        // Check if column is in whitelist (case-insensitive)
        return in_array(strtolower($column), array_map('strtolower', $this->allowedColumns), true);
    }

    /**
     * Validate column and throw exception if invalid
     *
     * @param string $column Column name to validate
     * @throws InvalidArgumentException If column is not in whitelist
     */
    protected function validateColumn(string $column): void {
        if (!$this->isValidColumn($column)) {
            $error = "Invalid column name: '$column' not in whitelist for " . static::class;
            SecureLogger::warning($error, [
                'column' => $column,
                'allowed' => $this->allowedColumns
            ]);
            throw new InvalidArgumentException($error);
        }
    }

    /**
     * Validate multiple columns at once
     *
     * @param array $columns Array of column names to validate
     * @throws InvalidArgumentException If any column is not in whitelist
     */
    protected function validateColumns(array $columns): void {
        foreach ($columns as $column) {
            $this->validateColumn($column);
        }
    }

    /**
     * Execute a prepared statement with parameters
     *
     * @param string $query SQL query with named placeholders
     * @param array $params Associative array of parameters
     * @return PDOStatement|false
     */
    protected function execute(string $query, array $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->execute();
            return $stmt;
        } catch (PDOException $e) {
            $this->logError("Query execution failed", $e, $query);
            return false;
        }
    }

    /**
     * Fetch a single row by ID
     *
     * @param mixed $id Primary key value
     * @return array|null Row data or null if not found
     */
    protected function findById($id): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE {$this->primaryKey} = :id LIMIT 1";
        $stmt = $this->execute($query, [':id' => $id]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Fetch a single row by any column
     *
     * @param string $column Column name
     * @param mixed $value Column value
     * @return array|null Row data or null if not found
     * @throws InvalidArgumentException If column is not in whitelist
     */
    protected function findByColumn(string $column, $value): ?array {
        $this->validateColumn($column);
        $query = "SELECT * FROM {$this->tableName} WHERE {$column} = :value LIMIT 1";
        $stmt = $this->execute($query, [':value' => $value]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Fetch multiple rows by column
     *
     * @param string $column Column name
     * @param mixed $value Column value
     * @param int $limit Maximum number of rows (0 = no limit)
     * @return array Array of rows
     * @throws InvalidArgumentException If column is not in whitelist
     */
    protected function findManyByColumn(string $column, $value, int $limit = 0): array {
        $this->validateColumn($column);
        $query = "SELECT * FROM {$this->tableName} WHERE {$column} = :value";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':value', $value);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to fetch multiple rows", $e);
            return [];
        }
    }

    /**
     * Fetch all rows from table
     *
     * @param int $limit Maximum number of rows (0 = no limit)
     * @param int $offset Starting offset
     * @return array Array of rows
     */
    protected function findAll(int $limit = 0, int $offset = 0): array {
        $query = "SELECT * FROM {$this->tableName}";

        if ($limit > 0) {
            $query .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $this->pdo->prepare($query);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to fetch all rows", $e);
            return [];
        }
    }

    /**
     * Insert a new row
     *
     * @param array $data Associative array of column => value
     * @return string|false Last insert ID or false on failure
     * @throws InvalidArgumentException If any column is not in whitelist
     */
    protected function insert(array $data) {
        $columns = array_keys($data);
        $this->validateColumns($columns);
        $placeholders = array_map(fn($col) => ":$col", $columns);

        $query = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->tableName,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($data as $key => $value) {
            $params[":$key"] = $value;
        }

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return false;
        }

        return $this->pdo->lastInsertId();
    }

    /**
     * Update rows by condition
     *
     * @param array $data Associative array of column => value to update
     * @param string $whereColumn WHERE condition column
     * @param mixed $whereValue WHERE condition value
     * @return int Number of affected rows, -1 on error
     * @throws InvalidArgumentException If any column is not in whitelist
     */
    protected function update(array $data, string $whereColumn, $whereValue): int {
        $this->validateColumn($whereColumn);
        $this->validateColumns(array_keys($data));
        $setParts = array_map(fn($col) => "$col = :$col", array_keys($data));

        $query = sprintf(
            "UPDATE %s SET %s WHERE %s = :where_value",
            $this->tableName,
            implode(', ', $setParts),
            $whereColumn
        );

        $params = [];
        foreach ($data as $key => $value) {
            $params[":$key"] = $value;
        }
        $params[':where_value'] = $whereValue;

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return -1;
        }

        return $stmt->rowCount();
    }

    /**
     * Delete rows by condition
     *
     * @param string $column Column name for WHERE clause
     * @param mixed $value Column value
     * @return int Number of deleted rows, -1 on error
     * @throws InvalidArgumentException If column is not in whitelist
     */
    protected function delete(string $column, $value): int {
        $this->validateColumn($column);
        $query = "DELETE FROM {$this->tableName} WHERE {$column} = :value";
        $stmt = $this->execute($query, [':value' => $value]);

        if (!$stmt) {
            return -1;
        }

        return $stmt->rowCount();
    }

    /**
     * Count rows matching condition
     *
     * @param string|null $column Column name (null for count all)
     * @param mixed|null $value Column value
     * @return int Row count
     * @throws InvalidArgumentException If column is not in whitelist
     */
    protected function count(?string $column = null, $value = null): int {
        if ($column === null) {
            $query = "SELECT COUNT(*) as count FROM {$this->tableName}";
            $params = [];
        } else {
            $this->validateColumn($column);
            $query = "SELECT COUNT(*) as count FROM {$this->tableName} WHERE {$column} = :value";
            $params = [':value' => $value];
        }

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Check if a row exists
     *
     * @param string $column Column name
     * @param mixed $value Column value
     * @return bool True if exists
     * @throws InvalidArgumentException If column is not in whitelist
     */
    protected function exists(string $column, $value): bool {
        // Note: validateColumn is called in count()
        return $this->count($column, $value) > 0;
    }

    /**
     * Begin a database transaction
     *
     * @return bool Success status
     */
    protected function beginTransaction(): bool {
        try {
            return $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            $this->logError("Failed to begin transaction", $e);
            return false;
        }
    }

    /**
     * Commit a database transaction
     *
     * @return bool Success status
     */
    protected function commit(): bool {
        try {
            return $this->pdo->commit();
        } catch (PDOException $e) {
            $this->logError("Failed to commit transaction", $e);
            return false;
        }
    }

    /**
     * Rollback a database transaction
     *
     * @return bool Success status
     */
    protected function rollback(): bool {
        try {
            return $this->pdo->rollBack();
        } catch (PDOException $e) {
            $this->logError("Failed to rollback transaction", $e);
            return false;
        }
    }

    /**
     * Log database errors using SecureLogger
     *
     * @param string $message Error message
     * @param PDOException|null $exception Exception object
     * @param string|null $query SQL query that failed
     */
    protected function logError(string $message, ?PDOException $exception = null, ?string $query = null): void {
        // Prepare context for logging
        $context = [
            'repository' => static::class,
            'message' => $message
        ];

        if ($query) {
            $context['query'] = $query;
        }

        // Use SecureLogger for consistent error logging
        if ($exception) {
            SecureLogger::logException($exception, $context);
        } else {
            $logMessage = "[" . static::class . "] $message";
            if ($query) {
                $logMessage .= " | Query: $query";
            }
            SecureLogger::debug($logMessage);
        }
    }

    /**
     * Get the PDO instance (for advanced operations)
     *
     * @return PDO
     */
    public function getPdo(): PDO {
        return $this->pdo;
    }

    /**
     * Get table name
     *
     * @return string
     */
    public function getTableName(): string {
        return $this->tableName;
    }

    /**
     * Decode JSON field(s) in result array(s)
     *
     * @param array $results Array of result rows (or single row)
     * @param string|array $fields Field name(s) to decode
     * @return array Results with decoded JSON fields
     */
    protected function decodeJsonFields(array &$results, $fields): array {
        $fieldList = is_array($fields) ? $fields : [$fields];

        // Check if this is a single row (associative) or multiple rows (indexed)
        $isSingleRow = !empty($results) && !isset($results[0]);

        if ($isSingleRow) {
            foreach ($fieldList as $field) {
                if (isset($results[$field]) && is_string($results[$field])) {
                    $results[$field] = json_decode($results[$field], true);
                }
            }
        } else {
            foreach ($results as &$item) {
                foreach ($fieldList as $field) {
                    if (isset($item[$field]) && is_string($item[$field])) {
                        $item[$field] = json_decode($item[$field], true);
                    }
                }
            }
        }

        return $results;
    }
}
