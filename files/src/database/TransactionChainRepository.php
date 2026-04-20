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
    public function verifyChainIntegrity(string $userPublicKey, string $contactPublicKey, ?string $currency = null, bool $useCheckpoint = true): array {
        $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $userPublicKey);
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);
        return $this->verifyChainIntegrityByHashes($userPubkeyHash, $contactPubkeyHash, $currency, $useCheckpoint);
    }

    /**
     * Variant of verifyChainIntegrity that accepts pre-computed pubkey hashes
     * directly. Used by callers that already work in hash space (e.g. the
     * transaction archival job, which finds bilateral pairs by hash and would
     * otherwise have to re-fetch the public keys just to hash them again).
     *
     * Phase 2 fast path (default, $useCheckpoint=true):
     *   1. Query ONLY live rows for the pair.
     *   2. Look up the pair's checkpoint in transaction_chain_checkpoints.
     *   3. Walk settled live txs. A settled tx whose previous_txid is not in
     *      the live lookup set is a real gap ONLY if no checkpoint exists for
     *      the pair. If a checkpoint exists, the archival service already
     *      verified gap-freeness at the moment of archival and wrote the
     *      proof — subsequent verify calls trust that boundary.
     *   Cost: O(live tail) + 1 indexed row lookup. Collapses from
     *   O(all history) once any archival has happened for a pair.
     *
     * Paranoid path ($useCheckpoint=false) — used by `eiou verify-chain`:
     *   1. Query live AND archive rows.
     *   2. Walk all settled txs (live + archive) with a merged lookup set.
     *   Cost: O(all history), but catches the case where something tampered
     *   with the archive between the archival run and the verify — which the
     *   checkpoint-trust path would miss by construction.
     *
     * transaction_count: settled live count + checkpoint.archived_count
     * (when checkpoint is present), so the total reflects the whole chain
     * history even though the walk only visits live in the fast path.
     */
    public function verifyChainIntegrityByHashes(string $userPubkeyHash, string $contactPubkeyHash, ?string $currency = null, bool $useCheckpoint = true): array {
        $result = [
            'valid' => true,
            'has_transactions' => false,
            'transaction_count' => 0,
            'gaps' => [],
            'broken_txids' => [],
            'gap_context' => []
        ];

        // Get all transactions between the two parties in live (including
        // cancelled/rejected — see commit 04239bc6 "Preserve chain integrity
        // via inclusion": previous_txid can legitimately point to a cancelled
        // row, so filtering them out would create false-positive gaps).
        $liveWhere = "((sender_public_key_hash = :user_hash AND receiver_public_key_hash = :contact_hash)
                       OR (sender_public_key_hash = :contact_hash2 AND receiver_public_key_hash = :user_hash2))";
        if ($currency !== null) {
            $liveWhere .= " AND currency = :currency";
        }

        $liveQuery = "SELECT txid, previous_txid, status FROM {$this->tableName}
                      WHERE {$liveWhere}
                      ORDER BY COALESCE(`time`, 0) ASC, timestamp ASC";
        $liveParams = [
            ':user_hash'     => $userPubkeyHash,
            ':contact_hash'  => $contactPubkeyHash,
            ':contact_hash2' => $contactPubkeyHash,
            ':user_hash2'    => $userPubkeyHash,
        ];
        if ($currency !== null) {
            $liveParams[':currency'] = $currency;
        }

        $stmt = $this->execute($liveQuery, $liveParams);
        if (!$stmt) {
            return $result;
        }
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Canonical (LEAST, GREATEST) pair for checkpoint lookup — matches
        // what TransactionArchiveRepository::upsertCheckpoint wrote.
        $pairA = $userPubkeyHash;
        $pairB = $contactPubkeyHash;
        if (strcmp($pairA, $pairB) > 0) {
            [$pairA, $pairB] = [$pairB, $pairA];
        }

        $checkpoint = null;
        if ($useCheckpoint) {
            // Fast path: trust the checkpoint as gap-free-at-archival proof.
            $checkpoint = $this->fetchChainCheckpoint($pairA, $pairB);
        } else {
            // Paranoid path: actually merge archive rows in so the walk can
            // detect post-archival tampering. See docblock.
            $archived = $this->fetchArchiveTransactionsForPair($userPubkeyHash, $contactPubkeyHash, $currency);
            if ($archived) {
                $transactions = array_merge($transactions, $archived);
            }
        }

        $txidSet = [];
        $settledStatuses = ['completed', 'accepted', 'paid'];
        $settledTransactions = [];
        foreach ($transactions as $tx) {
            $txidSet[$tx['txid']] = true;
            if (in_array($tx['status'], $settledStatuses, true)) {
                $settledTransactions[] = $tx;
            }
        }

        // Total count includes archived rows too — the transaction_count is
        // "how many settled txs does this bilateral chain have", not "how
        // many rows did we just scan". Fast path gets archived count from
        // the checkpoint; paranoid path already has archive rows merged into
        // $settledTransactions.
        $archivedCount = ($useCheckpoint && $checkpoint !== null) ? (int) $checkpoint['archived_count'] : 0;
        $result['transaction_count'] = count($settledTransactions) + $archivedCount;
        $result['has_transactions'] = $result['transaction_count'] > 0;

        if (count($settledTransactions) === 0) {
            return $result;
        }

        $prevValidTxid = null;
        foreach ($settledTransactions as $tx) {
            $prevTxid = $tx['previous_txid'];

            if ($prevTxid === null) {
                $prevValidTxid = $tx['txid'];
                continue;
            }
            if (isset($txidSet[$prevTxid])) {
                $prevValidTxid = $tx['txid'];
                continue;
            }

            // prev_txid not in the live set. In the fast path, a checkpoint
            // existing means the archival service already verified the
            // archived portion was gap-free at archival time — trust that
            // the missing prev_txid is an archived row. In the paranoid
            // path, $checkpoint is null by construction (we never fetched
            // it) and the archive rows are already in $txidSet, so missing
            // here is a REAL gap regardless.
            if ($useCheckpoint && $checkpoint !== null) {
                $prevValidTxid = $tx['txid'];
                continue;
            }

            $result['valid'] = false;
            $result['gaps'][] = $prevTxid;
            $result['broken_txids'][] = $tx['txid'];
            $result['gap_context'][] = [
                'missing_txid' => $prevTxid,
                'before_txid'  => $prevValidTxid,
                'after_txid'   => $tx['txid']
            ];
            $prevValidTxid = $tx['txid'];
        }

        return $result;
    }

    /**
     * Private helper: fetch the per-pair checkpoint row (or null). Silently
     * returns null if the checkpoint table doesn't exist — happens on a
     * freshly-migrated schema ≤ v9 node that hasn't applied v10 yet, or
     * a test environment where only the live table is set up. A missing
     * checkpoint is semantically the same as "no archive for this pair",
     * and the caller handles it as "no gaps can be hidden in the archive,
     * so a missing previous_txid is a real gap".
     *
     * Pair hashes MUST already be in canonical (LEAST, GREATEST) order —
     * caller canonicalizes before invoking.
     */
    private function fetchChainCheckpoint(string $pairA, string $pairB): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT user_public_key_hash, contact_public_key_hash, archived_count,
                        archived_txid_hash, highest_archived_timestamp,
                        highest_archived_time, last_verified_gap_free_at
                 FROM transaction_chain_checkpoints
                 WHERE user_public_key_hash = :a AND contact_public_key_hash = :b
                 LIMIT 1"
            );
            $stmt->execute([':a' => $pairA, ':b' => $pairB]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Private helper: fetch archive rows for a bilateral pair. Used only
     * by the paranoid ($useCheckpoint=false) verify path and by
     * `eiou verify-chain` via the higher-level call. Silently returns
     * an empty array if the archive table is missing.
     */
    private function fetchArchiveTransactionsForPair(string $userPubkeyHash, string $contactPubkeyHash, ?string $currency): array
    {
        try {
            $where = "((sender_public_key_hash = :a AND receiver_public_key_hash = :b)
                       OR (sender_public_key_hash = :b2 AND receiver_public_key_hash = :a2))";
            $params = [
                ':a'  => $userPubkeyHash,
                ':b'  => $contactPubkeyHash,
                ':a2' => $userPubkeyHash,
                ':b2' => $contactPubkeyHash,
            ];
            if ($currency !== null) {
                $where .= " AND currency = :currency";
                $params[':currency'] = $currency;
            }
            $sql = "SELECT txid, previous_txid, status FROM transactions_archive WHERE {$where}";
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
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
    public function getTransactionChain(string $userPublicKey, string $contactPublicKey, ?string $afterTxid = null, ?string $currency = null): array {
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

        if ($currency !== null) {
            $query .= " AND currency = :currency";
            $params[':currency'] = $currency;
        }

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
    public function getChainStateSummary(string $userPublicKey, string $contactPublicKey, ?string $currency = null): array {
        $userPubkeyHash = hash(Constants::HASH_ALGORITHM, $userPublicKey);
        $contactPubkeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);

        // NOTE: Do NOT filter by status - chain state summary must include
        // ALL transactions for accurate sync comparison.
        //
        // Scans live AND archive so the txid_list matches across nodes
        // regardless of archival state — otherwise two nodes syncing where
        // only one has archived part of the chain would compute different
        // txid_lists and the sync negotiation would treat archived rows as
        // missing. Can't use the per-pair checkpoint shortcut here because
        // sync needs the full txid set to compare (not just a count).
        $where = "((sender_public_key_hash = :user_hash AND receiver_public_key_hash = :contact_hash)
                   OR (sender_public_key_hash = :contact_hash2 AND receiver_public_key_hash = :user_hash2))";
        $params = [
            ':user_hash'     => $userPubkeyHash,
            ':contact_hash'  => $contactPubkeyHash,
            ':contact_hash2' => $contactPubkeyHash,
            ':user_hash2'    => $userPubkeyHash,
        ];
        if ($currency !== null) {
            $where .= " AND currency = :currency";
            $params[':currency'] = $currency;
        }

        // Live side — primary source of truth (if this fails we bail).
        $liveTxidStmt = $this->execute(
            "SELECT txid FROM {$this->tableName} WHERE {$where} ORDER BY timestamp ASC",
            $params
        );
        if (!$liveTxidStmt) {
            return [
                'transaction_count' => 0,
                'oldest_txid' => null,
                'newest_txid' => null,
                'txid_list' => []
            ];
        }
        $txidList = [];
        while ($row = $liveTxidStmt->fetch(PDO::FETCH_ASSOC)) {
            $txidList[] = $row['txid'];
        }

        // Archive side — silently append if the table exists. On a fresh
        // v9→v10 node or in tests without the archive table, this harmlessly
        // contributes zero rows. Archive rows go AFTER live in the list
        // since we assume archival moves the oldest first, but the sync
        // protocol sorts before comparing anyway.
        try {
            $archiveStmt = $this->pdo->prepare(
                "SELECT txid FROM transactions_archive WHERE {$where} ORDER BY timestamp ASC"
            );
            foreach ($params as $k => $v) {
                $archiveStmt->bindValue($k, $v);
            }
            $archiveStmt->execute();
            while ($row = $archiveStmt->fetch(PDO::FETCH_ASSOC)) {
                $txidList[] = $row['txid'];
            }
        } catch (PDOException $e) {
            // Archive table missing — live-only result is correct.
        }

        // oldest/newest by lexical txid, matching the prior MIN/MAX semantics.
        $oldest = null;
        $newest = null;
        foreach ($txidList as $t) {
            if ($oldest === null || strcmp($t, $oldest) < 0) $oldest = $t;
            if ($newest === null || strcmp($t, $newest) > 0) $newest = $t;
        }

        return [
            'transaction_count' => count($txidList),
            'oldest_txid' => $oldest,
            'newest_txid' => $newest,
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
    public function getLocalTransactionByPreviousTxid(string $previousTxid, string $pubkeyHash1, string $pubkeyHash2, ?string $currency = null): ?array {
        // NOTE: Do NOT filter by status here - chain conflict detection requires ALL transactions
        // to be included, even cancelled/rejected ones. The chain must be complete for proper
        // conflict resolution during sync operations.
        $query = "SELECT * FROM {$this->tableName}
                  WHERE previous_txid = :previous_txid
                  AND ((sender_public_key_hash = :pubkey_hash1 AND receiver_public_key_hash = :pubkey_hash2)
                       OR (sender_public_key_hash = :pubkey_hash3 AND receiver_public_key_hash = :pubkey_hash4))";

        $params = [
            ':previous_txid' => $previousTxid,
            ':pubkey_hash1' => $pubkeyHash1,
            ':pubkey_hash2' => $pubkeyHash2,
            ':pubkey_hash3' => $pubkeyHash2,
            ':pubkey_hash4' => $pubkeyHash1
        ];

        if ($currency !== null) {
            $query .= " AND currency = :currency";
            $params[':currency'] = $currency;
        }

        $query .= " ORDER BY timestamp DESC LIMIT 1";

        $stmt = $this->execute($query, $params);

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
