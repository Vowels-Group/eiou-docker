<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Transaction Repository
 *
 * Manages all database interactions for the transactions table.
 *
 * @package Database\Repository
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
                  WHERE sender_public_key != :publicKey";
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
            if($transaction['status'] === 'completed'){
                $completed+=1;
            }
        }
        return $completed == count($transaction);
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
            if($transaction['status'] === 'completed'){
                $completed+=1;
            }
        }
        return $completed == count($transaction);
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
     * @param string $senderPublicKey Sender's public key
     * @param string $receiverPublicKey Receiver's public key
     * @return string|null Previous txid or null
     */
    public function getPreviousTxid(string $senderPublicKey, string $receiverPublicKey): ?string {
        $senderPublicKeyHash = hash(Constants::HASH_ALGORITHM, $senderPublicKey);
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $receiverPublicKey);

        $query = "SELECT txid FROM {$this->tableName}
                WHERE (sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash) 
                    OR (sender_public_key_hash = :second_receiver_public_key_hash AND receiver_public_key_hash = :second_sender_public_key_hash)
                ORDER BY timestamp DESC LIMIT 1";

        $stmt = $this->execute($query, [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash,
            ':second_receiver_public_key_hash' => $receiverPublicKeyHash,
            ':second_sender_public_key_hash' => $senderPublicKeyHash
        ]);

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
                'type' => 'sent',
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
                'type' => 'sent',
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
                'type' => 'received',
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
                'type' => 'received',
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
                'type' => 'received',
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
                'type' => 'received',
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
                'type' => 'sent',
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
                'type' => 'sent',
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
                    sender_contact.name AS sender_name,
                    receiver_contact.name AS receiver_name,
                    p2p.destination_address AS p2p_destination,
                    p2p.amount AS p2p_amount,
                    p2p.my_fee_amount AS p2p_fee
                  FROM {$this->tableName} t
                  LEFT JOIN addresses sender_addr ON (t.sender_address = sender_addr.http OR t.sender_address = sender_addr.tor)
                  LEFT JOIN contacts sender_contact ON sender_addr.pubkey_hash = sender_contact.pubkey_hash
                  LEFT JOIN addresses receiver_addr ON (t.receiver_address = receiver_addr.http OR t.receiver_address = receiver_addr.tor)
                  LEFT JOIN contacts receiver_contact ON receiver_addr.pubkey_hash = receiver_contact.pubkey_hash
                  LEFT JOIN p2p ON t.memo = p2p.hash
                  WHERE (t.sender_address IN ($placeholders) OR t.receiver_address IN ($placeholders))
                  ORDER BY t.timestamp DESC LIMIT ?";

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
                'type' => $isSent ? 'sent' : 'received',
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
                  LEFT JOIN addresses sender_addr ON (t.sender_address = sender_addr.http OR t.sender_address = sender_addr.tor)
                  LEFT JOIN contacts sender_contact ON sender_addr.pubkey_hash = sender_contact.pubkey_hash
                  LEFT JOIN addresses receiver_addr ON (t.receiver_address = receiver_addr.http OR t.receiver_address = receiver_addr.tor)
                  LEFT JOIN contacts receiver_contact ON receiver_addr.pubkey_hash = receiver_contact.pubkey_hash
                  WHERE (t.sender_address IN ($placeholders) OR t.receiver_address IN ($placeholders)) AND t.currency = ?
                  ORDER BY t.timestamp DESC LIMIT ?";

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
                'type' => $isSent ? 'sent' : 'received',
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
            $query = "SELECT txid FROM {$this->tableName}
                    WHERE (sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash) 
                        OR (sender_public_key_hash = :second_receiver_public_key_hash AND receiver_public_key_hash = :second_sender_public_key_hash)
                    ORDER BY timestamp DESC LIMIT 1";

            $stmt = $this->execute($query, [
                ':sender_public_key_hash' => $senderPublicKeyHash,
                ':receiver_public_key_hash' => $receiverPublicKeyHash,
                ':second_receiver_public_key_hash' => $receiverPublicKeyHash,
                ':second_sender_public_key_hash' => $senderPublicKeyHash
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $data = [
                'tx_type' => $txType,
                'type' => $type,
                'sender_address' => $request['senderAddress'],
                'sender_public_key' => $request['senderPublicKey'],
                'sender_public_key_hash' => $senderPublicKeyHash,
                'receiver_address' => $request['receiverAddress'],
                'receiver_public_key' => $request['receiverPublicKey'],
                'receiver_public_key_hash' => $receiverPublicKeyHash,
                'amount' => $request['amount'],
                'currency' => $request['currency'],
                'txid' => $request['txid'],
                'previous_txid' => $result ? $result['txid'] : null,
                'sender_signature' => $request['signature'] ?? null, // upon initial inserting a standard transaction in database of original sender it is null
                'memo' => $request['memo'],
                'description' => $request['description'] ?? null
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
                "status" => "accepted",
                "txid" => $request['txid'],
                "message" => "Transaction recorded successfully"
            
            ]);
        } else {
            return json_encode([
                "status" => "rejected",
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

        // Query combines:
        // 1. Regular in-progress transactions (pending, sent, accepted) where user is sender
        // 2. P2P route requests sent by user (destination_address NOT NULL) that are in route search phase
        //    Note: 'paid' status is excluded as the transaction has moved to regular transaction flow
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
                    END as phase
                  FROM {$this->tableName}
                  WHERE status IN ('pending', 'sent', 'accepted')
                    AND sender_address IN ($placeholders)

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
                    END as phase
                  FROM p2p
                  WHERE destination_address IS NOT NULL
                    AND status NOT IN ('completed', 'expired', 'cancelled', 'paid')
                    AND expiration > ?

                  ORDER BY timestamp DESC
                  LIMIT ?";

        // Build params: user addresses for transactions, current time for p2p expiration, limit
        $params = array_merge($userAddresses, [$currentMicrotime, $limit]);
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

        $query = "SELECT * FROM {$this->tableName}
                  WHERE (sender_public_key_hash = :sender_pubkey_hash1 AND receiver_public_key_hash = :receiver_pubkey_hash1)
                     OR (sender_public_key_hash = :receiver_pubkey_hash2 AND receiver_public_key_hash = :sender_pubkey_hash2)
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
     * @param array $contactAddresses Array of contact addresses (http, tor, etc.)
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
                'status' => $tx['status'] ?? 'completed',
                'date' => $tx['timestamp'],
                'type' => $isSent ? 'sent' : 'received',
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
}
