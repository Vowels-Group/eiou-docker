<?php
/**
 * Bootstrap file - Single point of entry for eIOU daemon
 *
 * This file ensures the application is properly initialized with all required
 * services, configurations, and dependencies in the correct order.
 *
 * @copyright 2025
 */

// Define application root paths
if (!defined('EIOU_ROOT')) {
    define('EIOU_ROOT', dirname(__DIR__));
}

if (!defined('EIOU_SRC')) {
    define('EIOU_SRC', __DIR__);
}

if (!defined('EIOU_CONFIG_DIR')) {
    define('EIOU_CONFIG_DIR', '/etc/eiou');
}

// Check if configuration exists, run fresh install if needed
if (!file_exists(EIOU_CONFIG_DIR . '/config.php')) {
    require_once EIOU_CONFIG_DIR . '/functions.php';
    if (function_exists('freshInstall')) {
        freshInstall();
    }
}

// Load configuration
require_once EIOU_CONFIG_DIR . '/config.php';

// Load core dependencies in correct order
require_once EIOU_SRC . '/core/UserContext.php';
require_once EIOU_SRC . '/core/Constants.php';
require_once EIOU_SRC . '/core/Application.php';

// Initialize Application singleton (will handle all service initialization)
$app = Application::getInstance();

// Initialize ServiceContainer with Application context
require_once EIOU_SRC . '/services/ServiceContainer.php';

// Ensure ServiceContainer uses Application's PDO instance
if (method_exists('ServiceContainer', 'setApplication')) {
    ServiceContainer::getInstance()->setApplication($app);
} else {
    // Fallback: Ensure ServiceContainer gets proper PDO from Application
    if (method_exists('ServiceContainer', 'setPdo')) {
        ServiceContainer::getInstance()->setPdo($app->getDatabase());
    }
}

// Load utility functions if they haven't been loaded
if (!function_exists('createPDOConnection')) {
    require_once EIOU_CONFIG_DIR . '/functions.php';
}

// Set up error handling
require_once EIOU_SRC . '/core/ErrorHandler.php';
ErrorHandler::register();

// Set up rate limiting (if not CLI)
if (!$app->isCli()) {
    $rateLimiter = $app->getRateLimiter();
    if ($rateLimiter) {
        // Check rate limit for web requests
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!$rateLimiter->checkLimit($clientIp)) {
            http_response_code(429);
            die("Rate limit exceeded");
        }
    }
}

// Initialize logging
$logger = $app->getLogger();
if ($logger) {
    $logger->log('info', 'Application bootstrap completed', [
        'mode' => $app->isCli() ? 'cli' : 'web',
        'path' => $_SERVER['REQUEST_URI'] ?? 'cli'
    ]);
}

// Return the application instance for use by calling scripts
return $app;