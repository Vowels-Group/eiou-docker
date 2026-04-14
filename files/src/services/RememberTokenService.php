<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Database\RememberTokenRepository;
use Eiou\Utils\Logger;

/**
 * Remember-me token service.
 *
 * Business layer for the GUI "Remember me" login feature. Owns token
 * minting, rotation on use, revocation, device-cap (LRU) enforcement,
 * and user-facing listing. Raw tokens only ever appear as return values
 * from issueToken() / rotateToken(); elsewhere we deal in hashes.
 */
class RememberTokenService
{
    private RememberTokenRepository $repository;

    public function __construct(RememberTokenRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Mint a fresh token for a user. If the device cap is exceeded,
     * the LRU device is revoked first. Returns the raw token — to be
     * written into the EIOU_REMEMBER cookie and never stored again.
     */
    public function issueToken(
        string $pubkeyHash,
        ?string $rawUserAgent,
        int $lifetimeDays,
        int $maxDevices
    ): ?string {
        if ($lifetimeDays <= 0 || $maxDevices <= 0) {
            return null;
        }

        // Enforce device cap with LRU eviction BEFORE inserting, so the cap
        // is respected rather than "cap + 1 then prune".
        $safety = 0;
        while ($this->repository->countActiveForUser($pubkeyHash) >= $maxDevices && $safety++ < 100) {
            if (!$this->repository->revokeOldestForUser($pubkeyHash)) {
                break;
            }
        }

        $rawToken = bin2hex(random_bytes(Constants::REMEMBER_ME_TOKEN_BYTES));
        $hash = $this->hashToken($rawToken);
        $lifetimeSeconds = max(60, $lifetimeDays * 86400);

        if (!$this->repository->create(
            $hash,
            $pubkeyHash,
            $this->summariseUserAgent($rawUserAgent),
            $lifetimeSeconds
        )) {
            return null;
        }

        Logger::getInstance()->info("Remember-me token issued", [
            'pubkey_hash' => substr($pubkeyHash, 0, 16) . '...',
            'lifetime_days' => $lifetimeDays,
            'max_devices' => $maxDevices
        ]);

        return $rawToken;
    }

    /**
     * Validate a raw token from a cookie. On success, revoke it and mint
     * a replacement (single-use rotation). Returns:
     *   ['pubkey_hash' => ..., 'new_token' => ..., 'expires_at' => ...]
     * on success, or null on miss / revoked / expired.
     *
     * The new token inherits the previous row's remaining lifetime.
     * (We don't extend on use — "remember me for 30 days" means 30 days
     *  from initial opt-in, not perpetually sliding.)
     */
    public function rotateToken(string $rawToken, ?string $rawUserAgent): ?array
    {
        $oldHash = $this->hashToken($rawToken);
        $row = $this->repository->findActiveByTokenHash($oldHash);
        if ($row === null) {
            return null;
        }

        $remainingSeconds = max(60, (int)(strtotime($row['expires_at']) - time()));
        $newRawToken = bin2hex(random_bytes(Constants::REMEMBER_ME_TOKEN_BYTES));
        $newHash = $this->hashToken($newRawToken);

        if (!$this->repository->create(
            $newHash,
            $row['pubkey_hash'],
            $this->summariseUserAgent($rawUserAgent),
            $remainingSeconds
        )) {
            return null;
        }

        $this->repository->revokeByTokenHash($oldHash);

        return [
            'pubkey_hash' => $row['pubkey_hash'],
            'new_token' => $newRawToken,
            'expires_at' => date('Y-m-d H:i:s', time() + $remainingSeconds),
            'expires_at_unix' => time() + $remainingSeconds,
        ];
    }

    public function revokeToken(string $rawToken): bool
    {
        return $this->repository->revokeByTokenHash($this->hashToken($rawToken));
    }

    public function revokeTokenById(int $id, string $pubkeyHash): bool
    {
        return $this->repository->revokeById($id, $pubkeyHash);
    }

    /**
     * Revoke every active token for a user. Used by "Sign out everywhere"
     * and by the seed-restore flow.
     */
    public function revokeAllForUser(string $pubkeyHash): int
    {
        return $this->repository->revokeAllForUser($pubkeyHash);
    }

    /**
     * List active tokens for a user, for the Active Sessions panel. If
     * $currentRawToken is provided, the matching row is tagged as
     * 'current' so the UI can render a "this device" badge.
     */
    public function listForUser(string $pubkeyHash, ?string $currentRawToken = null): array
    {
        $currentHash = $currentRawToken !== null ? $this->hashToken($currentRawToken) : null;
        $rows = $this->repository->listActiveForUser($pubkeyHash);
        foreach ($rows as &$row) {
            $row['is_current'] = $currentHash !== null && $row['token_hash'] === $currentHash;
            // Never leak the hash to the caller. The id is enough for revoke.
            unset($row['token_hash']);
        }
        unset($row);
        return $rows;
    }

    public function pruneExpired(): int
    {
        return $this->repository->pruneExpired();
    }

    /**
     * Reduce a raw User-Agent header to a short, privacy-conscious family
     * string for display in the Active Sessions list. Captures "browser
     * family + major version · OS family" without IPs or fingerprintable
     * detail. Best-effort; unknown UAs fall back to "Unknown device".
     */
    public function summariseUserAgent(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return 'Unknown device';
        }

        $raw = trim(substr($raw, 0, 512));

        $os = 'Unknown OS';
        if (stripos($raw, 'Windows') !== false)       { $os = 'Windows'; }
        elseif (stripos($raw, 'Android') !== false)   { $os = 'Android'; }
        elseif (stripos($raw, 'iPhone') !== false || stripos($raw, 'iPad') !== false || stripos($raw, 'iOS') !== false) { $os = 'iOS'; }
        elseif (stripos($raw, 'Mac OS X') !== false || stripos($raw, 'Macintosh') !== false) { $os = 'macOS'; }
        elseif (stripos($raw, 'Linux') !== false)     { $os = 'Linux'; }

        $browser = null;
        // Order matters — Chrome/Edge/Opera UAs also contain "Safari".
        if (preg_match('#Edg/(\d+)#i', $raw, $m)) {
            $browser = 'Edge ' . $m[1];
        } elseif (preg_match('#OPR/(\d+)#i', $raw, $m)) {
            $browser = 'Opera ' . $m[1];
        } elseif (preg_match('#Firefox/(\d+)#i', $raw, $m)) {
            $browser = 'Firefox ' . $m[1];
        } elseif (preg_match('#Chrome/(\d+)#i', $raw, $m)) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('#Version/(\d+).* Safari/#i', $raw, $m)) {
            $browser = 'Safari ' . $m[1];
        } elseif (stripos($raw, 'curl/') !== false) {
            $browser = 'curl';
        }

        if ($browser === null) {
            return substr("$os · Unknown browser", 0, 128);
        }
        return substr("$browser · $os", 0, 128);
    }

    private function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
