<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Address Validator Utility
 *
 * Static utility class for validating network address formats.
 * This class has zero dependencies and can be used anywhere in the application,
 * including before ServiceContainer is initialized.
 *
 * Supported address types:
 * - HTTP: http://hostname or http://hostname:port
 * - HTTPS: https://hostname or https://hostname:port
 * - Tor: *.onion addresses
 *
 * @package Eiou\Utils
 */

namespace Eiou\Utils;

class AddressValidator
{
    /**
     * Check if address is HTTPS
     *
     * @param string $address The address to check
     * @return bool True if HTTPS address, false otherwise
     */
    public static function isHttpsAddress(string $address): bool
    {
        return preg_match('/^https:\/\//i', $address) === 1;
    }

    /**
     * Check if address is HTTP only (not HTTPS)
     *
     * @param string $address The address to check
     * @return bool True if HTTP address (not HTTPS), false otherwise
     */
    public static function isHttpAddress(string $address): bool
    {
        return preg_match('/^http:\/\//i', $address) === 1
            && preg_match('/^https:\/\//i', $address) === 0;
    }

    /**
     * Check if address is a Tor (.onion) address
     *
     * @param string $address The address to check
     * @return bool True if Tor address, false otherwise
     */
    public static function isTorAddress(string $address): bool
    {
        return preg_match('/^[a-z2-7]{56}\.onion(:\d{1,5})?$/', $address) === 1;
    }

    /**
     * Check if address is a valid HTTP, HTTPS, or Tor address
     *
     * @param string $address The address to check
     * @return bool True if valid address, false otherwise
     */
    public static function isAddress(string $address): bool
    {
        return self::isHttpAddress($address)
            || self::isHttpsAddress($address)
            || self::isTorAddress($address);
    }

    /**
     * Determine the transport type from an address
     *
     * @param string $address The address to check
     * @return string|null The transport type ('http', 'https', 'tor') or null if unknown
     */
    public static function getTransportType(string $address): ?string
    {
        if (self::isTorAddress($address)) {
            return 'tor';
        }
        if (self::isHttpsAddress($address)) {
            return 'https';
        }
        if (self::isHttpAddress($address)) {
            return 'http';
        }
        return null;
    }

    /**
     * Categorize an address into an associative array by type
     *
     * @param string $address The address to categorize
     * @return array|null Associative array like ['http' => $address] or null if unknown
     */
    public static function categorizeAddress(string $address): ?array
    {
        $type = self::getTransportType($address);
        if ($type === null) {
            return null;
        }
        return [$type => $address];
    }
}
