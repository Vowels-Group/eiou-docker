<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use PDO;
use PDOException;

class RouteCancellationRepository extends AbstractRepository {
    use QueryBuilder;

    protected array $allowedColumns = [
        'id', 'hash', 'candidate_id', 'contact_address',
        'status', 'created_at', 'acknowledged_at'
    ];

    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'route_cancellations';
        $this->primaryKey = 'id';
    }

    /**
     * Record a cancellation sent to an unselected route's contact
     */
    public function insertCancellation(string $hash, ?int $candidateId, string $contactAddress): bool {
        $query = "INSERT INTO {$this->tableName}
                  (hash, candidate_id, contact_address, status)
                  VALUES (:hash, :candidate_id, :contact_address, 'sent')";

        $stmt = $this->execute($query, [
            ':hash' => $hash,
            ':candidate_id' => $candidateId,
            ':contact_address' => $contactAddress,
        ]);

        return $stmt !== false;
    }

    /**
     * Mark a cancellation as acknowledged by the receiving node
     */
    public function acknowledge(string $hash, string $contactAddress): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = 'acknowledged',
                      acknowledged_at = CURRENT_TIMESTAMP(6)
                  WHERE hash = :hash
                    AND contact_address = :contact_address
                    AND status = 'sent'";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([
                ':hash' => $hash,
                ':contact_address' => $contactAddress,
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Failed to acknowledge cancellation", $e);
            return false;
        }
    }

    /**
     * Get all cancellations for a hash (audit trail)
     */
    public function getCancellationsByHash(string $hash): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE hash = :hash
                  ORDER BY created_at ASC";

        $stmt = $this->execute($query, [':hash' => $hash]);
        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete old records
     */
    public function deleteOldRecords(int $days = 7): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old route cancellation records", $e);
            return 0;
        }
    }
}
