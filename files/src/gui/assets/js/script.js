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

// ============================================================================
// Auth page: clear wallet state from localStorage
//
// When the user lands on the unauthenticated login page (body[data-page="auth"])
// we wipe any wallet-state keys left behind by a previous session, so stale
// "Background Processing" toasts and reopen-contact hints don't leak across
// the session boundary. Safe no-op on the wallet page.
// ============================================================================
document.addEventListener('DOMContentLoaded', function() {
    if (!document.body || document.body.getAttribute('data-page') !== 'auth') return;
    var authPageKeys = [
        'eiou_pending_operation',
        'eiou_timeout_message',
        'eiou_reopen_contact_id',
        'eiou_reopen_contact_tab'
    ];
    try {
        for (var i = 0; i < authPageKeys.length; i++) {
            localStorage.removeItem(authPageKeys[i]);
        }
    } catch (e) {}
});

// Relocate the Contact detail modal to document.body so it renders above
// every tab-panel. The markup lives inside the Contacts tab partial
// (#tab-panel-contacts), which is display:none when any other tab is
// active — hiding the modal along with it. Lifting it once at page load
// lets us open it from the Transaction Details modal (or anywhere else)
// without first having to switch to the Contacts tab.
document.addEventListener('DOMContentLoaded', function() {
    var contactModalEl = document.getElementById('contactModal');
    if (contactModalEl && contactModalEl.parentNode !== document.body) {
        document.body.appendChild(contactModalEl);
    }
});

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

/**
 * Display-time normalisation for transaction descriptions. Old contact-
 * request txs stored their description as the long phrase "Contact request
 * transaction"; the current default is the shorter "Contact request".
 * Render the short form regardless of what's in the DB so the history looks
 * consistent across old + new rows. Returns the input unchanged for any
 * other description.
 */
function displayTxDescription(desc) {
    if (desc === 'Contact request transaction') {
        return 'Contact request';
    }
    return desc;
}

// ============================================================================
// Paginator — shared pagination utility for server-rendered tables
// ============================================================================
//
// The wallet has three tables (Recent Transactions, Contacts, Payment
// Requests history) that server-render a capped slice of rows and then do
// 100% client-side sort / filter / search on the rendered DOM. Paginator
// layers on top of that so the user isn't presented with one long scroll.
//
// Row visibility is driven by two independent classes (both apply
// `display: none`):
//   - `.filter-hidden` — set by filter/search code; unrelated to pagination
//   - `.paginator-hidden` — set by Paginator to hide rows outside current page
// A row is visible iff neither class is present. Filter code doesn't touch
// `.paginator-hidden`; Paginator doesn't touch `.filter-hidden`. Sort only
// reorders DOM nodes, so the two hidden states stay consistent.
//
// Integration: after the filter or sort function runs, call
// `Paginator.get(key).apply()` so Paginator recomputes which subset of the
// currently-visible (unfiltered) rows falls in the active page.
//
// Phase 2 "Load older" is layered on via the `loadMore` config: Paginator
// renders a button below the nav that triggers the configured fetcher,
// appends the returned HTML fragment to the tbody, and re-runs `apply()`.

var PAGINATOR_SIZE_OPTIONS = [25, 50, 100, 0]; // 0 = All
var PAGINATOR_DEFAULT_SIZE = 25;

var Paginator = (function () {
    var instances = {};

    function storageKey(key) {
        return 'eiou_paginator_size_' + key;
    }

    function loadSize(key) {
        try {
            var v = safeStorageGet(storageKey(key));
            if (v === null || v === undefined || v === '') return PAGINATOR_DEFAULT_SIZE;
            var n = parseInt(v, 10);
            if (isNaN(n)) return PAGINATOR_DEFAULT_SIZE;
            // Sanity-clamp: reject sizes not in our option set to avoid an
            // orphaned localStorage value surviving a future option change.
            for (var i = 0; i < PAGINATOR_SIZE_OPTIONS.length; i++) {
                if (PAGINATOR_SIZE_OPTIONS[i] === n) return n;
            }
            return PAGINATOR_DEFAULT_SIZE;
        } catch (e) {
            return PAGINATOR_DEFAULT_SIZE;
        }
    }

    function saveSize(key, size) {
        try { safeStorageSet(storageKey(key), String(size)); } catch (e) {}
    }

    function create(config) {
        // config: { key, tbody, rowSelector, container, loadMore?: { button, onClick } }
        if (!config || !config.tbody || !config.container) return null;
        var state = {
            key: config.key,
            tbody: config.tbody,
            rowSelector: config.rowSelector || 'tr',
            container: config.container,
            page: 0,
            size: loadSize(config.key),
            loadMoreFn: config.loadMore && config.loadMore.onClick ? config.loadMore.onClick : null,
            // Per-table button label — contacts are alphabetically
            // sorted, so "Load older" is wrong for them. Transactions
            // and payment requests default to "Load older" because
            // they're reverse-chronological. Callers can override.
            loadMoreLabel: (config.loadMore && config.loadMore.label) ? config.loadMore.label : 'Load older',
            loadMoreExhausted: false,
            loadMoreBusy: false
        };

        function getRows() {
            return state.tbody.querySelectorAll(state.rowSelector);
        }

        function getVisibleRows() {
            // "Visible" here means "not filtered out" — i.e. the rows the
            // paginator is allowed to page through. Paginator's own
            // `.paginator-hidden` is ignored because we're about to
            // recompute it.
            var rows = getRows();
            var out = [];
            for (var i = 0; i < rows.length; i++) {
                if (!rows[i].classList.contains('filter-hidden')) {
                    out.push(rows[i]);
                }
            }
            return out;
        }

        function pageCount(visibleLen) {
            if (state.size === 0) return 1;
            if (visibleLen <= 0) return 1;
            return Math.ceil(visibleLen / state.size);
        }

        function apply() {
            var visible = getVisibleRows();
            var pages = pageCount(visible.length);
            if (state.page >= pages) state.page = pages - 1;
            if (state.page < 0) state.page = 0;

            if (state.size === 0) {
                // "All" — every visible row stays visible
                for (var i = 0; i < visible.length; i++) {
                    visible[i].classList.remove('paginator-hidden');
                }
            } else {
                var start = state.page * state.size;
                var end = start + state.size;
                for (var j = 0; j < visible.length; j++) {
                    if (j >= start && j < end) {
                        visible[j].classList.remove('paginator-hidden');
                    } else {
                        visible[j].classList.add('paginator-hidden');
                    }
                }
            }

            renderControls(visible.length, pages);
        }

        function renderControls(visibleLen, pages) {
            var sizeSelectId = 'paginator-size-' + state.key;
            var html = '';
            html += '<div class="paginator-row">';

            // Page size selector
            html += '<label class="paginator-size-label" for="' + sizeSelectId + '">';
            html += 'Rows:&nbsp;';
            html += '<select id="' + sizeSelectId + '" class="paginator-size-select">';
            for (var i = 0; i < PAGINATOR_SIZE_OPTIONS.length; i++) {
                var opt = PAGINATOR_SIZE_OPTIONS[i];
                var label = opt === 0 ? 'All' : String(opt);
                var sel = (opt === state.size) ? ' selected' : '';
                html += '<option value="' + opt + '"' + sel + '>' + label + '</option>';
            }
            html += '</select>';
            html += '</label>';

            // Range summary
            var rangeText;
            if (visibleLen === 0) {
                rangeText = '0 rows';
            } else if (state.size === 0) {
                rangeText = 'All ' + visibleLen + ' rows';
            } else {
                var from = state.page * state.size + 1;
                var to = Math.min(from + state.size - 1, visibleLen);
                rangeText = from + '–' + to + ' of ' + visibleLen;
            }
            html += '<span class="paginator-range">' + rangeText + '</span>';

            // Page navigation (only when more than one page)
            if (pages > 1) {
                html += '<span class="paginator-nav">';
                html += '<button type="button" class="paginator-btn paginator-prev" ' + (state.page === 0 ? 'disabled' : '') + ' data-paginator-action="prev" data-paginator-key="' + escapeHtml(state.key) + '" aria-label="Previous page"><i class="fas fa-chevron-left"></i></button>';
                html += '<span class="paginator-page-indicator">Page ' + (state.page + 1) + ' / ' + pages + '</span>';
                html += '<button type="button" class="paginator-btn paginator-next" ' + (state.page + 1 >= pages ? 'disabled' : '') + ' data-paginator-action="next" data-paginator-key="' + escapeHtml(state.key) + '" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>';
                html += '</span>';
            }

            // Load-older button (Phase 2)
            if (state.loadMoreFn && !state.loadMoreExhausted) {
                var busyIcon = state.loadMoreBusy ? 'fa-spinner fa-spin' : 'fa-cloud-download-alt';
                var busyLabel = state.loadMoreBusy ? 'Loading…' : state.loadMoreLabel;
                html += '<button type="button" class="paginator-btn paginator-load-more" ' + (state.loadMoreBusy ? 'disabled' : '') + ' data-paginator-action="load-more" data-paginator-key="' + escapeHtml(state.key) + '"><i class="fas ' + busyIcon + '"></i> ' + escapeHtml(busyLabel) + '</button>';
            }

            html += '</div>';
            state.container.innerHTML = html;
        }

        function goTo(page) {
            state.page = page;
            apply();
        }

        function setSize(size) {
            state.size = size;
            state.page = 0;
            saveSize(state.key, size);
            apply();
        }

        function appendFragment(html) {
            // Parse and append — use a template so <tr> fragments are allowed
            // outside of <tbody>'s normal context.
            var template = document.createElement('template');
            template.innerHTML = html.trim();
            while (template.content.firstChild) {
                state.tbody.appendChild(template.content.firstChild);
            }
        }

        function setLoadMoreExhausted(exhausted) {
            state.loadMoreExhausted = !!exhausted;
        }

        function setLoadMoreBusy(busy) {
            state.loadMoreBusy = !!busy;
            apply();
        }

        function getLoadedCount() {
            return getRows().length;
        }

        var api = {
            apply: apply,
            goTo: goTo,
            setSize: setSize,
            appendFragment: appendFragment,
            setLoadMoreExhausted: setLoadMoreExhausted,
            setLoadMoreBusy: setLoadMoreBusy,
            getLoadedCount: getLoadedCount,
            state: state
        };
        instances[config.key] = api;
        apply();
        return api;
    }

    function get(key) {
        return instances[key] || null;
    }

    // Delegated click/change handlers — bound once at DOMContentLoaded.
    function handleClick(event) {
        var el = event.target;
        while (el && el !== document) {
            var action = el.getAttribute && el.getAttribute('data-paginator-action');
            if (action) {
                var key = el.getAttribute('data-paginator-key');
                var inst = instances[key];
                if (!inst) return;
                event.preventDefault();
                if (action === 'prev') {
                    inst.goTo(inst.state.page - 1);
                } else if (action === 'next') {
                    inst.goTo(inst.state.page + 1);
                } else if (action === 'load-more') {
                    if (inst.state.loadMoreFn && !inst.state.loadMoreBusy) {
                        inst.setLoadMoreBusy(true);
                        inst.state.loadMoreFn(inst);
                    }
                }
                return;
            }
            el = el.parentNode;
        }
    }

    function handleChange(event) {
        var el = event.target;
        if (!el || !el.classList || !el.classList.contains('paginator-size-select')) return;
        var match = el.id.match(/^paginator-size-(.+)$/);
        if (!match) return;
        var inst = instances[match[1]];
        if (!inst) return;
        inst.setSize(parseInt(el.value, 10));
    }

    document.addEventListener('click', handleClick);
    document.addEventListener('change', handleChange);

    return {
        create: create,
        get: get
    };
})();

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

    // Recompute truncation on the contacts table once it's visible
    if (tabName === 'contacts' && typeof markTruncatedContactNumberCells === 'function') {
        markTruncatedContactNumberCells();
    }

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
    var addressTypeGroup = document.getElementById('address-type-group');
    var addressTypeSelect = document.getElementById('address-type');

    // Set initial state - manual address is visible by default
    if (manualAddressInput) manualAddressInput.required = true;

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
            // Sort by security preference: tor > https > http, then any others
            var preferredDisplayOrder = ['tor', 'https', 'http'];
            addressTypes.sort(function(a, b) {
                var ai = preferredDisplayOrder.indexOf(a);
                var bi = preferredDisplayOrder.indexOf(b);
                if (ai === -1) ai = preferredDisplayOrder.length;
                if (bi === -1) bi = preferredDisplayOrder.length;
                return ai - bi;
            });

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

// Section-intros always ship closed (HTML has no `open` attribute). Users
// expand on demand via the native <details>/<summary> toggle — works with
// or without JS, any viewport.

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
    var el = document.getElementById('editContactModal');
    if (el) el.style.display = 'none';
}

function openAddContactModal() {
    var el = document.getElementById('add-contact-modal');
    if (el) el.style.display = 'flex';
}

function closeAddContactModal() {
    var el = document.getElementById('add-contact-modal');
    if (el) el.style.display = 'none';
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

    // Header with amount. Contact-request transactions carry amount=0 by
    // definition (they only exist to establish a bilateral link) — showing
    // "+0 USD" with a green/red sent/received tint misrepresents them as
    // money movements. For contact reqs, promote the direction (Sent /
    // Received with arrow icon) into the header's hero slot instead of a
    // muted em-dash above a duplicate direction line.
    var isContactReq = tx.tx_type === 'contact';
    var headerClass = isContactReq
        ? 'tx-modal-header-neutral'
        : (tx.type === 'sent' ? 'tx-modal-header-sent' : 'tx-modal-header-received');
    html += '<div class="tx-modal-header ' + headerClass + '">';
    if (isContactReq) {
        html += '<div class="tx-modal-amount tx-modal-amount-placeholder"><i class="fas ' + directionIcon + '"></i> ' + directionText + '</div>';
    } else {
        html += '<div class="tx-modal-amount">' + (tx.type === 'sent' ? '-' : '+') + parseFloat(tx.amount).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency) + '</div>';
        html += '<div class="tx-modal-direction"><i class="fas ' + directionIcon + '"></i> ' + directionText + '</div>';
    }
    html += '</div>';

    // Status, type, and role badges
    html += '<div class="tx-modal-badges">';
    html += statusBadge;
    html += txTypeBadge;
    html += roleBadge;
    html += '</div>';

    // DLQ delivery failure warning
    if (tx.in_dlq) {
        html += '<div class="tx-modal-dlq-warning">';
        html += '<i class="fas fa-exclamation-triangle"></i> ';
        html += 'Delivery failed — this transaction is in the <a href="#dlq" onclick="closeTransactionModal()">Failed Messages</a> queue for retry.';
        html += '</div>';
    }

    // Details section
    html += '<div class="tx-detail-section">';

    // Counterparty (To/From) — clickable when the counterparty resolves to
    // one of our contacts, so the user can jump straight from a transaction
    // detail to that contact's modal.
    html += '<div class="tx-detail-row">';
    html += '<div class="tx-detail-label">' + (tx.type === 'sent' ? 'To' : 'From') + '</div>';
    var cpValueHtml = '';
    if (tx.counterparty_name) {
        cpValueHtml += '<strong>' + escapeHtml(tx.counterparty_name) + '</strong><br>';
    }
    cpValueHtml += '<span class="tx-modal-mono">' + escapeHtml(tx.counterparty_address) + '</span>';
    if (tx.counterparty_contact_id) {
        html += '<div class="tx-detail-value tx-detail-value-link cursor-pointer"'
            + ' data-action="jumpToContactFromTxModal"'
            + ' data-contact-id="' + escapeHtml(tx.counterparty_contact_id) + '"'
            + ' title="Open contact">' + cpValueHtml + '</div>';
    } else {
        html += '<div class="tx-detail-value">' + cpValueHtml + '</div>';
    }
    html += '</div>';

    // Description (moved up, right after To/From)
    if (tx.description) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Description</div>';
        html += '<div class="tx-detail-value">' + escapeHtml(displayTxDescription(tx.description)) + '</div>';
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

    // Previous Transaction — the immediately prior tx on the same chain
    // with this counterparty. Clickable: openTransactionModalByTxid
    // has a fast in-memory path and an AJAX fallback so chains can be
    // traversed even when the prior tx isn't in the current view window.
    if (tx.previous_txid) {
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">Previous Tx</div>';
        html += '<div class="tx-detail-value tx-modal-mono-sm tx-detail-value-link cursor-pointer"'
             +  ' data-action="openTransactionModalByTxid"'
             +  ' data-txid="' + escapeHtml(tx.previous_txid) + '"'
             +  ' title="Open previous transaction">'
             +  escapeHtml(tx.previous_txid)
             +  '</div>';
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

        // End Recipient — clickable when we recognise them as a contact
        // (typical case: we P2P'd through someone else because we had no
        // direct credit line with the end recipient).
        html += '<div class="tx-detail-row">';
        html += '<div class="tx-detail-label">End Recipient</div>';
        var endValueHtml = '';
        if (tx.p2p_destination_contact_name) {
            endValueHtml += '<strong>' + escapeHtml(tx.p2p_destination_contact_name) + '</strong><br>';
        }
        endValueHtml += '<span class="tx-modal-mono">' + escapeHtml(tx.p2p_destination) + '</span>';
        if (tx.p2p_destination_contact_id) {
            html += '<div class="tx-detail-value tx-detail-value-link cursor-pointer"'
                + ' data-action="jumpToContactFromTxModal"'
                + ' data-contact-id="' + escapeHtml(tx.p2p_destination_contact_id) + '"'
                + ' title="Open contact">' + endValueHtml + '</div>';
        } else {
            html += '<div class="tx-detail-value tx-modal-mono">' + escapeHtml(tx.p2p_destination) + '</div>';
        }
        html += '</div>';

        // Amount to Recipient
        if (tx.p2p_amount) {
            html += '<div class="tx-detail-row">';
            html += '<div class="tx-detail-label">Amount to Recipient</div>';
            html += '<div class="tx-detail-value">' + parseFloat(tx.p2p_amount).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency) + '</div>';
            html += '</div>';
        }

        // Routing Fee — always show when we can compute it (total amount −
        // amount forwarded to the end recipient), so users see explicitly
        // what the relay charged rather than having to subtract in their head.
        var routingFee = null;
        if (tx.p2p_fee) {
            routingFee = parseFloat(tx.p2p_fee);
        } else if (tx.amount != null && tx.p2p_amount != null) {
            var totalAmt = parseFloat(tx.amount);
            var recipAmt = parseFloat(tx.p2p_amount);
            if (!isNaN(totalAmt) && !isNaN(recipAmt)) {
                routingFee = Math.abs(totalAmt - recipAmt);
            }
        }
        if (routingFee !== null && !isNaN(routingFee)) {
            html += '<div class="tx-detail-row">';
            html += '<div class="tx-detail-label">Routing Fee</div>';
            html += '<div class="tx-detail-value">' + routingFee.toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency) + '</div>';
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
        // Clear the stack marker set when the tx modal was opened from
        // within another modal (e.g. DLQ detail → tx details).
        modal.classList.remove('modal-stack-top');
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
    var whatsNewModal = document.getElementById('whatsNewModal');

    if (event.target === editModal) {
        closeEditContactModal();
    }
    if (event.target === txModal) {
        closeTransactionModal();
    }
    if (event.target === addContactModal) {
        closeAddContactModal();
    }
    if (event.target === whatsNewModal) {
        closeWhatsNewModal();
    }

    var resetModal = document.getElementById('settingsResetToDefaultsModal');
    if (resetModal && event.target === resetModal) {
        resetModal.classList.add('d-none');
    }

    // Pending-contact modals are rendered one per request (id
    // "pending-contact-modal-<index>"). Any click on the backdrop of any
    // of them closes that specific modal.
    if (event.target && event.target.matches && event.target.matches('[data-pending-contact-modal]')) {
        event.target.classList.add('d-none');
    }

    // API-keys modals (all dispatch through the apiKeys IIFE so state
    // gets cleaned up correctly; e.g. pendingEdit / pendingDelete are reset)
    if (window.apiKeys) {
        var ak = window.apiKeys;
        if (event.target.id === 'apiKeysVerifyModal')      ak.closeVerifyModal();
        else if (event.target.id === 'apiKeysCreateModal')     ak.closeCreateModal();
        else if (event.target.id === 'apiKeysRevealModal')     ak.closeRevealModal();
        else if (event.target.id === 'apiKeysDetailModal')     ak.closeDetailModal();
        else if (event.target.id === 'apiKeysDeleteModal')     ak.closeDeleteModal();
        else if (event.target.id === 'apiKeysDisableAllModal') ak.closeDisableAllModal();
        else if (event.target.id === 'apiKeysDeleteAllModal')  ak.closeDeleteAllModal();
    }
}

// Close modal with Escape key (Tor Browser compatible - uses keyCode fallback)
document.addEventListener('keydown', function(event) {
    var isEscape = event.key === 'Escape' || event.keyCode === 27;
    if (!isEscape) return;
    // Stacked-modal case: if a modal is currently flagged as stacked on
    // top of another (e.g. contact modal opened from tx modal, or tx
    // modal opened from DLQ modal via its Transaction ID link), Escape
    // should dismiss only the top modal so the user returns to the
    // underlying modal they were reading.
    var contactModalEl = document.getElementById('contactModal');
    if (contactModalEl
        && contactModalEl.classList.contains('modal-stack-top')
        && contactModalEl.style.display !== 'none') {
        closeContactModal();
        return;
    }
    var txModalEl = document.getElementById('transactionModal');
    if (txModalEl
        && txModalEl.classList.contains('modal-stack-top')
        && txModalEl.style.display !== 'none') {
        closeTransactionModal();
        return;
    }
    closeEditContactModal();
    closeTransactionModal();
    closeContactModal();
    closeAddContactModal();
    closeWhatsNewModal();
    // Close any open pending-contact modal on Escape.
    var openPending = document.querySelectorAll('[data-pending-contact-modal]:not(.d-none)');
    for (var pi = 0; pi < openPending.length; pi++) {
        openPending[pi].classList.add('d-none');
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

// ============================================================================
// Event-toast system (extended showToast)
//
// Richer sibling of showToast() used by live event notifications. Keeps the
// existing showToast() signature unchanged for all legacy callers (clipboard,
// one-shot notices, etc.) — those don't need the extra machinery and 5s is
// right for them.
//
// Features layered here:
//   - pause-on-hover (timer freezes while mouse is over the toast)
//   - click body to expand → shows `details`, becomes sticky (no auto-dismiss)
//   - explicit × dismiss + optional action button ("View")
//   - dedupKey: re-firing with same key updates the live toast in place
//     instead of stacking (e.g. tx pending→sent→completed = 1 toast)
//   - aggregation: ≥3 fires of same `kind` within AGGREGATE_WINDOW_MS collapse
//     into a single "N new X" toast whose expanded state lists them
//   - modal suppression: if any `.modal` resolves to a visible computed
//     display (this app toggles via inline style / `d-none`, not `.show`),
//     fires are queued and flushed as an aggregate when the modal closes
//   - duration: user-preference in ms; 0 = until-dismissed
// ============================================================================

var EVENT_TOAST_DEFAULT_DURATION_MS = 10000; // overridden by user setting
var AGGREGATE_WINDOW_MS = 3000;              // recent-fires window for aggregation
var AGGREGATE_THRESHOLD = 3;                 // N same-kind fires → one aggregate
var eventToastQueue = [];                    // holds events while a modal is open
var eventToastRecentByKind = {};             // kind → [{ts, event}, ...]
var eventToastsByDedupKey = {};              // dedupKey → live toast element

function isBlockingModalOpen() {
    // "Blocking" = anything that'd make a toast steal focus or overlap.
    // This app's `.modal` CSS defaults to `display: flex`; visibility is
    // toggled via inline `style.display` OR the `d-none` class, so the
    // Bootstrap-style `.modal.show` check alone never matches here. Walk
    // every `.modal` and ask the engine whether it resolves to a visible
    // display — this captures both hiding patterns without hard-coding them.
    var modals = document.querySelectorAll('.modal');
    for (var i = 0; i < modals.length; i++) {
        var disp = window.getComputedStyle(modals[i]).display;
        if (disp && disp !== 'none') return true;
    }
    // Defensive: framework patterns that may appear in grafted-in partials.
    return !!(
        document.querySelector('[role="dialog"][aria-hidden="false"]') ||
        document.querySelector('.modal-overlay.is-visible') ||
        document.querySelector('.modal-overlay.active')
    );
}

function kindIcon(kind) {
    var map = {
        payment_request: 'fa-file-invoice-dollar',
        contact_request: 'fa-user-plus',
        tx_received: 'fa-arrow-down',
        tx_status: 'fa-exchange-alt',
        dlq: 'fa-exclamation-triangle',
    };
    return map[kind] || 'fa-info-circle';
}

function kindType(kind) {
    // Maps event kind → base toast colour variant (uses existing CSS).
    var map = {
        payment_request: 'info',
        contact_request: 'info',
        tx_received: 'success',
        tx_status: 'info',
        dlq: 'error',
    };
    return map[kind] || 'info';
}

function removeEventToast(toast) {
    if (!toast || !toast.parentElement) return;
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100px)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(function() {
        if (toast.parentElement) {
            toast.parentElement.removeChild(toast);
        }
    }, 300);
    // Drop any dedup-key pointer to this toast so a later fire re-creates.
    var key = toast.getAttribute('data-dedup-key');
    if (key && eventToastsByDedupKey[key] === toast) {
        delete eventToastsByDedupKey[key];
    }
}

function armToastAutoDismiss(toast, durationMs) {
    // 0 = persist until user dismisses. Also applies while the toast is in
    // "expanded/sticky" mode (user clicked the body).
    if (!durationMs || durationMs <= 0) return;
    if (toast._dismissTimer) clearTimeout(toast._dismissTimer);
    toast._dismissTimer = setTimeout(function() {
        removeEventToast(toast);
    }, durationMs);
}

function attachHoverPause(toast, durationMs) {
    if (!durationMs || durationMs <= 0) return;
    toast.addEventListener('mouseenter', function() {
        if (toast._dismissTimer) {
            clearTimeout(toast._dismissTimer);
            toast._dismissTimer = null;
        }
    });
    toast.addEventListener('mouseleave', function() {
        // Don't re-arm if user already expanded the toast (sticky mode).
        if (toast.classList.contains('toast-expanded')) return;
        armToastAutoDismiss(toast, durationMs);
    });
}

function renderEventDetails(details) {
    // Best-effort rendering: key/value pairs. Values are escaped; keys are
    // humanized to Title Case from snake_case.
    if (!details || typeof details !== 'object') return '';
    var html = '<dl class="toast-details">';
    for (var k in details) {
        if (!Object.prototype.hasOwnProperty.call(details, k)) continue;
        if (details[k] === null || details[k] === undefined || details[k] === '') continue;
        var label = k.replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
        html += '<dt>' + escapeHtml(label) + '</dt>';
        html += '<dd>' + escapeHtml(String(details[k])) + '</dd>';
    }
    html += '</dl>';
    return html;
}

function showEventToast(opts) {
    opts = opts || {};
    // If a modal is blocking, queue and let the flush-on-close path pick it up.
    if (isBlockingModalOpen()) {
        eventToastQueue.push(opts);
        return null;
    }
    // Safety net for modal close paths that don't fire `click` or `keydown:Escape`
    // (programmatic close, navigation that doesn't redirect, code paths we
    // didn't hook below) — drain any queue built up during the modal session
    // BEFORE rendering the current event so earlier events replay in order.
    // flushEventToastQueue splices the buffer to 0 on entry, so the recursive
    // showEventToast calls it makes can't trigger this branch again.
    if (eventToastQueue.length > 0) {
        flushEventToastQueue();
    }

    // Aggregation check: if ≥AGGREGATE_THRESHOLD same-kind fires happened in
    // the last AGGREGATE_WINDOW_MS, fold this + recent into one toast.
    var kind = opts.kind || 'info';
    var now = Date.now();
    var recent = eventToastRecentByKind[kind] || [];
    recent = recent.filter(function(e) { return now - e.ts <= AGGREGATE_WINDOW_MS; });
    recent.push({ ts: now, event: opts });
    eventToastRecentByKind[kind] = recent;
    if (recent.length >= AGGREGATE_THRESHOLD) {
        // Pop any individual toasts fired in this window so we don't
        // duplicate. The aggregate toast supersedes them.
        for (var i = 0; i < recent.length - 1; i++) {
            var prevKey = recent[i].event && recent[i].event.dedupKey;
            if (prevKey && eventToastsByDedupKey[prevKey]) {
                removeEventToast(eventToastsByDedupKey[prevKey]);
            }
        }
        var aggEvents = recent.map(function(e) { return e.event; });
        // Clear the buffer so the next fire starts a fresh window.
        eventToastRecentByKind[kind] = [];
        return showEventToast({
            kind: kind,
            title: aggEvents.length + ' new ' + (kind === 'payment_request' ? 'payment requests' :
                   kind === 'contact_request' ? 'contact requests' :
                   kind === 'tx_received' ? 'received transactions' :
                   kind === 'tx_status' ? 'transaction updates' :
                   kind === 'dlq' ? 'delivery failures' : 'events'),
            message: 'Click to expand.',
            details: { items: aggEvents.map(function(e) { return e.title + (e.message ? ' — ' + e.message : ''); }).join('\n') },
            dedupKey: 'agg:' + kind + ':' + now,
            duration: opts.duration,
            action: null,
        });
    }

    // Dedup: if we already have a live toast for this key, mutate instead of
    // stacking. Tx status churn (pending → sent → completed) flows through here.
    if (opts.dedupKey && eventToastsByDedupKey[opts.dedupKey]) {
        var live = eventToastsByDedupKey[opts.dedupKey];
        var titleEl = live.querySelector('.toast-title');
        var msgEl = live.querySelector('.toast-message');
        if (titleEl) titleEl.textContent = opts.title || '';
        if (msgEl) msgEl.textContent = opts.message || '';
        // Refresh timer on update so user sees the latest long enough.
        armToastAutoDismiss(live, opts.duration != null ? opts.duration : EVENT_TOAST_DEFAULT_DURATION_MS);
        return live;
    }

    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    var type = kindType(kind);
    var icon = kindIcon(kind);
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type + ' toast-event';
    if (opts.dedupKey) toast.setAttribute('data-dedup-key', opts.dedupKey);

    var bodyHtml = '<i class="fas ' + icon + ' toast-icon"></i>' +
        '<div class="toast-content">' +
        '<div class="toast-title">' + escapeHtml(opts.title || '') + '</div>' +
        '<div class="toast-message">' + escapeHtml(opts.message || '') + '</div>' +
        '<div class="toast-details-wrap" style="display:none">' + renderEventDetails(opts.details) + '</div>' +
        '</div>';
    if (opts.action && opts.action.label) {
        bodyHtml += '<button class="btn btn-sm btn-primary toast-action" type="button">' +
            escapeHtml(opts.action.label) + '</button>';
    }
    bodyHtml += '<button class="toast-close" type="button" aria-label="Dismiss">&times;</button>';
    toast.innerHTML = bodyHtml;

    // Wire up handlers — close, action, click-to-expand.
    toast.querySelector('.toast-close').addEventListener('click', function(e) {
        e.stopPropagation();
        removeEventToast(toast);
    });
    var actionBtn = toast.querySelector('.toast-action');
    if (actionBtn && opts.action && typeof opts.action.onClick === 'function') {
        actionBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            try { opts.action.onClick(); } catch (err) { /* swallow */ }
            removeEventToast(toast);
        });
    }
    toast.addEventListener('click', function() {
        // Click body → expand + make sticky.
        var wrap = toast.querySelector('.toast-details-wrap');
        if (!wrap) return;
        var alreadyExpanded = toast.classList.contains('toast-expanded');
        if (alreadyExpanded) return;
        toast.classList.add('toast-expanded');
        wrap.style.display = 'block';
        if (toast._dismissTimer) {
            clearTimeout(toast._dismissTimer);
            toast._dismissTimer = null;
        }
    });

    container.appendChild(toast);
    if (opts.dedupKey) eventToastsByDedupKey[opts.dedupKey] = toast;

    var duration = opts.duration != null ? opts.duration : EVENT_TOAST_DEFAULT_DURATION_MS;
    armToastAutoDismiss(toast, duration);
    attachHoverPause(toast, duration);
    return toast;
}

function flushEventToastQueue() {
    // Called when a blocking modal closes — batch-flushes as a single
    // aggregate to avoid a toast storm.
    if (eventToastQueue.length === 0) return;
    var batch = eventToastQueue.splice(0, eventToastQueue.length);
    if (batch.length === 1) {
        showEventToast(batch[0]);
        return;
    }
    showEventToast({
        kind: 'info',
        title: batch.length + ' events while you were busy',
        message: 'Click to expand.',
        details: { items: batch.map(function(e) { return e.title; }).join('\n') },
        dedupKey: 'flush:' + Date.now(),
    });
}
// Watch for blocking modals closing so we can flush the queue. Listens on
// BOTH click and `keydown:Escape` — a user who dismisses a modal via the
// Escape key (handled by the document-level keydown listener further up
// the file) would otherwise see their queued toasts sit buffered until
// their next mouse click, since the keydown path never fires a click event.
function checkFlushOnInteraction() {
    // 50ms lets the actual modal-close handler (click dispatch / keydown
    // close) run and update the DOM first, so isBlockingModalOpen() sees
    // the post-close state rather than racing it.
    setTimeout(function() {
        if (!isBlockingModalOpen()) flushEventToastQueue();
    }, 50);
}
document.addEventListener('click', checkFlushOnInteraction);
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) checkFlushOnInteraction();
});

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

                // Defaults guard — if any currency is at both the default
                // fee AND default credit, warn before accepting. User's
                // defaults come from data attributes on the accept-all form
                // (emitted by PHP from user settings). Skipped if all
                // currencies were customized.
                var defaultFee = form.getAttribute('data-default-fee');
                var defaultCredit = form.getAttribute('data-default-credit');
                if (defaultFee !== null && defaultCredit !== null) {
                    var untouched = [];
                    for (var m = 0; m < currencies.length; m++) {
                        // Number comparison — the rendered value may have
                        // trailing zeros etc. that string-compare would miss.
                        if (parseFloat(currencies[m].fee) === parseFloat(defaultFee)
                            && parseFloat(currencies[m].credit) === parseFloat(defaultCredit)) {
                            untouched.push(currencies[m].currency);
                        }
                    }
                    if (untouched.length > 0 && !form.dataset.guardConfirmed) {
                        e.preventDefault();
                        var msg = untouched.length === currencies.length
                            ? 'You\'re accepting all ' + currencies.length + ' currencies with your default fee ' + defaultFee + '% and credit limit ' + defaultCredit + '. Continue?'
                            : 'The following currencies are at your default fee ' + defaultFee + '% and credit limit ' + defaultCredit + ': ' + untouched.join(', ') + '. Continue?';
                        if (!confirm(msg)) return;
                        // One-shot bypass — set a flag, re-submit, skip the
                        // guard on the second pass.
                        form.dataset.guardConfirmed = '1';
                        form.submit();
                        return;
                    }
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
var currentContactCurrencies = [];
var currentContactBalances = {};

/**
 * Filters the contact list based on search text, status / chain / online
 * dropdowns. Case-insensitive substring match against name and address.
 * Tor-Browser-friendly (var + indexOf).
 *
 * @returns {void}
 */
function filterContacts() {
    var searchInput = document.getElementById('contact-search-input');
    var searchStatus = document.getElementById('contact-search-status');
    var searchCount = document.getElementById('contact-search-count');

    if (!searchInput) return;

    var searchTerm = searchInput.value.toLowerCase().trim();

    var statusEl = document.getElementById('contact-filter-status');
    var chainEl = document.getElementById('contact-filter-chain');
    var onlineEl = document.getElementById('contact-filter-online');
    var statusFilter = statusEl ? statusEl.value : '';
    var chainFilter = chainEl ? chainEl.value : '';
    var onlineFilter = onlineEl ? onlineEl.value : '';

    var hasActiveFilter = (searchTerm !== '') || (statusFilter !== '') || (chainFilter !== '') || (onlineFilter !== '');

    var contactCards = document.querySelectorAll('.contact-card');
    var visibleCount = 0;

    for (var i = 0; i < contactCards.length; i++) {
        var card = contactCards[i];
        var contactName = card.getAttribute('data-contact-name') || '';
        var contactAddress = card.getAttribute('data-contact-address') || '';
        var rowStatus = card.getAttribute('data-status') || '';
        var rowChain = card.getAttribute('data-chain-state') || '';
        var rowOnline = card.getAttribute('data-online') || '';

        // Match each filter dimension; an empty filter passes everything
        var matchesSearch = (searchTerm === '') || contactName.indexOf(searchTerm) !== -1 || contactAddress.indexOf(searchTerm) !== -1;
        var matchesStatus = (statusFilter === '') || rowStatus === statusFilter;
        var matchesChain;
        if (chainFilter === '') {
            matchesChain = true;
        } else if (chainFilter === 'issues') {
            // Anything that needs attention: incoming proposal, awaiting response, rejected, or chain warning
            matchesChain = (rowChain === 'action') || (rowChain === 'waiting') || (rowChain === 'rejected') || (rowChain === 'warning');
        } else if (chainFilter === 'valid') {
            matchesChain = rowChain === 'valid';
        } else {
            matchesChain = rowChain === chainFilter;
        }
        var matchesOnline;
        if (onlineFilter === '') {
            matchesOnline = true;
        } else if (onlineFilter === 'online') {
            // Lump partial in with online — both mean "at least one address reachable"
            matchesOnline = (rowOnline === 'online') || (rowOnline === 'partial');
        } else {
            matchesOnline = rowOnline === onlineFilter;
        }

        var matches = matchesSearch && matchesStatus && matchesChain && matchesOnline;

        if (matches) {
            card.classList.remove('filter-hidden');
            visibleCount++;
        } else {
            card.classList.add('filter-hidden');
        }
    }

    // Show the "X contacts found" status whenever any filter is active
    if (searchStatus && searchCount) {
        if (hasActiveFilter) {
            searchStatus.style.display = 'block';
            searchCount.textContent = visibleCount;
        } else {
            searchStatus.style.display = 'none';
        }
    }

    // Re-cut paginator pages against the newly filtered row set.
    var contactsPaginator = Paginator.get('contacts');
    if (contactsPaginator) contactsPaginator.apply();
}

/**
 * Contact sort state — held in module scope so repeated header clicks
 * cycle through asc → desc → clear → asc on the same column.
 * `originalOrder` is captured the first time a sort runs so we can restore
 * the default alphabetical layout when the user clears the sort.
 */
var contactsSortState = { column: null, direction: null, originalOrder: null };

/**
 * Sort the contacts table by a numeric column. Click cycle on the same
 * column: asc → desc → clear (back to default order).
 *
 * @param {string} column - 'balance', 'your-credit', or 'their-credit'
 */
function sortContacts(column) {
    var tbody = document.getElementById('contacts-grid');
    if (!tbody) return;

    // Snapshot the original DOM order on first sort so we can restore it later
    if (!contactsSortState.originalOrder) {
        contactsSortState.originalOrder = [];
        var initial = tbody.querySelectorAll('.contact-card');
        for (var k = 0; k < initial.length; k++) {
            contactsSortState.originalOrder.push(initial[k]);
        }
    }

    // Determine new direction based on current state
    var newDirection;
    if (contactsSortState.column !== column) {
        newDirection = 'asc';
    } else if (contactsSortState.direction === 'asc') {
        newDirection = 'desc';
    } else if (contactsSortState.direction === 'desc') {
        newDirection = null; // clear → restore default
    } else {
        newDirection = 'asc';
    }

    if (!newDirection) {
        // Restore default alphabetical order
        for (var i = 0; i < contactsSortState.originalOrder.length; i++) {
            tbody.appendChild(contactsSortState.originalOrder[i]);
        }
        contactsSortState.column = null;
        contactsSortState.direction = null;
    } else {
        var attr = 'data-' + column;
        var rows = [];
        var current = tbody.querySelectorAll('.contact-card');
        for (var j = 0; j < current.length; j++) rows.push(current[j]);

        rows.sort(function (a, b) {
            var av = parseFloat(a.getAttribute(attr));
            var bv = parseFloat(b.getAttribute(attr));
            // Missing / NaN values always sort to the bottom regardless of direction
            var aMissing = isNaN(av);
            var bMissing = isNaN(bv);
            if (aMissing && bMissing) return 0;
            if (aMissing) return 1;
            if (bMissing) return -1;
            return newDirection === 'asc' ? av - bv : bv - av;
        });

        for (var m = 0; m < rows.length; m++) tbody.appendChild(rows[m]);
        contactsSortState.column = column;
        contactsSortState.direction = newDirection;
    }

    updateSortIndicators();
    var contactsPaginator = Paginator.get('contacts');
    if (contactsPaginator) contactsPaginator.apply();
}

/**
 * Update the visual state of the sort arrow icons in the contacts table
 * header to reflect the current contactsSortState.
 */
function updateSortIndicators() {
    var headers = document.querySelectorAll('.contacts-table thead th.sortable');
    for (var i = 0; i < headers.length; i++) {
        var th = headers[i];
        var col = th.getAttribute('data-sort-column');
        var icon = th.querySelector('.sort-indicator');
        if (!icon) continue;

        // Reset to base classes
        icon.className = 'fas sort-indicator';
        th.classList.remove('sort-asc', 'sort-desc');

        if (col === contactsSortState.column) {
            if (contactsSortState.direction === 'asc') {
                icon.classList.add('fa-sort-up');
                th.classList.add('sort-asc');
            } else if (contactsSortState.direction === 'desc') {
                icon.classList.add('fa-sort-down');
                th.classList.add('sort-desc');
            } else {
                icon.classList.add('fa-sort');
            }
        } else {
            icon.classList.add('fa-sort');
        }
    }
}

/**
 * Mark contacts-table number cells whose content overflows the column.
 * Truncated cells get a dotted underline and a click handler that opens the
 * existing info modal showing the full value — a touch-friendly alternative
 * to native hover tooltips. Non-truncated cells stay actionless so the click
 * bubbles up to the row's openContactModal handler.
 */
function markTruncatedContactNumberCells() {
    // Scope to the Your Contacts table only — the tx-table reuses the same
    // contacts-table chrome but its rows have their own click-to-open-modal
    // handler that we don't want stolen by an injected showInfoModal action.
    var cells = document.querySelectorAll('.contacts-table:not(.tx-table) .col-number');
    for (var i = 0; i < cells.length; i++) {
        var cell = cells[i];
        // Skip cells in hidden ancestors (clientWidth is 0)
        if (cell.clientWidth === 0) continue;
        if (cell.scrollWidth > cell.clientWidth + 1) {
            cell.classList.add('truncated');
            cell.setAttribute('data-action', 'showInfoModal');
            cell.setAttribute('title', cell.textContent.replace(/\s+/g, ' ').trim());
        } else if (cell.classList.contains('truncated')) {
            cell.classList.remove('truncated');
            cell.removeAttribute('data-action');
            cell.removeAttribute('title');
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    markTruncatedContactNumberCells();
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(markTruncatedContactNumberCells, 150);
    });
});

/**
 * Filter the main transaction list by name, address, or description.
 * Client-side filter — works on the already-rendered transaction items.
 */
function filterTransactions() {
    var searchInput = document.getElementById('tx-search-input');
    var searchStatus = document.getElementById('tx-search-status');
    var searchCount = document.getElementById('tx-search-count');
    var dirSelect = document.getElementById('tx-filter-direction');
    var typeSelect = document.getElementById('tx-filter-type');
    var statusSelect = document.getElementById('tx-filter-status');

    if (!searchInput) return;

    var term = searchInput.value.toLowerCase().trim();
    var dirFilter = dirSelect ? dirSelect.value : '';
    var typeFilter = typeSelect ? typeSelect.value : '';
    var statusFilter = statusSelect ? statusSelect.value : '';
    var items = document.querySelectorAll('#transaction-list .tx-row');
    var visible = 0;
    var anyFilterActive = (term !== '' || dirFilter !== '' || typeFilter !== '' || statusFilter !== '');

    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var matches = true;

        if (term !== '') {
            var name = item.getAttribute('data-tx-name') || '';
            var desc = item.getAttribute('data-tx-desc') || '';
            var addr = item.getAttribute('data-tx-address') || '';
            // P2P endpoint — matches the chain's ultimate counterparty
            // even when the direct neighbour is a relay. Without these,
            // searching "carol" wouldn't match a sent-P2P tx routed
            // through Bob to Carol.
            var endName = item.getAttribute('data-tx-endpoint-name') || '';
            var endAddr = item.getAttribute('data-tx-endpoint-address') || '';
            // txid is lowercased on the attribute for case-insensitive
            // paste-a-hash lookup. Stored on data-txid (not a dedicated
            // search attr) because the row already carries it for the
            // detail-modal / DLQ cross-link paths.
            var txidAttr = (item.getAttribute('data-txid') || '').toLowerCase();
            if (name.indexOf(term) === -1
                && desc.indexOf(term) === -1
                && addr.indexOf(term) === -1
                && endName.indexOf(term) === -1
                && endAddr.indexOf(term) === -1
                && txidAttr.indexOf(term) === -1) {
                matches = false;
            }
        }
        if (matches && dirFilter !== '' && (item.getAttribute('data-direction') || '') !== dirFilter) {
            matches = false;
        }
        if (matches && typeFilter !== '' && (item.getAttribute('data-tx-type') || '') !== typeFilter) {
            matches = false;
        }
        if (matches && statusFilter !== '' && (item.getAttribute('data-status') || '') !== statusFilter) {
            matches = false;
        }

        if (matches) {
            item.classList.remove('filter-hidden');
            visible++;
        } else {
            item.classList.add('filter-hidden');
        }
    }

    if (searchStatus && searchCount) {
        if (anyFilterActive) {
            searchStatus.style.display = 'block';
            searchCount.textContent = visible;
        } else {
            searchStatus.style.display = 'none';
        }
    }

    var txPaginator = Paginator.get('transactions');
    if (txPaginator) txPaginator.apply();
}

// Independent sort state for the Recent Transactions table.
var transactionsSortState = { column: null, direction: null, originalOrder: null };

function sortTransactions(column) {
    var tbody = document.getElementById('transaction-list');
    if (!tbody) return;

    if (!transactionsSortState.originalOrder) {
        transactionsSortState.originalOrder = [];
        var initial = tbody.querySelectorAll('.tx-row');
        for (var k = 0; k < initial.length; k++) {
            transactionsSortState.originalOrder.push(initial[k]);
        }
    }

    var newDirection;
    if (transactionsSortState.column !== column) {
        newDirection = 'asc';
    } else if (transactionsSortState.direction === 'asc') {
        newDirection = 'desc';
    } else if (transactionsSortState.direction === 'desc') {
        newDirection = null;
    } else {
        newDirection = 'asc';
    }

    if (!newDirection) {
        for (var i = 0; i < transactionsSortState.originalOrder.length; i++) {
            tbody.appendChild(transactionsSortState.originalOrder[i]);
        }
        transactionsSortState.column = null;
        transactionsSortState.direction = null;
    } else {
        var attr = 'data-' + column;
        var rows = [];
        var current = tbody.querySelectorAll('.tx-row');
        for (var j = 0; j < current.length; j++) rows.push(current[j]);

        rows.sort(function (a, b) {
            var av = parseFloat(a.getAttribute(attr));
            var bv = parseFloat(b.getAttribute(attr));
            var aMissing = isNaN(av);
            var bMissing = isNaN(bv);
            if (aMissing && bMissing) return 0;
            if (aMissing) return 1;
            if (bMissing) return -1;
            return newDirection === 'asc' ? av - bv : bv - av;
        });

        for (var m = 0; m < rows.length; m++) tbody.appendChild(rows[m]);
        transactionsSortState.column = column;
        transactionsSortState.direction = newDirection;
    }

    updateTransactionsSortIndicators();
    var txPaginator = Paginator.get('transactions');
    if (txPaginator) txPaginator.apply();
}

function updateTransactionsSortIndicators() {
    var headers = document.querySelectorAll('.tx-table thead th.sortable');
    for (var i = 0; i < headers.length; i++) {
        var th = headers[i];
        var col = th.getAttribute('data-sort-column');
        var icon = th.querySelector('.sort-indicator');
        if (!icon) continue;

        icon.className = 'fas sort-indicator';
        th.classList.remove('sort-asc', 'sort-desc');

        if (col === transactionsSortState.column) {
            if (transactionsSortState.direction === 'asc') {
                icon.classList.add('fa-sort-up');
                th.classList.add('sort-asc');
            } else if (transactionsSortState.direction === 'desc') {
                icon.classList.add('fa-sort-down');
                th.classList.add('sort-desc');
            } else {
                icon.classList.add('fa-sort');
            }
        } else {
            icon.classList.add('fa-sort');
        }
    }
}


// =========================================================================
// Payment Requests — sort & filter
// =========================================================================

var prSortState = { column: null, direction: null, originalOrder: null };

function sortPaymentRequests(column) {
    var tbody = document.getElementById('pr-history-list');
    if (!tbody) return;

    if (!prSortState.originalOrder) {
        prSortState.originalOrder = [];
        var initial = tbody.querySelectorAll('.pr-row');
        for (var k = 0; k < initial.length; k++) {
            prSortState.originalOrder.push(initial[k]);
        }
    }

    var newDirection;
    if (prSortState.column !== column) {
        newDirection = 'asc';
    } else if (prSortState.direction === 'asc') {
        newDirection = 'desc';
    } else if (prSortState.direction === 'desc') {
        newDirection = null;
    } else {
        newDirection = 'asc';
    }

    if (!newDirection) {
        for (var i = 0; i < prSortState.originalOrder.length; i++) {
            tbody.appendChild(prSortState.originalOrder[i]);
        }
        prSortState.column = null;
        prSortState.direction = null;
    } else {
        var attr = 'data-' + column;
        var rows = [];
        var current = tbody.querySelectorAll('.pr-row');
        for (var j = 0; j < current.length; j++) rows.push(current[j]);

        rows.sort(function (a, b) {
            var av = parseFloat(a.getAttribute(attr));
            var bv = parseFloat(b.getAttribute(attr));
            var aMissing = isNaN(av);
            var bMissing = isNaN(bv);
            if (aMissing && bMissing) return 0;
            if (aMissing) return 1;
            if (bMissing) return -1;
            return newDirection === 'asc' ? av - bv : bv - av;
        });

        for (var m = 0; m < rows.length; m++) tbody.appendChild(rows[m]);
        prSortState.column = column;
        prSortState.direction = newDirection;
    }

    updatePrSortIndicators();
    var prPaginator = Paginator.get('payment-requests');
    if (prPaginator) prPaginator.apply();
}

function updatePrSortIndicators() {
    var headers = document.querySelectorAll('.pr-table thead th.sortable');
    for (var i = 0; i < headers.length; i++) {
        var th = headers[i];
        var col = th.getAttribute('data-sort-column');
        var icon = th.querySelector('.sort-indicator');
        if (!icon) continue;

        icon.className = 'fas sort-indicator';
        th.classList.remove('sort-asc', 'sort-desc');

        if (col === prSortState.column) {
            if (prSortState.direction === 'asc') {
                icon.classList.add('fa-sort-up');
                th.classList.add('sort-asc');
            } else if (prSortState.direction === 'desc') {
                icon.classList.add('fa-sort-down');
                th.classList.add('sort-desc');
            } else {
                icon.classList.add('fa-sort');
            }
        } else {
            icon.classList.add('fa-sort');
        }
    }
}

function filterPaymentRequests() {
    var searchInput = document.getElementById('pr-search-input');
    var statusSelect = document.getElementById('pr-filter-status');
    var dirSelect = document.getElementById('pr-filter-direction');
    var term = searchInput ? searchInput.value.toLowerCase().trim() : '';
    var statusFilter = statusSelect ? statusSelect.value : '';
    var dirFilter = dirSelect ? dirSelect.value : '';
    var rows = document.querySelectorAll('#pr-history-list .pr-row');

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var show = true;
        if (term && (row.getAttribute('data-pr-search') || '').indexOf(term) === -1) show = false;
        if (show && statusFilter && (row.getAttribute('data-status') || '') !== statusFilter) show = false;
        if (show && dirFilter && (row.getAttribute('data-direction') || '') !== dirFilter) show = false;
        if (show) {
            row.classList.remove('filter-hidden');
        } else {
            row.classList.add('filter-hidden');
        }
    }

    var prPaginator = Paginator.get('payment-requests');
    if (prPaginator) prPaginator.apply();
}

// ============================================================================
// "Search entire database" — server-side search for Recent Transactions and
// Payment Requests history. The local filter functions above only inspect
// already-rendered rows; these functions bypass them and ask the backend to
// LIKE across the full table. Results replace the tbody contents and switch
// the paginator into search mode (load-older suspended, banner visible).
// ============================================================================

function searchTransactionsDatabase() {
    runDatabaseSearch({
        action: 'searchTransactions',
        paginatorKey: 'transactions',
        inputId: 'tx-search-input',
        filters: {
            direction: 'tx-filter-direction',
            tx_type:   'tx-filter-type',
            status:    'tx-filter-status'
        },
        tbodyId: 'transaction-list',
        bannerId: 'tx-search-results-banner',
        bannerTextId: 'tx-search-results-text',
        loadMoreLabel: 'Load older',
        updateTransactionData: true
    });
}

function searchPaymentRequestsDatabase() {
    runDatabaseSearch({
        action: 'searchPaymentRequests',
        paginatorKey: 'payment-requests',
        inputId: 'pr-search-input',
        filters: {
            direction: 'pr-filter-direction',
            status:    'pr-filter-status'
        },
        tbodyId: 'pr-history-list',
        bannerId: 'pr-search-results-banner',
        bannerTextId: 'pr-search-results-text',
        loadMoreLabel: 'Load older',
        updateTransactionData: false
    });
}

/**
 * Shared server-side search runner. Reads the search term + filter dims from
 * the specified inputs, POSTs to the GUI action router, replaces the
 * paginator's tbody content with the returned HTML rows, and pops a banner
 * summarising the result count. The paginator is moved back to page 0 and
 * its load-older button is suspended so it doesn't compete with the search
 * result set.
 */
function runDatabaseSearch(cfg) {
    var inputEl = document.getElementById(cfg.inputId);
    if (!inputEl) return;
    var term = (inputEl.value || '').trim();
    if (term === '') {
        showToast('Search', 'Enter a search term first.', 'info');
        return;
    }

    var tbody = document.getElementById(cfg.tbodyId);
    var banner = document.getElementById(cfg.bannerId);
    var bannerText = document.getElementById(cfg.bannerTextId);
    var csrfTokenEl = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

    if (banner && bannerText) {
        banner.classList.remove('d-none');
        bannerText.textContent = 'Searching the database for “' + term + '”…';
    }

    var formData = new FormData();
    formData.append('action', cfg.action);
    formData.append('q', term);
    formData.append('csrf_token', csrfToken);
    Object.keys(cfg.filters || {}).forEach(function(key) {
        var filterEl = document.getElementById(cfg.filters[key]);
        if (filterEl) formData.append(key, filterEl.value || '');
    });

    fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    }).then(function(res) {
        return res.json();
    }).then(function(data) {
        if (!data || !data.success) {
            var msg = (data && data.message) || 'Search failed.';
            if (banner && bannerText) {
                bannerText.textContent = 'Search failed: ' + msg;
            } else {
                showToast('Search', msg, 'error');
            }
            return;
        }

        if (tbody) {
            tbody.innerHTML = data.html || '';
        }

        // For the transactions table, replace the page-level transactionData
        // array so openTransactionModal(index) on an appended row resolves
        // correctly against the search result set.
        if (cfg.updateTransactionData && Array.isArray(data.rows) && typeof transactionData !== 'undefined') {
            transactionData.length = 0;
            for (var i = 0; i < data.rows.length; i++) {
                transactionData.push(data.rows[i]);
            }
        }

        // Banner copy — "N matches for 'term'" + cap disclosure if
        // the server clipped the result set so the user knows there
        // might be more beyond what's shown.
        var total = Number(data.total || 0);
        var capNote = data.capped ? ' (capped at ' + (data.cap || 500) + ' — refine the term to narrow further)' : '';
        if (bannerText) {
            if (total === 0) {
                bannerText.textContent = 'No matches for “' + term + '” in the database.';
            } else {
                bannerText.textContent = total + ' match' + (total === 1 ? '' : 'es') + ' for “' + term + '”' + capNote;
            }
        }

        // Reset paginator + suspend load-older — the result set is
        // bounded by the server cap, so there's nothing older to fetch.
        var inst = Paginator.get(cfg.paginatorKey);
        if (inst) {
            inst.state.page = 0;
            inst.setLoadMoreExhausted(true);
            inst.apply();
        }
        // Rewrite the "Showing the last N …" copy to reflect the search
        // result count — e.g. "last 100 transactions" becomes "last 12
        // transactions" while a search is active, then reverts on reload
        // (clear-search triggers a page reload which restores the
        // server-side count).
        refreshMetaLoadedCount(cfg.paginatorKey, Number(data.total || 0));

        // Re-run the local filter so the "X transactions found" counter
        // next to the search input reflects the replaced row set.
        // Otherwise it shows a stale count from the last live-typed
        // keystroke, which doesn't know the DB search ran.
        if (cfg.paginatorKey === 'transactions' && typeof filterTransactions === 'function') {
            filterTransactions();
        } else if (cfg.paginatorKey === 'payment-requests' && typeof filterPaymentRequests === 'function') {
            filterPaymentRequests();
        }
    }).catch(function(err) {
        if (banner && bannerText) {
            bannerText.textContent = 'Network error while searching the database.';
        } else {
            showToast('Search', 'Network error while searching.', 'error');
        }
    });
}

/**
 * Map of paginator key → DOM id of the "Showing the last N …" counter
 * span. Kept here (rather than on each Paginator instance) so the
 * lookup works from both the load-older path (appends rows) and the
 * search-database path (replaces rows), without having to plumb an
 * extra field through the Paginator config.
 */
var META_LOADED_COUNT_IDS = {
    'transactions':     'tx-meta-loaded-count',
    'payment-requests': 'pr-meta-loaded-count'
};

/**
 * Rewrite the "Showing the last N …" count span for the given paginator
 * to reflect the current DOM row count. Safe to call from either the
 * load-older success path (which appended rows) or the search-database
 * path (which replaced them). Accepts an optional explicit override so
 * the search path can say "N matches" rather than re-counting rows
 * after a filter/paginator reshuffle.
 *
 * @param {string} paginatorKey
 * @param {number|null} [override]
 */
function refreshMetaLoadedCount(paginatorKey, override) {
    var spanId = META_LOADED_COUNT_IDS[paginatorKey];
    if (!spanId) return;
    var span = document.getElementById(spanId);
    if (!span) return;
    if (typeof override === 'number') {
        span.textContent = String(override);
        return;
    }
    var inst = Paginator.get(paginatorKey);
    if (!inst) return;
    span.textContent = String(inst.getLoadedCount());
}

/**
 * Clear an active server-side search and return the table to the default
 * view. Simplest and most robust approach: reload the page. A more
 * elaborate "restore original rows from memory" variant is possible but
 * adds non-trivial state management for a rare user action.
 */
function clearSearchResults(_key) {
    // Drop any transient search-mode URL fragments before reload so the
    // user lands cleanly on the default view.
    var target = window.location.pathname + window.location.search;
    window.location.href = target;
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
        '<div class="modal-content" style="max-width:440px">' +
            '<div class="modal-header">' +
                '<h3 style="font-size:1rem"><i class="fas fa-info-circle" style="color:#6c757d"></i> Info</h3>' +
                '<span class="close" id="info-modal-close" title="Close">&times;</span>' +
            '</div>' +
            // overflow-wrap:anywhere + word-break:break-word lets long
            // unbreakable strings (Tor onions, pubkey hashes, URLs without
            // spaces) wrap at any character rather than walking off the
            // right edge. white-space:pre-line preserves intentional
            // newlines in multi-line tooltips.
            '<div class="modal-body" style="padding:1.25rem;font-size:0.9rem;line-height:1.5;white-space:pre-line;overflow-wrap:anywhere;word-break:break-word">' +
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

    // Set contact name in header
    document.getElementById('modal_contact_name').textContent = contact.name || 'Unknown';

    // Propagate contact name onto the QR toggle button so the exported PNG
    // filename can include who it belongs to (see exportAddressQr).
    var contactQrBtn = document.querySelector('[data-qr-target="contact-address-qr-display"]');
    if (contactQrBtn) {
        contactQrBtn.setAttribute('data-qr-name', contact.name || '');
    }

    // Set addresses dropdown
    var addressSelector = document.getElementById('modal_address_selector');
    var addressDisplay = document.getElementById('modal_address_display');
    addressSelector.innerHTML = '';

    // Address dropdown — iterate whatever transport columns the schema
    // currently exposes (EIOU_ADDRESS_TYPE_DISPLAY is bootstrapped from
    // AddressRepository::getAllAddressTypes + Constants::ADDRESS_TYPE_DISPLAY
    // on page render). A new transport column added to the addresses table
    // picks up whatever display metadata the Constants registry has, or
    // falls back to an UPPERCASE(type) label + fa-question icon.
    var addresses = [];
    if (typeof EIOU_ADDRESS_TYPE_DISPLAY !== 'undefined' && EIOU_ADDRESS_TYPE_DISPLAY) {
        for (var _t in EIOU_ADDRESS_TYPE_DISPLAY) {
            if (!Object.prototype.hasOwnProperty.call(EIOU_ADDRESS_TYPE_DISPLAY, _t)) continue;
            if (!contact[_t]) continue;
            var _meta = EIOU_ADDRESS_TYPE_DISPLAY[_t];
            addresses.push({
                type: _meta.label || _t.toUpperCase(),
                address: contact[_t],
                icon: _meta.icon || 'fa-question',
            });
        }
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

        // Make clickable to scroll to tx drop section
        if (isClickable) {
            chainStatusEl.onclick = function() {
                showModalTab('status-tab', null);
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

    // Show tx drop section if chain is invalid
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
        var chainDropEmpty = document.getElementById('chain_drop_empty');
        currentChainDropProposalId = null;

        // Section is always visible; which sub-state shows depends on the
        // chain/proposal state. Default is the empty placeholder.
        chainDropSection.style.display = 'block';
        var showEmpty = true;

        if (contact.chain_drop_proposal) {
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
                showEmpty = false;
            } else if (proposal.direction === 'outgoing' && proposal.status === 'pending') {
                if (chainDropAwaiting) {
                    chainDropAwaiting.style.display = 'block';
                    var awaitingIdEl = document.getElementById('chain_drop_awaiting_id');
                    if (awaitingIdEl) {
                        awaitingIdEl.textContent = 'Proposal: ' + proposal.proposal_id;
                    }
                }
                showEmpty = false;
            } else if (proposal.status === 'rejected') {
                if (chainDropRejected) chainDropRejected.style.display = 'block';
                showEmpty = false;
            }
        } else if (contact.valid_chain === false || contact.valid_chain === 0) {
            // Chain is invalid but no proposal exists yet — show propose button
            if (chainDropPropose) chainDropPropose.style.display = 'block';
            showEmpty = false;
        }
        if (chainDropEmpty) {
            chainDropEmpty.style.display = showEmpty ? 'block' : 'none';
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

    // Store current contact pubkey hash for tx drop
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
        var statusIconMap = {
            'pending':   'fa-hourglass-half',
            'sending':   'fa-paper-plane',
            'sent':      'fa-check',
            'accepted':  'fa-check',
            'completed': 'fa-check-double',
            'rejected':  'fa-times',
            'cancelled': 'fa-ban'
        };
        var html = '<table class="contacts-table tx-table contact-modal-tx-table">';
        html += '<thead><tr>'
             +  '<th class="col-tx-status-icon" aria-label="Status"></th>'
             +  '<th class="col-tx-desc">Description</th>'
             +  '<th class="col-tx-amount text-right">Amount</th>'
             +  '</tr></thead>';
        html += '<tbody>';
        for (var i = 0; i < transactions.length; i++) {
            var tx = transactions[i];
            var isSent = tx.type === 'sent';
            var isContactReq = tx.tx_type === 'contact';
            var amountClass = isContactReq ? 'text-muted' : (isSent ? 'transaction-sent' : 'transaction-received');
            var amountPrefix = isSent ? '−' : '+';
            var status = (tx.status || '').toLowerCase();
            var inProgress = (status !== 'completed' && status !== 'rejected' && status !== 'cancelled');
            var statusIcon = statusIconMap[status] || 'fa-circle';
            var statusTitle = status ? (status.charAt(0).toUpperCase() + status.slice(1)) : 'Unknown';
            var description = tx.description || '';
            var rowClass = 'tx-row cursor-pointer' + (inProgress ? ' tx-item-in-progress' : '');

            // Open the main #transactionModal as a stacked overlay on top of
            // the contact modal. Inherits the `modal-stack-top` pattern used
            // by every other "drill into tx" path so click-outside + Escape
            // dismiss only the tx modal and return the user here.
            html += '<tr class="' + rowClass + '" data-action="openTransactionModalByTxid" data-txid="' + escapeHtml(tx.txid || '') + '" title="' + escapeHtml(tx.date || '') + ' — click for details">';
            html += '<td class="col-tx-status-icon text-center">';
            html += '<span class="tx-status-icon tx-status-' + escapeHtml(status) + '" title="' + escapeHtml(statusTitle) + '">';
            html += '<i class="fas ' + statusIcon + '"></i>';
            html += '</span>';
            html += '</td>';
            var displayDesc = displayTxDescription(description);
            html += '<td class="col-tx-desc" title="' + escapeHtml(displayDesc || 'No description') + '">';
            html += escapeHtml(displayDesc || '—');
            html += '</td>';
            html += '<td class="col-number col-tx-amount text-right ' + amountClass + '">';
            if (isContactReq) {
                // Mobile shows the label instead of the em-dash (see
                // `.tx-amount-contact-label` comment in page.css for why).
                html += '<span class="tx-amount-contact-label">Contact request</span>';
                html += '<span class="tx-amount-mdash">&mdash;</span>';
            } else {
                html += amountPrefix + parseFloat(tx.amount).toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + escapeHtml(tx.currency || 'USD');
            }
            html += '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        transactionsEl.innerHTML = html;
    }

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
    var el = document.getElementById('contactModal');
    if (el) {
        el.style.display = 'none';
        // Clear the stack marker set by jumpToContactFromTxModal so the next
        // time the contact modal opens standalone it isn't stuck on top.
        el.classList.remove('modal-stack-top');
    }
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
                        // Show success message briefly before reloading
                        var onlineStatus = response.online_status || 'unknown';
                        if (resultMsg) {
                            resultMsg.textContent = response.message || 'Status updated, reloading...';
                            resultMsg.style.color = onlineStatus === 'online' ? '#28a745' : (onlineStatus === 'partial' ? '#fd7e14' : '#dc3545');
                        }
                        // Delay reload so the user sees the result before the page refreshes
                        setTimeout(function() {
                            if (currentContactId) {
                                var storedId = safeStorageSet('eiou_reopen_contact_id', currentContactId);
                                var storedTab = safeStorageSet('eiou_reopen_contact_tab', 'status-tab');
                                if (storedId && storedTab) {
                                    window.location.reload();
                                } else {
                                    var currentUrl = window.location.href.split('#')[0];
                                    window.location.href = currentUrl + '#reopen_contact=' + encodeURIComponent(currentContactId) + '&tab=status';
                                    window.location.reload();
                                }
                            } else {
                                window.location.reload();
                            }
                        }, 1500);
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
                    // Non-JSON response — likely a rate limit redirect page
                    var fallbackMsg = 'Invalid response';
                    if (xhr.responseText) {
                        var waitMatch = xhr.responseText.match(/wait\s+(\d+)\s+seconds/i);
                        if (waitMatch) {
                            fallbackMsg = 'Too many requests. Please wait ' + waitMatch[1] + ' seconds.';
                        }
                    }
                    if (resultMsg) {
                        resultMsg.textContent = fallbackMsg;
                        resultMsg.style.color = '#dc3545';
                    }
                }
            } else if (xhr.status !== 0) {
                resetPingButton();
                // Try to extract rate limit or error message from response
                // 403 = session/CSRF expired — just reload and reopen modal
                if (xhr.status === 403 && currentContactId) {
                    var storedId = safeStorageSet('eiou_reopen_contact_id', currentContactId);
                    var storedTab = safeStorageSet('eiou_reopen_contact_tab', 'status-tab');
                    if (storedId && storedTab) {
                        window.location.reload();
                    } else {
                        var currentUrl = window.location.href.split('#')[0];
                        window.location.href = currentUrl + '#reopen_contact=' + encodeURIComponent(currentContactId) + '&tab=status';
                        window.location.reload();
                    }
                    return;
                }
                var errorMessage = 'Request failed (status ' + xhr.status + ')';
                if (xhr.responseText) {
                    // Check for redirect page with flash message (rate limit)
                    var waitMatch = xhr.responseText.match(/wait\s+(\d+)\s+seconds/i);
                    if (waitMatch) {
                        errorMessage = 'Too many requests. Please wait ' + waitMatch[1] + ' seconds.';
                    } else if (xhr.responseText.charAt(0) === '{') {
                        // Try JSON parse only if it looks like JSON
                        try {
                            var errResp = JSON.parse(xhr.responseText);
                            if (errResp.message) errorMessage = errResp.message;
                            else if (errResp.error) errorMessage = errResp.error;
                        } catch (e2) {}
                    }
                }
                if (resultMsg) {
                    resultMsg.textContent = errorMessage;
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

    // Activate the clicked button or find matching button. The fallback
    // lookup uses data-tab (the current markup); the old onclick= attribute
    // lookup was a dead path since the tab buttons moved to data-action
    // dispatch — that's why the Status tab lost its blue underline after
    // the "Check Status" reload path called showModalTab(tabId, null).
    if (button) {
        button.classList.add('active');
    } else {
        var matchTab = tabId.replace(/-tab$/, '');
        var tabButtonsAll = document.querySelectorAll('.modal-tab');
        for (var k = 0; k < tabButtonsAll.length; k++) {
            var btn = tabButtonsAll[k];
            var dataTab = btn.getAttribute('data-tab') || '';
            // data-tab stores either "status-tab" or just "status" in older
            // markup — accept both.
            if (dataTab === tabId || dataTab === matchTab) {
                btn.classList.add('active');
                break;
            }
        }
    }
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
    // Regenerate QR if it was visible when address changed
    var qrContainer = document.getElementById('contact-address-qr-display');
    if (qrContainer && qrContainer.style.display !== 'none' && qrContainer.innerHTML) {
        var svg = generateQrSvg(address, 200);
        qrContainer.innerHTML = svg || '<p style="color:#6c757d;font-size:0.85rem">QR code library not available</p>';
    }
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

    initPaginators();
});

/**
 * Attach Paginator instances to every paginated table on the current page.
 * Re-runs safely if the tables aren't rendered (short-circuits on null
 * tbody), so it's fine to call from the main DOMContentLoaded handler
 * regardless of which server-rendered tab is active at load time.
 */
function initPaginators() {
    var txBody = document.getElementById('transaction-list');
    var txContainer = document.getElementById('tx-paginator');
    if (txBody && txContainer) {
        Paginator.create({
            key: 'transactions',
            tbody: txBody,
            rowSelector: '.tx-row',
            container: txContainer,
            loadMore: { onClick: loadMoreTransactions }
        });
    }

    // Contacts — Phase 1 (client slicing) AND Phase 2 (load-older). Only
    // accepted contacts paginate via Phase 2; pending + blocked are always
    // rendered up-front because they're small and operationally important.
    var contactsBody = document.getElementById('contacts-grid');
    var contactsContainer = document.getElementById('contacts-paginator');
    if (contactsBody && contactsContainer) {
        Paginator.create({
            key: 'contacts',
            tbody: contactsBody,
            rowSelector: '.contact-card',
            container: contactsContainer,
            // Contacts are sorted alphabetically (name ASC), not
            // chronologically, so "Load older" would be wrong here —
            // use "Load more" for the name-ordered view.
            loadMore: { onClick: loadMoreContacts, label: 'Load more' }
        });
    }

    var prBody = document.getElementById('pr-history-list');
    var prContainer = document.getElementById('pr-paginator');
    if (prBody && prContainer) {
        Paginator.create({
            key: 'payment-requests',
            tbody: prBody,
            rowSelector: '.pr-row',
            container: prContainer,
            loadMore: { onClick: loadMorePaymentRequests }
        });
    }
}

/**
 * Phase-2 Load-older callback for Recent Transactions. Fetches the next
 * chunk via loadMoreTransactions AJAX action, appends the returned row
 * HTML, extends the in-memory transactionData[] so openTransactionModal
 * keeps working for newly appended rows, then asks the paginator to
 * re-cut pages.
 */
function loadMoreTransactions(inst) {
    loadMoreViaGuiAction('loadMoreTransactions', 'transactions', inst);
}

/**
 * Phase-2 Load-older callback for Payment Requests history. Rows are
 * server-rendered via the shared _paymentRequestRow.html partial; the
 * appended HTML carries everything the row needs (data-* attributes for
 * filter/search + click targets) so no in-memory JS array extension is
 * required — unlike Recent Transactions, where transactionData[] has to
 * grow to keep openTransactionModal(index) working.
 */
function loadMorePaymentRequests(inst) {
    loadMoreViaGuiAction('loadMorePaymentRequests', 'payment-requests', inst);
}

/**
 * Phase-2 Load-older callback for the Contacts table. Appends more
 * accepted-contact rows rendered server-side via _contactRow.html. The
 * row's data-contact JSON payload (built by ContactDataBuilder) is what
 * openContactModal consumes, so the appended rows open a fully-
 * populated contact modal without any in-memory JS array extension.
 */
function loadMoreContacts(inst) {
    loadMoreViaGuiAction('loadMoreContacts', 'contacts', inst);
}

/**
 * Shared fetcher for the three "Load older" buttons. Posts to the GUI
 * action router with the current loaded count as the offset, appends the
 * returned HTML fragment to the paginator's tbody, and marks the paginator
 * exhausted when the server reports no more rows available.
 *
 * @param {string} action - GUI action name (e.g. 'loadMoreTransactions')
 * @param {string} key    - Paginator key (for logging / debugging only)
 * @param {Object} inst   - Paginator instance returned by Paginator.create
 */
function loadMoreViaGuiAction(action, key, inst) {
    var offset = inst.getLoadedCount();
    var csrfTokenEl = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

    var formData = new FormData();
    formData.append('action', action);
    formData.append('offset', String(offset));
    formData.append('csrf_token', csrfToken);

    fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    }).then(function(res) {
        return res.json();
    }).then(function(data) {
        if (!data || !data.success) {
            showToast('Load failed', (data && data.error) || 'Could not load more rows.', 'error');
            inst.setLoadMoreBusy(false);
            return;
        }
        if (data.html) {
            inst.appendFragment(data.html);
        }
        // Extend transactionData[] so openTransactionModal(index) keeps
        // resolving for appended rows (their data-index attributes are
        // set server-side from the POSTed offset, so they line up with
        // the position we're about to extend the array to).
        if (Array.isArray(data.rows) && typeof transactionData !== 'undefined') {
            for (var i = 0; i < data.rows.length; i++) {
                transactionData.push(data.rows[i]);
            }
        }
        if (data.exhausted) {
            inst.setLoadMoreExhausted(true);
        }
        inst.setLoadMoreBusy(false);
        inst.apply();
        // Refresh the "Showing the last N" copy so it reflects the
        // newly-loaded row count — "last 100" becomes "last 125" after
        // a Load-older click, matches what the paginator range shows.
        refreshMetaLoadedCount(key);
    }).catch(function() {
        showToast('Network error', 'Could not load more rows.', 'error');
        inst.setLoadMoreBusy(false);
    });
}

// Tx Drop Resolution state
var currentChainDropProposalId = null;

/**
 * Proposes a tx drop to the current contact.
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
    if (btnText) btnText.textContent = 'Propose Tx Drop(s)';
}

/**
 * Accepts an incoming tx drop proposal.
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
 * Rejects an incoming tx drop proposal.
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
 * Used after tx drop propose/accept/reject so all statuses refresh from
 * server data (badges, chain status, notification banner).
 * @returns {void}
 */
function reloadAndReopenContactModal() {
    if (currentContactId) {
        var storedId = safeStorageSet('eiou_reopen_contact_id', currentContactId);
        var storedTab = safeStorageSet('eiou_reopen_contact_tab', 'status-tab');
        if (storedId && storedTab) {
            window.location.reload();
        } else {
            // Tor Browser fallback
            var currentUrl = window.location.href.split('#')[0];
            window.location.href = currentUrl + '#reopen_contact=' + encodeURIComponent(currentContactId) + '&tab=status';
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

    if (!content || !arrow) { return; }

    // A section that ships hidden uses the `d-none` utility class, not
    // an inline `style.display = "none"`. Reading `content.style.display`
    // alone returns "" in that case, so the first click would fall into
    // the "else" branch, set `display = "none"` (already hidden), and
    // *only* the second click would flip into the visible branch.
    // Fix: treat the d-none class as hidden too, and clear it on show.
    var isHidden = content.classList.contains('d-none') || content.style.display === 'none';
    if (isHidden) {
        content.classList.remove('d-none');
        content.style.display = 'block';
        arrow.style.transform = 'rotate(180deg)';
    } else {
        content.style.display = 'none';
        arrow.style.transform = 'rotate(0deg)';
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
        var limitedFilename = filename.replace('.json', '-limited.json');
        downloadDebugFile(jsonData, limitedFilename);
        showToast('Success', 'Limited debug report downloaded: ' + limitedFilename, 'success');
    }, 'limited');
}

/**
 * Submit a debug report directly to the support team via Tor.
 *
 * Generates a full debug report on the server and sends it to the
 * support endpoint (debug-reports.eiou.org) through the Tor proxy.
 * No manual download or email required.
 *
 * @param {string} reportMode - 'full' or 'limited'
 */
function submitDebugReportToSupport(reportMode) {
    reportMode = reportMode || 'full';
    var isFullReport = (reportMode === 'full');

    var descriptionEl = document.getElementById('debugDescription');
    var csrfTokenEl = document.getElementById('debugCsrfToken');
    var description = descriptionEl ? descriptionEl.value : '';
    var csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

    showToast('Sending Report', 'Generating and sending debug report via Tor. This may take a moment...', 'info');

    var formData = new FormData();
    formData.append('action', 'submitDebugReport');
    formData.append('csrf_token', csrfToken);
    formData.append('description', description);
    formData.append('report_mode', reportMode);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = isFullReport ? 300000 : 120000; // 5 min full, 2 min limited
    xhr.ontimeout = function() {
        showToast('Error', 'Report submission timed out. The report may be too large for Tor. Try downloading instead.', 'error');
    };
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var result = JSON.parse(xhr.responseText);
                    if (result.success) {
                        showToast('Report Sent', 'Debug report submitted to support successfully.', 'success');
                    } else {
                        showToast('Error', 'Failed to send report: ' + (result.error || 'Unknown error'), 'error');
                    }
                } catch (e) {
                    showToast('Error', 'Failed to parse response: ' + e.message, 'error');
                }
            } else {
                showToast('Error', 'Failed to submit report. Status: ' + xhr.status, 'error');
            }
        }
    };
    xhr.onerror = function() {
        showToast('Error', 'Network error while submitting report.', 'error');
    };
    xhr.send(formData);
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
    var address = el.getAttribute('data-qr-text');
    if (!address) {
        var sourceId = el.getAttribute('data-qr-source');
        if (sourceId) {
            var sourceEl = document.getElementById(sourceId);
            address = sourceEl ? sourceEl.textContent.trim() : '';
        }
    }
    if (!address) return;

    // Build QR payload: typed JSON envelope for add-contact
    var displayName = el.getAttribute('data-qr-name') || '';
    var qrPayload = { type: 'contact', address: address };
    if (displayName) {
        qrPayload.name = displayName;
    }
    var qrText = JSON.stringify(qrPayload);

    var svg = generateQrSvg(qrText, 200);
    if (!svg) {
        container.innerHTML = '<p style="color:#6c757d;font-size:0.85rem">QR code library not available</p>';
    } else {
        var safeAddr = escapeHtml(address);
        var safeName = escapeHtml(displayName);
        container.innerHTML = svg +
            '<div class="qr-action-row">' +
                '<button class="btn btn-sm btn-outline" data-action="exportAddressQr" data-qr-container="' + escapeHtml(targetId) + '" data-qr-address="' + safeAddr + '" data-qr-name="' + safeName + '" title="Download this QR code as a PNG image">' +
                    '<i class="fas fa-download"></i> Export QR' +
                '</button>' +
                '<button class="btn btn-sm btn-outline" data-action="scanContactQr" title="Scan a contact\'s QR code to add them">' +
                    '<i class="fas fa-camera"></i> Scan QR' +
                '</button>' +
            '</div>';
    }
    container.style.display = 'block';
}

/**
 * Export the QR code inside the given container as a downloadable PNG.
 * The QR is stored as an <svg> element (see generateQrSvg above). We
 * serialise it to a blob, render it to a canvas at higher resolution for
 * print/share quality, and trigger a browser download.
 */
function exportAddressQr(containerId, address, name) {
    var container = document.getElementById(containerId);
    if (!container) return;
    var svgEl = container.querySelector('svg');
    if (!svgEl) {
        if (typeof showToast === 'function') {
            showToast('Error', 'No QR code to export', 'error');
        }
        return;
    }

    var svgString = new XMLSerializer().serializeToString(svgEl);
    var svgBlob = new Blob([svgString], { type: 'image/svg+xml;charset=utf-8' });
    var svgUrl = URL.createObjectURL(svgBlob);

    var img = new Image();
    img.onload = function() {
        var exportSize = 600; // 3x the 200px on-screen size for crisp printing
        var canvas = document.createElement('canvas');
        canvas.width = exportSize;
        canvas.height = exportSize;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, exportSize, exportSize);
        ctx.drawImage(img, 0, 0, exportSize, exportSize);
        URL.revokeObjectURL(svgUrl);

        canvas.toBlob(function(pngBlob) {
            if (!pngBlob) {
                if (typeof showToast === 'function') {
                    showToast('Error', 'Could not generate PNG', 'error');
                }
                return;
            }
            var pngUrl = URL.createObjectURL(pngBlob);
            var a = document.createElement('a');
            a.href = pngUrl;
            // Filename: eiou-qr-<name>-<transport>.png — encodes the display
            // name (so the user can tell their own QR from a contact's) plus
            // the transport type (http / https / onion). The raw address is
            // never embedded, so the filename can be shared without leaking
            // the specific host/port/onion-hash. Falls back to just the
            // transport when no name is available.
            var transport = 'contact';
            if (address) {
                var addrLower = address.toLowerCase();
                if (addrLower.indexOf('https://') === 0) transport = 'https';
                else if (addrLower.indexOf('http://') === 0) transport = 'http';
                else if (addrLower.indexOf('.onion') !== -1) transport = 'onion';
            }
            var nameSlug = (name || '').trim()
                .replace(/[^a-zA-Z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 32);
            a.download = 'eiou-qr-' + (nameSlug ? nameSlug + '-' : '') + transport + '.png';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(pngUrl);
        }, 'image/png');
    };
    img.onerror = function() {
        URL.revokeObjectURL(svgUrl);
        if (typeof showToast === 'function') {
            showToast('Error', 'Could not render QR for export', 'error');
        }
    };
    img.src = svgUrl;
}

/**
 * Open the QR code scanner modal.
 * Uses html5-qrcode for camera scanning on regular browsers.
 * Tor Browser is detected and blocked early (camera and canvas both fail).
 */

/**
 * Detect Tor Browser fingerprinting protection (canvas data modified).
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

/**
 * Parse eIOU QR code data. Returns a typed object so callers can
 * distinguish between contact, payment request, or unknown payloads.
 *
 * Supported types:
 *   "contact"  — { type, address, name? }
 *   "payment"  — { type, address, amount?, currency?, description? }  (future)
 *
 * Legacy / plain-text QR codes are treated as type "contact" with
 * address only (backward compatible).
 *
 * @param {string} text - Raw QR code text
 * @returns {{ type: string, address: string, [key: string]: * }}
 */
function parseQrData(text) {
    try {
        var data = JSON.parse(text);
        if (data && typeof data.type === 'string' && typeof data.address === 'string') {
            return data;
        }
        // Legacy JSON without type — assume contact
        if (data && typeof data.address === 'string') {
            data.type = 'contact';
            return data;
        }
    } catch (e) { /* not JSON — treat as plain address */ }
    return { type: 'contact', address: text };
}

// QR scanner — uses html5-qrcode (vendored at vendor/html5-qrcode.min.js).
//
// Tor Browser is handled by the isCanvasBlocked() early-exit below: it
// blocks both camera and canvas APIs for fingerprinting protection, so any
// in-browser QR library will fail. We tell the user to copy/paste instead.
//
// iOS Safari notes for future maintainers — if iOS users still report
// "QR scanner doesn't work" after the layered fixes below:
//   1. The plain-string `{ facingMode: 'environment' }` constraint plus the
//      getCameras() fallback (Layer 2 below) should handle most iOS quirks
//      within html5-qrcode. Check console.warn output from showCameraError()
//      to find the exact err.name reported by the user. Do NOT switch back
//      to `{ facingMode: { ideal: 'environment' } }` — html5-qrcode v2.3.8
//      does not accept the `ideal` form and throws synchronously before
//      getUserMedia is called, breaking the scanner for every user.
//   2. html5-qrcode v2.3.8 is the last release (April 2023) — the library
//      is essentially unmaintained. There is no upgrade path within it.
//      That said, Snyk reports zero known vulnerabilities for 2.3.8 as of
//      2026-04, and the library is vendored (vendor/html5-qrcode.min.js)
//      and loaded under a nonce-based CSP, so supply-chain surface is nil
//      until someone re-vendors. "Unmaintained" is a latent concern, not
//      an active security risk.
//   3. Do NOT swap to @zxing/browser as a drop-in "maintained" replacement
//      — that premise is wrong as of 2026-04. @zxing/browser is still at
//      0.1.5, last published ~2 years ago, and is effectively just as
//      unmaintained as html5-qrcode. It also ships a lower-level API with
//      no built-in modal/viewfinder, so switching would mean rewriting the
//      overlay in openQrScanner() AND adding a bundle step (ESM-only
//      distribution), for zero proven security upside. Only revisit if a
//      maintained fork emerges.
//   4. If (1)+(2) prove insufficient, the right next step is the native
//      BarcodeDetector API with html5-qrcode as a fallback. Supported in
//      iOS Safari 17+, Chrome, Edge. NOT supported in Firefox. Pattern:
//      feature-detect first, use BarcodeDetector if available, fall back
//      to html5-qrcode for Firefox and older iOS. Roughly 50–80 lines
//      additional, no new dependency. Uses the most modern API exactly
//      where iOS reports matter, smallest blast radius.
//      Reminder: none of these options help Tor Browser, which blocks
//      camera and canvas APIs outright — the isCanvasBlocked() guard
//      above handles that case regardless of which library is used.
//      NOT the same as the previous "PHP ZXing" attempt removed in
//      commit 3e804e11 (that was a server-side wrapper around the Java
//      ZXing CLI, removed because Tor uploads were too slow) — in-browser
//      decoding is a different approach entirely.
function openQrScanner(targetInputId, opts) {
    opts = opts || {};

    // Tor Browser blocks camera API and canvas — QR scanning cannot work
    if (isCanvasBlocked()) {
        showToast('QR Scanner Unavailable',
            'Your browser blocks camera and image processing for privacy (Tor Browser). Please copy and paste the address manually.',
            'warning');
        return;
    }

    if (typeof Html5Qrcode === 'undefined') {
        showToast('Error', 'QR scanner library not available', 'error');
        return;
    }

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
                '<p style="text-align:center;color:#6c757d;font-size:0.85rem;margin-top:0.5rem">' +
                    'Point your camera at a QR code containing an address' +
                '</p>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);
    overlay.style.display = 'flex';

    var scanner = new Html5Qrcode('qr-scanner-reader');
    var scanning = false;

    function closeScanner() {
        if (scanning) {
            scanner.stop().catch(function() {});
        }
        if (document.body.contains(overlay)) {
            document.body.removeChild(overlay);
        }
        document.removeEventListener('keydown', escHandler);
    }

    function escHandler(e) {
        if (e.key === 'Escape' || e.keyCode === 27) closeScanner();
    }

    overlay.querySelector('#qr-scanner-close').onclick = closeScanner;
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeScanner();
    });
    document.addEventListener('keydown', escHandler);

    function onScanSuccess(decodedText) {
        var qrData = parseQrData(decodedText);

        var input = document.getElementById(targetInputId);
        if (input) {
            input.value = qrData.address;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // If name field target provided and name is available, fill it
        var nameInputId = opts.nameInputId;
        if (nameInputId && qrData.name) {
            var nameInput = document.getElementById(nameInputId);
            if (nameInput) {
                nameInput.value = qrData.name;
                nameInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        var displayAddr = qrData.address.length > 30 ? qrData.address.substring(0, 30) + '...' : qrData.address;
        var toastMsg = 'Address: ' + displayAddr;
        if (qrData.name) {
            toastMsg = qrData.name + ' — ' + displayAddr;
        }
        showToast('QR Scanned', toastMsg, 'success');
        closeScanner();

        // If callback provided, call it after scan
        if (typeof opts.onScan === 'function') {
            opts.onScan(qrData);
        }
    }

    var scanConfig = { fps: 10, qrbox: { width: 250, height: 250 } };
    function noopFrameError() { /* ignore — fires continuously when no QR in frame */ }

    // Use the literal 'environment' string form — html5-qrcode v2.3.8 only
    // accepts a plain string ('user' / 'environment') or the hard-constraint
    // `{ exact: 'environment' }` object. It does NOT accept the standard
    // MediaTrackConstraints `{ ideal: 'environment' }` form and throws
    // synchronously ("'facingMode' should be string or object with 'exact'")
    // before getUserMedia is even called. An earlier attempt at `{ ideal:
    // 'environment' }` broke every Android/Pixel user because that throw
    // wasn't an OverconstrainedError/NotFoundError and fell straight through
    // to showCameraError with name='Unknown'. The Layer 2 deviceId fallback
    // below still handles devices whose rear camera isn't tagged as
    // 'environment' — OverconstrainedError from getUserMedia does trigger it.
    scanner.start(
        { facingMode: 'environment' },
        scanConfig,
        onScanSuccess,
        noopFrameError
    ).then(function() {
        scanning = true;
    }).catch(function(err) {
        // Layer 2: if the constraint failed (no camera matched), enumerate
        // the device's cameras and start the scanner with an explicit deviceId.
        // Pick the LAST camera in the list — on phones the rear camera is
        // typically last; on desktops there's only one anyway.
        var name = err && err.name;
        if (name === 'OverconstrainedError' || name === 'NotFoundError') {
            Html5Qrcode.getCameras().then(function(devices) {
                if (!devices || !devices.length) {
                    throw err;
                }
                var preferred = devices[devices.length - 1];
                return scanner.start(preferred.id, scanConfig, onScanSuccess, noopFrameError)
                    .then(function() { scanning = true; });
            }).catch(function(secondErr) {
                showCameraError(secondErr || err);
            });
        } else {
            showCameraError(err);
        }
    });

    function showCameraError(err) {
        var name = (err && err.name) || 'Unknown';
        var msg = (err && err.message) || '';
        var ua = (typeof navigator !== 'undefined' && navigator.userAgent) || '';
        var isiOS = /iPad|iPhone|iPod/.test(ua);
        var heading = 'Camera unavailable';
        var detail = '';

        if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
            heading = 'Camera permission denied';
            if (isiOS) {
                detail = 'You blocked camera access for this site. Open Settings → Safari → Camera, set this site to "Allow", then reload the page and try again.';
            } else {
                detail = 'You blocked camera access for this site. Click the camera icon in your browser address bar to grant access, then reopen the scanner.';
            }
        } else if (name === 'NotFoundError') {
            heading = 'No camera found';
            detail = 'No camera is available on this device.';
        } else if (name === 'NotReadableError') {
            heading = 'Camera in use';
            detail = 'The camera is being used by another application. Close other apps using the camera and try again.';
        } else if (name === 'OverconstrainedError') {
            heading = 'No compatible camera';
            detail = 'No camera matched the requested settings. Your device may not have a usable rear camera.';
        } else if (name === 'SecurityError') {
            heading = 'Insecure connection';
            detail = 'Camera access requires a secure (HTTPS) connection. Reload the page over HTTPS and try again.';
        } else {
            heading = 'Camera error';
            detail = 'Could not start the camera (' + escapeHtml(name) + ').';
            if (msg) {
                detail += ' ' + escapeHtml(msg);
            }
        }

        var readerEl = document.getElementById('qr-scanner-reader');
        if (readerEl) {
            readerEl.innerHTML = '<div style="color:#dc3545;text-align:center;padding:2rem;font-size:0.9rem;line-height:1.5">' +
                '<i class="fas fa-exclamation-triangle" style="font-size:1.5rem;display:block;margin-bottom:0.75rem"></i>' +
                '<strong>' + escapeHtml(heading) + '</strong>' +
                '<p style="margin:0.75rem 0 0">' + detail + '</p>' +
                '</div>';
        }

        if (typeof console !== 'undefined' && console.warn) {
            console.warn('QR scanner camera error:', name, msg, err);
        }
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
/**
 * Open a detail modal for a pending payment request row.
 */
function openPrPendingModal(el) {
    var row = el.closest('tr');
    if (!row) return;

    var name = row.getAttribute('data-pr-name') || '—';
    var amount = row.getAttribute('data-pr-amount') || '—';
    var desc = row.getAttribute('data-pr-desc') || '';
    var date = row.getAttribute('data-pr-date') || '—';
    var direction = row.getAttribute('data-pr-direction') || '';
    var requestId = row.getAttribute('data-pr-request-id') || '';
    var csrf = row.getAttribute('data-pr-csrf') || '';

    var dirLabel = direction === 'incoming' ? 'Incoming — they requested' : 'Outgoing — you requested';

    var html = '<div class="tx-detail-row"><div class="tx-detail-label">Counterparty</div><div class="tx-detail-value"><strong>' + escapeHtml(name) + '</strong></div></div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Direction</div><div class="tx-detail-value">' + escapeHtml(dirLabel) + '</div></div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Amount</div><div class="tx-detail-value"><strong>' + escapeHtml(amount) + '</strong></div></div>';
    if (desc) {
        html += '<div class="tx-detail-row"><div class="tx-detail-label">Description</div><div class="tx-detail-value">' + escapeHtml(displayTxDescription(desc)) + '</div></div>';
    }
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Date</div><div class="tx-detail-value">' + escapeHtml(date) + '</div></div>';

    // Action buttons as real forms so they submit properly
    html += '<div class="d-flex gap-sm" style="margin-top:1rem">';
    if (direction === 'incoming') {
        html += '<form method="POST" class="d-inline"><input type="hidden" name="action" value="approvePaymentRequest"><input type="hidden" name="csrf_token" value="' + escapeHtml(csrf) + '"><input type="hidden" name="request_id" value="' + escapeHtml(requestId) + '"><button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check"></i> Pay</button></form>';
        html += '<form method="POST" class="d-inline"><input type="hidden" name="action" value="declinePaymentRequest"><input type="hidden" name="csrf_token" value="' + escapeHtml(csrf) + '"><input type="hidden" name="request_id" value="' + escapeHtml(requestId) + '"><button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Decline</button></form>';
    } else {
        html += '<form method="POST" class="d-inline"><input type="hidden" name="action" value="cancelPaymentRequest"><input type="hidden" name="csrf_token" value="' + escapeHtml(csrf) + '"><input type="hidden" name="request_id" value="' + escapeHtml(requestId) + '"><button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-ban"></i> Cancel</button></form>';
    }
    html += '</div>';

    var overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.id = 'pr-pending-modal';
    overlay.innerHTML =
        '<div class="modal-content" style="max-width:480px">' +
            '<div class="modal-header">' +
                '<h3 style="font-size:1rem"><i class="fas fa-hand-holding-usd" style="color:#6c757d"></i> Payment Request Details</h3>' +
                '<span class="close" id="pr-modal-close" title="Close">&times;</span>' +
            '</div>' +
            '<div class="modal-body" style="padding:1.25rem">' + html + '</div>' +
        '</div>';

    function closePrModal() {
        if (document.body.contains(overlay)) document.body.removeChild(overlay);
        document.removeEventListener('keydown', escHandler);
    }
    function escHandler(e) { if (e.key === 'Escape' || e.keyCode === 27) closePrModal(); }

    overlay.querySelector('#pr-modal-close').onclick = closePrModal;
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closePrModal(); });
    document.addEventListener('keydown', escHandler);
    document.body.appendChild(overlay);
    overlay.style.display = 'flex';
}

/**
 * Open a detail modal for a resolved payment request history row.
 */
function openPrHistoryModal(el) {
    var row = el.closest('tr');
    if (!row) return;

    var name = row.getAttribute('data-pr-name') || '—';
    var amount = row.getAttribute('data-pr-amount') || '—';
    var desc = row.getAttribute('data-pr-desc') || '';
    var date = row.getAttribute('data-pr-date') || '—';
    var direction = row.getAttribute('data-pr-direction') || '';
    var status = row.getAttribute('data-pr-status') || '';

    // Build a human-readable status summary
    var summary = '';
    if (status === 'cancelled' && direction === 'outgoing') {
        summary = 'You cancelled this request';
    } else if (status === 'cancelled' && direction === 'incoming') {
        summary = 'Counterparty cancelled this request';
    } else if (status === 'declined' && direction === 'incoming') {
        summary = 'You declined this request';
    } else if (status === 'declined' && direction === 'outgoing') {
        summary = 'Counterparty declined this request';
    } else if (status === 'approved') {
        summary = direction === 'incoming' ? 'You approved and paid this request' : 'Counterparty approved and paid this request';
    } else {
        summary = ucfirst(status);
    }

    var statusColors = { approved: '#28a745', declined: '#dc3545', cancelled: '#6c757d' };
    var statusColor = statusColors[status] || '#6c757d';

    var html = '<div style="text-align:center;padding:0.5rem 0 0.75rem;font-weight:600;color:' + statusColor + '">' + summary + '</div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Counterparty</div><div class="tx-detail-value"><strong>' + escapeHtml(name) + '</strong></div></div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Direction</div><div class="tx-detail-value">' + (direction === 'incoming' ? 'Incoming — they requested' : 'Outgoing — you requested') + '</div></div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Amount</div><div class="tx-detail-value"><strong>' + escapeHtml(amount) + '</strong></div></div>';
    if (desc) {
        html += '<div class="tx-detail-row"><div class="tx-detail-label">Description</div><div class="tx-detail-value">' + escapeHtml(displayTxDescription(desc)) + '</div></div>';
    }
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Date</div><div class="tx-detail-value">' + escapeHtml(date) + '</div></div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Status</div><div class="tx-detail-value" style="color:' + statusColor + '">' + ucfirst(escapeHtml(status)) + '</div></div>';

    var overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.id = 'pr-history-modal';
    overlay.innerHTML =
        '<div class="modal-content" style="max-width:480px">' +
            '<div class="modal-header">' +
                '<h3 style="font-size:1rem"><i class="fas fa-hand-holding-usd" style="color:#6c757d"></i> Payment Request Details</h3>' +
                '<span class="close" id="pr-hist-modal-close" title="Close">&times;</span>' +
            '</div>' +
            '<div class="modal-body" style="padding:1.25rem">' + html + '</div>' +
        '</div>';

    function closeModal() {
        if (document.body.contains(overlay)) document.body.removeChild(overlay);
        document.removeEventListener('keydown', escHandler);
    }
    function escHandler(e) { if (e.key === 'Escape' || e.keyCode === 27) closeModal(); }

    overlay.querySelector('#pr-hist-modal-close').onclick = closeModal;
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', escHandler);
    document.body.appendChild(overlay);
    overlay.style.display = 'flex';
}

function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

/**
 * Open a detail modal for a DLQ item, showing all fields + action buttons.
 */
function openDlqModal(el) {
    var row = el.closest('.dlq-row');
    if (!row) return;

    var dlqId = row.getAttribute('data-dlq-id');
    var type = row.getAttribute('data-dlq-type') || 'Unknown';
    var typeClass = row.getAttribute('data-dlq-type-class') || '';
    var typeIcon = row.getAttribute('data-dlq-type-icon') || 'fa-envelope';
    var recipient = row.getAttribute('data-dlq-recipient') || '—';
    var reason = row.getAttribute('data-dlq-reason') || '—';
    var date = row.getAttribute('data-dlq-date') || '—';
    var status = row.getAttribute('data-status') || 'pending';
    var canRetry = row.getAttribute('data-dlq-can-retry') === '1';
    var canAct = row.getAttribute('data-dlq-can-act') === '1';
    // Payload-extracted txid — populated server-side only for
    // transaction-like message types. Empty for 'contact' / unknown types.
    var dlqTxid = row.getAttribute('data-dlq-txid') || '';

    var statusLabel = status.charAt(0).toUpperCase() + status.slice(1);
    var typeBadge = '<span class="tx-type-badge ' + escapeHtml(typeClass) + '"><i class="fas ' + escapeHtml(typeIcon) + '"></i> ' + escapeHtml(type) + '</span>';

    var html = '<div class="tx-detail-row"><div class="tx-detail-label">Type</div><div class="tx-detail-value">' + typeBadge + '</div></div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Recipient</div><div class="tx-detail-value tx-modal-mono">' + escapeHtml(recipient) + '</div></div>';

    // Transaction ID — clickable so the user can jump straight into the
    // full Transaction Details modal for amount/description/etc. Keeps
    // the DLQ modal tight instead of duplicating transaction fields.
    // openTransactionModalByTxid has an in-memory fast path and an AJAX
    // fallback for older txids outside the current paginator window.
    if (dlqTxid !== '') {
        html += '<div class="tx-detail-row">' +
                '<div class="tx-detail-label">Transaction ID</div>' +
                '<div class="tx-detail-value tx-modal-mono-sm tx-detail-value-link cursor-pointer"' +
                    ' data-action="openTransactionModalByTxid"' +
                    ' data-txid="' + escapeHtml(dlqTxid) + '"' +
                    ' title="Open transaction details">' +
                    escapeHtml(dlqTxid) +
                '</div>' +
                '</div>';
    }
    // Failure Reason + info icon: the stored reason is the LAST error
    // encountered, which is usually from the fallback transport rather
    // than the primary. Without the note the pairing of a Tor-looking
    // recipient with a non-Tor error is confusing (e.g. ".onion" address
    // + "SSL certificate problem" from an HTTPS fallback attempt). The
    // info icon opens the shared info modal so users see the full story.
    // Plain-language copy — avoids jargon like "transport" / "primary"
    // and spells out the *why* with short paragraphs separated by blank
    // lines (the info modal uses white-space: pre-line so \n renders).
    var fallbackNote =
        'Why doesn\u2019t the error match the recipient\u2019s address?\n\n' +
        'Your wallet tries more than one way to reach a contact — first over Tor, then HTTPS, then plain HTTP — and stops when one works.\n\n' +
        'The reason shown above comes from the LAST attempt. So if the contact\u2019s address is a Tor (.onion) one but the error mentions HTTPS or HTTP, it means Tor was unreachable AND the other methods also failed.\n\n' +
        'This usually clears up once the contact is back online. Tap Retry to try again now.';
    html += '<div class="tx-detail-row">' +
            '<div class="tx-detail-label">Failure Reason ' +
                '<i class="fas fa-info-circle info-tooltip-icon" title="' + escapeHtml(fallbackNote) + '" data-action="showInfoModal" role="button" aria-label="About failure reasons"></i>' +
            '</div>' +
            '<div class="tx-detail-value">' + escapeHtml(reason) + '</div>' +
            '</div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Added</div><div class="tx-detail-value">' + escapeHtml(date) + '</div></div>';
    html += '<div class="tx-detail-row"><div class="tx-detail-label">Status</div><div class="tx-detail-value"><span class="dlq-status-badge dlq-badge-' + escapeHtml(status) + '">' + escapeHtml(statusLabel) + '</span></div></div>';

    if (canAct) {
        html += '<div class="d-flex gap-sm" style="margin-top:1rem">';
        if (canRetry) {
            html += '<button class="btn btn-success btn-sm" data-action="retryDlqItem" data-dlq-id="' + dlqId + '" data-stop-propagation="true"><i class="fas fa-redo"></i> Retry</button>';
        }
        html += '<button class="btn btn-secondary btn-sm" data-action="abandonDlqItem" data-dlq-id="' + dlqId + '" data-stop-propagation="true"><i class="fas fa-ban"></i> Abandon</button>';
        html += '</div>';
    }

    var overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.id = 'dlq-detail-modal';
    overlay.innerHTML =
        '<div class="modal-content" style="max-width:480px">' +
            '<div class="modal-header">' +
                '<h3 style="font-size:1rem"><i class="fas fa-inbox" style="color:#6c757d"></i> Failed Message Details</h3>' +
                '<span class="close" id="dlq-modal-close" title="Close">&times;</span>' +
            '</div>' +
            '<div class="modal-body" style="padding:1.25rem">' + html + '</div>' +
        '</div>';

    function closeDlqModal() {
        if (document.body.contains(overlay)) document.body.removeChild(overlay);
        document.removeEventListener('keydown', escHandler);
    }
    function escHandler(e) { if (e.key === 'Escape' || e.keyCode === 27) closeDlqModal(); }

    overlay.querySelector('#dlq-modal-close').onclick = closeDlqModal;
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeDlqModal(); });
    document.addEventListener('keydown', escHandler);
    document.body.appendChild(overlay);
    overlay.style.display = 'flex';
}

function setDlqFilter(filter) {
    // If called without argument, read from the dropdown
    if (!filter) {
        var sel = document.getElementById('dlq-filter-status');
        filter = sel ? sel.value : 'active';
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
    // Apply default filter (show all) on load
    if (document.getElementById('dlq-filter-status')) {
        setDlqFilter('all');
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

    // Close the detail modal if open
    var dlqModal = document.getElementById('dlq-detail-modal');
    if (dlqModal && document.body.contains(dlqModal)) { document.body.removeChild(dlqModal); }

    showLoader('Retrying delivery...', 'Attempting to re-send the message to the recipient.');
    // Match the send / approve / add-contact flows — show the 15s refresh
    // countdown so the user knows the page will refresh automatically.
    // The server-side retry continues regardless; a reload just returns
    // them to an interactive page. clearOperationTimeout fires on any
    // terminal XHR callback below.
    startOperationTimeout('dlqRetry', 'Still retrying delivery. The retry is continuing in the background — check Failed Messages for updates.');

    var formData = new FormData();
    formData.append('action',     'dlqRetry');
    formData.append('dlq_id',     dlqId);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 90000; // 90s — Tor connections can be slow

    xhr.ontimeout = function() {
        clearOperationTimeout();
        hideLoader();
        showToast('Timeout', 'Retry timed out — the recipient may be offline', 'warning');
    };

    xhr.onerror = function() {
        clearOperationTimeout();
        hideLoader();
        showToast('Error', 'Network error — please try again', 'error');
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        clearOperationTimeout();
        hideLoader();
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                showToast('Delivered', 'Message successfully re-sent', 'success');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else if (response.error && response.error.indexOf('CSRF') !== -1) {
                showToast('Session expired', 'Refreshing page — please retry after reload', 'warning');
                setTimeout(function() { window.location.hash = 'dlq'; window.location.reload(); }, 1500);
            } else {
                // Prefer the human-readable message over the generic error tag
                // ('server_error', 'delivery_failed' etc). Falls back to error
                // tag, then a generic notice if neither is populated.
                var errMsg = response.message || response.error || 'Retry failed — try again later';
                showToast('Retry Failed', errMsg, 'error');
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
        }
    };

    xhr.send(formData);
}

/**
 * Revoke a single remember-me session (one device) by row id.
 * On success, reloads so the Active Sessions list + any "This device"
 * badge get re-rendered.
 */
function revokeRememberSession(sessionId, btn) {
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        showToast('Error', 'CSRF token not found', 'error');
        return;
    }
    if (btn) { btn.disabled = true; }
    var formData = new FormData();
    formData.append('action', 'revokeRememberSession');
    formData.append('session_id', sessionId);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (btn) { btn.disabled = false; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                showToast('Signed out', 'Remembered device removed', 'success');
                setTimeout(function() { window.location.reload(); }, 500);
            } else {
                showToast('Error', response.message || response.error || 'Could not sign out', 'error');
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
        }
    };
    xhr.send(formData);
}

/**
 * Revoke every remember-me session, including the current one. Always
 * reloads: if the current session was included, the user ends up on the
 * login page.
 */
function revokeAllRememberSessions(btn) {
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        showToast('Error', 'CSRF token not found', 'error');
        return;
    }
    if (btn) { btn.disabled = true; }
    var formData = new FormData();
    formData.append('action', 'revokeAllRememberSessions');
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (btn) { btn.disabled = false; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                showToast('Signed out everywhere', (response.revoked || 0) + ' device(s) signed out', 'success');
                setTimeout(function() { window.location.reload(); }, 600);
            } else {
                showToast('Error', response.message || response.error || 'Could not sign out', 'error');
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
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

    // Close the detail modal if open
    var dlqModal = document.getElementById('dlq-detail-modal');
    if (dlqModal && document.body.contains(dlqModal)) { document.body.removeChild(dlqModal); }

    if (btn) { btn.disabled = true; }

    var formData = new FormData();
    formData.append('action',     'dlqAbandon');
    formData.append('dlq_id',     dlqId);
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 30000;

    xhr.ontimeout = function() {
        showToast('Timeout', 'Request timed out — please try again', 'warning');
    };

    xhr.onerror = function() {
        showToast('Error', 'Network error — please try again', 'error');
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                showToast('Abandoned', 'Message marked as abandoned', 'info');
                setTimeout(function() { window.location.hash = 'dlq'; window.location.reload(); }, 1000);
            } else if (response.error && response.error.indexOf('CSRF') !== -1) {
                showToast('Session expired', 'Refreshing page — please retry after reload', 'warning');
                setTimeout(function() { window.location.hash = 'dlq'; window.location.reload(); }, 1500);
            } else {
                showToast('Error', response.error || 'Failed to abandon item', 'error');
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
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
    var count = document.querySelectorAll('.dlq-row[data-status="pending"], .dlq-row[data-status="retrying"]').length;
    if (!confirm('Retry all ' + count + ' pending message' + (count !== 1 ? 's' : '') + '?')) {
        return;
    }

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        showToast('Error', 'CSRF token not found', 'error');
        return;
    }

    showLoader('Retrying all failed messages...', 'Each message is being re-sent to its recipient.');

    var formData = new FormData();
    formData.append('action',     'dlqRetryAll');
    formData.append('csrf_token', csrfToken.value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 120000; // 2 min — bulk retries can be slow over Tor

    xhr.ontimeout = function() {
        hideLoader();
        showToast('Timeout', 'Bulk retry timed out — some messages may not have been retried', 'warning');
    };

    xhr.onerror = function() {
        hideLoader();
        showToast('Error', 'Network error — please try again', 'error');
    };

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        hideLoader();
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                var delivered = response.delivered || 0;
                var failed = response.failed || 0;
                var total = response.total || 0;
                if (delivered > 0 && failed === 0) {
                    showToast('All delivered', delivered + ' message' + (delivered !== 1 ? 's' : '') + ' successfully re-sent', 'success');
                } else if (delivered > 0) {
                    showToast('Partial success', delivered + ' delivered, ' + failed + ' failed', 'warning');
                } else {
                    showToast('All failed', total + ' message' + (total !== 1 ? 's' : '') + ' could not be delivered', 'error');
                }
                setTimeout(function() { window.location.hash = 'dlq'; window.location.reload(); }, 2000);
            } else if (response.error && response.error.indexOf('CSRF') !== -1) {
                showToast('Session expired', 'Refreshing page — please retry after reload', 'warning');
                setTimeout(function() { window.location.hash = 'dlq'; window.location.reload(); }, 1500);
            } else {
                showToast('Error', response.error || 'Bulk retry failed', 'error');
            }
        } catch (e) {
            showToast('Error', 'Unexpected server response', 'error');
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
    var count = document.querySelectorAll('.dlq-row[data-status="pending"], .dlq-row[data-status="retrying"]').length;
    if (!confirm('Abandon all ' + count + ' pending message' + (count !== 1 ? 's' : '') + '? This cannot be undone.')) {
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
                setTimeout(function() { window.location.hash = 'dlq'; window.location.reload(); }, 1000);
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
    initToggleSwitch('autoAcceptTransaction', 'autoAcceptTransactionStatus');
    initToggleSwitch('autoAcceptRestoredContact', 'autoAcceptRestoredContactStatus');
    initToggleSwitch('autoRejectUnknownCurrency', 'autoRejectUnknownCurrencyStatus');
    initToggleSwitch('hopBudgetRandomized', 'hopBudgetRandomizedStatus');
    initToggleSwitch('hideEmptyGuiSections', 'hideEmptyGuiSectionsStatus');
    initToggleSwitch('liveNotificationsEnabled', 'liveNotificationsEnabledStatus');
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
// "What's New" — release notes modal after version upgrade
// ============================================================================

/**
 * Open the What's New modal and fetch release notes from the server.
 * The server fetches from GitHub Releases (cached) and returns rendered HTML.
 *
 * @param {string} version - The version to fetch notes for
 */
function openWhatsNewModal(version) {
    var modal = document.getElementById('whatsNewModal');
    if (!modal) { return; }

    var titleEl = document.getElementById('whats-new-modal-title');
    var contentEl = document.getElementById('whats-new-modal-content');
    var githubLink = document.getElementById('whats-new-github-link');

    if (titleEl) { titleEl.textContent = "What's New in v" + escapeHtml(version); }
    if (contentEl) {
        contentEl.innerHTML = '<div class="d-flex align-center justify-center" style="padding: 2rem;">' +
            '<i class="fas fa-spinner fa-spin"></i>&nbsp; Loading release notes&hellip;</div>';
    }
    if (githubLink) {
        githubLink.href = 'https://github.com/Vowels-Group/eiou-docker/releases/tag/v' + encodeURIComponent(version);
    }

    modal.classList.remove('d-none');
    modal.style.display = 'flex';

    // Fetch release notes via AJAX
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    var formData = new FormData();
    formData.append('action', 'whatsNewNotes');
    formData.append('version', version);
    if (csrfToken && csrfToken.value) {
        formData.append('csrf_token', csrfToken.value);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 20000;

    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) { return; }
        if (!contentEl) { return; }
        try {
            var response = JSON.parse(xhr.responseText);
            if (response.success && response.data && response.data.body_html) {
                var html = '';
                if (response.data.published_at) {
                    var d = new Date(response.data.published_at);
                    html += '<p class="release-notes-date"><i class="fas fa-calendar-alt"></i> Released ' + d.toLocaleDateString() + '</p>';
                }
                html += '<div class="release-notes-body">' + response.data.body_html + '</div>';
                contentEl.innerHTML = html;
                if (githubLink && response.data.html_url) {
                    githubLink.href = response.data.html_url;
                }
            } else {
                var errorMsg = (response && response.error) ? response.error : 'Could not load release notes.';
                contentEl.innerHTML = '<div class="release-notes-fallback">' +
                    '<p><i class="fas fa-info-circle"></i> ' + escapeHtml(errorMsg) + '</p>' +
                    '<p>View the full release notes on GitHub:</p>' +
                    '<a href="https://github.com/Vowels-Group/eiou-docker/releases/tag/v' + encodeURIComponent(version) + '" target="_blank" rel="noopener noreferrer">' +
                    '<i class="fab fa-github"></i> v' + escapeHtml(version) + ' Release Notes</a></div>';
            }
        } catch (e) {
            contentEl.innerHTML = '<div class="release-notes-fallback">' +
                '<p><i class="fas fa-exclamation-triangle"></i> Failed to load release notes.</p>' +
                '<a href="https://github.com/Vowels-Group/eiou-docker/releases/tag/v' + encodeURIComponent(version) + '" target="_blank" rel="noopener noreferrer">' +
                '<i class="fab fa-github"></i> View on GitHub</a></div>';
        }
    };

    xhr.onerror = function() {
        if (contentEl) {
            contentEl.innerHTML = '<div class="release-notes-fallback">' +
                '<p><i class="fas fa-exclamation-triangle"></i> Network error — could not reach the server.</p></div>';
        }
    };
    xhr.ontimeout = function() {
        if (contentEl) {
            contentEl.innerHTML = '<div class="release-notes-fallback">' +
                '<p><i class="fas fa-clock"></i> Request timed out. The node may not be able to reach GitHub.</p>' +
                '<a href="https://github.com/Vowels-Group/eiou-docker/releases/tag/v' + encodeURIComponent(version) + '" target="_blank" rel="noopener noreferrer">' +
                '<i class="fab fa-github"></i> View on GitHub</a></div>';
        }
    };
    xhr.send(formData);
}

/**
 * Close the What's New modal.
 */
function closeWhatsNewModal() {
    var modal = document.getElementById('whatsNewModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.add('d-none');
    }
}

/**
 * Dismiss the What's New banner — marks the current version as seen
 * so the banner won't reappear on next page load.
 */
function dismissWhatsNew() {
    var banner = document.getElementById('whats-new-banner');
    if (banner) {
        banner.style.opacity = '0';
        banner.style.transition = 'opacity 0.3s ease';
        setTimeout(function() { banner.remove(); }, 300);
    }

    var csrfToken = document.querySelector('input[name="csrf_token"]');
    var formData = new FormData();
    formData.append('action', 'whatsNewDismiss');
    if (csrfToken && csrfToken.value) {
        formData.append('csrf_token', csrfToken.value);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 10000;
    xhr.send(formData);
}

// ============================================================================
// Transaction history — auto-refresh and P2P approval
//
// The data bootstrap (transactionData, hasInProgressTx, autoRefreshEnabled,
// syncingTransactionCount) is rendered by transactionHistory.html as an inline
// <script> block with PHP-interpolated values. Everything static lives here.
// ============================================================================

var AUTO_REFRESH_DELAY = 15000; // 15 seconds
var autoRefreshInterval = null;

function startAutoRefresh() {
    // Only start if enabled in settings AND there are in-progress transactions
    if (!(typeof hasInProgressTx !== 'undefined' && hasInProgressTx)) return;
    if (!(typeof autoRefreshEnabled !== 'undefined' && autoRefreshEnabled)) return;
    // Mutual exclusion: when live event notifications are active, skip the
    // 15s full-page reload. Live notifications handle status changes
    // toast-style without clobbering a just-fired toast via a reload.
    if (typeof liveNotificationsEnabled !== 'undefined' && liveNotificationsEnabled) return;
    if (autoRefreshInterval) return;

    var indicator = document.getElementById('tx-auto-refresh-status');
    if (indicator) indicator.classList.add('active');

    autoRefreshInterval = setInterval(function() {
        // Skip if manual refresh is already in progress
        if (window.isRefreshing) { return; }

        // XMLHttpRequest — Tor Browser compatible (no fetch/AbortController)
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.location.pathname + '?check_updates=1&_=' + Date.now(), true);
        xhr.timeout = 30000; // 30s for Tor

        xhr.ontimeout = function() { /* retry next interval */ };
        xhr.onerror = function() { /* retry next interval */ };
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) { return; }
            if (window.isRefreshing) { return; }
            if (xhr.status >= 200 && xhr.status < 300) {
                window.location.reload();
            }
        };
        xhr.send();
    }, AUTO_REFRESH_DELAY);
}

window.stopAutoRefresh = function() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        var indicator = document.getElementById('tx-auto-refresh-status');
        if (indicator) indicator.classList.remove('active');
    }
};

// ============================================================================
// Live event notifications — non-reloading XHR poll.
//
// Separate from the 15s auto-refresh above. That one reloads the page; this
// one never does. Hits /?check_incoming=1&since=<ts>, receives JSON deltas,
// routes them to showEventToast() per the user's verbosity setting, and
// updates tab badges in place.
//
// Activity gates (all layered together to keep the feature unobtrusive):
//   - Visibility API: pause entirely when document.hidden (cursor preserved
//     so resume catches everything that arrived while hidden)
//   - Idle backoff: drop from base→3× after 60s no interaction, 6× after 5min
//   - Dedup via sessionStorage: surviving a full reload won't re-toast the
//     same ids
//   - Cursor overlap: next-poll `since` is server `now - 1` so events whose
//     DB timestamp rounds to the same integer second as the server's
//     `time()` call aren't dropped when they land after the query ran
// Settings are delivered by the server on each poll — we trust the server
// over the bootstrap constants, so a settings save takes effect without
// page reload.
// ============================================================================

var LIVE_POLL_BASE_MS = 10000;
var LIVE_POLL_IDLE_1_MS = 30000;   // 3× base
var LIVE_POLL_IDLE_2_MS = 60000;   // 6× base
var LIVE_IDLE_THRESHOLD_1_MS = 60 * 1000;
var LIVE_IDLE_THRESHOLD_2_MS = 5 * 60 * 1000;
var LIVE_SEEN_IDS_KEY = 'eiou_live_seen_ids_v1';
var LIVE_SEEN_IDS_MAX = 500; // bounded to avoid unbounded growth

var liveTimer = null;
var liveSinceTs = 0;
var liveLastInteractionAt = Date.now();
var liveInflight = false;
var liveSettingsCache = null; // last-seen server settings (enabled, verbosity, toast_duration_ms, poll_interval_ms)

function liveBumpInteraction() { liveLastInteractionAt = Date.now(); }
['mousedown', 'keydown', 'touchstart', 'scroll'].forEach(function(evt) {
    document.addEventListener(evt, liveBumpInteraction, { passive: true });
});

function liveLoadSeenIds() {
    try {
        var raw = sessionStorage.getItem(LIVE_SEEN_IDS_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch (e) { return {}; }
}
function liveSaveSeenIds(map) {
    try {
        var keys = Object.keys(map);
        if (keys.length > LIVE_SEEN_IDS_MAX) {
            // FIFO trim — drop oldest half.
            var pairs = keys.map(function(k) { return [k, map[k]]; });
            pairs.sort(function(a, b) { return a[1] - b[1]; });
            pairs = pairs.slice(pairs.length - Math.floor(LIVE_SEEN_IDS_MAX / 2));
            map = {};
            pairs.forEach(function(p) { map[p[0]] = p[1]; });
        }
        sessionStorage.setItem(LIVE_SEEN_IDS_KEY, JSON.stringify(map));
    } catch (e) { /* quota / private mode — swallow */ }
}
function liveMarkSeen(key) {
    var map = liveLoadSeenIds();
    map[key] = Date.now();
    liveSaveSeenIds(map);
}
function liveIsSeen(key) {
    var map = liveLoadSeenIds();
    return !!map[key];
}

function liveComputeInterval() {
    var baseMs = (liveSettingsCache && liveSettingsCache.poll_interval_ms) || LIVE_POLL_BASE_MS;
    var idleFor = Date.now() - liveLastInteractionAt;
    if (idleFor >= LIVE_IDLE_THRESHOLD_2_MS) return Math.max(baseMs * 6, LIVE_POLL_IDLE_2_MS);
    if (idleFor >= LIVE_IDLE_THRESHOLD_1_MS) return Math.max(baseMs * 3, LIVE_POLL_IDLE_1_MS);
    return baseMs;
}

function liveTruncate(s, n) { s = String(s || ''); return s.length > n ? s.substring(0, n - 1) + '…' : s; }
function liveShortHash(h) { if (!h) return ''; return String(h).substring(0, 8) + '…'; }
function liveSwitchTabIfExists(tabName) {
    if (typeof switchTab === 'function') {
        try { switchTab(tabName); } catch (e) { /* tab not present on this page */ }
    }
}

function liveBadgeBumpCount(selector, delta) {
    // Best-effort: increments a numeric badge by delta if we can parse its
    // current text. If not parseable, skip — a full page render will reconcile.
    var el = document.querySelector(selector);
    if (!el) return;
    var m = /(\d+)/.exec(el.textContent || '');
    if (!m) return;
    var n = parseInt(m[1], 10) + delta;
    el.textContent = (el.textContent || '').replace(/\d+/, String(n));
}

function liveDispatch(payload) {
    var verbosity = (liveSettingsCache && liveSettingsCache.verbosity) || 'balanced';
    var duration = (liveSettingsCache && liveSettingsCache.toast_duration_ms != null)
        ? liveSettingsCache.toast_duration_ms
        : EVENT_TOAST_DEFAULT_DURATION_MS;
    EVENT_TOAST_DEFAULT_DURATION_MS = duration; // keep module-level in sync for any direct callers

    var neu = payload && payload.new ? payload.new : {};

    // Payment requests — always toast (all verbosities).
    (neu.payment_requests || []).forEach(function(pr) {
        var seenKey = 'pr:' + (pr.id || '');
        if (!pr.id || liveIsSeen(seenKey)) return;
        liveMarkSeen(seenKey);
        liveBadgeBumpCount('[data-badge="payment-requests"]', 1);
        showEventToast({
            kind: 'payment_request',
            title: 'New payment request',
            message: (pr.amount != null ? pr.amount + ' ' : '') + (pr.currency || '') + (pr.description ? ' — ' + liveTruncate(pr.description, 40) : ''),
            details: {
                request_id: pr.id,
                amount: pr.amount,
                currency: pr.currency,
                requester: liveShortHash(pr.requester_pubkey_hash),
                description: pr.description,
            },
            dedupKey: seenKey,
            duration: duration,
            action: { label: 'View', onClick: function() { liveSwitchTabIfExists('activity'); } },
        });
    });

    // Contact requests — always toast. Address-type keys (http / https / tor
    // and any future additions) arrive under `c.addresses` as a dict keyed by
    // the schema column name — the server discovers these dynamically from
    // INFORMATION_SCHEMA.COLUMNS on the `addresses` table, so new transports
    // surface here without a client-side change.
    (neu.contact_requests || []).forEach(function(c) {
        var seenKey = 'c:' + (c.pubkey_hash || '');
        if (!c.pubkey_hash || liveIsSeen(seenKey)) return;
        liveMarkSeen(seenKey);
        liveBadgeBumpCount('[data-badge="contact-requests"]', 1);
        var details = { pubkey: liveShortHash(c.pubkey_hash) };
        if (c.addresses && typeof c.addresses === 'object') {
            for (var k in c.addresses) {
                if (Object.prototype.hasOwnProperty.call(c.addresses, k)) {
                    details[k] = c.addresses[k];
                }
            }
        }
        showEventToast({
            kind: 'contact_request',
            title: 'New contact request',
            message: liveShortHash(c.pubkey_hash),
            details: details,
            dedupKey: seenKey,
            duration: duration,
            action: { label: 'View', onClick: function() { liveSwitchTabIfExists('contacts'); } },
        });
    });

    // Transactions — verbosity-gated.
    //   Quiet:    only received-completed toasts
    //   Balanced: same as Quiet for tx (+ dlq toasts; handled below)
    //   Live:     everything toasts, including status churn on in-flight
    (neu.transactions || []).forEach(function(tx) {
        if (!tx.txid) return;
        var status = (tx.status || '').toLowerCase();
        var type = (tx.type || '').toLowerCase();
        var isReceivedCompleted = type === 'received' && status === 'completed';
        var shouldToast;
        if (verbosity === 'live') {
            shouldToast = true;
        } else {
            shouldToast = isReceivedCompleted;
        }
        // In-flight tx status updates always dedup to the same key so the
        // pending→sent→completed progression is ONE toast, not three.
        var seenKey = (isReceivedCompleted ? 'txc:' : 'tx:') + tx.txid;
        var dedupKey = 'tx:' + tx.txid;
        if (shouldToast) {
            if (isReceivedCompleted && liveIsSeen(seenKey)) return;
            if (isReceivedCompleted) liveMarkSeen(seenKey);
            showEventToast({
                kind: isReceivedCompleted ? 'tx_received' : 'tx_status',
                title: isReceivedCompleted ? 'Payment received' : ('Transaction ' + status),
                message: (tx.amount != null ? tx.amount + ' ' : '') + (tx.currency || ''),
                details: {
                    txid: liveShortHash(tx.txid),
                    type: tx.type,
                    status: tx.status,
                    amount: tx.amount,
                    currency: tx.currency,
                    sender: liveShortHash(tx.sender_address),
                    receiver: liveShortHash(tx.receiver_address),
                    description: tx.description,
                },
                dedupKey: dedupKey,
                duration: duration,
                action: { label: 'View', onClick: function() { liveSwitchTabIfExists('activity'); } },
            });
        }
        // Badge bump only for brand-new received txs (not status churn).
        if (isReceivedCompleted && !liveIsSeen('txb:' + tx.txid)) {
            liveMarkSeen('txb:' + tx.txid);
            liveBadgeBumpCount('[data-badge="recent-transactions"]', 1);
        }
    });

    // DLQ — verbosity-gated. Balanced + Live toast; Quiet is badge-only.
    (neu.dlq || []).forEach(function(d) {
        if (!d.id) return;
        var seenKey = 'dlq:' + d.id;
        if (liveIsSeen(seenKey)) return;
        liveMarkSeen(seenKey);
        liveBadgeBumpCount('[data-badge="dlq"]', 1);
        if (verbosity === 'quiet') return;
        showEventToast({
            kind: 'dlq',
            title: 'Delivery failure',
            message: (d.message_type || 'message') + ' — ' + (d.status || ''),
            details: {
                id: d.id,
                message_type: d.message_type,
                message_id: liveShortHash(d.message_id),
                status: d.status,
            },
            dedupKey: seenKey,
            duration: duration,
            action: { label: 'View', onClick: function() { liveSwitchTabIfExists('activity'); } },
        });
    });
}

function livePollOnce() {
    if (liveInflight) return;
    if (document.hidden) return;
    liveInflight = true;
    var url = window.location.pathname + '?check_incoming=1&since=' + encodeURIComponent(liveSinceTs) + '&_=' + Date.now();
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.timeout = 30000;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        liveInflight = false;
        if (xhr.status < 200 || xhr.status >= 300) return;
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
        if (!data) return;
        if (data.settings) {
            liveSettingsCache = data.settings;
            // Server disabled the feature → stop polling. Client can re-enable
            // on next page load.
            if (liveSettingsCache.enabled === false) {
                liveStopPolling();
                return;
            }
        }
        // Overlap the next cursor by 1 second so we don't silently drop
        // events whose DB timestamp rounds to the same integer second as
        // the server's `time()` call but which landed *after* the query
        // ran. Client-side dedup (`liveIsSeen` + `eventToastsByDedupKey`)
        // absorbs the redundant fire on the next poll.
        if (data.now) liveSinceTs = Math.max(0, data.now - 1);
        liveDispatch(data);
    };
    xhr.ontimeout = function() { liveInflight = false; };
    xhr.onerror = function() { liveInflight = false; };
    xhr.send();
}

function liveSchedule() {
    if (liveTimer) { clearTimeout(liveTimer); liveTimer = null; }
    if (typeof liveNotificationsEnabled !== 'undefined' && !liveNotificationsEnabled) return;
    liveTimer = setTimeout(function() {
        livePollOnce();
        liveSchedule();
    }, liveComputeInterval());
}

function liveStartPolling() {
    if (typeof liveNotificationsEnabled === 'undefined' || !liveNotificationsEnabled) return;
    // First start: since = now - 60s so we don't re-toast anything rendered
    // on first paint, but still catch any arrivals right after page load.
    // Subsequent calls (e.g. tab coming back from `visibilitychange:hidden`)
    // must PRESERVE the cursor — otherwise events accrued while the tab was
    // hidden get silently dropped on resume.
    if (!liveSinceTs) {
        liveSinceTs = Math.floor(Date.now() / 1000) - 60;
    }
    liveSchedule();
}
function liveStopPolling() {
    if (liveTimer) { clearTimeout(liveTimer); liveTimer = null; }
}

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        liveStopPolling();
    } else {
        liveStartPolling();
    }
});
window.addEventListener('beforeunload', liveStopPolling);

// P2P Transaction Approval/Rejection (XMLHttpRequest for Tor compatibility)
function approveP2pTransaction(hash, candidateId) {
    var msg = candidateId ? 'Are you sure you want to send via this route?' : 'Are you sure you want to approve and send this transaction?';
    if (!confirm(msg)) return;
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken) { alert('CSRF token not found'); return; }
    var body = 'action=approveP2pTransaction&hash=' + encodeURIComponent(hash) + '&csrf_token=' + encodeURIComponent(csrfToken.value);
    if (candidateId) {
        body = body + '&candidate_id=' + encodeURIComponent(candidateId);
    }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 60000;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) { window.location.reload(); }
                    else { alert('Error: ' + (data.message || 'Unknown error')); }
                } catch (e) {
                    alert('Error parsing response');
                }
            } else {
                alert('Network error');
            }
        }
    };
    xhr.ontimeout = function() { alert('Request timed out'); };
    xhr.send(body);
}

function rejectP2pTransaction(hash) {
    if (!confirm('Are you sure you want to reject this transaction?')) return;
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken) { alert('CSRF token not found'); return; }
    var body = 'action=rejectP2pTransaction&hash=' + encodeURIComponent(hash) + '&csrf_token=' + encodeURIComponent(csrfToken.value);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 60000;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) { window.location.reload(); }
                    else { alert('Error: ' + (data.message || 'Unknown error')); }
                } catch (e) {
                    alert('Error parsing response');
                }
            } else {
                alert('Network error');
            }
        }
    };
    xhr.ontimeout = function() { alert('Request timed out'); };
    xhr.send(body);
}

function loadP2pCandidates(hash, container) {
    var csrfToken = document.querySelector('input[name="csrf_token"]');
    if (!csrfToken) { container.innerHTML = '<div class="text-danger">CSRF token not found</div>'; return; }
    var body = 'action=getP2pCandidates&hash=' + encodeURIComponent(hash) + '&csrf_token=' + encodeURIComponent(csrfToken.value);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname, true);
    xhr.timeout = 60000;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success && data.candidates && data.candidates.length > 0) {
                        // Update the route count header dynamically (late candidates may arrive)
                        var countEl = document.getElementById('p2p-count-' + hash);
                        if (countEl) { countEl.textContent = data.candidates.length + ' route(s) found — choose one:'; }
                        var html = '';
                        var currency = 'USD';
                        // Convert SplitAmount {whole, frac} to float (frac modulus = 10^8)
                        function saToFloat(v) { return typeof v === 'number' ? v / 100 : (v.whole || 0) + (v.frac || 0) / 100000000; }
                        var baseFloat = saToFloat(data.base_amount);
                        for (var i = 0; i < data.candidates.length; i++) {
                            var c = data.candidates[i];
                            var totalCost = saToFloat(c.amount);
                            var routeFee = totalCost - baseFloat;
                            var addr = c.sender_address;
                            var shortAddr = addr.length > 20 ? addr.substring(0, 20) + '...' : addr;
                            html = html + '<div class="p2p-candidate-row">';
                            html = html + '<div class="p2p-candidate-info">';
                            html = html + '<div class="p2p-candidate-fee">Route Fee: <strong class="p2p-candidate-fee-value">' + routeFee.toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + currency + '</strong></div>';
                            html = html + '<div class="p2p-candidate-total">Total: ' + totalCost.toFixed(EIOU_DISPLAY_DECIMALS) + ' ' + currency + '</div>';
                            html = html + '<div class="text-muted monospace" title="' + addr + '">' + shortAddr + '</div>';
                            html = html + '</div>';
                            html = html + '<button class="btn btn-success btn-sm ml-sm btn-nowrap" data-action="approveP2pTransaction" data-txid="' + escapeHtml(hash) + '" data-candidate-id="' + c.id + '" data-stop-propagation="true">';
                            html = html + '<i class="fas fa-check"></i> Choose</button>';
                            html = html + '</div>';
                        }
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="status-msg-muted">No candidates available</div>';
                    }
                } catch (e) {
                    container.innerHTML = '<div class="status-msg-danger">Error loading routes</div>';
                }
            } else {
                container.innerHTML = '<div class="status-msg-danger">Failed to load routes</div>';
            }
        }
    };
    xhr.ontimeout = function() { container.innerHTML = '<div class="status-msg-danger">Request timed out</div>'; };
    xhr.send(body);
}

document.addEventListener('DOMContentLoaded', function() {
    // Start auto-refresh if there are in-progress transactions
    if (typeof hasInProgressTx !== 'undefined' && hasInProgressTx) {
        startAutoRefresh();
        if (typeof showInProgressToasts === 'function') {
            showInProgressToasts();
        }
    }
    // Start the live event notifications poll (independent of the 15s
    // reload). Visibility-gated, idle-backoff, modal-suppressed — safe to
    // kick off unconditionally when enabled.
    liveStartPolling();

    // Load candidates for multi-candidate P2P approval rows on page load
    var candidateContainers = document.querySelectorAll('[id^="p2p-candidates-"]');
    for (var i = 0; i < candidateContainers.length; i++) {
        var el = candidateContainers[i];
        var hash = el.id.replace('p2p-candidates-', '');
        loadP2pCandidates(hash, el);
    }
});

window.addEventListener('beforeunload', window.stopAutoRefresh);

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
            var nameTarget = el.getAttribute('data-scan-name-target');
            if (target) openQrScanner(target, { nameInputId: nameTarget || null });
        },
        'scanContactQr': function() {
            // Open scanner, then navigate to contacts tab and open add contact modal
            openQrScanner('address', {
                nameInputId: 'name',
                onScan: function() {
                    switchTab('contacts');
                    openAddContactModal();
                }
            });
        },
        'exportAddressQr': function(el) {
            exportAddressQr(
                el.getAttribute('data-qr-container'),
                el.getAttribute('data-qr-address') || '',
                el.getAttribute('data-qr-name') || ''
            );
        },

        // Navigation & reload
        'reloadWithHash': function(el) {
            var hash = el.getAttribute('data-hash');
            window.location.href = window.location.pathname + '#' + hash;
            window.location.reload();
        },

        // Contacts table — sort by clicking a numeric column header
        'sortContacts': function(el) {
            var col = el.getAttribute('data-sort-column');
            if (col) sortContacts(col);
        },

        // Transactions table — sort by clicking a sortable column header
        'sortTransactions': function(el) {
            var col = el.getAttribute('data-sort-column');
            if (col) sortTransactions(col);
        },
        // Payment requests table — sort
        'sortPaymentRequests': function(el) {
            var col = el.getAttribute('data-sort-column');
            if (col) sortPaymentRequests(col);
        },

        // Transaction history
        'openTransactionModal': function(el) {
            var index = parseInt(el.getAttribute('data-index'), 10);
            openTransactionModal(index);
        },
        'openTransactionModalByTxid': function(el) {
            // Preserve the current stack state whenever the click
            // originates *inside* the tx modal itself (e.g. the Previous
            // Tx link chaining from one tx to the prior one) — otherwise
            // we'd strip `modal-stack-top` mid-traversal and drop the tx
            // modal behind whichever modal it was stacked on top of.
            //
            // Only opt in / out of the stack when the click came from a
            // *different* modal. DLQ detail modal → Transaction ID adds
            // the class; a click from outside any modal clears it so a
            // stale flag from a previous session doesn't survive.
            var txModalEl = document.getElementById('transactionModal');
            if (txModalEl) {
                var originModal = el.closest ? el.closest('.modal') : null;
                var originatedInTxModal = (originModal && originModal.id === 'transactionModal');
                if (!originatedInTxModal) {
                    if (originModal
                        && originModal.style.display !== 'none'
                        && (originModal.offsetParent !== null || originModal.classList.contains('active'))) {
                        txModalEl.classList.add('modal-stack-top');
                    } else {
                        txModalEl.classList.remove('modal-stack-top');
                    }
                }
            }
            openTransactionModalByTxid(el.getAttribute('data-txid'));
        },
        'closeTransactionModal': function() { closeTransactionModal(); },
        // Jump from the Transaction Details modal to a contact's detail modal
        // (e.g. clicking the "To" / "From" party, or a P2P end recipient).
        // The contact modal is stacked on top of the tx modal so closing it
        // returns the user to the transaction they were investigating.
        'jumpToContactFromTxModal': function(el) {
            var cid = el.getAttribute('data-contact-id');
            if (!cid) return;
            var contactModalEl = document.getElementById('contactModal');
            if (contactModalEl) contactModalEl.classList.add('modal-stack-top');
            openContactByContactId(cid);
        },

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

        // Server-side "Search entire database" actions — bypass the
        // local-filter loop and ask the backend to LIKE across the full
        // table so old rows not yet Load-older'd into the DOM surface.
        'searchTransactionsDatabase':    function() { searchTransactionsDatabase(); },
        'searchPaymentRequestsDatabase': function() { searchPaymentRequestsDatabase(); },
        'clearSearchResults': function(el) {
            var key = el.getAttribute('data-search-key') || '';
            clearSearchResults(key);
        },

        // Contact list

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

        // Payment request modals
        'openPrPendingModal': function(el) { openPrPendingModal(el); },
        'openPrHistoryModal': function(el) { openPrHistoryModal(el); },
        // DLQ
        'openDlqModal': function(el) { openDlqModal(el); },
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

        // Remember-me session management
        'revokeRememberSession': function(el) {
            var id = parseInt(el.getAttribute('data-session-id'), 10);
            if (!id) return;
            if (!confirm('Sign out this browser? It will need to enter the auth code again.')) return;
            revokeRememberSession(id, el);
        },
        'revokeAllRememberSessions': function(el) {
            if (!confirm('Sign out ALL remembered browsers, including this one? Every device will need to enter the auth code again.')) return;
            revokeAllRememberSessions(el);
        },
        'openPendingContactModal': function(el) {
            // Row-click handler for the pending-contacts table. Reads the
            // target modal id from data-modal-id and un-hides it. Each
            // pending contact gets its own inline modal so the existing
            // form PHP doesn't need to be JS-serialized.
            var modalId = el.getAttribute('data-modal-id');
            if (!modalId) return;
            var modal = document.getElementById(modalId);
            if (!modal) return;
            modal.classList.remove('d-none');
        },
        'closePendingContactModal': function(el) {
            var modalId = el.getAttribute('data-modal-id');
            if (!modalId) return;
            var modal = document.getElementById(modalId);
            if (modal) modal.classList.add('d-none');
        },
        'openResetToDefaultsModal': function() {
            var modal = document.getElementById('settingsResetToDefaultsModal');
            var input = document.getElementById('settingsResetConfirm');
            var btn = document.getElementById('settingsResetSubmit');
            if (!modal || !input || !btn) return;
            input.value = '';
            btn.disabled = true;
            input.oninput = function() {
                btn.disabled = input.value.trim().toLowerCase() !== 'reset';
            };
            modal.classList.remove('d-none');
            setTimeout(function() { input.focus(); }, 50);
        },
        'closeResetToDefaultsModal': function() {
            var modal = document.getElementById('settingsResetToDefaultsModal');
            if (modal) modal.classList.add('d-none');
        },
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
        'submitDebugReportToSupport': function(el) {
            var mode = el.getAttribute('data-report-mode') || 'full';
            submitDebugReportToSupport(mode);
        },

        // Toast close
        'dismissToast': function(el) {
            var toast = el.parentElement;
            if (toast && toast.parentNode) { toast.parentNode.removeChild(toast); }
        },

        // Analytics consent modal
        'analyticsConsentEnable': function() { submitAnalyticsConsent(true); },
        'analyticsConsentSkip': function() { submitAnalyticsConsent(false); },

        // What's New modal (release notes after upgrade)
        'openWhatsNewModal': function(el, event) {
            event.preventDefault();
            var version = el.getAttribute('data-version') || '';
            openWhatsNewModal(version);
        },
        'closeWhatsNewModal': function() { closeWhatsNewModal(); },
        'dismissWhatsNew': function() { dismissWhatsNew(); },

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
        },

        // API Keys
        'openApiKeyCreateModal': function() { window.apiKeys.openCreateModal(); },
        'closeApiKeyCreateModal': function() { window.apiKeys.closeCreateModal(); },
        'submitApiKeyCreate': function() { window.apiKeys.submitCreate(); },
        'applyApiKeyPreset': function(el) { window.apiKeys.applyPreset(el.getAttribute('data-preset')); },
        'toggleApiKey': function(el) { window.apiKeys.toggle(el.getAttribute('data-key-id'), el.getAttribute('data-enable') === '1'); },
        'deleteApiKeyPrompt': function(el) {
            window.apiKeys.openDeleteModal(el.getAttribute('data-key-id'), el.getAttribute('data-label') || '');
        },
        'closeApiKeyDeleteModal': function() { window.apiKeys.closeDeleteModal(); },
        'submitApiKeyDelete': function() { window.apiKeys.submitDelete(); },
        'submitApiKeyEdit': function() { window.apiKeys.submitEdit(); },
        'disableAllApiKeysPrompt': function() { window.apiKeys.openDisableAllModal(); },
        'closeApiKeysDisableAllModal': function() { window.apiKeys.closeDisableAllModal(); },
        'submitApiKeysDisableAll': function() { window.apiKeys.submitDisableAll(); },
        'deleteAllApiKeysPrompt': function() { window.apiKeys.openDeleteAllModal(); },
        'closeApiKeysDeleteAllModal': function() { window.apiKeys.closeDeleteAllModal(); },
        'submitApiKeysDeleteAll': function() { window.apiKeys.submitDeleteAll(); },
        'sortApiKeys': function(el) { window.apiKeys.sort(el.getAttribute('data-sort-column')); },
        'filterApiKeys': function() { window.apiKeys.applyFilters(); },
        'copyApiKeyId': function(el) { window.apiKeys.copyKeyId(el.getAttribute('data-key-id')); },
        'openApiKeyDetail': function(el) { window.apiKeys.openDetailModal(el.getAttribute('data-key-id')); },
        'closeApiKeysDetailModal': function() { window.apiKeys.closeDetailModal(); },
        'detailToggleApiKey': function() { window.apiKeys.detailToggle(); },
        'detailDeleteApiKey': function() { window.apiKeys.detailDelete(); },
        'copyApiKeyIdFromDetail': function() { window.apiKeys.copyDetailKeyId(); },
        'closeApiKeyRevealModal': function() { window.apiKeys.closeRevealModal(); },
        'copyApiKeyReveal': function(el) { window.apiKeys.copyToClipboard(el.getAttribute('data-target')); },
        'closeApiKeysVerifyModal': function() { window.apiKeys.closeVerifyModal(); },
        'submitApiKeysVerify': function() { window.apiKeys.submitVerify(); }
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
        else if (action === 'filterContacts') { filterContacts(); }
        else if (action === 'filterTransactions') { filterTransactions(); }
        else if (action === 'filterPaymentRequests') { filterPaymentRequests(); }
        else if (action === 'setDlqFilter') { setDlqFilter(); }
        else if (action === 'filterApiKeys') { if (window.apiKeys) window.apiKeys.applyFilters(); }
        else if (action === 'previewColorScheme') {
            // Live preview: flip the swatch next to the select to the
            // chosen scheme without saving. Target element is named by
            // data-preview-target on the select; data-preview-attr tells
            // us which attribute to set (data-amount-colors for the
            // amount scheme, data-status-colors for the status scheme).
            var targetId = el.getAttribute('data-preview-target');
            var attrName = el.getAttribute('data-preview-attr') || 'data-amount-colors';
            var preview = targetId ? document.getElementById(targetId) : null;
            if (preview) { preview.setAttribute(attrName, el.value); }
        }
    }, false);

    // Delegated input handler
    document.addEventListener('input', function(event) {
        var el = event.target;
        var action = el.getAttribute('data-action-input');
        if (!action) return;

        if (action === 'filterDebugLogs') {
            var target = el.getAttribute('data-target');
            filterDebugLogs(el, target);
        } else if (action === 'searchDlq') {
            searchDlq(el.value);
        }
    }, false);

    // Delegated keyup handler
    document.addEventListener('keyup', function(event) {
        var el = event.target;
        var action = el.getAttribute('data-action-keyup');
        if (!action) return;

        if (action === 'filterContacts') { filterContacts(); }
        if (action === 'filterTransactions') { filterTransactions(); }
        if (action === 'filterPaymentRequests') { filterPaymentRequests(); }
        if (action === 'filterApiKeys') { if (window.apiKeys) window.apiKeys.applyFilters(); }
    }, false);

    // Delegated Enter-keypress handler — lets an input opt into "pressing
    // Enter runs X" without hand-rolling a listener per field. Used by
    // the search inputs to trigger the server-side database search, so
    // the user can just type a term and hit Enter.
    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter' && event.keyCode !== 13) return;
        var el = event.target;
        var action = el.getAttribute && el.getAttribute('data-action-keypress-enter');
        if (!action) return;
        event.preventDefault();
        if (action === 'searchTransactionsDatabase') { searchTransactionsDatabase(); }
        if (action === 'searchPaymentRequestsDatabase') { searchPaymentRequestsDatabase(); }
    }, false);

    // ========================================================================
    // API Keys GUI module
    //
    // Backs the "API Keys" section in the Settings tab. All mutating calls
    // (create / enable / disable / delete) round-trip through an additional
    // sensitive-action gate: the server answers 401 sensitive_access_required
    // when the grant is missing or expired, and the module re-prompts for the
    // auth code before retrying the original action. This is independent of
    // "Remember me" — a remembered session is still prompted here.
    // ========================================================================
    window.apiKeys = (function() {
        var pendingAction = null;   // { fn: Function, label: string } to retry after verify
        var pendingDelete = null;   // { keyId, label }
        var pendingEdit = null;     // keyId currently being edited
        var detailKeyId = null;     // keyId of the key in the detail modal
        var lastCreated = null;     // { keyId, secret } for copy-to-clipboard
        var keysById = {};          // cache of last rendered keys so Edit can prefill
        var loaded = false;
        // Table sort state: column name + asc/desc; null = unsorted (use stored order)
        var sortState = { column: null, direction: null };
        // Persistent order used when sort is cleared (server returns newest-first)
        var originalOrder = [];

        function csrfToken() {
            var el = document.querySelector('input[name="csrf_token"]');
            return (el && el.value) ? el.value : '';
        }

        function post(payload) {
            var body = new FormData();
            Object.keys(payload).forEach(function(k) {
                var v = payload[k];
                if (Array.isArray(v)) {
                    v.forEach(function(item) { body.append(k + '[]', item); });
                } else if (v !== null && v !== undefined) {
                    body.append(k, v);
                }
            });
            if (!body.has('csrf_token')) { body.append('csrf_token', csrfToken()); }
            return fetch(window.location.pathname, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function(res) {
                return res.json().then(function(data) {
                    return { status: res.status, data: data };
                });
            });
        }

        function escapeHtml(s) {
            if (s == null) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatWhen(ts) {
            if (!ts) return 'Never';
            // Backend returns SQL datetime strings. Parse as UTC then show local.
            var d = new Date(ts.replace(' ', 'T') + 'Z');
            if (isNaN(d.getTime())) return escapeHtml(ts);
            return d.toLocaleString();
        }

        function renderAccessState(secondsRemaining) {
            var el = document.getElementById('api-keys-access-state');
            if (!el) return;
            if (secondsRemaining > 0) {
                el.textContent = 'Edits unlocked for ' + Math.ceil(secondsRemaining / 60) + ' min';
            } else {
                el.textContent = '';
            }
        }

        function renderList(keys) {
            keys = keys || [];
            keysById = {};
            originalOrder = [];
            keys.forEach(function(k) {
                keysById[k.key_id] = k;
                originalOrder.push(k.key_id);
            });

            // Bulk-action button visibility based on what's actually present
            var activeCount = keys.filter(function(k) { return k.enabled; }).length;
            var disableAllBtn = document.getElementById('apiKeysDisableAllBtn');
            var deleteAllBtn  = document.getElementById('apiKeysDeleteAllBtn');
            if (disableAllBtn) disableAllBtn.classList.toggle('d-none', activeCount === 0);
            if (deleteAllBtn)  deleteAllBtn.classList.toggle('d-none', keys.length === 0);

            var emptyEl  = document.getElementById('api-keys-empty');
            var tableEl  = document.getElementById('api-keys-table-wrapper');
            var filterEl = document.getElementById('api-keys-filters');

            if (keys.length === 0) {
                if (emptyEl) {
                    emptyEl.innerHTML = 'No API keys yet. Create one to let an external application connect to this wallet.';
                    emptyEl.classList.remove('d-none');
                }
                if (tableEl)  tableEl.classList.add('d-none');
                if (filterEl) filterEl.classList.add('d-none');
                return;
            }

            if (emptyEl)  emptyEl.classList.add('d-none');
            if (tableEl)  tableEl.classList.remove('d-none');
            if (filterEl) filterEl.classList.remove('d-none');

            // Repopulate permission dropdown with the union of perms actually in use
            populatePermissionFilter(keys);

            // Render the body according to current sort state, then apply filters
            renderTableBody(sortedKeys());
            applyTableFilters();
            renderSortIndicators();
        }

        function sortedKeys() {
            var arr = originalOrder.map(function(id) { return keysById[id]; }).filter(Boolean);
            if (!sortState.column) return arr;
            var col = sortState.column;
            var dir = sortState.direction === 'desc' ? -1 : 1;
            arr.sort(function(a, b) {
                var av = a[col], bv = b[col];
                // Nulls always sink to the bottom regardless of direction
                if (av == null && bv == null) return 0;
                if (av == null) return 1;
                if (bv == null) return -1;
                if (col === 'rate_limit_per_minute') {
                    return (Number(av) - Number(bv)) * dir;
                }
                // timestamps: lexical compare works for ISO/SQL strings
                return (av < bv ? -1 : av > bv ? 1 : 0) * dir;
            });
            return arr;
        }

        // Collapse permissions by category for display only. Given
        //   ['wallet:read', 'wallet:send', 'contacts:read', 'admin']
        // returns ['wallet:read/send', 'contacts:read', 'admin']. Wildcards
        // (wallet:*) and scopeless entries (admin) are never folded since
        // they'd misrepresent scope. The underlying data row stays the raw
        // list so filter/search/sort are unaffected.
        function groupPermissionsForDisplay(perms) {
            if (!perms || !perms.length) return [];
            var buckets = {};
            var order = [];
            var standalone = [];
            perms.forEach(function(p) {
                var parts = String(p).split(':');
                if (parts.length !== 2) { standalone.push(p); return; }
                var cat = parts[0], action = parts[1];
                if (action === '*') {
                    var wKey = '__w_' + cat;
                    buckets[wKey] = [p];
                    if (order.indexOf(wKey) === -1) order.push(wKey);
                    return;
                }
                if (!buckets[cat]) { buckets[cat] = []; order.push(cat); }
                buckets[cat].push(action);
            });
            return order.map(function(key) {
                var vals = buckets[key];
                if (key.indexOf('__w_') === 0) return vals[0];
                return key + ':' + vals.join('/');
            }).concat(standalone);
        }

        function renderTableBody(keys) {
            var tbody = document.getElementById('api-keys-tbody');
            if (!tbody) return;
            tbody.innerHTML = keys.map(function(k) {
                var perms = k.permissions || [];
                var displayPerms = groupPermissionsForDisplay(perms);
                var permChips = displayPerms.length
                    ? displayPerms.map(function(p) { return '<span class="perm-chip">' + escapeHtml(p) + '</span>'; }).join('')
                    : '<span class="text-muted">—</span>';
                var statusDot = k.enabled
                    ? '<i class="fas fa-circle api-keys-status-dot api-keys-status-dot--active" title="Active"></i>'
                    : '<i class="fas fa-circle api-keys-status-dot api-keys-status-dot--disabled" title="Disabled"></i>';
                var toggleLabel = k.enabled ? 'Disable' : 'Enable';
                var toggleClass = k.enabled ? 'btn-secondary' : 'btn-primary';
                var enableFlag = k.enabled ? '0' : '1';
                var shortId = k.key_id.length > 14 ? k.key_id.slice(0, 13) + '…' : k.key_id;
                var permData = perms.join(',').toLowerCase();
                var searchHay = (k.name + ' ' + k.key_id).toLowerCase();

                return '' +
                    '<tr class="api-keys-row"' +
                        ' data-action="openApiKeyDetail"' +
                        ' data-key-id="' + escapeHtml(k.key_id) + '"' +
                        ' data-search="' + escapeHtml(searchHay) + '"' +
                        ' data-perms="' + escapeHtml(permData) + '"' +
                        ' data-status="' + (k.enabled ? 'active' : 'disabled') + '">' +
                        '<td class="col-api-keys-status text-center">' + statusDot + '</td>' +
                        '<td><div class="api-keys-label-truncate" title="' + escapeHtml(k.name) + '"><strong>' + escapeHtml(k.name) + '</strong></div></td>' +
                        '<td>' +
                            '<div class="api-keys-id-cell">' +
                                '<code title="' + escapeHtml(k.key_id) + '">' + escapeHtml(shortId) + '</code>' +
                                '<button type="button" class="api-keys-id-copy" title="Copy full key ID" ' +
                                    'data-action="copyApiKeyId" data-key-id="' + escapeHtml(k.key_id) + '" ' +
                                    'data-stop-propagation="true">' +
                                    '<i class="fas fa-copy"></i>' +
                                '</button>' +
                            '</div>' +
                        '</td>' +
                        '<td><div class="api-keys-perm-cell">' + permChips + '</div></td>' +
                        '<td class="text-right">' + (k.rate_limit_per_minute || 0) + '</td>' +
                        '<td>' + formatWhen(k.last_used_at) + '</td>' +
                        '<td>' + (k.expires_at ? formatWhen(k.expires_at) : '<span class="text-muted">Never</span>') + '</td>' +
                    '</tr>';
            }).join('');
        }

        function populatePermissionFilter(keys) {
            var sel = document.getElementById('api-keys-filter-permission');
            if (!sel) return;
            var prev = sel.value || 'all';
            var seen = {};
            keys.forEach(function(k) {
                (k.permissions || []).forEach(function(p) { seen[p] = true; });
            });
            var perms = Object.keys(seen).sort();
            sel.innerHTML = '<option value="all">All permissions</option>' +
                perms.map(function(p) {
                    return '<option value="' + escapeHtml(p) + '">' + escapeHtml(p) + '</option>';
                }).join('');
            // Preserve the user's selection across refreshes if it's still a valid option
            sel.value = (prev === 'all' || seen[prev]) ? prev : 'all';
        }

        function applyTableFilters() {
            var q      = (document.getElementById('api-keys-search') || {}).value || '';
            var perm   = (document.getElementById('api-keys-filter-permission') || {}).value || 'all';
            var status = (document.getElementById('api-keys-filter-status') || {}).value || 'all';
            q = q.trim().toLowerCase();

            var rows = document.querySelectorAll('#api-keys-tbody .api-keys-row');
            var visible = 0;
            rows.forEach(function(row) {
                var show = true;
                if (q && (row.getAttribute('data-search') || '').indexOf(q) === -1) show = false;
                if (show && perm !== 'all') {
                    var perms = (row.getAttribute('data-perms') || '').split(',');
                    if (perms.indexOf(perm.toLowerCase()) === -1) show = false;
                }
                if (show && status !== 'all' && row.getAttribute('data-status') !== status) show = false;
                row.classList.toggle('d-none', !show);
                if (show) visible++;
            });

            var noMatches = document.getElementById('api-keys-no-matches');
            if (noMatches) noMatches.classList.toggle('d-none', visible !== 0 || rows.length === 0);
        }

        function renderSortIndicators() {
            document.querySelectorAll('.api-keys-table thead th.sortable').forEach(function(th) {
                var col = th.getAttribute('data-sort-column');
                var icon = th.querySelector('.sort-indicator');
                th.classList.remove('sort-asc', 'sort-desc');
                if (icon) icon.className = 'fas fa-sort sort-indicator';
                if (col === sortState.column && sortState.direction) {
                    th.classList.add('sort-' + sortState.direction);
                    if (icon) {
                        icon.className = 'fas fa-sort-' + (sortState.direction === 'asc' ? 'up' : 'down') +
                                         ' sort-indicator';
                    }
                }
            });
        }

        function handleSort(column) {
            if (!column) return;
            if (sortState.column !== column) {
                sortState = { column: column, direction: 'asc' };
            } else if (sortState.direction === 'asc') {
                sortState.direction = 'desc';
            } else {
                sortState = { column: null, direction: null };  // third click clears
            }
            renderTableBody(sortedKeys());
            applyTableFilters();
            renderSortIndicators();
        }

        // --- Row-click combined detail + edit modal ---------------------
        // Populates read-only reference fields AND prefills the editable
        // inputs (label / rate limit / expiry) so the user can adjust in one
        // place. Shares pendingEdit + the existing submitEdit() flow.
        function openDetailModal(keyId) {
            var k = keysById[keyId];
            if (!k) {
                showToast('Error', 'Key details unavailable — refresh and try again', 'error');
                return;
            }
            detailKeyId = keyId;
            pendingEdit = keyId;  // enables the shared submitEdit()

            document.getElementById('apiKeysDetailLabel').textContent = k.name || '';
            document.getElementById('apiKeysDetailKeyId').textContent = k.key_id;
            document.getElementById('apiKeysDetailStatus').innerHTML = k.enabled
                ? '<span class="tx-status-badge tx-status-completed">Active</span>'
                : '<span class="tx-status-badge tx-status-rejected">Disabled</span>';
            document.getElementById('apiKeysDetailCreated').textContent = formatWhen(k.created_at);
            document.getElementById('apiKeysDetailLastUsed').textContent = formatWhen(k.last_used_at);

            // Editable fields — same IDs as the (now-retired) standalone edit
            // modal, so submitEdit() works unchanged
            var perms = k.permissions || [];
            var displayPerms = groupPermissionsForDisplay(perms);
            var permsEl = document.getElementById('apiKeysEditPermissions');
            if (permsEl) {
                permsEl.innerHTML = displayPerms.length
                    ? displayPerms.map(function(p) { return '<span class="perm-chip">' + escapeHtml(p) + '</span>'; }).join('')
                    : '<span class="text-muted">(none)</span>';
            }

            document.getElementById('apiKeyEditLabel').value = k.name || '';
            var rateInput = document.getElementById('apiKeyEditRateLimit');
            rateInput.value = k.rate_limit_per_minute || 100;
            rateInput.oninput = function() { updateRateLimitWarning(k.rate_limit_per_minute); };
            updateRateLimitWarning(k.rate_limit_per_minute);

            // Expires select — "Keep current" default, disable presets that would
            // extend a currently-finite expiry
            var daysLeft = daysUntil(k.expires_at);
            var select = document.getElementById('apiKeyEditExpires');
            select.innerHTML = '';
            var opts = [
                { v: '', label: k.expires_at ? 'Keep current (expires ' + formatWhen(k.expires_at) + ')' : 'Keep current (never expires)' },
                { v: '30',  label: '30 days from now' },
                { v: '90',  label: '90 days from now' },
                { v: '365', label: '1 year from now' }
            ];
            opts.forEach(function(o) {
                var opt = document.createElement('option');
                opt.value = o.v;
                opt.textContent = o.label;
                if (o.v !== '' && daysLeft !== null && Number(o.v) > daysLeft) {
                    opt.disabled = true;
                    opt.textContent += ' — would extend, blocked';
                }
                select.appendChild(opt);
            });
            select.value = '';

            var err = document.getElementById('apiKeysEditError');
            if (err) { err.classList.add('d-none'); err.textContent = ''; }

            // Footer toggle button — label + color reflect current state
            var toggleBtn = document.getElementById('apiKeysDetailToggleBtn');
            toggleBtn.innerHTML = k.enabled
                ? '<i class="fas fa-ban"></i> Disable'
                : '<i class="fas fa-play"></i> Enable';
            toggleBtn.className = 'btn btn-compact ' + (k.enabled ? 'btn-secondary' : 'btn-primary');
            toggleBtn.setAttribute('data-enable', k.enabled ? '0' : '1');

            showModal('apiKeysDetailModal');
        }

        function closeDetailModal() {
            detailKeyId = null;
            pendingEdit = null;
            hideModal('apiKeysDetailModal');
        }

        function detailToggle() {
            if (!detailKeyId) return;
            var k = keysById[detailKeyId];
            if (!k) return;
            var keyId = detailKeyId;
            var enable = !k.enabled;
            closeDetailModal();
            toggle(keyId, enable);
        }

        function detailDelete() {
            if (!detailKeyId) return;
            var k = keysById[detailKeyId];
            if (!k) return;
            var keyId = detailKeyId;
            var label = k.name || '';
            closeDetailModal();
            openDeleteModal(keyId, label);
        }

        function copyDetailKeyId() {
            if (detailKeyId) copyKeyId(detailKeyId);
        }

        function copyKeyId(keyId) {
            if (!keyId) return;
            var ok = function() { showToast('Copied', 'Key ID copied to clipboard', 'success'); };
            var fail = function() { showToast('Copy failed', 'Select and copy manually', 'error'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(keyId).then(ok, fail);
            } else {
                var ta = document.createElement('textarea');
                ta.value = keyId;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); ok(); } catch (e) { fail(); }
                document.body.removeChild(ta);
            }
        }

        function refresh() {
            return post({ action: 'apiKeysList' }).then(function(r) {
                if (r.data && r.data.success) {
                    renderList(r.data.keys || []);
                    renderAccessState(r.data.seconds_remaining || 0);
                    loaded = true;
                } else {
                    showToast('Error', (r.data && r.data.message) || 'Could not load API keys', 'error');
                }
            }).catch(function() {
                showToast('Error', 'Network error while loading API keys', 'error');
            });
        }

        function ensureLoadedOnTab() {
            // Lazy-load the list the first time the user switches to the
            // Settings tab, so we don't fire an AJAX call on every page load
            // for users who never open this section.
            var settingsPanel = document.getElementById('tab-panel-settings');
            if (!settingsPanel) return;
            var observer = new MutationObserver(function() {
                if (settingsPanel.style.display !== 'none' && !loaded) {
                    refresh();
                }
            });
            observer.observe(settingsPanel, { attributes: true, attributeFilter: ['style'] });
            // Handle initial render (tab already visible, e.g. deep link)
            if (settingsPanel.style.display !== 'none' && !loaded) {
                refresh();
            }
        }

        function showModal(id) {
            var el = document.getElementById(id);
            if (el) el.classList.remove('d-none');
        }
        function hideModal(id) {
            var el = document.getElementById(id);
            if (el) el.classList.add('d-none');
        }

        // --- Create -------------------------------------------------------
        function openCreateModal() {
            var form = document.getElementById('apiKeysCreateForm');
            if (form) form.reset();
            var err = document.getElementById('apiKeysCreateError');
            if (err) { err.classList.add('d-none'); err.textContent = ''; }
            showModal('apiKeysCreateModal');
        }

        function closeCreateModal() { hideModal('apiKeysCreateModal'); }

        function applyPreset(preset) {
            var boxes = document.querySelectorAll('#apiKeysCreateForm input[name="permissions[]"]');
            if (preset === 'clear') {
                boxes.forEach(function(b) { b.checked = false; });
                return;
            }
            var presets = {
                'read_only': ['wallet:read', 'contacts:read', 'system:read', 'backup:read'],
                'full_access': ['admin']
            };
            var target = presets[preset] || [];
            boxes.forEach(function(b) { b.checked = target.indexOf(b.value) !== -1; });
        }

        function collectPermissions() {
            var out = [];
            document.querySelectorAll('#apiKeysCreateForm input[name="permissions[]"]:checked').forEach(function(b) {
                out.push(b.value);
            });
            return out;
        }

        function submitCreate() {
            var name = document.getElementById('apiKeyName').value.trim();
            var permissions = collectPermissions();
            var rate = document.getElementById('apiKeyRateLimit').value;
            var expires = document.getElementById('apiKeyExpires').value;
            var err = document.getElementById('apiKeysCreateError');

            if (!name) { showCreateError('Please give the key a label.'); return; }
            if (permissions.length === 0) { showCreateError('Select at least one permission.'); return; }

            withSensitiveAccess(function() {
                return post({
                    action: 'apiKeysCreate',
                    name: name,
                    permissions: permissions,
                    rate_limit_per_minute: rate,
                    expires_in_days: expires
                });
            }, function(r) {
                if (r.data && r.data.success) {
                    closeCreateModal();
                    showRevealModal(r.data.key);
                    refresh();
                } else {
                    showCreateError((r.data && (r.data.message || r.data.error)) || 'Could not create key');
                }
            }, 'Create API key');
        }

        function showCreateError(msg) {
            var err = document.getElementById('apiKeysCreateError');
            if (err) { err.textContent = msg; err.classList.remove('d-none'); }
        }

        // --- Reveal (one-time) -------------------------------------------
        function showRevealModal(key) {
            lastCreated = { keyId: key.key_id, secret: key.secret };
            document.getElementById('apiKeysRevealKeyId').textContent = key.key_id;
            document.getElementById('apiKeysRevealSecret').textContent = key.secret;
            var ack = document.getElementById('apiKeysRevealAck');
            var err = document.getElementById('apiKeysRevealAckError');
            ack.checked = false;
            if (err) err.classList.add('d-none');
            ack.onchange = function() {
                if (ack.checked && err) err.classList.add('d-none');
            };
            showModal('apiKeysRevealModal');
        }

        function closeRevealModal() {
            var ack = document.getElementById('apiKeysRevealAck');
            var err = document.getElementById('apiKeysRevealAckError');
            if (ack && !ack.checked) {
                if (err) err.classList.remove('d-none');
                return;
            }
            // Clear the secret from memory and from the DOM the moment the
            // user closes. The server has no way to re-show this.
            document.getElementById('apiKeysRevealSecret').textContent = '';
            document.getElementById('apiKeysRevealKeyId').textContent = '';
            lastCreated = null;
            hideModal('apiKeysRevealModal');
        }

        function copyToClipboard(targetId) {
            var el = document.getElementById(targetId);
            if (!el) return;
            var text = el.textContent || '';
            if (!text) return;
            var ok = function() { showToast('Copied', 'Copied to clipboard', 'success'); };
            var fail = function() { showToast('Copy failed', 'Select and copy manually', 'error'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(ok, fail);
            } else {
                // Fallback — works over non-HTTPS and older browsers.
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); ok(); } catch (e) { fail(); }
                document.body.removeChild(ta);
            }
        }

        // --- Toggle / delete ---------------------------------------------
        function toggle(keyId, enable) {
            withSensitiveAccess(function() {
                return post({ action: 'apiKeysToggle', key_id: keyId, enable: enable ? '1' : '0' });
            }, function(r) {
                if (r.data && r.data.success) {
                    showToast('Saved', enable ? 'API key enabled' : 'API key disabled', 'success');
                    refresh();
                } else {
                    showToast('Error', (r.data && r.data.message) || 'Could not update key', 'error');
                }
            }, enable ? 'Enable API key' : 'Disable API key');
        }

        function openDeleteModal(keyId, label) {
            pendingDelete = { keyId: keyId, label: label };
            document.getElementById('apiKeysDeleteLabel').textContent = label;
            var confirmInput = document.getElementById('apiKeysDeleteConfirm');
            var submitBtn = document.getElementById('apiKeysDeleteSubmit');
            confirmInput.value = '';
            submitBtn.disabled = true;
            confirmInput.oninput = function() {
                submitBtn.disabled = confirmInput.value.trim() !== label;
            };
            showModal('apiKeysDeleteModal');
        }

        function closeDeleteModal() {
            pendingDelete = null;
            hideModal('apiKeysDeleteModal');
        }

        function submitDelete() {
            if (!pendingDelete) return;
            var keyId = pendingDelete.keyId;
            withSensitiveAccess(function() {
                return post({ action: 'apiKeysDelete', key_id: keyId });
            }, function(r) {
                if (r.data && r.data.success) {
                    showToast('Deleted', 'API key deleted', 'success');
                    closeDeleteModal();
                    refresh();
                } else {
                    showToast('Error', (r.data && r.data.message) || 'Could not delete key', 'error');
                }
            }, 'Delete API key');
        }

        // --- Edit ---------------------------------------------------------
        // Compute how many days remain on the key's current expiry so we
        // can disable any preset option in the dropdown that would EXTEND
        // it. The backend enforces this too; the UI hides extensions so
        // the user doesn't pick an option that just errors.
        function daysUntil(isoOrSqlTimestamp) {
            if (!isoOrSqlTimestamp) return null;
            var d = new Date(String(isoOrSqlTimestamp).replace(' ', 'T') + 'Z');
            if (isNaN(d.getTime())) return null;
            return Math.max(0, Math.floor((d.getTime() - Date.now()) / 86400000));
        }

        function updateRateLimitWarning(original) {
            var input = document.getElementById('apiKeyEditRateLimit');
            var warn  = document.getElementById('apiKeysEditRateLimitWarning');
            if (!input || !warn) return;
            var current = Number(input.value) || 0;
            var was     = Number(original) || 0;
            if (current > was) {
                warn.textContent = 'Raising rate limit from ' + was + ' to ' + current +
                    ' — existing callers can now send more traffic. Confirm this is intended.';
                warn.classList.remove('d-none');
            } else {
                warn.classList.add('d-none');
            }
        }

        function submitEdit() {
            if (!pendingEdit) {
                showToast('Error', 'Edit session lost — reopen the Edit dialog', 'error');
                return;
            }
            var keyId = pendingEdit;
            var original = keysById[keyId] || {};

            // Clear any stale error from a prior click so the user sees the
            // result of THIS attempt rather than yesterday's "Nothing changed."
            var err = document.getElementById('apiKeysEditError');
            if (err) { err.classList.add('d-none'); err.textContent = ''; }

            var name = document.getElementById('apiKeyEditLabel').value.trim();
            var rate = document.getElementById('apiKeyEditRateLimit').value;
            var expiresDays = document.getElementById('apiKeyEditExpires').value;

            if (!name) { showEditError('Please give the key a label.'); return; }

            var payload = { action: 'apiKeysUpdate', key_id: keyId };
            var changed = false;
            if (name !== original.name) { payload.name = name; changed = true; }
            if (String(rate) !== String(original.rate_limit_per_minute)) {
                payload.rate_limit_per_minute = rate;
                changed = true;
            }
            if (expiresDays !== '') {
                payload.expires_in_days = expiresDays;
                changed = true;
            }

            if (!changed) {
                showEditError('Nothing changed.');
                return;
            }

            withSensitiveAccess(function() {
                return post(payload);
            }, function(r) {
                if (r.data && r.data.success) {
                    showToast('Saved', 'API key updated', 'success');
                    closeDetailModal();
                    refresh();
                } else {
                    showEditError((r.data && (r.data.message || r.data.error)) || 'Could not update key');
                }
            }, 'Edit API key');
        }

        function showEditError(msg) {
            var err = document.getElementById('apiKeysEditError');
            if (err) { err.textContent = msg; err.classList.remove('d-none'); }
        }

        // --- Bulk Disable / Delete ---------------------------------------
        function openDisableAllModal() {
            var active = Object.values(keysById).filter(function(k) { return k.enabled; }).length;
            if (active === 0) {
                showToast('Nothing to do', 'No active keys to disable', 'info');
                return;
            }
            document.getElementById('apiKeysDisableAllCount').textContent = active;
            showModal('apiKeysDisableAllModal');
        }
        function closeDisableAllModal() { hideModal('apiKeysDisableAllModal'); }
        function submitDisableAll() {
            withSensitiveAccess(function() {
                return post({ action: 'apiKeysDisableAll' });
            }, function(r) {
                if (r.data && r.data.success) {
                    showToast('Disabled', 'Disabled ' + (r.data.count || 0) + ' key(s)', 'success');
                    closeDisableAllModal();
                    refresh();
                } else {
                    showToast('Error', (r.data && r.data.message) || 'Could not disable all keys', 'error');
                }
            }, 'Disable all API keys');
        }

        function openDeleteAllModal() {
            var total = Object.keys(keysById).length;
            if (total === 0) {
                showToast('Nothing to do', 'No keys to delete', 'info');
                return;
            }
            document.getElementById('apiKeysDeleteAllCount').textContent = total;
            var input = document.getElementById('apiKeysDeleteAllConfirm');
            var btn   = document.getElementById('apiKeysDeleteAllSubmit');
            input.value = '';
            btn.disabled = true;
            input.oninput = function() {
                btn.disabled = input.value.trim().toLowerCase() !== 'delete all';
            };
            showModal('apiKeysDeleteAllModal');
        }
        function closeDeleteAllModal() { hideModal('apiKeysDeleteAllModal'); }
        function submitDeleteAll() {
            withSensitiveAccess(function() {
                return post({ action: 'apiKeysDeleteAll' });
            }, function(r) {
                if (r.data && r.data.success) {
                    showToast('Deleted', 'Deleted ' + (r.data.count || 0) + ' key(s)', 'success');
                    closeDeleteAllModal();
                    refresh();
                } else {
                    showToast('Error', (r.data && r.data.message) || 'Could not delete all keys', 'error');
                }
            }, 'Delete all API keys');
        }

        // --- Sensitive-access gate ---------------------------------------
        // On 401 sensitive_access_required, opens the verify modal and
        // retries `requestFn` after the user re-auths — only then calls onResponse.
        // A rejected request (network / JSON parse / server returning HTML for an
        // unrouted action) surfaces as a toast instead of dying silently.
        function withSensitiveAccess(requestFn, onResponse, label) {
            var attempt = function() {
                return requestFn().then(function(r) {
                    if (r && r.status === 401 && r.data && r.data.error === 'sensitive_access_required') {
                        pendingAction = { fn: attempt, label: label };
                        openVerifyModal(r.data.message);
                        return;
                    }
                    if (typeof onResponse === 'function') onResponse(r);
                    return r;
                }).catch(function(e) {
                    showToast('Error', (label || 'Request') + ' failed — ' + (e && e.message ? e.message : 'network / server error'), 'error');
                });
            };
            return attempt();
        }

        function openVerifyModal(message) {
            var input = document.getElementById('apiKeysVerifyAuthcode');
            var err = document.getElementById('apiKeysVerifyError');
            input.value = '';
            if (err) { err.classList.add('d-none'); err.textContent = ''; }
            showModal('apiKeysVerifyModal');
            setTimeout(function() { input.focus(); }, 50);
        }

        function closeVerifyModal() {
            pendingAction = null;
            hideModal('apiKeysVerifyModal');
        }

        function submitVerify() {
            var input = document.getElementById('apiKeysVerifyAuthcode');
            var err = document.getElementById('apiKeysVerifyError');
            var code = input.value;
            if (!code) { return; }

            post({ action: 'apiKeysVerify', authcode: code }).then(function(r) {
                if (r.data && r.data.success) {
                    hideModal('apiKeysVerifyModal');
                    input.value = '';
                    renderAccessState(r.data.seconds_remaining || 0);
                    if (pendingAction) {
                        var fn = pendingAction.fn;
                        pendingAction = null;
                        fn();
                    }
                } else {
                    if (err) {
                        err.textContent = (r.data && r.data.error === 'invalid_authcode')
                            ? 'Invalid auth code. Please try again.'
                            : 'Could not verify. Please try again.';
                        err.classList.remove('d-none');
                    }
                }
            }).catch(function() {
                if (err) {
                    err.textContent = 'Network error. Please try again.';
                    err.classList.remove('d-none');
                }
            });
        }

        // --- Init ---------------------------------------------------------
        document.addEventListener('DOMContentLoaded', ensureLoadedOnTab);

        return {
            refresh: refresh,
            openCreateModal: openCreateModal,
            closeCreateModal: closeCreateModal,
            submitCreate: submitCreate,
            applyPreset: applyPreset,
            toggle: toggle,
            openDeleteModal: openDeleteModal,
            closeDeleteModal: closeDeleteModal,
            submitDelete: submitDelete,
            submitEdit: submitEdit,
            openDisableAllModal: openDisableAllModal,
            closeDisableAllModal: closeDisableAllModal,
            submitDisableAll: submitDisableAll,
            openDeleteAllModal: openDeleteAllModal,
            closeDeleteAllModal: closeDeleteAllModal,
            submitDeleteAll: submitDeleteAll,
            sort: handleSort,
            applyFilters: applyTableFilters,
            copyKeyId: copyKeyId,
            openDetailModal: openDetailModal,
            closeDetailModal: closeDetailModal,
            detailToggle: detailToggle,
            detailDelete: detailDelete,
            copyDetailKeyId: copyDetailKeyId,
            closeRevealModal: closeRevealModal,
            copyToClipboard: copyToClipboard,
            closeVerifyModal: closeVerifyModal,
            submitVerify: submitVerify
        };
    })();

})();
