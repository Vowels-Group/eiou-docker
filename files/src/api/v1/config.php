<?php
/**
 * API Configuration
 *
 * Copyright 2025
 * Configuration settings for EIOU REST API v1
 */

return [
    // API Version
    'version' => '1.0.0',
    'api_prefix' => '/api/v1',

    // Rate Limiting
    'rate_limit' => [
        'enabled' => true,
        'requests_per_minute' => 100,
        'burst_limit' => 200,
        'storage' => 'file', // 'file' or 'database'
        'storage_path' => '/tmp/api_rate_limit'
    ],

    // Authentication
    'auth' => [
        'required' => true,
        'type' => 'api_key', // 'api_key', 'bearer', or 'both'
        'header_name' => 'X-API-Key',
        'key_length' => 32,
        'keys_file' => '/etc/eiou/api_keys.json'
    ],

    // CORS Settings
    'cors' => [
        'enabled' => true,
        'allowed_origins' => ['*'], // ['https://example.com'] or ['*']
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
        'max_age' => 86400 // 24 hours
    ],

    // Response Format
    'response' => [
        'include_timestamp' => true,
        'include_request_id' => true,
        'pretty_print' => false, // Set to true for development
        'charset' => 'utf-8'
    ],

    // Logging
    'logging' => [
        'enabled' => true,
        'log_file' => '/var/log/api_access.log',
        'log_level' => 'info', // 'debug', 'info', 'warning', 'error'
        'log_requests' => true,
        'log_responses' => false // Set to true for debugging
    ],

    // Security
    'security' => [
        'https_only' => false, // Set to true in production
        'ip_whitelist' => [], // Empty = allow all
        'ip_blacklist' => [],
        'max_request_size' => 1048576, // 1MB in bytes
        'request_timeout' => 30 // seconds
    ],

    // Pagination
    'pagination' => [
        'default_limit' => 20,
        'max_limit' => 100
    ],

    // Cache
    'cache' => [
        'enabled' => false, // Enable when ready
        'ttl' => 300, // 5 minutes
        'driver' => 'file'
    ]
];
