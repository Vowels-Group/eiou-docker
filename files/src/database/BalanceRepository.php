<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Balance Repository
 *
 * Manages all database interactions for the balances table.
 *
 * @package Database\Repository
 */
class BalanceRepository extends AbstractRepository {
    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'balances';
        $this->primaryKey = 'pubkey_hash';
    }

    /**
     * Delete balance 
     * 
     * @param string $pubkey Contact pubkey
     * @return bool succeeded true/false
     */
    public function deleteByPubkey($pubkey): bool{
        $deletedRows = $this->delete($this->primaryKey, hash(Constants::HASH_ALGORITHM, $pubkey));
        return $deletedRows > 0;
    }

    /**
     * Get all balances in the table
     *
     * @return array|null Array of Balances
     */
    public function getAllBalances(): array|null{
        $query = "SELECT * FROM {$this->tableName}";
        $stmt = $this->execute($query);
        if (!$stmt) {
            return null;
        }
        $result = $stmt->fetchALL(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup contact balance (both ways) subsetted on currency
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return array|null Array of Balances
     */
    public function getContactBalance(string $pubkey, string $currency): array|null{
        $query = "SELECT received, sent FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),':currency' => $currency]);
        if (!$stmt) {
            return null;
        }
        $result = $stmt->fetchALL(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup contact balance (both ways) subsetted on currency
     *
     * @param string $pubkeyHash Contact pubkeyhash
     * @param string $currency currency
     * @return array|null Array of Balances
     */
    public function getContactBalanceByPubkeyHash(string $pubkeyHash, string $currency =  'USD'): array|null{
        $query = "SELECT received, sent FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash,':currency' => $currency]);
        if (!$stmt) {
            return null;
        }
        $result = $stmt->fetchALL(PDO::FETCH_ASSOC);
        return $result ?: null;
    }


    /**
     * Lookup current contact balance given contact pubkey
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return iny Balance
     */
    public function getCurrentContactBalance(string $pubkey, string $currency): int{
        return $this->getContactReceivedBalance($pubkey, $currency) - $this->getContactSentBalance($pubkey, $currency);
    }

    /**
     * Lookup contact received balance (subsetted on currency)
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return int Contact received Balance
     */
    public function getContactReceivedBalance(string $pubkey, string $currency): int{
        $query = "SELECT received FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),':currency' => $currency]);
        if (!$stmt) {
            return 0;
        }
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    }

    /**
     * Lookup contact sent balance (subsetted on currency)
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return int Contact sent Balance
     */
    public function getContactSentBalance(string $pubkey, string $currency): int{
        $query = "SELECT sent FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),':currency' => $currency]);
        if (!$stmt) {
            return 0;
        }
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    }

    /**
     * Lookup contact balances with currency (both ways)
     *
     * @param string $pubkey Contact pubkey
     * @return array|null Contact data or null
     */
    public function getContactBalances(string $pubkey): array|null{
        $query = "SELECT received, sent, currency FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey)]);
        if (!$stmt) {
            return null;
        }
        $result = $stmt->fetchALL(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup contact balances subsetted on currency (both ways)
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return array|null Contact data or null
     */
    public function getContactBalancesCurrency(string $pubkey, string $currency): array|null{
        $query = "SELECT received, sent, currency FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey), ':currency' => $currency]);
        if (!$stmt) {
            return null;
        }
        $result = $stmt->fetchALL(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    
    /**
     * Lookup User balances (grouped by currency)
     *
     * @return array|null Contact data or null
     */
    public function getUserBalance(): array|null{

        $query = "SELECT currency, SUM(received) - SUM(sent) AS total_balance 
                    FROM {$this->tableName}
                    GROUP BY currency";

        $stmt = $this->execute($query);
        
        if (!$stmt) {
            return null;
        }
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Lookup User balance for specific currency
     *
     * @param string $currency currency
     * @return int User balance of specific currency
     */
    public function getUserBalanceCurrency($currency): int{

        $query = "SELECT SUM(received) - SUM(sent) AS total_balance 
                    FROM {$this->tableName}
                    WHERE currency = :currency";

       $stmt = $this->execute($query, [':currency' => $currency]);
        
        if (!$stmt) {
            return 0;
        }
        
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    }

    /**
     * Lookup User balances regarding Contact (grouped by currency)
     *
     * @param string $pubkey Contact pubkey
     * @return array|null Contact data or null
     */
    public function getUserBalanceContact(string $pubkey): array|null{

        $query = "SELECT currency, 
                    SUM(received) - SUM(sent) AS total_balance 
                    FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash
                    GROUP BY currency";

        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey)]);
        
        if (!$stmt) {
            return null;
        }
        
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Insert (initial) contact balance
     *
     * @param string $pubkey Contact pubkey
     * @param int $receivedAmount Amount of received balance
     * @param int $sentAmount Amount of sent balance
     * @param string $currency currency
     * @return bool Success/Failure
     */
    public function insertBalance(string $pubkey, int $receivedAmount, int $sentAmount, string $currency): bool{
        $data = [
            'pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),
            'received' => $receivedAmount,
            'sent' => $sentAmount,
            'currency' => $currency
        ];

        $result = $this->insert($data);
        return $result !== false;
    }

    /**
     * Handle new contact balance creation
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     */
    public function insertInitialContactBalances(string $pubkey, string $currency){
        $this->insertBalance($pubkey, 0, 0, $currency);
       
    }


    /**
     * Update contact balance
     *
     * @param string $pubkey Contact pubkey
     * @param string $direction direction of balance (sent/received)
     * @param int $amount Amount of Balance to add
     * @param string $currency currency
     * @return bool Success/Failure of balance update
     */
    public function updateBalance(string $pubkey, string $direction, int $amount, string $currency): bool{
        $query = "UPDATE {$this->tableName} SET {$direction} = {$direction} + :amount WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':amount' => $amount,':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),':currency' => $currency]);    
        if(!$stmt){
            return false;
        }
        return true;
    }


    /**
     * Update contact balance(s) on transaction completion
     *
     * @param array $transactions Transaction Data
    * @return bool Success/Failure of balance(s) update
     */
    public function updateBalanceGivenTransactions($transactions): bool{
        $userAddresses = $this->currentUser->getUserAddresses();
        $amountTransactions = count($transactions);

        foreach($transactions as $transaction){
            // P2P Transaction
            if($transaction['tx_type'] == 'p2p'){
                // Intermediary or original sender of P2P Transaction
                if(in_array($transaction['sender_address'],$userAddresses)){
                    $updateSender = $this->updateBalance($transaction['receiver_public_key'], 'sent', $transaction['amount'], $transaction['currency']);
                } 
                // Intermediary or end receiver of P2P Transaction
                elseif(in_array($transaction['receiver_address'],$userAddresses)){
                    $updateReceiver = $this->updateBalance($transaction['sender_public_key'], 'received', $transaction['amount'], $transaction['currency']);
                }
            }
            // Direct Transaction
            elseif($transaction['tx_type'] == 'standard'){
                // Original sender of Direct Transaction
                if(in_array($transaction['sender_address'],$userAddresses)){
                    $updateSender = $this->updateBalance($transaction['receiver_public_key'], 'sent', $transaction['amount'], $transaction['currency']);
                } 
                // Receiver of Direct Transaction
                elseif($transaction['tx_type'] == 'standard' && in_array($transaction['receiver_address'],$userAddresses)){
                    $updateReceiver = $this->updateBalance($transaction['sender_public_key'], 'received', $transaction['amount'], $transaction['currency']);
                }
            }
        }
        if($amountTransactions == 2 && $updateReceiver && $updateSender){
            return true;
        } elseif($amountTransactions == 1 && ((isset($updateReceiver) && $updateReceiver) ||  (isset($updateSender) && $updateSender))){
            return true;
        }
        return false;
    }
}