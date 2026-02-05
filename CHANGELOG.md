# Changelog

All notable changes to the EIOU Docker project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This project does not yet follow [Semantic Versioning](https://semver.org/). There are
no version tags. Entries are organized by development period rather than release number.
The project is currently in **ALPHA** status.

---

## [Unreleased]

Changes on development branches not yet merged to main.

---

## 2026-02-01 -- 2026-02-05

Node identity, volume security, and architectural improvements.

### Added
- EIOU_NAME, EIOU_HOST, EIOU_PORT environment variables for address/name separation (#580)
- `eiou start` command to resume processors after shutdown (#577)
- PHPUnit infrastructure for unit testing (#558)
- Comprehensive P2P routing and sync protocol integration tests (#555, #563)

### Changed
- Migrate from Service Locator to Dependency Injection (#562)
- Extract common query patterns into QueryBuilder trait (#561)
- Split ContactService into focused services (#553, #560)
- Fix cross-domain repository access violations (#559)
- Move config files into /etc/eiou/config/ directory (#573)
- Organize /etc/eiou directory structure with processors/ and cli/ subdirectories (#569)
- Standardize error handling patterns across services (#548)
- Resolve circular dependencies with proper dependency injection (#545)
- Split TransactionService into focused services (#512, #544)
- Implement Composer classmap autoloading (#546)

### Fixed
- Prevent watchdog from restarting processors after `eiou shutdown` (#576)
- Apply QUICKSTART hostname after wallet restore (#578)
- Missing namespace imports across GUI controllers and services (#575)
- Remove exit() calls from service methods (#547)

### Security
- Remove /usr/local/bin/ from VOLUME declaration (#568)
- Remove /var/www/html volume, serve web files from /etc/eiou/www (#571)

---

## 2026-01-15 -- 2026-01-31

Encrypted backups, healthchecks, contact status pings, and HTTPS transport.

### Added
- Docker HEALTHCHECK, security hardening, and resource limits (#539)
- Encrypted MariaDB backup and restore system with AES-256-GCM (#497)
- SQL column whitelist validation and security test suite (#537)
- Contact status ping processor with online/chain validation (#465)
- Manual ping capability for contacts with rate limiting
- Recipient signature for transaction acceptance verification (#469)
- Alpha warning banners and seedphrase error validation (#476)
- REST API endpoints for wallet overview and pending contacts (#483)
- Database advisory locks to replace in-memory locking (#542)
- Interfaces for priority services (#492)
- `--show-auth` flag for info command; auth code redacted by default (#503, #504)
- CLI Demo Guide with comprehensive walkthrough (#509)
- Comprehensive documentation across codebase (#473)

### Changed
- Split HTTP and HTTPS address handling (#482)
- Rename Docker image from eioud to eiou/eiou (#478)
- Convert UtilityServiceContainer from singleton to managed service (#490)
- Eliminate Application::getInstance() fallbacks in service getters (#488)
- Move ApiAuthService and ApiKeyService to ServiceContainer (#327)

### Fixed
- GUI balance calculation to exclude pending/relay transactions (#500)
- Seed phrase restore reliability (#496)
- JSON encoding flags to prevent JS syntax errors in GUI (#505)
- Toast refresh message conditional on auto-refresh setting (#508)
- Handle null transportIndex in contact operations (#506)

### Security
- XSS protection improvements across JavaScript innerHTML operations (#484)
- Additional XSS protections from code audit (#466)
- Redact authentication code in `eiou info` output by default (#503)
- Prevent seedphrase exposure in Docker logs (#399)

---

## 2026-01-01 -- 2026-01-14

HTTPS P2P transport, sync reliability, and comprehensive test infrastructure.

### Added
- HTTPS mode for peer-to-peer communication (#438)
- Dynamic SSL certificate generation with IP/hostname SANs (#437)
- SSL certificate regeneration when hostname changes via CLI
- HTTPS mode support in test suite (#441, #442)
- Toggle to enable/disable auto-refresh for pending transactions (#436)
- Graceful shutdown and restart handling for application (#406)
- Atomic transaction claiming and crash recovery (#406)
- Watchdog supervision for processor stability (#430, #432)
- Deterministic chain conflict resolution for simultaneous transactions (#422)
- Transaction chain sync for invalid_previous_txid recovery (#337)
- Comprehensive sync test scenarios (#425, #429)
- Full sync for re-added deleted contacts (#340)
- Hold transactions until resync completes on invalid previous txid (#342)
- Held transaction GUI indicator (#361)
- P2P completion status check before expiring (#360)
- Comprehensive curl error handling test suite (#401)
- Self-send transaction prevention with error handling (#398)
- RATE_LIMIT_ENABLED constant for debugging (#415)
- Debug report download options (full and limited) (#416)
- Help command with detailed API key documentation (#411)
- Automatic app log pruning to keep latest 100 entries (#380)
- Search functionality in debug/app log (#376)

### Changed
- Status constants to replace hardcoded strings across codebase (#356, #358, #359)
- Standardize contact request transaction description (#368)
- Copyright updated from The Vowels Company to Adrien Hubert (#384)

### Fixed
- WSL2 environment timeout compatibility (#431)
- Sync signature verification failures across multiple scenarios (#380, #422)
- Chain fork handling and conflict resolution (#458)
- Tor Browser compatibility for GUI changes (#371)
- Previous_txid chain gaps during resync (#354)
- Processor restart stability (#432)
- Contact request acceptance race conditions (#446, #447)
- Proactive sync when prev_id does not match (#412)
- Increase HTTP timeout and add curl error handling (#401)
- Tor Browser compatibility for GUI and contact operations (#397)

---

## 2025-12-01 -- 2025-12-31

GUI overhaul, contact transactions, and wallet improvements.

### Added
- Self-signed SSL certificate for HTTPS support (#309)
- Deterministic Tor address derivation from seed phrase (#308)
- Deterministic authcode derivation from seedphrase (#325)
- Contact transaction status flow (sent, completed) with receiver-side tracking (#286)
- Pending/in-progress transaction display with auto-refresh (#269)
- Contact modal redesign with tabbed interface, address icons, and transaction details (#259)
- Settings form in GUI with debug section (#246, #257)
- Toast notifications for transaction status (#182)
- Transaction detail modal with status indicators (#181)
- Copy buttons for contact modal info (#276)
- P2P transaction details in recent transactions modal (#276)
- GUI enhancements: mobile buttons, transaction history names, address selector
- Centralized error codes with ErrorCodes class (#272)
- SecureLogger to replace error_log throughout codebase (#272)
- Default values for accept contact form (#237)
- Contact search and display limit (#282)
- Real-time logging and accurate Tor timeout (#328)
- Copyright headers across all files (#312)
- Loading indicators for contact actions and form submissions (#227)
- Rejection payloads with human-readable messages (#224)

### Changed
- PHP files renamed to UpperCamelCase convention (#274)
- API secret stored encrypted, HMAC verification reworked (#283)
- Synch renamed to sync throughout codebase (#215)
- RateLimiter moved to uniform service structure (#237)
- OutputSchema and echoSchema ordered by service type (#237)

### Fixed
- Tor restart after wallet key generation (#323)
- Contact re-add handling with different address types (#302, #306)
- GUI message parsing replaced with JSON output handling (#296)
- Balance sync functionality (#215)
- GUI debug log issues (#291)
- Missing mbstring extension in debug report (#291)
- P2P description privacy handling (#189)

---

## 2025-10-01 -- 2025-11-30

Security hardening, BIP39 wallet, API infrastructure, and service architecture.

### Added
- BIP39 seed phrase generation and wallet restoration (#198)
- AES-256-GCM encryption for private key storage (#143)
- REST API integration infrastructure with HMAC-SHA256 authentication (#145)
- RateLimiter, InputValidator, and Security class integration (#124)
- Process management and graceful shutdown (#141)
- Transaction reliability and message delivery system (#139)
- MessageDeliveryService with synchronous retry and exponential backoff
- Dead letter queue for failed message delivery
- Comprehensive peer testing and automated test runner
- Dynamic address type support in GUI (#192)
- Optional description field for eIOU transactions (#184)
- Tor connection verification in startup sequence
- JSON output format for all CLI commands

### Changed
- Repository pattern and Service layer architecture implemented
- Message processors extracted to classes with utility services (#106, #103)
- ServiceWrappers replaced with direct service calls (#113)
- Global $user replaced with UserContext throughout codebase
- Config files converted from PHP to JSON format
- Payload schemas replaced with classes
- GUI refactored to use UserContext

### Fixed
- CSRF enforcement in all POST handlers (#146)
- SQL injection protection (#160)
- Critical path issues in service initialization
- PDO connection failure handling with RuntimeException (#119)
- Magic numbers replaced with named constants (#57)

### Security
- CSRF enforcement across all POST handlers (#146)
- SQL injection prevention (#160)
- AES-256-GCM private key encryption (#143)
- Comprehensive error handling for database setup

---

## 2025-07-01 -- 2025-09-30

Core transaction system, P2P routing, and contact management.

### Added
- P2P (peer-to-peer) multi-hop payment routing
- RP2P (reverse P2P) relay transaction support
- Transaction expiration and cancellation handling
- Contact blocking and unblocking functionality
- Balance repository with currency support
- User-configurable P2P chain max hops
- Jitter function for message processing timing
- Separate cleanup loop for message management
- Wallet overwrite command

### Changed
- Messages.php split into transaction and cleanup modules
- Sent P2P message handling separated from queued message processing
- Fee percentage rounding improvements

### Fixed
- P2P funds availability checking with RP2P credit
- Previous txid handling for rapid succession transactions
- Contact re-add after deletion
- Transaction rejection reason messaging

---

## 2025-02-21 -- 2025-06-30

Initial project setup and core application development.

### Added
- Docker containerization with PHP, MariaDB, and Apache
- Docker Compose configurations: single node, 4-node line, 10-node line, cluster
- Core wallet generation and management
- Contact request and acceptance system
- Direct eIOU transaction sending and receiving
- Basic P2P routing infrastructure
- Tor hidden service integration
- CLI interface for all node operations
- Web GUI dashboard (wallet.html)
- Persistent storage with named Docker volumes
- Network topology configurations for testing
- Demo files for HTTP and Tor transaction workflows
- README with topology documentation

---

## Notes

- Project repository created 2025-02-21
- Licensed under Apache License 2.0
- Copyright 2025-2026 Vowels Group, LLC
