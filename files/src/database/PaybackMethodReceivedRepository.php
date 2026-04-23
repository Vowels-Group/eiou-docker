<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;

/**
 * Payback Method Received Repository
 *
 * Cache of payback methods fetched from contacts over the E2E message channel.
 * Fields are stored plaintext here — they are the contact's chosen disclosure,
 * not node-critical key material, and MariaDB TDE protects them at rest.
 *
 * Rows have an `expires_at` TTL; after expiry the contact-modal "Payback Options"
 * re-fetch flow replaces them.
 */
class PaybackMethodReceivedRepository extends AbstractRepository
{
    protected $tableName = 'payback_methods_received';
    protected array $allowedColumns = [
        'id', 'contact_pubkey_hash', 'remote_method_id',
        'type', 'label', 'currency',
        'fields_json',
        'settlement_min_unit', 'settlement_min_unit_exponent',
        'priority',
        'received_at', 'expires_at', 'revoked_at',
    ];

    /**
     * Upsert a received method. Replaces any existing row with the same
     * (contact_pubkey_hash, remote_method_id) pair.
     *
     * @param array $data Columns → values. fields_json must already be JSON string.
     * @return string|false Last insert id or affected row indicator.
     */
    public function upsertReceived(array $data)
    {
        // Try update first; if no row affected, insert.
        $existing = $this->findByPair(
            $data['contact_pubkey_hash'] ?? '',
            $data['remote_method_id'] ?? ''
        );
        if ($existing !== null) {
            $changes = $data;
            // Don't overwrite id; it's the PK.
            unset($changes['id']);
            $sql = "UPDATE {$this->tableName}
                    SET type = :type, label = :label, currency = :currency,
                        fields_json = :fields_json,
                        settlement_min_unit = :smu, settlement_min_unit_exponent = :smue,
                        priority = :priority,
                        received_at = CURRENT_TIMESTAMP(6),
                        expires_at = :expires_at,
                        revoked_at = NULL
                    WHERE contact_pubkey_hash = :contact_pubkey_hash
                      AND remote_method_id = :remote_method_id";
            $this->execute($sql, [
                ':type' => $changes['type'],
                ':label' => $changes['label'],
                ':currency' => $changes['currency'],
                ':fields_json' => $changes['fields_json'],
                ':smu' => $changes['settlement_min_unit'],
                ':smue' => $changes['settlement_min_unit_exponent'],
                ':priority' => $changes['priority'],
                ':expires_at' => $changes['expires_at'],
                ':contact_pubkey_hash' => $data['contact_pubkey_hash'],
                ':remote_method_id' => $data['remote_method_id'],
            ]);
            return (string) $existing['id'];
        }
        return $this->insert($data);
    }

    /**
     * Find a row by (contact, remote_method_id) unique pair.
     */
    public function findByPair(string $contactPubkeyHash, string $remoteMethodId): ?array
    {
        $stmt = $this->execute(
            "SELECT * FROM {$this->tableName}
             WHERE contact_pubkey_hash = :c AND remote_method_id = :r LIMIT 1",
            [':c' => $contactPubkeyHash, ':r' => $remoteMethodId]
        );
        if (!$stmt) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List non-revoked, non-expired methods for a contact.
     *
     * @param string $contactPubkeyHash
     * @param string|null $currency Optional filter by currency.
     * @return array<int, array<string, mixed>>
     */
    public function listFreshForContact(string $contactPubkeyHash, ?string $currency = null): array
    {
        $sql = "SELECT * FROM {$this->tableName}
                WHERE contact_pubkey_hash = :c
                  AND revoked_at IS NULL
                  AND expires_at > CURRENT_TIMESTAMP(6)";
        $params = [':c' => $contactPubkeyHash];
        if ($currency !== null) {
            $sql .= ' AND currency = :currency';
            $params[':currency'] = $currency;
        }
        $sql .= ' ORDER BY priority ASC, received_at DESC';

        $stmt = $this->execute($sql, $params);
        if (!$stmt) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark rows revoked by (contact, [remote_method_id...]) tuple.
     */
    public function markRevoked(string $contactPubkeyHash, array $remoteMethodIds): int
    {
        if ($remoteMethodIds === []) {
            return 0;
        }
        $placeholders = [];
        $params = [':c' => $contactPubkeyHash];
        foreach ($remoteMethodIds as $i => $rid) {
            $key = ":r{$i}";
            $placeholders[] = $key;
            $params[$key] = $rid;
        }
        $sql = "UPDATE {$this->tableName}
                SET revoked_at = CURRENT_TIMESTAMP(6)
                WHERE contact_pubkey_hash = :c
                  AND remote_method_id IN (" . implode(',', $placeholders) . ")";
        $stmt = $this->execute($sql, $params);
        return $stmt ? $stmt->rowCount() : 0;
    }

    /**
     * Delete all received methods from a contact (e.g., contact removed).
     */
    public function deleteByContact(string $contactPubkeyHash): int
    {
        return $this->delete('contact_pubkey_hash', $contactPubkeyHash);
    }

    /**
     * Has any fresh (non-expired, non-revoked) row for this contact?
     */
    public function hasFresh(string $contactPubkeyHash): bool
    {
        $stmt = $this->execute(
            "SELECT 1 FROM {$this->tableName}
             WHERE contact_pubkey_hash = :c
               AND revoked_at IS NULL
               AND expires_at > CURRENT_TIMESTAMP(6)
             LIMIT 1",
            [':c' => $contactPubkeyHash]
        );
        return $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
