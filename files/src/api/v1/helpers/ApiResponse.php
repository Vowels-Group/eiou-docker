<?php
/**
 * API Response Helper
 *
 * Copyright 2025
 * Standardized JSON response formatting for API
 */

class ApiResponse
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Send success response
     *
     * @param mixed $data Response data
     * @param int $statusCode HTTP status code
     * @param array $meta Additional metadata
     */
    public function success($data = null, int $statusCode = 200, array $meta = []): void
    {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        $this->sendResponse($response, $statusCode);
    }

    /**
     * Send error response
     *
     * @param string $error Error message
     * @param int $statusCode HTTP status code
     * @param string|null $errorCode Application error code
     * @param array $details Additional error details
     */
    public function error(
        string $error,
        int $statusCode = 400,
        ?string $errorCode = null,
        array $details = []
    ): void {
        $response = [
            'success' => false,
            'error' => $error
        ];

        if ($errorCode !== null) {
            $response['error_code'] = $errorCode;
        }

        if (!empty($details)) {
            $response['details'] = $details;
        }

        $this->sendResponse($response, $statusCode);
    }

    /**
     * Send paginated response
     *
     * @param array $items Items for current page
     * @param int $total Total number of items
     * @param int $page Current page number
     * @param int $limit Items per page
     */
    public function paginated(array $items, int $total, int $page, int $limit): void
    {
        $totalPages = (int)ceil($total / $limit);

        $response = [
            'success' => true,
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ];

        $this->sendResponse($response, 200);
    }

    /**
     * Send HTTP response
     *
     * @param array $response Response data
     * @param int $statusCode HTTP status code
     */
    private function sendResponse(array $response, int $statusCode): void
    {
        // Add timestamp if configured
        if ($this->config['response']['include_timestamp']) {
            $response['timestamp'] = date('c');
        }

        // Add request ID if configured
        if ($this->config['response']['include_request_id']) {
            $response['request_id'] = $this->generateRequestId();
        }

        // Set HTTP status code
        http_response_code($statusCode);

        // Set headers
        header('Content-Type: application/json; charset=' . $this->config['response']['charset']);

        // Encode and output JSON
        $jsonFlags = 0;
        if ($this->config['response']['pretty_print']) {
            $jsonFlags |= JSON_PRETTY_PRINT;
        }

        echo json_encode($response, $jsonFlags);
        exit;
    }

    /**
     * Generate unique request ID
     *
     * @return string
     */
    private function generateRequestId(): string
    {
        return 'req_' . bin2hex(random_bytes(8));
    }

    /**
     * Set rate limit headers
     *
     * @param array $limitInfo Rate limit information
     */
    public static function setRateLimitHeaders(array $limitInfo): void
    {
        foreach (RateLimiter::getHeaders($limitInfo) as $name => $value) {
            header("$name: $value");
        }
    }

    /**
     * Set CORS headers
     *
     * @param array $corsConfig CORS configuration
     */
    public static function setCorsHeaders(array $corsConfig): void
    {
        if (!$corsConfig['enabled']) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Check if origin is allowed
        if (in_array('*', $corsConfig['allowed_origins']) || in_array($origin, $corsConfig['allowed_origins'])) {
            $allowedOrigin = in_array('*', $corsConfig['allowed_origins']) ? '*' : $origin;
            header("Access-Control-Allow-Origin: $allowedOrigin");
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $corsConfig['allowed_methods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $corsConfig['allowed_headers']));
        header('Access-Control-Max-Age: ' . $corsConfig['max_age']);

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
