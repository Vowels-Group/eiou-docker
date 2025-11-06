// Copyright 2025
// Form AJAX Integration
// Converts synchronous form submissions to async AJAX calls
// Uses the existing EiouAPI and LoadingIndicator utilities

document.addEventListener('DOMContentLoaded', function() {
    /**
     * Initialize Send eIOU form with AJAX
     */
    function initializeSendEIOUForm() {
        const form = document.querySelector('form[action=""][method="POST"]');
        if (!form) return;

        // Check if this is the Send eIOU form
        const actionInput = form.querySelector('input[name="action"][value="sendEIOU"]');
        if (!actionInput) return;

        console.log('Initializing Send eIOU form with AJAX');

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Get form values
            const recipient = form.querySelector('#recipient').value;
            const manualRecipient = form.querySelector('#manual-address').value;
            const amount = form.querySelector('#amount').value;
            const currency = form.querySelector('#currency').value;

            // Validate
            if ((!recipient && !manualRecipient) || !amount || !currency) {
                Toast.show('Please fill in all required fields', 'error');
                return;
            }

            // Get submit button
            const submitBtn = form.querySelector('button[type="submit"]');

            // Show loading
            LoadingIndicator.showButtonLoading(submitBtn, 'Sending...');

            try {
                // Call API
                const result = await EiouAPI.transactions.send(
                    recipient,
                    manualRecipient,
                    amount,
                    currency
                );

                // Hide loading
                LoadingIndicator.hideButtonLoading(submitBtn);

                if (result.success) {
                    // Show success message
                    Toast.show(result.message || 'Transaction sent successfully!', 'success');

                    // Reset form
                    FormUtils.resetForm(form);

                    // Reload page after delay to show updated balance
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error message
                    Toast.show(result.message || 'Failed to send transaction', 'error');
                }
            } catch (error) {
                // Hide loading
                LoadingIndicator.hideButtonLoading(submitBtn);

                // Show error
                Toast.show('An error occurred. Please try again.', 'error');
                console.error('Send eIOU error:', error);
            }
        });
    }

    /**
     * Initialize Add Contact form with AJAX
     */
    function initializeAddContactForm() {
        const forms = document.querySelectorAll('form[action=""][method="POST"]');

        forms.forEach(form => {
            const actionInput = form.querySelector('input[name="action"][value="addContact"]');
            if (!actionInput) return;

            console.log('Initializing Add Contact form with AJAX');

            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                // Get form values
                const address = form.querySelector('#address').value;
                const name = form.querySelector('#name').value;
                const fee = form.querySelector('#fee').value;
                const credit = form.querySelector('#credit').value;
                const currency = form.querySelector('#contactCurrency').value;

                // Validate
                if (!address || !name || !fee || !credit || !currency) {
                    Toast.show('Please fill in all required fields', 'error');
                    return;
                }

                // Get submit button
                const submitBtn = form.querySelector('button[type="submit"]');

                // Show loading
                LoadingIndicator.showButtonLoading(submitBtn, 'Adding...');

                try {
                    // Call API
                    const result = await EiouAPI.contacts.add(
                        address,
                        name,
                        fee,
                        credit,
                        currency
                    );

                    // Hide loading
                    LoadingIndicator.hideButtonLoading(submitBtn);

                    if (result.success) {
                        // Show success message
                        Toast.show(result.message || 'Contact added successfully!', 'success');

                        // Reset form
                        FormUtils.resetForm(form);

                        // Reload page after delay
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        // Show error message
                        Toast.show(result.message || 'Failed to add contact', 'error');
                    }
                } catch (error) {
                    // Hide loading
                    LoadingIndicator.hideButtonLoading(submitBtn);

                    // Show error
                    Toast.show('An error occurred. Please try again.', 'error');
                    console.error('Add contact error:', error);
                }
            });
        });
    }

    /**
     * Initialize Edit Contact form with AJAX
     */
    function initializeEditContactForm() {
        const form = document.getElementById('editContactForm');
        if (!form) return;

        console.log('Initializing Edit Contact form with AJAX');

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Get form values
            const address = form.querySelector('#edit_contact_address').value;
            const name = form.querySelector('#edit_contact_name').value;
            const fee = form.querySelector('#edit_contact_fee').value;
            const credit = form.querySelector('#edit_contact_credit').value;
            const currency = form.querySelector('#edit_contact_currency').value;

            // Validate
            if (!address || !name || !fee || !credit || !currency) {
                Toast.show('Please fill in all required fields', 'error');
                return;
            }

            // Get submit button
            const submitBtn = form.querySelector('button[type="submit"]');

            // Show loading
            LoadingIndicator.showButtonLoading(submitBtn, 'Updating...');

            try {
                // Call API
                const result = await EiouAPI.contacts.edit(
                    address,
                    name,
                    fee,
                    credit,
                    currency
                );

                // Hide loading
                LoadingIndicator.hideButtonLoading(submitBtn);

                if (result.success) {
                    // Show success message
                    Toast.show(result.message || 'Contact updated successfully!', 'success');

                    // Close modal
                    if (typeof closeEditContactModal === 'function') {
                        closeEditContactModal();
                    }

                    // Reload page after delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error message
                    Toast.show(result.message || 'Failed to update contact', 'error');
                }
            } catch (error) {
                // Hide loading
                LoadingIndicator.hideButtonLoading(submitBtn);

                // Show error
                Toast.show('An error occurred. Please try again.', 'error');
                console.error('Edit contact error:', error);
            }
        });
    }

    /**
     * Initialize contact action buttons (accept, delete, block, unblock)
     */
    function initializeContactActionButtons() {
        // Accept contact buttons
        document.querySelectorAll('form[action=""][method="POST"]').forEach(form => {
            const actionInput = form.querySelector('input[name="action"][value="acceptContact"]');
            if (!actionInput) return;

            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                const address = form.querySelector('input[name="contact_address"]').value;
                const name = form.querySelector('input[name="contact_name"]').value;
                const fee = form.querySelector('input[name="contact_fee"]').value;
                const credit = form.querySelector('input[name="contact_credit"]').value;
                const currency = form.querySelector('input[name="contact_currency"]').value;

                // Get submit button
                const submitBtn = form.querySelector('button[type="submit"]');

                // Show loading
                LoadingIndicator.showButtonLoading(submitBtn, 'Accepting...');

                try {
                    const result = await EiouAPI.contacts.accept(address, name, fee, credit, currency);

                    LoadingIndicator.hideButtonLoading(submitBtn);

                    if (result.success) {
                        Toast.show(result.message || 'Contact accepted!', 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        Toast.show(result.message || 'Failed to accept contact', 'error');
                    }
                } catch (error) {
                    LoadingIndicator.hideButtonLoading(submitBtn);
                    Toast.show('An error occurred. Please try again.', 'error');
                    console.error('Accept contact error:', error);
                }
            });
        });

        // Delete contact buttons
        document.querySelectorAll('form[action=""][method="POST"]').forEach(form => {
            const actionInput = form.querySelector('input[name="action"][value="deleteContact"]');
            if (!actionInput) return;

            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to delete this contact?')) {
                    return;
                }

                const address = form.querySelector('input[name="contact_address"]').value;
                const submitBtn = form.querySelector('button[type="submit"]');

                LoadingIndicator.showButtonLoading(submitBtn, 'Deleting...');

                try {
                    const result = await EiouAPI.contacts.delete(address);

                    LoadingIndicator.hideButtonLoading(submitBtn);

                    if (result.success) {
                        Toast.show(result.message || 'Contact deleted!', 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        Toast.show(result.message || 'Failed to delete contact', 'error');
                    }
                } catch (error) {
                    LoadingIndicator.hideButtonLoading(submitBtn);
                    Toast.show('An error occurred. Please try again.', 'error');
                    console.error('Delete contact error:', error);
                }
            });
        });

        // Block contact buttons
        document.querySelectorAll('form[action=""][method="POST"]').forEach(form => {
            const actionInput = form.querySelector('input[name="action"][value="blockContact"]');
            if (!actionInput) return;

            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                const address = form.querySelector('input[name="contact_address"]').value;
                const submitBtn = form.querySelector('button[type="submit"]');

                LoadingIndicator.showButtonLoading(submitBtn, 'Blocking...');

                try {
                    const result = await EiouAPI.contacts.block(address);

                    LoadingIndicator.hideButtonLoading(submitBtn);

                    if (result.success) {
                        Toast.show(result.message || 'Contact blocked!', 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        Toast.show(result.message || 'Failed to block contact', 'error');
                    }
                } catch (error) {
                    LoadingIndicator.hideButtonLoading(submitBtn);
                    Toast.show('An error occurred. Please try again.', 'error');
                    console.error('Block contact error:', error);
                }
            });
        });

        // Unblock contact buttons
        document.querySelectorAll('form[action=""][method="POST"]').forEach(form => {
            const actionInput = form.querySelector('input[name="action"][value="unblockContact"]');
            if (!actionInput) return;

            form.addEventListener('submit', async function(e) {
                e.preventDefault();

                const address = form.querySelector('input[name="contact_address"]').value;
                const submitBtn = form.querySelector('button[type="submit"]');

                LoadingIndicator.showButtonLoading(submitBtn, 'Unblocking...');

                try {
                    const result = await EiouAPI.contacts.unblock(address);

                    LoadingIndicator.hideButtonLoading(submitBtn);

                    if (result.success) {
                        Toast.show(result.message || 'Contact unblocked!', 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        Toast.show(result.message || 'Failed to unblock contact', 'error');
                    }
                } catch (error) {
                    LoadingIndicator.hideButtonLoading(submitBtn);
                    Toast.show('An error occurred. Please try again.', 'error');
                    console.error('Unblock contact error:', error);
                }
            });
        });
    }

    // Initialize all forms
    initializeSendEIOUForm();
    initializeAddContactForm();
    initializeEditContactForm();
    initializeContactActionButtons();

    console.log('AJAX form integration initialized');
});
