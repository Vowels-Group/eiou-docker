<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Utilities;

use Eiou\Contracts\CurrencyUtilityServiceInterface;
use Eiou\Core\Constants;

/**
 * Currency Utility Service
 *
 * Handles currency formatting, conversion, and calculations.
 */
class CurrencyUtilityService implements CurrencyUtilityServiceInterface
{
    /**
     * Constructor
     *
     */
    public function __construct()
    {
    }

    /**
     * Format currency from minor units to major units with currency suffix
     *
     * @param float $amountInMinorUnits Amount in minor units (e.g. cents)
     * @param string $currency Currency code (default: USD)
     * @return string Formatted currency string
     */
    public function formatCurrency(float $amountInMinorUnits, string $currency = 'USD'): string
    {
        $amountInMajorUnits = $this->convertMinorToMajor($amountInMinorUnits, $currency);
        return number_format($amountInMajorUnits, Constants::getDisplayDecimals($currency)) . ' ' . $currency;
    }

    /**
     * Convert amount from minor units to major units
     *
     * @param float $amountInMinorUnits Amount in minor units (e.g. cents)
     * @param string $currency Currency code (default: USD)
     * @return float Amount in major units (e.g. dollars)
     */
    public function convertMinorToMajor(float $amountInMinorUnits, string $currency = 'USD'): float
    {
        return $amountInMinorUnits / Constants::getConversionFactor($currency);
    }

    /**
     * Convert amount from major units to minor units
     *
     * @param float $amountInMajorUnits Amount in major units (e.g. dollars)
     * @param string $currency Currency code (default: USD)
     * @return int Amount in minor units (e.g. cents)
     */
    public function convertMajorToMinor(float $amountInMajorUnits, string $currency = 'USD'): int
    {
        return self::exactMajorToMinor($amountInMajorUnits, Constants::getConversionFactor($currency));
    }

    /**
     * Calculate fee amount from percentage
     *
     * @param float $amount Base amount in minor units (e.g. cents, satoshi)
     * @param float $feePercent Fee as raw percentage (e.g., 0.01 for 0.01%, 2.5 for 2.5%)
     * @param float $minumFee Minimum fee in major units (e.g., 0.01 for $0.01)
     * @param string $currency Currency code (default: USD)
     * @return int Fee amount in minor units
     */
    public function calculateFee(float $amount, float $feePercent, float $minumFee, string $currency = 'USD'): int
    {
        $conversionFactor = Constants::getConversionFactor($currency);
        $feeAmount = self::exactMulDiv($amount, $feePercent, 100);
        $minFeeMinorUnits = self::exactMajorToMinor($minumFee, $conversionFactor);
        if ($feeAmount < $minFeeMinorUnits) {
            return $minFeeMinorUnits;
        }
        return $feeAmount;
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
     * Exact major-to-minor conversion using bcmul when available, sprintf fallback otherwise.
     * Avoids IEEE 754 float precision loss for large amounts.
     *
     * @param float $majorUnits Amount in major units
     * @param int $factor Conversion factor (e.g., 10^8)
     * @return int Amount in minor units
     */
    public static function exactMajorToMinor(float $majorUnits, int $factor): int
    {
        self::requireBcmath();
        return (int) \bcmul((string) $majorUnits, (string) $factor, 0);
    }

    /**
     * Exact multiplication then division using bcmath.
     * Used for fee calculations: amount * percent / 100.
     *
     * @param float $amount Amount (minor units)
     * @param float $multiplier Multiplier (e.g., fee percent)
     * @param float $divisor Divisor (e.g., 100)
     * @return int Result truncated to integer
     */
    public static function exactMulDiv(float $amount, float $multiplier, float $divisor): int
    {
        self::requireBcmath();
        return (int) \bcdiv(\bcmul((string) $amount, (string) $multiplier, 8), (string) $divisor, 0);
    }

    /**
     * Ensure bcmath extension is loaded. Throws if not.
     * bcmath is required for exact-precision currency arithmetic.
     */
    private static function requireBcmath(): void
    {
        if (!function_exists('bcmul')) {
            throw new \RuntimeException('The bcmath PHP extension is required. Install php-bcmath.');
        }
    }
}
