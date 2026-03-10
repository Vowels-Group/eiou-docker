# Upgrade Guide

How to update your EIOU node to the latest version while preserving your wallet, transaction history, and configuration.

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

EIOU uses Docker named volumes to separate user data from application code. When you upgrade to a new image, the container is recreated with updated code while your three named volumes are reattached, preserving all wallet data. A source file sync mechanism in `startup.sh` ensures the volume-mounted code directory is updated to match the new image.

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
| Database (transactions, contacts, balances) | `{node}-mysql-data` | `/var/lib/mysql` | All structured data |
| Wallet keys (encrypted) | `{node}-files` | `/etc/eiou/config/userconfig.json` | Public key, encrypted private key, mnemonic |
| Master encryption key | `{node}-files` | `/etc/eiou/config/.master.key` | Derived from seed phrase; recoverable via restore |
| Database credentials | `{node}-files` | `/etc/eiou/config/dbconfig.json` | Auto-generated username and password |
| User settings | `{node}-files` | `/etc/eiou/config/defaultconfig.json` | Fee preferences, transport mode, etc. |
| Encrypted backups | `{node}-backups` | `/var/lib/eiou/backups/*.eiou.enc` | AES-256-GCM encrypted database dumps |

### Updated automatically (overwritten by new image)

| Data | Container Path | Notes |
|------|----------------|-------|
| PHP source code | `/etc/eiou/src/` | Synced from image on every startup |
| Web GUI files | `/etc/eiou/www/` | Synced from image on every startup |
| API/CLI entry points | `/etc/eiou/api/`, `/etc/eiou/cli/` | Synced from image on every startup |
| Background processors | `/etc/eiou/processors/` | Synced from image on every startup |
| Composer autoloader | `/etc/eiou/vendor/` | Regenerated on every startup |

### Regenerated (not persisted across container recreation)

| Data | Container Path | Notes |
|------|----------------|-------|
| SSL certificates | `/etc/nginx/ssl/` | Self-signed certs regenerated; external certs re-copied from mount |
| Tor hidden service keys | `/var/lib/tor/hidden_service/` | Deterministically derived from wallet seed phrase |

**Tor address is stable**: The `.onion` address is derived deterministically from your BIP39 seed phrase. A new container will produce the same Tor address as long as the wallet data (in the `{node}-files` volume) is present.

---

## How It Works

The upgrade mechanism relies on four components working together:

### 1. Named Volumes Survive Container Recreation

Docker named volumes persist independently of containers. When `docker-compose up` recreates a container, the named volumes are reattached to the new container at the same mount points. The data on the volumes is untouched.

### 2. Config File Migration

Older images stored config files at `/etc/eiou/` (root level). Current images expect them at `/etc/eiou/config/`. On startup, `startup.sh` detects config files at the legacy location and migrates them to `/etc/eiou/config/` automatically. This ensures upgrades from any prior version work without manual intervention.

### 3. Source File Sync on Startup

Because `/etc/eiou/` is both a volume and the location for application code, old code would persist from the previous image if not handled. The solution:

- **At build time**: The Dockerfile copies source files into both `/etc/eiou/` (the volume target) and `/app/eiou-src-backup/` (a non-volume directory baked into the image layer).
- **At startup**: `startup.sh` copies from `/app/eiou-src-backup/` into the `/etc/eiou/` volume, overwriting old source code while leaving the `config/` subdirectory untouched.
- **After sync**: `composer install` runs to install any new dependencies and regenerate the autoloader.

### 4. Database Migrations on Application Init

`Application.php` calls `DatabaseSetup::runMigrations()` on every startup. This adds any new tables or columns required by the updated code without affecting existing data.

### Visual Flow

```
Old Container (running v1)
  ├── /var/lib/mysql          ← volume: {node}-mysql-data
  ├── /etc/eiou/              ← volume: {node}-files
  │   ├── config/             ← YOUR DATA (wallet, keys, settings)
  │   └── src/, www/, ...     ← v1 code
  └── /var/lib/eiou/backups   ← volume: {node}-backups

         │  docker-compose up -d --build
         │  (container removed, volumes kept, new image built)
         ▼

New Container (running v2) — startup.sh runs:
  1. Config migration    — moves legacy config files to /etc/eiou/config/ if needed
  2. Source file sync    — copies v2 code from image into volume
  3. Composer install    — installs new dependencies, regenerates autoloader
  4. Database migrations — adds new tables/columns as needed

Result:
  ├── /var/lib/mysql          ← same volume reattached (data intact)
  ├── /etc/eiou/              ← same volume reattached
  │   ├── config/             ← YOUR DATA (unchanged, migrated if needed)
  │   └── src/, www/, ...     ← v2 code (synced from /app/eiou-src-backup/)
  └── /var/lib/eiou/backups   ← same volume reattached (backups intact)
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
2. Docker Compose detects the image changed and recreates the container
3. Named volumes are reattached to the new container
4. `startup.sh` syncs the new code into the `/etc/eiou/` volume
5. Composer regenerates the autoloader
6. Database migrations run if needed
7. Services start normally

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

Each node's named volumes (`alice-mysql-data`, `alice-files`, `alice-backups`, etc.) are reattached to their respective new containers.

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

The source file sync will overwrite the volume code with the older version. Your data volumes remain untouched.

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

### "Backup directory /app/eiou-src-backup not found!"

The image was not rebuilt with the `--build` flag. The container is running with a cached old image.

**Fix**: `docker-compose -f <compose-file>.yml up -d --build`

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

The AES-256 master encryption key (`/etc/eiou/config/.master.key`) is deterministically derived from the BIP39 seed phrase. If the `{node}-files` volume is lost:
- Wallet keys, Tor address, auth code, and master key are all recoverable from the seed phrase
- Encrypted backups remain decryptable after a seed restore (the same master key is re-derived)

The seed phrase is the single recovery secret for the entire node.
