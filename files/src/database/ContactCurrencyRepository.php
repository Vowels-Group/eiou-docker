<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use PDO;

/**
 * Contact Currency Repository
 *
 * Manages the contact_currencies table which stores per-currency configuration
 * (fee_percent, credit_limit) for each contact. A single contact can have
 * multiple currency relationships.
 */
class ContactCurrencyRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'pubkey_hash', 'currency', 'fee_percent', 'credit_limit', 'min_fee_amount', 'status', 'direction', 'created_at', 'updated_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'contact_currencies';
        $this->primaryKey = 'pubkey_hash';
    }

    /**
     * Insert a new currency configuration for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @param int $feePercent Fee percentage (in minor units)
     * @param int $creditLimit Credit limit (in minor units)
     * @param string $status Currency status ('accepted' or 'pending')
     * @param string $direction Direction ('incoming' = they requested, 'outgoing' = we requested)
     * @return bool True on success
     */
    public function insertCurrencyConfig(string $pubkeyHash, string $currency, int $feePercent, ?int $creditLimit = null, string $status = 'accepted', string $direction = 'incoming', ?int $minFeeAmount = null): bool {
        $query = "INSERT IGNORE INTO {$this->tableName} (pubkey_hash, currency, fee_percent, credit_limit, min_fee_amount, status, direction)
                  VALUES (:pubkey_hash, :currency, :fee_percent, :credit_limit, :min_fee_amount, :status, :direction)";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency,
            ':fee_percent' => $feePercent,
            ':credit_limit' => $creditLimit,
            ':min_fee_amount' => $minFeeAmount,
            ':status' => $status,
            ':direction' => $direction
        ]);

        return $stmt !== false;
    }

    /**
     * Get currency configuration for a specific contact and currency
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @param string|null $direction Optional direction filter ('incoming' or 'outgoing')
     * @return array|null Currency config with fee_percent, credit_limit, currency, direction, or null if not found
     */
    public function getCurrencyConfig(string $pubkeyHash, string $currency, ?string $direction = null): ?array {
        $params = [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency
        ];

        $query = "SELECT currency, fee_percent, credit_limit, min_fee_amount, status, direction
                  FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        if ($direction !== null) {
            $query .= " AND direction = :direction";
            $params[':direction'] = $direction;
        }

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get all currency configurations for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string|null $direction Optional direction filter ('incoming' or 'outgoing')
     * @return array Array of currency config rows
     */
    public function getContactCurrencies(string $pubkeyHash, ?string $direction = null): array {
        $params = [':pubkey_hash' => $pubkeyHash];

        $query = "SELECT currency, fee_percent, credit_limit, min_fee_amount, status, direction
                  FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash";

        if ($direction !== null) {
            $query .= " AND direction = :direction";
            $params[':direction'] = $direction;
        }

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Check if a contact has a specific currency relationship
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @param string|null $direction Optional direction filter ('incoming' or 'outgoing')
     * @return bool True if the currency relationship exists
     */
    public function hasCurrency(string $pubkeyHash, string $currency, ?string $direction = null): bool {
        $params = [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency
        ];

        $query = "SELECT 1 FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        if ($direction !== null) {
            $query .= " AND direction = :direction";
            $params[':direction'] = $direction;
        }

        $query .= " LIMIT 1";

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return false;
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Get credit limit for a specific contact and currency
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @return int Credit limit (0 if not found)
     */
    public function getCreditLimit(string $pubkeyHash, string $currency): ?int {
        $query = "SELECT credit_limit FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency
        ]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['credit_limit'] !== null) {
            return (int) $result['credit_limit'];
        }
        return null;
    }

    /**
     * Get minimum fee amount for a specific contact and currency
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @return int|null Minimum fee amount in minor units, or null if not set
     */
    public function getMinFeeAmount(string $pubkeyHash, string $currency): ?int {
        $query = "SELECT min_fee_amount FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency
        ]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['min_fee_amount'] !== null) {
            return (int) $result['min_fee_amount'];
        }
        return null;
    }

    /**
     * Get fee percent for a specific contact and currency
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @return int Fee percent (0 if not found)
     */
    public function getFeePercent(string $pubkeyHash, string $currency): int {
        $query = "SELECT fee_percent FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency
        ]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['fee_percent'] ?? 0);
    }

    /**
     * Update currency configuration for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @param array $fields Associative array of fields to update (fee_percent, credit_limit)
     * @return bool True on success
     */
    /**
     * Update currency configuration for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @param array $fields Associative array of fields to update (fee_percent, credit_limit, status)
     * @param string|null $direction Optional direction filter for the WHERE clause
     * @return bool True on success
     */
    public function updateCurrencyConfig(string $pubkeyHash, string $currency, array $fields, ?string $direction = null): bool {
        $setClauses = [];
        $params = [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency
        ];

        foreach ($fields as $field => $value) {
            if (in_array($field, ['fee_percent', 'credit_limit', 'min_fee_amount', 'status'], true)) {
                $setClauses[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $setClause = implode(', ', $setClauses);
        $query = "UPDATE {$this->tableName} SET {$setClause}
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        if ($direction !== null) {
            $query .= " AND direction = :direction";
            $params[':direction'] = $direction;
        }

        $stmt = $this->execute($query, $params);
        return $stmt !== false;
    }

    /**
     * Insert or update currency configuration for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @param int $feePercent Fee percentage
     * @param int $creditLimit Credit limit
     * @param string $direction Direction ('incoming' or 'outgoing')
     * @return bool True on success
     */
    public function upsertCurrencyConfig(string $pubkeyHash, string $currency, int $feePercent, ?int $creditLimit = null, string $direction = 'incoming', ?int $minFeeAmount = null): bool {
        $query = "INSERT INTO {$this->tableName} (pubkey_hash, currency, fee_percent, credit_limit, min_fee_amount, direction)
                  VALUES (:pubkey_hash, :currency, :fee_percent, :credit_limit, :min_fee_amount, :direction)
                  ON DUPLICATE KEY UPDATE
                  fee_percent = VALUES(fee_percent),
                  credit_limit = VALUES(credit_limit)" .
                  ($minFeeAmount !== null ? ", min_fee_amount = VALUES(min_fee_amount)" : "");

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency,
            ':fee_percent' => $feePercent,
            ':credit_limit' => $creditLimit,
            ':min_fee_amount' => $minFeeAmount,
            ':direction' => $direction
        ]);

        return $stmt !== false;
    }

    /**
     * Update the status of a specific currency configuration
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @param string $status New status ('accepted' or 'pending')
     * @param string|null $direction Optional direction filter ('incoming' or 'outgoing')
     * @return bool True on success
     */
    public function updateCurrencyStatus(string $pubkeyHash, string $currency, string $status, ?string $direction = null): bool {
        $params = [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency,
            ':status' => $status,
        ];

        $query = "UPDATE {$this->tableName} SET status = :status
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        if ($direction !== null) {
            $query .= " AND direction = :direction";
            $params[':direction'] = $direction;
        }

        $stmt = $this->execute($query, $params);

        return $stmt !== false && $stmt->rowCount() > 0;
    }

    /**
     * Accept a pending currency configuration
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @return bool True on success
     */
    public function acceptCurrency(string $pubkeyHash, string $currency): bool {
        return $this->updateCurrencyStatus($pubkeyHash, $currency, 'accepted');
    }

    /**
     * Get pending currency configurations for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string|null $direction Optional direction filter ('incoming' or 'outgoing')
     * @return array Array of pending currency config rows
     */
    public function getPendingCurrencies(string $pubkeyHash, ?string $direction = null): array {
        $params = [':pubkey_hash' => $pubkeyHash];

        $query = "SELECT currency, fee_percent, credit_limit, min_fee_amount, status, direction
                  FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash AND status = 'pending'";

        if ($direction !== null) {
            $query .= " AND direction = :direction";
            $params[':direction'] = $direction;
        }

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete all currency configurations for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @return bool True if any rows were deleted
     */
    public function deleteAllForContact(string $pubkeyHash): bool {
        $deletedRows = $this->delete($this->primaryKey, $pubkeyHash);
        return $deletedRows > 0;
    }

    /**
     * Get all distinct accepted currencies across all contacts
     *
     * @return array Array of currency code strings
     */
    public function getDistinctAcceptedCurrencies(): array {
        $query = "SELECT DISTINCT currency FROM {$this->tableName}
                  WHERE status = 'accepted'";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Delete a specific currency configuration for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency Currency code
     * @return bool True if a row was deleted
     */
    public function deleteCurrencyConfig(string $pubkeyHash, string $currency): bool {
        $query = "DELETE FROM {$this->tableName}
                  WHERE pubkey_hash = :pubkey_hash AND currency = :currency";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':currency' => $currency
        ]);

        if (!$stmt) {
            return false;
        }

        return $stmt->rowCount() > 0;
    }
}
