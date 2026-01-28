<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Query Helper Trait
 *
 * Provides common query building utilities for repository classes.
 * Reduces duplication of placeholder generation and parameter building.
 *
 * @package Database\Traits
 */
trait QueryHelper
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
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        if (!$stmt) {
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
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        if (!$stmt) {
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
}
