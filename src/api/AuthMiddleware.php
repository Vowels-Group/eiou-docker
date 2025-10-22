<?php
# Copyright 2025

/**
 * Authentication Middleware
 *
 * Handles API authentication using authentication codes from config.php.
 *
 * @package API
 */
class AuthMiddleware {
    /**
     * @var UserContext Current user context
     */
    private UserContext $currentUser;

    /**
     * @var array Authenticated session data
     */
    private static array $session = [];

    /**
     * @var int Session timeout in seconds (default 1 hour)
     */
    private const SESSION_TIMEOUT = 3600;

    /**
     * Constructor
     *
     * @param UserContext $currentUser Current user context
     */
    public function __construct(UserContext $currentUser) {
        $this->currentUser = $currentUser;
    }

    /**
     * Authenticate request using authcode
     *
     * @param string $authCode Authentication code from request
     * @return bool True if authenticated
     */
    public function authenticateWithAuthCode(string $authCode): bool {
        $expectedAuthCode = $this->currentUser->getAuthCode();

        if (!$expectedAuthCode) {
            error_log('API Auth: No authcode configured in wallet');
            return false;
        }

        if ($authCode !== $expectedAuthCode) {
            error_log('API Auth: Invalid authcode provided');
            return false;
        }

        return true;
    }

    /**
     * Generate session token
     *
     * @param string $authCode Authentication code
     * @return string|null Session token or null if auth fails
     */
    public function generateSessionToken(string $authCode): ?string {
        if (!$this->authenticateWithAuthCode($authCode)) {
            return null;
        }

        // Generate secure random token
        $token = bin2hex(random_bytes(32));

        // Store session data
        self::$session[$token] = [
            'created_at' => time(),
            'expires_at' => time() + self::SESSION_TIMEOUT,
            'user_pubkey' => $this->currentUser->getPublicKey()
        ];

        return $token;
    }

    /**
     * Validate session token
     *
     * @param string $token Session token
     * @return bool True if valid
     */
    public function validateSessionToken(string $token): bool {
        // Check if token exists
        if (!isset(self::$session[$token])) {
            return false;
        }

        // Check if token has expired
        if (self::$session[$token]['expires_at'] < time()) {
            unset(self::$session[$token]);
            return false;
        }

        // Extend session on activity
        self::$session[$token]['expires_at'] = time() + self::SESSION_TIMEOUT;

        return true;
    }

    /**
     * Get authentication token from request headers
     *
     * @return string|null Token or null if not found
     */
    public function getTokenFromRequest(): ?string {
        // Check Authorization header (Bearer token)
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $matches = [];
            if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }

        // Check X-Auth-Token header
        if (isset($headers['X-Auth-Token'])) {
            return $headers['X-Auth-Token'];
        }

        // Check query parameter (less secure, for testing)
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }

        return null;
    }

    /**
     * Require authentication for API endpoint
     *
     * @return bool True if authenticated, sends error response and exits if not
     */
    public function requireAuth(): bool {
        $token = $this->getTokenFromRequest();

        if (!$token) {
            ResponseFormatter::unauthorized('Authentication token required. Please authenticate using POST /api/auth');
            return false;
        }

        if (!$this->validateSessionToken($token)) {
            ResponseFormatter::unauthorized('Invalid or expired session token. Please re-authenticate using POST /api/auth');
            return false;
        }

        return true;
    }

    /**
     * Get session data for a token
     *
     * @param string $token Session token
     * @return array|null Session data or null
     */
    public function getSessionData(string $token): ?array {
        return self::$session[$token] ?? null;
    }

    /**
     * Revoke session token (logout)
     *
     * @param string $token Session token
     * @return bool True if revoked
     */
    public function revokeToken(string $token): bool {
        if (isset(self::$session[$token])) {
            unset(self::$session[$token]);
            return true;
        }
        return false;
    }

    /**
     * Clean up expired sessions
     *
     * @return int Number of sessions cleaned
     */
    public function cleanupExpiredSessions(): int {
        $now = time();
        $cleaned = 0;

        foreach (self::$session as $token => $data) {
            if ($data['expires_at'] < $now) {
                unset(self::$session[$token]);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get all active sessions count
     *
     * @return int Number of active sessions
     */
    public function getActiveSessionsCount(): int {
        $this->cleanupExpiredSessions();
        return count(self::$session);
    }
}
