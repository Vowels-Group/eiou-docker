/**
 * AJAX Contact Operations
 *
 * Copyright 2025
 * Handles asynchronous contact form submissions without page reload.
 */

/**
 * Toast Notification System
 */
const Toast = {
    /**
     * Show a toast notification
     * @param {string} message - Message to display
     * @param {string} type - Type of toast (success, error, info, warning)
     * @param {number} duration - Duration in milliseconds (default: 4000)
     */
    show: function(message, type = 'info', duration = 4000) {
        // Create toast container if it doesn't exist
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type} toast-enter`;

        // Icon based on type
        const icons = {
            success: '<i class="fas fa-check-circle"></i>',
            error: '<i class="fas fa-exclamation-circle"></i>',
            warning: '<i class="fas fa-exclamation-triangle"></i>',
            info: '<i class="fas fa-info-circle"></i>'
        };

        toast.innerHTML = `
            ${icons[type] || icons.info}
            <span class="toast-message">${message}</span>
            <button class="toast-close" onclick="Toast.close(this.parentElement)">&times;</button>
        `;

        container.appendChild(toast);

        // Trigger enter animation
        setTimeout(() => {
            toast.classList.remove('toast-enter');
        }, 10);

        // Auto-dismiss after duration
        setTimeout(() => {
            Toast.close(toast);
        }, duration);
    },

    /**
     * Close a toast notification
     * @param {HTMLElement} toast - Toast element to close
     */
    close: function(toast) {
        toast.classList.add('toast-exit');
        setTimeout(() => {
            toast.remove();
        }, 300);
    },

    success: function(message, duration) {
        this.show(message, 'success', duration);
    },

    error: function(message, duration) {
        this.show(message, 'error', duration);
    },

    warning: function(message, duration) {
        this.show(message, 'warning', duration);
    },

    info: function(message, duration) {
        this.show(message, 'info', duration);
    }
};

/**
 * Loading Indicator System
 */
const LoadingIndicator = {
    /**
     * Show loading indicator on a button
     * @param {HTMLElement} button - Button element
     * @returns {Object} Original button state for restoration
     */
    showOnButton: function(button) {
        const originalHTML = button.innerHTML;
        const originalDisabled = button.disabled;

        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        return {
            button: button,
            originalHTML: originalHTML,
            originalDisabled: originalDisabled
        };
    },

    /**
     * Hide loading indicator and restore button
     * @param {Object} state - Original button state from showOnButton
     */
    hideOnButton: function(state) {
        state.button.innerHTML = state.originalHTML;
        state.button.disabled = state.originalDisabled;
    },

    /**
     * Show loading overlay on a form
     * @param {HTMLElement} form - Form element
     */
    showOnForm: function(form) {
        let overlay = form.querySelector('.loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = '<div class="spinner"><i class="fas fa-spinner fa-spin"></i></div>';
            form.style.position = 'relative';
            form.appendChild(overlay);
        }
        overlay.style.display = 'flex';
    },

    /**
     * Hide loading overlay on a form
     * @param {HTMLElement} form - Form element
     */
    hideOnForm: function(form) {
        const overlay = form.querySelector('.loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }
};

/**
 * AJAX Contact Form Handler
 */
class AjaxContactForm {
    /**
     * Initialize AJAX form handling
     * @param {string|HTMLElement} formSelector - Form selector or element
     * @param {string} apiEndpoint - API endpoint URL
     */
    constructor(formSelector, apiEndpoint = '/src/gui/api/contactApi.php') {
        this.form = typeof formSelector === 'string'
            ? document.querySelector(formSelector)
            : formSelector;

        if (!this.form) {
            console.error('Contact form not found:', formSelector);
            return;
        }

        this.apiEndpoint = apiEndpoint;
        this.submitButton = this.form.querySelector('button[type="submit"]');

        this.attachEventListener();
    }

    /**
     * Attach form submit event listener
     */
    attachEventListener() {
        this.form.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.handleSubmit();
        });
    }

    /**
     * Handle form submission
     */
    async handleSubmit() {
        // Collect form data
        const formData = new FormData(this.form);
        const data = Object.fromEntries(formData.entries());

        // Show loading indicator
        const buttonState = LoadingIndicator.showOnButton(this.submitButton);

        try {
            // Send AJAX request
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            // Hide loading indicator
            LoadingIndicator.hideOnButton(buttonState);

            // Handle response
            if (result.success) {
                Toast.success(result.message || 'Contact added successfully');
                this.form.reset();

                // Refresh contact list if available
                if (typeof refreshContactList === 'function') {
                    refreshContactList();
                }
            } else {
                Toast.error(result.message || 'Failed to add contact');
            }

        } catch (error) {
            // Hide loading indicator
            LoadingIndicator.hideOnButton(buttonState);

            console.error('AJAX Error:', error);
            Toast.error('Network error: Failed to communicate with server');
        }
    }
}

/**
 * Initialize AJAX contact form when DOM is ready
 */
document.addEventListener('DOMContentLoaded', function() {
    // Initialize add contact form
    const addContactForm = document.querySelector('#add-contact form');
    if (addContactForm) {
        new AjaxContactForm(addContactForm);
        console.log('AJAX contact form initialized');
    }
});

/**
 * Delete contact via AJAX
 * @param {string} contactId - Contact ID to delete
 * @param {string} csrfToken - CSRF token
 */
async function deleteContactAjax(contactId, csrfToken) {
    if (!confirm('Are you sure you want to delete this contact?')) {
        return;
    }

    try {
        const response = await fetch('/src/gui/api/contactApi.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'deleteContact',
                contact_id: contactId,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();

        if (result.success) {
            Toast.success(result.message || 'Contact deleted successfully');

            // Refresh contact list
            if (typeof refreshContactList === 'function') {
                refreshContactList();
            }
        } else {
            Toast.error(result.message || 'Failed to delete contact');
        }

    } catch (error) {
        console.error('Delete Error:', error);
        Toast.error('Network error: Failed to delete contact');
    }
}

/**
 * Refresh contact list via AJAX
 */
async function refreshContactList() {
    try {
        const response = await fetch('/src/gui/api/contactApi.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'listContacts'
            })
        });

        const result = await response.json();

        if (result.success) {
            // Update contact list in DOM
            updateContactListDOM(result.data);
        }

    } catch (error) {
        console.error('Refresh Error:', error);
    }
}

/**
 * Update contact list in DOM
 * @param {Array} contacts - Array of contact objects
 */
function updateContactListDOM(contacts) {
    const contactList = document.querySelector('#contact-list');
    if (!contactList) return;

    // This is a placeholder - implement based on your actual contact list structure
    console.log('Updated contacts:', contacts);

    // Example: Trigger a page section refresh or update the list dynamically
    // You would implement this based on your specific DOM structure
}
