# Testing Guide

This document describes how to run tests for the eIOU Docker node.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Test Types](#test-types)
3. [Unit Test Inventory](#unit-test-inventory)
4. [Integration Test Inventory](#integration-test-inventory)
5. [Running Unit Tests](#running-unit-tests)
6. [Running Integration Tests](#running-integration-tests)
7. [Test Structure](#test-structure)
8. [Writing New Tests](#writing-new-tests)
9. [Prerequisites](#prerequisites)
10. [Troubleshooting](#troubleshooting)
11. [Continuous Integration](#continuous-integration)

---

## Quick Start

```bash
cd eiou-docker/files

# Install dependencies (first time)
composer install

# Run all tests
composer test

# Run with verbose output (shows test names)
composer test-verbose

# Debug mode (stops on first failure)
composer test-debug
```

## Test Types

### Unit Tests (PHPUnit)

Unit tests validate individual PHP classes and methods in isolation.

- **Location**: `tests/Unit/`
- **Framework**: PHPUnit 11
- **Total**: 5000+ tests, 15000+ assertions across 200+ files in 16 test categories (Api, Cli, Core, Database, Events, Exceptions, Formatters, Gui, Processors, Repositories, Schemas, Security, Services, Startup, Utils, Validators)

### Integration Tests (Shell)

Integration tests validate the complete system behavior using Docker containers.

- **Location**: `tests/testfiles/` (per-test scripts) and `tests/` (runners and benchmarks)
- **Runner**: `./run-all-tests.sh`
- **Coverage**: contact lifecycle, transactions, P2P routing (fast and best-fee modes), sync & chain-integrity, REST API, CLI, backups, identity / wallet setup, process lifecycle, networking, security
- **Inventory**: see [Integration Test Inventory](#integration-test-inventory) below for the per-file table

**Topologies:**
- `http4` / `https4` / `tor4` — 4-node linear chain (standard transaction and routing tests)
- `collisions` — 12-node mesh topology with randomized fees and dead-end nodes (best-fee routing, path selection, deadlock prevention, cascade cancel)

## Unit Test Inventory

> The per-file tables below are a curated overview, not a mechanical
> dump of every test file. The repo's actual count is the authoritative
> number — `composer test -- --list-tests | wc -l` for tests, the
> PHPUnit summary line for assertions. The inventory may lag behind
> when new test files are added; the headings (Security, Utils, …)
> always reflect every directory under `tests/Unit/`.

### Security Tests (`tests/Unit/Security/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **BIP39Test.php** | 22 | Mnemonic generation (12/24 words), validation, seed derivation, key pair generation, auth code derivation |
| **KeyEncryptionTest.php** | 9 | AES-256-GCM encryption availability, info, secure clear, error handling |
| **PayloadEncryptionTest.php** | 17 | ECDH + AES-256-GCM E2E encryption: round-trip, tampering detection, wrong-key rejection, cross-node simulation, encrypt-then-sign workflow, secp256k1 compatibility |
| **TorKeyDerivationTest.php** | 10 | Ed25519 key derivation, .onion address generation, deterministic keys |

### Utils Tests (`tests/Unit/Utils/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **InputValidatorTest.php** | 40+ | All 18 validation methods: amount, currency, address, txid, contact name, fee percent, credit limit, public key, signature, memo, etc. |
| **SecurityTest.php** | 30 | XSS prevention (htmlEncode, jsEncode), input sanitization, password hashing/verification, CSRF tokens, email/URL/IP validation, filename sanitization, timing-safe comparison |
| **AddressValidatorTest.php** | 20 | HTTP/HTTPS/Tor address detection, transport type identification, address categorization |
| **LoggerTest.php** | 30 | Unified Logger facade: interface compliance, singleton, all log levels, logException, context passing, DebugService bridging, exception isolation, sensitive data masking |
| **SecureLoggerTest.php** | 18 | SecureLogger backend: sensitive data masking (passwords, authcodes, API keys, emails, credit cards, SSN, mnemonics), log levels, file rotation |
| **AdaptivePollerTest.php** | 17 | Polling interval calculation, state management, reset, force interval bounds clamping |
| **SecureSeedphraseDisplayTest.php** | 30+ | Secure file display, availability check, TTL, cleanup |

### API Tests (`tests/Unit/Api/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ApiControllerTest.php** | 41 | API endpoint routing, authentication, error handling, all wallet/contacts/system endpoints |

### Core Tests (`tests/Unit/Core/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ErrorCodesTest.php** | 20 | HTTP status mapping, error titles, code validation, constant verification |
| **ConstantsTest.php** | 43 | Application constants validation, hash algorithms, transport modes, status codes |
| **ApplicationTest.php** | 26 | Singleton pattern, service delegation, path getters, CLI mode |
| **DatabaseContextTest.php** | 29 | Config management, DB credentials, initialization state |
| **ErrorHandlerTest.php** | 30 | Error/exception handling, responses, request ID management |
| **UserContextTest.php** | 46 | User data, addresses, wallet validation, config defaults |
| **WalletTest.php** | 19 | Seed extraction, config defaults, hostname validation |

### Exceptions Tests (`tests/Unit/Exceptions/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ServiceExceptionTest.php** | 41 | Service exception hierarchy (ServiceException, FatalServiceException, RecoverableServiceException, ValidationServiceException) |

### Database Tests (`tests/Unit/Database/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **DatabaseSchemaTest.php** | 67 | Schema validation for all 14 tables, column types, constraints, indexes |
| **DatabaseSetupTest.php** | 15+ | Migration execution, column migrations, idempotency |
| **P2pSenderRepositoryTest.php** | 20+ | Multi-path upstream sender tracking for RP2P forwarding |
| **CapacityReservationRepositoryTest.php** | 12 | Capacity reservation CRUD, total reserved queries, release/commit by hash, TTL cleanup |
| **RouteCancellationRepositoryTest.php** | 8 | Route cancellation audit trail, acknowledgment, hash queries, TTL cleanup |
| **PdoConnectionTest.php** | 10+ | Connection creation, DSN format, PDO options |

### Processors Tests (`tests/Unit/Processors/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **AbstractMessageProcessorTest.php** | 30+ | Base processor, signal handling, lockfile management, shutdown |
| **CleanupMessageProcessorTest.php** | 15+ | Cleanup message handling, log intervals, polling config |
| **ContactStatusProcessorTest.php** | 35+ | Ping/pong, address priority (Tor > HTTPS > HTTP), chain validation |
| **P2pMessageProcessorTest.php** | 20+ | P2P message queue processing, fast polling config |
| **TransactionMessageProcessorTest.php** | 20+ | Transaction processing, lockfile paths |

### Repositories Tests (`tests/Unit/Repositories/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **PaymentRequestRepositoryTest.php** | 16 | `createRequest`, `getByRequestId`, `getPendingIncoming`, `getAllIncoming`/`getAllOutgoing` (with limit), `updateStatus` (with extra fields), `countPendingIncoming` (including query-failure → 0) |
| **TransactionRepositoryTest.php** | 4 | Transaction status constants, type constants, hash length validation |
| **AbstractRepositoryTest.php** | 30+ | Base CRUD operations, column validation, transactions, JSON decoding |
| **AddressRepositoryTest.php** | 25+ | Address management, lookups, pubkey hashing, transport types |
| **ApiKeyRepositoryTest.php** | 25+ | API key CRUD, permission checks, rate limit logging |
| **BalanceRepositoryTest.php** | 30+ | Balance operations, sent/received tracking, currency grouping |
| **ContactRepositoryTest.php** | 40+ | Contact management, status transitions, lookups |
| **DeadLetterQueueRepositoryTest.php** | 25+ | DLQ operations, status transitions, statistics |
| **DebugRepositoryTest.php** | 20+ | Debug logging, pruning, log levels |
| **DeliveryMetricsRepositoryTest.php** | 25+ | Delivery metrics tracking, aggregation, cleanup |
| **HeldTransactionRepositoryTest.php** | 44 | Held transaction lifecycle, sync status, retry management |
| **MessageDeliveryRepositoryTest.php** | 38 | Message delivery tracking, retry queue, statistics |
| **P2pRepositoryTest.php** | 48 | P2P request management, status updates, statistics |
| **RateLimiterRepositoryTest.php** | 30 | Rate limiting operations, blocking, cleanup |
| **Rp2pRepositoryTest.php** | 25+ | RP2P request management, queries, cleanup |
| **TransactionChainRepositoryTest.php** | 20+ | Chain integrity verification, gap detection |
| **TransactionContactRepositoryTest.php** | 15+ | Contact transaction queries, balance calculation |
| **TransactionRecoveryRepositoryTest.php** | 20+ | Recovery operations, stuck transactions, claiming |
| **TransactionStatisticsRepositoryTest.php** | 20+ | Transaction statistics, daily counts, type grouping |

### Services Tests (`tests/Unit/Services/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **TransactionServiceTest.php** | 4 | Txid generation algorithm, SHA-256 hashing, determinism |
| **ContactServiceTest.php** | 4 | Contact status constants, name length limits, default settings, online status |
| **ApiAuthServiceTest.php** | 14 | HMAC-SHA256 signature generation, string-to-sign building, request header parsing, client IP detection |
| **RateLimiterServiceTest.php** | 7 | Rate limiting logic, client IP detection (Cloudflare, X-Forwarded-For), test mode bypass |
| **BalanceServiceTest.php** | 23 | Contact balance conversion, user total balance, contact balance retrieval, batch balance operations, currency conversion, edge cases |
| **DatabaseLockingServiceTest.php** | 40 | MySQL advisory locks (GET_LOCK/RELEASE_LOCK/IS_FREE_LOCK), lock acquisition/release, timeout handling, lock name sanitization, held locks tracking |
| **ChainOperationsServiceTest.php** | 16 | Chain integrity verification, previous txid lookup, chain repair coordination, sync service injection, exception handling |
| **ChainVerificationServiceTest.php** | 16 | Chain verification logic, gap detection, conflict resolution |
| **HeldTransactionServiceTest.php** | 35 | Transaction hold/resume lifecycle, sync status tracking, previous txid updates, statistics, event handling, chain integrity checks, P2P expiry-aware resume (status + timestamp), sync timeout vs P2P expiration invariant |
| **TransactionRecoveryServiceTest.php** | 28 | Stuck transaction recovery, manual resolution (retry/cancel/complete), recovery statistics, exception handling |
| **TransactionValidationServiceTest.php** | 35 | Transaction validation logic, required fields, amount validation |
| **BackupServiceTest.php** | 22 | formatBytes utility, getNextScheduledBackup date logic, boundary conditions |
| **ApiKeyServiceTest.php** | 44 | CLI API key management, permission validation |
| **CleanupServiceTest.php** | 23 | Expired message processing, cleanup scheduling |
| **ContactStatusServiceTest.php** | 27 | Ping/pong handling, contact status updates |
| **MessageDeliveryServiceTest.php** | 55 | Message delivery with retries, dead letter queue |
| **WalletServiceTest.php** | 22 | Wallet key operations, key detection |
| **CliServiceTest.php** | 25+ | CLI command handling, output formatting |
| **DebugServiceTest.php** | 15+ | Debug context, error logging setup |
| **MessageServiceTest.php** | 25+ | Message processing, validation, routing |
| **P2pServiceTest.php** | 63 | P2P routing logic, fund availability via capacity reservations, matching, fee calculation |
| **Rp2pServiceTest.php** | 46 | RP2P relay logic, fee calculation, two-phase relay selection, race condition coverage |
| **RouteCancellationServiceTest.php** | 18 | Route cancellation for unselected candidates, partial route_cancel (multi-route safe acknowledge), full cancel (P2P cancel + reservation release + downstream propagation), hop budget (geometric distribution), capacity reservation release |
| **PaymentRequestServiceTest.php** | 26 | Full lifecycle: `create` (amount/currency validation, contact lookup, address resolution, delivery failure non-fatal, tor address priority), `approve` (sendEiou integration, txid extraction, status update, response message), `decline`, `cancel`, `handleIncomingRequest` (idempotent, contact name lookup, invalid currency skip), `handleIncomingResponse` (approved with txid, declined), `getAllForDisplay`, `countPendingIncoming`. Note: 8 `create` tests skip when `bcmath` extension is not installed on the host (they run inside Docker). |
| **SendOperationServiceTest.php** | 20+ | Send operations with locking, message delivery |
| **ServiceContainerTest.php** | 20+ | Singleton pattern, dependency management, lazy loading |
| **SyncServiceTest.php** | 20+ | Synchronization operations, contact/transaction sync |
| **TransactionProcessingServiceTest.php** | 20+ | Transaction processing, claiming, P2P handling |
| **UpdateCheckServiceTest.php** | 32 | Version comparison (`isNewerVersion`), prerelease ordering, v-prefix handling, `getStatus` structure, cache-miss behavior, `markdownToHtml` (headings, lists, bold, italic, inline code, code blocks, links, XSS escaping, horizontal rules, paragraphs, mixed content, list type switching, unclosed code blocks), `shouldShowWhatsNew` (fresh install seeding, upgrade detection, post-dismissal), `dismissWhatsNew` (file structure), `getReleaseNotes` (graceful failure, v-prefix stripping, response structure). Note: filesystem-dependent tests (`shouldShowWhatsNew`, `dismissWhatsNew`) skip outside Docker when `/etc/eiou/config` is not writable; `getReleaseNotes` structure test skips when GitHub is unreachable. |

### Services Proxies Tests (`tests/Unit/Services/Proxies/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **SyncServiceProxyTest.php** | 15+ | Lazy proxy pattern, deferred initialization |

### Services Utilities Tests (`tests/Unit/Services/Utilities/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **CurrencyUtilityServiceTest.php** | 27 | Cents/dollars conversion, currency formatting, fee calculations, minimum fee floor, rounding, large amounts, unknown currency exceptions |
| **TimeUtilityServiceTest.php** | 11 | Microtime conversion, expiration checking, TTL calculations, timestamp precision |
| **GeneralUtilityServiceTest.php** | 15+ | Address truncation, string manipulation |
| **TransportUtilityServiceTest.php** | 25+ | Transport detection, address types, jitter function |
| **UtilityServiceContainerTest.php** | 15+ | Lazy loading container, utility caching |
| **ValidationUtilityServiceTest.php** | 20+ | Request validation, signature verification, funds calculation |

### Service Wrappers Tests (`tests/Unit/Services/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ServiceWrappersTest.php** | 10+ | Output wrapper function, message handling |

### Startup Tests (`tests/Unit/Startup/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ConfigCheckTest.php** | 15+ | Userconfig validation, public key detection |
| **MessageCheckTest.php** | 15+ | Database prerequisite checks, PDO availability |

### CLI Tests (`tests/Unit/Cli/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **CliJsonResponseTest.php** | 24 | RFC 9457 compliant JSON responses, success/error structure, validation errors, pagination, table formatting, transaction responses |
| **CliOutputManagerTest.php** | 20 | Singleton pattern, JSON mode flag parsing, cleanArgv argument filtering, command parsing, fluent interface |

### Events Tests (`tests/Unit/Events/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **EventDispatcherTest.php** | 20 | Singleton pattern, event subscription/unsubscription, listener invocation order, exception handling, listener management |
| **SyncEventsTest.php** | 18 | Sync event constants verification, naming convention compliance, string type validation, reflection-based constant enumeration |

### Formatters Tests (`tests/Unit/Formatters/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **TransactionFormatterTest.php** | 14 | Amount conversion (cents to dollars), transaction history formatting, counterparty detection, contact formatting |

### GUI Tests (`tests/Unit/Gui/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **FunctionsTest.php** | 20+ | Router, view data initialization, XSS prevention, action routing |
| **Includes/SessionTest.php** | 40+ | Authentication, CSRF tokens, flash messages, session timeout |

### GUI Helpers Tests (`tests/Unit/Gui/Helpers/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ContactDataBuilderTest.php** | 20 | Contact data building, address type handling, primary address priority (Tor > HTTPS > HTTP), JSON encoding, HTML-safe output, status handling, Unicode support |
| **MessageHelperTest.php** | 62 | Message parsing, formatting, HTML encoding |
| **ViewHelperTest.php** | 54 | View rendering helpers, template processing |

### GUI Controllers Tests (`tests/Unit/Gui/Controllers/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ContactControllerTest.php** | 25+ | Contact CRUD actions, CSRF verification, validation |
| **SettingsControllerTest.php** | 25+ | Settings management, input validation, JSON export |
| **TransactionControllerTest.php** | 20+ | Transaction actions, recipient handling |

### Schema Tests (`tests/Unit/Schemas/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **OutputSchemaTest.php** | 25+ | Debug/logging output for all message types |

### Validator Tests (`tests/Unit/Validators/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **PaybackMethodTypeValidatorTest.php** | 30+ | Payback method type catalog: required-keys check, sibling-field references in `show_when`, currency-code references; field-by-field validation (text, mod-97 IBAN, ABA routing checksum, free-text custom rails); plugin-registered rail types. |
| **Checksum/** | varies | Checksum-validator helpers (IBAN mod-97, ABA routing) consumed by `PaybackMethodTypeValidator`. |

### Schema/Payload Tests (`tests/Unit/Schemas/Payloads/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **BasePayloadTest.php** | 53 | ensureRequiredFields validation, sanitizeString, sanitizeNumber type handling, validate empty check, edge cases |
| **ContactPayloadTest.php** | 26 | Contact creation/received/updated/rejection/pending/mutually-accepted payloads, filterAddresses, senderAddresses in creation payload, JSON encoding |
| **ContactStatusPayloadTest.php** | 53 | Ping/pong payloads, status responses |
| **MessagePayloadTest.php** | 20 | Contact inquiry/accepted/unknown payloads, transaction status/sync responses, P2P status inquiry/response |
| **P2pPayloadTest.php** | 50+ | P2P request payloads, validation |
| **Rp2pPayloadTest.php** | 54 | Return P2P payloads, relay routing |
| **TransactionPayloadTest.php** | 77 | Transaction payloads, all transaction types |
| **UtilPayloadTest.php** | 77 | Utility/error payloads, acknowledgments |

## Integration Test Inventory

> The tables below are a curated overview of the shell-based integration
> test suite under `tests/testfiles/`. The repo's actual count is the
> authoritative number — `ls tests/testfiles/*.sh | wc -l` for files and
> the `run-all-tests.sh` summary line for individual test counts. The
> inventory may lag behind when new test files are added; the headings
> always reflect the current categorization.

### Contact Lifecycle (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **addContactsTest.sh** | Contact addition workflow between containers — request, accept, status transitions |
| **mutualContactTest.sh** | Mutual-contact request auto-accept feature (both sides issue `add` simultaneously) |
| **contactListTest.sh** | Contact list storage, ordering, and per-contact metadata verification |
| **contactNameTest.sh** | Multi-part contact names and duplicate-name disambiguation across the API/CLI |

### Transactions & Balances (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **transactionTestSuite.sh** | Consolidated transaction tests: history, inquiry response, contact-tx type, chain reorder under cancellations, held-tx for invalid `previous_txid`, self-send prevention |
| **transactionRecoveryTest.sh** | Atomic transaction claiming and crash-recovery mechanisms |
| **balanceTest.sh** | Balance queries and verification across all containers in the topology |
| **sendMessageTest.sh** | Message sending between connected contacts |
| **sendAllPeersTest.sh** | Transaction sending to all connected peers in the network |
| **negativeFinancialTest.sh** | Negative / error paths for financial operations (over-credit, double-spend, malformed amounts) |

### P2P Routing (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **routingTest.sh** | Multi-hop message routing and relay-fee calculation |
| **bestFeeRoutingTest.sh** | Best-fee P2P route selection: single-node, 4-line, 12-collision topologies; fast-vs-best timing, path analysis with randomized fees, dead-end cascade cancel |
| **routeCancellationTest.sh** | Route-cancellation service wiring, capacity-reservation table, hop-budget distribution (constant under `EIOU_HOP_BUDGET_RANDOMIZED=false`, variance when randomized), reservation create/release, originator downstream cancel via `broadcastFullCancelForHash`, multi-route safety with `full_cancel` |
| **cascadeCancelTest.sh** | Cascade cancel / expire for dead-end P2P routes |
| **maxLevelCancelTest.sh** | Nodes at the P2P max-level boundary immediately cancel and notify the originator |

### Sync & Chain Integrity (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **syncTestSuite.sh** | Consolidated sync: basic sync command, transaction-chain recovery, signature-validation stop, multi-cycle resilience, cancelled-tx handling, `NULL previous_txid` edge cases |
| **chainDropTestSuite.sh** | Tx-drop agreement protocol for resolving mutual chain gaps (propose / accept / reject; auto-propose; balance-guard) |
| **chunkedSyncTest.sh** | Chunked transaction-sync protocol behavior at large chain lengths |

### REST API & Payment Requests (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **apiEndpointsTest.sh** | REST API endpoints — happy-path coverage across the v1 surface |
| **apiInputValidationTest.sh** | Missing-required-field 400s, invalid types, boundary values, special-character / encoding safety |
| **paymentRequestTest.sh** | Full payment-request lifecycle via REST: list, create, outgoing-pending, incoming-delivery (async-safe), decline, cancel, invalid-create, full **approve flow** (request → poll → approve → sendEiou → balance verify), **approve-with-payer-note** (description shape `"payment: <req desc> \| <payer note>"`), over-long payer-note rejection. Permission-gated: `wallet:read` for list/create/decline/cancel, `wallet:send` for approve |

### CLI (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **cliCommandsTest.sh** | CLI commands produce correct output in both regular and JSON modes |

### Backup (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **backupTestSuite.sh** | Encrypted backup create / list / verify / restore / status; auto-backup toggle; retention per prefix |

### Identity & Wallet Setup (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **nodeIdentityTest.sh** | `EIOU_NAME`, `EIOU_HOST`, `EIOU_PORT` environment-variable handling and userconfig effects |
| **hostnameTest.sh** | Hostname configuration in `userconfig.json` matches expected values |
| **seedphraseTestSuite.sh** | Seedphrase generate / restore, secure display, authcode restoration, restore + `EIOU_HOST` hostname application |
| **sslCertificateTest.sh** | SSL certificate generation and HTTPS functionality |

### Process & Lifecycle (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **gracefulShutdownTest.sh** | Graceful shutdown handling for eIOU Docker containers (processors stop cleanly, lockfiles cleared) |
| **sigTermTest.sh** | SIGTERM graceful shutdown via `docker stop` |
| **processorLockfileTest.sh** | Processor lockfile fix preventing random restart loops |
| **performanceBaseline.sh** | Performance baseline: transaction processing time, batch throughput, API response times, DB query performance |

### Networking & Messaging (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **messageDeliveryTest.sh** | `MessageDeliveryService` and Dead Letter Queue functionality |
| **parallelBroadcastTest.sh** | `curl_multi` parallel-broadcast functionality in P2P and transport layers |
| **pingTestSuite.sh** | Contact-status ping feature, online detection, response time, chain-head exchange |
| **curlErrorHandlingTest.sh** | HTTP-client error handling and timeout behavior |

### Tor (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **torTestSuite.sh** | Consolidated TOR: address verification, restart verification, key-file permissions, rapid-restart resilience |

### Code Quality & Static Checks (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **circularDependencyCheck.sh** | Static analysis: parses PHP service files for constructor & setter-injection deps, builds a dependency graph, DFS-detects cycles |
| **serviceExceptionTest.sh** | `ServiceException` hierarchy and error handling |
| **serviceInterfaceTest.sh** | Verifies all services properly implement their declared interfaces |

### Security (`tests/testfiles/`)

| Test File | Coverage |
|-----------|----------|
| **securityTestSuite.sh** | SQL-injection protection on API endpoints, XSS payload handling and sanitization, authentication-header manipulation, rate-limit enforcement |

### Benchmarks (`tests/`)

Standalone benchmark scripts in `tests/` (not run by `./run-all-tests.sh`):

| Test File | Coverage |
|-----------|----------|
| **benchmark-routing.sh** | Routing throughput and latency measurements |
| **benchmark-bestfee.sh** | Best-fee mode latency vs fast mode and route-fan-out cost |

## Running Unit Tests

### Configuration Files

- `tests/phpunit.xml.dist` - PHPUnit 11 configuration template (tracked in git)
- `tests/phpunit.xml` - Local configuration override (in .gitignore)

The `.dist` file is the tracked template. Copy it to `phpunit.xml` for local customization:
```bash
cp tests/phpunit.xml.dist tests/phpunit.xml
```

### Composer Commands

```bash
cd eiou-docker/files

# Run all tests
composer test

# Verbose output with readable test names
composer test-verbose

# Debug mode - stops on first failure
composer test-debug

# With coverage report (requires Xdebug/PCOV)
composer test-coverage

# Pass custom PHPUnit flags
composer test -- --filter=BIP39
composer test -- --stop-on-failure -v
composer test -- --testdox --group=security
```

### Using Docker (No Local PHP Required)

```bash
cd eiou-docker

# Install dependencies
docker run --rm -v "$(pwd)":/app -w /app/files composer:latest install

# Run all tests
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit --configuration tests/phpunit.xml

# Run specific test file
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit tests/Unit/Security/BIP39Test.php

# Run with verbose output
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit --configuration tests/phpunit.xml --testdox
```

### Running the suite inside an eiou node (closes most env-gated skips)

A handful of unit tests guard on environment state that only exists inside
an eiou container — `php-bcmath` loaded, `/app/eiou/` populated, `/etc/eiou/config/`
present and writable, the encryption-key file layout. Outside that
environment they `markTestSkipped()` and **this is normal, expected
behaviour** for host-side runs. To exercise that coverage:

```bash
# 1) Start a node (adds bcmath, /app/eiou, /etc/eiou/config, network)
docker compose up -d --build

# 2) Run the wrapper. Three modes; "all" runs them in sequence.
tests/run-unit-tests-docker.sh all          # node + fresh + fresh-key
tests/run-unit-tests-docker.sh node         # against running eiou-node only
tests/run-unit-tests-docker.sh fresh        # empty /etc/eiou/config (first-boot path)
tests/run-unit-tests-docker.sh fresh-key    # stub .master.key.enc for the volume-encryption presence path

# Forwarded args go to phpunit:
tests/run-unit-tests-docker.sh node --filter=ApplicationTest
```

Each mode covers a different class of skip:

| Mode        | What it adds                                      | Tests it unlocks                                         |
| ----------- | ------------------------------------------------- | -------------------------------------------------------- |
| `node`      | bcmath + DB + populated `/etc/eiou/config`        | `ApplicationTest` (27), bcmath gates (~14), `UserContextTest` config tests (3), `UpdateCheckServiceTest` config tests (~16), the InputValidator-routed currency checks |
| `fresh`     | empty `/etc/eiou/config` + clean `/dev/shm` tmpfs | `VolumeEncryptionTest` / `MariaDbEncryptionTest` / `KeyEncryptionTest` first-boot bootstrap paths (~9) |
| `fresh-key` | stub `.master.key.enc` present in tmpfs config    | `VolumeEncryptionTest::testInitThrowsWhenEncryptedKeyExistsWithoutPassphrase` (the encrypted-key-present branch of the same suite) |

#### Skips you'll still see — and why they're fine

These never run on a plain host and shouldn't be treated as failures:

- **bcmath gates** (`InputValidatorTest`, `ContactValidatorTest`,
  `CurrencyUtilityServiceTest`, `SettlementPrecisionServiceTest`,
  `PaymentRequestServiceTest`, `ContactDecisionServiceTest`,
  `ContactManagementServiceTest`, `TransactionFormatterTest`) — skip
  unless `bcmath` is loaded. Production and CI both have it.
- **Docker-environment gates** (`ApplicationTest` × 27, `UserContextTest`
  × 3, `UpdateCheckServiceTest` × ~16) — skip when `/app/eiou/` or
  `/etc/eiou/config/` aren't present/writable.
- **Encryption presence/absence** (`VolumeEncryptionTest`,
  `MariaDbEncryptionTest`, `KeyEncryptionTest`) — split by intent:
  some assert the *first-install* path (file absent), others the
  *active-session* path (file present). Any single environment will
  skip one of the two halves.
- **PHP build features** (`PayloadEncryptionTest` × 2,
  `E2eAllMessagesTest`, `E2eContactDescriptionTest`) — skip if the
  linked OpenSSL lacks `secp256k1` or PHP lacks `openssl_pkey_derive` /
  `hash_hkdf`. Production builds have both.
- **Maintenance lockfile** (`MaintenanceCheckTest`) — only skips when
  `/tmp/eiou_maintenance.lock` is present (loading the script during a
  maintenance window would call `exit()`).
- **Network** (`UpdateCheckServiceTest::testGetReleaseNotesReturnsExpectedKeysWhenAvailable`)
  — needs to reach github.com.

The wrapper above + a real network connection covers everything except the
maintenance-lockfile guard. If you want a single number to track,
`tests/run-unit-tests-docker.sh all` should bottom out at **≤ 5 skips**
total — anything more means a new environment guard slipped in.

### Running Specific Tests

```bash
# Run a single test file
composer test -- tests/Unit/Utils/InputValidatorTest.php

# Run tests matching a pattern
composer test -- --filter=testValidateAmount

# Run a specific test class
composer test -- --filter=BIP39Test

# Run tests in a directory
composer test -- tests/Unit/Security/
```

## Running Integration Tests

```bash
cd eiou-docker/tests

# Run all integration tests against 4-node topology
./run-all-tests.sh http4

# Run specific test suite
./run-all-tests.sh http4 transactions

# View available test suites
./run-all-tests.sh --help
```

### Integration Test Environment Variables

All buildfiles pass these env vars to containers (with test defaults):

| Variable | Test Default | Purpose |
|----------|-------------|---------|
| `EIOU_CONTACT_STATUS_ENABLED` | `true` | Enable/disable contact status pinging |
| `EIOU_TOR_FORCE_FAST` | `true` | Force fast mode for Tor routes |
| `EIOU_DEFAULT_TRANSPORT_MODE` | `http` | Transport mode (`http`/`https`/`tor`). Tests use `http` to avoid Tor's force-fast overriding best-fee mode |
| `EIOU_HOP_BUDGET_RANDOMIZED` | `false` | Disable hop budget randomization for deterministic routing depth assertions |

Override from the parent shell:
```bash
EIOU_HOP_BUDGET_RANDOMIZED=true ./run-all-tests.sh http4
```

## Test Structure

```
tests/
├── bootstrap.php              # PHPUnit bootstrap - sets up test environment:
│   │                          #   - EIOU_TEST_MODE constant (true during tests)
│   │                          #   - PSR-4 namespace Eiou\Tests\ autoloading
│   │                          #   - Mocked output() function for suppressing logs
├── phpunit.xml.dist           # PHPUnit configuration template (tracked in git)
├── run-all-tests.sh           # Integration test runner
├── Unit/                      # PHPUnit unit tests
│   ├── Cli/
│   │   ├── CliJsonResponseTest.php
│   │   └── CliOutputManagerTest.php
│   ├── Core/
│   │   ├── ConstantsTest.php
│   │   └── ErrorCodesTest.php
│   ├── Database/
│   │   ├── CapacityReservationRepositoryTest.php
│   │   ├── DatabaseSchemaTest.php
│   │   ├── P2pSenderRepositoryTest.php
│   │   └── RouteCancellationRepositoryTest.php
│   ├── Events/
│   │   ├── EventDispatcherTest.php
│   │   └── SyncEventsTest.php
│   ├── Exceptions/
│   │   └── ServiceExceptionTest.php
│   ├── Formatters/
│   │   └── TransactionFormatterTest.php
│   ├── Gui/
│   │   ├── Controllers/
│   │   │   ├── ContactControllerTest.php
│   │   │   ├── SettingsControllerTest.php
│   │   │   └── TransactionControllerTest.php
│   │   └── Helpers/
│   │       ├── ContactDataBuilderTest.php
│   │       ├── MessageHelperTest.php
│   │       └── ViewHelperTest.php
│   ├── Repositories/
│   │   ├── AbstractRepositoryTest.php
│   │   ├── AddressRepositoryTest.php
│   │   ├── ApiKeyRepositoryTest.php
│   │   ├── BalanceRepositoryTest.php
│   │   ├── ContactRepositoryTest.php
│   │   ├── DeadLetterQueueRepositoryTest.php
│   │   ├── DebugRepositoryTest.php
│   │   ├── DeliveryMetricsRepositoryTest.php
│   │   ├── HeldTransactionRepositoryTest.php
│   │   ├── MessageDeliveryRepositoryTest.php
│   │   ├── P2pRepositoryTest.php
│   │   ├── RateLimiterRepositoryTest.php
│   │   ├── Rp2pRepositoryTest.php
│   │   ├── TransactionChainRepositoryTest.php
│   │   ├── TransactionContactRepositoryTest.php
│   │   ├── TransactionRecoveryRepositoryTest.php
│   │   ├── TransactionRepositoryTest.php
│   │   └── TransactionStatisticsRepositoryTest.php
│   ├── Schemas/
│   │   └── Payloads/
│   │       ├── BasePayloadTest.php
│   │       ├── ContactPayloadTest.php
│   │       ├── ContactStatusPayloadTest.php
│   │       ├── MessagePayloadTest.php
│   │       ├── P2pPayloadTest.php
│   │       ├── Rp2pPayloadTest.php
│   │       ├── TransactionPayloadTest.php
│   │       └── UtilPayloadTest.php
│   ├── Security/
│   │   ├── BIP39Test.php
│   │   ├── KeyEncryptionTest.php
│   │   ├── PayloadEncryptionTest.php
│   │   └── TorKeyDerivationTest.php
│   ├── Services/
│   │   ├── ApiAuthServiceTest.php
│   │   ├── ApiKeyServiceTest.php
│   │   ├── BackupServiceTest.php
│   │   ├── BalanceServiceTest.php
│   │   ├── ChainOperationsServiceTest.php
│   │   ├── ChainVerificationServiceTest.php
│   │   ├── CleanupServiceTest.php
│   │   ├── CliServiceTest.php
│   │   ├── ContactServiceTest.php
│   │   ├── ContactStatusServiceTest.php
│   │   ├── DatabaseLockingServiceTest.php
│   │   ├── DebugServiceTest.php
│   │   ├── HeldTransactionServiceTest.php
│   │   ├── MessageDeliveryServiceTest.php
│   │   ├── MessageServiceTest.php
│   │   ├── P2pServiceTest.php
│   │   ├── RateLimiterServiceTest.php
│   │   ├── RouteCancellationServiceTest.php
│   │   ├── Rp2pServiceTest.php
│   │   ├── SendOperationServiceTest.php
│   │   ├── ServiceContainerTest.php
│   │   ├── SyncServiceTest.php
│   │   ├── TransactionProcessingServiceTest.php
│   │   ├── TransactionRecoveryServiceTest.php
│   │   ├── TransactionServiceTest.php
│   │   ├── TransactionValidationServiceTest.php
│   │   ├── WalletServiceTest.php
│   │   ├── Proxies/
│   │   │   └── SyncServiceProxyTest.php
│   │   └── Utilities/
│   │       ├── CurrencyUtilityServiceTest.php
│   │       └── TimeUtilityServiceTest.php
│   ├── Utils/
│   │   ├── AddressValidatorTest.php
│   │   ├── AdaptivePollerTest.php
│   │   ├── InputValidatorTest.php
│   │   ├── LoggerTest.php
│   │   ├── SecureLoggerTest.php
│   │   └── SecurityTest.php
│   └── Validators/
│       ├── Checksum/
│       └── PaybackMethodTypeValidatorTest.php
└── ...                        # Integration test scripts (see tests/README.md)
```

## Writing New Tests

### Unit Test Template

```php
<?php
/**
 * Unit Tests for YourClass
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\YourClass;

#[CoversClass(YourClass::class)]
class YourClassTest extends TestCase
{
    /**
     * Test description of what is being tested
     */
    public function testMethodNameWithScenario(): void
    {
        // Arrange
        $input = 'test-input';

        // Act
        $result = YourClass::doSomething($input);

        // Assert
        $this->assertEquals('expected', $result);
    }

    /**
     * Test error handling
     */
    public function testMethodThrowsOnInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected error message');

        YourClass::doSomething('invalid');
    }
}
```

### Test Naming Conventions

- **Test classes**: `{ClassName}Test.php`
- **Test methods**: `test{MethodName}{Scenario}`
  - `testValidateAmountWithPositiveValue`
  - `testValidateAmountWithNegativeValue`
  - `testValidateAmountThrowsOnInvalidInput`
- Use descriptive names that explain what is being tested

### Common Assertions

```php
// Equality
$this->assertEquals($expected, $actual);
$this->assertSame($expected, $actual);  // Strict type comparison
$this->assertNotEquals($a, $b);

// Boolean
$this->assertTrue($value);
$this->assertFalse($value);

// Types
$this->assertIsString($value);
$this->assertIsArray($value);
$this->assertIsInt($value);
$this->assertIsBool($value);

// Strings
$this->assertStringContainsString('needle', $haystack);
$this->assertStringStartsWith('prefix', $string);
$this->assertMatchesRegularExpression('/pattern/', $string);

// Arrays
$this->assertArrayHasKey('key', $array);
$this->assertCount(3, $array);
$this->assertContains($value, $array);

// Null
$this->assertNull($value);
$this->assertNotNull($value);

// Exceptions
$this->expectException(ExceptionClass::class);
$this->expectExceptionMessage('message');
```

## Prerequisites

### Local PHP Setup

**Ubuntu/Debian:**
```bash
sudo apt-get install php8.3-cli php8.3-xml php8.3-mbstring php8.3-sodium
```

**macOS (Homebrew):**
```bash
brew install php  # Includes all required extensions
```

**Windows:**
Ensure these extensions are enabled in `php.ini`:
- `extension=dom`
- `extension=mbstring`
- `extension=xml`
- `extension=sodium`

### Required Extensions

| Extension | Required For |
|-----------|--------------|
| `dom` | PHPUnit XML parsing |
| `mbstring` | String handling |
| `sodium` | TorKeyDerivation tests |
| `openssl` | KeyEncryption, PayloadEncryption, BIP39 tests |

## Troubleshooting

### "Composer autoloader not found"

```bash
cd files
composer install
```

### "ext-dom is missing"

```bash
# Ubuntu/Debian
sudo apt-get install php8.3-xml

# macOS
brew reinstall php
```

### "Class not found" errors

```bash
cd files
composer dump-autoload
```

### Tests fail with "Sodium extension required"

```bash
# Ubuntu/Debian
sudo apt-get install php8.3-sodium

# macOS (usually included)
brew reinstall php
```

### Docker permission errors

```bash
# Add user to docker group
sudo usermod -aG docker $USER
# Log out and back in
```

### See detailed error output

```bash
# Show full error details
composer test-debug

# Or with PHPUnit flags
composer test -- --stop-on-failure -v
```

## Continuous Integration

Unit tests run automatically on:
- Pull request creation
- Push to feature branches
- Merge to main branch

**Requirement**: All tests must pass before merging PRs.
