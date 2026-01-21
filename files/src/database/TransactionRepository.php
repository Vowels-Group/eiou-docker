<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Transaction Repository
 *
 * Manages all database interactions for the transactions table.
 *
 * @package Database\Repository
 *
 * TECH DEBT: Many methods contain redundant `if (!$stmt)` checks after execute().
 * Since PDO is configured with ERRMODE_EXCEPTION (see Pdo.php), the execute() method
 * will throw an exception on failure rather than returning false. These checks are
 * vestigial from defensive coding practices but can be safely removed in a future
 * refactoring pass. The checks don't cause harm, just unnecessary branches.
 */
class TransactionRepository extends AbstractRepository {
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
     * Calculate total amount sent to a public key
     *
     * @param string $publicKey Public key of contact
     * @return float Total amount sent
     */
    public function calculateTotalSentToContact(string $publicKey): float {
        $query = "SELECT SUM(amount) as total_sent FROM {$this->tableName}
                  WHERE receiver_public_key = :publicKey";
        $stmt = $this->execute($query, [':publicKey' => $publicKey]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['total_sent'] ?? 0);
    }

    /**
     * Calculate total amount sent by user
     *
     * @param string $userPublicKey User's public key
     * @return float Total amount sent by user
     */
    public function calculateTotalSentByUser(string $publicKey): float {
        $query = "SELECT SUM(amount) as total_sent FROM {$this->tableName}
                  WHERE sender_public_key = :publicKey";
        $stmt = $this->execute($query, [':publicKey' => $publicKey]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['total_sent'] ?? 0);
    }

    /**
     * Calculate total amount received from a public key
     *
     * @param string $publicKey Public key of contact
     * @return float Total amount received
     */
    public function calculateTotalReceivedFromContact(string $publicKey): float {
        $query = "SELECT SUM(amount) as total_received FROM {$this->tableName}
                  WHERE sender_public_key = :publicKey";
        $stmt = $this->execute($query, [':publicKey' => $publicKey]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['total_received'] ?? 0);
    }

    /**
     * Calculate total amount received by user (excluding self-sends)
     *
     * @param string $userPublicKey User's public key
     * @return float Total amount received by user
     */
    public function calculateTotalReceivedByUser(string $publicKey): float {
        $query = "SELECT SUM(amount) as total_received FROM {$this->tableName}
                  WHERE receiver_public_key = :publicKey
                  AND sender_public_key != :publicKey";
        $stmt = $this->execute($query, [':publicKey' => $publicKey]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['total_received'] ?? 0);
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
        $userHash = hash(Constants::HASH_ALGORITHM, $userPubkey);
        $contactHashes = array_map(function($pubkey) {
            return hash(Constants::HASH_ALGORITHM, $pubkey);
        }, $contactPubkeys);

        // Create a mapping of hash to pubkey for later lookup
        $hashToPubkey = array_combine($contactHashes, $contactPubkeys);

        // Build placeholders for IN clause
        $placeholders = str_repeat('?,', count($contactHashes) - 1) . '?';

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
     * Get users current balance
     *
     * @return string Balance 
     */
    function getUserTotalBalance() {
        $app = Application::getInstance();
        $currencyUtility = $app->utilityServices->getCurrencyUtility();
        $totalReceived = $this->calculateTotalReceivedByUser($this->currentUser->getPublicKey());
        $totalSent = $this->calculateTotalSentByUser($this->currentUser->getPublicKey());
        $balance = (string) $currencyUtility->convertCentsToDollars($totalReceived - $totalSent);
        return $balance ?? "0.00";
    }

    /**
     * Check for new transactions since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewTransactions(int $lastCheckTime): bool
    {
        $userAddresses = $this->currentUser->getUserAddresses();

        if (empty($userAddresses)) {
            return false;
        }

        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT COUNT(*) as count FROM {$this->tableName}
                    WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                    AND timestamp > ?";

        // Bind parameters - addresses twice for both IN clauses, then timestamp
        $params = array_merge($userAddresses, $userAddresses, [date(Constants::DISPLAY_DATE_FORMAT, $lastCheckTime)]);
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
        return $this->getTransactionHistoryCurrency($currency, PHP_INT_MAX); // Get a large number
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
        return $this->getSentUserTransactionsCurrency($currency, PHP_INT_MAX); // Get a large number
    }

    /**
     * Get transactions sent by user
     *
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactions(int $limit = 10): array {
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT receiver_address, amount, currency, timestamp FROM transactions 
                    WHERE sender_address IN ($placeholders) 
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
       
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_SENT,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['receiver_address']
            ];
        }
        return $formattedTransactions;
    }

    /**
     * Get transactions sent by user (subsetted on currency)
     *
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactionsCurrency(string $currency, int $limit = 10): array {
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT receiver_address, amount, currency, timestamp FROM transactions 
                    WHERE sender_address IN ($placeholders) AND currency = ?
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$currency, $limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
       
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_SENT,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['receiver_address']
            ];
        }
        return $formattedTransactions;
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
        return $this->getReceivedUserTransactionsCurrency($currency, PHP_INT_MAX); // Get a large number
    }

    /**
     * Get transactions received by user
     *
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactions(int $limit = 10): array{
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT sender_address, amount, currency, timestamp FROM transactions 
                    WHERE receiver_address IN ($placeholders) 
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_RECEIVED,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['sender_address']
            ];
        }
        return $formattedTransactions;
    }

    /**
     * Get transactions received by user (subsetted on currency)
     *
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactionsCurrency(string $currency, int $limit = 10): array{
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT sender_address, amount, currency, timestamp FROM transactions 
                    WHERE receiver_address IN ($placeholders) AND currency = ?
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$currency, $limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_RECEIVED,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['sender_address']
            ];
        }
        return $formattedTransactions;
    }


    /**
     * Get transactions received by user from specific address
     *
     * @param string $senderAddress Address of transaction sender
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactionsAddress(string $senderAddress, int $limit = 10): array{
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT sender_address, amount, currency, timestamp FROM transactions 
                    WHERE receiver_address IN ($placeholders) AND sender_address = ?
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$senderAddress], [$limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_RECEIVED,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['sender_address']
            ];
        }
        return $formattedTransactions;
    }

    /**
     * Get transactions received by user from specific address (subsetted on currency)
     *
     * @param string $senderAddress Address of transaction sender
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactionsAddressCurrency(string $senderAddress, string $currency, int $limit = 10): array{
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT sender_address, amount, currency, timestamp FROM transactions 
                    WHERE receiver_address IN ($placeholders) AND sender_address = ?
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$senderAddress], [$limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_RECEIVED,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['sender_address']
            ];
        }
        return $formattedTransactions;
    }




    /**
     * Get transactions sent by user to specific address 
     *
     * @param string $senderAddress Address of transaction sender
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactionsAddress(string $senderAddress, int $limit = 10): array {
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT receiver_address, amount, currency, timestamp FROM transactions 
                    WHERE sender_address IN ($placeholders) AND receiver_address = ?
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$senderAddress, $limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
       
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_SENT,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['receiver_address']
            ];
        }
        return $formattedTransactions;
    }

    /**
     * Get transactions sent by user to specific address (subsetted on currency)
     *
     * @param string $senderAddress Address of transaction sender
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactionsAddressCurrency(string $senderAddress, string $currency, int $limit = 10): array {
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT receiver_address, amount, currency, timestamp FROM transactions 
                    WHERE sender_address IN ($placeholders) AND receiver_address = ? AND currency = ?
                    ORDER BY timestamp DESC LIMIT ?";
        
        $params = array_merge($userAddresses, [$senderAddress, $currency, $limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        if(!$stmt){
            return [];
        }
       
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        
        foreach ($transactions as $tx) {
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => Constants::TX_TYPE_SENT,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['receiver_address']
            ];
        }
        return $formattedTransactions;
    }

    /**
     * Get transactions (all data) with limit
     *
     * @param int $limit
     * @return array
     */
    public function getTransactions(int $limit = 10): array
    {
        $userAddresses = $this->currentUser->getUserAddresses();
        
        if (empty($userAddresses)) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT * FROM {$this->tableName}
                    WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                    ORDER BY timestamp DESC LIMIT ?";

        // Bind parameters - addresses twice for both IN clauses, then limit
        $params = array_merge($userAddresses, $userAddresses, [$limit]);
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
     * @param int $limit
     * @return array
     */
    public function getTransactionHistory(int $limit = 10): array
    {
        $userAddresses = $this->currentUser->getUserAddresses();


        if (empty($userAddresses)) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

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
                  WHERE (t.sender_address IN ($placeholders) OR t.receiver_address IN ($placeholders))
                  ORDER BY COALESCE(t.time, 0) DESC, t.timestamp DESC LIMIT ?";

        // Bind parameters - addresses twice for both IN clauses, then limit
        $params = array_merge($userAddresses, $userAddresses, [$limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];

        foreach ($transactions as $tx) {
            $isSent = in_array($tx['sender_address'], $userAddresses);
            $counterpartyAddress = $isSent ? $tx['receiver_address'] : $tx['sender_address'];
            $counterpartyName = $isSent ? $tx['receiver_name'] : $tx['sender_name'];

            // Build display string: "Name (address)" or just "address" if no name
            $counterpartyDisplay = $counterpartyName
                ? $counterpartyName . ' (' . $counterpartyAddress . ')'
                : $counterpartyAddress;

            $formattedTransactions[] = [
                'id' => $tx['id'],
                'txid' => $tx['txid'],
                'tx_type' => $tx['tx_type'],
                'direction' => $tx['direction'],
                'status' => $tx['status'],
                'date' => $tx['timestamp'],
                'type' => $isSent ? Constants::TX_TYPE_SENT : Constants::TX_TYPE_RECEIVED,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
                'currency' => $tx['currency'],
                'counterparty' => $counterpartyDisplay,
                'counterparty_address' => $counterpartyAddress,
                'counterparty_name' => $counterpartyName,
                'sender_address' => $tx['sender_address'],
                'receiver_address' => $tx['receiver_address'],
                'sender_public_key' => $tx['sender_public_key'],
                'receiver_public_key' => $tx['receiver_public_key'],
                'memo' => $tx['memo'],
                'description' => $tx['description'],
                'previous_txid' => $tx['previous_txid'],
                'end_recipient_address' => $tx['end_recipient_address'] ?? null,
                'initial_sender_address' => $tx['initial_sender_address'] ?? null,
                'p2p_destination' => $tx['p2p_destination'] ?? null,
                'p2p_amount' => isset($tx['p2p_amount']) ? $tx['p2p_amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR : null,
                'p2p_fee' => isset($tx['p2p_fee']) ? $tx['p2p_fee'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR : null
            ];
        }
        return $formattedTransactions;
    }

     /**
     * Get transaction history with limit (subsetted on currency)
     *
     * @param string $currency Currency of transaction
     * @param int $limit
     * @return array
     */
    public function getTransactionHistoryCurrency(string $currency, int $limit = 10): array
    {
        $userAddresses = $this->currentUser->getUserAddresses();


        if (empty($userAddresses)) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        // Query with LEFT JOINs to get contact names for both sender and receiver
        $query = "SELECT
                    t.sender_address,
                    t.receiver_address,
                    t.amount,
                    t.currency,
                    t.timestamp,
                    sender_contact.name AS sender_name,
                    receiver_contact.name AS receiver_name
                  FROM {$this->tableName} t
                  LEFT JOIN addresses sender_addr ON (t.sender_address = sender_addr.http OR t.sender_address = sender_addr.https OR t.sender_address = sender_addr.tor)
                  LEFT JOIN contacts sender_contact ON sender_addr.pubkey_hash = sender_contact.pubkey_hash
                  LEFT JOIN addresses receiver_addr ON (t.receiver_address = receiver_addr.http OR t.receiver_address = receiver_addr.https OR t.receiver_address = receiver_addr.tor)
                  LEFT JOIN contacts receiver_contact ON receiver_addr.pubkey_hash = receiver_contact.pubkey_hash
                  WHERE (t.sender_address IN ($placeholders) OR t.receiver_address IN ($placeholders)) AND t.currency = ?
                  ORDER BY COALESCE(t.time, 0) DESC, t.timestamp DESC LIMIT ?";

        // Bind parameters - addresses twice for both IN clauses, then currency, then limit
        $params = array_merge($userAddresses, $userAddresses, [$currency, $limit]);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if(!$stmt){
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];

        foreach ($transactions as $tx) {
            $isSent = in_array($tx['sender_address'], $userAddresses);
            $counterpartyAddress = $isSent ? $tx['receiver_address'] : $tx['sender_address'];
            $counterpartyName = $isSent ? $tx['receiver_name'] : $tx['sender_name'];

            // Build display string: "Name (address)" or just "address" if no name
            $counterpartyDisplay = $counterpartyName
                ? $counterpartyName . ' (' . $counterpartyAddress . ')'
                : $counterpartyAddress;

            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => $isSent ? Constants::TX_TYPE_SENT : Constants::TX_TYPE_RECEIVED,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' => $counterpartyDisplay
            ];
        }
        return $formattedTransactions;
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
     * Retrieve pending transaction messages
     *
     * @param int $limit Maximum number of messages to retrieve
     * @return array Array of pending transactions
     */
    public function getPendingTransactions(int $limit = 5): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE status = 'pending'
                  ORDER BY timestamp ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve pending transactions", $e);
            return [];
        }
    }

    /**
     * Atomically claim a pending transaction for processing
     *
     * This method uses an atomic UPDATE with WHERE clause to change status
     * from 'pending' to 'sending'. Only succeeds if the transaction is still
     * in 'pending' status, preventing duplicate processing by multiple workers.
     *
     * The sending_started_at timestamp is set to track how long the transaction
     * has been in 'sending' status for recovery purposes.
     *
     * @param string $txid Transaction ID to claim
     * @return bool True if claim was successful, false if already claimed or not found
     */
    public function claimPendingTransaction(string $txid): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = :new_status,
                      sending_started_at = :started_at
                  WHERE txid = :txid
                  AND status = :current_status";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':new_status', Constants::STATUS_SENDING);
        $stmt->bindValue(':started_at', date('Y-m-d H:i:s'));
        $stmt->bindValue(':txid', $txid);
        $stmt->bindValue(':current_status', Constants::STATUS_PENDING);

        try {
            $stmt->execute();
            $claimed = $stmt->rowCount() > 0;

            if ($claimed && function_exists('output')) {
                output("Transaction {$txid} claimed for processing (pending -> sending)", 'SILENT');
            }

            return $claimed;
        } catch (PDOException $e) {
            $this->logError("Failed to claim pending transaction", $e);
            return false;
        }
    }

    /**
     * Get transactions stuck in 'sending' status that need recovery
     *
     * Returns transactions that have been in 'sending' status longer than
     * the configured timeout, indicating the processor may have crashed
     * while processing them.
     *
     * @param int $timeoutSeconds Timeout in seconds (default from Constants)
     * @return array Array of stuck transactions
     */
    public function getStuckSendingTransactions(int $timeoutSeconds = 0): array {
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = Constants::RECOVERY_SENDING_TIMEOUT_SECONDS;
        }

        $cutoffTime = date('Y-m-d H:i:s', time() - $timeoutSeconds);

        $query = "SELECT * FROM {$this->tableName}
                  WHERE status = :status
                  AND sending_started_at IS NOT NULL
                  AND sending_started_at < :cutoff_time
                  ORDER BY sending_started_at ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':status', Constants::STATUS_SENDING);
        $stmt->bindValue(':cutoff_time', $cutoffTime);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve stuck sending transactions", $e);
            return [];
        }
    }

    /**
     * Recover a stuck transaction by resetting it to pending
     *
     * Increments the recovery_count and resets status to 'pending' for retry.
     * If recovery_count exceeds max retries, marks transaction for manual review.
     *
     * @param string $txid Transaction ID to recover
     * @param int $maxRetries Maximum recovery attempts before manual review
     * @return array Result with 'recovered' (bool), 'needs_review' (bool), 'recovery_count' (int)
     */
    public function recoverStuckTransaction(string $txid, int $maxRetries = 0): array {
        if ($maxRetries <= 0) {
            $maxRetries = Constants::RECOVERY_MAX_RETRY_COUNT;
        }

        // First, get current recovery count
        $query = "SELECT recovery_count FROM {$this->tableName} WHERE txid = :txid";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':txid', $txid);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return ['recovered' => false, 'needs_review' => false, 'recovery_count' => 0];
        }

        $currentCount = (int)($result['recovery_count'] ?? 0);
        $newCount = $currentCount + 1;

        if ($newCount > $maxRetries) {
            // Mark for manual review
            $updateQuery = "UPDATE {$this->tableName}
                           SET status = :status,
                               recovery_count = :count,
                               sending_started_at = NULL,
                               needs_manual_review = 1
                           WHERE txid = :txid";

            $updateStmt = $this->pdo->prepare($updateQuery);
            $updateStmt->bindValue(':status', Constants::STATUS_FAILED);
            $updateStmt->bindValue(':count', $newCount);
            $updateStmt->bindValue(':txid', $txid);
            $updateStmt->execute();

            SecureLogger::warning("Transaction exceeded max recovery attempts, marked for manual review", [
                'txid' => $txid,
                'recovery_count' => $newCount,
                'max_retries' => $maxRetries
            ]);

            return ['recovered' => false, 'needs_review' => true, 'recovery_count' => $newCount];
        }

        // Reset to pending for retry
        $updateQuery = "UPDATE {$this->tableName}
                       SET status = :status,
                           recovery_count = :count,
                           sending_started_at = NULL
                       WHERE txid = :txid
                       AND status = :current_status";

        $updateStmt = $this->pdo->prepare($updateQuery);
        $updateStmt->bindValue(':status', Constants::STATUS_PENDING);
        $updateStmt->bindValue(':count', $newCount);
        $updateStmt->bindValue(':txid', $txid);
        $updateStmt->bindValue(':current_status', Constants::STATUS_SENDING);
        $updateStmt->execute();

        $recovered = $updateStmt->rowCount() > 0;

        if ($recovered) {
            SecureLogger::info("Transaction recovered from stuck sending state", [
                'txid' => $txid,
                'recovery_count' => $newCount
            ]);
        }

        return ['recovered' => $recovered, 'needs_review' => false, 'recovery_count' => $newCount];
    }

    /**
     * Get transactions marked for manual review
     *
     * @return array Array of transactions needing manual review
     */
    public function getTransactionsNeedingReview(): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE needs_manual_review = 1
                  ORDER BY timestamp ASC";

        $stmt = $this->pdo->prepare($query);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve transactions needing review", $e);
            return [];
        }
    }

    /**
     * Mark transaction as successfully sent (sending -> sent)
     *
     * Atomically updates status from 'sending' to 'sent', clearing the
     * sending_started_at timestamp.
     *
     * @param string $txid Transaction ID
     * @return bool True if update was successful
     */
    public function markAsSent(string $txid): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = :new_status,
                      sending_started_at = NULL
                  WHERE txid = :txid
                  AND status = :current_status";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':new_status', Constants::STATUS_SENT);
        $stmt->bindValue(':txid', $txid);
        $stmt->bindValue(':current_status', Constants::STATUS_SENDING);

        try {
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Failed to mark transaction as sent", $e);
            return false;
        }
    }

    /**
     * Get transactions that are in progress (not completed/rejected/cancelled)
     * Returns transactions with status: pending, sent, accepted (but not yet confirmed)
     * Also includes P2P route discovery requests sent by user that are not expired
     *
     * @param int $limit Maximum number of transactions to retrieve
     * @return array Array of in-progress transactions
     */
    public function getInProgressTransactions(int $limit = 10): array {
        $userAddresses = $this->currentUser->getUserAddresses();

        if (empty($userAddresses)) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        // Current microtime for expiration check (P2P expiration is stored as microtime * TIME_MICROSECONDS_TO_INT)
        $currentMicrotime = (int)(microtime(true) * Constants::TIME_MICROSECONDS_TO_INT);

        // Check if held_transactions table exists (may not exist in older databases)
        $heldTableExists = false;
        try {
            $checkStmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='held_transactions'");
            $heldTableExists = ($checkStmt && $checkStmt->fetch() !== false);
        } catch (PDOException $e) {
            // Table check failed, assume table doesn't exist
            $heldTableExists = false;
        }

        // Query combines:
        // 1. Regular in-progress transactions (pending, sent, accepted) where user is sender
        // 2. P2P route requests sent by user (destination_address NOT NULL) that are in route search phase
        //    Note: 'paid' status is excluded as the transaction has moved to regular transaction flow
        // 3. Held transactions (on hold pending chain sync) - shown with 'syncing' phase (if table exists)

        // Build base query for regular transactions
        $heldExclusion = $heldTableExists ? "AND txid NOT IN (SELECT txid FROM held_transactions)" : "";

        $query = "SELECT
                    txid,
                    tx_type,
                    status,
                    sender_address,
                    receiver_address,
                    amount,
                    currency,
                    memo,
                    timestamp,
                    CASE WHEN tx_type = 'p2p' THEN 'p2p_request' ELSE 'transaction' END as source_type,
                    NULL as destination_address,
                    NULL as fee_amount,
                    CASE
                        WHEN status = 'pending' THEN 'pending'
                        WHEN status = 'sent' THEN 'sending'
                        WHEN status = 'accepted' THEN 'sending'
                        ELSE 'pending'
                    END as phase,
                    0 as is_held
                  FROM {$this->tableName}
                  WHERE status IN ('pending', 'sent', 'accepted')
                    AND sender_address IN ($placeholders)
                    AND tx_type != 'contact'
                    $heldExclusion";

        // Add held transactions query if table exists
        if ($heldTableExists) {
            $query .= "

                  UNION ALL

                  SELECT
                    t.txid,
                    t.tx_type,
                    t.status,
                    t.sender_address,
                    t.receiver_address,
                    t.amount,
                    t.currency,
                    t.memo,
                    t.timestamp,
                    CASE WHEN t.tx_type = 'p2p' THEN 'p2p_request' ELSE 'transaction' END as source_type,
                    NULL as destination_address,
                    NULL as fee_amount,
                    'syncing' as phase,
                    1 as is_held
                  FROM {$this->tableName} t
                  INNER JOIN held_transactions ht ON t.txid = ht.txid
                  WHERE t.sender_address IN ($placeholders)
                    AND t.tx_type != 'contact'";
        }

        // Add P2P query
        $query .= "

                  UNION ALL

                  SELECT
                    hash as txid,
                    'p2p' as tx_type,
                    status,
                    sender_address,
                    destination_address as receiver_address,
                    amount,
                    currency,
                    hash as memo,
                    created_at as timestamp,
                    'p2p_request' as source_type,
                    destination_address,
                    my_fee_amount as fee_amount,
                    CASE
                        WHEN status IN ('initial', 'queued') THEN 'pending'
                        WHEN status = 'sent' THEN 'route_search'
                        WHEN status = 'found' THEN 'route_found'
                        ELSE 'pending'
                    END as phase,
                    0 as is_held
                  FROM p2p
                  WHERE destination_address IS NOT NULL
                    AND status NOT IN ('completed', 'expired', 'cancelled', 'paid')
                    AND expiration > ?

                  ORDER BY timestamp DESC
                  LIMIT ?";

        // Build params based on whether held_transactions table exists
        if ($heldTableExists) {
            // user addresses for regular transactions, user addresses for held transactions,
            // current time for p2p expiration, limit
            $params = array_merge($userAddresses, $userAddresses, [$currentMicrotime, $limit]);
        } else {
            // user addresses for regular transactions, current time for p2p expiration, limit
            $params = array_merge($userAddresses, [$currentMicrotime, $limit]);
        }
        $stmt = $this->pdo->prepare($query);

        try {
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve in-progress transactions", $e);
            return [];
        }
    }

    /**
     * Update previous_txid references when a transaction is cancelled/expired
     *
     * When a transaction is removed from the chain, all transactions that
     * pointed to it need to be updated to point to its previous_txid instead.
     *
     * Example: Chain A1->A2->A3->A4, if A2 is cancelled:
     * - A3 currently has previous_txid = A2
     * - A3 should be updated to have previous_txid = A1 (A2's previous_txid)
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
     * Get transactions count
     *
     * @return int Count of transactions in databae
     */
    public function getTotalCountTransactions(): int {
        $query = "SELECT COUNT(*) as count FROM {$this->tableName}";
        $stmt = $this->pdo->prepare($query);
        try {
            $stmt->execute();
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve transactions type counts", $e);
            return 0;
        }
    }

    /**
     * Get transactions type statistics
     *
     * @return array Array of transactions types with counts and sum of amount statistics
     */
    public function getTransactionsTypeStatistics(): array {
        $query = "SELECT type, COUNT(*) as count, SUM(amount) as total FROM {$this->tableName} GROUP BY type";
        $stmt = $this->pdo->prepare($query);
        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve specific transactions type counts", $e);
            return [];
        }
    }

    /**
     * Get specific transactions type statistics
     *
     * @param string $type type of transaction
     * @return array Array of transactions type with count and sum of amount statistics
     */
    public function getTransactionsSpecificTypeStatistics(string $type): array {
        $query = "SELECT type, COUNT(*) as count, SUM(amount) as total FROM {$this->tableName} WHERE type = :type";
        $stmt = $this->execute($query,[':type' => $type]);
        
        if (!$stmt) {
            return [];
        }
        $result = $stmt->fetch();
        return $result ?: [];
    }

    /**
     * Get specific transactions type counts
     *
     * @param string $type type of transaction
     * @return int Count of transactions type
     */
    public function getTransactionsSpecificTypeCount(string $type): int {
        $query = "SELECT COUNT(*) as count FROM {$this->tableName} WHERE type = :type";
        $stmt = $this->execute($query,[':type' => $type]);

        if (!$stmt) {
            return 0;
        }
        
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    }

    /**
     * Get transaction statistics
     *
     * @return array Statistics array with counts and totals
     */
    public function getStatistics(): array {
        $query = "SELECT
                    COUNT(*) as total_count,
                    SUM(amount) as total_amount,
                    AVG(amount) as average_amount,
                    COUNT(DISTINCT sender_address) as unique_senders,
                    COUNT(DISTINCT receiver_address) as unique_receivers,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
                  FROM {$this->tableName}";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: [];
    }

    /**
     * Get recent transactions with a specific contact by their addresses
     *
     * @param array $contactAddresses Array of contact addresses (http, https, tor, etc.)
     * @param int $limit Maximum number of transactions to return
     * @return array Formatted transactions with contact
     */
    public function getTransactionsWithContact(array $contactAddresses, int $limit = 5): array {
        $userAddresses = $this->currentUser->getUserAddresses();

        if (empty($userAddresses) || empty($contactAddresses)) {
            return [];
        }

        // Filter out empty addresses
        $contactAddresses = array_filter($contactAddresses);
        if (empty($contactAddresses)) {
            return [];
        }

        // Create placeholders for IN clauses
        $userPlaceholders = str_repeat('?,', count($userAddresses) - 1) . '?';
        $contactPlaceholders = str_repeat('?,', count($contactAddresses) - 1) . '?';

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
        $formattedTransactions = [];

        foreach ($transactions as $tx) {
            $isSent = in_array($tx['sender_address'], $userAddresses);

            $formattedTransactions[] = [
                'txid' => $tx['txid'] ?? '',
                'tx_type' => $tx['tx_type'] ?? 'standard',
                'status' => $tx['status'] ?? Constants::STATUS_COMPLETED,
                'date' => $tx['timestamp'],
                'type' => $isSent ? Constants::TX_TYPE_SENT : Constants::TX_TYPE_RECEIVED,
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
                'currency' => $tx['currency'],
                'sender_address' => $tx['sender_address'] ?? '',
                'receiver_address' => $tx['receiver_address'] ?? '',
                'memo' => $tx['memo'] ?? '',
                'description' => $tx['description'] ?? ''
            ];
        }

        return $formattedTransactions;
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
     * Get transaction by previous_txid
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

    /**
     * Verify local chain integrity for a contact pair
     *
     * Checks that the transaction chain between two parties has no gaps.
     * A gap exists when a transaction references a previous_txid that doesn't exist locally.
     *
     * This is used for sender-side chain verification before creating
     * new transactions. If gaps are detected, a sync should be triggered before sending.
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
            'broken_txids' => []
        ];

        // Get all transactions between the two parties (excluding cancelled/rejected)
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
        $result['transaction_count'] = count($transactions);
        $result['has_transactions'] = count($transactions) > 0;

        if (count($transactions) === 0) {
            return $result;
        }

        // Build a set of all txids for quick lookup
        $txidSet = [];
        foreach ($transactions as $tx) {
            $txidSet[$tx['txid']] = true;
        }

        // Check each transaction's previous_txid exists (except first transaction which has null)
        foreach ($transactions as $tx) {
            $prevTxid = $tx['previous_txid'];

            // Skip if previous_txid is null (first transaction in chain)
            if ($prevTxid === null) {
                continue;
            }

            // Check if previous_txid exists in our local chain
            if (!isset($txidSet[$prevTxid])) {
                $result['valid'] = false;
                $result['gaps'][] = $prevTxid;
                $result['broken_txids'][] = $tx['txid'];
            }
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
}
