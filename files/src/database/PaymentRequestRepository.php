<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;
use PDOException;

/**
 * Payment Request Repository
 *
 * Handles all database operations for the payment_requests table.
 * Stores both incoming (requests sent to us) and outgoing (requests we sent) records.
 *
 * History and search reads UNION the live table with `payment_requests_archive`
 * so archived rows remain queryable transparently to callers. Pending-only
 * reads (`getPendingIncoming`, `countPendingIncoming`) intentionally query
 * the live table only — pending rows are never archived.
 */
class PaymentRequestRepository extends AbstractRepository
{
    protected $tableName = 'payment_requests';
    private const ARCHIVE_TABLE = 'payment_requests_archive';
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
     * Get a request by its unique request_id. Falls through to the archive
     * if the row isn't in the live table — audit paths (tx detail → linked
     * request, old txid resolution) keep working after archival.
     */
    public function getByRequestId(string $requestId): ?array
    {
        $row = $this->findByColumn('request_id', $requestId);
        if ($row !== null) {
            return $row;
        }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM " . self::ARCHIVE_TABLE . " WHERE request_id = :rid LIMIT 1"
        );
        $stmt->bindValue(':rid', $requestId);
        $stmt->execute();
        $archiveRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$archiveRow) {
            return null;
        }
        $mapped = $this->mapRows([$archiveRow]);
        return $mapped[0] ?? null;
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
        // UNION ALL across live + archive so paginated history includes
        // archived rows without the caller knowing. Archive rows are always
        // non-pending by construction, so the status filter is only needed
        // on the live side. Column list kept identical on both sides so the
        // union is well-formed regardless of the archive-specific `archived_at`.
        $sql = "
            SELECT id, request_id, direction, status, requester_pubkey_hash,
                   requester_address, contact_name, recipient_pubkey_hash,
                   amount_whole, amount_frac, currency, description,
                   created_at, expires_at, responded_at, resulting_txid,
                   signed_message_content
              FROM {$this->tableName}
             WHERE status != 'pending'
            UNION ALL
            SELECT id, request_id, direction, status, requester_pubkey_hash,
                   requester_address, contact_name, recipient_pubkey_hash,
                   amount_whole, amount_frac, currency, description,
                   created_at, expires_at, responded_at, resulting_txid,
                   signed_message_content
              FROM " . self::ARCHIVE_TABLE . "
            ORDER BY COALESCE(responded_at, created_at) DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', max(0, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            // Archive table may not exist yet on a freshly-migrated node — fall
            // back to live-only so history pagination keeps working.
            $fallback = $this->pdo->prepare(
                "SELECT * FROM {$this->tableName}
                 WHERE status != 'pending'
                 ORDER BY COALESCE(responded_at, created_at) DESC
                 LIMIT :limit OFFSET :offset"
            );
            $fallback->bindValue(':limit', max(0, $limit), PDO::PARAM_INT);
            $fallback->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
            $fallback->execute();
            return $this->mapRows($fallback->fetchAll(PDO::FETCH_ASSOC));
        }
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

        // Match expression shared by both sides of the UNION. Each side owns
        // its own set of bound params because PDO positional-vs-named bindings
        // cannot be reused across subqueries.
        $matchExpr = "(
                 LOWER(COALESCE(contact_name, '')) LIKE %s
              OR LOWER(COALESCE(description,  '')) LIKE %s
              OR LOWER(COALESCE(requester_pubkey_hash, '')) LIKE %s
              OR LOWER(COALESCE(recipient_pubkey_hash, '')) LIKE %s
              OR LOWER(COALESCE(requester_address, '')) LIKE %s
            )";

        $liveMatch    = sprintf($matchExpr, ':lterm1', ':lterm2', ':lterm3', ':lterm4', ':lterm5');
        $archiveMatch = sprintf($matchExpr, ':aterm1', ':aterm2', ':aterm3', ':aterm4', ':aterm5');

        $liveExtra = '';
        $archiveExtra = '';
        $params = [
            ':lterm1' => $like, ':lterm2' => $like, ':lterm3' => $like,
            ':lterm4' => $like, ':lterm5' => $like,
            ':aterm1' => $like, ':aterm2' => $like, ':aterm3' => $like,
            ':aterm4' => $like, ':aterm5' => $like,
        ];
        if ($direction !== null && $direction !== '') {
            $liveExtra    .= " AND direction = :lDir";
            $archiveExtra .= " AND direction = :aDir";
            $params[':lDir'] = $direction;
            $params[':aDir'] = $direction;
        }
        if ($status !== null && $status !== '') {
            $liveExtra    .= " AND status = :lStatus";
            $archiveExtra .= " AND status = :aStatus";
            $params[':lStatus'] = $status;
            $params[':aStatus'] = $status;
        }

        $cols = "id, request_id, direction, status, requester_pubkey_hash,
                 requester_address, contact_name, recipient_pubkey_hash,
                 amount_whole, amount_frac, currency, description,
                 created_at, expires_at, responded_at, resulting_txid,
                 signed_message_content";

        $query = "
            SELECT {$cols} FROM {$this->tableName}
             WHERE status != 'pending'
               AND {$liveMatch}{$liveExtra}
            UNION ALL
            SELECT {$cols} FROM " . self::ARCHIVE_TABLE . "
             WHERE {$archiveMatch}{$archiveExtra}
            ORDER BY COALESCE(responded_at, created_at) DESC
            LIMIT :maxResults";

        $stmt = $this->pdo->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':maxResults', max(1, $maxResults), PDO::PARAM_INT);
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            // Archive table missing (fresh node before migration ran) — retry
            // against live only so search stays functional.
            $liveOnly = "SELECT {$cols} FROM {$this->tableName}
                          WHERE status != 'pending'
                            AND {$liveMatch}{$liveExtra}
                          ORDER BY COALESCE(responded_at, created_at) DESC
                          LIMIT :maxResults";
            $stmt = $this->pdo->prepare($liveOnly);
            foreach ($params as $k => $v) {
                if (strpos($k, ':a') === 0) {
                    continue;
                }
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':maxResults', max(1, $maxResults), PDO::PARAM_INT);
            try {
                $stmt->execute();
            } catch (PDOException $e2) {
                return [];
            }
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
