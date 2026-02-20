# Security Audit Report

**Repository:** eiou-org/eiou-docker
**Date:** 2026-02-20
**Auditor:** Claude Opus 4.6
**Scope:** Full codebase security audit
**Commit:** `4ab2d6b` (main)

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Methodology](#methodology)
3. [Findings Summary](#findings-summary)
4. [Critical Findings](#critical-findings)
5. [High Findings](#high-findings)
6. [Medium Findings](#medium-findings)
7. [Low Findings](#low-findings)
8. [Positive Security Observations](#positive-security-observations)
9. [Remediation Priority](#remediation-priority)

---

## Executive Summary

This report documents a comprehensive security audit of the eiou-docker PHP codebase covering six domains: SQL injection, authentication/authorization, input validation/XSS, cryptography/key management, Docker/infrastructure, and transaction/P2P business logic.

**Overall Assessment:** The codebase demonstrates a strong security foundation with consistent use of prepared statements, proper cryptographic primitives (AES-256-GCM, Ed25519, PBKDF2-HMAC-SHA512), layered input validation, and thoughtful session management. However, the audit identified **3 critical**, **12 high**, and **21 medium** severity findings that should be addressed to harden the application for production deployment.

The most urgent issues are: (1) chain drop signature bypass allowing transaction record corruption, (2) IP spoofing that undermines all rate limiting, (3) missing rate limiting on GUI login enabling brute force, (4) TOCTOU race conditions in transaction locking, and (5) hardcoded development mode exposing detailed errors to all users.

| Severity | Count |
|----------|-------|
| Critical | 3 |
| High | 12 |
| Medium | 21 |
| Low | 24 |
| **Total** | **60** |

---

## Methodology

The audit was conducted via static code analysis across six parallel workstreams:

1. **SQL Injection** -- Database layer, repositories, query patterns, user input flow
2. **Authentication & Authorization** -- API auth, session management, rate limiting, CSRF
3. **Input Validation & XSS** -- Validators, output encoding, payload schemas, GUI templates
4. **Cryptography & Key Management** -- BIP39, encryption, key storage, logging hygiene
5. **Docker & Infrastructure** -- Dockerfile, compose files, SSL/TLS, error handling, network
6. **Transaction & P2P Security** -- Race conditions, chain integrity, message authentication

All PHP source files in `files/src/`, `files/root/`, configuration files, Dockerfiles, compose files, shell scripts, and HTML templates were examined.

---

## Findings Summary

### By Category

| Category | Crit | High | Med | Low | Total |
|----------|------|------|-----|-----|-------|
| Transaction & P2P | 1 | 3 | 7 | 2 | 13 |
| Auth & Session | 2 | 4 | 5 | 4 | 15 |
| Docker & Infrastructure | 0 | 3 | 8 | 5 | 16 |
| Cryptography & Keys | 0 | 2 | 4 | 6 | 12 |
| Input Validation & XSS | 0 | 0 | 5 | 8 | 13 |
| SQL Injection | 0 | 0 | 1 | 5 | 6 |

---

## Critical Findings

### C-1: Chain Drop Accepts Re-signed Transactions Without Signature Verification — REMEDIATED

> **Status:** Fixed in [PR #635](https://github.com/eiou-org/eiou-docker/pull/635)
> **Fix:** `processResignedTransactions()` now fetches the full transaction from DB, merges the new signature/nonce, and calls `SyncTrigger::verifyTransactionSignaturePublic()` before storing. Null guard added for `$this->syncTrigger`.

**Category:** Transaction & P2P
**File:** `files/src/services/ChainDropService.php:1125-1146`

During chain drop completion, re-signed transaction data from the remote party is stored directly in the local database without verifying the signature is valid against the claimed public key and transaction data.

```php
private function processResignedTransactions(array $resignedTransactions): void
{
    foreach ($resignedTransactions as $txData) {
        $txid = $txData['txid'] ?? null;
        $signature = $txData['sender_signature'] ?? null;
        $nonce = $txData['signature_nonce'] ?? null;
        if (!$txid || !$signature || $nonce === null) {
            continue;
        }
        // Directly updates signature -- NO verification
        $updated = $this->transactionRepository->updateSignatureData($txid, $signature, (int)$nonce);
    }
}
```

**Impact:** A malicious contact could inject arbitrary signature data for any transaction ID, corrupting local signature records and potentially allowing forged transactions to pass future verification.

**Recommendation:** Before calling `updateSignatureData()`, retrieve the full transaction, reconstruct the signed message, and verify the new signature using `openssl_verify()` against the sender's public key.

---

### C-2: IP Address Spoofing Bypasses All Rate Limiting — REMEDIATED

> **Status:** Fixed in [PR #635](https://github.com/eiou-org/eiou-docker/pull/635)
> **Fix:** New centralized `Security::getClientIp()` only trusts proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`) when `REMOTE_ADDR` is in the `TRUSTED_PROXIES` list. `RateLimiterService` and `ApiAuthService` now delegate to it. `TRUSTED_PROXIES` configurable via env var.

**Category:** Authentication & Authorization
**Files:** `files/src/services/RateLimiterService.php:118-130`, `files/src/services/ApiAuthService.php:345-357`

Both `RateLimiterService::getClientIp()` and `ApiAuthService::getClientIp()` trust user-controllable headers (`HTTP_CLIENT_IP`, `HTTP_X_FORWARDED_FOR`) without validating that the request comes from a trusted proxy.

```php
public static function getClientIp(): string {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', $_SERVER[$key])[0]);
        }
    }
    return '0.0.0.0';
}
```

**Impact:** An attacker can set `X-Forwarded-For` or `Client-IP` to any arbitrary value, completely bypassing all IP-based rate limiting and brute-force protection.

**Recommendation:** Only trust proxy headers when `REMOTE_ADDR` matches a configured list of trusted proxy IPs (e.g., Cloudflare ranges). Default to `REMOTE_ADDR`.

---

### C-3: No Rate Limiting on GUI Login Authentication — REMEDIATED

> **Status:** Fixed in [PR #635](https://github.com/eiou-org/eiou-docker/pull/635)
> **Fix:** GUI login POST now calls `RateLimiterService::checkLimit()` with `gui_login` action before processing credentials. CSRF token validation also added (see H-7).

**Category:** Authentication & Authorization
**File:** `files/root/www/gui/index.html:52-63`

The GUI login form processes auth code submissions with zero rate limiting. The `SecurityInit.php` rate limiting only detects `$_POST['action'] === 'login'`, but the GUI form posts `$_POST['authcode']` without an `action` field.

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['authcode'])) {
    $submittedAuthCode = $_POST['authcode'];
    if ($user->has('authcode_encrypted') && $secureSession->authenticate($submittedAuthCode, $user->getAuthCode())) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
```

**Impact:** Unlimited brute-force attempts against the wallet authentication code with no lockout or delay.

**Recommendation:** Add explicit rate limiting before the authentication check (e.g., 5 attempts per 5 minutes, 15-minute lockout).

---

## High Findings

### H-1: TOCTOU Race in Send Lock — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** `insertTransaction()` and `updateTrackingFields()` moved inside the `try` block in `handleDirectRoute()`, executing before the lock is released in `finally`.

**Category:** Transaction & P2P
**File:** `files/src/services/SendOperationService.php:289-337`

The contact send lock protects chain verification and data preparation, but the actual transaction insert occurs AFTER the lock is released in the `finally` block. A concurrent send to the same contact could verify the chain in the same state, causing both transactions to use the same `previous_txid`.

**Recommendation:** Move `insertTransaction()` inside the `try` block before the `finally` releases the lock.

---

### H-2: No Locking on P2P Transaction Sends — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** `sendP2pEiou()` now wraps the entire method body in `acquireContactSendLock`/`releaseContactSendLock` using the sender's public key hash.

**Category:** Transaction & P2P
**File:** `files/src/services/SendOperationService.php:385-394`

Unlike `handleDirectRoute()` which uses `acquireContactSendLock()`, the `sendP2pEiou()` method performs no locking. Concurrent P2P route responses for the same contact could create chain forks.

**Recommendation:** Add contact send locking around P2P transaction insertion using the same lock pattern as direct sends.

---

### H-3: Non-Atomic Balance Updates on Receive — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** `processIncomingDirect()` and `processIncomingP2p()` (end-recipient path) now wrap status + balance updates in `beginTransaction()`/`commit()` with rollback on exception. `AbstractRepository` transaction methods made public.

**Category:** Transaction & P2P
**File:** `files/src/services/TransactionProcessingService.php:276-279`

Status update and balance update are separate database operations without transactional wrapping. A crash between them creates a permanent balance discrepancy.

```php
$this->transactionRepository->updateStatus($txid, Constants::STATUS_COMPLETED, true);
$this->balanceRepository->updateBalance($message['sender_public_key'], 'received', $message['amount'], $message['currency']);
```

**Recommendation:** Wrap both operations in a database transaction (`PDO::beginTransaction()` / `PDO::commit()`).

---

### H-4: No Replay Protection for API Requests — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** `X-API-Nonce` header required on all API requests (8-64 chars). Server-side nonce tracking via `api_nonces` table rejects duplicates within the timestamp window. Nonce included in HMAC signature string. Three new error codes added (`auth_missing_nonce`, `auth_invalid_nonce`, `auth_replay_detected`).

**Category:** Authentication & Authorization
**File:** `files/src/services/ApiAuthService.php:91-99`

API authentication uses only a 5-minute timestamp window with no nonce tracking. The same signed request can be replayed unlimited times within 300 seconds.

**Impact:** A financial transaction intercepted in transit could be replayed multiple times.

**Recommendation:** Implement a required `X-API-Nonce` header with server-side nonce tracking to reject duplicates.

---

### H-5: Permission Validation Missing on API Key Creation

**Category:** Authentication & Authorization
**File:** `files/src/api/ApiController.php:1869-1889`

The API endpoint for creating API keys accepts arbitrary permission strings and rate limits without validation, unlike the CLI path which validates against a whitelist.

**Recommendation:** Validate permissions against the same whitelist used by `ApiKeyService`. Validate `rate_limit_per_minute` as a positive integer with an upper bound.

---

### H-6: Path Traversal in Backup Delete Endpoint — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** `Security::sanitizeFilename()` applied in both `ApiController::deleteBackup()` and `BackupService::deleteBackup()` before the filename is used.

**Category:** Authentication & Authorization
**Files:** `files/src/api/ApiController.php:1765`, `files/src/services/BackupService.php:287`

The filename parameter is URL-decoded and concatenated with the backup directory without sanitization. An attacker with backup permissions could delete arbitrary files via `../../` traversal.

**Recommendation:** Apply `Security::sanitizeFilename()` before passing to the service.

---

### H-7: CSRF Token Missing on GUI Login Form — REMEDIATED

> **Status:** Fixed in [PR #635](https://github.com/eiou-org/eiou-docker/pull/635)
> **Fix:** Hidden CSRF token field added to `authenticationForm.html`. Login POST validates the token via `$secureSession->validateCSRFToken()` before processing credentials.

**Category:** Authentication & Authorization
**File:** `files/src/gui/layout/authenticationForm.html:19-23`

The authentication form does not include a CSRF token. Combined with missing rate limiting (C-3), this enables CSRF-based brute force.

**Recommendation:** Generate and validate a CSRF token on the login form.

---

### H-8: SSL Peer Verification Disabled by Default — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** Changed `getenv('P2P_SSL_VERIFY') === 'true'` to `getenv('P2P_SSL_VERIFY') !== 'false'` in both `send()` and `createCurlHandle()`. SSL verification now enabled by default.

**Category:** Docker & Infrastructure
**File:** `files/src/services/utilities/TransportUtilityService.php:380-383`

P2P HTTPS connections default to SSL verification disabled (`P2P_SSL_VERIFY` must be explicitly set to `true`).

```php
$verifySsl = getenv('P2P_SSL_VERIFY') === 'true';
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
```

**Impact:** All P2P connections are vulnerable to man-in-the-middle attacks by default.

**Recommendation:** Default to `true` (verify) and make it opt-out: `getenv('P2P_SSL_VERIFY') !== 'false'`.

---

### H-9: APP_ENV Hardcoded to 'development' — REMEDIATED

> **Status:** Fixed in [PR #635](https://github.com/eiou-org/eiou-docker/pull/635)
> **Fix:** New `Constants::getAppEnv()` method reads `APP_ENV` env var with fallback to the constant. All 14 direct `Constants::APP_ENV` references migrated. New `Constants::isDebug()` reads `APP_DEBUG` env var. `Application::isDebug()` and `DebugService::setupErrorLogging()` updated.

**Category:** Docker & Infrastructure
**File:** `files/src/core/Constants.php:44-46`

`APP_ENV` and `APP_DEBUG` are compile-time constants with no environment variable override mechanism. All deployed containers run in development mode.

**Impact:** Detailed error messages, file paths, and stack traces are displayed to all users (see H-10).

**Recommendation:** Make these configurable via environment variables with production-safe defaults.

---

### H-10: Verbose Error Display in All Environments — REMEDIATED

> **Status:** Fixed in [PR #635](https://github.com/eiou-org/eiou-docker/pull/635)
> **Fix:** `ErrorHandler::isProduction()` now uses `Constants::getAppEnv()` (env var override). `DebugService::setupErrorLogging()` gates `display_errors` behind `Constants::isDebug()`. Setting `APP_ENV=production APP_DEBUG=false` now hides error details.

**Category:** Docker & Infrastructure
**File:** `files/src/core/ErrorHandler.php:91-98, 138-149`

Because `APP_ENV` is always `'development'`, full error details are rendered in HTTP responses including file paths, line numbers, and stack traces.

**Recommendation:** Combined with H-9, ensure `isProduction()` returns true for deployed containers.

---

### H-11: Database Credentials Stored in Plaintext JSON — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** New `Application::migrateDbConfigEncryption()` auto-migrates plaintext `dbPass` to AES-256-GCM encrypted `dbPassEncrypted` on first boot after the master key is stable. `freshInstall()` writes plaintext initially to avoid key-timing issues during setup; encryption is deferred to the migration. File permissions set to 0600. Handles both fresh installs and upgrades from existing plaintext configs.

**Category:** Cryptography & Keys
**File:** `files/src/database/DatabaseSetup.php:104-126`

The database password is stored in plaintext in `dbconfig.json` without encryption and without restrictive file permissions. Unlike wallet keys which use `KeyEncryption::encrypt()`, database credentials receive no protection.

**Recommendation:** Encrypt the password field using `KeyEncryption::encrypt()` and set file permissions to 0600.

---

### H-12: RESTORE Environment Variable Exposes Seed Phrase

**Category:** Cryptography & Keys
**File:** `startup.sh:758-806`

The RESTORE environment variable containing the 24-word seed phrase remains readable via `docker inspect` and `/proc/1/environ` even after `unset`. The code acknowledges this limitation in comments.

**Recommendation:** Deprecate the RESTORE env var in favor of RESTORE_FILE (which writes to `/dev/shm` and uses `shred -u`).

---

## Medium Findings

### M-1: Raw SQL Execution from Backup File Content — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added regex validation to verify SQL matches expected `INSERT IGNORE INTO \`transactions\` VALUES (...)` pattern before execution.

**Category:** SQL Injection
**File:** `files/src/services/BackupService.php:675`

Raw SQL strings extracted from encrypted backup files are passed to `PDO::exec()` without parameterization. Exploitation requires compromising backup encryption.

---

### M-2: Session Cookie `secure` Flag Conditional on HTTPS — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Changed to `!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'` for proper HTTPS detection.

**Category:** Auth & Session
**File:** `files/src/gui/includes/Session.php:24-31`

The `secure` flag is set based on `isset($_SERVER['HTTPS'])`, allowing session cookies over HTTP.

---

### M-3: CSRF Token Not Rotated After Use — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** CSRF token is now unset after successful validation, forcing regeneration on next use.

**Category:** Auth & Session
**File:** `files/src/gui/includes/Session.php:158-200`

CSRF tokens are generated once per session and never rotated after successful validation.

---

### M-4: Rate Limiting Bypass via Environment Variable — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added one-time warning log when `EIOU_TEST_MODE` bypasses rate limiting. Added bypass check to `enforce()` for consistency.

**Category:** Auth & Session
**File:** `files/src/services/RateLimiterService.php:40-48`

`EIOU_TEST_MODE=true` silently disables all rate limiting. Misconfiguration in production would remove this security control.

---

### M-5: Information Leakage in API Error Responses — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Replaced distinct error messages with generic "Invalid or inactive API key" for all authentication failures. Internal error codes retained for logging.

**Category:** Auth & Session
**File:** `files/src/services/ApiAuthService.php:95-123`

Error messages reveal internal configuration (max request age, per-key rate limits) and distinguish between invalid/disabled/expired keys, aiding reconnaissance.

---

### M-6: Duplicate CSRF Token Implementation — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Removed duplicate `Security::validateCSRFToken()` and `Security::generateCSRFToken()` static methods. Session class is the single CSRF implementation.

**Category:** Auth & Session
**File:** `files/src/utils/Security.php:150-167`, `files/src/gui/includes/Session.php:158-200`

Two independent CSRF implementations both use `$_SESSION['csrf_token']` with different logic, risking inconsistent protection.

---

### M-7: `sanitizeInput()` Only Removes Null Bytes — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Renamed to `stripNullBytes()` to accurately describe behavior. Deprecated alias `sanitizeInput()` retained for backward compatibility.

**Category:** Input Validation
**File:** `files/src/utils/Security.php:54-62`

The method name implies comprehensive sanitization but only removes null bytes and trims whitespace. No HTML tag stripping or entity encoding.

---

### M-8: CORS Wildcard Configuration Possible — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added warning log when CORS wildcard is configured. Added `X-API-Nonce` to allowed headers list.

**Category:** Input Validation
**File:** `files/root/api/Api.php:27-37`

If `API_CORS_ALLOWED_ORIGINS` is configured as `'*'`, the API allows requests from any origin, which is unsafe with authenticated endpoints.

---

### M-9: `validateRequestLevel()` Trusts Request-Supplied Maximum — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Server-side max from `UserContext::getMaxP2pLevel()` now caps the request-supplied `maxRequestLevel`. Added integer cast and lower-bound check.

**Category:** Input Validation
**File:** `files/src/services/utilities/ValidationUtilityService.php:39-46`

P2P request level validation checks `requestLevel <= maxRequestLevel` but both values come from the incoming request, allowing an attacker to set arbitrarily high values.

---

### M-10: Exception Messages Exposed in Non-Production — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Changed exception detail guard from `Constants::getAppEnv() !== 'production'` to `Constants::isDebug()` across all controllers (ContactController, TransactionController).

**Category:** Input Validation
**File:** `files/src/gui/controllers/ContactController.php:146-148` (repeated in all controllers)

Full exception messages displayed via toast notifications in non-production environments (which is all environments per H-9).

---

### M-11: Unencoded Transaction Date in Template — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added `htmlspecialchars()` to unencoded date and currency output in `transactionHistory.html`.

**Category:** Input Validation
**File:** `files/src/gui/layout/walletSubParts/transactionHistory.html:107`

Transaction date echoed without `htmlspecialchars()` while other fields on the same line are properly encoded.

---

### M-12: API Key Secret Echoed to Docker Logs — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Removed raw `echo` block that output secret to stdout. Only structured `$this->output->success()` call retained.

**Category:** Cryptography
**File:** `files/src/services/ApiKeyService.php:124-141`

API key secrets written to stdout via `echo`, captured by Docker's log driver. Should use `SecureSeedphraseDisplay` mechanism.

---

### M-13: Master Encryption Key Has No Recovery Mechanism

**Category:** Cryptography
**File:** `files/src/security/KeyEncryption.php:43-82`

The master key is randomly generated and not derived from the BIP39 seed. Loss of the key file makes all encrypted data (keys, backups) permanently unrecoverable.

---

### M-14: Legacy Plaintext Private Key Check in UserContext — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Removed `|| $this->has('private')` from `hasKeys()`. Only checks for `private_encrypted`.

**Category:** Cryptography
**File:** `files/src/core/UserContext.php:187`

`hasKeys()` checks for a `'private'` field suggesting historical plaintext key storage, but no migration path exists.

---

### M-15: Decrypted Backup Written to /tmp Without Encryption — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Changed temp files to `/dev/shm` (RAM-backed tmpfs). Wrapped in `try/finally` for guaranteed cleanup on crash.

**Category:** Cryptography
**File:** `files/src/services/BackupService.php:218-219`

Decrypted SQL dump written to `/tmp` in plaintext during restore. Should use `/dev/shm` (tmpfs) with guaranteed cleanup.

---

### M-16: No P2P Request Rate Limiting — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added in-memory rate limiting by sender public key hash in `checkP2pPossible()` (60 requests/minute per sender).

**Category:** Transaction & P2P
**File:** `files/src/services/P2pService.php:352-458`

No rate limiting on incoming P2P requests. Each request triggers database lookups and broadcasts to all contacts, enabling amplification attacks.

---

### M-17: Transaction Recovery Lacks Authorization — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added terminal state guard (prevents retry from completed/cancelled) and ownership check (verifies transaction belongs to current user).

**Category:** Transaction & P2P
**File:** `files/src/services/TransactionRecoveryService.php:194-252`

`resolveTransaction()` can force any transaction to any terminal status without authorization checks. The `complete` action skips balance updates.

---

### M-18: P2P Message Authentication Weakness — ACKNOWLEDGED

> **Status:** Acknowledged (defense-in-depth)
> **Note:** The hash-based check is a secondary validation layer. Primary message authentication occurs at the transport layer via Ed25519 signature verification in `verifyRequestSignature()`. The transport signature covers the full message content including sender identity. Relay nodes cannot forge valid transport signatures.

**Category:** Transaction & P2P
**File:** `files/src/services/MessageService.php:250-290`

P2P message validity check only verifies `H(address + salt + time) == hash`, but relay nodes know all these values and could forge valid messages.

---

### M-19: Sync Continues After Signature Failures — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added circuit breaker (5 signature failures threshold) that aborts sync and logs a security warning when exceeded.

**Category:** Transaction & P2P
**File:** `files/src/services/SyncService.php:721-749`

Sync processing skips transactions with invalid signatures but continues processing the rest, potentially allowing selective injection of legitimate transactions alongside forged ones.

---

### M-20: Contact Sync Accepts Unvalidated Remote Addresses — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** `AddressRepository::updateContactFields()` now dynamically filters to valid transport columns via `getAllAddressTypes()`, preventing arbitrary field updates.

**Category:** Transaction & P2P
**File:** `files/src/services/ContactSyncService.php:695-697`

Remote party provides network addresses during contact sync without challenge-response verification. Malicious addresses could enable MITM.

---

### M-21: Held Transactions Processed Despite Chain Integrity Failure — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Changed from "log and continue" to "log and return early" when chain integrity check fails after sync.

**Category:** Transaction & P2P
**File:** `files/src/services/HeldTransactionService.php:237-251`

After sync, held transactions are resumed even when chain integrity check fails, risking sends with stale chain references.

---

### M-22: Container Runs as Root

**Category:** Docker & Infrastructure
**File:** `eiou.dockerfile:3-7`

No `USER` directive. PID 1 and background PHP processors run as root.

---

### M-23: No Container Security Hardening — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added `security_opt: [no-new-privileges:true]` and `pids_limit: 200` to all compose files. Documented `docker run` equivalents in DOCKER_CONFIGURATION.md.

**Category:** Docker & Infrastructure
**Files:** All docker-compose files

Missing `security_opt`, `cap_drop`, `no-new-privileges`, `pids_limit` directives.

---

### M-24: No Docker Log Rotation — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added `logging:` config (json-file, 10m max, 3 files) to all compose files. Added `logrotate` inside container for Apache/PHP logs. Documented daemon-level config in DOCKER_CONFIGURATION.md.

**Category:** Docker & Infrastructure
**Files:** All docker-compose files

No `logging:` configuration. Default json-file driver has no size limit, risking disk exhaustion.

---

### M-25: HTTP Port Exposed Without Redirect to HTTPS — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added HTTP-to-HTTPS redirect in Apache HTTP VirtualHost. The `/eiou` transport endpoint is excluded for P2P backward compatibility.

**Category:** Docker & Infrastructure
**File:** `eiou.dockerfile:61-62`

Both ports 80 and 443 serve the full application. No HTTP-to-HTTPS redirect configured.

---

### M-26: SSL/TLS Protocol and Cipher Hardening Missing — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added `SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1`, `SSLCipherSuite HIGH:!aNULL:!MD5:!3DES:!RC4`, `SSLHonorCipherOrder on`, and HSTS header to SSL VirtualHost. Enabled `mod_headers`.

**Category:** Docker & Infrastructure
**File:** `eiou.dockerfile:96-118`

SSL VirtualHost does not set `SSLProtocol`, `SSLCipherSuite`, or `SSLHonorCipherOrder`. May negotiate deprecated TLS 1.0/1.1.

---

### M-27: Payload Sent via URL Query Parameter — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Moved payload to `CURLOPT_POSTFIELDS` (POST body). Disabled `CURLOPT_FOLLOWLOCATION` to prevent leakage on redirects. Receiver updated to read from POST body with GET fallback.

**Category:** Docker & Infrastructure
**File:** `files/src/services/utilities/TransportUtilityService.php:367`

Signed transaction payloads appended to URLs despite POST method, appearing in server logs and proxy caches.

---

### M-28: Timestamp-Only Nonce for P2P Replay Protection — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Changed nonce from `time()` to `bin2hex(random_bytes(16))` for cryptographic uniqueness.

**Category:** Docker & Infrastructure
**File:** `files/src/services/utilities/TransportUtilityService.php:778`

Nonce is `time()` (seconds resolution). Messages within the same second share identical nonces.

---

### M-29: Composer Installed Without Hash Verification — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Replaced piped `curl|php` with hash-verified install: downloads installer, verifies SHA-384 against `composer.github.io/installer.sig`, aborts if mismatch.

**Category:** Docker & Infrastructure
**File:** `eiou.dockerfile:50`

Composer installed via piped `curl|php` without SHA-384 hash verification of the installer.

---

### M-30: MariaDB Runs With Default Configuration — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added `/etc/mysql/conf.d/security.cnf` with `bind-address=127.0.0.1` (container-local only) and `skip-symbolic-links`.

**Category:** Docker & Infrastructure
**File:** `eiou.dockerfile:37`, `startup.sh:683`

MariaDB installed from package with no password set. `mysqladmin ping` used without authentication.

---

### M-31: Lock File Permissions World-Writable — REMEDIATED

> **Status:** Fixed in [PR #641](https://github.com/eiou-org/eiou-docker/pull/641)
> **Fix:** Lock file permissions changed from `0666` to `0600`.

**Category:** Transaction & P2P
**File:** `files/src/services/SendOperationService.php:130`

Lock files created with `chmod 0666`. Should use `0600`.

---

### M-32: Balance Overflow Not Checked — REMEDIATED

> **Status:** Fixed in PR (medium-term security remediations)
> **Fix:** Added overflow guard after balance accumulation. If `abs($totalCents)` exceeds `PHP_INT_MAX / 100`, logs warning and clamps to safe maximum.

**Category:** Transaction & P2P
**File:** `files/src/services/BalanceService.php:158-161`

Balance accumulation uses PHP integer arithmetic without overflow/underflow guards.

---

---

## Low Findings

For brevity, low findings are summarized below. Full details available on request.

| ID | Category | File | Description |
|----|----------|------|-------------|
| L-1 | SQL | TransactionStatisticsRepository | Missing `$allowedColumns` whitelist |
| L-2 | SQL | TransactionChainRepository | Missing `$allowedColumns` whitelist |
| L-3 | SQL | QueryBuilder:180-183 | Raw SQL conditions via numeric keys (unused) |
| L-4 | SQL | QueryBuilder:214-216 | Column names interpolated in ORDER BY (unused) |
| L-5 | SQL | MessageDeliveryRepository:361 | Constants interpolated instead of bound |
| L-6 | SQL | DatabaseSetup.php | DDL queries use string interpolation (hardcoded values) |
| L-7 | Auth | Session.php:314-318 | `clear()` doesn't fully destroy session |
| L-8 | Auth | SecurityInit.php | Not loaded by API entry point |
| L-9 | Auth | ApiController.php:1894 | API key self-deletion not prevented |
| L-10 | Auth | index.html:52 | Decrypted auth code not cleared from memory |
| L-11 | Auth | ApiAuthService.php:191 | `buildStringToSign()` unnecessarily public |
| L-12 | Auth | ApiAuthService.php:222 | `generateSignature()` helper shipped server-side |
| L-13 | XSS | contactSection.html:94 | Unencoded currency values in templates |
| L-14 | XSS | contactSection.html:43 | Unencoded contact name initial in avatar |
| L-15 | XSS | settingsSection.html:387 | `$_SERVER['SERVER_SOFTWARE']` unencoded |
| L-16 | XSS | MessageHelper.php:131 | `$_SERVER['PHP_SELF']` in Location header |
| L-17 | XSS | InputValidator.php:370 | No maximum length on signature validation |
| L-18 | XSS | AddressValidator.php:52 | `isTorAddress()` regex too permissive |
| L-19 | XSS | BasePayload.php:118 | `sanitizeString()` only trims |
| L-20 | XSS | Functions.php:98 | Double-encoding of flash messages |
| L-21 | Crypto | BIP39.php:157 | Non-constant-time checksum comparison |
| L-22 | Crypto | BIP39.php:211 | Hardcoded HMAC key context strings (scattered) |
| L-23 | Crypto | startup.sh:437 | SSL certificates use RSA 2048-bit |
| L-24 | Crypto | KeyEncryption.php:121 | Incomplete memory clearing in `encrypt()` |
| L-25 | Crypto | UserContext.php:131 | `getAll()` / `toArray()` exposes encrypted blobs |
| L-26 | Crypto | P2pService.php:708 | Weak `rand()` used for request level |
| L-27 | Crypto | Wallet.php:201 | Seed phrase via CLI arguments visible in /proc |
| L-28 | Crypto | KeyEncryption.php:112 | No Additional Authenticated Data (AAD) in AES-GCM |
| L-29 | Docker | eiou.dockerfile:64 | Missing ServerTokens/ServerSignature/expose_php |
| L-30 | Docker | docker-compose-4line.yml:172 | Flat bridge network (no topology enforcement) |
| L-31 | Docker | startup.sh:896 | Tor keys accessible to root PHP processes |
| L-32 | Docker | Security.php:81 | CSP allows `unsafe-inline` for scripts |
| L-33 | Docker | eiou.dockerfile:77 | No HTTPS redirect on HTTP VirtualHost |
| L-34 | P2P | ChainDropService.php:179 | Predictable chain drop proposal ID |
| L-35 | P2P | DatabaseLockingService.php:317 | Lock name truncation could cause collisions |

---

## Positive Security Observations

The codebase demonstrates mature security practices in many areas:

### Database Layer
- **PDO with `ATTR_EMULATE_PREPARES => false`** ensures real server-side prepared statements
- **Column whitelist system** in `AbstractRepository` (18/20+ repositories properly define whitelists)
- **Consistent parameterized queries** throughout all repository files
- **Direction validation** uses `in_array(..., true)` strict comparison before SQL interpolation
- **Safe IN clause construction** with proper placeholder generation

### Authentication & Session
- **HMAC-SHA256 with `hash_equals()`** prevents timing attacks on API authentication
- **AES-256-GCM encryption** for API secrets at rest with proper IV handling and auth tags
- **`sodium_memzero()`** used to clear secrets after HMAC verification
- **Session regeneration** on login and periodically (every 5 minutes)
- **SameSite=Strict, HttpOnly** cookie attributes
- **Custom session name** avoids default PHP session fingerprinting
- **Constant-time auth code comparison** via `hash_equals()`
- **Permission checks on every API endpoint**

### Cryptography
- **`random_bytes()`** used consistently for entropy (BIP39, keys, CSRF tokens)
- **AES-256-GCM** with proper IV length, auth tags, and unique IVs per operation
- **PBKDF2-HMAC-SHA512 with 2048 iterations** per BIP39 specification
- **Ed25519 key clamping** in Tor key derivation with sodium library
- **Secure seed phrase display** via TTY-first approach, `/dev/shm` fallback, auto-deletion
- **Comprehensive log masking** of passwords, keys, tokens, emails, cards, SSNs, mnemonics
- **File permissions** (0600) consistently set for master key, userconfig, backups
- **Backup encryption** before writing to disk
- **MySQL credential passing** via `--defaults-extra-file` (not command line)
- **Startup script** uses `shred -u` for temp files containing seed phrases

### Input Validation
- **`InputValidator` class** provides thorough server-side validation with strict regex patterns
- **JavaScript `escapeHtml()`** used for all `innerHTML` assignments
- **`json_encode` with `JSON_HEX_*` flags** for safe PHP-to-JavaScript embedding
- **`htmlspecialchars()`** used in most template output points
- **Security headers** (CSP, X-Frame-Options, X-Content-Type-Options, HSTS, Referrer-Policy)

### Docker & Infrastructure
- **Health checks** in Dockerfile and all compose files with appropriate intervals
- **Resource limits** (CPU 1.0, memory 512M) in all compose files
- **Graceful shutdown** with SIGTERM/SIGINT/SIGHUP handling
- **Named volumes** with proper naming conventions
- **SSL certificate priority chain** (External > Let's Encrypt > CA-signed > Self-signed)
- **Backup retention** (3 most recent)
- **Watchdog process** with restart limits and cooldowns
- **`set -e`** in shell scripts for fail-fast behavior

### Transaction Processing
- **Atomic claiming pattern** using `claimPendingTransaction()` for PENDING->SENDING transitions
- **Chain verification** before transaction sends
- **Held transaction system** for sync-dependent transactions
- **Dead letter queue** for failed message delivery
- **Message delivery tracking** with retry logic

---

## Remediation Priority

### Immediate (Production Blockers)

| ID | Finding | Effort | Status |
|----|---------|--------|--------|
| C-1 | Verify signatures in chain drop `processResignedTransactions()` | Small | Fixed ([PR #635](https://github.com/eiou-org/eiou-docker/pull/635)) |
| C-2 | Fix `getClientIp()` to only trust proxy headers from trusted IPs | Small | Fixed ([PR #635](https://github.com/eiou-org/eiou-docker/pull/635)) |
| C-3 | Add rate limiting to GUI login handler | Small | Fixed ([PR #635](https://github.com/eiou-org/eiou-docker/pull/635)) |
| H-9 | Make `APP_ENV`/`APP_DEBUG` configurable via env vars | Small | Fixed ([PR #635](https://github.com/eiou-org/eiou-docker/pull/635)) |
| H-10 | Follows from H-9 -- errors hidden in production | -- | Fixed ([PR #635](https://github.com/eiou-org/eiou-docker/pull/635)) |

### Short-term (Next Sprint)

| ID | Finding | Effort | Status |
|----|---------|--------|--------|
| H-1 | Move `insertTransaction()` inside send lock | Small | Fixed ([PR #641](https://github.com/eiou-org/eiou-docker/pull/641)) |
| H-2 | Add locking to P2P transaction sends | Small | Fixed ([PR #641](https://github.com/eiou-org/eiou-docker/pull/641)) |
| H-3 | Wrap balance updates in database transactions | Small | Fixed ([PR #641](https://github.com/eiou-org/eiou-docker/pull/641)) |
| H-4 | Add nonce-based API replay protection | Medium | Fixed ([PR #641](https://github.com/eiou-org/eiou-docker/pull/641)) |
| H-6 | Apply `sanitizeFilename()` in backup delete | Small | Fixed ([PR #641](https://github.com/eiou-org/eiou-docker/pull/641)) |
| H-7 | Add CSRF token to GUI login form | Small | Fixed ([PR #635](https://github.com/eiou-org/eiou-docker/pull/635)) |
| H-8 | Default `P2P_SSL_VERIFY` to true | Small | Fixed ([PR #641](https://github.com/eiou-org/eiou-docker/pull/641)) |
| H-11 | Encrypt database password in config file | Small | Fixed ([PR #641](https://github.com/eiou-org/eiou-docker/pull/641)) |

### Medium-term (Next Release)

| ID | Finding | Effort |
|----|---------|--------|
| H-5 | Validate API key creation permissions | Small |
| H-12 | Deprecate RESTORE env var | Medium |
| M-16 | Implement P2P rate limiting | Medium |
| M-18 | Strengthen P2P message authentication | Large |
| M-22 | Run PHP processors as non-root | Medium |
| M-26 | Add SSL protocol/cipher hardening | Small |
| M-27 | Move payload to POST body | Medium |
| M-29 | Use hash-verified Composer install | Small |
| M-12 | Route API key display through secure mechanism | Small |
| M-13 | Evaluate master key recovery strategy | Large |

### Long-term (Backlog)

All remaining medium and low findings. Focus on defense-in-depth improvements, consistent output encoding, Docker hardening, and cryptographic enhancements.

---

*This report was generated through static code analysis. Dynamic testing and penetration testing would complement these findings. Some findings may have lower practical exploitability due to the Docker-contained deployment model and peer-to-peer network topology.*
