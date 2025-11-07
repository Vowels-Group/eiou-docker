<?php
/**
 * View Helper
 *
 * Copyright 2025
 * Provides utility functions for view rendering and display formatting.
 */

class ViewHelper
{
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
     * Include toast notification system CSS
     *
     * @return string
     */
    public static function includeToastCSS(): string
    {
        ob_start();
        require_once("/etc/eiou/src/gui/assets/css/toast.css");
        return ob_get_clean();
    }

    /**
     * Include toast notification system JavaScript
     *
     * @return string
     */
    public static function includeToastJS(): string
    {
        ob_start();
        require_once("/etc/eiou/src/gui/assets/js/toast.js");
        return ob_get_clean();
    }
}
