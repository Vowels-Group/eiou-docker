<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Utils\Logger;
use PDO;

/**
 * Contact Credit Repository
 *
 * Manages the contact_credit table which stores available credit information
 * for contacts. Updated during ping/pong operations and initialized when
 * contacts are created or accepted.
 */
class ContactCreditRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'pubkey_hash', 'available_credit', 'currency', 'updated_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'contact_credit';
        $this->primaryKey = 'pubkey_hash';
    }

    /**
     * Get available credit for a contact by pubkey hash and optional currency
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string|null $currency Optional currency code to filter by
     * @return array|null Credit data with available_credit and currency, or null if not found
     */
    public function getAvailableCredit(string $pubkeyHash, ?string $currency = null): ?array {
        if ($currency !== null) {
            $query = "SELECT available_credit, currency FROM {$this->tableName} WHERE pubkey_hash = :pubkey_hash AND currency = :currency";
            $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash, ':currency' => $currency]);
        } else {
            $query = "SELECT available_credit, currency FROM {$this->tableName} WHERE pubkey_hash = :pubkey_hash";
            $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash]);
        }

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get available credit for all currencies for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @return array Array of ['available_credit' => int, 'currency' => string] rows
     */
    public function getAvailableCreditAllCurrencies(string $pubkeyHash): array {
        $query = "SELECT available_credit, currency FROM {$this->tableName} WHERE pubkey_hash = :pubkey_hash";
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash]);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Insert or update available credit for a contact
     *
     * Uses MySQL's ON DUPLICATE KEY UPDATE for atomic upsert.
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param int $availableCredit Available credit amount (in cents)
     * @param string $currency Currency code
     * @return bool True on success
     */
    public function upsertAvailableCredit(string $pubkeyHash, int $availableCredit, string $currency): bool {
        $query = "INSERT INTO {$this->tableName} (pubkey_hash, available_credit, currency, updated_at)
                  VALUES (:pubkey_hash, :available_credit, :currency, NOW(6))
                  ON DUPLICATE KEY UPDATE
                  available_credit = VALUES(available_credit),
                  currency = VALUES(currency),
                  updated_at = NOW(6)";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':available_credit' => $availableCredit,
            ':currency' => $currency
        ]);

        return $stmt !== false;
    }

    /**
     * Create initial credit entry for a new contact
     *
     * Sets available_credit to 0 until updated by a ping/pong exchange.
     * Idempotent — uses upsert so existing entries are not overwritten.
     *
     * @param string $contactPublicKey Contact's full public key
     * @param string $currency Currency code
     * @return bool True on success
     */
    public function createInitialCredit(string $contactPublicKey, string $currency): bool {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);
        return $this->upsertAvailableCredit($pubkeyHash, 0, $currency);
    }

    /**
     * Get total available credit grouped by currency
     *
     * @return array Array of ['currency' => string, 'total_available_credit' => int] rows
     */
    public function getTotalAvailableCreditByCurrency(): array {
        $query = "SELECT currency, SUM(available_credit) as total_available_credit FROM {$this->tableName} GROUP BY currency";
        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete credit entry by pubkey hash
     *
     * @param string $pubkeyHash Contact's public key hash
     * @return bool True if a row was deleted
     */
    public function deleteByPubkeyHash(string $pubkeyHash): bool {
        $deletedRows = $this->delete($this->primaryKey, $pubkeyHash);
        return $deletedRows > 0;
    }
}
