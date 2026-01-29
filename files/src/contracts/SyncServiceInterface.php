<?php
namespace Eiou\Contracts;

use Eiou\Services\CliOutputManager;

/**
 * Interface for synchronization services.
 *
 * Defines the contract for synchronizing contacts, transactions,
 * and balances across the network.
 */
interface SyncServiceInterface
{
    /**
     * Perform sync operation based on command arguments.
     *
     * @param mixed $argv The command line arguments
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function sync($argv, ?CliOutputManager $output = null): void;

    /**
     * Synchronize all data (contacts, transactions, balances).
     *
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function syncAll(?CliOutputManager $output = null): void;

    /**
     * Synchronize all contacts.
     *
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function syncAllContacts(?CliOutputManager $output = null): void;

    /**
     * Synchronize a single contact.
     *
     * @param mixed $contactAddress The contact address to sync
     * @param string $echo Echo mode ('SILENT' for no output)
     * @return bool True if sync was successful
     */
    public function syncSingleContact($contactAddress, $echo = 'SILENT'): bool;

    /**
     * Synchronize all transactions.
     *
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function syncAllTransactions(?CliOutputManager $output = null): void;

    /**
     * Synchronize a transaction chain for a contact.
     *
     * @param string $contactAddress The contact's address
     * @param string $contactPublicKey The contact's public key
     * @param string|null $expectedTxid Optional expected transaction ID
     * @return array The sync result including any new transactions
     */
    public function syncTransactionChain(string $contactAddress, string $contactPublicKey, ?string $expectedTxid = null): array;

    /**
     * Synchronize the balance for a specific contact.
     *
     * @param string $contactPubkey The contact's public key
     * @return array The updated balance information
     */
    public function syncContactBalance(string $contactPubkey): array;

    /**
     * Synchronize all balances.
     *
     * @param CliOutputManager|null $output Optional CLI output manager for feedback
     * @return void
     */
    public function syncAllBalances(?CliOutputManager $output = null): void;

    /**
     * Perform bidirectional sync with a contact.
     *
     * @param string $contactAddress The contact's address
     * @param string $contactPublicKey The contact's public key
     * @return array The sync result from both directions
     */
    public function bidirectionalSync(string $contactAddress, string $contactPublicKey): array;
}
