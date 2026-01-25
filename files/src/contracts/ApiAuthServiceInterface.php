<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * API Authentication Service Interface
 *
 * Defines the contract for API authentication services with HMAC signature verification.
 *
 * Provides secure API authentication using:
 * - API Key identification
 * - HMAC-SHA256 request signing (server-side verification)
 * - Timestamp validation (prevents replay attacks)
 * - Rate limiting per API key
 *
 * @package Eiou\Contracts
 */
interface ApiAuthServiceInterface
{
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
     * @return array ['success' => bool, 'key' => array|null, 'error' => string|null, 'code' => string|null]
     */
    public function authenticate(
        string $method,
        string $path,
        string $body,
        array $headers
    ): array;

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
    ): string;

    /**
     * Check if authenticated key has a specific permission
     *
     * @param array $keyData Key data from authentication
     * @param string $permission Permission to check
     * @return bool True if permission is granted
     */
    public function hasPermission(array $keyData, string $permission): bool;

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
    ): string;

    /**
     * Get all request headers from $_SERVER
     *
     * @return array Headers array
     */
    public static function getRequestHeaders(): array;

    /**
     * Get client IP address
     *
     * @return string IP address
     */
    public static function getClientIp(): string;
}
