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

// Transaction detail modal functions
function openTransactionModal(index) {
    if (typeof transactionData === 'undefined' || !transactionData[index]) {
        return;
    }

    var tx = transactionData[index];
    var modal = document.getElementById('transactionModal');
    var content = document.getElementById('tx-modal-content');

    // Build status badge HTML
    var statusClass = 'tx-status-' + tx.status;
    var statusBadge = '<span class="tx-status-badge ' + statusClass + '">' + tx.status.charAt(0).toUpperCase() + tx.status.slice(1) + '</span>';

    // Build transaction type badge
    var txTypeBadge = tx.tx_type === 'p2p'
        ? '<span style="background: #ffc107; color: #000; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;"><i class="fas fa-network-wired"></i> P2P Routed</span>'
        : '<span style="background: #28a745; color: #fff; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;"><i class="fas fa-arrow-right"></i> Direct</span>';

    // Build direction badge
    var directionColor = tx.type === 'sent' ? '#dc3545' : '#28a745';
    var directionIcon = tx.type === 'sent' ? 'fa-arrow-up' : 'fa-arrow-down';
    var directionText = tx.type === 'sent' ? 'Sent' : 'Received';

    // Truncate long values for display
    function truncate(str, len) {
        if (!str) return 'N/A';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    // Build HTML content
    var html = '';

    // Header with amount
    html += '<div style="text-align: center; padding: 1rem; background: linear-gradient(135deg, ' + directionColor + ' 0%, ' + (tx.type === 'sent' ? '#ff6b6b' : '#20c997') + ' 100%); border-radius: 8px; margin-bottom: 1.5rem;">';
    html += '<div style="font-size: 2rem; font-weight: bold; color: white;">' + (tx.type === 'sent' ? '-' : '+') + '$' + parseFloat(tx.amount).toFixed(2) + ' ' + tx.currency + '</div>';
    html += '<div style="color: rgba(255,255,255,0.9); margin-top: 0.5rem;"><i class="fas ' + directionIcon + '"></i> ' + directionText + '</div>';
    html += '</div>';

    // Status and type badges
    html += '<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">';
    html += statusBadge;
    html += txTypeBadge;
    html += '</div>';

    // Details section
    html += '<div class="tx-detail-section">';

    // Counterparty
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">' + (tx.type === 'sent' ? 'To' : 'From') + '</div>';
    html += '<div class="tx-detail-value">' + (tx.counterparty_name ? '<strong>' + tx.counterparty_name + '</strong><br>' : '') + '<span style="font-family: monospace; font-size: 0.85rem; word-break: break-all;">' + tx.counterparty_address + '</span></div>';
    html += '</div>';

    // Date/Time
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">Date & Time</div>';
    html += '<div class="tx-detail-value">' + tx.date + '</div>';
    html += '</div>';

    // Transaction ID
    if (tx.txid) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Transaction ID</div>';
        html += '<div class="tx-detail-value" style="font-family: monospace; font-size: 0.8rem; word-break: break-all;">' + tx.txid + '</div>';
        html += '</div>';
    }

    // Memo (for P2P transactions)
    if (tx.memo && tx.memo !== 'standard') {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Routing Hash</div>';
        html += '<div class="tx-detail-value" style="font-family: monospace; font-size: 0.8rem; word-break: break-all;">' + truncate(tx.memo, 64) + '</div>';
        html += '</div>';
    }

    // Description
    if (tx.description) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Description</div>';
        html += '<div class="tx-detail-value">' + tx.description + '</div>';
        html += '</div>';
    }

    // Sender Address
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">Sender Address</div>';
    html += '<div class="tx-detail-value" style="font-family: monospace; font-size: 0.8rem; word-break: break-all;">' + tx.sender_address + '</div>';
    html += '</div>';

    // Receiver Address
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">Receiver Address</div>';
    html += '<div class="tx-detail-value" style="font-family: monospace; font-size: 0.8rem; word-break: break-all;">' + tx.receiver_address + '</div>';
    html += '</div>';

    html += '</div>';

    content.innerHTML = html;
    modal.style.display = 'flex';
}

function closeTransactionModal() {
    var modal = document.getElementById('transactionModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    var editModal = document.getElementById('editContactModal');
    var txModal = document.getElementById('transactionModal');

    if (event.target === editModal) {
        closeEditContactModal();
    }
    if (event.target === txModal) {
        closeTransactionModal();
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeEditContactModal();
        closeTransactionModal();
    }
});