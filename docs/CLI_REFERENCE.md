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
10. [Payback Methods](#payback-methods)
11. [Payment Request Commands](#payment-request-commands)
12. [Tx Drop Commands](#tx-drop-commands)
13. [Backup Commands](#backup-commands)
14. [Chain Integrity Audit](#chain-integrity-audit)
15. [Plugin Management](#plugin-management)
16. [Report Commands](#report-commands)
17. [Test Mode Commands](#test-mode-commands)
18. [Exit Codes](#exit-codes)
19. [Rate Limiting](#rate-limiting)

---

## Overview

The eIOU CLI provides a command-line interface for interacting with an eIOU wallet node.

> **Note:** All examples in this reference show bare `eiou` commands as run **inside** the container. From the host machine, prepend `docker exec <container>`:
> ```bash
> docker exec eiou-node eiou info          # from the host
> eiou info                                 # inside the container (docker exec -it <container> bash)
> ```

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
| `EIOU_HOST` | Hostname (IP, FQDN, or bare hostname for Docker-network use; optional `:port`). Setting this enables HTTP/HTTPS mode. Omit for Tor-only |
| `EIOU_NAME` | Display name for the node (defaults to `EIOU_HOST`) |
| `EIOU_PORT` | Port appended to HTTP/HTTPS URLs (default: standard 80/443) |
| `RESTORE` | BIP39 seed phrase (24 words) to restore an existing wallet |
| `RESTORE_FILE` | Path to file containing seed phrase (recommended — more secure) |

**Restoring contacts from a prior wallet:**
After restoring a wallet from a seed phrase, your previous contacts are not immediately present. When a prior contact pings or sends a message to your restored node, the ContactStatusService automatically creates a pending contact entry and triggers a sync to restore the shared transaction chain. The restored contact appears as a pending request (visible via `eiou contact pending`) that you can re-accept with `eiou contact accept <pubkey-hash> --currency CCY --fee F --credit C` (or `eiou contact apply` for multi-currency). If `autoAcceptRestoredContact` is enabled, the original per-currency settings are auto-restored and no manual accept is needed.

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

All contact operations are namespaced under `eiou contact …`. Identifiers (`<contact>`) accept a name, an address, or a pubkey-hash — pipe scripted values from `eiou contact pending --json` for stable identifiers across runs. Top-level verbs (`eiou add`, `eiou pending`, `eiou block`, …) were dropped in v0.1.14 in favour of subcommands so the apply / decline / per-currency surfaces have a home and identifier parsing is consistent.

Rate limit: 20 contact ops per minute (`contact` rate-limit bucket).

### contact add

Initiate an outbound contact request. Outbound-only — for an existing accepted contact use `contact currency add` to propose a new currency, or `contact update` to change settings.

```bash
eiou contact add <address> <name> [--fee F --credit C --currency CCY] [--requested-credit RC] [--message M]
```

| Flag | Default | Description |
|------|---------|-------------|
| `--fee` | `0` | Fee percentage you'll charge to relay transactions in this currency. |
| `--credit` | `0` | Credit limit you extend to this contact in this currency. `0` means contact-only (no transactions can route through you). |
| `--currency` | `USD` | Currency code (3-9 uppercase alphanumeric, e.g. USD, EIOU). |
| `--requested-credit` | — | Credit limit you'd like the **receiver** to extend to *you* in this currency. Sent on the wire as `requested_credit_limit` in the contact payload — the receiver sees it as a suggestion when they accept (mirrors what the GUI's "Requested Credit" field and the `POST /api/v1/contacts` endpoint expose). They choose what to actually grant. Omit to send no suggestion. |
| `--message` | — | Optional short message (≤ 255 chars, E2E or transport-encrypted). |

Flags can appear in any order, and in any position relative to the positional `<address> <name>`.

```bash
# Contact request with all defaults
eiou contact add http://bob:8080 Bob

# With explicit per-currency settings + a message
eiou contact add http://bob:8080 Bob --fee 1.0 --credit 100 --currency USD --message "Hey, it's Dave!"

# Suggest that Bob extend you a 500 USD credit limit when he accepts
eiou contact add http://bob:8080 Bob --fee 1.0 --credit 100 --currency USD --requested-credit 500

# Multi-word names need quoting in your shell — there's no special placeholder
eiou contact add http://bob:8080 "Jane Doe" --fee 1.0 --credit 100 --currency USD
```

### contact accept

Accept an incoming contact request from the receiving side. Single- or multi-currency in one shot — repeat the `--currency / --fee / --credit` triplet per currency.

```bash
eiou contact accept <pubkey-hash|address|name> --currency CCY --fee F --credit C \
                                               [--currency CCY --fee F --credit C ...]
```

```bash
# Single-currency accept
eiou contact accept abc123...hash --currency USD --fee 1.0 --credit 100

# Multi-currency accept in one call
eiou contact accept abc123...hash \
    --currency USD --fee 1.0 --credit 100 \
    --currency EUR --fee 0.5 --credit 50
```

For new (pending) contacts, the first accepted currency establishes the contact via the same path as `contact add`; subsequent accepts use the standard currency-acceptance path. This mirrors what the GUI batched-apply modal does and is implemented by the shared `ContactDecisionService::apply()`.

### contact apply

Apply a batched mix of accept / decline / defer decisions in one call — the CLI mirror of the GUI batched-apply modal. Two payload forms:

```bash
# Per-decision flags (repeatable)
eiou contact apply <pubkey-hash|address|name> [--accept CCY:fee:credit ...] \
                                              [--decline CCY ...] [--defer CCY ...]

# Or pipe a JSON array (modal payload shape: [{currency, action, fee?, credit?}, ...])
eiou contact apply <pubkey-hash|address|name> --from <file.json|->
```

```bash
# Accept USD, decline EUR, defer XRP
eiou contact apply abc123...hash --accept USD:0.01:1000 --decline EUR --defer XRP

# Pipe modal output through a script
cat decisions.json | eiou contact apply abc123...hash --from -
```

Declines run before accepts so a `decline EUR + accept USD` payload can't accidentally re-add the just-declined row via the new-contact bridge. Defer rows are intentional no-ops.

### contact decline

Decline every pending currency on a contact request in one shot.

```bash
eiou contact decline <pubkey-hash|address|name>
```

Each declined currency triggers a `contact_currency_declined` notification to the requester so their outgoing-pending row is dropped on the spot — without it the requester's view of the request hangs forever and a retry trips the legacy `CONTACT_EXISTS` path. After all per-currency declines, a single `contact_declined` notification is sent so the requester's contact transaction itself is rejected. Both message types are async-best-effort and fall back to DLQ retries on transport failure; the next ping/pong cycle reconciles any drift via `peerKnownCurrencies` (see `eiou contact ping` below).

### contact list

List contacts grouped by status.

```bash
eiou contact list [--status accepted|pending|blocked]
```

### contact pending

View pending contact requests (incoming + outgoing). The hint text printed for each incoming request points at `eiou contact accept <pubkey-hash> …` and `eiou contact decline <pubkey-hash>` so the printed command is paste-ready.

```bash
eiou contact pending [--json]
```

After a wallet restore, prior contacts that ping your node are auto-created as pending requests by `ContactStatusService` and appear here. They can be re-accepted via `contact accept` (or `contact apply` for multi-currency).

### contact view

View detailed information about a contact.

```bash
eiou contact view <name|address|pubkey-hash>
```

Output includes name, status, addresses, per-currency balances, fee/credit-limit settings, and your / their available credit per currency (refreshed via the ping/pong cycle, ~5 min).

### contact update

Update contact settings via flags — same shape as `contact add`, mirrors the API's `PUT /api/v1/contacts/:address` payload. All field flags are optional; provide whichever subset you want to change.

```bash
eiou contact update <name|address> [--name N] [--fee F] [--credit C] [--currency CCY]
```

| Flag | Required when | Description |
|------|---------------|-------------|
| `--name` | — | New display name (currency-independent). |
| `--fee` | always paired with `--currency` | New fee percentage in the given currency. |
| `--credit` | always paired with `--currency` | New credit limit in the given currency. |
| `--currency` | when `--fee` or `--credit` is set | Currency code for the per-currency row being updated. |

```bash
# Rename
eiou contact update Bob --name Robert

# Change fee on an existing currency
eiou contact update Bob --fee 1.5 --currency USD

# Change credit limit
eiou contact update Bob --credit 500 --currency EUR

# Multi-field — name + fee + credit in one command
eiou contact update Bob --name Robert --fee 2.0 --credit 1500 --currency USD

# By address
eiou contact update http://bob --fee 2.0 --currency USD --json
```

> **Atomicity:** the CLI fans out into one service call per touched field (`name`, `fee`, `credit`), so a multi-field update is **not atomic** across the name/per-currency boundary. The API equivalent (`PUT /api/v1/contacts/:address`) is atomic per request — use it if you need transactional semantics. Updates are **local-only** either way (the contact is not notified).

> **Breaking change (v0.1.14):** the legacy positional form (`eiou contact update Bob name Robert` / `… fee 1.5 USD` / `… all Robert 1.5 1500 USD`) was removed in favour of this flag form. Scripts using the old grammar will fail with a "No fields to update" or "Usage" error.

### contact delete / block / unblock / ping / search

```bash
eiou contact delete <name|address>             # Permanent removal
eiou contact block <name|address>              # Reject incoming traffic from this contact
eiou contact unblock <name|address>            # Reverse a prior block
eiou contact ping <name|address>               # Check online status + per-currency chain heads
eiou contact search <query>                    # Substring search by name
```

`contact ping` compares per-currency chain heads with the remote contact and verifies local chain integrity (gap detection). Mismatches trigger an automatic sync; if the sync can't repair the gap, a tx drop is auto-proposed (see [Tx Drop Commands](#tx-drop-commands)). All gap detection is local — no transaction lists go over the wire.

---

### contact currency add

Propose a new currency on an already-accepted contact. Sends a P2P request so the remote side can accept the new currency.

```bash
eiou contact currency add <contact> <currency> --fee F --credit C
```

### contact currency accept / decline

Accept or decline a single per-currency request that's pending on an existing contact. The `accept` path runs through `ContactDecisionService` so it shares the new-contact-first-accept-via-add semantics with the GUI modal.

```bash
eiou contact currency accept <contact> <currency> --fee F --credit C
eiou contact currency decline <contact> <currency>
```

`decline` sends a `contact_currency_declined` notification to the requester (best-effort, async). The remote drops their stale outgoing-pending row immediately on receipt; if the message is lost in flight, the next ping/pong call reconciles it via the `peerKnownCurrencies` payload field. A retry by the requester (`contact currency add`) succeeds in either case — the dispatcher detects a stale outgoing-pending row and routes it through `addCurrencyToExisting` instead of bailing with `CONTACT_EXISTS`.

### contact currency list

Show every currency configured for a contact, with status (`pending` / `accepted` / `declined`) and direction (`incoming` / `outgoing`).

```bash
eiou contact currency list <contact>
```

### contact currency remove

Remove a currency configuration locally. Local-only — the remote side is not notified. Use this to clean up a stale outgoing pending request, not to reject one (use `currency decline` for that).

```bash
eiou contact currency remove <contact> <currency>
```

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
| `transaction` | Direct eIOU payment to a contact | ✅ Yes | User-initiated; payload is refreshed against the current chain head before each retry (see below) |
| `contact` | Contact request sent to a peer | ✅ Yes | User-initiated; contact request remains valid |
| `p2p` | P2P routing request forwarded to a peer | ❌ No | Time-sensitive; expires in ≤300s — stale by the time retries are exhausted |
| `rp2p` | Relay response/cancel forwarded through this node | ❌ No | Relay message on behalf of others; underlying P2P transaction has expired or been resolved elsewhere |

**Transaction payload refresh on retry:** A transaction can sit in the DLQ for
minutes to hours. In that window the local chain may have advanced (new
outbound txs) or had a tx drop re-wire the link past a missing
transaction. On every retry — whether from
`eiou dlq retry`, the GUI's Retry button, or the automatic retry queue — the
payload's `previousTxid` and `time` are refreshed from current DB state before
the send. The transport layer re-signs the envelope on that send, and after a
successful delivery the fresh signature and nonce are persisted back to the
`transactions` table so later chain sync responses serve a signature that
still verifies.

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
- Chain integrity is verified locally before every send; if a gap is detected, sync is attempted and then a tx drop is auto-proposed if the gap persists
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
- **Feature Toggles:** Display name, auto-refresh, contact status pinging, contact status sync on ping, auto tx drop propose/accept, API enabled, API CORS origins, rate limiting
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
| `minFee` | Minimum fee amount (0 = free relaying) | `0.00000001` |
| `maxFee` | Maximum fee percentage | `5.0` |
| `maxP2pLevel` | Maximum P2P routing hops | `3` |
| `p2pExpiration` | P2P routing request timeout (seconds); P2P transactions get an extra 120s delivery window after this expires | `300` |
| `directTxExpiration` | Direct (non-P2P) transaction delivery timeout in seconds; 0 = no expiry (default); recommended: `120` (two Tor round-trips) | `0` |
| `maxOutput` | Max display lines (0 = unlimited) | `50` |
| `defaultTransportMode` | Preferred transport | `http`, `https`, `tor` |
| `autoBackupEnabled` | Auto-backup database daily | `true`, `false` |
| `analyticsEnabled` | Share anonymous usage statistics (opt-in) | `true`, `false` |
| `autoAcceptTransaction` | Auto-accept P2P transactions when route found | `true`, `false` |
| `hostname` | Node hostname (regenerates SSL cert) | `http://alice` |
| `name` | Display name for this node | `Alice` |
| `trustedProxies` | Trusted proxy IPs for header forwarding | `10.0.0.1,172.16.0.0/12` |
| `allowedCurrencies` | Allowed currencies (comma-separated) | `USD,EUR` |
| `autoRejectUnknownCurrency` | Auto-reject contact requests with currencies not in your allowed list | `true`, `false` |

**Advanced Settings (Feature Toggles):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `hopBudgetRandomized` | Randomize P2P hop depth for privacy (disable for max reachability in sparse networks) | `true`, `false` |
| `contactStatusEnabled` | Enable contact status tracking | `true`, `false` |
| `contactStatusSyncOnPing` | Sync status during ping operations | `true`, `false` |
| `autoChainDropPropose` | Auto-propose tx-drop operations | `true`, `false` |
| `autoChainDropAccept` | Auto-accept tx-drop proposals | `true`, `false` |
| `autoChainDropAcceptGuard` | Balance guard for auto-accept tx drops | `true`, `false` |
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
| `paymentRequestsArchiveRetentionDays` | Days resolved (non-pending) payment requests stay in the live table before moving to `payment_requests_archive` (min 1). **Not a delete** — archived rows stay queryable | `180` |
| `paymentRequestsArchiveBatchSize` | Max rows moved per archival cron run (min 1) | `500` |
| `transactionsArchiveRetentionDays` | Days completed transactions stay in the live table before moving to `transactions_archive` (min 1). Archival is additionally gated per bilateral pair on a gap-free chain-integrity check — pairs with a detected gap are skipped, not archived. **Not a delete** | `30` |
| `transactionsArchiveBatchSize` | Max rows moved per transactions archival cron run (min 1) | `500` |

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

**Advanced Settings (Display):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `displayDecimals` | Display decimal places for all currencies (0-8, default 2). Truncates (floors) — does not round, so displayed amounts never exceed actual value. Does not affect internal storage. | `2` |
| `displayDateFormat` | PHP date format string | `Y-m-d H:i:s.u` |

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
eiou changesettings paymentRequestsArchiveRetentionDays 90
eiou changesettings paymentRequestsArchiveBatchSize 1000
eiou changesettings transactionsArchiveRetentionDays 90
eiou changesettings transactionsArchiveBatchSize 1000
eiou changesettings httpTransportTimeoutSeconds 30
eiou changesettings rateLimitEnabled false
eiou changesettings displayDecimals 4
eiou changesettings torCircuitMaxFailures 5
eiou changesettings torFailureTransportFallback false
eiou changesettings contactStatusEnabled false

# JSON output
eiou changesettings defaultFee 1.5 --json
```

**Reset all settings to defaults:**

`eiou changesettings reset` wipes every saved setting in `/etc/eiou/config/defaultconfig.json` back to the values this build of the code considers default (each getter in `UserContext` falls back to its `Constants::`-backed default when the override is absent). The display `name` in `userconfig.json` is also cleared; identity fields (keys, hostnames, onion address) are preserved. Destructive, so the bare form prints an error and requires `--yes` / `-y` to confirm.

"Defaults" here means whatever the running code treats as default — if an operator has edited `Constants.php` on this node, this resets to the operator's edited values, not the upstream ship defaults. Contacts, transactions, backups, and API keys are never touched.

```bash
# Safety check — prints an error, does nothing:
eiou changesettings reset

# Actually reset:
eiou changesettings reset --yes
```

The same operation is exposed in the GUI under **Settings → Advanced Settings → Reset to Defaults** (typed-confirmation modal).

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

Display help information for top-level commands.

**Syntax:**
```bash
eiou help [command]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `command` | optional | Specific top-level command to get detailed help for |

**Examples:**
```bash
# General help
eiou help

# Help for specific top-level command
eiou help send
eiou help apikey

# JSON format
eiou help --json
```

**Namespaced subcommand help.** Every namespace that owns a CLI subtree — `apikey`, `contact`, `chaindrop`, `payback` — delegates `eiou help <namespace>` straight into that namespace's own help. `eiou help <ns>` and `eiou <ns>` (or `eiou <ns> help`) print the **exact same** subcommand tree, so help lives in exactly one place per namespace and never drifts.

```bash
eiou contact                # full contact subcommand tree
eiou help contact           # ← identical output, delegated to the contact handler
eiou contact currency       # per-currency subcommand tree
eiou help contact currency  # ← identical output, delegated to the same handler

eiou apikey                 # full API key help
eiou help apikey            # ← identical output

eiou chaindrop              # full chain drop help
eiou help chaindrop         # ← identical output

eiou payback                # full payback methods help
eiou help payback           # ← identical output
```

There is no `eiou help <namespace> <subcommand>` drill-down form — read the subcommand line you want from the namespace's full tree and use it directly. Top-level (non-namespaced) commands like `info`, `send`, `viewsettings` still render their detailed help via `eiou help <command>` as documented above.

---

### updatecheck

Check Docker Hub and GitHub Releases for newer image versions. Bypasses the 24-hour cache and performs a fresh check.

**Syntax:**
```bash
eiou updatecheck
```

**Examples:**
```bash
# Check for updates
eiou updatecheck

# JSON output
eiou updatecheck --json
```

**Output:**
- If an update is available: shows the latest version and a `docker pull` command
- If up to date: confirms the current version
- If check fails: reports that Docker Hub and GitHub could not be reached

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

### restart

Full in-place node restart: respawn processors **and** PHP-FPM workers so freshly-enabled plugins (or any other startup-bound state) take effect without a container reboot.

**Syntax:**
```bash
eiou restart
```

**Behavior:**
- Sends SIGTERM to all running processors (the watchdog respawns them within ~30s)
- Sends SIGUSR2 to the PHP-FPM master so all workers gracefully recycle (in-flight HTTP requests finish before the worker exits)
- Required when toggling plugins, since event subscriptions bind during boot
- Must run as root inside the container — the CLI process does, calling from a PHP-FPM worker (GUI) does not. The REST equivalent (`POST /api/v1/system/restart`) sidesteps this by writing a request marker that the root-side poller in `startup.sh` picks up.

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

## Alternate Auth Code

### altcode

Manage the alternate authentication code — a user-chosen passphrase that can be
submitted at the GUI login form or the sensitive-action gate alongside the
seed-derived primary auth code. Useful because the primary is 20 random hex
characters that aren't memorable; the alt code is whatever the operator can
actually recall.

**Syntax:**
```bash
eiou altcode <action>
```

**Actions:**

| Action   | Syntax            | Description                                                |
|----------|-------------------|------------------------------------------------------------|
| `status` | `altcode status`  | Show whether an alt code is currently set                  |
| `set`    | `altcode set`     | Set or rotate the alt code (interactively prompts for the primary first; then for the new alt code with confirmation) |
| `clear`  | `altcode clear`   | Remove the alt code (interactively prompts for the primary first) |
| `help`   | `altcode help`    | Show detailed alt-code help                                |

**Strength rules** (enforced server-side and mirrored in the GUI):

- minimum 12 characters
- at least one uppercase letter, one lowercase letter, one digit, one symbol
- no three repeated characters in a row (`aaa`, `111`)
- no monotonic ascending/descending run of length 4+ (`abcd`, `4321`)
- not a substring of a bundled common-password list

**Examples:**
```bash
# Check status (no secrets read)
eiou altcode status

# Set (prompts: Primary auth code → New alt code → Confirm new alt code)
eiou altcode set

# Remove (prompts: Primary auth code)
eiou altcode clear
```

**Security notes:**
- Set / clear always require the **primary** auth code. The alt code itself can
  never rotate or remove itself — this prevents an attacker who learns the alt
  code from locking the legitimate operator out.
- Inputs are read with `stty -echo` when stdin is a TTY so the plaintext does
  not appear in shell history or terminal scrollback.
- Stored as a one-way Argon2id hash in `userconfig.json` under `altcode_hash`.
  Forgetting it is recoverable only by re-running `altcode set` from a session
  that authenticates with the primary; there is no separate recovery code.
- The CLI rate limiter caps `altcode` invocations at 5 attempts per 5 minutes
  to slow online brute-force against the primary.

---

## Payback Methods

### payback

Manage your own payback methods — the settlement rails (bank wire, PayPal, Bitcoin, custom free-text, etc.) you offer contacts so they can settle debts they owe you. Each method is encrypted at rest per-row; sensitive fields only leave the node when you explicitly reveal them (via `show`) or a contact fetches them over E2E.

**Syntax:**
```bash
eiou payback <action> [args...]
```

**Actions:**

| Action | Syntax | Description |
|--------|--------|-------------|
| `list` | `payback list [--currency <c>] [--all]` | List all enabled methods. `--currency` filters to one code; `--all` also includes disabled rows. |
| `add` | `payback add <type> <label> <currency> [--share auto\|never] [--priority N]` | Create a new method. Type-specific fields are prompted interactively (sensitive inputs use `stty -echo` when stdin is a TTY). |
| `show` | `payback show <method_id>` | Display a single method with **all fields decrypted** to plaintext. |
| `edit` | `payback edit <method_id>` | Re-enter the type-specific fields. Label, priority, and share policy have their own subcommands. |
| `remove` | `payback remove <method_id>` | Permanently delete a method. |
| `share-policy` | `payback share-policy <method_id> auto\|never` | Update only the share policy on an existing method. |
| `help` | `payback help` | Show detailed help. |

**Supported Types (core):**

| Type | Description |
|------|-------------|
| `bank_wire` | Bank wire with sub-rails: `sepa`, `faster_payments`, `ach`, `fednow`, `swift`. Validates IBAN mod-97 and ABA-routing checksum. |
| `custom` | Free-text instructions (≤ 1024 chars). For anything without a dedicated rail type. |

Additional rail types (`btc`, `paypal`, `bizum`, `pix`, `upi`, `lightning`, etc.) arrive via plugins that register a `PaybackMethodTypeContract` — see `docs/PLUGINS.md` for authoring.

**Share Policies:**

| Policy | Behaviour when a contact's node fetches your methods |
|--------|------------------------------------------------------|
| `auto` | Any accepted contact can fetch without owner approval (default) |
| `prompt` | Fetches trigger an approval notification on the owner |
| `never` | Method is never shared via the E2E fetch flow |

**Priority:** Integer 0–9999, lower = preferred. Used as a tiebreaker when several methods of yours match the same currency (defaults to 100).

**Examples:**
```bash
# List all enabled methods
eiou payback list

# List only USD methods, including disabled ones
eiou payback list --currency USD --all

# JSON output for scripting
eiou payback list --json

# Add a SEPA bank-wire method (prompts for rail, name, IBAN)
eiou payback add bank_wire "My Revolut" EUR

# Add a custom free-text method, never auto-shared with contacts, top priority
eiou payback add custom "Monzo – DM me" GBP --share never --priority 10

# Reveal a method's plaintext fields (does not re-prompt for auth — CLI is trusted)
eiou payback show pbm_abc123

# Update the share policy on an existing method
eiou payback share-policy pbm_abc123 never

# Remove a method
eiou payback remove pbm_abc123
```

**Notes:**
- `show` prints **fully-decrypted plaintext**. The GUI and REST API gate plaintext reveal behind a sensitive-action authcode prompt, but the CLI is considered already-authenticated by virtue of having shell/container access.
- `edit` only re-enters the type-specific fields (re-encrypts the whole blob atomically). To change just the label or priority, use the REST API or GUI.
- Method IDs are UUIDs returned in the `add` response and visible in `list` output.

---

## Payment Request Commands

### request

Manage payment requests — ask a contact to pay you a specific amount. The recipient can approve (which sends the eIOU automatically), decline, or you can cancel your own outgoing requests.

**Syntax:**
```bash
eiou request [subcommand] [args...]
```

**Subcommands:**

| Subcommand | Syntax | Description |
|------------|--------|-------------|
| *(none/list)* | `eiou request` | List all incoming and outgoing payment requests |
| `create` | `eiou request create <contact> <amount> <currency> [description]` | Create and send a payment request to a contact |
| `approve` | `eiou request approve <request_id> [note]` | Approve an incoming request (sends eIOU automatically). Optional `[note]` is appended to the on-chain description with `" \| "` (e.g. `"paid via coinbase txid abc"`). |
| `decline` | `eiou request decline <request_id>` | Decline an incoming payment request |
| `cancel` | `eiou request cancel <request_id>` | Cancel an outgoing request you created |

**Examples:**

```bash
# List all payment requests
eiou request list
eiou request list --json

# Request 25 USD from Alice
eiou request create "Alice" 25.00 USD "Dinner last week"

# Request payment using a contact address (avoids duplicate name ambiguity)
eiou request create "http://alice-node.example.com" 50.00 USD

# Approve an incoming request (pays the requester)
eiou request approve req_abc123def456

# Approve and append a payer note to the on-chain description.
# Final description becomes "payment: <requester desc> | <your note>" —
# the note is capped against whatever space is left under the 255-char
# ceiling and rejected with a clear error if it doesn't fit.
eiou request approve req_abc123def456 "paid via coinbase txid abc"

# Decline a request
eiou request decline req_abc123def456

# Cancel your own outgoing request (only while pending)
eiou request cancel req_abc123def456
```

**Notes:**

- When you approve a request, `sendEiou` is called internally — the full transaction pipeline applies (P2P routing, credit checks, DLQ retries)
- If multiple contacts share the same name, `create` returns an error listing the matching contacts and their addresses — use an address instead
- Cancelling sends a cancellation message to the recipient; if they already approved before the cancel arrives, the payment takes priority
- Request IDs are returned in the `create` response and visible in `list` output

---

## Tx Drop Commands

### chaindrop

Manage tx drop agreements for resolving transaction chain gaps.

When both contacts are missing one or more of the same transactions in their shared chain, the chain cannot be repaired via sync. Tx drop resolves this by mutually agreeing to remove the missing transaction(s) and re-wire the chain around the drop (a single tx drop operation can span one or more *consecutive* missing transactions; non-consecutive gaps require a separate proposal per run of consecutive missing txs).

**Important:** While a chain gap exists, transactions with that contact are **blocked**. Chain gaps are detected locally by `send`, `sync`, and `ping` — all three commands verify chain integrity without exchanging transaction lists over the wire. Before resorting to a tx drop, the sync flow attempts **backup recovery**: the local node checks its own backups first (self-repair), then tells the remote node which txids are still missing so it can check its backups too. If either side has the missing transactions in a backup, the chain is repaired without a tx drop. Only when neither side has a backup does the `send` command auto-propose a tx drop. Rejecting a proposal leaves the gap unresolved, meaning the contacts cannot transact until a new proposal is accepted or the missing transactions are recovered.

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
| `help` | `chaindrop help` | Show tx drop help |

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
- **`send`** — verifies chain integrity before every transaction; triggers sync to repair (which includes backup recovery on both sides); auto-proposes a tx drop only if sync fails to repair the gap
- **`sync`** — verifies chain integrity, attempts local backup recovery before contacting the remote node, and asks the remote to check its backups for any remaining gaps
- **`ping`** — verifies local chain integrity (not just chain head comparison); triggers sync if chains don't match; auto-proposes a tx drop if sync detects mutual gaps (both sides missing the same transaction(s))

All detection is local — no transaction lists are sent over the wire.

**Recovery priority:**
1. **Local backup recovery** — during sync, the node checks its own database backups for missing transactions
2. **Remote backup recovery** — remaining missing txids are sent to the contact, who checks its DB and backups
3. **Tx drop** — only if neither side has the missing transactions in any backup; drops the missing run(s) and re-wires the chain around them

**Flow (when backup recovery fails):**
1. Contact A detects chain gap (`send` or `ping` auto-proposes, or `sync` reveals the gap)
2. Sync attempts backup recovery on both sides (automatic, no user action needed)
3. If recovery fails and auto-propose is enabled (`EIOU_AUTO_CHAIN_DROP_PROPOSE=true`, default), a tx drop is auto-proposed by `send` or `ping`; alternatively, Contact A runs: `eiou chaindrop propose <contact_B_address>`
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
- `accept` executes the tx drop locally, re-signs affected transactions, and exchanges re-signed copies with the proposer
- `reject` leaves the chain gap unresolved — transactions remain blocked until a new proposal is accepted
- Proposals expire automatically after their configured timeout
- Rate limited: 10 tx drop operations per minute
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
| `cleanup` | `backup cleanup` | Remove old backups (keep 3 most recent per type) |
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
- Filename format:
  - `backup_YYYYMMDD_HHmmss.eiou.enc` — live tables (archive tables excluded)
  - `archive_backup_YYYYMMDD_HHmmss.eiou.enc` — archive tables only (`payment_requests_archive` AND `transactions_archive`); written by either archival cron after a successful move
- Retention: 3 most recent backups **per prefix** (configurable via `backupRetentionCount`); a frequent live cadence never evicts the rarer archive backups
- `backup list` shows both prefixes; `backup restore <file>` restores whichever type the filename points at

**Payment Request Archival:**

The archival cron moves resolved payment requests older than `paymentRequestsArchiveRetentionDays` into `payment_requests_archive`. Run it manually for a preflight check or off-schedule sweep:

```bash
# Dry-run: count eligible rows, make no changes
php /app/eiou/scripts/payment-request-archive-cron.php --dry-run

# Normal run: move up to paymentRequestsArchiveBatchSize rows and trigger an archive_backup_* dump
php /app/eiou/scripts/payment-request-archive-cron.php
```

Logs are written to `/var/log/eiou/payment-request-archive.log`. A backup failure during archival is logged as WARNING but never fails the archival run itself — rows are already safely moved, and the next archival run that moves rows will re-attempt the archive backup.

**Transactions Archival:**

The archival cron moves completed transactions older than `transactionsArchiveRetentionDays` into `transactions_archive`, gated per bilateral pair on a gap-free chain-integrity check. Pairs with a detected gap are **skipped** (not archived) so the gap stays inspectable; clean pairs get a row upserted in `transaction_chain_checkpoints` recording the gap-free-at-archival proof. Runs nightly at 01:30 UTC (offset 30m from the payment-request archival at 01:00).

```bash
# Dry-run: count eligible pairs + rows, make no changes
php /app/eiou/scripts/transaction-archive-cron.php --dry-run

# Normal run: verify per pair, move up to transactionsArchiveBatchSize rows per pair
php /app/eiou/scripts/transaction-archive-cron.php
```

Logs are written to `/var/log/eiou/transaction-archive.log`. The run's JSON output includes `pairs_processed`, `pairs_archived`, `pairs_skipped_gap`, `moved`, `batches`, and `archive_backup_file` — `pairs_skipped_gap > 0` is worth investigating (a gap is either a local data issue or a missing sync). The per-pair checkpoint written here is consumed by `verifyChainIntegrity()` on every outbound send, so the send hot-path stays O(recent tail) regardless of total history size.

---

## Chain Integrity Audit

### verify-chain

Walk every bilateral chain on this node end-to-end, bypassing the hot-path checkpoint-trust optimization, and recompute each pair's archive hash to detect tampering. Use when auditing a node after a restore, investigating a `pairs_skipped_gap` from the archival cron, or generally checking that the on-disk archive is consistent with what the checkpoint says it should be.

**Usage:**
```bash
eiou verify-chain
```

**Output (per pair, to stdout):**
- `transactions: N settled (across live + archive)`
- `chain: OK` or `chain: FAIL - N gap(s)` with the missing txids
- `checkpoint: none` (pair has no archive yet), `checkpoint: archive hash matches stored value`, or `checkpoint: FAIL - archive hash mismatch` (archive was modified outside the archival service)

**Exit codes:**
- `0` — every pair clean
- `1` — at least one pair has a finding (chain gap or hash mismatch)

This command is intentionally NOT on any cron — it's O(all history) per pair, which is the cost the per-pair checkpoint avoids on the hot path. Run it deliberately.

---

## Plugin Management

### plugin

List installed plugins and toggle their enabled flag. Persistence-only — does **not** restart the node; you must follow up with `eiou restart` (or `POST /api/v1/system/restart`, or the GUI restart button) for an `enable`/`disable` to take effect, since event subscriptions bind during boot.

**Syntax:**
```bash
eiou plugin [list|enable|disable|uninstall] [name]
```

**Subcommands:**

| Subcommand | Syntax | Description |
|------------|--------|-------------|
| *(none / `list`)* | `eiou plugin` | List every installed plugin with version, enabled flag, status, license. |
| `enable` | `eiou plugin enable <name>` | Persist the enabled flag as `true`. |
| `disable` | `eiou plugin disable <name>` | Persist the enabled flag as `false`. |
| `uninstall` | `eiou plugin uninstall <name>` | Run the full uninstall sequence (onUninstall hook, drop tables, drop user, delete credentials, remove files). The plugin must be disabled first. |

**Examples:**
```bash
# List all plugins (table)
eiou plugin

# List as JSON (full metadata)
eiou plugin list --json

# Enable / disable
eiou plugin enable hello-eiou
eiou plugin disable hello-eiou

# Uninstall a disabled plugin
eiou plugin uninstall hello-eiou

# Apply the change
eiou restart
```

**Notes:**
- Persists to `/etc/eiou/config/plugins.json` immediately.
- Plugin names are validated against `^[a-z0-9][a-z0-9-_]{0,63}$` (kebab-case alphanumerics).
- Plugins disabled by default at install time — see [PLUGINS.md](PLUGINS.md) for the safety stance.
- Plugin-owned CLI verbs are dispatched via `PluginCliRegistry`; if a plugin registers a top-level verb, it falls through after core's `else` branch in `Eiou.php`. See [PLUGINS.md](PLUGINS.md) for plugin authoring.

---

## Report Commands

### report

Generate reports for troubleshooting and analysis.

**Usage:**
```bash
eiou report <type> [description] [--full] [--send]
```

**Available report types:**

| Type | Description |
|------|-------------|
| `debug` | System info, debug table entries, application logs, PHP errors, nginx errors |

**Options:**

| Option | Description |
|--------|-------------|
| `--full` | Include full log history (default: last 50 lines per log file) |
| `--send` | Submit report to support via Tor instead of saving to file |

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

# Send report to support instead of saving to file
eiou report debug --send

# Send full report with description to support
eiou report debug "login crash" --full --send
```

**Output (default):** Reports are saved as JSON files in `/tmp/` (e.g., `/tmp/eiou-debug-report-20260314170000.json`). The file path and size are printed to stdout. With `--json`, structured output includes `path`, `size`, `report_type`, and `debug_entries` count.

**Output (`--send`):** The report is scrubbed of sensitive data (addresses, keys, IPs) and submitted to the support endpoint via Tor. On success, a reference key is returned. Rate-limited to 3 submissions per day. With `--json`, structured output includes `key` and `report_type`.

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

| Command bucket | Limit | Window | Block Duration |
|----------------|-------|--------|----------------|
| `send` | 30 | 60 seconds | 5 minutes |
| `contact` (every `eiou contact …` subcommand) | 20 | 60 seconds | 5 minutes |
| `generate` | 5 | 5 minutes | 15 minutes |
| `backup` | 10 | 60 seconds | 5 minutes |
| `chaindrop` | 10 | 60 seconds | 5 minutes |
| `report` | 10 | 60 seconds | 5 minutes |
| `p2p` | 30 | 60 seconds | 5 minutes |
| `request` | 20 | 60 seconds | 5 minutes |
| All others | 100 | 60 seconds | 5 minutes |

When rate limited, you'll see an error with a retry-after time.

---

## See Also

- [API Reference](API_REFERENCE.md) - REST API documentation
- [API Quick Reference](API_QUICK_REFERENCE.md) - API endpoint summary
- [Error Codes](ERROR_CODES.md) - Complete error code reference
