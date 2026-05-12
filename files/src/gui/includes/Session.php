<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Includes;

use Eiou\Core\Constants;
use Eiou\Core\ErrorCodes;
use Eiou\Gui\Includes\SessionKeys;
use Eiou\Utils\AltCodeVerifier;

/**
 *
 * Session Management for eIOU Wallet
 *
 * Handles secure session-based authentication
 */

class Session
{
    /**
     * Cached per-node cookie suffix. Computed once per request from the
     * wallet's public key — see {@see getNodeCookieSuffix}. Cleared by
     * {@see overrideNodeCookieSuffixForTest()} in unit tests.
     */
    private static ?string $cachedNodeSuffix = null;

    /**
     * Test-only override path for the userconfig file. Production reads
     * from /etc/eiou/config/userconfig.json; tests inject a temp file.
     */
    private static ?string $userconfigPathOverride = null;

    /**
     * Initialize session if not already started with secure settings
     */
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Set session cookie parameters before starting session
            $cookieParams = [
                'lifetime' => 0, // Session cookie (expires when browser closes)
                'path' => '/',
                'domain' => '', // Current domain
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', // Only send over HTTPS if available
                'httponly' => true, // Prevent JavaScript access
                'samesite' => 'Strict' // Prevent CSRF attacks
            ];

            session_set_cookie_params($cookieParams);

            // Per-node session name. Plain "EIOU_WALLET_SESSION" collides
            // when two nodes share a hostname but differ only by port
            // (e.g. localhost:443 vs localhost:8443 in dev) — cookies
            // ignore port per RFC 6265, so the second login overwrites
            // the first's session cookie and both tabs end up logged
            // out. Suffixing with a stable per-node hash isolates the
            // cookies on the same host. No effect in prod where each
            // node already has its own hostname.
            session_name(self::sessionCookieName());

            // Start the session
            session_start();

            // Regenerate session ID periodically for security
            $this->checkSessionRegeneration();
        }
    }

    /**
     * Compute a stable, per-node suffix for cookie names. Derived from
     * the wallet's public key (sha256, first 16 hex chars) so two nodes
     * sharing a hostname but differing only by port — typical dev setup
     * with `localhost:443` (alice) and `localhost:8443` (bob) — write
     * cookies under different keys and don't clobber each other.
     *
     * Falls back to the literal string `default` when the userconfig
     * file is missing or malformed (e.g. during the brief
     * pre-initialization window before {@see Wallet::generate()}
     * writes it). Cached for the life of the request.
     */
    public static function getNodeCookieSuffix(): string
    {
        if (self::$cachedNodeSuffix !== null) {
            return self::$cachedNodeSuffix;
        }

        $path = self::$userconfigPathOverride ?? '/etc/eiou/config/userconfig.json';
        if (!is_file($path) || !is_readable($path)) {
            return self::$cachedNodeSuffix = 'default';
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::$cachedNodeSuffix = 'default';
        }
        $cfg = json_decode($raw, true);
        $pem = is_array($cfg) ? ($cfg['public'] ?? null) : null;
        if (!is_string($pem) || $pem === '') {
            return self::$cachedNodeSuffix = 'default';
        }
        return self::$cachedNodeSuffix = substr(hash('sha256', $pem), 0, 16);
    }

    /** Cookie name the PHP session is registered under, per-node. */
    private static function sessionCookieName(): string
    {
        return 'EIOU_WALLET_SESSION_' . self::getNodeCookieSuffix();
    }

    /** Cookie name the remember-me token is stored under, per-node. */
    public static function rememberCookieName(): string
    {
        return Constants::REMEMBER_ME_COOKIE_NAME . '_' . self::getNodeCookieSuffix();
    }

    /**
     * Test-only seam: point the suffix derivation at an alternate
     * userconfig file and reset the cache. Production code never calls
     * this.
     */
    public static function overrideNodeCookieSuffixForTest(?string $userconfigPath): void
    {
        self::$userconfigPathOverride = $userconfigPath;
        self::$cachedNodeSuffix = null;
    }

    /**
     * Check and regenerate session ID periodically for security
     *
     * @return void
     */
    private function checkSessionRegeneration(): void
    {
        if (!isset($_SESSION[SessionKeys::LAST_REGENERATION])) {
            $_SESSION[SessionKeys::LAST_REGENERATION] = time();
        } elseif (time() - $_SESSION[SessionKeys::LAST_REGENERATION] > 300) { // Regenerate every 5 minutes
            session_regenerate_id(true);
            $_SESSION[SessionKeys::LAST_REGENERATION] = time();
        }
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION[SessionKeys::AUTHENTICATED]) && $_SESSION[SessionKeys::AUTHENTICATED] === true;
    }

    /**
     * Authenticate user with the primary auth code or, optionally, the
     * user-configured alternate auth code.
     *
     * Both candidates are always evaluated — even when the primary check
     * matches — so a timing observer cannot tell which credential was
     * accepted (or whether an alt code is configured at all).
     *
     * On success, marks which credential was used in the session so any
     * follow-up gate that needs the *primary* specifically (e.g. rotating
     * the alt code itself) can refuse alt-code holders.
     *
     * @param string      $submitted    The code the caller submitted.
     * @param string      $userAuthCode Plaintext primary auth code from
     *                                  UserContext::getAuthCode().
     * @param string|null $altCodeHash  Argon2id hash from
     *                                  UserContext::getAltCodeHash(), or
     *                                  null when no alt code is set.
     */
    public function authenticate(string $submitted, string $userAuthCode, ?string $altCodeHash = null): bool
    {
        // Constant-time primary check.
        $primaryOk = hash_equals($userAuthCode, $submitted);

        // Always-runs alt check — AltCodeVerifier compares against the
        // real hash when configured, otherwise against a per-process
        // placeholder with the same Argon2id work factor. This keeps the
        // auth path's wall-clock time identical whether or not an alt
        // code is set, so a network attacker timing failed logins cannot
        // deduce alt-code presence from latency alone.
        $altOk = AltCodeVerifier::verify($submitted, $altCodeHash);

        if ($primaryOk || $altOk) {
            $_SESSION[SessionKeys::AUTHENTICATED] = true;
            $_SESSION[SessionKeys::AUTH_TIME] = time();
            $_SESSION[SessionKeys::LAST_ACTIVITY] = time();
            // Record which credential was used. Alt-code holders are not
            // allowed to rotate the alt code (see AltCodeController), so
            // the rotation handler needs to distinguish the two cases.
            $_SESSION[SessionKeys::AUTH_VIA_ALT] = ($primaryOk === false) && ($altOk === true);

            session_regenerate_id(true);
            return true;
        }

        return false;
    }

    /**
     * Whether the current session was authenticated via the alternate
     * auth code. Returns false for sessions logged in with the primary
     * code, unauthenticated sessions, or pre-feature sessions that don't
     * carry the marker.
     */
    public function authenticatedViaAlt(): bool
    {
        return !empty($_SESSION[SessionKeys::AUTH_VIA_ALT]);
    }

    /**
     * Check for session timeout based on configured inactivity period
     *
     * @return bool
     */
    public function checkSessionTimeout(): bool
    {
        if (isset($_SESSION[SessionKeys::LAST_ACTIVITY])) {
            $inactive = time() - $_SESSION[SessionKeys::LAST_ACTIVITY];
            $timeout = $this->getTimeoutSeconds();

            if ($inactive >= $timeout) {
                $this->logout();
                return false;
            }
        }

        $_SESSION[SessionKeys::LAST_ACTIVITY] = time();
        return true;
    }

    /**
     * Get session timeout in seconds from user config
     *
     * @return int
     */
    private function getTimeoutSeconds(): int
    {
        $configFile = '/etc/eiou/config/defaultconfig.json';
        $defaultMinutes = 30;

        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (is_array($config) && isset($config['sessionTimeoutMinutes'])) {
                $minutes = (int) $config['sessionTimeoutMinutes'];
                if (in_array($minutes, [5, 10, 15, 30, 60])) {
                    return $minutes * 60;
                }
            }
        }

        return $defaultMinutes * 60;
    }

    /**
     * Mark the current session as authenticated without requiring an authcode
     * POST — used by the remember-me flow when a valid EIOU_REMEMBER cookie
     * is presented. Still regenerates the session id to prevent fixation.
     *
     * Token rotation policy
     * ─────────────────────
     * The remember-me token itself is rotated single-use by
     * `RememberTokenService::rotateToken()`, which is invoked from
     * `gui/index.html` BEFORE this method runs. Each successful
     * remember-me login:
     *   1. Looks up the old token's hash in remember_tokens.
     *   2. Mints a fresh raw token (32 random bytes, hex-encoded).
     *   3. Inserts the new row, revokes the old row.
     *   4. Writes the new raw token into the EIOU_REMEMBER cookie.
     *
     * Replay detection: a stolen cookie stops working the moment the
     * real owner next logs in, because the thief's old token-hash
     * gets revoked when the legitimate session rotates.
     *
     * Lifetime: the new row inherits the old row's remaining
     * `expires_at` — "remember me for 30 days" means 30 days from the
     * initial opt-in, not a perpetually-sliding window. Prevents a
     * silent forever-session via repeated rotation.
     */
    public function markAuthenticatedFromRememberToken(): void
    {
        $_SESSION[SessionKeys::AUTHENTICATED] = true;
        $_SESSION[SessionKeys::AUTH_TIME] = time();
        $_SESSION[SessionKeys::LAST_ACTIVITY] = time();
        session_regenerate_id(true);
    }

    /**
     * Issue the remember-me cookie (carries the raw token). HttpOnly +
     * SameSite=Strict + Secure (when HTTPS is available). Written after
     * successful mint or rotate.
     */
    public function setRememberCookie(string $rawToken, int $expiresAtUnix): void
    {
        setcookie(
            self::rememberCookieName(),
            $rawToken,
            [
                'expires'  => $expiresAtUnix,
                'path'     => '/',
                'domain'   => '',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
    }

    /**
     * Clear the remember-me cookie on the client. Called on logout and
     * when a presented token fails validation (revoked / expired).
     */
    public function clearRememberCookie(): void
    {
        setcookie(
            self::rememberCookieName(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => '/',
                'domain'   => '',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
    }

    /**
     * Return the raw remember-me token from the request cookies, or null.
     */
    public function getRememberCookie(): ?string
    {
        $raw = $_COOKIE[self::rememberCookieName()] ?? null;
        return (is_string($raw) && $raw !== '') ? $raw : null;
    }

    /**
     * Logout user
     *
     * @return void
     */
    public function logout(): void
    {
        // Clear session data
        $_SESSION = [];

        // Destroy the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Destroy the session
        session_destroy();
    }

    /**
     * Require authentication for protected pages
     *
     * @return void
     */
    public function requireAuth(): void
    {
        if (!$this->isAuthenticated() || !$this->checkSessionTimeout()) {
            // Redirect to login page
            header('Location: ' . $_SERVER['SCRIPT_NAME']);
            exit;
        }
    }

    /**
     * Generate CSRF token
     *
     * @return string
     */
    public function generateCSRFToken(): string
    {
        if (!isset($_SESSION[SessionKeys::CSRF_TOKEN])) {
            $_SESSION[SessionKeys::CSRF_TOKEN] = bin2hex(random_bytes(32));
            $_SESSION[SessionKeys::CSRF_TOKEN_TIME] = time();
        }
        return $_SESSION[SessionKeys::CSRF_TOKEN];
    }

    /**
     * Get current CSRF token
     *
     * @return string
     */
    public function getCSRFToken(): string
    {
        return $this->generateCSRFToken();
    }

    /**
     * Validate CSRF token
     *
     * @param string $token
     * @param bool $rotate Whether to rotate (invalidate) the token after validation
     * @return bool
     */
    public function validateCSRFToken(string $token, bool $rotate = true): bool
    {
        // Check if token exists
        if (!isset($_SESSION[SessionKeys::CSRF_TOKEN]) || !isset($_SESSION[SessionKeys::CSRF_TOKEN_TIME])) {
            return false;
        }

        // Check token age (1 hour max)
        if (time() - $_SESSION[SessionKeys::CSRF_TOKEN_TIME] > 3600) {
            // Token expired, regenerate
            unset($_SESSION[SessionKeys::CSRF_TOKEN]);
            unset($_SESSION[SessionKeys::CSRF_TOKEN_TIME]);
            return false;
        }

        // Validate token using constant-time comparison
        if (hash_equals($_SESSION[SessionKeys::CSRF_TOKEN], $token)) {
            if ($rotate) {
                // Rotate token after successful validation to prevent reuse
                unset($_SESSION[SessionKeys::CSRF_TOKEN], $_SESSION[SessionKeys::CSRF_TOKEN_TIME]);
            }
            return true;
        }

        return false;
    }

    /**
     * Verify CSRF token for POST requests
     *
     * @param bool $rotate Whether to rotate the token after validation (false for AJAX)
     * @return void
     * @throws \Exception
     */
    public function verifyCSRFToken(bool $rotate = true): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';

            if (!$this->validateCSRFToken($token, $rotate)) {
                // CSRF token validation failed
                http_response_code(ErrorCodes::HTTP_FORBIDDEN);
                die('CSRF token validation failed. Please refresh the page and try again.');
            }
        }
    }

    /**
     * Get CSRF token field HTML
     *
     * @return string
     */
    public function getCSRFField(): string
    {
        $token = $this->getCSRFToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Get flash message
     *
     * @return array|null
     */
    public function getMessage(): ?array
    {
        if (isset($_SESSION[SessionKeys::MESSAGE])) {
            $message = [
                'text' => $_SESSION[SessionKeys::MESSAGE],
                'type' => $_SESSION[SessionKeys::MESSAGE_TYPE] ?? 'info'
            ];
            unset($_SESSION[SessionKeys::MESSAGE], $_SESSION[SessionKeys::MESSAGE_TYPE]);
            return $message;
        }
        return null;
    }

    /**
     * Set flash message
     *
     * @param string $message
     * @param string $type
     * @return void
     */
    public function setMessage(string $message, string $type = 'success'): void
    {
        $_SESSION[SessionKeys::MESSAGE] = $message;
        $_SESSION[SessionKeys::MESSAGE_TYPE] = $type;
    }

    /**
     * Get a session value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Check if session key exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a session value
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Clear all session data
     *
     * @return void
     */
    public function clear(): void
    {
        // Clear all session data
        $_SESSION = [];

        // Destroy the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // Destroy and restart session with new ID
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    /**
     * Regenerate session ID (security)
     *
     * @return void
     */
    public function regenerateId(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Default sensitive-action grant window (seconds).
     */
    public const SENSITIVE_ACCESS_TTL_SECONDS = 300;

    /**
     * Grant the current session sensitive-action access for a short window.
     * Called after a successful re-prompt for the auth code. Independent of
     * remember-me: a remember-me login still requires this gate to be passed
     * before mutating API keys.
     */
    public function grantSensitiveAccess(int $ttlSeconds = self::SENSITIVE_ACCESS_TTL_SECONDS): void
    {
        $_SESSION[SessionKeys::SENSITIVE_ACCESS_UNTIL] = time() + $ttlSeconds;
        // Bind grant to the current auth_time. If the user logs out and back
        // in, auth_time changes and the stale grant is ignored.
        $_SESSION[SessionKeys::SENSITIVE_ACCESS_AUTH_TIME] = $_SESSION[SessionKeys::AUTH_TIME] ?? 0;
    }

    /**
     * Check whether the current session currently holds sensitive-action access.
     */
    public function hasSensitiveAccess(): bool
    {
        $until = (int) ($_SESSION[SessionKeys::SENSITIVE_ACCESS_UNTIL] ?? 0);
        $boundAuthTime = (int) ($_SESSION[SessionKeys::SENSITIVE_ACCESS_AUTH_TIME] ?? -1);
        $currentAuthTime = (int) ($_SESSION[SessionKeys::AUTH_TIME] ?? 0);

        if ($until <= time()) {
            return false;
        }
        // If the underlying auth session has been replaced, the old grant
        // must not carry over.
        if ($boundAuthTime !== $currentAuthTime) {
            return false;
        }
        return true;
    }

    /**
     * Clear any outstanding sensitive-action grant.
     */
    public function clearSensitiveAccess(): void
    {
        unset(
            $_SESSION[SessionKeys::SENSITIVE_ACCESS_UNTIL],
            $_SESSION[SessionKeys::SENSITIVE_ACCESS_AUTH_TIME]
        );
    }

    /**
     * Number of seconds remaining on the current sensitive-access grant, or 0.
     */
    public function sensitiveAccessSecondsRemaining(): int
    {
        if (!$this->hasSensitiveAccess()) {
            return 0;
        }
        return max(0, (int) ($_SESSION[SessionKeys::SENSITIVE_ACCESS_UNTIL] ?? 0) - time());
    }
}
