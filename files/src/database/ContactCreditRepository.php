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
     * Insert or update available credit only if the provided timestamp is newer
     *
     * Used when saving credit from transaction completion or pong responses.
     * The timestamp is converted from the app's integer microtime format
     * (seconds * 10000) to a MySQL TIMESTAMP(6) and stored in updated_at.
     *
     * On INSERT (no existing row): always inserts.
     * On UPDATE (row exists): only updates if the new timestamp > stored updated_at.
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param int $availableCredit Available credit amount (in cents)
     * @param string $currency Currency code
     * @param int $calculatedAt Timestamp from getCurrentMicrotime() (seconds * 10000)
     * @return bool True on success
     */
    public function upsertAvailableCreditIfNewer(string $pubkeyHash, int $availableCredit, string $currency, int $calculatedAt): bool {
        $timestamp = self::microtimeIntToTimestamp($calculatedAt);

        $query = "INSERT INTO {$this->tableName} (pubkey_hash, available_credit, currency, updated_at)
                  VALUES (:pubkey_hash, :available_credit, :currency, :updated_at)
                  ON DUPLICATE KEY UPDATE
                  available_credit = IF(:updated_at2 > updated_at, VALUES(available_credit), available_credit),
                  updated_at = IF(:updated_at3 > updated_at, VALUES(updated_at), updated_at)";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':available_credit' => $availableCredit,
            ':currency' => $currency,
            ':updated_at' => $timestamp,
            ':updated_at2' => $timestamp,
            ':updated_at3' => $timestamp,
        ]);

        return $stmt !== false;
    }

    /**
     * Convert app microtime integer to MySQL TIMESTAMP(6) string
     *
     * App uses (int)(microtime(true) * 10000), e.g. 17417499042270.
     * This converts back to '2025-03-12 14:05:04.227000'.
     *
     * @param int $microtimeInt Integer from getCurrentMicrotime()
     * @return string MySQL TIMESTAMP(6) string
     */
    private static function microtimeIntToTimestamp(int $microtimeInt): string {
        $seconds = intdiv($microtimeInt, 10000);
        $remainder = $microtimeInt % 10000;
        // Convert remainder from 1/10000s to microseconds (multiply by 100)
        $microseconds = $remainder * 100;
        return date('Y-m-d H:i:s', $seconds) . '.' . sprintf('%06d', $microseconds);
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
     * Get available credits for multiple contacts in a single query
     *
     * @param array $pubkeyHashes Array of pubkey hashes to look up
     * @return array Associative array keyed by pubkey_hash => ['available_credit' => int, 'currency' => string]
     */
    public function getAvailableCreditsForHashes(array $pubkeyHashes): array {
        if (empty($pubkeyHashes)) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($pubkeyHashes) as $i => $hash) {
            $key = ':hash_' . $i;
            $placeholders[] = $key;
            $params[$key] = $hash;
        }
        $query = "SELECT pubkey_hash, available_credit, currency FROM {$this->tableName} WHERE pubkey_hash IN (" . implode(',', $placeholders) . ")";
        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return [];
        }

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[$row['pubkey_hash']] = [
                'available_credit' => $row['available_credit'],
                'currency' => $row['currency'],
            ];
        }
        return $results;
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
