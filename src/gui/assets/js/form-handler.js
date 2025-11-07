// Copyright 2025
// Form Handler with Toast Notifications and Error Handling
// Tor Browser compatible - no external dependencies

(function(window) {
    'use strict';

    /**
     * Enhanced Form Handler
     * Provides standardized form submission with error handling and toast notifications
     */
    const FormHandler = {
        /**
         * Submit form with AJAX and show appropriate toast notifications
         * @param {HTMLFormElement} form - The form element to submit
         * @param {Object} options - Configuration options
         * @returns {Promise<Object>} Response data
         */
        submitForm: async function(form, options) {
            options = options || {};
            const method = options.method || form.method || 'POST';
            const url = options.url || form.action;
            const successMessage = options.successMessage || 'Operation completed successfully';
            const errorContext = options.errorContext || 'submitting form';
            const onSuccess = options.onSuccess;
            const onError = options.onError;

            // Get form data
            const formData = new FormData(form);

            // Show loading state
            const submitButton = form.querySelector('button[type="submit"]');
            const originalButtonText = submitButton ? submitButton.innerHTML : '';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }

            try {
                // Submit form with retry logic
                const response = await window.RetryHandler.retryFetch(
                    () => fetch(url, {
                        method: method,
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }),
                    {
                        maxAttempts: 3,
                        initialDelay: 1000
                    }
                );

                // Check if response is ok
                if (!response.ok) {
                    await window.ErrorHandler.handleError(response, errorContext);
                    if (onError) onError(response);
                    return null;
                }

                // Parse response
                const contentType = response.headers.get('content-type');
                let data;
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    data = { success: true };
                }

                // Show success toast
                window.Toast.success(successMessage);

                // Call success callback
                if (onSuccess) {
                    onSuccess(data);
                }

                // Reset form
                if (options.resetForm !== false) {
                    form.reset();
                }

                return data;

            } catch (error) {
                // Handle error
                await window.ErrorHandler.handleError(error, errorContext);
                if (onError) onError(error);
                return null;

            } finally {
                // Restore button state
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                }
            }
        },

        /**
         * Send transaction with enhanced error handling
         * @param {HTMLFormElement} form - Send transaction form
         * @returns {Promise<void>}
         */
        handleSendTransaction: async function(form) {
            const result = await this.submitForm(form, {
                successMessage: 'Transaction sent successfully!',
                errorContext: 'sending transaction',
                onSuccess: function(data) {
                    // Refresh page to show updated balance and transaction history
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            });

            return result;
        },

        /**
         * Add contact with enhanced error handling
         * @param {HTMLFormElement} form - Add contact form
         * @returns {Promise<void>}
         */
        handleAddContact: async function(form) {
            const result = await this.submitForm(form, {
                successMessage: 'Contact added successfully!',
                errorContext: 'adding contact',
                onSuccess: function(data) {
                    // Refresh page to show new contact
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            });

            return result;
        },

        /**
         * Edit contact with enhanced error handling
         * @param {HTMLFormElement} form - Edit contact form
         * @returns {Promise<void>}
         */
        handleEditContact: async function(form) {
            const result = await this.submitForm(form, {
                successMessage: 'Contact updated successfully!',
                errorContext: 'updating contact',
                onSuccess: function(data) {
                    // Close modal and refresh
                    if (typeof closeEditContactModal === 'function') {
                        closeEditContactModal();
                    }
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            });

            return result;
        },

        /**
         * Delete contact with confirmation
         * @param {string} address - Contact address to delete
         * @param {string} name - Contact name for confirmation
         * @returns {Promise<void>}
         */
        handleDeleteContact: async function(address, name) {
            // Confirm deletion
            if (!confirm('Are you sure you want to delete contact "' + name + '"?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_contact');
            formData.append('address', address);

            try {
                window.Toast.info('Deleting contact...');

                const response = await window.RetryHandler.retryFetch(
                    () => fetch('/api/contacts', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }),
                    { maxAttempts: 3 }
                );

                if (!response.ok) {
                    await window.ErrorHandler.handleError(response, 'deleting contact');
                    return;
                }

                window.Toast.success('Contact deleted successfully!');

                // Refresh page
                setTimeout(() => {
                    window.location.reload();
                }, 1500);

            } catch (error) {
                await window.ErrorHandler.handleError(error, 'deleting contact');
            }
        },

        /**
         * Accept contact request
         * @param {string} address - Contact address
         * @returns {Promise<void>}
         */
        handleAcceptContact: async function(address) {
            const formData = new FormData();
            formData.append('action', 'accept_contact');
            formData.append('address', address);

            try {
                window.Toast.info('Accepting contact request...');

                const response = await window.RetryHandler.retryFetch(
                    () => fetch('/api/contacts', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }),
                    { maxAttempts: 3 }
                );

                if (!response.ok) {
                    await window.ErrorHandler.handleError(response, 'accepting contact');
                    return;
                }

                window.Toast.success('Contact accepted!');

                // Refresh page
                setTimeout(() => {
                    window.location.reload();
                }, 1500);

            } catch (error) {
                await window.ErrorHandler.handleError(error, 'accepting contact');
            }
        },

        /**
         * Reject contact request
         * @param {string} address - Contact address
         * @returns {Promise<void>}
         */
        handleRejectContact: async function(address) {
            // Confirm rejection
            if (!confirm('Are you sure you want to reject this contact request?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'reject_contact');
            formData.append('address', address);

            try {
                window.Toast.info('Rejecting contact request...');

                const response = await window.RetryHandler.retryFetch(
                    () => fetch('/api/contacts', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }),
                    { maxAttempts: 3 }
                );

                if (!response.ok) {
                    await window.ErrorHandler.handleError(response, 'rejecting contact');
                    return;
                }

                window.Toast.success('Contact rejected');

                // Refresh page
                setTimeout(() => {
                    window.location.reload();
                }, 1500);

            } catch (error) {
                await window.ErrorHandler.handleError(error, 'rejecting contact');
            }
        }
    };

    // Export to window
    window.FormHandler = FormHandler;

    // Auto-initialize form handlers when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Send transaction form
        const sendForm = document.getElementById('send-transaction-form');
        if (sendForm) {
            sendForm.addEventListener('submit', function(e) {
                e.preventDefault();
                window.FormHandler.handleSendTransaction(this);
            });
        }

        // Add contact form
        const addContactForm = document.getElementById('add-contact-form');
        if (addContactForm) {
            addContactForm.addEventListener('submit', function(e) {
                e.preventDefault();
                window.FormHandler.handleAddContact(this);
            });
        }

        // Edit contact form
        const editContactForm = document.getElementById('edit-contact-form');
        if (editContactForm) {
            editContactForm.addEventListener('submit', function(e) {
                e.preventDefault();
                window.FormHandler.handleEditContact(this);
            });
        }
    });

})(window);
