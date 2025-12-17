<?php
# Copyright 2025

require_once __DIR__ . '/../core/ErrorCodes.php';

/**
 * API Authentication Service with HMAC Signature Verification
 *
 * Provides secure API authentication using:
 * - API Key identification
 * - HMAC-SHA256 request signing (server-side verification)
 * - Timestamp validation (prevents replay attacks)
 * - Rate limiting per API key
 *
 * Security: API secrets are stored encrypted in the database and retrieved
 * only when needed for HMAC verification. The client never sends the secret
 * in requests - only the computed HMAC signature.
 */

class ApiAuthService {
    private $apiKeyRepository;
    private $logger;

    /**
     * Maximum age of a request timestamp in seconds (5 minutes)
     */
    private const MAX_REQUEST_AGE = 300;

    /**
     * HMAC algorithm to use
     */
    private const HMAC_ALGORITHM = 'sha256';

    /**
     * Constructor
     *
     * @param ApiKeyRepository $apiKeyRepository
     * @param SecureLogger|null $logger
     */
    public function __construct($apiKeyRepository, $logger = null) {
        $this->apiKeyRepository = $apiKeyRepository;
        $this->logger = $logger;
    }

    /**
     * Authenticate an API request
     *
     * Required headers:
     * - X-API-Key: The API key ID (eiou_...)
     * - X-API-Timestamp: Unix timestamp of request
     * - X-API-Signature: HMAC-SHA256 signature
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path (e.g., /api/v1/wallet/balance)
     * @param string $body Request body (empty string for GET)
     * @param array $headers Request headers
     * @return array ['success' => bool, 'key' => array|null, 'error' => string|null]
     */
    public function authenticate(
        string $method,
        string $path,
        string $body,
        array $headers
    ): array {
        // Extract required headers (case-insensitive)
        $apiKey = $this->getHeader($headers, 'X-API-Key');
        $timestamp = $this->getHeader($headers, 'X-API-Timestamp');
        $signature = $this->getHeader($headers, 'X-API-Signature');

        // Validate all required headers are present
        if (!$apiKey) {
            return $this->authError('Missing X-API-Key header', ErrorCodes::AUTH_MISSING_KEY);
        }
        if (!$timestamp) {
            return $this->authError('Missing X-API-Timestamp header', ErrorCodes::AUTH_MISSING_TIMESTAMP);
        }
        if (!$signature) {
            return $this->authError('Missing X-API-Signature header', ErrorCodes::AUTH_MISSING_SIGNATURE);
        }

        // Validate timestamp format
        if (!is_numeric($timestamp)) {
            return $this->authError('Invalid timestamp format', ErrorCodes::AUTH_INVALID_TIMESTAMP);
        }

        // Validate timestamp age (prevent replay attacks)
        $timestampInt = (int) $timestamp;
        $now = time();
        if (abs($now - $timestampInt) > self::MAX_REQUEST_AGE) {
            return $this->authError(
                'Request timestamp too old or in the future. Max age: ' . self::MAX_REQUEST_AGE . ' seconds',
                ErrorCodes::AUTH_EXPIRED_TIMESTAMP
            );
        }

        // Look up the API key to get the secret hash
        $keyData = $this->apiKeyRepository->getByKeyId($apiKey);
        if (!$keyData) {
            return $this->authError('Invalid API key', ErrorCodes::AUTH_INVALID_KEY);
        }

        // Check if key is enabled
        if (!$keyData['enabled']) {
            return $this->authError('API key is disabled', ErrorCodes::AUTH_KEY_DISABLED);
        }

        // Check if key is expired
        if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
            return $this->authError('API key has expired', ErrorCodes::AUTH_KEY_EXPIRED);
        }

        // Check rate limit
        $requestCount = $this->apiKeyRepository->getRequestCount($apiKey, 60);
        if ($requestCount >= $keyData['rate_limit_per_minute']) {
            return $this->authError(
                'Rate limit exceeded. Limit: ' . $keyData['rate_limit_per_minute'] . '/minute',
                ErrorCodes::RATE_LIMIT_EXCEEDED
            );
        }

        // Retrieve decrypted secret from database for HMAC verification
        // The secret is stored encrypted, not hashed, allowing server-side HMAC computation
        $secret = $this->apiKeyRepository->getSecretByKeyId($apiKey);
        if (!$secret) {
            return $this->authError('Invalid API credentials', ErrorCodes::AUTH_INVALID_CREDENTIALS);
        }

        // Build the string to sign
        $stringToSign = $this->buildStringToSign($method, $path, $timestamp, $body);

        // Calculate expected HMAC using the decrypted secret
        $expectedHmac = hash_hmac(self::HMAC_ALGORITHM, $stringToSign, $secret);

        // Constant-time comparison to prevent timing attacks
        // Client sends only the HMAC signature, not the secret
        if (!hash_equals($expectedHmac, $signature)) {
            $this->log('warning', 'HMAC signature mismatch', [
                'key_id' => $apiKey,
                'path' => $path
            ]);

            // Clear secret from memory
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }

            return $this->authError('Invalid signature', ErrorCodes::AUTH_INVALID_SIGNATURE);
        }

        // Clear secret from memory after use
        if (function_exists('sodium_memzero')) {
            sodium_memzero($secret);
        }

        // Get full key data for return
        $keyResult = $keyData;
        $keyResult['permissions'] = $keyData['permissions'];

        // Update last used timestamp
        $this->apiKeyRepository->updateLastUsed($apiKey);

        // Authentication successful
        $this->log('info', 'API authentication successful', [
            'key_id' => $apiKey,
            'key_name' => $keyData['name'],
            'path' => $path
        ]);

        return [
            'success' => true,
            'key' => $keyResult,
            'error' => null,
            'code' => null
        ];
    }

    /**
     * Build the string to sign for HMAC
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param string $timestamp Unix timestamp
     * @param string $body Request body
     * @return string String to sign
     */
    public function buildStringToSign(
        string $method,
        string $path,
        string $timestamp,
        string $body
    ): string {
        // Normalize method to uppercase
        $method = strtoupper($method);

        // Build canonical string: METHOD\nPATH\nTIMESTAMP\nBODY
        return implode("\n", [
            $method,
            $path,
            $timestamp,
            $body
        ]);
    }

    /**
     * Generate a signature for a request (client-side helper)
     *
     * The client computes HMAC-SHA256(secret, stringToSign) and sends only the
     * resulting signature. The secret is NEVER transmitted in the request.
     *
     * @param string $secret The API secret
     * @param string $method HTTP method
     * @param string $path Request path
     * @param string $timestamp Unix timestamp
     * @param string $body Request body
     * @return string The HMAC signature (hex encoded)
     */
    public static function generateSignature(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $body = ''
    ): string {
        $stringToSign = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $body
        ]);

        return hash_hmac(self::HMAC_ALGORITHM, $stringToSign, $secret);
    }

    /**
     * Get a header value (case-insensitive)
     *
     * @param array $headers Headers array
     * @param string $name Header name
     * @return string|null Header value or null
     */
    private function getHeader(array $headers, string $name): ?string {
        // Try exact match first
        if (isset($headers[$name])) {
            return $headers[$name];
        }

        // Try case-insensitive match
        $nameLower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $nameLower) {
                return $value;
            }
        }

        // Try HTTP_ prefix (for $_SERVER style headers)
        $httpName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($headers[$httpName])) {
            return $headers[$httpName];
        }

        return null;
    }

    /**
     * Create an authentication error response
     *
     * @param string $message Error message
     * @param string $code Error code (use ErrorCodes constants)
     * @return array Error response
     */
    private function authError(string $message, string $code): array {
        $this->log('warning', 'API authentication failed: ' . $message, [
            'code' => $code
        ]);

        return [
            'success' => false,
            'key' => null,
            'error' => $message,
            'code' => $code
        ];
    }

    /**
     * Log a message if logger is available
     *
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context data
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level($message, $context);
        }
    }

    /**
     * Check if authenticated key has a specific permission
     *
     * @param array $keyData Key data from authentication
     * @param string $permission Permission to check
     * @return bool True if permission is granted
     */
    public function hasPermission(array $keyData, string $permission): bool {
        require_once dirname(__DIR__) . '/database/ApiKeyRepository.php';
        return ApiKeyRepository::hasPermission($keyData['permissions'], $permission);
    }

    /**
     * Get all request headers from $_SERVER
     *
     * @return array Headers array
     */
    public static function getRequestHeaders(): array {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                // Convert HTTP_X_API_KEY to X-Api-Key
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }

        // Also check for headers that don't have HTTP_ prefix
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Get client IP address
     *
     * @return string IP address
     */
    public static function getClientIp(): string {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }
}
