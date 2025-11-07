<?php
/**
 * SessionManager - Hardened Session Management for eIOU Wallet
 *
 * Copyright 2025
 *
 * Implements comprehensive session security including:
 * - Session fixation protection
 * - Absolute and idle timeouts
 * - Secure cookie configuration
 * - Session regeneration on privilege changes
 * - Session fingerprinting
 * - Token-based CSRF protection
 */

require_once dirname(__DIR__) . '/core/Constants.php';
require_once dirname(__DIR__) . '/utils/SecureLogger.php';

class SessionManager
{
    // Session timeout constants
    private const IDLE_TIMEOUT = 1800;        // 30 minutes
    private const ABSOLUTE_TIMEOUT = 86400;    // 24 hours
    private const REGENERATION_INTERVAL = 300; // 5 minutes

    // Session keys
    private const KEY_AUTHENTICATED = 'authenticated';
    private const KEY_USER_ID = 'user_id';
    private const KEY_AUTH_TIME = 'auth_time';
    private const KEY_LAST_ACTIVITY = 'last_activity';
    private const KEY_LAST_REGENERATION = 'last_regeneration';
    private const KEY_FINGERPRINT = 'session_fingerprint';
    private const KEY_CSRF_TOKEN = 'csrf_token';
    private const KEY_CSRF_TOKEN_TIME = 'csrf_token_time';
    private const KEY_LOGIN_ATTEMPTS = 'login_attempts';
    private const KEY_LAST_LOGIN_ATTEMPT = 'last_login_attempt';

    private bool $started = false;

    /**
     * Initialize secure session management
     */
    public function __construct()
    {
        $this->startSecureSession();
    }

    /**
     * Start session with hardened security configuration
     *
     * @return void
     */
    private function startSecureSession(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Configure secure session settings before starting
        $this->configureSessionSecurity();

        // Use custom session name (security through obscurity layer)
        session_name('EIOU_WALLET_SID');

        // Start the session
        if (!session_start()) {
            SecureLogger::error('Failed to start session', [
                'php_sapi' => php_sapi_name()
            ]);
            throw new RuntimeException('Failed to initialize session');
        }

        $this->started = true;

        // Validate session fingerprint to prevent session hijacking
        $this->validateSessionFingerprint();

        // Check for session timeouts
        $this->checkTimeouts();

        // Perform periodic session regeneration
        $this->checkPeriodicRegeneration();

        SecureLogger::debug('Session started', [
            'session_id' => substr(session_id(), 0, 8) . '...',
            'authenticated' => $this->isAuthenticated()
        ]);
    }

    /**
     * Configure PHP session security settings
     *
     * @return void
     */
    private function configureSessionSecurity(): void
    {
        // Session cookie configuration
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

        $cookieParams = [
            'lifetime' => 0,           // Session cookie (expires when browser closes)
            'path' => '/',
            'domain' => '',            // Current domain
            'secure' => $isSecure,     // HTTPS only (when available)
            'httponly' => true,        // Prevent JavaScript access
            'samesite' => 'Strict'     // CSRF protection
        ];

        session_set_cookie_params($cookieParams);

        // Additional security settings
        ini_set('session.use_only_cookies', '1');       // Only use cookies, no URL rewriting
        ini_set('session.use_trans_sid', '0');          // Disable transparent session ID
        ini_set('session.use_strict_mode', '1');        // Reject uninitialized session IDs
        ini_set('session.cookie_httponly', '1');        // HTTP only cookies
        ini_set('session.cookie_secure', $isSecure ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', (string)self::ABSOLUTE_TIMEOUT);
        ini_set('session.sid_length', '48');            // Longer session IDs
        ini_set('session.sid_bits_per_character', '6'); // More entropy per character

        // Use strong entropy source
        ini_set('session.entropy_file', '/dev/urandom');
        ini_set('session.entropy_length', '32');
    }

    /**
     * Validate session fingerprint to prevent hijacking
     *
     * @return void
     */
    private function validateSessionFingerprint(): void
    {
        $currentFingerprint = $this->generateFingerprint();

        if (!isset($_SESSION[self::KEY_FINGERPRINT])) {
            // First access, set fingerprint
            $_SESSION[self::KEY_FINGERPRINT] = $currentFingerprint;
            return;
        }

        // Verify fingerprint matches
        if (!hash_equals($_SESSION[self::KEY_FINGERPRINT], $currentFingerprint)) {
            // Fingerprint mismatch - possible session hijacking
            SecureLogger::warning('Session fingerprint mismatch - possible hijacking attempt', [
                'expected_hash' => substr(hash('sha256', $_SESSION[self::KEY_FINGERPRINT]), 0, 16),
                'received_hash' => substr(hash('sha256', $currentFingerprint), 0, 16),
                'ip' => $this->getClientIp()
            ]);

            // Destroy compromised session
            $this->destroy();
            throw new SecurityException('Session validation failed');
        }
    }

    /**
     * Generate session fingerprint from client characteristics
     *
     * @return string
     */
    private function generateFingerprint(): string
    {
        // Use multiple client characteristics for fingerprinting
        // Note: We don't use User-Agent as it can change (browser updates)
        $components = [
            $this->getClientIp(),
            // Accept-Language is more stable than User-Agent
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            // Accept-Encoding is also relatively stable
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Get client IP address (with proxy support)
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // Proxy/Load balancer
            'HTTP_CLIENT_IP',         // Some proxies
            'REMOTE_ADDR'             // Direct connection
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Check for session timeouts (idle and absolute)
     *
     * @return void
     */
    private function checkTimeouts(): void
    {
        if (!$this->isAuthenticated()) {
            return;
        }

        $now = time();

        // Check absolute timeout (maximum session lifetime)
        if (isset($_SESSION[self::KEY_AUTH_TIME])) {
            $sessionAge = $now - $_SESSION[self::KEY_AUTH_TIME];
            if ($sessionAge > self::ABSOLUTE_TIMEOUT) {
                SecureLogger::info('Session expired due to absolute timeout', [
                    'session_age' => $sessionAge,
                    'user_id' => $_SESSION[self::KEY_USER_ID] ?? 'unknown'
                ]);
                $this->logout('absolute_timeout');
                return;
            }
        }

        // Check idle timeout (inactivity)
        if (isset($_SESSION[self::KEY_LAST_ACTIVITY])) {
            $idleTime = $now - $_SESSION[self::KEY_LAST_ACTIVITY];
            if ($idleTime > self::IDLE_TIMEOUT) {
                SecureLogger::info('Session expired due to idle timeout', [
                    'idle_time' => $idleTime,
                    'user_id' => $_SESSION[self::KEY_USER_ID] ?? 'unknown'
                ]);
                $this->logout('idle_timeout');
                return;
            }
        }

        // Update last activity timestamp
        $_SESSION[self::KEY_LAST_ACTIVITY] = $now;
    }

    /**
     * Perform periodic session ID regeneration
     *
     * @return void
     */
    private function checkPeriodicRegeneration(): void
    {
        $now = time();

        if (!isset($_SESSION[self::KEY_LAST_REGENERATION])) {
            $_SESSION[self::KEY_LAST_REGENERATION] = $now;
            return;
        }

        $timeSinceRegen = $now - $_SESSION[self::KEY_LAST_REGENERATION];

        if ($timeSinceRegen > self::REGENERATION_INTERVAL) {
            $this->regenerateId();
            $_SESSION[self::KEY_LAST_REGENERATION] = $now;

            SecureLogger::debug('Session ID regenerated (periodic)', [
                'time_since_last' => $timeSinceRegen
            ]);
        }
    }

    /**
     * Regenerate session ID (prevents session fixation)
     *
     * @return void
     */
    public function regenerateId(): void
    {
        if (!$this->started) {
            return;
        }

        $oldSessionId = session_id();

        // Delete old session and create new one
        if (!session_regenerate_id(true)) {
            SecureLogger::error('Failed to regenerate session ID');
            throw new RuntimeException('Session regeneration failed');
        }

        $_SESSION[self::KEY_LAST_REGENERATION] = time();

        SecureLogger::debug('Session ID regenerated', [
            'old_id' => substr($oldSessionId, 0, 8) . '...',
            'new_id' => substr(session_id(), 0, 8) . '...'
        ]);
    }

    /**
     * Authenticate user and establish secure session
     *
     * @param string $userId User identifier
     * @param array $additionalData Additional session data
     * @return void
     */
    public function authenticate(string $userId, array $additionalData = []): void
    {
        // Regenerate session ID on authentication to prevent fixation
        $this->regenerateId();

        $now = time();

        // Set authentication flags
        $_SESSION[self::KEY_AUTHENTICATED] = true;
        $_SESSION[self::KEY_USER_ID] = $userId;
        $_SESSION[self::KEY_AUTH_TIME] = $now;
        $_SESSION[self::KEY_LAST_ACTIVITY] = $now;
        $_SESSION[self::KEY_LAST_REGENERATION] = $now;

        // Store additional data
        foreach ($additionalData as $key => $value) {
            $_SESSION[$key] = $value;
        }

        SecureLogger::info('User authenticated successfully', [
            'user_id' => $userId,
            'ip' => $this->getClientIp(),
            'session_id' => substr(session_id(), 0, 8) . '...'
        ]);
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION[self::KEY_AUTHENTICATED])
            && $_SESSION[self::KEY_AUTHENTICATED] === true;
    }

    /**
     * Get authenticated user ID
     *
     * @return string|null
     */
    public function getUserId(): ?string
    {
        return $_SESSION[self::KEY_USER_ID] ?? null;
    }

    /**
     * Logout user and destroy session
     *
     * @param string $reason Logout reason for logging
     * @return void
     */
    public function logout(string $reason = 'user_initiated'): void
    {
        $userId = $this->getUserId();

        // Clear all session data
        $_SESSION = [];

        // Delete session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Destroy session
        session_destroy();
        $this->started = false;

        SecureLogger::info('User logged out', [
            'user_id' => $userId,
            'reason' => $reason,
            'ip' => $this->getClientIp()
        ]);
    }

    /**
     * Destroy session completely
     *
     * @return void
     */
    public function destroy(): void
    {
        $this->logout('session_destroyed');
    }

    /**
     * Generate CSRF token
     *
     * @return string
     */
    public function generateCsrfToken(): string
    {
        $now = time();

        // Check if token exists and is still valid
        if (isset($_SESSION[self::KEY_CSRF_TOKEN])
            && isset($_SESSION[self::KEY_CSRF_TOKEN_TIME])) {

            $tokenAge = $now - $_SESSION[self::KEY_CSRF_TOKEN_TIME];

            // Reuse token if less than 1 hour old
            if ($tokenAge < 3600) {
                return $_SESSION[self::KEY_CSRF_TOKEN];
            }
        }

        // Generate new token
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::KEY_CSRF_TOKEN] = $token;
        $_SESSION[self::KEY_CSRF_TOKEN_TIME] = $now;

        return $token;
    }

    /**
     * Validate CSRF token
     *
     * @param string $token Token to validate
     * @return bool
     */
    public function validateCsrfToken(string $token): bool
    {
        if (!isset($_SESSION[self::KEY_CSRF_TOKEN])
            || !isset($_SESSION[self::KEY_CSRF_TOKEN_TIME])) {
            return false;
        }

        // Check token age (1 hour maximum)
        $tokenAge = time() - $_SESSION[self::KEY_CSRF_TOKEN_TIME];
        if ($tokenAge > 3600) {
            // Token expired
            unset($_SESSION[self::KEY_CSRF_TOKEN]);
            unset($_SESSION[self::KEY_CSRF_TOKEN_TIME]);
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($_SESSION[self::KEY_CSRF_TOKEN], $token);
    }

    /**
     * Verify CSRF token from request (throws on failure)
     *
     * @throws SecurityException
     * @return void
     */
    public function verifyCsrfToken(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!$this->validateCsrfToken($token)) {
            SecureLogger::warning('CSRF token validation failed', [
                'ip' => $this->getClientIp(),
                'user_id' => $this->getUserId(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]);

            throw new SecurityException('CSRF token validation failed');
        }
    }

    /**
     * Get CSRF token for forms
     *
     * @return string
     */
    public function getCsrfToken(): string
    {
        return $this->generateCsrfToken();
    }

    /**
     * Get CSRF token as hidden form field
     *
     * @return string HTML input field
     */
    public function getCsrfField(): string
    {
        $token = $this->getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Set session value
     *
     * @param string $key Session key
     * @param mixed $value Value to store
     * @return void
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Get session value
     *
     * @param string $key Session key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if session key exists
     *
     * @param string $key Session key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove session value
     *
     * @param string $key Session key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Get session age in seconds
     *
     * @return int|null
     */
    public function getSessionAge(): ?int
    {
        if (!isset($_SESSION[self::KEY_AUTH_TIME])) {
            return null;
        }

        return time() - $_SESSION[self::KEY_AUTH_TIME];
    }

    /**
     * Get idle time in seconds
     *
     * @return int|null
     */
    public function getIdleTime(): ?int
    {
        if (!isset($_SESSION[self::KEY_LAST_ACTIVITY])) {
            return null;
        }

        return time() - $_SESSION[self::KEY_LAST_ACTIVITY];
    }

    /**
     * Set flash message
     *
     * @param string $message Message text
     * @param string $type Message type (success, error, warning, info)
     * @return void
     */
    public function setMessage(string $message, string $type = 'info'): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_message_type'] = $type;
    }

    /**
     * Get and clear flash message
     *
     * @return array|null ['text' => string, 'type' => string]
     */
    public function getMessage(): ?array
    {
        if (isset($_SESSION['flash_message'])) {
            $message = [
                'text' => $_SESSION['flash_message'],
                'type' => $_SESSION['flash_message_type'] ?? 'info'
            ];

            unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);

            return $message;
        }

        return null;
    }
}

/**
 * SecurityException - Thrown on security violations
 */
class SecurityException extends Exception
{
}
