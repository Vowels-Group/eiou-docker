<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use PDO;

/**
 * Balance Repository
 *
 * Manages all database interactions for the balances table.
 *
 * @package Database\Repository
 */
class BalanceRepository extends AbstractRepository {
    use QueryBuilder;
    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'pubkey_hash', 'received_whole', 'received_frac', 'sent_whole', 'sent_frac', 'currency'
    ];

    /** @var string[] Split amount column prefixes for automatic row mapping */
    protected array $splitAmountColumns = ['received', 'sent'];

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
        return $result ? $this->mapRows($result) : null;
    }

    /**
     * Lookup contact balance (both ways) subsetted on currency
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return array|null Array of Balances
     */
    public function getContactBalance(string $pubkey, string $currency): array|null{
        $query = "SELECT received_whole, received_frac, sent_whole, sent_frac FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),':currency' => $currency]);
        if (!$stmt) {
            return null;
        }
        $rows = $stmt->fetchALL(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        return array_map(function ($row) {
            return [
                'received' => new SplitAmount((int)$row['received_whole'], (int)$row['received_frac']),
                'sent' => new SplitAmount((int)$row['sent_whole'], (int)$row['sent_frac']),
            ];
        }, $rows);
    }

    /**
     * Lookup contact balance (both ways) subsetted on currency
     *
     * @param string $pubkeyHash Contact pubkeyhash
     * @param string $currency currency
     * @return array|null Array of Balances
     */
    public function getContactBalanceByPubkeyHash(string $pubkeyHash, string $currency =  'USD'): array|null{
        $query = "SELECT received_whole, received_frac, sent_whole, sent_frac FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => $pubkeyHash,':currency' => $currency]);
        if (!$stmt) {
            return null;
        }
        $rows = $stmt->fetchALL(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        return array_map(function ($row) {
            return [
                'received' => new SplitAmount((int)$row['received_whole'], (int)$row['received_frac']),
                'sent' => new SplitAmount((int)$row['sent_whole'], (int)$row['sent_frac']),
            ];
        }, $rows);
    }


    /**
     * Lookup current contact balance given contact pubkey
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return SplitAmount Balance
     */
    public function getCurrentContactBalance(string $pubkey, string $currency): SplitAmount{
        return $this->getContactReceivedBalance($pubkey, $currency)->subtract($this->getContactSentBalance($pubkey, $currency));
    }

    /**
     * Lookup contact received balance (subsetted on currency)
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return SplitAmount Contact received Balance
     */
    public function getContactReceivedBalance(string $pubkey, string $currency): SplitAmount{
        $query = "SELECT received_whole, received_frac FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),':currency' => $currency]);
        if (!$stmt) {
            return SplitAmount::zero();
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return SplitAmount::zero();
        }
        return new SplitAmount((int)$result['received_whole'], (int)$result['received_frac']);
    }

    /**
     * Lookup contact sent balance (subsetted on currency)
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return SplitAmount Contact sent Balance
     */
    public function getContactSentBalance(string $pubkey, string $currency): SplitAmount{
        $query = "SELECT sent_whole, sent_frac FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),':currency' => $currency]);
        if (!$stmt) {
            return SplitAmount::zero();
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            return SplitAmount::zero();
        }
        return new SplitAmount((int)$result['sent_whole'], (int)$result['sent_frac']);
    }

    /**
     * Lookup contact balances with currency (both ways)
     *
     * @param string $pubkey Contact pubkey
     * @return array|null Contact data or null
     */
    public function getContactBalances(string $pubkey): array|null{
        $query = "SELECT received_whole, received_frac, sent_whole, sent_frac, currency FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey)]);
        if (!$stmt) {
            return null;
        }
        $rows = $stmt->fetchALL(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        return array_map(function ($row) {
            return [
                'received' => new SplitAmount((int)$row['received_whole'], (int)$row['received_frac']),
                'sent' => new SplitAmount((int)$row['sent_whole'], (int)$row['sent_frac']),
                'currency' => $row['currency'],
            ];
        }, $rows);
    }

    /**
     * Get balances for multiple contacts by pubkey hash in a single query
     *
     * @param array $pubkeyHashes Array of pubkey hashes to look up
     * @param string $currency Currency to filter by
     * @return array Associative array keyed by pubkey_hash => ['received' => int, 'sent' => int]
     */
    public function getBalancesForPubkeyHashes(array $pubkeyHashes, string $currency = 'USD'): array {
        if (empty($pubkeyHashes)) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($pubkeyHashes) as $i => $hash) {
            $key = ':hash_' . $i;
            $placeholders[] = $key;
            $params[$key] = $hash;
        }
        $params[':currency'] = $currency;
        $query = "SELECT {$this->primaryKey}, received_whole, received_frac, sent_whole, sent_frac FROM {$this->tableName} WHERE {$this->primaryKey} IN (" . implode(',', $placeholders) . ") AND currency = :currency";
        $stmt = $this->execute($query, $params);

        if (!$stmt) {
            return [];
        }

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[$row[$this->primaryKey]] = [
                'received' => new SplitAmount((int)$row['received_whole'], (int)$row['received_frac']),
                'sent' => new SplitAmount((int)$row['sent_whole'], (int)$row['sent_frac']),
            ];
        }
        return $results;
    }

    /**
     * Lookup contact balances subsetted on currency (both ways)
     *
     * @param string $pubkey Contact pubkey
     * @param string $currency currency
     * @return array|null Contact data or null
     */
    public function getContactBalancesCurrency(string $pubkey, string $currency): array|null{
        $query = "SELECT received_whole, received_frac, sent_whole, sent_frac, currency FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey), ':currency' => $currency]);
        if (!$stmt) {
            return null;
        }
        $rows = $stmt->fetchALL(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        return array_map(function ($row) {
            return [
                'received' => new SplitAmount((int)$row['received_whole'], (int)$row['received_frac']),
                'sent' => new SplitAmount((int)$row['sent_whole'], (int)$row['sent_frac']),
                'currency' => $row['currency'],
            ];
        }, $rows);
    }

    
    /**
     * Lookup User balances (grouped by currency)
     *
     * @return array|null Contact data or null
     */
    public function getUserBalance(): array|null{

        $query = "SELECT currency,
                    SUM(received_whole) AS sum_received_whole, SUM(received_frac) AS sum_received_frac,
                    SUM(sent_whole) AS sum_sent_whole, SUM(sent_frac) AS sum_sent_frac
                    FROM {$this->tableName}
                    GROUP BY currency";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return null;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        return array_map(function ($row) {
            $received = self::sumCarry((int)$row['sum_received_whole'], (int)$row['sum_received_frac']);
            $sent = self::sumCarry((int)$row['sum_sent_whole'], (int)$row['sum_sent_frac']);
            return [
                'currency' => $row['currency'],
                'total_balance' => $received->subtract($sent),
            ];
        }, $rows);
    }

    /**
     * Lookup User balance for specific currency
     *
     * @param string $currency currency
     * @return SplitAmount User balance of specific currency
     */
    public function getUserBalanceCurrency($currency): SplitAmount{

        $query = "SELECT SUM(received_whole) AS sum_received_whole, SUM(received_frac) AS sum_received_frac,
                    SUM(sent_whole) AS sum_sent_whole, SUM(sent_frac) AS sum_sent_frac
                    FROM {$this->tableName}
                    WHERE currency = :currency";

       $stmt = $this->execute($query, [':currency' => $currency]);

        if (!$stmt) {
            return SplitAmount::zero();
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result || $result['sum_received_whole'] === null) {
            return SplitAmount::zero();
        }
        $received = self::sumCarry((int)$result['sum_received_whole'], (int)$result['sum_received_frac']);
        $sent = self::sumCarry((int)$result['sum_sent_whole'], (int)$result['sum_sent_frac']);
        return $received->subtract($sent);
    }

    /**
     * Lookup User balances regarding Contact (grouped by currency)
     *
     * @param string $pubkey Contact pubkey
     * @return array|null Contact data or null
     */
    public function getUserBalanceContact(string $pubkey): array|null{

        $query = "SELECT currency,
                    SUM(received_whole) AS sum_received_whole, SUM(received_frac) AS sum_received_frac,
                    SUM(sent_whole) AS sum_sent_whole, SUM(sent_frac) AS sum_sent_frac
                    FROM {$this->tableName} WHERE {$this->primaryKey} = :pubkey_hash
                    GROUP BY currency";

        $stmt = $this->execute($query, [':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey)]);

        if (!$stmt) {
            return null;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        return array_map(function ($row) {
            $received = self::sumCarry((int)$row['sum_received_whole'], (int)$row['sum_received_frac']);
            $sent = self::sumCarry((int)$row['sum_sent_whole'], (int)$row['sum_sent_frac']);
            return [
                'currency' => $row['currency'],
                'total_balance' => $received->subtract($sent),
            ];
        }, $rows);
    }

    /**
     * Insert (initial) contact balance
     *
     * @param string $pubkey Contact pubkey
     * @param SplitAmount $receivedAmount Amount of received balance
     * @param SplitAmount $sentAmount Amount of sent balance
     * @param string $currency currency
     * @return bool Success/Failure
     */
    public function insertBalance(string $pubkey, SplitAmount $receivedAmount, SplitAmount $sentAmount, string $currency): bool{
        $data = [
            'pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),
            'received_whole' => $receivedAmount->whole,
            'received_frac' => $receivedAmount->frac,
            'sent_whole' => $sentAmount->whole,
            'sent_frac' => $sentAmount->frac,
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
        $this->insertBalance($pubkey, SplitAmount::zero(), SplitAmount::zero(), $currency);
    }

    /**
     * Update contact balance
     *
     * @param string $pubkey Contact pubkey
     * @param string $direction direction of balance (sent/received)
     * @param SplitAmount $amount Amount of Balance to add
     * @param string $currency currency
     * @return bool Success/Failure of balance update
     */
    public function updateBalance(string $pubkey, string $direction, SplitAmount $amount, string $currency): bool {
        if (!in_array($direction, ['sent', 'received'], true)) {
            return false;
        }
        $wholeCol = "{$direction}_whole";
        $fracCol = "{$direction}_frac";
        // Add whole and frac separately; handle frac carry in SQL
        // new_frac_raw = old_frac + added_frac
        // carry = FLOOR(new_frac_raw / FRAC_MODULUS)
        // final_frac = new_frac_raw MOD FRAC_MODULUS
        // final_whole = old_whole + added_whole + carry
        $mod = SplitAmount::FRAC_MODULUS;
        $query = "UPDATE {$this->tableName} SET
                    {$wholeCol} = {$wholeCol} + :amount_whole + FLOOR(({$fracCol} + :amount_frac) / {$mod}),
                    {$fracCol} = ({$fracCol} + :amount_frac2) MOD {$mod}
                  WHERE {$this->primaryKey} = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [
            ':amount_whole' => $amount->whole,
            ':amount_frac' => $amount->frac,
            ':amount_frac2' => $amount->frac,
            ':pubkey_hash' => hash(Constants::HASH_ALGORITHM, $pubkey),
            ':currency' => $currency
        ]);
        if (!$stmt) {
            return false;
        }
        return true;
    }

    /**
     * Update contact balance (both directions)
     *
     * @param array $amounts Associative array of received => SplitAmount, sent => SplitAmount
     * @param string $contactPubkeyHash Contact's Public Key hash
     * @param string $currency currency
     * @return bool Success/Failure of balance update
     */
    public function updateBothDirectionBalance(array $amounts, string $contactPubkeyHash, string $currency): bool {
        $query = "UPDATE balances SET received_whole = :received_whole, received_frac = :received_frac,
                    sent_whole = :sent_whole, sent_frac = :sent_frac
                WHERE pubkey_hash = :pubkey_hash AND currency = :currency";
        $stmt = $this->execute($query, [
            ':received_whole' => $amounts['received']->whole,
            ':received_frac' => $amounts['received']->frac,
            ':sent_whole' => $amounts['sent']->whole,
            ':sent_frac' => $amounts['sent']->frac,
            ':pubkey_hash' => $contactPubkeyHash,
            ':currency' => $currency
        ]);
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
    public function updateBalanceGivenTransactions(array $transactions): bool {
        $userAddresses = $this->currentUser->getUserAddresses();
        $amountTransactions = count($transactions);
        $updateSender = false;
        $updateReceiver = false;

        foreach($transactions as $transaction){
            // Contact Request Transaction (amount=0, no balance change)
            if($transaction['tx_type'] == 'contact'){
                // Contact requests don't affect balances (amount is always 0)
                // Just mark as processed successfully
                $updateSender = true;
                $updateReceiver = true;
            }
            // P2P Transaction
            elseif($transaction['tx_type'] == 'p2p'){
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
        if($amountTransactions === 2 && $updateReceiver && $updateSender){
            return true;
        } elseif($amountTransactions === 1 && ($updateReceiver || $updateSender)){
            return true;
        }
        return false;
    }

    /**
     * Handle carry from summed fractional parts
     *
     * When SUM(frac) exceeds FRAC_MODULUS, carry the overflow into whole.
     *
     * @param int $sumWhole Summed whole parts
     * @param int $sumFrac Summed fractional parts (may exceed FRAC_MODULUS)
     * @return SplitAmount Properly normalized SplitAmount
     */
    private static function sumCarry(int $sumWhole, int $sumFrac): SplitAmount {
        $carry = intdiv($sumFrac, SplitAmount::FRAC_MODULUS);
        $frac = $sumFrac % SplitAmount::FRAC_MODULUS;
        return new SplitAmount($sumWhole + $carry, $frac);
    }
}