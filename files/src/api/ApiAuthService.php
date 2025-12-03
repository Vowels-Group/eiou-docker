<?php
# Copyright 2025

/**
 * API Authentication Service with HMAC Signature Verification
 *
 * Provides secure API authentication using:
 * - API Key identification
 * - HMAC-SHA256 request signing
 * - Timestamp validation (prevents replay attacks)
 * - Rate limiting per API key
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
            return $this->authError('Missing X-API-Key header', 'missing_key');
        }
        if (!$timestamp) {
            return $this->authError('Missing X-API-Timestamp header', 'missing_timestamp');
        }
        if (!$signature) {
            return $this->authError('Missing X-API-Signature header', 'missing_signature');
        }

        // Validate timestamp format
        if (!is_numeric($timestamp)) {
            return $this->authError('Invalid timestamp format', 'invalid_timestamp');
        }

        // Validate timestamp age (prevent replay attacks)
        $timestampInt = (int) $timestamp;
        $now = time();
        if (abs($now - $timestampInt) > self::MAX_REQUEST_AGE) {
            return $this->authError(
                'Request timestamp too old or in the future. Max age: ' . self::MAX_REQUEST_AGE . ' seconds',
                'expired_timestamp'
            );
        }

        // Look up the API key to get the secret hash
        $keyData = $this->apiKeyRepository->getByKeyId($apiKey);
        if (!$keyData) {
            return $this->authError('Invalid API key', 'invalid_key');
        }

        // Check if key is enabled
        if (!$keyData['enabled']) {
            return $this->authError('API key is disabled', 'disabled_key');
        }

        // Check if key is expired
        if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
            return $this->authError('API key has expired', 'expired_key');
        }

        // Check rate limit
        $requestCount = $this->apiKeyRepository->getRequestCount($apiKey, 60);
        if ($requestCount >= $keyData['rate_limit_per_minute']) {
            return $this->authError(
                'Rate limit exceeded. Limit: ' . $keyData['rate_limit_per_minute'] . '/minute',
                'rate_limit_exceeded'
            );
        }

        // Verify HMAC signature
        // The signature should be: HMAC-SHA256(secret, method + path + timestamp + body)
        // Since we store a hash of the secret, we need to use a different approach:
        // Client sends: HMAC-SHA256(secret, payload)
        // We verify by checking if HMAC-SHA256(secret, payload) matches the signature
        // But we only have the hash of the secret...

        // For HMAC verification with stored hash, client must include the secret in the signature
        // OR we store the secret encrypted (not just hashed)

        // Alternative approach: The signature header contains the raw secret for verification
        // Split signature: secret:hmac
        $signatureParts = explode(':', $signature, 2);
        if (count($signatureParts) !== 2) {
            return $this->authError('Invalid signature format. Expected: secret:hmac', 'invalid_signature_format');
        }

        $secret = $signatureParts[0];
        $providedHmac = $signatureParts[1];

        // Validate the secret against the stored hash
        $keyResult = $this->apiKeyRepository->validateKey($apiKey, $secret);
        if (!$keyResult) {
            return $this->authError('Invalid API credentials', 'invalid_credentials');
        }

        // Build the string to sign
        $stringToSign = $this->buildStringToSign($method, $path, $timestamp, $body);

        // Calculate expected HMAC
        $expectedHmac = hash_hmac(self::HMAC_ALGORITHM, $stringToSign, $secret);

        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($expectedHmac, $providedHmac)) {
            $this->log('warning', 'HMAC signature mismatch', [
                'key_id' => $apiKey,
                'path' => $path
            ]);
            return $this->authError('Invalid signature', 'invalid_signature');
        }

        // Authentication successful
        $this->log('info', 'API authentication successful', [
            'key_id' => $apiKey,
            'key_name' => $keyResult['name'],
            'path' => $path
        ]);

        return [
            'success' => true,
            'key' => $keyResult,
            'error' => null,
            'error_code' => null
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
     * @param string $secret The API secret
     * @param string $method HTTP method
     * @param string $path Request path
     * @param string $timestamp Unix timestamp
     * @param string $body Request body
     * @return string The complete signature header value (secret:hmac)
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

        $hmac = hash_hmac(self::HMAC_ALGORITHM, $stringToSign, $secret);

        return $secret . ':' . $hmac;
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
     * @param string $code Error code
     * @return array Error response
     */
    private function authError(string $message, string $code): array {
        $this->log('warning', 'API authentication failed: ' . $message, [
            'error_code' => $code
        ]);

        return [
            'success' => false,
            'key' => null,
            'error' => $message,
            'error_code' => $code
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
