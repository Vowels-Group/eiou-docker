<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\BalanceServiceInterface;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Database\BalanceRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\UserContext;

/**
 * Balance Service
 *
 * Handles all business logic for balance-related operations including
 * user balance retrieval, contact balance calculations, and contact
 * information conversion with balance data.
 *
 * Extracted from TransactionService as part of the God Class refactoring
 * to improve maintainability and adhere to Single Responsibility Principle.
 *
 * @package Services
 */
class BalanceService implements BalanceServiceInterface
{
    /** @var BalanceRepository */
    private BalanceRepository $balanceRepository;

    /** @var TransactionContactRepository */
    private TransactionContactRepository $transactionContactRepository;

    /** @var AddressRepository */
    private AddressRepository $addressRepository;

    /** @var CurrencyUtilityService */
    private CurrencyUtilityService $currencyUtility;

    /** @var UserContext */
    private UserContext $currentUser;

    /**
     * Constructor
     *
     * @param BalanceRepository $balanceRepository Balance repository
     * @param TransactionContactRepository $transactionContactRepository Transaction contact repository
     * @param AddressRepository $addressRepository Address repository
     * @param CurrencyUtilityService $currencyUtility Currency utility service
     * @param UserContext $currentUser Current user context
     */
    public function __construct(
        BalanceRepository $balanceRepository,
        TransactionContactRepository $transactionContactRepository,
        AddressRepository $addressRepository,
        CurrencyUtilityService $currencyUtility,
        UserContext $currentUser
    ) {
        $this->balanceRepository = $balanceRepository;
        $this->transactionContactRepository = $transactionContactRepository;
        $this->addressRepository = $addressRepository;
        $this->currencyUtility = $currencyUtility;
        $this->currentUser = $currentUser;
    }

    /**
     * Convert contact information with balance data for display
     *
     * Enriches contact array with balance information, transaction history,
     * and properly formatted currency values.
     *
     * @param array $contacts Contact information array
     * @param int $transactionLimit Maximum number of transactions to fetch per contact
     * @return array Converted contact information with balances
     */
    public function contactBalanceConversion(array $contacts, int $transactionLimit = Constants::BALANCE_TRANSACTION_LIMIT): array
    {
        if (empty($contacts)) {
            return [];
        }

        // Extract all pubkeys for batch processing
        $pubkeys = array_column($contacts, 'pubkey');

        // Get all balances in a single optimized query
        $balances = $this->transactionContactRepository->getAllContactBalances(
            $this->currentUser->getPublicKey(),
            $pubkeys
        );

        // Batch-fetch the last $transactionLimit transactions for every contact
        // in one ranked query (ROW_NUMBER per counterparty pubkey_hash). This
        // replaces the prior N+1 of one getTransactionsWithContact call per
        // contact inside the loop below.
        $contactHashes = array_values(array_filter(array_column($contacts, 'pubkey_hash')));
        $txByContactHash = !empty($contactHashes)
            ? $this->transactionContactRepository->getTransactionsWithContactsBatch($contactHashes, $transactionLimit)
            : [];

        // Get all address types for building address arrays
        $addressTypes = $this->addressRepository->getAllAddressTypes();

        // Build result array with balances
        $contactsWithBalances = [];

        foreach ($contacts as $contact) {
            // Get pre-calculated per-currency balances from batch query result
            $contactBalances = $balances[$contact['pubkey']] ?? [];

            // Primary balance uses the first available currency from balances, or default
            $primaryCurrency = !empty($contactBalances) ? array_key_first($contactBalances) : Constants::TRANSACTION_DEFAULT_CURRENCY;
            $balance = $contactBalances[$primaryCurrency] ?? 0;

            // Build balances_by_currency (converted to major units)
            $balancesByCurrency = [];
            foreach ($contactBalances as $cur => $bal) {
                $balancesByCurrency[$cur] = ($bal instanceof SplitAmount) ? $this->currencyUtility->convertMinorToMajor($bal) : 0;
            }

            // Build addresses associative array
            $addressesAssociative = [];
            foreach ($addressTypes as $addressType) {
                $addressesAssociative[$addressType] = $contact[$addressType] ?? '';
            }

            $transactions = $txByContactHash[$contact['pubkey_hash'] ?? ''] ?? [];

            $contactsWithBalances[] = array_merge($addressesAssociative, [
                'name' => $contact['name'],
                'balance' => ($balance instanceof SplitAmount) ? $this->currencyUtility->convertMinorToMajor($balance) : $balance,
                'balances_by_currency' => $balancesByCurrency,
                'pubkey' => $contact['pubkey'] ?? '',
                'contact_id' => $contact['contact_id'] ?? '',
                'transactions' => $transactions,
                'online_status' => $contact['online_status'] ?? 'unknown',
                'valid_chain' => $contact['valid_chain'] ?? null,
                'pubkey_hash' => $contact['pubkey_hash'] ?? '',
                'status' => $contact['status'] ?? ''
            ]);
        }

        return $contactsWithBalances;
    }

    /**
     * Get user's total balance
     *
     * Uses BalanceRepository which correctly tracks only completed transactions.
     * This ensures consistency with transaction validation and prevents counting
     * pending/in-progress transactions as available funds.
     *
     * @return string Balance formatted as major units (e.g., "10.50")
     */
    public function getUserTotalBalance(): string
    {
        $balances = $this->balanceRepository->getUserBalance();

        if ($balances === null || empty($balances)) {
            return "0.00";
        }

        // Sum all currency balances using SplitAmount arithmetic
        $total = SplitAmount::zero();
        foreach ($balances as $balance) {
            if (isset($balance['total_balance']) && $balance['total_balance'] instanceof SplitAmount) {
                $total = $total->add($balance['total_balance']);
            }
        }

        return $this->currencyUtility->convertMinorToMajor($total);
    }

    /**
     * Get contact balance (optimized single query)
     *
     * @param string $userPubkey User's public key
     * @param string $contactPubkey Contact's public key
     * @return int Balance in minor units
     */
    public function getContactBalance(string $userPubkey, string $contactPubkey): SplitAmount
    {
        return $this->transactionContactRepository->getContactBalance($userPubkey, $contactPubkey);
    }

    /**
     * Get all contact balances
     *
     * @param string $userPubkey User's public key
     * @param array $contactPubkeys Array of contact public keys
     * @return array Associative array of pubkey => balance
     */
    public function getAllContactBalances(string $userPubkey, array $contactPubkeys): array
    {
        return $this->transactionContactRepository->getAllContactBalances($userPubkey, $contactPubkeys);
    }
}
