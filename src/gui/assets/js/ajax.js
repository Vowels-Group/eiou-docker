// Copyright 2025
// AJAX Client Library for eIOU GUI
// Provides async operations without page reloads

/**
 * EiouAPI - Main API client
 */
const EiouAPI = {
    /**
     * Base path for API endpoints
     */
    basePath: '/etc/eiou/src/gui/api',

    /**
     * Get CSRF token from the page
     */
    getCsrfToken: function() {
        const tokenInput = document.querySelector('input[name="csrf_token"]');
        return tokenInput ? tokenInput.value : '';
    },

    /**
     * Generic API call function
     */
    call: async function(endpoint, data = {}, method = 'POST') {
        try {
            // Add CSRF token for POST requests
            if (method === 'POST') {
                data.csrf_token = this.getCsrfToken();
            }

            // Prepare request
            const options = {
                method: method,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };

            let url = this.basePath + endpoint;

            if (method === 'POST') {
                // Form-encoded data for POST
                const formData = new FormData();
                for (const key in data) {
                    formData.append(key, data[key]);
                }
                options.body = formData;
            } else if (method === 'GET' && Object.keys(data).length > 0) {
                // Query string for GET
                const params = new URLSearchParams(data);
                url += '?' + params.toString();
            }

            // Make request
            const response = await fetch(url, options);

            // Parse JSON response
            const result = await response.json();

            // Return result
            return result;
        } catch (error) {
            // Handle network or parsing errors
            return {
                success: false,
                message: 'Network error or invalid response',
                error: error.message
            };
        }
    },

    /**
     * Contact API methods
     */
    contacts: {
        add: async function(address, name, fee, credit, currency) {
            return await EiouAPI.call('/contacts.php', {
                action: 'add',
                address: address,
                name: name,
                fee: fee,
                credit: credit,
                currency: currency
            });
        },

        edit: async function(address, name, fee, credit, currency) {
            return await EiouAPI.call('/contacts.php', {
                action: 'edit',
                contact_address: address,
                contact_name: name,
                contact_fee: fee,
                contact_credit: credit,
                contact_currency: currency
            });
        },

        delete: async function(address) {
            return await EiouAPI.call('/contacts.php', {
                action: 'delete',
                contact_address: address
            });
        },

        block: async function(address) {
            return await EiouAPI.call('/contacts.php', {
                action: 'block',
                contact_address: address
            });
        },

        unblock: async function(address) {
            return await EiouAPI.call('/contacts.php', {
                action: 'unblock',
                contact_address: address
            });
        },

        accept: async function(address, name, fee, credit, currency) {
            return await EiouAPI.call('/contacts.php', {
                action: 'accept',
                contact_address: address,
                contact_name: name,
                contact_fee: fee,
                contact_credit: credit,
                contact_currency: currency
            });
        },

        list: async function() {
            return await EiouAPI.call('/contacts.php', {
                action: 'list'
            }, 'GET');
        }
    },

    /**
     * Transaction API methods
     */
    transactions: {
        send: async function(recipient, manualRecipient, amount, currency) {
            return await EiouAPI.call('/transactions.php', {
                action: 'send',
                recipient: recipient,
                manual_recipient: manualRecipient,
                amount: amount,
                currency: currency
            });
        },

        getBalance: async function() {
            return await EiouAPI.call('/transactions.php', {
                action: 'getBalance'
            }, 'GET');
        },

        getHistory: async function(limit = 10) {
            return await EiouAPI.call('/transactions.php', {
                action: 'getHistory',
                limit: limit
            }, 'GET');
        }
    }
};

/**
 * Loading indicator utilities
 */
const LoadingIndicator = {
    /**
     * Show loading state on a button
     */
    showButtonLoading: function(button, text = 'Processing...') {
        if (!button) return;

        // Store original content
        button.dataset.originalContent = button.innerHTML;
        button.disabled = true;

        // Show loading spinner
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + text;
    },

    /**
     * Hide loading state on a button
     */
    hideButtonLoading: function(button) {
        if (!button) return;

        // Restore original content
        if (button.dataset.originalContent) {
            button.innerHTML = button.dataset.originalContent;
            delete button.dataset.originalContent;
        }

        button.disabled = false;
    },

    /**
     * Show loading overlay
     */
    showOverlay: function(container, text = 'Loading...') {
        const overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.innerHTML = `
            <div class="loading-spinner">
                <i class="fas fa-spinner fa-spin fa-3x"></i>
                <p>${text}</p>
            </div>
        `;
        overlay.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        `;

        if (container) {
            container.style.position = 'relative';
            container.appendChild(overlay);
        }
        return overlay;
    },

    /**
     * Hide loading overlay
     */
    hideOverlay: function(overlay) {
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
    }
};

/**
 * Form utilities
 */
const FormUtils = {
    /**
     * Get form data as object
     */
    getFormData: function(form) {
        const formData = new FormData(form);
        const data = {};

        for (const [key, value] of formData.entries()) {
            data[key] = value;
        }

        return data;
    },

    /**
     * Reset form and clear validation
     */
    resetForm: function(form) {
        if (!form) return;

        form.reset();

        // Clear validation messages
        const errorElements = form.querySelectorAll('.error-message');
        errorElements.forEach(el => el.remove());

        // Remove error classes
        const invalidInputs = form.querySelectorAll('.is-invalid');
        invalidInputs.forEach(el => el.classList.remove('is-invalid'));
    },

    /**
     * Show field error
     */
    showFieldError: function(field, message) {
        if (!field) return;

        // Remove existing error
        this.clearFieldError(field);

        // Add error class
        field.classList.add('is-invalid');

        // Create error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message text-danger small mt-1';
        errorDiv.textContent = message;

        // Insert after field
        field.parentNode.insertBefore(errorDiv, field.nextSibling);
    },

    /**
     * Clear field error
     */
    clearFieldError: function(field) {
        if (!field) return;

        field.classList.remove('is-invalid');

        const nextElement = field.nextSibling;
        if (nextElement && nextElement.classList && nextElement.classList.contains('error-message')) {
            nextElement.remove();
        }
    }
};
