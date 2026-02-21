# Changelog

All notable changes to the EIOU Docker project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This project does not yet follow [Semantic Versioning](https://semver.org/). There are
no version tags. Entries are organized by development period rather than release number.
The project is currently in **ALPHA** status.

---

## [Unreleased]

### Fixed
- Fix 79 unit test failures across 26 files: ErrorHandler tearDown removing PHPUnit's handlers (45 failures), repository tests expecting false from AbstractRepository::execute() (5), SyncService chain conflict tests failing due to private signature verification methods and missing test data fields (8), DebugRepository random pruneOldEntries breaking strict mock expectations (8), tor address validation with wrong length (2), namespace-unqualified dynamic function calls in DatabaseSetup migrations (1), AbstractMessageProcessor flushing PHPUnit output buffers (1), UtilPayload null senderAddress handling (1), and various mock return type mismatches
- Implement 24 previously-skipped unit tests: ChainOperationsService chain verification/repair tests (16), SendOperationService validation and dependency injection tests (7), TransactionProcessingService missing fields test (1)
- Fix API test suite failures caused by HTTP→HTTPS redirect (PR #644): always use `https://localhost` with `-k` for API endpoint tests; add `X-API-Nonce` header and nonce in HMAC signature to all authenticated API test requests; fix `curlErrorHandlingTest` tests 2, 4, 5, 17 to resolve timeout constants via PHP and use correct function boundaries; pipe large API responses via stdin instead of command-line args to avoid `Argument list too long` errors in response format validation tests (apiEndpointsTest and cliCommandsTest); clarify curlErrorHandlingTest Test 13 output message
- Fix `contactNameTest` Test 4 (Duplicate Name Detection) skipping on all topologies: dynamically find a container with 2+ accepted contacts instead of hardcoding `containers[2]` which may not be a contact of the sender in line topologies
- Fix `bestFeeRoutingTest` Test 11 (Dead-end cascade cancel) querying wrong P2P record: record last P2P id before send and filter by `id > lastId` to avoid picking up Test 9's paid record
- Fix `maxLevelCancelTest` Test 5 (Destination at boundary): use `resolveUserAddressForTransport()` to get the same address that `handleP2pRequest` uses, so the hash matches correctly
- Fix `parallelBroadcastTest` Test 8: handle empty string curl responses as errors in `TransportUtilityService::sendBatch()` and `sendMultiBatch()`
- Fix `chainDropTestSuite` Sections 1-8 failing at proposal delivery: add `cleanup_backups` to `clean_chain()` so backup recovery doesn't short-circuit `proposeChainDrop()` before sending the proposal message
- Fix `performanceBaseline` batch transaction test: increase inter-send delay from 100ms to 250ms and timeout from 30s to 45s to prevent rate-limiting failures
- Improve `pingTestSuite` Tests 6.1/6.3 diagnostic output with pubkey hash comparison
- Fix Docker build failure: use Debian PHP conf path (`/etc/php/*/conf.d/`) instead of Docker-official-image path (`/usr/local/etc/php/conf.d/`) for `expose_php` setting; fix `|| true` operator precedence in security config step
- Fix KeyEncryption::encrypt() clearing IV before base64-encoding it, causing all encrypted data to have empty IVs and fail on decrypt

### Changed
- Trusted proxies now configurable via CLI (`changesettings trustedProxies`) instead of requiring container rebuild
- Rename `Security::sanitizeInput()` to `stripNullBytes()` for accuracy; deprecated alias retained
- P2P transport payload moved from URL query parameter to POST body for privacy (backward-compatible receiver fallback)
- P2P nonce changed from `time()` to `bin2hex(random_bytes(16))` for cryptographic uniqueness
- Exception detail display gated behind `Constants::isDebug()` instead of `APP_ENV !== 'production'`
- API authentication error messages normalized to prevent key state enumeration

### Security
- **M-1**: Validate SQL pattern before executing backup restore statements
- **M-2**: Fix session cookie `secure` flag to properly detect HTTPS (handles proxy/edge cases)
- **M-3**: Rotate CSRF tokens after successful validation to prevent reuse
- **M-4**: Log warning when `EIOU_TEST_MODE` bypasses rate limiting; add bypass to `enforce()` for consistency
- **M-5**: Normalize API error responses to generic "Invalid or inactive API key"
- **M-6**: Remove duplicate CSRF implementation from `Security` class (Session class is canonical)
- **M-7**: Rename misleading `sanitizeInput()` to `stripNullBytes()`
- **M-8**: Warn on CORS wildcard configuration; add `X-API-Nonce` to allowed CORS headers
- **M-9**: Cap P2P request level with server-side maximum from `UserContext::getMaxP2pLevel()`
- **M-10**: Gate exception message exposure behind debug mode, not environment name
- **M-11**: Add `htmlspecialchars()` to unencoded date and currency output in transaction history
- **M-12**: Remove raw API key secret echo to prevent Docker log exposure
- **M-14**: Remove legacy plaintext private key check from `hasKeys()`
- **M-15**: Use RAM-backed `/dev/shm` for decrypted backup temp files with guaranteed cleanup
- **M-16**: Add in-memory P2P rate limiting by sender public key (60 req/min)
- **M-17**: Add terminal state guard and ownership check to transaction recovery
- **M-19**: Add circuit breaker (5 failures) for sync signature verification failures
- **M-20**: Filter address updates to valid transport columns via dynamic `getAllAddressTypes()`
- **M-21**: Block held transaction processing when chain integrity check fails after sync
- **M-23**: Add `no-new-privileges` and `pids_limit` to all Docker Compose files
- **M-24**: Add Docker log rotation (json-file, 10MB/3 files) and in-container logrotate for Apache/PHP logs
- **M-25**: Add HTTP-to-HTTPS redirect (except `/eiou` transport endpoint)
- **M-26**: Harden SSL/TLS: disable SSLv3/TLSv1/TLSv1.1, strong cipher suite, HSTS header
- **M-27**: Move transport payload from URL query parameter to POST body; disable redirect following
- **M-28**: Replace timestamp nonce with cryptographic `random_bytes(16)` nonce
- **M-29**: Verify Composer installer SHA-384 hash before execution
- **M-30**: Harden MariaDB: bind to localhost, disable symbolic links
- **M-32**: Add balance overflow guard with warning log

### Docs
- Document container security hardening and log rotation in `DOCKER_CONFIGURATION.md`

### Fixed
- Tor hidden service address mismatch on container restart: HS key regeneration check compared file existence but Tor had already started and generated random keys — now compares actual .onion address against userconfig to detect mismatches and regenerate correct keys from seed
- Tor watchdog initial boot: first self-check now waits 120s (descriptor propagation grace period) instead of firing immediately on the first watchdog loop — prevents restart doom loop on fresh container start while avoiding a 5-minute blind spot
- Tor watchdog recovery: increase post-restart verification window from 30s to 90s to match descriptor propagation time, allow follow-up restart after 90s instead of waiting full 5-minute cooldown, increase self-check timeout for slow Tor circuits
- Mutual contact request recognition: when both users send contact requests to each other, the second request to arrive now auto-accepts on both sides instead of leaving both stuck at "Pending Response"
- Wire up dead-code `buildMutuallyAccepted()` payload in `ContactPayload.php` with `$txid` parameter for transaction synchronization
- Fix sync inquiry misidentifying mutual pending contacts as "unknown" — `hasPendingContactInserted()` now checked for the case where both sides initiated requests
- Fix stale `$status` variable in `syncSingleContact()` re-send path — response was never decoded and status check always used the original rejected value, causing sync to report failure even after successful mutual acceptance

### Security
- **C-1**: Verify cryptographic signatures on re-signed transactions in `ChainDropService::processResignedTransactions()` before storing — prevents accepting forged chain drop data
- **C-2**: Centralize IP resolution in `Security::getClientIp()` — only trust proxy headers (`X-Forwarded-For`, `CF-Connecting-IP`) when `REMOTE_ADDR` is in the trusted proxies list (configurable via CLI or `TRUSTED_PROXIES` env var)
- **C-3**: Add rate limiting and CSRF token validation to the GUI login form — prevents brute-force auth code guessing
- **H-9/H-10**: Make `APP_ENV` and `APP_DEBUG` overridable via environment variables (`Constants::getAppEnv()`, `Constants::isDebug()`) — allows production hardening without code changes; gate `display_errors` behind debug flag
- **H-1**: Move `insertTransaction()` inside the contact send lock in `handleDirectRoute()` — prevents TOCTOU race where concurrent sends to the same contact could use the same `previous_txid`
- **H-2**: Add contact send locking to `sendP2pEiou()` — P2P transaction inserts now protected by the same lock pattern as direct sends, preventing chain forks from concurrent P2P route completions
- **H-3**: Wrap transaction status + balance updates in database transactions — prevents balance discrepancy if a crash occurs between `updateStatus()` and `updateBalance()` during incoming transaction processing
- **H-4**: Add nonce-based API replay protection — `X-API-Nonce` header required on all API requests, server-side nonce tracking rejects duplicates within the timestamp window, nonce included in HMAC signature
- **H-6**: Sanitize filename in backup delete endpoint — `Security::sanitizeFilename()` applied in both `ApiController::deleteBackup()` and `BackupService::deleteBackup()` to prevent path traversal via `../../` in filenames
- **H-8**: Default `P2P_SSL_VERIFY` to `true` — HTTPS peer verification now enabled by default; self-signed certificates (e.g. QUICKSTART nodes) are rejected unless `P2P_SSL_VERIFY=false` or `P2P_CA_CERT` is set; automatically disabled in `EIOU_TEST_MODE`
- **H-11**: Encrypt database password in config file — `dbconfig.json` password auto-migrated from plaintext `dbPass` to AES-256-GCM encrypted `dbPassEncrypted` on first Application boot after the master key is stable; file permissions restricted to 0600
- **M-31**: Lock file permissions tightened from 0666 to 0600
- **L-1/L-2**: Add SQL column whitelists to `TransactionStatisticsRepository` and `TransactionChainRepository`
- **L-3**: Block raw SQL conditions via numeric keys in `QueryBuilder::buildWhereClause()`
- **L-4**: Validate ORDER BY columns against `$allowedColumns` whitelist in `QueryBuilder`
- **L-5**: Replace string interpolation with bound parameters in `MessageDeliveryRepository::markCompletedByHash()`
- **L-7**: Harden `Session::clear()` to fully destroy session, invalidate cookie, and regenerate ID
- **L-8**: Add `Security::setSecurityHeaders()` to API entry point
- **L-9**: Prevent API key self-deletion in `ApiController::deleteApiKey()`
- **L-11/L-12**: Reduce visibility of `buildStringToSign()` and `generateSignature()` to private in `ApiAuthService`
- **L-13/L-14**: Add `htmlspecialchars()` encoding to currency and avatar initial output in contact section
- **L-15**: Add `htmlspecialchars()` encoding to server info output in settings section
- **L-16**: Replace user-manipulable `$_SERVER['PHP_SELF']` with `$_SERVER['SCRIPT_NAME']` in redirects
- **L-17**: Add maximum length check (1024 chars) to signature validation in `InputValidator`
- **L-18**: Tighten Tor address regex to validate v3 onion format (56 base32 chars)
- **L-19**: Add null byte stripping and control character filtering to `BasePayload::sanitizeString()`
- **L-20**: Fix double-encoding of flash messages by removing redundant `htmlspecialchars()` in `Functions.php`
- **L-21**: Use constant-time `hash_equals()` for BIP39 mnemonic checksum comparison
- **L-22**: Replace hardcoded HMAC context strings with class constants in `BIP39` and `TorKeyDerivation`
- **L-23**: Upgrade SSL certificate RSA key size from 2048 to 4096 bits
- **L-24**: Use `secureClear()` with fallback for all sensitive memory clearing in `KeyEncryption`
- **L-25**: Filter encrypted key blobs from `UserContext::getAll()` and `toArray()` output
- **L-26**: Replace weak `rand()` with CSPRNG `random_int()` in P2P request level generation
- **L-27**: Add security warning when seed phrase is passed via CLI arguments (visible in process listings)
- **L-29**: Suppress Apache `ServerTokens`/`ServerSignature` and PHP `expose_php` in Docker image
- **L-34**: Replace predictable `microtime()` with `random_bytes()` in chain drop proposal ID generation
- **L-35**: Hash long lock names instead of truncating to prevent collisions in `DatabaseLockingService`

## 2026-02-18

### Added
- Immediate Tor SOCKS5 recovery: PHP transport layer now signals the watchdog via `/tmp/tor-restart-requested` when a SOCKS5 proxy failure is detected, triggering Tor restart within ~30 seconds instead of waiting for the 5-minute periodic health check
- Tor restart counter reset after 5-minute cooldown — prevents permanent Tor unavailability when recovery takes longer than 5 restart attempts
- Let's Encrypt integration for automatic browser-trusted SSL certificates
  - In-container certbot for single-node deployments (`LETSENCRYPT_EMAIL` env var)
  - `scripts/create-ssl-letsencrypt.sh` — host-level script for obtaining certificates (HTTP-01 and DNS-01 wildcard)
  - `scripts/renew-ssl-letsencrypt.sh` — host-level renewal script for cron automation
  - Automatic renewal cron job inside containers using Let's Encrypt
  - Support for sharing one wildcard cert across multiple nodes via `/ssl-certs/` volume
- New environment variables: `LETSENCRYPT_EMAIL`, `LETSENCRYPT_DOMAIN`, `LETSENCRYPT_STAGING`
- `/etc/letsencrypt` added as a persistent Docker volume for certificate state

### Changed
- SSL certificate priority chain updated: External → Let's Encrypt → CA-signed → Self-signed
- SSL section in `startup.sh` refactored from if/elif chain to sequential flag-based approach for cleaner fallback handling

### Docs
- Updated `SECURITY.md` to reference Let's Encrypt as the recommended production SSL option
- Added Tor SOCKS5 recovery section to `ERROR_CODES.md` with manual restart trigger instructions
- Updated `README.md`: removed obsolete `eiou generate` commands, added QUICKSTART explanation, added `eiou add` parameter reference explaining `<address>`, `<fee>`, `<credit>`, `<currency>` placeholders, fixed incorrect comments in cluster topology
- Renamed cluster hub node from `cluster-a` to `cluster-a0` in `docker-compose-cluster.yml` to match README and naming convention

## 2026-02-17

### Added
- `partial` online status for contacts — indicates node is reachable but has degraded message processors (some of P2P, Transaction, or Cleanup processors are not running)
- Pong response now includes processor health (`processorsRunning`, `processorsTotal`) for remote nodes to determine partial vs online status
- `contact_status` processor status in `GET /api/v1/system/status` response
- `isProcessorRunning()` static utility on `AbstractMessageProcessor` for PID file validation with process existence and cmdline verification
- CSS styling for partial status: orange indicator dot and warning badge in GUI
- `senderAddresses` field in contact creation payload — initial contact requests now include all known addresses (HTTP, HTTPS, TOR), enabling transport fallback when the primary address is unreachable
- TOR-to-HTTP/HTTPS transport fallback for contact requests only — when TOR delivery fails (SOCKS5 connection error) during initial contact creation, `TransportUtilityService::send()` attempts delivery via the recipient's known HTTP/HTTPS address; transactions and other messages respect the user's chosen transport to preserve privacy
- Tor hidden service self-health check in watchdog — every 5 minutes the watchdog curls the node's own `.onion` address through the SOCKS5 proxy; if unreachable, fixes hidden service directory permissions and restarts Tor to republish the descriptor (up to 5 attempts with 5-minute cooldown, resets on recovery)
- Send eIOU P2P info box collapsed by default — shows "Peer-to-Peer Routing Available" as a one-liner, click to expand details
- Best Fee Route experimental warning hidden by default — flask icon shown inline next to the label, yellow warning only appears when the toggle is enabled

### Changed
- GUI header (wallet title + logout) now wraps to two lines when viewport is too narrow instead of overlapping
- GUI quick action menu buttons scale to fit on one line at desktop widths; become a horizontal slider at tablet/phone sizes instead of wrapping to multiple rows
- Floating refresh and back-to-top buttons reduced from 60px to 40px and moved from right: 30px to right: 8px for a less intrusive presence
- All new inline styles moved to CSS classes; all new JS uses TOR-compatible patterns (var, className, vendor-prefixed flex)

### Fixed
- Contact acceptance messages fail when recipient's TOR hidden service is unreachable — the system now falls back to HTTP/HTTPS transport using stored alternative addresses
- Incoming contact requests only stored the sender's primary address — `handleContactCreation()` now extracts and stores `senderAddresses` from the request payload, and includes responder's addresses in the `buildReceived()` response
- `GET /api/v1/system/status` used wrong PID file names (`p2p_processor.pid` instead of `p2pmessages_lock.pid`), always reported processors as not running
- `GET /api/v1/system/status` now validates PID files properly (checks process existence and PHP cmdline) instead of only checking file existence
- Remove dead Curve25519 `sodium_crypto_scalarmult_base()` call from Tor key derivation that computed an unused value before the correct Ed25519 derivation
- Tor hidden service directory permission errors in startup.sh are now logged instead of silently swallowed — failed `chown`/`chmod` could cause Tor to reject seed-derived keys and generate random ones
- Log warning when OpenSSL falls back from secp256k1 to prime256v1 EC curve, which would cause wallet keys to differ from nodes using secp256k1
- Add missing `hop_wait` column to `p2p` table schema and migration — INSERT queries from `P2pRepository::insertP2pRequest()` were failing with "Unknown column 'hop_wait'"
- GUI header logout button overflows outside the card on narrow screens — wallet owner name now wraps to a new line on mobile, keeping the logout button anchored in the top right
- Contacts scroll buttons repositioned outside the card area so contact cards are fully visible — left button auto-hides when at the first contact, right button hides at the end
- Contact modal exceeded viewport height causing settings buttons (Block/Delete/Save) to be invisible — modal now constrained to 90vh with internal scrolling
- Contact modal settings buttons were stacked vertically with uneven sizing — now displayed in a compact inline row with consistent height
- Contact modal transactions tab refresh button overlapped info text on narrow screens — text now wraps while button stays intact

---

## 2026-02-16

### Added
- Available credit exchange during ping/pong — pong responses now include `availableCredit` and `currency` fields, allowing contacts to report how much credit is available to transact through them
- `contact_credit` database table — stores per-contact available credit (pubkey_hash, available_credit, currency) updated on each successful ping, linked to contacts via pubkey_hash
- `ContactCreditRepository` — new repository for managing contact credit entries with upsert, lookup, and initial creation methods
- Initial contact credit entry created on contact acceptance — both `ContactManagementService` and `ContactSyncService` create a zero-credit row when accepting contacts
- `ContactStatusProcessor` saves available credit from background ping pong responses
- Bidirectional available credit display — CLI `view`, `search`, API contact endpoints (`/contacts`, `/contacts/:address`, `/contacts/search`), and GUI contact modal show both "your available credit" (from pong) and "their available credit" (calculated from balance + credit limit)
- Total available credit per currency in CLI `info` command — sums available credit across all contacts, displayed in both text and JSON modes
- Total fee earnings per currency in CLI `info` command — sums P2P relay fee earnings across all completed P2Ps, displayed in both text and JSON modes
- Available credit in API contact endpoints — `GET /api/v1/contacts` and `GET /api/v1/contacts/:address` include `my_available_credit` and `their_available_credit` fields
- Total available credit in API wallet overview — `GET /api/v1/wallet/overview` includes `total_available_credit` array grouped by currency
- `getTotalAvailableCreditByCurrency()` method on `ContactCreditRepository` — aggregates available credit across all contacts by currency
- GUI total available credit dashboard card — shows summed available credit per currency in the wallet information section
- GUI contact modal bidirectional credit display — "Your Credit" (from pong, with refresh interval tooltip) and "Their Credit" (calculated locally) shown side by side
- GUI wallet dashboard stats (Total Balance, Total Earnings, Total Available Credit) displayed in a horizontal row on wide screens with consistent card styling; each currency gets its own row for future multi-currency support
- Contact modal labels renamed to "Your Available Credit" and "Their Available Credit"; reordered to: Credit Limit, Your Available Credit, Fee, Their Available Credit
- Sliding-window concurrency control for `curl_multi` batch sends — `executeWithConcurrencyLimit()` caps simultaneous connections per protocol (HTTP: 10, Tor: 5) to prevent Tor circuit overload
- `getConcurrencyLimit()` method on `TransportUtilityService` — centralized protocol-to-limit lookup using `Constants::CURL_MULTI_MAX_CONCURRENT` associative array
- Mega-batch P2P processing — `processQueuedP2pMessages()` uses a 3-phase approach: collect all sends across queued P2Ps, fire via `sendMultiBatch()`, map results back
- Coalesce delay (`P2P_QUEUE_COALESCE_MS`, 2000ms) — groups concurrent P2Ps arriving within a short window into a single mega-batch
- P2P parallel worker model — coordinator (`P2pMessageProcessor`) spawns independent worker processes (`P2pWorker.php`) for each queued P2P via `proc_open`, enabling truly parallel routing through the network
- `processSingleP2p()` method on `P2pService` — processes one P2P with atomic claim (`queued → sending`), broadcast via own `curl_multi`, and status transition (`sending → sent`)
- Atomic P2P claiming in `P2pRepository` — `claimQueuedP2p()`, `getStuckSendingP2ps()`, `recoverStuckP2p()`, `clearSendingMetadata()` methods for worker coordination and crash recovery
- `sending` status added to P2P ENUM with `sending_started_at` and `sending_worker_pid` columns — enables worker ownership tracking and stuck-sending recovery
- `P2P_MAX_WORKERS` keyed by transport protocol (HTTP: 50, HTTPS: 50, Tor: 5) and `P2P_SENDING_TIMEOUT_SECONDS` (300) constants for worker pool sizing and crash recovery threshold
- `Constants::getMaxP2pWorkers($transport)` static method — returns per-transport worker limit with `EIOU_P2P_MAX_WORKERS` env var override for per-deployment tuning

### Changed
- `P2pMessageProcessor` rewritten from single-threaded delegator to coordinator+worker model — polls for queued P2Ps, spawns workers up to per-transport limits (HTTP: 50, Tor: 5), tracks workers by transport type independently, reaps finished workers, and recovers stuck `sending` P2Ps with dead worker PIDs every 60s
- `CURL_MULTI_MAX_CONCURRENT` is now an associative array mapping protocol to limit (http: 10, https: 10, tor: 5) instead of a single value — unknown protocols fall back to the lowest configured limit
- Best-fee route selection now tries candidates from cheapest to most expensive with fallback — if the cheapest candidate's fee exceeds the originator's `maxFee` setting or a relay node can't afford the amount, the next candidate is tried instead of silently failing
- `handleRp2pRequest()` return type changed from `void` to `bool` — returns `false` when fee/affordability validation fails, enabling caller-driven fallback
- Originator fee check moved before RP2P insert — rejected candidates no longer pollute the database, and `updateStatus('found')` is deferred until validation passes
- `checkRp2pPossible()` fast-mode path now sends rejection response (not "inserted") when `handleRp2pRequest()` returns false — sender correctly records failed delivery instead of false positive acceptance
- Rejected RP2Ps in fast mode now increment `contacts_responded_count` — when all contacts have responded (all rejected or cancelled), the relay cancels immediately and propagates cancel upstream instead of waiting for expiration timeout
- P2P best-fee mode forced to fast for Tor recipients (`.onion` addresses) on both sender and receiver side — Tor latency (~5s/hop) makes best-fee relay overhead prohibitive; receiver-side override prevents remote nodes from forcing best-fee mode over Tor

### Fixed
- CA-signed SSL certificate generation in `startup.sh` — openssl errors were silently discarded (`2>/dev/null`), so if `/ssl-ca/` mount had permission issues or corrupt keys, Apache got an invalid cert and the container crashed with no explanation; now logs errors and falls back to self-signed
- CA-signed SSL serial file written to `/tmp/ca.srl` instead of `/ssl-ca/ca.srl` — `-CAcreateserial` tried to write into the read-only `/ssl-ca/` mount, causing signing to fail on every `:ro` mount
- SSL certificate CN and SANs included port number when QUICKSTART/EIOU_HOST contained a port (e.g. `88.99.69.172:1152`) — ports are not valid in certificate fields; now stripped before certificate generation
- SSL certificate used `DNS:` SAN prefix for IP addresses — IP addresses require the `IP:` prefix per RFC 5280; now auto-detected and prefixed correctly
- `viewsettings` CLI command and `GET /api/v1/system/settings` now include `hostname` and `hostname_secure` fields — previously these were settable via `changesettings` option 10 but not visible in the settings display
- API `GET /api/v1/system/settings` now includes `auto_backup_enabled` field
- Idempotency guards on P2P and transaction balance updates — `MessageService::handleTransactionMessageRequest` and `CleanupService::syncAndCompleteP2p` now check whether a P2P/transaction is already completed before calling `updateBalanceGivenTransactions`, preventing double balance increments when both the normal completion flow and cleanup recovery fire for the same hash
- Benchmark `benchmark-routing.sh` no longer filters P2P lookup by `fast` flag — the Tor fast-mode override stores `fast=1` even when the user requested best-fee (`fast=0`), causing the benchmark to find nothing and report N/A; `id > max_id` scoping is sufficient since the benchmark is sequential

### Fixed
- Ping/pong fatal error — `ContactStatusService::handlePingRequest()` called `protected` method `findByColumn()` on `AbstractRepository`; replaced with public `getContactByPubkey()`

### Changed
- Wallet dashboard balance and earnings cards now display per-currency rows — future-proofed for multi-currency support, matching the existing Total Available Credit pattern
- Dollar sign (`$`) prefix removed from all transaction amount displays — amounts now show as `83.32 USD` instead of `$83.32 USD` across recent transactions, transaction detail modals, contact modal transactions, in-progress transactions, P2P details, and toast notifications
- `getUserTotalEarningsByCurrency()` method added to `P2pRepository` and `P2pService` — returns fee earnings grouped by currency

### Docs
- Updated API Reference with `total_available_credit` in wallet overview response and `my_available_credit`/`their_available_credit` in contact endpoints
- Updated API Reference: `/contacts/search` now documents `fee_percent`, `credit_limit`, `my_available_credit`, `their_available_credit`, and `currency` fields
- Updated API Reference: `/system/settings` now documents `hostname`, `hostname_secure`, and `auto_backup_enabled` fields
- Updated CLI Reference: `search` command now documents available credit fields in output
- Updated CLI Reference: `info` command now documents total fee earnings per currency
- Updated CLI Reference: `viewsettings` command now documents hostname and auto-backup fields
- Updated GUI Reference: wallet dashboard cards documented as per-currency with fallback behavior
- Updated CLI Demo Guide with available credit details for `viewcontact` and `ping` sections
- Updated GUI Reference with total available credit dashboard card and contact modal credit fields
- Updated API and GUI Quick References with available credit field descriptions

## 2026-02-15

### Added
- Parallel P2P broadcast via `curl_multi` — broadcast to all contacts simultaneously instead of sequentially, reducing broadcast time from O(N × latency) to O(max latency)
- `createCurlHandle()` and `sendBatch()` methods on `TransportUtilityService` for reusable parallel transport
- `sendBatchAsync()` method on `MessageDeliveryService` for tracked batch delivery with per-recipient response processing
- Tor expiration scaling — P2P messages sent to Tor contacts use 2x expiration multiplier (`P2P_TOR_EXPIRATION_MULTIPLIER`) to account for higher Tor latency
- Integration test suite `parallelBroadcastTest.sh` (14 tests) for curl_multi batch send functionality

## 2026-02-13

### Fixed
- P2P max level boundary nodes now immediately send cancel notification upstream instead of going through the full broadcast-rejection cycle — when `requestLevel >= maxRequestLevel` after re-adjustment, the node stores as cancelled and notifies upstream instantly, significantly improving cancel cascade propagation speed in larger topologies

---

## 2026-02-06 -- 2026-02-12

### Added
- Cascade cancel/expire for dead-end P2P routes (#598): Nodes immediately notify upstream when they have no viable route, triggering early best-fee selection or cascade cancellation instead of waiting for expiration timers
- Multi-part contact names with spaces supported in CLI (use quotes: `"John Doe"`)
- Contact disambiguation when multiple contacts share the same name — CLI prompts for selection, JSON mode returns `multiple_matches` error with contact list
- Searchable contact dropdown in GUI send form — type to filter contacts by name or address instead of scrolling through a static list
- `lookupAllByName()` repository method for retrieving all contacts matching a name
- `STOPSIGNAL SIGTERM` directive in Dockerfile — makes the graceful shutdown signal explicit so `--restart unless-stopped` works correctly (containers restart on Docker daemon restart but stay stopped after `docker stop`)
- SIGTERM integration test (`sigTermTest.sh`) — verifies `docker stop` triggers graceful shutdown within the grace period, container exits cleanly (not SIGKILL'd), and restarts with data intact
- Two-phase best-fee selection: relay nodes first select from `inserted` contacts, then share the result with `already_relayed` contacts to break mutual deadlock, wait for their response, and re-select from all candidates before forwarding upstream
- Relay RP2P forwarding to late P2P senders: when a node already has an RP2P and receives a P2P from a new sender (`already_relayed`), it immediately sends the existing RP2P back — enabling optimal route discovery without waiting for hop-wait expiration
- `p2p_relayed_contacts` table for tracking contacts that returned `already_relayed` during P2P broadcast
- `contacts_relayed_count`, `contacts_relayed_responded_count`, and `phase1_sent` columns on `p2p` table for two-phase selection tracking
- Separate RP2P response counting: inserted contacts increment `contacts_responded_count` (phase 1), relayed contacts increment `contacts_relayed_responded_count` (phase 2) — prevents premature phase triggers from cross-path RP2P candidates
- Best-fee routing mode (`--best` flag): collects all RP2P responses and selects the lowest accumulated fee route (experimental)
- `rp2p_candidates` table for storing RP2P candidate responses during best-fee selection
- `p2p_senders` table for tracking all upstream P2P senders in multi-path routing
- Multi-path RP2P forwarding: relay nodes forward RP2P responses to all upstream senders, not just the first
- Per-hop expiration for best-fee mode: leaf nodes expire first, cascading selection upstream
- Orphaned candidate recovery: `CleanupService` triggers best-fee selection when P2P expires with available candidates
- `contacts_sent_count`, `contacts_responded_count`, `hop_wait`, and `fast` columns on `p2p` table for routing mode tracking
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

### Changed
- Removed manual `eiou in`/`eiou out` queue processing from integration tests (best-fee, cascade cancel, routing, send, negative-financial) — background daemon processors handle message routing naturally, reducing best-fee test time from ~73s to ~72s with no manual overhead; `syncTestSuite.sh` retains its own `process_all_queues` for precise chain synchronization sequencing
- Contacts grid now scrolls horizontally instead of wrapping into rows — cards continue to the right in a single scrollable row
- GUI banner system — place images in `assets/banners/` to display a banner above the wallet and login screens; empty folder shows nothing
- Startup user info section no longer creates a separate authcode temp file on first wallet creation — the seedphrase file already contains the authcode, so creating a second file was redundant and confusing; on restart or restore, the authcode-only file is still created as before
- `P2P_HOP_WAIT_DIVISOR` reduced from 20 to 12 — gives relay nodes 23s per hop (up from 15s clamped minimum) with the default 300s expiration, allowing more time for best-fee candidate collection
- `P2P_MAX_ROUTING_LEVEL` reduced from 20 to 10 (max hops a user can configure); hopWait formula now uses separate `P2P_HOP_WAIT_DIVISOR` (fixed at 12) to preserve privacy
- Collision topology test fees randomized (0.1-0.9) per run so best-fee routing is verified against varying fee structures
- Best-fee path analysis labels tied optimal routes as `[TIED BEST]` and prints the randomized fee structure
- Best-fee routing test shows timing comparison between fast mode and best-fee mode
- Best-fee path analysis uses compound fee multiplier `(1 + fee/100)` product instead of additive sum; destination hop excluded from fee calculation
- Best-fee routing test traces actual path for both fast and best-fee modes with a side-by-side path comparison; comparison now shows optimality status for each result category (OPTIMAL, BETTER, SAME optimal/sub-optimal, WORSE) and prints a `RESULT:` summary line for easy multi-run grep
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

### Fixed
- Race condition in best-fee P2P routing: cancel notifications from Phase 1 relayed contacts could arrive before the P2P daemon forwarded the queued message to the destination — `handleCancelNotification` and `handleRp2pCandidate` now defer selection when P2P status is 'queued'; matched-contact sends now track `contacts_sent_count` for 'found' responses and call `checkBestFeeSelection` after forwarding
- Phase 1 cancel deadlock between hub nodes: when all inserted contacts cancelled with no RP2P candidates, `sendBestCandidateToRelayedContacts` silently returned without notifying relayed contacts — hub nodes with mutual relayed references (e.g. A4↔A8) deadlocked until hop-wait expiration instead of cascading cancel immediately
- Collisions topology bugs: duplicate fee variable, missing `fee_A6_A9` (A6↔A9 link used wrong fee), duplicate `[A8,A10]` key instead of reverse `[A10,A8]`, wrong `expectedContacts` counts for A4 (4→5), A6 (3→4), wrong comment for A5; routing test intermediary lists now cover all shortest-path variations
- Cascade cancel tests sent to nonexistent hostname which fails address validation before P2P is broadcast — added isolated A12 node (no connections) as cascade cancel target so the P2P propagates through the mesh and dead-end nodes actually exercise cascade cancel
- Missing name validation in `updateContact()` command — names with invalid characters were accepted on update but rejected on add
- Clarified error message when recipient is not found — now reads "not a valid address or known contact" instead of just "not a valid address"
- Graceful shutdown output truncated after Apache stop — `service apache2 stop` blocked indefinitely with no timeout, consuming the Docker grace period before MariaDB/Tor/Cron stops and completion message could execute; all service stops now wrapped in `timeout` commands
- Phase 1/Phase 2 race condition: `selectAndForwardBestRp2p` now checks `phase1_sent` before forwarding upstream — if a relayed contact's RP2P arrived before all inserted contacts responded, Phase 2 triggered directly (skipping Phase 1), so the relayed contact never received our best downstream candidate and fell back to expiration with potentially sub-optimal candidates
- Relayed contacts merge in RP2P forwarding: `handleRp2pRequest` now merges `p2p_relayed_contacts` into the senders list — contacts that returned `already_relayed` during broadcast but whose P2P to us hadn't arrived yet were missing from `p2p_senders` and never received the RP2P response
- Phase 1 infinite loop: added `phase1_sent` flag to prevent `sendBestCandidateToRelayedContacts` from re-triggering when additional RP2P candidates arrive after Phase 1 has already fired — previously each new candidate that met the inserted threshold re-sent to relayed contacts, creating an exponential loop between nodes
- RP2P source classification: removed incorrect 3-category approach (upstream/relayed/inserted) — all RP2Ps at a node come from downstream contacts only (inserted or relayed), not upstream senders
- Phase 2 trigger condition: waits for all propagated contacts (inserted + relayed combined) to respond before final selection — the original upstream P2P sender is not counted since we send the result TO them
- Per-sender fee calculation: relay nodes now calculate separate fees for each upstream sender based on their individual contact fee settings, instead of using the first sender's fee for all paths — fixes incorrect fee comparison that caused sub-optimal route selection
- Phase 1 fee forwarding: reverted incorrect fee subtraction in `sendBestCandidateToRelayedContacts` — this node's fee must be included when sending to relayed contacts because cycle prevention ensures the RP2P won't loop back, and paths continuing through the relayed contact to other upstream nodes need this hop's fee in the accumulated total
- Multi-path RP2P forwarding: first P2P sender (inserted) was missing from `p2p_senders` table, causing collision nodes to only forward RP2P to later (already_relayed) senders — the first sender's upstream relay never received the RP2P and fell back to hop-wait expiration, missing potentially optimal routes
- Best-fee broadcast race condition: set `contacts_sent_count` ceiling before broadcast loop to prevent RP2P responses arriving via HTTP handler from triggering premature selection while the broadcast is still sending to other contacts
- Best-fee originator slow fallback when candidates exist at expiration: `expireMessage()` now triggers best-fee selection for originators (not just relays) when candidates are available at P2P expiration time, avoiding the extra 30s grace period wait
- Best-fee originator selecting suboptimal route: originator triggered immediate route selection on the first candidate after P2P expiration — both in `handleRp2pCandidate` (late arrivals) and in `expireMessage` step 1.5 (cleanup cycle). Now only relay nodes select immediately on expiration; originators wait for all contacts or fall back via cleanup after a grace period
- P2P `sender_address` not updated at end recipient in multi-path routing: when the RP2P-selected route differs from the P2P propagation path, the end recipient's P2P record retained the original propagation sender instead of the actual transaction sender; relay nodes already had this fix
- Best-fee relay expiration exceeding originator timeout: relay nodes computed `hopWait * remainingHops` which could far exceed the originator's P2P expiration (e.g. 75-285s vs 60s). Relay expiration is now capped to `upstreamExpiration - hopWait` with a minimum viability floor of `P2P_HOP_PROCESSING_BUFFER_SECONDS` (2s)
- Best-fee P2P re-expiration after selection: after `selectAndForwardBestRp2p()` ran during cleanup, the next cleanup cycle could re-process the same P2P because `getExpiredP2p()` didn't exclude `'found'` status. Now sets status to `'found'` after selection and excludes `'found'` from expired P2P queries
- Best-fee mode `contacts_sent_count` not set on direct-match path: relay nodes with the end-recipient as a direct contact used the `matchContact` shortcut without tracking sent count, blocking the "all contacts responded" trigger and forcing per-hop expiration fallback. Now counts both `inserted` and `already_relayed` from the destination (terminal node, no circular dependency risk)
- Best-fee mode broadcast path excludes `already_relayed` contacts from `contacts_sent_count`: these contacts run their own RP2P cascade on an independent hop-wait timer, and waiting for them delays selection until their full cascade completes. Their RP2P is still accepted as a bonus candidate if it arrives before selection
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

### Security
- Wallet restore no longer re-creates the seedphrase file — the user already has the seedphrase (they just used it to restore), so writing it to a temp file was an unnecessary security exposure; only the authcode file is regenerated so the user can retrieve it if lost

### Docs
- All `docker run` examples in CLI_DEMO_GUIDE.md now include `--restart unless-stopped` so containers automatically restart after host/Docker daemon restarts
- All `docker run` commands in legacy demo files (`tests/old/demo/`, `tests/gui.txt`) updated with `--restart unless-stopped`
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
