<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Currency Utility Service Interface
 *
 * Defines the contract for currency formatting, conversion, and calculations.
 */
interface CurrencyUtilityServiceInterface
{
    /**
     * Format currency from minor units to major units with currency suffix
     *
     * @param float $amountInMinorUnits Amount in minor units (e.g. cents)
     * @param string $currency Currency code (default: USD)
     * @return string Formatted currency string
     */
    public function formatCurrency(float $amountInMinorUnits, string $currency = 'USD'): string;

    /**
     * Convert amount from minor units to major units
     *
     * @param float $amountInMinorUnits Amount in minor units (e.g. cents)
     * @param string $currency Currency code (default: USD)
     * @return float Amount in major units (e.g. dollars)
     */
    public function convertMinorToMajor(float $amountInMinorUnits, string $currency = 'USD'): float;

    /**
     * Convert amount from major units to minor units
     *
     * @param float $amountInMajorUnits Amount in major units (e.g. dollars)
     * @param string $currency Currency code (default: USD)
     * @return int Amount in minor units (e.g. cents)
     */
    public function convertMajorToMinor(float $amountInMajorUnits, string $currency = 'USD'): int;

    /**
     * Calculate fee amount from percentage
     *
     * @param float $amount Base amount in minor units (e.g. cents, satoshi)
     * @param float $feePercent Fee as raw percentage (e.g., 0.01 for 0.01%, 2.5 for 2.5%)
     * @param float $minumFee Minimum fee in major units (e.g., 0.01 for $0.01)
     * @param string $currency Currency code (default: USD)
     * @return int Fee amount in minor units
     */
    public function calculateFee(float $amount, float $feePercent, float $minumFee, string $currency = 'USD'): int;

    /**
     * Calculate fee percentage from amounts
     *
     * @param float $totalAmount Total amount including fee
     * @param float $baseAmount Base amount before fee
     * @return float Fee percentage
     */
    public function calculateFeePercent(float $totalAmount, float $baseAmount): float;
}
