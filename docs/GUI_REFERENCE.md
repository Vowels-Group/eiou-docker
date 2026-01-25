# EIOU GUI Reference

Complete documentation for the EIOU Docker node web-based GUI.

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

The EIOU GUI is a server-rendered PHP web application optimized for Tor Browser compatibility. It follows an MVC-inspired pattern with controllers handling POST requests and HTML templates rendering the view.

### Request Flow

```
Browser Request
       |
       v
   walletIndex.php (Entry Point)
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
| AJAX | Limited use for specific features (ping, debug report) |

---

## Directory Structure

```
files/src/gui/
├── controllers/                    # Request handlers
│   ├── ContactController.php       # Contact CRUD operations
│   ├── TransactionController.php   # Transaction processing
│   └── SettingsController.php      # Settings and debug operations
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
│       ├── header.html             # Page header with logout
│       ├── notifications.html      # Toast and banner notifications
│       ├── quickActions.html       # Quick action cards
│       ├── walletInformation.html  # Balance and address display
│       ├── eiouForm.html           # Send transaction form
│       ├── contactForm.html        # Add contact form
│       ├── contactSection.html     # Contact list and modal
│       ├── transactionHistory.html # Transaction list and modal
│       ├── settingsSection.html    # Settings form and debug panel
│       └── floatingButtons.html    # Back-to-top and refresh buttons
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
| `handleAddContact()` | `addContact` | Add new contact | `address`, `name`, `fee`, `credit`, `currency` |
| `handleAcceptContact()` | `acceptContact` | Accept pending request | `contact_address`, `contact_name`, `contact_fee`, `contact_credit`, `contact_currency` |
| `handleDeleteContact()` | `deleteContact` | Delete contact | `contact_address` |
| `handleBlockContact()` | `blockContact` | Block contact | `contact_address` |
| `handleUnblockContact()` | `unblockContact` | Unblock contact | `contact_address` |
| `handleEditContact()` | `editContact` | Update contact settings | `contact_address`, `contact_name`, `contact_fee`, `contact_credit`, `contact_currency` |
| `handlePingContact()` | `pingContact` | Check contact status (AJAX) | `contact_address` |

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

---

### TransactionController

Handles transaction operations.

| Method | Action Value | Description | Parameters |
|--------|-------------|-------------|------------|
| `handleSendEIOU()` | `sendEIOU` | Send eIOU transaction | `recipient` or `manual_recipient`, `address_type`, `amount`, `currency`, `description` |
| `handleCheckUpdates()` | GET `check_updates=1` | Poll for updates | `last_check` (timestamp) |

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

**Available Settings:**

| Setting | Type | Description |
|---------|------|-------------|
| `defaultCurrency` | string | Default currency code |
| `defaultFee` | float | Default fee percentage |
| `minFee` | float | Minimum fee amount |
| `maxFee` | float | Maximum fee percentage |
| `defaultCreditLimit` | float | Default credit limit |
| `maxP2pLevel` | int | Maximum P2P routing hops |
| `p2pExpiration` | int | P2P request timeout (seconds) |
| `maxOutput` | int | Max display lines |
| `defaultTransportMode` | string | Preferred transport (http/https/tor) |
| `autoRefreshEnabled` | bool | Auto-refresh when transactions pending |

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

**Include Order:**
1. header.html
2. notifications.html
3. quickActions.html
4. walletInformation.html
5. eiouForm.html
6. contactForm.html
7. contactSection.html
8. transactionHistory.html
9. settingsSection.html
10. floatingButtons.html

---

### walletSubParts Components

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
| In-progress banner | Shows pending transaction count |
| Pending contacts banner | Shows pending contact request count |
| Completed transaction toasts | Notifications for finished transactions |
| DLQ notifications | Dead letter queue failure alerts |

---

#### quickActions.html

Quick navigation cards linking to main sections:

| Card | Target Section |
|------|----------------|
| Send eIOU | `#send-form` |
| Add Contact | `#add-contact` |
| View Contacts | `#contacts` |
| Transaction History | `#transactions` |
| Settings | `#settings` |

---

#### walletInformation.html

| Element | Purpose |
|---------|---------|
| Last updated timestamp | Shows data freshness |
| Total Balance | Aggregated wallet balance |
| Total Earnings | P2P routing earnings |
| User Addresses | HTTP/HTTPS/Tor with copy buttons |
| Public Key | Wallet public key with copy button |
| Status | Always "Active" |

---

#### eiouForm.html

| Field | Type | Description |
|-------|------|-------------|
| recipient | select | Contact dropdown |
| address_type | select | Address type for selected contact |
| manual_recipient | text | Direct address entry (P2P) |
| amount | number | Transaction amount |
| currency | select | Currency code |
| description | text | Optional memo |

**Features:**
- P2P routing information alert
- Dynamic address type selector
- Transaction type indicator

---

#### contactForm.html

| Field | Type | Description |
|-------|------|-------------|
| address | text | Contact node address |
| name | text | Display name |
| credit | number | Credit limit (default from settings) |
| fee | number | Fee percentage (default from settings) |
| currency | select | Currency code |

---

#### contactSection.html

**Contact Grid:**
- Accepted contacts with balance
- Pending user contacts (outgoing)
- Blocked contacts
- Search/filter functionality
- Show more toggle (>16 contacts)

**Contact Modal (Tabbed):**

| Tab | Contents |
|-----|----------|
| Info | Balance, credit limit, fee, online status, chain status, addresses, public key |
| Transactions | Recent transactions with this contact |
| Settings | Edit form, block/unblock/delete buttons |

**Pending Contact Requests Section:**
- Lists incoming requests
- Accept form with name/fee/credit fields
- Delete/block options

---

#### transactionHistory.html

**In-Progress Section:**
- Phase indicators (pending, route_search, route_found, sending, syncing)
- P2P vs Direct badges
- Held transaction notices

**Transaction List:**
- Status badges (pending, sent, accepted, completed, rejected, cancelled)
- Type badges (Contact, P2P, Direct)
- Click to open detail modal

**Transaction Modal:**
- Full transaction details
- Copy buttons for addresses/txid

**Auto-Refresh:**
- Polls every 15 seconds when transactions pending
- Controlled by `autoRefreshEnabled` setting

---

#### settingsSection.html

**Settings Form:**
- All wallet configuration options
- Auto-refresh toggle switch
- Save/Reset buttons

**Debug Section (Tabbed):**

| Tab | Contents |
|-----|----------|
| App Logs | Debug entries from database |
| EIOU Log | `/var/log/eiou/app.log` |
| PHP Logs | PHP error log |
| Apache Logs | Apache error log |
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
| `checkSessionTimeout()` | Enforce 30-minute inactivity limit |
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
| Session timeout | 30 minutes of inactivity |
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

**Address Priority:** Tor > HTTPS > HTTP (security preference)

---

## Known Limitations

| Limitation | Description | Workaround |
|------------|-------------|------------|
| No WebSockets | Tor Browser blocks WebSockets | Polling-based updates |
| Limited JavaScript | Tor Browser security settings | Server-side rendering |
| Session-based auth | No persistent login | Re-authenticate on browser close |
| Single currency display | USD hardcoded in some selects | Future multi-currency support |
| No real-time updates | Page refresh required | Auto-refresh when enabled |
| Large contact lists | Performance with >100 contacts | Pagination/virtualization planned |
| Debug log size | Full report slow over Tor | Limited report option available |

---

## Development Setup

### Volume Mount

During development, mount the source directory:

```yaml
volumes:
  - ./files/src:/etc/eiou/src:ro
```

### File Paths

| Context | Path Prefix |
|---------|-------------|
| Inside container | `/etc/eiou/src/gui/` |
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
