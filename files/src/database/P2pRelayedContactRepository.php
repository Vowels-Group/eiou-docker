<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Core\Constants;
use Eiou\Database\Traits\QueryBuilder;
use PDO;
use PDOException;

/**
 * P2P Relayed Contact Repository
 *
 * Manages database interactions for the p2p_relayed_contacts table.
 * Tracks contacts that returned 'already_relayed' during P2P broadcast
 * so that relay nodes can send RP2P results to them in phase 1 of
 * two-phase best-fee selection.
 *
 * @package Database\Repository
 */
class P2pRelayedContactRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'hash', 'contact_address', 'created_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'p2p_relayed_contacts';
        $this->primaryKey = 'id';
    }

    /**
     * Insert a relayed contact for a P2P hash
     *
     * Uses INSERT IGNORE to silently skip duplicates (same hash + contact_address).
     *
     * @param string $hash P2P hash
     * @param string $contactAddress Contact's address that returned already_relayed
     * @return void
     */
    public function insertRelayedContact(string $hash, string $contactAddress): void {
        $query = "INSERT IGNORE INTO {$this->tableName}
                  (hash, contact_address)
                  VALUES (:hash, :contact_address)";

        $this->execute($query, [
            ':hash' => $hash,
            ':contact_address' => $contactAddress,
        ]);
    }

    /**
     * Get all relayed contacts for a given hash
     *
     * @param string $hash P2P hash
     * @return array Array of relayed contact records
     */
    public function getRelayedContactsByHash(string $hash): array {
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
     * Check if a contact is a relayed contact for a given hash
     *
     * @param string $hash P2P hash
     * @param string $contactAddress Contact address to check
     * @return bool True if the contact returned 'already_relayed' during broadcast
     */
    public function isRelayedContact(string $hash, string $contactAddress): bool {
        $query = "SELECT 1 FROM {$this->tableName}
                  WHERE hash = :hash AND contact_address = :contact_address
                  LIMIT 1";

        $stmt = $this->execute($query, [
            ':hash' => $hash,
            ':contact_address' => $contactAddress,
        ]);

        if (!$stmt) {
            return false;
        }

        return $stmt->fetch() !== false;
    }

    /**
     * Delete all relayed contacts for a hash
     *
     * @param string $hash P2P hash
     * @return int Number of deleted records
     */
    public function deleteByHash(string $hash): int {
        return $this->delete('hash', $hash);
    }

    /**
     * Delete old relayed contact records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = Constants::CLEANUP_P2P_RELAYED_RETENTION_DAYS): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old P2P relayed contact records", $e);
            return 0;
        }
    }
}
