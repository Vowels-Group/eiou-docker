<?php
/**
 * View Helper
 *
 * Copyright 2025
 * Provides utility functions for view rendering and display formatting.
 *
 * SECURITY NOTE: This class now uses OutputEncoder for XSS protection.
 * Always use appropriate encoding methods based on output context.
 */

require_once '/etc/eiou/src/security/OutputEncoder.php';

class ViewHelper
{
    /**
     * Sanitize output for HTML display
     *
     * @deprecated Use OutputEncoder::html() instead for better XSS protection
     * @param string $text
     * @return string
     */
    public static function sanitize(string $text): string
    {
        return OutputEncoder::html($text);
    }

    /**
     * Encode for HTML context (alias for backward compatibility)
     *
     * @param mixed $value
     * @return string
     */
    public static function html($value): string
    {
        return OutputEncoder::html($value);
    }

    /**
     * Encode for HTML attribute context
     *
     * @param mixed $value
     * @return string
     */
    public static function attr($value): string
    {
        return OutputEncoder::attribute($value);
    }

    /**
     * Encode for JavaScript context
     *
     * @param mixed $value
     * @return string
     */
    public static function js($value): string
    {
        return OutputEncoder::javascript($value);
    }

    /**
     * Encode for URL context
     *
     * @param mixed $value
     * @return string
     */
    public static function url($value): string
    {
        return OutputEncoder::url($value);
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
}
