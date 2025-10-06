<?php
/**
 * Security initialization file
 * Include this at the beginning of all PHP entry points
 */

// Load security classes
require_once __DIR__ . '/utils/Security.php';
require_once __DIR__ . '/utils/RateLimiter.php';
require_once __DIR__ . '/utils/SecureLogger.php';

// Initialize secure logging
SecureLogger::init(getenv('LOG_FILE') ?: '/var/log/eiou/app.log', getenv('LOG_LEVEL') ?: 'INFO');

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

// Global error handler that doesn't expose sensitive information
set_error_handler(function($severity, $message, $file, $line) {
    // Log the full error
    SecureLogger::error("PHP Error", [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line
    ]);

    // In production, don't display errors to users
    if (getenv('APP_ENV') === 'production') {
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            echo "An error occurred. Please try again later.";
            exit;
        }
    }

    // Let PHP handle the error normally in development
    return false;
});

// Global exception handler
set_exception_handler(function($exception) {
    // Log the exception
    SecureLogger::logException($exception);

    // In production, don't display exceptions to users
    if (getenv('APP_ENV') === 'production') {
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            echo "An error occurred. Please try again later.";
            exit;
        }
    } else {
        // In development, show the exception
        echo "Exception: " . $exception->getMessage() . "\n";
        echo "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
    }
});

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