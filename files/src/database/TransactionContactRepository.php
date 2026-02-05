<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Formatters\TransactionFormatter;
use PDO;
use PDOException;

/**
 * Transaction Contact Repository
 *
 * Manages contact-related transaction operations from the transactions table.
 * Extracted from TransactionRepository for better separation of concerns.
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
class TransactionContactRepository extends AbstractRepository {
    use QueryBuilder;

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

        try {
            // Calculate sent to this contact
            $query = "SELECT COALESCE(SUM(amount), 0) as sent FROM {$this->tableName} WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ? AND status = 'completed'";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$userHash, $contactHash]);
            $sent = $stmt->fetch(PDO::FETCH_ASSOC)['sent'];

            // Calculate received from this contact
            $query = "SELECT COALESCE(SUM(amount), 0) as received FROM {$this->tableName} WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ? AND status = 'completed'";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute([$contactHash, $userHash]);
            $received = $stmt->fetch(PDO::FETCH_ASSOC)['received'];

            return $received - $sent;
        } catch (PDOException $e) {
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
                    AND status = 'completed'
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
                    AND status = 'completed'
                GROUP BY sender_public_key_hash
            ) as balance_calc
            GROUP BY contact_hash
        ";

        // Prepare parameters: userHash, contactHashes, userHash, contactHashes
        $params = array_merge([$userHash], $contactHashes, [$userHash], $contactHashes);
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
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

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
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
}
