<?php
# Copyright 2025

/**
 * Currency Utility Service
 *
 * Handles currency formatting, conversion, and calculations.
 *
 * @package Services\Utilities
 */

require_once __DIR__ . '/../../core/Constants.php';

class CurrencyUtilityService
{
    /**
     * Constructor
     *
     */
    public function __construct()
    {
    }

    /**
     * Format currency from cents to dollars with currency suffix
     *
     * @param float $amountInCents Amount in cents
     * @param string $currency Currency code (default: USD)
     * @return string Formatted currency string
     */
    public function formatCurrency(float $amountInCents, string $currency = 'USD'): string
    {
        $amountInDollars = $this->convertCentsToDollars($amountInCents);
        return number_format($amountInDollars, 2) . ' ' . $currency;
    }

    /**
     * Convert amount from cents to dollars
     *
     * @param float $amountInCents Amount in cents
     * @return float Amount in dollars
     */
    public function convertCentsToDollars(float $amountInCents): float
    {
        $conversionFactor = Constants::TRANSACTION_USD_CONVERSION_FACTOR ?? 100;
        return $amountInCents / $conversionFactor;
    }

    /**
     * Convert amount from dollars to cents
     *
     * @param float $amountInDollars Amount in dollars
     * @return int Amount in cents
     */
    public function convertDollarsToCents(float $amountInDollars): int
    {
        $conversionFactor = Constants::TRANSACTION_USD_CONVERSION_FACTOR ?? 100;
        return (int) round($amountInDollars * $conversionFactor);
    }

    /**
     * Calculate fee amount from percentage
     *
     * @param float $amount Base amount
     * @param float $feePercent Fee percentage (e.g., 2.5 for 2.5%)
     * @return int Fee amount in cents
     */
    public function calculateFee(float $amount, float $feePercent): int
    {
        return (int) round(($amount / Constants::TRANSACTION_USD_CONVERSION_FACTOR)  * ($feePercent / Constants::FEE_CONVERSION_FACTOR));
    }

    /**
     * Calculate fee percentage from amounts
     *
     * @param float $totalAmount Total amount including fee
     * @param float $baseAmount Base amount before fee
     * @return float Fee percentage
     */
    public function calculateFeePercent(float $totalAmount, float $baseAmount): float
    {
        if ($baseAmount == 0) {
            return 0.0;
        }

        $feeAmount = $totalAmount - $baseAmount;
        return round(($feeAmount / $baseAmount) * Constants::FEE_CONVERSION_FACTOR, Constants::FEE_PERCENT_DECIMAL_PRECISION);
    }

    /**
     * Truncate address for easier display
     *
     * @param string $address The address
     * @param int $length Point of truncation
     * @return string Truncated address
     */
    public function truncateAddress(string $address, int $length = 10): string
    {
        if (strlen($address) <= $length) {
            return $address;
        }
        return substr($address, 0, $length) . '...';
    }
}
