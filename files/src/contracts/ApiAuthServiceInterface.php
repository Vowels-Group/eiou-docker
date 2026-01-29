<?php
namespace Eiou\Contracts;

/**
 * API Authentication Service Interface
 *
 * Defines the contract for API authentication services with HMAC signature verification.
 */
interface ApiAuthServiceInterface
{
    /**
     * Authenticate an API request.
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param string $body Request body
     * @param array $headers Request headers
     * @return array Authentication result
     */
    public function authenticate(
        string $method,
        string $path,
        string $body,
        array $headers
    ): array;

    /**
     * Build the string to sign for HMAC.
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
     * Check if authenticated key has a specific permission.
     *
     * @param array $keyData Key data from authentication
     * @param string $permission Permission to check
     * @return bool True if permission is granted
     */
    public function hasPermission(array $keyData, string $permission): bool;
}
