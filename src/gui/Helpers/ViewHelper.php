<?php
/**
 * View Helper
 *
 * Provides utility functions for view rendering and display formatting.
 * Extracted from functions.php for clean separation of concerns.
 *
 * @package eIOUGUI\Helpers
 * @author Hive Mind Collective
 * @copyright 2025
 */

namespace eIOUGUI\Helpers;

class ViewHelper
{
    /**
     * Truncate address for display
     *
     * @param string $address
     * @param int $length
     * @return string
     */
    public static function truncateAddress(string $address, int $length = 10): string
    {
        if (strlen($address) <= $length) {
            return $address;
        }
        return substr($address, 0, $length) . '...';
    }

    /**
     * Convert currency output based on currency type
     *
     * @param float|int $value
     * @param string $currency
     * @return float|int
     */
    public static function currencyOutputConversion($value, string $currency)
    {
        if ($currency === 'USD') {
            return $value ? ($value / 100) : $value;
        }
        return $value;
    }

    /**
     * Convert contacts array with balances for output
     *
     * @param array $contacts
     * @param string $userPubkey
     * @param \eIOUGUI\Repositories\TransactionRepository $transactionRepository
     * @return array
     */
    public static function contactConversion(array $contacts, string $userPubkey, $transactionRepository): array
    {
        // If no contacts, return empty array
        if (empty($contacts)) {
            return [];
        }

        // Extract all pubkeys for batch processing
        $pubkeys = array_column($contacts, 'pubkey');

        // Get all balances in a single optimized query
        $balances = $transactionRepository->getAllContactBalances($userPubkey, $pubkeys);

        // Build result array with balances
        $contactsWithBalances = [];
        foreach ($contacts as $contact) {
            // Get pre-calculated balance from batch query result
            $balance = $balances[$contact['pubkey']] ?? 0;

            $contactsWithBalances[] = [
                'name' => $contact['name'],
                'address' => $contact['address'],
                'balance' => self::currencyOutputConversion($balance, $contact['currency']),
                'fee' => self::currencyOutputConversion($contact['fee_percent'], $contact['currency']),
                'credit_limit' => self::currencyOutputConversion($contact['credit_limit'], $contact['currency']),
                'currency' => $contact['currency']
            ];
        }
        return $contactsWithBalances;
    }

    /**
     * Format balance for display
     *
     * @param float $balance
     * @param int $decimals
     * @return string
     */
    public static function formatBalance(float $balance, int $decimals = 2): string
    {
        return number_format($balance, $decimals);
    }

    /**
     * Format currency amount with symbol
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    public static function formatCurrency(float $amount, string $currency = 'USD'): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'BTC' => '₿'
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }

    /**
     * Sanitize output for HTML display
     *
     * @param string $text
     * @return string
     */
    public static function sanitize(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format timestamp for display
     *
     * @param string $timestamp
     * @param string $format
     * @return string
     */
    public static function formatTimestamp(string $timestamp, string $format = 'Y-m-d H:i:s'): string
    {
        $time = strtotime($timestamp);
        if ($time === false) {
            return $timestamp;
        }
        return date($format, $time);
    }

    /**
     * Get relative time string (e.g., "2 hours ago")
     *
     * @param string $timestamp
     * @return string
     */
    public static function getRelativeTime(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if ($time === false) {
            return $timestamp;
        }

        $diff = time() - $time;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }

    /**
     * Generate CSS class for transaction type
     *
     * @param string $type
     * @return string
     */
    public static function getTransactionClass(string $type): string
    {
        $classes = [
            'send' => 'transaction-send',
            'sent' => 'transaction-send',
            'receive' => 'transaction-receive',
            'received' => 'transaction-receive',
            'p2p' => 'transaction-p2p'
        ];

        return $classes[strtolower($type)] ?? 'transaction-default';
    }

    /**
     * Get badge color for contact status
     *
     * @param string $status
     * @return string
     */
    public static function getStatusBadgeClass(string $status): string
    {
        $classes = [
            'accepted' => 'badge-success',
            'pending' => 'badge-warning',
            'blocked' => 'badge-danger',
            'rejected' => 'badge-danger'
        ];

        return $classes[strtolower($status)] ?? 'badge-default';
    }

    /**
     * Generate pagination HTML
     *
     * @param int $currentPage
     * @param int $totalPages
     * @param string $baseUrl
     * @return string
     */
    public static function generatePagination(int $currentPage, int $totalPages, string $baseUrl): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<div class="pagination">';

        // Previous button
        if ($currentPage > 1) {
            $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage - 1) . '">&laquo; Previous</a>';
        }

        // Page numbers
        for ($i = 1; $i <= $totalPages; $i++) {
            $active = ($i === $currentPage) ? ' class="active"' : '';
            $html .= '<a href="' . $baseUrl . '&page=' . $i . '"' . $active . '>' . $i . '</a>';
        }

        // Next button
        if ($currentPage < $totalPages) {
            $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage + 1) . '">Next &raquo;</a>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render select options from array
     *
     * @param array $options
     * @param string|null $selected
     * @return string
     */
    public static function renderSelectOptions(array $options, ?string $selected = null): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $selectedAttr = ($value === $selected) ? ' selected' : '';
            $html .= '<option value="' . self::sanitize($value) . '"' . $selectedAttr . '>';
            $html .= self::sanitize($label);
            $html .= '</option>';
        }
        return $html;
    }

    /**
     * Generate breadcrumb navigation
     *
     * @param array $breadcrumbs
     * @return string
     */
    public static function generateBreadcrumbs(array $breadcrumbs): string
    {
        if (empty($breadcrumbs)) {
            return '';
        }

        $html = '<nav class="breadcrumb"><ol>';
        $count = count($breadcrumbs);
        $i = 1;

        foreach ($breadcrumbs as $label => $url) {
            $html .= '<li>';
            if ($i === $count) {
                // Last item (current page)
                $html .= self::sanitize($label);
            } else {
                $html .= '<a href="' . self::sanitize($url) . '">' . self::sanitize($label) . '</a>';
            }
            $html .= '</li>';
            $i++;
        }

        $html .= '</ol></nav>';
        return $html;
    }

    /**
     * Get user total balance formatted
     *
     * @param string $userPubkey
     * @param \eIOUGUI\Repositories\TransactionRepository $transactionRepository
     * @return string
     */
    public static function getUserTotalBalance(string $userPubkey, $transactionRepository): string
    {
        try {
            $totalReceived = $transactionRepository->calculateTotalReceived($userPubkey);
            $totalSent = $transactionRepository->calculateTotalSent($userPubkey);
            $balance = ($totalReceived - $totalSent) / 100; // Convert from cents
            return number_format($balance, 2);
        } catch (\Exception $e) {
            return "0.00";
        }
    }
}
