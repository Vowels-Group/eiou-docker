<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * Error Codes Constants
 *
 * Centralized error codes for consistent error handling across the codebase.
 * All error codes follow RFC 9457 (Problem Details) conventions.
 *
 * Naming Convention:
 * - Use SCREAMING_SNAKE_CASE for constant names
 * - Group related errors by prefix (e.g., CONTACT_, TRANSACTION_, AUTH_)
 * - Codes should be descriptive and machine-readable
 *
 * HTTP Status Code Mapping:
 * - 400: Bad Request (validation errors, invalid input)
 * - 401: Unauthorized (authentication required)
 * - 403: Forbidden (permission denied)
 * - 404: Not Found (resource doesn't exist)
 * - 409: Conflict (resource already exists)
 * - 429: Too Many Requests (rate limiting)
 * - 500: Internal Server Error (server-side failures)
 *
 * @package Core
 */

class ErrorCodes
{
    // General errors
    public const GENERAL_ERROR = 'GENERAL_ERROR';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const NOT_FOUND = 'NOT_FOUND';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const TIMEOUT = 'TIMEOUT';
    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    // Authentication & Authorization errors
    public const AUTHENTICATION_ERROR = 'AUTHENTICATION_ERROR';
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const AUTH_INVALID = 'AUTH_INVALID';
    public const AUTH_EXPIRED = 'AUTH_EXPIRED';
    public const AUTH_MISSING_HEADER = 'AUTH_MISSING_HEADER';
    public const AUTH_INVALID_FORMAT = 'AUTH_INVALID_FORMAT';
    public const AUTH_KEY_DISABLED = 'AUTH_KEY_DISABLED';
    public const AUTH_PERMISSION_DENIED = 'AUTH_PERMISSION_DENIED';
    public const PERMISSION_DENIED = 'PERMISSION_DENIED';
    public const UNAUTHORIZED = 'UNAUTHORIZED';

    // API Key specific errors
    public const AUTH_MISSING_KEY = 'AUTH_MISSING_KEY';
    public const AUTH_MISSING_TIMESTAMP = 'AUTH_MISSING_TIMESTAMP';
    public const AUTH_MISSING_SIGNATURE = 'AUTH_MISSING_SIGNATURE';
    public const AUTH_INVALID_TIMESTAMP = 'AUTH_INVALID_TIMESTAMP';
    public const AUTH_EXPIRED_TIMESTAMP = 'AUTH_EXPIRED_TIMESTAMP';
    public const AUTH_INVALID_KEY = 'AUTH_INVALID_KEY';
    public const AUTH_KEY_EXPIRED = 'AUTH_KEY_EXPIRED';
    public const AUTH_INVALID_SIGNATURE = 'AUTH_INVALID_SIGNATURE';
    public const AUTH_INVALID_SIGNATURE_FORMAT = 'AUTH_INVALID_SIGNATURE_FORMAT';
    public const AUTH_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';

    // Rate limiting errors
    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    // Wallet errors
    public const WALLET_EXISTS = 'WALLET_EXISTS';
    public const WALLET_NOT_FOUND = 'WALLET_NOT_FOUND';
    public const INVALID_HOSTNAME = 'INVALID_HOSTNAME';
    public const SEED_RESTORE_FAILED = 'SEED_RESTORE_FAILED';
    public const INVALID_SEED_PHRASE = 'INVALID_SEED_PHRASE';
    public const INVALID_WORD_COUNT = 'INVALID_WORD_COUNT';
    public const INVALID_CHECKSUM = 'INVALID_CHECKSUM';

    // Contact errors
    public const CONTACT_NOT_FOUND = 'CONTACT_NOT_FOUND';
    public const CONTACT_EXISTS = 'CONTACT_EXISTS';
    public const CONTACT_BLOCKED = 'CONTACT_BLOCKED';
    public const CONTACT_REJECTED = 'CONTACT_REJECTED';
    public const CONTACT_CREATE_FAILED = 'CONTACT_CREATE_FAILED';
    public const SELF_CONTACT = 'SELF_CONTACT';
    public const ACCEPT_FAILED = 'ACCEPT_FAILED';
    public const BLOCK_FAILED = 'BLOCK_FAILED';
    public const UNBLOCK_FAILED = 'UNBLOCK_FAILED';
    public const UNBLOCK_ADD_FAILED = 'UNBLOCK_ADD_FAILED';
    public const DELETE_FAILED = 'DELETE_FAILED';
    public const UPDATE_FAILED = 'UPDATE_FAILED';
    public const ADDRESS_UPDATE_FAILED = 'ADDRESS_UPDATE_FAILED';
    public const NO_CONTACTS = 'NO_CONTACTS';
    public const CONTACT_UNREACHABLE = 'CONTACT_UNREACHABLE';

    // Transaction errors
    public const TRANSACTION_FAILED = 'TRANSACTION_FAILED';
    public const TRANSACTION_IN_PROGRESS = 'TRANSACTION_IN_PROGRESS';
    public const INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
    public const INVALID_AMOUNT = 'INVALID_AMOUNT';
    public const INVALID_CURRENCY = 'INVALID_CURRENCY';
    public const INVALID_RECIPIENT = 'INVALID_RECIPIENT';
    public const SELF_SEND = 'SELF_SEND';
    public const CHAIN_INTEGRITY_FAILED = 'CHAIN_INTEGRITY_FAILED';

    // Transport/Routing errors
    public const NO_VIABLE_TRANSPORT = 'NO_VIABLE_TRANSPORT';
    public const NO_VIABLE_ROUTE = 'NO_VIABLE_ROUTE';
    public const P2P_CANCELLED = 'P2P_CANCELLED';

    // Input validation errors
    public const INVALID_ADDRESS = 'INVALID_ADDRESS';
    public const INVALID_NAME = 'INVALID_NAME';
    public const INVALID_FEE = 'INVALID_FEE';
    public const INVALID_CREDIT = 'INVALID_CREDIT';
    public const INVALID_PARAMS = 'INVALID_PARAMS';
    public const INVALID_FIELD = 'INVALID_FIELD';
    public const INVALID_PERMISSION = 'INVALID_PERMISSION';
    public const INVALID_SETTING = 'INVALID_SETTING';
    public const INVALID_SYNC_TYPE = 'INVALID_SYNC_TYPE';
    public const INVALID_ARGUMENT = 'INVALID_ARGUMENT';
    public const MISSING_ARGUMENT = 'MISSING_ARGUMENT';
    public const MISSING_PARAMS = 'MISSING_PARAMS';
    public const MISSING_IDENTIFIER = 'MISSING_IDENTIFIER';
    public const MISSING_ADDRESS = 'MISSING_ADDRESS';
    public const NO_ADDRESS = 'NO_ADDRESS';

    // File errors
    public const FILE_NOT_FOUND = 'FILE_NOT_FOUND';
    public const FILE_NOT_READABLE = 'FILE_NOT_READABLE';

    // API Key errors
    public const API_KEY_NOT_FOUND = 'API_KEY_NOT_FOUND';
    public const CREATE_FAILED = 'CREATE_FAILED';
    public const LIST_FAILED = 'LIST_FAILED';
    public const DISABLE_FAILED = 'DISABLE_FAILED';
    public const ENABLE_FAILED = 'ENABLE_FAILED';

    // Command errors
    public const COMMAND_NOT_FOUND = 'COMMAND_NOT_FOUND';
    public const INTERACTIVE_NOT_SUPPORTED = 'INTERACTIVE_NOT_SUPPORTED';

    // Connection errors
    public const CONNECTION_FAILED = 'CONNECTION_FAILED';
    public const NETWORK_ERROR = 'NETWORK_ERROR';

    // HTTP Status Codes
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_CONFLICT = 409;
    public const HTTP_TOO_MANY_REQUESTS = 429;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;
    public const HTTP_SERVICE_UNAVAILABLE = 503;
    public const HTTP_GATEWAY_TIMEOUT = 504;

    // User-facing error messages
    public const MESSAGE_GENERIC = 'An error occurred. Please try again later.';
    public const MESSAGE_INVALID_INPUT = 'Invalid input provided.';
    public const MESSAGE_DATABASE_CONNECTION = 'Database connection failed.';
    public const MESSAGE_UNAUTHORIZED = 'Unauthorized access.';
    public const MESSAGE_RATE_LIMITED = 'Too many requests. Please try again later.';

    /**
     * Get HTTP status code for an error code
     *
     * @param string $code Error code
     * @return int HTTP status code
     */
    public static function getHttpStatus(string $code): int
    {
        $statusMap = [
            // 400 Bad Request
            self::VALIDATION_ERROR => 400,
            self::INVALID_ADDRESS => 400,
            self::INVALID_NAME => 400,
            self::INVALID_FEE => 400,
            self::INVALID_CREDIT => 400,
            self::INVALID_AMOUNT => 400,
            self::INVALID_CURRENCY => 400,
            self::INVALID_PARAMS => 400,
            self::INVALID_FIELD => 400,
            self::INVALID_PERMISSION => 400,
            self::INVALID_SETTING => 400,
            self::INVALID_SYNC_TYPE => 400,
            self::INVALID_HOSTNAME => 400,
            self::INVALID_SEED_PHRASE => 400,
            self::INVALID_WORD_COUNT => 400,
            self::INVALID_CHECKSUM => 400,
            self::INVALID_RECIPIENT => 400,
            self::MISSING_ARGUMENT => 400,
            self::MISSING_PARAMS => 400,
            self::MISSING_IDENTIFIER => 400,
            self::MISSING_ADDRESS => 400,
            self::SELF_CONTACT => 400,
            self::SELF_SEND => 400,
            self::NO_CONTACTS => 400,
            self::INTERACTIVE_NOT_SUPPORTED => 400,
            self::INVALID_ARGUMENT => 400,

            // 401 Unauthorized
            self::AUTHENTICATION_ERROR => 401,
            self::AUTH_REQUIRED => 401,
            self::AUTH_INVALID => 401,
            self::AUTH_EXPIRED => 401,
            self::AUTH_MISSING_HEADER => 401,
            self::AUTH_INVALID_FORMAT => 401,
            self::UNAUTHORIZED => 401,
            self::AUTH_MISSING_KEY => 401,
            self::AUTH_MISSING_TIMESTAMP => 401,
            self::AUTH_MISSING_SIGNATURE => 401,
            self::AUTH_INVALID_TIMESTAMP => 401,
            self::AUTH_EXPIRED_TIMESTAMP => 401,
            self::AUTH_INVALID_KEY => 401,
            self::AUTH_KEY_EXPIRED => 401,
            self::AUTH_INVALID_SIGNATURE => 401,
            self::AUTH_INVALID_SIGNATURE_FORMAT => 401,
            self::AUTH_INVALID_CREDENTIALS => 401,

            // 403 Forbidden
            self::PERMISSION_DENIED => 403,
            self::AUTH_PERMISSION_DENIED => 403,
            self::AUTH_KEY_DISABLED => 403,
            self::CONTACT_BLOCKED => 403,
            self::CONTACT_REJECTED => 403,
            self::INSUFFICIENT_FUNDS => 403,

            // 403 Forbidden
            self::FILE_NOT_READABLE => 403,

            // 404 Not Found
            self::NOT_FOUND => 404,
            self::WALLET_NOT_FOUND => 404,
            self::CONTACT_NOT_FOUND => 404,
            self::API_KEY_NOT_FOUND => 404,
            self::COMMAND_NOT_FOUND => 404,
            self::FILE_NOT_FOUND => 404,

            // 409 Conflict
            self::WALLET_EXISTS => 409,
            self::CONTACT_EXISTS => 409,

            // 429 Too Many Requests
            self::RATE_LIMIT_EXCEEDED => 429,
            self::TRANSACTION_IN_PROGRESS => 429,

            // 500 Internal Server Error
            self::INTERNAL_ERROR => 500,
            self::GENERAL_ERROR => 500,
            self::TRANSACTION_FAILED => 500,
            self::CHAIN_INTEGRITY_FAILED => 500,
            self::CONTACT_CREATE_FAILED => 500,
            self::ACCEPT_FAILED => 500,
            self::BLOCK_FAILED => 500,
            self::UNBLOCK_FAILED => 500,
            self::UNBLOCK_ADD_FAILED => 500,
            self::DELETE_FAILED => 500,
            self::UPDATE_FAILED => 500,
            self::ADDRESS_UPDATE_FAILED => 500,
            self::CREATE_FAILED => 500,
            self::LIST_FAILED => 500,
            self::DISABLE_FAILED => 500,
            self::ENABLE_FAILED => 500,
            self::NO_ADDRESS => 500,
            self::SEED_RESTORE_FAILED => 500,
            self::CONNECTION_FAILED => 500,
            self::NETWORK_ERROR => 500,

            // 503 Service Unavailable
            self::CONTACT_UNREACHABLE => 503,
            self::NO_VIABLE_TRANSPORT => 503,
            self::NO_VIABLE_ROUTE => 503,
            self::P2P_CANCELLED => 503,

            // 504 Gateway Timeout
            self::TIMEOUT => 504,
        ];

        return $statusMap[$code] ?? 500;
    }

    /**
     * Get human-readable title for an error code
     *
     * @param string $code Error code
     * @return string Human-readable title
     */
    public static function getTitle(string $code): string
    {
        $titles = [
            self::GENERAL_ERROR => 'General Error',
            self::VALIDATION_ERROR => 'Validation Error',
            self::NOT_FOUND => 'Not Found',
            self::INTERNAL_ERROR => 'Internal Server Error',
            self::TIMEOUT => 'Request Timeout',
            self::UNKNOWN_ERROR => 'Unknown Error',

            self::AUTHENTICATION_ERROR => 'Authentication Error',
            self::AUTH_REQUIRED => 'Authentication Required',
            self::AUTH_INVALID => 'Invalid Credentials',
            self::AUTH_EXPIRED => 'Session Expired',
            self::AUTH_MISSING_HEADER => 'Missing Authorization Header',
            self::AUTH_INVALID_FORMAT => 'Invalid Authorization Format',
            self::AUTH_KEY_DISABLED => 'API Key Disabled',
            self::AUTH_PERMISSION_DENIED => 'Permission Denied',
            self::PERMISSION_DENIED => 'Permission Denied',
            self::UNAUTHORIZED => 'Unauthorized',
            self::AUTH_MISSING_KEY => 'Missing API Key',
            self::AUTH_MISSING_TIMESTAMP => 'Missing Timestamp',
            self::AUTH_MISSING_SIGNATURE => 'Missing Signature',
            self::AUTH_INVALID_TIMESTAMP => 'Invalid Timestamp',
            self::AUTH_EXPIRED_TIMESTAMP => 'Expired Timestamp',
            self::AUTH_INVALID_KEY => 'Invalid API Key',
            self::AUTH_KEY_EXPIRED => 'API Key Expired',
            self::AUTH_INVALID_SIGNATURE => 'Invalid Signature',
            self::AUTH_INVALID_SIGNATURE_FORMAT => 'Invalid Signature Format',
            self::AUTH_INVALID_CREDENTIALS => 'Invalid Credentials',

            self::RATE_LIMIT_EXCEEDED => 'Rate Limit Exceeded',

            self::WALLET_EXISTS => 'Wallet Already Exists',
            self::WALLET_NOT_FOUND => 'Wallet Not Found',
            self::INVALID_HOSTNAME => 'Invalid Hostname',
            self::SEED_RESTORE_FAILED => 'Seed Restore Failed',
            self::INVALID_SEED_PHRASE => 'Invalid Seed Phrase',
            self::INVALID_WORD_COUNT => 'Invalid Word Count',
            self::INVALID_CHECKSUM => 'Invalid Checksum',

            self::CONTACT_NOT_FOUND => 'Contact Not Found',
            self::CONTACT_EXISTS => 'Contact Already Exists',
            self::CONTACT_BLOCKED => 'Contact Blocked',
            self::CONTACT_REJECTED => 'Contact Request Rejected',
            self::CONTACT_CREATE_FAILED => 'Contact Creation Failed',
            self::SELF_CONTACT => 'Cannot Add Self as Contact',
            self::SELF_SEND => 'Cannot Send to Yourself',
            self::ACCEPT_FAILED => 'Accept Failed',
            self::BLOCK_FAILED => 'Block Failed',
            self::UNBLOCK_FAILED => 'Unblock Failed',
            self::UNBLOCK_ADD_FAILED => 'Unblock and Add Failed',
            self::DELETE_FAILED => 'Delete Failed',
            self::UPDATE_FAILED => 'Update Failed',
            self::ADDRESS_UPDATE_FAILED => 'Address Update Failed',
            self::NO_CONTACTS => 'No Contacts Available',
            self::CONTACT_UNREACHABLE => 'Contact Unreachable',

            self::TRANSACTION_FAILED => 'Transaction Failed',
            self::TRANSACTION_IN_PROGRESS => 'Transaction In Progress',
            self::INSUFFICIENT_FUNDS => 'Insufficient Funds',
            self::INVALID_AMOUNT => 'Invalid Amount',
            self::INVALID_CURRENCY => 'Invalid Currency',
            self::INVALID_RECIPIENT => 'Invalid Recipient',
            self::CHAIN_INTEGRITY_FAILED => 'Chain Integrity Failed',

            self::INVALID_ADDRESS => 'Invalid Address',
            self::INVALID_NAME => 'Invalid Name',
            self::INVALID_FEE => 'Invalid Fee',
            self::INVALID_CREDIT => 'Invalid Credit',
            self::INVALID_PARAMS => 'Invalid Parameters',
            self::INVALID_FIELD => 'Invalid Field',
            self::INVALID_PERMISSION => 'Invalid Permission',
            self::INVALID_SETTING => 'Invalid Setting',
            self::INVALID_SYNC_TYPE => 'Invalid Sync Type',
            self::MISSING_ARGUMENT => 'Missing Argument',
            self::MISSING_PARAMS => 'Missing Parameters',
            self::MISSING_IDENTIFIER => 'Missing Identifier',
            self::MISSING_ADDRESS => 'Missing Address',
            self::NO_ADDRESS => 'No Address Available',

            self::API_KEY_NOT_FOUND => 'API Key Not Found',
            self::CREATE_FAILED => 'Creation Failed',
            self::LIST_FAILED => 'List Failed',
            self::DISABLE_FAILED => 'Disable Failed',
            self::ENABLE_FAILED => 'Enable Failed',

            self::COMMAND_NOT_FOUND => 'Command Not Found',
            self::INTERACTIVE_NOT_SUPPORTED => 'Interactive Mode Not Supported',

            self::CONNECTION_FAILED => 'Connection Failed',
            self::NETWORK_ERROR => 'Network Error',

            self::FILE_NOT_FOUND => 'File Not Found',
            self::FILE_NOT_READABLE => 'File Not Readable',
            self::INVALID_ARGUMENT => 'Invalid Argument',

            self::NO_VIABLE_TRANSPORT => 'No Viable Transport',
            self::NO_VIABLE_ROUTE => 'No Viable Route',
            self::P2P_CANCELLED => 'P2P Route Cancelled',
        ];

        return $titles[$code] ?? ucwords(strtolower(str_replace('_', ' ', $code)));
    }

    /**
     * Check if an error code is valid
     *
     * @param string $code Error code to check
     * @return bool True if valid
     */
    public static function isValid(string $code): bool
    {
        $reflection = new \ReflectionClass(self::class);
        $constants = $reflection->getConstants();

        return in_array($code, $constants, true);
    }

    /**
     * Get all error codes
     *
     * @return array<string, string> Array of error codes
     */
    public static function all(): array
    {
        $reflection = new \ReflectionClass(self::class);
        return $reflection->getConstants();
    }
}
