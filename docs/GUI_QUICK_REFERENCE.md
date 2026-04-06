# eIOU GUI Quick Reference

Quick lookup card for the eIOU Wallet web interface.

---

## Entry Points

| URL | File | Purpose |
|-----|------|---------|
| `/` | redirects to `/gui/` | Main wallet interface (auth + dashboard) |
| `/gui/` | `gui/index.html` | Main wallet interface (auth + dashboard) |
| `/gui/?logout` | `gui/index.html` | Logout and clear session |

---

## File Locations

| Component | Inside Container | Outside Container |
|-----------|------------------|-------------------|
| Entry point | `/app/eiou/www/gui/index.html` | `files/root/www/gui/index.html` |
| GUI source | `/app/eiou/src/gui/` | `files/src/gui/` |
| Controllers | `/app/eiou/src/gui/controllers/` | `files/src/gui/controllers/` |
| Helpers | `/app/eiou/src/gui/helpers/` | `files/src/gui/helpers/` |
| Layout | `/app/eiou/src/gui/layout/` | `files/src/gui/layout/` |
| JavaScript | `/app/eiou/src/gui/assets/js/script.js` | `files/src/gui/assets/js/script.js` |
| CSS | `/app/eiou/src/gui/assets/css/page.css` | `files/src/gui/assets/css/page.css` |
| User config | `/etc/eiou/config/defaultconfig.json` | N/A (generated at runtime) |

---

## Controllers

| Controller | File | Actions | Purpose |
|------------|------|---------|---------|
| `ContactController` | `controllers/ContactController.php` | add, accept, delete, block, unblock, edit, ping, proposeChainDrop, acceptChainDrop, rejectChainDrop, acceptCurrency, addCurrency, acceptAllCurrencies | Contact management |
| `TransactionController` | `controllers/TransactionController.php` | sendEIOU, checkUpdates, approveP2pTransaction, rejectP2pTransaction, getP2pCandidates | Sending transactions & P2P approval |
| `SettingsController` | `controllers/SettingsController.php` | updateSettings, clearDebugLogs, sendDebugReport, getDebugReportJson, analyticsConsent | User settings, debug & analytics |

---

## POST Actions

All POST actions require CSRF token.

### Contact Actions (ContactController)

| Action | Required Fields | Response |
|--------|-----------------|----------|
| `addContact` | `address`, `name`, `fee`, `credit`, `currency` | Redirect with message |
| `acceptContact` | `contact_address`, `contact_name`, `contact_fee`, `contact_credit`, `contact_currency` | Redirect with message |
| `deleteContact` | `contact_address` | Redirect with message |
| `blockContact` | `contact_address` | Redirect with message |
| `unblockContact` | `contact_address` | Redirect with message |
| `editContact` | `contact_address`, `contact_name`, `contact_fee`, `contact_credit`, `contact_currency` | Redirect with message |
| `pingContact` | `contact_address` | JSON (AJAX) |
| `proposeChainDrop` | `contact_pubkey_hash` | JSON (AJAX) |
| `acceptChainDrop` | `proposal_id` | JSON (AJAX) |
| `rejectChainDrop` | `proposal_id` | JSON (AJAX) |
| `acceptCurrency` | `pubkey_hash`, `currency`, `fee`, `credit` | Redirect with message |
| `addCurrency` | `pubkey`, `currency`, `fee`, `credit` | JSON (AJAX) |
| `acceptAllCurrencies` | `pubkey_hash`, `currencies` (JSON), `is_new_contact`, `contact_address`, `contact_name` | JSON (AJAX) |

### Transaction Actions (TransactionController)

| Action | Required Fields | Response |
|--------|-----------------|----------|
| `sendEIOU` | `recipient` OR `manual_recipient`, `amount`, `currency` | Redirect with message |
| `approveP2pTransaction` | `hash` | JSON (AJAX) |
| `rejectP2pTransaction` | `hash` | JSON (AJAX) |

Optional: `address_type` (when contact selected), `description`, `best_fee` (experimental best-fee routing)

| `getP2pCandidates` | `hash` | JSON (AJAX) |

### Settings Actions (SettingsController)

| Action | Required Fields | Response |
|--------|-----------------|----------|
| `updateSettings` | Any of: `defaultCurrency`, `defaultFee`, `minFee`, `maxFee`, `defaultCreditLimit`, `maxP2pLevel`, `p2pExpiration`, `maxOutput`, `defaultTransportMode`, `autoRefreshEnabled`, `autoBackupEnabled`, `autoAcceptTransaction`, plus advanced settings | Redirect with message |
| `clearDebugLogs` | (none) | Redirect with message |
| `sendDebugReport` | `description` (optional) | Redirect with message |
| `getDebugReportJson` | `description` (optional), `report_mode` (full/limited) | JSON (AJAX) |
| `analyticsConsent` | `consent` (0 or 1) | JSON (AJAX) |

### DLQ Actions (DlqController)

| Action | Required Fields | Response |
|--------|-----------------|----------|
| `dlqRetry` | `dlq_id`, `csrf_token` | JSON (AJAX) |
| `dlqAbandon` | `dlq_id`, `csrf_token` | JSON (AJAX) |
| `dlqRetryAll` | `csrf_token` | JSON (AJAX) |
| `dlqAbandonAll` | `csrf_token` | JSON (AJAX) |

---

## Layout Components

| Component | File | Tab | Purpose |
|-----------|------|-----|---------|
| Main layout | `layout/wallet.html` | — | Page structure, tab bar, includes all sections |
| Auth form | `layout/authenticationForm.html` | — | Login form |
| Banner | `layout/walletSubParts/banner.html` | — | Dynamic image banner carousel from `/gui/assets/banners/` |
| Header | `layout/walletSubParts/header.html` | — | Page header |
| Notifications | `layout/walletSubParts/notifications.html` | — | Toast/alert messages, pending contact/currency banners, Tor status, update available, chain drop proposal banners |
| Wallet info | `layout/walletSubParts/walletInformation.html` | Dashboard | Balance (blue), fee earnings (amber/gold), available credit (blue-purple); ⓘ icons open info modal on tap |
| Send form | `layout/walletSubParts/eiouForm.html` | Send | Send transaction form |
| Contact form | `layout/walletSubParts/contactForm.html` | Contacts | Add contact form |
| Contact section | `layout/walletSubParts/contactSection.html` | Contacts | Contact lists, modal with your/their credit |
| Transaction history | `layout/walletSubParts/transactionHistory.html` | Activity | Transaction list |
| DLQ section | `layout/walletSubParts/dlqSection.html` | Activity | Dead letter queue management |
| Settings | `layout/walletSubParts/settingsSection.html` | Settings | Settings form |
| Debug | `layout/walletSubParts/debugSection.html` | Settings | Debug logs & system info (below settings form) |
| Quick actions | `layout/walletSubParts/quickActions.html` | *(unused)* | Retained but not rendered — navigation is tab-based |
| Floating buttons | `layout/walletSubParts/floatingButtons.html` | — | Back-to-top, etc. |
| Analytics consent | `layout/walletSubParts/analyticsConsentModal.html` | — | One-time analytics opt-in modal |

---

## Security Requirements

### CSRF Token (Required for all POST)

```html
<!-- In form -->
<input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

<!-- Or use helper -->
<?php echo $secureSession->getCSRFField(); ?>
```

### JavaScript AJAX with CSRF

```javascript
fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=pingContact&contact_address=' + encodeURIComponent(address) +
          '&csrf_token=' + encodeURIComponent(csrfToken)
});
```

---

## JavaScript Key Functions

| Function | Purpose | Usage |
|----------|---------|-------|
| `refreshWalletData()` | Manual page refresh | Refresh button |
| `initializeSendForm()` | Setup send form (auto-called) | DOMContentLoaded |
| `openEditContactModal(address, name, fee, credit, currency)` | Open edit modal | Contact edit button |
| `closeEditContactModal()` | Close edit modal | Modal close button |
| `openTransactionModal(index)` | Show transaction details | Transaction list item |
| `escapeHtml(text)` | XSS prevention | Sanitize user input |
| `openContactModal(contact)` | Show contact detail modal | Contact card click |
| `openContactByContactId(id, tab)` | Open modal by contact ID | Notification banner |
| `pingContact()` | Check status + reload page | Contact modal button |
| `proposeChainDrop()` | Propose dropping missing tx | Chain drop section |
| `acceptChainDrop()` | Accept incoming proposal | Chain drop section |
| `rejectChainDrop()` | Reject incoming proposal | Chain drop section |
| `reloadAndReopenContactModal()` | Reload page, reopen modal | After AJAX actions |
| `safeStorageSet(key, value)` | Tor-safe sessionStorage | Store preferences |
| `safeStorageGet(key)` | Tor-safe sessionStorage | Retrieve preferences |
| `safeStorageRemove(key)` | Tor-safe sessionStorage | Clear preferences |
| `copyToClipboard(text, button)` | Copy text to clipboard | Address/key copy buttons |
| `filterContacts(query)` | Filter contact cards by name | Contact search input |
| `toggleShowAllContacts()` | Toggle between 16 and all contacts | Show more button |
| `initContactsDisplay()` | Initialize contact grid with scroll/filter | DOMContentLoaded |
| `updateAmountPrecisionHint()` | Show min amount hint for selected currency | Send form currency change |
| `initializeCurrencyAcceptHandlers()` | Set up accept forms for pending currencies | DOMContentLoaded |
| `showInfoModal(el)` | Show tap-friendly info modal from an element's `title` attribute | ⓘ icon click |
| `showToast(message, type, duration)` | Display a temporary toast notification | Internal notifications |

### Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `OPERATION_TIMEOUT_MS` | 15000 | Stalled operation detection |
| `storageAvailable` | boolean | Tor Browser storage check |

---

## Session Settings

| Setting | Session Key | Expiry |
|---------|-------------|--------|
| Authentication | `$_SESSION['authenticated']` | 30 min inactivity |
| CSRF token | `$_SESSION['csrf_token']` | 1 hour |
| Session regeneration | `$_SESSION['last_regeneration']` | 5 min |
| In-progress txids | `$_SESSION['in_progress_txids']` | Session lifetime |
| Known txids | `$_SESSION['known_txids']` | Session lifetime |
| DLQ tracking | `$_SESSION['known_dlq_ids']` | Session lifetime |

### Session Cookie

| Parameter | Value |
|-----------|-------|
| Name | `EIOU_WALLET_SESSION` |
| HttpOnly | `true` |
| SameSite | `Strict` |
| Secure | Auto (HTTPS only when available) |

---

## Helpers

| Helper | File | Purpose |
|--------|------|---------|
| `ViewHelper` | `helpers/ViewHelper.php` | HTML sanitization, formatting |
| `MessageHelper` | `helpers/MessageHelper.php` | Flash messages, CLI output parsing |
| `ContactDataBuilder` | `helpers/ContactDataBuilder.php` | Contact data formatting |
| `Session` | `includes/Session.php` | Auth, CSRF, session management |

---

## Request Flow

```
1. index.html
   -> Session check
   -> Auth form (if not authenticated)
   -> CSRF verification (POST only)
   -> Functions.php (route POST actions)
   -> wallet.html (render view)
```

---

## See Also

- [API Quick Reference](API_QUICK_REFERENCE.md) - REST API endpoints
- [Docker GUI Development](DOCKER_GUI_DEVELOPMENT.md) - Development setup
- [CLI Reference](CLI_REFERENCE.md) - Command-line interface
