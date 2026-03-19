# eIOU CLI Reference

Complete command-line interface documentation for the eIOU Docker node.

## Table of Contents

1. [Overview](#overview)
2. [Global Options](#global-options)
3. [Wallet Commands](#wallet-commands)
4. [Contact Commands](#contact-commands)
5. [Transaction Commands](#transaction-commands)
6. [Dead Letter Queue](#dead-letter-queue)
7. [Settings Commands](#settings-commands)
8. [System Commands](#system-commands)
9. [API Key Management](#api-key-management)
10. [Chain Drop Commands](#chain-drop-commands)
11. [Backup Commands](#backup-commands)
12. [Report Commands](#report-commands)
13. [Test Mode Commands](#test-mode-commands)
14. [Exit Codes](#exit-codes)
15. [Rate Limiting](#rate-limiting)

---

## Overview

The eIOU CLI provides a command-line interface for interacting with an eIOU wallet node.

**Usage:**
```bash
eiou <command> [arguments] [options]
```

All commands support JSON output mode for scripting and automation.

---

## Global Options

These options are available for all commands:

| Option | Description |
|--------|-------------|
| `--json`, `-j` | Output results in JSON format for scripting/automation |
| `--no-metadata` | Exclude metadata (timestamp, node_id) from JSON output |

**Example:**
```bash
eiou info --json
eiou viewbalances -j --no-metadata
```

### JSON Output Structure

When using `--json`, all commands return a consistent response format:

```json
{
    "success": true,
    "data": {
        // Command-specific response data
    },
    "metadata": {
        "timestamp": "2026-01-24T17:45:00Z",
        "node_id": "http://alice",
        "command": "viewbalances"
    }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether the command executed successfully |
| `data` | object | Command-specific response data |
| `metadata` | object | Request metadata (excluded with `--no-metadata`) |
| `metadata.timestamp` | string | ISO 8601 timestamp of the response |
| `metadata.node_id` | string | Node identifier (hostname/address) |
| `metadata.command` | string | The command that was executed |

**Error Response:**
```json
{
    "success": false,
    "error": {
        "code": "INSUFFICIENT_BALANCE",
        "message": "Insufficient funds for this transaction"
    }
}
```

---

## Wallet Commands

### generate (startup only)

Wallet generation and restoration are handled automatically by `startup.sh` during container initialization. By the time the CLI is accessible, a wallet already exists, so running `eiou generate` will always return a "wallet already exists" error.

**Wallet creation** is configured via Docker environment variables:

| Variable | Description |
|----------|-------------|
| `QUICKSTART` | Hostname for quickstart mode (e.g., `alice`) — auto-generates wallet on first boot |
| `EIOU_HOST` | Override hostname (takes priority over `QUICKSTART`) |
| `EIOU_NAME` | Display name for the node |
| `RESTORE` | BIP39 seed phrase (24 words) to restore an existing wallet |
| `RESTORE_FILE` | Path to file containing seed phrase (recommended — more secure) |

**Restoring contacts from a prior wallet:**
After restoring a wallet from a seed phrase, your previous contacts are not immediately present. When a prior contact pings or sends a message to your restored node, the ContactStatusService automatically creates a pending contact entry and triggers a sync to restore the shared transaction chain. The restored contact appears as a pending request (visible via `eiou pending`) that you can re-accept with the `add` command.

---

### info

Display wallet information including addresses, public key, fee earnings, and available credit.

**Syntax:**
```bash
eiou info [detail] [--show-auth]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `detail` | optional | Show detailed balance information with transaction breakdowns |
| `--show-auth` | optional | Securely display the authentication code via temp file |

**Examples:**
```bash
# Basic wallet info (auth code redacted)
eiou info

# Show authentication code securely
eiou info --show-auth

# Detailed info with balances
eiou info detail

# JSON output with auth code file path
eiou info --show-auth --json
```

**Output includes:**
- HTTP, HTTPS, and Tor addresses (locators)
- Authentication code status (redacted by default)
- Public key
- Total fee earnings per currency (P2P relay fee earnings)
- Total available credit per currency (sum of credit available through all contacts, received via ping/pong)
- (With `detail`) Total balances by currency with sent/received breakdown

**Security: Authentication Code Handling**

The authentication code is a sensitive credential that is **never** exposed directly in command output. This prevents accidental exposure through:
- Docker logs (`docker logs <container>`)
- Shell history and command output redirection
- Log aggregation systems
- Screenshots or screen sharing

When `--show-auth` is used, the code is stored in a secure temporary file:

1. **TTY Mode** (interactive terminal): Displays directly to terminal, bypassing Docker's log capture
2. **Non-TTY/JSON Mode**: Stores in `/dev/shm/` (memory-only tmpfs) with auto-deletion after 5 minutes

**Retrieving the authentication code:**
```bash
# The command outputs the file path
docker exec alice eiou info --show-auth

# Then retrieve it manually
docker exec alice cat /dev/shm/eiou_authcode_<random>

# Delete after use (or wait for auto-deletion)
docker exec alice rm /dev/shm/eiou_authcode_<random>
```

**JSON response format with `--show-auth`:**
```json
{
  "authentication_code": {
    "status": "stored_securely",
    "method": "file",
    "filepath": "/dev/shm/eiou_authcode_abc123...",
    "ttl_seconds": 300,
    "message": "Authentication code stored in secure temp file"
  }
}
```

---

### overview

Display a dashboard summary with balances and recent transactions.

**Syntax:**
```bash
eiou overview [limit]
```

**Arguments:**

| Argument | Type | Default | Description |
|----------|------|---------|-------------|
| `limit` | optional | 5 | Number of recent transactions to display |

**Examples:**
```bash
# Default overview (5 recent transactions)
eiou overview

# Show 10 recent transactions
eiou overview 10

# JSON output
eiou overview --json
```

---

## Contact Commands

### add

Add a new contact or accept an incoming contact request.

**Syntax:**
```bash
eiou add <address> <name> <fee> <credit> <currency> [message]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address` | required | Contact's node address (HTTP, HTTPS, or Tor) |
| `name` | required | Display name for the contact (use quotes for multi-word names, e.g., `"Jane Doe"`) |
| `fee` | required | Fee percentage for transactions (e.g., 1.0) |
| `credit` | required | Credit limit for this contact |
| `currency` | required | Currency code, 3-9 uppercase alphanumeric characters (e.g., USD, EIOU) |
| `message` | optional | A short message sent with the contact request |

**Examples:**
```bash
# Add a new contact
eiou add http://bob:8080 Bob 1.0 100 USD

# Add with a message
eiou add http://bob:8080 Bob 1.0 100 USD "Hey, it's Dave!"

# Add with a multi-word name
eiou add http://bob:8080 "Jane Doe" 1.0 100 USD

# Add via Tor address
eiou add abc123...onion Alice 0.5 500 USD

# JSON output
eiou add http://charlie:8080 Charlie 1 200 USD --json
```

**Notes:**
- Creates a pending contact request that the recipient must accept
- To accept an incoming request, use `add` with the sender's address
- Each currency request is tracked independently with a direction (`incoming`/`outgoing`) in the `contact_currencies` table
- Cross-currency requests are supported: Alice can request USD from Bob while Bob requests GBY from Alice — each side accepts independently
- Re-running `add` with a different currency for an existing pending contact updates the outgoing currency request
- Rate limited: 20 additions per minute

---

### viewcontact

View detailed information about a specific contact.

**Syntax:**
```bash
eiou viewcontact <address|name>
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Contact's address or display name |

**Examples:**
```bash
eiou viewcontact Bob
eiou viewcontact http://bob:8080
eiou viewcontact --json Bob
```

**Output includes:**
- Contact name, status, addresses
- Balance (received, sent, net)
- Fee percentage and credit limit
- Your available credit with them per currency (received via ping/pong, stored in `contact_credit`, ~5 min refresh)
- Their available credit with you per currency (calculated: credit_limit - balance)

**On Failure (JSON):**
```json
{
    "success": false,
    "error": {
        "code": "CONTACT_NOT_FOUND",
        "title": "Contact Not Found",
        "status": 404,
        "detail": "Contact not found",
        "query": "NonExistentContact"
    }
}
```

---

### update

Update contact information. Fee and credit updates require a currency parameter to specify which currency's settings to modify. Updates are applied to both the `contacts` table and the `contact_currencies` table.

**Syntax:**
```bash
eiou update <address|name> name <name>
eiou update <address|name> fee <value> <currency>
eiou update <address|name> credit <value> <currency>
eiou update <address|name> all <name> <fee> <credit> [currency]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Contact's address or display name |
| `field` | required | Field to update: `all`, `name`, `fee`, or `credit` |
| `values` | varies | New value(s) for the specified field(s) |
| `currency` | required for fee/credit | Currency code (e.g., USD, EUR). Optional for `all` (defaults to contact's current currency) |

**Examples:**
```bash
# Update contact name
eiou update Bob name Robert

# Update fee percentage for USD
eiou update Bob fee 1.5 USD

# Update credit limit for EUR
eiou update Bob credit 500 EUR

# Update all fields at once for GBY
eiou update Bob all NewName 2.0 1000 GBY
```

---

### search

Search for contacts by name.

**Syntax:**
```bash
eiou search [name]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `name` | optional | Search term (partial name match) |

**Output per contact:**
- Name, address(es), status
- Fee percentage, credit limit, currency
- Your Available Credit (from pong, how much credit they extend to you)
- Their Available Credit (calculated: how much credit you extend to them)

**Examples:**
```bash
# Search for contacts containing "bob"
eiou search bob

# List all contacts (no filter)
eiou search

# JSON output
eiou search alice --json
```

---

### ping

Check if a contact is online, verify chain validity, and retrieve available credit.

Ping compares per-currency chain heads (`prevTxidsByCurrency`) with the remote contact and also verifies local chain integrity to detect internal gaps (e.g., deleted transactions in the middle of the chain). Each currency has its own independent transaction chain. All gap detection is performed locally — no transaction lists are exchanged over the wire. The pong response includes per-currency available credit (`availableCreditByCurrency`) and per-currency chain validity (`chainStatusByCurrency`), stored locally for use by `viewcontact`, `search`, and `info`.

**Syntax:**
```bash
eiou ping <address|name>
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Contact's address or display name |

**Examples:**
```bash
eiou ping Bob
eiou ping http://bob:8080
eiou ping --json Alice
```

**Output includes:**
- Online status (`online`, `partial`, or `offline` — `partial` means the contact responded but not all processors are running)
- Chain validity status (includes internal gap detection)
- Response message

**Available credit exchange:**
The pong response includes per-currency available credit (`availableCreditByCurrency`). For each currency, the available credit is calculated as: what they sent you − what you sent them + their credit limit for you in that currency. These values are stored per-currency in the `contact_credit` table and visible via `viewcontact`, `search`, and `info`. The automatic ContactStatusProcessor also performs this exchange every ~5 minutes.

**Chain mismatch behavior:**
If any currency's local and remote chain heads don't match, or if internal gaps are detected, ping automatically triggers a sync (including backup recovery on both sides). If the sync fails to resolve the gap, a chain drop is auto-proposed. See [Chain Drop Commands](#chain-drop-commands) for details.

**Wallet restore behavior:**
When a ping is received by a node that was restored from a seed phrase, the ContactStatusService detects the incoming ping from a previously unknown address, auto-creates a pending contact, and triggers a sync to restore the shared transaction chain. The prior contact then appears as a pending request that the restored wallet owner can review via `eiou pending` and re-accept via `eiou add`. This allows prior contacts to re-establish their relationship with a restored wallet simply by pinging it.

---

### block

Block a contact from sending transactions to you. Blocked contacts cannot send you transactions or P2P requests — incoming messages from blocked contacts are rejected.

**Syntax:**
```bash
eiou block <address|name>
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Contact's address or display name to block |

**Examples:**
```bash
eiou block SpamUser
eiou block http://badactor:8080
eiou block http://badactor:8080 --json
```

**On Failure (JSON):**
```json
{
    "success": false,
    "error": {
        "code": "CONTACT_NOT_FOUND",
        "title": "Contact Not Found",
        "status": 404,
        "detail": "Contact not found for address: http://badactor:8080"
    }
}
```

---

### unblock

Unblock a previously blocked contact, allowing them to send transactions and P2P requests again.

**Syntax:**
```bash
eiou unblock <address|name>
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Contact's address or display name to unblock |

**Examples:**
```bash
eiou unblock SpamUser
eiou unblock http://user:8080 --json
```

**On Failure (JSON):**
```json
{
    "success": false,
    "error": {
        "code": "CONTACT_NOT_FOUND",
        "title": "Contact Not Found",
        "status": 404,
        "detail": "Contact not found for address: http://user:8080"
    }
}
```

---

### delete

Delete a contact permanently.

**Syntax:**
```bash
eiou delete <address|name>
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Contact's address or display name to delete |

**Examples:**
```bash
eiou delete OldContact
eiou delete http://old:8080
eiou delete OldContact --json
```

**On Failure (JSON):**
```json
{
    "success": false,
    "error": {
        "code": "CONTACT_NOT_FOUND",
        "title": "Contact Not Found",
        "status": 404,
        "detail": "Contact not found with name: OldContact"
    }
}
```

---

### pending

View all pending contact requests (incoming and outgoing).

**Syntax:**
```bash
eiou pending
```

**Examples:**
```bash
eiou pending
eiou pending --json
```

**Output includes:**
- Incoming requests (from others awaiting your acceptance)
- Outgoing requests (your requests awaiting others' acceptance)
- Count of pending requests

**Note:** After a wallet restore, prior contacts that ping your node are auto-created as pending contacts by the ContactStatusService. These appear as incoming requests in the pending list. Contacts with existing transaction history (visible after sync) are prior contacts from the previous wallet and can be re-accepted with `eiou add`.

---

### p2p

Manage P2P transactions awaiting manual approval. Used when `autoAcceptTransaction` is disabled.

**Syntax:**
```bash
eiou p2p [subcommand] [args...]
```

**Subcommands:**

| Subcommand | Syntax | Description |
|------------|--------|-------------|
| *(none/list)* | `eiou p2p` | List all P2P transactions awaiting approval |
| `candidates` | `eiou p2p candidates <hash>` | Show route candidates for a transaction |
| `approve` | `eiou p2p approve <hash> [index]` | Approve and send a P2P transaction |
| `reject` | `eiou p2p reject <hash>` | Reject and cancel a P2P transaction |

**Examples:**
```bash
# List pending P2P transactions
eiou p2p
eiou p2p --json

# View route candidates for a transaction
eiou p2p candidates abc123def456

# Approve a single-route P2P (fast mode)
eiou p2p approve abc123def456

# Approve using a specific candidate route (best-fee mode)
eiou p2p approve abc123def456 2

# Reject and cancel a P2P transaction
eiou p2p reject abc123def456
```

**Output includes:**
- Transaction hash, amount, currency
- Route mode (fast or best-fee)
- Number of route candidates
- Total cost including relay fees

**Approval behavior:**
- **Fast mode** (single route): `eiou p2p approve <hash>` sends immediately
- **Best-fee mode** (multiple candidates): Use `eiou p2p candidates <hash>` to view options, then `eiou p2p approve <hash> <index>` to select a route
- If multiple candidates exist but no index is provided, an error is returned

**Routing mode scenarios:**

| Routing Mode | `autoAcceptTransaction` | What Happens |
|-------------|------------------------|--------------|
| Fast (default) | ON (default) | Route is auto-sent — no approval needed |
| Fast | OFF | 1 route shown, use `eiou p2p approve <hash>` |
| Best-fee (`--best`) | ON | Cheapest route is auto-sent — no approval needed |
| Best-fee | OFF | All routes listed, use `eiou p2p candidates <hash>` then `eiou p2p approve <hash> <index>` |
| Best-fee + Tor dest | OFF | Internally fast mode — 1 route shown, use `eiou p2p approve <hash>` |

**Notes:**
- P2P transactions enter `awaiting_approval` status when `autoAcceptTransaction` is `false`. Without these commands (or GUI/API equivalents), such transactions expire through normal cleanup.
- Late-arriving route candidates are still accepted while a transaction is awaiting approval and will appear in the candidate list.
- Tor destinations force fast mode internally because Tor hidden services use single-hop routing.

---

## Dead Letter Queue

### dlq

Manage the dead letter queue (DLQ) — messages that could not be delivered after all automatic retry attempts.

**Syntax:**
```bash
eiou dlq [subcommand] [id] [--status=<filter>]
```

**Subcommands:**

| Subcommand | Syntax | Description |
|------------|--------|-------------|
| *(none/list)* | `eiou dlq` | List active (pending + retrying) DLQ items |
| `list` | `eiou dlq list [--status=all]` | List items with optional status filter |
| `retry` | `eiou dlq retry <id>` | Retry delivering a failed message |
| `abandon` | `eiou dlq abandon <id>` | Mark an item as abandoned (no further retries) |

**Status filter values for `--status`:**

| Value | Description |
|-------|-------------|
| *(omitted)* | Active items: pending + retrying (default) |
| `pending` | Awaiting manual action |
| `retrying` | Currently being retried |
| `resolved` | Successfully re-delivered |
| `abandoned` | Manually discarded |
| `all` | All items regardless of status |

**Examples:**
```bash
# List active DLQ items (pending + retrying)
eiou dlq

# List all items including resolved and abandoned
eiou dlq list --status=all

# List only pending items
eiou dlq list --status=pending

# Retry a specific item (transaction or contact only)
eiou dlq retry 42

# Abandon an item (cannot be undone)
eiou dlq abandon 42

# JSON output with statistics
eiou dlq --json
```

**Output includes:**
- Item ID, message type, status, retry count
- Recipient address
- Failure reason
- Timestamp when added

---

### DLQ Message Types and Retry Eligibility

> **Important:** Not all message types can be meaningfully retried. The DLQ captures
> messages from this node only — all items represent outbound messages that this
> node tried to send.

| Type | Description | Can Retry? | Reason |
|------|-------------|:----------:|--------|
| `transaction` | Direct eIOU payment to a contact | ✅ Yes | User-initiated; signed payload remains valid |
| `contact` | Contact request sent to a peer | ✅ Yes | User-initiated; contact request remains valid |
| `p2p` | P2P routing request forwarded to a peer | ❌ No | Time-sensitive; expires in ≤300s — stale by the time retries are exhausted |
| `rp2p` | Relay response/cancel forwarded through this node | ❌ No | Relay message on behalf of others; underlying P2P transaction has expired or been resolved elsewhere |

**Why `p2p` and `rp2p` cannot be retried:**

P2P and relay messages carry an `expiration` timestamp. Automatic retries use exponential backoff (2s, 4s, 8s, 16s, 32s = ~62s minimum). The default P2P expiration is **300 seconds**. By the time automatic retries are exhausted and the message reaches the DLQ, the P2P handshake on all other nodes has already timed out or been resolved through a different route.

Retrying a stale `rp2p` relay message could deliver a response for a transaction the originating node has already cancelled or completed — causing confusion or double-processing on the recipient.

**Recommended action for `p2p`/`rp2p` DLQ items:** Use `eiou dlq abandon <id>` to clear them. The original sender's P2P transaction will have already triggered its own timeout and cleanup.

---

**Automatic lifecycle:**
- Messages enter the DLQ after `DELIVERY_MAX_RETRIES` (5) failed delivery attempts
- Resolved and abandoned records are automatically deleted after `cleanupDlqRetentionDays` (default: 90 days)
- The GUI shows a warning toast and a **Failed Messages** badge in Quick Actions whenever pending items exist

---

## Transaction Commands

### send

Send an eIOU transaction to a contact.

**Syntax:**
```bash
eiou send <address|name> <amount> <currency> [description] [--best]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Recipient's address or display name |
| `amount` | required | Amount to send (positive number) |
| `currency` | required | Currency code, 3-9 uppercase alphanumeric characters (e.g., USD, EIOU) |
| `description` | optional | Transaction description/memo text |

**Flags:**

| Flag | Description |
|------|-------------|
| `--best` | **[EXPERIMENTAL]** Use best-fee routing. Collects all P2P route responses and selects the one with the lowest accumulated fee. This feature is experimental and may be slower or less reliable than the default fast mode. **Ignored for Tor recipients** (`.onion` addresses) — fast mode is always used over Tor to avoid excessive relay overhead. |

**Examples:**
```bash
# Send by contact name (fast mode, default)
eiou send Bob 50 USD

# Send by address
eiou send http://bob:8080 100 USD

# Send with a description
eiou send Bob 50 USD "Payment for lunch"

# Send with best-fee routing (experimental)
eiou send Bob 50 USD --best

# Send with description and best-fee routing
eiou send Bob 50 USD "Invoice #123" --best

# JSON output
eiou send Alice 25.50 USD --json
```

**Notes:**
- Transaction may be direct or routed through intermediaries (P2P relay)
- Default routing uses fast mode: the first RP2P response wins (lowest latency)
- With `--best`, all RP2P responses are collected and the lowest-fee route is selected (higher latency, lower cost)
- Chain integrity is verified locally before every send; if a gap is detected, sync is attempted and then a chain drop is auto-proposed if the gap persists
- Rate limited: 30 transactions per minute

**Transport selection:**

The transport used to deliver the transaction is determined by how the recipient is specified:

| Recipient form | Example | Transport used |
|----------------|---------|----------------|
| Explicit address with scheme | `eiou send http://Bob 100 USD` | HTTP (scheme taken from address) |
| Explicit address with scheme | `eiou send https://Bob 100 USD` | HTTPS |
| Explicit address with scheme | `eiou send Bob.onion 100 USD` | Tor |
| Contact name (no scheme) | `eiou send Bob 100 USD` | `defaultTransportMode` setting (default: `tor`) |

When you pass a full address like `http://Bob`, the scheme is extracted and used directly. When you pass a contact name, no transport is implied so the wallet falls back to the `defaultTransportMode` setting (configurable via `eiou changesettings defaultTransportMode`). This is intentional: the two forms are equivalent in *who* receives the transaction, but differ in *how* it is delivered.

**On Failure (JSON):**

Contact not found:
```json
{
    "success": false,
    "error": {
        "code": "NO_CONTACTS",
        "title": "No Contacts Available",
        "status": 400,
        "detail": "No contacts available for transaction",
        "recipient": "http://unknown:8080",
        "amount": 50,
        "currency": "USD"
    }
}
```

Insufficient balance:
```json
{
    "success": false,
    "error": {
        "code": "INSUFFICIENT_FUNDS",
        "title": "Insufficient Funds",
        "status": 403,
        "detail": "Insufficient balance for this transaction"
    }
}
```

---

### viewbalances

View eIOU balances with all contacts or a specific contact.

**Syntax:**
```bash
eiou viewbalances [address|name]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | optional | Filter by specific contact |

**Examples:**
```bash
# View all balances
eiou viewbalances

# View balance with specific contact
eiou viewbalances Bob

# JSON output
eiou viewbalances --json
```

---

### history

View transaction history with all contacts or a specific contact. Output is capped by the `maxOutput` setting (default: 5 lines) unless overridden with a limit argument.

**Syntax:**
```bash
eiou history [address|name] [limit]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | optional | Filter by specific contact |
| `limit` | optional | Maximum transactions to display (0 = unlimited) |

**Examples:**
```bash
# View all transaction history
eiou history

# View history with specific contact
eiou history Bob

# View all history with Bob (no limit)
eiou history Bob 0

# JSON output
eiou history --json
```

---

## Settings Commands

### viewsettings

Display current wallet settings.

**Syntax:**
```bash
eiou viewsettings
```

**Examples:**
```bash
eiou viewsettings
eiou viewsettings --json
```

**Settings displayed (grouped by category):**
- **Transaction Settings:** Default currency, minimum/default/maximum fee percentages, default credit limit
- **P2P & Network:** Max P2P level, P2P expiration, direct TX delivery expiration, default transport mode, HTTP/Tor transport timeouts, hostname (HTTP and HTTPS), trusted proxies, auto-accept P2P transactions
- **Feature Toggles:** Display name, auto-refresh, contact status pinging, contact status sync on ping, auto chain drop propose/accept, API enabled, API CORS origins, rate limiting
- **Backup & Logging:** Auto-backup, backup retention count, backup schedule, log level, log max entries
- **Data Retention:** Delivery, DLQ, held TX, RP2P, metrics retention days
- **Rate Limiting:** P2P rate limit per minute, max attempts, window, block duration
- **Display:** Max output lines (displays "unlimited" when set to 0), date format, currency decimals, recent transactions limit
- **Currency Management:** Allowed currencies

---

### changesettings

Change wallet settings.

**Syntax:**
```bash
eiou changesettings [setting] [value]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `setting` | optional | Setting name to change (interactive mode if omitted) |
| `value` | optional | New value for the setting |

**Available Settings:**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `defaultFee` | Default fee percentage | `0.01` |
| `defaultCreditLimit` | Default credit limit for new contacts | `100` |
| `defaultCurrency` | Default currency code | `USD` |
| `minFee` | Minimum fee amount (0 = free relaying) | `0.01` |
| `maxFee` | Maximum fee percentage | `5.0` |
| `maxP2pLevel` | Maximum P2P routing hops | `3` |
| `p2pExpiration` | P2P routing request timeout (seconds); P2P transactions get an extra 120s delivery window after this expires | `300` |
| `directTxExpiration` | Direct (non-P2P) transaction delivery timeout in seconds; 0 = no expiry (default); recommended: `120` (two Tor round-trips) | `0` |
| `maxOutput` | Max display lines (0 = unlimited) | `50` |
| `defaultTransportMode` | Preferred transport | `http`, `https`, `tor` |
| `autoRefreshEnabled` | Auto-refresh transactions | `true`, `false` |
| `autoBackupEnabled` | Auto-backup database daily | `true`, `false` |
| `autoAcceptTransaction` | Auto-accept P2P transactions when route found | `true`, `false` |
| `hostname` | Node hostname (regenerates SSL cert) | `http://alice` |
| `name` | Display name for this node | `Alice` |
| `trustedProxies` | Trusted proxy IPs for header forwarding | `10.0.0.1,172.16.0.0/12` |
| `allowedCurrencies` | Allowed currencies (comma-separated) | `USD,EUR` |

**Advanced Settings (Feature Toggles):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `hopBudgetRandomized` | Randomize P2P hop depth for privacy (disable for max reachability in sparse networks) | `true`, `false` |
| `contactStatusEnabled` | Enable contact status tracking | `true`, `false` |
| `contactStatusSyncOnPing` | Sync status during ping operations | `true`, `false` |
| `autoChainDropPropose` | Auto-propose chain-drop operations | `true`, `false` |
| `autoChainDropAccept` | Auto-accept chain-drop proposals | `true`, `false` |
| `autoChainDropAcceptGuard` | Balance guard for auto-accept chain drops | `true`, `false` |
| `autoAcceptRestoredContact` | Auto-accept restored contacts on wallet restore | `true`, `false` |
| `apiEnabled` | Enable REST API endpoint | `true`, `false` |
| `apiCorsAllowedOrigins` | Allowed CORS origins for API | `https://example.com` |
| `rateLimitEnabled` | Enable rate limiting (CLI/API only — not exposed in GUI) | `true`, `false` |

**Advanced Settings (Backup & Logging):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `backupRetentionCount` | Number of backup files to keep (min 1) | `3` |
| `backupCronHour` | Backup schedule hour UTC (0-23) | `0` |
| `backupCronMinute` | Backup schedule minute (0-59) | `0` |
| `logLevel` | Minimum log level | `DEBUG`, `INFO`, `WARNING`, `ERROR`, `CRITICAL` |
| `logMaxEntries` | Max log entries to keep (min 10) | `100` |

**Advanced Settings (Data Retention):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `cleanupDeliveryRetentionDays` | Days to retain delivery records (min 1) | `30` |
| `cleanupDlqRetentionDays` | Days to retain dead letter queue entries (min 1) | `90` |
| `cleanupHeldTxRetentionDays` | Days to retain held transactions (min 1) | `7` |
| `cleanupRp2pRetentionDays` | Days to retain P2P routing records (min 1) | `30` |
| `cleanupMetricsRetentionDays` | Days to retain metrics data (min 1) | `90` |

**Advanced Settings (Rate Limiting):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `p2pRateLimitPerMinute` | Max P2P requests per minute (min 1) | `60` |
| `rateLimitMaxAttempts` | Max attempts before rate limit triggers (min 1) | `10` |
| `rateLimitWindowSeconds` | Rate limit time window in seconds (min 1) | `60` |
| `rateLimitBlockSeconds` | Block duration after limit exceeded (min 1) | `300` |

**Advanced Settings (Network):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `httpTransportTimeoutSeconds` | HTTP transport timeout (5-120) | `15` |
| `torTransportTimeoutSeconds` | Tor transport timeout (10-300) | `30` |
| `torCircuitMaxFailures` | Consecutive Tor failures before cooldown (1-10) | `3` |
| `torCircuitCooldownSeconds` | Cooldown duration after max failures (60-3600) | `300` |
| `torFailureTransportFallback` | Fall back to HTTP/HTTPS when Tor fails | `true`, `false` |
| `torFallbackRequireEncrypted` | Only fall back to HTTPS, never plain HTTP | `true`, `false` |

**Advanced Settings (Sync):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `syncChunkSize` | Transactions per sync chunk (10-500) | `50` |
| `syncMaxChunks` | Max sync chunks per cycle (10-1000) | `100` |
| `heldTxSyncTimeoutSeconds` | Held tx sync timeout in seconds (30-299) | `120` |

**Advanced Settings (Currency):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `conversionFactors` | Minor-to-major unit conversion per currency (JSON) | `{"USD":100,"BTC":100000000}` |
| `currencyDecimals` | Display decimal places per currency (JSON) | `{"USD":2,"BTC":8}` |

**Advanced Settings (Display):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `displayDateFormat` | PHP date format string | `Y-m-d H:i:s.u` |
| `displayRecentTransactionsLimit` | Recent transactions on dashboard (min 1) | `5` |

**Interactive Mode:**

Running `eiou changesettings` without arguments enters interactive mode, which displays all current settings then presents a numbered menu of all changeable settings grouped by category. All settings available via direct command-line mode are also available interactively.

**Examples:**
```bash
# Interactive mode (shows all settings, prompts for selection)
eiou changesettings

# Direct setting change
eiou changesettings defaultCurrency USD
eiou changesettings maxP2pLevel 5
eiou changesettings maxOutput 0           # Unlimited output
eiou changesettings autoRefreshEnabled true
eiou changesettings autoBackupEnabled false
eiou changesettings autoAcceptTransaction false  # Require approval before sending P2P
eiou changesettings trustedProxies "10.0.0.1,172.16.0.1"
eiou changesettings trustedProxies ""       # Clear (trust no proxies)
eiou changesettings name "My Node"
eiou changesettings allowedCurrencies "USD,EUR"

# Advanced settings
eiou changesettings logLevel WARNING
eiou changesettings backupRetentionCount 5
eiou changesettings cleanupDeliveryRetentionDays 60
eiou changesettings httpTransportTimeoutSeconds 30
eiou changesettings rateLimitEnabled false
eiou changesettings conversionFactors '{"USD":100,"BTC":100000000}'
eiou changesettings currencyDecimals '{"USD":2,"BTC":8}'
eiou changesettings torCircuitMaxFailures 5
eiou changesettings torFailureTransportFallback false
eiou changesettings contactStatusEnabled false

# JSON output
eiou changesettings defaultFee 1.5 --json
```

---

## System Commands

### sync

Synchronize data with contacts.

After syncing transactions, chain integrity is verified locally for each contact. If gaps remain (e.g., both sides are missing the same transactions), the output reports the gap count and recommends using `chaindrop` to resolve.

**Syntax:**
```bash
eiou sync [type]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `type` | optional | Sync type: `contacts`, `transactions`, `balances` |

**Examples:**
```bash
# Sync everything
eiou sync

# Sync only contacts
eiou sync contacts

# Sync only transactions (includes backup recovery)
eiou sync transactions

# Recalculate balances from transaction history
eiou sync balances
```

**Notes:**
- Transaction sync verifies chain integrity locally for each contact
- If gaps are found, backup recovery is attempted on both sides (local backups first, then remote)
- If gaps remain after recovery, the output reports the gap count and recommends using `chaindrop` to resolve

---

### help

Display help information.

**Syntax:**
```bash
eiou help [command]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `command` | optional | Specific command to get detailed help for |

**Examples:**
```bash
# General help
eiou help

# Help for specific command
eiou help send
eiou help apikey

# JSON format
eiou help --json
```

---

### shutdown

Gracefully shutdown the wallet application. Stops all message processors and sets a shutdown flag to prevent the watchdog from restarting them.

**Syntax:**
```bash
eiou shutdown
```

**Behavior:**
- Sends SIGTERM to all running processors (P2P, Transaction, Cleanup, ContactStatus)
- Removes PID and lockfiles
- Creates `/tmp/eiou_shutdown.flag` to prevent watchdog restarts
- Releases application resources (database connections, services)

Use `eiou start` to resume processor operations after a shutdown.

---

### start

Resume processor operations after a previous `eiou shutdown`.

**Syntax:**
```bash
eiou start
```

**Behavior:**
- Removes the shutdown flag (`/tmp/eiou_shutdown.flag`)
- The watchdog detects the flag removal and restarts all processors within 30 seconds
- Restart counters are reset so processors are not blocked by pre-shutdown limits
- If no shutdown flag exists (processors are already running), the command reports that and exits

---

## API Key Management

### apikey

Manage API keys for external REST API access.

**Syntax:**
```bash
eiou apikey <action> [args...]
```

**Actions:**

| Action | Syntax | Description |
|--------|--------|-------------|
| `create` | `apikey create <name> [permissions]` | Create new API key |
| `list` | `apikey list` | List all API keys |
| `delete` | `apikey delete <key_id>` | Permanently delete a key |
| `disable` | `apikey disable <key_id>` | Temporarily disable a key |
| `enable` | `apikey enable <key_id>` | Re-enable a disabled key |
| `help` | `apikey help` | Show detailed API key help |

**Available Permissions:**

| Permission | Description |
|------------|-------------|
| `wallet:read` | Read wallet balance and transactions |
| `wallet:send` | Send transactions |
| `contacts:read` | List and view contacts |
| `contacts:write` | Add, update, delete contacts |
| `system:read` | View system status and metrics |
| `admin` | Full administrative access |
| `all` | All permissions (same as admin) |

**Examples:**
```bash
# Create API key with default permissions
eiou apikey create "My Mobile App"

# Create with specific permissions
eiou apikey create "Read Only" wallet:read,contacts:read

# List all keys
eiou apikey list

# Delete a key
eiou apikey delete eiou_abc123

# Disable/Enable
eiou apikey disable eiou_abc123
eiou apikey enable eiou_abc123
```

**Important:** The API secret is only shown once at creation time. Store it securely.

---

## Chain Drop Commands

### chaindrop

Manage chain drop agreements for resolving transaction chain gaps.

When both contacts are missing the same transaction in their shared chain, the chain cannot be repaired via sync. Chain drop resolves this by mutually agreeing to remove the missing transaction and relink the chain.

**Important:** While a chain gap exists, transactions with that contact are **blocked**. Chain gaps are detected locally by `send`, `sync`, and `ping` — all three commands verify chain integrity without exchanging transaction lists over the wire. Before resorting to a chain drop, the sync flow attempts **backup recovery**: the local node checks its own backups first (self-repair), then tells the remote node which txids are still missing so it can check its backups too. If either side has the transaction in a backup, the chain is repaired without a chain drop. Only when neither side has a backup does the `send` command auto-propose a chain drop. Rejecting a proposal leaves the gap unresolved, meaning the contacts cannot transact until a new proposal is accepted or the missing transaction is recovered.

**Syntax:**
```bash
eiou chaindrop <action> [args...]
```

**Actions:**

| Action | Syntax | Description |
|--------|--------|-------------|
| `propose` | `chaindrop propose <contact_address>` | Propose dropping a missing transaction |
| `accept` | `chaindrop accept <proposal_id>` | Accept an incoming proposal |
| `reject` | `chaindrop reject <proposal_id>` | Reject an incoming proposal |
| `list` | `chaindrop list [contact_address]` | List pending proposals |
| `help` | `chaindrop help` | Show chain drop help |

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `contact_address` | required (propose) | Contact's node address (HTTP, HTTPS, or Tor) |
| `proposal_id` | required (accept/reject) | The proposal ID to act on (format: `cdp-...`) |
| `contact_address` | optional (list) | Filter proposals by contact address |

**Examples:**
```bash
# Propose dropping a missing transaction (auto-detects the gap)
eiou chaindrop propose https://bob

# List all incoming pending proposals
eiou chaindrop list

# List proposals for a specific contact
eiou chaindrop list https://bob

# Accept a proposal (executes drop, re-signs transactions, exchanges data)
eiou chaindrop accept cdp-2c3c26ba61ab4073

# Reject a proposal (WARNING: gap remains unresolved, transactions stay blocked)
eiou chaindrop reject cdp-2c3c26ba61ab4073

# JSON output
eiou chaindrop propose https://bob --json
eiou chaindrop list --json
eiou chaindrop accept cdp-2c3c26ba61ab4073 --json
```

**Gap Detection and Recovery:**

Chain gaps are detected locally by three commands:
- **`send`** — verifies chain integrity before every transaction; triggers sync to repair (which includes backup recovery on both sides); auto-proposes a chain drop only if sync fails to repair the gap
- **`sync`** — verifies chain integrity, attempts local backup recovery before contacting the remote node, and asks the remote to check its backups for any remaining gaps
- **`ping`** — verifies local chain integrity (not just chain head comparison); triggers sync if chains don't match; auto-proposes a chain drop if sync detects mutual gaps (both sides missing same transaction)

All detection is local — no transaction lists are sent over the wire.

**Recovery priority:**
1. **Local backup recovery** — during sync, the node checks its own database backups for missing transactions
2. **Remote backup recovery** — remaining missing txids are sent to the contact, who checks its DB and backups
3. **Chain drop** — only if neither side has the transaction in any backup

**Flow (when backup recovery fails):**
1. Contact A detects chain gap (`send` or `ping` auto-proposes, or `sync` reveals the gap)
2. Sync attempts backup recovery on both sides (automatic, no user action needed)
3. If recovery fails and auto-propose is enabled (`EIOU_AUTO_CHAIN_DROP_PROPOSE=true`, default), a chain drop is auto-proposed by `send` or `ping`; alternatively, Contact A runs: `eiou chaindrop propose <contact_B_address>`
4. If auto-accept is enabled (`EIOU_AUTO_CHAIN_DROP_ACCEPT=true`, default OFF), Contact B's node auto-accepts the proposal. If the balance guard is enabled (`EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD=true`, default), it first checks that missing transactions don't include net payments owed to us. If the guard blocks or auto-accept is disabled, the proposal requires manual review.
5. Contact B checks incoming proposals: `eiou chaindrop list` (or sees GUI notification banner)
6. Contact B runs: `eiou chaindrop accept <proposal_id>` (or accepts via GUI)
7. Both chains are repaired, balances recalculated, and transactions can resume

For multiple gaps, repeat the propose/accept cycle for each gap.

**JSON Response Examples:**

Propose (success):
```json
{
    "success": true,
    "data": {
        "message": "Chain drop proposal sent",
        "proposal_id": "cdp-2c3c26ba61ab4073...",
        "missing_txid": "a1b2c3d4..."
    }
}
```

List proposals:
```json
{
    "success": true,
    "data": {
        "message": "Chain drop proposals",
        "count": 1,
        "proposals": [
            {
                "proposal_id": "cdp-2c3c26ba61ab4073...",
                "contact_pubkey_hash": "abc123...",
                "missing_txid": "a1b2c3d4...",
                "broken_txid": "e5f6g7h8...",
                "status": "pending",
                "direction": "incoming",
                "created_at": "2026-02-07 12:00:00"
            }
        ]
    }
}
```

Accept (success):
```json
{
    "success": true,
    "data": {
        "message": "Chain drop proposal accepted and executed",
        "proposal_id": "cdp-2c3c26ba61ab4073..."
    }
}
```

Reject (success):
```json
{
    "success": true,
    "data": {
        "message": "Chain drop proposal rejected",
        "proposal_id": "cdp-2c3c26ba61ab4073..."
    },
    "warning": "The chain gap remains unresolved. Transactions with this contact are blocked until a new chain drop proposal is accepted."
}
```

**On Failure (JSON):**
```json
{
    "success": false,
    "error": {
        "code": "CONTACT_NOT_FOUND",
        "message": "Contact not found: https://unknown"
    }
}
```

**Notes:**
- `propose` auto-detects the chain gap by verifying chain integrity with the specified contact
- `accept` executes the chain drop locally, re-signs affected transactions, and exchanges re-signed copies with the proposer
- `reject` leaves the chain gap unresolved — transactions remain blocked until a new proposal is accepted
- Proposals expire automatically after their configured timeout
- Rate limited: 10 chain drop operations per minute
- Auto-propose controlled by `EIOU_AUTO_CHAIN_DROP_PROPOSE` env var (default: `true`)
- Auto-accept controlled by `EIOU_AUTO_CHAIN_DROP_ACCEPT` env var (default: `false` — requires manual accept)
- Balance guard controlled by `EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD` env var (default: `true`). When enabled, blocks auto-accept if missing transactions include net payments owed to us. Set to `false` for unconditional auto-accept.

---

## Backup Commands

### backup

Manage encrypted database backups.

**Syntax:**
```bash
eiou backup <action> [args...]
```

**Actions:**

| Action | Syntax | Description |
|--------|--------|-------------|
| `create` | `backup create` | Create a new encrypted backup |
| `restore` | `backup restore <filename> --confirm` | Restore database from backup |
| `list` | `backup list` | List all available backups |
| `verify` | `backup verify <filename>` | Verify backup integrity |
| `delete` | `backup delete <filename>` | Delete a specific backup |
| `enable` | `backup enable` | Enable automatic daily backups |
| `disable` | `backup disable` | Disable automatic daily backups |
| `status` | `backup status` | Show backup system status |
| `cleanup` | `backup cleanup` | Remove old backups (keep 3 most recent) |
| `help` | `backup help` | Show backup help |

**Examples:**
```bash
# Create a new backup
eiou backup create

# List all backups
eiou backup list

# Verify a specific backup
eiou backup verify backup_20260125_120000.eiou.enc

# Restore from a backup (requires --confirm flag)
eiou backup restore backup_20260125_120000.eiou.enc --confirm

# Check backup status
eiou backup status

# Enable/disable automatic backups
eiou backup enable
eiou backup disable

# JSON output
eiou backup list --json
eiou backup status --json
```

**Security Notes:**
- Backups are encrypted with AES-256-GCM using the master key (derived from your seed phrase)
- Restore requires wallet restoration first (the master key is re-derived from the seed phrase)
- Backup directory has restricted permissions (700)
- Rate limited: 10 backup operations per minute

**Backup Storage:**
- Location: `/var/lib/eiou/backups/`
- Filename format: `backup_YYYYMMDD_HHmmss.eiou.enc`
- Retention: 3 most recent backups (configurable)

---

## Report Commands

### report

Generate reports for troubleshooting and analysis.

**Usage:**
```bash
eiou report <type> [description] [--full]
```

**Available report types:**

| Type | Description |
|------|-------------|
| `debug` | System info, debug table entries, application logs, PHP errors, nginx errors |

**Options:**

| Option | Description |
|--------|-------------|
| `--full` | Include full log history (default: last 50 lines per log file) |

**Examples:**
```bash
# Generate a limited debug report
eiou report debug

# Include an issue description
eiou report debug "login page crash"

# Full report with complete log history
eiou report debug --full

# Full report with description
eiou report debug "sync failure after restore" --full
```

**Output:** Reports are saved as JSON files in `/tmp/` (e.g., `/tmp/eiou-debug-report-20260314170000.json`). The file path and size are printed to stdout. With `--json`, structured output includes `path`, `size`, `report_type`, and `debug_entries` count.

**Report Contents:**
- System info: PHP version, MariaDB version, OS, memory limits, loaded extensions
- Application constants and user configuration (`defaultconfig.json`)
- PHP config (`php.ini`) and nginx config
- Debug table entries (from `DebugService::output()` calls)
- PHP error log, nginx error log, eIOU application log

**Notes:**
- Same report format as the GUI Debug Report (both use `DebugReportService`)
- Limited mode includes last 50 lines of each log file; full mode includes up to 5MB per log
- Reports do not contain private keys, seed phrases, or authentication codes

---

## Test Mode Commands

These commands are only available when `EIOU_TEST_MODE=true`.

### out

Process the outgoing message queue (pending transactions).

**Syntax:**
```bash
eiou out
```

**Notes:**
- Requires `EIOU_TEST_MODE=true` environment variable
- Processes pending transactions in the outgoing queue
- Used for testing and debugging

---

### in

Process incoming/held transactions.

**Syntax:**
```bash
eiou in
```

**Notes:**
- Requires `EIOU_TEST_MODE=true` environment variable
- Processes held transactions that may have completed sync
- Used for testing and debugging

---

## Exit Codes

| Code | Description |
|------|-------------|
| `0` | Success |
| `1` | Error (see error message for details) |

---

## Rate Limiting

CLI commands are rate-limited per wallet to prevent abuse:

| Command | Limit | Window | Block Duration |
|---------|-------|--------|----------------|
| `send` | 30 | 60 seconds | 5 minutes |
| `add` | 20 | 60 seconds | 5 minutes |
| `backup` | 10 | 60 seconds | 5 minutes |
| All others | 100 | 60 seconds | 5 minutes |

When rate limited, you'll see an error with a retry-after time.

---

## See Also

- [API Reference](API_REFERENCE.md) - REST API documentation
- [API Quick Reference](API_QUICK_REFERENCE.md) - API endpoint summary
- [Error Codes](ERROR_CODES.md) - Complete error code reference
