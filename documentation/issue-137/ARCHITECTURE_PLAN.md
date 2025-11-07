# GUI Modernization Architecture Plan (Issue #137)
## Steps 2-5 Technical Specifications

**Version**: 1.0
**Date**: 2025-11-07
**Status**: In Development
**Step 1 Status**: Completed in PR #151

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Step 1 Review - AJAX Operations](#step-1-review-ajax-operations)
3. [Step 2 - Error Handling & Toast Notifications](#step-2-error-handling--toast-notifications)
4. [Step 3 - Server-Sent Events for Real-time Updates](#step-3-server-sent-events-for-real-time-updates)
5. [Step 4 - API Caching and Optimization](#step-4-api-caching-and-optimization)
6. [Step 5 - MVC Refactoring](#step-5-mvc-refactoring)
7. [Integration Architecture](#integration-architecture)
8. [Performance Targets](#performance-targets)
9. [Testing Strategy](#testing-strategy)
10. [Implementation Timeline](#implementation-timeline)
11. [Success Criteria](#success-criteria)

---

## Executive Summary

This architecture plan outlines the technical implementation for Steps 2-5 of the GUI modernization initiative (Issue #137). Step 1 (AJAX Operations) was completed in PR #151, establishing the foundation for asynchronous operations.

**Goals**:
- Eliminate UI blocking and improve perceived performance by 75%
- Reduce page load time from 2-3s to <0.5s (83% faster)
- Implement real-time updates to eliminate manual refreshes
- Reduce Docker API calls from 20+/page to 2-3/page (85% reduction)
- Establish proper MVC architecture for long-term maintainability

**Architecture Philosophy**:
- **Tor Browser Compatible**: No external dependencies, vanilla JavaScript
- **Progressive Enhancement**: Each step builds on the previous
- **Backward Compatible**: Graceful degradation for older browsers
- **Privacy-First**: No tracking, no external CDNs, all local

---

## Step 1 Review - AJAX Operations

### Completed Implementation (PR #151)

**Files Created**:
- `src/gui/api/ajax-handler.php` (254 lines) - Unified AJAX endpoint
- `src/gui/assets/js/form-ajax-integration.js` (376 lines) - Form integration
- `src/gui/assets/js/ajax-forms.js` (279 lines) - Standalone AJAX utilities

**Files Modified**:
- `src/gui/assets/css/page.css` (+140 lines) - Loading indicators, toast styles
- `src/gui/helpers/MessageHelper.php` (+6 lines) - AJAX mode support
- `src/gui/layout/wallet.html` (+4 lines) - JavaScript includes

**Key Features**:
- Asynchronous form submissions using fetch() API
- Loading indicators on buttons during operations
- Basic toast notifications for success/error feedback
- No page reloads required
- CSRF token support
- Session validation

**Architecture Foundation**:
```
┌─────────────────┐
│  Browser (JS)   │
│  - Form Handler │
│  - Fetch API    │
│  - Loading UI   │
└────────┬────────┘
         │ AJAX POST
         ↓
┌─────────────────┐
│ ajax-handler.php│
│  - Route action │
│  - Call control │
│  - Return JSON  │
└────────┬────────┘
         │
         ↓
┌─────────────────┐
│  Controllers    │
│  - Contact      │
│  - Transaction  │
└─────────────────┘
```

**What Works Well**:
- Clean separation of concerns
- Reusable components (LoadingIndicator, Toast)
- Proper error handling structure
- CSRF protection maintained

**Areas for Enhancement (Steps 2-5)**:
- Error handling is basic (needs standardization)
- Toast notifications exist but need enhancement
- Still requires manual page refresh for data updates
- No caching mechanism (repeated API calls)
- Controllers still mix concerns (needs MVC refactoring)

---

## Step 2 - Error Handling & Toast Notifications

### Overview

Enhance the existing toast notification system and implement comprehensive error handling with user-friendly feedback, automatic retry logic, and detailed logging.

### Current State Analysis

**Existing Implementation**:
- `src/gui/assets/js/toast.js` (427 lines) - Toast manager already exists
- Supports success, error, warning, info types
- Auto-dismiss with configurable timeout
- Pause on hover functionality
- ErrorHandler utility with parseErrorResponse()
- RetryHandler utility with exponential backoff

**Current Gaps**:
1. Toast system exists but not fully integrated with AJAX operations
2. Error messages are generic (not user-friendly)
3. No centralized error logging
4. No retry mechanism for transient errors
5. No error state persistence across page loads
6. Limited error context information

### Technical Specifications

#### 2.1 Enhanced Toast Notification System

**Architecture**:
```javascript
// Existing toast.js is already comprehensive
// Integration needed with existing form handlers

// Example usage in form-ajax-integration.js:
try {
    const result = await EiouAPI.contacts.add(...);
    if (result.success) {
        Toast.success(result.message); // Already implemented
    } else {
        Toast.error(result.message); // Already implemented
    }
} catch (error) {
    ErrorHandler.handleError(error, 'adding contact'); // Already implemented
}
```

**Implementation Status**:
- ✅ Toast manager complete (toast.js)
- ✅ Multiple toast stacking
- ✅ Click to dismiss
- ✅ Auto-dismiss with timeout
- ✅ Pause on hover
- ⚠️ Needs integration with all AJAX endpoints
- ⚠️ Needs persistent error logging

**New Components Needed**:

1. **Error Logger** (`src/gui/assets/js/error-logger.js`):
```javascript
/**
 * Error Logger
 * Logs errors to console and optionally to server
 */
const ErrorLogger = {
    logs: [],
    maxLogs: 100,

    log: function(error, context, severity) {
        const entry = {
            timestamp: new Date().toISOString(),
            error: error.message || String(error),
            context: context,
            severity: severity || 'error',
            stack: error.stack || null,
            userAgent: navigator.userAgent
        };

        this.logs.push(entry);

        // Keep only last maxLogs entries
        if (this.logs.length > this.maxLogs) {
            this.logs.shift();
        }

        // Log to console
        console.error('[ErrorLogger]', entry);

        // Optionally send to server (only for critical errors)
        if (severity === 'critical') {
            this.sendToServer(entry);
        }
    },

    sendToServer: async function(entry) {
        try {
            await fetch('/api/log-error.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(entry)
            });
        } catch (err) {
            // Silently fail - don't create error loop
            console.error('Failed to send error to server:', err);
        }
    },

    getLogs: function() {
        return this.logs;
    },

    exportLogs: function() {
        const dataStr = JSON.stringify(this.logs, null, 2);
        const dataBlob = new Blob([dataStr], {type: 'application/json'});
        const url = URL.createObjectURL(dataBlob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'eiou-error-logs-' + Date.now() + '.json';
        link.click();
    }
};

window.ErrorLogger = ErrorLogger;
```

2. **Enhanced Error Handler Integration**:
```javascript
// Extend existing ErrorHandler in toast.js
ErrorHandler.handleError = async function(error, context, options) {
    options = options || {};
    const severity = options.severity || 'error';
    const showToast = typeof options.showToast !== 'undefined' ? options.showToast : true;
    const retry = options.retry || false;

    // Log error
    ErrorLogger.log(error, context, severity);

    // Get user-friendly message
    let message = 'An error occurred';
    if (context) {
        message = 'Error ' + context;
    }

    if (error instanceof Response) {
        const errorData = await this.parseErrorResponse(error);
        message = this.getUserFriendlyMessage(errorData.status, errorData.message);

        if (errorData.details) {
            message += ' (' + errorData.details + ')';
        }
    } else if (error instanceof Error) {
        message += ': ' + error.message;
    }

    // Show toast notification
    if (showToast) {
        if (severity === 'critical') {
            Toast.error(message, 0); // Persistent
        } else {
            Toast.error(message); // Auto-dismiss
        }
    }

    // Automatic retry for transient errors
    if (retry && this.isTransientError(error)) {
        return { shouldRetry: true, error: error };
    }

    return { shouldRetry: false, error: error };
};
```

#### 2.2 Server-Side Error Response Standardization

**New File**: `src/gui/api/JsonResponse.php`

```php
<?php
/**
 * JSON Response Helper
 * Standardizes API responses across all endpoints
 */

class JsonResponse {
    /**
     * Send success response
     */
    public static function success(string $message, array $data = [], int $httpCode = 200): void {
        http_response_code($httpCode);
        self::send([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ]);
    }

    /**
     * Send error response
     */
    public static function error(string $message, array $details = [], int $httpCode = 400): void {
        http_response_code($httpCode);
        self::send([
            'success' => false,
            'error' => $message,
            'details' => $details,
            'timestamp' => time()
        ]);
    }

    /**
     * Send validation error
     */
    public static function validationError(array $errors): void {
        self::error('Validation failed', [
            'validation_errors' => $errors
        ], 422);
    }

    /**
     * Send response and exit
     */
    private static function send(array $response): void {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}
```

**Update**: `src/gui/api/ajax-handler.php`

```php
<?php
// Replace sendJsonResponse() with:
function sendJsonResponse(bool $success, string $message, array $data = []): void {
    if ($success) {
        JsonResponse::success($message, $data);
    } else {
        JsonResponse::error($message, $data);
    }
}
```

#### 2.3 User-Friendly Error Messages

**Error Message Mapping** (`src/gui/helpers/ErrorMessages.php`):

```php
<?php
class ErrorMessages {
    private static $messages = [
        // Contact errors
        'CONTACT_ALREADY_EXISTS' => 'This contact already exists in your wallet.',
        'CONTACT_NOT_FOUND' => 'Contact not found. They may have been deleted.',
        'INVALID_ADDRESS' => 'The address format is invalid. Please check and try again.',
        'CANNOT_DELETE_ACTIVE_CONTACT' => 'Cannot delete contact with active transactions.',

        // Transaction errors
        'INSUFFICIENT_BALANCE' => 'Insufficient balance to complete this transaction.',
        'TRANSACTION_FAILED' => 'Transaction failed to send. Please try again.',
        'INVALID_AMOUNT' => 'Invalid amount. Please enter a valid number.',
        'RECIPIENT_NOT_FOUND' => 'Recipient not found. Please check the address.',
        'TRANSACTION_TIMEOUT' => 'Transaction timed out. It may still process.',

        // Authentication errors
        'SESSION_EXPIRED' => 'Your session has expired. Please refresh the page.',
        'CSRF_TOKEN_INVALID' => 'Security token invalid. Please refresh the page.',
        'UNAUTHORIZED' => 'You are not authorized to perform this action.',

        // System errors
        'DOCKER_UNAVAILABLE' => 'EIOU node is not responding. Please check the container.',
        'DATABASE_ERROR' => 'Database error occurred. Please try again.',
        'NETWORK_ERROR' => 'Network error. Please check your connection.',
        'RATE_LIMIT_EXCEEDED' => 'Too many requests. Please wait a moment.',

        // Validation errors
        'REQUIRED_FIELD_MISSING' => 'Required field is missing: %s',
        'INVALID_FORMAT' => 'Invalid format for field: %s',
        'VALUE_OUT_OF_RANGE' => 'Value out of range: %s must be between %d and %d'
    ];

    /**
     * Get user-friendly error message
     */
    public static function get(string $code, ...$params): string {
        if (!isset(self::$messages[$code])) {
            return $code; // Return code if no message found
        }

        $message = self::$messages[$code];

        // Replace placeholders with parameters
        if (!empty($params)) {
            $message = sprintf($message, ...$params);
        }

        return $message;
    }

    /**
     * Check if error code exists
     */
    public static function exists(string $code): bool {
        return isset(self::$messages[$code]);
    }
}
```

**Usage in Controllers**:

```php
<?php
// In ContactController.php
try {
    $this->contactService->addContact($address, $name, $fee, $credit, $currency);
    JsonResponse::success('Contact added successfully');
} catch (ContactAlreadyExistsException $e) {
    JsonResponse::error(ErrorMessages::get('CONTACT_ALREADY_EXISTS'));
} catch (InvalidAddressException $e) {
    JsonResponse::error(ErrorMessages::get('INVALID_ADDRESS'));
}
```

### Implementation Files

**New Files**:
1. `src/gui/assets/js/error-logger.js` (150 lines)
2. `src/gui/api/JsonResponse.php` (80 lines)
3. `src/gui/helpers/ErrorMessages.php` (100 lines)
4. `src/gui/api/log-error.php` (50 lines) - Server-side error logging

**Modified Files**:
1. `src/gui/assets/js/toast.js` (+50 lines) - Enhanced ErrorHandler
2. `src/gui/api/ajax-handler.php` (+30 lines) - Use JsonResponse
3. `src/gui/controllers/ContactController.php` (+40 lines) - Better exceptions
4. `src/gui/controllers/TransactionController.php` (+40 lines) - Better exceptions
5. `src/gui/layout/wallet.html` (+1 line) - Include error-logger.js

**Total New Code**: ~540 lines

### Testing Requirements

1. **Unit Tests**:
   - ErrorLogger.log() captures errors correctly
   - ErrorMessages.get() returns correct messages
   - JsonResponse formats correctly

2. **Integration Tests**:
   - AJAX errors trigger toasts
   - Transient errors retry automatically
   - Critical errors persist until dismissed
   - Error logs export correctly

3. **Manual Tests**:
   - Test each error type (network, validation, auth)
   - Verify user-friendly messages appear
   - Confirm retry logic works
   - Check error logs accumulate

---

## Step 3 - Server-Sent Events for Real-time Updates

### Overview

Implement Server-Sent Events (SSE) to provide real-time updates for wallet balance, transactions, and contact requests without requiring manual page refresh or polling.

### Current State Analysis

**Existing Polling Implementation** (`src/gui/assets/js/script.js`):
- Polls every 10 seconds for updates
- Pauses during user interaction
- Uses standard fetch() for polling
- Checks for 'new_transaction' or 'new_contact_request' in response

**Issues with Current Approach**:
1. Inefficient - makes request even when no updates
2. 10-second delay before updates appear
3. Wastes bandwidth checking when nothing changed
4. Pausing logic is complex and fragile

### Technical Specifications

#### 3.1 SSE vs WebSockets Decision

**Why SSE over WebSockets**:
- ✅ Simpler implementation (one-way server→client)
- ✅ Better Tor Browser compatibility
- ✅ Automatic reconnection built-in
- ✅ Works over HTTP/HTTPS (no special protocols)
- ✅ Lower overhead than WebSockets
- ❌ No client→server messaging (we don't need it)

**SSE Limitations**:
- Maximum 6 concurrent connections per domain (acceptable for our use case)
- Text-only data (JSON works fine)
- Not supported in IE11 (we don't support IE11 anyway)

#### 3.2 SSE Architecture

```
┌──────────────┐
│   Browser    │
│              │
│  EventSource │←────────────┐
│  - onmessage │             │ Server-Sent Events
│  - onerror   │             │ (text/event-stream)
└──────────────┘             │
                             │
                    ┌────────┴─────────┐
                    │  sse-updates.php │
                    │                  │
                    │  - Open stream   │
                    │  - Check DB      │
                    │  - Send events   │
                    │  - Keep-alive    │
                    └────────┬─────────┘
                             │
                    ┌────────┴─────────┐
                    │   Database       │
                    │                  │
                    │  - Transactions  │
                    │  - Contacts      │
                    │  - Balance       │
                    └──────────────────┘
```

#### 3.3 Server-Side Implementation

**New File**: `src/gui/api/sse-updates.php`

```php
<?php
/**
 * Server-Sent Events (SSE) Updates
 * Provides real-time updates for wallet data
 */

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Prevent PHP timeout
set_time_limit(0);
ini_set('max_execution_time', '0');

// Disable output buffering
if (ob_get_level()) {
    ob_end_clean();
}

// Start session and initialize services
require_once __DIR__ . '/../includes/session.php';

/**
 * Send SSE event
 */
function sendSSEEvent(string $event, array $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";

    // Force flush output
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

/**
 * Send keep-alive ping
 */
function sendKeepAlive(): void {
    echo ": keepalive\n\n";
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

/**
 * Get current wallet state
 */
function getWalletState(): array {
    $serviceContainer = ServiceContainer::getInstance();
    $contactService = $serviceContainer->getContactService();
    $transactionService = $serviceContainer->getTransactionService();

    try {
        // Get balance
        $balance = $transactionService->getBalance();

        // Get pending contact requests count
        $pendingContacts = count($contactService->getPendingContacts());

        // Get recent transactions count
        $recentTransactions = count($transactionService->getRecentTransactions(10));

        // Get last transaction timestamp
        $transactions = $transactionService->getRecentTransactions(1);
        $lastTransactionTime = !empty($transactions) ? $transactions[0]['timestamp'] : null;

        return [
            'balance' => $balance,
            'pending_contacts' => $pendingContacts,
            'recent_transactions_count' => $recentTransactions,
            'last_transaction_time' => $lastTransactionTime,
            'timestamp' => time()
        ];
    } catch (\Exception $e) {
        error_log("SSE error: " . $e->getMessage());
        return [
            'error' => 'Failed to fetch wallet state',
            'timestamp' => time()
        ];
    }
}

// Initial state
$previousState = getWalletState();
sendSSEEvent('init', $previousState);

// Keep connection alive and check for updates
$lastKeepAlive = time();
$checkInterval = 5; // Check every 5 seconds
$keepAliveInterval = 30; // Keep-alive every 30 seconds

while (true) {
    // Check if connection is still alive
    if (connection_aborted()) {
        break;
    }

    // Get current state
    $currentState = getWalletState();

    // Check for changes
    $changes = [];

    // Balance changed
    if ($currentState['balance'] !== $previousState['balance']) {
        $changes['balance'] = $currentState['balance'];
        sendSSEEvent('balance_update', [
            'balance' => $currentState['balance'],
            'previous_balance' => $previousState['balance']
        ]);
    }

    // New contact request
    if ($currentState['pending_contacts'] > $previousState['pending_contacts']) {
        $changes['pending_contacts'] = $currentState['pending_contacts'];
        sendSSEEvent('contact_request', [
            'count' => $currentState['pending_contacts']
        ]);
    }

    // New transaction
    if ($currentState['last_transaction_time'] !== $previousState['last_transaction_time']) {
        $changes['transaction'] = true;
        sendSSEEvent('new_transaction', [
            'timestamp' => $currentState['last_transaction_time']
        ]);
    }

    // Update previous state
    $previousState = $currentState;

    // Send keep-alive ping
    if (time() - $lastKeepAlive >= $keepAliveInterval) {
        sendKeepAlive();
        $lastKeepAlive = time();
    }

    // Sleep before next check
    sleep($checkInterval);
}
```

#### 3.4 Client-Side Implementation

**New File**: `src/gui/assets/js/realtime-updates.js`

```javascript
/**
 * Real-time Updates via Server-Sent Events
 * Tor Browser compatible
 */

(function(window) {
    'use strict';

    const RealtimeUpdates = {
        eventSource: null,
        reconnectAttempts: 0,
        maxReconnectAttempts: 5,
        reconnectDelay: 5000, // 5 seconds
        isConnected: false,
        listeners: {},

        /**
         * Initialize SSE connection
         */
        init: function() {
            if (typeof EventSource === 'undefined') {
                console.warn('EventSource not supported in this browser');
                // Fall back to polling
                this.fallbackToPolling();
                return;
            }

            this.connect();
        },

        /**
         * Connect to SSE endpoint
         */
        connect: function() {
            // Get auth code from URL for session
            const urlParams = new URLSearchParams(window.location.search);
            const authCode = urlParams.get('code');

            const url = '/api/sse-updates.php' + (authCode ? '?code=' + authCode : '');

            console.log('Connecting to SSE endpoint:', url);

            try {
                this.eventSource = new EventSource(url);

                // Connection opened
                this.eventSource.addEventListener('open', () => {
                    console.log('SSE connection established');
                    this.isConnected = true;
                    this.reconnectAttempts = 0;
                    this.updateConnectionStatus('connected');
                });

                // Initial state
                this.eventSource.addEventListener('init', (event) => {
                    const data = JSON.parse(event.data);
                    console.log('Initial state received:', data);
                    this.trigger('init', data);
                });

                // Balance update
                this.eventSource.addEventListener('balance_update', (event) => {
                    const data = JSON.parse(event.data);
                    console.log('Balance updated:', data);
                    this.handleBalanceUpdate(data);
                });

                // New contact request
                this.eventSource.addEventListener('contact_request', (event) => {
                    const data = JSON.parse(event.data);
                    console.log('New contact request:', data);
                    this.handleContactRequest(data);
                });

                // New transaction
                this.eventSource.addEventListener('new_transaction', (event) => {
                    const data = JSON.parse(event.data);
                    console.log('New transaction:', data);
                    this.handleNewTransaction(data);
                });

                // Connection error
                this.eventSource.addEventListener('error', (event) => {
                    console.error('SSE connection error:', event);
                    this.isConnected = false;
                    this.updateConnectionStatus('error');

                    // Close and retry connection
                    this.eventSource.close();
                    this.reconnect();
                });

            } catch (error) {
                console.error('Failed to create EventSource:', error);
                this.fallbackToPolling();
            }
        },

        /**
         * Reconnect after error
         */
        reconnect: function() {
            if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                console.error('Max reconnect attempts reached, falling back to polling');
                this.fallbackToPolling();
                return;
            }

            this.reconnectAttempts++;
            const delay = this.reconnectDelay * this.reconnectAttempts;

            console.log('Reconnecting in ' + (delay / 1000) + ' seconds (attempt ' +
                       this.reconnectAttempts + '/' + this.maxReconnectAttempts + ')');

            this.updateConnectionStatus('reconnecting');

            setTimeout(() => {
                this.connect();
            }, delay);
        },

        /**
         * Handle balance update
         */
        handleBalanceUpdate: function(data) {
            // Update balance display
            const balanceElements = document.querySelectorAll('[data-balance]');
            balanceElements.forEach(el => {
                el.textContent = data.balance;

                // Highlight change
                el.classList.add('balance-updated');
                setTimeout(() => {
                    el.classList.remove('balance-updated');
                }, 2000);
            });

            // Show toast notification
            const change = parseFloat(data.balance) - parseFloat(data.previous_balance);
            const changeStr = (change > 0 ? '+' : '') + change.toFixed(2);

            if (window.Toast) {
                window.Toast.info('Balance updated: ' + changeStr);
            }

            this.trigger('balance_update', data);
        },

        /**
         * Handle new contact request
         */
        handleContactRequest: function(data) {
            // Update pending count badge
            const badges = document.querySelectorAll('[data-pending-contacts]');
            badges.forEach(badge => {
                badge.textContent = data.count;
                badge.style.display = data.count > 0 ? 'inline-block' : 'none';
            });

            // Show toast notification
            if (window.Toast) {
                window.Toast.info('New contact request received');
            }

            // Refresh contacts list
            this.refreshContactsList();

            this.trigger('contact_request', data);
        },

        /**
         * Handle new transaction
         */
        handleNewTransaction: function(data) {
            // Show toast notification
            if (window.Toast) {
                window.Toast.success('New transaction received');
            }

            // Refresh transactions list
            this.refreshTransactionsList();

            this.trigger('new_transaction', data);
        },

        /**
         * Refresh contacts list via AJAX
         */
        refreshContactsList: function() {
            // Find contacts container and reload it
            const container = document.querySelector('[data-contacts-list]');
            if (!container) return;

            // Add loading indicator
            container.classList.add('loading');

            // Reload page section (or use AJAX to fetch contacts)
            // For now, just reload after delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        },

        /**
         * Refresh transactions list via AJAX
         */
        refreshTransactionsList: function() {
            // Find transactions container and reload it
            const container = document.querySelector('[data-transactions-list]');
            if (!container) return;

            // Add loading indicator
            container.classList.add('loading');

            // Reload page section (or use AJAX to fetch transactions)
            // For now, just reload after delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        },

        /**
         * Update connection status indicator
         */
        updateConnectionStatus: function(status) {
            const statusElement = document.getElementById('realtime-status');
            if (!statusElement) return;

            const statusConfig = {
                connected: {
                    icon: 'fa-wifi',
                    text: 'Real-time updates active',
                    color: '#28a745'
                },
                reconnecting: {
                    icon: 'fa-sync-alt fa-spin',
                    text: 'Reconnecting...',
                    color: '#ffc107'
                },
                error: {
                    icon: 'fa-exclamation-triangle',
                    text: 'Connection error',
                    color: '#dc3545'
                },
                polling: {
                    icon: 'fa-clock',
                    text: 'Polling for updates',
                    color: '#6c757d'
                }
            };

            const config = statusConfig[status] || statusConfig.error;

            statusElement.innerHTML = `<i class="fas ${config.icon}"></i> ${config.text}`;
            statusElement.style.color = config.color;
        },

        /**
         * Fall back to polling when SSE unavailable
         */
        fallbackToPolling: function() {
            console.log('Falling back to polling');
            this.updateConnectionStatus('polling');

            // Use existing polling implementation from script.js
            // This is already implemented, just needs to be kept as fallback
            if (typeof initializePolling === 'function') {
                initializePolling();
            }
        },

        /**
         * Disconnect SSE
         */
        disconnect: function() {
            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
                this.isConnected = false;
            }
        },

        /**
         * Add event listener
         */
        on: function(event, callback) {
            if (!this.listeners[event]) {
                this.listeners[event] = [];
            }
            this.listeners[event].push(callback);
        },

        /**
         * Trigger event
         */
        trigger: function(event, data) {
            if (!this.listeners[event]) return;

            this.listeners[event].forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error('Error in event listener:', error);
                }
            });
        }
    };

    // Export to window
    window.RealtimeUpdates = RealtimeUpdates;

    // Auto-initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        RealtimeUpdates.init();
    });

})(window);
```

#### 3.5 UI Integration

**Update**: `src/gui/layout/wallet.html`

```html
<!-- Connection status indicator -->
<div class="connection-status">
    <span id="realtime-status">
        <i class="fas fa-sync-alt fa-spin"></i> Connecting...
    </span>
</div>

<!-- Include SSE script -->
<script src="/assets/js/realtime-updates.js"></script>
```

**Add CSS** to `src/gui/assets/css/page.css`:

```css
/* Connection status indicator */
.connection-status {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.85rem;
    z-index: 1000;
    transition: all 0.3s ease;
}

.connection-status:hover {
    opacity: 0.7;
}

/* Balance update animation */
[data-balance].balance-updated {
    animation: balanceFlash 0.5s ease-in-out 2;
}

@keyframes balanceFlash {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; background-color: #ffc107; }
}

/* Loading state for containers */
[data-contacts-list].loading,
[data-transactions-list].loading {
    opacity: 0.6;
    pointer-events: none;
    position: relative;
}

[data-contacts-list].loading::after,
[data-transactions-list].loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #0d6efd;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}
```

### Implementation Files

**New Files**:
1. `src/gui/api/sse-updates.php` (250 lines)
2. `src/gui/assets/js/realtime-updates.js` (350 lines)

**Modified Files**:
1. `src/gui/layout/wallet.html` (+10 lines) - Status indicator, script include
2. `src/gui/assets/css/page.css` (+80 lines) - Status styles, animations
3. `src/gui/assets/js/script.js` (+20 lines) - Make polling optional fallback

**Total New Code**: ~710 lines

### Testing Requirements

1. **Unit Tests**:
   - SSE connection establishes correctly
   - Events are parsed correctly
   - Reconnection logic works
   - Falls back to polling when SSE unavailable

2. **Integration Tests**:
   - Balance updates appear in real-time
   - New transactions trigger notifications
   - Contact requests update badge
   - Connection status indicator accurate

3. **Manual Tests**:
   - Open two browser windows
   - Send transaction from one
   - Verify other updates without refresh
   - Test reconnection after network interruption
   - Verify works in Tor Browser

---

## Step 4 - API Caching and Optimization

### Overview

Implement intelligent caching, request batching, and connection pooling to reduce Docker API calls from 20+/page to 2-3/page (85% reduction) and improve page load time by 76%.

### Current State Analysis

**Current API Call Pattern**:
```
Page Load:
1. Get balance (1 call)
2. Get contacts list (1 call)
3. Get pending contacts (1 call)
4. Get blocked contacts (1 call)
5. Get transactions (1 call)
6. Get earnings (1 call)
Per Contact (N times):
7. Get contact details (N calls)
8. Get contact balance (N calls)

Total: 6 + (2 × N) calls = 26 calls for 10 contacts
```

**Issues**:
- No caching - same data fetched repeatedly
- Sequential calls - no parallelization
- Full page reload loses all cached data
- Inefficient for data that rarely changes

### Technical Specifications

#### 4.1 Client-Side Caching Layer

**New File**: `src/gui/assets/js/cache-manager.js`

```javascript
/**
 * Cache Manager
 * Client-side caching with TTL and invalidation
 */

(function(window) {
    'use strict';

    const CacheManager = {
        storage: {},

        /**
         * Cache configurations (TTL in seconds)
         */
        config: {
            balance: 5,              // 5 seconds
            contacts: 30,            // 30 seconds
            transactions: 60,        // 60 seconds
            contact_details: 300,    // 5 minutes
            earnings: 60             // 60 seconds
        },

        /**
         * Set cache entry
         */
        set: function(key, value, ttl) {
            const expiry = Date.now() + (ttl * 1000);

            this.storage[key] = {
                value: value,
                expiry: expiry
            };

            // Also persist to sessionStorage for page reloads
            try {
                sessionStorage.setItem(
                    'cache_' + key,
                    JSON.stringify({
                        value: value,
                        expiry: expiry
                    })
                );
            } catch (e) {
                // Ignore if sessionStorage is full or unavailable
                console.warn('Failed to persist cache to sessionStorage:', e);
            }
        },

        /**
         * Get cache entry
         */
        get: function(key) {
            // Check memory cache first
            if (this.storage[key]) {
                const entry = this.storage[key];

                // Check if expired
                if (Date.now() < entry.expiry) {
                    return entry.value;
                }

                // Expired - delete
                delete this.storage[key];
            }

            // Check sessionStorage
            try {
                const cached = sessionStorage.getItem('cache_' + key);
                if (cached) {
                    const entry = JSON.parse(cached);

                    // Check if expired
                    if (Date.now() < entry.expiry) {
                        // Restore to memory
                        this.storage[key] = entry;
                        return entry.value;
                    }

                    // Expired - delete
                    sessionStorage.removeItem('cache_' + key);
                }
            } catch (e) {
                console.warn('Failed to read cache from sessionStorage:', e);
            }

            return null;
        },

        /**
         * Check if cache has valid entry
         */
        has: function(key) {
            return this.get(key) !== null;
        },

        /**
         * Invalidate cache entry
         */
        invalidate: function(key) {
            delete this.storage[key];

            try {
                sessionStorage.removeItem('cache_' + key);
            } catch (e) {
                console.warn('Failed to remove from sessionStorage:', e);
            }
        },

        /**
         * Invalidate all cache entries matching pattern
         */
        invalidatePattern: function(pattern) {
            // Invalidate memory cache
            Object.keys(this.storage).forEach(key => {
                if (key.includes(pattern)) {
                    delete this.storage[key];
                }
            });

            // Invalidate sessionStorage
            try {
                Object.keys(sessionStorage).forEach(key => {
                    if (key.startsWith('cache_') && key.includes(pattern)) {
                        sessionStorage.removeItem(key);
                    }
                });
            } catch (e) {
                console.warn('Failed to clear sessionStorage:', e);
            }
        },

        /**
         * Clear all cache
         */
        clear: function() {
            this.storage = {};

            try {
                Object.keys(sessionStorage).forEach(key => {
                    if (key.startsWith('cache_')) {
                        sessionStorage.removeItem(key);
                    }
                });
            } catch (e) {
                console.warn('Failed to clear sessionStorage:', e);
            }
        },

        /**
         * Get cache statistics
         */
        stats: function() {
            const memoryKeys = Object.keys(this.storage);
            let storageKeys = [];

            try {
                storageKeys = Object.keys(sessionStorage).filter(k => k.startsWith('cache_'));
            } catch (e) {
                // Ignore
            }

            return {
                memory_entries: memoryKeys.length,
                storage_entries: storageKeys.length,
                total_entries: new Set([...memoryKeys, ...storageKeys.map(k => k.replace('cache_', ''))]).size
            };
        }
    };

    // Export to window
    window.CacheManager = CacheManager;

})(window);
```

#### 4.2 API Client with Caching

**New File**: `src/gui/assets/js/api-client.js`

```javascript
/**
 * API Client with Caching
 * Handles all API calls with automatic caching
 */

(function(window) {
    'use strict';

    const APIClient = {
        baseUrl: '/api',

        /**
         * Make API request with caching
         */
        request: async function(endpoint, options, cacheKey, cacheTTL) {
            options = options || {};

            // Check cache first (only for GET requests)
            if ((!options.method || options.method === 'GET') && cacheKey) {
                const cached = CacheManager.get(cacheKey);
                if (cached) {
                    console.log('Cache hit:', cacheKey);
                    return cached;
                }
            }

            // Make request
            const url = this.baseUrl + endpoint;

            try {
                const response = await fetch(url, {
                    ...options,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(options.headers || {})
                    }
                });

                if (!response.ok) {
                    throw response;
                }

                const data = await response.json();

                // Cache response (only for successful GET requests)
                if ((!options.method || options.method === 'GET') && cacheKey && cacheTTL) {
                    CacheManager.set(cacheKey, data, cacheTTL);
                }

                return data;

            } catch (error) {
                console.error('API request failed:', endpoint, error);
                throw error;
            }
        },

        /**
         * Batch multiple API requests
         */
        batch: async function(requests) {
            const promises = requests.map(req => {
                return this.request(
                    req.endpoint,
                    req.options,
                    req.cacheKey,
                    req.cacheTTL
                );
            });

            return Promise.all(promises);
        },

        /**
         * Get wallet balance
         */
        getBalance: function() {
            return this.request(
                '/balance.php',
                { method: 'GET' },
                'balance',
                CacheManager.config.balance
            );
        },

        /**
         * Get contacts list
         */
        getContacts: function() {
            return this.request(
                '/contacts.php',
                { method: 'GET' },
                'contacts',
                CacheManager.config.contacts
            );
        },

        /**
         * Get transactions
         */
        getTransactions: function(limit) {
            limit = limit || 50;
            return this.request(
                '/transactions.php?limit=' + limit,
                { method: 'GET' },
                'transactions_' + limit,
                CacheManager.config.transactions
            );
        },

        /**
         * Get earnings
         */
        getEarnings: function() {
            return this.request(
                '/earnings.php',
                { method: 'GET' },
                'earnings',
                CacheManager.config.earnings
            );
        },

        /**
         * Send transaction (no caching)
         */
        sendTransaction: async function(recipient, manualAddress, amount, currency) {
            const formData = new FormData();
            formData.append('action', 'sendEIOU');
            formData.append('recipient', recipient);
            formData.append('manual-address', manualAddress);
            formData.append('amount', amount);
            formData.append('currency', currency);

            const result = await this.request(
                '/ajax-handler.php',
                {
                    method: 'POST',
                    body: formData
                }
            );

            // Invalidate affected caches
            CacheManager.invalidate('balance');
            CacheManager.invalidatePattern('transactions');

            return result;
        },

        /**
         * Add contact (no caching)
         */
        addContact: async function(address, name, fee, credit, currency) {
            const formData = new FormData();
            formData.append('action', 'addContact');
            formData.append('address', address);
            formData.append('name', name);
            formData.append('fee', fee);
            formData.append('credit', credit);
            formData.append('contactCurrency', currency);

            const result = await this.request(
                '/ajax-handler.php',
                {
                    method: 'POST',
                    body: formData
                }
            );

            // Invalidate contacts cache
            CacheManager.invalidate('contacts');

            return result;
        },

        /**
         * Get all wallet data in one batch
         */
        getWalletData: function() {
            return this.batch([
                {
                    endpoint: '/balance.php',
                    cacheKey: 'balance',
                    cacheTTL: CacheManager.config.balance
                },
                {
                    endpoint: '/contacts.php',
                    cacheKey: 'contacts',
                    cacheTTL: CacheManager.config.contacts
                },
                {
                    endpoint: '/transactions.php?limit=50',
                    cacheKey: 'transactions_50',
                    cacheTTL: CacheManager.config.transactions
                },
                {
                    endpoint: '/earnings.php',
                    cacheKey: 'earnings',
                    cacheTTL: CacheManager.config.earnings
                }
            ]);
        }
    };

    // Export to window
    window.APIClient = APIClient;

})(window);
```

#### 4.3 Server-Side API Endpoint Optimization

**New File**: `src/gui/api/batch.php`

```php
<?php
/**
 * Batch API Endpoint
 * Allows multiple API calls in a single HTTP request
 */

header('Content-Type: application/json');

// Ensure this is an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint only accepts AJAX requests'
    ]);
    exit;
}

// Start session
require_once __DIR__ . '/../includes/session.php';

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Method not allowed', [], 405);
}

// Get requests from JSON body
$input = file_get_contents('php://input');
$requests = json_decode($input, true);

if (!is_array($requests) || empty($requests)) {
    JsonResponse::error('Invalid request format. Expected array of requests.');
}

// Limit batch size
if (count($requests) > 10) {
    JsonResponse::error('Batch size too large. Maximum 10 requests allowed.');
}

$responses = [];

foreach ($requests as $index => $request) {
    if (!isset($request['endpoint'])) {
        $responses[$index] = [
            'success' => false,
            'error' => 'Missing endpoint'
        ];
        continue;
    }

    $endpoint = $request['endpoint'];
    $method = $request['method'] ?? 'GET';
    $params = $request['params'] ?? [];

    // Execute request internally
    try {
        $result = executeBatchRequest($endpoint, $method, $params);
        $responses[$index] = [
            'success' => true,
            'data' => $result
        ];
    } catch (\Exception $e) {
        $responses[$index] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Return all responses
JsonResponse::success('Batch completed', ['responses' => $responses]);

/**
 * Execute a single batch request
 */
function executeBatchRequest(string $endpoint, string $method, array $params): mixed {
    $serviceContainer = ServiceContainer::getInstance();

    // Route to appropriate handler
    switch ($endpoint) {
        case '/balance':
            $transactionService = $serviceContainer->getTransactionService();
            return ['balance' => $transactionService->getBalance()];

        case '/contacts':
            $contactService = $serviceContainer->getContactService();
            return ['contacts' => $contactService->getAllContacts()];

        case '/transactions':
            $transactionService = $serviceContainer->getTransactionService();
            $limit = $params['limit'] ?? 50;
            return ['transactions' => $transactionService->getRecentTransactions($limit)];

        case '/earnings':
            $transactionService = $serviceContainer->getTransactionService();
            return ['earnings' => $transactionService->calculateEarnings()];

        default:
            throw new \Exception('Unknown endpoint: ' . $endpoint);
    }
}
```

#### 4.4 Cache Invalidation Strategy

**Cache Invalidation Rules**:

1. **After Transaction Sent**:
   - Invalidate: `balance`, `transactions_*`
   - Keep: `contacts`, `earnings`

2. **After Contact Added/Edited/Deleted**:
   - Invalidate: `contacts`
   - Keep: `balance`, `transactions_*`, `earnings`

3. **After SSE Balance Update**:
   - Invalidate: `balance`
   - Keep: others

4. **After SSE Transaction Update**:
   - Invalidate: `balance`, `transactions_*`
   - Keep: `contacts`, `earnings`

5. **On Page Reload**:
   - Keep all valid cached entries in sessionStorage
   - Restore to memory cache

### Implementation Files

**New Files**:
1. `src/gui/assets/js/cache-manager.js` (250 lines)
2. `src/gui/assets/js/api-client.js` (300 lines)
3. `src/gui/api/batch.php` (150 lines)

**Modified Files**:
1. `src/gui/assets/js/form-ajax-integration.js` (+30 lines) - Use APIClient
2. `src/gui/assets/js/realtime-updates.js` (+20 lines) - Invalidate cache on updates
3. `src/gui/layout/wallet.html` (+2 lines) - Include new scripts

**Total New Code**: ~752 lines

### Performance Impact

**Before**:
- Page load: 26 API calls (2-3 seconds)
- Cache hit rate: 0%
- Bandwidth per page: ~50KB

**After**:
- Initial page load: 4 API calls (0.5 seconds) - 85% reduction
- Subsequent page loads: 0-2 API calls (0.1 seconds) - cache hits
- Cache hit rate: ~70%
- Bandwidth per page: ~10KB - 80% reduction

### Testing Requirements

1. **Unit Tests**:
   - CacheManager set/get works correctly
   - Cache expiry works
   - Cache invalidation patterns work
   - APIClient makes correct requests

2. **Integration Tests**:
   - Batch endpoint processes multiple requests
   - Cache persists across page reloads
   - Cache invalidation triggers correctly
   - Cache stats accurate

3. **Performance Tests**:
   - Measure page load time before/after
   - Measure API call count before/after
   - Verify cache hit rate >70%
   - Test with 10, 50, 100 contacts

4. **Manual Tests**:
   - Load page, check network tab (4 calls)
   - Reload page, check network tab (0-2 calls)
   - Send transaction, verify cache invalidation
   - Check cache stats in console

---

## Step 5 - MVC Refactoring

### Overview

Refactor PHP backend into proper MVC architecture with PSR-4 autoloading, dependency injection, and testable code. This establishes long-term maintainability and enables future enhancements.

### Current State Analysis

**Current Architecture Issues**:
1. Controllers mix business logic with presentation
2. No clear service layer separation
3. Direct database access in controllers
4. Tight coupling between components
5. No dependency injection container
6. Difficult to test

**Current Structure**:
```
src/gui/
├── controllers/          # Controllers (some logic mixed)
├── helpers/              # Helper functions
├── functions/            # Legacy functions
├── includes/             # Session management
├── layout/               # HTML templates
└── api/                  # API endpoints
```

### Technical Specifications

#### 5.1 Target MVC Architecture

```
src/gui/
├── app/
│   ├── Controllers/      # Thin controllers (route → service)
│   ├── Services/         # Business logic
│   ├── Models/           # Data models
│   ├── Repositories/     # Data access layer
│   ├── Views/            # Presentation templates
│   └── Middleware/       # Request/response filtering
├── core/
│   ├── Application.php   # App container
│   ├── Router.php        # Request router
│   ├── Container.php     # DI container
│   └── Config.php        # Configuration
├── config/
│   ├── app.php           # App configuration
│   ├── database.php      # DB configuration
│   └── cache.php         # Cache configuration
└── public/
    └── index.php         # Entry point
```

#### 5.2 Dependency Injection Container

**New File**: `src/gui/core/Container.php`

```php
<?php
namespace EIOU\GUI\Core;

/**
 * Dependency Injection Container
 * PSR-11 compliant
 */
class Container {
    private array $bindings = [];
    private array $instances = [];

    /**
     * Bind a class or interface to a concrete implementation
     */
    public function bind(string $abstract, $concrete = null, bool $singleton = false): void {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton
        ];
    }

    /**
     * Bind a singleton
     */
    public function singleton(string $abstract, $concrete = null): void {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Resolve a class from the container
     */
    public function make(string $abstract, array $parameters = []): mixed {
        // Check if we have a binding
        if (!isset($this->bindings[$abstract])) {
            // Try to resolve directly
            return $this->resolve($abstract, $parameters);
        }

        $binding = $this->bindings[$abstract];

        // If singleton and already instantiated, return existing instance
        if ($binding['singleton'] && isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Resolve the concrete implementation
        $concrete = $binding['concrete'];

        if ($concrete instanceof \Closure) {
            $object = $concrete($this, $parameters);
        } else {
            $object = $this->resolve($concrete, $parameters);
        }

        // Store singleton instance
        if ($binding['singleton']) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Resolve a class using reflection
     */
    private function resolve(string $class, array $parameters = []): mixed {
        $reflector = new \ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new \Exception("Class {$class} is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class;
        }

        $dependencies = $this->resolveDependencies(
            $constructor->getParameters(),
            $parameters
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor dependencies
     */
    private function resolveDependencies(array $parameters, array $provided = []): array {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            // Check if provided
            if (isset($provided[$parameter->getName()])) {
                $dependencies[] = $provided[$parameter->getName()];
                continue;
            }

            // Get type
            $type = $parameter->getType();

            if ($type === null) {
                // No type hint - check for default value
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new \Exception("Cannot resolve parameter {$parameter->getName()}");
                }
                continue;
            }

            // Resolve type from container
            $typeName = $type->getName();

            if ($type->isBuiltin()) {
                // Built-in type - check for default value
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new \Exception("Cannot resolve built-in parameter {$parameter->getName()}");
                }
            } else {
                // Class type - resolve from container
                $dependencies[] = $this->make($typeName);
            }
        }

        return $dependencies;
    }

    /**
     * Check if container has binding
     */
    public function has(string $abstract): bool {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Get instance (PSR-11 compatibility)
     */
    public function get(string $id): mixed {
        return $this->make($id);
    }
}
```

#### 5.3 Service Layer Separation

**Example**: Contact Service

**New File**: `src/gui/app/Services/ContactService.php`

```php
<?php
namespace EIOU\GUI\App\Services;

use EIOU\GUI\App\Repositories\ContactRepository;
use EIOU\GUI\App\Models\Contact;
use EIOU\GUI\Core\Cache\CacheInterface;

/**
 * Contact Service
 * Business logic for contact management
 */
class ContactService {
    public function __construct(
        private ContactRepository $repository,
        private CacheInterface $cache
    ) {}

    /**
     * Get all contacts
     */
    public function getAllContacts(): array {
        // Check cache first
        $cacheKey = 'contacts_all';
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // Fetch from repository
        $contacts = $this->repository->findAll();

        // Cache for 30 seconds
        $this->cache->set($cacheKey, $contacts, 30);

        return $contacts;
    }

    /**
     * Add new contact
     */
    public function addContact(
        string $address,
        string $name,
        float $fee,
        float $credit,
        string $currency
    ): Contact {
        // Validate
        $this->validateContact($address, $name, $fee, $credit, $currency);

        // Check if already exists
        if ($this->repository->existsByAddress($address)) {
            throw new ContactAlreadyExistsException("Contact with address {$address} already exists");
        }

        // Create contact
        $contact = new Contact([
            'address' => $address,
            'name' => $name,
            'fee' => $fee,
            'credit' => $credit,
            'currency' => $currency,
            'status' => 'pending'
        ]);

        // Save to repository
        $this->repository->save($contact);

        // Invalidate cache
        $this->cache->delete('contacts_all');

        return $contact;
    }

    /**
     * Validate contact data
     */
    private function validateContact(
        string $address,
        string $name,
        float $fee,
        float $credit,
        string $currency
    ): void {
        if (empty($address)) {
            throw new \InvalidArgumentException('Address is required');
        }

        if (empty($name)) {
            throw new \InvalidArgumentException('Name is required');
        }

        if ($fee < 0) {
            throw new \InvalidArgumentException('Fee must be positive');
        }

        if ($credit < 0) {
            throw new \InvalidArgumentException('Credit must be positive');
        }

        if (!in_array($currency, ['USD', 'EUR', 'GBP'])) {
            throw new \InvalidArgumentException('Invalid currency');
        }
    }

    // Additional methods: editContact(), deleteContact(), acceptContact(), etc.
}
```

#### 5.4 Repository Pattern

**New File**: `src/gui/app/Repositories/ContactRepository.php`

```php
<?php
namespace EIOU\GUI\App\Repositories;

use EIOU\GUI\App\Models\Contact;
use EIOU\GUI\Core\Database\ConnectionInterface;

/**
 * Contact Repository
 * Data access layer for contacts
 */
class ContactRepository {
    public function __construct(
        private ConnectionInterface $db
    ) {}

    /**
     * Find all contacts
     */
    public function findAll(): array {
        $stmt = $this->db->query("
            SELECT * FROM contacts
            ORDER BY name ASC
        ");

        return array_map(
            fn($row) => new Contact($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * Find contact by address
     */
    public function findByAddress(string $address): ?Contact {
        $stmt = $this->db->prepare("
            SELECT * FROM contacts
            WHERE address = :address
            LIMIT 1
        ");

        $stmt->execute(['address' => $address]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? new Contact($row) : null;
    }

    /**
     * Check if contact exists
     */
    public function existsByAddress(string $address): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM contacts
            WHERE address = :address
        ");

        $stmt->execute(['address' => $address]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Save contact
     */
    public function save(Contact $contact): void {
        if ($contact->getId()) {
            // Update existing
            $this->update($contact);
        } else {
            // Insert new
            $this->insert($contact);
        }
    }

    /**
     * Insert new contact
     */
    private function insert(Contact $contact): void {
        $stmt = $this->db->prepare("
            INSERT INTO contacts (address, name, fee, credit, currency, status, created_at)
            VALUES (:address, :name, :fee, :credit, :currency, :status, :created_at)
        ");

        $stmt->execute([
            'address' => $contact->getAddress(),
            'name' => $contact->getName(),
            'fee' => $contact->getFee(),
            'credit' => $contact->getCredit(),
            'currency' => $contact->getCurrency(),
            'status' => $contact->getStatus(),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $contact->setId($this->db->lastInsertId());
    }

    /**
     * Update existing contact
     */
    private function update(Contact $contact): void {
        $stmt = $this->db->prepare("
            UPDATE contacts
            SET name = :name,
                fee = :fee,
                credit = :credit,
                currency = :currency,
                status = :status,
                updated_at = :updated_at
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $contact->getId(),
            'name' => $contact->getName(),
            'fee' => $contact->getFee(),
            'credit' => $contact->getCredit(),
            'currency' => $contact->getCurrency(),
            'status' => $contact->getStatus(),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Delete contact
     */
    public function delete(Contact $contact): void {
        $stmt = $this->db->prepare("
            DELETE FROM contacts
            WHERE id = :id
        ");

        $stmt->execute(['id' => $contact->getId()]);
    }
}
```

#### 5.5 Model Classes

**New File**: `src/gui/app/Models/Contact.php`

```php
<?php
namespace EIOU\GUI\App\Models;

/**
 * Contact Model
 */
class Contact {
    private ?int $id = null;
    private string $address;
    private string $name;
    private float $fee;
    private float $credit;
    private string $currency;
    private string $status = 'pending'; // pending, active, blocked
    private ?string $createdAt = null;
    private ?string $updatedAt = null;

    public function __construct(array $data = []) {
        if (isset($data['id'])) {
            $this->id = (int)$data['id'];
        }

        if (isset($data['address'])) {
            $this->address = $data['address'];
        }

        if (isset($data['name'])) {
            $this->name = $data['name'];
        }

        if (isset($data['fee'])) {
            $this->fee = (float)$data['fee'];
        }

        if (isset($data['credit'])) {
            $this->credit = (float)$data['credit'];
        }

        if (isset($data['currency'])) {
            $this->currency = $data['currency'];
        }

        if (isset($data['status'])) {
            $this->status = $data['status'];
        }

        if (isset($data['created_at'])) {
            $this->createdAt = $data['created_at'];
        }

        if (isset($data['updated_at'])) {
            $this->updatedAt = $data['updated_at'];
        }
    }

    // Getters
    public function getId(): ?int { return $this->id; }
    public function getAddress(): string { return $this->address; }
    public function getName(): string { return $this->name; }
    public function getFee(): float { return $this->fee; }
    public function getCredit(): float { return $this->credit; }
    public function getCurrency(): string { return $this->currency; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }

    // Setters
    public function setId(int $id): void { $this->id = $id; }
    public function setAddress(string $address): void { $this->address = $address; }
    public function setName(string $name): void { $this->name = $name; }
    public function setFee(float $fee): void { $this->fee = $fee; }
    public function setCredit(float $credit): void { $this->credit = $credit; }
    public function setCurrency(string $currency): void { $this->currency = $currency; }
    public function setStatus(string $status): void { $this->status = $status; }

    /**
     * Convert to array
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'address' => $this->address,
            'name' => $this->name,
            'fee' => $this->fee,
            'credit' => $this->credit,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }

    /**
     * Check if contact is active
     */
    public function isActive(): bool {
        return $this->status === 'active';
    }

    /**
     * Check if contact is blocked
     */
    public function isBlocked(): bool {
        return $this->status === 'blocked';
    }
}
```

#### 5.6 Thin Controllers

**Updated**: `src/gui/app/Controllers/ContactController.php`

```php
<?php
namespace EIOU\GUI\App\Controllers;

use EIOU\GUI\App\Services\ContactService;
use EIOU\GUI\Core\Http\Request;
use EIOU\GUI\Core\Http\Response;

/**
 * Contact Controller
 * Thin controller - routes requests to services
 */
class ContactController {
    public function __construct(
        private ContactService $contactService
    ) {}

    /**
     * Add new contact
     */
    public function add(Request $request): Response {
        try {
            // Validate CSRF token
            $request->validateCsrfToken();

            // Get input
            $address = $request->input('address');
            $name = $request->input('name');
            $fee = $request->input('fee', 0.0);
            $credit = $request->input('credit', 0.0);
            $currency = $request->input('contactCurrency', 'USD');

            // Call service
            $contact = $this->contactService->addContact(
                $address,
                $name,
                (float)$fee,
                (float)$credit,
                $currency
            );

            // Return success response
            return Response::json([
                'success' => true,
                'message' => 'Contact added successfully',
                'data' => $contact->toArray()
            ]);

        } catch (\InvalidArgumentException $e) {
            return Response::json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);

        } catch (ContactAlreadyExistsException $e) {
            return Response::json([
                'success' => false,
                'error' => ErrorMessages::get('CONTACT_ALREADY_EXISTS')
            ], 409);

        } catch (\Exception $e) {
            error_log('Error adding contact: ' . $e->getMessage());

            return Response::json([
                'success' => false,
                'error' => 'An error occurred while adding contact'
            ], 500);
        }
    }

    /**
     * Get all contacts
     */
    public function index(Request $request): Response {
        try {
            $contacts = $this->contactService->getAllContacts();

            return Response::json([
                'success' => true,
                'data' => array_map(fn($c) => $c->toArray(), $contacts)
            ]);

        } catch (\Exception $e) {
            error_log('Error fetching contacts: ' . $e->getMessage());

            return Response::json([
                'success' => false,
                'error' => 'Failed to fetch contacts'
            ], 500);
        }
    }

    // Additional methods: edit(), delete(), accept(), etc.
}
```

#### 5.7 PSR-4 Autoloading

**New File**: `src/gui/vendor/autoload.php`

```php
<?php
/**
 * PSR-4 Autoloader
 */

spl_autoload_register(function ($class) {
    // Project-specific namespace prefix
    $prefix = 'EIOU\\GUI\\';

    // Base directory for the namespace prefix
    $base_dir = __DIR__ . '/../';

    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No, move to the next registered autoloader
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace the namespace prefix with the base directory,
    // replace namespace separators with directory separators,
    // and append with .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});
```

### Migration Strategy

**Phase 1: Parallel Implementation** (Week 1-2)
- Create new MVC structure alongside existing code
- No breaking changes to existing functionality
- Test new architecture with subset of features

**Phase 2: Gradual Migration** (Week 3-4)
- Migrate one controller at a time
- Keep old endpoints working
- Add feature flags to toggle between old/new

**Phase 3: Complete Transition** (Week 5-6)
- Migrate all controllers to MVC
- Remove legacy code
- Update documentation

**Phase 4: Cleanup** (Week 7)
- Remove feature flags
- Final testing
- Performance optimization

### Implementation Files

**New Files**:
1. `src/gui/core/Container.php` (200 lines)
2. `src/gui/core/Application.php` (150 lines)
3. `src/gui/core/Router.php` (200 lines)
4. `src/gui/app/Services/ContactService.php` (300 lines)
5. `src/gui/app/Services/TransactionService.php` (350 lines)
6. `src/gui/app/Repositories/ContactRepository.php` (250 lines)
7. `src/gui/app/Repositories/TransactionRepository.php` (300 lines)
8. `src/gui/app/Models/Contact.php` (150 lines)
9. `src/gui/app/Models/Transaction.php` (200 lines)
10. `src/gui/app/Controllers/ContactController.php` (400 lines)
11. `src/gui/app/Controllers/TransactionController.php` (450 lines)
12. `src/gui/vendor/autoload.php` (50 lines)

**Total New Code**: ~3,000 lines

### Testing Requirements

1. **Unit Tests** (PHPUnit):
   - Container resolves dependencies correctly
   - Services implement business logic correctly
   - Repositories perform CRUD operations
   - Models validate data correctly

2. **Integration Tests**:
   - Controllers route to services correctly
   - Services call repositories correctly
   - Database transactions work
   - Cache invalidation works

3. **Migration Tests**:
   - Old and new code produce same results
   - Feature flags work correctly
   - No data loss during migration

---

## Integration Architecture

### How All Steps Work Together

```
┌─────────────────────────────────────────────────────────────────┐
│                          BROWSER                                │
│                                                                 │
│  ┌──────────────┐  ┌────────────────┐  ┌──────────────┐      │
│  │ Form AJAX    │  │ SSE Connection │  │ Cache Manager│      │
│  │ (Step 1)     │  │ (Step 3)       │  │ (Step 4)     │      │
│  └──────┬───────┘  └────────┬───────┘  └──────┬───────┘      │
│         │                   │                   │              │
│         │                   │                   │              │
│  ┌──────┴─────────────────────────────────────┴───────┐      │
│  │         Error Handler & Toast (Step 2)             │      │
│  └─────────────────────────────────────────────────────┘      │
│                           │                                     │
└───────────────────────────┼─────────────────────────────────────┘
                            │
                            ↓ HTTP/SSE
┌───────────────────────────┼─────────────────────────────────────┐
│                        SERVER                                   │
│                           │                                     │
│  ┌────────────────────────┼────────────────────────────┐      │
│  │          API Layer (ajax-handler, sse-updates)       │      │
│  └────────────────────────┼────────────────────────────┘      │
│                           │                                     │
│  ┌────────────────────────┼────────────────────────────┐      │
│  │              MVC Architecture (Step 5)               │      │
│  │                                                       │      │
│  │  ┌──────────────┐    ┌─────────────┐                │      │
│  │  │ Controllers  │───▶│  Services   │                │      │
│  │  └──────────────┘    └──────┬──────┘                │      │
│  │                             │                        │      │
│  │                             ↓                        │      │
│  │                      ┌─────────────┐                 │      │
│  │                      │Repositories │                 │      │
│  │                      └──────┬──────┘                 │      │
│  │                             │                        │      │
│  └─────────────────────────────┼────────────────────────┘      │
│                                │                                │
│  ┌─────────────────────────────┼────────────────────────┐      │
│  │              Database Layer                          │      │
│  └──────────────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────────┘
```

### Data Flow Example: Send Transaction

1. **User Action** (Step 1):
   - User fills form, clicks "Send"
   - AJAX handler prevents default submission
   - Shows loading indicator

2. **Error Validation** (Step 2):
   - Client-side validation
   - If error, show toast notification
   - If valid, proceed

3. **Check Cache** (Step 4):
   - Check if recipient data cached
   - Use cached data if available
   - Else fetch from server

4. **API Request** (Step 1):
   - POST to ajax-handler.php
   - Include CSRF token
   - Send transaction data

5. **Server Processing** (Step 5):
   - Router routes to Controller
   - Controller calls Service
   - Service validates business rules
   - Repository saves to database

6. **Response** (Step 2):
   - Server returns JSON response
   - If success, show success toast
   - If error, show error toast with retry option

7. **Cache Invalidation** (Step 4):
   - Invalidate balance cache
   - Invalidate transactions cache

8. **Real-time Update** (Step 3):
   - SSE detects balance change
   - Pushes update to all connected clients
   - Update balance display without reload

### Event Sequence Diagram

```
User          Browser          Server          Database
 │              │                │                │
 │─Click Send──▶│                │                │
 │              │──Validate──▶   │                │
 │              │◀─Valid─────    │                │
 │              │                │                │
 │              │──POST /ajax──▶ │                │
 │              │                │──Save──▶       │
 │              │                │◀─OK──┘         │
 │              │◀─JSON Success──│                │
 │              │                │                │
 │◀─Toast──────│                │                │
 │              │                │                │
 │              │◀──SSE Update───│                │
 │◀─Balance────│                │                │
```

---

## Performance Targets

### Metrics Comparison

| Metric | Before (Current) | After Step 2 | After Step 3 | After Step 4 | After Step 5 | Improvement |
|--------|------------------|--------------|--------------|--------------|--------------|-------------|
| Page Load Time | 2-3s | 2-3s | 2-3s | 0.5s | 0.4s | **83% faster** |
| UI Blocking | Yes | No | No | No | No | **Eliminated** |
| Manual Refresh | Required | Required | Optional | Optional | Optional | **Auto-updates** |
| API Calls/Page | 20+ | 20+ | 20+ | 2-3 | 2-3 | **85% reduction** |
| Memory Usage | 150MB | 150MB | 155MB | 90MB | 80MB | **47% less** |
| Time to Interactive | 3s | 2s | 2s | 0.5s | 0.4s | **87% faster** |
| Error Recovery | Manual | Auto | Auto | Auto | Auto | **Automatic** |
| Cache Hit Rate | 0% | 0% | 0% | 70% | 75% | **75% hits** |
| Real-time Updates | No | No | Yes | Yes | Yes | **Instant** |
| Code Testability | Low | Low | Low | Low | High | **Testable** |

### Performance Testing Plan

**Test Scenarios**:
1. Cold load (no cache)
2. Warm load (cache populated)
3. With 10 contacts
4. With 50 contacts
5. With 100 contacts
6. Concurrent users (10, 50, 100)
7. Network latency simulation (Tor)

**Tools**:
- Chrome DevTools Performance
- Lighthouse CI
- Apache Benchmark (ab)
- Custom Node.js load testing

---

## Testing Strategy

### Unit Testing

**PHP Unit Tests** (PHPUnit):
```bash
vendor/bin/phpunit tests/Unit
```

**Test Coverage Goals**:
- Controllers: 80%
- Services: 90%
- Repositories: 85%
- Models: 90%
- Utilities: 95%

**Example Test**:
```php
<?php
namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use EIOU\GUI\App\Services\ContactService;

class ContactServiceTest extends TestCase {
    public function testAddContactValidData() {
        $service = new ContactService($this->mockRepository(), $this->mockCache());

        $contact = $service->addContact(
            'test-address',
            'Test Contact',
            1.0,
            10.0,
            'USD'
        );

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertEquals('Test Contact', $contact->getName());
    }

    public function testAddContactInvalidAddress() {
        $this->expectException(\InvalidArgumentException::class);

        $service = new ContactService($this->mockRepository(), $this->mockCache());
        $service->addContact('', 'Test', 1.0, 10.0, 'USD');
    }
}
```

### Integration Testing

**Docker Integration Tests**:
```bash
./tests/eiou-docker/eiou-docker-test.sh
```

**Test Cases**:
1. Container startup and initialization
2. AJAX endpoints return valid JSON
3. SSE connection establishes
4. Cache persists across requests
5. MVC routing works correctly

### End-to-End Testing

**Manual Test Checklist**:

**Step 1 (AJAX)**:
- [ ] Send transaction via AJAX
- [ ] Add contact via AJAX
- [ ] Edit contact via AJAX
- [ ] Delete contact via AJAX
- [ ] Loading indicators appear
- [ ] Toast notifications show

**Step 2 (Error Handling)**:
- [ ] Invalid input shows error toast
- [ ] Network error shows retry option
- [ ] Server error shows user-friendly message
- [ ] Error logs accumulate
- [ ] Critical errors persist

**Step 3 (SSE)**:
- [ ] SSE connection establishes
- [ ] Balance updates in real-time
- [ ] New transactions appear automatically
- [ ] Connection status indicator accurate
- [ ] Falls back to polling if SSE unavailable

**Step 4 (Caching)**:
- [ ] First page load fetches data
- [ ] Second page load uses cache
- [ ] Cache invalidates after mutations
- [ ] Cache persists across reloads
- [ ] Cache stats accurate

**Step 5 (MVC)**:
- [ ] All endpoints still work
- [ ] No breaking changes
- [ ] Performance improved
- [ ] Code is more maintainable
- [ ] Tests pass

### Automated Testing

**GitHub Actions CI/CD**:
```yaml
name: GUI Modernization Tests

on:
  pull_request:
    paths:
      - 'src/gui/**'
      - 'tests/**'

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'

      - name: Install dependencies
        run: composer install

      - name: Run unit tests
        run: vendor/bin/phpunit

      - name: Run Docker tests
        run: ./tests/eiou-docker/eiou-docker-test.sh

      - name: Check code coverage
        run: vendor/bin/phpunit --coverage-clover coverage.xml
```

---

## Implementation Timeline

### Phase 1: Step 2 - Error Handling (Week 1)

**Duration**: 5 days

**Tasks**:
1. Create error-logger.js (1 day)
2. Create JsonResponse.php (1 day)
3. Create ErrorMessages.php (1 day)
4. Update controllers with better error handling (1 day)
5. Testing and documentation (1 day)

**Deliverables**:
- Enhanced error handling system
- User-friendly error messages
- Error logging functionality
- Toast notifications integrated

**Success Criteria**:
- All error types have user-friendly messages
- Errors are logged correctly
- Toast notifications show for all operations
- No console errors

---

### Phase 2: Step 3 - Real-time Updates (Week 2)

**Duration**: 5 days

**Tasks**:
1. Create sse-updates.php (2 days)
2. Create realtime-updates.js (2 days)
3. Integration with existing UI (1 day)
4. Testing and fallback to polling (1 day)
5. Documentation (0.5 day)

**Deliverables**:
- SSE endpoint working
- Real-time balance updates
- Real-time transaction notifications
- Connection status indicator
- Fallback to polling

**Success Criteria**:
- SSE connection establishes within 2 seconds
- Updates appear within 1 second of change
- Reconnection works after network interruption
- Falls back to polling when SSE unavailable
- Works in Tor Browser

---

### Phase 3: Step 4 - Caching & Optimization (Week 3)

**Duration**: 5 days

**Tasks**:
1. Create cache-manager.js (1 day)
2. Create api-client.js (1 day)
3. Create batch.php endpoint (1 day)
4. Integration with existing code (1 day)
5. Performance testing and tuning (1 day)

**Deliverables**:
- Client-side caching working
- API client with automatic caching
- Batch API endpoint
- Cache invalidation strategy
- Performance metrics

**Success Criteria**:
- 70%+ cache hit rate
- Page load time <0.5s (warm cache)
- API calls reduced by 85%
- Cache persists across page reloads
- No stale data shown

---

### Phase 4: Step 5 - MVC Refactoring (Weeks 4-6)

**Duration**: 15 days

**Week 4: Foundation**
1. Create DI Container (2 days)
2. Create Router (1 day)
3. Setup PSR-4 autoloading (1 day)
4. Create base classes (1 day)

**Week 5: Services & Repositories**
5. Implement ContactService (2 days)
6. Implement TransactionService (2 days)
7. Implement Repositories (2 days)
8. Implement Models (1 day)

**Week 6: Controllers & Migration**
9. Migrate Controllers (3 days)
10. Integration testing (2 days)
11. Remove legacy code (1 day)
12. Documentation (1 day)

**Deliverables**:
- Complete MVC architecture
- PSR-4 autoloading
- Dependency injection
- Service layer separation
- Repository pattern
- Thin controllers
- Unit tests for all components

**Success Criteria**:
- All existing features work
- No breaking changes
- Code is more maintainable
- Test coverage >80%
- Performance improved
- Documentation complete

---

### Phase 5: Final Integration & Optimization (Week 7)

**Duration**: 5 days

**Tasks**:
1. Performance optimization (2 days)
2. Security audit (1 day)
3. Documentation review (1 day)
4. Final testing (1 day)

**Deliverables**:
- Performance report
- Security audit report
- Complete documentation
- Final test results

**Success Criteria**:
- All performance targets met
- No security vulnerabilities
- Documentation complete
- All tests passing

---

## Success Criteria

### Technical Success Criteria

**Performance**:
- ✅ Page load time <0.5s (83% faster)
- ✅ UI blocking eliminated
- ✅ API calls reduced by 85%
- ✅ Memory usage reduced by 47%
- ✅ Time to interactive <0.5s

**Functionality**:
- ✅ All existing features work
- ✅ Real-time updates working
- ✅ Error handling comprehensive
- ✅ Caching effective (70%+ hit rate)
- ✅ MVC architecture complete

**Quality**:
- ✅ Test coverage >80%
- ✅ No breaking changes
- ✅ Tor Browser compatible
- ✅ Security audit passed
- ✅ Documentation complete

### User Experience Success Criteria

**Usability**:
- ✅ No manual refresh required
- ✅ Instant visual feedback
- ✅ User-friendly error messages
- ✅ Automatic error recovery
- ✅ Smooth animations

**Reliability**:
- ✅ No crashes or freezes
- ✅ Graceful error handling
- ✅ Offline-friendly (cached data)
- ✅ Cross-browser compatible
- ✅ Mobile responsive

### Maintenance Success Criteria

**Code Quality**:
- ✅ Clean architecture (MVC)
- ✅ Well-documented code
- ✅ Unit tests comprehensive
- ✅ Easy to extend
- ✅ Follows best practices

**Operational**:
- ✅ Easy to deploy
- ✅ Easy to debug
- ✅ Logs are useful
- ✅ Monitoring in place
- ✅ Error tracking working

---

## Risk Mitigation

### Identified Risks

1. **SSE Connection Stability in Tor**
   - **Risk**: SSE might not work reliably over Tor
   - **Mitigation**: Fallback to polling, short timeout, automatic reconnection

2. **Cache Invalidation Bugs**
   - **Risk**: Stale data shown to users
   - **Mitigation**: Conservative TTL, aggressive invalidation, manual refresh button

3. **Breaking Changes During MVC Migration**
   - **Risk**: Existing features break during refactoring
   - **Mitigation**: Parallel implementation, feature flags, gradual migration

4. **Performance Regression**
   - **Risk**: New code slower than old code
   - **Mitigation**: Benchmark before/after, performance testing, profiling

5. **Tor Browser Compatibility**
   - **Risk**: New features don't work in Tor Browser
   - **Mitigation**: Test in Tor Browser, no external dependencies, graceful degradation

### Rollback Plan

**If Critical Issue Detected**:

1. **Immediate**: Disable feature flag
2. **Short-term**: Revert to previous version
3. **Medium-term**: Fix issue in development
4. **Long-term**: Deploy fixed version

**Feature Flags**:
```php
// In config/features.php
return [
    'sse_enabled' => env('FEATURE_SSE_ENABLED', false),
    'cache_enabled' => env('FEATURE_CACHE_ENABLED', false),
    'mvc_enabled' => env('FEATURE_MVC_ENABLED', false)
];
```

---

## Conclusion

This architecture plan provides a comprehensive roadmap for completing Steps 2-5 of the GUI modernization initiative. Each step builds on the previous one, creating a cohesive system that delivers:

- **83% faster page loads**
- **85% fewer API calls**
- **Eliminated UI blocking**
- **Real-time updates**
- **Comprehensive error handling**
- **Maintainable codebase**

The implementation is designed to be:
- **Incremental**: Each step can be completed independently
- **Testable**: Comprehensive testing at each phase
- **Reversible**: Feature flags allow rollback if needed
- **Compatible**: Works with Tor Browser and existing infrastructure

By following this plan, we will transform the EIOU Docker GUI from a basic PHP application into a modern, performant, maintainable web application that provides an excellent user experience while maintaining privacy and security standards.

---

**Next Steps**:
1. Review and approve this architecture plan
2. Create GitHub issues for each phase
3. Assign implementation tasks
4. Begin Phase 1: Error Handling
5. Track progress against success criteria

**Questions for Review**:
1. Are the performance targets realistic?
2. Is the timeline achievable?
3. Are there any missing requirements?
4. Should we prioritize any steps differently?
5. Are the success criteria measurable?
