<?php
/**
 * Security initialization file
 * Include this at the beginning of all PHP entry points
 */

// Load constants
require_once __DIR__ . '/src/core/Constants.php';

// Load error handler (must be loaded before other security classes)
require_once __DIR__ . '/src/core/ErrorHandler.php';

// Load security classes
require_once __DIR__ . '/src/utils/Security.php';
require_once __DIR__ . '/src/utils/RateLimiter.php';
require_once __DIR__ . '/src/utils/SecureLogger.php';

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
        $rateLimiter = new RateLimiter($pdo);

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
        $rateLimiter->enforce($action, $rateLimits[$action]);
    }
}

// Note: Error and exception handlers are now managed by ErrorHandler::init()
// No need to set them here as ErrorHandler provides centralized error handling

// Helper function for secure output encoding
function h($string) {
    return Security::htmlEncode($string);
}

// Helper function for JavaScript encoding
function j($value) {
    return Security::jsEncode($value);
}

// Helper function for URL encoding
function u($string) {
    return Security::urlEncode($string);
}

// Log application start
SecureLogger::info("Application initialized", [
    'sapi' => php_sapi_name(),
    'ip' => php_sapi_name() !== 'cli' ? RateLimiter::getClientIp() : 'CLI',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
]);