<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Security Initialization File
 *
 * Initializes security components for all EIOU PHP entry points.
 * This file should be included early in the request lifecycle.
 *
 * Initialization Sequence:
 * 1. Load PSR-4 autoloader (if not already loaded)
 * 2. Initialize SecureLogger
 * 3. Initialize ErrorHandler
 * 4. Set security headers (web requests only)
 * 5. Configure and start secure session
 * 6. Apply rate limiting based on action type
 *
 * Rate Limits (web requests):
 *   api_request:    100 requests/minute, 5-minute block on exceed
 *   login_attempt:  5 attempts/5 minutes, 15-minute block on exceed
 *   transaction:    20 transactions/minute, 10-minute block on exceed
 *   contact_add:    10 contacts/minute, 5-minute block on exceed
 *
 * Helper Functions Defined:
 *   h($string) - HTML encoding for safe output
 *   j($value)  - JavaScript encoding for safe output
 *   u($string) - URL encoding for safe output
 */

// Load PSR-4 autoloader (safe to call multiple times due to double-inclusion guard)
require_once __DIR__ . '/src/bootstrap.php';

use Eiou\Core\Constants;
use Eiou\Core\ErrorHandler;
use Eiou\Utils\Security;
use Eiou\Utils\SecureLogger;
use Eiou\Database\RateLimiterRepository;
use Eiou\Services\RateLimiterService;

// Initialize secure logging
SecureLogger::init(Constants::LOG_FILE_APP ?: '/var/log/eiou/app.log', Constants::LOG_LEVEL ?: 'INFO');

// Initialize error handler (must be done early)
ErrorHandler::init();

// Set security headers for web requests
if (php_sapi_name() !== 'cli') {
    Security::setSecurityHeaders();

    // Start secure session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        // Secure session configuration
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        // Set session timeout
        ini_set('session.gc_maxlifetime', 3600); // 1 hour
        ini_set('session.cookie_lifetime', 0); // Session cookie

        session_start();

        // Regenerate session ID periodically
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutes
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }

    // Initialize rate limiting (requires PDO connection)
    if (isset($pdo) && $pdo instanceof PDO) {
        $rateLimiterRepository = new RateLimiterRepository($pdo);
        $rateLimiterService = new RateLimiterService($rateLimiterRepository);

        // Define rate limits for different actions
        $rateLimits = [
            'api_request' => ['max' => 100, 'window' => 60, 'block' => 300],     // 100 requests per minute
            'login_attempt' => ['max' => 5, 'window' => 300, 'block' => 900],    // 5 attempts per 5 minutes
            'transaction' => ['max' => 20, 'window' => 60, 'block' => 600],      // 20 transactions per minute
            'contact_add' => ['max' => 10, 'window' => 60, 'block' => 300],      // 10 contacts per minute
        ];

        // Apply rate limiting based on current action
        $action = 'api_request'; // Default action

        // Determine action based on request
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'login':
                case 'authenticate':
                    $action = 'login_attempt';
                    break;
                case 'sendTransaction':
                case 'createTransaction':
                    $action = 'transaction';
                    break;
                case 'addContact':
                case 'acceptContact':
                    $action = 'contact_add';
                    break;
            }
        }

        // Enforce rate limit
        $rateLimiterService->enforce($action, $rateLimits[$action]);
    }
}

// Note: Error and exception handlers are now managed by ErrorHandler::init()
// No need to set them here as ErrorHandler provides centralized error handling

/**
 * Secure HTML encoding for output
 *
 * @param string $string The string to encode
 * @return string HTML-encoded string safe for browser output
 */
function h($string) {
    return Security::htmlEncode($string);
}

/**
 * Secure JavaScript encoding for output
 *
 * @param mixed $value The value to encode (will be JSON-encoded)
 * @return string JSON-encoded string safe for JavaScript contexts
 */
function j($value) {
    return Security::jsEncode($value);
}

/**
 * Secure URL encoding
 *
 * @param string $string The string to URL-encode
 * @return string URL-encoded string safe for use in URLs
 */
function u($string) {
    return Security::urlEncode($string);
}

// Log application start
SecureLogger::info("Application initialized", [
    'sapi' => php_sapi_name(),
    'ip' => php_sapi_name() !== 'cli' ? RateLimiterService::getClientIp() : 'CLI',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
]);