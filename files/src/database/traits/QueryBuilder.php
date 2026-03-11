<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database\Traits;

use Eiou\Utils\Logger;
use PDO;
use PDOException;

/**
 * Query Builder Trait
 *
 * Provides common query building utilities for repository classes.
 * Reduces duplication of placeholder generation and parameter building.
 *
 * @package Database\Traits
 */
trait QueryBuilder
{
    /**
     * Create SQL placeholders for IN clause
     *
     * @param array $values Array of values to create placeholders for
     * @return string Placeholder string like "?,?,?" or empty string if no values
     */
    protected function createPlaceholders(array $values): string
    {
        $count = count($values);
        if ($count === 0) {
            return '';
        }
        return str_repeat('?,', $count - 1) . '?';
    }

    /**
     * Build parameters for query with multiple IN clauses using same values
     *
     * @param array $values Base values (e.g., user addresses)
     * @param int $repeatCount Number of times to repeat values (for multiple IN clauses)
     * @param array $additionalParams Additional parameters to append (e.g., limit)
     * @return array Combined parameters ready for execute()
     */
    protected function buildInClauseParams(array $values, int $repeatCount = 1, array $additionalParams = []): array
    {
        $params = [];
        for ($i = 0; $i < $repeatCount; $i++) {
            $params = array_merge($params, $values);
        }
        return array_merge($params, $additionalParams);
    }

    /**
     * Get user addresses with empty check
     *
     * Returns user addresses or null if empty, allowing early return pattern.
     * Requires $this->currentUser to be set.
     *
     * @return array|null User addresses or null if empty
     */
    protected function getUserAddressesOrNull(): ?array
    {
        if (!isset($this->currentUser)) {
            return null;
        }
        $addresses = $this->currentUser->getUserAddresses();
        return empty($addresses) ? null : $addresses;
    }

    /**
     * Execute a simple select query and return all results
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array Results or empty array on failure
     */
    protected function executeSelectAll(string $query, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::getInstance()->log('executeSelectAll query failed: ' . $e->getMessage(), 'WARNING');
            return [];
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Execute a simple select query and return single result
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array|null Single row or null on failure/not found
     */
    protected function executeSelectOne(string $query, array $params = []): ?array
    {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::getInstance()->log('executeSelectOne query failed: ' . $e->getMessage(), 'WARNING');
            return null;
        }

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Build a simple user transaction query
     *
     * Helper for common pattern: SELECT ... WHERE address IN (user addresses) LIMIT ?
     *
     * @param string $selectFields Fields to select
     * @param string $addressField Which address field to filter (sender_address or receiver_address)
     * @param string|null $currencyFilter Optional currency filter
     * @param string|null $additionalAddressFilter Optional additional address filter (e.g., specific sender)
     * @return string SQL query with placeholders
     */
    protected function buildUserTransactionQuery(
        string $selectFields,
        string $addressField,
        ?string $currencyFilter = null,
        ?string $additionalAddressFilter = null
    ): string {
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null) {
            return '';
        }

        $placeholders = $this->createPlaceholders($userAddresses);
        $otherAddressField = $addressField === 'sender_address' ? 'receiver_address' : 'sender_address';

        $query = "SELECT {$selectFields} FROM {$this->tableName} WHERE {$addressField} IN ({$placeholders})";

        if ($additionalAddressFilter !== null) {
            $query .= " AND {$otherAddressField} = ?";
        }

        if ($currencyFilter !== null) {
            $query .= " AND currency = ?";
        }

        $query .= " ORDER BY timestamp DESC LIMIT ?";

        return $query;
    }

    /**
     * Build complete IN clause with placeholders
     *
     * @param array $values Array of values for IN clause
     * @return string Complete IN clause like "IN (?,?,?)" or empty string if no values
     */
    protected function buildInClause(array $values): string
    {
        if (empty($values)) {
            return '';
        }
        $placeholders = $this->createPlaceholders($values);
        return "IN ({$placeholders})";
    }

    /**
     * Build WHERE clause from array of conditions
     *
     * Supports multiple formats:
     * - Simple: ['column' => 'value'] becomes "column = ?"
     * - With operator: ['column >' => 'value'] becomes "column > ?"
     * - Raw SQL: ['column IN (?,?,?)'] (numeric key)
     *
     * @param array $conditions Associative array of conditions
     * @return string WHERE clause parts joined by AND (without "WHERE" keyword)
     */
    protected function buildWhereClause(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }

        $parts = [];
        foreach ($conditions as $key => $value) {
            if (is_numeric($key)) {
                // Numeric keys (raw SQL conditions) are blocked to prevent SQL injection
                throw new \InvalidArgumentException("Raw SQL conditions via numeric keys are not allowed in QueryBuilder");
            } elseif (strpos($key, ' ') !== false) {
                // Key contains operator (e.g., "column >")
                $parts[] = "{$key} ?";
            } else {
                // Simple column = value
                $parts[] = "{$key} = ?";
            }
        }

        return implode(' AND ', $parts);
    }

    /**
     * Build ORDER BY clause from array of columns
     *
     * Supports formats:
     * - Simple: ['column1', 'column2'] - defaults to ASC
     * - With direction: ['column1' => 'DESC', 'column2' => 'ASC']
     *
     * @param array $columns Array of column specifications
     * @return string ORDER BY clause (without "ORDER BY" keyword)
     */
    protected function buildOrderByClause(array $columns): string
    {
        if (empty($columns)) {
            return '';
        }

        $parts = [];
        foreach ($columns as $key => $value) {
            if (is_numeric($key)) {
                // Simple column name, default to ASC - validate if allowedColumns is available
                if (isset($this->allowedColumns) && !empty($this->allowedColumns) && !$this->isValidColumn($value)) {
                    continue;
                }
                $parts[] = $value;
            } else {
                // Column with explicit direction - validate if allowedColumns is available
                if (isset($this->allowedColumns) && !empty($this->allowedColumns) && !$this->isValidColumn($key)) {
                    continue;
                }
                $direction = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                $parts[] = "{$key} {$direction}";
            }
        }

        return implode(', ', $parts);
    }
}
