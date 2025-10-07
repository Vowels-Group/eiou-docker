<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * Transaction Repository
 *
 * Manages all database interactions for the transactions table.
 * Replaces global $pdo usage in databaseTransactionInteraction.php
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
                     OR (sender_public_key_hash = :receiver_public_key_hash AND receiver_public_key_hash = :sender_public_key_hash)
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
            'sender_signature' => $request['signature'],
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
