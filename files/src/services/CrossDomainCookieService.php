<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\UserContext;

/**
 * Cross-Subdomain Cookie Service
 *
 * Sets wallet identity cookies on the parent domain (.eiou.org)
 * so all subdomains (wallet.eiou.org, wns.eiou.org, gftn.eiou.org)
 * can read the authenticated user's wallet info.
 *
 * Cookie contains non-sensitive wallet metadata only:
 * - display name
 * - public key hash (not the key itself)
 * - node addresses (already public via Tor/HTTP)
 * - authentication timestamp
 *
 * The cookie is signed with HMAC to prevent tampering.
 */
class CrossDomainCookieService
{
    private const COOKIE_NAME = 'eiou_wallet';
    private const COOKIE_LIFETIME = 86400 * 30; // 30 days
    private const COOKIE_VERSION = 1;

    private UserContext $user;
    private string $hmacKey;

    public function __construct(UserContext $user)
    {
        $this->user = $user;
        // Derive HMAC key from the node's private key — unique per node
        $this->hmacKey = hash('sha256', $user->getPrivateKey() ?? 'eiou-default-key', true);
    }

    /**
     * Detect the parent domain from the current request.
     * e.g. wallet.eiou.org → .eiou.org
     *      localhost → '' (no cross-domain)
     */
    public static function getParentDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';

        // Strip port
        $host = explode(':', $host)[0];

        // IP address or localhost — no cross-domain possible
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return '';
        }

        // Count dots to find parent domain
        $parts = explode('.', $host);
        $count = count($parts);

        // Already a root domain (eiou.org) or subdomain (wallet.eiou.org)
        // Either way, set cookie on .eiou.org (last two parts)
        if ($count >= 2) {
            return '.' . implode('.', array_slice($parts, -2));
        }

        return '';
    }

    /**
     * Set the cross-subdomain wallet cookie after successful login.
     */
    public function setWalletCookie(): bool
    {
        $payload = [
            'v' => self::COOKIE_VERSION,
            'name' => $this->user->getName(),
            'pubkey_hash' => $this->user->getPublicKeyHash(),
            'http' => $this->user->getHttpAddress(),
            'https' => $this->user->getHttpsAddress(),
            'tor' => $this->user->getTorAddress(),
            'auth_time' => time(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $json, $this->hmacKey);
        $cookieValue = base64_encode($json) . '.' . $signature;

        $domain = self::getParentDomain();

        return setcookie(self::COOKIE_NAME, $cookieValue, [
            'expires' => time() + self::COOKIE_LIFETIME,
            'path' => '/',
            'domain' => $domain ?: '',
            'secure' => true,
            'httponly' => false, // JS needs to read this for cross-subdomain demo
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Clear the cross-subdomain cookie (on logout).
     */
    public function clearWalletCookie(): bool
    {
        $domain = self::getParentDomain();

        return setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 86400,
            'path' => '/',
            'domain' => $domain ?: '',
            'secure' => true,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Read and verify the wallet cookie (works on any subdomain).
     * Returns decoded payload or null if invalid/missing.
     */
    public function readWalletCookie(): ?array
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$raw) return null;

        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) return null;

        [$encoded, $signature] = $parts;
        $json = base64_decode($encoded, true);
        if ($json === false) return null;

        // Verify HMAC
        $expected = hash_hmac('sha256', $json, $this->hmacKey);
        if (!hash_equals($expected, $signature)) return null;

        $payload = json_decode($json, true);
        if (!is_array($payload)) return null;

        // Check version
        if (($payload['v'] ?? 0) !== self::COOKIE_VERSION) return null;

        return $payload;
    }

    /**
     * Static helper: read wallet cookie without verification (for demo pages
     * on other subdomains that don't have the node's private key).
     * Returns decoded payload — caller should treat as untrusted.
     */
    public static function readWalletCookieUntrusted(): ?array
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$raw) return null;

        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) return null;

        $json = base64_decode($parts[0], true);
        if ($json === false) return null;

        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }
}
