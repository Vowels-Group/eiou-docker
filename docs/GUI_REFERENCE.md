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
| AJAX | Limited use for specific features (ping, chain drop, debug report) |

---

## Directory Structure

```
files/src/gui/
├── controllers/                    # Request handlers
│   ├── ContactController.php       # Contact CRUD operations
│   ├── TransactionController.php   # Transaction processing
│   ├── SettingsController.php      # Settings and debug operations
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
│       ├── settingsSection.html     # Settings form and debug panel
│       ├── floatingButtons.html     # Back-to-top and refresh buttons
│       └── analyticsConsentModal.html # One-time analytics opt-in modal
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
| `handleAcceptChainDrop()` | `acceptChainDrop` | Accept chain drop proposal (AJAX) | `proposal_id` |
| `handleRejectChainDrop()` | `rejectChainDrop` | Reject chain drop proposal (AJAX) | `proposal_id` |
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
| `handleClearDebugLogs()` | `clearDebugLogs` | Clear debug entries | None |
| `handleSendDebugReport()` | `sendDebugReport` | Generate debug file | `description` |
| `handleGetDebugReportJson()` | `getDebugReportJson` | Download debug JSON (AJAX) | `description`, `report_mode` |
| `handleAnalyticsConsent()` | `analyticsConsent` | Save one-time analytics consent choice (AJAX) | `consent` (0 or 1) |

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
| `autoBackupEnabled` | bool | Enable automatic daily database backups |
| `updateCheckEnabled` | bool | Check Docker Hub daily for newer versions (read-only API call) |
| `autoAcceptTransaction` | bool | Auto-accept P2P transactions when route found (when OFF, transactions pause at `awaiting_approval` for user review in both fast and best-fee modes) |
| `syncChunkSize` | int | Transactions per sync chunk (10-500) |
| `syncMaxChunks` | int | Max sync chunks per cycle (10-1000) |
| `heldTxSyncTimeoutSeconds` | int | Held tx sync timeout in seconds (30-299) |

**Advanced Settings** are organized into categories via a dropdown selector:

| Category | Settings |
|----------|----------|
| Feature Toggles | `hopBudgetRandomized`, `contactStatusEnabled`, `contactStatusSyncOnPing`, `autoChainDropPropose`, `autoChainDropAccept`, `autoChainDropAcceptGuard`, `autoAcceptRestoredContact`, `autoRejectUnknownCurrency`, `apiEnabled`, `autoRefreshEnabled`, `autoAcceptTransaction`, `autoBackupEnabled`, `updateCheckEnabled`, `analyticsEnabled` |
| Backup & Logging | `backupCronTime`, `backupRetentionCount`, `logMaxEntries`, `logLevel` |
| Data Retention | `cleanupDeliveryRetentionDays`, `cleanupDlqRetentionDays`, `cleanupHeldTxRetentionDays`, `cleanupRp2pRetentionDays`, `cleanupMetricsRetentionDays` |
| Rate Limiting | `p2pRateLimitPerMinute`, `rateLimitMaxAttempts`, `rateLimitWindowSeconds`, `rateLimitBlockSeconds` |
| Sync | `syncChunkSize`, `syncMaxChunks`, `heldTxSyncTimeoutSeconds` |
| Network | `httpTransportTimeoutSeconds`, `torTransportTimeoutSeconds`, `torCircuitMaxFailures`, `torCircuitCooldownSeconds`, `torFailureTransportFallback`, `torFallbackRequireEncrypted`, `maxP2pLevel`, `p2pExpiration`, `directTxExpiration`, `apiCorsAllowedOrigins` |
| Currency | `allowedCurrencies` |
| Display | `displayDecimals`, `displayDateFormat`, `displayRecentTransactionsLimit`, `maxOutput`, `sessionTimeoutMinutes` |

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
| In-progress banner | Shows pending transaction count |
| Pending contacts banner | Shows pending contact request count |
| Pending currency requests | Shows incoming currency requests from existing contacts |
| Chain drop proposal banner | Incoming proposals requiring action (red alert) |
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
- Chain drop proposal badges on contact cards:
  - Red "Action Required" — incoming proposal needs acceptance
  - Blue "Awaiting Response" — outgoing proposal pending
  - Orange "Blocked" — rejected proposal, transactions blocked
  - Yellow triangle — chain invalid, no proposal yet

**Contact Modal (Tabbed):**

| Tab | Contents |
|-----|----------|
| Info | Per-currency balance, credit limit, fee, your/their available credit (via horizontal currency slider pills), addresses (with Copy and QR code buttons; QR regenerates when switching address types), public key (single-line display with Copy button), contact ID |
| Transactions | Recent transactions with this contact |
| Status | Online status, chain status (proposal-aware, clickable — switches to this tab), Check Status button, chain drop resolution section (propose/accept/reject) |
| Settings | Edit form, block/unblock/delete buttons |

**Chain Drop Resolution Section (in Info tab):**
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

**Transaction Modal:**
- Full transaction details
- Copy buttons for addresses/txid

**Auto-Refresh:**
- Polls every 15 seconds when transactions pending
- Controlled by `autoRefreshEnabled` setting

---

#### dlqSection.html

The Dead Letter Queue section displays messages that could not be delivered after all automatic retry attempts.

**Status Filter:** Dropdown matching contacts/transactions pattern — Any status (default), Pending & Retrying, Pending Only, Resolved, Abandoned.

**Stats Bar:** Per-status counts (Pending / Retrying / Resolved / Abandoned).

**Table Columns:** Type, Recipient, Failure Reason (truncated), Added, Status, Actions. Uses `contacts-table` chrome with 60vh scrollable wrapper. Clicking any row opens a detail modal with all fields + Retry/Abandon buttons.

**Mobile (≤600px):** Shows Type + Recipient. Tap row for detail modal with full info and actions. Action buttons and status collapse to icon-only at ≤900px.

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

#### settingsSection.html

**Settings Form:**
- Basic wallet settings (currency, fee, credit limit, transport mode)
- Collapsible Advanced Settings with category dropdown (Feature Toggles, Backup & Logging, Data Retention, Rate Limiting, Sync, Network, Currency, Display)
- Save/Reset buttons

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
