<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Database\Traits\QueryBuilder;
use Eiou\Core\Constants;
use PDO;
use PDOException;

/**
 * P2P Repository
 *
 * Manages all database interactions for the p2p table.
 *
 * @package Database\Repository
 */
class P2pRepository extends AbstractRepository {
    use QueryBuilder;
    /**
     * @var array Allowed column names for SQL injection prevention
     */
    protected array $allowedColumns = [
        'id', 'hash', 'salt', 'time', 'expiration', 'currency', 'amount',
        'my_fee_amount', 'rp2p_amount', 'destination_address', 'destination_pubkey',
        'destination_signature', 'request_level', 'max_request_level',
        'sender_public_key', 'sender_address', 'sender_signature',
        'description', 'fast', 'contacts_sent_count', 'contacts_responded_count', 'hop_wait', 'contacts_relayed_count', 'contacts_relayed_responded_count',
        'phase1_sent', 'status', 'sending_started_at', 'sending_worker_pid',
        'created_at', 'incoming_txid', 'outgoing_txid', 'completed_at'
    ];

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
        return $p2p && ($p2p['status'] ?? '') === Constants::STATUS_COMPLETED;
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
        $status = $request['status'] ?? Constants::STATUS_INITIAL;

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
            'description' => $description, // Privacy: Only stored locally, never sent to relay nodes
            'fast' => (int)($request['fast'] ?? 1),
            'hop_wait' => (int)($request['hopWait'] ?? 0)
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
                "status" => Constants::STATUS_REJECTED,
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
    public function getExpiringP2pMessages(int $limit = Constants::P2P_EXPIRING_BATCH_SIZE): array {
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
    public function getQueuedP2pMessages(string $status = 'queued', int $limit = Constants::P2P_QUEUE_BATCH_SIZE): array {
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

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':status', $status);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logError("Failed to count P2P messages with status: {$status}", $e);
            return 0;
        }

        $result = $stmt->fetchColumn();
        return (int) ($result ?? 0);
    }

    /**
     * Retrieve credit currently on hold in P2P for a pubkey
     *
     * @param string $pubkey Sender pubkey
     * @return int Total amount on hold
     */
    public function getCreditInP2p(string $pubkey, ?string $currency = null): int {
        $query = "SELECT SUM(amount) as total_amount FROM {$this->tableName}
                  WHERE sender_public_key = :pubkey
                    AND status NOT IN ('cancelled', 'completed', 'expired')";
        $params = [':pubkey' => $pubkey];

        if ($currency !== null) {
            $query .= " AND currency = :currency";
            $params[':currency'] = $currency;
        }

        $stmt = $this->execute($query, $params);

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
     * Get users earnings through fees grouped by currency
     *
     * @return array Array of ['currency' => string, 'total_amount' => int] rows
     */
    public function getUserTotalEarningsByCurrency(): array {
        $query = "SELECT currency, SUM(my_fee_amount) as total_amount FROM {$this->tableName} WHERE status = 'completed' GROUP BY currency";
        $stmt = $this->execute($query);

        if (!$stmt) {
            return [];
        }

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ?: [];
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
                    AND status NOT IN ('completed', 'expired', 'cancelled', 'found')
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
     * Get expired originator P2Ps that have rp2p candidates but haven't been processed yet
     *
     * Used by CleanupService as a fallback: after a grace period, selects the best route
     * for originator P2Ps even if not all contacts responded (some paths may be dead).
     *
     * @param int $currentMicrotime Current microtime
     * @param int $gracePeriod Grace period in microtime-int units (default 300000 = ~30s)
     * @return array Array of expired originator P2P messages with candidates
     */
    public function getExpiredOriginatorP2psWithCandidates(int $currentMicrotime, int $gracePeriod = 300000): array {
        $query = "SELECT p.* FROM {$this->tableName} p
                  INNER JOIN rp2p_candidates rc ON rc.hash = p.hash
                  LEFT JOIN rp2p r ON r.hash = p.hash
                  WHERE p.status = :status
                    AND p.destination_address IS NOT NULL
                    AND p.fast = 0
                    AND (p.expiration + :gracePeriod) < :currentTime
                    AND r.hash IS NULL
                  GROUP BY p.hash
                  ORDER BY p.created_at ASC";

        $stmt = $this->pdo->prepare($query);
        $stmt->bindValue(':status', Constants::STATUS_EXPIRED, PDO::PARAM_STR);
        $stmt->bindValue(':gracePeriod', $gracePeriod, PDO::PARAM_INT);
        $stmt->bindValue(':currentTime', $currentMicrotime, PDO::PARAM_INT);

        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve expired originator P2Ps with candidates", $e);
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
     * Update P2P sender address
     *
     * Used when the actual transaction sender differs from the originally stored
     * sender, e.g. in multi-path routing where the chosen route uses a different
     * upstream node than the first one that relayed the P2P request.
     *
     * @param string $hash P2P hash
     * @param string $senderAddress New sender address
     * @return bool Success status
     */
    public function updateSenderAddress(string $hash, string $senderAddress): bool {
        $affectedRows = $this->update(['sender_address' => $senderAddress], 'hash', $hash);
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
     * Update contacts sent count for a P2P hash
     *
     * @param string $hash P2P hash
     * @param int $count Number of contacts sent to
     * @return bool Success status
     */
    public function updateContactsSentCount(string $hash, int $count): bool {
        $affectedRows = $this->update(['contacts_sent_count' => $count], 'hash', $hash);
        return $affectedRows >= 0;
    }

    /**
     * Update contacts relayed count for a P2P hash
     *
     * Tracks the number of contacts that returned 'already_relayed' during broadcast.
     * Used by two-phase best-fee selection to know when all relayed contacts have responded.
     *
     * @param string $hash P2P hash
     * @param int $count Number of already_relayed contacts
     * @return bool Success status
     */
    public function updateContactsRelayedCount(string $hash, int $count): bool {
        $affectedRows = $this->update(['contacts_relayed_count' => $count], 'hash', $hash);
        return $affectedRows >= 0;
    }

    /**
     * Atomically increment the contacts responded count for a P2P hash
     *
     * @param string $hash P2P hash
     * @return bool Success status
     */
    public function incrementContactsRespondedCount(string $hash): bool {
        $query = "UPDATE {$this->tableName}
                  SET contacts_responded_count = contacts_responded_count + 1
                  WHERE hash = :hash";

        $stmt = $this->execute($query, [':hash' => $hash]);
        return $stmt !== false;
    }

    /**
     * Atomically increment the relayed contacts responded count for a P2P hash
     *
     * Tracks responses from contacts that returned 'already_relayed' during broadcast.
     * Used for phase 2 trigger: inserted + relayed responses >= sentCount + relayedCount.
     *
     * @param string $hash P2P hash
     * @return bool Success status
     */
    public function incrementContactsRelayedRespondedCount(string $hash): bool {
        $query = "UPDATE {$this->tableName}
                  SET contacts_relayed_responded_count = contacts_relayed_responded_count + 1
                  WHERE hash = :hash";

        $stmt = $this->execute($query, [':hash' => $hash]);
        return $stmt !== false;
    }

    /**
     * Mark Phase 1 as sent for a P2P hash
     *
     * Prevents Phase 1 (sendBestCandidateToRelayedContacts) from re-triggering
     * when additional RP2P candidates arrive after it has already fired.
     *
     * @param string $hash P2P hash
     * @return bool Success status
     */
    public function markPhase1Sent(string $hash): bool {
        $affectedRows = $this->update(['phase1_sent' => 1], 'hash', $hash);
        return $affectedRows >= 0;
    }

    /**
     * Get tracking counts for a P2P hash (sent count, responded count, fast flag)
     *
     * @param string $hash P2P hash
     * @return array|null Array with contacts_sent_count, contacts_responded_count, contacts_relayed_count, contacts_relayed_responded_count, fast or null
     */
    public function getTrackingCounts(string $hash): ?array {
        $query = "SELECT contacts_sent_count, contacts_responded_count, contacts_relayed_count, contacts_relayed_responded_count, phase1_sent, fast
                  FROM {$this->tableName}
                  WHERE hash = :hash";

        $stmt = $this->execute($query, [':hash' => $hash]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Atomically claim a queued P2P for processing by a worker
     *
     * Uses WHERE status='queued' to prevent race conditions between workers.
     * Only one worker can successfully claim a given P2P hash.
     *
     * @param string $hash P2P hash to claim
     * @param int $pid Worker process ID
     * @return bool True if claim succeeded (this worker owns it), false if already claimed
     */
    public function claimQueuedP2p(string $hash, int $pid): bool {
        $query = "UPDATE {$this->tableName}
                  SET status = :newStatus,
                      sending_started_at = CURRENT_TIMESTAMP(6),
                      sending_worker_pid = :pid
                  WHERE hash = :hash AND status = :currentStatus";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':newStatus', Constants::STATUS_SENDING, PDO::PARAM_STR);
            $stmt->bindValue(':pid', $pid, PDO::PARAM_INT);
            $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':currentStatus', Constants::STATUS_QUEUED, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            $this->logError("Failed to claim queued P2P", $e);
            return false;
        }
    }

    /**
     * Find P2Ps stuck in 'sending' status beyond the timeout threshold
     *
     * @param int $timeoutSeconds Seconds after which a sending P2P is considered stuck
     * @return array Array of stuck P2P records
     */
    public function getStuckSendingP2ps(int $timeoutSeconds = Constants::P2P_SENDING_TIMEOUT_SECONDS): array {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE status = :status
                    AND sending_started_at < DATE_SUB(NOW(6), INTERVAL :timeout SECOND)
                  ORDER BY sending_started_at ASC";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':status', Constants::STATUS_SENDING, PDO::PARAM_STR);
            $stmt->bindValue(':timeout', $timeoutSeconds, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve stuck sending P2Ps", $e);
            return [];
        }
    }

    /**
     * Recover a stuck P2P by resetting it to queued status
     *
     * Checks if the worker PID is still alive before recovering.
     * If the worker is dead, resets to queued for reprocessing.
     *
     * @param string $hash P2P hash to recover
     * @return bool True if recovered (reset to queued), false otherwise
     */
    public function recoverStuckP2p(string $hash): bool {
        $p2p = $this->getByHash($hash);
        if (!$p2p || $p2p['status'] !== Constants::STATUS_SENDING) {
            return false;
        }

        $workerPid = (int) ($p2p['sending_worker_pid'] ?? 0);

        // If worker PID is set and still alive, don't recover — it's still working
        if ($workerPid > 0 && @posix_kill($workerPid, 0)) {
            return false;
        }

        // Worker is dead — reset to queued for reprocessing
        $query = "UPDATE {$this->tableName}
                  SET status = :newStatus,
                      sending_started_at = NULL,
                      sending_worker_pid = NULL
                  WHERE hash = :hash AND status = :currentStatus";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':newStatus', Constants::STATUS_QUEUED, PDO::PARAM_STR);
            $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
            $stmt->bindValue(':currentStatus', Constants::STATUS_SENDING, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            $this->logError("Failed to recover stuck P2P", $e);
            return false;
        }
    }

    /**
     * Clear sending metadata after a worker completes processing
     *
     * @param string $hash P2P hash
     * @return bool Success status
     */
    public function clearSendingMetadata(string $hash): bool {
        $query = "UPDATE {$this->tableName}
                  SET sending_started_at = NULL,
                      sending_worker_pid = NULL
                  WHERE hash = :hash";

        $stmt = $this->execute($query, [':hash' => $hash]);
        return $stmt !== false;
    }

    /**
     * Set the RP2P total amount on a P2P record
     *
     * Used when auto-accept is disabled to store the total cost
     * (including all relay fees) for the approval UI.
     *
     * @param string $hash P2P hash
     * @param int $rp2pAmount Total amount from RP2P response
     * @return bool Success status
     */
    public function setRp2pAmount(string $hash, int $rp2pAmount): bool {
        $affectedRows = $this->update(['rp2p_amount' => $rp2pAmount], 'hash', $hash);
        return $affectedRows >= 0;
    }

    /**
     * Get a P2P record that is awaiting approval
     *
     * @param string $hash P2P hash
     * @return array|null P2P data or null if not found or not in awaiting_approval status
     */
    public function getAwaitingApproval(string $hash): ?array {
        $query = "SELECT * FROM {$this->tableName} WHERE hash = :hash AND status = :status";
        $stmt = $this->execute($query, [':hash' => $hash, ':status' => Constants::STATUS_AWAITING_APPROVAL]);

        if (!$stmt) {
            return null;
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get all P2P records awaiting user approval (originator only)
     *
     * Returns P2Ps where this node is the originator (has destination_address set)
     * and the status is 'awaiting_approval'.
     *
     * @return array Array of awaiting approval P2P records
     */
    public function getAwaitingApprovalList(): array {
        $query = "SELECT hash, amount, currency, destination_address, my_fee_amount,
                         rp2p_amount, fast, created_at
                  FROM {$this->tableName}
                  WHERE status = :status
                    AND destination_address IS NOT NULL
                  ORDER BY created_at ASC";

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':status', Constants::STATUS_AWAITING_APPROVAL, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Failed to retrieve awaiting approval P2P list", $e);
            return [];
        }
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