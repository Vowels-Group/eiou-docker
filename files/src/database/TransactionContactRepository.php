<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Formatters\TransactionFormatter;
use Eiou\Utils\Logger;
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
        'receiver_public_key_hash', 'amount_whole', 'amount_frac', 'currency', 'timestamp', 'txid',
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
     * @return SplitAmount Balance
     */
    public function getContactBalance(string $userPubkey, string $contactPubkey): SplitAmount
    {
        $userHash = hash(Constants::HASH_ALGORITHM, $userPubkey);
        $contactHash = hash(Constants::HASH_ALGORITHM, $contactPubkey);

        try {
            // UNION live + archive on each side. Archive silently contributes
            // zero rows if the table doesn't exist yet (v9→v10 transitional),
            // MySQL treats the missing-table case as a query error which we
            // swallow via the union-with-empty-select pattern below (SELECT 0,0
            // UNION ALL ...FROM archive where archive may error).
            //
            // Simpler: two separate queries, summed in PHP. Archive query
            // errors are caught and treated as 0 contribution.
            $sentLive = $this->sumAmountsFromTable($this->tableName, $userHash, $contactHash);
            $recvLive = $this->sumAmountsFromTable($this->tableName, $contactHash, $userHash);
            $sentArchive = $this->sumAmountsFromTable('transactions_archive', $userHash, $contactHash);
            $recvArchive = $this->sumAmountsFromTable('transactions_archive', $contactHash, $userHash);

            $sent     = $sentLive->add($sentArchive);
            $received = $recvLive->add($recvArchive);

            return $received->subtract($sent);
        } catch (PDOException $e) {
            Logger::getInstance()->log('Failed to get contact balance: ' . $e->getMessage(), 'WARNING');
            return SplitAmount::zero();
        }
    }

    /**
     * Private helper: SUM(amount) from one table for a specific
     * sender→receiver direction + status='completed'. Returns SplitAmount::zero()
     * on PDOException (archive table missing during v9→v10 transitional
     * period, etc.) rather than propagating — the live query is the primary
     * source of truth and should not fail merely because archive is absent.
     */
    private function sumAmountsFromTable(string $table, string $senderHash, string $receiverHash): SplitAmount
    {
        try {
            $sql = "SELECT COALESCE(SUM(amount_whole), 0) AS sum_whole,
                           COALESCE(SUM(amount_frac), 0) AS sum_frac
                    FROM {$table}
                    WHERE sender_public_key_hash = ?
                      AND receiver_public_key_hash = ?
                      AND status = 'completed'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$senderHash, $receiverHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return self::sumCarry((int) $row['sum_whole'], (int) $row['sum_frac']);
        } catch (PDOException $e) {
            // Archive-missing or other transient table issue — contribute 0.
            return SplitAmount::zero();
        }
    }


    /**
     * Get all contact balances in a single optimized query (fixes N+1 problem)
     *
     * Returns per-currency balances for each contact.
     *
     * @param string $userPubkey
     * @param array $contactPubkeys
     * @return array Associative array of pubkey => ['USD' => SplitAmount, 'GBY' => SplitAmount]
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

        // Build a single UNION query across live + archive for each direction
        // (sent / received), grouped at the outer level. Four UNION branches
        // total: live-sent, live-received, archive-sent, archive-received.
        // On a node where the archive table doesn't exist yet (fresh v9→v10
        // migration moment) the query fails; we retry without the archive
        // branches so balance display keeps working. Live is always present.
        $buildQuery = function (bool $includeArchive) use ($placeholders) {
            $liveSent = "-- Sent live
                SELECT
                    receiver_public_key_hash as contact_hash,
                    currency,
                    SUM(amount_whole) as sent_whole,
                    SUM(amount_frac) as sent_frac,
                    0 as received_whole,
                    0 as received_frac
                FROM {$this->tableName}
                WHERE sender_public_key_hash = ?
                    AND receiver_public_key_hash IN ($placeholders)
                    AND status = 'completed'
                GROUP BY receiver_public_key_hash, currency";
            $liveRecv = "-- Received live
                SELECT
                    sender_public_key_hash as contact_hash,
                    currency,
                    0 as sent_whole,
                    0 as sent_frac,
                    SUM(amount_whole) as received_whole,
                    SUM(amount_frac) as received_frac
                FROM {$this->tableName}
                WHERE receiver_public_key_hash = ?
                    AND sender_public_key_hash IN ($placeholders)
                    AND status = 'completed'
                GROUP BY sender_public_key_hash, currency";
            $branches = [$liveSent, $liveRecv];
            if ($includeArchive) {
                $archiveSent = "-- Sent archive
                    SELECT
                        receiver_public_key_hash as contact_hash,
                        currency,
                        SUM(amount_whole) as sent_whole,
                        SUM(amount_frac) as sent_frac,
                        0 as received_whole,
                        0 as received_frac
                    FROM transactions_archive
                    WHERE sender_public_key_hash = ?
                        AND receiver_public_key_hash IN ($placeholders)
                        AND status = 'completed'
                    GROUP BY receiver_public_key_hash, currency";
                $archiveRecv = "-- Received archive
                    SELECT
                        sender_public_key_hash as contact_hash,
                        currency,
                        0 as sent_whole,
                        0 as sent_frac,
                        SUM(amount_whole) as received_whole,
                        SUM(amount_frac) as received_frac
                    FROM transactions_archive
                    WHERE receiver_public_key_hash = ?
                        AND sender_public_key_hash IN ($placeholders)
                        AND status = 'completed'
                    GROUP BY sender_public_key_hash, currency";
                $branches[] = $archiveSent;
                $branches[] = $archiveRecv;
            }
            $unionBody = implode("\n\n                UNION ALL\n\n                ", $branches);
            return "
                SELECT
                    contact_hash,
                    currency,
                    SUM(sent_whole) as total_sent_whole,
                    SUM(sent_frac) as total_sent_frac,
                    SUM(received_whole) as total_received_whole,
                    SUM(received_frac) as total_received_frac
                FROM (
                    {$unionBody}
                ) as balance_calc
                GROUP BY contact_hash, currency
            ";
        };

        $liveArchiveParams = array_merge(
            [$userHash], $contactHashes,  // live sent
            [$userHash], $contactHashes,  // live received
            [$userHash], $contactHashes,  // archive sent
            [$userHash], $contactHashes   // archive received
        );
        $liveOnlyParams = array_merge(
            [$userHash], $contactHashes,
            [$userHash], $contactHashes
        );

        $stmt = null;
        try {
            $stmt = $this->pdo->prepare($buildQuery(true));
            $stmt->execute($liveArchiveParams);
        } catch (PDOException $e) {
            // Archive table missing (v9→v10 transitional) or other SQL issue
            // — retry live-only so the balance display keeps working. This is
            // a no-op when the archive is correctly set up because the first
            // try succeeds.
            try {
                $stmt = $this->pdo->prepare($buildQuery(false));
                $stmt->execute($liveOnlyParams);
            } catch (PDOException $e2) {
                Logger::getInstance()->log('Failed to get all contact balances: ' . $e2->getMessage(), 'WARNING');
                return array_fill_keys($contactPubkeys, []);
            }
        }

        // Build result array indexed by original pubkey, then by currency
        $balances = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pubkey = $hashToPubkey[$row['contact_hash']] ?? null;
            if ($pubkey) {
                if (!isset($balances[$pubkey])) {
                    $balances[$pubkey] = [];
                }
                $currency = $row['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                $received = self::sumCarry((int)$row['total_received_whole'], (int)$row['total_received_frac']);
                $sent = self::sumCarry((int)$row['total_sent_whole'], (int)$row['total_sent_frac']);
                $balance = $received->subtract($sent);
                if (isset($balances[$pubkey][$currency])) {
                    $balances[$pubkey][$currency] = $balances[$pubkey][$currency]->add($balance);
                } else {
                    $balances[$pubkey][$currency] = $balance;
                }
            }
        }

        // Ensure all contacts have a balance entry (default to empty array)
        foreach ($contactPubkeys as $pubkey) {
            if (!isset($balances[$pubkey])) {
                $balances[$pubkey] = [];
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
    public function getTransactionsWithContact(array $contactAddresses, int $limit = Constants::CONTACT_TRANSACTIONS_LIMIT): array {
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
        $query = "SELECT txid, tx_type, status, sender_address, receiver_address, amount_whole, amount_frac, currency, timestamp, memo, description
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
            Logger::getInstance()->log('Failed to get contact transactions: ' . $e->getMessage(), 'WARNING');
            return [];
        }

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return TransactionFormatter::formatContactMany($transactions, $userAddresses);
    }

    /**
     * Fetch the most recent N transactions for each of several contacts in a
     * single DB round-trip. Replaces the N+1 pattern where
     * contactBalanceConversion ran one SELECT per contact.
     *
     * Uses a ranked subquery (MariaDB 10.2+ / MySQL 8+ window functions) to
     * pick the top $limitPerContact rows per contact. Contact identity is the
     * pubkey_hash (stable across the user's multiple transport addresses).
     *
     * @param array<string> $contactPubkeyHashes Hashes of the contacts to fetch for
     * @param int $limitPerContact Max transactions per contact (default: CONTACT_TRANSACTIONS_LIMIT)
     * @return array<string, array> Map of contact pubkey_hash → array of formatted transactions (newest first)
     */
    public function getTransactionsWithContactsBatch(array $contactPubkeyHashes, int $limitPerContact = Constants::CONTACT_TRANSACTIONS_LIMIT): array
    {
        $result = array_fill_keys($contactPubkeyHashes, []);

        $userAddresses = $this->getUserAddressesOrNull();
        if ($userAddresses === null || empty($contactPubkeyHashes)) {
            return $result;
        }

        $myHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());
        $contactPlaceholders = $this->createPlaceholders($contactPubkeyHashes);

        // ROW_NUMBER() partitioned by the counterparty hash, ordered by
        // timestamp DESC — one ranked pass per contact instead of a separate
        // query per contact. Outer filter keeps only the top N per partition.
        $query = "SELECT contact_hash, txid, tx_type, status, sender_address, receiver_address,
                         amount_whole, amount_frac, currency, timestamp, memo, description
                    FROM (
                        SELECT
                            CASE WHEN sender_public_key_hash = ? THEN receiver_public_key_hash
                                 ELSE sender_public_key_hash END AS contact_hash,
                            txid, tx_type, status, sender_address, receiver_address,
                            amount_whole, amount_frac, currency, timestamp, memo, description,
                            ROW_NUMBER() OVER (
                                PARTITION BY CASE WHEN sender_public_key_hash = ?
                                                  THEN receiver_public_key_hash
                                                  ELSE sender_public_key_hash END
                                ORDER BY timestamp DESC
                            ) AS rn
                        FROM {$this->tableName}
                        WHERE (sender_public_key_hash = ? AND receiver_public_key_hash IN ($contactPlaceholders))
                           OR (sender_public_key_hash IN ($contactPlaceholders) AND receiver_public_key_hash = ?)
                    ) ranked
                    WHERE rn <= ?
                    ORDER BY contact_hash, timestamp DESC";

        $params = array_merge(
            [$myHash, $myHash, $myHash],
            $contactPubkeyHashes,
            $contactPubkeyHashes,
            [$myHash, $limitPerContact]
        );

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::getInstance()->log('Failed to batch-fetch contact transactions: ' . $e->getMessage(), 'WARNING');
            return $result;
        }

        $rowsByContact = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hash = $row['contact_hash'];
            // Drop the helper columns before formatting — keep the formatter's
            // input shape identical to getTransactionsWithContact's.
            unset($row['contact_hash']);
            $rowsByContact[$hash][] = $row;
        }

        foreach ($rowsByContact as $hash => $rows) {
            $result[$hash] = TransactionFormatter::formatContactMany($rows, $userAddresses);
        }
        return $result;
    }

    /**
     * Get the contact transaction between two specific parties
     *
     * Looks up the contact transaction where senderPubkey sent a contact request
     * to receiverPubkey. Returns the txid and signature_nonce needed for
     * generating/storing the recipient signature.
     *
     * @param string $senderPubkey The sender's public key
     * @param string $receiverPubkey The receiver's public key
     * @return array|null Array with 'txid' and 'signature_nonce', or null if not found
     */
    public function getContactTransactionByParties(string $senderPubkey, string $receiverPubkey): ?array
    {
        $senderHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);
        $receiverHash = hash(Constants::HASH_ALGORITHM, $receiverPubkey);

        $query = "SELECT txid, signature_nonce, currency, description FROM {$this->tableName}
                  WHERE tx_type = 'contact'
                  AND sender_public_key_hash = :sender AND receiver_public_key_hash = :receiver
                  LIMIT 1";

        $stmt = $this->execute($query, [':sender' => $senderHash, ':receiver' => $receiverHash]);

        if (!$stmt) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get contact transaction descriptions indexed by currency for a sender/receiver pair
     *
     * @param string $senderPubkey Sender's public key
     * @param string $receiverPubkey Receiver's public key
     * @return array<string, string> Currency => description mapping
     */
    public function getContactDescriptionsByCurrency(string $senderPubkey, string $receiverPubkey): array
    {
        $senderHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);
        $receiverHash = hash(Constants::HASH_ALGORITHM, $receiverPubkey);

        $query = "SELECT currency, description FROM {$this->tableName}
                  WHERE tx_type = 'contact'
                  AND sender_public_key_hash = :sender AND receiver_public_key_hash = :receiver";

        $stmt = $this->execute($query, [':sender' => $senderHash, ':receiver' => $receiverHash]);
        if (!$stmt) {
            return [];
        }

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $desc = $row['description'] ?? null;
            if ($desc !== null && $desc !== 'Contact request' && $desc !== 'Contact request transaction') {
                $result[$row['currency']] = $desc;
            }
        }
        return $result;
    }

    /**
     * Check if a contact transaction exists for a given sender public key hash
     *
     * Used to prevent duplicate contact transactions when receiving repeated
     * contact requests from the same sender.
     *
     * @param string $senderPublicKeyHash The hash of the remote sender's public key
     * @return bool True if a contact transaction exists
     */
    public function contactTransactionExistsForReceiver(string $senderPublicKeyHash, ?string $currency = null): bool {
        $receiverPublicKeyHash = hash(Constants::HASH_ALGORITHM, $this->currentUser->getPublicKey());

        $query = "SELECT 1 FROM {$this->tableName}
                  WHERE tx_type = 'contact'
                  AND sender_public_key_hash = :sender_public_key_hash
                  AND receiver_public_key_hash = :receiver_public_key_hash";
        $params = [
            ':sender_public_key_hash' => $senderPublicKeyHash,
            ':receiver_public_key_hash' => $receiverPublicKeyHash
        ];

        if ($currency !== null) {
            $query .= " AND currency = :currency";
            $params[':currency'] = $currency;
        }

        $query .= " LIMIT 1";

        $stmt = $this->execute($query, $params);

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
     * Handle carry from summed fractional parts
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
