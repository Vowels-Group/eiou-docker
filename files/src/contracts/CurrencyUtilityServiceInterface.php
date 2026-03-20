<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

use Eiou\Core\SplitAmount;

/**
 * Currency Utility Service Interface
 *
 * Defines the contract for currency formatting, conversion, and calculations.
 * All amounts use SplitAmount (whole + frac) representation.
 */
interface CurrencyUtilityServiceInterface
{
    /**
     * Format currency from SplitAmount to display string with currency suffix
     *
     * @param SplitAmount $amount Amount as SplitAmount
     * @param string $currency Currency code (default: USD)
     * @return string Formatted currency string
     */
    public function formatCurrency(SplitAmount $amount, string $currency = 'USD'): string;

    /**
     * Convert SplitAmount to major units (float) for display
     *
     * @param SplitAmount $amount Amount as SplitAmount
     * @param string $currency Currency code (default: USD)
     * @return float Amount in major units (e.g. dollars)
     */
    public function convertMinorToMajor(SplitAmount $amount, string $currency = 'USD'): float;

    /**
     * Convert amount from major units to SplitAmount
     *
     * @param float $amountInMajorUnits Amount in major units (e.g. dollars)
     * @param string $currency Currency code (default: USD)
     * @return SplitAmount
     */
    public function convertMajorToMinor(float $amountInMajorUnits, string $currency = 'USD'): SplitAmount;

    /**
     * Calculate fee amount from percentage
     *
     * @param SplitAmount $amount Base amount
     * @param float $feePercent Fee as raw percentage (e.g., 0.01 for 0.01%, 2.5 for 2.5%)
     * @param float $minimumFee Minimum fee in major units (e.g., 0.01 for $0.01)
     * @param string $currency Currency code (default: USD)
     * @return SplitAmount Fee amount
     */
    public function calculateFee(SplitAmount $amount, float $feePercent, float $minimumFee, string $currency = 'USD'): SplitAmount;

    /**
     * Calculate fee percentage from amounts
     *
     * @param SplitAmount $totalAmount Total amount including fee
     * @param SplitAmount $baseAmount Base amount before fee
     * @return float Fee percentage
     */
    public function calculateFeePercent(SplitAmount $totalAmount, SplitAmount $baseAmount): float;
}
