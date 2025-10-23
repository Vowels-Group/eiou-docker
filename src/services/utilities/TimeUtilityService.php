<?php
# Copyright 2025

/**
 * Time Utility Service
 *
 * Handles all time-related utility functions.
 *
 * @package Services\Utilities
 */

class TimeUtilityService
{
    /**
     * @var Constants Environment constants
     */
    private Constants $constants;

    /**
     * Constructor
     *
     * @param Constants $constants Environment constants
     */
    public function __construct(Constants $constants)
    {
        $this->constants = $constants;
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
        return (int) ($time * 10000);
    }

    /**
     * Check if timestamp has expired
     *
     * @param int $timestamp Timestamp to check
     * @param int $expirationTime Expiration time
     * @return bool True if expired
     */
    public function isExpired(int $timestamp, int $expirationTime): bool
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
        return $this->getCurrentMicrotime() + ($ttlSeconds * 10000);
    }
}
