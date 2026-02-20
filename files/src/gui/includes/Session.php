<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Includes;

use Eiou\Core\ErrorCodes;

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
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 300) { // Regenerate every 5 minutes
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
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
            $_SESSION['authenticated'] = true;
            $_SESSION['auth_time'] = time();
            $_SESSION['last_activity'] = time();

            // Regenerate session ID on successful authentication
            session_regenerate_id(true);

            return true;
        }

        return false;
    }

    /**
     * Check for session timeout (30 minutes of inactivity)
     *
     * @return bool
     */
    public function checkSessionTimeout(): bool
    {
        if (isset($_SESSION['last_activity'])) {
            $inactive = time() - $_SESSION['last_activity'];
            $timeout = 1800; // 30 minutes

            if ($inactive >= $timeout) {
                $this->logout();
                return false;
            }
        }

        $_SESSION['last_activity'] = time();
        return true;
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
            header('Location: ' . $_SERVER['PHP_SELF']);
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
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
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
     * @return bool
     */
    public function validateCSRFToken(string $token): bool
    {
        // Check if token exists
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }

        // Check token age (1 hour max)
        if (time() - $_SESSION['csrf_token_time'] > 3600) {
            // Token expired, regenerate
            unset($_SESSION['csrf_token']);
            unset($_SESSION['csrf_token_time']);
            return false;
        }

        // Validate token using constant-time comparison
        if (hash_equals($_SESSION['csrf_token'], $token)) {
            // Rotate token after successful validation to prevent reuse
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return true;
        }

        return false;
    }

    /**
     * Verify CSRF token for POST requests
     *
     * @return void
     * @throws \Exception
     */
    public function verifyCSRFToken(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';

            if (!$this->validateCSRFToken($token)) {
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
        if (isset($_SESSION['message'])) {
            $message = [
                'text' => $_SESSION['message'],
                'type' => $_SESSION['message_type'] ?? 'info'
            ];
            unset($_SESSION['message'], $_SESSION['message_type']);
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
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $type;
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
        session_destroy();
        session_start();
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
