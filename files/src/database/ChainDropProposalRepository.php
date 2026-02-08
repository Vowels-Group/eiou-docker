<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Utils\Logger;
use PDO;
use PDOException;

/**
 * Chain Drop Proposal Repository
 *
 * Manages proposals for dropping missing transactions from the chain
 * by mutual agreement between two contacts.
 *
 * When both sides are missing the same transaction, the chain cannot be
 * repaired via sync. This table stores proposals for both parties to
 * explicitly agree to skip the missing transaction and relink the chain.
 *
 * Lifecycle: pending -> accepted -> executed (success path)
 *            pending -> rejected (declined)
 *            pending -> expired (timeout after 7 days)
 *            accepted -> failed (execution error)
 */
class ChainDropProposalRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'proposal_id', 'contact_pubkey_hash', 'missing_txid',
        'broken_txid', 'previous_txid_before_gap', 'direction',
        'status', 'gap_context', 'created_at', 'updated_at',
        'expires_at', 'resolved_at'
    ];

    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'chain_drop_proposals';
        $this->primaryKey = 'id';
    }

    /**
     * Create a new chain drop proposal
     *
     * @param array $data Proposal data with keys:
     *   - proposal_id: string - Unique proposal identifier
     *   - contact_pubkey_hash: string - Contact's public key hash
     *   - missing_txid: string - The txid that is missing from both chains
     *   - broken_txid: string - The txid whose previous_txid points to the missing one
     *   - previous_txid_before_gap: string|null - The txid before the gap
     *   - direction: string - 'outgoing' or 'incoming'
     *   - gap_context: array - Additional context about the gap
     * @return bool True on success
     */
    public function createProposal(array $data): bool {
        $query = "INSERT INTO {$this->tableName}
                  (proposal_id, contact_pubkey_hash, missing_txid, broken_txid,
                   previous_txid_before_gap, direction, status, gap_context, expires_at)
                  VALUES (:proposal_id, :contact_pubkey_hash, :missing_txid, :broken_txid,
                          :previous_txid_before_gap, :direction, 'pending', :gap_context,
                          DATE_ADD(NOW(6), INTERVAL 7 DAY))";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':proposal_id', $data['proposal_id'], PDO::PARAM_STR);
            $stmt->bindValue(':contact_pubkey_hash', $data['contact_pubkey_hash'], PDO::PARAM_STR);
            $stmt->bindValue(':missing_txid', $data['missing_txid'], PDO::PARAM_STR);
            $stmt->bindValue(':broken_txid', $data['broken_txid'], PDO::PARAM_STR);
            $stmt->bindValue(':previous_txid_before_gap', $data['previous_txid_before_gap'],
                $data['previous_txid_before_gap'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':direction', $data['direction'], PDO::PARAM_STR);
            $stmt->bindValue(':gap_context', json_encode($data['gap_context'] ?? []), PDO::PARAM_STR);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            $this->logError("Failed to create chain drop proposal", $e);
            return false;
        }
    }

    /**
     * Get a proposal by its unique proposal ID
     *
     * @param string $proposalId The proposal ID
     * @return array|null Proposal data or null if not found
     */
    public function getByProposalId(string $proposalId): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE proposal_id = :proposal_id";
        $stmt = $this->execute($query, [':proposal_id' => $proposalId]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get pending proposals for a specific contact
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return array List of pending proposals
     */
    public function getPendingForContact(string $contactPubkeyHash): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE contact_pubkey_hash = :contact_hash
                  AND status = 'pending'
                  AND expires_at > NOW(6)
                  ORDER BY created_at DESC";

        $stmt = $this->execute($query, [':contact_hash' => $contactPubkeyHash]);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all incoming pending proposals (for GUI notifications)
     *
     * @return array List of incoming pending proposals
     */
    public function getIncomingPending(): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE direction = 'incoming'
                  AND status = 'pending'
                  AND expires_at > NOW(6)
                  ORDER BY created_at DESC";

        $stmt = $this->execute($query, []);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all outgoing pending proposals
     *
     * @return array List of outgoing pending proposals
     */
    public function getOutgoingPending(): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE direction = 'outgoing'
                  AND status = 'pending'
                  AND expires_at > NOW(6)
                  ORDER BY created_at DESC";

        $stmt = $this->execute($query, []);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the most recent rejected proposal per contact
     *
     * Returns only the latest rejected proposal for each contact so the GUI
     * can show that the chain gap is unresolved after a rejection.
     *
     * @return array List of rejected proposals (one per contact)
     */
    public function getRecentRejected(): array {
        $query = "SELECT p1.* FROM {$this->tableName} p1
                  INNER JOIN (
                      SELECT contact_pubkey_hash, MAX(resolved_at) as max_resolved
                      FROM {$this->tableName}
                      WHERE status = 'rejected'
                      GROUP BY contact_pubkey_hash
                  ) p2 ON p1.contact_pubkey_hash = p2.contact_pubkey_hash
                      AND p1.resolved_at = p2.max_resolved
                  WHERE p1.status = 'rejected'
                  ORDER BY p1.resolved_at DESC";

        $stmt = $this->execute($query, []);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Update proposal status
     *
     * @param string $proposalId The proposal ID
     * @param string $status New status
     * @return bool True on success
     */
    public function updateStatus(string $proposalId, string $status): bool {
        $data = ['status' => $status];

        if (in_array($status, ['executed', 'rejected', 'expired', 'failed'])) {
            $data['resolved_at'] = date('Y-m-d H:i:s.u');
        }

        $affectedRows = $this->update($data, 'proposal_id', $proposalId);
        return $affectedRows > 0;
    }

    /**
     * Mark a proposal as executed
     *
     * @param string $proposalId The proposal ID
     * @return bool True on success
     */
    public function markExecuted(string $proposalId): bool {
        return $this->updateStatus($proposalId, 'executed');
    }

    /**
     * Expire proposals that have passed their expiration time
     *
     * @return int Number of proposals expired
     */
    public function expireOldProposals(): int {
        $query = "UPDATE {$this->tableName}
                  SET status = 'expired', resolved_at = NOW(6)
                  WHERE status = 'pending'
                  AND expires_at <= NOW(6)";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to expire old chain drop proposals", $e);
            return 0;
        }
    }

    /**
     * Check if an active proposal already exists for a specific gap
     *
     * Prevents duplicate proposals for the same gap with the same contact.
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @param string $missingTxid The missing transaction ID
     * @return array|null Existing proposal or null
     */
    public function getActiveProposalForGap(string $contactPubkeyHash, string $missingTxid): ?array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE contact_pubkey_hash = :contact_hash
                  AND missing_txid = :missing_txid
                  AND status IN ('pending', 'accepted')
                  AND expires_at > NOW(6)
                  ORDER BY created_at DESC
                  LIMIT 1";

        $stmt = $this->execute($query, [
            ':contact_hash' => $contactPubkeyHash,
            ':missing_txid' => $missingTxid
        ]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get all proposals for a contact (any status, for display)
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @param int $limit Maximum results
     * @return array List of proposals
     */
    public function getAllForContact(string $contactPubkeyHash, int $limit = 20): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE contact_pubkey_hash = :contact_hash
                  ORDER BY created_at DESC
                  LIMIT :limit";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':contact_hash', $contactPubkeyHash, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logError("Failed to get proposals for contact", $e);
            return [];
        }
    }
}
