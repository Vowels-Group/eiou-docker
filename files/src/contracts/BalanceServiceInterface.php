<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Interface for balance-related operations.
 *
 * Defines the contract for retrieving and managing user and contact balances,
 * as well as converting contact information with balance data for display.
 *
 * @package Contracts
 */
interface BalanceServiceInterface
{
    /**
     * Convert contact information with balance data for display.
     *
     * Enriches contact array with balance information, transaction history,
     * and properly formatted currency values.
     *
     * @param array $contacts Contact information array
     * @param int $transactionLimit Maximum number of transactions to fetch per contact
     * @return array Converted contact information with balances
     */
    public function contactBalanceConversion(array $contacts, int $transactionLimit = 5): array;

    /**
     * Get user's total balance.
     *
     * Calculates the sum of all currency balances for the current user.
     * Uses BalanceRepository which correctly tracks only completed transactions.
     *
     * @return string Balance formatted as dollars (e.g., "10.50")
     */
    public function getUserTotalBalance(): string;

    /**
     * Get contact balance (optimized single query).
     *
     * @param string $userPubkey User's public key
     * @param string $contactPubkey Contact's public key
     * @return \Eiou\Core\SplitAmount Balance
     */
    public function getContactBalance(string $userPubkey, string $contactPubkey): \Eiou\Core\SplitAmount;

    /**
     * Get all contact balances.
     *
     * @param string $userPubkey User's public key
     * @param array $contactPubkeys Array of contact public keys
     * @return array Associative array of pubkey => balance
     */
    public function getAllContactBalances(string $userPubkey, array $contactPubkeys): array;
}
