<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Utilities;

use Eiou\Contracts\TimeUtilityServiceInterface;
use Eiou\Core\Constants;

/**
 * Time Utility Service
 *
 * Handles all time-related utility functions.
 */
class TimeUtilityService implements TimeUtilityServiceInterface
{
    /**
     * Constructor
     *
     */
    public function __construct()
    {
    }

    /**
     * Get current micro-time stamp in integer form
     *
     * @return int Microtime stamp
     */
    public function getCurrentMicrotime(): int
    {
        return $this->convertMicrotimeToInt(microtime(true));
    }

    /**
     * Convert float microtime to integer
     *
     * Moves decimal places to create integer timestamp
     *
     * @param float $time Microtime float
     * @return int Converted microtime
     */
    public function convertMicrotimeToInt(float $time): int
    {
        return (int) ($time * Constants::TIME_MICROSECONDS_TO_INT);
    }

    /**
     * Check if expiration time has passed
     *
     * @param int $expirationTime Expiration timestamp to check against current time
     * @return bool True if expired (current time > expiration time)
     */
    public function isExpired(int $expirationTime): bool
    {
        return $this->getCurrentMicrotime() > $expirationTime;
    }

    /**
     * Calculate expiration time from current time
     *
     * @param int $ttlSeconds Time to live in seconds
     * @return int Expiration timestamp
     */
    public function calculateExpiration(int $ttlSeconds): int
    {
        return $this->getCurrentMicrotime() + ($ttlSeconds * Constants::TIME_MICROSECONDS_TO_INT);
    }
}
