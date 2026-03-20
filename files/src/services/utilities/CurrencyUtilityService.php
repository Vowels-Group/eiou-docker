<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Utilities;

use Eiou\Contracts\CurrencyUtilityServiceInterface;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;

/**
 * Currency Utility Service
 *
 * Handles currency formatting, conversion, and calculations.
 * All amounts use SplitAmount (whole + frac) representation.
 */
class CurrencyUtilityService implements CurrencyUtilityServiceInterface
{
    public function __construct()
    {
    }

    /**
     * Format currency from SplitAmount to display string with currency suffix
     *
     * @param SplitAmount $amount Amount as SplitAmount
     * @param string $currency Currency code (default: USD)
     * @return string Formatted currency string
     */
    public function formatCurrency(SplitAmount $amount, string $currency = 'USD'): string
    {
        return number_format($amount->toMajorUnits(), Constants::getDisplayDecimals($currency)) . ' ' . $currency;
    }

    /**
     * Convert SplitAmount to major units (float) for display
     *
     * @param SplitAmount $amount Amount as SplitAmount
     * @param string $currency Currency code (default: USD)
     * @return float Amount in major units (e.g. dollars)
     */
    public function convertMinorToMajor(SplitAmount $amount, string $currency = 'USD'): float
    {
        return $amount->toMajorUnits();
    }

    /**
     * Convert amount from major units to SplitAmount
     *
     * @param float $amountInMajorUnits Amount in major units (e.g. dollars)
     * @param string $currency Currency code (default: USD)
     * @return SplitAmount
     */
    public function convertMajorToMinor(float $amountInMajorUnits, string $currency = 'USD'): SplitAmount
    {
        return SplitAmount::fromMajorUnits($amountInMajorUnits);
    }

    /**
     * Calculate fee amount from percentage
     *
     * @param SplitAmount $amount Base amount
     * @param float $feePercent Fee as raw percentage (e.g., 0.01 for 0.01%, 2.5 for 2.5%)
     * @param float $minimumFee Minimum fee in major units (e.g., 0.01 for $0.01)
     * @param string $currency Currency code (default: USD)
     * @return SplitAmount Fee amount
     */
    public function calculateFee(SplitAmount $amount, float $feePercent, float $minimumFee, string $currency = 'USD'): SplitAmount
    {
        $feeAmount = $amount->multiplyPercent($feePercent);
        $minFeeSplit = SplitAmount::fromMajorUnits($minimumFee);
        if ($feeAmount->lt($minFeeSplit)) {
            return $minFeeSplit;
        }
        return $feeAmount;
    }

    /**
     * Calculate fee percentage from amounts
     *
     * @param SplitAmount $totalAmount Total amount including fee
     * @param SplitAmount $baseAmount Base amount before fee
     * @return float Fee percentage
     */
    public function calculateFeePercent(SplitAmount $totalAmount, SplitAmount $baseAmount): float
    {
        if ($baseAmount->isZero()) {
            return 0.0;
        }

        $feeAmount = $totalAmount->subtract($baseAmount);
        // Convert both to floats for percentage calculation
        $feeFloat = $feeAmount->toMajorUnits();
        $baseFloat = $baseAmount->toMajorUnits();
        return round(($feeFloat / $baseFloat) * Constants::FEE_CONVERSION_FACTOR, Constants::FEE_PERCENT_DECIMAL_PRECISION);
    }

    /**
     * Convert major units to SplitAmount (static convenience).
     * Replaces the old exactMajorToMinor that returned int.
     *
     * @param float $majorUnits Amount in major units
     * @param int $factor Conversion factor (ignored — always uses SplitAmount)
     * @return SplitAmount
     */
    public static function exactMajorToMinor(float $majorUnits, int $factor): SplitAmount
    {
        // For fee_percent conversions where factor is FEE_CONVERSION_FACTOR (100),
        // we still need the old integer behavior — fee_percent is a simple scaled int,
        // not a monetary amount (e.g., 0.01% → stored as 1, 2.50% → stored as 250).
        if ($factor === Constants::FEE_CONVERSION_FACTOR) {
            $result = (int) \bcmul((string) $majorUnits, (string) $factor, 0);
            return new SplitAmount($result, 0);
        }
        return SplitAmount::fromMajorUnits($majorUnits);
    }

    /**
     * Exact multiplication then division using bcmath.
     * Used for fee calculations: amount * percent / 100.
     *
     * @param SplitAmount $amount Amount
     * @param float $multiplier Multiplier (e.g., fee percent)
     * @param float $divisor Divisor (e.g., 100)
     * @return SplitAmount Result
     */
    public static function exactMulDiv(SplitAmount $amount, float $multiplier, float $divisor): SplitAmount
    {
        return $amount->mulDiv($multiplier, $divisor);
    }

}
