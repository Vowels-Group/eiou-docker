<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Database\Traits\QueryBuilder;
use PDO;
use PDOException;

/**
 * RP2P Candidate Repository
 *
 * Manages database interactions for the rp2p_candidates table.
 * Stores candidate rp2p responses for best-fee route selection.
 *
 * @package Database\Repository
 */
class Rp2pCandidateRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'hash', 'time', 'amount_whole', 'amount_frac', 'currency', 'sender_public_key',
        'sender_address', 'sender_signature', 'fee_amount_whole', 'fee_amount_frac', 'created_at'
    ];

    /** @var string[] Split amount column prefixes for automatic row mapping */
    protected array $splitAmountColumns = ['amount', 'fee_amount'];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'rp2p_candidates';
        $this->primaryKey = 'id';
    }

    /**
     * Insert a new RP2P candidate
     *
     * @param array $request RP2P candidate data
     * @param SplitAmount $feeAmount The accumulated fee amount for this route
     * @return string|false Last insert ID or false on failure
     */
    public function insertCandidate(array $request, SplitAmount $feeAmount) {
        /** @var SplitAmount $amount */
        $amount = $request['amount'];
        $data = [
            'hash' => $request['hash'],
            'time' => $request['time'],
            'amount_whole' => $amount->whole,
            'amount_frac' => $amount->frac,
            'currency' => $request['currency'],
            'sender_public_key' => $request['senderPublicKey'],
            'sender_address' => $request['senderAddress'],
            'sender_signature' => $request['signature'],
            'fee_amount_whole' => $feeAmount->whole,
            'fee_amount_frac' => $feeAmount->frac,
        ];

        return $this->insert($data);
    }

    /**
     * Get all candidates for a given hash, ordered by amount ascending (lowest fee = lowest amount)
     *
     * @param string $hash P2P hash
     * @return array Array of candidate records
     */
    public function getCandidatesByHash(string $hash): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE hash = :hash
                  ORDER BY amount_whole ASC, amount_frac ASC";

        $stmt = $this->execute($query, [':hash' => $hash]);

        if (!$stmt) {
            return [];
        }

        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get a specific candidate by its primary key ID
     *
     * @param int $id Candidate ID
     * @return array|null Candidate record or null
     */
    public function getCandidateById(int $id): ?array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE id = :id
                  LIMIT 1";

        $stmt = $this->execute($query, [':id' => $id]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->mapRow($result ?: null);
    }

    /**
     * Get the best (lowest amount) candidate for a hash
     *
     * @param string $hash P2P hash
     * @return array|null Best candidate or null
     */
    public function getBestCandidate(string $hash): ?array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE hash = :hash
                  ORDER BY amount_whole ASC, amount_frac ASC
                  LIMIT 1";

        $stmt = $this->execute($query, [':hash' => $hash]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->mapRow($result ?: null);
    }

    /**
     * Get number of candidates for a hash
     *
     * @param string $hash P2P hash
     * @return int Number of candidates
     */
    public function getCandidateCount(string $hash): int {
        return $this->count('hash', $hash);
    }

    /**
     * Delete all candidates for a hash (after selection is made)
     *
     * @param string $hash P2P hash
     * @return int Number of deleted records
     */
    public function deleteCandidatesByHash(string $hash): int {
        return $this->delete('hash', $hash);
    }

    /**
     * Check if a candidate already exists for a given hash and sender
     *
     * @param string $hash P2P hash
     * @param string $senderAddress Sender address
     * @return bool True if candidate exists
     */
    public function candidateExistsForSender(string $hash, string $senderAddress): bool {
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
     * Delete old candidate records
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldRecords(int $days = Constants::CLEANUP_RP2P_CANDIDATE_RETENTION_DAYS): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old RP2P candidate records", $e);
            return 0;
        }
    }
}
