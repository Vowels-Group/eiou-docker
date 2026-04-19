<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;

/**
 * Payment Request Repository
 *
 * Handles all database operations for the payment_requests table.
 * Stores both incoming (requests sent to us) and outgoing (requests we sent) records.
 */
class PaymentRequestRepository extends AbstractRepository
{
    protected $tableName = 'payment_requests';
    protected array $allowedColumns = [
        'id', 'request_id', 'direction', 'status',
        'requester_pubkey_hash', 'requester_address', 'contact_name',
        'recipient_pubkey_hash',
        'amount_whole', 'amount_frac', 'currency', 'description',
        'created_at', 'expires_at', 'responded_at',
        'resulting_txid', 'signed_message_content',
    ];
    protected array $splitAmountColumns = ['amount'];

    /**
     * Create a new payment request record
     */
    public function createRequest(array $data): string|false
    {
        return $this->insert($data);
    }

    /**
     * Get a request by its unique request_id
     */
    public function getByRequestId(string $requestId): ?array
    {
        return $this->findByColumn('request_id', $requestId);
    }

    /**
     * Get all incoming requests with status=pending, newest first
     */
    public function getPendingIncoming(): array
    {
        $stmt = $this->execute(
            "SELECT * FROM {$this->tableName} WHERE direction = 'incoming' AND status = 'pending' ORDER BY created_at DESC"
        );
        if (!$stmt) {
            return [];
        }
        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get all incoming requests (all statuses), newest first
     */
    public function getAllIncoming(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tableName} WHERE direction = 'incoming' ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get all outgoing requests (all statuses), newest first
     */
    public function getAllOutgoing(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tableName} WHERE direction = 'outgoing' ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get all requests (both directions), newest first
     */
    public function getAll(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tableName} ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get a page of resolved (non-pending) requests across both directions,
     * newest first. Backs the Payment Requests history paginator — the
     * pending section is rendered separately from its own unbounded fetch,
     * so the history table only needs terminal states.
     *
     * Server-sorted by `COALESCE(responded_at, created_at) DESC` so the
     * ordering matches what the initial template render sorts to (see
     * `paymentRequestsSection.html`'s `usort` on the combined array) —
     * guarantees that successive "Load older" pages append cleanly
     * without interleaving.
     *
     * @param int $limit  Max rows per page
     * @param int $offset Zero-based offset (0 returns the first page)
     * @return array Rows as returned by mapRows(); each row already has
     *               the `direction` column set, so callers can tag it
     *               onto the `$req['_direction']` the row partial expects
     */
    public function getResolvedHistoryPage(int $limit, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tableName}
             WHERE status != 'pending'
             ORDER BY COALESCE(responded_at, created_at) DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', max(0, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Database-wide search across resolved (non-pending) payment
     * requests. Backs the Payment Requests "Search entire database"
     * button.
     *
     * Matches (case-insensitive, substring) against:
     *   - `contact_name`           — snapshot stored at request time
     *   - `description`
     *   - `requester_pubkey_hash` / `recipient_pubkey_hash`
     *   - `requester_address`
     *
     * Filter dims (direction, status) mirror the client-side filter
     * selects. Result capped at $maxResults (default 500).
     *
     * @param string      $term       Substring search term
     * @param string|null $direction  'incoming' | 'outgoing' | null
     * @param string|null $status     'approved' | 'declined' | 'cancelled' | 'expired' | null
     * @param int         $maxResults Hard cap on returned rows
     * @return array Rows as mapRows()
     */
    public function searchResolvedHistory(
        string $term,
        ?string $direction = null,
        ?string $status = null,
        int $maxResults = 500
    ): array {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $like = '%' . strtolower($term) . '%';

        $query = "SELECT * FROM {$this->tableName}
                  WHERE status != 'pending'
                    AND (
                         LOWER(COALESCE(contact_name, '')) LIKE :term1
                      OR LOWER(COALESCE(description,  '')) LIKE :term2
                      OR LOWER(COALESCE(requester_pubkey_hash, '')) LIKE :term3
                      OR LOWER(COALESCE(recipient_pubkey_hash, '')) LIKE :term4
                      OR LOWER(COALESCE(requester_address, '')) LIKE :term5
                    )";

        $params = [
            ':term1' => $like, ':term2' => $like, ':term3' => $like,
            ':term4' => $like, ':term5' => $like,
        ];

        if ($direction !== null && $direction !== '') {
            $query .= " AND direction = :direction";
            $params[':direction'] = $direction;
        }
        if ($status !== null && $status !== '') {
            $query .= " AND status = :status";
            $params[':status'] = $status;
        }

        $query .= " ORDER BY COALESCE(responded_at, created_at) DESC
                    LIMIT :maxResults";

        $stmt = $this->pdo->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':maxResults', max(1, $maxResults), PDO::PARAM_INT);
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            return [];
        }

        return $this->mapRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Update the status of a request, optionally setting additional fields
     *
     * @param string $requestId    The request_id to update
     * @param string $status       New status value
     * @param array  $extra        Additional columns to set (e.g. responded_at, resulting_txid)
     * @return bool True on success
     */
    public function updateStatus(string $requestId, string $status, array $extra = []): bool
    {
        $data = array_merge(['status' => $status], $extra);
        $result = $this->update($data, 'request_id', $requestId);
        return $result >= 0;
    }

    /**
     * Count pending incoming requests (for badge display)
     */
    public function countPendingIncoming(): int
    {
        $stmt = $this->execute(
            "SELECT COUNT(*) as count FROM {$this->tableName} WHERE direction = 'incoming' AND status = 'pending'"
        );
        if (!$stmt) {
            return 0;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['count'] ?? 0);
    }
}
