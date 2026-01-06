<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * Message Helper
 *
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

    /**
     * Parse CLI JSON output and determine message type for GUI display
     *
     * This method parses structured JSON responses from CLI commands that use
     * the --json flag. It maps error codes to user-friendly messages and
     * falls back to parseContactOutput() for non-JSON responses.
     *
     * @param string $output Raw CLI output (may be JSON or plain text)
     * @return array ['message' => string, 'type' => string, 'code' => string|null, 'data' => array|null]
     */
    public static function parseCliJsonOutput(string $output): array
    {
        $output = trim($output);

        // Handle empty output
        if (empty($output)) {
            return [
                'message' => 'No response received',
                'type' => 'error',
                'code' => null,
                'data' => null
            ];
        }

        // Parse JSON
        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // JSON parsing failed - this should not happen with properly configured
            // CLI commands using --json flag, but fall back to legacy parsing for safety
            return self::parseContactOutput($output);
        }

        // Handle success response
        if (isset($decoded['success']) && $decoded['success'] === true) {
            return [
                'message' => $decoded['message'] ?? 'Operation completed successfully',
                'type' => self::mapStatusToMessageType($decoded['data']['status'] ?? 'success'),
                'code' => null,
                'data' => $decoded['data'] ?? null
            ];
        }

        // Handle error response
        if (isset($decoded['error'])) {
            $errorCode = $decoded['error']['code'] ?? 'GENERAL_ERROR';
            $detail = $decoded['error']['detail'] ?? $decoded['message'] ?? '';
            return [
                'message' => self::getGuiFriendlyMessage($errorCode, $detail),
                'type' => 'error',
                'code' => $errorCode,
                'data' => $decoded['error'] ?? null
            ];
        }

        // Handle legacy success format (success=false means error in CliJsonResponse)
        if (isset($decoded['success']) && $decoded['success'] === false) {
            $errorCode = $decoded['error']['code'] ?? 'GENERAL_ERROR';
            $detail = $decoded['error']['detail'] ?? $decoded['message'] ?? '';
            return [
                'message' => self::getGuiFriendlyMessage($errorCode, $detail),
                'type' => 'error',
                'code' => $errorCode,
                'data' => $decoded['error'] ?? null
            ];
        }

        // Fallback for unexpected format
        return self::parseContactOutput($output);
    }

    /**
     * Map CLI error codes to user-friendly GUI messages
     *
     * @param string $errorCode Error code from ErrorCodes class
     * @param string $detail Original error detail message
     * @return string User-friendly message
     */
    public static function getGuiFriendlyMessage(string $errorCode, string $detail): string
    {
        // User-friendly messages for error codes
        $friendlyMessages = [
            // Validation errors
            'INVALID_ADDRESS' => 'The address you entered is not valid. Please check and try again.',
            'INVALID_NAME' => 'The contact name is not valid. Please use alphanumeric characters.',
            'INVALID_FEE' => 'The fee percentage must be between 0 and 100.',
            'INVALID_CREDIT' => 'The credit limit must be a positive number.',
            'INVALID_CURRENCY' => 'The selected currency is not supported.',
            'INVALID_AMOUNT' => 'Please enter a valid transaction amount.',
            'INVALID_RECIPIENT' => 'Invalid recipient address or contact name.',
            'INVALID_PARAMS' => 'Invalid parameters provided. Please check your input.',
            'SELF_CONTACT' => 'You cannot add yourself as a contact.',

            // Contact state errors
            'CONTACT_EXISTS' => 'This contact already exists in your contact list.',
            'CONTACT_NOT_FOUND' => 'Contact not found. It may have been deleted.',
            'CONTACT_BLOCKED' => 'This contact is currently blocked. Unblock to send transactions.',
            'CONTACT_REJECTED' => 'The contact request was rejected.',
            'CONTACT_UNREACHABLE' => 'Could not reach this contact. They may be offline.',

            // Operation errors
            'ACCEPT_FAILED' => 'Failed to accept the contact request. Please try again.',
            'BLOCK_FAILED' => 'Failed to block this contact. Please try again.',
            'UNBLOCK_FAILED' => 'Failed to unblock this contact. Please try again.',
            'DELETE_FAILED' => 'Failed to delete this contact. Please try again.',
            'UPDATE_FAILED' => 'Failed to update contact information. Please try again.',
            'CONTACT_CREATE_FAILED' => 'Failed to create contact. Please try again.',

            // Transaction errors
            'NO_CONTACTS' => 'You need at least one contact to send transactions. Please add a contact first.',
            'INSUFFICIENT_FUNDS' => 'Insufficient funds to complete this transaction.',
            'NO_VIABLE_TRANSPORT' => 'Unable to reach the recipient. They may use a different network type (TOR/HTTP) than your available contacts.',
            'NO_VIABLE_ROUTE' => 'No route could be found to the recipient through your contact network.',
            'P2P_CANCELLED' => 'The P2P route search was cancelled. No compatible contacts available for routing.',

            // Missing data errors
            'MISSING_IDENTIFIER' => 'Please provide a contact address or name.',
            'MISSING_ADDRESS' => 'Contact address is required.',
            'MISSING_PARAMS' => 'Required information is missing. Please fill in all fields.',
            'NO_ADDRESS' => 'No valid address found for this contact.',

            // General errors
            'GENERAL_ERROR' => 'An error occurred. Please try again.',
            'VALIDATION_ERROR' => 'Please check your input and try again.',
        ];

        // Return friendly message if available, otherwise return original detail
        return $friendlyMessages[$errorCode] ?? ($detail ?: 'An error occurred while processing your request.');
    }

    /**
     * Map contact/transaction status to GUI message type
     *
     * @param string $status Status from response
     * @return string Message type for GUI display
     */
    private static function mapStatusToMessageType(string $status): string
    {
        $statusMap = [
            'success' => 'success',
            'accepted' => 'contact-accepted',
            'pending' => 'info',
            'blocked' => 'warning',
            'updated' => 'success',
            'unblocked' => 'success',
            'deleted' => 'success',
            'synced' => 'success',
            'sent' => 'success',
            'completed' => 'success',
        ];

        return $statusMap[strtolower($status)] ?? 'success';
    }
}
