   // Copyright 2025-2026 Vowels Group, LLC

/**
 * @fileoverview EIOU Wallet GUI JavaScript
 * @description Main JavaScript file for the EIOU wallet web interface.
 * Handles wallet operations, contact management, transaction display, and UI interactions.
 */

/**
 * Operation timeout in milliseconds.
 * Used to detect stalled operations and show timeout notifications.
 * @type {number}
 * @constant
 */
var OPERATION_TIMEOUT_MS = 15000;

/**
 * ID of the active operation timeout timer.
 * Set when an operation starts, cleared on completion or timeout.
 * @type {number|null}
 */
var operationTimeoutId = null;

/**
 * ID of the countdown interval timer for timeout display.
 * @type {number|null}
 */
var countdownIntervalId = null;

/**
 * Flag indicating whether sessionStorage is available.
 * Set to false in Tor Browser with strict privacy settings.
 * @type {boolean}
 * @constant
 */
var storageAvailable = (function() {
    try {
        var test = '__storage_test__';
        sessionStorage.setItem(test, test);
        sessionStorage.removeItem(test);
        return true;
    } catch (e) {
        return false;
    }
})();

// Safe storage functions with URL parameter fallback

/**
 * Safely stores a value in sessionStorage with error handling.
 *
 * Provides a wrapper around sessionStorage.setItem that gracefully handles
 * storage unavailability (common in Tor Browser with strict privacy settings).
 * Falls back silently if storage is not available.
 *
 * @param {string} key - The storage key to set
 * @param {string} value - The value to store
 * @returns {boolean} True if the value was successfully stored, false otherwise
 * @example
 * // Store a value
 * if (safeStorageSet('user_preference', 'dark_mode')) {
 *     console.log('Preference saved');
 * }
 */
function safeStorageSet(key, value) {
    if (storageAvailable) {
        try {
            sessionStorage.setItem(key, value);
            return true;
        } catch (e) {}
    }
    return false;
}

/**
 * Safely retrieves a value from sessionStorage with error handling.
 *
 * Provides a wrapper around sessionStorage.getItem that gracefully handles
 * storage unavailability (common in Tor Browser with strict privacy settings).
 *
 * @param {string} key - The storage key to retrieve
 * @returns {string|null} The stored value, or null if not found or storage unavailable
 * @example
 * var preference = safeStorageGet('user_preference');
 * if (preference) {
 *     applyPreference(preference);
 * }
 */
function safeStorageGet(key) {
    if (storageAvailable) {
        try {
            return sessionStorage.getItem(key);
        } catch (e) {}
    }
    return null;
}

/**
 * Safely removes a value from sessionStorage with error handling.
 *
 * Provides a wrapper around sessionStorage.removeItem that gracefully handles
 * storage unavailability (common in Tor Browser with strict privacy settings).
 *
 * @param {string} key - The storage key to remove
 * @returns {void}
 * @example
 * // Clean up stored data
 * safeStorageRemove('temporary_data');
 */
function safeStorageRemove(key) {
    if (storageAvailable) {
        try {
            sessionStorage.removeItem(key);
        } catch (e) {}
    }
}

/**
 * Escapes HTML special characters to prevent XSS attacks.
 *
 * Uses the DOM's textContent property to safely escape any HTML entities
 * in user-provided data before inserting into the DOM. This is a defense-in-depth
 * measure to prevent cross-site scripting attacks.
 *
 * @param {*} text - The text to escape (will be converted to string)
 * @returns {string} The escaped HTML-safe string, or empty string if null/undefined
 * @example
 * // Safely display user input
 * element.innerHTML = '<span>' + escapeHtml(userInput) + '</span>';
 *
 * // Handles null/undefined gracefully
 * escapeHtml(null);  // returns ''
 * escapeHtml('User input with special chars');  // safely escapes HTML entities
 */
function escapeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

// ============================================================================
// Tab Navigation
// ============================================================================

/**
 * Map of legacy hash fragments to their tab and optional scroll target.
 * Keeps old #anchor bookmarks and quick-action links working.
 */
var TAB_HASH_MAP = {
    'dashboard':         { tab: 'dashboard' },
    'send':              { tab: 'send' },
    'send-form':         { tab: 'send', scrollTo: 'send-form' },
    'payment-requests':  { tab: 'send', scrollTo: 'payment-requests-section' },
    'add-contact':       { tab: 'contacts', openModal: 'openAddContactModal' },
    'contacts':          { tab: 'contacts', scrollTo: 'contacts' },
    'pending-contacts':  { tab: 'contacts', scrollTo: 'pending-contacts' },
    'transactions':      { tab: 'activity', scrollTo: 'transactions' },
    'dlq':               { tab: 'activity', scrollTo: 'dlq' },
    'settings':          { tab: 'settings' },
    'debug':             { tab: 'settings' },
    'debug-section':     { tab: 'settings' }
};

/**
 * Switches the visible tab panel and updates tab bar active states.
 *
 * @param {string} tabName - The tab identifier (dashboard, send, contacts, activity, settings)
 * @param {string} [scrollToId] - Optional element ID to scroll to within the tab
 */
function switchTab(tabName, scrollToId) {
    var panels = document.querySelectorAll('.tab-panel');
    var desktopBtns = document.querySelectorAll('.tab-bar .tab-btn');
    var mobileBtns = document.querySelectorAll('.tab-bar-mobile .tab-btn-mobile');
    var targetPanel = document.getElementById('tab-panel-' + tabName);

    if (!targetPanel) return;

    // Hide all panels, show target
    for (var i = 0; i < panels.length; i++) {
        panels[i].style.display = 'none';
    }
    targetPanel.style.display = 'block';

    // Update desktop tab bar
    for (var j = 0; j < desktopBtns.length; j++) {
        if (desktopBtns[j].getAttribute('data-tab') === tabName) {
            desktopBtns[j].classList.add('tab-active');
        } else {
            desktopBtns[j].classList.remove('tab-active');
        }
    }

    // Update mobile tab bar
    for (var k = 0; k < mobileBtns.length; k++) {
        if (mobileBtns[k].getAttribute('data-tab') === tabName) {
            mobileBtns[k].classList.add('tab-active');
        } else {
            mobileBtns[k].classList.remove('tab-active');
        }
    }

    // Update hash without triggering reload
    try {
        if (history.replaceState) {
            history.replaceState(null, '', '#' + tabName);
        }
    } catch (e) { /* Tor strict mode fallback — hash won't update but tab still works */ }

    // Persist last tab
    safeStorageSet('eiou_active_tab', tabName);

    // Scroll to specific element within the tab, or scroll to top
    if (scrollToId) {
        var scrollTarget = document.getElementById(scrollToId);
        if (scrollTarget) {
            scrollTarget.scrollIntoView({ block: 'start' });
            return;
        }
    }
    window.scrollTo(0, 0);
}

/**
 * Initializes tab navigation on page load.
 * Checks URL hash, then sessionStorage, then defaults to dashboard.
 */
function initTabNavigation() {
    var hash = window.location.hash ? window.location.hash.substring(1) : '';
    var tabToShow = 'dashboard';
    var scrollToId = null;

    // Check URL hash first
    if (hash && TAB_HASH_MAP[hash]) {
        tabToShow = TAB_HASH_MAP[hash].tab;
        scrollToId = TAB_HASH_MAP[hash].scrollTo || null;
        var openModalFn = TAB_HASH_MAP[hash].openModal || null;
    } else if (hash && hash.indexOf('reopen_contact') !== -1) {
        // Contact reopen hash — show contacts tab
        tabToShow = 'contacts';
    } else {
        // Fall back to stored tab
        var stored = safeStorageGet('eiou_active_tab');
        if (stored && document.getElementById('tab-panel-' + stored)) {
            tabToShow = stored;
        }
    }

    switchTab(tabToShow, scrollToId);
    if (openModalFn && typeof window[openModalFn] === 'function') {
        window[openModalFn]();
    }
}

// Re-run tab+scroll routing whenever the hash changes (e.g. clicking notification "View" links)
window.addEventListener('hashchange', function() {
    var hash = window.location.hash ? window.location.hash.substring(1) : '';
    if (hash && TAB_HASH_MAP[hash]) {
        switchTab(TAB_HASH_MAP[hash].tab, TAB_HASH_MAP[hash].scrollTo || null);
        var fn = TAB_HASH_MAP[hash].openModal;
        if (fn && typeof window[fn] === 'function') { window[fn](); }
    }
});

    // Simple script to show/hide the floating action button
    // This is minimal JavaScript that should work in Tor Browser
    window.addEventListener('scroll', function() {
        var fab = document.getElementById('backToTop');
        if (fab) {
            if (window.pageYOffset > 300) {
                fab.classList.remove('hidden');
            } else {
                fab.classList.add('hidden');
            }
        }
    });

    // Hide FAB initially and initialize tabs
    document.addEventListener('DOMContentLoaded', function() {
        var fab = document.getElementById('backToTop');
        if (fab) {
            fab.classList.add('hidden');
        }
        // Initialize tab navigation
        initTabNavigation();
    });

/**
 * Manually refreshes wallet data by reloading the page.
 *
 * Stops any auto-refresh processes, shows a loading spinner on the refresh button,
 * and reloads the page to fetch fresh wallet data. This is Tor Browser compatible
 * and uses a simple page reload for consistency with auto-refresh behavior.
 *
 * The function sets a global `window.isRefreshing` flag to prevent race conditions
 * with auto-refresh functionality.
 *
 * @returns {void}
 * @example
 * // Called from manual refresh button onclick
 * <button id="manualRefresh" onclick="refreshWalletData()">Refresh</button>
 */
function refreshWalletData() {
    // Set global flag FIRST to prevent race conditions with auto-refresh
    window.isRefreshing = true;

    // Stop auto-refresh before manual refresh
    if (typeof window.stopAutoRefresh === 'function') {
        window.stopAutoRefresh();
    }

    var refreshBtn = document.getElementById('manualRefresh');
    if (refreshBtn) {
        var icon = refreshBtn.querySelector('i');
        if (icon) {
            icon.className = 'fas fa-spinner fa-spin';
        }
        refreshBtn.disabled = true;
    }

    // Use reload for consistency with auto-refresh
    window.location.reload();
}

/**
 * Initializes the Send eIOU form with dynamic transaction type detection.
 *
 * Sets up event listeners on the recipient select dropdown and manual address input
 * to dynamically show/hide form fields and update the transaction type indicator.
 * Handles two transaction modes:
 * - Direct Transaction: When a contact is selected from the dropdown
 * - P2P Transaction: When a manual address is entered (routed through contacts)
 *
 * The function manages form validation by toggling required attributes on fields
 * based on the selected transaction mode. It also dynamically populates the
 * address type dropdown when a contact with multiple addresses is selected.
 *
 * @returns {void}
 * @example
 * // Called automatically on DOMContentLoaded
 * document.addEventListener('DOMContentLoaded', function() {
 *     initializeSendForm();
 * });
 */
function initializeSendForm() {
    var recipientHidden = document.getElementById('recipient');
    var recipientSearch = document.getElementById('recipient-search');
    var recipientDropdown = document.getElementById('recipient-dropdown');
    var manualAddressGroup = document.getElementById('manual-address-group');
    var manualAddressInput = document.getElementById('manual-address');
    var transactionTypeIndicator = document.getElementById('transaction-type-indicator');
    var transactionTypeText = document.getElementById('transaction-type-text');
    var addressTypeGroup = document.getElementById('address-type-group');
    var addressTypeSelect = document.getElementById('address-type');

    // Set initial state - manual address is visible by default
    if (manualAddressInput) manualAddressInput.required = true;
    if (transactionTypeIndicator) {
        transactionTypeIndicator.style.display = 'block';
        transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
        transactionTypeText.style.color = '#ffc107';
    }

    // Initialize searchable contact dropdown
    if (recipientSearch && recipientDropdown) {
        var allOptions = recipientDropdown.querySelectorAll('.recipient-option');

        // Filter dropdown on text input
        recipientSearch.addEventListener('input', function() {
            var query = this.value.toLowerCase().trim();

            // Clear selected contact if user is typing
            if (recipientHidden) recipientHidden.value = '';

            // Reset to P2P state when search cleared
            if (!query) {
                recipientDropdown.style.display = 'none';
                manualAddressGroup.style.display = 'block';
                if (manualAddressInput) manualAddressInput.required = true;
                addressTypeGroup.style.display = 'none';
                if (addressTypeSelect) addressTypeSelect.required = false;
                transactionTypeIndicator.style.display = 'block';
                transactionTypeText.textContent = 'P2P Transaction (routed through contacts)';
                transactionTypeText.style.color = '#ffc107';
                // Restore all currency options
                var currSelect = document.getElementById('currency');
                if (currSelect) {
                    for (var ri = 0; ri < currSelect.options.length; ri++) {
                        currSelect.options[ri].style.display = '';
                    }
                }
                updateAmountPrecisionHint();
                return;
            }

            var hasVisible = false;
            for (var i = 0; i < allOptions.length; i++) {
                var name = (allOptions[i].getAttribute('data-name') || '').toLowerCase();
                var matched = name.indexOf(query) !== -1;

                // Also match against contact addresses
                if (!matched) {
                    var addrJson = allOptions[i].getAttribute('data-addresses') || '{}';
                    try {
                        var addrs = JSON.parse(addrJson);
                        for (var key in addrs) {
                            if (addrs[key] && addrs[key].toLowerCase().indexOf(query) !== -1) {
                                matched = true;
                                break;
                            }
                        }
                    } catch (e) {}
                }

                if (matched) {
                    allOptions[i].style.display = '';
                    hasVisible = true;
                } else {
                    allOptions[i].style.display = 'none';
                }
            }
            recipientDropdown.style.display = hasVisible ? 'block' : 'none';
        });

        // Show dropdown on focus if there's text
        recipientSearch.addEventListener('focus', function() {
            if (this.value.trim()) {
                // Re-trigger filter
                this.dispatchEvent(new Event('input'));
            }
        });

        // Shared selection logic — called from both mouse click and keyboard Enter
        function selectRecipientOption(optionEl) {
            var name = optionEl.getAttribute('data-name');
            recipientSearch.value = name;
            if (recipientHidden) recipientHidden.value = name;
            recipientDropdown.style.display = 'none';
            focusedOptionIndex = -1;

            // Hide manual address, show address type selector
            manualAddressGroup.style.display = 'none';
            if (manualAddressInput) {
                manualAddressInput.required = false;
                manualAddressInput.value = '';
            }

            // Populate address type dropdown
            var addressesJson = optionEl.getAttribute('data-addresses');
            var addresses = {};
            try {
                addresses = addressesJson ? JSON.parse(addressesJson) : {};
            } catch (err) {
                addresses = {};
            }

            addressTypeSelect.innerHTML = '<option value="">Select address type</option>';
            var addressTypes = Object.keys(addresses);

            for (var j = 0; j < addressTypes.length; j++) {
                var type = addressTypes[j];
                var addr = addresses[type];
                var truncatedAddr = addr.length > 30 ? addr.substring(0, 30) + '...' : addr;
                var displayType = type.toUpperCase();
                addressTypeSelect.innerHTML += '<option value="' + escapeHtml(type) + '">' + escapeHtml(displayType) + ' (' + escapeHtml(truncatedAddr) + ')</option>';
            }

            if (addressTypes.length > 0) {
                addressTypeGroup.style.display = 'block';
                addressTypeSelect.required = true;
                // Auto-select best address: prefer security order (tor > https > http)
                var preferredOrder = ['tor', 'https', 'http'];
                var autoSelected = false;
                for (var p = 0; p < preferredOrder.length; p++) {
                    if (addressTypes.indexOf(preferredOrder[p]) !== -1) {
                        addressTypeSelect.value = preferredOrder[p];
                        autoSelected = true;
                        break;
                    }
                }
                if (!autoSelected) {
                    addressTypeSelect.value = addressTypes[0];
                }
            } else {
                addressTypeGroup.style.display = 'none';
                addressTypeSelect.required = false;
            }

            // Filter currency dropdown to contact's accepted currencies
            var currencySelect = document.getElementById('currency');
            if (currencySelect) {
                var contactCurrenciesJson = optionEl.getAttribute('data-currencies');
                var contactCurrencies = [];
                try { contactCurrencies = contactCurrenciesJson ? JSON.parse(contactCurrenciesJson) : []; } catch (e) {}
                if (contactCurrencies.length > 0) {
                    var allCurrencyOpts = currencySelect.options;
                    for (var ci = 0; ci < allCurrencyOpts.length; ci++) {
                        allCurrencyOpts[ci].style.display = contactCurrencies.indexOf(allCurrencyOpts[ci].value) !== -1 ? '' : 'none';
                    }
                    // Select first available currency if current selection is hidden
                    if (contactCurrencies.indexOf(currencySelect.value) === -1) {
                        currencySelect.value = contactCurrencies[0];
                    }
                }
                updateAmountPrecisionHint();
            }

            transactionTypeIndicator.style.display = 'block';
            transactionTypeText.textContent = 'Direct Transaction (to contact)';
            transactionTypeText.style.color = '#28a745';
        }

        // Handle option click
        for (var i = 0; i < allOptions.length; i++) {
            allOptions[i].addEventListener('mousedown', function(e) {
                e.preventDefault(); // Prevent blur from hiding dropdown before click registers
                selectRecipientOption(this);
            });

            // Hover highlight
            allOptions[i].addEventListener('mouseenter', function() {
                this.style.background = '#e9ecef';
                // Sync keyboard index to mouse position
                var visible = getVisibleRecipientOptions();
                for (var k = 0; k < visible.length; k++) {
                    if (visible[k] === this) { focusedOptionIndex = k; break; }
                }
            });
            allOptions[i].addEventListener('mouseleave', function() {
                this.style.background = '';
            });
        }

        // Keyboard navigation — ArrowDown/Up to highlight, Enter to select, Escape to close
        var focusedOptionIndex = -1;

        function getVisibleRecipientOptions() {
            var visible = [];
            for (var i = 0; i < allOptions.length; i++) {
                if (allOptions[i].style.display !== 'none') {
                    visible.push(allOptions[i]);
                }
            }
            return visible;
        }

        function highlightRecipientOption(visible, index) {
            for (var i = 0; i < visible.length; i++) {
                visible[i].style.background = '';
            }
            if (index >= 0 && index < visible.length) {
                visible[index].style.background = '#e9ecef';
                // Scroll within the dropdown only — never scroll the page
                var el = visible[index];
                if (el.offsetTop < recipientDropdown.scrollTop) {
                    recipientDropdown.scrollTop = el.offsetTop;
                } else if (el.offsetTop + el.offsetHeight > recipientDropdown.scrollTop + recipientDropdown.clientHeight) {
                    recipientDropdown.scrollTop = el.offsetTop + el.offsetHeight - recipientDropdown.clientHeight;
                }
            }
        }

        recipientSearch.addEventListener('keydown', function(e) {
            var key = e.key;
            if (recipientDropdown.style.display === 'none') { return; }
            var visible = getVisibleRecipientOptions();
            if (!visible.length) { return; }

            if (key === 'ArrowDown') {
                e.preventDefault();
                focusedOptionIndex = focusedOptionIndex < visible.length - 1 ? focusedOptionIndex + 1 : 0;
                highlightRecipientOption(visible, focusedOptionIndex);
            } else if (key === 'ArrowUp') {
                e.preventDefault();
                focusedOptionIndex = focusedOptionIndex > 0 ? focusedOptionIndex - 1 : visible.length - 1;
                highlightRecipientOption(visible, focusedOptionIndex);
            } else if (key === 'Enter') {
                if (focusedOptionIndex >= 0 && focusedOptionIndex < visible.length) {
                    e.preventDefault();
                    selectRecipientOption(visible[focusedOptionIndex]);
                }
            } else if (key === 'Escape') {
                recipientDropdown.style.display = 'none';
                focusedOptionIndex = -1;
            }
        });

        // Reset focus index when search text changes
        recipientSearch.addEventListener('input', function() {
            focusedOptionIndex = -1;
        }, true); // capture phase so it runs after the existing input handler

        // Hide dropdown on blur
        recipientSearch.addEventListener('blur', function() {
            // Small delay to allow option click to register
            setTimeout(function() {
                recipientDropdown.style.display = 'none';
                focusedOptionIndex = -1;
            }, 150);
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

/**
 * Update the amount precision hint and input attributes based on selected currency.
 * Reads display decimals from the currency select's data attribute.
 */
function updateAmountPrecisionHint() {
    var currencySelect = document.getElementById('currency');
    var hintText = document.getElementById('amount-precision-text');
    var amountInput = document.getElementById('amount');
    if (!currencySelect || !hintText) return;

    var currency = currencySelect.value;
    // Input validation always uses internal precision, regardless of display decimals
    var decimals = parseInt(currencySelect.getAttribute('data-internal-precision') || '8', 10);
    var minimum = (1 / Math.pow(10, decimals)).toFixed(decimals);
    var step = minimum;

    hintText.textContent = 'Minimum amount for ' + currency + ': ' + minimum + '. Values below this will be rejected.';

    if (amountInput) {
        amountInput.setAttribute('min', minimum);
        amountInput.setAttribute('step', step);
    }
}

// Initialize send form when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeSendForm();
    updateAmountPrecisionHint();

    var currencySelect = document.getElementById('currency');
    if (currencySelect) {
        currencySelect.addEventListener('change', updateAmountPrecisionHint);
    }
});

// Edit contact modal functions

/**
 * Opens the edit contact modal with pre-populated form fields.
 *
 * Populates the edit form with the contact's current information and displays
 * the modal for editing. This is a simple modal used for quick contact edits
 * from the contact list view.
 *
 * @param {string} address - The contact's node address (HTTP, HTTPS, or Tor)
 * @param {string} name - The contact's display name
 * @param {number|string} fee - The transaction fee percentage for this contact
 * @param {number|string} credit - The credit limit set for this contact
 * @param {string} currency - The currency code (e.g., 'USD', 'EUR')
 * @returns {void}
 * @example
 * // Open edit modal for a contact
 * openEditContactModal('http://bob:8080', 'Bob', 1.0, 100, 'USD');
 */
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

/**
 * Closes the edit contact modal.
 *
 * Hides the edit contact modal by setting its display style to 'none'.
 * Called when the user clicks the close button, clicks outside the modal,
 * or presses the Escape key.
 *
 * @returns {void}
 */
function closeEditContactModal() {
    document.getElementById('editContactModal').style.display = 'none';
}

function openAddContactModal() {
    document.getElementById('add-contact-modal').style.display = 'flex';
}

function closeAddContactModal() {
    document.getElementById('add-contact-modal').style.display = 'none';
}

/**
 * Opens the transaction detail modal for a specific transaction.
 *
 * Retrieves transaction data from the global `transactionData` array using the provided
 * index and renders a detailed view including amount, status badges, transaction type,
 * counterparty information, and P2P routing details if applicable.
 *
 * The modal displays different badge styles based on:
 * - Transaction status: completed, pending, failed, syncing
 * - Transaction type: contact (request), p2p (routed), direct
 * - Direction: sent, received, relay
 *
 * @param {number} index - Zero-based index into the global `transactionData` array.
 *                         The transactionData array is populated by PHP and contains
 *                         transaction objects with properties like amount, status,
 *                         type, tx_type, counterparty_address, etc.
 * @returns {void}
 * @requires transactionData - Global array of transaction objects (set by PHP)
 * @example
 * // Called from transaction list item onclick
 * <div onclick="openTransactionModal(0)">Transaction 1</div>
 */
function openTransactionModal(index) {
    if (typeof transactionData === 'undefined' || !transactionData[index]) {
        return;
    }
    renderTransactionModal(transactionData[index]);
}

/**
 * Renders and opens the transaction detail modal from a transaction data object.
 * Called by openTransactionModal (from transactionData array) and
 * openTransactionModalByTxid (which may fetch via AJAX).
 *
 * @param {Object} tx - Transaction object with the same keys as transactionData entries
 */
function renderTransactionModal(tx) {
    var modal = document.getElementById('transactionModal');
    var content = document.getElementById('tx-modal-content');

    // Build status badge HTML (escapeHtml for defense-in-depth)
    var statusClass = 'tx-status-' + escapeHtml(tx.status);
    var statusBadge = '<span class="tx-status-badge ' + statusClass + '">' + escapeHtml(tx.status.charAt(0).toUpperCase() + tx.status.slice(1)) + '</span>';

    // Build transaction type badge with role indicator
    var txTypeBadge = '';
    var roleBadge = '';

    // Determine role icon, label, and badge class
    var roleIcon = tx.type === 'sent' ? 'fa-arrow-up' : 'fa-arrow-down';
    var roleLabel = tx.type === 'sent' ? 'Sent' : 'Received';
    var roleBadgeClass = tx.type === 'sent' ? 'tx-modal-badge-sent' : 'tx-modal-badge-received';

    // Check for relay transactions
    if (tx.direction === 'relay') {
        roleIcon = 'fa-random';
        roleLabel = 'Relay';
        roleBadgeClass = 'tx-modal-badge-relay';
    }

    if (tx.tx_type === 'contact') {
        txTypeBadge = '<span class="tx-modal-badge tx-modal-badge-contact"><i class="fas fa-user-plus"></i> Contact Request</span>';
        roleBadge = '<span class="tx-modal-badge ' + roleBadgeClass + '"><i class="fas ' + roleIcon + '"></i> ' + roleLabel + '</span>';
    } else if (tx.tx_type === 'p2p') {
        txTypeBadge = '<span class="tx-modal-badge tx-modal-badge-p2p"><i class="fas fa-network-wired"></i> P2P Routed</span>';
        roleBadge = '<span class="tx-modal-badge ' + roleBadgeClass + '"><i class="fas ' + roleIcon + '"></i> ' + roleLabel + '</span>';
    } else {
        txTypeBadge = '<span class="tx-modal-badge tx-modal-badge-direct"><i class="fas fa-exchange-alt"></i> Direct</span>';
        roleBadge = '<span class="tx-modal-badge ' + roleBadgeClass + '"><i class="fas ' + roleIcon + '"></i> ' + roleLabel + '</span>';
    }

    // Build direction badge for header
    var directionIcon = tx.type === 'sent' ? 'fa-arrow-up' : 'fa-arrow-down';
    var directionText = tx.type === 'sent' ? 'Sent' : 'Received';

    /**
     * Truncates a string to a specified length with ellipsis.
     * @param {string|null} str - The string to truncate
     * @param {number} len - Maximum length before truncation
     * @returns {string} Truncated string with '...' or 'N/A' if null
     * @private
     */
    function truncate(str, len) {
        if (!str) return 'N/A';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    // Build HTML content
    var html = '';

    // Header with amount
    var headerClass = tx.type === 'sent' ? 'tx-modal-header-sent' : 'tx-modal-header-received';
    html += '<div class="tx-modal-header ' + headerClass + '">';
    html += '<div class="tx-modal-amount">' + (tx.type === 'sent' ? '-' : '+') + parseFloat(tx.amount).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency) + '</div>';
    html += '<div class="tx-modal-direction"><i class="fas ' + directionIcon + '"></i> ' + directionText + '</div>';
    html += '</div>';

    // Status, type, and role badges
    html += '<div class="tx-modal-badges">';
    html += statusBadge;
    html += txTypeBadge;
    html += roleBadge;
    html += '</div>';

    // Details section
    html += '<div class="tx-detail-section">';

    // Counterparty (To/From)
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">' + (tx.type === 'sent' ? 'To' : 'From') + '</div>';
    html += '<div class="tx-detail-value">' + (tx.counterparty_name ? '<strong>' + escapeHtml(tx.counterparty_name) + '</strong><br>' : '') + '<span class="tx-modal-mono">' + escapeHtml(tx.counterparty_address) + '</span></div>';
    html += '</div>';

    // Description (moved up, right after To/From)
    if (tx.description) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Description</div>';
        html += '<div class="tx-detail-value">' + escapeHtml(tx.description) + '</div>';
        html += '</div>';
    }

    // Date/Time
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">Date & Time</div>';
    html += '<div class="tx-detail-value">' + escapeHtml(tx.date) + '</div>';
    html += '</div>';

    // Transaction ID
    if (tx.txid) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Transaction ID</div>';
        html += '<div class="tx-detail-value tx-modal-mono-sm">' + escapeHtml(tx.txid) + '</div>';
        html += '</div>';
    }

    // Routing Hash (only for P2P transactions, not direct or contact)
    if (tx.tx_type === 'p2p' && tx.memo && tx.memo !== 'standard') {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Routing Hash</div>';
        html += '<div class="tx-detail-value tx-modal-mono-sm">' + escapeHtml(truncate(tx.memo, 64)) + '</div>';
        html += '</div>';
    }

    html += '</div>';

    // P2P Transaction Details (end recipient, amount, fee) - displayed below main details
    if (tx.tx_type === 'p2p' && tx.p2p_destination) {
        html += '<div class="tx-modal-p2p-details">';
        html += '<div class="tx-modal-p2p-title">';
        html += '<i class="fas fa-network-wired"></i> P2P Transaction Details';
        html += '</div>';

        // End Recipient
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">End Recipient</div>';
        html += '<div class="tx-detail-value tx-modal-mono">' + escapeHtml(tx.p2p_destination) + '</div>';
        html += '</div>';

        // Amount to Recipient
        if (tx.p2p_amount) {
            html += '<div class="tx-detail-row">';
            html += '<div class="tx-detail-label">Amount to Recipient</div>';
            html += '<div class="tx-detail-value">' + parseFloat(tx.p2p_amount).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency) + '</div>';
            html += '</div>';
        }

        // Transaction Fee
        if (tx.p2p_fee) {
            html += '<div class="tx-detail-row">';
            html += '<div class="tx-detail-label">Transaction Fee</div>';
            html += '<div class="tx-detail-value">' + parseFloat(tx.p2p_fee).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency) + '</div>';
            html += '</div>';
        }

        html += '</div>';
    }

    content.innerHTML = html;
    modal.style.display = 'flex';
}

/**
 * Closes the transaction detail modal.
 *
 * Hides the transaction modal by setting its display style to 'none'.
 * Called when the user clicks the close button, clicks outside the modal,
 * or presses the Escape key.
 *
 * @returns {void}
 */
function closeTransactionModal() {
    var modal = document.getElementById('transactionModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Open the transaction detail modal for a given txid.
 * Checks the in-page transactionData array first (fast path); if not found,
 * falls back to an AJAX POST to fetch the transaction from the server.
 *
 * @param {string} txid - The transaction ID to look up and display
 */
function openTransactionModalByTxid(txid) {
    if (!txid) { return; }

    // Fast path: already in the page's transactionData array
    if (typeof transactionData !== 'undefined') {
        for (var i = 0; i < transactionData.length; i++) {
            if (transactionData[i].txid === txid) {
                renderTransactionModal(transactionData[i]);
                return;
            }
        }
    }

    // Slow path: fetch from server via AJAX
    var modal = document.getElementById('transactionModal');
    var content = document.getElementById('tx-modal-content');
    if (modal && content) {
        content.innerHTML = '<div style="text-align:center;padding:2rem"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
        modal.style.display = 'flex';
    }

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        if (content) { content.innerHTML = '<div style="padding:1rem;color:#dc3545">Could not load transaction: CSRF token missing.</div>'; }
        return;
    }

    var formData = new FormData();
    formData.append('action', 'getTransactionByTxid');
    formData.append('txid', txid);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 15000;
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success && resp.transaction) {
                    renderTransactionModal(resp.transaction);
                } else {
                    if (content) { content.innerHTML = '<div style="padding:1rem;color:#dc3545">Transaction not found.</div>'; }
                }
            } catch (e) {
                if (content) { content.innerHTML = '<div style="padding:1rem;color:#dc3545">Error loading transaction.</div>'; }
            }
        } else {
            if (content) { content.innerHTML = '<div style="padding:1rem;color:#dc3545">Server error loading transaction.</div>'; }
        }
    };
    xhr.ontimeout = function() {
        if (content) { content.innerHTML = '<div style="padding:1rem;color:#dc3545">Request timed out.</div>'; }
    };
    xhr.onerror = function() {
        if (content) { content.innerHTML = '<div style="padding:1rem;color:#dc3545">Network error loading transaction.</div>'; }
    };
    xhr.send(formData);
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    var editModal = document.getElementById('editContactModal');
    var txModal = document.getElementById('transactionModal');
    var addContactModal = document.getElementById('add-contact-modal');

    if (event.target === editModal) {
        closeEditContactModal();
    }
    if (event.target === txModal) {
        closeTransactionModal();
    }
    if (event.target === addContactModal) {
        closeAddContactModal();
    }
}

// Close modal with Escape key (Tor Browser compatible - uses keyCode fallback)
document.addEventListener('keydown', function(event) {
    var isEscape = event.key === 'Escape' || event.keyCode === 27;
    if (isEscape) {
        closeEditContactModal();
        closeTransactionModal();
        closeContactModal();
        closeAddContactModal();
    }
});

/**
 * Displays a toast notification message to the user.
 *
 * Creates and appends a styled toast notification element to the toast container.
 * The toast includes an icon based on the type, title, message, and a close button.
 * Toasts automatically dismiss after 5 seconds with a fade-out animation.
 *
 * @param {string} title - The bold title text displayed at the top of the toast
 * @param {string} message - The descriptive message text displayed below the title
 * @param {string} [type='info'] - The toast type which determines styling and icon.
 *                                 Available types:
 *                                 - 'success' - Green styling with check-circle icon
 *                                 - 'error' - Red styling with exclamation-circle icon
 *                                 - 'warning' - Yellow/orange styling with exclamation-triangle icon
 *                                 - 'info' - Blue styling with info-circle icon (default)
 * @returns {void}
 * @example
 * // Show a success notification
 * showToast('Success', 'Transaction completed successfully!', 'success');
 *
 * // Show an error notification
 * showToast('Error', 'Failed to connect to server', 'error');
 *
 * // Show an info notification (default type)
 * showToast('Processing', 'Your request is being processed...');
 */
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
        '<div class="toast-title">' + escapeHtml(title) + '</div>' +
        '<div class="toast-message">' + escapeHtml(message) + '</div>' +
        '</div>' +
        '<button class="toast-close" data-action="dismissToast">&times;</button>';

    container.appendChild(toast);

    // Auto-remove after 5 seconds
    setTimeout(function() {
        if (toast.parentElement) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(function() {
                if (toast.parentElement) {
                    toast.parentElement.removeChild(toast);
                }
            }, 300);
        }
    }, 5000);
}

/**
 * Initializes the transaction toast notification on form submission.
 *
 * Attaches a submit event listener to the send form that displays an info toast
 * notification when a transaction is initiated. The toast shows the transaction
 * type (P2P or Direct), amount, currency, and recipient.
 *
 * @returns {void}
 * @example
 * // Called automatically on DOMContentLoaded
 * document.addEventListener('DOMContentLoaded', function() {
 *     initializeTransactionToast();
 * });
 */
function initializeTransactionToast() {
    var sendForm = document.querySelector('#send-form form');
    if (sendForm) {
        sendForm.addEventListener('submit', function(e) {
            var recipientSelect = document.getElementById('recipient');
            var manualAddress = document.getElementById('manual-address');
            var amount = document.getElementById('amount');
            var currency = document.getElementById('currency');

            var recipient = '';
            var recipientSearch = document.getElementById('recipient-search');
            if (recipientSelect && recipientSelect.value && recipientSearch && recipientSearch.value) {
                recipient = recipientSearch.value;
            } else if (recipientSelect && recipientSelect.value) {
                var addr = recipientSelect.value;
                recipient = addr.length > 25 ? addr.substring(0, 25) + '...' : addr;
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
                'Sending ' + amountValue + ' ' + currencyValue + ' to ' + recipient + '...',
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

/**
 * Displays the loading overlay with a message and optional subtext.
 *
 * Shows a full-screen loading overlay with a spinner, message text, optional
 * subtext for additional context, and an elapsed time counter. The timer
 * updates every second to show how long the operation has been running.
 *
 * @param {string} [message='Loading...'] - The main loading message to display
 * @param {string} [subtext] - Optional secondary text for additional context
 * @returns {void}
 * @example
 * // Show basic loading message
 * showLoader('Processing...');
 *
 * // Show loading with context
 * showLoader('Sending transaction...', 'This may take a moment over Tor.');
 */
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

/**
 * Hides the loading overlay and stops the elapsed timer.
 *
 * Removes the loading overlay from view and cleans up the timer interval
 * that was tracking elapsed time. Should be called when the loading
 * operation completes or is cancelled.
 *
 * @returns {void}
 * @example
 * // Hide loader after operation completes
 * performOperation().then(function() {
 *     hideLoader();
 * });
 */
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

/**
 * Starts a 15-second timeout that auto-reloads the page if an operation takes too long.
 *
 * Shows a countdown in the loading overlay and automatically reloads the page
 * after 15 seconds (OPERATION_TIMEOUT_MS). On reload, stores the timeout message
 * in sessionStorage (or URL parameter for Tor Browser) to display a toast
 * notification informing the user the operation is continuing in the background.
 *
 * @param {string} operationType - Identifier for the operation type (e.g., 'sendTransaction')
 * @param {string} timeoutMessage - Message to display in toast after page reload
 * @returns {void}
 * @example
 * // Start timeout for a send operation
 * startOperationTimeout('sendTransaction',
 *     'Still waiting for response. The transaction is being retried in the background.');
 */
function startOperationTimeout(operationType, timeoutMessage) {
    // Clear any existing timeout and countdown
    if (operationTimeoutId) {
        clearTimeout(operationTimeoutId);
    }
    if (countdownIntervalId) {
        clearInterval(countdownIntervalId);
    }

    // Show countdown in loading overlay
    var countdownEl = document.getElementById('loadingCountdown');
    var countdownSeconds = Math.floor(OPERATION_TIMEOUT_MS / 1000);
    var startTime = Date.now();

    if (countdownEl) {
        countdownEl.textContent = 'Page will refresh automatically in ' + countdownSeconds + 's';
        countdownEl.style.display = 'block';

        // Update countdown every second
        countdownIntervalId = setInterval(function() {
            var elapsed = Math.floor((Date.now() - startTime) / 1000);
            var remaining = countdownSeconds - elapsed;
            if (remaining > 0) {
                countdownEl.textContent = 'Page will refresh automatically in ' + remaining + 's';
            } else {
                countdownEl.textContent = 'Refreshing...';
            }
        }, 1000);
    }

    // Set 15-second timeout to reload page
    operationTimeoutId = setTimeout(function() {
        // Store message only when timeout fires, so it shows after reload
        // Try sessionStorage first, fall back to URL parameter
        var storedOp = safeStorageSet('eiou_pending_operation', operationType);
        var storedMsg = safeStorageSet('eiou_timeout_message', timeoutMessage);

        var currentUrl = window.location.href.split('?')[0].split('#')[0];
        if (storedOp && storedMsg) {
            // Navigate via href (not reload()) so it interrupts any in-flight POST
            window.location.href = currentUrl;
        } else {
            // Storage failed (Tor Browser), pass message as URL parameter
            var encodedMsg = encodeURIComponent(timeoutMessage);
            window.location.href = currentUrl + '?timeout_msg=' + encodedMsg;
        }
    }, OPERATION_TIMEOUT_MS);
}

/**
 * Clears the operation timeout and hides the countdown display.
 *
 * Cancels any pending timeout and countdown interval, hides the countdown
 * element in the loading overlay, and removes stored timeout messages from
 * sessionStorage. Should be called when an operation completes successfully
 * before the timeout fires.
 *
 * @returns {void}
 * @example
 * // Clear timeout after successful operation
 * operationComplete().then(function() {
 *     clearOperationTimeout();
 *     hideLoader();
 * });
 */
function clearOperationTimeout() {
    if (operationTimeoutId) {
        clearTimeout(operationTimeoutId);
        operationTimeoutId = null;
    }
    if (countdownIntervalId) {
        clearInterval(countdownIntervalId);
        countdownIntervalId = null;
    }
    // Hide countdown element
    var countdownEl = document.getElementById('loadingCountdown');
    if (countdownEl) {
        countdownEl.style.display = 'none';
    }
    safeStorageRemove('eiou_pending_operation');
    safeStorageRemove('eiou_timeout_message');
}

/**
 * Checks for and displays a timeout toast notification after page reload.
 *
 * Called on DOMContentLoaded to check if the page was reloaded due to an
 * operation timeout. Retrieves the stored timeout message from sessionStorage
 * or URL parameters (for Tor Browser) and displays it as an info toast.
 * Cleans up the stored/URL data after displaying.
 *
 * @returns {void}
 * @example
 * // Called automatically on page load
 * document.addEventListener('DOMContentLoaded', function() {
 *     checkForTimeoutToast();
 * });
 */
function checkForTimeoutToast() {
    var timeoutMessage = safeStorageGet('eiou_timeout_message');

    // Check URL parameter fallback if storage didn't have the message
    if (!timeoutMessage) {
        var urlParams = new URLSearchParams(window.location.search);
        timeoutMessage = urlParams.get('timeout_msg');
        if (timeoutMessage) {
            // Clean up URL by removing the parameter (without reload)
            var cleanUrl = window.location.href.split('?')[0];
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, cleanUrl);
            }
        }
    }

    if (timeoutMessage) {
        // Clear storage first to prevent showing again on refresh
        safeStorageRemove('eiou_pending_operation');
        safeStorageRemove('eiou_timeout_message');

        // Show the toast after a brief delay to ensure page is ready
        setTimeout(function() {
            showToast('Background Processing', timeoutMessage, 'info');
        }, 500);
    }
}

// Check for timeout toast on page load
document.addEventListener('DOMContentLoaded', function() {
    checkForTimeoutToast();
});

/**
 * Initializes loading overlays for all form submissions.
 *
 * Attaches submit event listeners to various forms (add contact, edit contact,
 * accept/block/unblock/delete contact, auth, send transaction) to display
 * appropriate loading messages when forms are submitted. Also starts operation
 * timeouts for long-running operations like adding contacts and sending transactions.
 *
 * @returns {void}
 * @example
 * // Called automatically on DOMContentLoaded
 * window.addEventListener('DOMContentLoaded', function() {
 *     initializeFormLoaders();
 * });
 */
function initializeFormLoaders() {
    // Retry info text for contact operations
    var retryInfoText = 'Connecting to contact server. The message processor will continue retrying in the background.';

    // Add contact form (now inside modal)
    var addContactForm = document.getElementById('add-contact-form');
    if (addContactForm) {
        addContactForm.addEventListener('submit', function() {
            showLoader('Adding contact...', retryInfoText);
            startOperationTimeout('addContact', 'Still waiting for response. The message is being retried in the background. You can continue using the app and check back later.');
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
                startOperationTimeout('acceptContact', 'Still waiting for response. The message is being retried in the background. You can continue using the app and check back later.');
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

    // Send form — action field is mutated at submit time (sendEIOU vs createPaymentRequest)
    var sendForm = document.getElementById('send-form-el');
    if (sendForm) {
        sendForm.addEventListener('submit', function() {
            var actionField = document.getElementById('send-form-action');
            var action = actionField ? actionField.value : 'sendEIOU';
            if (action === 'createPaymentRequest') {
                showLoader('Sending payment request...', retryInfoText);
                startOperationTimeout('createPaymentRequest', 'Still waiting. The request is being retried in the background. You can continue using the app and check back later.');
            } else {
                showLoader('Sending transaction...', 'Processing your transaction. The message processor will continue retrying in the background.');
                startOperationTimeout('sendTransaction', 'Still waiting for response. The transaction is being retried in the background. Check your transaction history for updates.');
            }
        });
    }

    // Payment request — Approve & Pay (triggers a full sendEiou, can be slow over Tor)
    var approveForms = document.querySelectorAll('form input[name="action"][value="approvePaymentRequest"]');
    for (var i = 0; i < approveForms.length; i++) {
        var form = approveForms[i].closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                showLoader('Approving & sending payment...', 'Processing your transaction. This may take a moment over Tor.');
                startOperationTimeout('approvePayment', 'Still processing. Check your transaction history — the payment may have completed in the background.');
            });
        }
    }

    // Payment request — Decline (sends a Tor response message, can be slow)
    var declineForms = document.querySelectorAll('form input[name="action"][value="declinePaymentRequest"]');
    for (var i = 0; i < declineForms.length; i++) {
        var form = declineForms[i].closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                showLoader('Declining payment request...', retryInfoText);
                startOperationTimeout('declinePaymentRequest', 'Still waiting. The request has been declined locally. You can continue using the app and check back later.');
            });
        }
    }

    // Payment request — Cancel
    var cancelPrForms = document.querySelectorAll('form input[name="action"][value="cancelPaymentRequest"]');
    for (var i = 0; i < cancelPrForms.length; i++) {
        var form = cancelPrForms[i].closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                showLoader('Cancelling payment request...');
                startOperationTimeout('cancelPaymentRequest', 'Still waiting. The request has been cancelled locally. You can continue using the app and check back later.');
            });
        }
    }
}

/**
 * Initializes shared name fields and Accept All buttons for pending contact currency forms.
 *
 * Shared name: A single Name input above all currency forms for a contact.
 * On form submit, the name value is copied to a hidden field inside the form.
 *
 * Accept All: Sequentially submits all currency forms for a contact via fetch,
 * then reloads the page to show results.
 */
function initializeCurrencyAcceptHandlers() {
    // Before form submit, copy shared name to hidden field
    var currencyForms = document.querySelectorAll('.currency-accept-form');
    for (var i = 0; i < currencyForms.length; i++) {
        (function(form) {
            form.addEventListener('submit', function(e) {
                var sharedNameId = form.getAttribute('data-shared-name-id');
                if (sharedNameId) {
                    var nameInput = document.getElementById(sharedNameId);
                    var target = form.querySelector('.shared-name-target');
                    if (nameInput && target) {
                        var nameVal = nameInput.value.trim();
                        if (!nameVal) {
                            e.preventDefault();
                            nameInput.focus();
                            nameInput.style.borderColor = '#dc3545';
                            if (typeof showToast === 'function') {
                                showToast('Required', 'Please enter a name for this contact', 'warning');
                            }
                            return false;
                        }
                        target.value = nameVal;
                    }
                }
            });
        })(currencyForms[i]);
    }

    // Accept All form handler — collects fee/credit from individual currency forms
    var acceptAllForms = document.querySelectorAll('.accept-all-form');
    for (var j = 0; j < acceptAllForms.length; j++) {
        (function(form) {
            form.addEventListener('submit', function(e) {
                var card = form.closest('.pending-contact-accept-form');
                if (!card) { e.preventDefault(); return; }

                // Validate shared name first
                var nameInput = card.querySelector('.shared-name-input');
                if (nameInput && !nameInput.value.trim()) {
                    e.preventDefault();
                    nameInput.focus();
                    nameInput.style.borderColor = '#dc3545';
                    if (typeof showToast === 'function') {
                        showToast('Required', 'Please enter a name for this contact', 'warning');
                    }
                    return;
                }

                // Copy shared name into the Accept All form's hidden field (for new contacts)
                var nameTarget = form.querySelector('.accept-all-name-target');
                if (nameTarget && nameInput) {
                    nameTarget.value = nameInput.value.trim();
                }

                // Collect currency data from individual forms
                // Field names differ: existing contacts use "currency"/"fee"/"credit",
                // new contacts use "contact_currency"/"contact_fee"/"contact_credit"
                var currencyForms = card.querySelectorAll('.currency-accept-form');
                var currencies = [];
                for (var k = 0; k < currencyForms.length; k++) {
                    var cf = currencyForms[k];
                    var currency = cf.querySelector('input[name="currency"]') || cf.querySelector('input[name="contact_currency"]');
                    var fee = cf.querySelector('input[name="fee"]') || cf.querySelector('input[name="contact_fee"]');
                    var credit = cf.querySelector('input[name="credit"]') || cf.querySelector('input[name="contact_credit"]');
                    if (currency && fee && credit) {
                        currencies.push({
                            currency: currency.value,
                            fee: fee.value,
                            credit: credit.value
                        });
                    }
                }

                if (currencies.length === 0) {
                    e.preventDefault();
                    return;
                }

                // Set the JSON data into the hidden field
                form.querySelector('.accept-all-currencies-data').value = JSON.stringify(currencies);

                // Show loading state
                var btn = form.querySelector('.accept-all-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Accepting...';
                }
            });
        })(acceptAllForms[j]);
    }
}

// Add to existing DOMContentLoaded
window.addEventListener('DOMContentLoaded', function() {
    initializeFormLoaders();
    initializeCurrencyAcceptHandlers();
});

/**
 * Copies text to the clipboard with multiple fallback strategies for browser compatibility.
 *
 * This function implements a progressive fallback approach to maximize compatibility,
 * especially with privacy-focused browsers like Tor Browser:
 *
 * 1. First attempts document.execCommand('copy') - most reliable in restrictive environments
 * 2. Falls back to the modern Clipboard API (navigator.clipboard.writeText)
 * 3. If both methods fail, shows a manual copy modal where users can select and copy text
 *
 * @param {string} text - The text content to copy to the clipboard
 * @param {string} [successMessage='Copied to clipboard!'] - Custom message to display in the
 *                                                           success toast notification
 * @returns {void}
 * @example
 * // Copy an address with default success message
 * copyToClipboard('http://example.onion/api');
 *
 * // Copy with custom success message
 * copyToClipboard(contact.pubkey, 'Public key copied!');
 */
function copyToClipboard(text, successMessage) {
    successMessage = successMessage || 'Copied to clipboard!';

    // Try execCommand first - more reliable in Tor Browser and restrictive environments
    if (tryExecCommandCopy(text)) {
        showToast('Success', successMessage, 'success');
        return;
    }

    // Fall back to modern Clipboard API
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text)
            .then(function() {
                showToast('Success', successMessage, 'success');
            })
            .catch(function() {
                // Both methods failed - show manual copy modal
                showManualCopyModal(text, successMessage);
            });
    } else {
        // No clipboard methods available - show manual copy modal
        showManualCopyModal(text, successMessage);
    }
}

/**
 * Attempts to copy text using the legacy execCommand method.
 *
 * Creates a temporary hidden textarea, populates it with the text,
 * selects the content, and executes the copy command. This synchronous
 * method is more reliable in Tor Browser and other restrictive environments
 * that may block the modern Clipboard API.
 *
 * @param {string} text - The text to copy to clipboard
 * @returns {boolean} True if the copy was successful, false otherwise
 * @example
 * if (tryExecCommandCopy('Hello World')) {
 *     console.log('Copy succeeded');
 * }
 */
function tryExecCommandCopy(text) {
    var tempTextarea = document.createElement('textarea');
    tempTextarea.value = text;
    tempTextarea.style.position = 'fixed';
    tempTextarea.style.left = '-9999px';
    tempTextarea.style.top = '0';
    // Prevent iOS zoom on focus
    tempTextarea.style.fontSize = '16px';
    document.body.appendChild(tempTextarea);
    tempTextarea.focus();
    tempTextarea.select();

    var successful = false;
    try {
        successful = document.execCommand('copy');
    } catch (err) {
        // execCommand threw an error
    }

    document.body.removeChild(tempTextarea);
    return successful;
}

/**
 * Shows a modal for manual text copying when programmatic copy fails.
 *
 * Creates a modal overlay with a visible, pre-selected textarea containing
 * the text to copy. The user can then use Ctrl+C/Cmd+C to manually copy.
 * This is the last-resort fallback for browsers that block all clipboard APIs.
 * Listens for the copy event to automatically close the modal and show success.
 *
 * @param {string} text - The text to display for manual copying
 * @param {string} successMessage - The message to display in the success toast after copying
 * @returns {void}
 * @example
 * // Show manual copy modal as fallback
 * showManualCopyModal('http://example.onion', 'Address copied!');
 */
function showManualCopyModal(text, successMessage) {
    // Create overlay
    var overlay = document.createElement('div');
    overlay.id = 'manual-copy-overlay';
    overlay.className = 'manual-copy-overlay';

    // Create modal
    var modal = document.createElement('div');
    modal.className = 'manual-copy-modal';
    modal.innerHTML = '<h4 class="manual-copy-title">Copy to Clipboard</h4>' +
        '<p class="manual-copy-desc">Select the text below and press <strong>Ctrl+C</strong> (or <strong>Cmd+C</strong> on Mac) to copy:</p>' +
        '<textarea id="manual-copy-text" readonly class="manual-copy-textarea"></textarea>' +
        '<div class="manual-copy-actions">' +
        '<button id="manual-copy-close" class="manual-copy-close">Close</button>' +
        '</div>';

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    var textArea = document.getElementById('manual-copy-text');
    textArea.value = text;
    textArea.focus();
    textArea.select();

    /**
     * Closes the manual copy modal and cleans up event listeners.
     * Removes the overlay from DOM and detaches keyboard listener.
     * @private
     */
    function closeModal() {
        if (document.body.contains(overlay)) {
            document.body.removeChild(overlay);
        }
        document.removeEventListener('keydown', escHandler);
    }

    /**
     * Handles Escape key press to close the modal.
     * @param {KeyboardEvent} e - The keyboard event
     * @private
     */
    function escHandler(e) {
        var isEscape = e.key === 'Escape' || e.keyCode === 27;
        if (isEscape) {
            closeModal();
        }
    }

    document.getElementById('manual-copy-close').onclick = closeModal;
    overlay.onclick = function(e) {
        if (e.target === overlay) {
            closeModal();
        }
    };
    document.addEventListener('keydown', escHandler);

    // Listen for successful copy - close modal and show success toast
    textArea.addEventListener('copy', function() {
        showToast('Success', successMessage, 'success');
        closeModal();
    });
}

// ============================================================================
// CONTACT SECTION FUNCTIONS
// ============================================================================

/**
 * Updates the visibility of the left/right contacts scroll buttons
 * based on the current scroll position of the contacts grid.
 * Hides the left button when scrolled to the start and the right
 * button when scrolled to the end.
 */
function updateContactsScrollButtons() {
    var grid = document.getElementById('contacts-grid');
    if (!grid) return;
    var leftBtn = document.getElementById('contacts-scroll-left');
    var rightBtn = document.getElementById('contacts-scroll-right');
    if (leftBtn) {
        if (grid.scrollLeft <= 0) {
            leftBtn.className = leftBtn.className.replace(' hidden', '') + ' hidden';
        } else {
            leftBtn.className = leftBtn.className.replace(' hidden', '');
        }
    }
    if (rightBtn) {
        // 1px tolerance for rounding
        var atEnd = grid.scrollLeft + grid.clientWidth >= grid.scrollWidth - 1;
        if (atEnd) {
            rightBtn.className = rightBtn.className.replace(' hidden', '') + ' hidden';
        } else {
            rightBtn.className = rightBtn.className.replace(' hidden', '');
        }
    }
}

/**
 * Scrolls the contacts grid left or right by one card width.
 * @param {number} direction - -1 for left, 1 for right
 */
function scrollContacts(direction) {
    var grid = document.getElementById('contacts-grid');
    if (!grid) return;
    // Card width (250px) + gap (16px)
    var scrollAmount = 266 * direction;
    grid.scrollLeft = grid.scrollLeft + scrollAmount;
    // Delay update to let scroll settle
    setTimeout(updateContactsScrollButtons, 50);
}

// Update scroll buttons on page load and when the grid is scrolled
(function() {
    var initScrollButtons = function() {
        var grid = document.getElementById('contacts-grid');
        if (!grid) return;
        grid.addEventListener('scroll', updateContactsScrollButtons);
        updateContactsScrollButtons();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScrollButtons);
    } else {
        initScrollButtons();
    }
})();

/**
 * Updates the visibility of the quick actions scroll buttons
 * based on the current scroll position.
 */
function updateQuickActionsScrollButtons() {
    var grid = document.getElementById('quick-actions-grid');
    if (!grid) return;
    var leftBtn = document.getElementById('quick-actions-scroll-left');
    var rightBtn = document.getElementById('quick-actions-scroll-right');
    // Only show buttons when content overflows
    var hasOverflow = grid.scrollWidth > grid.clientWidth + 1;
    if (leftBtn) {
        if (!hasOverflow || grid.scrollLeft <= 0) {
            leftBtn.className = leftBtn.className.replace(' hidden', '') + ' hidden';
        } else {
            leftBtn.className = leftBtn.className.replace(' hidden', '');
        }
    }
    if (rightBtn) {
        var atEnd = grid.scrollLeft + grid.clientWidth >= grid.scrollWidth - 1;
        if (!hasOverflow || atEnd) {
            rightBtn.className = rightBtn.className.replace(' hidden', '') + ' hidden';
        } else {
            rightBtn.className = rightBtn.className.replace(' hidden', '');
        }
    }
}

/**
 * Scrolls the quick actions grid left or right by one card width.
 * @param {number} direction - -1 for left, 1 for right
 */
function scrollQuickActions(direction) {
    var grid = document.getElementById('quick-actions-grid');
    if (!grid) return;
    var firstCard = grid.querySelector('.action-card');
    var cardWidth = firstCard ? firstCard.offsetWidth : 218;
    var gap = parseInt(getComputedStyle(grid).gap) || 16;
    var scrollAmount = (cardWidth + gap) * direction;
    grid.scrollLeft = grid.scrollLeft + scrollAmount;
    setTimeout(updateQuickActionsScrollButtons, 50);
}

// Update quick actions scroll buttons on load, scroll, and resize
(function() {
    var initQuickActionsScroll = function() {
        var grid = document.getElementById('quick-actions-grid');
        if (!grid) return;
        grid.addEventListener('scroll', updateQuickActionsScrollButtons);
        updateQuickActionsScrollButtons();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initQuickActionsScroll);
    } else {
        initQuickActionsScroll();
    }
    window.addEventListener('resize', updateQuickActionsScrollButtons);
})();

// Contact Modal Functions (Tor Browser compatible - uses var and for loops)
var currentContactId = null;
var currentContactPubkeyHash = null;
var contactTransactionData = [];
var currentContactCurrencies = [];
var currentContactBalances = {};
var contactsShowAll = false;
var CONTACTS_DEFAULT_LIMIT = 16;

/**
 * Filters the contact list based on a search term entered by the user.
 *
 * Performs case-insensitive substring matching against contact names and addresses.
 * When searching, all matching contacts are displayed regardless of the pagination limit.
 * When the search is cleared, the display respects the current show all/limited state.
 *
 * The function updates:
 * - Contact card visibility (display: '' or display: 'none')
 * - Search status indicator showing match count
 * - Show more button visibility (hidden during active search)
 *
 * Uses Tor Browser compatible code (var declarations, indexOf instead of includes).
 *
 * @returns {void}
 * @example
 * // Called from search input oninput event
 * <input type="text" id="contact-search-input" oninput="filterContacts()">
 */
function filterContacts() {
    var searchInput = document.getElementById('contact-search-input');
    var searchStatus = document.getElementById('contact-search-status');
    var searchCount = document.getElementById('contact-search-count');
    var showMoreBtn = document.getElementById('contacts-show-more');

    if (!searchInput) return;

    var searchTerm = searchInput.value.toLowerCase().trim();
    var contactCards = document.querySelectorAll('.contact-card');
    var visibleCount = 0;

    for (var i = 0; i < contactCards.length; i++) {
        var card = contactCards[i];
        var contactName = card.getAttribute('data-contact-name') || '';
        var contactAddress = card.getAttribute('data-contact-address') || '';
        var matchesSearch = contactName.indexOf(searchTerm) !== -1 || contactAddress.indexOf(searchTerm) !== -1;

        if (searchTerm === '' || matchesSearch) {
            // Show card if matches search (respecting limit when not searching)
            if (searchTerm === '') {
                // When not searching, respect the show all / limited state
                if (contactsShowAll || visibleCount < CONTACTS_DEFAULT_LIMIT) {
                    card.style.display = '';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            } else {
                // When searching, show all matches
                card.style.display = '';
                visibleCount++;
            }
        } else {
            card.style.display = 'none';
        }
    }

    // Update search status
    if (searchStatus && searchCount) {
        if (searchTerm !== '') {
            searchStatus.style.display = 'block';
            searchCount.textContent = visibleCount;
        } else {
            searchStatus.style.display = 'none';
        }
    }

    // Hide show more button when searching
    if (showMoreBtn) {
        showMoreBtn.style.display = searchTerm !== '' ? 'none' : '';
    }

    // Update scroll button visibility after filtering
    setTimeout(updateContactsScrollButtons, 50);
}

/**
 * Toggles between showing all contacts and showing only the first 16.
 *
 * When collapsed, only the first 16 contacts (CONTACTS_DEFAULT_LIMIT) are visible.
 * When expanded, all contacts are shown. Updates the button text to reflect
 * the current state. Uses Tor Browser compatible code (var declarations, for loops).
 *
 * @returns {void}
 * @example
 * // Called from "Show All" button onclick
 * <button id="show-more-btn" onclick="toggleShowAllContacts()">Show All</button>
 */
function toggleShowAllContacts() {
    contactsShowAll = !contactsShowAll;

    var showMoreBtn = document.getElementById('show-more-btn');
    var hiddenCount = document.getElementById('hidden-contacts-count');
    var contactCards = document.querySelectorAll('.contact-card');
    var totalContacts = contactCards.length;

    if (contactsShowAll) {
        // Show all contacts
        for (var i = 0; i < contactCards.length; i++) {
            contactCards[i].style.display = '';
        }
        if (showMoreBtn) {
            showMoreBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Show Less';
        }
    } else {
        // Show only first 16 contacts
        for (var j = 0; j < contactCards.length; j++) {
            if (j < CONTACTS_DEFAULT_LIMIT) {
                contactCards[j].style.display = '';
            } else {
                contactCards[j].style.display = 'none';
            }
        }
        if (showMoreBtn && hiddenCount) {
            showMoreBtn.innerHTML = '<i class="fas fa-chevron-right"></i> Show All (<span id="hidden-contacts-count">' + (totalContacts - CONTACTS_DEFAULT_LIMIT) + '</span> more)';
        }
    }

    // Update scroll button visibility after toggling
    setTimeout(updateContactsScrollButtons, 50);
}

/**
 * Initializes the contact list display with pagination limit.
 *
 * Hides all contact cards beyond the first 16 (CONTACTS_DEFAULT_LIMIT) on page load.
 * This improves initial page performance for users with many contacts and provides
 * a cleaner UI. Users can click "Show All" to see the remaining contacts.
 *
 * @returns {void}
 * @example
 * // Called automatically on DOMContentLoaded
 * window.addEventListener('DOMContentLoaded', function() {
 *     initContactsDisplay();
 * });
 */
function initContactsDisplay() {
    var contactCards = document.querySelectorAll('.contact-card');
    if (contactCards.length > CONTACTS_DEFAULT_LIMIT) {
        for (var i = CONTACTS_DEFAULT_LIMIT; i < contactCards.length; i++) {
            contactCards[i].style.display = 'none';
        }
    }
}

/**
 * Shows a small info modal with the text from an info icon's title attribute.
 * Used so touch/mobile users can read tooltip text without hover.
 *
 * @param {HTMLElement} el - The info icon element with a title attribute
 */
function showInfoModal(el) {
    var text = el.getAttribute('title') || '';
    if (!text) return;

    var overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.id = 'info-modal';
    overlay.innerHTML =
        '<div class="modal-content" style="max-width:340px">' +
            '<div class="modal-header">' +
                '<h3 style="font-size:1rem"><i class="fas fa-info-circle" style="color:#6c757d"></i> Info</h3>' +
                '<span class="close" id="info-modal-close" title="Close">&times;</span>' +
            '</div>' +
            '<div class="modal-body" style="padding:1.25rem;font-size:0.9rem;line-height:1.5">' +
                escapeHtml(text) +
            '</div>' +
        '</div>';

    function closeInfoModal() {
        if (document.body.contains(overlay)) {
            document.body.removeChild(overlay);
        }
        document.removeEventListener('keydown', escHandler);
    }

    function escHandler(e) {
        if (e.key === 'Escape' || e.keyCode === 27) { closeInfoModal(); }
    }

    overlay.querySelector('#info-modal-close').onclick = closeInfoModal;
    overlay.onclick = function(e) { if (e.target === overlay) { closeInfoModal(); } };
    document.addEventListener('keydown', escHandler);
    document.body.appendChild(overlay);
}

/**
 * Opens the contact detail modal with all contact information and transaction history.
 *
 * This function populates the modal with contact details including addresses (HTTP, HTTPS, TOR),
 * balance information, credit limits, status badges, and recent transactions. It also sets up
 * the edit form and action buttons based on the contact's current status.
 *
 * @param {Object} contact - The contact data object containing all contact information
 * @param {string} contact.contact_id - Unique identifier for the contact
 * @param {string} contact.name - Display name of the contact
 * @param {string} [contact.http] - HTTP address of the contact
 * @param {string} [contact.https] - HTTPS address of the contact
 * @param {string} [contact.tor] - TOR (.onion) address of the contact
 * @param {string} [contact.pubkey] - Public key of the contact
 * @param {string} contact.address - Primary address used for form actions
 * @param {number|string} [contact.balance=0] - Current balance with this contact
 * @param {number|string} [contact.credit_limit=0] - Credit limit set for this contact
 * @param {number|string} [contact.fee=0] - Transaction fee percentage for this contact
 * @param {string} [contact.currency='USD'] - Currency code for balance display
 * @param {string} contact.status - Contact status ('accepted', 'pending', 'blocked')
 * @param {string} [contact.online_status] - Online status ('online', 'offline', 'unknown')
 * @param {boolean|number} [contact.valid_chain] - Chain validation status (true/1=valid, false/0=invalid, null=not checked)
 * @param {Array<Object>} [contact.transactions=[]] - Array of recent transactions with this contact
 * @param {string} [openTab='info-tab'] - ID of the tab to open initially ('info-tab', 'transactions-tab', 'edit-tab', 'actions-tab')
 * @returns {void}
 * @example
 * // Open contact modal to info tab (default)
 * openContactModal(contactData);
 *
 * // Open contact modal directly to transactions tab
 * openContactModal(contactData, 'transactions-tab');
 */
function openContactModal(contact, openTab) {
    // Store current contact ID for refresh
    currentContactId = contact.contact_id;
    // Store transactions for detail view
    contactTransactionData = contact.transactions || [];

    // Set contact name in header
    document.getElementById('modal_contact_name').textContent = contact.name || 'Unknown';

    // Set addresses dropdown
    var addressSelector = document.getElementById('modal_address_selector');
    var addressDisplay = document.getElementById('modal_address_display');
    addressSelector.innerHTML = '';

    var addresses = [];
    if (contact.tor) {
        addresses.push({ type: 'TOR', address: contact.tor, icon: 'fa-user-secret' });
    }
    if (contact.https) {
        addresses.push({ type: 'HTTPS', address: contact.https, icon: 'fa-globe' });
    }
    if (contact.http) {
        addresses.push({ type: 'HTTP', address: contact.http, icon: 'fa-globe' });
    }

    if (addresses.length === 0) {
        addressSelector.style.display = 'none';
        addressDisplay.textContent = 'Not Available';
    } else if (addresses.length === 1) {
        addressSelector.style.display = 'none';
        addressDisplay.textContent = addresses[0].address;
    } else {
        addressSelector.style.display = 'block';
        for (var i = 0; i < addresses.length; i++) {
            var option = document.createElement('option');
            option.value = addresses[i].type;
            option.setAttribute('data-address', addresses[i].address);
            option.textContent = addresses[i].type;
            addressSelector.appendChild(option);
        }
        addressDisplay.textContent = addresses[0].address;
    }

    // Set public key
    document.getElementById('modal_pubkey').textContent = contact.pubkey || 'Not Available';

    // Set contact ID
    document.getElementById('modal_contact_id').textContent = contact.contact_id || 'Not Available';

    // Set balance (no color styling)
    var balance = parseFloat(contact.balance) || 0;
    var balanceEl = document.getElementById('modal_balance');
    balanceEl.textContent = (balance >= 0 ? '+' : '') + balance.toFixed(EIOU_DISPLAY_DECIMALS);
    balanceEl.className = 'balance-amount';
    // Derive initial currency, credit_limit, and fee from first accepted currency
    var primaryCur = (contact.currencies && contact.currencies.length > 0) ? contact.currencies[0] : null;
    var currency = primaryCur ? primaryCur.currency : 'USD';
    document.getElementById('modal_balance_currency').textContent = currency;

    // Set credit limit, available credits (both directions), and fee
    var creditLimit = primaryCur ? (parseFloat(primaryCur.credit_limit) || 0) : 0;
    document.getElementById('modal_credit_limit').textContent = creditLimit.toFixed(EIOU_DISPLAY_DECIMALS);
    document.getElementById('modal_credit_currency').textContent = currency;

    // My available credit with them (from pong)
    var myAvailableCreditEl = document.getElementById('modal_my_available_credit');
    if (myAvailableCreditEl) {
        var myAvailCredit = primaryCur ? primaryCur.my_available_credit : contact.my_available_credit;
        myAvailableCreditEl.textContent = (myAvailCredit !== null && myAvailCredit !== undefined)
            ? parseFloat(myAvailCredit).toFixed(EIOU_DISPLAY_DECIMALS) : '—';
    }
    var myCreditCurrencyEl = document.getElementById('modal_my_credit_currency');
    if (myCreditCurrencyEl) myCreditCurrencyEl.textContent = currency;

    // Their available credit with me (calculated)
    var theirAvailableCreditEl = document.getElementById('modal_their_available_credit');
    if (theirAvailableCreditEl) {
        var theirAvailCredit = primaryCur ? primaryCur.their_available_credit : contact.their_available_credit;
        theirAvailableCreditEl.textContent = (theirAvailCredit !== null && theirAvailCredit !== undefined)
            ? parseFloat(theirAvailCredit).toFixed(EIOU_DISPLAY_DECIMALS) : '—';
    }
    var theirCreditCurrencyEl = document.getElementById('modal_their_credit_currency');
    if (theirCreditCurrencyEl) theirCreditCurrencyEl.textContent = currency;

    var fee = primaryCur ? (parseFloat(primaryCur.fee) || 0) : 0;
    document.getElementById('modal_fee').textContent = fee.toFixed(2);

    // Populate currency slider for multi-currency contacts
    var currencySelectorSection = document.getElementById('currency-selector-section');
    var sliderTrack = document.getElementById('currency_slider_track');
    if (currencySelectorSection && sliderTrack) {
        var currencies = contact.currencies || [];
        currentContactCurrencies = currencies;
        currentContactBalances = contact.balances_by_currency || {};
        if (currencies.length > 1) {
            currencySelectorSection.style.display = 'block';
            sliderTrack.innerHTML = '';
            for (var i = 0; i < currencies.length; i++) {
                var pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'currency-slider-pill' + (currencies[i].currency === currency ? ' active' : '');
                pill.textContent = currencies[i].currency;
                pill.setAttribute('data-currency', currencies[i].currency);
                pill.setAttribute('data-action', 'currencySliderSelect');
                sliderTrack.appendChild(pill);
            }
            updateCurrencySliderArrows();
        } else {
            currencySelectorSection.style.display = 'none';
            sliderTrack.innerHTML = '';
        }
    }

    // Populate pending currency requests section (read-only info in modal)
    var pendingCurrencySection = document.getElementById('pending-currency-section');
    var pendingCurrencyContainer = document.getElementById('pending-currency-container');
    if (pendingCurrencySection && pendingCurrencyContainer) {
        var pendingCurrencies = contact.pending_currencies || []; // incoming: they requested from us
        var outgoingCurrencies = contact.outgoing_currencies || []; // outgoing: we requested from them
        if (pendingCurrencies.length > 0 || outgoingCurrencies.length > 0) {
            pendingCurrencySection.style.display = 'block';
            var sectionHeading = pendingCurrencySection.querySelector('h4');
            if (sectionHeading) {
                sectionHeading.innerHTML = '<i class="fas fa-exchange-alt"></i> Currency Requests';
            }
            var phtml = '';

            // Show outgoing requests (we sent, awaiting their acceptance)
            if (outgoingCurrencies.length > 0) {
                phtml += '<div class="mb-md"><strong><i class="fas fa-paper-plane"></i> Your requests:</strong></div>';
                for (var oi = 0; oi < outgoingCurrencies.length; oi++) {
                    var oc = outgoingCurrencies[oi];
                    phtml += '<div class="d-flex gap-sm align-items-center mb-sm">';
                    phtml += '<span class="badge badge-warning">' + escapeHtml(oc.currency) + '</span>';
                    phtml += '<span class="text-muted">Awaiting their acceptance</span>';
                    phtml += '</div>';
                }
            }

            // Show incoming requests (read-only — accept via Pending Contact Requests section)
            if (pendingCurrencies.length > 0) {
                phtml += '<div class="mb-md"><strong><i class="fas fa-inbox"></i> Their requests:</strong></div>';
                for (var pi = 0; pi < pendingCurrencies.length; pi++) {
                    var pc = pendingCurrencies[pi];
                    phtml += '<div class="d-flex gap-sm align-items-center mb-sm">';
                    phtml += '<span class="badge badge-info">' + escapeHtml(pc.currency) + '</span>';
                    phtml += '<span class="text-muted">Accept via Pending Contact Requests section</span>';
                    phtml += '</div>';
                }
            }

            pendingCurrencyContainer.innerHTML = phtml;
        } else {
            pendingCurrencySection.style.display = 'none';
            pendingCurrencyContainer.innerHTML = '';
        }
    }

    // Set status badge
    var statusBadge = document.getElementById('modal_status_badge');
    statusBadge.textContent = contact.status.charAt(0).toUpperCase() + contact.status.slice(1);
    statusBadge.className = 'badge badge-' + contact.status;

    // Set online status (online/offline/unknown)
    var onlineStatusEl = document.getElementById('modal_online_status');
    if (onlineStatusEl) {
        var onlineStatus = contact.online_status || 'unknown';
        var statusText = onlineStatus.charAt(0).toUpperCase() + onlineStatus.slice(1);
        onlineStatusEl.textContent = statusText;
        onlineStatusEl.className = 'status-badge status-' + onlineStatus;
    }

    // Set chain status (valid/invalid/not checked) — proposal takes priority
    var chainStatusEl = document.getElementById('modal_chain_status');
    if (chainStatusEl) {
        var validChain = contact.valid_chain;
        var proposal = contact.chain_drop_proposal;
        var chainText;
        var chainClass;
        var isClickable = false;

        // Proposal state overrides chain validity display
        if (proposal && proposal.direction === 'incoming' && proposal.status === 'pending') {
            chainText = 'Action Required';
            chainClass = 'chain-action-required';
            isClickable = true;
        } else if (proposal && proposal.direction === 'outgoing' && proposal.status === 'pending') {
            chainText = 'Awaiting Acceptance';
            chainClass = 'chain-awaiting';
            isClickable = true;
        } else if (proposal && proposal.status === 'rejected') {
            chainText = 'Blocked';
            chainClass = 'chain-rejected';
            isClickable = true;
        } else if (validChain === null || validChain === undefined) {
            chainText = 'Not Checked';
            chainClass = 'chain-unknown';
        } else if (validChain === true || validChain === 1) {
            chainText = 'Valid';
            chainClass = 'chain-valid';
        } else {
            chainText = 'Chain Gap';
            chainClass = 'chain-invalid';
            isClickable = true;
        }
        chainStatusEl.textContent = chainText;
        chainStatusEl.className = 'chain-badge ' + chainClass + (isClickable ? ' chain-clickable' : '');

        // Make clickable to scroll to chain drop section
        if (isClickable) {
            chainStatusEl.onclick = function() {
                var section = document.getElementById('chain_drop_section');
                if (section && section.style.display !== 'none') {
                    section.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            };
            chainStatusEl.title = 'Click to view chain gap resolution';
        } else {
            chainStatusEl.onclick = null;
            chainStatusEl.title = '';
        }
    }

    // Show chain drop section if chain is invalid
    var chainDropSection = document.getElementById('chain_drop_section');
    var chainDropPropose = document.getElementById('chain_drop_propose');
    var chainDropAwaiting = document.getElementById('chain_drop_awaiting');
    var chainDropIncoming = document.getElementById('chain_drop_incoming');
    var chainDropRejected = document.getElementById('chain_drop_rejected');

    if (chainDropSection) {
        // Reset all sub-sections
        if (chainDropPropose) chainDropPropose.style.display = 'none';
        if (chainDropAwaiting) chainDropAwaiting.style.display = 'none';
        if (chainDropIncoming) chainDropIncoming.style.display = 'none';
        if (chainDropRejected) chainDropRejected.style.display = 'none';
        currentChainDropProposalId = null;

        if (contact.chain_drop_proposal) {
            chainDropSection.style.display = 'block';
            var proposal = contact.chain_drop_proposal;
            if (proposal.direction === 'incoming' && proposal.status === 'pending') {
                if (chainDropIncoming) {
                    chainDropIncoming.style.display = 'block';
                    var incomingIdEl = document.getElementById('chain_drop_incoming_id');
                    if (incomingIdEl) {
                        incomingIdEl.textContent = 'Proposal: ' + proposal.proposal_id;
                    }
                }
                currentChainDropProposalId = proposal.proposal_id;
            } else if (proposal.direction === 'outgoing' && proposal.status === 'pending') {
                if (chainDropAwaiting) {
                    chainDropAwaiting.style.display = 'block';
                    var awaitingIdEl = document.getElementById('chain_drop_awaiting_id');
                    if (awaitingIdEl) {
                        awaitingIdEl.textContent = 'Proposal: ' + proposal.proposal_id;
                    }
                }
            } else if (proposal.status === 'rejected') {
                if (chainDropRejected) chainDropRejected.style.display = 'block';
            }
        } else if (contact.valid_chain === false || contact.valid_chain === 0) {
            // Chain is invalid but no proposal exists yet — show propose button
            chainDropSection.style.display = 'block';
            if (chainDropPropose) chainDropPropose.style.display = 'block';
        } else {
            chainDropSection.style.display = 'none';
        }

        // Populate chain gap details if available
        var gapDetailsEl = document.getElementById('chain_gap_details');
        if (gapDetailsEl) {
            gapDetailsEl.innerHTML = '';
            gapDetailsEl.style.display = 'none';
            if (contact.chain_gap_details && contact.chain_gap_details.length > 0) {
                gapDetailsEl.style.display = 'block';
                var html = '<div class="chain-gap-container">';
                html += '<div class="chain-gap-title"><i class="fas fa-search"></i> Gap Details (' + contact.chain_gap_details.length + ' gap' + (contact.chain_gap_details.length > 1 ? 's' : '') + ')</div>';
                for (var i = 0; i < contact.chain_gap_details.length; i++) {
                    var gap = contact.chain_gap_details[i];
                    var shortMissing = gap.missing_txid ? gap.missing_txid.substring(0, 12) + '...' : '?';
                    var shortBefore = gap.before_txid ? gap.before_txid.substring(0, 12) + '...' : '(start)';
                    var shortAfter = gap.after_txid ? gap.after_txid.substring(0, 12) + '...' : '(end)';
                    html += '<div class="' + (i > 0 ? 'chain-gap-row chain-gap-row-separator' : 'chain-gap-row') + '">';
                    html += '<span class="chain-gap-valid" title="' + (gap.before_txid || 'chain start') + '">' + shortBefore + '</span>';
                    html += ' <i class="fas fa-arrow-right chain-gap-arrow"></i> ';
                    html += '<span class="chain-gap-missing" title="Missing: ' + (gap.missing_txid || '?') + '">' + shortMissing + '</span>';
                    html += ' <i class="fas fa-arrow-right chain-gap-arrow"></i> ';
                    html += '<span class="chain-gap-valid" title="' + (gap.after_txid || 'chain end') + '">' + shortAfter + '</span>';
                    html += '</div>';
                }
                html += '</div>';
                gapDetailsEl.innerHTML = html;
            }
        }
    }

    // Set form values
    document.getElementById('edit_contact_address').value = contact.address;
    document.getElementById('edit_contact_name').value = contact.name;

    // Populate currency dropdown from contact's accepted currencies
    var editCurrencySelect = document.getElementById('edit_contact_currency');
    editCurrencySelect.innerHTML = '';
    var acceptedCurrencies = (contact.currencies || []).filter(function(c) { return c.status === 'accepted'; });
    if (acceptedCurrencies.length > 0) {
        for (var ci = 0; ci < acceptedCurrencies.length; ci++) {
            var opt = document.createElement('option');
            opt.value = acceptedCurrencies[ci].currency;
            opt.textContent = acceptedCurrencies[ci].currency;
            editCurrencySelect.appendChild(opt);
        }
        // Select the first currency and load its fee/credit
        editCurrencySelect.value = acceptedCurrencies[0].currency;
        document.getElementById('edit_contact_fee').value = acceptedCurrencies[0].fee;
        document.getElementById('edit_contact_credit').value = acceptedCurrencies[0].credit_limit;
    } else {
        // Fallback: no accepted currencies
        var opt = document.createElement('option');
        opt.value = 'USD';
        opt.textContent = 'USD';
        editCurrencySelect.appendChild(opt);
        document.getElementById('edit_contact_fee').value = 0;
        document.getElementById('edit_contact_credit').value = 0;
    }

    // Set action form addresses
    document.getElementById('block_contact_address').value = contact.address;
    document.getElementById('unblock_contact_address').value = contact.address;
    document.getElementById('delete_contact_address').value = contact.address;

    // Store current contact address for ping function
    currentContactAddress = contact.address;

    // Store current contact pubkey hash for chain drop
    currentContactPubkeyHash = contact.pubkey_hash || null;

    // Reset ping button state and result message
    resetPingButton();
    var resultMsg = document.getElementById('ping_result_message');
    if (resultMsg) resultMsg.textContent = '';

    // Show/hide ping button based on contact status (only show for accepted contacts)
    var pingBtn = document.getElementById('ping_contact_btn');
    if (pingBtn) {
        if (contact.status === 'accepted') {
            pingBtn.style.display = 'inline-block';
        } else {
            pingBtn.style.display = 'none';
        }
    }

    // Show/hide block/unblock buttons based on status
    if (contact.status === 'blocked') {
        document.getElementById('blockForm').style.display = 'none';
        document.getElementById('unblockForm').style.display = 'inline';
    } else {
        document.getElementById('blockForm').style.display = 'inline';
        document.getElementById('unblockForm').style.display = 'none';
    }

    // Display transactions (Tor Browser compatible)
    var transactionsEl = document.getElementById('modal_transactions');
    var transactions = contact.transactions || [];

    if (transactions.length === 0) {
        transactionsEl.innerHTML = '<p class="no-transactions">No recent transactions with this contact.</p>';
    } else {
        var html = '';
        for (var i = 0; i < transactions.length; i++) {
            var tx = transactions[i];
            var typeClass = tx.type === 'sent' ? 'tx-sent' : 'tx-received';
            var typeIcon = tx.type === 'sent' ? 'fa-arrow-up' : 'fa-arrow-down';
            var typeLabel = tx.type === 'sent' ? 'Sent' : 'Received';
            var amountPrefix = tx.type === 'sent' ? '-' : '+';

            html += '<div class="transaction-item ' + typeClass + ' cursor-pointer" data-action="showContactTxDetail" data-index="' + i + '" title="Click for details">';
            html += '<div class="tx-icon"><i class="fas ' + typeIcon + '"></i></div>';
            html += '<div class="tx-details">';
            html += '<div class="tx-type">' + typeLabel + '</div>';
            html += '<div class="tx-date">' + escapeHtml(tx.date || 'Unknown date') + '</div>';
            html += '</div>';
            html += '<div class="tx-amount">' + amountPrefix + parseFloat(tx.amount).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency || 'USD') + '<i class="fas fa-chevron-right chevron-indicator"></i></div>';
            html += '</div>';
        }
        transactionsEl.innerHTML = html;
    }

    // Reset transaction view to list (in case detail was open before)
    var txListView = document.getElementById('tx-list-view');
    var txDetailView = document.getElementById('tx-detail-view');
    if (txListView) txListView.style.display = 'block';
    if (txDetailView) txDetailView.style.display = 'none';

    // Open specified tab or default to info tab
    var tabToOpen = openTab || 'info-tab';
    showModalTab(tabToOpen, null);

    // Show modal
    document.getElementById('contactModal').style.display = 'flex';
}

/**
 * Closes the contact detail modal.
 *
 * Hides the contact modal by setting its display style to 'none'.
 * Called when the user clicks the close button, clicks outside the modal,
 * or presses the Escape key.
 *
 * @returns {void}
 */
function closeContactModal() {
    document.getElementById('contactModal').style.display = 'none';
}

/**
 * Handles currency change in the edit contact settings form.
 * Loads fee and credit limit for the selected currency from stored contact data.
 */
function editCurrencyChanged(selectedCurrency) {
    if (!selectedCurrency || !currentContactCurrencies) return;
    for (var i = 0; i < currentContactCurrencies.length; i++) {
        if (currentContactCurrencies[i].currency === selectedCurrency) {
            document.getElementById('edit_contact_fee').value = currentContactCurrencies[i].fee;
            document.getElementById('edit_contact_credit').value = currentContactCurrencies[i].credit_limit;
            return;
        }
    }
}

/**
 * Switches the contact modal display to show data for the selected currency.
 * Updates balance, credit limit, fee, and available credit fields.
 */
/**
 * Update currency slider arrow enabled/disabled state
 */
function updateCurrencySliderArrows() {
    var track = document.getElementById('currency_slider_track');
    var prevBtn = document.getElementById('currency_slider_prev');
    var nextBtn = document.getElementById('currency_slider_next');
    if (!track || !prevBtn || !nextBtn) return;
    prevBtn.disabled = track.scrollLeft <= 0;
    nextBtn.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
}

/**
 * Slide the currency slider left or right
 */
function slideCurrencyTrack(direction) {
    var track = document.getElementById('currency_slider_track');
    if (!track) return;
    var scrollAmount = 100;
    track.scrollBy({ left: direction * scrollAmount, behavior: 'smooth' });
    setTimeout(updateCurrencySliderArrows, 300);
}

function switchContactCurrency(selectedCurrency) {
    if (!selectedCurrency) return;

    // Find currency config from stored currencies array
    var currencyConfig = null;
    for (var i = 0; i < currentContactCurrencies.length; i++) {
        if (currentContactCurrencies[i].currency === selectedCurrency) {
            currencyConfig = currentContactCurrencies[i];
            break;
        }
    }

    // Update balance from per-currency balances
    var balance = parseFloat(currentContactBalances[selectedCurrency]) || 0;
    var balanceEl = document.getElementById('modal_balance');
    if (balanceEl) {
        balanceEl.textContent = (balance >= 0 ? '+' : '') + balance.toFixed(EIOU_DISPLAY_DECIMALS);
        balanceEl.className = 'balance-amount';
    }
    var balanceCurrencyEl = document.getElementById('modal_balance_currency');
    if (balanceCurrencyEl) balanceCurrencyEl.textContent = selectedCurrency;

    if (currencyConfig) {
        // Update credit limit
        var creditLimit = parseFloat(currencyConfig.credit_limit) || 0;
        var creditLimitEl = document.getElementById('modal_credit_limit');
        if (creditLimitEl) creditLimitEl.textContent = creditLimit.toFixed(EIOU_DISPLAY_DECIMALS);

        var creditCurrencyEl = document.getElementById('modal_credit_currency');
        if (creditCurrencyEl) creditCurrencyEl.textContent = selectedCurrency;

        // Update fee
        var fee = parseFloat(currencyConfig.fee) || 0;
        var feeEl = document.getElementById('modal_fee');
        if (feeEl) feeEl.textContent = fee.toFixed(2);

        // Update available credits
        var myAvailEl = document.getElementById('modal_my_available_credit');
        if (myAvailEl) {
            myAvailEl.textContent = (currencyConfig.my_available_credit !== null && currencyConfig.my_available_credit !== undefined)
                ? parseFloat(currencyConfig.my_available_credit).toFixed(EIOU_DISPLAY_DECIMALS) : '\u2014';
        }
        var myCreditCurEl = document.getElementById('modal_my_credit_currency');
        if (myCreditCurEl) myCreditCurEl.textContent = selectedCurrency;

        var theirAvailEl = document.getElementById('modal_their_available_credit');
        if (theirAvailEl) {
            theirAvailEl.textContent = (currencyConfig.their_available_credit !== null && currencyConfig.their_available_credit !== undefined)
                ? parseFloat(currencyConfig.their_available_credit).toFixed(EIOU_DISPLAY_DECIMALS) : '\u2014';
        }
        var theirCreditCurEl = document.getElementById('modal_their_credit_currency');
        if (theirCreditCurEl) theirCreditCurEl.textContent = selectedCurrency;
    }
}

// Track current contact address for ping
var currentContactAddress = null;

/**
 * Pings a contact to check their online status and chain validity.
 *
 * Sends an AJAX POST request to the server with the 'pingContact' action.
 * The server attempts to connect to the contact's address and returns
 * their online status and chain validation state.
 *
 * The function flow:
 * 1. Validates that a contact address is available
 * 2. Disables the ping button and shows loading state
 * 3. Retrieves the CSRF token from the page
 * 4. Sends XMLHttpRequest POST to current page with form data
 * 5. Updates the online status badge (online/offline/unknown)
 * 6. Updates the chain status badge (Valid/Needs Sync/Not Checked)
 * 7. Displays result message and resets button state
 *
 * Uses Tor Browser compatible XMLHttpRequest with 60 second timeout
 * to account for slow Tor network connections.
 *
 * @returns {void}
 * @requires currentContactAddress - Global variable set by openContactModal
 * @example
 * // Called from ping button onclick
 * <button id="ping_contact_btn" onclick="pingContact()">Check Status</button>
 */
function pingContact() {
    var btn = document.getElementById('ping_contact_btn');
    var icon = document.getElementById('ping_icon');
    var btnText = document.getElementById('ping_btn_text');
    var resultMsg = document.getElementById('ping_result_message');
    var onlineStatusEl = document.getElementById('modal_online_status');
    var chainStatusEl = document.getElementById('modal_chain_status');

    if (!currentContactAddress) {
        if (resultMsg) {
            resultMsg.textContent = 'No contact address';
            resultMsg.style.color = '#dc3545';
        }
        return;
    }

    // Disable button and show loading state
    if (btn) btn.disabled = true;
    if (icon) icon.className = 'fas fa-spinner fa-spin';
    if (btnText) btnText.textContent = 'Checking...';
    if (resultMsg) resultMsg.textContent = '';

    // Get CSRF token (try multiple selectors for compatibility)
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken) {
        csrfToken = document.getElementById('csrf_token');
    }
    if (!csrfToken || !csrfToken.value) {
        if (resultMsg) {
            resultMsg.textContent = 'CSRF token not found';
            resultMsg.style.color = '#dc3545';
        }
        resetPingButton();
        return;
    }

    // Create form data for POST request (Tor Browser compatible)
    var formData = new FormData();
    formData.append('action', 'pingContact');
    formData.append('contact_address', currentContactAddress);
    formData.append('csrf_token', csrfToken.value);

    // Send AJAX request (Tor Browser compatible XMLHttpRequest)
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 60000; // 60 second timeout for Tor connections

    xhr.ontimeout = function() {
        resetPingButton();
        if (resultMsg) {
            resultMsg.textContent = 'Request timed out - contact may be offline';
            resultMsg.style.color = '#dc3545';
        }
        // Update status to offline on timeout
        if (onlineStatusEl) {
            onlineStatusEl.textContent = 'Offline';
            onlineStatusEl.className = 'status-badge status-offline';
        }
    };

    xhr.onerror = function() {
        resetPingButton();
        if (resultMsg) {
            resultMsg.textContent = 'Network error';
            resultMsg.style.color = '#dc3545';
        }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);

                    if (response.success) {
                        // Show brief success message then reload to persist changes
                        var onlineStatus = response.online_status || 'unknown';
                        if (resultMsg) {
                            resultMsg.textContent = response.message || 'Status updated, reloading...';
                            resultMsg.style.color = onlineStatus === 'online' ? '#28a745' : (onlineStatus === 'partial' ? '#fd7e14' : '#dc3545');
                        }
                        // Reload and reopen modal on info tab so updated values persist
                        if (currentContactId) {
                            var storedId = safeStorageSet('eiou_reopen_contact_id', currentContactId);
                            var storedTab = safeStorageSet('eiou_reopen_contact_tab', 'info-tab');
                            if (storedId && storedTab) {
                                window.location.reload();
                            } else {
                                // Tor Browser fallback
                                var currentUrl = window.location.href.split('#')[0];
                                window.location.href = currentUrl + '#reopen_contact=' + encodeURIComponent(currentContactId) + '&tab=info';
                                window.location.reload();
                            }
                        } else {
                            window.location.reload();
                        }
                        return;
                    } else {
                        resetPingButton();
                        // Show error message
                        if (resultMsg) {
                            resultMsg.textContent = response.message || 'Ping failed';
                            resultMsg.style.color = '#dc3545';
                        }
                    }
                } catch (e) {
                    resetPingButton();
                    if (resultMsg) {
                        resultMsg.textContent = 'Invalid response';
                        resultMsg.style.color = '#dc3545';
                    }
                }
            } else if (xhr.status !== 0) {
                resetPingButton();
                // Status 0 means aborted/timeout (handled separately)
                if (resultMsg) {
                    resultMsg.textContent = 'Request failed';
                    resultMsg.style.color = '#dc3545';
                }
            }
        }
    };
    xhr.send(formData);
}

/**
 * Resets the ping button to its default state.
 *
 * Re-enables the ping button and resets its icon and text from the loading
 * state back to the default "Check Status" state with wifi icon. Called
 * after a ping request completes (success, error, or timeout).
 *
 * @returns {void}
 */
function resetPingButton() {
    var btn = document.getElementById('ping_contact_btn');
    var icon = document.getElementById('ping_icon');
    var btnText = document.getElementById('ping_btn_text');

    if (btn) btn.disabled = false;
    if (icon) icon.className = 'fas fa-wifi';
    if (btnText) btnText.textContent = 'Check Status';
}

/**
 * Refreshes the page and reopens the contact modal on the transactions tab.
 *
 * Stores the current contact ID and target tab in sessionStorage (or URL hash
 * for Tor Browser) before reloading the page. After reload, checkReopenContactModal()
 * retrieves these values and reopens the modal to the transactions tab.
 *
 * @returns {void}
 * @example
 * // Called from "Refresh Transactions" button in contact modal
 * <button onclick="refreshContactModalTransactions()">Refresh</button>
 */
function refreshContactModalTransactions() {
    // Store the current contact ID to reopen after refresh
    if (currentContactId) {
        // Try sessionStorage first, fall back to URL hash
        var storedId = safeStorageSet('eiou_reopen_contact_id', currentContactId);
        var storedTab = safeStorageSet('eiou_reopen_contact_tab', 'transactions-tab');

        if (storedId && storedTab) {
            // Storage worked, simple reload
            window.location.reload();
        } else {
            // Storage failed (Tor Browser), use URL hash fallback
            var currentUrl = window.location.href.split('#')[0];
            window.location.href = currentUrl + '#reopen_contact=' + encodeURIComponent(currentContactId) + '&tab=transactions';
            window.location.reload();
        }
    } else {
        window.location.reload();
    }
}

/**
 * Checks if a contact modal should be reopened after page refresh.
 *
 * Retrieves the stored contact ID and tab from sessionStorage or URL hash
 * (Tor Browser fallback). If found, locates the matching contact card,
 * clicks it to open the modal, and switches to the appropriate tab.
 * Cleans up the stored data after processing.
 *
 * @returns {void}
 * @example
 * // Called automatically on DOMContentLoaded
 * window.addEventListener('DOMContentLoaded', function() {
 *     checkReopenContactModal();
 * });
 */
function checkReopenContactModal() {
    try {
        var reopenContactId = safeStorageGet('eiou_reopen_contact_id');
        var reopenTab = safeStorageGet('eiou_reopen_contact_tab');

        // Check URL hash fallback if storage didn't have the values
        if (!reopenContactId) {
            var hash = window.location.hash;
            if (hash && hash.indexOf('reopen_contact=') !== -1) {
                // Parse hash: #reopen_contact=ID&tab=transactions
                var hashContent = hash.substring(1); // Remove leading #
                var params = hashContent.split('&');
                for (var j = 0; j < params.length; j++) {
                    var pair = params[j].split('=');
                    if (pair[0] === 'reopen_contact') {
                        reopenContactId = decodeURIComponent(pair[1]);
                    } else if (pair[0] === 'tab') {
                        reopenTab = pair[1] + '-tab';
                    }
                }
                // Clean up URL by removing the hash (without reload)
                if (window.history && window.history.replaceState) {
                    var cleanUrl = window.location.href.split('#')[0];
                    window.history.replaceState({}, document.title, cleanUrl);
                }
            }
        }

        if (reopenContactId) {
            // Clear the stored values first
            safeStorageRemove('eiou_reopen_contact_id');
            safeStorageRemove('eiou_reopen_contact_tab');

            // Find the contact card with matching contact ID using data attribute
            var contactCards = document.querySelectorAll('.contact-card');
            for (var i = 0; i < contactCards.length; i++) {
                var card = contactCards[i];
                var cardContactId = card.getAttribute('data-contact-id');
                if (cardContactId && cardContactId === reopenContactId) {
                    // Click the card to open the modal
                    card.click();
                    // Then switch to transactions tab after a short delay
                    setTimeout(function() {
                        var tabToOpen = reopenTab || 'transactions-tab';
                        showModalTab(tabToOpen, null);
                    }, 150);
                    return;
                }
            }
        }
    } catch (e) {
        // Silently fail - don't break the page if something goes wrong
    }
}

/**
 * Opens a contact modal by contact ID.
 *
 * Finds the contact card with matching data-contact-id attribute and clicks
 * it to open the modal. Used by notification banners to link directly to a
 * specific contact.
 *
 * @param {string} contactId - The contact ID to open
 * @param {string} [tab] - Optional tab to switch to after opening (e.g. 'info-tab')
 * @returns {void}
 */
function openContactByContactId(contactId, tab) {
    var contactCards = document.querySelectorAll('.contact-card');
    for (var i = 0; i < contactCards.length; i++) {
        var card = contactCards[i];
        var cardContactId = card.getAttribute('data-contact-id');
        if (cardContactId && cardContactId === contactId) {
            card.click();
            if (tab) {
                setTimeout(function() {
                    showModalTab(tab, null);
                }, 150);
            }
            return;
        }
    }
}

/**
 * Switches to a specific tab within a modal.
 *
 * Hides all tab contents, deactivates all tab buttons, then shows the
 * selected tab content and activates the corresponding button. If no
 * button is provided, finds and activates the button that matches the tab ID.
 *
 * @param {string} tabId - The ID of the tab content element to show
 * @param {HTMLElement|null} button - The tab button that was clicked, or null to auto-find
 * @returns {void}
 * @example
 * // Switch to transactions tab via button click
 * showModalTab('transactions-tab', this);
 *
 * // Switch to tab programmatically
 * showModalTab('info-tab', null);
 */
function showModalTab(tabId, button) {
    // Hide all tab contents
    var tabContents = document.querySelectorAll('.modal-tab-content');
    for (var i = 0; i < tabContents.length; i++) {
        tabContents[i].classList.remove('active');
    }

    // Deactivate all tab buttons
    var tabButtons = document.querySelectorAll('.modal-tab');
    for (var j = 0; j < tabButtons.length; j++) {
        tabButtons[j].classList.remove('active');
    }

    // Show selected tab content
    var targetTab = document.getElementById(tabId);
    if (targetTab) {
        targetTab.classList.add('active');
    }

    // Activate the clicked button or find matching button
    if (button) {
        button.classList.add('active');
    } else {
        // Find the button that corresponds to this tab
        var tabButtons = document.querySelectorAll('.modal-tab');
        for (var k = 0; k < tabButtons.length; k++) {
            var btn = tabButtons[k];
            var onclickStr = btn.getAttribute('onclick') || '';
            if (onclickStr.indexOf(tabId) !== -1) {
                btn.classList.add('active');
                break;
            }
        }
    }
}

/**
 * Shows the detailed view for a specific transaction within the contact modal.
 *
 * Retrieves transaction data from the contactTransactionData array and renders
 * a detailed view with amount, status badges, transaction type, counterparty
 * address, description, date, and routing information for P2P transactions.
 * Hides the transaction list and shows the detail view.
 *
 * @param {number} index - Zero-based index into the contactTransactionData array
 * @returns {void}
 * @requires contactTransactionData - Global array set by openContactModal
 * @example
 * // Called from transaction item onclick in contact modal
 * <div onclick="showContactTxDetail(0)">Transaction 1</div>
 */
function showContactTxDetail(index) {
    if (!contactTransactionData || !contactTransactionData[index]) {
        return;
    }

    var tx = contactTransactionData[index];
    var content = document.getElementById('contact-tx-detail-content');

    // Build direction info
    var directionIcon = tx.type === 'sent' ? 'fa-arrow-up' : 'fa-arrow-down';
    var directionText = tx.type === 'sent' ? 'Sent' : 'Received';

    // Build status badge (escapeHtml for defense-in-depth)
    var status = tx.status || 'completed';
    var statusBadge = '<span class="tx-status-badge tx-status-' + escapeHtml(status) + '">' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span>';

    // Build transaction type badge (both yellow for consistency)
    var txType = tx.tx_type || 'standard';
    var txTypeBadge = txType === 'p2p'
        ? '<span class="tx-modal-badge tx-modal-badge-p2p"><i class="fas fa-network-wired"></i> P2P</span>'
        : '<span class="tx-modal-badge tx-modal-badge-p2p"><i class="fas fa-exchange-alt"></i> Direct</span>';

    // Build role badge (Sent/Received/Relay)
    var roleIcon = tx.type === 'sent' ? 'fa-arrow-up' : 'fa-arrow-down';
    var roleLabel = tx.type === 'sent' ? 'Sent' : 'Received';
    var roleBadgeClass = tx.type === 'sent' ? 'tx-modal-badge-sent' : 'tx-modal-badge-received';
    if (tx.direction === 'relay') {
        roleIcon = 'fa-random';
        roleLabel = 'Relay';
        roleBadgeClass = 'tx-modal-badge-relay';
    }
    var roleBadge = '<span class="tx-modal-badge ' + roleBadgeClass + '"><i class="fas ' + roleIcon + '"></i> ' + roleLabel + '</span>';

    // Build HTML content
    var html = '';

    // Header with amount
    var headerClass2 = tx.type === 'sent' ? 'tx-modal-header-sent' : 'tx-modal-header-received';
    html += '<div class="tx-modal-header ' + headerClass2 + '">';
    html += '<div class="tx-modal-amount">' + (tx.type === 'sent' ? '-' : '+') + parseFloat(tx.amount).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency || 'USD') + '</div>';
    html += '<div class="tx-modal-direction"><i class="fas ' + directionIcon + '"></i> ' + directionText + '</div>';
    html += '</div>';

    // Status, type, and role badges
    html += '<div class="tx-modal-badges">';
    html += statusBadge;
    html += txTypeBadge;
    html += roleBadge;
    html += '</div>';

    // Details section
    html += '<div class="tx-detail-section">';

    // To/From address (shows which address the transaction was sent to/from)
    var counterpartyAddress = tx.type === 'sent' ? tx.receiver_address : tx.sender_address;
    if (counterpartyAddress) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">' + (tx.type === 'sent' ? 'To' : 'From') + '</div>';
        html += '<div class="tx-detail-value tx-modal-mono">' + escapeHtml(counterpartyAddress) + '</div>';
        html += '</div>';
    }

    // Description
    if (tx.description) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Description</div>';
        html += '<div class="tx-detail-value">' + escapeHtml(tx.description) + '</div>';
        html += '</div>';
    }

    // Date/Time
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">Date & Time</div>';
    html += '<div class="tx-detail-value">' + escapeHtml(tx.date || 'Unknown') + '</div>';
    html += '</div>';

    // Transaction ID
    if (tx.txid) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Transaction ID</div>';
        html += '<div class="tx-detail-value tx-modal-mono-sm">' + escapeHtml(tx.txid) + '</div>';
        html += '</div>';
    }

    // Routing Hash (only for P2P transactions, not direct or contact)
    if (tx.tx_type === 'p2p' && tx.memo && tx.memo !== 'standard') {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Routing Hash</div>';
        html += '<div class="tx-detail-value tx-modal-mono-sm">' + escapeHtml(tx.memo) + '</div>';
        html += '</div>';
    }

    html += '</div>';

    content.innerHTML = html;

    // Show detail view, hide list view
    document.getElementById('tx-list-view').style.display = 'none';
    document.getElementById('tx-detail-view').style.display = 'block';
}

/**
 * Hides the transaction detail view and shows the transaction list.
 *
 * Switches the contact modal's transactions tab from the detailed single
 * transaction view back to the list of all transactions with this contact.
 *
 * @returns {void}
 * @example
 * // Called from "Back to list" button in transaction detail view
 * <button onclick="hideContactTxDetail()">Back to list</button>
 */
function hideContactTxDetail() {
    document.getElementById('tx-detail-view').style.display = 'none';
    document.getElementById('tx-list-view').style.display = 'block';
}

/**
 * Updates the displayed contact address when the address type dropdown changes.
 *
 * Reads the selected option from the address type dropdown and updates
 * the address display element with the corresponding address stored in
 * the option's data-address attribute.
 *
 * @returns {void}
 * @example
 * // Called from address selector onchange
 * <select id="modal_address_selector" onchange="showSelectedContactAddress()">
 */
function showSelectedContactAddress() {
    var select = document.getElementById('modal_address_selector');
    var selectedOption = select.options[select.selectedIndex];
    var address = selectedOption.getAttribute('data-address');
    document.getElementById('modal_address_display').textContent = address;
}

// Close contact modal when clicking outside (Tor Browser compatible)
// Note: We need to wait until DOM is loaded to attach this handler
window.addEventListener('DOMContentLoaded', function() {
    var contactModalEl = document.getElementById('contactModal');
    if (contactModalEl) {
        contactModalEl.onclick = function(e) {
            if (e.target === contactModalEl) {
                closeContactModal();
            }
        };
    }

    // Check if we need to reopen contact modal after refresh
    checkReopenContactModal();

    // Initialize contacts display limit (show only first 16 by default)
    initContactsDisplay();
});

// Chain Drop Resolution state
var currentChainDropProposalId = null;

/**
 * Proposes a chain drop to the current contact.
 * Sends AJAX POST with the contact's pubkey hash.
 * @returns {void}
 */
function proposeChainDrop() {
    var btn = document.getElementById('chain_drop_propose_btn');
    var icon = document.getElementById('chain_drop_propose_icon');
    var btnText = document.getElementById('chain_drop_propose_text');
    var resultMsg = document.getElementById('chain_drop_propose_result');

    if (!currentContactPubkeyHash) {
        if (resultMsg) {
            resultMsg.textContent = 'Contact information not available';
            resultMsg.style.color = '#dc3545';
        }
        return;
    }

    if (btn) btn.disabled = true;
    if (icon) icon.className = 'fas fa-spinner fa-spin';
    if (btnText) btnText.textContent = 'Sending...';
    if (resultMsg) resultMsg.textContent = '';

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        if (resultMsg) {
            resultMsg.textContent = 'CSRF token not found';
            resultMsg.style.color = '#dc3545';
        }
        resetChainDropProposeButton();
        return;
    }

    var formData = new FormData();
    formData.append('action', 'proposeChainDrop');
    formData.append('contact_pubkey_hash', currentContactPubkeyHash);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 60000;

    xhr.ontimeout = function() {
        resetChainDropProposeButton();
        if (resultMsg) {
            resultMsg.textContent = 'Request timed out';
            resultMsg.style.color = '#dc3545';
        }
    };

    xhr.onerror = function() {
        resetChainDropProposeButton();
        if (resultMsg) {
            resultMsg.textContent = 'Network error';
            resultMsg.style.color = '#dc3545';
        }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            resetChainDropProposeButton();
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // Reload and reopen modal so all statuses refresh
                        reloadAndReopenContactModal();
                        return;
                    } else {
                        if (resultMsg) {
                            resultMsg.textContent = response.message || 'Proposal failed';
                            resultMsg.style.color = '#dc3545';
                        }
                    }
                } catch (e) {
                    if (resultMsg) {
                        resultMsg.textContent = 'Invalid response';
                        resultMsg.style.color = '#dc3545';
                    }
                }
            } else if (xhr.status !== 0) {
                if (resultMsg) {
                    resultMsg.textContent = 'Request failed';
                    resultMsg.style.color = '#dc3545';
                }
            }
        }
    };
    xhr.send(formData);
}

function resetChainDropProposeButton() {
    var btn = document.getElementById('chain_drop_propose_btn');
    var icon = document.getElementById('chain_drop_propose_icon');
    var btnText = document.getElementById('chain_drop_propose_text');
    if (btn) btn.disabled = false;
    if (icon) icon.className = 'fas fa-handshake';
    if (btnText) btnText.textContent = 'Propose Dropping Missing Transaction(s)';
}

/**
 * Accepts an incoming chain drop proposal.
 * @returns {void}
 */
function acceptChainDrop() {
    if (!currentChainDropProposalId) return;

    var btn = document.getElementById('chain_drop_accept_btn');
    var icon = document.getElementById('chain_drop_accept_icon');
    var btnText = document.getElementById('chain_drop_accept_text');
    var resultMsg = document.getElementById('chain_drop_action_result');

    if (btn) btn.disabled = true;
    if (icon) icon.className = 'fas fa-spinner fa-spin';
    if (btnText) btnText.textContent = 'Accepting...';
    if (resultMsg) resultMsg.textContent = '';

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        if (resultMsg) {
            resultMsg.textContent = 'CSRF token not found';
            resultMsg.style.color = '#dc3545';
        }
        resetChainDropActionButtons();
        return;
    }

    var formData = new FormData();
    formData.append('action', 'acceptChainDrop');
    formData.append('proposal_id', currentChainDropProposalId);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 60000;

    xhr.ontimeout = function() {
        resetChainDropActionButtons();
        if (resultMsg) {
            resultMsg.textContent = 'Request timed out';
            resultMsg.style.color = '#dc3545';
        }
    };

    xhr.onerror = function() {
        resetChainDropActionButtons();
        if (resultMsg) {
            resultMsg.textContent = 'Network error';
            resultMsg.style.color = '#dc3545';
        }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            resetChainDropActionButtons();
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // Reload and reopen modal so all statuses refresh
                        reloadAndReopenContactModal();
                        return;
                    } else {
                        if (resultMsg) {
                            resultMsg.textContent = response.message || 'Accept failed';
                            resultMsg.style.color = '#dc3545';
                        }
                    }
                } catch (e) {
                    if (resultMsg) {
                        resultMsg.textContent = 'Invalid response';
                        resultMsg.style.color = '#dc3545';
                    }
                }
            } else if (xhr.status !== 0) {
                if (resultMsg) {
                    resultMsg.textContent = 'Request failed';
                    resultMsg.style.color = '#dc3545';
                }
            }
        }
    };
    xhr.send(formData);
}

/**
 * Rejects an incoming chain drop proposal.
 * @returns {void}
 */
function rejectChainDrop() {
    if (!currentChainDropProposalId) return;

    var btn = document.getElementById('chain_drop_reject_btn');
    var icon = document.getElementById('chain_drop_reject_icon');
    var btnText = document.getElementById('chain_drop_reject_text');
    var resultMsg = document.getElementById('chain_drop_action_result');

    if (btn) btn.disabled = true;
    if (icon) icon.className = 'fas fa-spinner fa-spin';
    if (btnText) btnText.textContent = 'Rejecting...';
    if (resultMsg) resultMsg.textContent = '';

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        if (resultMsg) {
            resultMsg.textContent = 'CSRF token not found';
            resultMsg.style.color = '#dc3545';
        }
        resetChainDropActionButtons();
        return;
    }

    var formData = new FormData();
    formData.append('action', 'rejectChainDrop');
    formData.append('proposal_id', currentChainDropProposalId);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 60000;

    xhr.ontimeout = function() {
        resetChainDropActionButtons();
        if (resultMsg) {
            resultMsg.textContent = 'Request timed out';
            resultMsg.style.color = '#dc3545';
        }
    };

    xhr.onerror = function() {
        resetChainDropActionButtons();
        if (resultMsg) {
            resultMsg.textContent = 'Network error';
            resultMsg.style.color = '#dc3545';
        }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            resetChainDropActionButtons();
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // Reload and reopen modal so all statuses refresh
                        reloadAndReopenContactModal();
                        return;
                    } else {
                        if (resultMsg) {
                            resultMsg.textContent = response.message || 'Reject failed';
                            resultMsg.style.color = '#dc3545';
                        }
                    }
                } catch (e) {
                    if (resultMsg) {
                        resultMsg.textContent = 'Invalid response';
                        resultMsg.style.color = '#dc3545';
                    }
                }
            } else if (xhr.status !== 0) {
                if (resultMsg) {
                    resultMsg.textContent = 'Request failed';
                    resultMsg.style.color = '#dc3545';
                }
            }
        }
    };
    xhr.send(formData);
}

function resetChainDropActionButtons() {
    var acceptBtn = document.getElementById('chain_drop_accept_btn');
    var acceptIcon = document.getElementById('chain_drop_accept_icon');
    var acceptText = document.getElementById('chain_drop_accept_text');
    var rejectBtn = document.getElementById('chain_drop_reject_btn');
    var rejectIcon = document.getElementById('chain_drop_reject_icon');
    var rejectText = document.getElementById('chain_drop_reject_text');

    if (acceptBtn) acceptBtn.disabled = false;
    if (acceptIcon) acceptIcon.className = 'fas fa-check';
    if (acceptText) acceptText.textContent = 'Accept';
    if (rejectBtn) rejectBtn.disabled = false;
    if (rejectIcon) rejectIcon.className = 'fas fa-times';
    if (rejectText) rejectText.textContent = 'Reject';
}

/**
 * Reloads the page and reopens the current contact modal on the info tab.
 * Used after chain drop propose/accept/reject so all statuses refresh from
 * server data (badges, chain status, notification banner).
 * @returns {void}
 */
function reloadAndReopenContactModal() {
    if (currentContactId) {
        var storedId = safeStorageSet('eiou_reopen_contact_id', currentContactId);
        var storedTab = safeStorageSet('eiou_reopen_contact_tab', 'info-tab');
        if (storedId && storedTab) {
            window.location.reload();
        } else {
            // Tor Browser fallback
            var currentUrl = window.location.href.split('#')[0];
            window.location.href = currentUrl + '#reopen_contact=' + encodeURIComponent(currentContactId) + '&tab=info';
            window.location.reload();
        }
    } else {
        window.location.reload();
    }
}

// ============================================================================
// SETTINGS SECTION FUNCTIONS
// ============================================================================

/**
 * Switches between debug tabs in the settings debug section.
 *
 * Hides all debug content sections, deactivates all tab buttons, then
 * shows the selected content and activates the clicked button. Uses
 * Tor Browser compatible code (var declarations, for loops).
 *
 * @param {string} tabId - The ID of the debug content element to show
 * @param {HTMLElement} button - The tab button that was clicked
 * @returns {void}
 * @example
 * // Called from debug tab button onclick
 * <button class="debug-tab" onclick="showDebugTab('debug-logs', this)">Logs</button>
 */
function showDebugTab(tabId, button) {
    // Hide all content
    var contents = document.querySelectorAll('.debug-content');
    for (var i = 0; i < contents.length; i++) {
        contents[i].classList.remove('active');
    }

    // Deactivate all tabs
    var tabs = document.querySelectorAll('.debug-tab');
    for (var j = 0; j < tabs.length; j++) {
        tabs[j].classList.remove('active');
    }

    // Show selected content and activate tab
    var targetContent = document.getElementById(tabId);
    if (targetContent) {
        targetContent.classList.add('active');
    }
    if (button) {
        button.classList.add('active');
    }
}

/**
 * Toggles the visibility of a collapsible configuration section.
 *
 * Shows or hides the content element and rotates the arrow indicator
 * to reflect the current expanded/collapsed state.
 *
 * @param {string} contentId - The ID of the content element to toggle
 * @param {string} arrowId - The ID of the arrow icon element to rotate
 * @returns {void}
 * @example
 * // Called from section header onclick
 * <div onclick="toggleConfigSection('section-content', 'section-arrow')">
 *     <i id="section-arrow">▼</i> Section Title
 * </div>
 */
/**
 * Switches the visible panel inside the Advanced Settings section.
 *
 * All .adv-section divs are hidden and the one matching the given sectionId
 * is shown. Because every section's fields are always present in the DOM,
 * all values are submitted regardless of which panel is currently visible.
 *
 * @param {string} sectionId - Value attribute of the selected dropdown option
 *     (e.g. 'feature-toggles', 'backup-logging')
 * @returns {void}
 */
function switchAdvancedSection(sectionId) {
    var sections = document.querySelectorAll('.adv-section');
    for (var i = 0; i < sections.length; i++) {
        sections[i].style.display = 'none';
    }
    var target = document.getElementById('adv-section-' + sectionId);
    if (target) {
        target.style.display = 'block';
    }
}

/**
 * Keeps the Held TX Sync Timeout max in sync with the P2P Expiration field.
 *
 * Reads the current value of #p2pExpiration and sets the max attribute of
 * #heldTxSyncTimeoutSeconds to (p2pExpiration - 1), clamped to a minimum
 * of 30. If the current timeout value exceeds the new max it is clamped down.
 * Called once on page load and on every input/change event on #p2pExpiration.
 *
 * @returns {void}
 */
function initSyncTimeoutDynamicMax() {
    var p2pInput = document.getElementById('p2pExpiration');
    var syncInput = document.getElementById('heldTxSyncTimeoutSeconds');
    if (!p2pInput || !syncInput) {
        return;
    }
    function updateMax() {
        var p2pVal = parseInt(p2pInput.value, 10);
        if (!isNaN(p2pVal) && p2pVal > 1) {
            var newMax = Math.max(30, p2pVal - 1);
            syncInput.max = newMax;
            var current = parseInt(syncInput.value, 10);
            if (!isNaN(current) && current > newMax) {
                syncInput.value = newMax;
            }
        }
    }
    p2pInput.addEventListener('input', updateMax);
    p2pInput.addEventListener('change', updateMax);
    updateMax();
}

function toggleConfigSection(contentId, arrowId) {
    var content = document.getElementById(contentId);
    var arrow = document.getElementById(arrowId);

    if (content && arrow) {
        if (content.style.display === 'none') {
            content.style.display = 'block';
            arrow.style.transform = 'rotate(180deg)';
        } else {
            content.style.display = 'none';
            arrow.style.transform = 'rotate(0deg)';
        }
    }
}

/**
 * Toggles the P2P routing info alert between collapsed and expanded states.
 * Collapsed shows only the header; expanded reveals the description paragraph.
 *
 * @returns {void}
 */
function toggleP2pInfo() {
    var detail = document.getElementById('p2p-info-detail');
    var chevron = document.getElementById('p2p-info-chevron');
    if (!detail) return;
    if (detail.style.display === 'block') {
        detail.style.display = 'none';
        if (chevron) chevron.className = 'fas fa-chevron-down p2p-info-chevron';
    } else {
        detail.style.display = 'block';
        if (chevron) chevron.className = 'fas fa-chevron-up p2p-info-chevron';
    }
}

/**
 * Filters debug log entries based on search input.
 *
 * Performs case-insensitive substring matching on debug entries or pre-formatted
 * text lines. For structured entries (.debug-entry), shows/hides individual entries.
 * For pre-formatted text (.debug-pre), filters and rewrites the text content.
 * Updates the search info message and shows/hides the "no results" message.
 *
 * @param {HTMLInputElement} inputElement - The search input element
 * @param {string} containerId - The ID of the container element with debug content
 * @returns {void}
 * @example
 * // Called from search input oninput
 * <input type="text" oninput="filterDebugLogs(this, 'debug-logs-container')">
 */
function filterDebugLogs(inputElement, containerId) {
    var searchTerm = inputElement.value.toLowerCase();
    var container = document.getElementById(containerId);
    if (!container) return;

    var noResultsElement = container.querySelector('.debug-no-results');
    var visibleCount = 0;
    var totalCount = 0;

    // Check if this is a structured log (debug entries) or pre-formatted text
    var entries = container.querySelectorAll('.debug-entry');

    if (entries.length > 0) {
        // Filter structured debug entries
        totalCount = entries.length;
        for (var i = 0; i < entries.length; i++) {
            var entry = entries[i];
            var text = entry.textContent.toLowerCase();

            if (searchTerm === '' || text.indexOf(searchTerm) !== -1) {
                entry.classList.remove('hidden');
                visibleCount++;
            } else {
                entry.classList.add('hidden');
            }
        }
    } else {
        // Filter pre-formatted text (line by line)
        var preElement = container.querySelector('.debug-pre');
        if (preElement) {
            var originalContent = preElement.getAttribute('data-original');
            if (!originalContent) {
                // Store original content on first search
                originalContent = preElement.textContent;
                preElement.setAttribute('data-original', originalContent);
            }

            var lines = originalContent.split('\n');
            totalCount = lines.length;
            var filteredLines = [];

            for (var j = 0; j < lines.length; j++) {
                if (searchTerm === '' || lines[j].toLowerCase().indexOf(searchTerm) !== -1) {
                    filteredLines.push(lines[j]);
                    visibleCount++;
                }
            }

            preElement.textContent = filteredLines.join('\n');

            // Restore original if search is cleared
            if (searchTerm === '') {
                preElement.textContent = originalContent;
                visibleCount = totalCount;
            }
        }
    }

    // Update search info
    var searchInfo = container.querySelector('.debug-search-info');
    if (searchInfo) {
        if (searchTerm === '') {
            searchInfo.textContent = 'Showing all ' + totalCount + ' entries';
        } else {
            searchInfo.textContent = 'Showing ' + visibleCount + ' of ' + totalCount + ' entries matching "' + inputElement.value + '"';
        }
    }

    // Show/hide no results message
    if (noResultsElement) {
        if (visibleCount === 0 && searchTerm !== '') {
            noResultsElement.classList.add('visible');
        } else {
            noResultsElement.classList.remove('visible');
        }
    }
}

/**
 * Clears the debug search input and resets the filter.
 *
 * Empties the search input field and calls filterDebugLogs to reset
 * the display to show all entries.
 *
 * @param {string} inputId - The ID of the search input element
 * @param {string} containerId - The ID of the container element with debug content
 * @returns {void}
 * @example
 * // Called from clear button onclick
 * <button onclick="clearDebugSearch('debug-search', 'debug-logs-container')">Clear</button>
 */
function clearDebugSearch(inputId, containerId) {
    var input = document.getElementById(inputId);
    if (input) {
        input.value = '';
        filterDebugLogs(input, containerId);
    }
}

// Store last generated debug report for reuse (avoids refetching)
var lastDebugReport = null;
var lastDebugFilename = null;

/**
 * Fetches debug report data from the server via AJAX.
 *
 * Sends a POST request to retrieve debug logs and system information.
 * Shows a toast notification while fetching and handles timeouts appropriate
 * for the report type (3 minutes for full reports, 1 minute for limited).
 * Generates a timestamped filename and passes the data to the callback.
 *
 * @param {Function} callback - Function to call with (jsonData, filename, description)
 * @param {string} [reportMode='full'] - Report type: 'full' for complete logs,
 *                                       'limited' for GUI-displayed data only
 * @returns {void}
 * @example
 * // Fetch full report and download
 * fetchDebugReport(function(jsonData, filename) {
 *     downloadDebugFile(jsonData, filename);
 * }, 'full');
 */
function fetchDebugReport(callback, reportMode) {
    reportMode = reportMode || 'full';
    var isFullReport = (reportMode === 'full');

    var descriptionEl = document.getElementById('debugDescription');
    var csrfTokenEl = document.getElementById('debugCsrfToken');
    var description = descriptionEl ? descriptionEl.value : '';
    var csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

    // Show loading state based on report type
    if (isFullReport) {
        showToast('Generating Full Report', 'Collecting complete debug logs. This may take a moment over Tor...', 'info');
    } else {
        showToast('Generating Limited Report', 'Collecting recent debug logs...', 'info');
    }

    // Create form data for POST request
    var formData = new FormData();
    formData.append('action', 'getDebugReportJson');
    formData.append('csrf_token', csrfToken);
    formData.append('description', description);
    formData.append('report_mode', reportMode);

    // Fetch debug data via AJAX (Tor Browser compatible XMLHttpRequest)
    // Full reports need longer timeout; limited reports are faster
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = isFullReport ? 180000 : 60000; // 3 minutes for full, 1 minute for limited
    xhr.ontimeout = function() {
        if (isFullReport) {
            showToast('Error', 'Request timed out. Full debug reports can be large - please try again or check your Tor connection.', 'error');
        } else {
            showToast('Error', 'Request timed out. Please try again.', 'error');
        }
    };
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var jsonData = xhr.responseText;
                    var parsedData = JSON.parse(jsonData);

                    // Check for error in response
                    if (parsedData.error) {
                        showToast('Error', parsedData.error, 'error');
                        return;
                    }

                    // Generate filename with current datetime
                    var now = new Date();
                    var year = now.getFullYear();
                    var month = String(now.getMonth() + 1).padStart(2, '0');
                    var day = String(now.getDate()).padStart(2, '0');
                    var hours = String(now.getHours()).padStart(2, '0');
                    var minutes = String(now.getMinutes()).padStart(2, '0');
                    var seconds = String(now.getSeconds()).padStart(2, '0');
                    var filename = 'eiou-docker-debug-' + year + '-' + month + '-' + day + '-' + hours + minutes + seconds + '.json';

                    // Store for potential reuse
                    lastDebugReport = jsonData;
                    lastDebugFilename = filename;

                    // Call the callback with the data
                    callback(jsonData, filename, description);

                } catch (e) {
                    showToast('Error', 'Failed to parse debug report: ' + e.message, 'error');
                }
            } else {
                showToast('Error', 'Failed to fetch debug report. Status: ' + xhr.status, 'error');
            }
        }
    };

    xhr.onerror = function() {
        showToast('Error', 'Network error while fetching debug report.', 'error');
    };

    xhr.send(formData);
}

/**
 * Downloads a full debug report containing complete system logs.
 *
 * Fetches the full debug report from the server and triggers a download
 * with a '-full' suffix in the filename. This report contains all available
 * debug data and may be large.
 *
 * @returns {void}
 * @example
 * // Called from "Download Full Report" button onclick
 * <button onclick="downloadFullDebugReport()">Download Full Report</button>
 */
function downloadFullDebugReport() {
    fetchDebugReport(function(jsonData, filename, description) {
        // Add '-full' suffix to filename
        var fullFilename = filename.replace('.json', '-full.json');
        downloadDebugFile(jsonData, fullFilename);
        showToast('Success', 'Full debug report downloaded: ' + fullFilename, 'success');
    }, 'full');
}

/**
 * Downloads a limited debug report matching the GUI display.
 *
 * Fetches a limited debug report from the server (same data shown in the GUI)
 * and triggers a download with a '-limited' suffix in the filename.
 * This is faster to generate than the full report.
 *
 * @returns {void}
 * @example
 * // Called from "Download Limited Report" button onclick
 * <button onclick="downloadLimitedDebugReport()">Download Limited Report</button>
 */
function downloadLimitedDebugReport() {
    fetchDebugReport(function(jsonData, filename, description) {
        // Add '-limited' suffix to filename
        var limitedFilename = filename.replace('.json', '-limited.json');
        downloadDebugFile(jsonData, limitedFilename);
        showToast('Success', 'Limited debug report downloaded: ' + limitedFilename, 'success');
    }, 'limited');
}

/**
 * Legacy function that redirects to downloadFullDebugReport.
 * @deprecated Use downloadFullDebugReport() instead
 * @returns {void}
 */
function emailDebugReport() {
    downloadFullDebugReport();
}

/**
 * Legacy function that redirects to downloadFullDebugReport.
 * @deprecated Use downloadFullDebugReport() instead
 * @returns {void}
 */
function sendDebugReport() {
    downloadFullDebugReport();
}

/**
 * Triggers a browser download of the debug report JSON file.
 *
 * Creates a Blob from the JSON data, generates a download link, clicks it
 * to trigger the download, then cleans up. Uses URL.createObjectURL which
 * is compatible with Tor Browser.
 *
 * @param {string} jsonData - The JSON string to download
 * @param {string} filename - The filename for the downloaded file
 * @returns {void}
 * @example
 * downloadDebugFile('{"debug": true}', 'eiou-debug-2024-01-15.json');
 */
function downloadDebugFile(jsonData, filename) {
    // Create blob from JSON data
    var blob = new Blob([jsonData], { type: 'application/json' });

    // Create download link
    var downloadLink = document.createElement('a');
    downloadLink.href = URL.createObjectURL(blob);
    downloadLink.download = filename;

    // Append to body, click, and remove
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);

    // Clean up the object URL after a delay
    setTimeout(function() {
        URL.revokeObjectURL(downloadLink.href);
    }, 1000);
}

// ============================================================================
// WALLET INFORMATION FUNCTIONS
// ============================================================================

/**
 * Updates the displayed user address when the address type dropdown changes.
 *
 * Reads the selected option from the user address type dropdown and updates
 * the address display element with the corresponding address stored in
 * the option's data-address attribute.
 *
 * @returns {void}
 * @example
 * // Called from address selector onchange
 * <select id="user-address-selector" onchange="showSelectedUserAddress()">
 */
function showSelectedUserAddress() {
    var select = document.getElementById('user-address-selector');
    var selectedOption = select.options[select.selectedIndex];
    var address = selectedOption.getAttribute('data-address');
    document.getElementById('user-address-value').textContent = address;

    // If QR code is visible, regenerate it for the new address
    var qrContainer = document.getElementById('address-qr-display');
    if (qrContainer && qrContainer.style.display !== 'none' && qrContainer.innerHTML) {
        var svg = generateQrSvg(address, 200);
        if (svg) qrContainer.innerHTML = svg;
    }
}

/**
 * Switch visible wallet currency on mobile (tab per currency)
 */
function switchWalletCurrency(currency) {
    var tabs = document.querySelectorAll('.wallet-currency-tab');
    var rows = document.querySelectorAll('.wallet-stats-row[data-wallet-currency]');
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.toggle('active', tabs[i].getAttribute('data-currency') === currency);
    }
    for (var j = 0; j < rows.length; j++) {
        rows[j].classList.toggle('wallet-currency-active', rows[j].getAttribute('data-wallet-currency') === currency);
    }
}

// Initialize first currency as active on load
(function() {
    var firstTab = document.querySelector('.wallet-currency-tab.active');
    if (firstTab) {
        switchWalletCurrency(firstTab.getAttribute('data-currency'));
    }
})();

// ============================================================================
// QR CODE FUNCTIONS
// ============================================================================

/**
 * Generate a QR code SVG string for the given text using qrcode-generator.
 * Returns an SVG element string, or null if the library is not loaded.
 */
function generateQrSvg(text, size) {
    size = size || 200;
    if (typeof qrcode === 'undefined') return null;

    var qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();

    var moduleCount = qr.getModuleCount();
    var cellSize = size / moduleCount;
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">';
    svg += '<rect width="100%" height="100%" fill="white"/>';

    for (var row = 0; row < moduleCount; row++) {
        for (var col = 0; col < moduleCount; col++) {
            if (qr.isDark(row, col)) {
                svg += '<rect x="' + (col * cellSize) + '" y="' + (row * cellSize) + '" width="' + cellSize + '" height="' + cellSize + '" fill="black"/>';
            }
        }
    }
    svg += '</svg>';
    return svg;
}

/**
 * Toggle QR code display for an address.
 * Reads the address from data-qr-text or data-qr-source (element ID),
 * generates the QR, and shows/hides the target container.
 */
function toggleAddressQr(el) {
    var targetId = el.getAttribute('data-qr-target');
    var container = document.getElementById(targetId);
    if (!container) return;

    // Toggle visibility
    if (container.style.display !== 'none' && container.innerHTML) {
        container.style.display = 'none';
        return;
    }

    // Get address text
    var text = el.getAttribute('data-qr-text');
    if (!text) {
        var sourceId = el.getAttribute('data-qr-source');
        if (sourceId) {
            var sourceEl = document.getElementById(sourceId);
            text = sourceEl ? sourceEl.textContent.trim() : '';
        }
    }
    if (!text) return;

    var svg = generateQrSvg(text, 200);
    if (!svg) {
        container.innerHTML = '<p style="color:#6c757d;font-size:0.85rem">QR code library not available</p>';
    } else {
        container.innerHTML = svg;
    }
    container.style.display = 'block';
}

/**
 * Open the QR code scanner modal using html5-qrcode.
 * On successful scan, fills the target input and closes the modal.
 */
/**
 * Decode a QR code image server-side (fallback for Tor Browser).
 * Uploads the file to the node's PHP backend which decodes it with GD + ZXing.
 */
function decodeQrServerSide(file, onSuccess, onError, onComplete) {
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken) {
        onError('CSRF token not found');
        return;
    }

    var formData = new FormData();
    formData.append('action', 'decodeQr');
    formData.append('csrf_token', csrfToken.value);
    formData.append('qr_image', file);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.onload = function() {
        if (xhr.status !== 200) {
            onError('Server error (HTTP ' + xhr.status + '). Please refresh and try again.');
            return;
        }
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.success && resp.data) {
                if (onComplete) onComplete();
                onSuccess(resp.data);
            } else {
                onError(resp.error || 'No QR code found in image. Try a clearer photo.');
            }
        } catch (e) {
            // Log the actual response for debugging
            console.error('QR decode: unexpected response', xhr.status, xhr.responseText.substring(0, 200));
            onError('Server returned an unexpected response. Check the browser console for details.');
        }
    };
    xhr.onerror = function() {
        onError('Could not reach server for QR decoding.');
    };
    xhr.send(formData);
}

/**
 * Quick canvas integrity check — detects Tor Browser fingerprinting protection.
 * Returns true if canvas data is being modified by the browser.
 */
function isCanvasBlocked() {
    try {
        var c = document.createElement('canvas');
        c.width = 2; c.height = 1;
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#ff0000';
        ctx.fillRect(0, 0, 1, 1);
        ctx.fillStyle = '#0000ff';
        ctx.fillRect(1, 0, 1, 1);
        var d = ctx.getImageData(0, 0, 2, 1).data;
        return (d[0] !== 255 || d[1] !== 0 || d[4] !== 0 || d[6] !== 255);
    } catch (e) {
        return true;
    }
}

function openQrScanner(targetInputId) {
    // Detect canvas blocking early — if blocked, camera and client-side
    // jsQR both fail, so go straight to server-side file upload
    var canvasIsBlocked = isCanvasBlocked();
    var hasCameraLib = !canvasIsBlocked && typeof Html5Qrcode !== 'undefined';

    // Create scanner modal — z-index above other modals (e.g., Add Contact)
    var overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.id = 'qr-scanner-modal';
    overlay.style.zIndex = '10001';
    overlay.innerHTML =
        '<div class="modal-content" style="max-width:400px">' +
            '<div class="modal-header">' +
                '<h3 style="font-size:1rem"><i class="fas fa-qrcode"></i> Scan QR Code</h3>' +
                '<span class="close" id="qr-scanner-close" title="Close">&times;</span>' +
            '</div>' +
            '<div class="modal-body" style="padding:1rem">' +
                '<div id="qr-scanner-reader" style="width:100%"></div>' +
                '<p id="qr-scanner-hint" style="text-align:center;color:#6c757d;font-size:0.85rem;margin-top:0.5rem">' +
                    'Point your camera at a QR code containing an address' +
                '</p>' +
                '<div id="qr-scanner-file-fallback" style="display:none;text-align:center;padding:1rem 0">' +
                    '<p style="color:#6c757d;font-size:0.85rem;margin-bottom:0.75rem">' +
                        'Upload a photo of the QR code:' +
                    '</p>' +
                    '<label class="btn btn-primary btn-sm" style="cursor:pointer">' +
                        '<i class="fas fa-image"></i> Choose Image' +
                        '<input type="file" id="qr-file-input" accept="image/*" style="display:none">' +
                    '</label>' +
                    '<p id="qr-file-error" style="color:#dc3545;font-size:0.85rem;margin-top:0.5rem;display:none"></p>' +
                '</div>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);
    overlay.style.display = 'flex';

    var scanner = hasCameraLib ? new Html5Qrcode('qr-scanner-reader') : null;
    var scanning = false;

    function onScanSuccess(decodedText) {
        var input = document.getElementById(targetInputId);
        if (input) {
            input.value = decodedText;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        showToast('QR Scanned', 'Address: ' + (decodedText.length > 30 ? decodedText.substring(0, 30) + '...' : decodedText), 'success');
        closeScanner();
    }

    function closeScanner() {
        if (scanning && scanner) {
            scanner.stop().catch(function() {});
        }
        if (document.body.contains(overlay)) {
            document.body.removeChild(overlay);
        }
        document.removeEventListener('keydown', escHandler);
    }

    function showFileFallback() {
        var readerEl = document.getElementById('qr-scanner-reader');
        var hintEl = document.getElementById('qr-scanner-hint');
        var fallbackEl = document.getElementById('qr-scanner-file-fallback');
        if (readerEl) readerEl.innerHTML = '';
        if (hintEl) hintEl.style.display = 'none';
        if (fallbackEl) fallbackEl.style.display = 'block';
    }

    function escHandler(e) {
        if (e.key === 'Escape' || e.keyCode === 27) closeScanner();
    }

    overlay.querySelector('#qr-scanner-close').onclick = closeScanner;
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeScanner();
    });
    document.addEventListener('keydown', escHandler);

    // Wire file input — uses jsQR for decoding (works in Tor Browser)
    var fileInput = document.getElementById('qr-file-input');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (!file) return;
            var errorEl = document.getElementById('qr-file-error');
            var fallbackEl = document.getElementById('qr-scanner-file-fallback');
            if (errorEl) { errorEl.style.display = 'none'; }

            // Show loading indicator
            var loadingEl = document.getElementById('qr-file-loading');
            if (!loadingEl && fallbackEl) {
                loadingEl = document.createElement('p');
                loadingEl.id = 'qr-file-loading';
                loadingEl.style.cssText = 'text-align:center;color:#0d6efd;font-size:0.85rem;margin-top:0.5rem';
                loadingEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing image...';
                fallbackEl.appendChild(loadingEl);
            }
            if (loadingEl) loadingEl.style.display = 'block';

            function hideLoading() {
                if (loadingEl) loadingEl.style.display = 'none';
            }

            function showError(msg) {
                hideLoading();
                if (errorEl) {
                    errorEl.textContent = msg;
                    errorEl.style.display = 'block';
                }
                fileInput.value = '';
            }

            if (canvasIsBlocked) {
                // Canvas blocked (Tor Browser) — go straight to server-side, no timeout needed
                decodeQrServerSide(file, onScanSuccess, showError, hideLoading);
                return;
            }

            // Timeout — if client-side image never loads after 10 seconds, show error
            var loadTimeout = setTimeout(function() {
                showError('Image loading timed out. Try a smaller or different image file.');
            }, 10000);

            // Try client-side decode with jsQR (faster, no upload)
            var reader = new FileReader();
            reader.onload = function() {
                var img = new Image();
                img.onload = function() {
                    clearTimeout(loadTimeout);
                    setTimeout(function() {
                        try {
                            var canvas = document.createElement('canvas');
                            var maxDim = 1000;
                            var w = img.width, h = img.height;
                            if (w > maxDim || h > maxDim) {
                                var scale = maxDim / Math.max(w, h);
                                w = Math.round(w * scale);
                                h = Math.round(h * scale);
                            }
                            canvas.width = w;
                            canvas.height = h;
                            var ctx = canvas.getContext('2d');
                            ctx.drawImage(img, 0, 0, w, h);
                            var d = ctx.getImageData(0, 0, w, h).data;

                            // Threshold to pure black/white
                            for (var i = 0; i < d.length; i += 4) {
                                var br = (d[i]*299 + d[i+1]*587 + d[i+2]*114) / 1000;
                                var bw = br > 128 ? 255 : 0;
                                d[i]=bw; d[i+1]=bw; d[i+2]=bw; d[i+3]=255;
                            }

                            if (typeof jsQR !== 'undefined') {
                                var code = jsQR(d, w, h);
                                if (code && code.data) {
                                    hideLoading();
                                    onScanSuccess(code.data);
                                    return;
                                }
                            }
                            showError('No QR code found in image. Try a clearer photo.');
                        } catch (e) {
                            // Canvas failed — fall back to server
                            decodeQrServerSide(file, onScanSuccess, showError, hideLoading);
                        }
                    }, 50);
                };
                img.onerror = function() {
                    clearTimeout(loadTimeout);
                    decodeQrServerSide(file, onScanSuccess, showError, hideLoading);
                };
                img.src = reader.result;
            };
            reader.onerror = function() {
                clearTimeout(loadTimeout);
                showError('Could not read file.');
            };
            reader.readAsDataURL(file);
        });
    }

    // Try camera (html5-qrcode) first, fall back to file upload (jsQR)
    if (scanner) {
        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            onScanSuccess,
            function() { /* ignore — fires continuously when no QR in frame */ }
        ).then(function() {
            scanning = true;
        }).catch(function() {
            showFileFallback();
        });
    } else {
        // No camera library — go straight to file upload
        showFileFallback();
    }
}

// ============================================================================
// SYNCING TRANSACTION NOTIFICATION FUNCTIONS
// ============================================================================

/**
 * Shows toast notifications for in-progress and syncing transactions.
 *
 * Checks for transactions that are currently being processed or are on hold
 * due to chain synchronization. Shows appropriate warning/info toasts to
 * inform the user of the current state.
 *
 * @returns {void}
 * @requires syncingTransactionCount - Global variable set by PHP in transactionHistory.html
 * @example
 * // Called from transaction history page
 * showInProgressToasts();
 */
function showInProgressToasts() {
    if (typeof showToast !== 'function') {
        return;
    }

    var inProgressCount = document.querySelectorAll('.tx-in-progress-item').length;

    // Show specific toast for syncing transactions (syncingTransactionCount is set by PHP)
    if (typeof syncingTransactionCount !== 'undefined' && syncingTransactionCount > 0) {
        showToast(
            'Chain Synchronization',
            syncingTransactionCount + ' transaction' + (syncingTransactionCount > 1 ? 's are' : ' is') + ' on hold while syncing the transaction chain. This ensures accurate records.',
            'warning'
        );
    }

    // Show general in-progress toast for other transactions
    var otherInProgress = inProgressCount - (typeof syncingTransactionCount !== 'undefined' ? syncingTransactionCount : 0);
    if (otherInProgress > 0) {
        // Check if auto-refresh is enabled to show appropriate message
        var refreshMessage = (typeof autoRefreshEnabled !== 'undefined' && autoRefreshEnabled)
            ? 'Page will refresh automatically.'
            : 'Refresh the page manually to see updates.';
        showToast(
            'Transactions In Progress',
            otherInProgress + ' transaction' + (otherInProgress > 1 ? 's are' : ' is') + ' being processed. ' + refreshMessage,
            'info'
        );
    }
}

// ============================================================================
// DEAD LETTER QUEUE NOTIFICATION FUNCTIONS
// ============================================================================

/**
 * Shows toast notifications for messages added to the Dead Letter Queue.
 *
 * Iterates through the dlqNotifications array (set by PHP) and displays
 * a warning toast for each new DLQ entry. This alerts users when messages
 * have failed to process after multiple retry attempts.
 *
 * @returns {void}
 * @requires dlqNotifications - Global array of {type, title, message} objects set by PHP
 * @example
 * // Called automatically on DOMContentLoaded
 * document.addEventListener('DOMContentLoaded', function() {
 *     showDlqToasts();
 * });
 */
function showDlqToasts() {
    if (typeof showToast !== 'function') {
        return;
    }

    // dlqNotifications is set by PHP with array of {type, title, message} objects
    if (typeof dlqNotifications === 'undefined' || !dlqNotifications || dlqNotifications.length === 0) {
        return;
    }

    for (var i = 0; i < dlqNotifications.length; i++) {
        var notification = dlqNotifications[i];
        showToast(notification.title, notification.message, 'warning');
    }
}

/**
 * Client-side DLQ tab filter — show/hide rows without a page reload.
 *
 * filter values: 'active' (pending+retrying), 'pending', 'resolved',
 *                'abandoned', 'all'
 *
 * @param {string} filter
 */
function setDlqFilter(filter) {
    // Update active tab
    var tabs = document.querySelectorAll('.dlq-filter-tab');
    for (var i = 0; i < tabs.length; i++) {
        var isActive = tabs[i].getAttribute('data-filter') === filter;
        if (isActive) {
            tabs[i].classList.add('active');
        } else {
            tabs[i].classList.remove('active');
        }
    }

    // Show/hide rows and mark filter state for search
    var rows = document.querySelectorAll('.dlq-row');
    var visibleCount = 0;
    for (var j = 0; j < rows.length; j++) {
        var status = rows[j].getAttribute('data-status');
        var show = filter === 'all'
            || (filter === 'active' && (status === 'pending' || status === 'retrying'))
            || (filter !== 'all' && filter !== 'active' && status === filter);
        rows[j].style.display = show ? '' : 'none';
        rows[j].setAttribute('data-filter-hidden', show ? 'false' : 'true');
        if (show) { visibleCount++; }
    }

    // Re-apply search if active
    var searchInput = document.getElementById('dlq-search');
    if (searchInput && searchInput.value.trim()) {
        searchDlq(searchInput.value);
        return; // searchDlq updates the count
    }

    // Toggle filter-empty message vs table
    var filterEmpty  = document.getElementById('dlq-filter-empty');
    var tableWrapper = document.querySelector('#dlq .dlq-table-wrapper');
    if (filterEmpty)  { filterEmpty.style.display  = visibleCount === 0 ? '' : 'none'; }
    if (tableWrapper) { tableWrapper.style.display = visibleCount === 0 ? 'none' : ''; }

    // Update footer count
    var countEl = document.getElementById('dlq-visible-count');
    if (countEl) { countEl.textContent = visibleCount; }
}

/**
 * Search/filter DLQ rows by recipient, failure reason, or type.
 * Works on top of the active status filter — only searches visible rows.
 */
function searchDlq(query) {
    query = (query || '').toLowerCase().trim();
    var rows = document.querySelectorAll('.dlq-row');
    var visibleCount = 0;

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        // Skip rows already hidden by status filter
        if (row.getAttribute('data-filter-hidden') === 'true') {
            continue;
        }
        if (!query) {
            row.style.display = '';
            visibleCount++;
            continue;
        }
        var text = (row.textContent || '').toLowerCase();
        var match = text.indexOf(query) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    }

    var countEl = document.getElementById('dlq-visible-count');
    if (countEl) { countEl.textContent = visibleCount; }

    var filterEmpty = document.getElementById('dlq-filter-empty');
    var tableWrapper = document.querySelector('#dlq .dlq-table-wrapper');
    if (filterEmpty) { filterEmpty.style.display = visibleCount === 0 ? '' : 'none'; }
    if (tableWrapper) { tableWrapper.style.display = visibleCount === 0 ? 'none' : ''; }
}

// Initialize DLQ toasts on page load (Tor Browser compatible)
document.addEventListener('DOMContentLoaded', function() {
    showDlqToasts();
    // Apply default filter (pending & retrying) on load
    if (document.querySelector('.dlq-filter-tab')) {
        setDlqFilter('active');
    }
});

/**
 * Retry a DLQ item by ID.
 *
 * Sends an AJAX POST to the current page with action=dlqRetry.
 * Disables the button during the request and shows a toast on completion.
 * On success the row is visually marked resolved; on failure the status
 * label is updated and the row remains actionable for another attempt.
 *
 * Close all open DLQ dropdown menus
 */
function closeDlqDropdowns() {
    var menus = document.querySelectorAll('.dlq-dropdown-menu.open');
    for (var i = 0; i < menus.length; i++) {
        menus[i].classList.remove('open');
    }
}

// Close DLQ dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dlq-dropdown')) {
        closeDlqDropdowns();
    }
});

/**
 * @param {number} dlqId - The DLQ record ID
 * @param {HTMLElement} btn - The button element that was clicked
 */
function retryDlqItem(dlqId, btn) {
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        showToast('Error', 'CSRF token not found', 'error');
        return;
    }

    var retryBtn  = document.getElementById('dlq-retry-'   + dlqId);
    var abandonBtn = document.getElementById('dlq-abandon-' + dlqId);
    if (retryBtn)  { retryBtn.disabled  = true; retryBtn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Retrying...'; }
    if (abandonBtn) { abandonBtn.disabled = true; }

    var formData = new FormData();
    formData.append('action',     'dlqRetry');
    formData.append('dlq_id',     dlqId);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 90000; // 90s — Tor connections can be slow

    xhr.ontimeout = function() {
        showToast('Timeout', 'Retry timed out — the recipient may be offline', 'warning');
        if (retryBtn)  { retryBtn.disabled  = false; retryBtn.innerHTML  = '<i class="fas fa-redo"></i> Retry'; }
        if (abandonBtn) { abandonBtn.disabled = false; }
    };

    xhr.onerror = function() {
        showToast('Error', 'Network error — please try again', 'error');
        if (retryBtn)  { retryBtn.disabled  = false; retryBtn.innerHTML  = '<i class="fas fa-redo"></i> Retry'; }
        if (abandonBtn) { abandonBtn.disabled = false; }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                showToast('Delivered', 'Message successfully re-sent', 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                var errMsg = response.error || 'Retry failed — try again later';
                showToast('Retry Failed', errMsg, 'error');
                if (retryBtn)  { retryBtn.disabled  = false; retryBtn.innerHTML  = '<i class="fas fa-redo"></i> Retry'; }
                if (abandonBtn) { abandonBtn.disabled = false; }
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
            if (retryBtn)  { retryBtn.disabled  = false; retryBtn.innerHTML  = '<i class="fas fa-redo"></i> Retry'; }
            if (abandonBtn) { abandonBtn.disabled = false; }
        }
    };

    xhr.send(formData);
}

/**
 * Abandon a DLQ item by ID (marks it as abandoned — cannot be undone).
 *
 * Prompts for confirmation before sending the AJAX request.
 * Hides the row on success.
 *
 * @param {number} dlqId - The DLQ record ID
 * @param {HTMLElement} btn - The button element that was clicked
 */
function abandonDlqItem(dlqId, btn) {
    if (!confirm('Abandon this message? It will no longer be retried and this cannot be undone.')) {
        return;
    }

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        showToast('Error', 'CSRF token not found', 'error');
        return;
    }

    var retryBtn  = document.getElementById('dlq-retry-'   + dlqId);
    var abandonBtn = document.getElementById('dlq-abandon-' + dlqId);
    if (retryBtn)  { retryBtn.disabled  = true; }
    if (abandonBtn) { abandonBtn.disabled = true; abandonBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Abandoning...'; }

    var formData = new FormData();
    formData.append('action',     'dlqAbandon');
    formData.append('dlq_id',     dlqId);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 30000; // 30s — enough for a simple DB update even over Tor

    xhr.ontimeout = function() {
        showToast('Timeout', 'Request timed out — please try again', 'warning');
        if (retryBtn)  { retryBtn.disabled  = false; }
        if (abandonBtn) { abandonBtn.disabled = false; abandonBtn.innerHTML = '<i class="fas fa-ban"></i> Abandon'; }
    };

    xhr.onerror = function() {
        showToast('Error', 'Network error — please try again', 'error');
        if (retryBtn)  { retryBtn.disabled  = false; }
        if (abandonBtn) { abandonBtn.disabled = false; abandonBtn.innerHTML = '<i class="fas fa-ban"></i> Abandon'; }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                showToast('Abandoned', 'Message marked as abandoned', 'info');
                setTimeout(function() { window.location.reload(); }, 1000);
            } else {
                showToast('Error', response.error || 'Failed to abandon item', 'error');
                if (retryBtn)  { retryBtn.disabled  = false; }
                if (abandonBtn) { abandonBtn.disabled = false; abandonBtn.innerHTML = '<i class="fas fa-ban"></i> Abandon'; }
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
            if (retryBtn)  { retryBtn.disabled  = false; }
            if (abandonBtn) { abandonBtn.disabled = false; abandonBtn.innerHTML = '<i class="fas fa-ban"></i> Abandon'; }
        }
    };

    xhr.send(formData);
}

/**
 * Retry all pending/retrying transaction and contact DLQ items.
 *
 * @param {HTMLElement} btn - The button element that was clicked
 */
function retryAllDlqItems(btn) {
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        showToast('Error', 'CSRF token not found', 'error');
        return;
    }

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Retrying...'; }

    var formData = new FormData();
    formData.append('action',     'dlqRetryAll');
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 120000; // 2 min — bulk retries can be slow over Tor

    xhr.ontimeout = function() {
        showToast('Timeout', 'Bulk retry timed out — some messages may still be queued', 'warning');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Retry All'; }
    };

    xhr.onerror = function() {
        showToast('Error', 'Network error — please try again', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Retry All'; }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                var count = response.queued || 0;
                showToast('Retry All', count + ' message' + (count !== 1 ? 's' : '') + ' queued for retry', 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                showToast('Error', response.error || 'Bulk retry failed', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Retry All'; }
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Retry All'; }
        }
    };

    xhr.send(formData);
}

/**
 * Abandon all pending/retrying DLQ items (all types).
 *
 * @param {HTMLElement} btn - The button element that was clicked
 */
function abandonAllDlqItems(btn) {
    if (!confirm('Abandon all pending messages? This cannot be undone.')) {
        return;
    }

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        showToast('Error', 'CSRF token not found', 'error');
        return;
    }

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Abandoning...'; }

    var formData = new FormData();
    formData.append('action',     'dlqAbandonAll');
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 30000;

    xhr.ontimeout = function() {
        showToast('Timeout', 'Request timed out — please try again', 'warning');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban"></i> Abandon All'; }
    };

    xhr.onerror = function() {
        showToast('Error', 'Network error — please try again', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban"></i> Abandon All'; }
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                var count = response.abandoned || 0;
                showToast('Abandoned', count + ' message' + (count !== 1 ? 's' : '') + ' abandoned', 'info');
                setTimeout(function() { window.location.reload(); }, 1000);
            } else {
                showToast('Error', response.error || 'Bulk abandon failed', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban"></i> Abandon All'; }
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban"></i> Abandon All'; }
        }
    };

    xhr.send(formData);
}

/**
 * Initialize a toggle switch with status text.
 *
 * Binds a change listener to the checkbox input that updates the adjacent
 * status label text and CSS class. Uses null checks for elements that only
 * exist on specific pages (Tor Browser compatible — no optional chaining).
 *
 * @param {string} inputId - The checkbox input element ID
 * @param {string} statusId - The status text span element ID
 */
function initToggleSwitch(inputId, statusId, onChange) {
    var input = document.getElementById(inputId);
    var status = document.getElementById(statusId);
    if (input && status) {
        input.addEventListener('change', function() {
            if (this.checked) {
                status.textContent = 'Enabled';
                status.className = 'toggle-status enabled';
            } else {
                status.textContent = 'Disabled';
                status.className = 'toggle-status';
            }
            if (typeof onChange === 'function') {
                onChange(this.checked);
            }
        });
    }
}

// Initialize all toggle switches (elements only exist on their respective pages)
document.addEventListener('DOMContentLoaded', function() {
    initToggleSwitch('best-fee', 'bestFeeStatus', function(checked) {
        var experimentalNote = document.getElementById('best-fee-experimental');
        if (experimentalNote) {
            experimentalNote.style.display = checked ? 'block' : 'none';
        }
    });
    initToggleSwitch('autoRefreshEnabled', 'autoRefreshStatus');
    initToggleSwitch('autoBackupEnabled', 'autoBackupStatus');
    initToggleSwitch('contactStatusEnabled', 'contactStatusEnabledStatus');
    initToggleSwitch('contactStatusSyncOnPing', 'contactStatusSyncOnPingStatus');
    initToggleSwitch('autoChainDropPropose', 'autoChainDropProposeStatus');
    initToggleSwitch('autoChainDropAccept', 'autoChainDropAcceptStatus');
    initToggleSwitch('autoChainDropAcceptGuard', 'autoChainDropAcceptGuardStatus');
    initToggleSwitch('autoAcceptRestoredContact', 'autoAcceptRestoredContactStatus');
    initToggleSwitch('hopBudgetRandomized', 'hopBudgetRandomizedStatus');
    initToggleSwitch('apiEnabled', 'apiEnabledStatus');
    initToggleSwitch('rateLimitEnabled', 'rateLimitEnabledStatus');
    initToggleSwitch('updateCheckEnabled', 'updateCheckEnabledStatus');
    initToggleSwitch('analyticsEnabled', 'analyticsEnabledStatus');
    initSyncTimeoutDynamicMax();
});

// ============================================================================
// Analytics consent modal
// ============================================================================

/**
 * Submit analytics consent choice via AJAX and close the modal.
 * @param {boolean} enable - true to enable analytics, false to skip
 */
function submitAnalyticsConsent(enable) {
    var modal = document.getElementById('analyticsConsentModal');
    if (!modal) { return; }

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        // Fallback: hide modal even if CSRF is missing (shouldn't happen)
        modal.classList.add('d-none');
        return;
    }

    // Disable buttons to prevent double-submit
    var btns = modal.querySelectorAll('button');
    for (var i = 0; i < btns.length; i++) { btns[i].disabled = true; }

    var formData = new FormData();
    formData.append('action', 'analyticsConsent');
    formData.append('consent', enable ? '1' : '0');
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 15000;

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        // Hide modal regardless of outcome — don't block the user
        modal.classList.add('d-none');
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success && enable) {
                showToast('Analytics Enabled', 'Thank you! You can change this anytime in Settings.', 'success');
                // Update the settings toggle if it exists on the page
                var toggle = document.getElementById('analyticsEnabled');
                if (toggle) { toggle.checked = true; }
                var status = document.getElementById('analyticsEnabledStatus');
                if (status) { status.textContent = 'Enabled'; status.classList.add('enabled'); }
            }
        } catch (e) { /* modal already hidden, nothing else to do */ }
    };

    xhr.onerror = function() { modal.classList.add('d-none'); };
    xhr.ontimeout = function() { modal.classList.add('d-none'); };
    xhr.send(formData);
}

// ============================================================================
// CSP nonce-compatible event delegation (L-32)
// Replaces all inline on* handlers with data-action attributes
// ============================================================================

(function() {
    // Map of action names to handler functions
    var clickActions = {
        // Tab navigation
        'switchTab': function(el, event) {
            event.preventDefault();
            var tab = el.getAttribute('data-tab');
            var scrollTo = el.getAttribute('data-scroll-to') || null;
            switchTab(tab, scrollTo);
        },
        'switchWalletCurrency': function(el) {
            switchWalletCurrency(el.getAttribute('data-currency'));
        },
        'toggleAddressQr': function(el) { toggleAddressQr(el); },
        'openQrScanner': function(el) {
            var target = el.getAttribute('data-scan-target');
            if (target) openQrScanner(target);
        },

        // Navigation & reload
        'reloadWithHash': function(el) {
            var hash = el.getAttribute('data-hash');
            window.location.href = window.location.pathname + '#' + hash;
            window.location.reload();
        },

        // Transaction history
        'openTransactionModal': function(el) {
            var index = parseInt(el.getAttribute('data-index'), 10);
            openTransactionModal(index);
        },
        'openTransactionModalByTxid': function(el) {
            openTransactionModalByTxid(el.getAttribute('data-txid'));
        },
        'closeTransactionModal': function() { closeTransactionModal(); },

        // P2P transaction approval
        'approveP2pTransaction': function(el) {
            var txid = el.getAttribute('data-txid');
            var candidateId = el.getAttribute('data-candidate-id');
            approveP2pTransaction(txid, candidateId ? parseInt(candidateId, 10) : undefined);
        },
        'rejectP2pTransaction': function(el) {
            var txid = el.getAttribute('data-txid');
            rejectP2pTransaction(txid);
        },

        // Info icon modal (mobile-friendly tooltip replacement)
        'showInfoModal': function(el, event) {
            event.preventDefault();
            showInfoModal(el);
        },

        // Contact modal
        'openContactModal': function(el) {
            var data = el.getAttribute('data-contact');
            try { openContactModal(JSON.parse(data)); } catch (e) {}
        },
        'openContactByContactId': function(el) {
            var cid = el.getAttribute('data-contact-id');
            openContactByContactId(cid);
        },
        'closeContactModal': function() { closeContactModal(); },

        // Contact modal tabs
        'showModalTab': function(el) {
            var tab = el.getAttribute('data-tab');
            showModalTab(tab, el);
        },

        // Currency slider
        'currencySliderSelect': function(el) {
            var cur = el.getAttribute('data-currency');
            var pills = document.querySelectorAll('.currency-slider-pill');
            for (var i = 0; i < pills.length; i++) pills[i].classList.remove('active');
            el.classList.add('active');
            switchContactCurrency(cur);
        },
        'currencySliderPrev': function() { slideCurrencyTrack(-1); },
        'currencySliderNext': function() { slideCurrencyTrack(1); },

        // Contact actions
        'pingContact': function() { pingContact(); },
        'proposeChainDrop': function() { proposeChainDrop(); },
        'acceptChainDrop': function() { acceptChainDrop(); },
        'rejectChainDrop': function() { rejectChainDrop(); },
        'refreshContactModalTransactions': function() { refreshContactModalTransactions(); },
        'hideContactTxDetail': function() { hideContactTxDetail(); },

        // Contact list
        'scrollContacts': function(el) {
            var dir = parseInt(el.getAttribute('data-direction'), 10);
            scrollContacts(dir);
        },
        'toggleShowAllContacts': function() { toggleShowAllContacts(); },

        // Quick actions
        'scrollQuickActions': function(el) {
            var dir = parseInt(el.getAttribute('data-direction'), 10);
            scrollQuickActions(dir);
        },

        // Clipboard
        'copyToClipboard': function(el) {
            var text = el.getAttribute('data-copy-text');
            var msg = el.getAttribute('data-copy-message');
            copyToClipboard(text, msg);
        },
        'copyFromElement': function(el) {
            var sourceId = el.getAttribute('data-copy-source');
            var msg = el.getAttribute('data-copy-message');
            var sourceEl = document.getElementById(sourceId);
            if (sourceEl) {
                var val = sourceEl.textContent;
                if (val && val !== 'Not Available') {
                    copyToClipboard(val, msg);
                }
            }
        },

        // Wallet
        'refreshWalletData': function() { refreshWalletData(); },
        'toggleP2pInfo': function() { toggleP2pInfo(); },

        // DLQ
        'setDlqFilter': function(el) {
            var filter = el.getAttribute('data-filter');
            setDlqFilter(filter);
        },
        'retryDlqItem': function(el) {
            var id = parseInt(el.getAttribute('data-dlq-id'), 10);
            retryDlqItem(id, el);
        },
        'abandonDlqItem': function(el) {
            var id = parseInt(el.getAttribute('data-dlq-id'), 10);
            abandonDlqItem(id, el);
        },
        'retryAllDlqItems': function(el) { closeDlqDropdowns(); retryAllDlqItems(el); },
        'abandonAllDlqItems': function(el) { closeDlqDropdowns(); abandonAllDlqItems(el); },
        'toggleDlqDropdown': function(el) {
            var targetId = el.getAttribute('data-target');
            var menu = document.getElementById(targetId);
            if (!menu) return;
            var wasOpen = menu.classList.contains('open');
            closeDlqDropdowns();
            if (!wasOpen) menu.classList.add('open');
        },

        // Settings
        'toggleConfigSection': function(el) {
            var section = el.getAttribute('data-section');
            var arrow = el.getAttribute('data-arrow');
            toggleConfigSection(section, arrow);
        },
        'showDebugTab': function(el) {
            var tab = el.getAttribute('data-tab');
            showDebugTab(tab, el);
        },
        'downloadLimitedDebugReport': function() { downloadLimitedDebugReport(); },
        'downloadFullDebugReport': function() { downloadFullDebugReport(); },

        // Toast close
        'dismissToast': function(el) {
            var toast = el.parentElement;
            if (toast && toast.parentNode) { toast.parentNode.removeChild(toast); }
        },

        // Contact modal transaction detail
        'showContactTxDetail': function(el) {
            var index = parseInt(el.getAttribute('data-index'), 10);
            showContactTxDetail(index);
        },

        // Analytics consent modal
        'analyticsConsentEnable': function() { submitAnalyticsConsent(true); },
        'analyticsConsentSkip': function() { submitAnalyticsConsent(false); },

        // Add contact modal
        'openAddContactModal': function() { openAddContactModal(); },
        'closeAddContactModal': function() { closeAddContactModal(); },

        // Payment request — same form as Send, different action
        'requestPayment': function(el, event) {
            event.preventDefault();
            var actionField = document.getElementById('send-form-action');
            var form = document.getElementById('send-form-el');
            if (!actionField || !form) { return; }
            actionField.value = 'createPaymentRequest';
            showLoader('Sending payment request...', 'Connecting to contact server. The message processor will continue retrying in the background.');
            startOperationTimeout('createPaymentRequest', 'Still waiting. The request is being retried in the background. You can continue using the app and check back later.');
            form.submit();
        }
    };

    // Settings grid hint expand — click to toggle truncated hint text
    document.addEventListener('click', function(event) {
        var hint = event.target.closest('.settings-grid .form-hint');
        if (hint) { hint.classList.toggle('expanded'); }
    }, false);

    // Delegated click handler
    document.addEventListener('click', function(event) {
        // Walk up from target to find closest element with data-action or data-confirm or data-stop-propagation
        var el = event.target;
        while (el && el !== document) {
            // data-stop-propagation: stop propagation (for DLQ links inside clickable rows)
            if (el.getAttribute('data-stop-propagation') === 'true' && !el.getAttribute('data-action')) {
                event.stopPropagation();
                return; // let the default action (e.g. href) proceed
            }

            // data-confirm: confirmation dialog for form submit buttons
            if (el.getAttribute('data-confirm')) {
                if (!confirm(el.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
                return;
            }

            // data-action: dispatch to handler
            var action = el.getAttribute('data-action');
            if (action) {
                // Handle stop propagation
                if (el.getAttribute('data-stop-propagation') === 'true') {
                    event.stopPropagation();
                }

                var handler = clickActions[action];
                if (typeof handler === 'function') {
                    handler(el, event);
                }
                return;
            }

            el = el.parentElement;
        }
    }, false);

    // Delegated change handler
    document.addEventListener('change', function(event) {
        var el = event.target;
        var action = el.getAttribute('data-action-change');
        if (!action) return;

        if (action === 'showSelectedContactAddress') { showSelectedContactAddress(); }
        else if (action === 'showSelectedUserAddress') { showSelectedUserAddress(); }
        else if (action === 'switchAdvancedSection') { switchAdvancedSection(el.value); }
        else if (action === 'editCurrencyChanged') { editCurrencyChanged(el.value); }
        else if (action === 'switchContactCurrency') { switchContactCurrency(el.value); }
    }, false);

    // Delegated input handler
    document.addEventListener('input', function(event) {
        var el = event.target;
        var action = el.getAttribute('data-action-input');
        if (!action) return;

        if (action === 'filterDebugLogs') {
            var target = el.getAttribute('data-target');
            filterDebugLogs(el, target);
        }
    }, false);

    // Delegated keyup handler
    document.addEventListener('keyup', function(event) {
        var el = event.target;
        var action = el.getAttribute('data-action-keyup');
        if (!action) return;

        if (action === 'filterContacts') { filterContacts(); }
    }, false);
})();
