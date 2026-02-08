# Security Policy

Security policy and guidelines for the EIOU Docker project.

## Table of Contents

1. [Project Status](#project-status)
2. [Supported Versions](#supported-versions)
3. [Reporting a Vulnerability](#reporting-a-vulnerability)
4. [Security Best Practices for Users](#security-best-practices-for-users)
5. [Security Architecture Overview](#security-architecture-overview)
6. [Known Limitations](#known-limitations)

---

## Project Status

EIOU Docker is currently in **ALPHA** status.

**WARNING: Do NOT use this software for real financial transactions.** The codebase has not undergone a formal third-party security audit. While security measures are implemented throughout the system, alpha software may contain undiscovered vulnerabilities. Use this software for development, testing, and evaluation purposes only.

---

## Supported Versions

EIOU Docker follows a rolling-release model during the alpha phase. Security patches are applied to the latest version on the `main` branch only. There is no backporting of fixes to older commits or tags during alpha.

| Version | Status | Security Updates |
|---------|--------|------------------|
| `main` (latest) | Alpha | Yes |
| Older commits | Alpha | No |

Once EIOU Docker reaches a stable release, a formal versioning and support policy will be published.

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

EIOU containers persist critical data in Docker volumes. Loss of these volumes means loss of wallet access and transaction history.

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
| Tor | Enabled by default | Provides IP anonymization via onion routing |
| TLS | TLS 1.2+ with auto-generated or custom certificates | Self-signed by default; mount external certs for production |
| Transport priority | Tor > HTTPS > HTTP | System prefers the most secure transport available |
| Internal network | Docker bridge network | Containers communicate over an isolated Docker network |

**Recommendations:**

- Use HTTPS or Tor for all inter-node communication in non-testing environments
- Mount proper CA-signed SSL certificates for production deployments (see [DOCKER_CONFIGURATION.md](docs/DOCKER_CONFIGURATION.md) SSL section)
- Avoid exposing container ports directly to the public internet without a reverse proxy or firewall
- The HTTP transport mode is intended for local Docker network testing only

### Container Security

| Setting | Default | Purpose |
|---------|---------|---------|
| CPU limit | 1.0 core | Prevents resource exhaustion |
| Memory limit | 512MB | Prevents unbounded memory consumption |
| Memory reservation | 256MB | Guarantees minimum available memory |
| Privilege dropping | Apache as `www-data`, MariaDB as `mysql`, Tor as `debian-tor` | Limits blast radius of service compromise |

**Recommendations:**

- Do not run containers with `--privileged` flag
- Do not override the default resource limits unless necessary for your environment
- Review exposed ports and limit them to what is required for your topology
- Use Docker Compose v2 to enforce resource limits (v1 ignores the `deploy` section)

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

EIOU Docker implements security at multiple layers. This section provides a brief summary. For detailed technical documentation, see [Architecture - Security Model](docs/ARCHITECTURE.md#security-model).

### Cryptographic Foundations

| Component | Algorithm | Purpose |
|-----------|-----------|---------|
| Wallet seed | BIP39 (24 words) | Deterministic key derivation |
| Signing keys | secp256k1 (ECDSA) | Message signing and identity verification |
| Tor identity | Ed25519 | Hidden service authentication |
| Private key storage | AES-256-GCM | Encryption at rest for private keys and auth codes |
| Backup encryption | AES-256-GCM | Encrypted MariaDB database backups |
| API authentication | HMAC-SHA256 | Request signing with 5-minute timestamp window |

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
| HTTPS | TLS 1.2+ with configurable certificate sources |
| Tor | Onion routing for network-level anonymity |
| Message signing | All P2P messages signed with secp256k1 ECDSA signatures |
| Replay prevention | API timestamps validated within a 5-minute window |

---

## Known Limitations

The following are known security limitations of the current alpha release.

| Limitation | Details |
|------------|---------|
| No formal audit | The codebase has not undergone a third-party security audit |
| Alpha status | Software may contain undiscovered vulnerabilities; do not use for real financial transactions |
| PHP processors run as root | Background message processors (P2P, Transaction, Cleanup, ContactStatus) run as the root user inside the container |
| Self-signed certificates by default | Default TLS configuration uses self-signed certificates, which do not provide server identity verification |
| Single-container architecture | Each node runs all services (web server, database, Tor, processors) in a single container, limiting isolation between components |
| No HSM support | Private keys are stored encrypted on disk rather than in a hardware security module |
| No multi-factor authentication | API access relies on HMAC-SHA256 key-based authentication without a second factor |
| Seed phrase is the single root of trust | Compromise of the 24-word seed phrase grants full control over the wallet, Tor identity, and backup decryption |

These limitations will be addressed as the project matures toward a production release.

---

## See Also

- [Architecture - Security Model](docs/ARCHITECTURE.md#security-model) - Detailed security architecture documentation
- [Docker Configuration - Security](docs/DOCKER_CONFIGURATION.md#security-configuration) - Container security settings
- [Docker Configuration - Wallet Restoration](docs/DOCKER_CONFIGURATION.md#wallet-restoration) - Secure seed phrase handling
- [API Reference](docs/API_REFERENCE.md) - API authentication documentation
- [Error Handling Policy](docs/ERROR_HANDLING_POLICY.md) - Error handling and logging standards
