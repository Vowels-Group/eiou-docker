<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/AbstractRepository.php';
require_once __DIR__ . '/../formatters/TransactionFormatter.php';
require_once __DIR__ . '/traits/QueryHelper.php';

/**
 * Transaction Repository
 *
 * Manages all database interactions for the transactions table.
 *
 * @package Database\Repository
 *
 * NOTE: Many methods contain `if (!$stmt)` checks after execute(). While PDO is
 * configured with ERRMODE_EXCEPTION, the AbstractRepository::execute() method
 * catches PDOException and returns false (see AbstractRepository.php lines 147-160).
 * These checks ARE required to handle query failures gracefully. To remove them,
 * AbstractRepository::execute() would need to be refactored to throw exceptions
 * instead of returning false, and all callers updated to use try/catch.
 *
 * @see AbstractRepository::execute() for the error handling implementation
 */
class TransactionRepository extends AbstractRepository {
    use QueryHelper;

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
     * Get previous transaction ID between two parties
     *
     * Excludes expired and cancelled transactions from the chain.
     * When finding the previous txid for a new transaction, we only
     * consider active transactions to maintain chain integrity.
     *
     * @param string $senderPublicKey Sender's public key
     * @param string $receiverPublicKey Receiver's public key
     * @param string|null $excludeTxid Optional txid to exclude from the query
     * @return string|null Previous txid or null
     */
    public function getPreviousTxid(string $senderPublicKey, string $receiverPublicKey, ?string $excludeTxid = null): ?string {
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $receiverPublicKey);

        // NOTE: Do NOT filter by status here - chain integrity requires ALL transactions
        // to be included, even cancelled/rejected ones. The previous_txid must point to
        // the actual last transaction in the chain for proper linking and sync.
        $query = "SELECT txid FROM {$this->tableName}
                WHERE ((sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash)
                    OR (sender_public_key_hash = :second_receiver_public_key_hash AND receiver_public_key_hash = :second_sender_public_key_hash))";

        $params = [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash,
            ':second_receiver_public_key_hash' => $receiverPublicKeyHash,
            ':second_sender_public_key_hash' => $senderPublicKeyHash
        ];

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
     * Get contact balance (optimized single query)
     *
     * @param string $userPubkey
     * @param string $contactPubkey
     * @return int Balance in cents
     */
    public function getContactBalance(string $userPubkey, string $contactPubkey): int
    {
        $userHash = hash(Constants::HASH_ALGORITHM, $userPubkey);
        $contactHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);

        // Calculate sent to this contact
        $query = "SELECT COALESCE(SUM(amount), 0) as sent FROM {$this->tableName} WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ?";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$userHash, $contactHash]);
        if(!$stmt){
            return 0;
        }
        $sent = $stmt->fetch(PDO::FETCH_ASSOC)['sent'];

        // Calculate received from this contact
        $query = "SELECT COALESCE(SUM(amount), 0) as received FROM {$this->tableName} WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ?";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([$contactHash, $userHash]);
        if(!$stmt){
            return 0;
        }
        $received = $stmt->fetch(PDO::FETCH_ASSOC)['received'];
        return $received - $sent;     
    }

    
    /**
     * Get all contact balances in a single optimized query (fixes N+1 problem)
     *
     * @param string $userPubkey
     * @param array $contactPubkeys
     * @return array Associative array of pubkey => balance
     */
    public function getAllContactBalances(string $userPubkey, array $contactPubkeys): array
    {
        if (empty($contactPubkeys)) {
            return [];
        }

        $userHash = hash(Constants::HASH_ALGORITHM, $userPubkey);
        $contactHashes = array_map(function ($pubkey) {
            return hash(Constants::HASH_ALGORITHM, $pubkey);
        }, $contactPubkeys);

        // Create a mapping of hash to pubkey for later lookup
        $hashToPubkey = array_combine($contactHashes, $contactPubkeys);

        // Build placeholders for IN clause
        $placeholders = $this->createPlaceholders($contactHashes);

        // Single query to get all balances using UNION
        $query = "
            SELECT
                contact_hash,
                SUM(sent) as total_sent,
                SUM(received) as total_received
            FROM (
                -- Sent from user to contacts
                SELECT
                    receiver_public_key_hash as contact_hash,
                    SUM(amount) as sent,
                    0 as received
                FROM {$this->tableName}
                WHERE sender_public_key_hash = ?
                    AND receiver_public_key_hash IN ($placeholders)
                GROUP BY receiver_public_key_hash

                UNION ALL

                -- Received by user from contacts
                SELECT
                    sender_public_key_hash as contact_hash,
                    0 as sent,
                    SUM(amount) as received
                FROM {$this->tableName}
                WHERE receiver_public_key_hash = ?
                    AND sender_public_key_hash IN ($placeholders)
                GROUP BY sender_public_key_hash
            ) as balance_calc
            GROUP BY contact_hash
        ";

        // Prepare parameters: userHash, contactHashes, userHash, contactHashes
        $params = array_merge([$userHash], $contactHashes, [$userHash], $contactHashes);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if(!$stmt){
            return array_fill_keys($contactPubkeys, 0);
        }

        // Build result array indexed by original pubkey
        $balances = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pubkey = $hashToPubkey[$row['contact_hash']] ?? null;
            if ($pubkey) {
                $balances[$pubkey] = $row['total_received'] - $row['total_sent'];
            }
        }

        // Ensure all contacts have a balance entry (default to 0)
        foreach ($contactPubkeys as $pubkey) {
            if (!isset($balances[$pubkey])) {
                $balances[$pubkey] = 0;
            }
        }
        return $balances;
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

        $params = $this->buildInClauseParams($userAddresses, 2, [date(Constants::DISPLAY_DATE_FORMAT, $lastCheckTime)]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if(!$stmt){
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
    public function getTransactionsByType(string $type): array
    {
        $allTransactions = $this->getAllTransactions();
        $filtered = [];

        foreach ($allTransactions as $transaction) {
            if ($transaction['type'] === $type) {
                $filtered[] = $transaction;
            }
        }

        return $filtered;
    }

    /**
     * Get recent transactions
     *
     * @param int $limit
     * @return array
     */
    public function getRecentTransactions(int $limit = 5): array
    {
        return $this->getTransactionHistory($limit);
    }

    /**
     * Get all transactions
     *
     * @return array
     */
    public function getAllTransactions(): array
    {
        return $this->getTransactionHistory(PHP_INT_MAX); // Get a large number
    }
    
    /**
     * Get all transactions (subsetted on currency)
     *
     * @param string $currency Currency of transaction
     * @return array
     */
    public function getAllTransactionsCurrency(string $currency): array
    {
        return $this->getTransactionHistory(PHP_INT_MAX, $currency); // Get a large number
    }


    /**
     * Get all sent transactions
     *
     * @return array
     */
    public function getAllSentUserTransactions(): array
    {
        return $this->getSentUserTransactions(PHP_INT_MAX); // Get a large number
    }

    /**
     * Get all sent transactions (subsetted on currency)
     *
     * @param string $currency Currency of transaction
     * @return array
     */
    public function getAllSentUserTransactionsCurrency(string $currency): array
    {
        return $this->getSentUserTransactions(PHP_INT_MAX, $currency); // Get a large number
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

        $query = "SELECT receiver_address, amount, currency, timestamp FROM transactions
                    WHERE sender_address IN ($placeholders)";

        if ($currency !== null) {
            $query .= " AND currency = ?";
            $params = $this->buildInClauseParams($userAddresses, 1, [$currency, $limit]);
        } else {
            $params = $this->buildInClauseParams($userAddresses, 1, [$limit]);
        }

        $query .= " ORDER BY timestamp DESC LIMIT ?";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_SENT, 'receiver_address');
    }

    /**
     * Get transactions sent by user (subsetted on currency)
     *
     * @deprecated Use getSentUserTransactions($limit, $currency) instead
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactionsCurrency(string $currency, int $limit = 10): array {
        return $this->getSentUserTransactions($limit, $currency);
    }

    /**
     * Get all received transactions
     *
     * @return array
     */
    public function getAllReceivedUserTransactions(): array
    {
        return $this->getReceivedUserTransactions(PHP_INT_MAX); // Get a large number
    }

    /**
     * Get all received transactions (subsetted on currency)
     *
     * @param string $currency Currency of transaction
     * @return array
     */
    public function getAllReceivedUserTransactionsCurrency(string $currency): array
    {
        return $this->getReceivedUserTransactions(PHP_INT_MAX, $currency); // Get a large number
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

        $query = "SELECT sender_address, amount, currency, timestamp FROM transactions
                    WHERE receiver_address IN ($placeholders)";

        if ($currency !== null) {
            $query .= " AND currency = ?";
            $params = $this->buildInClauseParams($userAddresses, 1, [$currency, $limit]);
        } else {
            $params = $this->buildInClauseParams($userAddresses, 1, [$limit]);
        }

        $query .= " ORDER BY timestamp DESC LIMIT ?";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_RECEIVED, 'sender_address');
    }

    /**
     * Get transactions received by user (subsetted on currency)
     *
     * @deprecated Use getReceivedUserTransactions($limit, $currency) instead
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactionsCurrency(string $currency, int $limit = 10): array{
        return $this->getReceivedUserTransactions($limit, $currency);
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

        $query = "SELECT sender_address, amount, currency, timestamp FROM transactions
                    WHERE receiver_address IN ($placeholders) AND sender_address = ?";

        $additionalParams = [$senderAddress];
        if ($currency !== null) {
            $query .= " AND currency = ?";
            $additionalParams[] = $currency;
        }

        $query .= " ORDER BY timestamp DESC LIMIT ?";
        $additionalParams[] = $limit;

        $params = $this->buildInClauseParams($userAddresses, 1, $additionalParams);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_RECEIVED, 'sender_address');
    }

    /**
     * Get transactions received by user from specific address (subsetted on currency)
     *
     * @deprecated Use getReceivedUserTransactionsAddress($senderAddress, $limit, $currency) instead
     * @param string $senderAddress Address of transaction sender
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactionsAddressCurrency(string $senderAddress, string $currency, int $limit = 10): array{
        return $this->getReceivedUserTransactionsAddress($senderAddress, $limit, $currency);
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

        $query = "SELECT receiver_address, amount, currency, timestamp FROM transactions
                    WHERE sender_address IN ($placeholders) AND receiver_address = ?";

        $additionalParams = [$receiverAddress];
        if ($currency !== null) {
            $query .= " AND currency = ?";
            $additionalParams[] = $currency;
        }

        $query .= " ORDER BY timestamp DESC LIMIT ?";
        $additionalParams[] = $limit;

        $params = $this->buildInClauseParams($userAddresses, 1, $additionalParams);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatSimpleMany($transactions, Constants::TX_TYPE_SENT, 'receiver_address');
    }

    /**
     * Get transactions sent by user to specific address (subsetted on currency)
     *
     * @deprecated Use getSentUserTransactionsAddress($receiverAddress, $limit, $currency) instead
     * @param string $receiverAddress Address of transaction receiver
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactionsAddressCurrency(string $receiverAddress, string $currency, int $limit = 10): array {
        return $this->getSentUserTransactionsAddress($receiverAddress, $limit, $currency);
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
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $transactions ?: [];
    }

    /**
     * Get transaction history with limit
     *
     * @param int $limit Maximum number of transactions to return
     * @param string|null $currency Optional currency filter
     * @return array
     */
    public function getTransactionHistory(int $limit = 10, ?string $currency = null): array
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
                    t.receiver_public_key,
                    t.amount,
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
                    p2p.amount AS p2p_amount,
                    p2p.my_fee_amount AS p2p_fee
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

        $query .= " ORDER BY COALESCE(t.time, 0) DESC, t.timestamp DESC LIMIT ?";
        $additionalParams[] = $limit;

        // Bind parameters - addresses twice for both IN clauses, then optional currency, then limit
        $params = $this->buildInClauseParams($userAddresses, 2, $additionalParams);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatHistoryMany($transactions, $userAddresses);
    }

     /**
     * Get transaction history with limit (subsetted on currency)
     *
     * @deprecated Use getTransactionHistory($limit, $currency) instead
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getTransactionHistoryCurrency(string $currency, int $limit = 10): array
    {
        return $this->getTransactionHistory($limit, $currency);
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
        return $result ?: null;
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
        return $result ?: null;
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
        if ($request['memo'] === 'contact' || (isset($request['amount']) && $request['amount'] == 0)) {
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
                // Look up previous txid - include ALL transactions for chain integrity
                // Chain must include cancelled/rejected transactions for proper linking
                // Order by timestamp (database insertion time) for chain ordering
                $query = "SELECT txid FROM {$this->tableName}
                        WHERE ((sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash)
                            OR (sender_public_key_hash = :second_receiver_public_key_hash AND receiver_public_key_hash = :second_sender_public_key_hash))
                        ORDER BY timestamp DESC LIMIT 1";

                $stmt = $this->execute($query, [
                    ':sender_public_key_hash' => $senderPublicKeyHash,
                    ':receiver_public_key_hash' => $receiverPublicKeyHash,
                    ':second_receiver_public_key_hash' => $receiverPublicKeyHash,
                    ':second_sender_public_key_hash' => $senderPublicKeyHash
                ]);
                $lookupResult = $stmt->fetch(PDO::FETCH_ASSOC);
                $previousTxid = $lookupResult ? $lookupResult['txid'] : null;
            }

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
                'amount' => $request['amount'],
                'currency' => $request['currency'],
                'txid' => $request['txid'],
                'previous_txid' => $previousTxid,
                'sender_signature' => $request['signature'] ?? null, // upon initial inserting a standard transaction in database of original sender it is null
                'recipient_signature' => $request['recipientSignature'] ?? null, // recipient's signature upon accepting
                'signature_nonce' => $request['nonce'] ?? $request['signatureNonce'] ?? null, // nonce from signed message (for verification)
                'time' => $request['time'] ?? null, // microtime used for P2P/RP2P hash or transaction creation
                'memo' => $request['memo'],
                'description' => $request['description'] ?? null
                // NOTE: end_recipient_address and initial_sender_address are NOT included here
                // They are local tracking fields added via updateTrackingFields() after insert
                // to avoid including them in the signed message (sync partners don't have this info)
            ];
            $result = $this->insert($data);
            $this->commit();
        } catch (PDOException $e) {
            $this->rollBack();
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
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve transactions between pubkeys", $e);
            return [];
        }
    }

    /**
     * Get timestamp column Tranasactions (for ordering check)
     *
     * @return array Timestamp column
     */
    public function getTimestampsTransactions(int $limit = 5): array {
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
     * Get recent transactions with a specific contact by their addresses
     *
     * @param array $contactAddresses Array of contact addresses (http, https, tor, etc.)
     * @param int $limit Maximum number of transactions to return
     * @return array Formatted transactions with contact
     */
    public function getTransactionsWithContact(array $contactAddresses, int $limit = 5): array {
        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null || empty($contactAddresses)) {
            return [];
        }

        // Filter out empty addresses
        $contactAddresses = array_filter($contactAddresses);
        if (empty($contactAddresses)) {
            return [];
        }

        // Create placeholders for IN clauses
        $userPlaceholders = $this->createPlaceholders($userAddresses);
        $contactPlaceholders = $this->createPlaceholders($contactAddresses);

        // Get transactions where user sent to contact OR user received from contact
        $query = "SELECT txid, tx_type, status, sender_address, receiver_address, amount, currency, timestamp, memo, description
                  FROM {$this->tableName}
                  WHERE (sender_address IN ($userPlaceholders) AND receiver_address IN ($contactPlaceholders))
                     OR (sender_address IN ($contactPlaceholders) AND receiver_address IN ($userPlaceholders))
                  ORDER BY timestamp DESC LIMIT ?";

        // Build params: user addresses, contact addresses, contact addresses, user addresses, limit
        $params = array_merge(
            $userAddresses,
            $contactAddresses,
            $contactAddresses,
            $userAddresses,
            [$limit]
        );

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        if (!$stmt) {
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatContactMany($transactions, $userAddresses);
    }

    /**
     * Check if a contact transaction exists for a given receiver public key hash
     *
     * Used to prevent duplicate contact transactions when re-adding a deleted contact.
     *
     * @param string $receiverPublicKeyHash The hash of the receiver's public key
     * @return bool True if a contact transaction exists
     */
    public function contactTransactionExistsForReceiver(string $receiverPublicKeyHash): bool {
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());

        $query = "SELECT 1 FROM {$this->tableName}
                  WHERE tx_type = 'contact'
                  AND sender_public_key_hash = :sender_public_key_hash
                  AND receiver_public_key_hash = :receiver_public_key_hash
                  LIMIT 1";

        $stmt = $this->execute($query, [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Update contact transaction status to completed when contact is accepted
     *
     * Called when receiving an 'accepted' status in handleContactMessageRequest.
     * Updates the contact transaction from 'sent' to 'completed'.
     *
     * @param string $contactPublicKey The public key of the contact who accepted
     * @return bool True if update was successful
     */
    public function completeContactTransaction(string $contactPublicKey): bool {
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);

        $query = "UPDATE {$this->tableName}
                  SET status = 'completed'
                  WHERE tx_type = 'contact'
                  AND sender_public_key_hash = :sender_public_key_hash
                  AND receiver_public_key_hash = :receiver_public_key_hash
                  AND status = 'sent'";

        $stmt = $this->execute($query, [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Update received contact transaction status to completed when user accepts the request
     *
     * Called from the receiver's perspective when they accept a contact request.
     * Updates the contact transaction from 'accepted' to 'completed'.
     * The sender (contact who sent the request) is the sender_public_key_hash,
     * and the current user (receiver) is the receiver_public_key_hash.
     *
     * @param string $senderPublicKey The public key of the contact who sent the request
     * @return bool True if update was successful
     */
    public function completeReceivedContactTransaction(string $senderPublicKey): bool {
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());

        $query = "UPDATE {$this->tableName}
                  SET status = 'completed'
                  WHERE tx_type = 'contact'
                  AND sender_public_key_hash = :sender_public_key_hash
                  AND receiver_public_key_hash = :receiver_public_key_hash
                  AND status = 'accepted'";

        $stmt = $this->execute($query, [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Update signature data for a transaction
     *
     * Updates the sender_signature and signature_nonce fields for a transaction.
     * This is used after a transaction is successfully sent to store the signing
     * data for future verification during sync operations.
     *
     * @param string $txid Transaction ID
     * @param string $signature Base64-encoded signature
     * @param int $nonce Signature nonce (unix timestamp)
     * @return bool Success status
     */
    public function updateSignatureData(string $txid, string $signature, int $nonce): bool {
        $affectedRows = $this->update([
            'sender_signature' => $signature,
            'signature_nonce' => $nonce
        ], 'txid', $txid);
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
