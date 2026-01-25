<?php

declare(strict_types=1);

namespace Eiou\Contracts;

use Eiou\Cli\CliOutputManager;

/**
 * Interface for transaction services.
 *
 * Defines the contract for handling financial transactions
 * including sending, receiving, and verifying transactions.
 */
interface TransactionServiceInterface
{
    /**
     * Send a transaction.
     *
     * @param array $data The transaction data
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function sendTransaction(array $data, ?CliOutputManager $output = null): void;

    /**
     * Handle an incoming transaction request.
     *
     * @param array $request The transaction request data
     * @return string The response message or transaction ID
     */
    public function handleTransaction(array $request): string;

    /**
     * Check if a transaction is possible.
     *
     * @param array $request The transaction request to validate
     * @param bool $echo Whether to output validation messages
     * @return bool True if the transaction is possible
     */
    public function checkTransactionPossible(array $request, bool $echo = true): bool;

    /**
     * Verify a transaction signature using the public key.
     *
     * @param array $tx The transaction data including signature
     * @return bool True if the signature is valid
     */
    public function verifyTransactionSignaturePublic(array $tx): bool;
}
