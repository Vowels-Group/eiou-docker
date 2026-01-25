<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * CLI Service Interface
 *
 * Defines the contract for CLI management operations.
 * Handles settings management, help display, user information,
 * balance operations, transaction history, and SSL certificate management.
 *
 * @package Eiou\Contracts
 */
interface CliServiceInterface
{
    /**
     * Handler for CLI input changes to user settings
     *
     * Supports the following settings:
     * - defaultFee: Default fee percentage for transactions
     * - defaultCreditLimit: Default credit limit for new contacts
     * - defaultCurrency: Default currency code (e.g., USD)
     * - minFee: Minimum fee amount
     * - maxFee: Maximum fee percentage
     * - maxP2pLevel: Maximum peer-to-peer routing level
     * - p2pExpiration: P2P request expiration time in seconds
     * - maxOutput: Maximum lines of output to display
     * - defaultTransportMode: Default transport type (http, https, tor)
     * - autoRefreshEnabled: Enable auto-refresh for pending transactions
     * - hostname: Node hostname
     *
     * @param array $argv The CLI input data
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function changeSettings(array $argv, ?\CliOutputManager $output = null);

    /**
     * Display current settings of user in the CLI
     *
     * Shows all current user settings including currency, fees, limits,
     * P2P configuration, output settings, and transport mode.
     *
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function displayCurrentSettings(?\CliOutputManager $output = null);

    /**
     * Display available commands to user in the CLI
     *
     * Shows all available CLI commands with their usage and descriptions.
     * If a specific command is provided, shows detailed help for that command.
     *
     * @param array $argv The CLI input data
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function displayHelp(array $argv, ?\CliOutputManager $output = null);

    /**
     * Display user information to user in the CLI
     *
     * Shows user locators, authentication code, public key, and optionally
     * detailed balance information when 'detail' argument is provided.
     *
     * @param array $argv The CLI input data
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function displayUserInfo(array $argv, ?\CliOutputManager $output = null);

    /**
     * Display pending contact requests (both incoming and outgoing)
     *
     * Shows all pending contact requests separated into:
     * - Incoming: Requests from others awaiting user acceptance
     * - Outgoing: Requests user initiated awaiting recipient acceptance
     *
     * @param array $argv The CLI input data
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function displayPendingContacts(array $argv, ?\CliOutputManager $output = null): void;

    /**
     * Display overview dashboard with balances and recent transactions
     *
     * Shows a summary dashboard including:
     * - Current balances by currency
     * - Pending contact request counts
     * - Recent transactions (configurable limit, default 5)
     *
     * @param array $argv The CLI input data
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function displayOverview(array $argv, ?\CliOutputManager $output = null): void;

    /**
     * View balance information in the CLI based on transactions
     *
     * Displays balance information for received or sent transactions,
     * formatted in a table view with contact name, address, amount, and currency.
     *
     * @param string $direction Direction of transactions: 'received' or 'sent'
     * @param string $where Preposition for display: 'from' or 'to'
     * @param array $results Formatted transaction data
     * @param int $displayLimit The limit of output lines displayed
     * @return void
     */
    public function viewBalanceQuery(string $direction, string $where, array $results, int $displayLimit);

    /**
     * Display balance information based on transactions to user in the CLI
     *
     * Shows overall balance by currency and per-contact balances.
     * Optionally filters by contact address or name.
     *
     * @param array $argv The CLI input data
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function viewBalances(array $argv, ?\CliOutputManager $output = null);

    /**
     * Display all transaction history in pretty print table to user in the CLI
     *
     * Shows sent and received transaction history with timestamp, type,
     * contact name/address, amount, and currency. Optionally filters by contact.
     *
     * @param array $argv The CLI input data
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function viewTransactionHistory(array $argv, ?\CliOutputManager $output = null);

    /**
     * Helper to display transaction history (sent or received) in pretty print table
     *
     * Formats and displays transaction data with configurable output limit.
     *
     * @param array $transactions The formatted transaction data
     * @param string $direction Direction: 'received' or 'sent'
     * @param int|string $displayLimit The limit of output displayed (int or 'all')
     * @param \CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function displayHistory(array $transactions, string $direction, $displayLimit, ?\CliOutputManager $output = null);
}
