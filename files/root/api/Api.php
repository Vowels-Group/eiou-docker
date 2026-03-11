<?php
# Copyright 2025-2026 Vowels Group, LLC

// Block requests during startup/upgrade (source sync, migrations)
require_once dirname(__DIR__) . '/src/startup/MaintenanceCheck.php';

// Load Composer autoloader
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Core\Application;
use Eiou\Api\ApiController;
use Eiou\Services\ApiAuthService;

/**
 * API Entry Point
 *
 * Handles all incoming REST API requests
 *
 * All requests should be directed here via nginx try_files:
 * location /api/ { try_files $uri /api/index.php?$args; }
 */

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');

// Unauthenticated health check endpoint for Docker healthcheck / load balancers.
// Returns basic status without exposing internal details.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestPath === '/api/health') {
    $health = ['status' => 'ok', 'timestamp' => date('c')];
    $httpCode = 200;

    // Check database connectivity
    try {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=eiou',
            'eiou',
            trim(file_get_contents('/run/secrets/db_password') ?: (getenv('DB_PASSWORD') ?: 'eiou')),
            [PDO::ATTR_TIMEOUT => 3]
        );
        $pdo->query('SELECT 1');
        $health['database'] = 'healthy';
    } catch (\Exception $e) {
        $health['database'] = 'unhealthy';
        $health['status'] = 'degraded';
        $httpCode = 503;
    }

    // Check message processors (PID files)
    $processors = ['p2pmessages' => 'p2p', 'transactionmessages' => 'transaction', 'cleanupmessages' => 'cleanup', 'contact_status' => 'contact_status'];
    $health['processors'] = [];
    foreach ($processors as $pidFile => $name) {
        $pidPath = "/tmp/{$pidFile}_lock.pid";
        $running = false;
        if (file_exists($pidPath)) {
            $pid = trim(file_get_contents($pidPath));
            $running = $pid && file_exists("/proc/{$pid}");
        }
        $health['processors'][$name] = $running;
    }

    http_response_code($httpCode);
    echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Set CORS headers for API access (configurable per user via defaultconfig.json)
try {
    $corsOrigins = \Eiou\Core\UserContext::getInstance()->getApiCorsAllowedOrigins();
} catch (\Exception $e) {
    $corsOrigins = Constants::API_CORS_ALLOWED_ORIGINS;
}
if (!empty($corsOrigins)) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($corsOrigins === '*') {
        error_log("SECURITY WARNING: CORS wildcard origin configured - restrict API_CORS_ALLOWED_ORIGINS in production");
        header('Access-Control-Allow-Origin: *');
    } else {
        $allowedOrigins = array_map('trim', explode(',', $corsOrigins));
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: X-API-Key, X-API-Timestamp, X-API-Signature, X-API-Nonce, Content-Type');
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(ErrorCodes::HTTP_OK);
    exit;
}

// Initialize application
try {
    $app = Application::getInstance();

    // Check if API access is enabled (user-configurable)
    if (!\Eiou\Core\UserContext::getInstance()->getApiEnabled()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'API access is disabled', 'code' => 'api_disabled'],
            'timestamp' => date('c')
        ]);
        exit;
    }

    // Set security headers for API responses
    \Eiou\Utils\Security::setSecurityHeaders();
} catch (\Exception $e) {
    http_response_code(ErrorCodes::HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Service unavailable',
            'code' => 'service_unavailable'
        ],
        'timestamp' => date('c')
    ]);
    exit;
}

// Initialize API components using ServiceContainer
$authService = $app->services->getApiAuthService();
$apiKeyRepo = $app->services->getApiKeyRepository();
$logger = $app->getLogger();
$controller = new ApiController($authService, $apiKeyRepo, $app->services, $logger);

// Get request details
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$params = $_GET;
$body = file_get_contents('php://input') ?: '';
$headers = ApiAuthService::getRequestHeaders();

// Handle the request
$response = $controller->handleRequest($method, $path, $params, $body, $headers);

// Set HTTP status code
http_response_code($response['status_code'] ?? 200);

// Add rate limit headers
if (isset($response['data']['rate_limit'])) {
    header('X-RateLimit-Limit: ' . $response['data']['rate_limit']['limit']);
    header('X-RateLimit-Remaining: ' . $response['data']['rate_limit']['remaining']);
    header('X-RateLimit-Reset: ' . $response['data']['rate_limit']['reset']);
}

// Remove internal status_code from response
unset($response['status_code']);

// Output JSON response
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
