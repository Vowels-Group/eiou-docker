<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use Eiou\Core\Constants;
use PDO;
use PDOException;

/**
 * Remember Token Repository
 *
 * Backing store for the GUI "Remember me" login feature. Each row is a
 * one-device rotation token — the raw token value lives only in the
 * user's cookie; the DB stores SHA-256 of it so a DB leak cannot hand
 * out live sessions. Every successful consume rotates (revokes the old
 * row, inserts a new one) so a stolen cookie is invalidated the moment
 * the real owner logs in again.
 */
class RememberTokenRepository extends AbstractRepository
{
    protected array $allowedColumns = [
        'id', 'token_hash', 'pubkey_hash', 'user_agent_family',
        'created_at', 'last_used_at', 'expires_at', 'revoked'
    ];

    public function __construct(?PDO $pdo = null)
    {
        parent::__construct($pdo);
        $this->tableName = 'remember_tokens';
        $this->primaryKey = 'id';
    }

    /**
     * Insert a new remember-me token.
     *
     * @param string $tokenHash SHA-256 hex of the raw token
     * @param string $pubkeyHash Owner's pubkey hash
     * @param string|null $userAgentFamily Short UA family string (truncated, no IP)
     * @param int $lifetimeSeconds Seconds until expires_at
     * @return bool True on success
     */
    public function create(string $tokenHash, string $pubkeyHash, ?string $userAgentFamily, int $lifetimeSeconds): bool
    {
        $query = "INSERT INTO {$this->tableName}
                  (token_hash, pubkey_hash, user_agent_family, expires_at)
                  VALUES (:token_hash, :pubkey_hash, :user_agent_family,
                          DATE_ADD(NOW(6), INTERVAL :lifetime SECOND))";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':token_hash', $tokenHash, PDO::PARAM_STR);
            $stmt->bindValue(':pubkey_hash', $pubkeyHash, PDO::PARAM_STR);
            $stmt->bindValue(':user_agent_family', $userAgentFamily,
                $userAgentFamily === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':lifetime', $lifetimeSeconds, PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            $this->logError("Failed to create remember token", $e);
            return false;
        }
    }

    /**
     * Look up an active (non-revoked, non-expired) token row by its hash.
     * Returns null if missing, revoked, or expired.
     */
    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE token_hash = :h
                    AND revoked = 0
                    AND expires_at > NOW(6)
                  LIMIT 1";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':h', $tokenHash, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            $this->logError("Failed to look up remember token", $e);
            return null;
        }
    }

    /**
     * Count active tokens for a user (used for device-cap enforcement).
     */
    public function countActiveForUser(string $pubkeyHash): int
    {
        $query = "SELECT COUNT(*) FROM {$this->tableName}
                  WHERE pubkey_hash = :h AND revoked = 0 AND expires_at > NOW(6)";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':h', $pubkeyHash, PDO::PARAM_STR);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Failed to count remember tokens", $e);
            return 0;
        }
    }

    /**
     * List active tokens for a user, newest-used first.
     */
    public function listActiveForUser(string $pubkeyHash): array
    {
        $query = "SELECT * FROM {$this->tableName}
                  WHERE pubkey_hash = :h AND revoked = 0 AND expires_at > NOW(6)
                  ORDER BY last_used_at DESC, id DESC";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':h', $pubkeyHash, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $this->logError("Failed to list remember tokens", $e);
            return [];
        }
    }

    /**
     * Revoke a single token by its hash. Returns true if a row was updated.
     */
    public function revokeByTokenHash(string $tokenHash): bool
    {
        $query = "UPDATE {$this->tableName} SET revoked = 1
                  WHERE token_hash = :h AND revoked = 0";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':h', $tokenHash, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Failed to revoke remember token", $e);
            return false;
        }
    }

    /**
     * Revoke a token identified by its primary key (used for
     * sign-out-a-specific-device UI action).
     */
    public function revokeById(int $id, string $pubkeyHash): bool
    {
        // pubkey_hash scoping prevents cross-user revocation in the (unlikely)
        // case where a stolen session tried to revoke someone else's token.
        $query = "UPDATE {$this->tableName} SET revoked = 1
                  WHERE id = :id AND pubkey_hash = :h AND revoked = 0";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':h', $pubkeyHash, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Failed to revoke remember token by id", $e);
            return false;
        }
    }

    /**
     * Revoke every active token belonging to a user. Returns the count.
     * Called on "Sign out everywhere" and on seed-restore.
     */
    public function revokeAllForUser(string $pubkeyHash): int
    {
        $query = "UPDATE {$this->tableName} SET revoked = 1
                  WHERE pubkey_hash = :h AND revoked = 0";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':h', $pubkeyHash, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to revoke all remember tokens", $e);
            return 0;
        }
    }

    /**
     * Revoke the oldest active token for a user (LRU eviction when the
     * device cap is exceeded). Returns true if one was revoked.
     */
    public function revokeOldestForUser(string $pubkeyHash): bool
    {
        $query = "UPDATE {$this->tableName} SET revoked = 1
                  WHERE id = (
                      SELECT id FROM (
                          SELECT id FROM {$this->tableName}
                          WHERE pubkey_hash = :h AND revoked = 0 AND expires_at > NOW(6)
                          ORDER BY last_used_at ASC, id ASC
                          LIMIT 1
                      ) t
                  )";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':h', $pubkeyHash, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Failed to evict oldest remember token", $e);
            return false;
        }
    }

    /**
     * Delete rows whose expires_at or revoked-at (approximated via
     * last_used_at for revoked rows) is older than the retention window.
     * Called from CleanupService on its regular sweep.
     */
    public function pruneExpired(int $retentionDays = Constants::CLEANUP_REMEMBER_TOKEN_RETENTION_DAYS): int
    {
        $query = "DELETE FROM {$this->tableName}
                  WHERE (revoked = 1 AND last_used_at < DATE_SUB(NOW(6), INTERVAL :days DAY))
                     OR expires_at < DATE_SUB(NOW(6), INTERVAL :days2 DAY)";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':days',  $retentionDays, PDO::PARAM_INT);
            $stmt->bindValue(':days2', $retentionDays, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError("Failed to prune remember tokens", $e);
            return 0;
        }
    }
}
