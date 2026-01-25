# Docker Configuration Reference

Complete reference for environment variables and volume mounts used in EIOU Docker containers.

## Table of Contents

1. [Environment Variables](#environment-variables)
2. [Volume Mounts](#volume-mounts)
3. [Network Configuration](#network-configuration)
4. [SSL Certificate Configuration](#ssl-certificate-configuration)
5. [Wallet Restoration](#wallet-restoration)
6. [Timeout Configuration](#timeout-configuration)
7. [Healthcheck Configuration](#healthcheck-configuration)
8. [Security Configuration](#security-configuration)
9. [Backup and Restore](#backup-and-restore)
10. [Troubleshooting](#troubleshooting)

---

## Environment Variables

### Quick Reference

| Variable | Default | Required | Description |
|----------|---------|----------|-------------|
| `QUICKSTART` | (none) | Yes* | Node hostname for HTTP/HTTPS addressing |
| `RESTORE` | (none) | No | 24-word seed phrase for wallet restoration |
| `RESTORE_FILE` | (none) | No | Path to file containing seed phrase |
| `SSL_DOMAIN` | `$QUICKSTART` | No | Primary domain for SSL certificate CN |
| `SSL_EXTRA_SANS` | (none) | No | Additional Subject Alternative Names |
| `EIOU_HS_TIMEOUT` | `60` | No | Tor hidden service wait timeout (seconds) |
| `EIOU_TOR_TIMEOUT` | `120` | No | Tor connectivity timeout (seconds) |
| `EIOU_TEST_MODE` | `false` | No | Enable manual message processing |
| `EIOU_CONTACT_STATUS_ENABLED` | `true` | No | Enable contact status pinging |
| `EIOU_BACKUP_AUTO_ENABLED` | `true` | No | Enable/disable automatic daily backups |

*Required unless using Tor-only mode

### Detailed Descriptions

#### QUICKSTART

Sets the node's hostname for HTTP/HTTPS addressing. When set, the node generates a self-signed SSL certificate and is reachable at `https://<value>` or `http://<value>`.

```yaml
environment:
  - QUICKSTART=alice
```

The node will be accessible at:
- `http://alice` (within Docker network)
- `https://alice` (within Docker network)

#### RESTORE / RESTORE_FILE

Restore a wallet from a BIP39 24-word seed phrase. Two methods are available:

**Method 1: File-based (RECOMMENDED)**

More secure as the seed phrase is not visible in process listings or environment variable dumps.

```yaml
environment:
  - RESTORE_FILE=/restore/seed
volumes:
  - /path/to/seed.txt:/restore/seed:ro
```

**Method 2: Environment variable**

Convenient but less secure - the seed phrase may be visible in logs or process listings.

```yaml
environment:
  - RESTORE=word1 word2 word3 ... word24
```

#### SSL_DOMAIN

Override the primary domain used in the SSL certificate's Common Name (CN). Useful when the container hostname differs from the external domain.

```yaml
environment:
  - QUICKSTART=internal-name
  - SSL_DOMAIN=public.example.com
```

#### SSL_EXTRA_SANS

Add additional Subject Alternative Names (SANs) to the SSL certificate. Format: comma-separated list of `TYPE:value` pairs.

```yaml
environment:
  - SSL_EXTRA_SANS=DNS:alt.example.com,IP:192.168.1.100,DNS:node.local
```

Supported types:
- `DNS:hostname` - Additional DNS names
- `IP:address` - IP addresses

#### EIOU_TEST_MODE

Enables test mode for manual message processing. When enabled:
- Unlocks CLI commands `eiou in` and `eiou out` for manual message queue processing
- Bypasses rate limiting for automated testing
- Should only be used in development/testing environments

```yaml
environment:
  - EIOU_TEST_MODE=true
```

**Warning:** Never enable in production - bypasses security rate limiting.

#### EIOU_CONTACT_STATUS_ENABLED

Controls the contact status polling background processor. When enabled (default), the node periodically pings contacts to update their online/offline status.

```yaml
environment:
  - EIOU_CONTACT_STATUS_ENABLED=true   # Enable (default)
  - EIOU_CONTACT_STATUS_ENABLED=false  # Disable
```

**Use Cases:**
- Disable during automated tests to prevent interference with sync operations
- Disable in low-bandwidth environments to reduce network traffic

#### EIOU_BACKUP_AUTO_ENABLED

Controls automatic daily database backups at midnight. Backups are encrypted using the user's master key.

```yaml
environment:
  - EIOU_BACKUP_AUTO_ENABLED=true   # Enable (default)
  - EIOU_BACKUP_AUTO_ENABLED=false  # Disable
```

**Notes:**
- Backups are stored in `/var/lib/eiou/backups/`
- Only the 3 most recent backups are retained (configurable)
- Backups are encrypted with AES-256-GCM
- Restore requires wallet restoration first (master key dependency)

---

## Volume Mounts

### Required Volumes

All EIOU containers use four named volumes for data persistence:

| Volume | Container Path | Purpose | Backup Priority |
|--------|----------------|---------|-----------------|
| `{node}-mysql-data` | `/var/lib/mysql` | Database: transactions, contacts, balances | **CRITICAL** |
| `{node}-files` | `/etc/eiou/` | Config: wallet keys, userconfig.json, encryption data | **CRITICAL** |
| `{node}-backups` | `/var/lib/eiou/backups` | Encrypted database backups | **CRITICAL** |
| `{node}-index` | `/var/www/html` | Web: GUI and API files | Low (regenerated) |
| `{node}-eiou` | `/usr/local/bin/` | CLI: eiou command-line tool | Low (regenerated) |

**Example:**
```yaml
volumes:
  - alice-mysql-data:/var/lib/mysql     # Transaction history, contacts
  - alice-files:/etc/eiou/              # Wallet keys, configuration
  - alice-index:/var/www/html           # Web interface
  - alice-eiou:/usr/local/bin/          # CLI tool
```

### Optional Volumes

#### External SSL Certificates

Mount externally-obtained SSL certificates (Let's Encrypt, commercial CA, etc.):

```yaml
volumes:
  - /path/to/certs:/ssl-certs:ro
```

Required files in the mounted directory:
- `server.crt` - SSL certificate (PEM format)
- `server.key` - Private key (PEM format)
- `ca.crt` (optional) - CA certificate chain

#### CA-Signed Certificates

Mount a local Certificate Authority for signing certificates:

```yaml
volumes:
  - ./ssl-ca:/ssl-ca:ro
```

Required files:
- `ca.crt` - CA certificate
- `ca.key` - CA private key

Generate a CA using: `./scripts/create-ssl-ca.sh ./ssl-ca`

---

## Network Configuration

### Docker Network

EIOU containers communicate over a Docker bridge network. All compose files use a shared network named `eiou-network-compose`.

```yaml
networks:
  eiou-network-compose:
    driver: bridge
    name: eiou-network-compose
```

### Port Mappings

| Port | Protocol | Purpose |
|------|----------|---------|
| 80   | TCP      | HTTP web interface and API |
| 443  | TCP      | HTTPS web interface and API (SSL) |

**Note:** Only the first container in multi-node setups should expose ports 80/443 to the host. Other containers communicate internally via the Docker network.

### Container Hostname Resolution

Containers use their service name or container_name as their hostname within the Docker network:

```bash
# From inside container 'alice', reach 'bob' via:
curl http://bob/api/status    # Container name
curl https://bob/api/status   # HTTPS with self-signed cert
```

### Tor Network

EIOU containers automatically configure Tor hidden services:
- Hidden service directory: `/var/lib/tor/hidden_service/`
- Hidden service port: Maps external port 80 to internal Apache
- Tor SOCKS proxy: Available at `127.0.0.1:9050` inside the container

---

## SSL Certificate Configuration

### Certificate Priority

The container selects SSL certificates in this order:

1. **External certificates** (`/ssl-certs/server.crt`) - Mounted externally-obtained certs
2. **CA-signed** (`/ssl-ca/ca.crt`) - Self-generated, signed by mounted CA
3. **Self-signed** - Generated automatically using SSL_DOMAIN or QUICKSTART

### Let's Encrypt Example

```yaml
services:
  eiou:
    environment:
      - QUICKSTART=mynode
      - SSL_DOMAIN=node.example.com
    volumes:
      - /etc/letsencrypt/live/node.example.com:/ssl-certs:ro
```

### Local CA Example

```bash
# Generate CA once
./scripts/create-ssl-ca.sh ./ssl-ca

# Install ca.crt in your browser's trust store
```

```yaml
services:
  eiou:
    environment:
      - QUICKSTART=alice
    volumes:
      - ./ssl-ca:/ssl-ca:ro
```

---

## Wallet Restoration

### Security Recommendations

1. **Prefer RESTORE_FILE over RESTORE** - File-based restoration is more secure
2. **Use read-only mounts** - Mount seed files as `:ro`
3. **Delete seed files after restoration** - Don't leave them mounted permanently
4. **Use secrets management** - In production, consider Docker secrets or vault

### Restoration Process

1. Create seed file:
   ```bash
   echo "word1 word2 ... word24" > /secure/location/seed.txt
   chmod 600 /secure/location/seed.txt
   ```

2. Start container with restoration:
   ```yaml
   environment:
     - RESTORE_FILE=/restore/seed
   volumes:
     - /secure/location/seed.txt:/restore/seed:ro
   ```

3. After successful restoration, remove the mount and restart

---

## Timeout Configuration

### Default Timeouts

| Variable | Default | Purpose |
|----------|---------|---------|
| `EIOU_HS_TIMEOUT` | 60s | Wait for Tor hidden service to become available |
| `EIOU_TOR_TIMEOUT` | 120s | Wait for Tor network connectivity |
| `EIOU_INIT_TIMEOUT` | 120s | Container initialization timeout (test runner) |

### WSL2 / Slow Environments

Increase timeouts for slower environments:

```yaml
environment:
  - EIOU_HS_TIMEOUT=120
  - EIOU_TOR_TIMEOUT=240
```

### Disabling Tor Wait

For HTTP-only testing, you can reduce Tor timeouts:

```yaml
environment:
  - EIOU_HS_TIMEOUT=5
  - EIOU_TOR_TIMEOUT=10
```

---

## Healthcheck Configuration

Docker healthchecks monitor container readiness. Default configuration:

```yaml
healthcheck:
  test: ["CMD", "curl", "-f", "http://localhost/"]
  interval: 30s        # Check every 30 seconds
  timeout: 20s         # Timeout per check
  retries: 5           # Mark unhealthy after 5 failures
  start_period: 120s   # Grace period for container startup
stop_grace_period: 45s   # Time allowed for graceful shutdown
```

### Healthcheck Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `test` | `curl -f http://localhost/` | Command to verify health |
| `interval` | `30s` | Time between health checks |
| `timeout` | `20s` | Maximum time for health check |
| `retries` | `5` | Failures before unhealthy |
| `start_period` | `120s` | Startup grace period |
| `stop_grace_period` | `45s` | Time for graceful shutdown before SIGKILL |

### Graceful Shutdown

The `stop_grace_period` controls how long Docker waits for the container to stop gracefully before sending SIGKILL. The 45-second default allows:

- Background processors (P2P, Transaction, Cleanup) to finish current operations
- Database connections to close cleanly
- Pending message queue items to be saved

### Custom Healthcheck for Slow Environments

For WSL2 or limited resources:

```yaml
healthcheck:
  test: ["CMD", "curl", "-f", "http://localhost/"]
  interval: 60s
  timeout: 30s
  retries: 10
  start_period: 180s
```

---

## Security Configuration

### Container Privilege Model

EIOU containers run as root during initialization, then services drop privileges:

| Service | Runtime User | Purpose |
|---------|--------------|---------|
| Apache | `www-data` | Web server and API |
| MariaDB | `mysql` | Database operations |
| Tor | `debian-tor` | Tor hidden service |
| PHP processors | `root` | Background message processing |

### Critical File Permissions

| Path | Permissions | Owner | Purpose |
|------|-------------|-------|---------|
| `/etc/eiou/` | 755 (dir) / 644 (files) | www-data | Configuration |
| `/var/lib/mysql/` | 700 | mysql | Database files |
| `/var/lib/tor/hidden_service/` | 700 | debian-tor | Tor keys |
| `/etc/apache2/ssl/server.key` | 600 | root | SSL private key |

### Secure Seed Phrase Handling

**Best Practices:**
1. Always use `RESTORE_FILE` instead of `RESTORE` environment variable
2. Mount seed files as read-only (`:ro`)
3. Delete seed files after successful restoration
4. Never commit seed phrases to version control

---

## Backup and Restore

### Automated Backup System

EIOU includes an automated backup system that creates encrypted database backups daily at midnight.

**Configuration:**
```yaml
environment:
  - EIOU_BACKUP_AUTO_ENABLED=true   # Enable automatic backups (default)
  - EIOU_BACKUP_AUTO_ENABLED=false  # Disable automatic backups
```

**Backup Details:**
- Backups run automatically at midnight (container local time)
- Stored in `/var/lib/eiou/backups/` (persisted via `{node}-backups` volume)
- Encrypted with AES-256-GCM using the wallet's master key
- Only the 3 most recent backups are retained by default
- Backup files are named with timestamps: `backup-YYYYMMDD-HHMMSS.enc`

### CLI Backup Commands

The `eiou` CLI provides commands for manual backup and restore operations:

```bash
# Create a manual backup
docker exec <container> eiou backup create

# List available backups
docker exec <container> eiou backup list

# Restore from a specific backup
docker exec <container> eiou backup restore <backup-file>

# Delete old backups (keeps most recent 3)
docker exec <container> eiou backup prune
```

**Important:** Restore operations require the wallet to be restored first, as backups are encrypted with the master key derived from the seed phrase.

### Critical Data

**Must backup:**
- `{node}-mysql-data` - Contains all transaction history and contact relationships
- `{node}-files` - Contains wallet private keys and encryption keys
- `{node}-backups` - Contains encrypted database backups

**Optional backup:**
- `{node}-index` and `{node}-eiou` - Regenerated on container startup

### Manual Volume Backup

For complete disaster recovery, back up Docker volumes directly:

```bash
# Backup volumes
docker run --rm -v alice-mysql-data:/data -v /backup:/backup \
  alpine tar czf /backup/alice-mysql-data.tar.gz -C /data .

docker run --rm -v alice-files:/data -v /backup:/backup \
  alpine tar czf /backup/alice-files.tar.gz -C /data .

docker run --rm -v alice-backups:/data -v /backup:/backup \
  alpine tar czf /backup/alice-backups.tar.gz -C /data .
```

### Restore Commands

```bash
# Restore volumes
docker run --rm -v alice-mysql-data:/data -v /backup:/backup \
  alpine sh -c "cd /data && tar xzf /backup/alice-mysql-data.tar.gz"

docker run --rm -v alice-files:/data -v /backup:/backup \
  alpine sh -c "cd /data && tar xzf /backup/alice-files.tar.gz"

docker run --rm -v alice-backups:/data -v /backup:/backup \
  alpine sh -c "cd /data && tar xzf /backup/alice-backups.tar.gz"
```

### Restore from Encrypted Backup

To restore from an encrypted backup file:

1. **Restore wallet first** (required for decryption):
   ```yaml
   environment:
     - RESTORE_FILE=/restore/seed
   volumes:
     - /path/to/seed.txt:/restore/seed:ro
   ```

2. **Start container and restore backup**:
   ```bash
   docker exec <container> eiou backup list
   docker exec <container> eiou backup restore backup-20240115-000000.enc
   ```

### Complete Reset

Remove all data and start fresh:

```bash
docker-compose -f docker-compose-single.yml down -v
```

---

## Troubleshooting

### Container Startup Issues

#### Container Exits Immediately

**Symptoms:** Container starts but exits with code 1

**Solutions:**
```bash
# Check container logs
docker logs <container_name>

# Verify volume permissions
docker run --rm -v mynode-files:/data alpine ls -la /data

# Reset and rebuild
docker-compose -f <config>.yml down -v
docker-compose -f <config>.yml up -d --build
```

#### Container Stuck at "Waiting for MariaDB"

**Cause:** MariaDB taking too long to initialize (common on first startup)

**Solutions:**
- Wait longer (up to 2 minutes on first run)
- Check MariaDB logs: `docker logs <container> 2>&1 | grep -i maria`
- Ensure sufficient memory (minimum 275MB per container)

### Tor Connectivity Issues

#### Hidden Service Not Ready

**Symptoms:** "Hidden service hostname file not ready" warning

**Solution:** Increase timeout:
```yaml
environment:
  - EIOU_HS_TIMEOUT=120    # Increase from default 60s
```

#### Tor Connection Timeout

**Symptoms:** "Tor connection could not be verified" warning

**Solution:**
```yaml
environment:
  - EIOU_TOR_TIMEOUT=240   # Increase from default 120s
```

### SSL Certificate Issues

#### Browser Shows Certificate Warning

**Cause:** Self-signed certificate not trusted by browser

**Solutions:**
1. Install CA-signed certificates (see SSL Certificate Configuration)
2. Add exception in browser for development
3. Use `./scripts/create-ssl-ca.sh` to create local CA

### Performance Issues (WSL2)

**Cause:** WSL2 has slower I/O and network performance

**Solutions:**
```yaml
environment:
  - EIOU_HS_TIMEOUT=120      # Double default
  - EIOU_TOR_TIMEOUT=240     # Double default
```

For tests:
```bash
EIOU_INIT_TIMEOUT=180 ./run-all-tests.sh http4
```

### Common Log Locations

| Log | Location | Purpose |
|-----|----------|---------|
| Apache access | `/var/log/apache2/access.log` | HTTP requests |
| Apache error | `/var/log/apache2/error.log` | Web server errors |
| PHP errors | `/var/log/php_errors.log` | Application errors |
| Tor | `/var/log/tor/log` | Tor network status |

```bash
# View logs inside container
docker exec <container> tail -f /var/log/apache2/error.log
docker exec <container> tail -f /var/log/php_errors.log
```

---

## See Also

- [CLI Reference](CLI_REFERENCE.md) - Command-line interface documentation
- [API Reference](API_REFERENCE.md) - REST API documentation
- [Error Codes](ERROR_CODES.md) - Complete error code reference
- [docker-compose-single.yml](../docker-compose-single.yml) - Reference template with inline documentation
