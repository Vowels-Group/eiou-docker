# Docker Configuration Reference

Complete reference for environment variables and volume mounts used in EIOU Docker containers.

## Table of Contents

1. [Environment Variables](#environment-variables)
2. [Volume Mounts](#volume-mounts)
3. [Network Configuration](#network-configuration)
4. [Resource Limits](#resource-limits)
5. [SSL Certificate Configuration](#ssl-certificate-configuration)
6. [Wallet Restoration](#wallet-restoration)
7. [Timeout Configuration](#timeout-configuration)
8. [Healthcheck Configuration](#healthcheck-configuration)
9. [Security Configuration](#security-configuration)
10. [Backup and Restore](#backup-and-restore)
11. [Troubleshooting](#troubleshooting)

---

## Environment Variables

### Quick Reference

| Variable | Default | Required | Description |
|----------|---------|----------|-------------|
| `QUICKSTART` | (none) | Yes* | Node hostname for HTTP/HTTPS addressing |
| `EIOU_NAME` | `$QUICKSTART` | No | Display name for the node (shown in local UI) |
| `EIOU_HOST` | `$QUICKSTART` | No | Externally reachable address (IP or domain) |
| `EIOU_PORT` | (none) | No | Port for HTTP/HTTPS URLs (appended to addresses) |
| `RESTORE` | (none) | No | 24-word seed phrase for wallet restoration |
| `RESTORE_FILE` | (none) | No | Path to file containing seed phrase |
| `SSL_DOMAIN` | `$EIOU_HOST` or `$QUICKSTART` | No | Primary domain for SSL certificate CN |
| `SSL_EXTRA_SANS` | (none) | No | Additional Subject Alternative Names |
| `LETSENCRYPT_EMAIL` | (none) | No | Email for Let's Encrypt — enables automatic trusted certs |
| `LETSENCRYPT_DOMAIN` | `$SSL_DOMAIN` | No | Domain for Let's Encrypt certificate |
| `LETSENCRYPT_STAGING` | `false` | No | Use Let's Encrypt staging server for testing |
| `EIOU_HS_TIMEOUT` | `60` | No | Tor hidden service wait timeout (seconds) |
| `EIOU_TOR_TIMEOUT` | `120` | No | Tor connectivity timeout (seconds) |
| `EIOU_TEST_MODE` | `false` | No | Enable manual message processing |
| `EIOU_CONTACT_STATUS_ENABLED` | `true` | No | Enable contact status pinging |
| `EIOU_BACKUP_AUTO_ENABLED` | `true` | No | Enable/disable automatic daily backups |
| `EIOU_AUTO_CHAIN_DROP_PROPOSE` | `true` | No | Auto-propose chain drops when mutual gaps detected |
| `EIOU_AUTO_CHAIN_DROP_ACCEPT` | `false` | No | Auto-accept incoming chain drop proposals (with balance guard) |
| `P2P_SSL_VERIFY` | `true` | No | Verify SSL certificates for P2P HTTPS connections. Set to `false` for self-signed certs |
| `P2P_CA_CERT` | (none) | No | Path to CA certificate file for P2P SSL verification |

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

#### EIOU_NAME / EIOU_HOST / EIOU_PORT

These optional variables allow separating the node's display name from its network address. When omitted, `QUICKSTART` provides backward-compatible behavior (hostname = display name = address).

`EIOU_NAME` is purely local — it is never broadcast to contacts or other nodes. It appears in the GUI wallet header, Docker startup logs, and any integration that reads the node's display name.

| Variable | Purpose | Fallback |
|----------|---------|----------|
| `EIOU_NAME` | Display name (shown in local UI) | Falls back to `QUICKSTART` |
| `EIOU_HOST` | Externally reachable address (IP or domain) | Falls back to `QUICKSTART` |
| `EIOU_PORT` | Port appended to HTTP/HTTPS URLs | Not appended if omitted |

**Example: Production node with external IP**

```yaml
environment:
  - QUICKSTART=dave           # Still needed as container hostname
  - EIOU_NAME=Dave            # Local display name
  - EIOU_HOST=88.99.69.172   # External IP address
  - EIOU_PORT=1133            # Custom port
```

This generates:
- `http://88.99.69.172:1133` (HTTP address)
- `https://88.99.69.172:1133` (HTTPS address)
- Display name: "Dave"

**Example: Docker-to-Docker (testing)**

```yaml
environment:
  - QUICKSTART=alice   # Works exactly as before - no other vars needed
```

**Priority:**
- Address: `EIOU_HOST` > `QUICKSTART`
- Name: `EIOU_NAME` > `QUICKSTART`
- SSL CN: `SSL_DOMAIN` > `EIOU_HOST` > `QUICKSTART`

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
  - "RESTORE=word1 word2 word3 ... word24"
```

Or using Docker CLI:

```bash
docker run -e "RESTORE=word1 word2 word3 ... word24" ...
```

> **Important:** The seed phrase value must be quoted because it contains spaces. Without quotes, only the first word would be captured.

> **Security Note:** When using the `RESTORE` environment variable, the container will display a warning recommending `RESTORE_FILE` instead. The `RESTORE` variable is automatically unset after successful seed restoration to prevent the seed phrase from remaining in the environment during normal operation.

> **Combining with QUICKSTART:** When both `RESTORE` (or `RESTORE_FILE`) and `QUICKSTART` are set, the wallet is first restored from the seed phrase (restoring keys and Tor address), and then the `QUICKSTART` hostname is automatically applied as the HTTP/HTTPS address. This allows a restored wallet to be reachable at both its original Tor address and the new HTTP/HTTPS hostname. Example:
> ```yaml
> environment:
>   - QUICKSTART=alice
>   - RESTORE_FILE=/restore/seed
> volumes:
>   - /path/to/seed.txt:/restore/seed:ro
> ```

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

Controls automatic daily database backups at midnight. Backups are encrypted using the master key derived from the seed phrase.

```yaml
environment:
  - EIOU_BACKUP_AUTO_ENABLED=true   # Enable (default)
  - EIOU_BACKUP_AUTO_ENABLED=false  # Disable
```

**Notes:**
- Backups are stored in `/var/lib/eiou/backups/`
- Only the 3 most recent backups are retained (configurable)
- Backups are encrypted with AES-256-GCM
- Restore requires wallet restoration first (the master key is derived from the seed phrase)

#### EIOU_AUTO_CHAIN_DROP_PROPOSE

Controls whether chain drops are automatically proposed when `send` or `ping` detects a mutual gap that sync and backup recovery cannot repair.

```yaml
environment:
  - EIOU_AUTO_CHAIN_DROP_PROPOSE=true   # Enable (default)
  - EIOU_AUTO_CHAIN_DROP_PROPOSE=false  # Disable — require manual `eiou chaindrop propose`
```

**Notes:**
- When disabled, users must manually run `eiou chaindrop propose <contact>` or use the GUI
- Sync and backup recovery still run automatically regardless of this setting
- Only affects auto-proposal; incoming proposals are still received and stored

#### EIOU_AUTO_CHAIN_DROP_ACCEPT

Controls whether incoming chain drop proposals are automatically accepted. A **balance guard** compares stored balances against transaction-calculated balances to block proposals where missing transactions would erase debt owed to us.

```yaml
environment:
  - EIOU_AUTO_CHAIN_DROP_ACCEPT=false  # Disable (default) — require manual accept
  - EIOU_AUTO_CHAIN_DROP_ACCEPT=true   # Enable — auto-accept with balance guard
```

**Notes:**
- Default is OFF for safety — all proposals require manual review via CLI or GUI
- When enabled, the balance guard blocks auto-accept if `net_missing > 0` (missing transactions include net payments to us that would be erased)
- Blocked proposals remain pending for manual review
- The guard compares stored balance (from `balances` table) with balance calculated from existing transactions; if they match, `net_missing = 0` and auto-accept proceeds

---

## Volume Mounts

### Required Volumes

All EIOU containers use named volumes for data persistence:

| Volume | Container Path | Purpose | Backup Priority |
|--------|----------------|---------|-----------------|
| `{node}-mysql-data` | `/var/lib/mysql` | Database: transactions, contacts, balances | **CRITICAL** |
| `{node}-files` | `/etc/eiou/` | Config: wallet keys, config/userconfig.json, encryption data | **CRITICAL** |
| `{node}-backups` | `/var/lib/eiou/backups` | Encrypted database backups | **CRITICAL** |

**Example:**
```yaml
volumes:
  - alice-mysql-data:/var/lib/mysql     # Transaction history, contacts
  - alice-files:/etc/eiou/              # Wallet keys, configuration
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

#### Single Node

| Port | Protocol | Purpose |
|------|----------|---------|
| 80   | TCP      | HTTP web interface and API |
| 443  | TCP      | HTTPS web interface and API (SSL) |

#### Multi-Node Topologies

All nodes in multi-node setups expose unique ports for external access:

| Topology | Nodes | HTTP Ports | HTTPS Ports |
|----------|-------|------------|-------------|
| 4-line | alice, bob, carol, daniel | 8080-8083 | 8443-8446 |
| 10-line | node-a through node-j | 8080-8089 | 8443-8452 |
| cluster | cluster-a0 through cluster-a42 | 8080-8092 | 8443-8455 |

**Example Access:**
```bash
# Access alice (4-line topology)
curl http://localhost:8080/api/v1/system/status
curl -k https://localhost:8443/api/v1/system/status

# Access bob (4-line topology)
curl http://localhost:8081/api/v1/system/status
```

Containers also communicate internally via the Docker network using their hostnames (e.g., `http://alice`, `https://bob`).

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

## Resource Limits

All EIOU containers are configured with resource limits to prevent runaway resource consumption and ensure predictable performance.

### Default Resource Configuration

```yaml
deploy:
  resources:
    limits:
      cpus: '1.0'
      memory: 512M
    reservations:
      memory: 256M
```

### Resource Parameters

| Parameter | Value | Description |
|-----------|-------|-------------|
| `limits.cpus` | `1.0` | Maximum CPU cores the container can use |
| `limits.memory` | `512M` | Maximum memory the container can use |
| `reservations.memory` | `256M` | Guaranteed minimum memory allocation |

### Memory Requirements by Topology

| Topology | Nodes | Per-Node Limit | Total Reserved | Total Limit |
|----------|-------|----------------|----------------|-------------|
| Single | 1 | 512M | 256M | 512M |
| 4-line | 4 | 512M each | 1GB | 2GB |
| 10-line | 10 | 512M each | 2.5GB | 5GB |
| Cluster | 13 | 512M each | 3.25GB | 6.5GB |

### Customizing Resource Limits

Override resource limits for specific environments:

```yaml
services:
  alice:
    deploy:
      resources:
        limits:
          cpus: '2.0'      # Allow 2 CPU cores
          memory: 1G       # Allow 1GB memory
        reservations:
          memory: 512M     # Reserve 512MB minimum
```

**Note:** Resource limits require Docker Compose v2 or Docker Swarm mode. In Docker Compose v1, the `deploy` section is ignored when not using `docker stack deploy`.

---

## SSL Certificate Configuration

### Certificate Priority

The container selects SSL certificates in this order:

1. **External certificates** (`/ssl-certs/server.crt`) - Mounted externally-obtained certs
2. **Let's Encrypt** (automatic) - When `LETSENCRYPT_EMAIL` is set with a valid FQDN
3. **CA-signed** (`/ssl-ca/ca.crt`) - Self-generated, signed by mounted CA
4. **Self-signed** - Generated automatically using SSL_DOMAIN or QUICKSTART

### Let's Encrypt (Automatic — Recommended for Production)

Let's Encrypt provides free, browser-trusted SSL certificates. Two approaches are supported:

#### Single Node (In-Container Certbot)

For a single node with port 80 reachable from the internet:

```yaml
services:
  eiou:
    ports:
      - "80:80"
      - "443:443"
    environment:
      - QUICKSTART=node.example.com
      - LETSENCRYPT_EMAIL=admin@example.com
      # - LETSENCRYPT_STAGING=true   # Uncomment to test first (avoids rate limits)
    volumes:
      - eiou-letsencrypt:/etc/letsencrypt   # Persist certs across restarts
volumes:
  eiou-letsencrypt:
```

The container will:
1. Request a certificate from Let's Encrypt on first boot
2. Install a daily cron job for automatic renewal
3. Fall back to self-signed if the request fails

**Requirements:**
- `LETSENCRYPT_EMAIL` must be set (also receives expiry warnings)
- Domain must be a real FQDN (not IP, localhost, or container name)
- Port 80 must be reachable from the public internet for the ACME HTTP-01 challenge
- DNS must resolve the domain to the server's public IP

| Variable | Default | Description |
|----------|---------|-------------|
| `LETSENCRYPT_EMAIL` | (none) | Email for Let's Encrypt registration — enables LE when set |
| `LETSENCRYPT_DOMAIN` | `$SSL_DOMAIN` | Domain for the certificate (falls back to SSL_DOMAIN → EIOU_HOST → QUICKSTART) |
| `LETSENCRYPT_STAGING` | `false` | Use staging server for testing (certs won't be browser-trusted) |

#### Multiple Nodes, Same Domain, Different Ports (Recommended)

SSL certificates validate the **domain name**, not the port. A single standard certificate for `wallet.example.com` is valid on every port — `https://wallet.example.com:1153`, `https://wallet.example.com:1154`, etc. This means 2–150+ nodes on one server can all share one regular (non-wildcard) certificate.

**Step 1: Install certbot on the host server:**

```bash
sudo apt install certbot
```

**Step 2: Get a single cert (run once on the host):**

```bash
# Option A: HTTP-01 (if port 80 is open on the server)
./scripts/create-ssl-letsencrypt.sh \
    -d wallet.example.com \
    -e admin@example.com

# Option B: DNS-01 (no port 80 needed — uses DNS provider API)
./scripts/create-ssl-letsencrypt.sh \
    -d wallet.example.com \
    -e admin@example.com \
    --dns-plugin cloudflare \
    --credentials ./cloudflare.ini
```

**Step 3: Mount the cert in all containers:**

```yaml
services:
  node-1:
    ports: ["1153:443"]
    environment:
      - QUICKSTART=wallet.example.com
      - EIOU_PORT=1153
    volumes:
      - ./letsencrypt-certs:/ssl-certs:ro    # Shared cert

  node-2:
    ports: ["1154:443"]
    environment:
      - QUICKSTART=wallet.example.com
      - EIOU_PORT=1154
    volumes:
      - ./letsencrypt-certs:/ssl-certs:ro    # Same cert

  # ... repeat for all nodes (only port number changes)
```

Every container receives the same certificate file. Each node's Apache listens on 443 internally; Docker maps that to the unique external port. Only one DNS A record is needed — `wallet.example.com → <server IP>`.

**Step 4: Set up automatic renewal (host crontab):**

Let's Encrypt certificates expire after 90 days. The renewal script checks whether the certificate is due for renewal (within 30 days of expiry) and only contacts Let's Encrypt when needed — so running it daily is safe and won't hit rate limits.

Running the script manually is a one-time check:

```bash
# One-time manual check (does NOT set up automatic renewal)
./scripts/renew-ssl-letsencrypt.sh -d wallet.example.com -o ./letsencrypt-certs
```

To automate renewal, add a cron job on the host server. Open the root crontab editor:

```bash
sudo crontab -e
```

This opens a text editor (usually `nano` or `vi`) showing the root user's scheduled tasks. Add the following line at the end of the file, then save and exit:

```
0 3 * * * /path/to/scripts/renew-ssl-letsencrypt.sh \
    -d wallet.example.com -o /path/to/letsencrypt-certs \
    --restart "eiou-*" --graceful >> /var/log/eiou-ssl-renew.log 2>&1
```

Replace `/path/to/` with the actual absolute paths on your server (e.g., `/root/eiou-docker/scripts/...`).

**Flag reference:**

| Flag | Required | Description |
|------|----------|-------------|
| `-d wallet.example.com` | Yes | The domain name of the certificate to renew |
| `-o /path/to/letsencrypt-certs` | Yes | Directory where the renewed cert files are copied (the same directory mounted as `/ssl-certs` in your containers) |
| `--restart "eiou-*"` | No | After a successful renewal, restart Docker containers whose names match this pattern (e.g., `eiou-*` matches `eiou-node-1`, `eiou-node-2`, etc. — use the actual naming pattern of your containers) |
| `--graceful` | No | Used with `--restart` — sends a reload signal (SIGHUP) to containers instead of fully restarting them, avoiding downtime |

The `>> /var/log/eiou-ssl-renew.log 2>&1` part at the end redirects all output to a log file so you can check what happened later.

Most days the cron job will do nothing. Certbot only renews when the certificate is within 30 days of expiry. When it does renew, the script copies the new files into the output directory and optionally reloads containers.

#### Multiple Nodes, Different Subdomains (Wildcard)

If each node needs its own subdomain (e.g., `alice.example.com:1154`, `bob.example.com:1155`), use a wildcard certificate. A wildcard cert for `*.example.com` covers any single subdomain.

**Step 1: Get the wildcard cert (run once on the host):**

```bash
# Wildcard certs require DNS-01 (no HTTP-01 support)
sudo apt install certbot python3-certbot-dns-cloudflare

echo "dns_cloudflare_api_token = YOUR_TOKEN" > cloudflare.ini
chmod 600 cloudflare.ini

./scripts/create-ssl-letsencrypt.sh \
    -d example.com \
    -e admin@example.com \
    --wildcard \
    --dns-plugin cloudflare \
    --credentials ./cloudflare.ini
```

**Step 2: Mount the cert in all containers:**

```yaml
services:
  alice:
    ports: ["1154:443"]
    environment:
      - QUICKSTART=alice.example.com
      - EIOU_PORT=1154
    volumes:
      - ./letsencrypt-certs:/ssl-certs:ro

  bob:
    ports: ["1155:443"]
    environment:
      - QUICKSTART=bob.example.com
      - EIOU_PORT=1155
    volumes:
      - ./letsencrypt-certs:/ssl-certs:ro

  # ... each node gets a unique subdomain + port
```

Each subdomain needs a DNS A record pointing to the server IP (or use a wildcard DNS record: `*.example.com → <server IP>`).

#### Which approach to choose

| Setup | Cert Type | DNS Records | Best For |
|-------|-----------|-------------|----------|
| `wallet.example.com:1153`, `:1154`, ... | 1 standard cert | 1 A record | Simplest — all nodes share one domain |
| `alice.example.com`, `bob.example.com`, ... | 1 wildcard cert | 1 per subdomain (or wildcard DNS) | Each node has its own identity |

**DNS-01 vs HTTP-01:**

| Challenge | Port Required | Wildcard Support | Best For |
|-----------|--------------|------------------|----------|
| HTTP-01 | Port 80 | No | Single domain, port 80 available |
| DNS-01 | None | Yes | Wildcard certs, no port 80 needed |

DNS-01 uses your DNS provider's API to validate domain ownership — no port access needed. Supported providers include Cloudflare, Route53, DigitalOcean, Google Cloud DNS, and many more.

### External Certificates (Manual)

Mount externally-obtained certificates from any source:

```yaml
services:
  eiou:
    volumes:
      - /path/to/certs:/ssl-certs:ro
```

Required files in the mounted directory:
- `server.crt` - SSL certificate (PEM format)
- `server.key` - Private key (PEM format)
- `ca-chain.crt` (optional) - CA certificate chain

### Local CA (Development/Testing)

```bash
# Generate CA once
./scripts/create-ssl-ca.sh ./ssl-ca
```

Then install `ca.crt` in your browser or operating system trust store so that certificates signed by this CA are recognized as trusted:

**Chrome / Edge (Windows):**
1. Open `chrome://settings/security` (or `edge://settings/privacy`)
2. Click **Manage certificates** (opens Windows certificate manager)
3. Go to the **Trusted Root Certification Authorities** tab
4. Click **Import...** → select `ssl-ca/ca.crt`
5. Place in **Trusted Root Certification Authorities** → Finish
6. Restart Chrome

**Chrome / Edge (macOS):**
1. Double-click `ssl-ca/ca.crt` — this opens Keychain Access
2. Add to the **System** keychain
3. Find **EIOU Root CA** in the list, double-click it
4. Expand **Trust** → set **When using this certificate** to **Always Trust**
5. Close and enter your password to confirm

**Chrome (Linux):**
1. Open `chrome://settings/certificates`
2. Go to the **Authorities** tab
3. Click **Import** → select `ssl-ca/ca.crt`
4. Check **Trust this certificate for identifying websites** → OK

**Firefox (all platforms):**

Firefox uses its own certificate store, separate from the OS.

1. Open `about:preferences#privacy`
2. Scroll to **Certificates** → click **View Certificates...**
3. Go to the **Authorities** tab
4. Click **Import...** → select `ssl-ca/ca.crt`
5. Check **Trust this CA to identify websites** → OK

**Linux system-wide (for curl, wget, etc.):**
```bash
sudo cp ssl-ca/ca.crt /usr/local/share/ca-certificates/eiou-ca.crt
sudo update-ca-certificates
```

After installing the CA, mount it in docker-compose so containers generate CA-signed certificates:

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
  test: ["CMD", "curl", "-f", "http://localhost/gui/"]
  interval: 30s        # Check every 30 seconds
  timeout: 20s         # Timeout per check
  retries: 5           # Mark unhealthy after 5 failures
  start_period: 120s   # Grace period for container startup
stop_grace_period: 45s   # Time allowed for graceful shutdown
```

### Healthcheck Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `test` | `curl -f http://localhost/gui/` | Command to verify health |
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
  test: ["CMD", "curl", "-f", "http://localhost/gui/"]
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
| PHP processors | `www-data` | Background message processing (via `runuser`) |

### Critical File Permissions

| Path | Permissions | Owner | Purpose |
|------|-------------|-------|---------|
| `/etc/eiou/` | 755 (dir) / 644 (files) | www-data | Configuration |
| `/var/lib/mysql/` | 700 | mysql | Database files |
| `/var/lib/tor/hidden_service/` | 700 | debian-tor | Tor keys |
| `/etc/apache2/ssl/server.key` | 600 | root | SSL private key |

### Container Security Hardening

The reference compose files include security hardening directives:

```yaml
security_opt:
  - no-new-privileges:true    # Prevent privilege escalation
pids_limit: 200               # Limit process count per container
```

For `docker run` users, add equivalent flags:

```bash
docker run --security-opt no-new-privileges:true --pids-limit 200 ...
```

### Log Rotation

Docker stdout/stderr log rotation is configured in the reference compose files:

```yaml
logging:
  driver: json-file
  options:
    max-size: "10m"
    max-file: "3"
```

For `docker run` users or daemon-level configuration, add to `/etc/docker/daemon.json`:

```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
```

Application logs (Apache, PHP) inside the container are rotated by `logrotate` (weekly, 4 rotations, compressed).

### Base Image Pinning

The base image in `eiou.dockerfile` is pinned to a SHA256 digest to ensure reproducible builds and prevent supply chain attacks from upstream tag republishing.

To check whether the pinned digest is current, run `./scripts/check-base-image.sh`. For full verification and update instructions, see [SECURITY.md](../SECURITY.md#base-image-integrity).

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
- Encrypted with AES-256-GCM using the master key (derived from the seed phrase)
- Only the 3 most recent backups are retained by default
- Backup files are named with timestamps: `backup_YYYYMMDD_HHmmss.eiou.enc`

### CLI Backup Commands

The `eiou` CLI provides commands for manual backup and restore operations:

```bash
# Create a manual backup
docker exec <container> eiou backup create

# List available backups
docker exec <container> eiou backup list

# Restore from a specific backup (requires --confirm flag)
docker exec <container> eiou backup restore <backup-file> --confirm

# Delete old backups (keeps most recent 3)
docker exec <container> eiou backup cleanup
```

**Important:** Restore operations require the wallet to be restored first, as backups are encrypted with the master key derived from the seed phrase.

### Critical Data

**Must backup:**
- `{node}-mysql-data` - Contains all transaction history and contact relationships
- `{node}-files` - Contains wallet private keys and encryption keys
- `{node}-backups` - Contains encrypted database backups

**Optional backup:**
- `{node}-eiou` - Regenerated on container startup

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
   docker exec <container> eiou backup restore backup_20260115_000000.eiou.enc --confirm
   ```

### Complete Reset

Remove all data and start fresh:

```bash
docker compose down -v
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
1. Use Let's Encrypt for automatic trusted certificates (see SSL Certificate Configuration)
2. Use `./scripts/create-ssl-ca.sh` to create local CA for development
3. Add exception in browser for quick testing

#### P2P HTTPS Fails Between Docker Nodes (Self-Signed Certificates)

**Cause:** SSL peer verification is enabled by default. Docker nodes using `QUICKSTART` generate self-signed certificates that are not trusted by other nodes.

**Error:** `HTTP request failed: SSL certificate problem: self-signed certificate`

**Solutions:**

1. **Disable verification for development/testing** (easiest):
```yaml
environment:
  - P2P_SSL_VERIFY=false
```

2. **Use a shared CA certificate** (recommended for production):
```bash
# Generate a local CA
./scripts/create-ssl-ca.sh
```
```yaml
environment:
  - P2P_CA_CERT=/ssl-ca/ca.crt
volumes:
  - ./ssl-ca:/ssl-ca:ro
```

3. **Use Let's Encrypt** for real trusted certificates (see SSL Certificate Configuration)

> **Note:** Self-signed certificates generated by `QUICKSTART` will always be rejected by other nodes unless `P2P_SSL_VERIFY=false` is set or a shared CA is configured. This is intentional — P2P SSL verification is enabled by default for security.

#### Let's Encrypt Certificate Request Failed

**Cause:** ACME challenge could not be completed

**Solutions:**
- Ensure port 80 is reachable from the internet (for HTTP-01 challenge)
- Verify the domain resolves to the server's public IP: `dig +short yourdomain.com`
- Test with staging first: `LETSENCRYPT_STAGING=true` (avoids rate limits)
- Check certbot logs: `docker exec <container> cat /var/log/letsencrypt/letsencrypt.log`
- For multi-node setups, use the host-level wildcard approach instead (see docs)

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
- [docker-compose.yml](../docker-compose.yml) - Reference template with inline documentation
