# EIOU GUI Quick Reference

Quick lookup card for the EIOU Wallet web interface.

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
| Entry point | `/etc/eiou/www/gui/index.html` | `files/root/www/gui/index.html` |
| GUI source | `/etc/eiou/src/gui/` | `files/src/gui/` |
| Controllers | `/etc/eiou/src/gui/controllers/` | `files/src/gui/controllers/` |
| Helpers | `/etc/eiou/src/gui/helpers/` | `files/src/gui/helpers/` |
| Layout | `/etc/eiou/src/gui/layout/` | `files/src/gui/layout/` |
| JavaScript | `/etc/eiou/src/gui/assets/js/script.js` | `files/src/gui/assets/js/script.js` |
| CSS | `/etc/eiou/src/gui/assets/css/page.css` | `files/src/gui/assets/css/page.css` |
| User config | `/etc/eiou/defaultconfig.json` | N/A (generated at runtime) |

---

## Controllers

| Controller | File | Actions | Purpose |
|------------|------|---------|---------|
| `ContactController` | `controllers/ContactController.php` | add, accept, delete, block, unblock, edit, ping | Contact management |
| `TransactionController` | `controllers/TransactionController.php` | sendEIOU, checkUpdates | Sending transactions |
| `SettingsController` | `controllers/SettingsController.php` | updateSettings, clearDebugLogs, sendDebugReport, getDebugReportJson | User settings & debug |

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

### Transaction Actions (TransactionController)

| Action | Required Fields | Response |
|--------|-----------------|----------|
| `sendEIOU` | `recipient` OR `manual_recipient`, `amount`, `currency` | Redirect with message |

Optional: `address_type` (when contact selected), `description`

### Settings Actions (SettingsController)

| Action | Required Fields | Response |
|--------|-----------------|----------|
| `updateSettings` | Any of: `defaultCurrency`, `defaultFee`, `minFee`, `maxFee`, `defaultCreditLimit`, `maxP2pLevel`, `p2pExpiration`, `maxOutput`, `defaultTransportMode`, `autoRefreshEnabled`, `autoBackupEnabled` | Redirect with message |
| `clearDebugLogs` | (none) | Redirect with message |
| `sendDebugReport` | `description` (optional) | Redirect with message |
| `getDebugReportJson` | `description` (optional), `report_mode` (full/limited) | JSON (AJAX) |

---

## Layout Components

| Component | File | Purpose |
|-----------|------|---------|
| Main layout | `layout/wallet.html` | Page structure, includes all sections |
| Auth form | `layout/authenticationForm.html` | Login form |
| Header | `layout/walletSubParts/header.html` | Page header |
| Notifications | `layout/walletSubParts/notifications.html` | Toast/alert messages |
| Quick actions | `layout/walletSubParts/quickActions.html` | Action buttons |
| Wallet info | `layout/walletSubParts/walletInformation.html` | Balance, earnings display |
| Send form | `layout/walletSubParts/eiouForm.html` | Send transaction form |
| Contact form | `layout/walletSubParts/contactForm.html` | Add contact form |
| Contact section | `layout/walletSubParts/contactSection.html` | Contact lists (pending, accepted, blocked) |
| Transaction history | `layout/walletSubParts/transactionHistory.html` | Transaction list |
| Settings | `layout/walletSubParts/settingsSection.html` | Settings panel & debug tools |
| Floating buttons | `layout/walletSubParts/floatingButtons.html` | Back-to-top, etc. |

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
| `safeStorageSet(key, value)` | Tor-safe sessionStorage | Store preferences |
| `safeStorageGet(key)` | Tor-safe sessionStorage | Retrieve preferences |
| `safeStorageRemove(key)` | Tor-safe sessionStorage | Clear preferences |

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
