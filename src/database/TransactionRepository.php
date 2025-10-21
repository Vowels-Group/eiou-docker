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
     * @param string $publicKey Public key
     * @return float Total amount sent
     */
    public function calculateTotalSent(string $publicKey): float {
        $publicKeyHash = hash('sha256', $publicKey);
        $query = "SELECT SUM(amount) as total_sent FROM {$this->tableName}
                  WHERE receiver_public_key_hash = :publicKeyHash";
        $stmt = $this->execute($query, [':publicKeyHash' => $publicKeyHash]);

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
    public function calculateTotalSentByUser(string $userPublicKey): float {
        $publicKeyHash = hash('sha256', $userPublicKey);
        $query = "SELECT SUM(amount) as total_sent FROM {$this->tableName}
                  WHERE sender_public_key_hash = :publicKeyHash";
        $stmt = $this->execute($query, [':publicKeyHash' => $publicKeyHash]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['total_sent'] ?? 0);
    }

    /**
     * Calculate total amount received from a public key
     *
     * @param string $publicKey Public key
     * @return float Total amount received
     */
    public function calculateTotalReceived(string $publicKey): float {
        $publicKeyHash = hash('sha256', $publicKey);
        $query = "SELECT SUM(amount) as total_received FROM {$this->tableName}
                  WHERE sender_public_key_hash = :publicKeyHash";
        $stmt = $this->execute($query, [':publicKeyHash' => $publicKeyHash]);

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
    public function calculateTotalReceivedByUser(string $userPublicKey): float {
        $publicKeyHash = hash('sha256', $userPublicKey);
        $query = "SELECT SUM(amount) as total_received FROM {$this->tableName}
                  WHERE sender_public_key_hash != :publicKeyHash";
        $stmt = $this->execute($query, [':publicKeyHash' => $publicKeyHash]);

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
        $transaction = $this->getByMemo($memo);
        return $transaction && ($transaction['status'] ?? '') === 'completed';
    }

    /**
     * Check if transaction is completed by txid
     *
     * @param string $txid Transaction ID
     * @return bool True if completed
     */
    public function isCompletedByTxid(string $txid): bool {
        $transaction = $this->getByTxid($txid);
        return $transaction && ($transaction['status'] ?? '') === 'completed';
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
        $senderPublicKeyHash = hash('sha256', $senderPublicKey);
        $receiverPublicKeyHash = hash('sha256', $receiverPublicKey);

        $query = "SELECT txid FROM {$this->tableName}
                  WHERE (sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash)
                    --  OR (sender_public_key_hash = :receiver_public_key_hash AND receiver_public_key_hash = :sender_public_key_hash)
                  ORDER BY timestamp DESC LIMIT 1";

        $stmt = $this->execute($query, [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash
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
        $userHash = hash('sha256', $userPubkey);
        $contactHash = hash('sha256', $contactPubkey);

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
        $userHash = hash('sha256', $userPubkey);
        $contactHashes = array_map(function($pubkey) {
            return hash('sha256', $pubkey);
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
        $totalReceived = $this->calculateTotalReceivedByUser($this->currentUser->getPublicKey());
        $totalSent = $this->calculateTotalSentByUser($this->currentUser->getPublicKey());
        $balance = (string)convertQuantityCurrency($totalReceived - $totalSent);
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
        $params = array_merge($userAddresses, $userAddresses, [date($this->envVariables->get('DISPLAY_DATE_FORMAT'), $lastCheckTime)]);
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
     * Get all sent transactions
     *
     * @return array
     */
    public function getAllSentUserTransactions(): array
    {
        return $this->getSentUserTransactions(PHP_INT_MAX); // Get a large number
    }

    /**
     * Get transactions sent by user
     *
     * @param int $limit
     * @return array
     */
    public function getSentUserTransactions($limit = 10): array {
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
                'amount' => $tx['amount'] / $this->envVariables->get('TRANSACTION_USD_CONVERSION_FACTOR'), // Convert from cents
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
     * Get transactions received by user
     *
     * @param int $limit
     * @return array
     */
    public function getReceivedUserTransactions($limit = 10): array{
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
                'amount' => $tx['amount'] / $this->envVariables->get('TRANSACTION_USD_CONVERSION_FACTOR'), // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' =>  $tx['sender_address']
            ];
        }
        return $formattedTransactions;
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

        $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM {$this->tableName}
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
        $formattedTransactions = [];

        foreach ($transactions as $tx) {
            $isSent = in_array($tx['sender_address'], $userAddresses);
            $counterpartyAddress = $isSent ? $tx['receiver_address'] : $tx['sender_address'];

            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => $isSent ? 'sent' : 'received',
                'amount' => $tx['amount'] / $this->envVariables->get('TRANSACTION_USD_CONVERSION_FACTOR'), // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' => $counterpartyAddress
            ];
        }
        return $formattedTransactions;
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

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
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

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Insert a new transaction
     *
     * @param array $request Transaction request data
     * @return string JSON response
     */
    public function insertTransaction(array $request): string {
        // Calculate public key hashes
        $senderPublicKeyHash = hash('sha256', $request['senderPublicKey']);
        $receiverPublicKeyHash = hash('sha256', $request['receiverPublicKey']);

        // Determine transaction type
        $txType = ($request['memo'] === 'standard') ? 'standard' : 'p2p';

        $data = [
            'tx_type' => $txType,
            'sender_address' => $request['senderAddress'],
            'sender_public_key' => $request['senderPublicKey'],
            'sender_public_key_hash' => $senderPublicKeyHash,
            'receiver_address' => $request['receiverAddress'],
            'receiver_public_key' => $request['receiverPublicKey'],
            'receiver_public_key_hash' => $receiverPublicKeyHash,
            'amount' => $request['amount'],
            'currency' => $request['currency'],
            'txid' => $request['txid'],
            'previous_txid' => $request['previousTxid'],
            'sender_signature' => $request['signature'] ?? null, // upon initial inserting a standard transaction in database of original sender it is null
            'memo' => $request['memo']
        ];

        $result = $this->insert($data);

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
                "message" => "Transaction recorded successfully",
                "txid" => $request['txid']
            ]);
        } else {
            return json_encode([
                "status" => "rejected",
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
                     OR (sender_address = :address2 AND receiver_address = :address1)
                  ORDER BY timestamp DESC";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':address1', $address1);
        $stmt->bindValue(':address2', $address2);

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
}
