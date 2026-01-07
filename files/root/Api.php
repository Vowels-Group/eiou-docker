<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

require_once __DIR__ . '/src/core/ErrorCodes.php';
require_once __DIR__ . '/src/core/Constants.php';

/**
 * API Entry Point
 *
 * Handles all incoming REST API requests
 *
 * All requests should be directed here via Apache rewrite:
 * RewriteRule ^api/(.*)$ /etc/eiou/Api.php [L,QSA]
 */

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');

// Set CORS headers for API access (configurable via Constants)
$corsOrigins = Constants::API_CORS_ALLOWED_ORIGINS;
if (!empty($corsOrigins)) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($corsOrigins === '*') {
        header('Access-Control-Allow-Origin: *');
    } else {
        $allowedOrigins = array_map('trim', explode(',', $corsOrigins));
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, X-API-Timestamp, X-API-Signature, Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(ErrorCodes::HTTP_OK);
    exit;
}

// Initialize application
require_once '/etc/eiou/src/core/Application.php';
require_once '/etc/eiou/src/core/Constants.php';

try {
    $app = Application::getInstance();
} catch (Exception $e) {
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

// Check if API is enabled
if (!defined('Constants::API_ENABLED') || Constants::API_ENABLED !== true) {
    // API is enabled by default if constant is not set
}

// Load API components
require_once '/etc/eiou/src/api/ApiController.php';
require_once '/etc/eiou/src/services/ServiceWrappers.php';
require_once '/etc/eiou/src/schemas/OutputSchema.php';

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
