<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Time Utility Service Interface
 *
 * Defines the contract for time-related utility functions.
 */
interface TimeUtilityServiceInterface
{
    /**
     * Get current micro-time stamp in integer form
     *
     * @return int Microtime stamp
     */
    public function getCurrentMicrotime(): int;

    /**
     * Convert float microtime to integer
     *
     * Moves decimal places to create integer timestamp
     *
     * @param float $time Microtime float
     * @return int Converted microtime
     */
    public function convertMicrotimeToInt(float $time): int;

    /**
     * Check if expiration time has passed
     *
     * @param int $expirationTime Expiration timestamp to check against current time
     * @return bool True if expired (current time > expiration time)
     */
    public function isExpired(int $expirationTime): bool;

    /**
     * Calculate expiration time from current time
     *
     * @param int $ttlSeconds Time to live in seconds
     * @return int Expiration timestamp
     */
    public function calculateExpiration(int $ttlSeconds): int;
}
