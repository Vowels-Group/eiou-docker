> **OPEN ALPHA**
>
> eIOU is a decentralized peer-to-peer credit network. There is no central server, no central authority, and no central point of failure. Each node is independently operated and stores its own data. This software is in open alpha and under active development. Features and behavior may change without prior notice.
>
> IOUs are bilateral agreements between real people and may not be reversible once accepted. Debt can be denominated in any unit of account (fiat, commodities, time, or custom units). eIOU is not a cryptocurrency, token, or digital asset. No coins are mined, minted, or generated.
>
> eIOU believes that tracking interpersonal debts is not subject to Money Services Business or money transmitter regulations, as no funds are transmitted, held, or custodied. However, regulations vary by jurisdiction, and routing IOUs through intermediary nodes may be classified differently under your local laws. This software is provided as open source under the First Amendment right to publish software (*Bernstein v. DOJ*, 176 F.3d 1132, 9th Cir. 1999; *Junger v. Daley*, 209 F.3d 481, 6th Cir. 2000).
>
> You are responsible for securing your node and keys, maintaining backups, understanding the obligations you create, and complying with all applicable laws. Do not use this for obligations you cannot afford to lose. This is not legal advice. Consult an attorney in your jurisdiction.

# eIOU Docker

Run an eIOU node with a single `docker compose` command. The container includes everything needed: nginx, PHP-FPM, MariaDB, Tor, PHP processors, and the web GUI.

## Key Features

- **Web GUI Dashboard** — manage contacts, transactions, and node settings from your browser
- **P2P Transactions** — automatic multi-hop payment routing through trust networks with best-fee selection and cascade cancel
- **End-to-End Encryption** — all contact messages encrypted with ECDH + AES-256-GCM (ephemeral keys, forward secrecy). All message types are indistinguishable on the wire
- **Multi-Transport** — HTTP, HTTPS, and Tor (.onion) with automatic failover and Tor circuit health tracking
- **REST API** — full API with HMAC-SHA256 authentication
- **CLI Interface** — complete command-line management via `eiou` commands
- **Data-at-Rest Encryption** — MariaDB Transparent Data Encryption (TDE) encrypts all database files automatically. Optional volume passphrase (`EIOU_VOLUME_KEY_FILE`) encrypts the master key itself so the host cannot read it
- **Encrypted Backups** — automatic daily database backups encrypted with AES-256-GCM
- **Deterministic Key Recovery** — all cryptographic material (wallet keys, Tor identity, encryption master key) derived from BIP39 seed phrase
- **Update Notifications** — daily check for newer Docker image versions (read-only Docker Hub API call, no data sent)
- **Persistent Storage** — named Docker volumes keep your data across restarts and rebuilds

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) (includes Docker Compose)

## Quick Start

The `docker-compose.yml` file contains environment variables that configure the node (name, host address, ports, wallet restoration, etc.). Review the file and adjust as needed before starting. See [Docker Configuration](docs/DOCKER_CONFIGURATION.md) for a full reference of all available options.

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

The container automatically generates a wallet, starts Tor, and initializes all services. With the default `QUICKSTART=eiou` setting, it also configures HTTP/HTTPS addresses and creates a self-signed SSL certificate — suitable for Docker-internal testing between containers on the same network. These addresses are not reachable from outside Docker. For production use (public IP, trusted SSL, custom domain), set `EIOU_HOST` and `EIOU_PORT` and configure proper SSL — see the [Configuration](#configuration) section below. The node is ready once the healthcheck passes (~2 minutes on first boot).

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
docker exec eiou-node eiou search             # List contacts

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

This creates container `my-wallet` with volumes `my-wallet-mysql-data`, `my-wallet-config`, `my-wallet-backups`.

### Environment Variables

#### Node Identity

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `QUICKSTART` | No | *(none)* | Container hostname for HTTP/HTTPS mode. Generates Docker-internal addresses (`http://<value>`) with a self-signed SSL certificate — **not reachable from outside the Docker network**. For external access, also set `EIOU_HOST` and `EIOU_PORT`. If omitted, the node runs in **Tor-only mode** (reachable only via its .onion address) |
| `EIOU_NAME` | No | `QUICKSTART` | Display name shown in the local GUI header and logs. Cosmetic only — never sent to other nodes |
| `EIOU_HOST` | No | `QUICKSTART` | Externally reachable address (IP or domain, with optional `:port`). **Required for access from outside Docker.** Use a real IP or FQDN with proper SSL (Let's Encrypt or CA-signed) for production. If `:port` is included (e.g., `192.168.1.100:8080`), it is used as `EIOU_PORT` unless `EIOU_PORT` is explicitly set |
| `EIOU_PORT` | No | *(none)* | Port appended to URLs. Use when mapping to a non-standard external port (e.g., `8443`). Can also be embedded in `QUICKSTART` or `EIOU_HOST` as `:port` |

**Example — production node with public IP:**

```yaml
environment:
  - QUICKSTART=mynode
  - EIOU_NAME=My eIOU Node
  - EIOU_HOST=88.99.69.172
  - EIOU_PORT=8443
```

This generates addresses `http://88.99.69.172:8443` and `https://88.99.69.172:8443`, with "My eIOU Node" as the local display name.

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
| `P2P_SSL_VERIFY` | `true` | Verify SSL certificates on outbound P2P HTTPS connections. Self-signed certs (from QUICKSTART) are **rejected by default**. Set to `false` for dev/testing, or use `P2P_CA_CERT` with a shared CA |
| `P2P_CA_CERT` | *(none)* | Path to a CA certificate inside the container for P2P verification. Requires a volume mount (e.g., `./ssl-ca:/ssl-ca:ro`). Lets nodes trust each other without disabling verification |

**Certificate selection priority:**
1. External certs mounted at `/ssl-certs` (server.crt, server.key)
2. Let's Encrypt (when `LETSENCRYPT_EMAIL` is set)
3. CA-signed (when `/ssl-ca/ca.crt` is mounted)
4. Self-signed (automatic)

**Alternative:** Instead of managing SSL inside the container, you can use a **reverse proxy** (nginx, Caddy, Traefik) or a **Cloudflare Tunnel** to terminate SSL externally. The container keeps its self-signed cert internally.

See [docs/DOCKER_CONFIGURATION.md](docs/DOCKER_CONFIGURATION.md#ssl-certificate-configuration) for multi-node SSL setups, reverse proxy/tunnel configuration, wildcard certificates, and browser CA trust installation.

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
| `EIOU_AUTO_CHAIN_DROP_PROPOSE` | `true` | Auto-propose tx drops when mutual gaps are detected that sync cannot repair |
| `EIOU_AUTO_CHAIN_DROP_ACCEPT` | `false` | Auto-accept tx drop proposals (with balance guard). Default is off — proposals require manual review |
| `EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD` | `true` | Balance guard for auto-accept: compares stored vs calculated balances before accepting. Set to `false` to accept unconditionally |
| `EIOU_DEFAULT_TRANSPORT_MODE` | `tor` | Default transport when sending to a contact by name. Options: `tor`, `http`, `https` |
| `EIOU_TOR_FORCE_FAST` | `true` | Force fast mode (first response wins) for Tor routes. Set to `false` to allow best-fee mode over Tor |
| `EIOU_HOP_BUDGET_RANDOMIZED` | `true` | Randomize P2P hop budget with geometric distribution. Set to `false` for deterministic routing depth |
| `EIOU_UPDATE_CHECK_ENABLED` | `true` | Check Docker Hub daily for newer image versions. Set to `false` to disable |
| `EIOU_ANALYTICS_ENABLED` | `false` | Opt-in anonymous usage statistics sent weekly. See [Anonymous Analytics](docs/ANONYMOUS_ANALYTICS.md) |
| `EIOU_AUTO_ACCEPT_RESTORED_CONTACT` | `true` | Auto-accept restored contacts when transaction history proves prior relationship |
| `EIOU_VOLUME_KEY_FILE` | *(none)* | Path to file containing volume encryption passphrase. Encrypts the master key at rest so the host cannot read it from the Docker volume |
| `APP_DEBUG` | `true` | Enable debug logging to database (visible in GUI Debug panel). Set to `false` for production |
| `EIOU_P2P_MAX_WORKERS` | *(per-transport)* | Override max concurrent P2P worker processes. Defaults: HTTP=50, HTTPS=50, Tor=5 |

### Volume Mounts

#### Required Volumes (enabled by default)

These named volumes persist your data across container restarts and rebuilds. Volume names are derived from `NODE_NAME` (default: `eiou-node`). **Back up regularly.**

| Volume | Container Path | Contents | Backup Priority |
|--------|----------------|----------|-----------------|
| `{NODE_NAME}-mysql-data` | `/var/lib/mysql` | Transaction history, contacts, balances | **Critical** |
| `{NODE_NAME}-config` | `/etc/eiou/config` | Wallet private keys, encryption keys, configuration | **Critical** |
| `{NODE_NAME}-backups` | `/var/lib/eiou/backups` | Encrypted database backups (AES-256-GCM) | **Critical** |
| `{NODE_NAME}-letsencrypt` | `/etc/letsencrypt` | Let's Encrypt certificates. Safe to comment out if you will never use Let's Encrypt | Low |

To completely reset and start fresh: `docker compose down -v`

#### Optional Volumes (commented out in docker-compose.yml)

Uncomment these in `docker-compose.yml` as needed:

| Volume | Container Path | When to Use |
|--------|----------------|-------------|
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
# Backup (replace <container> with your container name, default: eiou-node)
docker exec <container> tar czf /tmp/mysql-data.tar.gz -C /var/lib/mysql .
docker cp <container>:/tmp/mysql-data.tar.gz ./mysql-data.tar.gz

docker exec <container> tar czf /tmp/config.tar.gz -C /etc/eiou/config .
docker cp <container>:/tmp/config.tar.gz ./config.tar.gz

docker exec <container> tar czf /tmp/backups.tar.gz -C /var/lib/eiou/backups .
docker cp <container>:/tmp/backups.tar.gz ./backups.tar.gz

# Restore
docker cp ./mysql-data.tar.gz <container>:/tmp/mysql-data.tar.gz
docker exec <container> sh -c "cd /var/lib/mysql && tar xzf /tmp/mysql-data.tar.gz && chown -R mysql:mysql /var/lib/mysql"

docker cp ./config.tar.gz <container>:/tmp/config.tar.gz
docker exec <container> sh -c "cd /etc/eiou/config && tar xzf /tmp/config.tar.gz && chown -R www-data:www-data /etc/eiou/config"

docker cp ./backups.tar.gz <container>:/tmp/backups.tar.gz
docker exec <container> sh -c "cd /var/lib/eiou/backups && tar xzf /tmp/backups.tar.gz && chown -R www-data:www-data /var/lib/eiou/backups"
```

## Troubleshooting

### Container won't start

```bash
docker compose logs                      # Check startup logs
docker compose down -v && docker compose up -d --build   # Fresh start
```

### Container stuck at "Waiting for MariaDB"

Normal on first boot — MariaDB initialization takes up to 2 minutes. The startup script has a 60-second timeout with automatic recovery: if MariaDB fails due to a version mismatch after an image rebuild (encrypted redo log incompatibility), it automatically performs a force-recovery and restart. If it still fails, the container exits with a FATAL message instead of looping. Ensure at least 512MB memory is available.

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
| nginx access | `/var/log/nginx/access.log` |
| nginx errors | `/var/log/nginx/error.log` |
| PHP errors | `/var/log/php_errors.log` |
| Tor | `/var/log/tor/log` |

```bash
docker exec eiou-node tail -f /var/log/nginx/error.log
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
| [Currency Configuration](docs/CURRENCY_CONFIGURATION.md) | Adding currencies, conversion factors, and decimal places |
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
| [Anonymous Analytics](docs/ANONYMOUS_ANALYTICS.md) | What data is sent, privacy guarantees, how to toggle |
| [Security Policy](SECURITY.md) | Security architecture, vulnerability reporting, and best practices |

## Legal Notice

See the full notice displayed at container startup (`scripts/banners/alpha-warning.txt`) or the blockquote at the top of this README.

Patent pending.
