<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use PDO;
use PDOException;

/**
 * Transaction Chain Repository
 *
 * Handles chain/sync related operations for transactions including:
 * - Chain integrity verification
 * - Previous txid handling and updates
 * - Chain state summaries for sync negotiation
 * - Chain conflict resolution
 *
 * Extracted from TransactionRepository to separate chain-specific
 * operations from general transaction CRUD operations.
 *
 * @package Database
 */
class TransactionChainRepository extends AbstractRepository
{
    use QueryBuilder;

    /**
     * @var string Table name for this repository
     */
    protected $tableName = 'transactions';

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'tx_type', 'type', 'status', 'sender_address', 'sender_public_key',
        'sender_public_key_hash', 'receiver_address', 'receiver_public_key',
        'receiver_public_key_hash', 'amount', 'currency', 'timestamp', 'txid',
        'previous_txid', 'sender_signature', 'recipient_signature', 'signature_nonce',
        'time', 'memo', 'description', 'initial_sender_address', 'end_recipient_address',
        'sending_started_at', 'recovery_count', 'needs_manual_review'
    ];

    /**
     * Verify chain integrity between two parties
     *
     * Checks that all previous_txid references resolve to existing transactions,
     * detecting gaps in the local transaction chain.
     *
     * @param string $userPublicKey User's public key
     * @param string $contactPublicKey Contact's public key
     * @return array Result with:
     *   - valid: bool - Whether chain is complete
     *   - has_transactions: bool - Whether any transactions exist
     *   - transaction_count: int - Total transaction count
     *   - gaps: array - List of missing previous_txid values
     *   - broken_txids: array - Transactions with missing previous_txid
     */
    public function verifyChainIntegrity(string $userPublicKey, string $contactPublicKey): array {
        $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $userPublicKey);
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);

        $result = [
            'valid' => true,
            'has_transactions' => false,
            'transaction_count' => 0,
            'gaps' => [],
            'broken_txids' => [],
            'gap_context' => []
        ];

        // Get all active transactions between the two parties (excluding cancelled/rejected).
        // We need ALL active txids in the lookup set so that a completed transaction
        // referencing an in-flight transaction's txid doesn't falsely report a gap.
        // However, we only CHECK previous_txid links on settled transactions
        // (completed/accepted/paid) because in-flight transactions (pending/sending/sent)
        // may reference a previous_txid that hasn't been synced yet, or may be re-signed
        // with a different previous_txid after sync (see HeldTransactionService).
        $query = "SELECT txid, previous_txid, status FROM {$this->tableName}
                  WHERE ((sender_public_key_hash = :user_hash AND receiver_public_key_hash = :contact_hash)
                         OR (sender_public_key_hash = :contact_hash2 AND receiver_public_key_hash = :user_hash2))
                  AND status NOT IN ('cancelled', 'rejected')
                  ORDER BY COALESCE(time, 0) ASC, timestamp ASC";

        $stmt = $this->execute($query, [
            ':user_hash' => $userPubkeyHash,
            ':contact_hash' => $contactPubkeyHash,
            ':contact_hash2' => $contactPubkeyHash,
            ':user_hash2' => $userPubkeyHash
        ]);

        if (!$stmt) {
            return $result;
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($transactions) === 0) {
            return $result;
        }

        // Build a set of ALL active txids for lookup (including in-flight)
        // This ensures a completed tx referencing a sending tx's txid won't false-gap
        $txidSet = [];
        // Separate settled transactions for chain-link checking
        $settledStatuses = ['completed', 'accepted', 'paid'];
        $settledTransactions = [];
        foreach ($transactions as $tx) {
            $txidSet[$tx['txid']] = true;
            if (in_array($tx['status'], $settledStatuses, true)) {
                $settledTransactions[] = $tx;
            }
        }

        $result['transaction_count'] = count($settledTransactions);
        $result['has_transactions'] = count($settledTransactions) > 0;

        if (count($settledTransactions) === 0) {
            return $result;
        }

        // Check each settled transaction's previous_txid exists in the full txid set
        // Also build gap context: for each gap, identify the last valid txid before it
        // and the first valid txid after it, so the GUI can show where gaps are in the chain
        $prevValidTxid = null;
        foreach ($settledTransactions as $tx) {
            $prevTxid = $tx['previous_txid'];

            // Skip if previous_txid is null (first transaction in chain)
            if ($prevTxid === null) {
                $prevValidTxid = $tx['txid'];
                continue;
            }

            // Check if previous_txid exists in our local chain (any active status)
            if (!isset($txidSet[$prevTxid])) {
                $result['valid'] = false;
                $result['gaps'][] = $prevTxid;
                $result['broken_txids'][] = $tx['txid'];
                $result['gap_context'][] = [
                    'missing_txid' => $prevTxid,
                    'before_txid' => $prevValidTxid,
                    'after_txid' => $tx['txid']
                ];
            }

            $prevValidTxid = $tx['txid'];
        }

        return $result;
    }

    /**
     * Get the full transaction chain between two parties for sync
     *
     * Returns all active transactions ordered from oldest to newest.
     * Used for bidirectional sync negotiation.
     *
     * @param string $userPublicKey User's public key
     * @param string $contactPublicKey Contact's public key
     * @param string|null $afterTxid Only return transactions after this txid
     * @return array List of transactions
     */
    public function getTransactionChain(string $userPublicKey, string $contactPublicKey, ?string $afterTxid = null): array {
        $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $userPublicKey);
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);

        $query = "SELECT * FROM {$this->tableName}
                  WHERE ((sender_public_key_hash = :user_hash AND receiver_public_key_hash = :contact_hash)
                         OR (sender_public_key_hash = :contact_hash2 AND receiver_public_key_hash = :user_hash2))
                  AND status NOT IN ('cancelled', 'rejected')";

        $params = [
            ':user_hash' => $userPubkeyHash,
            ':contact_hash' => $contactPubkeyHash,
            ':contact_hash2' => $contactPubkeyHash,
            ':user_hash2' => $userPubkeyHash
        ];

        // If afterTxid specified, only get newer transactions
        if ($afterTxid !== null) {
            $query .= " AND (time, timestamp) > (
                SELECT COALESCE(time, 0), timestamp FROM {$this->tableName} WHERE txid = :after_txid
            )";
            $params[':after_txid'] = $afterTxid;
        }

        $query .= " ORDER BY COALESCE(time, 0) ASC, timestamp ASC";

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get chain state summary for sync negotiation
     *
     * Returns a summary of the local chain state for bidirectional sync.
     *
     * @param string $userPublicKey User's public key
     * @param string $contactPublicKey Contact's public key
     * @return array Chain state summary
     */
    public function getChainStateSummary(string $userPublicKey, string $contactPublicKey): array {
        $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $userPublicKey);
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);

        // Get count and oldest/newest txids
        // NOTE: Do NOT filter by status - chain state summary must include ALL transactions
        // for accurate sync comparison. Excluding cancelled/rejected transactions causes
        // sync to think data exists when it doesn't.
        $query = "SELECT
                    COUNT(*) as transaction_count,
                    MIN(txid) as oldest_txid,
                    MAX(txid) as newest_txid
                  FROM {$this->tableName}
                  WHERE ((sender_public_key_hash = :user_hash AND receiver_public_key_hash = :contact_hash)
                         OR (sender_public_key_hash = :contact_hash2 AND receiver_public_key_hash = :user_hash2))";

        $stmt = $this->execute($query, [
            ':user_hash' => $userPubkeyHash,
            ':contact_hash' => $contactPubkeyHash,
            ':contact_hash2' => $contactPubkeyHash,
            ':user_hash2' => $userPubkeyHash
        ]);

        if (!$stmt) {
            return [
                'transaction_count' => 0,
                'oldest_txid' => null,
                'newest_txid' => null,
                'txid_list' => []
            ];
        }

        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get list of all txids for comparison
        // NOTE: Do NOT filter by status - must match the count query above
        $txidQuery = "SELECT txid FROM {$this->tableName}
                      WHERE ((sender_public_key_hash = :user_hash AND receiver_public_key_hash = :contact_hash)
                             OR (sender_public_key_hash = :contact_hash2 AND receiver_public_key_hash = :user_hash2))
                      ORDER BY timestamp ASC";

        $txidStmt = $this->execute($txidQuery, [
            ':user_hash' => $userPubkeyHash,
            ':contact_hash' => $contactPubkeyHash,
            ':contact_hash2' => $contactPubkeyHash,
            ':user_hash2' => $userPubkeyHash
        ]);

        $txidList = [];
        if ($txidStmt) {
            while ($row = $txidStmt->fetch(PDO::FETCH_ASSOC)) {
                $txidList[] = $row['txid'];
            }
        }

        return [
            'transaction_count' => (int)$summary['transaction_count'],
            'oldest_txid' => $summary['oldest_txid'],
            'newest_txid' => $summary['newest_txid'],
            'txid_list' => $txidList
        ];
    }

    /**
     * Get transactions that reference a specific previous_txid
     *
     * Finds transactions that have a specific previous_txid, used for detecting
     * chain conflicts during sync (two transactions claiming the same prev_txid).
     *
     * @param string $previousTxid The previous_txid to search for
     * @return array|null Array of transactions or null if none found
     */
    public function getByPreviousTxid(string $previousTxid): ?array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE previous_txid = :previous_txid
                  AND status NOT IN ('cancelled', 'rejected')
                  ORDER BY COALESCE(time, 0) DESC, timestamp DESC";
        $stmt = $this->execute($query, [':previous_txid' => $previousTxid]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Check if a transaction exists with a specific previous_txid between two parties
     *
     * Used to detect chain forks during sync operations.
     *
     * @param string $previousTxid The previous_txid to check
     * @param string $pubkeyHash1 First party's pubkey hash
     * @param string $pubkeyHash2 Second party's pubkey hash
     * @return array|null Transaction data if found, null otherwise
     */
    public function getLocalTransactionByPreviousTxid(string $previousTxid, string $pubkeyHash1, string $pubkeyHash2): ?array {
        // NOTE: Do NOT filter by status here - chain conflict detection requires ALL transactions
        // to be included, even cancelled/rejected ones. The chain must be complete for proper
        // conflict resolution during sync operations.
        $query = "SELECT * FROM {$this->tableName}
                  WHERE previous_txid = :previous_txid
                  AND ((sender_public_key_hash = :pubkey_hash1 AND receiver_public_key_hash = :pubkey_hash2)
                       OR (sender_public_key_hash = :pubkey_hash3 AND receiver_public_key_hash = :pubkey_hash4))
                  ORDER BY timestamp DESC
                  LIMIT 1";

        $stmt = $this->execute($query, [
            ':previous_txid' => $previousTxid,
            ':pubkey_hash1' => $pubkeyHash1,
            ':pubkey_hash2' => $pubkeyHash2,
            ':pubkey_hash3' => $pubkeyHash2,
            ':pubkey_hash4' => $pubkeyHash1
        ]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Update previous_txid references when a transaction is removed from chain
     *
     * When a transaction is removed (e.g., due to chain conflict resolution),
     * this updates any transactions that were pointing to it to point to
     * the removed transaction's previous_txid instead.
     *
     * @param string $oldTxid The txid being removed from chain
     * @param string|null $newPreviousTxid The new previous_txid to use (may be null)
     * @return int Number of rows updated
     */
    public function updatePreviousTxidReferences(string $oldTxid, ?string $newPreviousTxid): int {
        $query = "UPDATE {$this->tableName}
                  SET previous_txid = :new_previous_txid
                  WHERE previous_txid = :old_txid";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':new_previous_txid', $newPreviousTxid, $newPreviousTxid === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':old_txid', $oldTxid, PDO::PARAM_STR);

        try {
            $stmt->execute();
            $rowCount = $stmt->rowCount();

            if ($rowCount > 0 && function_exists('output')) {
                output("Updated {$rowCount} transaction(s) previous_txid from {$oldTxid} to " . ($newPreviousTxid ?? 'NULL'), 'SILENT');
            }

            return $rowCount;
        } catch (PDOException $e) {
            $this->logError("Failed to update previous_txid references", $e);
            return 0;
        }
    }

    /**
     * Update previous_txid for a specific transaction
     *
     * Used by HeldTransactionService to update a held transaction's
     * previous_txid after sync completes.
     *
     * @param string $txid The txid of the transaction to update
     * @param string|null $newPreviousTxid The new previous_txid value
     * @return bool True if update was successful
     */
    public function updatePreviousTxid(string $txid, ?string $newPreviousTxid): bool {
        $affectedRows = $this->update(
            ['previous_txid' => $newPreviousTxid],
            'txid',
            $txid
        );

        return $affectedRows >= 0;
    }

    /**
     * Update transaction for chain conflict resolution
     *
     * When a transaction is re-signed after losing a chain conflict, the sender
     * re-sends it with a new previous_txid and new signature. This method updates
     * the existing transaction record with the new values and updates the timestamp
     * to reflect when the re-signing occurred.
     *
     * @param string $txid Transaction ID
     * @param string|null $newPreviousTxid New previous_txid (pointing to the winner)
     * @param string $newSignature New signature
     * @param int $newNonce New signature nonce
     * @param bool $updateTimestamp Whether to update the timestamp (default: true)
     * @return bool True if update was successful
     */
    public function updateChainConflictResolution(string $txid, ?string $newPreviousTxid, string $newSignature, int $newNonce, bool $updateTimestamp = true): bool {
        $data = [
            'previous_txid' => $newPreviousTxid,
            'sender_signature' => $newSignature,
            'signature_nonce' => $newNonce
        ];

        if ($updateTimestamp) {
            $data['timestamp'] = date('Y-m-d H:i:s.u');
        }

        $affectedRows = $this->update($data, 'txid', $txid);

        return $affectedRows >= 0;
    }
}
