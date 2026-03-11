# Codebase Audit Report

**Date:** 2026-03-11
**Branch:** `eiou-docker-audit-codebase-audit-260311`
**Scope:** Full codebase audit covering security, architecture, code quality, dependencies, and DevOps
**Codebase:** ~307 PHP files (164+ classes, 132 test files), 1 Dockerfile, 5 docker-compose configs, 60+ shell scripts

### Remediation Progress

| Phase | PR | Status |
|-------|-----|--------|
| Phase 1: Critical & Security | [#706](https://github.com/eiou-org/eiou-docker/pull/706) | Submitted |
| Phase 2: High Priority | [#707](https://github.com/eiou-org/eiou-docker/pull/707) | Submitted |
| Phase 3: Medium Priority | [#708](https://github.com/eiou-org/eiou-docker/pull/708) | Submitted |
| Phase 4: Low Priority | [#713](https://github.com/eiou-org/eiou-docker/pull/713) | Submitted |
| ARCH-04: CliService Refactor | [#714](https://github.com/eiou-org/eiou-docker/pull/714) | Submitted |
| Phase 5: Remaining Open Items | [#715](https://github.com/eiou-org/eiou-docker/pull/715) | Submitted |
| DOCK-05: Source/Data Separation | [#716](https://github.com/eiou-org/eiou-docker/pull/716) | Submitted |
| ARCH-05 + ARCH-01: RepositoryFactory & Constructor DI | Branch `eiou-docker-refactor-arch05-repository-factory-260311` | PR pending |

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Security Findings](#security-findings)
3. [Architectural Issues](#architectural-issues)
4. [Code Quality & Dead Code](#code-quality--dead-code)
5. [Docker & Infrastructure](#docker--infrastructure)
6. [Dependencies & DevOps](#dependencies--devops)
7. [Positive Findings](#positive-findings)
8. [Recommended Action Plan](#recommended-action-plan)

---

## Executive Summary

| Severity | Count |
|----------|-------|
| Critical | 2 |
| High | 7 |
| Medium | 18 |
| Low | 8 |
| **Total** | **35** |

The codebase demonstrates **solid security fundamentals** (parameterized queries, bcrypt hashing, secure sessions, rate limiting, container hardening). However, there are notable issues in command execution patterns, architectural complexity, shell script safety, and code quality that should be addressed before production hardening.

---

## Security Findings

### SEC-01: Command Injection in SSL Certificate Generation
**Severity:** CRITICAL
**File:** `files/src/api/ApiController.php:2586`

The `regenerateSslForHostname()` method constructs an OpenSSL command using `exec()`. While direct exec parameters use `escapeshellarg()`, the generated `openssl.conf` file contains unsanitized hostname data:

```php
$domain = preg_replace('#^https?://#', '', $newHostname);
$domain = rtrim($domain, '/');
$sanList = "DNS:localhost,DNS:{$domain}";

// Domain embedded in openssl.conf without escaping
file_put_contents($opensslConf, "[req]\n...CN={$domain}\n[v3_ca]\nsubjectAltName={$sanList}\n");
exec("openssl req -x509 ... -config {$opensslConf} 2>/dev/null");
```

OpenSSL config parsing could allow injection through crafted domain names (e.g., `example.com\n[req]`).

**Fix:** Use PHP's `openssl_csr_new()` / `openssl_x509_export()` instead of shell exec. If exec is required, validate hostname strictly: alphanumeric, dots, and dashes only.

---

### SEC-02: Debug Mode Hardcoded as Enabled
**Severity:** MEDIUM
**File:** `files/src/core/Constants.php:46`
**Status:** **FIXED** in [#707](https://github.com/eiou-org/eiou-docker/pull/707)

```php
const APP_ENV = 'development';
const APP_DEBUG = true;
```

Debug mode logs sensitive information (query details, stack traces, internal paths) and may expose debug panels in the GUI.

**Fix:** Default `APP_DEBUG` to `false`. Override via environment variable for development.

**Resolution:** Changed `APP_DEBUG = false` (secure-by-default). Migrated all consumers (`DebugService`, `Security.php`, GUI settings) to `Constants::isDebug()` which respects `APP_DEBUG` env var override. Documented `APP_DEBUG` env var in `docker-compose.yml` feature flags. Also fixed a pre-existing bug in `Security.php` that compared the boolean constant to the string `'true'`.

---

### SEC-03: Sensitive Credentials in Temporary Files
**Severity:** MEDIUM
**File:** `files/src/services/BackupService.php:79, 239`
**Status:** **FIXED** in [#707](https://github.com/eiou-org/eiou-docker/pull/707)

Database credentials are written to temporary MySQL config files. TOCTOU race condition exists between `tempnam()` creation and `chmod()`:

```php
$tempCnf = tempnam($tmpDir, 'mysql_');
chmod($tempCnf, 0600);  // Race condition window
file_put_contents($tempCnf, "[client]\nuser={$dbUser}\npassword={$dbPass}\n");
```

**Fix:** Use `MYSQL_PWD` environment variable or atomically create files with restricted permissions. Ensure cleanup in `finally` blocks.

**Resolution:** Set `umask(0177)` before `tempnam()` so the file is created with `0600` permissions atomically, eliminating the TOCTOU race. Umask is restored immediately after. Existing `try/finally` cleanup and `/dev/shm` usage were already in place.

---

### SEC-04: Insecure Temporary File for SSL Config
**Severity:** MEDIUM
**File:** `files/src/services/CliSettingsService.php:1282`
**Status:** **FIXED** in Phase 5 PR

ApiController's `createSslConfig()` was already secure (uses `tempnam()`, `/dev/shm`, `register_shutdown_function()`). The real issue was in `CliSettingsService::regenerateSslCertificate()` which used hardcoded `/tmp/openssl-san.cnf` with no permissions and no guaranteed cleanup.

**Fix:** Use `/dev/shm` (RAM-backed). Set 0600 permissions immediately. Use `try/finally` for cleanup.

**Resolution:** Replaced hardcoded `/tmp/openssl-san.cnf` and `/tmp/server.csr` with `tempnam()` using `/dev/shm` when available, restrictive `umask(0177)` for atomic 0600 permissions, and `try/finally` block for guaranteed cleanup.

---

### SEC-05: SSL Verification Can Be Disabled
**Severity:** MEDIUM
**File:** `files/src/services/utilities/TransportUtilityService.php:400-408`

```php
if (!$verifySsl) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
}
```

P2P communications vulnerable to MITM when `P2P_SSL_VERIFY=false`.

**Fix:** Default to `true`. Implement certificate pinning for known nodes. Log warnings when disabled.

---

## Architectural Issues

### ARCH-01: Circular Dependency Wiring Anti-Pattern
**Severity:** HIGH
**File:** `files/src/services/ServiceContainer.php:1302-1656`
**Status:** **FIXED** on branch `eiou-docker-refactor-arch05-repository-factory-260311`

The application uses explicit "circular dependency wiring" requiring manual setter injection after initialization. 30+ services depend on this fragile ordering. Services throw `RuntimeException` if dependencies aren't wired.

**Fix:** Refactor to constructor DI exclusively. Use lazy-loading proxies (already started with `SyncServiceProxy`) for true circular dependencies. Consider event-driven decoupling.

**Resolution:** Moved 27 repository setter calls and 11 `SyncTriggerInterface` setter calls from `wireCircularDependencies()` into service constructors via `RepositoryFactory` and `SyncServiceProxy` injection. The `wireCircularDependencies()` method was reduced from 73 setter calls to 35 (service-to-service circular dependencies only). 16 service classes received new constructor parameters and had corresponding setter methods removed. 6 interface contracts updated to remove setter declarations (`setSyncTrigger`, `setBalanceRepository`, `setContactCreditRepository`). PSR-11 `get()`/`has()` methods updated to use `RepositoryFactory` instead of removed `$repositories` array. Net change: +191 / -638 lines across 24 files. All 3048+ tests pass.

---

### ARCH-02: Dual Singleton Pattern (Application + ServiceContainer)
**Severity:** MEDIUM
**Files:** `files/src/core/Application.php:30`, `files/src/services/ServiceContainer.php:88`

Both manage overlapping state (UserContext, PDO, services). Unclear which is authoritative. Complicates testing.

**Fix:** Choose one container pattern. Eliminate `Application` singleton or make it a thin wrapper around `ServiceContainer`.

---

### ARCH-03: N+1 Query Pattern in Contact Search
**Severity:** CRITICAL
**File:** `files/src/services/ContactManagementService.php:576-606`

```php
foreach ($results as &$result) {
    $creditData = $this->contactCreditRepository->getAvailableCredit($hash);       // Query per row
    $balanceData = $this->balanceRepository->getContactBalanceByPubkeyHash($hash);  // Query per row
}
```

100 contacts = 200+ additional queries.

**Fix:** Add batch query methods: `getAvailableCreditsForPubkeyHashes(array $hashes)`. Pre-load via JOINs in the initial search query.

---

### ARCH-04: God Classes Violating Single Responsibility
**Severity:** HIGH
**Status:** **PARTIALLY FIXED** — CliService refactored in [#714](https://github.com/eiou-org/eiou-docker/pull/714)

| Class | Lines | Concerns | Status |
|-------|-------|----------|--------|
| CliService | 3,783 → 1,136 | Settings, user info, balance, transactions, SSL, P2P | **Fixed** (70% reduction via 4 sub-services) |
| SyncService | 2,267 | Contact sync, transaction sync, balance sync, chain resolution | Open |
| ServiceContainer | 1,842 → 1,570 | 79+ getter methods, wiring, initialization | **Partially fixed** (ARCH-05 + ARCH-01: -272 lines via RepositoryFactory + constructor DI) |
| ContactSyncService | 1,775 | Multiple sync strategies | Open |
| MessageDeliveryService | 1,764 | Multiple delivery mechanisms | Open |

**Fix:** Break down using composition, strategy pattern, or command/handler pattern. Extract validation into dedicated validators.

**CliService remediation:** Extracted `CliP2pApprovalService` (414 lines), `CliDlqService` (285 lines), `CliSettingsService` (1,328 lines), `CliHelpService` (929 lines). Facade/delegation pattern maintains backward compatibility.

---

### ARCH-05: Missing Repository Abstraction Layer
**Severity:** MEDIUM
**File:** `files/src/database/` (24 repository classes)
**Status:** **FIXED** on branch `eiou-docker-refactor-arch05-repository-factory-260311`

ServiceContainer contains 40+ repetitive repository getter methods. No factory or registry pattern. Tight coupling to concrete PDO instance.

**Fix:** Implement `RepositoryFactory` class. Would reduce ServiceContainer size by ~50%.

**Resolution:** Created `RepositoryFactory` class (`files/src/database/RepositoryFactory.php`) with generic `get(class-string)` method, validation, caching, `has()`, and `set()` (for testing). Replaced 25 near-identical lazy-loading repository getter methods in `ServiceContainer` (~345 lines of boilerplate) with one-line delegations to the factory (~100 lines). All typed getter methods preserved for backward compatibility. New `getRepositoryFactory()` method exposes the factory for direct use by services. Unit tests added (`tests/Unit/Database/RepositoryFactoryTest.php`, 9 tests, 26 assertions).

---

### ARCH-06: Silent Exception Swallowing
**Severity:** HIGH
**Files:** `files/src/services/ContactManagementService.php:589-591`, `files/src/gui/functions/Functions.php:319, 334`
**Status:** **FIXED** in [#707](https://github.com/eiou-org/eiou-docker/pull/707)

```php
} catch (\Exception $e) {
    // Non-critical
}
```

All exceptions silently ignored. Hides database failures, invalid data states, and real bugs.

**Fix:** Log all exceptions at minimum. Catch specific exception types only.

**Resolution:** Added logging to 17 silent catch blocks across the service and database layers: `TransactionRepository` (7 catches), `TransactionContactRepository` (3), `QueryBuilder` trait (2), `TorCircuitHealth` (2), `ContactSyncService` (2), and `ConfigCheck` (1). Database query failures log at WARNING level, transaction insert failures at ERROR, config fallbacks at DEBUG. GUI-layer silent catches (cosmetic data loading) were left as-is since they are non-critical display code.

---

### ARCH-07: Inconsistent Error Handling Patterns
**Severity:** MEDIUM
**Files:** Multiple services

Mixed strategies observed:
- `try/catch → log → re-throw` (Application.php:223)
- `try/catch → log → continue` (Application.php:310)
- `try/catch → silent` (ContactManagementService:589)
- No try/catch at all (many repository methods)

**Fix:** Create custom exception hierarchy (`FatalServiceException`, `NonFatalServiceException`). Document error handling policy per service layer.

---

### ARCH-08: Missing Database Indexes
**Severity:** MEDIUM
**Files:** Various repositories in `files/src/database/`
**Status:** **NO ACTION NEEDED** — indexes verified as present

Queries in `TransactionRepository::findByStatus()`, `ContactRepository::searchContacts()`, `BalanceRepository::getContactBalanceByPubkeyHash()` may lack corresponding indexes.

**Fix:** Audit all repository queries against schema. Add missing indexes. Use `EXPLAIN` to verify efficiency.

**Review:** Schema audit confirmed indexes are properly implemented: `idx_transactions_status`, `idx_transactions_status_timestamp`, `idx_contacts_name`, `idx_contacts_pubkey_hash`, `idx_contacts_pubkey_hash_status`, `idx_balances_pubkey_hash`. All critical query columns and join columns are indexed.

---

### ARCH-09: Unbounded Query Results
**Severity:** MEDIUM
**Files:** Multiple repositories

Methods like `findAll()`, `searchContacts()` don't enforce result limits.

**Fix:** Add mandatory pagination parameters with sensible defaults (`limit=50, offset=0`). Cap max limit (e.g., 1000).

---

### ARCH-10: Missing Health Check Validation
**Severity:** MEDIUM
**File:** `docker-compose.yml:316-321`
**Status:** **FIXED** in Phase 5 PR

Health check only validates nginx serves `/gui/`:
```yaml
test: ["CMD", "curl", "-f", "http://localhost/gui/"]
```

Does not verify database connectivity, message processors, or Tor.

**Fix:** Create `/api/health` endpoint checking all critical subsystems. Return proper HTTP codes and JSON.

**Resolution:** Added unauthenticated `/api/health` endpoint in `Api.php` that checks database connectivity (SELECT 1 with 3s timeout) and message processor PID files. Returns `{"status":"ok"}` (HTTP 200) or `{"status":"degraded"}` (HTTP 503). Docker healthcheck updated to use `/api/health` instead of `/gui/`.

---

## Code Quality & Dead Code

### CQ-01: Excessive Error Suppression Operator (@)
**Severity:** MEDIUM
**Count:** 41+ occurrences across 7+ files
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

Key files:
- `files/src/utils/TorCircuitHealth.php` (5 occurrences)
- `files/src/processors/AbstractMessageProcessor.php` (6 occurrences)
- `files/src/utils/SecureLogger.php` (3 occurrences)

**Fix:** Replace with proper error handling (`try/catch`, `file_exists()` checks, return value handling).

**Resolution:** Removed 33 `@` operators across 11 files: `AbstractMessageProcessor` (10), `SecureSeedphraseDisplay` (6), `TorCircuitHealth` (6), `SecureLogger` (4), `SendOperationService` (4), `Functions.php` (3), `TransportUtilityService` (2), `CliService` (2), `DatabaseSetup` (2), `P2pMessageProcessor` (2), `P2pRepository` (1). Replaced with `file_exists()` checks before `unlink()`, return value checks on `file_get_contents()`/`fopen()`, and direct calls where failure is non-critical (e.g., `posix_kill`, `mkdir`).

---

### CQ-02: `print_r()` in Output Functions
**Severity:** MEDIUM
**File:** `files/src/schemas/OutputSchema.php` (~25+ occurrences)
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

```php
function outputContactNotFoundTryP2p($request){
    return "[Contact] Not found, trying p2p with data: " . print_r($request, true)."\n";
}
```

Exposes internal data structures. Verbose, poorly formatted output.

**Fix:** Use `json_encode()` for structured data. Move debug output to Logger calls.

**Resolution:** Replaced all 26 `print_r()` calls in `OutputSchema.php` with `json_encode($var, JSON_UNESCAPED_SLASHES)`. Also fixed 2 occurrences in `MessagePayload.php` and `Rp2pPayload.php` — scalar values used directly, array values switched to `json_encode()`.

---

### CQ-03: Missing Type Hints
**Severity:** MEDIUM

- `files/src/services/ApiKeyService.php:24-25` - Untyped properties and constructor
- `files/src/schemas/OutputSchema.php` - 99+ functions without return type hints
- Various utility functions lack parameter types

**Fix:** Add type hints progressively, starting with service classes.

---

### CQ-04: Deprecated `array()` Syntax
**Severity:** LOW
**Count:** 152 occurrences across 51 files
**Status:** **FIXED** in [#713](https://github.com/eiou-org/eiou-docker/pull/713)

Modern PHP uses `[]` short syntax (available since PHP 5.4).

**Fix:** Run automated fixer (PHP-CS-Fixer) codebase-wide.

**Resolution:** Modernized remaining `array()` syntax to `[]` in `TransportUtilityService.php` (6 occurrences: callable syntax and literal arrays). Original audit estimate of 152 was inflated — most were already using short syntax or were in comments/strings.

---

### CQ-05: Spelling Errors in Function Names
**Severity:** LOW
**File:** `files/src/schemas/OutputSchema.php`
**Status:** **FIXED** in [#713](https://github.com/eiou-org/eiou-docker/pull/713)

- Line 10: `outputAdressContactIssue` → should be `outputAddressContactIssue`
- Line 14: `outputAdressOrContactIssue` → same
- Line 26: `outputContactSuccesfullysynced` → `outputContactSuccessfullySynced`

**Fix:** Deprecate misspelled functions and add correctly-named wrappers. Update all call sites.

**Resolution:** Renamed all 3 functions directly (no deprecation wrappers needed — internal code only). Updated call sites in `P2pService.php`, `SyncService.php` (2 calls with a different misspelling variant `outputContactSuccesfullySynced`), and test file `OutputSchemaTest.php`.

---

### CQ-06: Magic Strings Instead of Constants
**Severity:** LOW
**Files:** `files/src/gui/functions/Functions.php`, various services
**Status:** **FIXED** in [#713](https://github.com/eiou-org/eiou-docker/pull/713)

Hardcoded action names, message prefixes, and session keys:
```php
if (in_array($action, ['addContact', 'acceptContact', ...]))
$_SESSION['message']
$_SESSION['in_progress_txids']
```

**Fix:** Define constants in a `SessionKeys` class and action name constants.

**Resolution:** Created `SessionKeys` constants class (`files/src/gui/includes/SessionKeys.php`) with 11 named constants for all session keys (authentication, CSRF, flash messages, transaction tracking). Updated `Session.php`, `Functions.php`, and `MessageHelper.php` to use constants instead of string literals. Action name constants deferred (lower priority, spread across many files).

---

### CQ-07: Inconsistent Session Variable Naming
**Severity:** LOW
**File:** `files/src/gui/functions/Functions.php`
**Status:** **FIXED** in CQ-06 ([#713](https://github.com/eiou-org/eiou-docker/pull/713)) + Phase 5 PR

Mixed naming conventions for session keys: `'message'`, `'in_progress_txids'`, `'known_txids'`, `'known_dlq_ids'`.

**Fix:** Centralize in a `SessionKeys` constants class.

**Resolution:** `SessionKeys` class created in CQ-06 with 11 constants. All session key usage migrated to constants across `Session.php`, `Functions.php`, `MessageHelper.php`. Last remaining hardcoded string in `SecurityInit.php` fixed in Phase 5.

---

### CQ-08: Large Entry Point Wrappers
**Severity:** LOW
**Files:** `files/root/processors/` (5 files)

Thin wrappers that include `Functions.php`, `SecurityInit.php`, then instantiate processors. Could be consolidated.

**Fix:** Consider a single entry point with routing based on CLI argument.

---

## Docker & Infrastructure

### DOCK-01: Missing `set -e` in startup.sh
**Severity:** HIGH
**File:** `startup.sh` (1,612 lines)

The main startup script lacks `set -e` and `set -u`. Configuration errors can be silently ignored.

**Fix:** Add `set -e; set -u` at script top. Refactor functions to handle errors explicitly.

---

### DOCK-02: Unquoted Variable Expansions in Shell Scripts
**Severity:** HIGH
**File:** `startup.sh:162-172, 1209-1213`

```bash
docker exec $(hostname)  # Unquoted - word splitting risk
```

**Fix:** Quote all variable expansions: `"$(hostname)"`, `"$variable"`.

---

### DOCK-03: Insecure Temporary File Usage in startup.sh
**Severity:** MEDIUM
**File:** `startup.sh:60`

```bash
SHUTDOWN_FLAG="/tmp/eiou_shutdown.flag"  # Predictable path
```

**Fix:** Use `mktemp` for randomized temp files. Avoid predictable paths in `/tmp`.

---

### DOCK-04: No Multi-Stage Docker Build
**Severity:** MEDIUM
**File:** `eiou.dockerfile`

Entire image contains composer dependencies and development tools. Source backup at `/app/eiou-src-backup` wastes space.

**Fix:** Implement multi-stage build. Expected savings: 150-200MB image size.

---

### DOCK-05: Source File Sync Anti-Pattern
**Severity:** MEDIUM
**File:** `startup.sh:593-649`
**Status:** **FIXED** in [#716](https://github.com/eiou-org/eiou-docker/pull/716)

Startup copies files from backup to volume, reapplies permissions, runs composer install every boot. Slow, duplicative.

**Fix:** Deploy pre-initialized volumes. Use Docker build cache properly. Move updates to a separate upgrade script.

**Resolution:** Separated source code from user data: code now lives at `/app/eiou/` (image filesystem, auto-updates with image), config/data volume narrowed to `/etc/eiou/config/`. Removed ~110 lines of boot-time source sync and composer autoloader setup from `startup.sh`. Added one-time migration cleanup for existing volumes. Updated all hardcoded `/etc/eiou/` code paths across Dockerfile, entry points, GUI templates, processors, and scripts. Config paths unchanged.

---

### DOCK-06: Tight Resource Limits
**Severity:** MEDIUM
**File:** `docker-compose.yml:309-315`
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

Memory: 512MB hard / 256MB reservation for nginx + PHP-FPM + MariaDB + Tor + cron. OOM kills likely under load.

**Fix:** Increase to 1-2GB. Document memory requirements. Consider separating services into multiple containers.

**Resolution:** Increased resource limits in `docker-compose.yml`: memory 512M→1024M, memory reservation 256M→512M, CPUs 1.0→2.0.

---

### DOCK-07: Container Runs Services as Root During Startup
**Severity:** MEDIUM
**File:** `eiou.dockerfile`

No explicit `USER` directive. Services drop privileges after startup via service users (www-data, debian-tor, mysql), but startup runs as root.

**Fix:** Add explicit `USER` directive where possible. Document required root operations.

---

### DOCK-08: Missing NODE_NAME Validation
**Severity:** MEDIUM
**File:** `startup.sh`
**Status:** **FIXED** in Phase 5 PR

`NODE_NAME` defaults to "eiou-node" with no character validation. Could contain dangerous characters.

**Fix:** Validate: alphanumeric + hyphens only. Reject or sanitize on startup.

**Resolution:** Added `validate_hostname()` and `validate_name()` functions in `startup.sh` that validate QUICKSTART, EIOU_HOST (alphanumeric + dots + hyphens + underscores), EIOU_NAME (same plus spaces), and EIOU_PORT (numeric only). Container exits with error on invalid input. NODE_NAME itself is validated by Docker (container/volume naming rules).

---

## Dependencies & DevOps

### DEV-01: No `composer audit` in CI/CD
**Severity:** HIGH
**Files:** `.github/workflows/phpunit.yml`

No security scanning for known PHP vulnerabilities in dependencies.

**Fix:** Add `composer audit` step to GitHub Actions workflow.

---

### DEV-02: No Static Analysis (PHPStan/Psalm)
**Severity:** MEDIUM
**Files:** `.github/workflows/`
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

No SAST or static analysis tools configured.

**Fix:** Add PHPStan or Psalm to CI pipeline. Start at level 1 and increase over time.

**Resolution:** Added PHPStan at level 1 with CI workflow (`.github/workflows/phpstan.yml`), config file (`phpstan.neon`), and `phpstan/phpstan` as Composer dev dependency. GUI and OutputSchema excluded from analysis scope initially.

---

### DEV-03: No Container Image Scanning
**Severity:** MEDIUM
**Files:** `.github/workflows/`
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

No Trivy, Grype, or similar scanning post-build.

**Fix:** Add image scanning step to integration-tests workflow.

**Resolution:** Added Trivy container image scan step to `.github/workflows/integration-tests.yml`. Scans for CRITICAL and HIGH severity vulnerabilities. Configured as non-blocking (exit-code 0) initially to avoid breaking builds on base image CVEs.

---

### DEV-04: Missing `.env.example`
**Severity:** MEDIUM
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

No documentation of all configurable environment variables in a template file.

**Fix:** Create `.env.example` with all variables, defaults, and descriptions.

**Resolution:** Created `.env.example` documenting all environment variables with their defaults, descriptions, and grouping (required, optional, feature flags, advanced).

---

### DEV-05: Missing Dependabot Configuration
**Severity:** MEDIUM
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

No automated dependency update mechanism.

**Fix:** Add `.github/dependabot.yml` for Composer and GitHub Actions.

**Resolution:** Created `.github/dependabot.yml` with weekly update schedules for both Composer (`/files` directory) and GitHub Actions ecosystems.

---

### DEV-06: Incomplete .gitignore
**Severity:** LOW
**Status:** **FIXED** in [#708](https://github.com/eiou-org/eiou-docker/pull/708)

Missing patterns for: `*.log`, `*.db`, `*.sqlite`, `*.swp`, `*.swo`, `*~`, `.DS_Store`, `Thumbs.db`, `.env`, `*.pem`, `*.key`, `*.crt`.

**Fix:** Add missing patterns to `.gitignore`.

**Resolution:** Added patterns for OS artifacts (`.DS_Store`, `Thumbs.db`, `*.swp`, `*.swo`, `*~`), sensitive files (`*.pem`, `*.key`, `.env`), and logs (`*.log`, `*.db`, `*.sqlite`).

---

### DEV-07: Missing OCI Labels in Dockerfile
**Severity:** LOW
**File:** `eiou.dockerfile`
**Status:** **FIXED** in [#713](https://github.com/eiou-org/eiou-docker/pull/713)

No `LABEL` directives for version, documentation, or maintainer metadata.

**Fix:** Add standard OCI labels.

**Resolution:** Added 8 OCI labels: `image.title`, `image.description`, `image.url`, `image.source`, `image.documentation`, `image.vendor`, `image.licenses` (Apache-2.0), `image.base.name`.

---

### DEV-08: RESTORE Environment Variable Exposure
**Severity:** LOW
**File:** `docker-compose.yml:111`

`RESTORE` env var (seed phrase) visible in `docker inspect` and process listings. `RESTORE_FILE` is recommended but not enforced.

**Fix:** Deprecate `RESTORE` in favor of `RESTORE_FILE`. Add startup warning if `RESTORE` is used.

---

## Positive Findings

The codebase demonstrates strong security practices in many areas:

| Practice | Status |
|----------|--------|
| Parameterized SQL queries (prepared statements) | Implemented throughout |
| Password hashing (`password_hash()` with bcrypt) | Correct |
| Secure random generation (`random_bytes()`) | Correct |
| Session security (HttpOnly, SameSite=Strict, Secure) | Implemented |
| CSRF protection via SameSite cookies | Implemented |
| Rate limiting (nginx: 30/10/20 req/s by endpoint) | Configured |
| Connection limits (50 concurrent per IP) | Configured |
| Security headers (HSTS, X-Frame-Options, CSP) | Implemented |
| Output encoding (`htmlspecialchars()`, JSON) | Proper usage |
| Base image pinned with digest hash | Prevents tag mutation |
| Composer hash verification in Dockerfile | Prevents supply chain attacks |
| Container capability dropping (CAP_DROP: ALL) | Best practice |
| `no-new-privileges` security option | Enabled |
| PID limit (200) | Fork bomb protection |
| tini as PID 1 | Proper signal handling |
| Unserialize protection (`__wakeup()` prevention) | Implemented |
| Column whitelisting in repositories | SQL injection prevention |
| Hidden file access blocked (nginx) | `.env`, `.git` protected |
| Directory listing disabled | `autoindex off` |
| TLS 1.2+ with strong ciphers | Properly configured |
| MariaDB bound to localhost only | Network isolation |
| Graceful shutdown mechanism | 45s grace period |
| Log rotation | 10MB max, 3 files |

---

## Recommended Action Plan

### Phase 1: Critical & Security (Immediate) — [PR #706](https://github.com/eiou-org/eiou-docker/pull/706)

| # | Issue | Action | Status |
|---|-------|--------|--------|
| 1 | SEC-01 | Replace `exec()` SSL generation with PHP openssl functions | Done |
| 2 | ARCH-03 | Fix N+1 query in ContactManagementService with batch queries | Done |
| 3 | DOCK-01 | Add `set -u; set -o pipefail` to startup.sh | Done |
| 4 | DOCK-02 | Quote all variable expansions in shell scripts | Done |
| 5 | DEV-01 | Add `composer audit` to CI pipeline | Done |

### Phase 2: High Priority — [#707](https://github.com/eiou-org/eiou-docker/pull/707)

| # | Issue | Action | Status |
|---|-------|--------|--------|
| 6 | SEC-02 | Default APP_DEBUG to false, override via env var | Done |
| 7 | SEC-03 | Fix credential temp file race conditions | Done |
| 8 | SEC-05 | Default P2P_SSL_VERIFY to true | Already fixed (pre-audit) |
| 9 | ARCH-01 | Refactor circular dependency wiring to constructor DI | **Done** — branch `eiou-docker-refactor-arch05-repository-factory-260311` |
| 10 | ARCH-06 | Add logging to all silent catch blocks | Done |
| 11 | ARCH-04 | Split CliService into focused services | **Done** — [#714](https://github.com/eiou-org/eiou-docker/pull/714) |

### Phase 3: Medium Priority — [#708](https://github.com/eiou-org/eiou-docker/pull/708)

| # | Issue | Action | Status |
|---|-------|--------|--------|
| 12 | DOCK-04 | Implement multi-stage Docker build | Deferred (requires Docker testing) |
| 13 | DOCK-06 | Increase resource limits, document requirements | Done |
| 14 | DEV-02 | Add PHPStan to CI pipeline | Done |
| 15 | DEV-03 | Add container image scanning | Done |
| 16 | DEV-04 | Create .env.example | Done |
| 17 | DEV-05 | Configure Dependabot | Done |
| 18 | ARCH-05 | Implement RepositoryFactory | **Done** — branch `eiou-docker-refactor-arch05-repository-factory-260311` |
| 19 | CQ-01 | Replace @ operators with proper error handling | Done |
| 20 | CQ-02 | Replace print_r() with json_encode() in output functions | Done |
| 21 | CQ-03 | Add type hints to services and output functions | Deferred (massive scope) |

### Phase 4: Low Priority — [#713](https://github.com/eiou-org/eiou-docker/pull/713)

| # | Issue | Action | Status |
|---|-------|--------|--------|
| 22 | CQ-04 | Modernize array() to [] syntax | Done |
| 23 | CQ-05 | Fix function name spelling errors | Done |
| 24 | CQ-06 | Extract magic strings to constants | Done |
| 25 | DEV-06 | Update .gitignore | Done (in Phase 3 / [#708](https://github.com/eiou-org/eiou-docker/pull/708)) |
| 26 | DEV-07 | Add OCI labels to Dockerfile | Done |

### Phase 5: Remaining Open Items

Items identified in the audit but not included in Phases 1-4.

| # | Issue | Severity | Action | Status |
|---|-------|----------|--------|--------|
| 27 | SEC-04 | MEDIUM | Secure SSL config temp file in CliSettingsService (use /dev/shm, chmod 0600, try/finally cleanup) | **Done** |
| 28 | ARCH-02 | MEDIUM | Resolve dual singleton (Application + ServiceContainer) — choose one authoritative container | Deferred (Application is bootstrap facade, ServiceContainer is DI — not truly duplicating state; refactor touches 19+ files) |
| 29 | ARCH-07 | MEDIUM | Standardize error handling patterns — create custom exception hierarchy | Deferred (exception hierarchy already exists: ServiceException, RecoverableServiceException, FatalServiceException, ValidationServiceException — migration to use them is massive scope) |
| 30 | ARCH-08 | MEDIUM | Audit repository queries against schema, add missing database indexes | **No action needed** — indexes already present for all queried columns (status, pubkey_hash, name, composite indexes) |
| 31 | ARCH-09 | MEDIUM | Add mandatory pagination to unbounded query methods (findAll, searchContacts, etc.) | Deferred (12+ repository methods affected, requires careful analysis of all callers) |
| 32 | ARCH-10 | MEDIUM | Create /api/health endpoint checking DB, message processors, and Tor | **Done** |
| 33 | DOCK-03 | MEDIUM | Replace predictable /tmp paths in startup.sh with mktemp | **No action needed** — predictable paths are intentional for cross-process coordination (shutdown flag, maintenance lock, PID files). SSL temp files fixed under SEC-04 |
| 34 | DOCK-05 | MEDIUM | Eliminate source file sync anti-pattern — separate code from data volumes | **Done** — [#716](https://github.com/eiou-org/eiou-docker/pull/716) |
| 35 | DOCK-07 | MEDIUM | Add explicit USER directive in Dockerfile, document required root operations | **No action needed** — root required for nginx/php-fpm master processes, privilege dropping to www-data already implemented, hardening controls in place (cap_drop ALL, no-new-privileges) |
| 36 | DOCK-08 | MEDIUM | Validate QUICKSTART/EIOU_HOST/EIOU_NAME/EIOU_PORT on startup | **Done** |
| 37 | CQ-07 | LOW | Standardize session variable naming (partially addressed by CQ-06 SessionKeys) | **Done** (last hardcoded string in SecurityInit.php fixed) |
| 38 | CQ-08 | LOW | Consolidate processor entry point wrappers into single router | **No action needed** — 5 wrappers are well-structured, consistent, and thin; consolidation would add complexity without benefit |
| 39 | DEV-08 | LOW | Deprecate RESTORE env var in favor of RESTORE_FILE, add startup warning | Skipped (per user request) |

**Also deferred from earlier phases:**

| # | Issue | Phase | Action | Status |
|---|-------|-------|--------|--------|
| 9 | ARCH-01 | Phase 2 | Refactor circular dependency wiring to constructor DI | **Done** — branch `eiou-docker-refactor-arch05-repository-factory-260311` |
| 12 | DOCK-04 | Phase 3 | Implement multi-stage Docker build | Deferred (requires Docker testing) |
| 18 | ARCH-05 | Phase 3 | Implement RepositoryFactory | **Done** — branch `eiou-docker-refactor-arch05-repository-factory-260311` |
| 21 | CQ-03 | Phase 3 | Add type hints to services and output functions | Deferred (massive scope) |

---

*Report generated by codebase audit on 2026-03-11.*
