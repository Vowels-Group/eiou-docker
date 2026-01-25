<?php
/**
 * Interface for transaction services.
 *
 * Defines the contract for handling financial transactions
 * including sending, receiving, and verifying transactions.
 */
interface TransactionServiceInterface
{
    /**
     * Send EIOU transaction.
     *
     * @param array $request The transaction request data
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function sendEiou(array $request, ?CliOutputManager $output = null): void;

    /**
     * Process a transaction.
     *
     * @param array $request The transaction request data
     * @return void
     */
    public function processTransaction(array $request): void;

    /**
     * Check if a transaction is possible.
     *
     * @param array $request The transaction request to validate
     * @param bool $echo Whether to output validation messages
     * @return bool True if the transaction is possible
     */
    public function checkTransactionPossible(array $request, bool $echo = true): bool;

    /**
     * Get transaction by txid.
     *
     * @param string $txid The transaction ID
     * @return array|null The transaction data or null if not found
     */
    public function getByTxid(string $txid): ?array;
}
