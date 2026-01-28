<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/../contracts/BalanceServiceInterface.php';
require_once __DIR__ . '/../database/BalanceRepository.php';
require_once __DIR__ . '/../database/TransactionContactRepository.php';
require_once __DIR__ . '/../database/AddressRepository.php';
require_once __DIR__ . '/utilities/CurrencyUtilityService.php';
require_once __DIR__ . '/../core/UserContext.php';

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
    public function contactBalanceConversion(array $contacts, int $transactionLimit = 5): array
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

        // Get all address types for building address arrays
        $addressTypes = $this->addressRepository->getAllAddressTypes();

        // Build result array with balances
        $contactsWithBalances = [];

        foreach ($contacts as $contact) {
            // Get pre-calculated balance from batch query result
            $balance = $balances[$contact['pubkey']] ?? 0;

            $feePercent = $contact['fee_percent'];
            $creditLimit = $contact['credit_limit'];

            // Build addresses associative array
            $addressesAssociative = [];
            $contactAddresses = [];
            foreach ($addressTypes as $addressType) {
                $addr = $contact[$addressType] ?? '';
                $addressesAssociative[$addressType] = $addr;
                if (!empty($addr)) {
                    $contactAddresses[] = $addr;
                }
            }

            // Get recent transactions with this contact
            $transactions = $this->transactionContactRepository->getTransactionsWithContact(
                $contactAddresses,
                $transactionLimit
            );

            $contactsWithBalances[] = array_merge($addressesAssociative, [
                'name' => $contact['name'],
                'balance' => $balance ? $this->currencyUtility->convertCentsToDollars($balance) : $balance,
                'fee' => $feePercent ? $this->currencyUtility->convertCentsToDollars($feePercent) : $feePercent,
                'credit_limit' => $creditLimit ? $this->currencyUtility->convertCentsToDollars($creditLimit) : $creditLimit,
                'currency' => $contact['currency'],
                'pubkey' => $contact['pubkey'] ?? '',
                'contact_id' => $contact['contact_id'] ?? '',
                'transactions' => $transactions,
                'online_status' => $contact['online_status'] ?? 'unknown',
                'valid_chain' => $contact['valid_chain'] ?? null
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
     * @return string Balance formatted as dollars (e.g., "10.50")
     */
    public function getUserTotalBalance(): string
    {
        $balances = $this->balanceRepository->getUserBalance();

        if ($balances === null || empty($balances)) {
            return "0.00";
        }

        // Sum all currency balances (typically just USD)
        // Balance is already in cents from BalanceRepository
        $totalCents = 0;
        foreach ($balances as $balance) {
            $totalCents += (int) ($balance['total_balance'] ?? 0);
        }

        return $this->currencyUtility->convertCentsToDollars($totalCents);
    }

    /**
     * Get contact balance (optimized single query)
     *
     * @param string $userPubkey User's public key
     * @param string $contactPubkey Contact's public key
     * @return int Balance in cents
     */
    public function getContactBalance(string $userPubkey, string $contactPubkey): int
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
