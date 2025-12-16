   // Copyright 2025
    
    // Simple script to show/hide the floating action button
    // This is minimal JavaScript that should work in Tor Browser
    window.addEventListener('scroll', function() {
        var fab = document.getElementById('backToTop');
        if (window.pageYOffset > 300) {
            fab.classList.remove('hidden');
        } else {
            fab.classList.add('hidden');
        }
    });

    // Hide FAB initially
    document.addEventListener('DOMContentLoaded', function() {
        var fab = document.getElementById('backToTop');
        fab.classList.add('hidden');
    });

// Manual refresh function (Tor Browser compatible)
function refreshWalletData() {
    var refreshBtn = document.getElementById('manualRefresh');
    var icon = refreshBtn.querySelector('i');

    // Show loading state
    icon.className = 'fas fa-spinner fa-spin';
    refreshBtn.disabled = true;

    // Preserve auth code when refreshing
    window.location.href = window.location.href;
}

// Send eIOU form handling
function initializeSendForm() {
    var recipientSelect = document.getElementById('recipient');
    var manualAddressGroup = document.getElementById('manual-address-group');
    var manualAddressInput = document.getElementById('manual-address');
    var transactionTypeIndicator = document.getElementById('transaction-type-indicator');
    var transactionTypeText = document.getElementById('transaction-type-text');
    var addressTypeGroup = document.getElementById('address-type-group');
    var addressTypeSelect = document.getElementById('address-type');

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
            var selectedValue = this.value;
            var selectedOption = this.options[this.selectedIndex];

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

                // Get available addresses from JSON data attribute
                var addressesJson = selectedOption.getAttribute('data-addresses');
                var addresses = {};
                try {
                    addresses = addressesJson ? JSON.parse(addressesJson) : {};
                } catch (e) {
                    addresses = {};
                }

                // Populate address type dropdown dynamically
                addressTypeSelect.innerHTML = '<option value="">Select address type</option>';

                var addressTypes = Object.keys(addresses);
                var addressCount = addressTypes.length;

                for (var i = 0; i < addressTypes.length; i++) {
                    var type = addressTypes[i];
                    var addr = addresses[type];
                    var truncatedAddr = addr.length > 30 ? addr.substring(0, 30) + '...' : addr;
                    var displayType = type.toUpperCase();
                    addressTypeSelect.innerHTML += '<option value="' + type + '">' + displayType + ' (' + truncatedAddr + ')</option>';
                }

                // Show address type selector if at least one address available
                if (addressCount > 0) {
                    addressTypeGroup.style.display = 'block';
                    addressTypeSelect.required = true;

                    // Auto-select if only one address available
                    if (addressCount === 1) {
                        addressTypeSelect.value = addressTypes[0];
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
            var address = this.value.trim();
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

    // Build transaction type badge (both yellow for consistency)
    var txTypeBadge = tx.tx_type === 'p2p'
        ? '<span style="background: #ffc107; color: #000; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;"><i class="fas fa-network-wired"></i> P2P Routed</span>'
        : '<span style="background: #ffc107; color: #000; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;"><i class="fas fa-exchange-alt"></i> Direct</span>';

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

    // Counterparty (To/From)
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">' + (tx.type === 'sent' ? 'To' : 'From') + '</div>';
    html += '<div class="tx-detail-value">' + (tx.counterparty_name ? '<strong>' + tx.counterparty_name + '</strong><br>' : '') + '<span style="font-family: monospace; font-size: 0.85rem; word-break: break-all;">' + tx.counterparty_address + '</span></div>';
    html += '</div>';

    // Description (moved up, right after To/From)
    if (tx.description) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Description</div>';
        html += '<div class="tx-detail-value">' + tx.description + '</div>';
        html += '</div>';
    }

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

    // Routing Hash (for P2P transactions)
    if (tx.memo && tx.memo !== 'standard') {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Routing Hash</div>';
        html += '<div class="tx-detail-value" style="font-family: monospace; font-size: 0.8rem; word-break: break-all;">' + truncate(tx.memo, 64) + '</div>';
        html += '</div>';
    }

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

// Toast Notification System
function showToast(title, message, type) {
    type = type || 'info';

    // Ensure toast container exists
    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    // Create toast element
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;

    // Icon mapping
    var icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };

    toast.innerHTML = '<i class="fas ' + icons[type] + ' toast-icon"></i>' +
        '<div class="toast-content">' +
        '<div class="toast-title">' + title + '</div>' +
        '<div class="toast-message">' + message + '</div>' +
        '</div>' +
        '<button class="toast-close" onclick="this.parentElement.remove()">&times;</button>';

    container.appendChild(toast);

    // Auto-remove after 5 seconds
    setTimeout(function() {
        if (toast.parentElement) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(function() {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }
    }, 5000);
}

// Show toast when sending transaction
function initializeTransactionToast() {
    var sendForm = document.querySelector('#send-form form');
    if (sendForm) {
        sendForm.addEventListener('submit', function(e) {
            var recipientSelect = document.getElementById('recipient');
            var manualAddress = document.getElementById('manual-address');
            var amount = document.getElementById('amount');
            var currency = document.getElementById('currency');

            var recipient = '';
            if (recipientSelect && recipientSelect.value) {
                recipient = recipientSelect.options[recipientSelect.selectedIndex].text;
            } else if (manualAddress && manualAddress.value) {
                var addr = manualAddress.value;
                recipient = addr.length > 25 ? addr.substring(0, 25) + '...' : addr;
            }

            var amountValue = amount ? amount.value : '0';
            var currencyValue = currency ? currency.value : 'USD';

            // Determine if P2P or direct
            var isP2P = !recipientSelect || !recipientSelect.value;
            var txType = isP2P ? 'P2P' : 'Direct';

            showToast(
                'Sending ' + txType + ' Transaction',
                'Sending $' + amountValue + ' ' + currencyValue + ' to ' + recipient + '...',
                'info'
            );
        });
    }
}

// Initialize toast on DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    initializeTransactionToast();
});
// Loading Overlay Functions
var loaderTimerInterval = null;
var loaderStartTime = null;

function showLoader(message, subtext) {
    message = message || 'Loading...';
    var overlay = document.getElementById('loadingOverlay');
    var loadingText = document.getElementById('loadingText');
    var loadingSubtext = document.getElementById('loadingSubtext');
    var loadingTimer = document.getElementById('loadingTimer');

    if (overlay && loadingText) {
        loadingText.textContent = message;

        // Set subtext if provided
        if (loadingSubtext) {
            loadingSubtext.textContent = subtext || '';
            loadingSubtext.style.display = subtext ? 'block' : 'none';
        }

        // Start elapsed timer
        if (loadingTimer) {
            loaderStartTime = Date.now();
            loadingTimer.textContent = 'Elapsed: 0s';
            loadingTimer.style.display = 'block';

            // Clear any existing timer
            if (loaderTimerInterval) {
                clearInterval(loaderTimerInterval);
            }

            // Update timer every second
            loaderTimerInterval = setInterval(function() {
                var elapsed = Math.floor((Date.now() - loaderStartTime) / 1000);
                loadingTimer.textContent = 'Elapsed: ' + elapsed + 's';
            }, 1000);
        }

        overlay.classList.add('active');
    }
}

function hideLoader() {
    var overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.remove('active');
    }

    // Clear timer
    if (loaderTimerInterval) {
        clearInterval(loaderTimerInterval);
        loaderTimerInterval = null;
    }
    loaderStartTime = null;
}

// Form loaders initialization
function initializeFormLoaders() {
    // Retry info text for contact operations
    var retryInfoText = 'Connecting to contact server. If unreachable, up to 5 retry attempts will be made automatically.';

    // Add contact form
    var addContactForm = document.querySelector('#add-contact form');
    if (addContactForm) {
        addContactForm.addEventListener('submit', function() {
            showLoader('Adding contact...', retryInfoText);
        });
    }

    // Edit contact form
    var editContactForm = document.querySelector('#editContactModal form');
    if (editContactForm) {
        editContactForm.addEventListener('submit', function() {
            showLoader('Updating contact...');
        });
    }

    // Accept contact forms (in pending contact requests section)
    var acceptContactForms = document.querySelectorAll('form input[name="action"][value="acceptContact"]');
    for (var i = 0; i < acceptContactForms.length; i++) {
        var form = acceptContactForms[i].closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                showLoader('Accepting contact request...', retryInfoText);
            });
        }
    }

    // Block contact forms
    var blockContactForms = document.querySelectorAll('form input[name="action"][value="blockContact"]');
    for (var i = 0; i < blockContactForms.length; i++) {
        var form = blockContactForms[i].closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                showLoader('Blocking contact...');
            });
        }
    }

    // Unblock contact forms
    var unblockContactForms = document.querySelectorAll('form input[name="action"][value="unblockContact"]');
    for (var i = 0; i < unblockContactForms.length; i++) {
        var form = unblockContactForms[i].closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                showLoader('Unblocking contact...');
            });
        }
    }

    // Delete contact forms
    var deleteContactForms = document.querySelectorAll('form input[name="action"][value="deleteContact"]');
    for (var i = 0; i < deleteContactForms.length; i++) {
        var form = deleteContactForms[i].closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                showLoader('Deleting contact request...');
            });
        }
    }

    // Auth form
    var authForm = document.querySelector('.auth-form');
    if (authForm) {
        authForm.addEventListener('submit', function() {
            showLoader('Authenticating...');
        });
    }

    // Send form - already handled in initializeTransactionToast, add loader
    var sendForm = document.querySelector('#send-form form');
    if (sendForm) {
        sendForm.addEventListener('submit', function() {
            showLoader('Sending transaction...', 'Processing your transaction. This may take a moment.');
        });
    }
}

// Add to existing DOMContentLoaded
window.addEventListener('DOMContentLoaded', function() {
    initializeFormLoaders();
});
