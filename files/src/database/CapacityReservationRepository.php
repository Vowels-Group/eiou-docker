<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use PDO;
use PDOException;

class CapacityReservationRepository extends AbstractRepository {
    use QueryBuilder;

    protected array $allowedColumns = [
        'id', 'hash', 'contact_pubkey_hash', 'base_amount', 'total_amount',
        'currency', 'status', 'created_at', 'released_at', 'release_reason'
    ];

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
        int $baseAmount,
        int $totalAmount,
        string $currency
    ): bool {
        $query = "INSERT INTO {$this->tableName}
                  (hash, contact_pubkey_hash, base_amount, total_amount, currency, status)
                  VALUES (:hash, :pubkey_hash, :base_amount, :total_amount, :currency, 'active')
                  ON DUPLICATE KEY UPDATE
                  base_amount = VALUES(base_amount),
                  total_amount = VALUES(total_amount),
                  status = 'active',
                  released_at = NULL,
                  release_reason = NULL";

        $stmt = $this->execute($query, [
            ':hash' => $hash,
            ':pubkey_hash' => $contactPubkeyHash,
            ':base_amount' => $baseAmount,
            ':total_amount' => $totalAmount,
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
    public function getTotalReservedForPubkey(string $contactPubkeyHash, ?string $currency = null): int {
        $query = "SELECT COALESCE(SUM(total_amount), 0) as total
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
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['total'] ?? 0);
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

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}
