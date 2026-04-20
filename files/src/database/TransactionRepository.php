<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Formatters\TransactionFormatter;
use Eiou\Utils\Logger;
use PDO;
use PDOException;

/**
 * Transaction Repository
 *
 * Manages all database interactions for the transactions table.
 *
 * @package Database\Repository
 *
 * NOTE: Methods using `$this->execute()` contain `if (!$stmt)` checks because
 * AbstractRepository::execute() catches PDOException and returns false
 * (see AbstractRepository.php). Those checks ARE required.
 *
 * Methods using `$this->pdo->prepare()` directly use try/catch(PDOException)
 * instead, since PDO throws exceptions and would never return a falsy $stmt.
 *
 * @see AbstractRepository::execute() for the error handling implementation
 */
class TransactionRepository extends AbstractRepository {
    use QueryBuilder;

    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'tx_type', 'type', 'status', 'sender_address', 'sender_public_key',
        'sender_public_key_hash', 'receiver_address', 'receiver_public_key',
        'receiver_public_key_hash', 'amount_whole', 'amount_frac', 'currency', 'timestamp', 'txid',
        'previous_txid', 'sender_signature', 'recipient_signature', 'signature_nonce',
        'time', 'memo', 'description', 'initial_sender_address', 'end_recipient_address',
        'sending_started_at', 'recovery_count', 'needs_manual_review', 'expires_at',
        'signed_message_content'
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
        $this->tableName = 'transactions';
        $this->primaryKey = 'id';
    }
    /**
     * Check if transaction is completed by memo
     *
     * @param string $memo Transaction memo
     * @return bool True if completed
     */
    public function isCompletedByMemo(string $memo): bool {
        $transactions = $this->getByMemo($memo);
        $completed = 0;
        foreach($transactions as $transaction){
            if($transaction['status'] === Constants::STATUS_COMPLETED){
                $completed+=1;
            }
        }
        return $completed === count($transactions);
    }

    /**
     * Check if transaction is completed by txid
     *
     * @param string $txid Transaction ID
     * @return bool True if completed
     */
    public function isCompletedByTxid(string $txid): bool {
        $transactions = $this->getByTxid($txid);
        $completed = 0;
        foreach($transactions as $transaction){
            if($transaction['status'] === Constants::STATUS_COMPLETED){
                $completed+=1;
            }
        }
        return $completed === count($transactions);
    }

    /**
     * Check if previous txid exists in chain
     *
     * @param string $previousTxid Previous transaction ID
     * @return bool True if exists
     */
    public function existingPreviousTxid(string $previousTxid): bool {
        $query = "SELECT txid FROM {$this->tableName}
                  WHERE previous_txid = :previous_txid
                  ORDER BY timestamp DESC LIMIT 1";
        $stmt = $this->execute($query, [':previous_txid' => $previousTxid]);

        if (!$stmt) {
            return false;
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Check if txid exists
     *
     * @param string $txid Transaction ID
     * @return bool True if exists
     */
    public function existingTxid(string $txid): bool {
        $query = "SELECT txid FROM {$this->tableName}
                  WHERE txid = :txid
                  ORDER BY timestamp DESC LIMIT 1";
        $stmt = $this->execute($query, [':txid' => $txid]);

        if (!$stmt) {
            return false;
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Get previous transaction ID between two parties.
     *
     * Skips cancelled and rejected transactions so the resulting previous_txid
     * only points at rows that sync between nodes. Cancelled rows are sender-
     * local (never signed / never delivered when cancelled while pending) and
     * rejected rows are filtered out of sync responses (see
     * TransactionChainRepository::getTransactionChain). Linking through them
     * would cause asymmetric chain state: the signing node chains past the
     * cancelled row but the peer never sees it, producing a permanent gap.
     *
     * @param string $senderPublicKey Sender's public key
     * @param string $receiverPublicKey Receiver's public key
     * @param string|null $excludeTxid Optional txid to exclude from the query
     * @return string|null Previous txid or null
     */
    public function getPreviousTxid(string $senderPublicKey, string $receiverPublicKey, ?string $excludeTxid = null, ?string $currency = null): ?string {
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $receiverPublicKey);

        $query = "SELECT txid FROM {$this->tableName}
                WHERE ((sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash)
                    OR (sender_public_key_hash = :second_receiver_public_key_hash AND receiver_public_key_hash = :second_sender_public_key_hash))
                AND status NOT IN ('cancelled', 'rejected')";

        $params = [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash,
            ':second_receiver_public_key_hash' => $receiverPublicKeyHash,
            ':second_sender_public_key_hash' => $senderPublicKeyHash
        ];

        // Filter by currency for per-currency chain separation
        if ($currency !== null) {
            $query .= " AND currency = :currency";
            $params[':currency'] = $currency;
        }

        // Exclude specific txid if provided (used when updating held transaction's previous_txid)
        if ($excludeTxid !== null) {
            $query .= " AND txid != :exclude_txid";
            $params[':exclude_txid'] = $excludeTxid;
        }

        // Order by timestamp (database insertion time) for chain ordering
        $query .= " ORDER BY timestamp DESC LIMIT 1";

        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['txid'] : null;
    }

    /**
     * Get the latest txid for each currency in the chain between two parties
     *
     * @param string $senderPublicKey
     * @param string $receiverPublicKey
     * @return array Map of currency => latest txid (e.g. ['USD' => 'abc...', 'EUR' => 'def...'])
     */
    public function getPreviousTxidsByCurrency(string $senderPublicKey, string $receiverPublicKey): array {
        $senderHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $receiverHash = hash(Constants::HASH_ALGORITHM, $receiverPublicKey);

        $query = "SELECT currency, txid FROM {$this->tableName}
                WHERE ((sender_public_key_hash = :s1 AND receiver_public_key_hash = :r1)
                    OR (sender_public_key_hash = :r2 AND receiver_public_key_hash = :s2))
                AND currency IS NOT NULL
                AND status NOT IN ('cancelled', 'rejected')
                ORDER BY timestamp DESC";

        $stmt = $this->execute($query, [
            ':s1' => $senderHash, ':r1' => $receiverHash,
            ':r2' => $receiverHash, ':s2' => $senderHash
        ]);

        if (!$stmt) {
            return [];
        }

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // First occurrence per currency is the latest (ORDER BY timestamp DESC)
            if (!isset($result[$row['currency']])) {
                $result[$row['currency']] = $row['txid'];
            }
        }

        return $result;
    }

    /**
     * Check for new transactions since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewTransactions(int $lastCheckTime): bool
    {
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null) {
            return false;
        }

        $placeholders = $this->createPlaceholders($userAddresses);

        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                    WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                    AND timestamp > ?";

        // Use MySQL DATETIME wire format — DISPLAY_DATE_FORMAT is the European
        // presentation format and MySQL 1292-errors when it's passed as a
        // parameter to a DATETIME comparison.
        $params = $this->buildInClauseParams($userAddresses, 2, [date('Y-m-d H:i:s', $lastCheckTime)]);
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::getInstance()->log('Failed to check new transactions: ' . $e->getMessage(), 'WARNING');
            return false;
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * Get transactions by type
     *
     * @param string $type
     * @return array
     */
    public function getTransactionsByType(string $type, int $limit = 10, int $offset = 0): array
    {
        // TODO(#863 phase 3): push $type into SQL + UNION archive so this
        // isn't O(all history). Callers in ApiController already pass
        // $limit + $offset (which this method previously silently dropped
        // because its signature was single-arg) — matching the signature
        // here at least lets the API result respect pagination, but the
        // inner getTransactionHistory still scans live-only until a deeper
        // refactor.
        $allTransactions = $this->getTransactionHistory(PHP_INT_MAX);
        $filtered = array_values(array_filter(
            $allTransactions,
            fn($tx) => ($tx['type'] ?? null) === $type
        ));
        return array_slice($filtered, $offset, $limit);
    }

    /**
     * Get recent transactions
     *
     * @param int $limit
     * @return array
     */
    public function getRecentTransactions(int $limit = Constants::DISPLAY_RECENT_TRANSACTIONS_LIMIT): array
    {
        return $this->getTransactionHistory($limit);
    }

    /**
     * Get transactions sent by user
     *
     * @param int $limit Maximum number of transactions to return
     * @param string|null $currency Optional currency filter
     * @return array
     */
    public function getSentUserTransactions(int $limit = 10, ?string $currency = null): array {
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null) {
            return [];
        }

        $placeholders = $this->createPlaceholders($userAddresses);

        $selectCols = "receiver_address, amount_whole, amount_frac, currency, timestamp";
        $where      = "sender_address IN ($placeholders)";
        if ($currency !== null) {
            $where .= " AND currency = ?";
        }

        $whereParams = $this->buildInClauseParams($userAddresses, 1, $currency !== null ? [$currency] : []);

        $transactions = $this->queryTransactionsUnionArchive(
            $selectCols, $where, $whereParams, $limit,
            'Failed to get sent transactions'
        );
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_SENT, 'receiver_address');
    }

    /**
     * Get transactions received by user
     *
     * @param int $limit Maximum number of transactions to return
     * @param string|null $currency Optional currency filter
     * @return array
     */
    public function getReceivedUserTransactions(int $limit = 10, ?string $currency = null): array{
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null) {
            return [];
        }

        $placeholders = $this->createPlaceholders($userAddresses);

        $selectCols = "sender_address, amount_whole, amount_frac, currency, timestamp";
        $where      = "receiver_address IN ($placeholders)";
        if ($currency !== null) {
            $where .= " AND currency = ?";
        }

        $whereParams = $this->buildInClauseParams($userAddresses, 1, $currency !== null ? [$currency] : []);

        $transactions = $this->queryTransactionsUnionArchive(
            $selectCols, $where, $whereParams, $limit,
            'Failed to get received transactions'
        );
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_RECEIVED, 'sender_address');
    }

    /**
     * Get transactions received by user from specific address
     *
     * @param string $senderAddress Address of transaction sender
     * @param int $limit Maximum number of transactions to return
     * @param string|null $currency Optional currency filter
     * @return array
     */
    public function getReceivedUserTransactionsAddress(string $senderAddress, int $limit = 10, ?string $currency = null): array{
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null) {
            return [];
        }

        $placeholders = $this->createPlaceholders($userAddresses);

        $selectCols = "sender_address, amount_whole, amount_frac, currency, timestamp";
        $where      = "receiver_address IN ($placeholders) AND LOWER(sender_address) = LOWER(?)";
        $extraParams = [$senderAddress];
        if ($currency !== null) {
            $where .= " AND currency = ?";
            $extraParams[] = $currency;
        }

        $whereParams = $this->buildInClauseParams($userAddresses, 1, $extraParams);

        $transactions = $this->queryTransactionsUnionArchive(
            $selectCols, $where, $whereParams, $limit,
            'Failed to get received transactions by address'
        );
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_RECEIVED, 'sender_address');
    }

    /**
     * Get transactions sent by user to specific address
     *
     * @param string $receiverAddress Address of transaction receiver
     * @param int $limit Maximum number of transactions to return
     * @param string|null $currency Optional currency filter
     * @return array
     */
    public function getSentUserTransactionsAddress(string $receiverAddress, int $limit = 10, ?string $currency = null): array {
        $userAddresses = $this->getUserAddressesOrNull();

        if ($userAddresses === null) {
            return [];
        }

        $placeholders = $this->createPlaceholders($userAddresses);

        $selectCols = "receiver_address, amount_whole, amount_frac, currency, timestamp";
        $where      = "sender_address IN ($placeholders) AND LOWER(receiver_address) = LOWER(?)";
        $extraParams = [$receiverAddress];
        if ($currency !== null) {
            $where .= " AND currency = ?";
            $extraParams[] = $currency;
        }

        $whereParams = $this->buildInClauseParams($userAddresses, 1, $extraParams);

        $transactions = $this->queryTransactionsUnionArchive(
            $selectCols, $where, $whereParams, $limit,
            'Failed to get sent transactions by address'
        );
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_SENT, 'receiver_address');
    }

    /**
     * Private helper: run the same SELECT against `transactions` AND
     * `transactions_archive`, merge + sort + limit in PHP. Used by the
     * four sent/received methods above so a CLI history dump after
     * archival shows the complete history (not just live rows).
     *
     * Semantics:
     *   - Live query MUST succeed — it's the primary source of truth.
     *     Failure returns `[]` and logs a WARNING.
     *   - Archive query is best-effort. On PDOException (archive table
     *     missing during v9→v10 transitional period, etc.) it
     *     contributes zero rows and no error is surfaced.
     *   - Each side's `LIMIT $limit` is applied independently before the
     *     merge. This means we fetch up to 2 × $limit rows and then
     *     re-sort / re-slice in PHP. For reasonable limits (tens to
     *     hundreds) this is cheap; the archive's index on `timestamp`
     *     keeps the per-side fetch fast.
     *
     * @param string $selectCols     The comma-separated SELECT list (no "FROM").
     * @param string $where          WHERE clause body (with ?  placeholders).
     * @param array  $whereParams    Parameters for the WHERE placeholders.
     * @param int    $limit          Row cap after merge.
     * @param string $errorContext   Log message prefix on failure.
     * @return array Rows sorted by `timestamp` DESC, capped at $limit.
     */
    private function queryTransactionsUnionArchive(
        string $selectCols,
        string $where,
        array $whereParams,
        int $limit,
        string $errorContext
    ): array {
        $liveQuery    = "SELECT {$selectCols} FROM transactions WHERE {$where} ORDER BY timestamp DESC LIMIT ?";
        $archiveQuery = "SELECT {$selectCols} FROM transactions_archive WHERE {$where} ORDER BY timestamp DESC LIMIT ?";

        $paramsWithLimit = array_merge($whereParams, [$limit]);

        try {
            $stmt = $this->pdo->prepare($liveQuery);
            $stmt->execute($paramsWithLimit);
            $liveRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            Logger::getInstance()->log("{$errorContext}: " . $e->getMessage(), 'WARNING');
            return [];
        }

        $archiveRows = [];
        try {
            $stmt = $this->pdo->prepare($archiveQuery);
            $stmt->execute($paramsWithLimit);
            $archiveRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Archive table missing — live-only result is correct.
        }

        if ($archiveRows === []) {
            return $liveRows;
        }

        $merged = array_merge($liveRows, $archiveRows);
        usort($merged, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        return array_slice($merged, 0, $limit);
    }

    /**
     * Get transactions (all data) with limit
     *
     * @param int $limit
     * @return array
     */
    public function getTransactions(int $limit = 10): array
    {
        $userAddresses = $this->getUserAddressesOrNull();

        if ($userAddresses === null) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = $this->createPlaceholders($userAddresses);

        $query = "SELECT * FROM {$this->tableName}
                    WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                    ORDER BY timestamp DESC LIMIT ?";

        // Bind parameters - addresses twice for both IN clauses, then limit
        $params = $this->buildInClauseParams($userAddresses, 2, [$limit]);
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::getInstance()->log('Failed to get transactions: ' . $e->getMessage(), 'WARNING');
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $transactions ? $this->mapRows($transactions) : [];
    }

    /**
     * Get transaction history with limit
     *
     * @param int $limit Maximum number of transactions to return
     * @param string|null $currency Optional currency filter
     * @return array
     */
    public function getTransactionHistory(int $limit = 10, ?string $currency = null, int $offset = 0): array
    {
        $userAddresses = $this->getUserAddressesOrNull();

        if ($userAddresses === null) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = $this->createPlaceholders($userAddresses);

        // Query with LEFT JOINs to get contact names, p2p details, and full transaction details
        $query = "SELECT
                    t.id,
                    t.txid,
                    t.tx_type,
                    t.type AS direction,
                    t.status,
                    t.sender_address,
                    t.receiver_address,
                    t.sender_public_key,
                    t.sender_public_key_hash,
                    t.receiver_public_key,
                    t.receiver_public_key_hash,
                    t.amount_whole,
                    t.amount_frac,
                    t.currency,
                    t.timestamp,
                    t.memo,
                    t.description,
                    t.previous_txid,
                    t.end_recipient_address,
                    t.initial_sender_address,
                    sender_contact.name AS sender_name,
                    receiver_contact.name AS receiver_name,
                    p2p.destination_address AS p2p_destination,
                    p2p.amount_whole AS p2p_amount_whole,
                    p2p.amount_frac AS p2p_amount_frac,
                    p2p.my_fee_amount_whole AS p2p_fee_whole,
                    p2p.my_fee_amount_frac AS p2p_fee_frac
                  FROM {$this->tableName} t
                  LEFT JOIN addresses sender_addr ON (t.sender_address = sender_addr.http OR t.sender_address = sender_addr.https OR t.sender_address = sender_addr.tor)
                  LEFT JOIN contacts sender_contact ON sender_addr.pubkey_hash = sender_contact.pubkey_hash
                  LEFT JOIN addresses receiver_addr ON (t.receiver_address = receiver_addr.http OR t.receiver_address = receiver_addr.https OR t.receiver_address = receiver_addr.tor)
                  LEFT JOIN contacts receiver_contact ON receiver_addr.pubkey_hash = receiver_contact.pubkey_hash
                  LEFT JOIN p2p ON t.memo = p2p.hash
                  WHERE (t.sender_address IN ($placeholders) OR t.receiver_address IN ($placeholders))";

        $additionalParams = [];
        if ($currency !== null) {
            $query .= " AND t.currency = ?";
            $additionalParams[] = $currency;
        }

        $query .= " ORDER BY COALESCE(t.time, 0) DESC, t.timestamp DESC LIMIT ? OFFSET ?";
        $additionalParams[] = $limit;
        $additionalParams[] = max(0, $offset);

        // Bind parameters - addresses twice for both IN clauses, then optional currency, then limit, offset
        $params = $this->buildInClauseParams($userAddresses, 2, $additionalParams);
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::getInstance()->log('Failed to get transaction history: ' . $e->getMessage(), 'WARNING');
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatHistoryMany($transactions, $userAddresses);
    }

    /**
     * Database-wide search across the user's transaction history. Backs
     * the "Search entire database" button on the Recent Transactions
     * table — lets users find old transactions (e.g. "Bob") without
     * hammering the Load-older button until the match appears in the
     * current page window.
     *
     * Search term matches (case-insensitive, substring) against:
     *   - counterparty name (sender's contact.name or receiver's contact.name)
     *   - transaction description
     *   - sender address / receiver address
     *
     * Optional filter dimensions mirror the client-side filter select
     * inputs so "search database" respects the same dropdowns the user
     * already set. Empty filter = no restriction.
     *
     * Result capped at $maxResults (default 500) so a broad query like
     * "USD" returns a bounded JSON response the GUI can paginate
     * client-side. Callers can detect truncation when the result length
     * equals $maxResults.
     *
     * @param string      $term        Lowercase substring search term (already lowercased by caller)
     * @param string|null $direction   'sent' | 'received' | null
     * @param string|null $txType      'direct' | 'p2p' | 'contact' | null (maps to tx_type column)
     * @param string|null $status      'pending' | 'sent' | 'accepted' | 'completed' | 'rejected' | 'cancelled' | null
     * @param int         $maxResults  Hard cap on returned rows
     * @return array Formatted transactions (same shape as getTransactionHistory)
     */
    public function searchTransactions(
        string $term,
        ?string $direction = null,
        ?string $txType = null,
        ?string $status = null,
        int $maxResults = 500
    ): array {
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null) {
            return [];
        }
        // Defensive: an empty term would match every row; short-circuit
        // so a caller who accidentally submits "" doesn't ship the whole
        // table over the wire.
        if (trim($term) === '') {
            return [];
        }

        $placeholders = $this->createPlaceholders($userAddresses);
        $like = '%' . strtolower(trim($term)) . '%';

        // Same SELECT + JOIN structure as getTransactionHistory — share
        // the column list so the formatter + row partial see identical
        // data shape between the history path and the search path.
        // Extra JOINs for the P2P endpoint columns so a search for
        // "carol" can match the tx's *ultimate* counterparty when Alice
        // sent through Bob — end_recipient_address carries Carol's
        // address on the sent-P2P side, initial_sender_address carries
        // it on the received-P2P side. Without these JOINs the WHERE
        // could only match direct neighbours, which missed every P2P tx
        // where the search term names someone further down the chain.
        $query = "SELECT
                    t.id, t.txid, t.tx_type, t.type AS direction, t.status,
                    t.sender_address, t.receiver_address,
                    t.sender_public_key, t.sender_public_key_hash,
                    t.receiver_public_key, t.receiver_public_key_hash,
                    t.amount_whole, t.amount_frac, t.currency, t.timestamp,
                    t.memo, t.description, t.previous_txid,
                    t.end_recipient_address, t.initial_sender_address,
                    sender_contact.name AS sender_name,
                    receiver_contact.name AS receiver_name,
                    p2p.destination_address AS p2p_destination,
                    p2p.amount_whole AS p2p_amount_whole,
                    p2p.amount_frac AS p2p_amount_frac,
                    p2p.my_fee_amount_whole AS p2p_fee_whole,
                    p2p.my_fee_amount_frac AS p2p_fee_frac
                  FROM {$this->tableName} t
                  LEFT JOIN addresses sender_addr ON (t.sender_address = sender_addr.http OR t.sender_address = sender_addr.https OR t.sender_address = sender_addr.tor)
                  LEFT JOIN contacts sender_contact ON sender_addr.pubkey_hash = sender_contact.pubkey_hash
                  LEFT JOIN addresses receiver_addr ON (t.receiver_address = receiver_addr.http OR t.receiver_address = receiver_addr.https OR t.receiver_address = receiver_addr.tor)
                  LEFT JOIN contacts receiver_contact ON receiver_addr.pubkey_hash = receiver_contact.pubkey_hash
                  LEFT JOIN addresses end_addr ON (t.end_recipient_address = end_addr.http OR t.end_recipient_address = end_addr.https OR t.end_recipient_address = end_addr.tor)
                  LEFT JOIN contacts end_contact ON end_addr.pubkey_hash = end_contact.pubkey_hash
                  LEFT JOIN addresses init_addr ON (t.initial_sender_address = init_addr.http OR t.initial_sender_address = init_addr.https OR t.initial_sender_address = init_addr.tor)
                  LEFT JOIN contacts init_contact ON init_addr.pubkey_hash = init_contact.pubkey_hash
                  LEFT JOIN p2p ON t.memo = p2p.hash
                  WHERE (t.sender_address IN ($placeholders) OR t.receiver_address IN ($placeholders))
                    AND (
                         LOWER(COALESCE(sender_contact.name, '')) LIKE ?
                      OR LOWER(COALESCE(receiver_contact.name, '')) LIKE ?
                      OR LOWER(COALESCE(end_contact.name, '')) LIKE ?
                      OR LOWER(COALESCE(init_contact.name, '')) LIKE ?
                      OR LOWER(COALESCE(t.description, '')) LIKE ?
                      OR LOWER(COALESCE(t.sender_address, '')) LIKE ?
                      OR LOWER(COALESCE(t.receiver_address, '')) LIKE ?
                      OR LOWER(COALESCE(t.end_recipient_address, '')) LIKE ?
                      OR LOWER(COALESCE(t.initial_sender_address, '')) LIKE ?
                      OR LOWER(COALESCE(t.txid, '')) LIKE ?
                    )";

        $additional = [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like];

        // status is a direct column filter
        if ($status !== null && $status !== '') {
            $query .= " AND t.status = ?";
            $additional[] = $status;
        }
        // tx_type: the client-side filter uses 'direct' / 'p2p' /
        // 'contact' labels. DB stores 'standard' / 'p2p' / 'contact' —
        // map 'direct' → '<'standard','direct'>' (historical
        // transactions may carry either), others pass through.
        if ($txType !== null && $txType !== '') {
            if ($txType === 'direct') {
                $query .= " AND t.tx_type IN ('standard', 'direct')";
            } else {
                $query .= " AND t.tx_type = ?";
                $additional[] = $txType;
            }
        }

        $query .= " ORDER BY COALESCE(t.time, 0) DESC, t.timestamp DESC LIMIT ?";
        $additional[] = max(1, $maxResults);

        // addresses twice for the two IN clauses, then the tail params
        $params = $this->buildInClauseParams($userAddresses, 2, $additional);
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::getInstance()->log('Failed to search transactions: ' . $e->getMessage(), 'WARNING');
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formatted = TransactionFormatter::formatHistoryMany($rows, $userAddresses);

        // Direction filter applied post-SQL because `direction` (sent /
        // received) is computed from the user's addresses in the
        // formatter — same pipeline the rest of the GUI uses.
        if ($direction !== null && $direction !== '') {
            $formatted = array_values(array_filter($formatted, function ($tx) use ($direction) {
                return ($tx['type'] ?? '') === $direction;
            }));
        }

        return $formatted;
    }

    /**
     * Check if Transaction exists by memo
     *
     * @param string $memo Transaction memo
     * @return bool True if exists
     */
    public function transactionExistsMemo(string $memo): bool {
        return $this->exists('memo', $memo);
    }

    /**
     * Get transaction by memo
     *
     * @param string $memo Transaction memo
     * @return array|null Transaction data or null
     */
    public function getByMemo(string $memo): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE memo = :memo";
        $stmt = $this->execute($query, [':memo' => $memo]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ? $this->mapRows($result) : null;
    }

    /**
     * Get transaction status by memo
     *
     * @param string $memo Transaction memo
     * @return string Transaction status or null
     */
    public function getStatusByMemo(string $memo): ?string {
        $query = "SELECT status FROM {$this->tableName} WHERE memo = :memo";
        $stmt = $this->execute($query, [':memo' => $memo]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Check if Transaction exists by txid
     *
     * @param string $txid Transaction txid
     * @return bool True if exists
     */
    public function transactionExistsTxid(string $txid): bool {
        return $this->exists('txid', $txid);
    }


    /**
     * Get transaction by txid
     *
     * @param string $txid Transaction ID
     * @return array|null Transaction data or null
     */
    public function getByTxid(string $txid): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE txid = :txid";
        $stmt = $this->execute($query, [':txid' => $txid]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchALL(PDO::FETCH_ASSOC);
        return $result ? $this->mapRows($result) : null;
    }

    /**
     * Get transaction status by txid
     *
     * @param string $txid Transaction txid
     * @return string Transaction status or null
     */
    public function getStatusByTxid(string $txid): ?string {
        $query = "SELECT status FROM {$this->tableName} WHERE txid = :txid";
        $stmt = $this->execute($query, [':txid' => $txid]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetchColumn();
        return $result ?: null;
    }

    /**
     * Insert a new transaction
     *
     * @param array $request Transaction request data
     * @param string $transType Transaction type (received, sent, relay)
     * @return string JSON response
     */
    public function insertTransaction(array $request, string $type): string {

        // Calculate public key hashes
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $request['senderPublicKey']);
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $request['receiverPublicKey']);

        // Determine transaction type
        // 'contact' for contact requests (amount=0), 'standard' for direct transactions, 'p2p' for p2p routing
        if ($request['memo'] === 'contact' || (isset($request['amount']) && $request['amount'] instanceof SplitAmount && $request['amount']->isZero())) {
            $txType = 'contact';
        } elseif ($request['memo'] === 'standard') {
            $txType = 'standard';
        } else {
            $txType = 'p2p';
        }
        $result = false;
        try{
            $this->beginTransaction();

            // Determine previous_txid:
            // 1. If explicitly provided in request (e.g., from sync), use that value
            // 2. Otherwise, look up from database (excluding cancelled/rejected)
            $previousTxid = null;
            if (array_key_exists('previousTxid', $request)) {
                // Use the provided previous_txid (from sync or explicit set)
                $previousTxid = $request['previousTxid'];
            } else {
                // Look up previous txid - per-currency chain separation
                // Each currency between a contact pair has its own independent chain
                // Chain must include cancelled/rejected transactions for proper linking
                $txCurrency = $request['currency'] ?? null;
                $query = "SELECT txid FROM {$this->tableName}
                        WHERE ((sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash)
                            OR (sender_public_key_hash = :second_receiver_public_key_hash AND receiver_public_key_hash = :second_sender_public_key_hash))";

                $inlineParams = [
                    ':sender_public_key_hash' => $senderPublicKeyHash,
                    ':receiver_public_key_hash' => $receiverPublicKeyHash,
                    ':second_receiver_public_key_hash' => $receiverPublicKeyHash,
                    ':second_sender_public_key_hash' => $senderPublicKeyHash
                ];

                if ($txCurrency !== null) {
                    $query .= " AND currency = :currency";
                    $inlineParams[':currency'] = $txCurrency;
                }

                $query .= " ORDER BY timestamp DESC LIMIT 1";

                $stmt = $this->execute($query, $inlineParams);
                $lookupResult = $stmt->fetch(PDO::FETCH_ASSOC);
                $previousTxid = $lookupResult ? $lookupResult['txid'] : null;
            }

            $amount = SplitAmount::from($request['amount']);
            $data = [
                'tx_type' => $txType,
                'type' => $type,
                'status' => $request['status'] ?? Constants::STATUS_PENDING, // Allow custom status, default to pending
                'sender_address' => $request['senderAddress'],
                'sender_public_key' => $request['senderPublicKey'],
                'sender_public_key_hash' => $senderPublicKeyHash,
                'receiver_address' => $request['receiverAddress'],
                'receiver_public_key' => $request['receiverPublicKey'],
                'receiver_public_key_hash' => $receiverPublicKeyHash,
                'amount_whole' => $amount->whole,
                'amount_frac' => $amount->frac,
                'currency' => $request['currency'],
                'txid' => $request['txid'],
                'previous_txid' => $previousTxid,
                'sender_signature' => $request['signature'] ?? null, // upon initial inserting a standard transaction in database of original sender it is null
                'recipient_signature' => $request['recipientSignature'] ?? null, // recipient's signature upon accepting
                'signature_nonce' => $request['nonce'] ?? $request['signatureNonce'] ?? null, // nonce from signed message (for verification)
                'time' => $request['time'] ?? null, // microtime used for P2P/RP2P hash or transaction creation
                'memo' => $request['memo'],
                'description' => $request['description'] ?? null,
                'signed_message_content' => $request['signedMessageContent'] ?? null
                // NOTE: end_recipient_address and initial_sender_address are NOT included here
                // They are local tracking fields added via updateTrackingFields() after insert
                // to avoid including them in the signed message (sync partners don't have this info)
            ];
            $result = $this->insert($data);
            $this->commit();
        } catch (PDOException $e) {
            $this->rollBack();
            Logger::getInstance()->log('Failed to insert transaction: ' . $e->getMessage(), 'ERROR');
        }
                

        if ($result !== false) {
            // Output silent logging if function exists
            if (function_exists('output')) {
                if ($request['memo'] !== "standard") {
                    if (function_exists('outputInsertedTransactionMemo')) {
                        output(outputInsertedTransactionMemo($request), 'SILENT');
                    }
                } else {
                    if (function_exists('outputInsertedTransactionTxid')) {
                        output(outputInsertedTransactionTxid($request), 'SILENT');
                    }
                }
            }
            return json_encode([
                "status" => Constants::STATUS_ACCEPTED,
                "txid" => $request['txid'],
                "message" => "Transaction recorded successfully"

            ]);
        } else {
            return json_encode([
                "status" => Constants::STATUS_REJECTED,
                "txid" => $request['txid'],
                "message" => "Failed to record transaction"
            ]);
        }
    }

    /**
     * Update timestamp for a transaction
     *
     * @param string $txid Transaction ID
     * @return bool True if update was successful
     */
    /**
     * Update the signed-payload microtime (`time`) for a transaction.
     * Used when refreshing a DLQ entry before retry so the payload carries a
     * current timestamp instead of the stale one from the original send.
     */
    public function updateTime(string $txid, int $microtime): bool {
        $affectedRows = $this->update(
            ['time' => $microtime],
            'txid',
            $txid
        );
        return $affectedRows >= 0;
    }

    public function updateTimestamp(string $txid): bool {
        $affectedRows = $this->update(
            ['timestamp' => date('Y-m-d H:i:s.u')],
            'txid',
            $txid
        );

        return $affectedRows >= 0;
    }

    /**
     * Update transaction status
     *
     * @param string $identifier Transaction memo or txid
     * @param string $status New status
     * @param bool $isTxid True if identifier is txid, false if memo
     * @return bool Success status
     */
    public function updateStatus(string $identifier, string $status, bool $isTxid = false): bool {
        $column = $isTxid ? 'txid' : 'memo';
        $affectedRows = $this->update(['status' => $status], $column, $identifier);

        // Output silent logging if function exists
        if (function_exists('output') && function_exists('outputTransactionStatusUpdated')) {
            $typeTransaction = $isTxid ? 'txid' : 'hash';
            output(outputTransactionStatusUpdated($status, $typeTransaction, $identifier), 'SILENT');
        }

        return $affectedRows >= 0;
    }

    /**
     * Cancel only 'pending' transactions matching a memo/hash.
     *
     * Used by CleanupService when a P2P expires: transactions already in-flight
     * (sending/sent/accepted) are left alone — they have their own expires_at
     * deadline and should be allowed to complete naturally.
     *
     * @param string $memo P2P hash / transaction memo
     * @return bool True on success
     */
    public function cancelPendingByMemo(string $memo): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = :cancelled
                  WHERE memo = :memo
                  AND status = :pending";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':cancelled', Constants::STATUS_CANCELLED);
        $stmt->bindValue(':memo', $memo);
        $stmt->bindValue(':pending', Constants::STATUS_PENDING);
        try {
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            $this->logError("Failed to cancel pending transactions by memo", $e);
            return false;
        }
    }

    /**
     * Get transactions that have passed their expires_at deadline and are still in
     * a non-final state (pending/sending/sent).
     *
     * @return array Array of expired transaction records
     */
    public function getExpiredTransactions(): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE expires_at IS NOT NULL
                  AND expires_at < NOW()
                  AND status NOT IN ('completed', 'rejected', 'cancelled', 'failed')
                  ORDER BY expires_at ASC";
        $stmt = $this->pdo->prepare($query);
        try {
            $stmt->execute();
            return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve expired transactions", $e);
            return [];
        }
    }

    /**
     * Set the expires_at delivery deadline for a transaction.
     *
     * @param string $txid Transaction ID
     * @param string $expiresAt DATETIME(6) string (e.g. date('Y-m-d H:i:s', ...))
     * @return bool Success status
     */
    public function setExpiresAt(string $txid, string $expiresAt): bool {
        $affectedRows = $this->update(['expires_at' => $expiresAt], 'txid', $txid);
        return $affectedRows >= 0;
    }

    /**
     * Update transaction description
     *
     * @param string $identifier Transaction memo or txid
     * @param string $description Description
     * @param bool $isTxid True if identifier is txid, false if memo
     * @return bool Success status
     */
    public function updateDescription(string $identifier, string $description, bool $isTxid = false): bool {
        $column = $isTxid ? 'txid' : 'memo';
        $affectedRows = $this->update(['description' => $description], $column, $identifier);

        // Output silent logging if function exists
        if (function_exists('output') && function_exists('outputTransactionDescriptionUpdated')) {
            $typeTransaction = $isTxid ? 'txid' : 'hash';
            output(outputTransactionDescriptionUpdated($description, $typeTransaction, $identifier), 'SILENT');
        }

        return $affectedRows >= 0;
    }

    /**
     * Update end recipient address
     *
     * @param string $identifier Transaction memo or txid
     * @param string $address End recipient address
     * @param bool $isTxid True if identifier is txid, false if memo
     * @return bool Success status
     */
    public function updateEndRecipientAddress(string $identifier, string $address, bool $isTxid = false): bool {
        $column = $isTxid ? 'txid' : 'memo';
        $affectedRows = $this->update(['end_recipient_address' => $address], $column, $identifier);
        return $affectedRows >= 0;
    }

    /**
     * Update initial sender address
     *
     * @param string $identifier Transaction memo or txid
     * @param string $address Initial sender address
     * @param bool $isTxid True if identifier is txid, false if memo
     * @return bool Success status
     */
    public function updateInitialSenderAddress(string $identifier, string $address, bool $isTxid = false): bool {
        $column = $isTxid ? 'txid' : 'memo';
        $affectedRows = $this->update(['initial_sender_address' => $address], $column, $identifier);
        return $affectedRows >= 0;
    }

    /**
     * Get transactions by status
     *
     * @param string $status Transaction status
     * @param int $limit Maximum number of transactions
     * @return array Array of transactions
     */
    public function getByStatus(string $status, int $limit = 0): array {
        return $this->findManyByColumn('status', $status, $limit);
    }

    /**
     * Get transactions between two addresses
     *
     * @param string $address1 First address
     * @param string $address2 Second address
     * @param int $limit Maximum number of transactions
     * @return array Array of transactions
     */
    public function getTransactionsBetweenAddresses(string $address1, string $address2, int $limit = 0): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE (sender_address = :address1 AND receiver_address = :address2)
                     OR (sender_address = :address3 AND receiver_address = :address4)
                  ORDER BY timestamp DESC";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':address1', $address1);
        $stmt->bindValue(':address2', $address2);
        $stmt->bindValue(':address3', $address2);
        $stmt->bindValue(':address4', $address1);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve transactions between addresses", $e);
            return [];
        }
    }

     /**
     * Get transactions between two pubkeys
     *
     * @param string $pubkey1 First pubkey
     * @param string $pubkey2 Second pubkey
     * @param int $limit Maximum number of transactions
     * @return array Array of transactions
     */
    public function getTransactionsBetweenPubkeys(string $pubkey1, string $pubkey2, int $limit = 0): array {
        // Who sent or received does not matter, we check both directions
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $pubkey1);
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $pubkey2);

        // NOTE: Do NOT filter by status here - sync needs ALL transactions including
        // cancelled/rejected to preserve chain integrity for proper linking.
        $query = "SELECT * FROM {$this->tableName}
                  WHERE ((sender_public_key_hash = :sender_pubkey_hash1 AND receiver_public_key_hash = :receiver_pubkey_hash1)
                     OR (sender_public_key_hash = :receiver_pubkey_hash2 AND receiver_public_key_hash = :sender_pubkey_hash2))
                  ORDER BY timestamp DESC";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':sender_pubkey_hash1', $senderPublicKeyHash);
        $stmt->bindValue(':receiver_pubkey_hash1', $receiverPublicKeyHash);
        $stmt->bindValue(':receiver_pubkey_hash2', $receiverPublicKeyHash);
        $stmt->bindValue(':sender_pubkey_hash2', $senderPublicKeyHash);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve transactions between pubkeys", $e);
            return [];
        }
    }

    /**
     * Get non-contact transactions between two pubkeys
     *
     * Same as getTransactionsBetweenPubkeys but excludes contact transactions
     * (tx_type='contact'). Used to detect prior transaction history without
     * false positives from the contact request transaction itself.
     *
     * @param string $pubkey1 First pubkey
     * @param string $pubkey2 Second pubkey
     * @param int $limit Max results (0 = unlimited)
     * @return array Transaction records
     */
    public function getNonContactTransactionsBetweenPubkeys(string $pubkey1, string $pubkey2, int $limit = 0): array {
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $pubkey1);
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $pubkey2);

        $query = "SELECT * FROM {$this->tableName}
                  WHERE tx_type != 'contact'
                  AND ((sender_public_key_hash = :sender_pubkey_hash1 AND receiver_public_key_hash = :receiver_pubkey_hash1)
                     OR (sender_public_key_hash = :receiver_pubkey_hash2 AND receiver_public_key_hash = :sender_pubkey_hash2))
                  ORDER BY timestamp DESC";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':sender_pubkey_hash1', $senderPublicKeyHash);
        $stmt->bindValue(':receiver_pubkey_hash1', $receiverPublicKeyHash);
        $stmt->bindValue(':receiver_pubkey_hash2', $receiverPublicKeyHash);
        $stmt->bindValue(':sender_pubkey_hash2', $senderPublicKeyHash);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve non-contact transactions between pubkeys", $e);
            return [];
        }
    }

    /**
     * Get timestamp column Tranasactions (for ordering check)
     *
     * @return array Timestamp column
     */
    public function getTimestampsTransactions(int $limit = Constants::DISPLAY_RECENT_TRANSACTIONS_LIMIT): array {
        $query = "SELECT timestamp FROM {$this->tableName}
                  ORDER BY timestamp DESC LIMIT ?";
        $stmt = $this->pdo->prepare($query);
        try {
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve transactions timestamps", $e);
            return [];
        }
    }

    /**
     * Update signature data for a transaction
     *
     * Updates the sender_signature, signature_nonce, and optionally signed_message_content
     * fields for a transaction. This is used after a transaction is successfully sent to
     * store the signing data for future verification during sync operations.
     *
     * For E2E encrypted transactions, $signedMessageContent contains the raw signed JSON
     * (which includes the encrypted block). Without this, signature verification during
     * sync would fail because reconstruction from plaintext DB fields produces a different
     * message than what was originally signed.
     *
     * @param string $txid Transaction ID
     * @param string $signature Base64-encoded signature
     * @param string $nonce Signature nonce
     * @param string|null $signedMessageContent Raw signed JSON for E2E encrypted transactions
     * @return bool Success status
     */
    public function updateSignatureData(string $txid, string $signature, string $nonce, ?string $signedMessageContent = null): bool {
        $data = [
            'sender_signature' => $signature,
            'signature_nonce' => $nonce
        ];
        if ($signedMessageContent !== null) {
            $data['signed_message_content'] = $signedMessageContent;
        }
        $affectedRows = $this->update($data, 'txid', $txid);
        return $affectedRows >= 0;
    }

    /**
     * Update recipient signature for a transaction
     *
     * Stores the recipient's signature that was received in the acceptance response.
     * This signature authenticates that the recipient actually accepted the transaction
     * and can be verified during sync operations.
     *
     * @param string $txid Transaction ID
     * @param string $signature Base64-encoded recipient signature
     * @return bool Success status
     */
    public function updateRecipientSignature(string $txid, string $signature): bool {
        $affectedRows = $this->update([
            'recipient_signature' => $signature
        ], 'txid', $txid);
        return $affectedRows >= 0;
    }

    /**
     * Update tracking fields for a transaction after insert
     *
     * These fields (end_recipient_address, initial_sender_address) are local tracking
     * information that should NOT be included in the signed message payload.
     * They are added after the transaction is inserted to keep them separate from
     * the data that gets signed and verified during sync.
     *
     * @param string $txid Transaction ID
     * @param string|null $endRecipientAddress Final recipient address (for P2P chains)
     * @param string|null $initialSenderAddress Original sender address (for P2P chains)
     * @return bool True on success
     */
    public function updateTrackingFields(string $txid, ?string $endRecipientAddress, ?string $initialSenderAddress): bool {
        $updates = [];

        if ($endRecipientAddress !== null) {
            $updates['end_recipient_address'] = $endRecipientAddress;
        }

        if ($initialSenderAddress !== null) {
            $updates['initial_sender_address'] = $initialSenderAddress;
        }

        if (empty($updates)) {
            return true; // Nothing to update
        }

        $affectedRows = $this->update($updates, 'txid', $txid);
        return $affectedRows >= 0;
    }

}
