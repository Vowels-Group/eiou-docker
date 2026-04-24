# eIOU GUI Reference

Complete documentation for the eIOU Docker node web-based GUI.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Directory Structure](#directory-structure)
3. [Controllers](#controllers)
4. [Layout Components](#layout-components)
5. [Session Management](#session-management)
6. [Security Features](#security-features)
7. [Helpers](#helpers)
8. [Known Limitations](#known-limitations)
9. [Development Setup](#development-setup)
10. [See Also](#see-also)

---

## Architecture Overview

The eIOU GUI is a server-rendered PHP web application optimized for Tor Browser compatibility. It follows an MVC-inspired pattern with controllers handling POST requests and HTML templates rendering the view.

### Request Flow

```
Browser Request
       |
       v
   index.html (Entry Point)
       |
       +-- Authentication check (Session.php)
       |       |
       |       +-- Not authenticated --> authenticationForm.html
       |       |
       |       +-- Authenticated --> Continue
       |
       +-- Functions.php (Central Router)
       |       |
       |       +-- POST Request? --> Route to Controller
       |       |       |
       |       |       +-- ContactController
       |       |       +-- TransactionController
       |       |       +-- SettingsController
       |       |
       |       +-- Initialize View Data
       |               |
       |               +-- Balances, Transactions
       |               +-- Contacts (all states)
       |               +-- Address Types
       |               +-- Notification Tracking
       |
       +-- wallet.html (Main Layout)
               |
               +-- Include walletSubParts/*.html
               |
               v
          Rendered HTML Response
```

### Key Characteristics

| Characteristic | Description |
|----------------|-------------|
| Rendering | Server-side PHP with embedded HTML |
| JavaScript | Minimal client-side JS for UX enhancements |
| Compatibility | Optimized for Tor Browser (no WebSockets, limited JS) |
| State Management | Session-based with CSRF protection |
| Styling | Inline CSS via `page.css` include |
| Forms | Traditional POST form submissions with redirect |
| AJAX | Limited use for specific features (ping, tx drop, debug report) |

---

## Directory Structure

```
files/src/gui/
├── controllers/                    # Request handlers
│   ├── ContactController.php       # Contact CRUD operations
│   ├── TransactionController.php   # Transaction processing
│   ├── SettingsController.php      # Settings and debug operations
│   ├── PaybackMethodsController.php # Payback-methods CRUD + reveal gate
│   └── DlqController.php           # Dead letter queue retry/abandon (AJAX)
│
├── functions/
│   └── Functions.php               # Central router and view data initializer
│
├── helpers/
│   ├── MessageHelper.php           # Message formatting and parsing
│   ├── ViewHelper.php              # HTML rendering utilities
│   └── ContactDataBuilder.php      # Contact data structure builder
│
├── includes/
│   └── Session.php                 # Session management and security
│
├── layout/
│   ├── authenticationForm.html     # Login page
│   ├── wallet.html                 # Main wallet layout (includes subparts)
│   └── walletSubParts/             # Modular UI components
│       ├── banner.html              # Dynamic image banner carousel
│       ├── header.html              # Page header with logout
│       ├── notifications.html       # Toast and banner notifications
│       ├── quickActions.html        # Quick action cards
│       ├── walletInformation.html   # Balance and address display
│       ├── eiouForm.html            # Send transaction form
│       ├── contactForm.html         # Add contact form
│       ├── contactSection.html      # Contact list and modal
│       ├── transactionHistory.html  # Transaction list and modal
│       ├── dlqSection.html          # Dead letter queue management
│       ├── paybackMethodsSection.html # Payback methods dashboard section (list + "How payback methods work" intro)
│       ├── paybackMethodForm.html   # Two-step Add / Edit / View modal (type picker → per-type fields)
│       ├── settingsSection.html     # Settings form and debug panel
│       ├── floatingButtons.html     # Back-to-top and refresh buttons
│       └── analyticsConsentModal.html # One-time analytics opt-in modal
│   (wallet.html also contains inline modal definitions for Transaction Details and What's New)
│
└── assets/
    ├── css/
    │   ├── page.css                # Main wallet styles
    │   └── authentication-form.css # Login page styles
    ├── js/
    │   └── script.js               # Client-side JavaScript
    └── fontawesome/                # Icon library
```

---

## Controllers

Controllers handle POST requests routed through `Functions.php`. All controllers follow a consistent pattern:
1. Verify CSRF token
2. Validate and sanitize input
3. Execute operation via service layer
4. Redirect with message

### ContactController

Handles all contact-related operations.

| Method | Action Value | Description | Parameters |
|--------|-------------|-------------|------------|
| `handleAddContact()` | `addContact` | Add new contact | `address`, `name`, `fee`, `credit`, `currency`, `description` (optional) |
| `handleAcceptContact()` | `acceptContact` | Accept pending request | `contact_address`, `contact_name`, `contact_fee`, `contact_credit`, `contact_currency` |
| `handleDeleteContact()` | `deleteContact` | Delete contact | `contact_address` |
| `handleBlockContact()` | `blockContact` | Block contact | `contact_address` |
| `handleUnblockContact()` | `unblockContact` | Unblock contact | `contact_address` |
| `handleEditContact()` | `editContact` | Update contact settings | `contact_address`, `contact_name`, `contact_fee`, `contact_credit`, `contact_currency` |
| `handlePingContact()` | `pingContact` | Check contact status (AJAX) | `contact_address` |
| `handleProposeChainDrop()` | `proposeChainDrop` | Propose dropping missing tx (AJAX) | `contact_pubkey_hash` |
| `handleAcceptChainDrop()` | `acceptChainDrop` | Accept tx drop proposal (AJAX) | `proposal_id` |
| `handleRejectChainDrop()` | `rejectChainDrop` | Reject tx drop proposal (AJAX) | `proposal_id` |
| `handleAcceptCurrency()` | `acceptCurrency` | Accept pending incoming currency | `pubkey_hash`, `currency`, `fee`, `credit` |
| `handleAddCurrency()` | `addCurrency` | Add a new currency to an existing contact (AJAX) | `pubkey`, `currency`, `fee`, `credit` |
| `handleAcceptAllCurrencies()` | `acceptAllCurrencies` | Accept all pending currencies for a contact (AJAX) | `pubkey_hash`, `currencies` (JSON array), `is_new_contact`, `contact_address`, `contact_name` |

**AJAX Response Format (pingContact):**

```json
{
    "success": true,
    "contact_name": "Bob",
    "online_status": "online",
    "chain_valid": true,
    "message": "Ping complete"
}
```

Internally, the ping/pong protocol exchanges per-currency data: `prevTxidsByCurrency` (chain heads per currency), `chainStatusByCurrency` (per-currency chain validity), and `availableCreditByCurrency` (per-currency available credit). The AJAX response aggregates chain validity into a single `chain_valid` boolean. Per-currency available credit is stored in the `contact_credit` table and displayed in the contact modal.

---

### TransactionController

Handles transaction operations.

| Method | Action Value | Description | Parameters |
|--------|-------------|-------------|------------|
| `handleSendEIOU()` | `sendEIOU` | Send eIOU transaction | `recipient` or `manual_recipient`, `address_type`, `amount`, `currency`, `description` |
| `handleCheckUpdates()` | GET `check_updates=1` | Poll for updates | `last_check` (timestamp) |
| `handleGetP2pCandidates()` | `getP2pCandidates` | Fetch P2P route candidates for best-fee approval (AJAX) | `hash` |

**Recipient Resolution:**
1. If `manual_recipient` is provided, use as-is (P2P routing)
2. If `recipient` + `address_type`, look up specific address
3. Fallback to `recipient` name (backend resolution)

---

### SettingsController

Handles settings and debug operations.

| Method | Action Value | Description | Parameters |
|--------|-------------|-------------|------------|
| `handleUpdateSettings()` | `updateSettings` | Save wallet settings | Multiple settings fields |
| `handleResetToDefaults()` | `resetToDefaults` | Wipe every saved setting back to build defaults via `UserContext::resetToDefaults()` — empties `defaultconfig.json` so every setting getter falls back to its `Constants::`-backed default; clears the `name` key from `userconfig.json`. Identity fields, contacts, transactions, backups, and API keys are untouched. CSRF-gated. GUI confirmation modal requires typing `reset` before the submit button enables | `csrf_token` |
| `handleClearDebugLogs()` | `clearDebugLogs` | Clear debug entries | None |
| `handleSendDebugReport()` | `sendDebugReport` | Generate debug file | `description` |
| `handleGetDebugReportJson()` | `getDebugReportJson` | Download debug JSON (AJAX) | `description`, `report_mode` |
| `handleSubmitDebugReport()` | `submitDebugReport` | Submit debug report to support via Tor (AJAX, non-blocking) | `description`, `report_mode` |
| `handleAnalyticsConsent()` | `analyticsConsent` | Save one-time analytics consent choice (AJAX) | `consent` (0 or 1) |

**What's New actions** (handled directly in `Functions.php`, not via a controller):

| Action Value | Description | Parameters |
|-------------|-------------|------------|
| `whatsNewDismiss` | Mark current version's "What's New" as seen (AJAX) | None |
| `whatsNewNotes` | Fetch release notes from GitHub for a version (AJAX, cached) | `version` |

**Available Settings:**

| Setting | Type | Description |
|---------|------|-------------|
| `name` | string | Display name shared via QR codes (saved to userconfig.json) |
| `sessionTimeoutMinutes` | int | Session inactivity timeout (5, 10, 15, 30, or 60 minutes) |
| `defaultCurrency` | string | Default currency code |
| `defaultFee` | float | Default fee percentage |
| `minFee` | float | Minimum fee amount |
| `maxFee` | float | Maximum fee percentage |
| `defaultCreditLimit` | float | Default credit limit |
| `maxP2pLevel` | int | Maximum P2P routing hops |
| `p2pExpiration` | int | P2P routing request timeout (seconds); P2P transactions get an extra 120s delivery window after this expires |
| `directTxExpiration` | int | Direct (non-P2P) transaction delivery timeout in seconds; 0 = no expiry (default); recommended: 120s (two Tor round-trips) |
| `maxOutput` | int | Max display lines |
| `defaultTransportMode` | string | Preferred transport (http/https/tor) |
| `autoRefreshEnabled` | bool | Auto-refresh when transactions pending |
| `contactAvatarStyle` | string | Contact avatar rendering style (`gradient`, `pixel`, `tile`) |
| `amountColorScheme` | string | Color scheme for transaction amounts (`neutral`, `western`, `eastern`) |
| `statusColorScheme` | string | Color scheme for status badges (`neutral`, `western`, `eastern`) |
| `autoBackupEnabled` | bool | Enable automatic daily database backups |
| `updateCheckEnabled` | bool | Check Docker Hub daily for newer versions (read-only API call) |
| `autoAcceptTransaction` | bool | Auto-accept P2P transactions when route found (when OFF, transactions pause at `awaiting_approval` for user review in both fast and best-fee modes) |
| `syncChunkSize` | int | Transactions per sync chunk (10-500) |
| `syncMaxChunks` | int | Max sync chunks per cycle (10-1000) |
| `heldTxSyncTimeoutSeconds` | int | Held tx sync timeout in seconds (30-299) |

**Advanced Settings** are organized into categories via a dropdown selector:

| Category | Settings |
|----------|----------|
| Feature Toggles → Contacts | `contactStatusEnabled`, `contactStatusSyncOnPing`, `autoAcceptRestoredContact`, `autoRejectUnknownCurrency` |
| Feature Toggles → Transactions | `autoAcceptTransaction`, `hopBudgetRandomized`, `autoChainDropPropose`, `autoChainDropAccept`, `autoChainDropAcceptGuard` |
| Feature Toggles → GUI | `autoRefreshEnabled`, `hideEmptyGuiSections` |
| Feature Toggles → System | `apiEnabled`, `autoBackupEnabled`, `updateCheckEnabled`, `analyticsEnabled` |
| Backup & Logging → Backup | `backupCronTime`, `backupRetentionCount` |
| Backup & Logging → Logging | `logMaxEntries`, `logLevel` |
| Data Retention → Cleanup | `cleanupDeliveryRetentionDays`, `cleanupDlqRetentionDays`, `cleanupHeldTxRetentionDays`, `cleanupRp2pRetentionDays`, `cleanupMetricsRetentionDays` |
| Data Retention → Archive | `paymentRequestsArchiveRetentionDays`, `paymentRequestsArchiveBatchSize`, `transactionsArchiveRetentionDays`, `transactionsArchiveBatchSize` |
| Rate Limiting → Throughput | `p2pRateLimitPerMinute` |
| Rate Limiting → Attempt Blocking | `rateLimitMaxAttempts`, `rateLimitWindowSeconds`, `rateLimitBlockSeconds` |
| Sync | `syncChunkSize`, `syncMaxChunks`, `heldTxSyncTimeoutSeconds` |
| Network → Transport Timeouts | `httpTransportTimeoutSeconds`, `torTransportTimeoutSeconds` |
| Network → Tor Resilience | `torCircuitMaxFailures`, `torCircuitCooldownSeconds`, `torFailureTransportFallback`, `torFallbackRequireEncrypted` |
| Network → Routing & Delivery | `maxP2pLevel`, `p2pExpiration`, `directTxExpiration` |
| Network → API | `apiCorsAllowedOrigins` |
| Currency | `allowedCurrencies` |
| Display | `displayDecimals`, `displayDateFormat`, `displayRecentTransactionsLimit`, `maxOutput`, `sessionTimeoutMinutes`, `contactAvatarStyle`, `amountColorScheme`, `statusColorScheme` |

---

### DlqController

Handles dead letter queue management actions (AJAX only — all responses are JSON).

| Method | Action Value | Description | Parameters |
|--------|-------------|-------------|------------|
| `handleRetry()` | `dlqRetry` | Re-send a failed message to its original recipient | `dlq_id`, `csrf_token` |
| `handleAbandon()` | `dlqAbandon` | Mark a DLQ item as abandoned | `dlq_id`, `csrf_token` |
| `handleRetryAll()` | `dlqRetryAll` | Retry all retryable DLQ items in bulk (transaction + contact types only) | `csrf_token` |
| `handleAbandonAll()` | `dlqAbandonAll` | Abandon all pending/retrying DLQ items | `csrf_token` |

**Retry constraints:**
- Only `transaction` and `contact` message types can be retried
- `p2p` and `rp2p` items are rejected with an explanatory error — they are time-sensitive relay messages that expire in ≤300s and are stale by the time they reach the DLQ

**Retry mechanism:**
The controller re-sends the original signed payload (stored verbatim in the DLQ) directly to the recipient address using `TransportUtilityService::send()`. If the recipient returns a success status, the item is marked `resolved`. On failure it returns to `pending`.

---

### ApiKeysController

Handles all API-key management done from the wallet GUI's Settings tab (AJAX only — all responses are JSON). CSRF is validated on every call via `Session::validateCSRFToken(..., false)` (non-rotating, since the page fires many AJAX calls from a single load). Every destructive call is additionally gated by a short-lived "sensitive access" grant (see below).

| Method | Action Value | Description | Parameters |
|--------|-------------|-------------|------------|
| `status()` (inline) | `apiKeysStatus` | Report whether the session currently holds a sensitive-access grant and its seconds remaining. Safe to call from any page render. | `csrf_token` |
| `verify()` | `apiKeysVerify` | Verify the user's auth code and open a `Session::SENSITIVE_ACCESS_TTL_SECONDS` grant (default 5 min) for this session. | `authcode`, `csrf_token` |
| `clearAccess()` (inline) | `apiKeysClearAccess` | Drop the sensitive-access grant immediately (user clicked "lock again"). | `csrf_token` |
| `listKeys()` | `apiKeysList` | List every key on this node — `name`, `key_id`, `permissions`, `rate_limit_per_minute`, `enabled`, timestamps, `expires_at`. **No sensitive-access required** — `key_id` values are public identifiers and listing them is non-destructive. | `csrf_token` |
| `createKey()` | `apiKeysCreate` | Mint a new key. Returns the `key_id` + secret *once*; the secret is never retrievable again. Reuses `ApiKeyService::validatePermissions()` and `validateRateLimit()` so CLI and GUI agree on what's accepted. | `name`, `permissions[]`, `rate_limit_per_minute` (optional, default 100), `expires_in_days` (optional, `0` or absent = never), `csrf_token` |
| `toggleKey()` | `apiKeysToggle` | Enable or disable a single key. Idempotent — toggling a key that's already in the target state returns success. | `key_id`, `enable` (`"1"` or `"0"`), `csrf_token` |
| `updateKey()` | `apiKeysUpdate` | Edit a key's label, rate limit, and/or expiry. Permissions are intentionally **not** editable (revoke + reissue to change scope). Expiry may only be **shortened** — a proposed timestamp later than the current `expires_at` returns `expiration_extension_not_allowed`. Each of `name` / `rate_limit_per_minute` / `expires_in_days` is independently optional; omitted fields stay untouched. | `key_id`, any of `name`, `rate_limit_per_minute`, `expires_in_days`, `csrf_token` |
| `deleteKey()` | `apiKeysDelete` | Permanently delete a single key. GUI requires the user to type the key's label to confirm. | `key_id`, `csrf_token` |
| (inline) | `apiKeysDisableAll` | Disable every currently-enabled key in one statement. Returns the affected `count`. | `csrf_token` |
| (inline) | `apiKeysDeleteAll` | Permanently delete every key on the node. GUI requires the user to type `delete all` verbatim before the destructive button enables. | `csrf_token` |

**Sensitive-access gate:**
Every mutating action (`apiKeysCreate`, `apiKeysToggle`, `apiKeysUpdate`, `apiKeysDelete`, `apiKeysDisableAll`, `apiKeysDeleteAll`) calls `$this->requireSensitive()` before handling the request. Without an active grant the controller returns `401 sensitive_access_required` with a descriptive message; the client responds by opening the verify modal, collecting the auth code, and retrying the original request automatically on a successful verify. The grant is bound to the session's `auth_time`, so `logout` or a session-timeout rotation invalidates it immediately. Listing (`apiKeysList`, `apiKeysStatus`) is deliberately outside the gate because it's read-only and leaks nothing the operator doesn't already see.

**Bulk-action safety:**
- "Disable all" is only offered when ≥1 enabled key exists; the confirmation modal states the exact active count.
- "Delete all" is only offered when ≥1 key exists (enabled or disabled); the user must type `delete all` into a confirmation input before the destructive button enables. No bulk *Enable* — re-activation is intentionally one key at a time so a recently disabled key can't be reactivated in a single careless click.
- Both return a `count` of affected rows so the client can toast the exact number processed.

**Audit logging:**
All GUI-driven creations, toggles, updates, deletions, and bulk operations log through the unified `Logger` facade — `SecureLogger` masks any `eiou_*` key-id patterns and any `sk_*` secret patterns, so the audit trail never contains a shown-once secret or correlates a `key_id` to an action in readable form in `app.log`.

**Routing:**
Dispatched from `Functions.php` via an allowlist of action names. A new action must be added to both the `in_array($action, [...])` check in `Functions.php` **and** the `switch ($action)` in `ApiKeysController::routeAction()` — missing it from the allowlist causes `Functions.php` to fall through and render `wallet.html` (107 kB of HTML) instead of routing to the controller, which manifests client-side as a silent `res.json()` rejection.

---

### PaybackMethodsController

Handles every AJAX call the dashboard's Payback Methods section and the contact modal's Payback tab fire — CRUD for your own methods plus the synchronous E2E fetch used to pull a contact's shareable methods over the wire. JSON-only; CSRF is validated on every call.

| Method | Action Value | Description | Parameters |
|--------|-------------|-------------|------------|
| `list()` | `paybackMethodsList` | List this node's methods (public shape, sensitive fields masked). Response also carries `sensitive_access` + `seconds_remaining` mirroring `apiKeysList`, so the section header can show "🔓 Unlocked for N min" without an extra round-trip. | `csrf_token` |
| `create()` | `paybackMethodsCreate` | Create a new method. Validates via `PaybackMethodTypeValidator` (which delegates to `PaybackMethodTypeContract::validate()` for plugin-registered types). Returns `method_id`. | `type`, `label`, `currency`, `fields[...]`, optional `share_policy`, optional `priority`, `csrf_token` |
| `update()` | `paybackMethodsUpdate` | Patch an existing row. Accepts any of `label`, `share_policy`, `priority`, `enabled`, `fields` — sending `fields` re-encrypts the entire blob atomically. | `method_id`, any of `label`, `share_policy`, `priority`, `enabled`, `fields[...]`, `csrf_token` |
| `reveal()` | `paybackMethodsReveal` | Return the method with all fields decrypted to plaintext. Used by Edit (to pre-populate sensitive inputs) and the per-field Copy buttons on the detail modal. | `method_id`, `csrf_token` |
| `remove()` | `paybackMethodsRemove` | Permanently delete a method. | `method_id`, `csrf_token` |
| `setSharePolicy()` | `paybackMethodsSetSharePolicy` | Atomic share-policy update without touching other fields. | `method_id`, `share_policy`, `csrf_token` |
| `fetchFromContact()` | `paybackMethodsFetchFromContact` | Fire a synchronous `payback-methods-request.v1` E2E round-trip at a contact and return their shareable methods inline. Nothing is cached — closing the tab drops the in-memory copy. Returns `{status, methods, ttl_seconds}`; `status` is one of `ok` / `denied` / `rate_limited`. Stale responses are dropped if the user switched contacts mid-flight. | `address`, optional `currency`, `csrf_token` |

**Sensitive-access gate:**
All mutations (`Create`, `Update`, `Remove`, `SetSharePolicy`) **and** `Reveal` require an active sensitive-access grant — the GUI shares the same `apiKeysVerify` / `apiKeysStatus` / `apiKeysClearAccess` mechanism so a single unlock covers both API-key and payback-method edits for the grant's lifetime. The client routes all seven calls through `withSensitiveAccess(requestFn, onResponse, label)` which opens `apiKeysVerifyModal` on a `401 sensitive_access_required` response and retries on successful unlock. Listing (`paybackMethodsList`) and contact-fetch (`paybackMethodsFetchFromContact`) are outside the gate — list rows already show masked values, and the contact-fetch response is composed by the *other* node so there's nothing sensitive for this node to re-gate.

**E2E fetch mechanics (`fetchFromContact`):**
The controller extracts the inner response body from the envelope that `MessageService` echoes back from `MessageDeliveryService::sendSyncMessage()`, normalizes the status strings, and returns them to the GUI. Underlying flow: request → receiver's `ReceivedPaybackMethodService::handleIncomingRequest()` → receiver's `PaybackMethodService::listShareable()` → response envelope back through the same E2E channel. Nothing is written to `payback_methods_received` on this flow — that table is reserved for a future cache layer.

**Routing:**
Same allowlist rule as every other AJAX controller — new actions must be added both to `Functions.php`'s action check and the controller's `switch` dispatch.

---

## Layout Components

### authenticationForm.html

Login page displayed when user is not authenticated.

| Element | Purpose |
|---------|---------|
| Password input | Auth code entry |
| Error message | Displayed on failed login |
| Loading overlay | Shown during submission |

---

### wallet.html

Main layout container that includes all subpart components.

**Tab Structure:**

| Tab | Components |
|-----|------------|
| Dashboard | `walletInformation.html` |
| Send | `eiouForm.html`, `paymentRequestsSection.html` |
| Contacts | `contactSection.html` |
| Activity | `transactionHistory.html`, `dlqSection.html` |
| Settings | `settingsSection.html`, `debugSection.html` |

**Include Order:**
1. banner.html
2. header.html
3. notifications.html
4. walletInformation.html *(Dashboard tab)*
5. eiouForm.html *(Send tab)*
6. paymentRequestsSection.html *(Send tab)*
7. contactSection.html *(Contacts tab)*
8. transactionHistory.html *(Activity tab)*
9. dlqSection.html *(Activity tab)*
10. settingsSection.html *(Settings tab)*
11. debugSection.html *(Settings tab — appended below settings form)*
12. floatingButtons.html
13. analyticsConsentModal.html

---

### walletSubParts Components

#### banner.html

Loads and displays banner images from `/gui/assets/banners/`. Any image placed in that directory (jpg, jpeg, png, gif, svg, webp) is shown at the top of the wallet page. Files are sorted alphabetically. Used for promotional or informational banners.

| Element | Purpose |
|---------|---------|
| Banner carousel | Displays images from the banners directory |

---

#### header.html

| Element | Purpose |
|---------|---------|
| Wallet title | Branding with icon |
| Logout link | Ends session |

---

#### notifications.html

| Element | Purpose |
|---------|---------|
| Operation result toasts | Success/error messages from redirects |
| Tor connectivity status | Warning when Tor is unreachable, success toast when restored |
| Update available banner | Shows current vs available version with Docker pull command |
| What's New banner | After upgrade, shows "See what's new in vX.X.X" link that opens a release notes modal; dismissible per-version |
| In-progress banner | Shows pending transaction count |
| Pending contacts banner | Shows pending contact request count |
| Pending currency requests | Shows incoming currency requests from existing contacts |
| Tx drop proposal banner | Incoming proposals requiring action (red alert) |
| Completed transaction toasts | Notifications for finished transactions |
| Received transaction toasts | Notifications for incoming payments |
| DLQ notifications | Dead letter queue failure alerts |

---

#### quickActions.html

> **Note:** This component is no longer included in the dashboard layout. Navigation is now fully tab-based. The file is retained but unused.

---

#### walletInformation.html

| Element | Purpose |
|---------|---------|
| Last updated timestamp + Refresh link | Shows data freshness with manual refresh |
| Total Balance | Aggregated wallet balance per currency (blue card) |
| Total Fee Earnings | P2P relay fee earnings per currency (amber/gold card) |
| Total Available Credit | Sum of available credit per currency (blue-purple card), from ping/pong, ~5 min refresh |
| User Addresses | HTTP/HTTPS/Tor with Copy and QR code buttons |
| Scan Contact QR | Opens camera scanner — on scan, switches to Contacts tab and opens Add Contact modal with pre-filled address and name |
| Public Key | Wallet public key with copy button |
| Status | Always "Active" |

All three dashboard cards display per-currency rows. When a card has no data for a given category, it shows "0.00" with the currency derived from other data sources for consistency.

The ⓘ icons next to "Total Fee Earnings" and "Total Available Credit" open a small info modal on click (tap-friendly on mobile).

**QR Code Format:** QR codes use a typed JSON envelope for forward compatibility:

| Type | Format | Description |
|------|--------|-------------|
| `contact` | `{"type":"contact","address":"...","name":"..."}` | Add contact (name optional) |
| `payment` | `{"type":"payment","address":"...","amount":...,"currency":"...","description":"..."}` | Payment request (future) |

Legacy plain-text QR codes (just an address string) are parsed as type `contact` for backward compatibility.

---

#### eiouForm.html

| Field | Type | Description |
|-------|------|-------------|
| recipient | select | Contact dropdown |
| address_type | select | Address type for selected contact |
| manual_recipient | text | Direct address entry (P2P) |
| amount | number | Transaction amount |
| currency | select | Currency code (dynamically populated from user's allowed currencies; filtered to contact's accepted currencies when a contact is selected) |
| description | text | Optional memo |
| best_fee | checkbox | **[Experimental]** Use best-fee routing: collects all route responses and selects the lowest fee |

**Features:**
- P2P routing information alert
- Dynamic address type selector (options sorted by security preference: Tor > HTTPS > HTTP)
- Dynamic currency dropdown: shows all allowed currencies when no contact selected, filtered to contact's accepted currencies when a contact is selected
- Direct/P2P routing info integrated into address type and manual address hint text
- Best-fee routing checkbox with experimental warning label

---

#### paymentRequestsSection.html

Rendered below the Send form in the Send tab. Shows incoming and outgoing payment requests.

**Incoming Requests (pending):**

| Element | Purpose |
|---------|---------|
| Requester name/address | Who sent the request |
| Amount + currency | Requested amount |
| Description | Optional note from requester |
| Approve & Pay button | Sends the eIOU automatically, marks request approved |
| Decline button | Rejects the request, notifies requester |

**Outgoing Requests (pending):**

| Element | Purpose |
|---------|---------|
| Recipient name/address | Who you sent the request to |
| Amount + currency | Requested amount |
| Description | Optional note |
| Status badge | Pending / Approved / Declined |
| Cancel button | Cancels the outgoing request |

Resolved requests (approved/declined) appear in a collapsed history section. Approved requests show a clickable truncated txid that opens the transaction detail modal.

**History Paginator (resolved requests only):**
- Same shared `Paginator` IIFE as the Recent Transactions and Contacts tables. Page buttons + size selector (25 / 50 / 100 / All), persisted as `eiou_paginator_size_payment-requests`.
- **"Load older" button** — fetches next server-side page via `loadMorePaymentRequests` GUI AJAX action. Backed by `PaymentRequestRepository::getResolvedHistoryPage($limit, $offset)` — a single SQL query on the unified table with `WHERE status != 'pending' ORDER BY COALESCE(responded_at, created_at) DESC LIMIT ? OFFSET ?`, matching the initial template's `usort` key so pages append cleanly. Rows rendered via a shared `_paymentRequestRow.html` partial.
- **"Showing the last N requests"** counter is dynamic via `#pr-meta-loaded-count`; updates after each Load-older click.

**Search database (server-side):**
- Same pattern as Recent Transactions — button next to the search input, or Enter in the search box. Fires `searchPaymentRequests` GUI AJAX action → `PaymentRequestRepository::searchResolvedHistory` (LIKE across `contact_name`, `description`, `requester_pubkey_hash`, `recipient_pubkey_hash`, `requester_address`).
- Respects the direction / status filter selects. Hard-capped at 500 rows; response includes `{html, total, capped, cap}`.
- Result rows replace the tbody; banner shows `N matches for "term" · Clear search`; Clear reloads the page.

---

#### contactForm.html

> **Note:** This file contains the Add Contact form fields. It is included as part of the Add Contact modal inside `contactSection.html`, not as a standalone include in `wallet.html`.

| Field | Type | Description |
|-------|------|-------------|
| address | text | Contact node address (placeholder: "Enter Tor (.onion) or HTTP(S) address", with QR scan button) |
| name | text | Display name |
| credit | number | Credit limit (default from settings) |
| fee | number | Fee percentage (default from settings) |
| currency | select | Currency code (dynamically populated from user's allowed currencies) |
| description | text | Optional message sent with the contact request (max 255 chars). For non-Tor contacts, sent as a separate E2E encrypted follow-up after key exchange. For Tor contacts, included directly (protected by Tor transport encryption) |

---

#### contactSection.html

The Contacts tab. The contact list is shown first. The "Add Contact" form is accessed via the "+ New Contact" button which opens a modal dialog (the form fields from `contactForm.html` are embedded in this modal, not rendered inline).

**Contact Grid:**
- Accepted contacts with balance
- Pending user contacts (outgoing)
- Blocked contacts
- Search/filter functionality
- Show more toggle (>16 contacts)
- **Shared paginator** — page-size selector (25/50/100/All, persisted per-table via `safeStorageSet` under `eiou_paginator_size_contacts`) + prev/next buttons + range indicator, installed by `Paginator.create('contacts', …)` in `script.js`. Orthogonal `.filter-hidden` / `.paginator-hidden` classes on rows so the local search + status/chain/online filters keep working in combination with pagination
- **"Load more" button** (accepted contacts only — pending + blocked are always rendered up-front because they're bounded and operationally important). Fetches the next server-side page via the `loadMoreContacts` GUI AJAX action, rendering rows through a shared `_contactRow.html` partial so appended rows are byte-identical to the initial render
- Tx drop proposal badges on contact cards:
  - Red "Action Required" — incoming proposal needs acceptance
  - Blue "Awaiting Response" — outgoing proposal pending
  - Orange "Blocked" — rejected proposal, transactions blocked
  - Yellow triangle — chain invalid, no proposal yet

**Contact Modal (Tabbed):**

| Tab | Contents |
|-----|----------|
| Info | Per-currency balance, credit limit, fee, your/their available credit (via horizontal currency slider pills), addresses (with Copy and QR code buttons; QR regenerates when switching address types), public key (single-line display with Copy button), contact ID |
| Transactions | Recent transactions with this contact |
| Payback | **Live E2E fetch** of the contact's shareable payback methods (bank wire, PayPal, BTC, etc.). Opens a synchronous `payback-methods-request.v1` round-trip through the node; response is rendered inline and never persisted to disk or `localStorage`. Currency dropdown defaults to the currency of the largest debt owed, filters the fetch when changed (auto-refetches). Rows are clickable → per-method detail modal with per-field Copy buttons. Renders dedicated copy for `denied` / `rate_limited` states. |
| Status | Online status, chain status (proposal-aware, clickable — switches to this tab), Check Status button, tx drop resolution section (propose/accept/reject) |
| Settings | Edit form, block/unblock/delete buttons |

Every tab has a collapsible **About <tab>** info panel at the top explaining what the columns/fields mean.

**Tx Drop Resolution Section (in Info tab):**
- Shown when chain has a gap or active proposal
- Sub-sections: propose, awaiting acceptance, incoming (accept/reject), rejected (repropose)
- Funds warning: dropping a transaction removes its funds from both balances
- Chain status badge clicks scroll to this section

**Pending Contact Requests Section:**
- Lists incoming requests with direction-aware currency display
- Outgoing currencies shown as read-only badges ("Awaiting their acceptance")
- Incoming currencies shown as actionable accept forms ("They requested") with fee/credit fields
- Per-currency accept forms when multiple currencies are requested
- Legacy fallback form for contacts without `contact_currencies` data
- Delete/block options

---

#### transactionHistory.html

**In-Progress Section:**
- Phase indicators (pending, route_search, route_found, sending, syncing)
- Direct badge shown for non-P2P transactions (P2P type is implied by route/fee info)
- Held transaction notices
- P2P approval gate: when `autoAcceptTransaction` is OFF, transactions pause at `awaiting_approval` (blue badge) with route selection UI. Send amount shown inline before candidates
  - Fast mode: shows 1 route with fee breakdown, Accept/Reject buttons
  - Best-fee mode: lists all returned routes ordered by fee (lowest first), user picks one. On mobile (≤576px) candidate rows stack vertically
  - Route count updates dynamically as late-arriving candidates are received
  - Node fee hidden during awaiting_approval (route fee shown per-candidate instead)

**Transaction Table Columns:**

| Column | Description |
|--------|-------------|
| Status icon | Leading column, no header. Single check (sent/accepted), double check (completed), hourglass (pending), cross (rejected), ban (cancelled). Muted grey for normal states; amber for pending, red for rejected |
| Counterparty | Avatar + contact name |
| Amount | Sortable. ±value carries sent/received direction |
| Description | CSS-truncated; full text on hover |
| Type | Direct / P2P / Contact badge |
| Origin / Dest. | P2P only: final destination (sent) or original sender (received) |
| Date | Sortable, formatted timestamp |

- Type badges (Contact, P2P, Direct)
- Click any row to open detail modal

**Paginator:**
- Shared `Paginator` IIFE in `script.js` — same infrastructure Contacts and Payment Requests history use. Page-size selector (25 / 50 / 100 / All, persisted per-table), prev/next buttons, range indicator ("101–125 of 200").
- **"Load older" button** — fetches the next server-side page via the `loadMoreTransactions` GUI AJAX action, rendering rows through the `_transactionHistoryRow.html` partial that both the initial foreach and the AJAX handler consume. Client concatenates the returned `data.rows` onto the in-memory `transactionData[]` so `openTransactionModal(index)` keeps resolving for appended rows. Button auto-hides when the server reports `exhausted: true`.
- **"Showing the last N transactions"** counter updates dynamically as rows are loaded or replaced. Uses a `#tx-meta-loaded-count` span rewritten by `refreshMetaLoadedCount()`.

**Search database (server-side):**
- Button next to the search input, also triggered by pressing Enter in the search box.
- Fires `searchTransactions` GUI AJAX action — backend runs a case-insensitive `LIKE %term%` across counterparty name (direct + P2P end-recipient + P2P initial-sender), transaction description, all four address fields (sender/receiver/end-recipient/initial-sender), and the txid. Pasting any full or partial hash surfaces the matching row, whether it's currently loaded in the paginator window or not.
- Respects the current direction / type / status filter select values.
- Hard-capped at 500 rows; response carries `{html, rows[], total, capped, cap}` so the banner can disclose when a broad query was clipped.
- Result rows **replace** (not append to) the table body. Paginator moves to page 0, load-older is suspended (the result set is bounded by the server cap). Banner shows `N matches for "term" · Clear search` which reloads to the default view.
- The local search input's live-keystroke filter also searches the P2P endpoint name/address via new `data-tx-endpoint-name` / `data-tx-endpoint-address` row attributes and the txid (via `data-txid`, which was already present on the row for the detail-modal cross-link) — so typing "carol" or pasting a txid locally matches the same rows the database search would return for already-loaded transactions, and the "X transactions found" counter stays consistent after a database search.

**Transaction Modal:**
- Full transaction details
- Copy buttons for addresses/txid

**Auto-Refresh:**
- Polls every 15 seconds when transactions pending
- Controlled by `autoRefreshEnabled` setting

---

#### dlqSection.html

The Dead Letter Queue section displays messages that could not be delivered after all automatic retry attempts.

**Status Filter:** Dropdown matching contacts/transactions pattern — Any status (default), Pending & Retrying, Pending Only, Resolved, Abandoned. The search bar and filter are both gated on `!empty($dlqItems)` — an empty queue hides them so users aren't shown controls with nothing to act on (matches the Contacts and Payment Requests sections).

**Stats Bar:** Per-status counts (Pending / Retrying / Resolved / Abandoned).

**Table Columns:** Status icon (leading, slim), Type, Recipient, Failure Reason (truncated), Added, Actions. Uses `contacts-table` chrome with 60vh scrollable wrapper. Clicking any row opens a detail modal with all fields + Retry/Abandon buttons. The leading status-icon column carries pending / retrying / resolved / abandoned state via `fa-hourglass-half` / `fa-sync-alt fa-spin` / `fa-check-double` / `fa-ban` — resolved and abandoned colours follow the user-selected status colour scheme. The legacy dedicated "Status" column was removed: the Actions cell carries "Delivered" / "Abandoned" text for terminal rows and the icon + row-click modal cover the rest.

**Mobile (≤600px):** Shows status icon + Type + Recipient (the generic `.contacts-table` rule hiding cols 3+ is overridden here so the recipient stays visible alongside the icon column). Tap row for detail modal with full info and actions. Action buttons collapse to icon-only at ≤900px.

**Actions per row:**

| Action | Available For | Description |
|--------|--------------|-------------|
| **Retry** | `transaction`, `contact` types only, pending/retrying status | Re-sends the original signed payload directly to the recipient |
| **Abandon** | Any pending/retrying item | Marks as abandoned — no further retries |
| **Retry All** | Bulk action — all retryable items | Re-sends all eligible items (transaction + contact types only) |
| **Abandon All** | Bulk action — all pending/retrying items | Marks all actionable items as abandoned |

> **Important — retry eligibility by message type:**
>
> | Type | Retryable | Reason |
> |------|:---------:|--------|
> | `transaction` | ✅ | User-initiated payment; payload remains valid |
> | `contact` | ✅ | User-initiated contact request; payload remains valid |
> | `p2p` | ❌ | P2P routing request; expires in ≤300s — stale by retry time |
> | `rp2p` | ❌ | Relay message forwarded on behalf of another node; underlying P2P has expired or resolved elsewhere |
>
> `p2p` and `rp2p` items show an **"Expired"** label instead of a Retry button. Use **Abandon** to clear them.

**Tab Badge:**
When pending DLQ items exist, the **Activity** tab in the navigation bar displays a count badge.

**DLQ Indicator in Transaction History:**
Transactions that have a pending or retrying DLQ entry display a DLQ icon next to the status icon in the Recent Transactions table and a **DLQ** badge in the In-Progress Transactions list. Clicking the icon/badge navigates to `#dlq` to retry or abandon the delivery. When a transaction's delivery is exhausted and it moves to the DLQ, its status is immediately set to `cancelled` so it is removed from the In-Progress panel and stops triggering auto-refresh. Retrying from the DLQ resets the status to `sending` and re-delivers the original signed payload.

**Mobile Layout (≤600px):**
Collapses to three columns: **Status icon | Counterparty | Amount**. The +/− on Amount carries direction; the status icon confirms completion state.

**Notifications:**
A warning toast appears when new items are added to the DLQ (tracked per session — each item fires once per browser session).

---

#### paybackMethodsSection.html

Dashboard section rendering the user's own payback methods — the settlement rails (bank wire, PayPal, BTC, custom free-text, etc.) they offer contacts for squaring debts. Uses the same `form-container fade-in-up` chrome as Payment Requests.

**Header:**
- `+ Add` button — opens the two-step Add/Edit modal (`paybackMethodForm.html`)
- `🔓 Unlocked for N min` — status text shown after a sensitive-access grant; inherited from the same session-grant mechanism as API Keys

**Header callout — "How payback methods work":** per-row encryption at rest (AES-256-GCM, keyed to the wallet), how each rail's masking differs (typed rails show last-4; `custom` shows the first 80 chars as a preview since it's user-authored free text), share policy behaviour.

**Filter bar:** client-side Type and Currency dropdowns reusing the shared `.contacts-filter-select` chrome from Recent Transactions. Only surfaces when >1 option exists on an axis — a single-method / single-currency wallet sees no dead widgets.

**Table columns:** Type (plain uppercase, no badge), Currency, Label, Details (the `masked_display`), Share policy. Ordered by `priority ASC, created_at DESC` — same sort the repository uses. Row click opens the **view** modal.

**Add / View / Edit modal (`paybackMethodForm.html`)** is a two-step flow:
1. **Step 1 — Type picker.** Tiles injected from the catalog JSON at `#payback-methods-catalog` (the PHP side echoes the full `PaybackMethodTypeValidator::getCatalog()` result, including plugin-registered types). Tiles group by `bank` / `crypto` / `mobile` / `fintech` / `other` — plugin-declared groups are injected automatically.
2. **Step 2 — Per-type fields.** Form body is built from the catalog entry's `fields` spec: label input, currency select (filtered to `type.currencies` when the type declares a whitelist), type-specific inputs (IBAN, routing number, email, address…), share-policy select, priority number input. SWIFT sub-rail gets a segmented `[IBAN] [Account number]` toggle so only the chosen identifier submits.

View mode renders the same DOM with inputs set to `readonly` and a *"View only. Click Edit below to make changes"* banner; footer swaps to `Close · Edit`. Edit mode flips inputs to editable without refetch (title changes, banner hides, footer swaps to `Cancel · Delete · Save Method`).

**Sensitive-access gate:** opening the modal for edit/view — and every mutation (`add`, `update`, `remove`, `share-policy`, `reveal`) — returns `401 sensitive_access_required` unless a short-lived grant is active. The client routes through the same `withSensitiveAccess(requestFn, onResponse, label)` helper that `apiKeysSection.html` uses, so a denied request opens `apiKeysVerifyModal` on top of the form and retries on successful unlock. Unlock persists for a few minutes; the chrome-level "Unlocked for N min" readout ticks down in the section header.

**Per-rail "About <rail>" info panel** at the top of step 2, populated from the catalog entry's optional `info` HTML string. Starts collapsed so returning users who don't need the refresher see the compact form. Plugins opt-in by returning an `info` key from `getCatalogEntry()` — a BTC plugin can call out accepted address formats, a PayPal plugin can remind operators to link an active account, etc.

Client-side module (`script.js`, IIFE exported as `window.paybackMethods`) talks to `PaybackMethodsController` via `paybackMethodsList` / `paybackMethodsCreate` / `paybackMethodsUpdate` / `paybackMethodsRemove` / `paybackMethodsReveal` / `paybackMethodsSetSharePolicy` AJAX actions. Session-expired responses (302 / 401 / HTML login page) are caught in the JSON-parse branch and surfaced as a readable "Session expired — please sign in again" message instead of a cryptic `bad_json` toast.

**Related surfaces:**
- Contact modal **Payback** tab (`contactSection.html`) — live E2E fetch of the *other* contact's shareable methods; nothing persists. See the Contact Modal table above.
- `PaybackMethodsController` — see Controllers section below.

---

#### settingsSection.html

**Header callout** — `.section-intro` explaining what Save does, what Reset reverts (unsaved changes only), and pointing at Advanced Settings → Reset to Defaults for a full wipe.

**Settings Form:**
- Basic wallet settings organized into four `<h5 class="settings-group-heading">` subsections — **Appearance** (contact avatar style, amount and status color schemes), **Identity** (display name), **Payments** (default currency, fees, credit limit), **Network** (default transport mode)
- Collapsible Advanced Settings with category dropdown. Categories: **Feature Toggles**, **Currency**, **Display**, **Backup & Logging**, **Data Retention**, **Sync**, **Network**, **Rate Limiting**, **GUI Security**, **Reset to Defaults**
- Advanced categories with >3 fields are further subdivided into `<h5 class="settings-group-heading">` groups: **Feature Toggles** (Contacts, Transactions, GUI, System), **Backup & Logging** (Backup, Logging), **Data Retention** (Cleanup, Archive), **Rate Limiting** (Throughput, Attempt Blocking), **Network** (Transport Timeouts, Tor Resilience, Routing & Delivery, API). Each subsection is its own `.settings-grid` so the 3-column `auto-fill` layout applies per group
- **GUI subsection** hosts `autoRefreshEnabled` and `hideEmptyGuiSections`. `hideEmptyGuiSections` (default OFF) hides the Failed Messages / Payment Requests / Pending Contact Requests sections when their lists are empty — when OFF (the default) these sections render an empty-state panel so users know the feature exists
- **GUI Security** category hosts Session Timeout (moved here from the main grid), Remember Me Duration, Max Remembered Devices, and the Active Remembered Sessions list. The sessions list heading uses `settings-group-heading` for cross-category consistency, and the empty state uses the shared `.empty-panel` (dashed border + centered text) that also backs the API Keys empty state
- **Data Retention** category has two subsections with distinct semantics: a **Cleanup** block (`cleanupDeliveryRetentionDays`, `cleanupDlqRetentionDays`, `cleanupHeldTxRetentionDays`, `cleanupRp2pRetentionDays`, `cleanupMetricsRetentionDays`) where rows past retention are **deleted**, and a separate **Archive** block (`paymentRequestsArchiveRetentionDays`, `paymentRequestsArchiveBatchSize`) where resolved payment requests past retention **move to the `payment_requests_archive` table** — they stay queryable in the history/search paths. The archive block carries its own inline warning ("nothing is deleted") so users don't confuse it with the cleanup retentions above it
- **Reset to Defaults** category is a dedicated destructive-action surface — danger button opens `settingsResetToDefaultsModal` which requires typing `reset` into a confirmation input before the submit button enables. Submits to `SettingsController::handleResetToDefaults()` via a separate form (outside the main settings `<form>`, since a nested form isn't legal HTML)
- Save / Reset buttons at the bottom — Save posts `updateSettings`; Reset is a plain `<button type="reset">` that rolls back unsaved form state

The Settings tab additionally hosts **apiKeysSection.html** (API-key lifecycle, see below) and **debugSection.html** (debug logs, system info, debug report).

---

#### .section-intro — shared top-of-section callout

Any section can start with a `<details class="section-intro text-muted">` callout:
```html
<details class="section-intro text-muted">
    <summary><i class="fas fa-info-circle"></i> <span>Short title</span></summary>
    <div class="section-intro-body">Longer explanation…</div>
</details>
```
Currently applied to: **Wallet Settings**, **API Keys**, **Failed Messages**, **Debug Information**, **New eIOU**, **Your Contacts**, **Recent Transactions**, **Payback Methods**, **Plugins**, and every tab inside the contact modal (**Info**, **Transactions**, **Payback**, **Status**, **Settings**) — per-tab "About <tab>" intros help users who land on a tab cold.

Always ships closed (no `open` attribute) — user clicks the summary to expand via native `<details>` behaviour. Same UX on desktop and mobile and for JS-disabled users (Tor "Safest" mode). The summary shows an info icon on the left and a chevron on the right that rotates when open. CSS lives at `.section-intro` / `details.section-intro > summary` / `.section-intro-body` in `page.css`.

---

#### apiKeysSection.html

Rendered on the **Settings** tab, between the settings form and the debug section. Lists every API key on the node and exposes the full lifecycle (create / enable / disable / edit / delete / bulk-disable / bulk-delete) without leaving the page.

**Toolbar:**
- **Create API Key** — opens the create modal
- **Refresh** — re-fetches the list without a full page reload
- **Disable all** — visible only when ≥1 key is enabled
- **Delete all** — visible only when ≥1 key exists (enabled or disabled)
- **"Edits unlocked for N min"** — status text shown while a sensitive-access grant is active

**List rows (one per key):**
- Label (bold) + Active/Disabled badge
- `key_id` (monospace)
- Meta line: permission summary (comma-joined), rate limit, created timestamp, last-used timestamp, expiration (if any)
- Actions: **Enable/Disable** (toggles single key), **Edit** (label / rate limit / expiry), **Delete** (typed-confirmation)

**Modals (all `position: fixed`, stacked on `.modal` z-index 10000 except re-auth at 10010):**
- `apiKeysCreateModal` — label, permission checkboxes grouped by scope (Wallet / Contacts / System / Backup / Admin), Read-only / Full access / Clear presets, rate limit (1–`ApiKeyService::MAX_RATE_LIMIT`, default 100), expiry (Never / 30d / 90d / 1 year)
- `apiKeysRevealModal` — **one-time** secret display with per-field Copy buttons and a mandatory "I've saved..." acknowledgement before Done. Secret is scrubbed from the DOM the moment this modal closes
- `apiKeysEditModal` — read-only permission chips + editable label / rate-limit / expiry-shortening. Live warning when rate limit is raised above the stored value
- `apiKeysDeleteModal` — typed confirmation (must match the key's label)
- `apiKeysDisableAllModal` — one-click confirmation with the exact active-key count
- `apiKeysDeleteAllModal` — typed confirmation (must type `delete all` verbatim)
- `apiKeysVerifyModal` — sensitive-action re-auth prompt (auth code input + Unlock). Opens *on top of* whichever modal triggered the gate

Client-side module (`script.js`, IIFE exported as `window.apiKeys`) handles dispatch through a shared `withSensitiveAccess(requestFn, onResponse, label)` helper: on a `401 sensitive_access_required` response the module opens the verify modal, and on successful verify retries the original request with the same `onResponse` handler so the caller doesn't have to know about the re-auth dance.

---

#### debugSection.html

Rendered at the bottom of the **Settings** tab (below the settings form).

**Debug Logs (Tabbed):**

| Tab | Contents |
|-----|----------|
| App Logs | Debug entries from database |
| eIOU Log | `/var/log/eiou/app.log` |
| PHP Logs | PHP error log |
| nginx Logs | nginx error log |
| System Info | PHP version, extensions, config files, constants |

**Debug Report:**
- Limited report: Same data as GUI display
- Full report: Complete log history

---

#### floatingButtons.html

| Button | Purpose |
|--------|---------|
| Back to top | Scroll to page top |
| Manual refresh | Reload wallet data |

---

#### analyticsConsentModal.html

One-time modal shown after first login to ask the user whether to enable anonymous analytics. The choice is saved via the `analyticsConsent` AJAX action and the modal never reappears (`analyticsConsentAsked` flag in config).

| Element | Purpose |
|---------|---------|
| Consent modal | Opt-in/opt-out choice for anonymous analytics |
| Enable button | Sets `analyticsEnabled=true` |
| Decline button | Sets `analyticsEnabled=false` |

---

## Session Management

The `Session` class provides secure session handling.

### Session Configuration

| Parameter | Value | Description |
|-----------|-------|-------------|
| `lifetime` | 0 | Session cookie (expires on browser close) |
| `httponly` | true | Prevent JavaScript access |
| `samesite` | Strict | CSRF protection via cookie |
| `secure` | auto | HTTPS-only when available |
| `name` | EIOU_WALLET_SESSION | Custom session name |

### Key Methods

| Method | Description |
|--------|-------------|
| `isAuthenticated()` | Check if user is logged in |
| `authenticate($authCode, $userAuthCode)` | Validate auth code |
| `checkSessionTimeout()` | Enforce configurable inactivity limit (default 30 min, reads from `sessionTimeoutMinutes` in config) |
| `logout()` | Clear session and destroy cookie |
| `requireAuth()` | Redirect to login if not authenticated |
| `generateCSRFToken()` | Create secure token |
| `validateCSRFToken($token)` | Verify token with constant-time comparison |
| `verifyCSRFToken()` | Auto-verify POST requests |
| `getCSRFField()` | Generate hidden input HTML |
| `setMessage($message, $type)` | Set flash message |
| `getMessage()` | Get and clear flash message |

### Session Security Features

| Feature | Implementation |
|---------|----------------|
| Session regeneration | Every 5 minutes |
| Auth code comparison | `hash_equals()` constant-time |
| Session timeout | Configurable (5/10/15/30/60 min, default 30) |
| ID regeneration on login | Prevents session fixation |
| CSRF token expiration | 1 hour max age |

---

## Security Features

### CSRF Protection

All POST forms include a CSRF token:

```html
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
```

Token validation occurs at the start of every controller action via `$this->session->verifyCSRFToken()`.

---

### XSS Prevention

| Location | Method |
|----------|--------|
| View output | `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` |
| URL parameters | URL encoding + HTML escaping |
| JSON in HTML | `json_encode()` + `htmlspecialchars()` |
| User input | `Security::sanitizeInput()` |

---

### Input Validation

All user input is validated through `InputValidator`:

| Validator | Purpose |
|-----------|---------|
| `validateAddress($address)` | HTTP/HTTPS/Tor address format |
| `validateContactName($name)` | Alphanumeric with spaces |
| `validateFeePercent($fee)` | 0-100 range |
| `validateCreditLimit($credit)` | Positive number |
| `validateCurrency($currency)` | Supported currency code |
| `validateAmount($amount, $currency)` | Positive transaction amount |
| `validateNotSelfSend($address, $userContext)` | Prevent sending to self |

---

### Session Security

| Protection | Description |
|------------|-------------|
| Constant-time comparison | Auth code and CSRF validation |
| Session regeneration | Periodic and on authentication |
| HTTP-only cookies | Prevent XSS token theft |
| SameSite Strict | Prevent CSRF via cookies |
| Inactivity timeout | 30-minute auto-logout |

---

## Helpers

### MessageHelper

Utility class for message handling between controllers and views.

#### Key Methods

| Method | Description |
|--------|-------------|
| `parseContactOutput($output)` | Parse CLI output, determine message type |
| `parseCliJsonOutput($output)` | Parse JSON CLI response |
| `formatMessage($message, $type)` | Generate HTML message div |
| `getMessageClass($type)` | Get CSS class for message type |
| `getMessageIcon($type)` | Get icon character for message type |
| `redirectMessage($message, $type, $url)` | Redirect with URL-encoded message |
| `getMessageFromUrl()` | Extract message from GET params |
| `displayFlashMessage($session)` | Render flash or URL message |
| `getGuiFriendlyMessage($errorCode, $detail)` | Map error codes to user messages |

#### Message Types

| Type | CSS Class | Icon |
|------|-----------|------|
| success | message-success | checkmark |
| error | message-error | X |
| warning | message-warning | triangle |
| info | message-info | i |
| contact-accepted | message-success | checkmark |

---

### ViewHelper

Utility class for view rendering.

| Method | Description |
|--------|-------------|
| `sanitize($text)` | HTML escape with UTF-8 |
| `formatTimestamp($timestamp, $format)` | Format date/time |
| `getTransactionClass($type)` | CSS class for transaction type |
| `getStatusBadgeClass($status)` | CSS class for contact status |
| `generatePagination($page, $total, $url)` | Render pagination links |
| `renderSelectOptions($options, $selected)` | Generate option tags |
| `generateBreadcrumbs($items)` | Render breadcrumb nav |

---

### ContactDataBuilder

Builds standardized contact data structures for the GUI.

| Method | Description |
|--------|-------------|
| `buildContactData($contact, $status)` | Create normalized contact array |
| `buildEncodedContactData($contact, $status)` | JSON-encoded, HTML-safe for onclick |

**Contact Data Fields:** name, address, fee, credit_limit, currency, status, pubkey, pubkey_hash, balance, balances_by_currency, contact_id, transactions, online_status, valid_chain, my_available_credit, their_available_credit, chain_drop_proposal, chain_gap_details, currencies, pending_currencies, outgoing_currencies, plus all dynamic address types.

**Address Priority:** Tor > HTTPS > HTTP (security preference)

---

## Known Limitations

| Limitation | Description | Workaround |
|------------|-------------|------------|
| No WebSockets | Tor Browser blocks WebSockets | Polling-based updates |
| Limited JavaScript | Tor Browser security settings | Server-side rendering |
| Session-based auth | No persistent login | Re-authenticate on browser close |
| No real-time updates | Page refresh required | Auto-refresh when enabled |
| Large contact lists | Performance with >100 contacts | Pagination/virtualization planned |
| Debug log size | Full report slow over Tor | Limited report option available |

---

## Development Setup

### Volume Mount

During development, mount the source directory:

```yaml
volumes:
  - ./files/src:/app/eiou/src:ro
```

### File Paths

| Context | Path Prefix |
|---------|-------------|
| Inside container | `/app/eiou/src/gui/` |
| Outside container | `./files/src/gui/` |
| Browser assets | `/gui/assets/` |

### Testing Changes

1. Edit files in `./files/src/gui/`
2. Refresh browser (container uses mounted files)
3. Check browser console for JS errors
4. Check PHP logs for server errors

### CSS Development

Styles are in `assets/css/page.css`, included inline via PHP require. Changes require browser refresh.

### JavaScript Development

Scripts are in `assets/js/script.js`, included inline. Use browser dev tools for debugging.

---

## See Also

- [API Reference](API_REFERENCE.md) - REST API documentation
- [CLI Reference](CLI_REFERENCE.md) - Command-line interface documentation
- [Docker GUI Development](DOCKER_GUI_DEVELOPMENT.md) - GUI architecture guide
- [Error Codes](ERROR_CODES.md) - Complete error code reference
