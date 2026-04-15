<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Includes;

use Eiou\Core\ErrorCodes;
use Eiou\Gui\Includes\SessionKeys;

/**
 *
 * Session Management for eIOU Wallet
 *
 * Handles secure session-based authentication
 */

class Session
{
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

            // Use a custom session name
            session_name('EIOU_WALLET_SESSION');

            // Start the session
            session_start();

            // Regenerate session ID periodically for security
            $this->checkSessionRegeneration();
        }
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
     * Authenticate user with auth code
     *
     * @param string $authCode
     * @param string $userAuthCode
     * @return bool
     */
    public function authenticate(string $authCode, string $userAuthCode): bool
    {
        // Use constant-time comparison to prevent timing attacks
        if (hash_equals($userAuthCode, $authCode)) {
            $_SESSION[SessionKeys::AUTHENTICATED] = true;
            $_SESSION[SessionKeys::AUTH_TIME] = time();
            $_SESSION[SessionKeys::LAST_ACTIVITY] = time();

            // Regenerate session ID on successful authentication
            session_regenerate_id(true);

            return true;
        }

        return false;
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
            \Eiou\Core\Constants::REMEMBER_ME_COOKIE_NAME,
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
            \Eiou\Core\Constants::REMEMBER_ME_COOKIE_NAME,
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
        $raw = $_COOKIE[\Eiou\Core\Constants::REMEMBER_ME_COOKIE_NAME] ?? null;
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
}
