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
     * Format currency from cents to dollars with currency suffix
     *
     * @param float $amountInCents Amount in cents
     * @param string $currency Currency code (default: USD)
     * @return string Formatted currency string
     */
    public function formatCurrency(float $amountInCents, string $currency = 'USD'): string;

    /**
     * Convert amount from cents to dollars
     *
     * @param float $amountInCents Amount in cents
     * @return float Amount in dollars
     */
    public function convertCentsToDollars(float $amountInCents): float;

    /**
     * Convert amount from dollars to cents
     *
     * @param float $amountInDollars Amount in dollars
     * @return int Amount in cents
     */
    public function convertDollarsToCents(float $amountInDollars): int;

    /**
     * Calculate fee amount from percentage
     *
     * @param float $amount Base amount
     * @param float $feePercent Fee percentage (e.g., 2.5 for 2.5%)
     * @param float $minumFee Fee amount (e.g., 0.01 for 1 cent)
     * @return int Fee amount in cents
     */
    public function calculateFee(float $amount, float $feePercent, float $minumFee): int;

    /**
     * Calculate fee percentage from amounts
     *
     * @param float $totalAmount Total amount including fee
     * @param float $baseAmount Base amount before fee
     * @return float Fee percentage
     */
    public function calculateFeePercent(float $totalAmount, float $baseAmount): float;
}
