   // Copyright 2025
    
    // Simple script to show/hide the floating action button
    // This is minimal JavaScript that should work in Tor Browser
    window.addEventListener('scroll', function() {
        const fab = document.getElementById('backToTop');
        if (window.pageYOffset > 300) {
            fab.classList.remove('hidden');
        } else {
            fab.classList.add('hidden');
        }
    });

    // Hide FAB initially
    document.addEventListener('DOMContentLoaded', function() {
        const fab = document.getElementById('backToTop');
        fab.classList.add('hidden');
    });

// Manual refresh function (Tor Browser compatible)
function refreshWalletData() {
    const refreshBtn = document.getElementById('manualRefresh');
    const icon = refreshBtn.querySelector('i');
    
    // Show loading state
    icon.className = 'fas fa-spinner fa-spin';
    refreshBtn.disabled = true;
    
    // Preserve auth code when refreshing
    const url = new URL(window.location.href);
    window.location.href = url.toString();
}

// Send eIOU form handling
function initializeSendForm() {
    const recipientSelect = document.getElementById('recipient');
    const manualAddressGroup = document.getElementById('manual-address-group');
    const manualAddressInput = document.getElementById('manual-address');
    const transactionTypeIndicator = document.getElementById('transaction-type-indicator');
    const transactionTypeText = document.getElementById('transaction-type-text');
    const addressTypeGroup = document.getElementById('address-type-group');
    const addressTypeSelect = document.getElementById('address-type');

    // Set initial state - manual address is visible by default
    if (manualAddressInput) manualAddressInput.required = true;
    if (recipientSelect) recipientSelect.required = false;
    if (transactionTypeIndicator) {
        transactionTypeIndicator.style.display = 'block';
        transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
        transactionTypeText.style.color = '#ffc107';
    }

    if (recipientSelect) {
        recipientSelect.addEventListener('change', function() {
            const selectedValue = this.value;
            const selectedOption = this.options[this.selectedIndex];

            if (selectedValue === '') {
                // Show manual address input (default state)
                manualAddressGroup.style.display = 'block';
                manualAddressInput.required = true;
                recipientSelect.required = false;
                addressTypeGroup.style.display = 'none';
                if (addressTypeSelect) addressTypeSelect.required = false;
                transactionTypeIndicator.style.display = 'block';
                transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
                transactionTypeText.style.color = '#ffc107';
            } else {
                // Contact selected - show address type selector, hide manual input
                manualAddressGroup.style.display = 'none';
                manualAddressInput.required = false;
                manualAddressInput.value = '';
                recipientSelect.required = true;

                // Get available addresses from data attributes
                const httpAddr = selectedOption.getAttribute('data-http');
                const torAddr = selectedOption.getAttribute('data-tor');

                // Populate address type dropdown
                addressTypeSelect.innerHTML = '<option value="">Select address type</option>';

                if (torAddr) {
                    const truncatedTor = torAddr.length > 30 ? torAddr.substring(0, 30) + '...' : torAddr;
                    addressTypeSelect.innerHTML += '<option value="tor">Tor (' + truncatedTor + ')</option>';
                }
                if (httpAddr) {
                    const truncatedHttp = httpAddr.length > 30 ? httpAddr.substring(0, 30) + '...' : httpAddr;
                    addressTypeSelect.innerHTML += '<option value="http">HTTP (' + truncatedHttp + ')</option>';
                }

                // Show address type selector if at least one address available
                if (torAddr || httpAddr) {
                    addressTypeGroup.style.display = 'block';
                    addressTypeSelect.required = true;

                    // Auto-select if only one address available
                    if (torAddr && !httpAddr) {
                        addressTypeSelect.value = 'tor';
                    } else if (httpAddr && !torAddr) {
                        addressTypeSelect.value = 'http';
                    }
                } else {
                    addressTypeGroup.style.display = 'none';
                    addressTypeSelect.required = false;
                }

                transactionTypeIndicator.style.display = 'block';
                transactionTypeText.textContent = 'Direct Transaction (to contact)';
                transactionTypeText.style.color = '#28a745';
            }
        });
    }

    // Handle manual address input
    if (manualAddressInput) {
        manualAddressInput.addEventListener('input', function() {
            const address = this.value.trim();
            if (address) {
                transactionTypeIndicator.style.display = 'block';
                if (address.includes('.onion') || address.startsWith('http')) {
                    transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
                    transactionTypeText.style.color = '#ffc107';
                } else {
                    transactionTypeText.textContent = 'P2P Transaction (address format detected)';
                    transactionTypeText.style.color = '#ffc107';
                }
            } else {
                transactionTypeIndicator.style.display = 'none';
            }
        });
    }
}

// Initialize send form when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeSendForm();
});

// Edit contact modal functions
function openEditContactModal(address, name, fee, credit, currency) {
    // Populate the form fields
    document.getElementById('edit_contact_address').value = address;
    document.getElementById('edit_contact_name').value = name;
    document.getElementById('edit_contact_fee').value = fee;
    document.getElementById('edit_contact_credit').value = credit;
    document.getElementById('edit_contact_currency').value = currency;

    // Show the modal
    document.getElementById('editContactModal').style.display = 'flex';
}

function closeEditContactModal() {
    document.getElementById('editContactModal').style.display = 'none';
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('editContactModal');
    if (event.target === modal) {
        closeEditContactModal();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditContactModal();
    }
});