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
9. [Test Mode Commands](#test-mode-commands)
10. [Exit Codes](#exit-codes)
11. [Rate Limiting](#rate-limiting)

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

---

## Wallet Commands

### generate

Generate a new wallet or restore from a BIP39 seed phrase.

**Syntax:**
```bash
eiou generate [restore <24 words>] [restore-file <filepath>] [hostname]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `restore` | optional | Restore wallet from BIP39 seed phrase (24 words following this keyword) |
| `restore-file` | optional | Path to file containing seed phrase (more secure - avoids process list exposure) |
| `hostname` | optional | HTTP hostname for the wallet (e.g., `http://alice`) |

**Examples:**
```bash
# Generate new wallet with auto-generated seed phrase
eiou generate

# Restore from 24-word seed phrase
eiou generate restore word1 word2 word3 ... word24

# Restore from seed phrase file (recommended for security)
eiou generate restore-file /path/to/seedphrase.txt

# Generate with custom hostname
eiou generate http://mynode

# JSON output
eiou generate --json
```

**Notes:**
- The seed phrase is displayed only once during generation - store it securely
- Using `restore-file` is more secure as the seed phrase won't appear in process listings
- Rate limited: 5 generations per 5 minutes

---

### info

Display wallet information including addresses and public key.

**Syntax:**
```bash
eiou info [detail]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `detail` | optional | Show detailed balance information with transaction breakdowns |

**Examples:**
```bash
# Basic wallet info
eiou info

# Detailed info with balances
eiou info detail

# JSON output
eiou info --json
eiou info detail --json
```

**Output includes:**
- HTTP, HTTPS, and Tor addresses (locators)
- Authentication code
- Public key
- (With `detail`) Total balances by currency with sent/received breakdown

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
| `name` | required | Display name for the contact |
| `fee` | required | Fee percentage for transactions (e.g., 1.0) |
| `credit` | required | Credit limit for this contact |
| `currency` | required | Currency code (e.g., USD) |

**Examples:**
```bash
# Add a new contact
eiou add http://bob:8080 Bob 1.0 100 USD

# Add via Tor address
eiou add abc123...onion Alice 0.5 500 EUR

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

Check if a contact is online and verify chain validity.

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
- Online status (online/offline)
- Chain validity status
- Response message

---

### block

Block a contact from sending transactions.

**Syntax:**
```bash
eiou block <address|name>
```

**Examples:**
```bash
eiou block SpamUser
eiou block http://badactor:8080
```

---

### unblock

Unblock a previously blocked contact.

**Syntax:**
```bash
eiou unblock <address|name>
```

**Examples:**
```bash
eiou unblock SpamUser
```

---

### delete

Delete a contact permanently.

**Syntax:**
```bash
eiou delete <address|name>
```

**Examples:**
```bash
eiou delete OldContact
eiou delete http://old:8080
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

---

## Transaction Commands

### send

Send an eIOU transaction to a contact.

**Syntax:**
```bash
eiou send <address|name> <amount> <currency>
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | required | Recipient's address or display name |
| `amount` | required | Amount to send (positive number) |
| `currency` | required | Currency code (e.g., USD, EUR) |

**Examples:**
```bash
# Send by contact name
eiou send Bob 50 USD

# Send by address
eiou send http://bob:8080 100 EUR

# JSON output
eiou send Alice 25.50 USD --json
```

**Notes:**
- Transaction may be direct or routed through intermediaries (P2P relay)
- Rate limited: 30 transactions per minute

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

View transaction history.

**Syntax:**
```bash
eiou history [address|name] [limit]
```

**Arguments:**

| Argument | Type | Description |
|----------|------|-------------|
| `address\|name` | optional | Filter by specific contact |
| `limit` | optional | Maximum transactions to display (or "all") |

**Examples:**
```bash
# View all transaction history
eiou history

# View history with specific contact
eiou history Bob

# View all history (no limit)
eiou history all

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
- Max output lines
- Default transport mode
- Auto-refresh status

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
| `maxOutput` | Max display lines (integer or "all") | `50` |
| `defaultTransportMode` | Preferred transport | `http`, `https`, `tor` |
| `autoRefreshEnabled` | Auto-refresh transactions | `true`, `false` |
| `hostname` | Node hostname (regenerates SSL cert) | `http://alice` |

**Examples:**
```bash
# Interactive mode
eiou changesettings

# Direct setting change
eiou changesettings defaultCurrency EUR
eiou changesettings maxP2pLevel 5
eiou changesettings autoRefreshEnabled true

# JSON output
eiou changesettings defaultFee 1.5 --json
```

---

## System Commands

### sync

Synchronize data with contacts.

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

# Sync only transactions
eiou sync transactions

# Recalculate balances
eiou sync balances
```

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

Gracefully shutdown the wallet application.

**Syntax:**
```bash
eiou shutdown
```

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
| `generate` | 5 | 5 minutes | 15 minutes |
| All others | 100 | 60 seconds | 5 minutes |

When rate limited, you'll see an error with a retry-after time.

---

## See Also

- [API Reference](API_REFERENCE.md) - REST API documentation
- [API Quick Reference](API_QUICK_REFERENCE.md) - API endpoint summary
- [Error Codes](ERROR_CODES.md) - Complete error code reference
