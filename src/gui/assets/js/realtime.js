/**
 * Real-time Updates via Server-Sent Events (SSE)
 *
 * Copyright 2025
 * Provides real-time wallet updates using SSE with automatic reconnection.
 * Compatible with Tor Browser and privacy-focused environments.
 *
 * Features:
 * - Automatic reconnection with exponential backoff
 * - Event handlers for different event types
 * - Graceful degradation to polling if SSE unavailable
 * - Connection status monitoring
 * - UI updates without page refresh
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        SSE_ENDPOINT: '/api/events.php',
        RECONNECT_BASE_DELAY: 1000,      // Start with 1 second
        RECONNECT_MAX_DELAY: 30000,      // Max 30 seconds
        RECONNECT_MULTIPLIER: 1.5,       // Exponential backoff multiplier
        MAX_RECONNECT_ATTEMPTS: 10,      // Stop after 10 failed attempts
        FALLBACK_POLL_INTERVAL: 10000,   // Fallback to polling every 10s
        CONNECTION_TIMEOUT: 60000        // Consider connection dead after 60s without heartbeat
    };

    // State
    let eventSource = null;
    let reconnectAttempts = 0;
    let reconnectTimeout = null;
    let lastHeartbeat = Date.now();
    let connectionStatus = 'disconnected';
    let heartbeatCheckInterval = null;
    let useFallback = false;

    /**
     * Initialize real-time updates
     */
    function initializeRealtime() {
        // Check if EventSource is supported (should work in Tor Browser)
        if (typeof EventSource !== 'undefined') {
            console.log('SSE: EventSource supported, initializing...');
            connectSSE();
        } else {
            console.log('SSE: EventSource not supported, using fallback polling');
            useFallback = true;
            startFallbackPolling();
        }

        // Start heartbeat monitoring
        startHeartbeatMonitoring();

        // Update connection status UI
        updateConnectionStatus();
    }

    /**
     * Connect to SSE endpoint
     */
    function connectSSE() {
        if (eventSource && eventSource.readyState !== EventSource.CLOSED) {
            console.log('SSE: Already connected');
            return;
        }

        console.log('SSE: Connecting to ' + CONFIG.SSE_ENDPOINT);

        try {
            // Get auth code from URL if present
            const urlParams = new URLSearchParams(window.location.search);
            const authCode = urlParams.get('authcode') || '';

            // Build SSE URL with auth
            let sseUrl = CONFIG.SSE_ENDPOINT;
            if (authCode) {
                sseUrl += '?authcode=' + encodeURIComponent(authCode);
            }

            eventSource = new EventSource(sseUrl);

            // Connection opened
            eventSource.addEventListener('open', function() {
                console.log('SSE: Connection opened');
                connectionStatus = 'connected';
                reconnectAttempts = 0;
                lastHeartbeat = Date.now();
                updateConnectionStatus();
            });

            // Connection error
            eventSource.addEventListener('error', function(e) {
                console.error('SSE: Connection error', e);
                connectionStatus = 'error';
                updateConnectionStatus();

                // Close and reconnect
                if (eventSource) {
                    eventSource.close();
                    eventSource = null;
                }

                scheduleReconnect();
            });

            // Event listeners
            setupEventListeners();

        } catch (error) {
            console.error('SSE: Failed to create EventSource', error);
            useFallback = true;
            startFallbackPolling();
        }
    }

    /**
     * Setup event listeners for different event types
     */
    function setupEventListeners() {
        if (!eventSource) return;

        // Connected event
        eventSource.addEventListener('connected', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: Connected', data);
            showNotification('Real-time updates enabled', 'success');
        });

        // Heartbeat event
        eventSource.addEventListener('heartbeat', function(e) {
            lastHeartbeat = Date.now();
            // Silent heartbeat, no UI update needed
        });

        // Balance update event
        eventSource.addEventListener('balance_update', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: Balance update', data);
            handleBalanceUpdate(data);
        });

        // New transaction event
        eventSource.addEventListener('transaction_new', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: New transaction', data);
            handleNewTransaction(data);
        });

        // Transaction update event
        eventSource.addEventListener('transaction_update', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: Transaction update', data);
            handleTransactionUpdate(data);
        });

        // Status change event
        eventSource.addEventListener('status_change', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: Status change', data);
            handleStatusChange(data);
        });

        // Contact update event
        eventSource.addEventListener('contact_update', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: Contact update', data);
            handleContactUpdate(data);
        });

        // Reconnect request from server
        eventSource.addEventListener('reconnect', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: Reconnect requested', data);
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
            scheduleReconnect();
        });

        // Connection close event
        eventSource.addEventListener('close', function(e) {
            const data = JSON.parse(e.data);
            console.log('SSE: Connection closed', data);
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
            scheduleReconnect();
        });
    }

    /**
     * Schedule reconnection with exponential backoff
     */
    function scheduleReconnect() {
        if (reconnectTimeout) {
            clearTimeout(reconnectTimeout);
        }

        if (reconnectAttempts >= CONFIG.MAX_RECONNECT_ATTEMPTS) {
            console.log('SSE: Max reconnect attempts reached, falling back to polling');
            useFallback = true;
            startFallbackPolling();
            return;
        }

        reconnectAttempts++;
        const delay = Math.min(
            CONFIG.RECONNECT_BASE_DELAY * Math.pow(CONFIG.RECONNECT_MULTIPLIER, reconnectAttempts - 1),
            CONFIG.RECONNECT_MAX_DELAY
        );

        console.log('SSE: Scheduling reconnect in ' + delay + 'ms (attempt ' + reconnectAttempts + ')');
        connectionStatus = 'reconnecting';
        updateConnectionStatus();

        reconnectTimeout = setTimeout(function() {
            connectSSE();
        }, delay);
    }

    /**
     * Start heartbeat monitoring
     */
    function startHeartbeatMonitoring() {
        if (heartbeatCheckInterval) {
            clearInterval(heartbeatCheckInterval);
        }

        heartbeatCheckInterval = setInterval(function() {
            if (useFallback) return;

            const timeSinceHeartbeat = Date.now() - lastHeartbeat;
            if (timeSinceHeartbeat > CONFIG.CONNECTION_TIMEOUT) {
                console.log('SSE: Heartbeat timeout, reconnecting');
                if (eventSource) {
                    eventSource.close();
                    eventSource = null;
                }
                scheduleReconnect();
            }
        }, 10000); // Check every 10 seconds
    }

    /**
     * Start fallback polling mechanism
     */
    function startFallbackPolling() {
        console.log('SSE: Starting fallback polling');
        connectionStatus = 'polling';
        updateConnectionStatus();

        // Use existing polling mechanism from script.js
        if (typeof initializePolling === 'function') {
            initializePolling();
        }
    }

    /**
     * Handle balance update event
     */
    function handleBalanceUpdate(data) {
        showNotification(
            'Balance updated: ' + formatCurrency(data.change),
            data.change > 0 ? 'success' : 'info'
        );

        // Update balance display
        const balanceElement = document.getElementById('wallet-balance');
        if (balanceElement) {
            balanceElement.textContent = formatCurrency(data.new_balance);

            // Add animation
            balanceElement.classList.add('balance-updated');
            setTimeout(function() {
                balanceElement.classList.remove('balance-updated');
            }, 1000);
        }

        // Optionally reload page to show all changes
        setTimeout(function() {
            if (confirm('Balance updated. Reload page to see details?')) {
                window.location.reload();
            }
        }, 2000);
    }

    /**
     * Handle new transaction event
     */
    function handleNewTransaction(data) {
        const message = data.count > 1
            ? data.count + ' new transactions received'
            : 'New transaction received';

        showNotification(message, 'info');

        // Reload page after short delay
        setTimeout(function() {
            window.location.reload();
        }, 2000);
    }

    /**
     * Handle transaction update event
     */
    function handleTransactionUpdate(data) {
        showNotification('Transaction status updated', 'info');

        // Reload transactions list
        setTimeout(function() {
            window.location.reload();
        }, 2000);
    }

    /**
     * Handle status change event
     */
    function handleStatusChange(data) {
        showNotification(
            'Status changed: ' + data.new_status,
            'warning'
        );
    }

    /**
     * Handle contact update event
     */
    function handleContactUpdate(data) {
        showNotification(
            'Contact updated: ' + data.name,
            'info'
        );
    }

    /**
     * Update connection status UI
     */
    function updateConnectionStatus() {
        const statusElement = document.getElementById('realtime-status');
        if (!statusElement) return;

        let statusHTML = '';
        let statusColor = '';

        switch (connectionStatus) {
            case 'connected':
                statusHTML = '<i class="fas fa-circle"></i> Real-time updates active';
                statusColor = '#28a745';
                break;
            case 'reconnecting':
                statusHTML = '<i class="fas fa-circle"></i> Reconnecting...';
                statusColor = '#ffc107';
                break;
            case 'polling':
                statusHTML = '<i class="fas fa-sync-alt"></i> Polling mode (SSE unavailable)';
                statusColor = '#17a2b8';
                break;
            case 'error':
                statusHTML = '<i class="fas fa-exclamation-circle"></i> Connection error';
                statusColor = '#dc3545';
                break;
            default:
                statusHTML = '<i class="fas fa-circle"></i> Connecting...';
                statusColor = '#6c757d';
        }

        statusElement.innerHTML = statusHTML;
        statusElement.style.color = statusColor;
    }

    /**
     * Show notification to user
     */
    function showNotification(message, type) {
        // Check if notification function exists from script.js
        if (typeof showUpdateNotification === 'function') {
            showUpdateNotification();
            return;
        }

        // Create simple notification
        const notification = document.createElement('div');
        notification.className = 'realtime-notification realtime-notification-' + type;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#28a745' : type === 'warning' ? '#ffc107' : '#0d6efd'};
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 4px;
            z-index: 10000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            animation: slideInRight 0.3s ease-out;
            max-width: 300px;
        `;
        notification.textContent = message;
        document.body.appendChild(notification);

        // Remove after 4 seconds
        setTimeout(function() {
            notification.style.animation = 'slideOutRight 0.3s ease-out';
            setTimeout(function() {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 4000);
    }

    /**
     * Format currency value
     */
    function formatCurrency(value) {
        const num = parseFloat(value);
        if (isNaN(num)) return '0.00';

        const sign = num >= 0 ? '+' : '';
        return sign + num.toFixed(2);
    }

    /**
     * Disconnect SSE (for cleanup)
     */
    function disconnect() {
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }

        if (reconnectTimeout) {
            clearTimeout(reconnectTimeout);
            reconnectTimeout = null;
        }

        if (heartbeatCheckInterval) {
            clearInterval(heartbeatCheckInterval);
            heartbeatCheckInterval = null;
        }

        connectionStatus = 'disconnected';
        updateConnectionStatus();
    }

    // Public API
    window.RealtimeUpdates = {
        init: initializeRealtime,
        disconnect: disconnect,
        getStatus: function() {
            return connectionStatus;
        }
    };

    // Auto-initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeRealtime);
    } else {
        initializeRealtime();
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', disconnect);

})();
