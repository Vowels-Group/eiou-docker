<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;

/**
 * Payback Method Repository
 *
 * Handles database operations for the payback_methods table — the user's
 * profile of settlement methods (bank_wire, btc, evm, lightning, pix, etc.).
 *
 * Encryption/decryption of the `encrypted_fields` JSON blob is the caller's
 * responsibility (done in PaybackMethodService via KeyEncryption) so this
 * repository can stay a thin PDO layer.
 */
class PaybackMethodRepository extends AbstractRepository
{
    protected $tableName = 'payback_methods';
    protected array $allowedColumns = [
        'id', 'method_id', 'type', 'label', 'currency',
        'encrypted_fields', 'fields_version',
        'settlement_min_unit', 'settlement_min_unit_exponent',
        'priority', 'enabled', 'share_policy',
        'created_at', 'updated_at',
    ];

    /**
     * Insert a new payback method row.
     *
     * @param array $data Columns → values. `encrypted_fields` must already be a JSON string.
     * @return string|false Last insert id on success, false on failure.
     */
    public function createMethod(array $data)
    {
        return $this->insert($data);
    }

    /**
     * Fetch a single method by its stable external id (uuid).
     */
    public function getByMethodId(string $methodId): ?array
    {
        return $this->findByColumn('method_id', $methodId);
    }

    /**
     * List methods, newest-created first, ordered secondarily by priority ascending.
     *
     * @param string|null $currency Filter by currency (null = all).
     * @param bool $enabledOnly If true, return only enabled=1 rows.
     * @return array<int, array<string, mixed>>
     */
    public function listMethods(?string $currency = null, bool $enabledOnly = true): array
    {
        $where = [];
        $params = [];

        if ($currency !== null) {
            $where[] = 'currency = :currency';
            $params[':currency'] = $currency;
        }
        if ($enabledOnly) {
            $where[] = 'enabled = 1';
        }

        $sql = "SELECT * FROM {$this->tableName}";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY priority ASC, created_at DESC';

        $stmt = $this->execute($sql, $params);
        if (!$stmt) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List methods matching a currency with share_policy != 'never' and enabled.
     * Used by the responder when answering a contact's payback-methods-request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listShareableForCurrency(string $currency): array
    {
        $sql = "SELECT * FROM {$this->tableName}
                WHERE enabled = 1
                  AND share_policy != 'never'
                  AND currency = :currency
                ORDER BY priority ASC, created_at DESC";
        $stmt = $this->execute($sql, [':currency' => $currency]);
        if (!$stmt) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List every enabled, non-`never` method — used when the request omits a currency filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllShareable(): array
    {
        $sql = "SELECT * FROM {$this->tableName}
                WHERE enabled = 1
                  AND share_policy != 'never'
                ORDER BY priority ASC, created_at DESC";
        $stmt = $this->execute($sql, []);
        if (!$stmt) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update an existing row identified by method_id.
     *
     * @param string $methodId
     * @param array $changes Columns → new values. `encrypted_fields` must be JSON string if present.
     * @return int Affected rows, -1 on error.
     */
    public function updateByMethodId(string $methodId, array $changes): int
    {
        if ($changes === []) {
            return 0;
        }
        return $this->update($changes, 'method_id', $methodId);
    }

    /**
     * Hard-delete by method_id. Caller is responsible for auth gating before calling.
     *
     * @return int Deleted rows, -1 on error.
     */
    public function deleteByMethodId(string $methodId): int
    {
        return $this->delete('method_id', $methodId);
    }

    /**
     * Count enabled methods, optionally for a specific currency.
     */
    public function countEnabled(?string $currency = null): int
    {
        if ($currency === null) {
            $stmt = $this->execute("SELECT COUNT(*) AS c FROM {$this->tableName} WHERE enabled = 1", []);
        } else {
            $stmt = $this->execute(
                "SELECT COUNT(*) AS c FROM {$this->tableName} WHERE enabled = 1 AND currency = :currency",
                [':currency' => $currency]
            );
        }
        if (!$stmt) {
            return 0;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['c'] ?? 0);
    }
}
