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
