# EIOU Docker

Run an EIOU node with a single `docker compose` command. The container includes everything needed: Apache, MariaDB, Tor, PHP processors, and the web GUI.

## Key Features

- **Web GUI Dashboard** — manage contacts, transactions, and node settings from your browser
- **P2P Transactions** — automatic multi-hop payment routing through trust networks
- **Multi-Transport** — HTTP, HTTPS, and Tor (.onion) support out of the box
- **REST API** — full API with HMAC-SHA256 authentication
- **CLI Interface** — complete command-line management via `eiou` commands
- **Encrypted Backups** — automatic daily database backups encrypted with AES-256-GCM
- **Persistent Storage** — named Docker volumes keep your data across restarts and rebuilds

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) (includes Docker Compose)

## Quick Start

```bash
# Clone the repository
git clone https://github.com/eiou-org/eiou-docker.git
cd eiou-docker

# Start the node
docker compose up -d --build

# View logs
docker compose logs -f

# Open the web GUI
# https://localhost (HTTP redirects to HTTPS by default — your browser will show a certificate warning for the self-signed cert, this is expected)
# For Tor: use Tor Browser and navigate to your node's .onion address (no certificate warning)
```

The container automatically generates a wallet, starts Tor, and initializes all services. With the default `QUICKSTART=eiou` setting, it also configures HTTP/HTTPS addresses and creates a self-signed SSL certificate — suitable for local testing. For production use (public IP, trusted SSL, custom domain), see the [Configuration](#configuration) section below. The node is ready once the healthcheck passes (~2 minutes on first boot).

## Container Management

In the examples below, `eiou-node` is the container name (set via `NODE_NAME`, see [Container & Volume Naming](#container--volume-naming)).

```bash
# Check container status and health
docker compose ps

# View real-time logs
docker compose logs -f

# Execute CLI commands inside the container
docker exec eiou-node eiou info              # Node address, Tor address, public key
docker exec eiou-node eiou info detail       # Detailed info including balances
docker exec eiou-node eiou contacts          # List contacts

# Add a contact (address is the other node's HTTP/HTTPS/Tor URL)
docker exec eiou-node eiou add <address> <name> <fee> <credit> <currency>

# Send a transaction (by contact name or address)
docker exec eiou-node eiou send <contact-name-or-address> <amount> <currency>

# Restart the container
docker compose restart

# Stop (preserves all data in volumes)
docker compose down

# Stop and DELETE all data (fresh start)
docker compose down -v
```

For the full list of CLI commands, see the [CLI Reference](docs/CLI_REFERENCE.md).

## Configuration

All configuration is done through environment variables and volume mounts in `docker-compose.yml`. The file is heavily commented — open it and uncomment what you need.

### Container & Volume Naming

The `NODE_NAME` variable controls the container name and all volume names (default: `eiou-node`). Change it to run multiple independent nodes or to customize naming:

Create a `.env` file next to `docker-compose.yml` with the following content:

```
NODE_NAME=my-wallet
```

This creates container `my-wallet` with volumes `my-wallet-mysql-data`, `my-wallet-files`, `my-wallet-backups`.

### Environment Variables

#### Node Identity

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `QUICKSTART` | No | *(none)* | Node hostname for HTTP/HTTPS mode. Sets the address, display name, and SSL certificate CN. The node is reachable at `http://<value>` within Docker. If omitted, the node runs in **Tor-only mode** (reachable only via its .onion address) |
| `EIOU_NAME` | No | `QUICKSTART` | Display name shown in the local GUI header and logs. Cosmetic only — never sent to other nodes |
| `EIOU_HOST` | No | `QUICKSTART` | Externally reachable address (IP or domain). Use when the public address differs from the container hostname |
| `EIOU_PORT` | No | *(none)* | Port appended to URLs. Use when mapping to a non-standard external port (e.g., `8443`) |

**Example — production node with public IP:**

```yaml
environment:
  - QUICKSTART=mynode
  - EIOU_NAME=My EIOU Node
  - EIOU_HOST=88.99.69.172
  - EIOU_PORT=8443
```

This generates addresses `http://88.99.69.172:8443` and `https://88.99.69.172:8443`, with "My EIOU Node" as the local display name.

**Priority:** Address: `EIOU_HOST` > `QUICKSTART` | Name: `EIOU_NAME` > `QUICKSTART` | SSL CN: `SSL_DOMAIN` > `EIOU_HOST` > `QUICKSTART`

#### Wallet Restoration

To restore an existing wallet from a BIP39 24-word seed phrase, use one of these methods. If neither is set, a fresh wallet is generated on first boot.

| Variable | Description |
|----------|-------------|
| `RESTORE_FILE` | Path inside the container to a file containing the seed phrase. **Recommended** — the seed is not exposed in process listings or `docker inspect`. Requires a corresponding volume mount (see below) |
| `RESTORE` | The seed phrase directly as an environment variable. Less secure — visible in logs and `docker inspect`. Value **must be quoted** (contains spaces). Cleared by the container after restoration |

**Example — file-based restoration (recommended):**

```yaml
environment:
  - QUICKSTART=mynode
  - RESTORE_FILE=/restore/seed
volumes:
  - /secure/path/seed.txt:/restore/seed:ro
```

Create the seed file: `echo "word1 word2 ... word24" > seed.txt && chmod 600 seed.txt`

After successful restoration, remove the volume mount and restart. Don't leave seed files mounted permanently.

#### SSL Certificates

The container auto-generates a self-signed certificate by default. Override with trusted certificates using these variables.

| Variable | Default | Description |
|----------|---------|-------------|
| `SSL_DOMAIN` | `EIOU_HOST` or `QUICKSTART` | Override the certificate Common Name (CN) |
| `SSL_EXTRA_SANS` | *(none)* | Additional Subject Alternative Names. Format: `DNS:alt.local,IP:10.0.0.1` |
| `LETSENCRYPT_EMAIL` | *(none)* | **Setting this enables Let's Encrypt.** Email for registration and expiry warnings. Requires a real FQDN and port 80 reachable from the internet |
| `LETSENCRYPT_DOMAIN` | `SSL_DOMAIN` | Domain for the Let's Encrypt certificate |
| `LETSENCRYPT_STAGING` | `false` | Use the staging server for testing (avoids rate limits, certs won't be browser-trusted) |
| `P2P_SSL_VERIFY` | `true` | Verify SSL certificates on outbound P2P HTTPS connections. Set to `false` when all nodes use self-signed certs |
| `P2P_CA_CERT` | *(none)* | Path to a CA certificate for P2P verification. Use with the `/ssl-ca` volume mount |

**Certificate selection priority:**
1. External certs mounted at `/ssl-certs` (server.crt, server.key)
2. Let's Encrypt (when `LETSENCRYPT_EMAIL` is set)
3. CA-signed (when `/ssl-ca/ca.crt` is mounted)
4. Self-signed (automatic)

See [docs/DOCKER_CONFIGURATION.md](docs/DOCKER_CONFIGURATION.md#ssl-certificate-configuration) for multi-node SSL setups, wildcard certificates, and browser CA trust installation.

#### Timeouts

Increase these for WSL2 or resource-constrained environments.

| Variable | Default | Description |
|----------|---------|-------------|
| `EIOU_HS_TIMEOUT` | `60` | Seconds to wait for Tor hidden service |
| `EIOU_TOR_TIMEOUT` | `120` | Seconds to wait for Tor network connectivity |

#### Feature Flags

| Variable | Default | Description |
|----------|---------|-------------|
| `EIOU_TEST_MODE` | `false` | Enable manual message processing (`eiou in`/`eiou out`) and bypass rate limiting. **Development only** |
| `EIOU_CONTACT_STATUS_ENABLED` | `true` | Background processor that pings contacts for online/offline status. Disable to reduce network traffic |
| `EIOU_BACKUP_AUTO_ENABLED` | `true` | Automatic encrypted database backups at midnight. Keeps 3 most recent |
| `EIOU_AUTO_CHAIN_DROP_PROPOSE` | `true` | Auto-propose chain drops when mutual gaps are detected that sync cannot repair |
| `EIOU_AUTO_CHAIN_DROP_ACCEPT` | `false` | Auto-accept chain drop proposals (with balance guard). Default is off — proposals require manual review |

### Volume Mounts

#### Required Volumes (enabled by default)

These named volumes persist your data across container restarts and rebuilds. Volume names are derived from `NODE_NAME` (default: `eiou-node`). **Back up regularly.**

| Volume | Container Path | Contents | Backup Priority |
|--------|----------------|----------|-----------------|
| `{NODE_NAME}-mysql-data` | `/var/lib/mysql` | Transaction history, contacts, balances | **Critical** |
| `{NODE_NAME}-files` | `/etc/eiou/` | Wallet private keys, encryption keys, configuration | **Critical** |
| `{NODE_NAME}-backups` | `/var/lib/eiou/backups` | Encrypted database backups (AES-256-GCM) | **Critical** |

To completely reset and start fresh: `docker compose down -v`

#### Optional Volumes (commented out in docker-compose.yml)

Uncomment these in `docker-compose.yml` as needed:

| Volume | Container Path | When to Use |
|--------|----------------|-------------|
| `{NODE_NAME}-letsencrypt` | `/etc/letsencrypt` | With `LETSENCRYPT_EMAIL` — persists certificates across restarts |
| `./my-certs` | `/ssl-certs:ro` | External SSL certificates (server.crt, server.key, optional ca-chain.crt) |
| `./ssl-ca` | `/ssl-ca:ro` | Local CA for development. Generate with `./scripts/create-ssl-ca.sh ./ssl-ca` |
| `/path/to/seed.txt` | `/restore/seed:ro` | **Temporary** — wallet restoration only. Use with `RESTORE_FILE=/restore/seed`. Remove this mount and delete the seed file from disk after successful restoration. Never leave seed phrases on persistent storage |

### Resource Limits

The default configuration allocates:

| Resource | Limit | Reservation |
|----------|-------|-------------|
| CPU | 1.0 core | — |
| Memory | 512 MB | 256 MB |

Adjust in the `deploy.resources` section of `docker-compose.yml` if needed.

## Backups

### Automatic Backups

Enabled by default (`EIOU_BACKUP_AUTO_ENABLED=true`). Runs at midnight, encrypts with AES-256-GCM, retains 3 most recent backups.

### Manual Backup Commands

```bash
docker exec eiou-node eiou backup create                              # Create backup now
docker exec eiou-node eiou backup list                                # List available backups
docker exec eiou-node eiou backup restore <backup-file> --confirm     # Restore from backup
docker exec eiou-node eiou backup cleanup                             # Delete old backups (keeps 3)
```

### Volume-Level Backup

For complete disaster recovery, back up Docker volumes directly:

```bash
# Backup (replace eiou-node with your NODE_NAME if changed)
docker run --rm -v eiou-node-mysql-data:/data -v $(pwd):/backup alpine tar czf /backup/mysql-data.tar.gz -C /data .
docker run --rm -v eiou-node-files:/data -v $(pwd):/backup alpine tar czf /backup/files.tar.gz -C /data .
docker run --rm -v eiou-node-backups:/data -v $(pwd):/backup alpine tar czf /backup/backups.tar.gz -C /data .

# Restore
docker run --rm -v eiou-node-mysql-data:/data -v $(pwd):/backup alpine sh -c "cd /data && tar xzf /backup/mysql-data.tar.gz"
docker run --rm -v eiou-node-files:/data -v $(pwd):/backup alpine sh -c "cd /data && tar xzf /backup/files.tar.gz"
docker run --rm -v eiou-node-backups:/data -v $(pwd):/backup alpine sh -c "cd /data && tar xzf /backup/backups.tar.gz"
```

## Troubleshooting

### Container won't start

```bash
docker compose logs                      # Check startup logs
docker compose down -v && docker compose up -d --build   # Fresh start
```

### Container stuck at "Waiting for MariaDB"

Normal on first boot — MariaDB initialization takes up to 2 minutes. If it persists, ensure at least 512MB memory is available.

### SSL certificate warnings in browser

Expected with the default self-signed certificate. Options:
1. Accept the browser warning for quick testing
2. Use Let's Encrypt for trusted certs (`LETSENCRYPT_EMAIL`)
3. Create a local CA: `./scripts/create-ssl-ca.sh ./ssl-ca`

### P2P HTTPS fails between nodes (self-signed certs)

Set `P2P_SSL_VERIFY=false` or use a shared CA. See [SSL troubleshooting](docs/DOCKER_CONFIGURATION.md#p2p-https-fails-between-docker-nodes-self-signed-certificates).

### Slow startup on WSL2

Increase timeouts:
```yaml
environment:
  - EIOU_HS_TIMEOUT=120
  - EIOU_TOR_TIMEOUT=240
```

### Log locations inside the container

| Log | Path |
|-----|------|
| Apache access | `/var/log/apache2/access.log` |
| Apache errors | `/var/log/apache2/error.log` |
| PHP errors | `/var/log/php_errors.log` |
| Tor | `/var/log/tor/log` |

```bash
docker exec eiou-node tail -f /var/log/apache2/error.log
docker exec eiou-node tail -f /var/log/php_errors.log
```

## Multi-Node Testing

The old multi-node compose files (4-line, 10-line, 13-node cluster) are archived in [`tests/old/compose-files/`](tests/old/compose-files/) along with demo topologies in [`tests/old/demo/`](tests/old/demo/) for HTTP, HTTPS, and Tor setups.

To run multiple nodes, duplicate the service block in `docker-compose.yml` with unique container names, ports, and volume names. See [docs/DOCKER_CONFIGURATION.md](docs/DOCKER_CONFIGURATION.md#port-mappings) for port mapping conventions.

## Testing

```bash
# Unit tests (PHPUnit)
cd files && composer test

# Integration tests
cd tests && ./run-all-tests.sh http4
```

See [Testing Guide](docs/TESTING.md) for details.

## Documentation

| Document | Description |
|----------|-------------|
| [Docker Configuration](docs/DOCKER_CONFIGURATION.md) | Full environment variable, volume, SSL, and network reference |
| [Architecture](docs/ARCHITECTURE.md) | System architecture and design |
| [Upgrade Guide](docs/UPGRADE_GUIDE.md) | How to update your node while preserving data |
| [API Reference](docs/API_REFERENCE.md) | REST API documentation |
| [API Quick Reference](docs/API_QUICK_REFERENCE.md) | API endpoint summary |
| [GUI Reference](docs/GUI_REFERENCE.md) | Web interface documentation |
| [GUI Quick Reference](docs/GUI_QUICK_REFERENCE.md) | GUI feature summary |
| [CLI Reference](docs/CLI_REFERENCE.md) | Command-line interface documentation |
| [CLI Demo Guide](docs/CLI_DEMO_GUIDE.md) | Step-by-step CLI command walkthrough |
| [Error Codes](docs/ERROR_CODES.md) | Error codes and troubleshooting |
| [Testing Guide](docs/TESTING.md) | Unit and integration testing documentation |
| [Error Handling Policy](docs/ERROR_HANDLING_POLICY.md) | Error handling standards |
