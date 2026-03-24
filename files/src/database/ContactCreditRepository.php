<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
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
        'id', 'pubkey_hash', 'available_credit_whole', 'available_credit_frac', 'currency', 'updated_at'
    ];

    /** @var string[] Split amount column prefixes for automatic row mapping */
    protected array $splitAmountColumns = ['available_credit'];

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
            $query = "SELECT available_credit_whole, available_credit_frac, currency FROM {$this->tableName} WHERE pubkey_hash = :pubkey_hash AND currency = :currency";
            $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash, ':currency' => $currency]);
        } else {
            $query = "SELECT available_credit_whole, available_credit_frac, currency FROM {$this->tableName} WHERE pubkey_hash = :pubkey_hash";
            $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash]);
        }

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }
        return [
            'available_credit' => new SplitAmount((int)$result['available_credit_whole'], (int)$result['available_credit_frac']),
            'currency' => $result['currency'],
        ];
    }

    /**
     * Get available credit for all currencies for a contact
     *
     * @param string $pubkeyHash Contact's public key hash
     * @return array Array of ['available_credit' => int, 'currency' => string] rows
     */
    public function getAvailableCreditAllCurrencies(string $pubkeyHash): array {
        $query = "SELECT available_credit_whole, available_credit_frac, currency FROM {$this->tableName} WHERE pubkey_hash = :pubkey_hash";
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash]);

        if (!$stmt) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        return array_map(function ($row) {
            return [
                'available_credit' => new SplitAmount((int)$row['available_credit_whole'], (int)$row['available_credit_frac']),
                'currency' => $row['currency'],
            ];
        }, $rows);
    }

    /**
     * Insert or update available credit for a contact
     *
     * Uses MySQL's ON DUPLICATE KEY UPDATE for atomic upsert.
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param SplitAmount $availableCredit Available credit amount
     * @param string $currency Currency code
     * @return bool True on success
     */
    public function upsertAvailableCredit(string $pubkeyHash, SplitAmount $availableCredit, string $currency): bool {
        $query = "INSERT INTO {$this->tableName} (pubkey_hash, available_credit_whole, available_credit_frac, currency, updated_at)
                  VALUES (:pubkey_hash, :available_credit_whole, :available_credit_frac, :currency, NOW(6))
                  ON DUPLICATE KEY UPDATE
                  available_credit_whole = VALUES(available_credit_whole),
                  available_credit_frac = VALUES(available_credit_frac),
                  currency = VALUES(currency),
                  updated_at = NOW(6)";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':available_credit_whole' => $availableCredit->whole,
            ':available_credit_frac' => $availableCredit->frac,
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
     * @param SplitAmount $availableCredit Available credit amount
     * @param string $currency Currency code
     * @param int $calculatedAt Timestamp from getCurrentMicrotime() (seconds * 10000)
     * @return bool True on success
     */
    public function upsertAvailableCreditIfNewer(string $pubkeyHash, SplitAmount $availableCredit, string $currency, int $calculatedAt): bool {
        $timestamp = self::microtimeIntToTimestamp($calculatedAt);

        $query = "INSERT INTO {$this->tableName} (pubkey_hash, available_credit_whole, available_credit_frac, currency, updated_at)
                  VALUES (:pubkey_hash, :available_credit_whole, :available_credit_frac, :currency, :updated_at)
                  ON DUPLICATE KEY UPDATE
                  available_credit_whole = IF(:updated_at2 > updated_at, VALUES(available_credit_whole), available_credit_whole),
                  available_credit_frac = IF(:updated_at3 > updated_at, VALUES(available_credit_frac), available_credit_frac),
                  updated_at = IF(:updated_at4 > updated_at, VALUES(updated_at), updated_at)";

        $stmt = $this->execute($query, [
            ':pubkey_hash' => $pubkeyHash,
            ':available_credit_whole' => $availableCredit->whole,
            ':available_credit_frac' => $availableCredit->frac,
            ':currency' => $currency,
            ':updated_at' => $timestamp,
            ':updated_at2' => $timestamp,
            ':updated_at3' => $timestamp,
            ':updated_at4' => $timestamp,
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
        return $this->upsertAvailableCredit($pubkeyHash, SplitAmount::zero(), $currency);
    }

    /**
     * Get total available credit grouped by currency
     *
     * @return array Array of ['currency' => string, 'total_available_credit' => int] rows
     */
    public function getTotalAvailableCreditByCurrency(): array {
        $query = "SELECT currency, SUM(available_credit_whole) AS sum_whole, SUM(available_credit_frac) AS sum_frac FROM {$this->tableName} GROUP BY currency";
        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        return array_map(function ($row) {
            return [
                'currency' => $row['currency'],
                'total_available_credit' => self::sumCarry((int)$row['sum_whole'], (int)$row['sum_frac']),
            ];
        }, $rows);
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
        $query = "SELECT pubkey_hash, available_credit_whole, available_credit_frac, currency FROM {$this->tableName} WHERE pubkey_hash IN (" . implode(',', $placeholders) . ")";
        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return [];
        }

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[$row['pubkey_hash']] = [
                'available_credit' => new SplitAmount((int)$row['available_credit_whole'], (int)$row['available_credit_frac']),
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

    /**
     * Handle carry from summed fractional parts
     *
     * @param int $sumWhole Summed whole parts
     * @param int $sumFrac Summed fractional parts (may exceed FRAC_MODULUS)
     * @return SplitAmount Properly normalized SplitAmount
     */
    private static function sumCarry(int $sumWhole, int $sumFrac): SplitAmount {
        $carry = intdiv($sumFrac, SplitAmount::FRAC_MODULUS);
        $frac = $sumFrac % SplitAmount::FRAC_MODULUS;
        return new SplitAmount($sumWhole + $carry, $frac);
    }
}
