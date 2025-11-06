<?php

/**
 * Message Helper
 *
 * Copyright 2025
 * Provides utility functions for message parsing, formatting, and display.
 */

class MessageHelper
{
    /**
     * Parse contact function output and determine message type
     *
     * @param string $output
     * @return array ['message' => string, 'type' => string]
     */
    public static function parseContactOutput(string $output): array
    {
        $output = trim($output);

        // Success messages
        if (str_contains(strtolower($output), 'contact accepted.') !== false) {
            return ['message' => $output, 'type' => 'contact-accepted'];
        }

        // General success message
        if (str_contains(strtolower($output), 'success') !== false) {
            return ['message' => $output, 'type' => 'success'];
        }

        // Warning messages
        if (str_contains(strtolower($output), 'already been added or accepted') !== false) {
            return ['message' => $output, 'type' => 'warning'];
        }
        if (str_contains(strtolower($output), 'warning:') !== false) {
            return ['message' => $output, 'type' => 'warning'];
        }

        // Error messages
        if (str_contains(strtolower($output), 'failed') !== false) {
            return ['message' => $output . ' Please try again.', 'type' => 'error'];
        }
        if (str_contains(strtolower($output), 'not accepted by the recipient') !== false) {
            return ['message' => $output . ' Please try again or contact the recipient directly.', 'type' => 'error'];
        }
        if (str_contains(strtolower($output), 'not found') !== false) {
            return ['message' => $output, 'type' => 'error'];
        }
        if (str_contains(strtolower($output), 'no results found.') !== false) {
            return ['message' => $output, 'type' => 'error'];
        }

        // Generic error detection
        if (str_contains(strtolower($output), 'error') !== false || str_contains(strtolower($output), 'failed') !== false) {
            return ['message' => $output, 'type' => 'error'];
        }

        // Default success case
        return ['message' => $output, 'type' => 'success'];
    }

    /**
     * Format message for display with HTML
     *
     * @param string $message
     * @param string $type
     * @return string
     */
    public static function formatMessage(string $message, string $type = 'info'): string
    {
        $typeClass = self::getMessageClass($type);
        $icon = self::getMessageIcon($type);

        return sprintf(
            '<div class="message %s">%s %s</div>',
            htmlspecialchars($typeClass),
            $icon,
            htmlspecialchars($message)
        );
    }

    /**
     * Get CSS class for message type
     *
     * @param string $type
     * @return string
     */
    public static function getMessageClass(string $type): string
    {
        $classes = [
            'success' => 'message-success',
            'error' => 'message-error',
            'warning' => 'message-warning',
            'info' => 'message-info',
            'contact-accepted' => 'message-success'
        ];

        return $classes[strtolower($type)] ?? 'message-info';
    }

    /**
     * Get icon for message type (simple text-based icons for Tor compatibility)
     *
     * @param string $type
     * @return string
     */
    public static function getMessageIcon(string $type): string
    {
        $icons = [
            'success' => '✓',
            'error' => '✗',
            'warning' => '⚠',
            'info' => 'ℹ',
            'contact-accepted' => '✓'
        ];

        return $icons[strtolower($type)] ?? 'ℹ';
    }

    /**
     * Redirect with message
     *
     * @param string $message
     * @param string $messageType
     * @param string|null $url
     * @return void
     */
    public static function redirectMessage(string $message, string $messageType, ?string $url = null): void
    {
        // If in AJAX mode, just echo the message instead of redirecting
        if (isset($_SERVER['AJAX_MODE']) && $_SERVER['AJAX_MODE']) {
            echo $message;
            return;
        }

        if ($url === null) {
            $url = $_SERVER['PHP_SELF'];
        }

        // Build redirect URL with message parameters
        $redirectUrl = $url . '?message=' . urlencode($message) . '&type=' . urlencode($messageType);
        header('Location: ' . $redirectUrl);
        exit;
    }

    /**
     * Get message from URL parameters
     *
     * @return array|null ['message' => string, 'type' => string]
     */
    public static function getMessageFromUrl(): ?array
    {
        if (isset($_GET['message']) && isset($_GET['type'])) {
            return [
                'message' => $_GET['message'],
                'type' => $_GET['type']
            ];
        }
        return null;
    }

    /**
     * Display flash message if available
     *
     * @param Session $session
     * @return string
     */
    public static function displayFlashMessage($session): string
    {
        $message = $session->getMessage();
        if ($message) {
            return self::formatMessage($message['text'], $message['type']);
        }

        // Check URL parameters as fallback
        $urlMessage = self::getMessageFromUrl();
        if ($urlMessage) {
            return self::formatMessage($urlMessage['message'], $urlMessage['type']);
        }

        return '';
    }

    /**
     * Validate and sanitize message input
     *
     * @param string $message
     * @param int $maxLength
     * @return string
     */
    public static function sanitizeMessage(string $message, int $maxLength = 500): string
    {
        // Remove any HTML tags
        $message = strip_tags($message);

        // Trim whitespace
        $message = trim($message);

        // Limit length
        if (strlen($message) > $maxLength) {
            $message = substr($message, 0, $maxLength) . '...';
        }

        return $message;
    }

    /**
     * Create success message
     *
     * @param string $action
     * @param string $subject
     * @return string
     */
    public static function successMessage(string $action, string $subject): string
    {
        return sprintf("%s %s successfully", $subject, $action);
    }

    /**
     * Create error message
     *
     * @param string $action
     * @param string $subject
     * @param string|null $reason
     * @return string
     */
    public static function errorMessage(string $action, string $subject, ?string $reason = null): string
    {
        $message = sprintf("Failed to %s %s", $action, $subject);
        if ($reason) {
            $message .= ": " . $reason;
        }
        return $message;
    }

    /**
     * Create warning message
     *
     * @param string $warning
     * @return string
     */
    public static function warningMessage(string $warning): string
    {
        return "Warning: " . $warning;
    }

    /**
     * Render message list
     *
     * @param array $messages Array of ['message' => string, 'type' => string]
     * @return string
     */
    public static function renderMessageList(array $messages): string
    {
        if (empty($messages)) {
            return '';
        }

        $html = '<div class="message-list">';
        foreach ($messages as $msg) {
            $html .= self::formatMessage($msg['message'], $msg['type']);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if message is error type
     *
     * @param string $type
     * @return bool
     */
    public static function isErrorMessage(string $type): bool
    {
        return in_array(strtolower($type), ['error', 'danger', 'failed']);
    }

    /**
     * Check if message is success type
     *
     * @param string $type
     * @return bool
     */
    public static function isSuccessMessage(string $type): bool
    {
        return in_array(strtolower($type), ['success', 'contact-accepted', 'completed']);
    }
}
