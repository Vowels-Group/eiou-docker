<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Formatters;

use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;

/**
 * Transaction Formatter
 *
 * Extracts data formatting logic from TransactionRepository.
 * Provides consistent transformation of raw database records into
 * API-ready response formats.
 *
 * All amounts are SplitAmount objects (whole + frac) from the database.
 * Database rows contain amount_whole/amount_frac column pairs.
 *
 * @package Formatters
 */
class TransactionFormatter
{
    /**
     * Extract a SplitAmount from a database row.
     *
     * Reads {$prefix}_whole and {$prefix}_frac columns from the row.
     * Returns null if both columns are null.
     *
     * @param array $row Database row
     * @param string $prefix Column name prefix (e.g., 'amount', 'p2p_amount')
     * @return SplitAmount|null
     */
    public static function extractAmount(array $row, string $prefix = 'amount'): ?SplitAmount
    {
        $wholeKey = $prefix . '_whole';
        $fracKey = $prefix . '_frac';

        if (!isset($row[$wholeKey]) && !isset($row[$fracKey])) {
            return null;
        }

        return new SplitAmount(
            (int) ($row[$wholeKey] ?? 0),
            (int) ($row[$fracKey] ?? 0)
        );
    }

    /**
     * Convert a SplitAmount to display value (major units float)
     *
     * @param SplitAmount|null $amount Amount as SplitAmount
     * @return float|null Amount in major units (e.g. dollars)
     */
    public static function convertAmount(?SplitAmount $amount): ?float
    {
        if ($amount === null) {
            return null;
        }
        return $amount->toMajorUnits();
    }

    /**
     * Format a simple transaction for sent/received lists
     *
     * @param array $tx Raw transaction data (with amount_whole/amount_frac columns)
     * @param string $type Transaction type (Constants::TX_TYPE_SENT or TX_TYPE_RECEIVED)
     * @param string $counterparty Counterparty address
     * @return array Formatted transaction
     */
    public static function formatSimple(array $tx, string $type, string $counterparty): array
    {
        return [
            'date' => $tx['timestamp'],
            'type' => $type,
            'amount' => self::convertAmount(self::extractAmount($tx, 'amount')),
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
            'amount' => self::convertAmount(self::extractAmount($tx, 'amount')),
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
            'p2p_amount' => self::convertAmount(self::extractAmount($tx, 'p2p_amount')),
            'p2p_fee' => self::convertAmount(self::extractAmount($tx, 'p2p_fee'))
        ];
    }

    /**
     * Format multiple transactions for history view
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
            'amount' => self::convertAmount(self::extractAmount($tx, 'amount')),
            'currency' => $tx['currency'],
            'sender_address' => $tx['sender_address'] ?? '',
            'receiver_address' => $tx['receiver_address'] ?? '',
            'memo' => $tx['memo'] ?? '',
            'description' => $tx['description'] ?? ''
        ];
    }

    /**
     * Format multiple transactions for contact view
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
