# EIOU CLI Demo Guide

A step-by-step walkthrough for demonstrating EIOU CLI commands.

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites & Installation](#section-1-prerequisites--installation)
   - [System Requirements](#system-requirements)
   - [Pulling the EIOU Image](#pulling-the-eiou-image)
   - [Building from Source](#building-from-source-alternative)
   - [Loading from a .tar File](#loading-from-a-tar-file)
3. [Creating Containers](#section-2-creating-containers)
   - [QUICKSTART vs No QUICKSTART](#quickstart-vs-no-quickstart)
   - [Creating a New Wallet (QUICKSTART)](#creating-a-new-wallet-quickstart)
   - [Restoring an Existing Wallet](#restoring-an-existing-wallet)
   - [Changing Hostname After Creation](#changing-hostname-after-creation)
   - [Tor-Only Mode](#tor-only-mode-no-quickstart)
4. [Basic Wallet Commands](#section-3-basic-wallet-commands)
   - [info](#31-info---wallet-information)
   - [overview](#32-overview---dashboard-summary)
   - [viewsettings](#33-viewsettings---current-settings)
   - [changesettings](#34-changesettings---modify-settings)
   - [help](#35-help---getting-help)
5. [Multi-Container Network Setup](#section-4-multi-container-network-setup)
   - [Understanding the 4-Node Topology](#understanding-the-4-node-topology)
   - [Starting the Environment](#starting-the-4-node-environment)
   - [Network Architecture](#network-architecture)
6. [Contact Management Commands](#section-5-contact-management-commands)
   - [add](#51-add---adding-contacts)
   - [pending](#52-pending---viewing-pending-requests)
   - [search](#53-search---finding-contacts)
   - [viewcontact](#54-viewcontact---contact-details)
   - [update](#55-update---modifying-contacts)
   - [ping](#56-ping---checking-online-status)
   - [block/unblock](#57-blockunblock---blocking-contacts)
   - [delete](#58-delete---removing-contacts)
7. [Transaction Commands](#section-6-transaction-commands)
   - [send](#61-send---sending-transactions)
   - [viewbalances](#62-viewbalances---checking-balances)
   - [history](#63-history---transaction-history)
   - [Multi-Hop P2P Routing](#64-multi-hop-p2p-routing)
8. [System & Utility Commands](#section-7-system--utility-commands)
   - [sync](#71-sync---synchronizing-data)
   - [backup](#72-backup---backup-management)
   - [apikey](#73-apikey---api-key-management)
9. [Cleanup](#section-8-cleanup)
   - [Stopping Containers](#stopping-containers-preserving-data)
   - [Complete Cleanup](#complete-cleanup-remove-all-data)
   - [Verifying Cleanup](#verifying-cleanup)
10. [Quick Reference](#quick-reference)
11. [Troubleshooting](#troubleshooting)

---

## Overview

This guide provides a hands-on walkthrough for demonstrating the EIOU command-line interface (CLI). It covers container setup, wallet generation, contact management, and transaction operations.

**What you will learn:**
- How to pull and run the EIOU Docker image
- Two methods for creating EIOU wallets (automatic and manual)
- Essential CLI commands for daily wallet operations
- Setting up a 4-node network for P2P routing demonstrations
- Sending transactions directly and through multi-hop routing
- Best practices for secure wallet management

**Target audience:**
- Developers evaluating the EIOU system
- Demo presenters conducting live demonstrations
- System administrators deploying EIOU nodes
- Users learning to operate EIOU wallets

**Prerequisites:**
- Basic familiarity with Docker commands
- Terminal/command-line experience
- Understanding of cryptocurrency wallet concepts (helpful but not required)

---

## Section 1: Prerequisites & Installation

### System Requirements

Before running EIOU containers, ensure your system meets these requirements:

**Docker Requirements:**
- Docker Engine 20.10 or later
- Docker Compose v2.0 or later (for multi-node setups)

**Verify Docker installation:**
```bash
# Check Docker version
docker --version

# Check Docker Compose version
docker compose version
```

**Memory Requirements:**

| Configuration | RAM Required | Use Case |
|---------------|--------------|----------|
| Single node | ~275 MB | Development, testing, personal wallet |
| 4-node network | ~1.1 GB | Multi-party testing, demos |
| 10-node network | ~2.8 GB | Complex routing tests, stress testing |

**Disk Space:**
- Base image: ~500 MB
- Per-container data: ~50 MB minimum (grows with transaction history)

**Network:**
- Ports 80 and 443 available (configurable)
- Outbound access to Tor network (for .onion addresses)

---

### Pulling the EIOU Image

The fastest way to get started is pulling the pre-built image from Docker Hub.

**Pull the latest image:**
```bash
docker pull eiou/eiou
```

**Verify the image was downloaded:**
```bash
docker images | grep eiou
```

**Expected output:**
```
eiou/eiou     latest    abc123def456   2 days ago   498MB
```

---

### Building from Source (Alternative)

If you need to customize the image or are contributing to development, build from source.

**Clone and build:**
```bash
# Clone the repository
git clone https://github.com/eiou-org/eiou-docker.git
cd eiou-docker

# Build the image
docker build -f eiou.dockerfile -t eiou/eiou .
```

**Verify the build:**
```bash
docker images | grep eiou
```

---

### Loading from a .tar File

If you have a pre-built image as a `.tar` archive (e.g., for offline installation or air-gapped environments):

**Load the image:**
```bash
# Load from a .tar file
docker load -i eiou-image.tar

# Or load from a compressed .tar.gz file
docker load -i eiou-image.tar.gz
```

**Expected output:**
```
Loaded image: eiou/eiou:latest
```

**Verify the image was loaded:**
```bash
docker images | grep eiou
```

**Creating a .tar file (for distribution):**

If you need to export an image to share with others:
```bash
# Save image to .tar file
docker save -o eiou-image.tar eiou/eiou:latest

# Or save with compression
docker save eiou/eiou:latest | gzip > eiou-image.tar.gz
```

---

## Section 2: Creating Containers

EIOU wallet generation and restoration happens at container startup via environment variables.

### QUICKSTART vs No QUICKSTART

| Mode | Transport | Use Case |
|------|-----------|----------|
| With `QUICKSTART=<hostname>` | HTTP + HTTPS + Tor | Standard usage, demos, most deployments |
| Without `QUICKSTART` | Tor only | Privacy-focused, no HTTP/HTTPS exposure |

- **With QUICKSTART**: The container starts with HTTP, HTTPS, and Tor addresses. The hostname you provide becomes the HTTP/HTTPS address.
- **Without QUICKSTART**: The container starts with only a Tor (.onion) address. No HTTP or HTTPS is configured.

---

### Creating a New Wallet (QUICKSTART)

The `QUICKSTART` environment variable generates a new wallet with HTTP/HTTPS when the container starts.

**What QUICKSTART does automatically:**
1. Generates a new BIP39 seed phrase (24 words)
2. Creates wallet keys from the seed phrase
3. Configures the node hostname for HTTP and HTTPS
4. Generates SSL certificates for HTTPS
5. Starts Tor and generates .onion address
6. Initializes the database
7. Starts all background processors

**Basic command:**
```bash
docker run -d --name alice -p 80:80 -p 443:443 -e QUICKSTART=alice eiou/eiou
```

**With persistent volumes (recommended):**
```bash
docker run -d --name alice -p 80:80 -p 443:443 -e QUICKSTART=alice -v alice-mysql-data:/var/lib/mysql -v alice-files:/etc/eiou/ -v alice-backups:/var/lib/eiou/backups eiou/eiou
```

**Volume descriptions:**

| Volume | Purpose | Backup Priority |
|--------|---------|-----------------|
| `alice-mysql-data` | Database (transactions, contacts, balances) | CRITICAL |
| `alice-files` | Wallet keys, userconfig.json, encryption data | CRITICAL |
| `alice-backups` | Encrypted database backups | CRITICAL |

**View container logs to see wallet information:**
```bash
docker logs -f alice
```

**Important:** The seed phrase is displayed in the logs only once during initial generation. Copy and store it securely before the container restarts.

**Accessing the CLI:**

The CLI is accessed via `docker exec <container> eiou`:
```bash
# Access the CLI (shows help/usage)
docker exec alice eiou

# Run a specific command
docker exec alice eiou info
docker exec alice eiou help
```

---

### Restoring an Existing Wallet

To restore a wallet from an existing seed phrase, use the `RESTORE` or `RESTORE_FILE` environment variables at container startup.

**Method 1: RESTORE_FILE (Recommended - more secure):**

Create a file containing your 24-word seed phrase, then mount it:
```bash
echo "word1 word2 word3 word4 word5 word6 word7 word8 word9 word10 word11 word12 word13 word14 word15 word16 word17 word18 word19 word20 word21 word22 word23 word24" > /tmp/seed.txt
```

```bash
docker run -d --name alice -p 80:80 -p 443:443 -e QUICKSTART=alice -e RESTORE_FILE=/restore/seed -v /tmp/seed.txt:/restore/seed:ro -v alice-mysql-data:/var/lib/mysql -v alice-files:/etc/eiou/ -v alice-backups:/var/lib/eiou/backups eiou/eiou
```

After successful restoration, delete the seed file:
```bash
rm /tmp/seed.txt
```

**Why RESTORE_FILE is more secure:**
- Seed phrase does not appear in `docker inspect` output
- Seed phrase does not appear in environment variable listings
- File can be deleted after container starts

**Method 2: RESTORE (Convenient but less secure):**

Pass the seed phrase directly as an environment variable:
```bash
docker run -d --name alice -p 80:80 -p 443:443 -e QUICKSTART=alice -e "RESTORE=word1 word2 word3 word4 word5 word6 word7 word8 word9 word10 word11 word12 word13 word14 word15 word16 word17 word18 word19 word20 word21 word22 word23 word24" -v alice-mysql-data:/var/lib/mysql -v alice-files:/etc/eiou/ -v alice-backups:/var/lib/eiou/backups eiou/eiou
```

**Warning:** The `RESTORE` environment variable remains visible via `docker inspect`. Use `RESTORE_FILE` for production.

---

### Changing Hostname After Creation

If you need to add or change the HTTP/HTTPS hostname after the wallet is already created, use `changesettings`:

```bash
# Add or change hostname
docker exec alice eiou changesettings hostname http://alice
```

Setting the HTTP hostname automatically derives the HTTPS version (e.g., `http://alice` also configures `https://alice`). The SSL certificate is regenerated when the hostname changes.

---

### Tor-Only Mode (No QUICKSTART)

For privacy-focused deployments with only Tor access (no HTTP/HTTPS), omit the `QUICKSTART` variable:

```bash
docker run -d --name alice-tor -v alice-mysql-data:/var/lib/mysql -v alice-files:/etc/eiou/ -v alice-backups:/var/lib/eiou/backups eiou/eiou
```

**Note:** Without `QUICKSTART`, the container:
- Generates a wallet with only a Tor (.onion) address
- Has no HTTP or HTTPS hostname configured
- Is only accessible via the Tor network

You can add an HTTP/HTTPS hostname later using `eiou changesettings hostname`.

---

### Summary

| Scenario | Environment Variables |
|----------|----------------------|
| New wallet (HTTP/HTTPS + Tor) | `QUICKSTART=<hostname>` |
| New wallet (Tor only) | No `QUICKSTART` |
| Restore wallet (secure) | `QUICKSTART=<hostname>` + `RESTORE_FILE=/restore/seed` |
| Restore wallet (simple) | `QUICKSTART=<hostname>` + `RESTORE="24 word phrase"` |
| Change hostname later | Use `eiou changesettings hostname <url>` |

---

## Section 3: Basic Wallet Commands

This section covers the essential wallet commands for viewing information, managing settings, and getting help.

### 3.1 info - Wallet Information

The `info` command displays comprehensive wallet information including addresses, authentication status, and public key.

#### Basic Usage

```bash
docker exec alice eiou info
```

**Expected Output:**
```
=== Wallet Information ===

Locators:
  HTTP:  http://alice
  HTTPS: https://alice
  Tor:   abc123...xyz.onion

Authentication Code: [REDACTED]
Public Key: 04a1b2c3d4e5f6...

Tip: Use --show-auth to securely retrieve your authentication code
```

#### Detailed Information

```bash
docker exec alice eiou info detail
```

Shows balance breakdowns in addition to basic info.

#### Showing the Authentication Code

```bash
docker exec alice eiou info --show-auth
```

**Security Note:** The auth code is never displayed directly in command output. Instead, it is stored in a secure temporary file at `/dev/shm/` with a 5-minute TTL.

#### JSON Output

```bash
docker exec alice eiou info --json
```

---

### 3.2 overview - Dashboard Summary

The `overview` command provides a quick dashboard view of your wallet status.

```bash
# Default overview (5 recent transactions)
docker exec alice eiou overview

# Show 10 recent transactions
docker exec alice eiou overview 10
```

**Expected Output:**
```
=== Wallet Overview ===

Total Balances:
  USD: 150.00

Active Contacts: 3
Pending Requests: 1

=== Recent Transactions (5) ===

  #1  2026-01-26 10:15  SENT     -50.00 USD  -> Bob
  #2  2026-01-26 09:30  RECEIVED +75.00 USD  <- Charlie
  ...
```

---

### 3.3 viewsettings - Current Settings

Display all current wallet configuration options.

```bash
docker exec alice eiou viewsettings
```

**Expected Output:**
```
=== Wallet Settings ===

Currency & Fees:
  Default Currency:      USD
  Minimum Fee:           0.01
  Default Fee:           1.0%
  Maximum Fee:           5.0%

Credit & Limits:
  Default Credit Limit:  100

P2P Routing:
  Max P2P Level:         3
  P2P Expiration:        300 seconds

Automation:
  Auto-Refresh Enabled:  true
  Auto-Backup Enabled:   true
```

---

### 3.4 changesettings - Modify Settings

Change wallet configuration directly or interactively.

```bash
# View current settings
docker exec alice eiou viewsettings

# Change P2P routing level
docker exec alice eiou changesettings maxP2pLevel 5

# Change default fee
docker exec alice eiou changesettings defaultFee 1.5

# Change hostname (derives HTTPS automatically)
docker exec alice eiou changesettings hostname http://alice
```

**Available Settings:**

| Setting | Description | Valid Values |
|---------|-------------|--------------|
| `defaultFee` | Default fee percentage for transactions | Decimal (e.g., `1.0`) |
| `defaultCreditLimit` | Default credit limit for new contacts | Integer (e.g., `100`) |
| `defaultCurrency` | Default currency code | `USD` (only USD currently supported) |
| `minFee` | Minimum fee amount | Decimal (e.g., `0.01`) |
| `maxFee` | Maximum fee percentage | Decimal (e.g., `5.0`) |
| `maxP2pLevel` | Maximum P2P routing hops | Integer 1-10 |
| `p2pExpiration` | P2P request expiration time (seconds) | Integer (e.g., `300`) |
| `maxOutput` | Maximum lines of output to display | Integer or `all` |
| `defaultTransportMode` | Default transport type | `http`, `https`, `tor` |
| `autoRefreshEnabled` | Enable auto-refresh for pending transactions | `true`, `false` |
| `hostname` | Node hostname (derives HTTPS automatically) | URL (e.g., `http://alice`) |

---

### 3.5 help - Getting Help

```bash
# General help (lists all commands)
docker exec alice eiou help

# Help for specific commands
docker exec alice eiou help info
docker exec alice eiou help add
docker exec alice eiou help search
docker exec alice eiou help viewcontact
docker exec alice eiou help update
docker exec alice eiou help block
docker exec alice eiou help unblock
docker exec alice eiou help delete
docker exec alice eiou help send
docker exec alice eiou help viewbalances
docker exec alice eiou help history
docker exec alice eiou help pending
docker exec alice eiou help overview
docker exec alice eiou help viewsettings
docker exec alice eiou help changesettings
docker exec alice eiou help sync
docker exec alice eiou help ping
docker exec alice eiou help backup
docker exec alice eiou help apikey
```

**Test mode commands** (require `EIOU_TEST_MODE=true`):
```bash
docker exec alice eiou help out
docker exec alice eiou help in
```

---

### 3.6 Global Options

All CLI commands support these global options:

| Option | Description |
|--------|-------------|
| `--json`, `-j` | Output results in JSON format |
| `--no-metadata` | Exclude metadata (timestamp, node_id) from JSON output |

**Example with jq:**
```bash
# Extract just the HTTP locator
docker exec alice eiou info --json | jq -r '.data.locators.http'
```

---

## Section 4: Multi-Container Network Setup

### Understanding the 4-Node Topology

The EIOU 4-node setup creates a linear chain of containers for demonstrating peer-to-peer routing:

```
Alice <---> Bob <---> Carol <---> Daniel
```

**Key Characteristics:**
- **Line topology**: Each node only knows its immediate neighbors
- **P2P routing**: Transactions can traverse the chain through intermediary nodes
- **No pre-configured contacts**: Nodes start independently; connections must be established manually

**Why This Matters:**
If Alice wants to transact with Daniel, the transaction must route through Bob and Carol. This demonstrates EIOU's peer-to-peer relay capabilities.

---

### Starting the 4-Node Environment

#### Step 1: Navigate to the eiou-docker Directory

```bash
cd /path/to/eiou-docker
```

#### Step 2: Clean Up Any Existing Setup

```bash
docker-compose -f docker-compose-4line.yml down -v
```

#### Step 3: Start the Topology

```bash
docker-compose -f docker-compose-4line.yml up -d --build
```

#### Step 4: Wait for Initialization

**This step is critical.** Each node needs time to initialize.

```bash
# Wait for all nodes to initialize (2 minutes recommended)
sleep 120
```

For WSL2 or slow environments, wait up to 180 seconds.

#### Step 5: Verify All Containers Are Running

```bash
docker-compose -f docker-compose-4line.yml ps
```

**Expected output:**
```
NAME      STATUS
alice     Up 2 minutes (healthy)
bob       Up 2 minutes (healthy)
carol     Up 2 minutes (healthy)
daniel    Up 2 minutes (healthy)
```

---

### Verifying Each Node

```bash
# Verify each node
docker exec alice eiou info
docker exec bob eiou info
docker exec carol eiou info
docker exec daniel eiou info
```

**Quick verification script:**
```bash
for node in alice bob carol daniel; do
    echo "=== $node ==="
    docker exec $node eiou info | head -10
    echo ""
done
```

---

### Network Architecture

Within the Docker network, containers resolve each other by hostname:

| From | Can Reach | Via URL |
|------|-----------|---------|
| alice | bob | `http://bob` |
| bob | alice, carol | `http://alice`, `http://carol` |
| carol | bob, daniel | `http://bob`, `http://daniel` |
| daniel | carol | `http://carol` |

**Important:** Contacts are NOT pre-configured. You must manually add contacts to establish the chain (covered in Section 5).

---

## Section 5: Contact Management Commands

### Understanding EIOU Contacts

EIOU contacts form the trust network that enables value transfer.

#### Bidirectional Requirement

A contact relationship requires **mutual agreement**. Both parties must add each other:

```
Alice adds Bob     -->  Creates a PENDING relationship
Bob adds Alice     -->  Both sides become ACCEPTED
```

#### Contact Parameters

| Parameter | Description | Example |
|-----------|-------------|---------|
| `address` | Node's network address | `http://bob` |
| `name` | Display name | `Bob` |
| `fee` | Transaction fee percentage | `0.1` (0.1%) |
| `credit` | Maximum balance to extend | `1000` |
| `currency` | Currency for credit limit | `USD` |

#### Contact States

| State | Description |
|-------|-------------|
| **Pending** | Awaiting acceptance from the other party |
| **Accepted** | Both parties have added each other |
| **Blocked** | Transactions blocked |

---

### 5.1 add - Adding Contacts

**Syntax:**
```bash
eiou add <address> <name> <fee> <credit> <currency>
```

#### Creating the A<->B<->C<->D Chain

```bash
# Create A<->B link
docker exec alice eiou add http://bob Bob 0.1 1000 USD
docker exec bob eiou add http://alice Alice 0.1 1000 USD

# Create B<->C link
docker exec bob eiou add http://carol Carol 0.1 1000 USD
docker exec carol eiou add http://bob Bob 0.1 1000 USD

# Create C<->D link
docker exec carol eiou add http://daniel Daniel 0.1 1000 USD
docker exec daniel eiou add http://carol Carol 0.1 1000 USD
```

#### Verifying the Chain

```bash
# Alice should see Bob as accepted
docker exec alice eiou search

# Bob should see both Alice and Carol
docker exec bob eiou search

# Carol should see both Bob and Daniel
docker exec carol eiou search

# Daniel should see Carol
docker exec daniel eiou search
```

---

### 5.2 pending - Viewing Pending Requests

```bash
docker exec alice eiou pending
```

Shows incoming and outgoing requests awaiting acceptance.

---

### 5.3 search - Finding Contacts

```bash
# List all contacts
docker exec alice eiou search

# Search by name
docker exec bob eiou search Alice
```

---

### 5.4 viewcontact - Contact Details

```bash
# View by name
docker exec alice eiou viewcontact Bob

# View by address
docker exec bob eiou viewcontact http://alice
```

---

### 5.5 update - Modifying Contacts

```bash
# Update contact name
docker exec alice eiou update Bob name Robert

# Update fee
docker exec alice eiou update Bob fee 0.5

# Update credit limit
docker exec alice eiou update Bob credit 2000

# Update all at once
docker exec alice eiou update Bob all NewName 0.2 1500
```

---

### 5.6 ping - Checking Online Status

```bash
docker exec alice eiou ping Bob
```

**Expected output:**
```
Pinging Bob (http://bob)...

Status:        ONLINE
Response Time: 45ms
Chain Valid:   Yes
```

---

### 5.7 block/unblock - Blocking Contacts

```bash
# Block a contact
docker exec alice eiou block Bob

# Verify blocked status
docker exec alice eiou viewcontact Bob

# Unblock the contact
docker exec alice eiou unblock Bob
```

**Effects of blocking:**
- They cannot send transactions to you
- They cannot route transactions through you
- Existing balances remain unchanged

---

### 5.8 delete - Removing Contacts

```bash
docker exec alice eiou delete OldContact
```

**Warning:** Deletion is permanent. Outstanding balances should be settled before deletion.

---

## Section 6: Transaction Commands

### 6.1 send - Sending Transactions

**Prerequisite:** Ensure contacts have been added as described in [Section 5.1](#51-add---adding-contacts). The A<->B<->C<->D chain must be established before sending transactions.

**Syntax:**
```bash
eiou send <address|name> <amount> <currency>
```

#### Direct Transactions

```bash
# Alice sends 100 USD to Bob (direct contact)
docker exec alice eiou send Bob 100 USD

# Wait for processing
sleep 5

# Verify with balance check
docker exec alice eiou viewbalances
```

**Note:** Tor transport is slower than HTTP/HTTPS. If a transaction or balance doesn't appear immediately, wait a few more seconds and re-check. Results may take longer to propagate over Tor.

**Expected output:**
```
Transaction sent successfully.
  Recipient: Bob
  Amount: 100.00 USD
  Type: standard
```

---

### 6.2 viewbalances - Checking Balances

```bash
# View all balances
docker exec alice eiou viewbalances

# View balance with specific contact
docker exec alice eiou viewbalances Bob
```

**Understanding Balance Output:**

| Field | Description |
|-------|-------------|
| **Sent** | Total amount you have sent |
| **Received** | Total amount received |
| **Net Balance** | Difference (negative = you owe them) |

---

### 6.3 history - Transaction History

```bash
# View all transaction history
docker exec alice eiou history

# View history with specific contact
docker exec alice eiou history Bob

# View all (no limit)
docker exec alice eiou history all
```

---

### 6.4 Multi-Hop P2P Routing

This demonstrates EIOU's key feature: sending transactions to contacts you don't directly know.

**Prerequisite:** The full A<->B<->C<->D contact chain from [Section 5.1](#51-add---adding-contacts) must be established for P2P routing to work.

#### How P2P Routing Works

In the 4-node topology:
```
Alice <--> Bob <--> Carol <--> Daniel
```

Alice and Daniel cannot transact directly. When Alice sends to Daniel:
1. Transaction broadcasts to Alice's contacts (Bob)
2. Relays through Bob to Carol
3. Delivers from Carol to Daniel
4. Response returns along the route

#### Demonstration

**Step 1: Verify Alice cannot directly reach Daniel**
```bash
docker exec alice eiou search Daniel
# Expected: No contacts found matching "Daniel"
```

**Step 2: Send transaction via P2P routing**
```bash
docker exec alice eiou send Daniel 100 USD
```

**Expected output:**
```
Transaction sent via P2P routing.
  Recipient: Daniel
  Amount: 100.00 USD
  Type: p2p
  Route: alice -> bob -> carol -> daniel
```

**Step 3: Wait for P2P propagation**
```bash
sleep 15
```

**Note:** P2P routing takes longer than direct transactions. Over Tor, this can take significantly longer. If balances don't appear immediately, wait and re-check.

**Step 4: Verify balances across all nodes**
```bash
docker exec alice eiou viewbalances
docker exec bob eiou viewbalances
docker exec carol eiou viewbalances
docker exec daniel eiou viewbalances
```

**Step 5: Check Daniel's history**
```bash
docker exec daniel eiou history
```

#### Understanding P2P Fees

Fees are **added to the sender's amount**, not deducted from the recipient. The P2P request travels forward to find a route, then on the return path (rp2p) the fees are calculated and communicated back to the sender.

With 0.1% fee per hop, when Alice sends 100 USD to Daniel:

| Step | Description | Fee (0.1%) |
|------|-------------|------------|
| Alice -> Bob | Direct send (no relay fee) | 0.00 |
| Bob -> Carol | Bob relays | 0.10 |
| Carol -> Daniel | Carol relays | 0.10 |
| | **Total fees** | **0.20** |

**Alice sends: 100.20 USD** (100 + relay fees)
**Daniel receives: 100.00 USD**

Only relay nodes charge fees. The sender's direct contact (Bob) and the recipient (Daniel) do not add relay fees.

---

## Section 7: System & Utility Commands

### 7.1 sync - Synchronizing Data

```bash
# Full sync
docker exec alice eiou sync

# Sync specific data types
docker exec alice eiou sync contacts
docker exec alice eiou sync transactions
docker exec alice eiou sync balances
```

**When to use sync:**
- After network outage
- Balance discrepancy
- Missing transactions
- After container restart

---

### 7.2 backup - Backup Management

#### Checking Backup Status

```bash
docker exec alice eiou backup status
```

#### Creating Manual Backups

```bash
docker exec alice eiou backup create
```

#### Listing Backups

```bash
docker exec alice eiou backup list
```

#### Verifying Backups

```bash
docker exec alice eiou backup verify backup_20260126_103045.eiou.enc
```

#### Enabling/Disabling Auto-Backup

```bash
docker exec alice eiou backup enable
docker exec alice eiou backup disable
```

#### Cleanup Old Backups

```bash
docker exec alice eiou backup cleanup
```

#### Restoring from Backup

```bash
# Requires --confirm flag
docker exec alice eiou backup restore backup_20260126_030000.eiou.enc --confirm
```

**Note:** Restoration requires the original seed phrase (backups are encrypted with master key).

---

### 7.3 apikey - API Key Management

API keys allow external applications to interact with your EIOU wallet programmatically.

#### Creating an API Key

```bash
# Create with default permissions
docker exec alice eiou apikey create myapp

# Create with specific permissions
docker exec alice eiou apikey create myapp wallet:read,contacts:read
```

**Available permissions:**
- `wallet:read` - Read wallet balance and transactions
- `wallet:send` - Send transactions
- `contacts:read` - List and view contacts
- `contacts:write` - Add, update, delete contacts
- `system:read` - View system status and metrics
- `admin` - Full administrative access
- `all` - All permissions (same as admin)

#### Listing API Keys

```bash
docker exec alice eiou apikey list
```

#### Disabling/Enabling an API Key

```bash
# Disable (key remains but cannot be used)
docker exec alice eiou apikey disable <key_id>

# Re-enable a disabled key
docker exec alice eiou apikey enable <key_id>
```

#### Deleting an API Key

```bash
docker exec alice eiou apikey delete <key_id>
```

#### API Key Help

```bash
docker exec alice eiou apikey help
```

**Security Notes:**
- Store API keys securely - they provide access to wallet functions
- Use minimal permissions for each application
- Disable keys when not in active use
- Delete keys for applications no longer needed

---

## Section 8: Cleanup

### Stopping Containers (Preserving Data)

```bash
docker-compose -f docker-compose-4line.yml down
```

This stops containers but preserves all data volumes.

### Complete Cleanup (Remove All Data)

```bash
docker-compose -f docker-compose-4line.yml down -v
```

**Warning:** The `-v` flag removes all Docker volumes. This permanently deletes:
- All transaction history
- All contact relationships
- Wallet private keys
- All encrypted backups

### Verifying Cleanup

```bash
# Check no containers remain
docker ps | grep -E "alice|bob|carol|daniel"

# Check no volumes remain
docker volume ls | grep -E "alice|bob|carol|daniel"
```

**Expected:** No results (empty output) indicates complete cleanup.

### Cleaning Up Single Containers

If you created standalone containers:

```bash
# Stop and remove container
docker rm -f demo-node

# Remove associated volumes
docker volume rm demo-mysql-data demo-files demo-eiou demo-backups
```

---

## Quick Reference

### Command Summary Table

| Category | Command | Description |
|----------|---------|-------------|
| **Wallet** | `eiou info` | Wallet information |
| | `eiou overview` | Dashboard summary |
| **Contacts** | `eiou add <addr> <name> <fee> <credit> <currency>` | Add contact |
| | `eiou search [name]` | Search contacts |
| | `eiou viewcontact <name>` | View contact details |
| | `eiou pending` | Show pending requests |
| | `eiou ping <name>` | Check online status |
| | `eiou update <name> <field> <value>` | Update contact |
| | `eiou block <name>` | Block contact |
| | `eiou unblock <name>` | Unblock contact |
| | `eiou delete <name>` | Delete contact |
| **Transactions** | `eiou send <name> <amount> <currency>` | Send transaction |
| | `eiou viewbalances [name]` | View balances |
| | `eiou history [name]` | Transaction history |
| **Settings** | `eiou viewsettings` | View settings |
| | `eiou changesettings <key> <value>` | Change setting |
| **System** | `eiou sync [type]` | Synchronize data |
| | `eiou backup <action>` | Backup management |
| | `eiou help [command]` | Display help |

### Global Options

| Option | Description |
|--------|-------------|
| `--json`, `-j` | Output in JSON format |
| `--no-metadata` | Exclude metadata from JSON |

### Docker Compose Commands

| Command | Description |
|---------|-------------|
| `docker-compose -f <file>.yml up -d` | Start containers |
| `docker-compose -f <file>.yml down` | Stop, preserve data |
| `docker-compose -f <file>.yml down -v` | Stop, remove all data |
| `docker-compose -f <file>.yml logs -f` | Follow logs |
| `docker-compose -f <file>.yml ps` | Show status |

---

## Troubleshooting

### Container Not Starting

**Solutions:**
```bash
# Check logs
docker logs alice

# Reset and rebuild
docker-compose -f docker-compose-4line.yml down -v
docker-compose -f docker-compose-4line.yml up -d --build
```

### Contact Stuck in Pending

**Solutions:**
```bash
# Check if both nodes are online
docker exec alice eiou ping Bob

# Trigger sync on both nodes
docker exec alice eiou sync
docker exec bob eiou sync
```

### Transaction NO_VIABLE_ROUTE Error

**Solutions:**
```bash
# Verify contact is accepted
docker exec alice eiou viewcontact Bob

# Check contact is online
docker exec alice eiou ping Bob

# Sync both nodes
docker exec alice eiou sync
```

### Backup Restore Fails

**Solutions:**
```bash
# Verify wallet is restored first
docker exec alice eiou info

# Verify backup is valid
docker exec alice eiou backup verify <filename>
```

---

## See Also

- [CLI Reference](CLI_REFERENCE.md) - Complete CLI command documentation
- [Docker Configuration](DOCKER_CONFIGURATION.md) - Environment variables and volumes
- [API Reference](API_REFERENCE.md) - REST API documentation
- [Error Codes](ERROR_CODES.md) - Complete error code reference
