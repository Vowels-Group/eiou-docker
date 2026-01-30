# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x.x   | :white_check_mark: |

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly.

### How to Report

**DO NOT** create a public GitHub issue for security vulnerabilities.

Instead, please email: **security@eiou.org**

Include in your report:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Any suggested fixes (optional)

### What to Expect

1. **Acknowledgment**: We will acknowledge receipt within 48 hours
2. **Initial Assessment**: Within 7 days, we will provide an initial assessment
3. **Resolution Timeline**: We aim to resolve critical issues within 30 days
4. **Disclosure**: We will coordinate disclosure timing with you

### Security Best Practices for Users

#### Seedphrase Security

- **Write it down physically** - never store digitally
- The `eiou info --show-auth` command displays secrets securely via TTY (not logged by Docker)
- For non-interactive environments, secrets are stored in `/dev/shm` temp files with auto-deletion

#### Container Security

- Run containers with minimal privileges
- Use Docker secrets for sensitive environment variables when possible
- Prefer `RESTORE_FILE` over `RESTORE` environment variable to avoid process list exposure:
  ```bash
  # More secure - file-based restore
  docker run -v /path/to/seed.txt:/seed:ro -e RESTORE_FILE=/seed eiou/eiou

  # Less secure - environment variable (visible in process list)
  docker run -e RESTORE="your seed phrase" eiou/eiou
  ```

#### Network Security

- Use HTTPS or Tor transport modes for production
- HTTP mode should only be used in isolated test environments
- SSL certificates are auto-generated; for production, provide your own CA-signed certificates

#### API Key Security

- Store API secrets securely; they are encrypted at rest
- Use minimal required permissions when creating API keys
- Rotate API keys periodically
- Monitor API key usage for anomalies

### Security Features

#### Input Validation
All user inputs are validated using the `InputValidator` class with specific validation rules for:
- Addresses (HTTP, HTTPS, Tor)
- Currency codes
- Amounts and fees
- Hostnames

#### Session Security
- HttpOnly cookies prevent JavaScript access
- SameSite=Strict prevents CSRF attacks
- Secure flag enabled when HTTPS is available
- Session ID regeneration every 5 minutes
- 30-minute inactivity timeout

#### CSRF Protection
All forms include CSRF tokens with:
- Cryptographically secure random generation
- Constant-time comparison
- 1-hour expiration

#### Shell Command Security
All shell commands with variable inputs use `escapeshellarg()` to prevent injection.

### Known Security Considerations

1. **Self-signed certificates**: Default SSL certificates are self-signed. For production, provide CA-signed certificates.

2. **Debug mode**: Ensure `APP_DEBUG` is disabled in production environments.

3. **Docker logs**: The seedphrase display system avoids Docker log capture, but users should still be cautious with container log access.

### Security Changelog

Security-related changes are documented in [CHANGELOG.md](CHANGELOG.md) under the `Security` section.
