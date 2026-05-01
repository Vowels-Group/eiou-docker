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
        // Joined to contact_currencies + filtered to status='accepted'
        // for the same reason as getTotalAvailableCreditByCurrency:
        // an unaccepted (or declined) currency that still has a stale
        // contact_credit row shouldn't surface as "you have credit"
        // anywhere — neither the dashboard total nor per-contact UI.
        if ($currency !== null) {
            $query = "SELECT cc.available_credit_whole, cc.available_credit_frac, cc.currency
                      FROM {$this->tableName} cc
                      INNER JOIN contact_currencies cur
                        ON cur.pubkey_hash = cc.pubkey_hash
                       AND cur.currency    = cc.currency
                       AND cur.status      = 'accepted'
                      WHERE cc.pubkey_hash = :pubkey_hash AND cc.currency = :currency";
            $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash, ':currency' => $currency]);
        } else {
            $query = "SELECT cc.available_credit_whole, cc.available_credit_frac, cc.currency
                      FROM {$this->tableName} cc
                      INNER JOIN contact_currencies cur
                        ON cur.pubkey_hash = cc.pubkey_hash
                       AND cur.currency    = cc.currency
                       AND cur.status      = 'accepted'
                      WHERE cc.pubkey_hash = :pubkey_hash";
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
        $query = "SELECT cc.available_credit_whole, cc.available_credit_frac, cc.currency
                  FROM {$this->tableName} cc
                  INNER JOIN contact_currencies cur
                    ON cur.pubkey_hash = cc.pubkey_hash
                   AND cur.currency    = cc.currency
                   AND cur.status      = 'accepted'
                  WHERE cc.pubkey_hash = :pubkey_hash";
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
     * Multi-currency batched upsert — equivalent to N
     * `upsertAvailableCredit` calls collapsed into one INSERT round-trip.
     *
     * Avoids the N+1 pattern in ContactSyncService when storing credit
     * for a contact who has many accepted currencies (5–10 common).
     *
     * @param string $pubkeyHash    Contact pubkey hash
     * @param array  $creditByCurrency  ['USD' => SplitAmount, 'EUR' => SplitAmount, …]
     * @return bool true on success (false if the prepared statement fails)
     */
    public function upsertAvailableCreditBatch(string $pubkeyHash, array $creditByCurrency): bool {
        if (empty($creditByCurrency)) {
            return true;
        }
        $rows = [];
        $params = [];
        foreach ($creditByCurrency as $currency => $availableCredit) {
            $rows[] = '(?, ?, ?, ?, NOW(6))';
            $params[] = $pubkeyHash;
            $params[] = $availableCredit->whole;
            $params[] = $availableCredit->frac;
            $params[] = $currency;
        }
        $query = "INSERT INTO {$this->tableName}
                  (pubkey_hash, available_credit_whole, available_credit_frac, currency, updated_at)
                  VALUES " . implode(', ', $rows) . "
                  ON DUPLICATE KEY UPDATE
                  available_credit_whole = VALUES(available_credit_whole),
                  available_credit_frac  = VALUES(available_credit_frac),
                  currency               = VALUES(currency),
                  updated_at             = NOW(6)";
        try {
            $stmt = $this->pdo->prepare($query);
            // Positional `?` placeholders + numeric-keyed params: pass
            // directly to execute() rather than through bindValue() (which
            // expects 1-indexed integer keys for positional, but our flat
            // array is 0-indexed). PDO::execute() handles the binding.
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            $this->logError("upsertAvailableCreditBatch failed", $e, $query);
            return false;
        }
    }

    /**
     * Multi-currency batched if-newer upsert — equivalent to N
     * `upsertAvailableCreditIfNewer` calls collapsed into one INSERT
     * round-trip.
     *
     * Per-row "newer" check uses MySQL's `VALUES(col)` to read the
     * about-to-be-inserted value inside the ON DUPLICATE KEY UPDATE
     * clause, so each currency's update is gated on its own
     * timestamp comparison (not the batch's first row).
     *
     * @param string $pubkeyHash       Contact pubkey hash
     * @param array  $creditByCurrency ['USD' => SplitAmount, 'EUR' => SplitAmount, …]
     * @param int    $calculatedAt     Single timestamp (sender's
     *                                 microtime; same for every currency
     *                                 in this response)
     * @return bool true on success
     */
    public function upsertAvailableCreditIfNewerBatch(
        string $pubkeyHash,
        array $creditByCurrency,
        int $calculatedAt
    ): bool {
        if (empty($creditByCurrency)) {
            return true;
        }
        $timestamp = self::microtimeIntToTimestamp($calculatedAt);
        $rows = [];
        $params = [];
        foreach ($creditByCurrency as $currency => $availableCredit) {
            $rows[] = '(?, ?, ?, ?, ?)';
            $params[] = $pubkeyHash;
            $params[] = $availableCredit->whole;
            $params[] = $availableCredit->frac;
            $params[] = $currency;
            $params[] = $timestamp;
        }
        $query = "INSERT INTO {$this->tableName}
                  (pubkey_hash, available_credit_whole, available_credit_frac, currency, updated_at)
                  VALUES " . implode(', ', $rows) . "
                  ON DUPLICATE KEY UPDATE
                  available_credit_whole = IF(VALUES(updated_at) > updated_at, VALUES(available_credit_whole), available_credit_whole),
                  available_credit_frac  = IF(VALUES(updated_at) > updated_at, VALUES(available_credit_frac), available_credit_frac),
                  updated_at             = IF(VALUES(updated_at) > updated_at, VALUES(updated_at), updated_at)";
        try {
            $stmt = $this->pdo->prepare($query);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            $this->logError("upsertAvailableCreditIfNewerBatch failed", $e, $query);
            return false;
        }
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
        // INNER JOIN with contact_currencies on (pubkey_hash, currency)
        // and filter to status='accepted' so currencies the peer hasn't
        // accepted yet — or has declined — don't count toward the
        // dashboard's "Total Available Credit." The contact_credit row
        // gets pre-emptively created when WE propose an outgoing
        // currency (ContactManagementService::addCurrencyToContact),
        // and it stays around until decline-cleanup or ping-reconcile
        // catches it; this filter makes the aggregation correct
        // regardless of those cleanup paths' state.
        $query = "SELECT cc.currency,
                         SUM(cc.available_credit_whole) AS sum_whole,
                         SUM(cc.available_credit_frac)  AS sum_frac
                  FROM {$this->tableName} cc
                  INNER JOIN contact_currencies cur
                    ON cur.pubkey_hash = cc.pubkey_hash
                   AND cur.currency    = cc.currency
                   AND cur.status      = 'accepted'
                  GROUP BY cc.currency";
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
        $query = "SELECT cc.pubkey_hash, cc.available_credit_whole, cc.available_credit_frac, cc.currency
                  FROM {$this->tableName} cc
                  INNER JOIN contact_currencies cur
                    ON cur.pubkey_hash = cc.pubkey_hash
                   AND cur.currency    = cc.currency
                   AND cur.status      = 'accepted'
                  WHERE cc.pubkey_hash IN (" . implode(',', $placeholders) . ")";
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
     * Delete the credit row for one specific (contact, currency) pair.
     * Called from the decline-cleanup paths so orphan rows don't
     * accumulate even though the aggregation queries already filter
     * them out — keeps the table tight and avoids confusing snapshots.
     *
     * @param string $pubkeyHash Contact's public key hash
     * @param string $currency   Currency code
     * @return bool True if a row was deleted
     */
    public function deleteForContactCurrency(string $pubkeyHash, string $currency): bool {
        $stmt = $this->execute(
            "DELETE FROM {$this->tableName}
             WHERE pubkey_hash = :pubkey_hash AND currency = :currency",
            [':pubkey_hash' => $pubkeyHash, ':currency' => $currency]
        );
        if (!$stmt) {
            return false;
        }
        return $stmt->rowCount() > 0;
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
