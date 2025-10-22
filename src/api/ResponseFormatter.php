<?php
# Copyright 2025

/**
 * Response Formatter
 *
 * Handles JSON response formatting with proper HTTP status codes and CORS headers.
 *
 * @package API
 */
class ResponseFormatter {
    /**
     * Send JSON response with proper headers
     *
     * @param mixed $data Response data
     * @param int $statusCode HTTP status code
     * @param array $headers Additional headers
     * @return void
     */
    public static function json($data, int $statusCode = 200, array $headers = []): void {
        // Set HTTP status code
        http_response_code($statusCode);

        // Set default headers
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        // Set custom headers
        foreach ($headers as $key => $value) {
            header("$key: $value");
        }

        // Output JSON
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send success response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $statusCode HTTP status code (default 200)
     * @return void
     */
    public static function success($data = null, string $message = 'Success', int $statusCode = 200): void {
        $response = [
            'status' => 'success',
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        self::json($response, $statusCode);
    }

    /**
     * Send error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default 400)
     * @param array $errors Additional error details
     * @return void
     */
    public static function error(string $message, int $statusCode = 400, array $errors = []): void {
        $response = [
            'status' => 'error',
            'message' => $message
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        self::json($response, $statusCode);
    }

    /**
     * Send 404 Not Found response
     *
     * @param string $message Error message
     * @return void
     */
    public static function notFound(string $message = 'Resource not found'): void {
        self::error($message, 404);
    }

    /**
     * Send 401 Unauthorized response
     *
     * @param string $message Error message
     * @return void
     */
    public static function unauthorized(string $message = 'Unauthorized'): void {
        self::error($message, 401);
    }

    /**
     * Send 403 Forbidden response
     *
     * @param string $message Error message
     * @return void
     */
    public static function forbidden(string $message = 'Forbidden'): void {
        self::error($message, 403);
    }

    /**
     * Send 500 Internal Server Error response
     *
     * @param string $message Error message
     * @param Exception|null $exception Optional exception for debugging
     * @return void
     */
    public static function serverError(string $message = 'Internal server error', ?Exception $exception = null): void {
        $errors = [];

        // Include exception details in debug mode
        if ($exception && defined('DEBUG_MODE') && DEBUG_MODE) {
            $errors['exception'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        self::error($message, 500, $errors);
    }

    /**
     * Send 400 Bad Request response
     *
     * @param string $message Error message
     * @param array $validationErrors Validation errors
     * @return void
     */
    public static function badRequest(string $message = 'Bad request', array $validationErrors = []): void {
        self::error($message, 400, $validationErrors);
    }

    /**
     * Send 201 Created response
     *
     * @param mixed $data Created resource data
     * @param string $message Success message
     * @return void
     */
    public static function created($data = null, string $message = 'Resource created successfully'): void {
        self::success($data, $message, 201);
    }

    /**
     * Send 204 No Content response
     *
     * @return void
     */
    public static function noContent(): void {
        http_response_code(204);
        header('Access-Control-Allow-Origin: *');
        exit;
    }

    /**
     * Handle CORS preflight requests
     *
     * @return void
     */
    public static function handlePreflight(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
            exit;
        }
    }
}
