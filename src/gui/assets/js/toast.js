// Copyright 2025
// Toast Notification System
// Tor Browser compatible - no external dependencies

(function(window) {
    'use strict';

    /**
     * Toast Notification Manager
     * Provides success, error, warning, and info notifications
     * Features:
     * - Auto-dismiss after configurable timeout (default 5s)
     * - Stack multiple toasts vertically
     * - Click to dismiss
     * - Pause on hover
     * - No external dependencies
     * - Tor Browser compatible
     */
    const ToastManager = {
        container: null,
        toasts: [],
        maxToasts: 5,
        defaultTimeout: 5000,

        /**
         * Initialize the toast container
         */
        init: function() {
            if (this.container) return; // Already initialized

            // Create container for toasts
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        },

        /**
         * Show a toast notification
         * @param {string} message - The message to display
         * @param {string} type - Toast type: 'success', 'error', 'warning', 'info'
         * @param {number} timeout - Auto-dismiss timeout in ms (0 = no auto-dismiss)
         * @returns {HTMLElement} The toast element
         */
        show: function(message, type, timeout) {
            this.init();

            type = type || 'info';
            timeout = typeof timeout !== 'undefined' ? timeout : this.defaultTimeout;

            // Create toast element
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + type;

            // Get icon based on type
            const icon = this.getIcon(type);

            // Create toast content
            toast.innerHTML = `
                <div class="toast-icon">${icon}</div>
                <div class="toast-message">${this.escapeHtml(message)}</div>
                <button class="toast-close" aria-label="Close">&times;</button>
            `;

            // Add to container
            this.container.appendChild(toast);
            this.toasts.push(toast);

            // Trigger animation (add after DOM insertion for CSS transition)
            setTimeout(() => {
                toast.classList.add('toast-show');
            }, 10);

            // Set up close button
            const closeBtn = toast.querySelector('.toast-close');
            closeBtn.addEventListener('click', () => {
                this.dismiss(toast);
            });

            // Click anywhere on toast to dismiss
            toast.addEventListener('click', (e) => {
                if (e.target !== closeBtn) {
                    this.dismiss(toast);
                }
            });

            // Auto-dismiss after timeout
            if (timeout > 0) {
                let timeoutId = setTimeout(() => {
                    this.dismiss(toast);
                }, timeout);

                // Pause on hover
                toast.addEventListener('mouseenter', () => {
                    clearTimeout(timeoutId);
                });

                toast.addEventListener('mouseleave', () => {
                    timeoutId = setTimeout(() => {
                        this.dismiss(toast);
                    }, timeout);
                });
            }

            // Remove oldest toast if we exceed max
            if (this.toasts.length > this.maxToasts) {
                this.dismiss(this.toasts[0]);
            }

            return toast;
        },

        /**
         * Dismiss a toast
         * @param {HTMLElement} toast - The toast element to dismiss
         */
        dismiss: function(toast) {
            if (!toast || !toast.parentNode) return;

            // Fade out animation
            toast.classList.remove('toast-show');
            toast.classList.add('toast-hide');

            // Remove from DOM after animation
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }

                // Remove from tracking array
                const index = this.toasts.indexOf(toast);
                if (index > -1) {
                    this.toasts.splice(index, 1);
                }
            }, 300); // Match CSS animation duration
        },

        /**
         * Get icon HTML for toast type
         * @param {string} type - Toast type
         * @returns {string} SVG icon HTML
         */
        getIcon: function(type) {
            const icons = {
                success: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                error: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                warning: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                info: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
            };
            return icons[type] || icons.info;
        },

        /**
         * Escape HTML to prevent XSS
         * @param {string} text - Text to escape
         * @returns {string} Escaped text
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Show success toast
         * @param {string} message - Success message
         * @param {number} timeout - Optional timeout
         */
        success: function(message, timeout) {
            return this.show(message, 'success', timeout);
        },

        /**
         * Show error toast
         * @param {string} message - Error message
         * @param {number} timeout - Optional timeout (0 for persistent)
         */
        error: function(message, timeout) {
            // Errors stay longer by default (8 seconds)
            timeout = typeof timeout !== 'undefined' ? timeout : 8000;
            return this.show(message, 'error', timeout);
        },

        /**
         * Show warning toast
         * @param {string} message - Warning message
         * @param {number} timeout - Optional timeout
         */
        warning: function(message, timeout) {
            return this.show(message, 'warning', timeout);
        },

        /**
         * Show info toast
         * @param {string} message - Info message
         * @param {number} timeout - Optional timeout
         */
        info: function(message, timeout) {
            return this.show(message, 'info', timeout);
        },

        /**
         * Clear all toasts
         */
        clearAll: function() {
            const toastsCopy = this.toasts.slice();
            toastsCopy.forEach(toast => {
                this.dismiss(toast);
            });
        }
    };

    // Export to window
    window.Toast = ToastManager;

})(window);

/**
 * Error Handler Utility
 * Provides standardized error handling and user-friendly error messages
 */
(function(window) {
    'use strict';

    const ErrorHandler = {
        /**
         * Parse error response from API
         * @param {Response} response - Fetch response object
         * @returns {Promise<Object>} Error details
         */
        parseErrorResponse: async function(response) {
            let errorData = {
                status: response.status,
                statusText: response.statusText,
                message: 'An unexpected error occurred',
                details: null
            };

            try {
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const json = await response.json();
                    errorData.message = json.error || json.message || errorData.message;
                    errorData.details = json.details || null;
                } else {
                    const text = await response.text();
                    if (text) {
                        errorData.message = text.substring(0, 200); // Limit length
                    }
                }
            } catch (parseError) {
                console.error('Error parsing error response:', parseError);
            }

            return errorData;
        },

        /**
         * Get user-friendly error message
         * @param {number} status - HTTP status code
         * @param {string} message - Original error message
         * @returns {string} User-friendly message
         */
        getUserFriendlyMessage: function(status, message) {
            const friendlyMessages = {
                400: 'Invalid request. Please check your input and try again.',
                401: 'Authentication required. Please log in.',
                403: 'Access denied. You do not have permission to perform this action.',
                404: 'Resource not found. The requested item does not exist.',
                408: 'Request timeout. Please check your connection and try again.',
                429: 'Too many requests. Please wait a moment and try again.',
                500: 'Server error. Please try again later.',
                502: 'Gateway error. The server is temporarily unavailable.',
                503: 'Service unavailable. Please try again later.',
                504: 'Gateway timeout. The request took too long to complete.'
            };

            // Return specific message if available
            if (friendlyMessages[status]) {
                return friendlyMessages[status];
            }

            // Return original message or generic error
            return message || 'An error occurred. Please try again.';
        },

        /**
         * Handle fetch error with toast notification
         * @param {Error|Response} error - Error object or response
         * @param {string} context - Context of the error (e.g., "sending transaction")
         * @returns {Promise<void>}
         */
        handleError: async function(error, context) {
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

            window.Toast.error(message);
            console.error('Error:', error);
        },

        /**
         * Check if error is transient (retryable)
         * @param {Response|Error} error - Error to check
         * @returns {boolean} True if error is transient
         */
        isTransientError: function(error) {
            if (error instanceof Response) {
                // Network errors and server errors are retryable
                const retryableStatuses = [408, 429, 500, 502, 503, 504];
                return retryableStatuses.includes(error.status);
            }

            if (error instanceof Error) {
                // Network errors
                const networkErrors = ['NetworkError', 'TimeoutError', 'AbortError'];
                return networkErrors.some(type => error.name.includes(type));
            }

            return false;
        }
    };

    window.ErrorHandler = ErrorHandler;

})(window);

/**
 * Retry Logic Utility
 * Provides exponential backoff retry mechanism for transient errors
 */
(function(window) {
    'use strict';

    const RetryHandler = {
        /**
         * Retry a fetch request with exponential backoff
         * @param {Function} fetchFn - Function that returns a fetch promise
         * @param {Object} options - Retry options
         * @returns {Promise<Response>} Fetch response
         */
        retryFetch: async function(fetchFn, options) {
            options = options || {};
            const maxAttempts = options.maxAttempts || 3;
            const initialDelay = options.initialDelay || 1000; // 1 second
            const maxDelay = options.maxDelay || 10000; // 10 seconds
            const backoffMultiplier = options.backoffMultiplier || 2;

            let lastError;

            for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                try {
                    const response = await fetchFn();

                    // Check if response is ok
                    if (response.ok) {
                        return response;
                    }

                    // Check if error is retryable
                    if (!window.ErrorHandler.isTransientError(response)) {
                        throw response;
                    }

                    lastError = response;

                } catch (error) {
                    lastError = error;

                    // Don't retry if not a transient error
                    if (!window.ErrorHandler.isTransientError(error)) {
                        throw error;
                    }
                }

                // Don't wait after last attempt
                if (attempt < maxAttempts) {
                    // Calculate delay with exponential backoff
                    const delay = Math.min(
                        initialDelay * Math.pow(backoffMultiplier, attempt - 1),
                        maxDelay
                    );

                    // Show retry notification
                    if (attempt > 1) {
                        window.Toast.warning(
                            'Request failed. Retrying (attempt ' + attempt + '/' + maxAttempts + ')...',
                            delay
                        );
                    }

                    // Wait before retry
                    await this.sleep(delay);
                }
            }

            // All attempts failed
            throw lastError;
        },

        /**
         * Sleep for specified duration
         * @param {number} ms - Milliseconds to sleep
         * @returns {Promise<void>}
         */
        sleep: function(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    };

    window.RetryHandler = RetryHandler;

})(window);
