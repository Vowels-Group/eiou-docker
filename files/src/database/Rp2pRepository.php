<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Database\Traits\QueryBuilder;
use PDO;
use PDOException;

/**
 * RP2P Repository
 *
 * Manages all database interactions for the rp2p (reverse P2P) table.
 *
 * @package Database\Repository
 */
class Rp2pRepository extends AbstractRepository {
    use QueryBuilder;
    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'hash', 'time', 'amount_whole', 'amount_frac', 'currency', 'sender_public_key',
        'sender_address', 'sender_signature', 'created_at'
    ];

    /** @var string[] Split amount column prefixes for automatic row mapping */
    protected array $splitAmountColumns = ['amount'];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'rp2p';
        $this->primaryKey = 'id';
    }

    /**
     * Check if RP2P exists by hash
     *
     * @param string $hash RP2P hash
     * @return bool True if exists
     */
    public function rp2pExists(string $hash): bool {
        return $this->exists('hash', $hash);
    }

    /**
     * Get RP2P by hash
     *
     * @param string $hash RP2P hash
     * @return array|null RP2P data or null
     */
    public function getByHash(string $hash): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE hash = :hash";
        $stmt = $this->execute($query, [':hash' => $hash]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->mapRow($result ?: null);
    }

    /**
     * Insert a new RP2P request
     *
     * @param array $request RP2P request data
     * @return string JSON response
     */
    public function insertRp2pRequest(array $request): string {
        $amount = SplitAmount::from($request['amount']);
        $data = [
            'hash' => $request['hash'],
            'time' => $request['time'],
            'amount_whole' => $amount->whole,
            'amount_frac' => $amount->frac,
            'currency' => $request['currency'],
            'sender_public_key' => $request['senderPublicKey'],
            'sender_address' => $request['senderAddress'],
            'sender_signature' => $request['signature']
        ];

        $result = $this->insert($data);

        if ($result !== false) {
            // Output silent logging if function exists
            if (function_exists('output') && function_exists('outputInsertedRp2p')) {
                output(outputInsertedRp2p($request), 'SILENT');
            }

            return json_encode([
                "status" => "received",
                "message" => "rp2p recorded successfully"
            ]);
        } else {
            $errorMessage = "Failed to record rp2p";
            if (function_exists('output')) {
                output("Error inserting rp2p request: " . $errorMessage);
            }

            return json_encode([
                "status" => "rejected",
                "message" => $errorMessage
            ]);
        }
    }

    /**
     * Retrieve credit currently in RP2P for a pubkey
     *
     * @param string $pubkey Sender pubkey
     * @return SplitAmount Total amount on hold
     */
    public function getCreditInRp2p(string $pubkey): SplitAmount {
        $query = "SELECT COALESCE(SUM(amount_whole), 0) AS sum_whole, COALESCE(SUM(amount_frac), 0) AS sum_frac FROM {$this->tableName}
                  WHERE sender_public_key = :pubkey
                    AND status NOT IN ('cancelled', 'completed')";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return SplitAmount::zero();
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return self::sumCarry((int)$result['sum_whole'], (int)$result['sum_frac']);
    }

    /**
     * Get RP2P messages by sender address
     *
     * @param string $address Sender address
     * @param int $limit Maximum number of messages
     * @return array Array of RP2P messages
     */
    public function getBySenderAddress(string $address, int $limit = 0): array {
        return $this->findManyByColumn('sender_address', $address, $limit);
    }

    /**
     * Get recent RP2P requests
     *
     * @param int $limit Maximum number of requests
     * @return array Array of RP2P requests
     */
    public function getRecentRequests(int $limit = 10): array {
        $query = "SELECT * FROM {$this->tableName}
                  ORDER BY created_at DESC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve recent RP2P requests", $e);
            return [];
        }
    }

    /**
     * Get RP2P requests by time range
     *
     * @param int $startTime Start timestamp
     * @param int $endTime End timestamp
     * @return array Array of RP2P requests
     */
    public function getByTimeRange(int $startTime, int $endTime): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE time >= :start_time AND time <= :end_time
                  ORDER BY time DESC";

        $stmt = $this->execute($query, [
            ':start_time' => $startTime,
            ':end_time' => $endTime
        ]);

        if (!$stmt) {
            return [];
        }

        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get total amount requested by address
     *
     * @param string $address Sender address
     * @return SplitAmount Total amount requested
     */
    public function getTotalAmountByAddress(string $address): SplitAmount {
        $query = "SELECT COALESCE(SUM(amount_whole), 0) AS sum_whole, COALESCE(SUM(amount_frac), 0) AS sum_frac FROM {$this->tableName}
                  WHERE sender_address = :address";
        $stmt = $this->execute($query, [':address' => $address]);

        if (!$stmt) {
            return SplitAmount::zero();
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return self::sumCarry((int)$result['sum_whole'], (int)$result['sum_frac']);
    }

    /**
     * Get RP2P statistics
     *
     * @return array Statistics array with counts and totals
     */
    public function getStatistics(): array {
        $query = "SELECT
                    COUNT(*) as total_count,
                    COALESCE(SUM(amount_whole), 0) AS total_amount_whole, COALESCE(SUM(amount_frac), 0) AS total_amount_frac,
                    COUNT(DISTINCT sender_address) as unique_senders,
                    MIN(time) as earliest_request,
                    MAX(time) as latest_request
                  FROM {$this->tableName}";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return [];
        }
        return [
            'total_count' => $result['total_count'],
            'total_amount' => self::sumCarry((int)$result['total_amount_whole'], (int)$result['total_amount_frac']),
            'unique_senders' => $result['unique_senders'],
            'earliest_request' => $result['earliest_request'],
            'latest_request' => $result['latest_request'],
        ];
    }

    /**
     * Delete old RP2P records older than specified days
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = Constants::CLEANUP_RP2P_RETENTION_DAYS): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old RP2P records", $e);
            return 0;
        }
    }

    /**
     * Get RP2P requests by currency
     *
     * @param string $currency Currency code
     * @param int $limit Maximum number of requests
     * @return array Array of RP2P requests
     */
    public function getByCurrency(string $currency, int $limit = 0): array {
        return $this->findManyByColumn('currency', $currency, $limit);
    }

    /**
     * Count RP2P requests for an address
     *
     * @param string $address Sender address
     * @return int Number of requests
     */
    public function countByAddress(string $address): int {
        return $this->count('sender_address', $address);
    }

    /**
     * Get RP2P requests with amount greater than specified value
     *
     * @param SplitAmount $minAmount Minimum amount
     * @param int $limit Maximum number of requests
     * @return array Array of RP2P requests
     */
    public function getByMinAmount(SplitAmount $minAmount, int $limit = 0): array {
        // Compare using (whole, frac) tuple ordering
        $query = "SELECT * FROM {$this->tableName}
                  WHERE (amount_whole > :min_whole OR (amount_whole = :min_whole2 AND amount_frac >= :min_frac))
                  ORDER BY amount_whole DESC, amount_frac DESC";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':min_whole', $minAmount->whole, PDO::PARAM_INT);
        $stmt->bindValue(':min_whole2', $minAmount->whole, PDO::PARAM_INT);
        $stmt->bindValue(':min_frac', $minAmount->frac, PDO::PARAM_INT);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve RP2P by min amount", $e);
            return [];
        }
    }

    /**
     * Verify RP2P signature (placeholder for future implementation)
     *
     * @param string $hash RP2P hash
     * @return bool True if signature is valid
     */
    public function verifySignature(string $hash): bool {
        $rp2p = $this->getByHash($hash);

        if (!$rp2p) {
            return false;
        }

        // Signature verification logic would go here
        // This is a placeholder that returns true if data exists
        return !empty($rp2p['sender_signature']);
    }

    /**
     * Get duplicate RP2P requests (same hash)
     *
     * @return array Array of duplicate hashes with counts
     */
    public function getDuplicates(): array {
        $query = "SELECT hash, COUNT(*) as count
                  FROM {$this->tableName}
                  GROUP BY hash
                  HAVING count > 1
                  ORDER BY count DESC";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
