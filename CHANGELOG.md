# Changelog

All notable changes to the EIOU Docker project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This project does not yet follow [Semantic Versioning](https://semver.org/). There are
no version tags. Entries are organized by development period rather than release number.
The project is currently in **ALPHA** status.

---

## [Unreleased]

### Changed
- `P2P_MAX_ROUTING_LEVEL` reduced from 20 to 10 (max hops a user can configure); hopWait formula now uses separate `P2P_HOP_WAIT_DIVISOR` (fixed at 20) to preserve privacy

### Added
- Best-fee routing mode (`--best` flag): collects all RP2P responses and selects the lowest accumulated fee route (experimental)
- `rp2p_candidates` table for storing RP2P candidate responses during best-fee selection
- `p2p_senders` table for tracking all upstream P2P senders in multi-path routing
- Multi-path RP2P forwarding: relay nodes forward RP2P responses to all upstream senders, not just the first
- Per-hop expiration for best-fee mode: leaf nodes expire first, cascading selection upstream
- Orphaned candidate recovery: `CleanupService` triggers best-fee selection when P2P expires with available candidates
- `contacts_sent_count`, `contacts_responded_count`, `hop_wait`, and `fast` columns on `p2p` table for routing mode tracking

### Changed
- Collision topology test fees randomized (0.1-0.9) per run so best-fee routing is verified against varying fee structures
- Best-fee path analysis labels tied optimal routes as `[TIED BEST]` and prints the randomized fee structure
- Best-fee routing test shows timing comparison between fast mode and best-fee mode
- Best-fee path analysis uses compound fee multiplier `(1 + fee/100)` product instead of additive sum; destination hop excluded from fee calculation
- Best-fee routing test traces actual path for both fast and best-fee modes with a side-by-side path comparison
- Best-fee routing test timeouts are transport-mode aware: HTTP uses 120s P2P expiration (was 60s), Tor uses 180s (was 120s), sized to guarantee 30s+ gap between closest relay and originator even with maxLevel jitter
- Best-fee routing test timeout includes grace period + headroom (180s for HTTP, 240s for Tor) to prevent false failures at the boundary
- `run-all-tests.sh` supports `SKIP_CLEANUP=1` environment variable to keep containers alive after test completion
- Best-fee routing GUI checkbox now displays a prominent warning-styled "Experimental" label instead of a subtle info note
- Best-fee routing CLI `--best` flag help text emphasises experimental status with `[EXPERIMENTAL]` prefix
- GUI notifications use session flash messages instead of URL parameters; messages no longer re-appear on page refresh
- GUI shows toast notification when receiving transactions ("Payment Received" with amount and sender name)
- GUI recent transactions list shows description instead of counterparty address; click to view full details with address in modal (#589)
- GUI chain drop terminology changed from "chain drop" to "drop missing transaction(s)" in all user-facing text
- GUI Check Status (ping) now reloads the page and reopens the contact modal so updated values persist
- GUI chain drop propose/accept/reject actions reload page and reopen contact modal after completion

### Added
- GUI chain drop proposal badges on contact cards: red "Action Required" (incoming), blue "Awaiting Response" (outgoing), orange "Blocked" (rejected)
- GUI chain status badge in contact modal is now proposal-aware: shows "Action Required", "Awaiting Acceptance", or "Blocked" with clickable scroll to chain drop section
- GUI chain drop resolution section in contact modal with four states: propose, awaiting acceptance, incoming (accept/reject), rejected (repropose)
- GUI notification banner for incoming chain drop proposals at top of page (red alert, similar to pending contacts banner)
- GUI funds warning on all chain drop UI sections: dropping a transaction removes its transferred funds from both balances
- Auto-propose chain drop when ping/Check Status detects mutual gaps after sync (ContactStatusService → ChainDropService)
- Wallet restore contact re-establishment: ping from unknown contact auto-creates pending contact and triggers sync to restore transaction chain (handles seed-only restore without backups)
- GUI "Prior Contact" badge on pending contact cards when transaction history exists (distinguishes restored contacts from fresh requests)
- GUI pending contacts notification banner shows count of prior contacts with existing history
- Balance recalculation after chain drop execution via `SyncTriggerInterface::syncContactBalance()`
- Chain status (`valid_chain`) updated to valid after successful chain drop execution on both sides
- Chain drop agreement protocol for resolving mutual transaction chain gaps with two-party consent
- Chain drop integration test suite (`chainDropTestSuite.sh`) with 4 scenarios: single gap, non-consecutive gaps, consecutive gaps, and rejection flow
- Backup recovery during sync: SyncService checks local backups for missing transactions before contacting remote, and includes a `missingTxids` field so the remote side can check its backups too — both sides repair in a single round trip
- Chain drop backup checks demoted to fallback safety net (sync-level recovery handles the primary path)
- GUI wallet header displays "₳ Wallet of [name]" after login; name hidden from page titles and login screen to prevent identity leakage via Tor (#587)
- Upgrade guide documentation (`docs/UPGRADE_GUIDE.md`)
- Unified `LoggerInterface` and `Logger` facade for consolidated logging (#557)
- Full codebase migration from `SecureLogger` to `Logger` across 46 source files (#557)
- `LoggerInterface` contract for dependency injection and testability (#557)

### Fixed
- Best-fee originator slow fallback when candidates exist at expiration: `expireMessage()` now triggers best-fee selection for originators (not just relays) when candidates are available at P2P expiration time, avoiding the extra 30s grace period wait
- Best-fee originator selecting suboptimal route: originator triggered immediate route selection on the first candidate after P2P expiration — both in `handleRp2pCandidate` (late arrivals) and in `expireMessage` step 1.5 (cleanup cycle). Now only relay nodes select immediately on expiration; originators wait for all contacts or fall back via cleanup after a grace period
- P2P `sender_address` not updated at end recipient in multi-path routing: when the RP2P-selected route differs from the P2P propagation path, the end recipient's P2P record retained the original propagation sender instead of the actual transaction sender; relay nodes already had this fix
- Best-fee relay expiration exceeding originator timeout: relay nodes computed `hopWait * remainingHops` which could far exceed the originator's P2P expiration (e.g. 75-285s vs 60s). Relay expiration is now capped to `upstreamExpiration - hopWait` with a minimum viability floor of `P2P_HOP_PROCESSING_BUFFER_SECONDS` (2s)
- Best-fee P2P re-expiration after selection: after `selectAndForwardBestRp2p()` ran during cleanup, the next cleanup cycle could re-process the same P2P because `getExpiredP2p()` didn't exclude `'found'` status. Now sets status to `'found'` after selection and excludes `'found'` from expired P2P queries
- Best-fee mode `contacts_sent_count` not set on direct-match path: relay nodes with the end-recipient as a direct contact used the `matchContact` shortcut without tracking sent count, blocking the "all contacts responded" trigger and forcing per-hop expiration fallback. Now counts both `inserted` and `already_relayed` from the destination (terminal node, no circular dependency risk)
- Best-fee mode circular RP2P deadlock in mesh topologies: broadcast path counted `already_relayed` contacts in `contacts_sent_count`, causing nodes to wait for each other indefinitely (A waits for B's RP2P, B waits for A's RP2P). Now only counts `inserted` contacts in the broadcast path; `already_relayed` RP2P responses are still accepted as bonus candidates if they arrive before selection
- Best-fee mode leaf relay nodes blocked with `contacts_sent_count=0`: tracking check required `contacts_sent_count > 0`, preventing leaf relays (that only forwarded to the destination) from ever triggering the "all responded" path. Removed the `> 0` guard so `contacts_responded_count >= 0` correctly triggers immediate forwarding
- P2P `sender_address` not updated when relay receives transaction from a different upstream node than the original P2P sender (multi-path routing)
- Contact request transactions now use dual-signature protocol: recipient signs `{'type':'create','nonce':N}` on acceptance, matching the sender's signature; `verifyRecipientSignature()` uses `reconstructContactSignedMessage()` for contact transactions instead of bypassing verification
- Balance not recalculating after accepting a restored prior contact: `acceptContact()` now calls `syncContactBalance()` after `insertInitialContactBalances()` to recalculate from synced transactions
- Chain status stuck on "Needs Sync" after accepting chain drop proposal: `valid_chain` now updated after execution
- Balance not recalculating after chain drop: `syncContactBalance()` now called after `executeChainDrop()`
- Rejected chain drop proposals not visible in GUI: `getRecentRejected()` query added to data loading
- Stale rejected proposal blocking GUI after newer proposal accepted: `NOT EXISTS` clause filters superseded rejections
- Chain gap detection not triggering on send: `verifySenderChainAndSync()` skipped re-verification when sync reported success, missing mutual gaps where both sides lack the same transactions
- Chain gap detection not triggering on sync: `syncTransactionChain()` unconditionally returned success without verifying chain integrity after sync completion
- Chain gap detection not triggering on ping: `handlePingRequest()` and `pingContact()` only compared chain heads (last txid), missing internal gaps when both sides had same chain head
- CLI sync not reporting chain gaps: `syncAllTransactionsInternal()` now includes gap count and chaindrop guidance in sync results
- `proposeChainDrop()` signature mismatch: interface/service had 4-param signature but CLI/GUI called with 1 param; changed to auto-detect signature accepting `contactPubkeyHash`
- Message delivery retry queue not processed: `processRetryQueue()` was never called from any background processor; wired into CleanupMessageProcessor
- Config file migration in startup.sh for upgrades from pre-#573 images (config moved from `/etc/eiou/` to `/etc/eiou/config/`)
- Composer dependency installation on upgrade: `startup.sh` now runs `composer install` instead of `dump-autoload` to install new dependencies
- Composer lock file sync: `composer.lock` now included in source file backup for deterministic dependency installation
- Startup authcode display: create secure temp file on every container start, not just initial generation
- GUI Total Balance incorrectly included rejected, expired, and cancelled transactions
- Balance sync operations (`syncContactBalance`, `syncAllBalances`) counted non-completed transactions
- `TransactionContactRepository` contact balance queries missing status filter
- `getAllContactBalances` unhandled PDOException on query failure
- Dead `if(!$stmt)` checks after `pdo->prepare()` replaced with proper try/catch across 4 repository files
- AJAX requests returning HTML login form instead of JSON when session expired (debug report download error)

### Docs
- Document best-fee routing `best_fee` parameter in API_REFERENCE, API_QUICK_REFERENCE, GUI_REFERENCE, GUI_QUICK_REFERENCE
- Add `--best` flag and best-fee routing example to CLI_DEMO_GUIDE
- Add collision topology and best-fee integration tests to TESTING.md

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
- Use `getAllAddressTypes()` for dynamic address display in viewcontact (#567)

### Fixed
- Prevent watchdog from restarting processors after `eiou shutdown` (#576)
- Apply QUICKSTART hostname after wallet restore (#578)
- Missing namespace imports across GUI controllers and services (#575)
- Missing backslash on `ReflectionClass` in `Constants::all()` (#574)
- MSYS path conversion in insufficient funds test (#564)
- Add php-xml for ext-dom required by Composer resolution (#566)
- Remove exit() calls from service methods (#547)

### Docs
- Update documentation for recent architectural changes (#565)

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
- Extract formatters from TransactionRepository (#543)

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
