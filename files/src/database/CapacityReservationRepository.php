<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\SplitAmount;
use PDO;
use PDOException;

class CapacityReservationRepository extends AbstractRepository {
    use QueryBuilder;

    protected array $allowedColumns = [
        'id', 'hash', 'contact_pubkey_hash', 'base_amount_whole', 'base_amount_frac',
        'total_amount_whole', 'total_amount_frac',
        'currency', 'status', 'created_at', 'released_at', 'release_reason'
    ];

    /** @var string[] Split amount column prefixes for automatic row mapping */
    protected array $splitAmountColumns = ['base_amount', 'total_amount'];

    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'capacity_reservations';
        $this->primaryKey = 'id';
    }

    /**
     * Create a capacity reservation for a P2P relay
     */
    public function createReservation(
        string $hash,
        string $contactPubkeyHash,
        SplitAmount $baseAmount,
        SplitAmount $totalAmount,
        string $currency
    ): bool {
        $query = "INSERT INTO {$this->tableName}
                  (hash, contact_pubkey_hash, base_amount_whole, base_amount_frac, total_amount_whole, total_amount_frac, currency, status)
                  VALUES (:hash, :pubkey_hash, :base_amount_whole, :base_amount_frac, :total_amount_whole, :total_amount_frac, :currency, 'active')
                  ON DUPLICATE KEY UPDATE
                  base_amount_whole = VALUES(base_amount_whole),
                  base_amount_frac = VALUES(base_amount_frac),
                  total_amount_whole = VALUES(total_amount_whole),
                  total_amount_frac = VALUES(total_amount_frac),
                  status = 'active',
                  released_at = NULL,
                  release_reason = NULL";

        $stmt = $this->execute($query, [
            ':hash' => $hash,
            ':pubkey_hash' => $contactPubkeyHash,
            ':base_amount_whole' => $baseAmount->whole,
            ':base_amount_frac' => $baseAmount->frac,
            ':total_amount_whole' => $totalAmount->whole,
            ':total_amount_frac' => $totalAmount->frac,
            ':currency' => $currency,
        ]);

        return $stmt !== false;
    }

    /**
     * Get total credit reserved by a sender pubkey (replaces getCreditInP2p)
     *
     * Returns the sum of total_amount for all active reservations
     * where the sender's pubkey hash matches.
     */
    public function getTotalReservedForPubkey(string $contactPubkeyHash, ?string $currency = null): SplitAmount {
        $query = "SELECT COALESCE(SUM(total_amount_whole), 0) AS sum_whole, COALESCE(SUM(total_amount_frac), 0) AS sum_frac
                  FROM {$this->tableName}
                  WHERE contact_pubkey_hash = :pubkey_hash
                    AND status = 'active'";
        $params = [':pubkey_hash' => $contactPubkeyHash];

        if ($currency !== null) {
            $query .= " AND currency = :currency";
            $params[':currency'] = $currency;
        }

        $stmt = $this->execute($query, $params);
        if (!$stmt) {
            return SplitAmount::zero();
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return self::sumCarry((int)$result['sum_whole'], (int)$result['sum_frac']);
    }

    /**
     * Release all active reservations for a hash
     */
    public function releaseByHash(string $hash, string $reason = 'cancelled'): int {
        $query = "UPDATE {$this->tableName}
                  SET status = 'released',
                      released_at = CURRENT_TIMESTAMP(6),
                      release_reason = :reason
                  WHERE hash = :hash AND status = 'active'";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([':hash' => $hash, ':reason' => $reason]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to release reservations by hash", $e);
            return 0;
        }
    }

    /**
     * Release a specific reservation for a hash + contact
     */
    public function releaseByHashAndContact(string $hash, string $contactPubkeyHash, string $reason = 'cancelled'): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = 'released',
                      released_at = CURRENT_TIMESTAMP(6),
                      release_reason = :reason
                  WHERE hash = :hash
                    AND contact_pubkey_hash = :pubkey_hash
                    AND status = 'active'";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':hash' => $hash,
                ':pubkey_hash' => $contactPubkeyHash,
                ':reason' => $reason,
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Failed to release reservation by hash and contact", $e);
            return false;
        }
    }

    /**
     * Commit all active reservations for a hash (transaction completed)
     */
    public function commitByHash(string $hash): int {
        $query = "UPDATE {$this->tableName}
                  SET status = 'committed',
                      released_at = CURRENT_TIMESTAMP(6),
                      release_reason = 'committed'
                  WHERE hash = :hash AND status = 'active'";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([':hash' => $hash]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to commit reservations by hash", $e);
            return 0;
        }
    }

    /**
     * Get active reservations for a hash
     */
    public function getActiveByHash(string $hash): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE hash = :hash AND status = 'active'";

        $stmt = $this->execute($query, [':hash' => $hash]);
        if (!$stmt) {
            return [];
        }

        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Delete old released/committed records
     */
    public function deleteOldRecords(int $days = 7): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE status IN ('released', 'committed')
                    AND released_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old capacity reservations", $e);
            return 0;
        }
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
