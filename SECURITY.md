# Security Policy

Security policy and guidelines for the eIOU Docker project.

## Table of Contents

1. [Project Status](#project-status)
2. [Supported Versions](#supported-versions)
3. [Reporting a Vulnerability](#reporting-a-vulnerability)
4. [Security Best Practices for Users](#security-best-practices-for-users)
5. [Security Architecture Overview](#security-architecture-overview)
6. [Known Limitations](#known-limitations)

---

## Project Status

eIOU Docker is currently in **ALPHA** status.

**WARNING: Do NOT use this software for real financial transactions.** The codebase has not undergone a formal third-party security audit. While security measures are implemented throughout the system, alpha software may contain undiscovered vulnerabilities. Use this software for development, testing, and evaluation purposes only.

---

## Supported Versions

eIOU Docker follows a rolling-release model during the alpha phase. Security patches are applied to the latest version on the `main` branch only. There is no backporting of fixes to older commits or tags during alpha.

| Version | Status | Security Updates |
|---------|--------|------------------|
| `main` (latest) | Alpha | Yes |
| Older commits | Alpha | No |

Once eIOU Docker reaches a stable release, a formal versioning and support policy will be published.

---

## Reporting a Vulnerability

We take security vulnerabilities seriously, even during the alpha phase. If you discover a security issue, please follow the responsible disclosure process below.

### Where to Report

| Method | Details |
|--------|---------|
| Email | [dockersecurity@eiou.org](mailto:dockersecurity@eiou.org) |

**Do NOT** file security vulnerabilities as public GitHub issues. Use the private channels listed above.

### What to Include

When reporting a vulnerability, include as much of the following as possible:

| Field | Description |
|-------|-------------|
| Summary | Brief description of the vulnerability |
| Affected component | File path, service name, or endpoint affected |
| Severity estimate | Your assessment of impact (critical, high, medium, low) |
| Reproduction steps | Step-by-step instructions to reproduce the issue |
| Proof of concept | Code, screenshots, or logs demonstrating the vulnerability |
| Impact assessment | What an attacker could achieve by exploiting this |
| Suggested fix | If you have a recommendation for remediation |
| Environment | Docker version, OS, compose file used, relevant configuration |

### Response Timeline

| Stage | Target |
|-------|--------|
| Acknowledgment | Within 48 hours of report |
| Initial assessment | Within 7 days |
| Status update | At least every 14 days until resolved |
| Fix or mitigation | Dependent on severity and complexity |

Critical vulnerabilities affecting seed phrase or private key exposure will be prioritized above all other work.

### Responsible Disclosure

We ask that reporters:

1. Allow us reasonable time to investigate and address the issue before public disclosure
2. Test only against a local node topology that is not connected to the live network (e.g., `docker-compose-4line.yml` on an isolated Docker bridge network)
3. Do not interact with other users' nodes through the network as part of vulnerability testing
4. Do not exploit the vulnerability beyond what is necessary to demonstrate the issue

We commit to:

1. Acknowledging all reports within the timeline above
2. Crediting reporters (unless anonymity is requested) in the fix announcement
3. Not pursuing legal action against researchers who follow this disclosure policy
4. Publishing a security advisory on GitHub once a fix is available

---

## Security Best Practices for Users

### Seed Phrase Handling

The BIP39 24-word seed phrase is the most sensitive piece of data in the system. All cryptographic keys, the Tor hidden service address, and the backup encryption key are derived from it. Anyone with access to the seed phrase has full control of the wallet.

| Practice | Recommendation |
|----------|----------------|
| Restoration method | Use `RESTORE_FILE`, not the `RESTORE` environment variable |
| File permissions | Set seed files to `chmod 600` (owner read/write only) |
| Volume mount | Mount seed files as read-only (`:ro`) |
| Post-restore | Remove the seed file mount after successful restoration |
| Storage | Store seed phrases offline, never in version control or cloud storage |
| Process visibility | The `RESTORE` env var exposes the seed in process listings and Docker inspect output |

**Recommended restoration workflow:**

```bash
# 1. Write seed phrase to a temporary file with restrictive permissions
echo "word1 word2 ... word24" > /secure/location/seed.txt
chmod 600 /secure/location/seed.txt

# 2. Start the container with file-based restore
docker-compose up -d  # with RESTORE_FILE=/restore/seed and volume mount

# 3. After successful restoration, remove the seed file
shred -u /secure/location/seed.txt

# 4. Remove the RESTORE_FILE mount from docker-compose.yml and restart
```

The container itself follows secure seed handling internally: temporary copies are written to `/dev/shm/` (RAM-backed filesystem) with `chmod 600` permissions and are securely deleted with `shred -u` after use. The `RESTORE` environment variable is cleared from the container environment after restoration completes.

### Volume Security

eIOU containers persist critical data in Docker volumes. Loss of these volumes means loss of wallet access and transaction history.

| Volume | Contains | Backup Priority |
|--------|----------|-----------------|
| `{node}-mysql-data` | Transactions, contacts, balances | Critical |
| `{node}-files` | Wallet private keys, encryption keys, configuration | Critical |
| `{node}-backups` | AES-256-GCM encrypted database backups | Critical |

**Recommendations:**

- Back up all three critical volumes regularly using the automated backup system or manual volume backup procedures documented in [DOCKER_CONFIGURATION.md](docs/DOCKER_CONFIGURATION.md)
- Store volume backups on separate physical media or secure remote storage
- Test backup restoration periodically to verify integrity
- Use Docker named volumes (default) rather than bind mounts for production data to benefit from Docker's volume management

### Network Security

| Layer | Configuration | Notes |
|-------|---------------|-------|
| E2E encryption | All contact messages encrypted (ECDH + AES-256-GCM) | Forward secrecy, type-indistinguishable on wire |
| Tor | Enabled by default | Provides IP anonymization via onion routing |
| Tor circuit health | Per-address failure tracking with cooldown | Prevents wasted retries; configurable fallback to HTTPS (HTTP excluded by default via `torFallbackRequireEncrypted`) |
| TLS | TLS 1.2+ with auto-generated or custom certificates | Self-signed by default; use Let's Encrypt or mount external certs for production |
| Transport priority | Tor > HTTPS > HTTP | System prefers the most secure transport available |
| Transport fallback | Automatic on Tor failure | Falls back to HTTPS when `torFailureTransportFallback=true` (default). `torFallbackRequireEncrypted=true` (default) restricts fallback to HTTPS only |
| Connection-level rate limiting | nginx `limit_req_zone` per-IP: 30r/s general, 10r/s API, 20r/s P2P | Enforced before PHP executes — drops floods at the web server level |
| Connection limits | nginx `limit_conn` 50 concurrent connections per IP | Prevents single-IP resource exhaustion |
| Connection timeouts | 10s header/body timeout | Drops slow/incomplete connections before they consume PHP-FPM workers |
| Internal network | Docker bridge network | Containers communicate over an isolated Docker network |

**Recommendations:**

- Use HTTPS or Tor for all inter-node communication in non-testing environments
- Use Let's Encrypt certificates for production deployments, or mount your own CA-signed certificates (see [DOCKER_CONFIGURATION.md](docs/DOCKER_CONFIGURATION.md) SSL section)
- If running behind an external reverse proxy, bind ports to localhost only (`127.0.0.1:80:80`) in `docker-compose.yml`. The built-in nginx already provides rate limiting, connection limits, and timeouts for direct exposure
- The HTTP transport mode is intended for local Docker network testing only
- The `torFailureTransportFallback` setting can be disabled for Tor-only operation where privacy is paramount
- The `torFallbackRequireEncrypted` setting (default: enabled) ensures Tor fallback only goes to HTTPS, never plain HTTP. Disable only if you explicitly need HTTP fallback in a trusted local network

### Running Behind an External Reverse Proxy

The built-in nginx provides connection-level rate limiting, connection limits, and timeouts — so the container is safe to expose directly. However, if you already run a reverse proxy (Traefik, Caddy, nginx, HAProxy) for centralized SSL management, domain routing, or multiple services on the same host, you can place the eIOU container behind it.

**Step 1: Restrict container ports to localhost**

In `docker-compose.yml`, change the port binding so only the reverse proxy (running on the same host) can reach the container:

```yaml
ports:
  - "127.0.0.1:80:80"
  - "127.0.0.1:443:443"
```

With `docker run`:

```bash
docker run -p 127.0.0.1:80:80 -p 127.0.0.1:443:443 ...
```

This prevents direct access from the internet — all traffic must go through the reverse proxy.

**Step 2: Configure the external reverse proxy**

Point the proxy at `127.0.0.1:443` (HTTPS) or `127.0.0.1:80` (HTTP). Example for nginx as an external proxy:

```nginx
server {
    listen 443 ssl;
    server_name wallet.example.com;

    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    location / {
        proxy_pass https://127.0.0.1:443;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**Step 3: If the proxy terminates SSL, use HTTP internally**

If your reverse proxy handles SSL and forwards plain HTTP to the container, point it at port 80 instead. The container's built-in nginx serves content on both ports. Note that `.onion` requests and `/eiou` P2P requests are served directly on port 80 without redirect, so HTTP forwarding works without issues.

**What the two layers handle:**

| Layer | Responsibility |
|-------|----------------|
| External proxy | Domain routing, centralized SSL for multiple services, load balancing, IP allowlisting |
| Built-in nginx | Application-specific rate limiting (30r/s general, 10r/s API, 20r/s P2P), per-IP connection limits (50), timeouts (10s), PHP-FPM routing, static file serving |

The two layers complement each other. The external proxy handles infrastructure concerns; the built-in nginx handles application-level protections. Both rate limiters operate independently — the external proxy may enforce its own limits before traffic reaches the container.

### Container Security

| Setting | Default | Purpose |
|---------|---------|---------|
| CPU limit | 1.0 core | Prevents resource exhaustion |
| Memory limit | 512MB | Prevents unbounded memory consumption |
| Memory reservation | 256MB | Guarantees minimum available memory |
| Privilege dropping | nginx workers as `www-data`, PHP-FPM workers as `www-data`, MariaDB as `mysql`, Tor as `debian-tor`, PHP processors as `www-data` | Limits blast radius of service compromise |

**Recommendations:**

- Do not run containers with `--privileged` flag
- Do not override the default resource limits unless necessary for your environment
- Review exposed ports and limit them to what is required for your topology
- Use Docker Compose v2 to enforce resource limits (v1 ignores the `deploy` section)

### Base Image Integrity

The Dockerfile pins the base image (`debian:12-slim`) to a SHA256 digest rather than relying on the mutable tag alone. This prevents supply chain attacks where a compromised tag republish silently replaces the image content.

**Verifying the digest:**

1. Using Docker:

```bash
docker pull debian:12-slim
docker images --digests debian
# Compare the DIGEST column against the value in eiou.dockerfile
```

2. Using the Docker Hub registry API (no Docker required):

```bash
TOKEN=$(curl -s "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/debian:pull" \
  | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
curl -sI -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/vnd.docker.distribution.manifest.list.v2+json" \
  "https://registry-1.docker.io/v2/library/debian/manifests/12-slim" \
  | grep -i docker-content-digest
```

3. Using the helper script:

```bash
./scripts/check-base-image.sh
```

**Updating the digest:**

When a new upstream image is published, update the `FROM` line in `eiou.dockerfile`:

```bash
docker pull debian:12-slim
docker inspect --format='{{index .RepoDigests 0}}' debian:12-slim
# Replace the digest in the FROM line
```

CI monitors the digest monthly and opens a GitHub issue when it becomes stale.

### Environment Variable Hygiene

| Variable | Risk | Mitigation |
|----------|------|------------|
| `RESTORE` | Seed phrase visible in process listings, Docker inspect, and logs | Use `RESTORE_FILE` instead |
| `EIOU_TEST_MODE` | Disables rate limiting | Never enable in production |
| API secrets | Visible in environment if set as env vars | Use the API key management CLI commands |

**Recommendations:**

- Audit your `docker-compose.yml` files before committing to version control
- Never commit files containing seed phrases, API secrets, or private keys
- Use Docker secrets or a secrets management tool for production deployments
- Review `docker inspect <container>` output to verify no sensitive data is exposed in the container configuration

---

## Security Architecture Overview

eIOU Docker implements security at multiple layers. This section provides a brief summary. For detailed technical documentation, see [Architecture - Security Model](docs/ARCHITECTURE.md#security-model).

### Cryptographic Foundations

| Component | Algorithm | Purpose |
|-----------|-----------|---------|
| Wallet seed | BIP39 (24 words) | Deterministic key derivation — all keys, Tor identity, and master encryption key derived from seed |
| Signing keys | secp256k1 (ECDSA) | Message signing and identity verification |
| Tor identity | Ed25519 | Hidden service authentication (derived from secp256k1 keys) |
| Master encryption key | HKDF-SHA256 from BIP39 seed | Deterministic master key for at-rest encryption. Recoverable via seed phrase restore |
| Private key storage | AES-256-GCM | Encryption at rest for private keys, auth codes, and mnemonics |
| Backup encryption | AES-256-GCM | Encrypted MariaDB database backups |
| Payload E2E encryption | ECDH + AES-256-GCM | End-to-end encryption for **all** contact message payloads. Uses ephemeral key pairs for forward secrecy. Encrypt-then-sign design allows signature verification without decryption |
| API authentication | HMAC-SHA256 | Request signing with 5-minute timestamp window |

### End-to-End Encryption

All messages sent to known contacts are encrypted using ephemeral ECDH key agreement + AES-256-GCM. This includes P2P requests, RP2P responses, relay transactions, pings, route cancellations, and text messages. Every content field — including the message `type` — is inside the encrypted block, making all message types indistinguishable on the wire.

| Property | Detail |
|----------|--------|
| Cipher | AES-256-GCM with HKDF-SHA256 key derivation |
| Key agreement | Ephemeral ECDH (same curve as recipient's signing key) |
| Forward secrecy | New ephemeral key pair per message |
| Design | Encrypt-then-sign: signature covers ciphertext, verification does not require decryption |
| Excluded | `create` (contact requests) — recipient's public key may not be known yet |
| Fallback | Graceful cleartext when recipient public key is unavailable (e.g., transaction inquiry to P2P end-recipient) |

**Sync compatibility:** The signature covers the encrypted content. The raw signed JSON is stored in the `signed_message_content` column so chain sync recovery can verify signatures without re-encrypting.

### Tor Circuit Health Tracking

Per-`.onion` address failure tracking with automatic cooldown prevents wasted retries and Tor circuit overload when a hidden service is temporarily unreachable.

| Setting | Default | Description |
|---------|---------|-------------|
| `torCircuitMaxFailures` | `2` | Consecutive failures before cooldown |
| `torCircuitCooldownSeconds` | `300` (5 min) | Duration to skip the address |
| `torFailureTransportFallback` | `true` | Fall back to HTTP/HTTPS when Tor fails |
| `torFallbackRequireEncrypted` | `true` | Restrict Tor fallback to HTTPS only (never plain HTTP). Preserves transport encryption when Tor is unavailable |

State is file-based in `/tmp/tor-circuit-health/` (clears on container restart). Configurable via CLI (`eiou changesettings`), GUI settings panel, and REST API.

**Privacy note:** When `torFallbackRequireEncrypted` is enabled (default), a Tor delivery failure will only fall back to HTTPS. If the contact has no HTTPS address, delivery fails gracefully rather than downgrading to unencrypted HTTP. This prevents accidental privacy leaks when Tor is the preferred transport.

### Application Security

| Protection | Implementation |
|------------|----------------|
| XSS prevention | `htmlEncode`, `jsEncode`, JSON encoding flags on all output |
| CSRF protection | Tokens validated on all POST request handlers |
| SQL injection | Column whitelist validation, parameterized queries via PDO prepared statements |
| Input validation | 18+ validation methods in `InputValidator` class |
| Rate limiting | Per-key limits (default: 100 requests/minute) via `RateLimiterService` |
| Secure logging | Automatic masking of passwords, auth codes, API keys, and mnemonics in log output |

### Transport Security

| Layer | Protection |
|-------|------------|
| E2E encryption | ECDH + AES-256-GCM for all contact message payloads (type-indistinguishable on wire) |
| HTTPS | TLS 1.2+ with configurable certificate sources (Let's Encrypt, CA-signed, or self-signed) |
| Tor | Onion routing for network-level anonymity with circuit health tracking and transport fallback |
| Message signing | All P2P messages signed with secp256k1 ECDSA signatures |
| Replay prevention | API timestamps validated within a 5-minute window |
| Hop budget randomization | Geometric distribution prevents traffic analysis of routing depth |

---

## Known Limitations

The following are known security limitations of the current alpha release.

| Limitation | Details |
|------------|---------|
| No formal audit | The codebase has not undergone a third-party security audit |
| Alpha status | Software may contain undiscovered vulnerabilities; do not use for real financial transactions |
| Self-signed certificates by default | Default TLS configuration uses self-signed certificates, which do not provide server identity verification. Let's Encrypt support is available for browser-trusted certificates (see [DOCKER_CONFIGURATION.md](docs/DOCKER_CONFIGURATION.md) SSL section) |
| Single-container architecture | Each node runs all services (web server, database, Tor, processors) in a single container; while each service drops to its own user, there is no inter-service network isolation |
| No HSM support | Private keys are stored encrypted on disk rather than in a hardware security module |
| No multi-factor authentication | API access relies on HMAC-SHA256 key-based authentication without a second factor |
| Seed phrase is the single root of trust | Compromise of the 24-word seed phrase grants full control over the wallet, Tor identity, and backup decryption |
| Contact request messages are not E2E encrypted | Initial contact requests (`type: create`) cannot be E2E encrypted because the recipient's public key is not yet known. The optional message/description included with a contact request is protected only by transport-level encryption (Tor or HTTPS). Avoid including sensitive information in contact request messages when using plain HTTP transport |

These limitations will be addressed as the project matures toward a production release.

---

## See Also

- [Architecture - Security Model](docs/ARCHITECTURE.md#security-model) - Detailed security architecture documentation
- [Docker Configuration - Security](docs/DOCKER_CONFIGURATION.md#security-configuration) - Container security settings
- [Docker Configuration - SSL](docs/DOCKER_CONFIGURATION.md#ssl-certificate-configuration) - SSL certificate configuration including Let's Encrypt
- [Docker Configuration - Wallet Restoration](docs/DOCKER_CONFIGURATION.md#wallet-restoration) - Secure seed phrase handling
- [API Reference](docs/API_REFERENCE.md) - API authentication documentation
- [Error Handling Policy](docs/ERROR_HANDLING_POLICY.md) - Error handling and logging standards
