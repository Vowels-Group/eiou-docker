<?php
/**
 * Transaction Repository
 *
 * Handles data access for transactions. Provides database abstraction for all transaction-related queries.
 * Extracted from functions.php for clean separation of concerns.
 *
 * @package eIOUGUI\Repositories
 * @author Hive Mind Collective
 * @copyright 2025
 */

namespace eIOUGUI\Repositories;

use PDO;
use Exception;

class TransactionRepository
{
    /**
     * @var PDO|null Database connection
     */
    private ?PDO $pdo = null;

    /**
     * Get PDO connection (lazy initialization)
     *
     * @return PDO|null
     */
    private function getPDOConnection(): ?PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = $this->createPDOConnection();
            } catch (Exception $e) {
                error_log("Database connection failed: " . $e->getMessage());
                return null;
            }
        }
        return $this->pdo;
    }

    /**
     * Create PDO connection
     *
     * @return PDO
     * @throws Exception
     */
    private function createPDOConnection(): PDO
    {
        // This should be implemented based on your database configuration
        global $pdo;
        if ($pdo !== null) {
            return $pdo;
        }

        // If no global connection exists, call the global function
        if (function_exists('getPDOConnection')) {
            $connection = getPDOConnection();
            if ($connection !== null) {
                return $connection;
            }
        }

        throw new Exception("Database connection not available");
    }

    /**
     * Get transaction history with limit
     *
     * @param int $limit
     * @return array
     */
    public function getTransactionHistory(int $limit = 10): array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return [];
        }

        try {
            global $user;

            $userAddresses = [];
            if (isset($user['hostname'])) {
                $userAddresses[] = $user['hostname'];
            }
            if (isset($user['torAddress'])) {
                $userAddresses[] = $user['torAddress'];
            }

            if (empty($userAddresses)) {
                return [];
            }

            // Create placeholders for IN clause
            $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

            $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions
                      WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                      ORDER BY timestamp DESC LIMIT ?";

            $stmt = $pdo->prepare($query);

            // Bind parameters - addresses twice for both IN clauses, then limit
            $params = array_merge($userAddresses, $userAddresses, [$limit]);
            $stmt->execute($params);

            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $formattedTransactions = [];

            foreach ($transactions as $tx) {
                $isSent = in_array($tx['sender_address'], $userAddresses);
                $counterpartyAddress = $isSent ? $tx['receiver_address'] : $tx['sender_address'];

                // Get contact name for counterparty
                $contactRepository = new \eIOUGUI\Repositories\ContactRepository();
                $contactName = $contactRepository->getContactNameByAddress($counterpartyAddress);

                $formattedTransactions[] = [
                    'date' => $tx['timestamp'],
                    'type' => $isSent ? 'sent' : 'received',
                    'amount' => $tx['amount'] / 100, // Convert from cents
                    'currency' => $tx['currency'],
                    'counterparty' => $contactName ?: $counterpartyAddress
                ];
            }

            return $formattedTransactions;
        } catch (Exception $e) {
            error_log("Error getting transaction history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all transactions
     *
     * @return array
     */
    public function getAllTransactions(): array
    {
        return $this->getTransactionHistory(1000); // Get a large number
    }

    /**
     * Calculate total received amount for a user
     *
     * @param string $userPubkey
     * @return int Amount in cents
     */
    public function calculateTotalReceived(string $userPubkey): int
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE receiver_public_key_hash = ?");
            $stmt->execute([hash('sha256', $userPubkey)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (Exception $e) {
            error_log("Error calculating total received: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Calculate total sent amount for a user
     *
     * @param string $userPubkey
     * @return int Amount in cents
     */
    public function calculateTotalSent(string $userPubkey): int
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE sender_public_key_hash = ?");
            $stmt->execute([hash('sha256', $userPubkey)]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (Exception $e) {
            error_log("Error calculating total sent: " . $e->getMessage());
            return 0;
        }
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
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return 0;
        }

        try {
            $userHash = hash('sha256', $userPubkey);
            $contactHash = hash('sha256', $contactPubkey);

            // Calculate sent to this contact
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as sent FROM transactions WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ?");
            $stmt->execute([$userHash, $contactHash]);
            $sent = $stmt->fetch(PDO::FETCH_ASSOC)['sent'];

            // Calculate received from this contact
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as received FROM transactions WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ?");
            $stmt->execute([$contactHash, $userHash]);
            $received = $stmt->fetch(PDO::FETCH_ASSOC)['received'];

            return $received - $sent;
        } catch (Exception $e) {
            error_log("Error getting contact balance: " . $e->getMessage());
            return 0;
        }
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
        $pdo = $this->getPDOConnection();
        if ($pdo === null || empty($contactPubkeys)) {
            return [];
        }

        try {
            $userHash = hash('sha256', $userPubkey);
            $contactHashes = array_map(function($pubkey) {
                return hash('sha256', $pubkey);
            }, $contactPubkeys);

            // Create a mapping of hash to pubkey for later lookup
            $hashToPubkey = array_combine($contactHashes, $contactPubkeys);

            // Build placeholders for IN clause
            $placeholders = str_repeat('?,', count($contactHashes) - 1) . '?';

            // Single query to get all balances using UNION
            $sql = "
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
                    FROM transactions
                    WHERE sender_public_key_hash = ?
                        AND receiver_public_key_hash IN ($placeholders)
                    GROUP BY receiver_public_key_hash

                    UNION ALL

                    -- Received by user from contacts
                    SELECT
                        sender_public_key_hash as contact_hash,
                        0 as sent,
                        SUM(amount) as received
                    FROM transactions
                    WHERE receiver_public_key_hash = ?
                        AND sender_public_key_hash IN ($placeholders)
                    GROUP BY sender_public_key_hash
                ) as balance_calc
                GROUP BY contact_hash
            ";

            // Prepare parameters: userHash, contactHashes, userHash, contactHashes
            $params = array_merge([$userHash], $contactHashes, [$userHash], $contactHashes);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

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
        } catch (Exception $e) {
            error_log("Error getting contact balances: " . $e->getMessage());
            // Return zero balances for all contacts on error
            return array_fill_keys($contactPubkeys, 0);
        }
    }

    /**
     * Check for new transactions since last check
     *
     * @param int $lastCheckTime
     * @return bool
     */
    public function checkForNewTransactions(int $lastCheckTime): bool
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return false;
        }

        try {
            global $user;

            $userAddresses = [];
            if (isset($user['hostname'])) {
                $userAddresses[] = $user['hostname'];
            }
            if (isset($user['torAddress'])) {
                $userAddresses[] = $user['torAddress'];
            }

            if (empty($userAddresses)) {
                return false;
            }

            // Create placeholders for IN clause
            $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

            $query = "SELECT COUNT(*) as count FROM transactions
                      WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                      AND timestamp > ?";

            $stmt = $pdo->prepare($query);

            // Bind parameters - addresses twice for both IN clauses, then timestamp
            $params = array_merge($userAddresses, $userAddresses, [date('Y-m-d H:i:s', $lastCheckTime)]);
            $stmt->execute($params);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
        } catch (Exception $e) {
            error_log("Error checking for new transactions: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Save a new transaction
     *
     * @param array $transaction_data
     * @return bool
     */
    public function saveTransaction(array $transaction_data): bool
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return false;
        }

        try {
            // This is a placeholder - actual implementation depends on your database schema
            $stmt = $pdo->prepare("INSERT INTO transactions (sender_address, receiver_address, amount, currency, timestamp) VALUES (?, ?, ?, ?, ?)");
            return $stmt->execute([
                $transaction_data['sender_address'] ?? '',
                $transaction_data['receiver_address'] ?? '',
                $transaction_data['amount'] ?? 0,
                $transaction_data['currency'] ?? 'USD',
                $transaction_data['timestamp'] ?? date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Error saving transaction: " . $e->getMessage());
            return false;
        }
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
     * Find transaction by ID
     *
     * @param int $transaction_id
     * @return array|null
     */
    public function findById(int $transaction_id): ?array
    {
        $pdo = $this->getPDOConnection();
        if ($pdo === null) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
            $stmt->execute([$transaction_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (Exception $e) {
            error_log("Error finding transaction: " . $e->getMessage());
            return null;
        }
    }
}
