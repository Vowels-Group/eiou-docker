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
        $this->primaryKey = 'pubkey';
    }

    /**
     * Get balance for receiving 
     *  If you want to send money towards some contact check how much balance you have with them
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return int Contact Balance
     */
    public function getBalanceForSendingTowards(string $pubkey, string $currency): int{
        $query = "SELECT balance FROM {$this->tableName} WHERE pubkey = :pubkey AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey' => $pubkey,':currency' => $currency]);
        if (!$stmt) {
            return 0;
        }
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    }

    /**
     * Get balance for sending 
     *  If a contact requests a p2p, check how much balance they have with you for sending
     *  This is the negative of the stored balance
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return int Contact Balance
     */
    public function getBalanceForSendingOnwards(string $pubkey, string $currency): int{
        $query = "SELECT balance FROM {$this->tableName} WHERE pubkey = :pubkey AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey' => $pubkey,':currency' => $currency]);
        if (!$stmt) {
            return 0;
        }
        $result = $stmt->fetchColumn();

        // Substract the result, 
        return (0-$result) ?: 0;
    }

    /**
     * Lookup contact balance
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return int Contact Balance
     */
    public function getContactBalance(string $pubkey, string $currency): int{
        $query = "SELECT balance FROM {$this->tableName} WHERE pubkey = :pubkey AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey' => $pubkey,':currency' => $currency]);
        if (!$stmt) {
            return 0;
        }
        $result = $stmt->fetchColumn();
        return $result ?: 0;
    }

    /**
     * Lookup contact balances with currency
     *
     * @param string $pubkey Contact pubkey
     * @return array|null Contact data or null
     */
    public function getContactBalances(string $pubkey): null{
        $query = "SELECT balance, currency FROM {$this->tableName} WHERE pubkey = :pubkey";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);
        if (!$stmt) {
            return null;
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Insert (initial) contact balance
     *
     * @param string $pubkey Contact pubkey
     * @param int $amount Amount of Balance (0)
     * @param string $currency currency
     * @return bool Success/Failure
     */
    public function insertBalance(string $pubkey, int $amount, string $currency): bool{
        $data = [
            'pubkey' => $pubkey,
            'balance' => $amount,
            'currency' => $currency
        ];

        $result = $this->insert($data);
        return $result !== false;
    }

    /**
     * Update contact balance
     *
     * @param string $pubkey Contact pubkey
     * @param int $amount Amount of Balance (0)
     * @param string $currency currency
     * @return bool Success/Failure
     */
    public function updateBalance(string $pubkey, int $amount, string $currency): bool{
        $query = "UPDATE {$this->tableName} SET balance = balance + :amount WHERE pubkey = :pubkey AND currency = :currency";
        $stmt = $this->execute($query, [':amount' => $amount,':pubkey' => $pubkey,':currency' => $currency]);    
        if(!$stmt){
            return false;
        }
        return true;
    }

}