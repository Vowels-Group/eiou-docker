<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Formatters;

use Eiou\Core\Constants;

/**
 * Transaction Formatter
 *
 * Extracts data formatting logic from TransactionRepository.
 * Provides consistent transformation of raw database records into
 * API-ready response formats.
 *
 * Format Types:
 * - Simple: Basic sent/received list format (date, type, amount, currency, counterparty)
 * - History: Full transaction history with joins and P2P info
 * - Contact: Transaction format for contact-related queries
 * - InProgress: In-progress transaction format with routing info
 *
 * @package Formatters
 */
class TransactionFormatter
{
    /**
     * Convert amount from minor units to display value
     *
     * @param int|null $minorAmount Amount in minor units (e.g. cents)
     * @param string $currency Currency code (default: USD)
     * @return float|null Amount in major units (e.g. dollars)
     */
    public static function convertAmount(?int $minorAmount, string $currency = 'USD'): ?float
    {
        if ($minorAmount === null) {
            return null;
        }
        return $minorAmount / Constants::getConversionFactor($currency);
    }

    /**
     * Format a simple transaction for sent/received lists
     *
     * @param array $tx Raw transaction data
     * @param string $type Transaction type (Constants::TX_TYPE_SENT or TX_TYPE_RECEIVED)
     * @param string $counterparty Counterparty address
     * @return array Formatted transaction
     */
    public static function formatSimple(array $tx, string $type, string $counterparty): array
    {
        return [
            'date' => $tx['timestamp'],
            'type' => $type,
            'amount' => self::convertAmount((int)$tx['amount'], $tx['currency']),
            'currency' => $tx['currency'],
            'counterparty' => $counterparty
        ];
    }

    /**
     * Format multiple simple transactions
     *
     * @param array $transactions Raw transactions
     * @param string $type Transaction type
     * @param string $counterpartyField Field name for counterparty address
     * @return array Formatted transactions
     */
    public static function formatSimpleMany(array $transactions, string $type, string $counterpartyField): array
    {
        $formatted = [];
        foreach ($transactions as $tx) {
            $formatted[] = self::formatSimple($tx, $type, $tx[$counterpartyField]);
        }
        return $formatted;
    }

    /**
     * Format a transaction for history view with full details
     *
     * @param array $tx Raw transaction data (with joins)
     * @param array $userAddresses User's addresses for determining direction
     * @return array Formatted transaction
     */
    public static function formatHistory(array $tx, array $userAddresses): array
    {
        $isSent = in_array($tx['sender_address'], $userAddresses);
        $counterpartyAddress = $isSent ? $tx['receiver_address'] : $tx['sender_address'];
        $counterpartyName = $isSent ? ($tx['receiver_name'] ?? null) : ($tx['sender_name'] ?? null);

        // Build display string: "Name (address)" or just "address" if no name
        $counterpartyDisplay = $counterpartyName
            ? $counterpartyName . ' (' . $counterpartyAddress . ')'
            : $counterpartyAddress;

        return [
            'id' => $tx['id'],
            'txid' => $tx['txid'],
            'tx_type' => $tx['tx_type'],
            'direction' => $tx['direction'] ?? null,
            'status' => $tx['status'],
            'date' => $tx['timestamp'],
            'type' => $isSent ? Constants::TX_TYPE_SENT : Constants::TX_TYPE_RECEIVED,
            'amount' => self::convertAmount((int)$tx['amount'], $tx['currency']),
            'currency' => $tx['currency'],
            'counterparty' => $counterpartyDisplay,
            'counterparty_address' => $counterpartyAddress,
            'counterparty_name' => $counterpartyName,
            'sender_address' => $tx['sender_address'],
            'receiver_address' => $tx['receiver_address'],
            'sender_public_key' => $tx['sender_public_key'] ?? null,
            'receiver_public_key' => $tx['receiver_public_key'] ?? null,
            'memo' => $tx['memo'] ?? null,
            'description' => $tx['description'] ?? null,
            'previous_txid' => $tx['previous_txid'] ?? null,
            'end_recipient_address' => $tx['end_recipient_address'] ?? null,
            'initial_sender_address' => $tx['initial_sender_address'] ?? null,
            'p2p_destination' => $tx['p2p_destination'] ?? null,
            'p2p_amount' => isset($tx['p2p_amount']) ? self::convertAmount((int)$tx['p2p_amount'], $tx['currency']) : null,
            'p2p_fee' => isset($tx['p2p_fee']) ? self::convertAmount((int)$tx['p2p_fee'], $tx['currency']) : null
        ];
    }

    /**
     * Format multiple transactions for history view
     *
     * @param array $transactions Raw transactions
     * @param array $userAddresses User's addresses
     * @return array Formatted transactions
     */
    public static function formatHistoryMany(array $transactions, array $userAddresses): array
    {
        $formatted = [];
        foreach ($transactions as $tx) {
            $formatted[] = self::formatHistory($tx, $userAddresses);
        }
        return $formatted;
    }

    /**
     * Format a transaction for contact view
     *
     * @param array $tx Raw transaction data
     * @param array $userAddresses User's addresses for determining direction
     * @return array Formatted transaction
     */
    public static function formatContact(array $tx, array $userAddresses): array
    {
        $isSent = in_array($tx['sender_address'], $userAddresses);

        return [
            'txid' => $tx['txid'] ?? '',
            'tx_type' => $tx['tx_type'] ?? 'standard',
            'status' => $tx['status'] ?? Constants::STATUS_COMPLETED,
            'date' => $tx['timestamp'],
            'type' => $isSent ? Constants::TX_TYPE_SENT : Constants::TX_TYPE_RECEIVED,
            'amount' => self::convertAmount((int)$tx['amount'], $tx['currency']),
            'currency' => $tx['currency'],
            'sender_address' => $tx['sender_address'] ?? '',
            'receiver_address' => $tx['receiver_address'] ?? '',
            'memo' => $tx['memo'] ?? '',
            'description' => $tx['description'] ?? ''
        ];
    }

    /**
     * Format multiple transactions for contact view
     *
     * @param array $transactions Raw transactions
     * @param array $userAddresses User's addresses
     * @return array Formatted transactions
     */
    public static function formatContactMany(array $transactions, array $userAddresses): array
    {
        $formatted = [];
        foreach ($transactions as $tx) {
            $formatted[] = self::formatContact($tx, $userAddresses);
        }
        return $formatted;
    }
}
