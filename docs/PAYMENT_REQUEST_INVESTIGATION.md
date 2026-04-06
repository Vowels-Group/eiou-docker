# Payment Request System — Investigation & Design

**Branch**: `eiou-docker-feat-payment-requests`
**Date**: 2026-04-06
**Status**: Pre-implementation investigation

---

## Table of Contents

1. [Feature Definition](#1-feature-definition)
2. [Existing Transaction System](#2-existing-transaction-system)
3. [Message & Transport System](#3-message--transport-system)
4. [Database Patterns](#4-database-patterns)
5. [GUI Patterns](#5-gui-patterns)
6. [API & CLI Patterns](#6-api--cli-patterns)
7. [Proposed Design](#7-proposed-design)
8. [Files to Create / Modify](#8-files-to-create--modify)
9. [Open Questions](#9-open-questions)

---

## 1. Feature Definition

A **payment request** allows a user to ask a contact to pay them a specific amount. The flow is:

1. **Requester** creates a payment request (amount, currency, optional description) and sends it to a contact.
2. **Recipient** receives the request and sees it in their GUI (new tab or notification).
3. **Recipient** approves — triggering a normal `sendEIOU` transaction to the requester — or declines.
4. **Requester** gets notified of the outcome.

This is a common feature in payment apps (Venmo requests, PayPal invoice, Splitwise). It does not exist yet in eIOU.

---

## 2. Existing Transaction System

### 2.1 `sendEIOU` End-to-End Flow

```
GUI/CLI/API
    → TransactionController::handleSendEIOU()
    → TransactionService::sendEiou()
    → SendOperationService
        → handleDirectRoute()   (known contact, single hop)
        → handleP2pRoute()      (unknown/multi-hop via P2P mesh)
    → TransportUtilityService::send() [blocking cURL]
    → Full page reload
```

**Data prepared for every transaction:**
```php
[
    'txType'               => 'standard' | 'p2p',
    'time'                 => microtime,
    'currency'             => 'USD',
    'amount'               => SplitAmount,
    'txid'                 => hash(senderPubkey + receiverPubkey + amount + time),
    'previousTxid'         => last txid in chain (chain integrity),
    'receiverAddress'      => resolved http/https/tor address,
    'receiverPublicKey'    => contact's public key,
    'memo'                 => 'standard' | P2P routing hash,
    'description'          => user text (optional),
    'end_recipient_address'=> final destination,
    'initial_sender_address'=> sender's own address,
]
```

### 2.2 `transactions` Table Schema

Key columns (from `DatabaseSchema::getTransactionsTableSchema()`):

| Column | Type | Notes |
|---|---|---|
| `tx_type` | ENUM | `standard`, `p2p`, `contact` |
| `type` | ENUM | `received`, `sent`, `relay` |
| `status` | ENUM | see below |
| `amount_whole` | BIGINT | integer part |
| `amount_frac` | BIGINT | fractional × 10^8 |
| `currency` | VARCHAR(10) | |
| `txid` | VARCHAR(255) UNIQUE | SHA-256 hex |
| `previous_txid` | VARCHAR(255) | chain integrity |
| `sender_public_key` | TEXT | |
| `receiver_public_key` | TEXT | |
| `sender_signature` | TEXT | |
| `recipient_signature` | TEXT | set on acceptance |
| `memo` | TEXT | `standard` or P2P hash |
| `description` | TEXT | user-visible memo |
| `expires_at` | DATETIME(6) | optional deadline |
| `signed_message_content` | TEXT | raw JSON for sync verification |

### 2.3 Transaction Status Constants

From `Constants.php`:

```
pending → sending → sent → accepted → completed
                         ↘ rejected
                         ↘ cancelled (timed out)
                         ↘ failed    (max retries)
```

### 2.4 SplitAmount

Avoids floating-point precision issues. All amounts stored as two BIGINTs:
- `amount_whole` — integer part
- `amount_frac` — fractional part × 10^8

Factory methods: `SplitAmount::fromString("1234.56")`, `::fromDb($whole, $frac)`, `::from($value)`.

Operations: `add()`, `subtract()`, `multiplyPercent()`, `compareTo()`, `toArray()`.

### 2.5 P2P vs Direct

| | Direct | P2P |
|---|---|---|
| Recipient | Known contact | Unknown or multi-hop |
| Routing | Single cURL to contact | Inquiry → proposals → chosen route |
| Speed | Fast (1 hop) | Slower (multi-hop negotiation) |
| Fee | Contact's standard fee | Negotiated per route |

---

## 3. Message & Transport System

### 3.1 Transport

`TransportUtilityService` resolves addresses and sends via cURL:

- **Tor**: max 5 concurrent, 45s timeout (6 circuit hops per eIOU hop)
- **HTTPS/HTTP**: max 10 concurrent, 15s timeout

Priority order for sending: **Tor > HTTPS > HTTP**

### 3.2 Existing Message Types

From `Constants.php` and `files/src/schemas/payloads/`:

| Type | Payload Class | Purpose |
|---|---|---|
| `transaction` | `TransactionPayload` | Send value |
| `contact` | `ContactPayload` | Contact request/accept |
| `p2p` | `P2pPayload` | Route inquiry |
| `rp2p` | `Rp2pPayload` | Route proposal response |

A **new message type `payment_request`** would be added alongside these.

### 3.3 Message Signing

1. Payload builder creates array
2. JSON-encoded and signed with SHA-256(message + nonce) using sender's private key
3. Optional RSA encryption with recipient's public key for E2E
4. Remote node verifies signature on receipt

### 3.4 Delivery & DLQ

- Max retries: 5 (`DELIVERY_MAX_RETRIES`)
- Base delay: 2s with ±20% jitter
- On exhaustion → moved to Dead Letter Queue
- DLQ shows in Activity tab; user can retry or abandon

**Payment request messages will use the same delivery pipeline**, so retries and DLQ apply automatically.

### 3.5 Incoming Message Processing

Incoming messages arrive at the node's HTTP endpoint and are processed by:

- `TransactionMessageProcessor` — handles `transaction` type
- `P2pMessageProcessor` — handles `p2p` / `rp2p` types

A **`PaymentRequestMessageProcessor`** (or an extension to an existing processor) would handle `payment_request` type messages.

---

## 4. Database Patterns

### 4.1 Schema Creation

New tables added via `DatabaseSchema.php` + migration in `DatabaseSetup.php`:

```php
// DatabaseSchema.php
public static function getPaymentRequestsTableSchema(): string {
    return "CREATE TABLE IF NOT EXISTS payment_requests ( ... )";
}

// DatabaseSetup.php — run once when SCHEMA_VERSION bumps
private function migrateToVersion5(): void {
    $this->pdo->exec(DatabaseSchema::getPaymentRequestsTableSchema());
}
```

`SCHEMA_VERSION` in `Constants.php` must be incremented (currently 4 → 5).

### 4.2 Repository Pattern

All repositories extend `AbstractRepository`:

```php
class PaymentRequestRepository extends AbstractRepository {
    protected array $allowedColumns = ['id', 'request_id', 'status', ...];

    public function create(array $data): string { ... }
    public function getById(string $requestId): ?array { ... }
    public function getPendingIncoming(string $pubkeyHash): array { ... }
    public function getPendingOutgoing(): array { ... }
    public function updateStatus(string $requestId, string $status): bool { ... }
}
```

Split-amount columns automatically handled by `mapDbRow()` in `AbstractRepository`.

### 4.3 Registration

Add to `RepositoryFactory::get()` and inject into `ServiceContainer` / `PaymentRequestService` constructor.

---

## 5. GUI Patterns

### 5.1 Tab Structure (post tabbed-navigation merge)

Current tabs: **Dashboard | Send | Contacts | Activity | Settings**

Payment requests could go in one of two places:
- **Option A**: New dedicated **"Requests"** tab (6th tab — fits on desktop, tight on mobile)
- **Option B**: Incoming requests shown as a **notification banner** + section inside the **Send** tab

**Recommendation: Option A** — a dedicated tab keeps it discoverable and doesn't clutter Send.

Tab badge on "Requests" shows pending incoming count (same pattern as Activity tab's DLQ badge).

### 5.2 Adding a Tab

In `wallet.html` (desktop nav):
```html
<button type="button" class="tab-btn" data-action="switchTab" data-tab="requests">
    <i class="fas fa-hand-holding-usd"></i>
    <span class="tab-label">Requests</span>
    <?php if ($pendingRequestCount > 0): ?>
    <span class="tab-badge"><?php echo (int)$pendingRequestCount; ?></span>
    <?php endif; ?>
</button>
```

Panel:
```html
<div class="tab-panel" id="tab-panel-requests" style="display:none">
    <?php require_once("/app/eiou/src/gui/layout/walletSubParts/paymentRequestsSection.html");?>
</div>
```

Mobile nav follows the same pattern. With 6 tabs the mobile bar will be tight — labels may need to be shortened ("Requests" → "Reqs" or icon-only).

### 5.3 POST Routing in Functions.php

```php
// In Functions.php routing block:
if (in_array($action, ['createPaymentRequest', 'approvePaymentRequest', 'declinePaymentRequest', 'cancelPaymentRequest'])) {
    $paymentRequestController = new PaymentRequestController($session, $serviceContainer);
    $paymentRequestController->routeAction($action);
}
```

### 5.4 Flash Messages

After approve/decline, controller sets session flash and redirects:
```php
Session::setFlash('Payment request approved — transaction sent.', 'success');
header('Location: /gui/');
exit;
```

Rendered automatically by `notifications.html`.

### 5.5 TAB_HASH_MAP update

In `script.js`:
```javascript
var TAB_HASH_MAP = {
    ...
    'requests':          { tab: 'requests' },
    'payment-requests':  { tab: 'requests' },
};
```

---

## 6. API & CLI Patterns

### 6.1 API Endpoint Layout

New endpoints under `/api/v1/requests/`:

| Method | Path | Permission | Action |
|---|---|---|---|
| `GET` | `/api/v1/requests` | `wallet:read` | List all requests (incoming + outgoing) |
| `POST` | `/api/v1/requests` | `wallet:send` | Create a payment request |
| `GET` | `/api/v1/requests/{id}` | `wallet:read` | Get request details |
| `POST` | `/api/v1/requests/approve` | `wallet:send` | Approve (triggers sendEIOU) |
| `POST` | `/api/v1/requests/decline` | `wallet:send` | Decline a request |
| `DELETE` | `/api/v1/requests/{id}` | `wallet:send` | Cancel own outgoing request |

Added to `ApiController::handleRequest()` routing switch under `case 'requests':`.

### 6.2 CLI Commands

```
eiou requests list
eiou requests create <contact> <amount> <currency> [--description "..."]
eiou requests approve <request-id>
eiou requests decline <request-id>
eiou requests cancel <request-id>
```

New `CliPaymentRequestService` following the same pattern as `CliDlqService` / `CliP2pApprovalService`.

---

## 7. Proposed Design

### 7.1 Proposed `payment_requests` Table

```sql
CREATE TABLE payment_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id VARCHAR(64) UNIQUE NOT NULL,        -- SHA-256 hex, like txid
    direction ENUM('incoming', 'outgoing') NOT NULL,
    status ENUM('pending', 'approved', 'declined', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',

    -- Who is involved
    requester_pubkey_hash VARCHAR(64) NOT NULL,    -- person who wants payment
    recipient_pubkey_hash VARCHAR(64) NOT NULL,    -- person being asked to pay

    -- What is requested
    amount_whole BIGINT NOT NULL,
    amount_frac  BIGINT NOT NULL DEFAULT 0,
    currency     VARCHAR(10) NOT NULL,
    description  VARCHAR(255) DEFAULT NULL,

    -- Lifecycle
    created_at   DATETIME(6) NOT NULL,
    expires_at   DATETIME(6) DEFAULT NULL,         -- optional expiry
    responded_at DATETIME(6) DEFAULT NULL,

    -- Result (if approved)
    resulting_txid VARCHAR(64) DEFAULT NULL,       -- txid of the created transaction

    -- Raw signed message (for verification / DLQ replay)
    signed_message_content TEXT DEFAULT NULL,

    INDEX idx_requester (requester_pubkey_hash),
    INDEX idx_recipient (recipient_pubkey_hash),
    INDEX idx_status (status)
)
```

### 7.2 New Message Type

A `payment_request` message type, with two sub-types:

**`payment_request_incoming`** — sent from requester to recipient:
```json
{
    "type": "message",
    "typeMessage": "payment_request",
    "requestId": "<64-char hex>",
    "requesterAddress": "http://...",
    "requesterPublicKey": "...",
    "amount": { "whole": 10, "frac": 50000000 },
    "currency": "USD",
    "description": "Dinner last night",
    "expiresAt": null,
    "signature": "..."
}
```

**`payment_request_response`** — sent from recipient back to requester:
```json
{
    "type": "message",
    "typeMessage": "payment_request_response",
    "requestId": "<64-char hex>",
    "outcome": "approved" | "declined",
    "txid": "<txid if approved>",
    "signature": "..."
}
```

### 7.3 Status Flow

```
[Requester creates]
    → status: pending (stored locally as outgoing)
    → message sent to recipient

[Recipient receives]
    → stored locally as incoming, status: pending
    → appears in Requests tab with badge

[Recipient approves]
    → normal sendEIOU() triggered (recipient → requester)
    → status updated: approved
    → response message sent back to requester

[Recipient declines]
    → status updated: declined
    → response message sent back

[Requester receives response]
    → outgoing request status updated
    → flash notification shown

[Optional: expiry]
    → cleanup job marks expired requests
```

### 7.4 GUI Layout (Requests Tab)

```
┌─────────────────────────────────────────────────────────┐
│  💸 Payment Requests                                    │
├─────────────────────────────────────────────────────────┤
│  ┌─── REQUEST PAYMENT ─────────────────────────────┐   │
│  │  Contact: [dropdown]  Amount: [___]  [USD ▼]    │   │
│  │  Description: [optional text]                   │   │
│  │  [Send Request]                                 │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  INCOMING REQUESTS (2 pending)                          │
│  ┌─────────────────────────────────────────────────┐   │
│  │ 🔴 Alice wants 25.00 USD  "Coffee & lunch"      │   │
│  │    Received: 2026-04-06 14:23                   │   │
│  │    [Approve & Pay]  [Decline]                   │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  YOUR OUTGOING REQUESTS                                 │
│  ┌─────────────────────────────────────────────────┐   │
│  │ ⏳ Requested 10.00 USD from Bob  "Taxi"         │   │
│  │    Sent: 2026-04-05 09:10  · Pending            │   │
│  │    [Cancel]                                     │   │
│  │─────────────────────────────────────────────────│   │
│  │ ✅ Received 50.00 USD from Carol  "Rent share"  │   │
│  │    Approved: 2026-04-04 18:45                   │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### 7.5 Approve Flow Detail

When a user clicks **"Approve & Pay"**:
1. POST `approvePaymentRequest` with `request_id`
2. `PaymentRequestController` calls `PaymentRequestService::approve()`
3. Service calls `TransactionService::sendEiou()` with the requester's address and the requested amount
4. On success: marks request `approved`, stores `resulting_txid`, sends response message back
5. Redirects with flash "Payment of 25.00 USD sent to Alice"

This reuses the **entire existing send pipeline** — no new transaction logic needed.

---

## 8. Files to Create / Modify

### 8.1 New Files

| File | Purpose |
|------|---------|
| `files/src/database/PaymentRequestRepository.php` | DB access for payment_requests table |
| `files/src/services/PaymentRequestService.php` | Business logic: create, approve, decline, cancel |
| `files/src/services/cli/CliPaymentRequestService.php` | CLI command handlers |
| `files/src/schemas/payloads/PaymentRequestPayload.php` | Message payload builder |
| `files/src/gui/controllers/PaymentRequestController.php` | GUI POST handler |
| `files/src/gui/layout/walletSubParts/paymentRequestsSection.html` | Requests tab UI |
| `tests/Unit/Services/PaymentRequestServiceTest.php` | Service unit tests |
| `tests/Unit/Repositories/PaymentRequestRepositoryTest.php` | Repository unit tests |

### 8.2 Files to Modify

| File | Change |
|------|--------|
| `files/src/core/Constants.php` | Add `payment_request` message type, status constants, bump `SCHEMA_VERSION` to 5 |
| `files/src/database/DatabaseSchema.php` | Add `getPaymentRequestsTableSchema()` |
| `files/src/database/DatabaseSetup.php` | Add `migrateToVersion5()` |
| `files/src/database/RepositoryFactory.php` | Register `PaymentRequestRepository` |
| `files/src/services/ServiceContainer.php` | Register `PaymentRequestService` |
| `files/src/gui/functions/Functions.php` | Route new POST actions, pass `$pendingRequestCount` and `$paymentRequests` to template |
| `files/src/gui/layout/wallet.html` | Add Requests tab button + panel (desktop + mobile) |
| `files/src/gui/assets/js/script.js` | Add `requests` to `TAB_HASH_MAP` |
| `files/src/api/ApiController.php` | Add `/api/v1/requests/` endpoint handlers |
| `files/src/services/CliService.php` | Wire `CliPaymentRequestService` |
| `CHANGELOG.md` | Document feature under `[Unreleased]` |
| `docs/GUI_REFERENCE.md` | Document new tab and actions |
| `docs/GUI_QUICK_REFERENCE.md` | Update layout components table |

---

## 9. Open Questions

These need decisions before or during implementation:

1. **Expiry**: Should payment requests expire automatically? If so, what default TTL? (Suggested: 7 days, configurable in settings)

2. **Non-contact requests**: Can you send a payment request to someone who is not yet a contact? (Simplest: contacts-only for v1)

3. **P2P requests**: Can requests be sent via P2P routing (to non-contacts)? (Suggested: no for v1 — keep it simple)

4. **Amount limits**: Should requests be subject to the same `maxOutput` / credit limit checks that sends are? (Probably not at request-creation time, only at approval time)

5. **Mobile tab count**: Adding a 6th tab will make the mobile bottom bar very tight. Options:
   - Shorten labels (e.g. "Reqs" instead of "Requests")
   - Move to icon-only on mobile for the requests tab
   - Show requests as a notification banner in the existing Contacts tab instead

6. **Notification on receipt**: Currently there is no push mechanism — the recipient only sees it on next page load. The existing ContactStatus polling cycle could be extended to check for pending payment requests, or we can rely on the tab badge appearing on next load.

7. **Request history**: How many completed/declined requests to keep? Match `displayRecentTransactionsLimit` setting or separate?

8. **Currency compatibility**: Should the request validate that the recipient has the requested currency enabled? (Yes — check at creation time and show an error if not compatible)
