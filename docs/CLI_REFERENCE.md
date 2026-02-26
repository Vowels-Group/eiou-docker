# EIOU CLI Reference

Complete command-line interface documentation for the EIOU Docker node.

## Table of Contents

1. [Overview](#overview)
2. [Global Options](#global-options)
3. [Wallet Commands](#wallet-commands)
4. [Contact Commands](#contact-commands)
5. [Transaction Commands](#transaction-commands)
6. [Settings Commands](#settings-commands)
7. [System Commands](#system-commands)
8. [API Key Management](#api-key-management)
9. [Chain Drop Commands](#chain-drop-commands)
10. [Backup Commands](#backup-commands)
11. [Test Mode Commands](#test-mode-commands)
12. [Exit Codes](#exit-codes)
13. [Rate Limiting](#rate-limiting)

---

## Overview

The EIOU CLI provides a command-line interface for interacting with an EIOU wallet node.

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
eiou add <address> <name> <fee> <credit> <currency>
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address` | required | Contact's node address (HTTP, HTTPS, or Tor) |
| `name` | required | Display name for the contact (use quotes for multi-word names, e.g., `"Jane Doe"`) |
| `fee` | required | Fee percentage for transactions (e.g., 1.0) |
| `credit` | required | Credit limit for this contact |
| `currency` | required | Currency code (e.g., USD) |

**Examples:**
```bash
# Add a new contact
eiou add http://bob:8080 Bob 1.0 100 USD

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
- Your available credit with them (received via ping/pong, ~5 min refresh)
- Their available credit with you (calculated: sent - received + credit_limit)

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

Update contact information.

**Syntax:**
```bash
eiou update <address|name> <field> [values...]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Contact's address or display name |
| `field` | required | Field to update: `all`, `name`, `fee`, or `credit` |
| `values` | varies | New value(s) for the specified field(s) |

**Examples:**
```bash
# Update contact name
eiou update Bob name Robert

# Update fee percentage
eiou update Bob fee 1.5

# Update credit limit
eiou update Bob credit 500

# Update all fields at once
eiou update Bob all NewName 2.0 1000
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

Ping compares chain heads with the remote contact and also verifies local chain integrity to detect internal gaps (e.g., deleted transactions in the middle of the chain). All gap detection is performed locally — no transaction lists are exchanged over the wire. The pong response also includes the available credit the contact extends to you, which is stored locally for use by `viewcontact`, `search`, and `info`.

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
The pong response includes the available credit the contact has for you (how much you can spend with them), calculated as: what they sent you − what you sent them + their credit limit for you. This value is stored locally in the background and is visible via `viewcontact`, `search`, and `info`. The automatic ContactStatusProcessor also performs this exchange every ~5 minutes.

**Chain mismatch behavior:**
If the local and remote chain heads don't match, or if internal gaps are detected, ping automatically triggers a sync (including backup recovery on both sides). If the sync fails to resolve the gap, a chain drop is auto-proposed. See [Chain Drop Commands](#chain-drop-commands) for details.

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
| `currency` | required | Currency code (e.g., USD) |
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

**Settings displayed:**
- Default currency
- Minimum/Default/Maximum fee percentages
- Default credit limit
- Max P2P routing level
- P2P expiration time
- Max output lines (displays "unlimited" when set to 0)
- Default transport mode
- Hostname (HTTP and HTTPS)
- Auto-refresh status
- Auto-backup status

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
| `defaultFee` | Default fee percentage | `1.0` |
| `defaultCreditLimit` | Default credit limit for new contacts | `100` |
| `defaultCurrency` | Default currency code | `USD` |
| `minFee` | Minimum fee amount | `0.01` |
| `maxFee` | Maximum fee percentage | `5.0` |
| `maxP2pLevel` | Maximum P2P routing hops | `3` |
| `p2pExpiration` | P2P request timeout (seconds) | `300` |
| `maxOutput` | Max display lines (0 = unlimited) | `50` |
| `defaultTransportMode` | Preferred transport | `http`, `https`, `tor` |
| `autoRefreshEnabled` | Auto-refresh transactions | `true`, `false` |
| `autoBackupEnabled` | Auto-backup database daily | `true`, `false` |
| `hostname` | Node hostname (regenerates SSL cert) | `http://alice` |
| `trustedProxies` | Trusted proxy IPs for header forwarding | `10.0.0.1,172.16.0.0/12` |

**Advanced Settings (Feature Toggles):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `contactStatusEnabled` | Enable contact status tracking | `true`, `false` |
| `contactStatusSyncOnPing` | Sync status during ping operations | `true`, `false` |
| `autoChainDropPropose` | Auto-propose chain-drop operations | `true`, `false` |
| `autoChainDropAccept` | Auto-accept chain-drop proposals | `true`, `false` |
| `apiEnabled` | Enable REST API endpoint | `true`, `false` |
| `apiCorsAllowedOrigins` | Allowed CORS origins for API | `https://example.com` |
| `rateLimitEnabled` | Enable rate limiting | `true`, `false` |

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

**Advanced Settings (Sync):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `syncChunkSize` | Transactions per sync chunk (10-500) | `50` |
| `syncMaxChunks` | Max sync chunks per cycle (10-1000) | `100` |
| `heldTxSyncTimeoutSeconds` | Held tx sync timeout in seconds (30-299) | `120` |

**Advanced Settings (Display):**

| Setting | Description | Example Value |
|---------|-------------|---------------|
| `displayDateFormat` | PHP date format string | `Y-m-d H:i:s.u` |
| `displayCurrencyDecimals` | Currency decimal places (0-8) | `2` |
| `displayRecentTransactionsLimit` | Recent transactions on dashboard (min 1) | `5` |

**Examples:**
```bash
# Interactive mode
eiou changesettings

# Direct setting change
eiou changesettings defaultCurrency USD
eiou changesettings maxP2pLevel 5
eiou changesettings maxOutput 0           # Unlimited output
eiou changesettings autoRefreshEnabled true
eiou changesettings autoBackupEnabled false
eiou changesettings trustedProxies "10.0.0.1,172.16.0.1"
eiou changesettings trustedProxies ""       # Clear (trust no proxies)

# Advanced settings
eiou changesettings logLevel WARNING
eiou changesettings backupRetentionCount 5
eiou changesettings cleanupDeliveryRetentionDays 60
eiou changesettings httpTransportTimeoutSeconds 30
eiou changesettings displayCurrencyDecimals 4
eiou changesettings rateLimitEnabled false
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
4. If auto-accept is enabled (`EIOU_AUTO_CHAIN_DROP_ACCEPT=true`, default OFF), Contact B's node auto-accepts the proposal if the balance guard passes (missing transactions don't include net payments owed to us). If the guard blocks or auto-accept is disabled, the proposal requires manual review.
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
- When auto-accept is enabled, a balance guard blocks acceptance if missing transactions include net payments owed to us (prevents debt erasure)

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
- Backups are encrypted with AES-256-GCM using your master key
- Restore is only possible after wallet restoration (key dependency)
- Backup directory has restricted permissions (700)
- Rate limited: 10 backup operations per minute

**Backup Storage:**
- Location: `/var/lib/eiou/backups/`
- Filename format: `backup_YYYYMMDD_HHmmss.eiou.enc`
- Retention: 3 most recent backups (configurable)

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
