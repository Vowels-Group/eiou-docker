<?php
# Copyright 2025

require_once __DIR__ . '/AbstractRepository.php';

/**
 * P2P Repository
 *
 * Manages all database interactions for the p2p table.
 *
 * @package Database\Repository
 */
class P2pRepository extends AbstractRepository {
    /**
     * Constructor
     *
     * @param PDO|null $pdo Optional PDO instance for dependency injection
     */
    public function __construct(?PDO $pdo = null) {
        parent::__construct($pdo);
        $this->tableName = 'p2p';
        $this->primaryKey = 'id';
    }

    /**
     * Check if P2P is completed by hash
     *
     * @param string $hash P2P hash
     * @return bool True if completed
     */
    public function isCompletedByHash(string $hash): bool {
        $p2p = $this->getByHash($hash);
        return $p2p && ($p2p['status'] ?? '') === 'completed';
    }
 
    /**
     * Check if P2P exists by hash
     *
     * @param string $hash P2P hash
     * @return bool True if exists
     */
    public function p2pExists(string $hash): bool {
        return $this->exists('hash', $hash);
    }

    /**
     * Get P2P by hash
     *
     * @param string $hash P2P hash
     * @return array|null P2P data or null
     */
    public function getByHash(string $hash): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE hash = :hash";
        $stmt = $this->execute($query, [':hash' => $hash]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Insert a new P2P request
     *
     * @param array $request P2P request data
     * @param string|null $destinationAddress Destination address
     * @param string|null $description Optional description (only stored locally, not sent to relays)
     * @return string JSON response
     */
    public function insertP2pRequest(array $request, ?string $destinationAddress = null, ?string $description = null): string {
        $myFeeAmount = $request['feeAmount'] ?? null;
        $status = $request['status'] ?? 'initial';

        $data = [
            'hash' => $request['hash'],
            'salt' => $request['salt'],
            'time' => $request['time'],
            'expiration' => $request['expiration'],
            'currency' => $request['currency'],
            'amount' => $request['amount'],
            'my_fee_amount' => $myFeeAmount,
            'request_level' => $request['requestLevel'],
            'max_request_level' => $request['maxRequestLevel'],
            'sender_public_key' => $request['senderPublicKey'],
            'sender_address' => $request['senderAddress'],
            'sender_signature' => $request['signature'] ?? null, // upon inserting a p2p in the db of the user that created it it is null
            'destination_address' => $destinationAddress,
            'incoming_txid' => $request['incoming_txid'] ?? null,
            'outgoing_txid' => $request['outgoing_txid'] ?? null,
            'status' => $status,
            'description' => $description // Privacy: Only stored locally, never sent to relay nodes
        ];

        $result = $this->insert($data);

        if ($result !== false) {
            // Output silent logging if function exists
            if (function_exists('output') && function_exists('outputInsertedP2p')) {
                output(outputInsertedP2p($request), 'SILENT');
            }

            return json_encode([
                "status" => "received",
                "message" => "p2p recorded successfully"
            ]);
        } else {
            return json_encode([
                "status" => "rejected",
                "message" => "Failed to record p2p"
            ]);
        }
    }

    /**
     * Retrieve expiring P2P messages (not in final states)
     *
     * @param int $limit Maximum number of messages
     * @return array Array of P2P messages
     */
    public function getExpiringP2pMessages(int $limit = 5): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE status NOT IN ('completed', 'expired', 'cancelled')
                  ORDER BY created_at ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve expiring P2P messages", $e);
            return [];
        }
    }

    /**
     * Retrieve queued P2P messages by status
     *
     * @param string $status P2P status (default: 'queued')
     * @param int $limit Maximum number of messages
     * @return array Array of P2P messages
     */
    public function getQueuedP2pMessages(string $status = 'queued', int $limit = 5): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE status = :status
                  ORDER BY created_at ASC
                  LIMIT :limit";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve queued P2P messages", $e);
            return [];
        }
    }

    /**
     * Retrieve count of P2P messages by status
     *
     * @param string $status P2P status (default: 'queued')
     * @return int count of P2P messages
     */
    public function getCountP2pMessagesWithStatus(string $status = 'queued'): int {
        $query = "SELECT count(*) as count
                    FROM {$this->tableName}
                    WHERE status = :status";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':status', $status);
        $stmt->execute();

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetchColumn();
        return (int) $result ?? 0;
    }

    /**
     * Retrieve credit currently on hold in P2P for a pubkey
     *
     * @param string $pubkey Sender pubkey
     * @return int Total amount on hold
     */
    public function getCreditInP2p(string $pubkey): int {
        $query = "SELECT SUM(amount) as total_amount FROM {$this->tableName}
                  WHERE sender_public_key = :pubkey
                    AND status NOT IN ('cancelled', 'completed', 'expired')";
        $stmt = $this->execute($query, [':pubkey' => $pubkey]);

        if (!$stmt) {
            return 0;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['total_amount'] ?? 0);
    }

    /**
     * Get users earnings through fees
     *
     * @return string Fee Balance 
     */
    function getUserTotalEarnings() {
        $query = "SELECT SUM(my_fee_amount) as total_amount FROM {$this->tableName} WHERE status = 'completed'";
        $stmt = $this->execute($query);
        
        if (!$stmt) {
            return 0.00;
        }
        
        $balance = $stmt->fetchColumn();
        return $balance ?? 0.00;
    }

    /**
     * Update incoming txid for P2P
     *
     * @param string $hash P2P hash
     * @param string $txid Transaction ID
     * @return bool Success status
     */
    public function updateIncomingTxid(string $hash, string $txid): bool {
        $affectedRows = $this->update(['incoming_txid' => $txid], 'hash', $hash);

        // Output silent logging if function exists
        if (function_exists('output') && function_exists('outputUpdatedTxid')) {
            output(outputUpdatedTxid($txid, 'incoming_txid', $hash), 'SILENT');
        }

        return $affectedRows >= 0;
    }

    /**
     * Update outgoing txid for P2P
     *
     * @param string $hash P2P hash
     * @param string $txid Transaction ID
     * @return bool Success status
     */
    public function updateOutgoingTxid(string $hash, string $txid): bool {
        $affectedRows = $this->update(['outgoing_txid' => $txid], 'hash', $hash);

        // Output silent logging if function exists
        if (function_exists('output') && function_exists('outputUpdatedTxid')) {
            output(outputUpdatedTxid($txid, 'outgoing_txid', $hash), 'SILENT');
        }
        return $affectedRows >= 0;
    }

    /**
     * Update P2P request status
     *
     * @param string $hash P2P hash
     * @param string $status New status
     * @param bool $completed Whether to set completed timestamp
     * @return bool Success status
     */
    public function updateStatus(string $hash, string $status, bool $completed = false): bool {
        $data = ['status' => $status];

        if ($completed) {
            // Use raw SQL for CURRENT_TIMESTAMP
            $query = "UPDATE {$this->tableName}
                      SET status = :status, completed_at = CURRENT_TIMESTAMP(6)
                      WHERE hash = :hash";
            $stmt = $this->execute($query, [':status' => $status, ':hash' => $hash]);
            $success = $stmt !== false;
        } else {
            $affectedRows = $this->update($data, 'hash', $hash);
            $success = $affectedRows >= 0;
        }

        // Output silent logging if function exists
        if (function_exists('output') && function_exists('outputP2pStatusUpdated')) {
            output(outputP2pStatusUpdated($status, $hash), 'SILENT');
        }

        return $success;
    }

    /**
     * Get P2P messages by status
     *
     * @param string $status P2P status
     * @param int $limit Maximum number of messages
     * @return array Array of P2P messages
     */
    public function getByStatus(string $status, int $limit = 0): array {
        return $this->findManyByColumn('status', $status, $limit);
    }

    /**
     * Get P2P messages for a sender address
     *
     * @param string $address Sender address
     * @param int $limit Maximum number of messages
     * @return array Array of P2P messages
     */
    public function getBySenderAddress(string $address, int $limit = 0): array {
        return $this->findManyByColumn('sender_address', $address, $limit);
    }

    /**
     * Get P2P messages for a destination address
     *
     * @param string $address Destination address
     * @param int $limit Maximum number of messages
     * @return array Array of P2P messages
     */
    public function getByDestinationAddress(string $address, int $limit = 0): array {
        return $this->findManyByColumn('destination_address', $address, $limit);
    }

    /**
     * Get expired P2P messages
     *
     * Retrieves P2P messages that have exceeded their expiration time.
     * Note: expiration is stored as microtime * TIME_MICROSECONDS_TO_INT,
     * so we pass the current microtime from PHP for accurate comparison.
     *
     * @param int $currentMicrotime Current microtime (from TimeUtilityService::getCurrentMicrotime())
     * @param int $limit Maximum number of messages
     * @return array Array of expired P2P messages
     */
    public function getExpiredP2p(int $currentMicrotime, int $limit = 0): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE expiration < :currentTime
                    AND status NOT IN ('completed', 'expired', 'cancelled')
                  ORDER BY created_at ASC";

        if ($limit > 0) {
            $query .= " LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':currentTime', $currentMicrotime, PDO::PARAM_INT);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve expired P2P messages", $e);
            return [];
        }
    }

    /**
     * Get P2P statistics
     *
     * @return array Statistics array with counts and totals
     */
    public function getStatistics(): array {
        $query = "SELECT
                    COUNT(*) as total_count,
                    SUM(amount) as total_amount,
                    AVG(amount) as average_amount,
                    COUNT(DISTINCT sender_address) as unique_senders,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN status = 'queued' THEN 1 END) as queued_count,
                    COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent_count,
                    COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_count,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count
                  FROM {$this->tableName}";

        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: [];
    }

    /**
     * Update P2P destination address
     *
     * @param string $hash P2P hash
     * @param string $destinationAddress Destination address
     * @return bool Success status
     */
    public function updateDestinationAddress(string $hash, string $destinationAddress): bool {
        $affectedRows = $this->update(['destination_address' => $destinationAddress], 'hash', $hash);
        return $affectedRows >= 0;
    }

    /**
     * Update P2P fee amount
     *
     * @param string $hash P2P hash
     * @param float $feeAmount Fee amount
     * @return bool Success status
     */
    public function updateFeeAmount(string $hash, float $feeAmount): bool {
        $affectedRows = $this->update(['my_fee_amount' => $feeAmount], 'hash', $hash);
        return $affectedRows >= 0;
    }

    /**
     * Update P2P description
     *
     * @param string $hash P2P hash
     * @param string $description Description
     * @return bool Success status
     */
    public function updateDescription(string $hash, string $description): bool {
        $affectedRows = $this->update(['description' => $description], 'hash', $hash);

        // Output silent logging if function exists
        if (function_exists('output') && function_exists('outputP2pDescriptionUpdated')) {
            output(outputP2pDescriptionUpdated($description, $hash), 'SILENT');
        }

        return $affectedRows >= 0;
    }

    /**
     * Delete expired P2P records older than specified days
     *
     * @param int $days Number of days to keep
     * @return int Number of deleted records
     */
    public function deleteOldExpired(int $days = 30): int {
        $query = "DELETE FROM {$this->tableName}
                  WHERE status = 'expired'
                    AND completed_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to delete old expired P2P records", $e);
            return 0;
        }
    }
}