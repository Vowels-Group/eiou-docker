<?php
/**
 * API Authentication Middleware
 *
 * Copyright 2025
 * Handles API key authentication and validation
 */

class ApiAuth
{
    private array $config;
    private array $validKeys;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->loadApiKeys();
    }

    /**
     * Load valid API keys from storage
     */
    private function loadApiKeys(): void
    {
        $keysFile = $this->config['auth']['keys_file'];

        if (file_exists($keysFile)) {
            $content = file_get_contents($keysFile);
            $this->validKeys = json_decode($content, true) ?? [];
        } else {
            $this->validKeys = [];
        }
    }

    /**
     * Authenticate API request
     *
     * @return array ['authenticated' => bool, 'key_info' => array|null, 'error' => string|null]
     */
    public function authenticate(): array
    {
        // Check if authentication is required
        if (!$this->config['auth']['required']) {
            return ['authenticated' => true, 'key_info' => null, 'error' => null];
        }

        // Get API key from header
        $apiKey = $this->getApiKeyFromRequest();

        if (empty($apiKey)) {
            return [
                'authenticated' => false,
                'key_info' => null,
                'error' => 'API key missing. Provide key in ' . $this->config['auth']['header_name'] . ' header'
            ];
        }

        // Validate API key
        $keyInfo = $this->validateApiKey($apiKey);

        if ($keyInfo === null) {
            return [
                'authenticated' => false,
                'key_info' => null,
                'error' => 'Invalid API key'
            ];
        }

        // Check if key is active
        if (isset($keyInfo['active']) && !$keyInfo['active']) {
            return [
                'authenticated' => false,
                'key_info' => null,
                'error' => 'API key is inactive'
            ];
        }

        // Check if key has expired
        if (isset($keyInfo['expires_at'])) {
            $expiresAt = strtotime($keyInfo['expires_at']);
            if ($expiresAt < time()) {
                return [
                    'authenticated' => false,
                    'key_info' => null,
                    'error' => 'API key has expired'
                ];
            }
        }

        return [
            'authenticated' => true,
            'key_info' => $keyInfo,
            'error' => null
        ];
    }

    /**
     * Get API key from request headers
     *
     * @return string|null
     */
    private function getApiKeyFromRequest(): ?string
    {
        $headerName = $this->config['auth']['header_name'];

        // Try X-API-Key header
        $headers = getallheaders();
        if (isset($headers[$headerName])) {
            return trim($headers[$headerName]);
        }

        // Try Authorization header (Bearer token)
        if (isset($headers['Authorization'])) {
            $auth = trim($headers['Authorization']);
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Validate API key against stored keys
     *
     * @param string $apiKey
     * @return array|null Key info if valid, null otherwise
     */
    private function validateApiKey(string $apiKey): ?array
    {
        // Hash the provided key to compare with stored hashes
        $hashedKey = hash('sha256', $apiKey);

        foreach ($this->validKeys as $keyData) {
            $storedHash = $keyData['key_hash'] ?? hash('sha256', $keyData['key'] ?? '');

            if (hash_equals($storedHash, $hashedKey)) {
                return $keyData;
            }
        }

        return null;
    }

    /**
     * Generate a new API key
     *
     * @param string $name Key name/description
     * @param array $permissions Key permissions
     * @param string|null $expiresAt Expiration date (ISO 8601)
     * @return array ['key' => string, 'key_hash' => string, 'info' => array]
     */
    public static function generateApiKey(
        string $name,
        array $permissions = [],
        ?string $expiresAt = null
    ): array {
        // Generate secure random key
        $key = bin2hex(random_bytes(32)); // 64 character hex string

        $keyInfo = [
            'name' => $name,
            'key_hash' => hash('sha256', $key),
            'permissions' => $permissions,
            'created_at' => date('c'),
            'expires_at' => $expiresAt,
            'active' => true,
            'last_used' => null,
            'usage_count' => 0
        ];

        return [
            'key' => $key,
            'key_hash' => $keyInfo['key_hash'],
            'info' => $keyInfo
        ];
    }

    /**
     * Save API keys to storage
     *
     * @param array $keys
     * @return bool
     */
    public function saveApiKeys(array $keys): bool
    {
        $keysFile = $this->config['auth']['keys_file'];
        $keysDir = dirname($keysFile);

        // Create directory if it doesn't exist
        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0700, true);
        }

        // Write keys to file
        $json = json_encode($keys, JSON_PRETTY_PRINT);
        $result = file_put_contents($keysFile, $json, LOCK_EX);

        // Set restrictive permissions
        if ($result !== false) {
            chmod($keysFile, 0600);
            return true;
        }

        return false;
    }

    /**
     * Update key usage statistics
     *
     * @param string $apiKey
     */
    public function updateKeyUsage(string $apiKey): void
    {
        $hashedKey = hash('sha256', $apiKey);

        foreach ($this->validKeys as &$keyData) {
            $storedHash = $keyData['key_hash'] ?? hash('sha256', $keyData['key'] ?? '');

            if (hash_equals($storedHash, $hashedKey)) {
                $keyData['last_used'] = date('c');
                $keyData['usage_count'] = ($keyData['usage_count'] ?? 0) + 1;
                $this->saveApiKeys($this->validKeys);
                break;
            }
        }
    }
}
