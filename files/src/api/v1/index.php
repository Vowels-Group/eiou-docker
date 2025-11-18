<?php
/**
 * EIOU REST API v1 - Main Entry Point
 *
 * Copyright 2025
 * RESTful API for EIOU wallet operations
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display errors in production

// Load configuration
$config = require __DIR__ . '/config.php';

// Load dependencies
require_once __DIR__ . '/middleware/ApiAuth.php';
require_once __DIR__ . '/middleware/RateLimiter.php';
require_once __DIR__ . '/helpers/ApiResponse.php';
require_once __DIR__ . '/controllers/WalletController.php';

// Initialize response handler
$apiResponse = new ApiResponse($config);

// Set CORS headers
ApiResponse::setCorsHeaders($config['cors']);

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Get request URI and method
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string
$requestUri = strtok($requestUri, '?');

// Remove API prefix
$apiPrefix = $config['api_prefix'];
if (strpos($requestUri, $apiPrefix) === 0) {
    $requestUri = substr($requestUri, strlen($apiPrefix));
}

// Trim slashes
$requestUri = trim($requestUri, '/');

try {
    // Initialize authentication
    $auth = new ApiAuth($config);
    $authResult = $auth->authenticate();

    if (!$authResult['authenticated']) {
        $apiResponse->error($authResult['error'], 401, 'UNAUTHORIZED');
    }

    $keyInfo = $authResult['key_info'] ?? [];

    // Update key usage statistics
    if (!empty($keyInfo)) {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $apiKey = str_replace('Bearer ', '', $apiKey);
        $auth->updateKeyUsage($apiKey);
    }

    // Initialize rate limiter
    $rateLimiter = new RateLimiter($config);
    $identifier = $keyInfo['name'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $limitInfo = $rateLimiter->checkRateLimit($identifier);

    // Set rate limit headers
    ApiResponse::setRateLimitHeaders($limitInfo);

    // Check if rate limit exceeded
    if (!$limitInfo['allowed']) {
        $apiResponse->error(
            'Rate limit exceeded. Try again in ' . ($limitInfo['reset'] - time()) . ' seconds',
            429,
            'RATE_LIMIT_EXCEEDED',
            ['retry_after' => $limitInfo['reset']]
        );
    }

    // Route the request
    routeRequest($requestUri, $requestMethod, $apiResponse, $keyInfo, $config);

} catch (Exception $e) {
    // Log error if logging is enabled
    if ($config['logging']['enabled']) {
        error_log('[API Error] ' . $e->getMessage());
    }

    $apiResponse->error(
        'Internal server error',
        500,
        'INTERNAL_ERROR'
    );
}

/**
 * Route incoming request to appropriate controller
 *
 * @param string $uri Request URI
 * @param string $method HTTP method
 * @param ApiResponse $response Response handler
 * @param array $keyInfo API key information
 * @param array $config Configuration
 */
function routeRequest(
    string $uri,
    string $method,
    ApiResponse $response,
    array $keyInfo,
    array $config
): void {
    // Parse URI into segments
    $segments = explode('/', $uri);

    // Get resource and action
    $resource = $segments[0] ?? '';
    $action = $segments[1] ?? '';
    $id = $segments[2] ?? null;

    // Initialize service container
    require_once __DIR__ . '/../../services/ServiceContainer.php';
    $serviceContainer = ServiceContainer::getInstance();

    // Route to appropriate controller
    switch ($resource) {
        case 'wallet':
            $controller = new WalletController($serviceContainer, $response, $keyInfo);
            routeWalletRequests($controller, $action, $method, $response);
            break;

        case 'contacts':
            // Import existing contact API logic from PR #168
            require_once __DIR__ . '/../../gui/api/contactApi.php';
            // The contactApi.php handles its own routing
            break;

        case 'system':
            routeSystemRequests($action, $method, $response, $config);
            break;

        case 'health':
        case '':
            // Health check endpoint
            $response->success([
                'status' => 'ok',
                'version' => $config['version'],
                'uptime' => getServerUptime()
            ]);
            break;

        default:
            $response->error(
                "Resource not found: $resource",
                404,
                'RESOURCE_NOT_FOUND'
            );
    }
}

/**
 * Route wallet-related requests
 */
function routeWalletRequests(
    WalletController $controller,
    string $action,
    string $method,
    ApiResponse $response
): void {
    switch ($action) {
        case 'balance':
            if ($method === 'GET') {
                $controller->getBalance();
            } else {
                $response->error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
            }
            break;

        case 'send':
            if ($method === 'POST') {
                $controller->send();
            } else {
                $response->error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
            }
            break;

        case 'address':
            if ($method === 'GET') {
                $controller->getAddress();
            } else {
                $response->error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
            }
            break;

        case 'transactions':
            if ($method === 'GET') {
                $controller->getTransactions();
            } else {
                $response->error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
            }
            break;

        default:
            $response->error("Unknown wallet action: $action", 404, 'ACTION_NOT_FOUND');
    }
}

/**
 * Route system-related requests
 */
function routeSystemRequests(
    string $action,
    string $method,
    ApiResponse $response,
    array $config
): void {
    if ($method !== 'GET') {
        $response->error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
    }

    switch ($action) {
        case 'status':
            $response->success([
                'status' => 'operational',
                'version' => $config['version'],
                'uptime' => getServerUptime(),
                'timestamp' => date('c')
            ]);
            break;

        case 'metrics':
            $response->success([
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'uptime' => getServerUptime()
            ]);
            break;

        default:
            $response->error("Unknown system action: $action", 404, 'ACTION_NOT_FOUND');
    }
}

/**
 * Get server uptime in seconds
 *
 * @return int
 */
function getServerUptime(): int
{
    if (file_exists('/proc/uptime')) {
        $uptime = file_get_contents('/proc/uptime');
        $uptime = explode(' ', $uptime);
        return (int)$uptime[0];
    }
    return 0;
}
