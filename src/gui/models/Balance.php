<?php
/**
 * Balance Model
 *
 * Represents wallet balance data and provides balance-related operations
 *
 * Copyright 2025
 */

namespace Eiou\Gui\Models;

require_once __DIR__ . '/../../services/ServiceContainer.php';

use ServiceContainer;

/**
 * Balance Model
 *
 * Handles wallet balance operations and data
 */
class Balance
{
    /**
     * @var ServiceContainer Service container for accessing services
     */
    private ServiceContainer $serviceContainer;

    /**
     * @var array Balance data cache
     */
    private array $balances = [];

    /**
     * Constructor
     *
     * @param ServiceContainer $serviceContainer Service container
     */
    public function __construct(ServiceContainer $serviceContainer)
    {
        $this->serviceContainer = $serviceContainer;
    }

    /**
     * Get current user's balance
     *
     * @param string $currency Currency code (e.g., 'USD', 'EUR')
     * @return float Balance amount
     */
    public function getBalance(string $currency = 'USD'): float
    {
        // Check cache first
        $cacheKey = "balance_{$currency}";
        if (isset($this->balances[$cacheKey])) {
            return $this->balances[$cacheKey];
        }

        $walletService = $this->serviceContainer->getWalletService();
        $balance = $walletService->getBalance($currency);

        // Cache the balance
        $this->balances[$cacheKey] = $balance;

        return $balance;
    }

    /**
     * Get balances for all currencies
     *
     * @return array Associative array of currency => balance
     */
    public function getAllBalances(): array
    {
        $walletService = $this->serviceContainer->getWalletService();
        return $walletService->getAllBalances();
    }

    /**
     * Get formatted balance string
     *
     * @param string $currency Currency code
     * @param int $decimals Number of decimal places
     * @return string Formatted balance (e.g., "1,234.56")
     */
    public function getFormattedBalance(string $currency = 'USD', int $decimals = 2): string
    {
        $balance = $this->getBalance($currency);
        return number_format($balance, $decimals);
    }

    /**
     * Check if balance is sufficient for transaction
     *
     * @param float $amount Amount to check
     * @param string $currency Currency code
     * @return bool True if sufficient balance
     */
    public function hasSufficientBalance(float $amount, string $currency = 'USD'): bool
    {
        $balance = $this->getBalance($currency);
        return $balance >= $amount;
    }

    /**
     * Get available credit for a contact
     *
     * @param string $contactAddress Contact's address
     * @return float Available credit amount
     */
    public function getAvailableCredit(string $contactAddress): float
    {
        $contactService = $this->serviceContainer->getContactService();
        $contact = $contactService->getContact($contactAddress);

        if (!$contact) {
            return 0.0;
        }

        // Calculate available credit (credit limit - current balance)
        $creditLimit = $contact['credit'] ?? 0.0;
        $currentBalance = $contact['balance'] ?? 0.0;

        return max(0, $creditLimit - $currentBalance);
    }

    /**
     * Clear balance cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->balances = [];
    }

    /**
     * Refresh balance for specific currency
     *
     * @param string $currency Currency code
     * @return float Updated balance
     */
    public function refreshBalance(string $currency = 'USD'): float
    {
        // Clear cached value
        $cacheKey = "balance_{$currency}";
        unset($this->balances[$cacheKey]);

        // Fetch fresh balance
        return $this->getBalance($currency);
    }

    /**
     * Get balance history
     *
     * @param string $currency Currency code
     * @param int $limit Number of records to retrieve
     * @return array Balance history records
     */
    public function getBalanceHistory(string $currency = 'USD', int $limit = 10): array
    {
        $transactionService = $this->serviceContainer->getTransactionService();
        return $transactionService->getBalanceHistory($currency, $limit);
    }

    /**
     * Calculate total balance across all currencies in base currency
     *
     * @param string $baseCurrency Base currency for conversion (default: USD)
     * @return float Total balance in base currency
     */
    public function getTotalBalance(string $baseCurrency = 'USD'): float
    {
        $allBalances = $this->getAllBalances();
        $currencyUtility = $this->serviceContainer->getUtilityServiceContainer()
            ->getCurrencyUtility();

        $total = 0.0;
        foreach ($allBalances as $currency => $balance) {
            if ($currency === $baseCurrency) {
                $total += $balance;
            } else {
                // Convert to base currency
                $converted = $currencyUtility->convert($balance, $currency, $baseCurrency);
                $total += $converted;
            }
        }

        return $total;
    }
}
