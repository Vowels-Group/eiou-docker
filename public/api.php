<?php
# Copyright 2025

/**
 * API Entry Point
 *
 * Main entry point for the eIOU HTTP REST API.
 * This file handles all incoming API requests and routes them to appropriate controllers.
 *
 * @package API
 */

// Error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set error log location
ini_set('error_log', '/var/log/eiou-api-error.log');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

// Load required files
require_once('/etc/eiou/functions.php');

// Check if config exists, if not run fresh install
if (!file_exists('/etc/eiou/config.php')) {
    freshInstall();
}
require_once('/etc/eiou/config.php');

// Load API components
require_once(__DIR__ . '/../src/api/ResponseFormatter.php');
require_once(__DIR__ . '/../src/api/AuthMiddleware.php');
require_once(__DIR__ . '/../src/api/Router.php');
require_once(__DIR__ . '/../src/api/ApiController.php');

// Load service container
require_once('/etc/eiou/src/services/ServiceContainer.php');

// Set up error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");

    // Don't execute PHP internal error handler
    return true;
});

// Set up exception handler
set_exception_handler(function($exception) {
    error_log('Uncaught exception: ' . $exception->getMessage());
    error_log('Stack trace: ' . $exception->getTraceAsString());

    ResponseFormatter::serverError(
        'An unexpected error occurred',
        $exception
    );
});

try {
    // Create controller
    $controller = new ApiController();

    // Create router
    $router = new Router($controller);

    // Dispatch request
    $router->dispatch();

} catch (Exception $e) {
    error_log('Fatal API error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());

    ResponseFormatter::serverError(
        'A fatal error occurred while processing your request',
        $e
    );
}
