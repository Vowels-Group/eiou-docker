<?php
# Copyright 2025-2026 Vowels Group, LLC
/**
 * General Utility Service Interface
 *
 * Defines the contract for general utility functions.
 *
 * @package Eiou\Contracts
 */
interface GeneralUtilityServiceInterface
{
    /**
     * Truncate address for easier display
     *
     * @param string $address The address
     * @param int $length Point of truncation
     * @return string Truncated address
     */
    public function truncateAddress(string $address, int $length = 10): string;
}
