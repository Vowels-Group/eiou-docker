# Upgrade Guide

How to update your eIOU node to the latest version while preserving your wallet, transaction history, and configuration.

## Table of Contents

1. [Overview](#overview)
2. [What Gets Preserved](#what-gets-preserved)
3. [How It Works](#how-it-works)
4. [Upgrade Procedures](#upgrade-procedures)
5. [Verification](#verification)
6. [Rollback](#rollback)
7. [Troubleshooting](#troubleshooting)

---

## Overview

eIOU uses Docker named volumes to separate user data from application code. Source code lives at `/app/eiou/` (baked into the image), while user data is persisted on named volumes. When you upgrade to a new image, the container is recreated with updated code while your three named volumes are reattached, preserving all wallet data.

**The short version:**

```bash
# 1. Create a backup
docker exec <container> eiou backup create

# 2. Pull/build the new image, recreate the container
docker-compose -f <compose-file>.yml up -d --build

# 3. Verify
docker exec <container> eiou info
```

Your wallet, contacts, transaction history, and settings are preserved automatically.

---

## What Gets Preserved

### Preserved (stored on named volumes)

| Data | Volume | Container Path | Notes |
|------|--------|----------------|-------|
| Database (transactions, contacts, balances) | `{node}-mysql-data` | `/var/lib/mysql` | All structured data (encrypted at rest via MariaDB TDE) |
| Wallet keys (encrypted) | `{node}-config` | `/etc/eiou/config/userconfig.json` | Public key, encrypted private key, mnemonic |
| Master encryption key | `{node}-config` | `/etc/eiou/config/.master.key` | Derived from seed phrase; recoverable via restore. Optionally encrypted with a volume passphrase (stored as `.master.key.enc`) |
| Database credentials (encrypted) | `{node}-config` | `/etc/eiou/config/dbconfig.json` | Auto-generated; encrypted at rest with AES-256-GCM |
| User settings | `{node}-config` | `/etc/eiou/config/defaultconfig.json` | Fee preferences, transport mode, update check preference, etc. |
| MariaDB TDE config | `{node}-mysql-data` | `/etc/mysql/conf.d/encryption.cnf` | Generated on first boot after wallet creation |
| Encrypted backups | `{node}-backups` | `/var/lib/eiou/backups/*.eiou.enc` | AES-256-GCM encrypted database dumps |

### Updated automatically (overwritten by new image)

| Data | Container Path | Notes |
|------|----------------|-------|
| PHP source code | `/app/eiou/src/` | Baked into image at build time |
| Web GUI files | `/app/eiou/www/` | Baked into image at build time |
| API/CLI entry points | `/app/eiou/api/`, `/app/eiou/cli/` | Baked into image at build time |
| Background processors | `/app/eiou/processors/` | Baked into image at build time |
| Composer autoloader | `/app/eiou/vendor/` | Baked into image at build time |

### Regenerated (not persisted across container recreation)

| Data | Container Path | Notes |
|------|----------------|-------|
| SSL certificates | `/etc/nginx/ssl/` | Self-signed certs regenerated; external certs re-copied from mount |
| Tor hidden service keys | `/var/lib/tor/hidden_service/` | Deterministically derived from wallet seed phrase |
| MariaDB TDE key file | `/dev/shm/.mariadb-encryption-key` | RAM-backed; re-derived from master key on every boot |

**Tor address is stable**: The `.onion` address is derived deterministically from your BIP39 seed phrase. A new container will produce the same Tor address as long as the wallet data (in the `{node}-config` volume) is present.

---

## How It Works

The upgrade mechanism relies on several components working together:

### 1. Named Volumes Survive Container Recreation

Docker named volumes persist independently of containers. When `docker-compose up` recreates a container, the named volumes are reattached to the new container at the same mount points. The data on the volumes is untouched.

### 2. Config File Migration

Older images stored config files at `/etc/eiou/` (root level). Current images expect them at `/etc/eiou/config/`. On startup, `startup.sh` detects config files at the legacy location and migrates them to `/etc/eiou/config/` automatically. This ensures upgrades from any prior version work without manual intervention.

### 3. Source/Data Separation

Source code and user data are stored in separate locations:

- **Source code** (`/app/eiou/`) is baked into the image at build time. When a new image is built, it contains the updated code. No runtime sync is needed.
- **User data** (`/etc/eiou/config/`) is stored on the `{node}-config` named volume and is never overwritten by image updates.
- **After startup**: `composer install` runs to install any new dependencies and regenerate the autoloader.

### 4. Database Migrations on Application Init

`Application.php` calls `DatabaseSetup::runMigrations()` on every startup. This adds any new tables or columns required by the updated code without affecting existing data.

### 5. Automatic Pre-Shutdown Backup

When the container receives SIGTERM (from `docker compose up -d --build` or `docker compose down`), `graceful_shutdown()` creates an encrypted database backup before stopping any processors or services. This ensures a recent backup exists even if the user forgot to run `eiou backup create` manually. The backup is stored on the `{node}-backups` volume, which is preserved across container recreations.

### 6. Maintenance Mode During Startup

On startup, `startup.sh` creates a lockfile (`/tmp/eiou_maintenance.lock`) before beginning database migrations. While this lockfile exists, all HTTP entry points (API, GUI, P2P transport) return `503 Service Unavailable` with a `Retry-After: 30` header. This prevents:
- Requests hitting a mid-migration database schema
- Incoming P2P messages being processed before the node is fully initialized

The lockfile is removed after all initialization is complete (composer install, migrations, processor startup).

### 7. Data-at-Rest Encryption (MariaDB TDE)

On first startup after wallet creation, MariaDB Transparent Data Encryption (TDE) is enabled automatically. The TDE key is derived from the master encryption key via HMAC-SHA256 and written to `/dev/shm` (RAM-backed, lost on restart). MariaDB is restarted once to load the `file_key_management` plugin, then all existing tables are encrypted. On subsequent boots, the TDE key is re-derived before MariaDB starts — no user action is needed.

Database credentials in `dbconfig.json` are also encrypted at rest with AES-256-GCM on first boot after the master key becomes available.

### 8. Update Version Check

A daily cron job (2 AM UTC) checks Docker Hub for newer image tags and caches the result in `/etc/eiou/config/update-check.json`. If Docker Hub is unreachable, it falls back to GitHub Releases. The check is read-only, cached for 24 hours, and respects the user's `updateCheckEnabled` setting (enabled by default). Tor-only nodes silently skip the check. When an update is available, a notification is shown in the GUI dashboard.

### Visual Flow

```
Old Container (running v1) — receives SIGTERM:
  1. Pre-shutdown backup   — encrypted backup saved to {node}-backups volume
  2. Processor shutdown    — SIGTERM to all PHP processors, wait for completion
  3. Service shutdown      — web server, MariaDB, Tor, cron stopped in order
  4. Lockfile cleanup      — processor lockfiles and shutdown flag removed

         │  container removed, volumes kept, new image built
         ▼

New Container (running v2) — startup.sh runs:
  1. Maintenance mode ON   — /tmp/eiou_maintenance.lock created (HTTP → 503)
  2. Config migration      — moves legacy config files to /etc/eiou/config/ if needed
  3. Volume decryption     — if volume passphrase active, decrypt master key to /dev/shm
  4. TDE key setup         — derive MariaDB TDE key from master key, write to /dev/shm
  5. Composer install      — installs new dependencies, regenerates autoloader
  6. Services start        — web server, MariaDB, Tor, cron
  7. Database migrations   — adds new tables/columns as needed
  8. TDE first-time setup  — if new, encrypt existing tables (MariaDB restarts once)
  9. Credential encryption — encrypt dbconfig.json credentials if not already encrypted
  10. Update check cron    — install daily version check (if enabled)
  11. Maintenance mode OFF — lockfile removed, HTTP requests accepted
  12. Processors start     — P2P, Transaction, Cleanup, ContactStatus

Result:
  ├── /var/lib/mysql          ← same volume reattached (data intact)
  ├── /app/eiou/              ← v2 source code (baked into image)
  ├── /etc/eiou/config/       ← same volume reattached (YOUR DATA, unchanged)
  └── /var/lib/eiou/backups   ← same volume reattached (backups + pre-shutdown backup)
```

---

## Upgrade Procedures

### Method 1: Local Build (from source)

Use this when you have the repository cloned and want to build from the latest source.

```bash
cd /path/to/eiou-docker

# Pull the latest source
git pull origin main

# Create a pre-upgrade backup
docker exec <container-name> eiou backup create

# Rebuild image and recreate container (volumes preserved)
docker-compose -f <compose-file>.yml up -d --build
```

**What happens:**
1. Docker builds a new image from the updated source
2. Docker Compose sends SIGTERM to the running container
3. **Automatic pre-shutdown backup** is created (encrypted, stored on `{node}-backups` volume)
4. PHP processors receive SIGTERM and finish their current work gracefully
5. Services stop in reverse order (web server, MariaDB, Tor, cron)
6. Container is removed, named volumes are kept
7. New container starts with **maintenance mode** enabled (HTTP requests return 503)
8. New source code is available at `/app/eiou/` (baked into the image)
9. Composer regenerates the autoloader
10. Database migrations run if needed
11. Maintenance mode is released — HTTP requests are accepted again
12. Background processors start normally

### Method 2: Docker Hub Pull

Use this when pulling a pre-built image from Docker Hub.

```bash
# Create a pre-upgrade backup
docker exec <container-name> eiou backup create

# Pull the latest image
docker pull eiou/eiou:latest

# Recreate container with the new image
docker-compose -f <compose-file>.yml up -d
```

> **Note**: If your `docker-compose` file uses `build:` instead of `image:`, you need to either change it to `image: eiou/eiou:latest` or use Method 1 instead.

### Method 3: Multi-Node Upgrade (4-line, 10-line, cluster)

For multi-node topologies, all nodes are upgraded together since they share the same image.

```bash
cd /path/to/eiou-docker

# Pull latest source
git pull origin main

# Create backups for all nodes
docker-compose -f docker-compose-4line.yml exec alice eiou backup create
docker-compose -f docker-compose-4line.yml exec bob eiou backup create
docker-compose -f docker-compose-4line.yml exec carol eiou backup create
docker-compose -f docker-compose-4line.yml exec daniel eiou backup create

# Rebuild and recreate all containers
docker-compose -f docker-compose-4line.yml up -d --build
```

Each node's named volumes (`alice-mysql-data`, `alice-config`, `alice-backups`, etc.) are reattached to their respective new containers.

---

## Verification

After upgrading, verify the node is running correctly:

```bash
# Check container is healthy
docker ps | grep eiou

# Check node information (wallet address, hostname, etc.)
docker exec <container-name> eiou info --show-auth

# Check backup list (should include pre-upgrade backup)
docker exec <container-name> eiou backup list

# View startup logs for any errors
docker logs <container-name> 2>&1 | head -100
```

**What to look for in the logs:**
- `Syncing source files from image to volume...` -- source code was updated
- `Source file sync completed.` -- sync succeeded
- `Composer autoloader generated successfully.` -- autoloader rebuilt
- `Initialization successful` or `Wallet already configured` -- wallet data found
- `MariaDB TDE: encryption key ready` -- TDE key derived successfully
- `Enabling MariaDB data-at-rest encryption...` -- first-time TDE setup (normal on first upgrade)
- `Update check cron job installed (daily at 2 AM UTC)` -- version check active

---

## Rollback

If the new version has issues and you need to go back:

### Option A: Rebuild from an Older Commit

```bash
cd /path/to/eiou-docker

# Check out the previous working version
git log --oneline -10       # find the commit hash
git checkout <commit-hash>

# Rebuild with the old code
docker-compose -f <compose-file>.yml up -d --build
```

The older image contains the previous source code at `/app/eiou/`. Your data volumes remain untouched.

### Option B: Restore from Backup

If the database was affected by a migration issue:

```bash
# List available backups
docker exec <container-name> eiou backup list

# Restore a specific backup
docker exec <container-name> eiou backup restore <backup-filename>
```

---

## Troubleshooting

### Container starts but wallet is gone

The named volumes were removed (likely by `docker-compose down -v`). The `-v` flag deletes all named volumes.

**Recovery options:**
1. **From backup**: If the `{node}-backups` volume still exists, or you have a backup file, restore from it
2. **From seed phrase**: If you have your 24-word mnemonic, restore the wallet:
   ```yaml
   environment:
     - RESTORE=word1 word2 word3 ... word24
   ```
   The master key is derived deterministically from the seed phrase, so restoring from seed recovers the same master key. Old encrypted backups remain decryptable.

### Database connection error after upgrade

The `dbconfig.json` file may have been accidentally removed or corrupted. Check that it exists:

```bash
docker exec <container-name> cat /etc/eiou/config/dbconfig.json
```

If missing, the database credentials are lost. You would need to restore from backup with a fresh wallet setup.

### SSL certificate warnings after upgrade

Self-signed SSL certificates are regenerated when a container is recreated. This is normal. If you use external certificates, ensure your bind mount is configured:

```yaml
volumes:
  - /path/to/certs:/ssl-certs:ro
```

### MariaDB fails to start after upgrade

If the TDE encryption plugin was enabled on a previous boot but the TDE key file is missing, MariaDB cannot read its encrypted tables. Check the logs for `WARNING: Failed to prepare TDE key file`. This can happen if the master key is unavailable (e.g., volume passphrase not provided). Ensure the `{node}-config` volume has `.master.key` (or `.master.key.enc` with the correct `EIOU_VOLUME_KEY_FILE`).

### Volume passphrase lost

If you enabled volume passphrase encryption (`EIOU_VOLUME_KEY_FILE`) and lost the passphrase, the encrypted master key cannot be decrypted. Recovery requires restoring from your 24-word seed phrase, which re-derives the master key from scratch. Old encrypted backups remain decryptable after seed restore.

### Permissions errors in logs

The source sync may have set incorrect permissions. This is usually handled automatically, but if errors persist:

```bash
docker exec <container-name> bash -c "
  chown -R www-data:www-data /etc/eiou/config/
  chmod 600 /etc/eiou/config/.master.key
  chmod 600 /etc/eiou/config/userconfig.json
  chmod 600 /etc/eiou/config/dbconfig.json
"
```

---

## Important Notes

### Do NOT use `docker-compose down -v`

The `-v` flag removes named volumes, which **permanently deletes all wallet data, transaction history, and backups**. Only use `-v` if you intentionally want to start fresh.

| Command | Volumes | Safe for upgrade? |
|---------|---------|-------------------|
| `docker-compose down` | Preserved | Yes |
| `docker-compose up -d --build` | Preserved | Yes |
| `docker-compose down -v` | **Deleted** | **No -- data loss** |

### Back Up Your Seed Phrase

Your 24-word BIP39 mnemonic is displayed only once during initial wallet generation. Store it securely offline. It is your last-resort recovery method if all volumes are lost.

### Back Up Before Every Upgrade

Always run `eiou backup create` before upgrading. The encrypted backup file can be used to restore your database if anything goes wrong. Backup files are stored on the `{node}-backups` volume, which is preserved across upgrades.

### Master Key Is Derived from Seed

The AES-256 master encryption key (`/etc/eiou/config/.master.key`) is deterministically derived from the BIP39 seed phrase. All other encryption keys (MariaDB TDE, database credential encryption, backup encryption) are derived from this single master key. If the `{node}-config` volume is lost:
- Wallet keys, Tor address, auth code, and master key are all recoverable from the seed phrase
- Encrypted backups remain decryptable after a seed restore (the same master key is re-derived)
- MariaDB TDE and credential encryption are re-established automatically on next boot

The seed phrase is the single recovery secret for the entire node.