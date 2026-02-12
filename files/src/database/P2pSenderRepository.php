<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use PDO;
use PDOException;

/**
 * P2P Sender Repository
 *
 * Manages database interactions for the p2p_senders table.
 * Tracks all upstream senders per P2P hash so that RP2P responses
 * are sent back to every sender (multi-path support).
 *
 * @package Database\Repository
 */
class P2pSenderRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'hash', 'sender_address', 'sender_public_key', 'created_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'p2p_senders';
        $this->primaryKey = 'id';
    }

    /**
     * Insert a new P2P sender
     *
     * Uses INSERT IGNORE to silently skip duplicates (same hash + sender_address).
     *
     * @param string $hash P2P hash
     * @param string $senderAddress Sender's address
     * @param string $senderPublicKey Sender's public key
     * @return string|false Last insert ID or false on failure/duplicate
     */
    public function insertSender(string $hash, string $senderAddress, string $senderPublicKey) {
        $query = "INSERT IGNORE INTO {$this->tableName}
                  (hash, sender_address, sender_public_key)
                  VALUES (:hash, :sender_address, :sender_public_key)";

        $stmt = $this->execute($query, [
            ':hash' => $hash,
            ':sender_address' => $senderAddress,
            ':sender_public_key' => $senderPublicKey,
        ]);

        if (!$stmt) {
            return false;
        }

        $lastId = $this->pdo->lastInsertId();
        return $lastId ?: false;
    }

    /**
     * Get all senders for a given hash
     *
     * @param string $hash P2P hash
     * @return array Array of sender records
     */
    public function getSendersByHash(string $hash): array {
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
     * Check if a sender already exists for a given hash and address
     *
     * @param string $hash P2P hash
     * @param string $senderAddress Sender address
     * @return bool True if sender exists
     */
    public function senderExists(string $hash, string $senderAddress): bool {
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                  WHERE hash = :hash AND sender_address = :sender_address";

        $stmt = $this->execute($query, [
            ':hash' => $hash,
            ':sender_address' => $senderAddress,
        ]);

        if (!$stmt) {
            return false;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * Delete all senders for a hash
     *
     * @param string $hash P2P hash
     * @return int Number of deleted records
     */
    public function deleteSendersByHash(string $hash): int {
        return $this->delete('hash', $hash);
    }

    /**
     * Delete old sender records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = 1): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old P2P sender records", $e);
            return 0;
        }
    }
}
