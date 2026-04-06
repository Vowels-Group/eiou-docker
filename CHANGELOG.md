# Changelog

All notable changes to the eIOU Docker project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This project does not yet follow [Semantic Versioning](https://semver.org/). There are
no version tags. Entries are organized by development period rather than release number.
The project is currently in **ALPHA** status.

---

## [Unreleased]

### Changed
- Restructure wallet GUI tab navigation: split "Send & Contacts" tab into a dedicated **Send** tab (eIOU form only) and a dedicated **Contacts** tab (add contact form + contact list); merge **Debug** section into the bottom of the **Settings** tab (removed as a standalone tab); remove the Quick Actions dashboard card bar (Send eIOU, Add Contact, View Contacts, Transaction History, Failed Messages, Settings shortcuts) — navigation is now fully tab-based
- Change "Total Fee Earnings" dashboard card color from green (`#28a745`→`#20c997`) to amber/gold (`#fd7e14`→`#ffc107`) to avoid culturally ambiguous color associations (green/red carry opposite financial meanings in different markets)
- Info tooltip icons (ⓘ) now open a tap-friendly modal on click in addition to the existing hover tooltip, making them accessible on touch/mobile devices where hover is not available

---

## v0.1.8-alpha (2026-04-03)

### Changed
- Reorder all transport/address display to most-secure-first: Tor > HTTPS > HTTP — affects GUI dropdowns (settings, send form, wallet info, contact modal), contact card icons, pending contact address lines, CLI output, and startup log. `VALID_TRANSPORT_INDICES` constant reordered. Startup log shows yellow ⚠ next to HTTP/HTTPS addresses only when they are Docker-internal (QUICKSTART without EIOU_HOST)

### Fixed
- Fix MariaDB failing to start after image rebuild due to version-incompatible encrypted InnoDB redo logs — when `apt` installs a different MariaDB patch version between builds, the new binary cannot parse the old version's redo log encryption metadata format (error: "Reading log encryption info failed; the log was created with MariaDB X.Y.Z"). `startup.sh` now tracks the MariaDB binary version in `/var/lib/mysql/.mariadb_version` and on mismatch starts MariaDB with `innodb_force_recovery=1` to bypass stale redo logs, performs a clean shutdown to regenerate them in the new format, then restarts normally and runs `mariadb-upgrade`. A reactive fallback applies the same force-recovery if the normal startup times out (e.g., first boot with version tracking). The MariaDB wait loop now has a 60-second timeout with diagnostics instead of looping forever
- Fix MariaDB failing to start when InnoDB redo log (`ib_logfile0`) is completely missing from the persistent volume — this occurs when upgrading from a broken prior container that crashed during initialization, had a partially restored volume, or never completed MariaDB setup. MariaDB refuses to initialize the InnoDB plugin without the redo log file present, and no `innodb_force_recovery` level can bypass this. `startup.sh` now detects this condition and performs automatic recovery: moves the broken data to `/tmp/mysql-broken-<timestamp>/` for inspection, reinitializes MariaDB with `mysql_install_db`, recreates the database and tables via `Application::getInstance()`, enables TDE encryption, and auto-restores from the latest backup on the backups volume. Wallet identity (keys, `.onion` address) is preserved because `userconfig.json` on the config volume is never modified — only `dbconfig.json` is regenerated with fresh database credentials. The recovery is crash-safe: if the process dies at any point, the next boot retriggers the same flow
- Fix MariaDB failing to start after container rebuild when TDE was enabled — `encryption.cnf` lives in the container filesystem (not a volume) and is lost when the container is recreated, but the mysql-data volume still has TDE-encrypted redo logs and tablespace files. MariaDB fails with `Obtaining redo log encryption key version 1 failed`. The pre-MariaDB TDE key setup now detects this condition: if the master key is available and a database exists on the volume but `encryption.cnf` is missing, it recreates the encryption config and TDE key file before MariaDB starts

### Docs
- Update `GUI_REFERENCE.md` and `GUI_QUICK_REFERENCE.md` — add 6 undocumented controller actions (`addCurrency`, `acceptAllCurrencies`, `getP2pCandidates`, `analyticsConsent`, `dlqRetryAll`, `dlqAbandonAll`), 3 missing layout components (`banner.html`, `dlqSection.html`, `analyticsConsentModal.html`), 3 undocumented notification types (Tor connectivity, update available, pending currency requests), 2 missing Feature Toggle settings (`autoRejectUnknownCurrency`, `analyticsEnabled`), and remove outdated "Single currency display" limitation
- Update `ARCHITECTURE.md` — add `MariaDbEncryption` and `VolumeEncryption` to Security Components, add database TDE/credential/volume encryption to Encrypted Storage table, add 8 missing services to Service Catalog (`AnalyticsService`, `UpdateCheckService`, `DebugReportService`, CLI services), add `DeliveryEvents` to Event-Driven Communication section
- Update `DOCKER_CONFIGURATION.md` — add 16 service tuning environment variables (nginx workers, rate limits, PHP-FPM process manager settings) and `TRUSTED_PROXIES` to the Quick Reference table; add troubleshooting entries for missing InnoDB redo logs and TDE config lost after rebuild
- Update `UPGRADE_GUIDE.md` — add MariaDB version detection to startup flow, add missing redo log recovery and TDE config rebuild steps, expand troubleshooting with version mismatch, missing redo log, TDE config loss, and force-recovery details

---

## v0.1.5-alpha (2026-03-31)

### Added
- Add **MariaDB Transparent Data Encryption (TDE)** — enabled by default after wallet generation, all database files encrypted at rest using `file_key_management` plugin with key derived from master key via HMAC-SHA256. TDE key stored only in `/dev/shm` (RAM-backed, never persisted to disk). On first boot the encryption plugin is loaded and all existing tables are encrypted; on subsequent boots the TDE key is regenerated from the master key before MariaDB starts. No user configuration required
- Add **optional volume passphrase** (`EIOU_VOLUME_KEY` / `EIOU_VOLUME_KEY_FILE`) for environments with external secrets management — encrypts the master key at rest using Argon2id key derivation + AES-256-GCM. When set, the host server cannot read the master key from the Docker volume without the passphrase. `EIOU_VOLUME_KEY_FILE` (recommended) reads from a file; `EIOU_VOLUME_KEY` reads from an environment variable. Plaintext master key exists only in `/dev/shm` at runtime
- Encrypt `dbUser` and `dbName` in `dbconfig.json` alongside `dbPass` — all database credentials are now encrypted at rest using AES-256-GCM with domain-separated AAD contexts. Backward-compatible: plaintext fields are encrypted on first boot after master key is available
- Two-location master key fallback — `KeyEncryption::getMasterKey()` checks `/dev/shm/.master.key` (RAM) first, then falls back to the persistent volume. When volume encryption is active, only the RAM copy exists in plaintext
- Add **update version notification** — checks Docker Hub daily for newer image tags and notifies the user via a GUI banner and the `/api/v1/system/status` API response. Compares semver tags (handles `alpha` < `beta` < stable ordering). Results are cached for 24 hours in `/etc/eiou/config/update-check.json`. Configurable via `EIOU_UPDATE_CHECK_ENABLED` env var (default: true), GUI toggle in Feature Toggles, and CLI `changesettings updateCheckEnabled`. No data is sent — read-only Docker Hub API call. Tor-only nodes silently skip the check
- Add **opt-in anonymous analytics** — sends aggregate, anonymous usage statistics weekly to `analytics.eiou.org`. Disabled by default (opt-in only). Reports transaction counts, volume per currency, contact count, and days active for the past 7 days. The anonymous ID is an HMAC-SHA256 hash that cannot be reversed to the node's identity. No personal data, individual transaction details, amounts per transaction, contacts, or addresses are ever sent. Configurable via `EIOU_ANALYTICS_ENABLED` env var, GUI toggle in Feature Toggles, CLI `changesettings analyticsEnabled`, and API `analytics_enabled`. Server-side: Cloudflare Worker + D1 in `Vowels-Group/eiou-analytics` (private)
- Add **one-time analytics consent modal** — after first login, a modal asks the user whether to enable anonymous analytics. The choice is saved to config (`analyticsConsentAsked`) and the modal never reappears. Users can always change their preference later in Settings > Feature Toggles. The modal uses AJAX to save the preference without a page reload

- Add **version compatibility guard** — nodes include their version in the message envelope (outside signed content, no impact on signatures) and in contact acceptance responses. Incompatible nodes (below `MIN_COMPATIBLE_VERSION` 0.1.3-alpha) are rejected at the entry point for all message types (transactions, P2P, sync, chain drops). Contact creation requests intentionally omit the version to prevent untrusted nodes from fingerprinting the software — version is only exchanged after trust is established (mutual acceptance, ping/pong, message envelopes). Direction-aware: tells the remote to upgrade if they're old, or tells you to upgrade if you're old. Stores `remote_version` per contact in the database. Log-once behavior: first incompatibility detection logs at warning, repeated rejections are silent. Outbound sends are blocked to contacts with known incompatible versions (unknown/null is allowed through). Auto-heals when the remote upgrades and sends a message or responds to a ping

### Fixed
- Fix analytics cron not installed when enabled via GUI/CLI/API after startup — the cron job is now always installed since the PHP script already exits gracefully when analytics is disabled. Also trigger an immediate `node_setup` event (no jitter) when analytics is first enabled through any interface (GUI consent modal, settings toggle, CLI, or API)
- Fix GUI settings controller not processing `updateCheckEnabled` toggle — checkbox value was not read from POST data, so toggling it in the GUI had no effect
- Fix GitHub Releases fallback in `UpdateCheckService` — the `/releases/latest` endpoint excludes pre-releases (returns 404 since all releases are pre-release). Switch to `/releases?per_page=10` and pick the highest semver, matching the Docker Hub tag selection logic

### Security
- Add data-at-rest encryption for all database files (MariaDB TDE) and optional volume passphrase protection for the master encryption key — see Added section for details

### Docs
- Add `ANONYMOUS_ANALYTICS.md` — full reference covering privacy guarantees, what is/isn't sent, exact payload examples, and how to toggle via GUI, CLI, and API
- Update `UPGRADE_GUIDE.md` — document MariaDB TDE, credential encryption, update version check, expanded startup flow, new verification log lines, and new troubleshooting entries

### Tests
- Add `VolumeEncryptionTest` (13 tests) and `MariaDbEncryptionTest` (5 tests) — unit tests for the new encryption services covering availability, status reporting, key file management, init scenarios, and error handling
- Add `UpdateCheckServiceTest` (9 tests) — unit tests for version comparison logic, prerelease ordering, and status reporting
- Add version compatibility tests — 7 unit tests for `InputValidator::checkVersionCompatibility()` (null, old, minimum, newer, stable versions) and 5 tests for `ValidationUtilityService::verifyVersionCompatibility()` (compatible, no version, old version, version update, skip update when unchanged)
- Fix chain drop test timing in sections 8 and 13 of `chainDropTestSuite.sh` — add `sleep 5` and increase wait timeouts to match passing sections, preventing flaky failures from tight timing on proposal delivery

---

## v0.1.4-alpha (2026-03-30)

### Added
- Allow `ip:port` format in `QUICKSTART` and `EIOU_HOST` environment variables — the embedded port is automatically extracted and used when `EIOU_PORT` is not explicitly set (e.g., `EIOU_HOST=192.168.1.100:8080` is equivalent to `EIOU_HOST=192.168.1.100` + `EIOU_PORT=8080`)
- Add optional requested credit limit to contact requests — when sending a contact request, the sender can specify the credit limit they would like the receiver to set for them. The receiver sees this value pre-filled in the credit limit field when accepting, with an info message ("Contact requested a credit limit of X"). If no value is sent, the receiver's default credit limit is used. Stored in the existing `credit_limit` columns on incoming pending `contact_currencies` rows (no schema change). CLI: `eiou add <addr> <name> <fee> <credit> <currency> [requested_credit] [message]`. API: new optional `requested_credit_limit` field in `POST /api/v1/contacts`. GUI: new "Requested Credit Limit" field on the add contact form
- Add GitHub Actions workflow to publish Docker image on release — automatically builds from `eiou.dockerfile` and pushes to Docker Hub (`eiou/eiou`) when a GitHub release is published with a version tag (`vX.Y.Z`, `vX.Y.Z-alpha`, `vX.Y.Z-beta`). Attaches the image tarball to the release and appends a Docker Hub reference to the notes

### Changed
- Contact request messages are now E2E encrypted for non-Tor transports — the optional description/message is no longer sent in cleartext with the initial contact request. Instead, the contact request is sent without the description (cleartext bootstrap for key exchange), then the description is delivered as a separate E2E encrypted follow-up using the recipient's public key obtained from the response. Tor addresses continue to use the single-phase flow (description included directly, protected by Tor transport encryption). The GUI warning about unencrypted messages has been removed

### Security
- Add nginx security headers to both HTTP and HTTPS server blocks — `X-Frame-Options: DENY` (prevent clickjacking), `X-Content-Type-Options: nosniff` (prevent MIME-type sniffing), `Referrer-Policy: strict-origin-when-cross-origin` (control referrer leakage). HSTS was already present on the HTTPS block. CSP is handled at the PHP application layer with per-request nonces

---

## v0.1.3-alpha (2026-03-24)

### Added
- Add `autoRejectUnknownCurrency` setting (default: enabled) — automatically rejects incoming contact requests when the requested currency is not in the node's `allowedCurrencies`. When disabled, unknown currency requests arrive as pending for manual review. Configurable via GUI toggle (Settings → Currency), CLI (`changesettings autoRejectUnknownCurrency true/false`), and API (`auto_reject_unknown_currency`)
- Auto-add currency on contact acceptance — when a user accepts a contact or currency request for a currency not in their allowed list, that currency is automatically added to `allowedCurrencies` and persisted to config. Covers all acceptance paths: GUI (accept contact, accept currency, accept all currencies) and CLI (`eiou add`)
- GUI warning for unknown currency requests — pending contact/currency requests show a yellow alert when the requested currency is not in the user's allowed list, informing them that accepting will auto-add it
- Add `hopBudgetRandomized` user setting to toggle geometric hop budget randomization — configurable via GUI toggle, CLI (`changesettings hopBudgetRandomized true/false`), API (`PUT /api/v1/system/settings` with `hop_budget_randomized`), and `EIOU_HOP_BUDGET_RANDOMIZED` env variable. Default: enabled. Disabling uses the full `maxP2pLevel` for every P2P transaction, maximizing routing depth at the cost of privacy. Intended for early/sparse trust graphs where reachability matters more than traffic analysis resistance; the toggle can be removed once the network has sufficient depth for geometric randomization to be always-on
- Show minimum transaction amount hint on send form — dynamically displays the currency's smallest valid amount (inferred from display decimals) below the amount/currency fields. Updates when currency changes or contact selection filters currencies. Also sets the HTML `min` and `step` attributes on the amount input to match
- Split amount storage: two BIGINTs (whole + frac) per amount column — replaces the single BIGINT representation (whole × 10^8 + frac). The `whole` column stores the integer part, `frac` stores the fractional part × 10^8 (e.g., 1234.56 → whole=1234, frac=56000000). This raises the maximum representable amount from ~92 billion to PHP_INT_MAX (~9.2 quintillion). Affected tables: `transactions`, `balances`, `p2p`, `rp2p`, `contact_currencies`, `contact_credit`, `capacity_reservations`
- `SplitAmount` value object class — stores amounts as `{whole, frac}` pairs. Factory methods: `fromString()` (precision-safe string parsing via bcmath), `fromMajorUnits()` (float), `fromMinorUnits()` (legacy int), `from()` (universal factory accepting string, float, int, array, or SplitAmount). Arithmetic: `add()`, `subtract()`, `multiplyPercent()`, `mulDiv()` — all use bcmath internally to avoid overflow. JSON serialization emits `{whole, frac}` for wire format
- `TRANSACTION_MAX_AMOUNT` enforcement — amounts above PHP_INT_MAX / 4 (~2.3 quintillion) are rejected at input validation. The headroom ensures fee accumulation across multi-hop P2P routes cannot overflow

### Changed
- Change `DISPLAY_DECIMALS` default from 4 to 2 — matches traditional currency display (e.g., $1,234.56)
- Replace date format free-text input with validated dropdown — 20 predefined formats (ISO, US, EU, short, unix timestamp) defined in `VALID_DATE_FORMATS`. GUI shows live-formatted examples. CLI interactive mode shows numbered list. Validation rejects arbitrary strings via whitelist
- Apply `displayDateFormat` to all GUI timestamps — transaction list, transaction/contact modals, wallet "last updated", DLQ timestamps all use the user's configured format via `formatTimestamp()` helper. Previously showed raw database timestamps
- Fix 16 hardcoded `.toFixed(2)` calls in JavaScript — all currency amounts in transaction modals, contact modals, transaction history, and P2P candidate routes now use `EIOU_DISPLAY_DECIMALS` global. Fee percentages remain at 2 decimals
- Simplify `displayDecimals` from per-currency map to a single global integer (0-8, default 2) — replaces the old `{"USD":2,"BTC":8}` JSON format with a plain number that applies to all currencies. Moved from Currency to Display section in both GUI and CLI. GUI now uses a dropdown instead of a textarea. Values are truncated (floored), not rounded, so displayed amounts never exceed the actual stored value. CLI: `changesettings displayDecimals 2`. API: `{"display_decimals": 2}`
- `Constants::getDisplayDecimals()` no longer takes a currency parameter — the display precision is global, not per-currency
- `formatCurrency()` uses display decimals (display-layer function); all internal calculations use `INTERNAL_PRECISION` (8)
- Add `displayDecimals()` and `cspNonce()` template helper functions in `Functions.php` — shorthand for `\Eiou\Core\Constants::getDisplayDecimals()` and `\Eiou\Utils\Security::getCspNonce()` in GUI templates, replacing 23 verbose fully-qualified calls
- Rename `conversionFactors` setting to `displayDecimals` — the setting now controls display decimal places only (e.g., `{"USD":2}` instead of `{"USD":100}`). Internal storage precision is fixed at 8 decimals for all currencies
- `Constants::getConversionFactor()` now always returns `INTERNAL_CONVERSION_FACTOR` (10^8) regardless of currency — the conversion factor is no longer per-node configurable
- `Constants::getCurrencyDecimals()` now always returns `INTERNAL_PRECISION` (8) — use `Constants::getDisplayDecimals($currency)` for display formatting
- `DISPLAY_CURRENCY_DECIMALS` fallback changed from 2 to 8 — undefined currencies default to full internal precision
- All major-to-minor unit conversions now use `bcmul()` (php-bcmath) instead of float multiplication — eliminates IEEE 754 precision loss for all amounts up to the 92 billion maximum. Fee calculation (`calculateFee`) also switched to `bcmul()`/`bcdiv()` for exact results at any transaction size. All conversions go through `CurrencyUtilityService::exactMajorToMinor()` which throws `RuntimeException` if bcmath is not installed
- Add `php-bcmath` to Dockerfile — required dependency for exact-precision currency arithmetic. Node will not start without it
- Decouple input validation precision from display decimals — `validateAmount()`, `validateAmountFee()`, `validateFeeAmount()`, and `validateCreditLimit()` now validate and truncate at `INTERNAL_PRECISION` (8 decimal places) instead of `DISPLAY_DECIMALS` (2 for USD). Display decimals only affect UI formatting, not input acceptance or storage. Amounts like 128.99999999 are now preserved instead of being rounded to 129
- All input validators return bcmath decimal strings — `validateAmount()`, `validateAmountFee()`, `validateFeeAmount()`, and `validateCreditLimit()` replace `floatval()` with `bccomp()`/`bcadd()` string operations, preserving full precision for values up to PHP_INT_MAX. Return type changed from `float` to `string` (e.g., `"128.99999999"`)
- Credit limit and amount callers updated from `SplitAmount::fromMajorUnits()` to `SplitAmount::from()` — handles the string return type from validators without float precision loss
- `RouteCancellationService::computeHopBudget()` now accepts an optional `$randomized` parameter that overrides the global constant, allowing per-user control from `P2pService`
- Replace `createInitialCredit(0)` with actual credit calculation at all acceptance paths (#768) — `acceptContact()`, currency acceptance, unblock, wallet restore auto-accept, and GUI currency acceptance all now compute `(sentBalance - receivedBalance) + creditLimit`
- Allow minimum fee (`minFee`) to be set to 0 — enables free relaying for friends and family while keeping the default at 0.01. Fee percent was already allowed at 0%; now the minimum fee floor can also be removed. Since fees are set per contact, operators can relay free for trusted contacts while charging fees for others. No technical issues: fees are excluded from hash/txid generation, all division-by-zero paths are guarded, and balance updates handle zero fees correctly

### Fixed
- Fix SplitAmount type mismatches in sync responses, backup restore, chain drop, and GUI display — multiple code paths still passed raw integers or floats where `SplitAmount` was expected after the split-column migration
- Fix PHP 8.x bcmath `ValueError` on scientific notation and whitespace — `InputValidator` now sanitizes input strings (strips whitespace, converts scientific notation to decimal) before passing to `bccomp()`/`bcadd()` which reject non-numeric formats in PHP 8.x
- Fix `TypeError` in ping handler preventing seedphrase restore sync — `ContactStatusService` passed wrong type to ping response builder, causing restored contacts to fail re-synchronization
- Fix sub-minimum currency amounts passing validation — amounts like 0.001 USD passed the `> 0` check, then rounded to 0.00 and were accepted as valid zero-dollar transactions. `validateAmount()` and `validateAmountFee()` now re-check after rounding and reject amounts below the currency's smallest unit (0.00000001 at internal precision). Credit limits and minimum fees still allow zero. Error messages include the currency-specific minimum
- Fix credit limit corruption for large values — `floatval()` converted PHP_INT_MAX to scientific notation (9.2233720368548E+18), which `SplitAmount::fromMajorUnits()` then mangled by splitting on the decimal point of the scientific notation string (9223372036854775807 → 9.22). `validateCreditLimit()` now uses bcmath string operations and `SplitAmount::fromString()` for precision-safe parsing
- Fix `SplitAmount::fromMajorUnits()` scientific notation bug — `(string)` cast on large floats produced "9.2233720368548E+18" which `explode('.')` split incorrectly. Now uses `number_format()` to produce plain decimal strings
- Fix `SplitAmount::add()` overflow detection — the post-addition overflow check (`$whole < $this->whole`) failed when PHP silently converted `PHP_INT_MAX + positive_int` to float, bypassing the comparison. Now checks before adding (`$this->whole > PHP_INT_MAX - $other->whole`). Throws `OverflowException` instead of `TypeError`
- Fix P2P crash on amount overflow — when relay fee calculation pushed amounts past PHP_INT_MAX, `SplitAmount::add()` threw an unhandled exception crashing the request handler with a PHP fatal error and empty response. `checkAvailableFunds()` now catches `OverflowException` and returns an `insufficient_funds` rejection with a log entry
- Fix `addCurrencyToContact()` type mismatch — passed `(int) $credit` where `?SplitAmount` was expected. Now properly creates a `SplitAmount` via `SplitAmount::from()`
- Fix API endpoints missing `InputValidator` validation that CLI/GUI already enforce:
  - **`POST /wallet/send`**: add `validateAddress()`, `validateNotSelfSend()`, `validateCurrency()`, `validateMemo()` for description — previously only validated amount
  - **`POST /contacts`**: add `validateAddress()`, `validateContactName()`, `validateFeePercent()`, `validateCreditLimit()`, `validateCurrency()`, `validateMemo()` for description — previously no field validation at all
  - **`PUT /contacts/:address`**: add `validateContactName()`, `validateCurrency()`, `validateFeePercent()`, `validateCreditLimit()` — previously accepted raw unvalidated values
  - **`GET/POST /p2p/candidates`, `/p2p/approve`, `/p2p/reject`**: add `validateTxid()` for hash parameters
- Fix pre-existing `RouteCancellationServiceTest` constructor mismatch — updated to pass `RepositoryFactory` required by current constructor signature
- Send available credit on contact acceptance (#768) — when a contact request is accepted, both nodes now exchange their calculated available credit in the E2E-encrypted acceptance message and acknowledgment. This eliminates the gap where available credit shows as 0 until the first ping/pong cycle or transaction. For new contacts the available credit equals the credit limit; for re-added contacts with prior transactions it reflects the real balance
- Include available credit in mutual acceptance responses (#768) — when both sides sent contact requests simultaneously, the inline `buildMutuallyAccepted` response now includes credit data
- Include available credit in wallet restore pong (#768) — when a restored contact is auto-accepted during a ping, the pong response now includes the calculated credit instead of an empty array
- Fix pre-existing test constructor mismatches in `ContactManagementServiceTest` and `MessageServiceTest` (#768) — updated to match current constructor signatures for `RepositoryFactory` and `SyncTriggerInterface` parameters

### Docs
- Rewrite `CURRENCY_CONFIGURATION.md` for global display decimals — updated all GUI/CLI/API examples, added truncation (floor) explanation, removed per-currency format references
- Update `CLI_REFERENCE.md` — move `displayDecimals` from Currency to Display section, update example from JSON to integer
- Update `GUI_REFERENCE.md` — move `displayDecimals` from Currency to Display category
- Add `displayDecimals` to `info changesettings` help text with truncation/floor description
- Add minimum transaction amount (inferred) to `CURRENCY_CONFIGURATION.md` overview and conversion factors table
- Update `API_REFERENCE.md` validation error codes table with new codes: `invalid_address`, `self_send`, `invalid_currency`, `invalid_name`, `invalid_fee`, `invalid_credit`, `invalid_description`, `invalid_hash`, `missing_currency`
- Update `API_REFERENCE.md` field descriptions for `POST /wallet/send`, `POST /contacts`, and `PUT /contacts/:address` to document validation constraints (format, ranges, precision)

### Tests
- Fix 285 of 412 pre-existing unit test failures from SplitAmount migration — updated constructor signatures (P2pService, ContactStatusService, CleanupService, ChainDropService, ChainVerificationService), replaced removed setter calls with constructor injection (setSyncTrigger, setP2pRelayedContactRepository, setTransactionChainRepository), fixed mock return types for SplitAmount, updated getPreviousTxid → getPreviousTxidsByCurrency mock expectations
- Fix batch transaction performance test race condition — the chain reset between single tx (4.1) and batch (4.2) deleted the tx while the daemon was still async-completing it, causing a broken `previous_txid` on re-insertion. Fix: let batch chain naturally from the single tx instead of resetting, wait for queue settlement, and fix dangling pointers before batch
- Improve batch transaction performance test reliability — add queue processing between sends, reset chain state before benchmarks, increase timing margins, poll chain validity instead of transaction status
- Add `EIOU_TEST_MODE` flag to bypass rate limiting in performance tests
- Clear `held_transactions` and `capacity_reservations` in performance test chain reset to prevent interference from prior test suites

### Removed
- Remove `CURRENCY_DECIMALS` constant and `currencyDecimals` setting — decimal places are now inferred from the conversion factor via `log10(factor)` (e.g. factor=100 → 2 decimals, factor=100000000 → 8 decimals). This eliminates a source of misconfiguration where conversion factors and decimal places could be set inconsistently. The `DISPLAY_CURRENCY_DECIMALS` fallback (default: 2) remains for unknown currencies. Removed from GUI settings, CLI `changesettings`, and `getConfigurableDefaults()`


---

## 2026-03-15
P2P fee fix, duplicate contact prevention, available credit on completion, watchdog fix, documentation updates, P2P inquiry token authentication.

### Added
- Include available credit in transaction completion responses (#763) — the completing node calculates the sender's available credit and includes it with a timestamp in the completion payload. The sender saves it only if the timestamp is newer than what's stored, preventing out-of-order completions from overwriting fresher values
- Relay nodes attach their own credit calculation when forwarding completion messages upstream, so each node in a P2P chain receives credit info from its direct contact (#763)
- Ping/pong credit saves now use the same timestamp guard via `upsertAvailableCreditIfNewer()` (#763)

### Docs
- Fix backup/restore and troubleshooting commands to use `docker exec` on the running container instead of `docker run` with a separate image (#761)

### Security
- Add P2P inquiry token authentication (#757) — prevents relay nodes from forging completion inquiries to end-recipients. The P2P hash now includes a hash-committed `inquiry_token` (`sha256(inquiry_secret)`). Only the original sender knows the pre-image (`inquiry_secret`), which is included in the completion inquiry for end-recipient verification. Relay nodes can see the token but cannot reverse it, and swapping the token breaks the P2P hash that every node validates
- Completion inquiries now require `inquiry_secret` — `checkMessageValidity` rejects inquiry messages that lack the secret when the P2P has an `inquiry_token`, closing the relay forgery gap where the address-based fallback allowed any node to pass validation

### Fixed
- Fix originator charging itself a relay fee on P2P transactions (#764) — `handleRp2pRequest()` calculated and added a relay fee for every node receiving an RP2P response, including the originator. This caused A→B→C payments to overcharge the sender and overpay the end-recipient
- Fix duplicate contact transaction inserted when receiving repeated contact requests (#762) — `contactTransactionExistsForReceiver()` had sender/receiver swapped in its query, so the duplicate check never found the existing transaction
- Fix `/tmp/tor-gui-status` permission denied in watchdog (#765) — PHP (www-data) creates the file with 0644 permissions, then the watchdog (root) fails to overwrite it. Added `write_tor_gui_status()` helper that removes the file before writing, and PHP now sets 0666 permissions after writing
- Fix `CliService::displayPendingContacts()` crash (#756) — `$this->container` property didn't exist, replaced with stored `$repositoryFactory`
- Fix `cliCommandsTest.sh` report debug JSON assertions (#756) — `"success":true` (no space) didn't match `JSON_PRETTY_PRINT` output `"success": true`
- Fix `cliCommandsTest.sh` and `apiEndpointsTest.sh` checking for removed `display_currency_decimals` key (#756) — replaced with `currency_decimals` in CLI test, removed from API test
- Fix sync test cascading failures (#756) — replace naive description-pattern cleanup with chain-aware reset to contact-only state between tests. Clears non-contact transactions, repairs `previous_txid` links, and wipes related table residue (balances, p2p, chain_drop_proposals, etc.)
- Fix P2P completion inquiry description stripped before delivery (#756, #757) — `signWithCapture()` removed `description` from all non-send/non-contact messages, including completion inquiries. Now preserves description for `type=message` with `inquiry=true`
- Fix `inquiry_token` and `inquiry_secret` missing from `P2pRepository::$allowedColumns` whitelist (#757) — caused all P2P inserts to fail silently, breaking P2P routing entirely
- Fix `inquiry_secret` not stored on originator (#757) — `sendP2pRequest()` passed the wire payload (which correctly excludes the secret) to `insertP2pRequest()`, losing the secret. Now restores the secret from prepared data before local DB insert
- Remove stale `changesettings displayCurrencyDecimals` integration test (#756) — setting was replaced by `currencyDecimals` JSON map
- Remove stale `scripts/alpha-warning.txt` (#755) — file was already moved to `scripts/banners/`

### Changed
- P2P hash formula changed from `sha256(receiver_address + salt + time)` to `sha256(receiver_address + salt + time + inquiry_token)` (#757)
- P2P table schema: added `inquiry_token` (propagates through relay chain) and `inquiry_secret` (stored only on originator) columns (#757)
- Sync test P2P output now shows B's chain recovery counts alongside P2P delivery result (#759)
- Update unit tests for `currencyDecimals` rename (#756)

## 2026-03-14 
Open alpha launch prep, currency configuration, settings GUI cleanup, legal notices, debug fix.

### Fixed
- Fix debug panel showing "No debug entries found" despite `APP_DEBUG=true` — `Logger::registerDebugService()` was never called, so all debug entries were silently discarded. Added registration in `ServiceContainer::wireAllServices()`
- Fix Recent Transactions Refresh button smaller than Failed Messages Refresh button — use consistent `btn-sm` class on both
- Fix Allowed Currencies textarea shorter than Conversion Factors and Currency Decimal Places textareas — match `rows="4"`
- Fix Add Contact message label and warning hint entirely red — only the warning icon and "WARNING:" text are now red; "(optional)" and the rest of the hint text are default color. Warning icon moved after "(optional)"
- Fix `auto_accept_restored_contact` missing from API GET `/api/v1/system/settings` response — setting was writable via PUT but not returned in GET

### Docs
- Audit and update CLI_REFERENCE.md: add missing settings (`autoChainDropAcceptGuard`, `conversionFactors`, `currencyDecimals`, Tor circuit settings), remove stale `displayCurrencyDecimals`, fix settings count
- Audit and update API_REFERENCE.md: add Tor circuit fields to GET response example and PUT table, remove stale `display_currency_decimals`
- Audit and update GUI_REFERENCE.md: document all 8 Advanced Settings categories, add contact form `description` field
- Audit and update DOCKER_CONFIGURATION.md: add missing env vars (`APP_DEBUG`, `EIOU_DEFAULT_TRANSPORT_MODE`, `EIOU_TOR_FORCE_FAST`, `EIOU_HOP_BUDGET_RANDOMIZED`)
- Audit and update CLI_DEMO_GUIDE.md: fix stale `docker-compose-4line.yml` references to `tests/old/compose-files/`, add optional `[message]` parameter to `eiou add` syntax, add `[currency]` to `eiou update` quick reference
- Add "Before Creating a New Issue" section to CONTRIBUTING.md: search open/closed issues and CHANGELOG before filing duplicates
- Clarify QUICKSTART addresses are Docker-internal only in README.md, docker-compose.yml, and DOCKER_CONFIGURATION.md — note that external access requires `EIOU_HOST`, `EIOU_PORT`, and proper SSL
- Add reverse proxy (nginx, Caddy, Traefik) and Cloudflare Tunnel as SSL alternatives to DOCKER_CONFIGURATION.md and README.md
- Add startup log warning when QUICKSTART is used without EIOU_HOST — alerts users that HTTP/HTTPS addresses are Docker-internal only and not reachable from outside
- Clarify `P2P_SSL_VERIFY` behavior with self-signed certs across README.md, docker-compose.yml, and DOCKER_CONFIGURATION.md — QUICKSTART self-signed certs are rejected by default, expanded descriptions in quick reference tables and SSL section
- Add startup log warning when HTTPS between QUICKSTART nodes will fail due to `P2P_SSL_VERIFY=true` with self-signed certs — only shown when `P2P_SSL_VERIFY` is not disabled and `P2P_CA_CERT` is not set
- Add `eiou report debug` to CLI_REFERENCE.md: usage, options, examples, report contents
- Add unit tests for `DebugReportService` (17 tests), `ServiceContainer` getter tests (2 tests), and integration tests for `eiou report debug` in `cliCommandsTest.sh` (5 tests)

### Changed
- Currency codes now accept 3-9 uppercase alphanumeric characters (previously fixed at exactly 3). Validated with `/^[A-Z0-9]+$/` regex. Input is always uppercased
- Make `conversionFactors` and `currencyDecimals` configurable through UserContext (GUI, CLI, API) instead of hardcoded constants. Settings persist in the config volume across container rebuilds. `Constants::getConversionFactor()` and `Constants::getCurrencyDecimals()` now check UserContext first with fallback to defaults
- Replace all direct `Constants::CONVERSION_FACTORS[$currency]` array accesses with `Constants::getConversionFactor($currency)` method calls to route through configurable settings
- Move Allowed Currencies from Network to new Currency category in GUI Advanced Settings
- Bump `APP_VERSION` from `0.0.1` to `0.1.0-alpha` (used in `/api/health` endpoint)
- Set `APP_DEBUG=true` by default for the alpha/development phase (overridable via `APP_DEBUG=false` env var)
- Remove redundant `displayCurrencyDecimals` global setting — per-currency decimals map now handles all formatting. The setting was never used for actual display, only shown in settings views
- Declutter main Wallet Settings page: move P2P routing level, P2P expiration, TX delivery expiry, max output lines to Advanced Settings > Network; move auto-refresh, auto-accept P2P, auto-backup toggles to Advanced Settings > Feature Toggles
- Move GUI/CLI Max Output Lines from Network dropdown to Display dropdown in Advanced Settings
- Move API CORS Origins to end of Network dropdown in Advanced Settings (largest input box last)
- Convert `eiou.dockerfile` to multi-stage build (DOCK-04): Composer and `unzip` are now isolated in a builder stage; only the pre-built `vendor/` directory is copied into the runtime image. Removes ~20-30MB of build-only tooling from the final image and eliminates Composer as a post-compromise attack vector
- Add PHP type hints across codebase (CQ-03): add return type `: string` and parameter types to all 90+ `OutputSchema.php` functions; add typed properties and constructor parameter types to `ApiKeyService`, `ApiAuthService`; add return types and parameter types to `DebugService` methods and `DebugServiceInterface`; add `mixed` type to `ApiKeyService::validateRateLimit()` parameter
- Increase legacy demo sleep timers from 15s to 160s across all 48 test files in `tests/old/` (HTTP, HTTPS, Tor) as a safety margin for Tor connection readiness
- Consolidate alpha warning and legal notice into a single combined startup message (`scripts/banners/alpha-warning.txt`); remove separate `legal-notice.txt`

### Added
- Add `eiou report debug` CLI command for generating debug reports from the command line. Supports optional issue description and `--full` flag for complete log history. Outputs JSON report to `/tmp/`
- Extract `DebugReportService` from `SettingsController` — shared service used by both the GUI debug report buttons and the new CLI `report` command, eliminating ~200 lines of duplicated report generation logic
- Add Currency category to GUI Advanced Settings dropdown with fields for conversion factors, currency decimals, and allowed currencies
- Add CLI `changesettings` handlers for `conversionFactors` (JSON) and `currencyDecimals` (JSON)
- Add `docs/CURRENCY_CONFIGURATION.md`: guide for adding new currencies via GUI, CLI, and API with persistence and example configurations
- Add optional message/description field to contact requests: GUI (Add Contact form), CLI (`eiou add ... "message"`), and API (`description` field). The message is sent with the contact request and stored in the contact transaction on both sides. Descriptions are included in the signed payload for direct sends and contact requests but stripped from P2P relay messages for privacy (#739)
- Add `autoAcceptRestoredContact` toggle (env: `EIOU_AUTO_ACCEPT_RESTORED_CONTACT`, default: `true`) to control whether contacts are auto-accepted on wallet restore when transaction history proves a prior relationship. When disabled, restored contacts stay pending for manual review. Configurable via CLI, GUI, and API. Restored contacts are named `RestoredContact<N>` for identification
- Add alpha warning and legal notice to GUI login screen with collapsible Important dropdown
- Add warning icon next to "(optional)" in Add Contact message label to clarify the encryption warning applies to the message field only
- Move banner text files (`alpha-warning.txt`, `legal-notice.txt`) from `scripts/` to `scripts/banners/` to separate static content from executable scripts

### Fixed
- Fix fee input step validation in contact forms: change `step="0.1"` to `step="0.01"` so values like 0.01% (the default fee) are accepted. Affected: add contact form, edit contact form, and pending contact accept forms
- Fix integration and unit test failures from ARCH-05 RepositoryFactory migration: update all shell test scripts and PHPUnit tests to use `getRepositoryFactory()->get()` instead of removed direct repository getters on `ServiceContainer`
- Fix garbled namespace in `addContactsTest.sh` (`\\Eiou\\Core\\\Eiou\Core\Application` → `\Eiou\Core\Application`)
- Fix `curlErrorHandlingTest.sh` timeout expectations to match current constants (TOR_TRANSPORT_TIMEOUT: 45s, TOR_CONNECT_TIMEOUT: 20s) and grep patterns to match `DELIVERY_ERROR` constant
- Fix `seedphraseTestSuite.sh` require paths from `${EIOU_DIR}/src/...` to `${BOOTSTRAP_PATH}` for Composer autoloading
- Fix GUI crash from removed `get*Repository()` methods on `ServiceContainer` (regression from ARCH-05 PR #717): migrate 6 calls in `index.html` and `settingsSection.html` to use `getRepositoryFactory()->get()` — affected `getP2pRepository`, `getRp2pRepository`, `getRp2pCandidateRepository`, `getDeadLetterQueueRepository`, `getTransactionRepository`, `getDebugRepository`
- Fix `/var/log/eiou/app.log` permission denied: move log directory creation before PHP-FPM start so the file is owned by `www-data` before any PHP worker writes to it
- Fix stale volume names in 48 demo files (`tests/old/demo/`): volume delete commands referenced old `*-files` volumes instead of current `*-config`, causing actual config volumes to persist across resets
- Fix `RateLimiterRepository` crash: extend `AbstractRepository` so it can be created via `RepositoryFactory` (regression from ARCH-05 PR #717). All four processors (P2P, Transaction, Cleanup, ContactStatus) were crash-looping on startup
- Fix missing `ContactStatusService::setChainDropService()` setter: wiring call existed in `ServiceContainer::wireCircularDependencies()` but the method was never added (regression from ARCH-05 PR #717)
- Fix jagged text in login screen Important dropdown: hard-wrapped lines from `alpha-warning.txt` were preserved as `<br>` via `nl2br()`, causing awkward breaks on narrow screens. Now joins lines into flowing paragraphs
- Remove duplicate legal notice section below the login form — all content is now in the alpha warning Important dropdown

### Docs
- Update `tests/README.md` with benchmark documentation, new topologies (`collisions`, `collisionscluster`), new test subsets (`bestfee`, `mutual`), complete test file listing, and current environment variables
- Rewrite alpha warning and legal notice text for open alpha launch: decentralization emphasis, IOU irreversibility warning, any-unit-of-account framing, First Amendment case law precedents
- Update README.md alpha warning and remove archived banner SVGs
- Standardize branding: rename "EIOU" to "eIOU" in all prose text across 58 files (docs, comments, configs, tests). Preserves uppercase in code values (currency codes, SSL cert fields, PHP namespaces)
- Update CONTRIBUTING.md for open alpha external contributions: update Docker commands, repo structure, architecture patterns, PR checklist (add CHANGELOG requirement), and docs table
- Add docker-compose.yml setup note to CONTRIBUTING.md
- Document currency code length change (3-9 alphanumeric) in CLI_REFERENCE, API_REFERENCE, and ERROR_CODES
- Remove stale `.dockerignore` entries for config files that moved to runtime volume generation
- Update MySQL overview files (`tests/mysql.txt`, `tests/mysql - easy overview.txt`): add 4 new tables (`api_nonces`, `capacity_reservations`, `contact_currencies`, `route_cancellations`); remove stale `fee_percent`/`credit_limit`/`currency` columns from contacts (moved to `contact_currencies`); add missing columns across p2p (`rp2p_amount`, `expiration`), transactions (`expires_at`), message_delivery (`max_retries`, `next_retry_at`, `last_response`), held_transactions (`max_retries`, `last_sync_attempt`, `next_retry_at`), chain_drop_proposals (`previous_txid_before_gap`, `gap_context`, `updated_at`), delivery_metrics (`created_at`), dead_letter_queue (`last_retry_at`), contacts (`created_at`). All 23 tables now validated against live database

## 2026-03-11 
Codebase audit remediation (Phases 1-5, ARCH-04, DOCK-05, ARCH-05/01).

### Security
- Move dbconfig.json password encryption from startup.sh into `Wallet::generateWallet()` and `Wallet::restoreWallet()`, running immediately after master key initialization. Eliminates the window where plaintext DB password persisted on disk between Application constructor and next container restart. Success message logged for operator confirmation
- Default `APP_DEBUG` to `false` (secure-by-default). Debug mode now requires explicit opt-in via `APP_DEBUG=true` environment variable. Updated `DebugService`, `Security`, and GUI settings to use `Constants::isDebug()` for env var override support
- Fix TOCTOU race condition in `BackupService` credential temp file creation: set restrictive umask before `tempnam()` so the file is created with `0600` permissions atomically, instead of chmod after creation
- Add logging to silent catch blocks across database repositories, services, and utilities. Previously, exceptions in `TransactionRepository`, `TransactionContactRepository`, `QueryBuilder`, `TorCircuitHealth`, `ContactSyncService`, and `ConfigCheck` were swallowed without any logging, masking potential database and configuration failures
- Fix insecure SSL temp files in `CliSettingsService::regenerateSslCertificate()`: use `tempnam()` with `/dev/shm` for unique file names, set restrictive umask for 0600 permissions at creation, wrap in try/finally for guaranteed cleanup (SEC-04)
- Add input validation for QUICKSTART, EIOU_HOST, EIOU_NAME, and EIOU_PORT environment variables in startup.sh to prevent injection via crafted values (DOCK-08)

### Added
- PHPStan static analysis at level 1 with CI workflow (`.github/workflows/phpstan.yml`, `phpstan.neon`)
- Container image vulnerability scanning via Trivy in integration tests workflow
- Dependabot configuration for Composer and GitHub Actions dependency updates (`.github/dependabot.yml`)
- `.env.example` template documenting all configurable environment variables with defaults
- Extended `.gitignore` with OS artifacts, sensitive files, logs, and database patterns
- OCI labels in Dockerfile (title, description, source, vendor, license, base image)
- Unauthenticated `/api/health` endpoint for Docker healthcheck and load balancers — checks database connectivity and message processor status, returns JSON with `ok`/`degraded` status (ARCH-10). Docker healthcheck updated from `/gui/` to `/api/health`
- Environment variable overrides for PHP-FPM and Nginx service tuning: `PHP_FPM_PM`, `PHP_FPM_MAX_CHILDREN`, `PHP_FPM_MAX_REQUESTS`, `NGINX_WORKER_PROCESSES`, `NGINX_WORKER_CONNECTIONS`, `NGINX_RATE_LIMIT_*`, `NGINX_CONN_LIMIT`, `NGINX_CLIENT_MAX_BODY`. Applied at boot by startup.sh — no volume mount needed, configure via docker-compose.yml environment variables

### Changed
- Extract `RepositoryFactory` to centralize repository instantiation and caching (ARCH-05). Removes 25 repository getter methods from `ServiceContainer` (~345 lines). All callers migrated to use `getRepositoryFactory()->get(XxxRepository::class)` directly
- Refactor circular dependency wiring in `ServiceContainer` (ARCH-01). Move 27 repository setter calls and 11 `SyncTriggerInterface` setter calls from `wireCircularDependencies()` into service constructors via `RepositoryFactory` and `SyncServiceProxy` injection. Reduces `wireCircularDependencies()` from 73 setter calls to 35 (service-to-service only). Remove corresponding setter methods from 16 service classes and 6 interface contracts. PSR-11 `get()`/`has()` methods updated to use `RepositoryFactory` instead of removed `$repositories` array
- Separate source code from user data in Docker layout (DOCK-05): code moves from `/etc/eiou/` volume to `/app/eiou/` image filesystem. Config data stays at `/etc/eiou/config/` (volume). Eliminates startup source file sync, composer autoloader regeneration, and `/app/eiou-src-backup` duplication. Image upgrades now apply code immediately without boot-time sync. Volume mounts, nginx config, test files, and documentation updated for new paths
- Refactor `CliService` God Class (3,784 → 1,136 lines, 70% reduction) by extracting four focused sub-services (ARCH-04): `CliSettingsService` (1,328 lines), `CliHelpService` (929 lines), `CliP2pApprovalService` (414 lines), `CliDlqService` (285 lines). CliService delegates to sub-services
- Modernize `array()` syntax to short `[]` syntax in `TransportUtilityService.php` (callable and literal array forms)
- Fix misspelled function names: `outputAdressContactIssue` → `outputAddressContactIssue`, `outputAdressOrContactIssue` → `outputAddressOrContactIssue`, `outputContactSuccesfullysynced` → `outputContactSuccessfullySynced`. Updated all call sites in `P2pService`, `SyncService`, and tests
- Extract session key magic strings to `SessionKeys` constants class (`Session.php`, `Functions.php`, `MessageHelper.php`, `SecurityInit.php`)
- Replace all `print_r()` calls (29 occurrences) in output functions with `json_encode()` for consistent structured logging. Prevents internal data structure exposure in debug output (`OutputSchema.php`, `MessagePayload.php`, `Rp2pPayload.php`)
- Remove all `@` error suppression operators (33 occurrences across 11 files) with proper error handling: `file_exists()` checks before `unlink()`, return value checks for `file_get_contents()`/`fopen()`, and direct calls where `is_dir()` guards exist
- Increase container resource limits from 512MB/256MB to 1024MB/512MB (memory limit/reservation) and from 1.0 to 2.0 CPU cores to prevent OOM kills under load with nginx + PHP-FPM + MariaDB + Tor

## 2026-03-07 -- 2026-03-10 
Multi-currency, E2E encryption, nginx migration, route cancellation, Tor circuit health.

### Security
- Replace `exec()` SSL certificate generation with PHP native `openssl_pkey_new()`/`openssl_csr_sign()` functions, eliminating command injection risk via OpenSSL config file. Add strict hostname validation (alphanumeric, dots, hyphens only)
- Fix N+1 query pattern in contact search: batch-load credits and balances in 2 queries instead of 2*N individual queries per search result
- Add `set -u` (undefined variable protection) and `set -o pipefail` to startup.sh entrypoint for fail-fast on undefined variables and pipe failures
- Quote variable expansions in startup.sh `docker exec` commands to prevent word splitting
- Add `composer audit` step to CI pipeline to catch known PHP dependency vulnerabilities
- Replace Apache with nginx + PHP-FPM: connection-level rate limiting (`limit_req_zone` per-IP: 30r/s general, 10r/s API, 20r/s P2P), concurrent connection limits (50/IP via `limit_conn`), and aggressive timeouts (10s header/body) enforce network-layer protection before PHP executes. Apache's only rate limiting was the application-level PHP `RateLimiterService` which ran after full request processing. SSL paths moved from `/etc/apache2/ssl/` to `/etc/nginx/ssl/`, PHP-FPM communicates via unix socket at `/run/php/php-fpm.sock`
- E2E encryption expanded to all contact messages. Every message sent to a known contact (P2P, RP2P, relay transactions, pings, route cancellations, messages) is now fully encrypted — ALL content fields including `type` are inside the encrypted block, making all message types indistinguishable on the wire. Only contact requests (`type=create`) are excluded since the recipient may not be a contact yet. Messages to non-contacts (e.g. transaction inquiry to P2P end-recipient, contact acceptance inquiry to pending contacts) gracefully fall back to cleartext when the recipient's public key is unavailable. Updated `signWithCapture()`, `send()`, `sendBatch()`, `sendMultiBatch()` to pass recipient address for contact public key lookup
- End-to-end payload encryption for direct transactions using ECDH + AES-256-GCM. Sensitive fields (amount, currency, txid, previousTxid, memo) are encrypted with the recipient's EC public key before signing. Uses ephemeral key pairs for forward secrecy. Encrypt-then-sign design allows signature verification without decryption. New `PayloadEncryption` class, with decryption in `index.html` and encryption in `TransportUtilityService::signWithCapture()`. P2P relay transactions remain unencrypted (relays need cleartext amount for fee calculation)
- Add `signed_message_content` column to transactions table for E2E encrypted transaction sync signature verification (signature was over encrypted content, not plaintext)
- Preserve `signed_message_content` through the full data flow: capture raw signed JSON at signing time in `signWithCapture()`, propagate through `send()`/`sendBatch()`/`sendMultiBatch()` and `MessageDeliveryService`, store via `updateSignatureData()`, include in sync responses from `formatTransactionForSync()`, and store on the receiving end in `syncTransactionChain()`. Without this, E2E encrypted transactions failed signature verification during chain sync recovery
- Derive master encryption key deterministically from BIP39 seed (M-13). Master key is now recoverable via seed phrase restore instead of being randomly generated. Wallet generate and restore both produce identical master keys from the same seed.
- Remove master key SHA-256 hash from seedphrase test output (sensitive information should not be displayed in logs)

### Added
- Maintenance mode lockfile (`/tmp/eiou_maintenance.lock`): created during startup before source file sync and database migrations, removed after all initialization is complete. HTTP entry points (API, GUI, P2P) return 503 with `Retry-After: 30` while the lockfile exists, preventing partial code execution against mid-sync source files or mid-migration database schema
- Automatic pre-shutdown backup: `graceful_shutdown()` creates a database backup before stopping processors and services. Ensures a recent backup exists when upgrading via `docker compose up -d --build` without requiring manual `eiou backup create`
- ContactStatus processor added to graceful shutdown: SIGTERM signal, PID tracking, and lockfile cleanup (`/tmp/contact_status.pid`) now included alongside P2P, Transaction, and Cleanup processors
- Tor circuit health tracking (`TorCircuitHealth`): per-.onion address failure tracking with cooldown. After consecutive Tor timeouts to the same address (default: 2), further attempts are skipped for a cooldown period (default: 5 min) to avoid wasted retries and Tor circuit overload. File-based in `/tmp` so state clears on container restart
- Transport fallback on Tor failure: when a Tor delivery fails and the contact has an HTTP/HTTPS address, automatically fall back to an alternative transport. Controlled by `torFailureTransportFallback` setting (default: enabled). Can be disabled via `eiou changesettings torFailureTransportFallback false` for Tor-only operation
- `torFallbackRequireEncrypted` setting (default: `true`): restricts Tor failure fallback to HTTPS only, never plain HTTP. When enabled, if a contact has no HTTPS address, delivery fails gracefully rather than downgrading to unencrypted HTTP. Preserves transport encryption when Tor is unavailable. Configurable via CLI (`eiou changesettings`), GUI settings panel, and REST API
- New configurable settings: `torCircuitMaxFailures` (1-10), `torCircuitCooldownSeconds` (60-3600), `torFailureTransportFallback` (true/false), `torFallbackRequireEncrypted` (true/false). Available via `eiou changesettings` and displayed in `eiou settings`
- Multi-currency infrastructure: per-currency conversion factors (`CONVERSION_FACTORS`), decimal places (`CURRENCY_DECIMALS`), and helper methods `getConversionFactor()` / `getCurrencyDecimals()` in Constants. Currently USD-only; adding a new currency requires only adding map entries
- Database amount columns changed from INT to BIGINT: `contact_credit.available_credit`, `contact_currencies.credit_limit`, `balances.received/sent`, `transactions.amount`, `p2p.amount/my_fee_amount/rp2p_amount`, `rp2p.amount`, `rp2p_candidates.amount/fee_amount`, `capacity_reservations.base_amount/total_amount`. Supports large-value currencies without overflow
- Route cancellation service: actively cancels unselected P2P routes after best-fee selection to immediately release reserved credit capacity. CleanupService TTL expiry remains as natural fallback
- Capacity reservation table (`capacity_reservations`): dedicated table tracking credit reserved at each relay hop with base_amount and total_amount (including fees), replacing implicit credit hold calculation from P2P table
- Route cancellation audit table (`route_cancellations`): tracks cancellation messages sent to unselected routes
- Randomized hop budget: geometric distribution (30% stop probability per hop) for hop budget initialization preventing traffic analysis attacks. Integrated into P2pService originator hop calculation via `RouteCancellationService::computeHopBudget()`. Minimum hop budget enforced via `HOP_BUDGET_MIN_RATIO` (default 0.5) to prevent uselessly low budgets
- New `route_cancel` message type for inter-node route cancellation delivery
- Full cancel downstream propagation: `broadcastFullCancelForHash()` on P2pService broadcasts `route_cancel` with `full_cancel=true` to all accepted contacts, enabling originator and relay cancellation to propagate through the entire route chain
- `EIOU_DEFAULT_TRANSPORT_MODE` env variable: overrides the default transport mode (`tor` in production) used when sending to a contact name. Test buildfiles default to `http` so best-fee tests don't silently use Tor's force-fast mode
- `EIOU_TOR_FORCE_FAST` env variable: when set to `false`, disables the automatic fast-mode override for Tor routes, allowing best-fee mode testing over Tor topologies
- `EIOU_HOP_BUDGET_RANDOMIZED` env variable: when set to `false`, disables geometric distribution and returns `maxP2pLevel` deterministically. Test buildfiles default to `false` for predictable routing depth assertions
- `EIOU_AUTO_CHAIN_DROP_ACCEPT_GUARD` env variable and per-node setting: separate toggle for the balance guard that runs before auto-accepting chain drop proposals. Default `true` (guard enabled). Set to `false` for unconditional auto-accept when `EIOU_AUTO_CHAIN_DROP_ACCEPT=true`. Configurable via CLI (`changesettings autoChainDropAcceptGuard`), GUI settings toggle, and REST API
- Integration test `routeCancellationTest.sh` (13 tests): service wiring, table existence, hop budget distribution, capacity reservation creation/release, cancel timing, relay status propagation, originator downstream cancel and multi-route safety verification
- Shared P2P diagnostic functions in `testHelpers.sh`: `get_processor_health`, `get_p2p_state`, `get_p2p_timing`, `dump_p2p_diagnostic` — reusable across all test suites for debugging P2P routing issues
- Shared backup helper functions moved to `testHelpers.sh`: `cleanup_backups`, `count_backups`, `verify_tx_exists` — previously defined inline in `chainDropTestSuite.sh` after first use (causing `command not found` errors)
- Best-fee routing test: diagnostic output on failure AND on slow success (>60s), showing per-hop P2P status, timing, candidate counts, and processor health
- Per-currency transaction chain validation: ping sends `prevTxidsByCurrency` map (one chain head per currency) instead of single `prevTxid`; pong returns `chainStatusByCurrency` map with per-currency chain validity
- Per-currency available credit exchange: pong returns `availableCreditByCurrency` map; each currency's available credit stored independently in `contact_credit` table (UNIQUE on `pubkey_hash, currency`)
- GUI currency slider: contact modal uses horizontal pill-style currency slider with left/right arrows to switch between currencies, replacing the dropdown selector
- Dynamic currency dropdowns: Send eIOU form populates currencies from user's allowed currencies and filters to contact's accepted currencies when a contact is selected; Add Contact form also uses allowed currencies
- Per-currency "Your Available Credit" and "Their Available Credit" display in contact modal per-currency entries
- `TransactionRepository::getPreviousTxidsByCurrency()` for retrieving per-currency chain heads
- `ContactCurrencyRepository::getDistinctAcceptedCurrencies()` for wallet info currency display
- `contact_currencies.direction` column (`ENUM('incoming','outgoing')`) with database migration — enables per-direction currency tracking so both sides independently track what they requested vs what was requested of them
- Sender-side outgoing currency tracking: `handleNewContact` now inserts `direction='outgoing'` entries in `contact_currencies` when a contact request is sent, so the sender can see "Awaiting their acceptance" for each requested currency
- Direction-aware GUI: pending contact section shows "Your pending requests (awaiting their acceptance)" for outgoing currencies and "They requested" for incoming currencies, eliminating the confusion where sender's own currency appeared as an incoming request
- `MessageService` now updates outgoing `contact_currencies` entries to 'accepted' when remote acceptance is received
- Unique index on `contact_currencies` changed from `(pubkey_hash, currency)` to `(pubkey_hash, currency, direction)` — allows both sides to independently request the same currency
- Multi-currency GUI display: wallet info cards now show one row per currency (Balance, Earnings, Credit grouped per currency) instead of mixing all currencies in a single row
- Contact detail modal currency selector: multi-currency contacts now display a dropdown to switch between currencies, updating balance, credit limit, fee, and available credit fields
- Per-currency contact balances: `getAllContactBalances()` now returns balances grouped by currency (`pubkey => ['USD' => amount, 'GBY' => amount]`)
- `balances_by_currency` field added to contact data throughout the GUI pipeline (BalanceService, TransactionService, ContactDataBuilder)
- Pending currency acceptance flow: adding a new currency to an existing contact now sends a P2P request; the remote side sees it as "pending" and must accept with their own fee/credit terms
- `contact_currencies.status` column (`'accepted'`/`'pending'`) with database migration for existing tables
- `ContactCurrencyRepository::acceptCurrency()` and `getPendingCurrencies()` methods
- `ContactSyncService::setContactCurrencyRepository()` for new currency request handling on receiver side
- GUI `acceptCurrency` action handler in ContactController for accepting pending currency requests
- Pending currency badge on contact cards and accept form in contact detail modal
- Cross-currency mutual request handling: when both sides initiate contact requests with different currencies, the remote's currency is stored as a pending entry in `contact_currencies` and displayed in the GUI as a "Currency Mismatch" with an option to accept their terms
- CLI `eiou add` now allows updating the currency on a pending outgoing contact request — re-sends the P2P request with the new currency, enabling mutual accept when currencies now match
- Accept contact form currency dropdown now populated dynamically from `getAllowedCurrencies()` instead of hardcoded USD
- Configurable allowed currencies — the hardcoded `['USD']` allowed list in `InputValidator::validateCurrency()` is now a `Constants::ALLOWED_CURRENCIES` default that can be overridden per-node via `UserContext::getAllowedCurrencies()`
  - New `Constants::ALLOWED_CURRENCIES` constant defines the system default
  - New `UserContext::getAllowedCurrencies()` getter reads from config (comma-separated string or array), falls back to Constants
  - `InputValidator::validateCurrency()` now reads allowed list from UserContext; accepts optional `$allowedCurrencies` parameter for tests and override scenarios
  - New `InputValidator::validateAllowedCurrency()` validates that a currency code has a `Constants::CONVERSION_FACTORS` entry before it can be added to the allowed list
  - CLI `changesettings allowedCurrencies` command and interactive menu option (16) for managing allowed currencies
  - GUI settings: dynamic default currency dropdown populated from allowed list; new "Allowed Currencies" text input field
  - GUI SettingsController validates each currency has a conversion factor on save
  - API `PUT /api/v1/system/settings` supports `allowed_currencies` field with per-currency conversion factor validation
  - `allowedCurrencies` added to `UserContext::getConfigurableDefaults()` (stored as comma-separated string)
- Multi-currency contact support — contacts can now have multiple currency relationships with independent fee and credit limit per currency
  - New `contact_currencies` table stores per-currency configuration (`pubkey_hash`, `currency`, `fee_percent`, `credit_limit`) with composite UNIQUE on `(pubkey_hash, currency)`
  - New `ContactCurrencyRepository` with full CRUD: `insertCurrencyConfig()`, `getCurrencyConfig()`, `getContactCurrencies()`, `hasCurrency()`, `getCreditLimit()`, `getFeePercent()`, `updateCurrencyConfig()`, `upsertCurrencyConfig()`, `deleteAllForContact()`, `deleteCurrencyConfig()`
  - `ContactManagementService::addCurrencyToContact()` method to add a new currency to an existing accepted contact, creating rows in `contact_currencies`, `balances`, and `contact_credit`
  - `ContactCreditRepository::getAvailableCreditAllCurrencies()` returns all per-currency credit rows for a contact
  - GUI contact cards show "+N currency" badge when contact has multiple currencies
  - GUI contact modal shows "Additional Currencies" section with per-currency credit limit, fee, and available credit
  - GUI contact controller supports `addCurrency` action for adding currencies from the contact settings
  - API `GET /api/v1/contacts/:address` response now includes `currencies` array with per-currency configuration
  - Data migration in `DatabaseSetup::runColumnMigrations()` copies existing `contacts.{currency, fee_percent, credit_limit}` into `contact_currencies` via `INSERT IGNORE`

### Changed
- Web server replaced from Apache (mod_php) to nginx + PHP-FPM. nginx handles connections, SSL termination, rate limiting, and static files; PHP-FPM executes PHP via unix socket. All SSL cert paths moved from `/etc/apache2/ssl/` to `/etc/nginx/ssl/`. Log paths changed from `/var/log/apache2/` to `/var/log/nginx/`. GUI debug panel updated from "Apache Logs" to "nginx Logs". Service reload command changed from `apache2ctl graceful` to `nginx -s reload`
- Interactive `changesettings` menu refactored from flat 44-item numbered list to two-level grouped navigation: select a category (1-8), then select a setting within that category. Press 0 to go back or cancel. All validation logic unchanged
- Tor circuit health settings (max failures, cooldown duration, transport fallback) added to API (GET/PUT `/api/v1/system/settings`), GUI settings panel, and grouped interactive CLI menu
- Default fee percentage reduced from 0.1% to 0.01% (`CONTACT_DEFAULT_FEE_PERCENT`). The minimum fee floor (`TRANSACTION_MINIMUM_FEE = 0.01`) ensures fees never round to zero on small amounts
- All hardcoded currency display patterns (`/ 100`, `number_format(..., 2)`) replaced with `Constants::getConversionFactor()` and `Constants::getCurrencyDecimals()` across GUI templates, CLI, and services — display is now per-currency aware
- Credit hold calculation in `checkAvailableFunds` now uses `capacity_reservations` table (Option 1: single source of truth) with fallback to legacy `getCreditInP2p` method
- `P2pServiceTest` updated to mock `CapacityReservationRepository::getTotalReservedForPubkey` instead of legacy `P2pRepository::getCreditInP2p`, testing the new capacity reservation path as primary
- Letsencrypt volume mount changed from optional (commented out) to always-created named volume to prevent dangling anonymous volumes on container rebuilds. Safe to comment out if you will never use Let's Encrypt
- Legacy demo/test compose files updated with letsencrypt named volumes for consistency
- Legacy demo sleep timers increased from 5s/10s to 15s to account for longer container boot times
- Test buildfiles and legacy demo scripts updated with letsencrypt named volume mount for consistency; removed obsolete `index` and `eiou` volume references from cleanup commands
- Interactive `changesettings` menu expanded from 16 to 43 options, now covering all available settings organized by category (Transaction, P2P & Network, Feature Toggles, Backup & Logging, Data Retention, Rate Limiting, Display, Currency Management)
- `viewsettings` display now includes all changeable settings: added `name`, `direct_tx_expiration`, `allowed_currencies`; removed duplicate autoRefresh and autoBackup entries that appeared in two sections
- Added newline separator between `viewsettings` output and the interactive menu prompt for readability
- Help `available_settings` for `changesettings` updated from 14 to 43 entries
- API GET `/api/v1/system/settings` response now includes `name`, `direct_tx_expiration`, `trusted_proxies`, `allowed_currencies`
- API PUT `/api/v1/system/settings` now accepts `direct_tx_expiration` and `trusted_proxies`
- `MessagePayload::buildTransactionSyncRequest()` now accepts optional `lastKnownTxidsByCurrency` parameter for per-currency sync cursors
- `SyncService::handleTransactionSyncRequest()` filters transactions per-currency when `lastKnownTxidsByCurrency` is provided in the request
- `ContactStatusPayload::build()` sends `prevTxidsByCurrency` instead of single `prevTxid`
- `ContactStatusPayload::buildResponse()` takes `chainStatusByCurrency` and `availableCreditByCurrency` maps instead of single `chainValid`/`availableCredit`/`currency` values
- `getCreditLimit()` without direction parameter now returns `MAX(credit_limit)` across all direction rows for that contact+currency
- Wallet information section now includes currencies from accepted contact relationships (not just those with balances/earnings)
- Legacy single-`prevTxid` code removed from ping/pong protocol entirely
- CLI `update` command now requires currency parameter for `fee` and `credit` fields (`eiou update Bob fee 1.5 USD`); optional for `all` (defaults to contact's current currency)
- CLI `update` for fee/credit now propagates changes to `contact_currencies` table (not just `contacts`)
- GUI contact settings tab: currency selector moved above fee/credit, populated from contact's accepted currencies; changing currency loads that currency's current fee/credit values
- GUI `handleEditContact` updates `contact_currencies` directly for fee/credit per selected currency instead of only updating `contacts` table
- `contact_credit` table UNIQUE constraint changed from `pubkey_hash` alone to composite `(pubkey_hash, currency)` — allows storing per-currency credit entries for the same contact
- `getCreditLimit()` across all interfaces, services, and repositories now accepts an optional `currency` parameter (defaults to `Constants::TRANSACTION_DEFAULT_CURRENCY`) — affects `ContactServiceInterface`, `ContactManagementServiceInterface`, `ContactRepository`, `ContactManagementService`, `ContactService`
- `ContactRepository::getCreditLimit()` queries `contact_currencies` table first, falls back to `contacts` table for backward compatibility
- `ContactCreditRepository::getAvailableCredit()` now accepts optional `currency` parameter to filter by specific currency
- All `getCreditLimit()` call sites updated to pass currency from the request/transaction context: `TransactionValidationService`, `TransactionService`, `Rp2pService` (2 call sites), `P2pService`
- `ContactStatusService::handlePingRequest()` reads credit limit from `contact_currencies` table first, falling back to `contacts.credit_limit`
- `ContactManagementService::acceptContact()` now also writes to `contact_currencies` table alongside the existing `contacts` table write
- `ContactDataBuilder` output includes `currencies` array for multi-currency GUI rendering
- `Functions.php` fetches per-contact currency configs and all-currency available credits for GUI display

### Fixed
- RP2P fee calculation changed from additive to multiplicative (compounding): each relay now recalculates its fee on the **accumulated RP2P total** (base + all downstream fees) instead of the original base amount. The exact rounded fee is saved to `my_fee_amount` during RP2P backtracking and added to the forwarded amount, ensuring `TransactionService::removeTransactionFee()` subtracts the identical value with no rounding discrepancies. New `calculateFeeForP2p()` helper in `Rp2pService` handles per-contact fee lookup and calculation
- `routeCancellationTest` hop budget test: now checks `Constants::isHopBudgetRandomized()` and accepts deterministic output (constant `maxHops`) when randomization is disabled via `EIOU_HOP_BUDGET_RANDOMIZED=false`, instead of always requiring variance across 100 samples
- `DatabaseSchemaTest`: Update column type assertions from INT/INTEGER to BIGINT for `transactions.amount`, `balances.received/sent`, `p2p.amount/my_fee_amount`, `rp2p.amount`. Remove obsolete `contacts.currency/fee_percent/credit_limit` assertions (moved to `contact_currencies` table). Add `signed_message_content` assertion for transactions table
- `DatabaseSetupTest`: Replace two obsolete `online_status` enum migration tests with single test verifying no enum migration runs (schema already includes 'partial' on fresh install)
- Fee calculation formula in `CurrencyUtilityService::calculateFee()` was currency-dependent — used `conversionFactor` in the formula which only produced correct results for USD (factor=100). Replaced with currency-independent formula `amount * feePercent / 100`. Also fixed inconsistent fee scale: `getDefaultFee()` returned raw percentage while DB `getFeePercent()` returned a scaled INT — callers now normalize DB values before passing to `calculateFee()`
- Originator cancel now propagates downstream: `CliService::rejectP2p()` calls `broadcastFullCancelForHash()` instead of `sendCancelNotificationForHash()` which exited early for originator nodes
- Multi-route cancel safety (diamond topology): regular `route_cancel` from best-fee selection now just acknowledges without cancelling P2P or releasing reservation, preventing incorrect resource freeing when a node is part of both selected and unselected routes
- `handleIncomingCancellation` now propagates `full_cancel` downstream to relay contacts, enabling cancel cascade through the full route chain instead of being local-only
- `P2pService::sendP2pMessage` visibility changed from private to public and added to `P2pServiceInterface`, fixing runtime error when called from `RouteCancellationService::cancelUnselectedRoutes`
- `changesettings maxP2pLevel` via command-line was broken: `strtolower($argv[2]) === 'maxp2pLevel'` comparison could never match due to uppercase in the comparison target
- `autoBackupEnabled` was only changeable in interactive mode; added to command-line argv handling
- P2P best-fee mode over Tor: forced fast mode now detects Tor transport on any hop (originator resolved address or incoming sender address), not just when the final destination is a `.onion` address. Previously, if the originator's address resolved to Tor via fallback but the destination was HTTP, best-fee mode (`fast=0`) persisted across the entire chain — causing 240s+ delays waiting for Tor relay timeouts on unresponsive routes. Transport index cascading (`determineTransportType(sender_address)`) propagated Tor to all downstream relays.
- Chain drop test suite: `clean_chain()` preserves the contact transaction (`tx_type != 'contact'`), so `tx1.previous_txid` correctly points to it rather than being NULL. Removed false `tx1.previous_txid == NULL` assertions from tests 6.6, 7.6, and 8.4 — the chain drop relink assertions (the actual test targets) were already passing.
- Chunked sync test 2.2: assertion now accounts for pre-existing transactions from earlier test suites instead of assuming exactly 10 transactions between the two contacts
- `contact_currencies` table enforces single row per (pubkey_hash, currency) — direction column records who initiated, but only one row exists per contact-currency pair. Eliminates dual-row creation during mutual accept flows.
- `credit_limit` column defaults to NULL instead of 0: NULL means "not yet configured", 0 means "explicitly set to zero" (e.g., to block transactions in that currency)
- `ContactRepository::getCreditLimit()` simplified to direct single-row query (no MAX needed with single-row design)
- Contact transaction `signature_nonce` is now generated during `insertReceivedContactTransaction()` when not provided by the sender, enabling the dual-signature protocol for contact transactions
- Recipient signature generation ordering: `generateAndStoreContactRecipientSignature()` is now called after the received contact transaction is created (was called before, finding no TX)
- Added recipient signature generation on sender's node when receiving mutual accept (STATUS_ACCEPTED), ensuring both sides have valid dual signatures
- Ping test 6.1/6.2: signature check now looks for the received contact TX direction first (where the current node is the recipient and recipient_signature exists)
- Ping test 6.3: signature verification now includes `currency` in the reconstructed signed message, matching `ContactPayload::generateRecipientSignature()` format
- Chunked sync test: fixed `getUserContext()` → `getCurrentUser()` and `getMessagePayload()` → direct `MessagePayload` instantiation (methods don't exist on ServiceContainer)
- Chain drop test suite: `clean_chain()` deletes ALL transactions including the contact TX — identified as root cause of ping test 6.1/6.3 dual-signature failures (design decision pending: missing contact TX invalidates entire chain)
- Contact transaction sync recovery: chain gap detection and `missingTxids` DB lookup no longer gated behind `backupService !== null` — previously, if `BackupService` was not wired (lazy-loaded), sync could not detect gaps or ask the remote to look up missing transactions, causing permanent "both sides missing same transactions" errors even when the remote had the transaction in its DB
- Contact transaction signature verification during sync: `senderAddresses` removed from signed content in `signWithCapture()` — previously, the sender's full address set was included in the signed message, making signatures unverifiable if any address was added or removed after signing
- Contact signature reconstruction: `reconstructContactSignedMessage()` now includes `currency` field to match the actual signed content — previously reconstructed only `{"type":"create","nonce":"..."}` which never matched the signed payload
- Recipient signature for contact transactions: `generateRecipientSignature()` now includes `currency` to match sender signature format; `getContactTransactionByParties()` query updated to return `currency` column — previously recipient signed without currency, causing verification mismatch during sync recovery
- Accept All button for new pending contacts: removed `$isExisting` gate so the "Accept All N Currencies" button appears for both new and existing contacts with multiple pending currencies — previously only shown after the first currency was individually accepted
- Accept All for new contacts: `handleAcceptAllCurrencies` now handles new contacts by accepting the first currency via `addContact` CLI flow (to establish the contact) then remaining currencies via standard acceptance path — previously only worked for already-accepted contacts
- Credit limit conversion: `handleAcceptCurrency` and `handleAcceptAllCurrencies` now properly convert fee and credit limit to minor units (cents) before storing — previously stored raw float values
- "Their Available Credit" in contact modal now calculated per-currency as `credit_limit - balance` instead of showing "—"
- "Your Available Credit" in pong calculation no longer incorrectly filters by direction — credit limit lookup uses max across directions
- Multi-currency acceptance for existing contacts: `handleExistingContact()` now creates an outgoing `contact_currencies` entry and sends a P2P notification when accepting an incoming pending currency — previously only the incoming entry was accepted, leaving the remote side stuck on "Awaiting their acceptance" for non-default currencies
- False positive chain gap after contact add/accept cycle: pong handler now re-evaluates chain validity after sync using txid existence checks instead of stale head comparison — resolves race condition where in-flight transactions caused chain head mismatches between ping and pong
- Wallet restore via ping: `handlePingRequest` auto-create path now creates `contact_currencies` entries for all currencies from `prevTxidsByCurrency`, auto-accepts the contact when sync proves prior relationship, and uses the correct multi-currency `buildResponse` signature — previously only created a bare pending contact with no currencies and used the deprecated single-value response signature
- Wallet restore balance recalculation: auto-create path now calls `syncContactBalance()` to recalculate balances from synced transaction history instead of initializing to 0/0 — previously all restored contact balances showed zero
- Multi-currency transaction sync: `syncTransactionChain()` now sends per-currency cursors (`lastKnownTxidsByCurrency`) so the handler can filter independently per currency — previously used a single `lastKnownTxid` cursor across all currencies, causing partial syncs to miss older transactions in currencies other than the one with the latest txid
- Sync handler per-currency filtering: `handleTransactionSyncRequest()` now supports `lastKnownTxidsByCurrency` for per-currency cursor filtering with backward-compatible fallback to single `lastKnownTxid` break
- Multi-currency contact add for existing contacts: `handleNewContact()` now creates outgoing `contact_currencies` entry, contact transaction, and balance/credit entries when adding additional currencies to an existing contact — previously silently returned "Contact address updated" without any per-currency state
- Receiver-side per-currency contact transactions: `handleContactCreation()` now creates a contact transaction per currency for both pending and accepted contacts — previously only created one contact transaction for the first currency, causing asymmetric chain state
- Per-currency contact transaction check: `contactTransactionExistsForReceiver()` now supports optional currency filter — previously checked only whether any contact transaction existed regardless of currency
- Txid hash now includes currency: `createUniqueTxid`, `createUniqueDatabaseTxid`, `insertContactTransaction`, and `insertReceivedContactTransaction` all include currency in the hash input — prevents potential txid collisions when the same amount is sent in different currencies at the same microsecond, or when multiple per-currency contact transactions are created between the same parties
- Per-currency independent contact requests: incoming currency from P2P contact requests is now stored in `contact_currencies` with `status='pending'` instead of being lost (root cause: `addPendingContact()` stores `currency: null`)
- Accept contact now validates against pending currencies from `contact_currencies` instead of the always-null `contacts.currency` field
- Accepting a pending currency for an existing accepted contact now correctly creates initial balance and credit entries for the new currency
- GUI `handleAcceptCurrency` now inserts initial balance/credit entries when accepting a pending currency (previously only updated `contact_currencies` status)
- P2P fee lookup crash: `P2pService::calculateRequestedAmount()` and `Rp2pService::handleRp2pRequest()` referenced removed `contacts.fee_percent` column — now uses `ContactCurrencyRepository::getFeePercent()` for per-currency fee lookup with user default fallback
- P2P funds-on-hold not filtered by currency: `getCreditInP2p()` summed ALL active P2P amounts regardless of currency, incorrectly blocking transactions in one currency due to holds in another — now filters by currency
- P2P broadcast to ineligible contacts: `processQueuedP2pMessages()` and `processSingleP2p()` broadcast P2P requests to all accepted contacts regardless of currency support — now uses currency-filtered `getAllAcceptedAddresses($currency)` so requests are only sent to contacts that have the transaction's currency accepted
- P2P currency hardcoded to USD: `prepareP2pRequestData()` ignored request currency and defaulted to USD — now reads currency from request
- Auto-recovery crash on wallet restore: `ContactRepository` methods used 0-indexed positional params causing PDO `ValueError` — fixed with named params
- Pending contact requests in GUI now show per-currency accept forms when multiple currencies are requested, each with independent fee/credit settings
- Pending contacts section enriched with currency data from `contact_currencies` table
- `acceptContact()` now ensures the accepted currency is properly recorded in `contact_currencies` with fee/credit values
- `handleContactCreation` no longer references removed `currency`, `fee_percent`, `credit_limit` columns from `contacts` table — now reads from `contact_currencies` for mutual accept matching
- GUI `openContactModal` in script.js now derives currency/fee/credit from `currencies[0]` instead of removed top-level contact fields
- GUI `contactSection.html` now derives fee/credit/currency display from `currencies` array instead of removed top-level contact fields
- Accept contact with mismatched currency no longer rejects — user can accept with their preferred currency while remote's pending currencies stay for later acceptance
- Cross-currency contact requests now correctly distinguished via `direction` column in `contact_currencies`: "incoming" = they requested from us, "outgoing" = we requested from them — resolves mismatch where Alice's USD request was confused with Bob's GBY request
- Mutual auto-accept no longer fires when currencies differ — Alice adding Bob with USD and Bob adding Alice with GBY are now treated as separate pending requests instead of auto-accepting with mismatched currencies
- Wallet info card currency rows now follow the allowed currencies order (first allowed currency = first row)
- Wallet info card loops use `isset()` guards so data for currencies not in `$knownCurrencies` cannot create phantom rows

### Removed
- Legacy `currency`, `fee_percent`, `credit_limit` columns from `contacts` table — fee/credit configuration is now exclusively in `contact_currencies` table
- Dual-write pattern: services no longer write fee/credit/currency to `contacts` table; only `contact_currencies` is used
- Single-value fee/credit/currency from CLI `get` and `search` output; replaced with per-currency display
- Single-value `fee_percent`, `credit_limit`, `currency` from API GET endpoints (`/contacts`, `/contacts/search`, `/contacts/ping`); replaced with `currencies` array
- Legacy `their_available_credit` single-value calculation from GUI; per-currency values in `currencies` array are used instead

### Docs
- ARCHITECTURE.md: Add credit reservation lifecycle section — explains `base_amount` vs `total_amount`, three release paths (cancel/commit/expiry), and status transitions with diagram
- ARCHITECTURE.md: Add fee accumulation through relays section — shows multiplicative/compounding per-hop fee calculation, two-phase process (estimate on outbound, authoritative recalculation on RP2P return), multi-route example with best-fee selection, and `rp2p_candidates` table schema
- ARCHITECTURE.md: Add coalesce delay and mega-batch section — explains `P2P_QUEUE_COALESCE_MS`, when mega-batch is used vs inline sends, and compound key mapping
- ARCHITECTURE.md: Add message delivery and dead letter queue section — documents retry policy, exponential backoff schedule, Tor cooldown handling, DLQ operations (retry/abandon/resolve), and atomic claiming
- ARCHITECTURE.md: Add distributed locking section — explains MariaDB advisory locks, atomic claiming pattern, and where locks are used
- ARCHITECTURE.md: Add ping/pong credit exchange section — documents ping payload, pong response fields, available credit synchronization, chain validation, sync trigger, and online status determination
- ARCHITECTURE.md: Add proactive vs reactive sync comparison table to sync flow section
- `update` CLI help now documents the required `currency` parameter for `fee` and `credit` fields, and optional currency for `all`. Usage, arguments, examples, and note all updated to reflect the actual command syntax.

## 2026-02-28 -- 2026-03-03

DLQ management, configurable settings, security hardening, GUI improvements.

### Security
- Drop all Linux capabilities and re-add only the 7 required (`CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `KILL`, `NET_BIND_SERVICE`, `SETGID`, `SETUID`) in all compose files — significantly reduces blast radius of a container escape (#521)
- Add `security_opt: no-new-privileges` and `pids_limit: 200` to cluster compose file (was missing from all 13 nodes)
- Use `tini` as PID 1 for proper signal forwarding and zombie reaping — prevents zombie process accumulation from crashed PHP processors and `runuser` wrappers (#521)
- Bind Docker port mappings to `127.0.0.1` in all dev/test compose files (single, 4line, 10line, cluster) — inter-node communication uses Docker network, host ports are only for local GUI access; production `docker-compose.yml` keeps `0.0.0.0` (required for incoming P2P) with documented `127.0.0.1` alternative for reverse proxy setups
- Harden Tor hidden service key file creation (L-31) — set umask to 0077 before `file_put_contents()` to eliminate race window where files are briefly world-readable; add explicit error handling if `debian-tor` user is missing or `chown()`/`chgrp()` fails; restore umask in `finally` block
- Add permission whitelist and rate limit validation to API key creation endpoint (H-5) — `ApiKeyService::validatePermissions()` and `validateRateLimit()` static methods shared by CLI and API paths; `ApiController::createApiKey()` returns 400 for invalid permissions or rate limits exceeding 1000/min
- Add AAD context to AES-256-GCM encryption (L-28) — `KeyEncryption::encrypt()` accepts `$context` param used as Additional Authenticated Data; output includes `version` and `aad` fields; `decrypt()` requires v2 format; callers pass context strings (`private_key`, `auth_code`, `mnemonic`, `api_secret`, `backup`); **breaking: v1 encrypted data must be re-encrypted**
- M-18 (P2P hash strengthening) investigated and determined incompatible with multi-hop relay design — sender public key changes at each relay hop, making hash unverifiable at destination; finding remains acknowledged with Ed25519 signatures as primary defense
- Replace CSP `unsafe-inline` with per-request nonce for `script-src` (L-32) — `Security::generateCspNonce()` creates a cryptographic 128-bit nonce per request; all `<script>` tags (external and inline) receive `nonce="..."` attribute; every inline `onclick`/`onchange`/`oninput`/`onkeyup` handler across 12 template files migrated to `data-action` attributes with a single delegated event dispatcher in `script.js`; wallet and authentication pages load `script.js` via external `<script src>` instead of PHP `require_once` inlining
- Remove `style-src 'unsafe-inline'` from CSP — all ~285 inline `style=""` attributes across 8 template files and ~40 `innerHTML`/`style.cssText` patterns in `script.js` migrated to CSS classes in `page.css`; dynamic PHP-generated colors (DLQ status badges, transaction phase badges) replaced with variant classes; `<style>` tags in `wallet.html` and `authenticationForm.html` receive `nonce="..."` attribute; CSP `style-src` now uses `'nonce-{$nonce}'` matching `script-src`
- Run PHP message processors and backup cron as `www-data` instead of root (M-22) — uses `runuser -u www-data --` for all processor launches and watchdog restarts; `chown` config directory to `www-data` before processor start; guard root-only `chown()`/`chgrp()` calls in `BackupService` and `Application` with `posix_getuid()` check

### Added
- Add Dead Letter Queue (DLQ) management UI (`dlqSection.html`): filterable table (Pending & Retrying / Pending Only / Resolved / Abandoned / All), per-item Retry and Abandon actions, stats bar with per-status counts, mobile card layout at ≤640px using `data-label` attributes, and a "Failed Messages (N)" quick-action card in the dashboard header that links to the DLQ when pending items exist
- Add DLQ indicator badge (red **DLQ** pill) to Recent Transactions and In-Progress Transactions lists — shown when a transaction has a pending or retrying DLQ entry; clicking navigates to `#dlq` for retry or abandon
- Add CLI DLQ management commands: `dlq list [status]` (lists DLQ entries), `dlq retry <id>` (retry a pending entry), `dlq abandon <id>` (abandon a pending entry), `dlq stats` (summary counts)
- Add `DIRECT_TX_DELIVERY_EXPIRATION_SECONDS = 120` constant — maximum time allowed for direct (non-P2P) transaction delivery (two Tor round-trips: 4 × `TOR_TRANSPORT_TIMEOUT_SECONDS`); used as the post-expiry delivery window granted to P2P transactions
- Add `expires_at DATETIME(6)` column and index to the `transactions` table — `NULL` means no expiry (direct tx default); P2P transactions set `expires_at` to P2P expiry + 120s; direct transactions set `expires_at` only when `directTxExpiration > 0`
- Add `directTxExpiration` user setting (default `120`s = two Tor round-trips) — configurable via GUI Settings, `changesettings directtxexpiration <seconds>` CLI command, and REST API; direct transactions are cancelled after this many seconds if still undelivered; set to `0` to disable expiry
- Add `CleanupService::expireStaleTransactions()` — independently cancels transactions past their `expires_at` deadline; runs each cleanup cycle after P2P expiry processing, keeping P2P and transaction lifecycles decoupled
- Add `TransactionRepository::cancelPendingByMemo()` — cancels only `pending` transactions for a given memo hash, leaving in-flight (`sending`/`sent`/`accepted`) transactions to complete naturally or expire via their own `expires_at`
- Add `TransactionRepository::getExpiredTransactions()` and `setExpiresAt()` helper methods
- Add Retry All and Abandon All bulk action buttons to DLQ header — Retry All re-queues all pending/retrying transaction and contact messages (p2p/rp2p excluded); Abandon All marks every pending/retrying item as abandoned; both reload the page on success
- Add unit tests for DLQ transaction expiry lifecycle: `CleanupServiceTransactionExpiryTest` (expireStaleTransactions, cancelPendingByMemo decoupling, constant values), `DlqControllerTest` (constructor, extractTxidFromMessageId via reflection, expires_at refresh on retry, cancelled-to-sending status reset, p2p/rp2p rejection), `TransactionRepositoryExpiryTest` (method existence via reflection, constant values, datetime format)
- Add persistent user-configurable settings infrastructure: `UserContext::getConfigurableDefaults()` provides canonical map of all 41 configurable settings with Constants defaults; `Application::migrateDefaultConfig()` adds missing keys to `defaultconfig.json` on boot without overwriting user values; `Wallet.php` uses canonical map instead of hardcoded arrays
- Add 30 new user-configurable settings covering feature toggles (including `autoAcceptTransaction` from #663), backup/logging, data retention, rate limiting, network timeouts, sync tuning, and display preferences — all persisted to `defaultconfig.json` and surviving container updates
- Expose all 30 new settings through REST API (GET/PUT `/system/settings`) and GUI Settings page (collapsible "Advanced Settings" section with grouped fields)
- Document all 30 new settings in CLI_REFERENCE.md, API_REFERENCE.md, and GUI_REFERENCE.md
- Add category dropdown selector to Advanced Settings — replaces flat scrollable list with a `<select>` that switches between Feature Toggles, Display, Backup & Logging, Data Retention, Sync, Network, and Rate Limiting panels (ordered simple→advanced); all fields remain in the DOM so changes across multiple categories are saved in a single click
- Add `.adv-section-nav` and `.settings-section-warning` CSS classes to `page.css`; extend `.form-group` rules to cover `textarea` elements (monospace font, vertical resize, matching border/focus/default-value styles)
- API `POST /api/v1/wallet/send` now reads `best_fee` from request body and passes `--best` to argv — previously the field was documented but silently ignored, always using fast mode (#679)

### Changed
- Increase `DIRECT_TX_DELIVERY_EXPIRATION_SECONDS` from 60s to 120s (two Tor round-trips instead of one) — gives Tor delivery enough time to complete under normal network conditions; P2P post-expiry delivery window and DLQ retry window increase accordingly
- Replace separate Backup Hour / Backup Minute number inputs with a single `<input type="time">` (`HH:MM`); `SettingsController` parses the combined value and stores the individual `backupCronHour` / `backupCronMinute` keys unchanged
- Replace API CORS Origins single-line text input with a resizable monospace textarea (one origin per line); controller normalises newline/comma-separated input to comma-separated storage and PHP renders it back as newline-separated on display
- Make Held TX Sync Timeout upper bound dynamic: PHP renders the initial `max` as `p2pExpiration − 1` and a JS listener on the P2P Request Expiration field keeps it current, auto-clamping the timeout value if needed; server-side validation also uses the submitted/saved `p2pExpiration` value rather than a hardcoded 299
- Rename "Max Display Lines" setting label to "GUI/CLI Max Output Lines" (setting affects both the GUI dashboard and CLI commands); update description to reference correct CLI commands (`viewbalances`, `history`); update "Recent Transactions Limit" note to reference the renamed label
- Move API CORS Origins field from Feature Toggles to Network section — it is a text configuration input, not a feature toggle
- Reorder Backup & Logging fields: Backup Time (UTC) → Backup Retention Count → Max Log Entries → Log Level (backup schedule before retention count; log capacity before log level)
- Style the Advanced Settings category `<select>` to match other form inputs — adds padding, border, border-radius, custom chevron arrow, and focus ring consistent with `.form-group select`
- Add expert warning to Network timeout section; add data-loss warning to Data Retention section
- Clarify Rate Limiting section: describe the two independent mechanisms (P2P throughput cap vs attempt-counting brute-force blocker) so the separate fields are not confused as controlling the same thing; rename fields to reflect their actual function ("P2P Throughput Limit", "Max Attempts per Window", "Attempt Window")
- Remove `rateLimitEnabled` toggle from GUI Feature Toggles — rate limiting is a security-critical feature that should not be easily disabled from the UI; toggle remains available via CLI and API; remove corresponding POST handler in `SettingsController` to prevent saving settings from silently writing `false` for a missing checkbox; document as CLI/API-only in API_REFERENCE.md and CLI_REFERENCE.md
- Remove incorrect "(HH:MM, 24-hour)" qualifier from Backup Time field description — the browser native time picker renders in 12h or 24h based on OS locale
- Migrate service consumers from Constants static helpers to UserContext getters: ContactStatusProcessor, ContactStatusService, SendOperationService, ChainDropService, and BackupService now read feature toggles from user configuration instead of hardcoded constants
- Deprecate `Constants::isContactStatusEnabled()`, `isAutoBackupEnabled()`, `isAutoChainDropProposeEnabled()`, and `isAutoChainDropAcceptEnabled()` in favor of UserContext getters
- Make integration tests manual-only — no longer auto-runs on every PR; trigger via `workflow_dispatch` from the Actions tab or by adding the `run-integration` label to a PR
- Group DatabaseSchema tables into 6 logical sections (Contacts & Network, Transactions & Chain Integrity, P2P Routing, Message Delivery, API, System & Security) with header comments; update matching order in DatabaseSetup and DatabaseSchemaTest
- Make contact IDs deterministic using HMAC-SHA256(contact_pubkey, user_pubkey) — re-adding a contact after deletion or database wipe now produces the same contact_id, preserving record correlation
- Consolidate to a single `docker-compose.yml` at project root — replaces the four separate compose files (single, 4line, 10line, cluster) with one fully-documented single-node compose file containing all environment variables and volume mounts as commented-out options
- Archive old multi-node compose files to `tests/old/compose-files/`
- Rewrite README.md to focus on the single compose file with comprehensive configuration reference
- CLI wrapper (`/usr/local/bin/eiou`) now waits up to 30s for MariaDB before running commands — prevents "Database setup failed" errors when `docker exec` is used before node startup completes

### Fixed
- Fix `sending` transaction status badge rendering as unstyled text — added `.tx-status-sending` CSS rule with orange background (`#fd7e14`) to match the existing in-progress phase badge colour and distinguish it from pending (yellow) and sent (teal)
- Fix quick-action cards squashing when the "Failed Messages" card was added as a sixth card — cards now have a fixed `218px` width (matching the original 5-card layout where cards filled the ~1160px container) and the slider is always active at all viewport widths; the `max-width: 992px` description-hiding breakpoint was removed since fixed-width cards handle overflow via scroll
- Fix quick-action scroll arrows advancing by a hardcoded `130px` instead of one full card — arrows now read the first card's `offsetWidth` plus the computed CSS `gap` at click time, scrolling exactly one card regardless of viewport or responsive breakpoint
- Fix transaction staying in In-Progress panel and triggering auto-refresh after being moved to DLQ — `processOutgoingDirect` now immediately cancels the transaction when delivery is exhausted (`dlq=true`), removing it from the in-progress view; the DLQ retry path already resets `cancelled→sending` when the user retries
- Fix duplicate retry workers for the same message — three race windows closed: (1) `sendWithTracking` now calls `lockForProcessing()` immediately after creating the delivery record to cover the initial `next_retry_at = NULL` window that `processRetryQueue` treated as immediately eligible; (2) `incrementRetry` is now passed `delay + 60s` buffer so the lock extends past the sleep and covers the following delivery attempt window; (3) `MessageDeliveryRepository::lockForProcessing()` added as an unconditional `next_retry_at` setter for use by sync callers
- Fix chain gap details not shown on initial page load after a chain drop proposal is created — `proposeChainDrop()` now sets `valid_chain = 0` on the contact immediately so the page render computes gap context without a prior "Check Status" ping; `Functions.php` also gains a safety net that computes gap details whenever an active proposal (pending/awaiting_acceptance/rejected) exists, covering any remaining window before `valid_chain` is written
- Fix "Failed Messages" quick-action card missing from the dashboard when the DLQ is empty — card is now always shown between Transaction History and Settings; the warning style and pending-count badge are still applied only when there are pending/retrying items
- Fix wallet state toasts (e.g. "Background Processing") leaking onto the unauthenticated login screen — `authenticationForm.html` now clears all `eiou_*` localStorage keys (`eiou_pending_operation`, `eiou_timeout_message`, `eiou_reopen_contact_id`, `eiou_reopen_contact_tab`) before `script.js` loads, so `checkForTimeoutToast()` finds nothing and no wallet activity is revealed to unauthenticated users
- Fix DLQ filter tabs causing a full page reload on every click — tabs are now client-side buttons; PHP always loads all items and `setDlqFilter()` shows/hides rows by `data-status`, updates the footer count, and toggles a "no items for this filter" message; default view on load is Pending & Retrying
- Fix transactions in transient `sending` status not appearing in the In-Progress Transactions panel — `sending` added to `getInProgressTransactions()` status list and phase mapping so transactions are visible in the banner during the HTTP send window
- Fix DLQ Retry and Abandon buttons leaving the page stale after success — both now reload the page after a short delay (1.5 s / 1.0 s) matching the Retry All and Abandon All behaviour; previously the row was only faded/hidden in place
- Fix duplicate DLQ entries and double retry sequences when two concurrent workers process the same failed message — `MessageDeliveryRepository::claimForRetry()` atomically claims a message via a conditional UPDATE (rowCount check) so a second worker's claim returns false and is skipped; `DeadLetterQueueRepository::addToQueue()` checks for an existing `pending`/`retrying` entry before inserting to prevent duplicate DLQ rows
- Fix `handleInvalidPreviousTxidDirect()` Step 3 — after a successful chain sync, the method logged "Retrying transaction..." but returned `true` without re-signing or re-sending; transaction was left stuck in `STATUS_SENT` with the wrong `previousTxid` and never retried; fix mirrors `attemptP2pRetryAndSync()`: get the synced `previousTxid` via `getPreviousTxid()`, call `updateAndResignTransaction()`, immediately re-send, and reset to `STATUS_PENDING` on failure so the next processing cycle picks it up
- Fix DLQ retry/abandon "Invalid CSRF token" error after first action — `DlqController` now passes `rotate: false` to `validateCSRFToken` so the token is not consumed on each AJAX call; users can retry or abandon multiple items without a page reload
- Fix auto-refresh using `fetch()`/`AbortController` (unsupported in Tor Browser strict mode) — replaced with `XMLHttpRequest` and `xhr.timeout` to match the rest of the codebase's Tor Browser compatibility requirement
- Fix recipient search dropdown ignoring arrow keys — add `keydown` handler to navigate options with ArrowDown/ArrowUp, select with Enter, and dismiss with Escape; mouse hover and keyboard focus stay in sync; extracted shared `selectRecipientOption()` function
- Fix arrow-key navigation in recipient dropdown scrolling the page — replaced `scrollIntoView(false)` (which scrolls the viewport) with manual `scrollTop` adjustment on the dropdown container so keyboard navigation only scrolls within the dropdown
- Shorten "Direct Transaction Delivery Expiration (seconds):" settings label to "Tx Delivery Expiry (seconds):" to prevent the label from wrapping and breaking the settings grid layout
- Fix DLQ badge link in Recent Transactions / In-Progress Transactions opening the transaction detail modal simultaneously — add `event.stopPropagation()` to the badge anchor so clicking navigates to `#dlq` without triggering the parent row's modal
- Fix DLQ retry producing "Unexpected server response" toast — wrap `setExpiresAt`/`updateStatus` pre-retry calls in `try-catch(\Throwable)` so a missing `expires_at` column (pre-migration container) does not abort the response; update `Functions.php` DLQ handler to catch `\Throwable` instead of `Exception` to handle PHP `Error` subclasses
- Fix Recipient and Failure Reason columns wrapping to multiple lines — replace PHP truncation with CSS `text-overflow: ellipsis; white-space: nowrap` via `.dlq-truncate` class; full values remain accessible via `title` tooltip
- DLQ table scrolls when more than 5 items — `.dlq-table-scrollable` class (`max-height: 420px; overflow-y: auto`) is added to the wrapper when `count($dlqItems) > 5`, matching the recent-transactions scroll behaviour
- Migrate all static inline styles in `dlqSection.html` to named CSS classes in `page.css` for Tor Browser compatibility — added `.dlq-section-header-actions`, `.dlq-description`, `.dlq-empty-icon`, `.dlq-reason-text`, `.dlq-cell-center`, `.dlq-date-cell`, `.dlq-final-state`, `.dlq-footer-count`; dynamic PHP-variable styles (colours, badge backgrounds) kept inline
- Add `expires_at` refresh and `cancelled→sending` status reset when retrying a `transaction`-type DLQ item — both GUI (`DlqController`) and CLI (`CliService`) retry paths now give the transaction a fresh `DIRECT_TX_DELIVERY_EXPIRATION_SECONDS` delivery window so the expiry sweeper does not immediately re-cancel it
- Fix `CleanupService::expireMessage()` cancelling all transactions (including in-flight `sending`/`sent`/`accepted` ones) when their parent P2P request expires — now uses `cancelPendingByMemo()` so only `pending` transactions are cancelled; in-flight transactions are allowed to complete and are independently expired via `expires_at` if they miss their delivery deadline
- Fix actions cell alignment in DLQ table — `display: flex` was applied directly to the `<td>`, causing the row bottom border to misalign for rows with short action content (e.g. abandoned rows); flex layout moved to an inner `<div class="dlq-actions-cell">` inside the `<td>`
- Fix type badge wrapping to two lines in DLQ table — add `white-space: nowrap; display: inline-flex; align-items: center; gap: 0.25rem` to `.tx-type-badge`
- Fix API CORS Origins setting saved to `defaultconfig.json` but never applied at runtime — `Api.php` was reading from `Constants::API_CORS_ALLOWED_ORIGINS` instead of `UserContext`; now reads from `UserContext::getInstance()->getApiCorsAllowedOrigins()`
- Fix `apiEnabled` setting having no enforcement gate — API always responded regardless of the toggle; now returns HTTP 403 with `api_disabled` error when the setting is off
- Fix `contactStatusEnabled` read from `Constants::CONTACT_STATUS_ENABLED` in `ContactStatusService::handleStatus()` — incoming contact status requests were never actually gated by user config; now uses `$this->currentUser->getContactStatusEnabled()`
- Fix `contactStatusSyncOnPing` read from `Constants::CONTACT_STATUS_SYNC_ON_PING` in `ContactStatusService` and `ContactStatusProcessor` — ping payload and sync gate always used the hardcoded constant; now uses `$this->currentUser->getContactStatusSyncOnPing()`
- Fix `syncChunkSize` and `syncMaxChunks` read from Constants in `SyncService` — chunk pagination and requester loop limit always used hardcoded values; now uses `$this->currentUser->getSyncChunkSize()` and `getSyncMaxChunks()`
- Fix `heldTxSyncTimeoutSeconds` read from `Constants::HELD_TX_SYNC_TIMEOUT_SECONDS` in `TransactionProcessingService` — proactive hold guard always used hardcoded timeout; now uses `$this->currentUser->getHeldTxSyncTimeoutSeconds()`
- Fix rate limit errors showing raw JSON instead of user-friendly flash message in the GUI — replace `enforce()` (which called `exit` with JSON) with `checkLimit()` + `MessageHelper::redirectMessage()` so the user sees a proper warning banner
- Fix GUI transaction rate limit bucket not applied to `sendEIOU` action — add `sendEIOU` to the `transaction` case in SecurityInit.php action mapping
- Remove dead `enforce()` method from `RateLimiterService` and its interface — replaced by `checkLimit()` + GUI flash redirect in SecurityInit.php
- Fix transaction details modal on mobile clipping P2P section off-screen — add `overflow-y: auto` to `.modal-body` so the full content scrolls within the viewport
- Fix P2P approval gate missing in fast mode — originator now checks `autoAcceptTransaction` before auto-sending in fast mode, presenting the route for approval when the setting is off; previously only best-fee mode had the approval gate
- Fix P2P expiration handler bypassing approval gate — `expireMessage()` called `selectAndForwardBestRp2p()` then unconditionally set status to `found`, auto-sending the transaction without user consent; now skips route selection when status is already `awaiting_approval`
- Fix late-arriving RP2P candidates rejected during `awaiting_approval` — candidates that arrive after route selection was deferred are now accepted and stored so they appear in the user's route list on refresh
- Fix cancel notifications re-triggering route selection during `awaiting_approval` — cancel count is tracked but `selectAndForwardBestRp2p` is no longer called when the user hasn't yet approved
- Fix P2P approval gate firing on every fast-mode RP2P response instead of only in best-fee mode — the gate now correctly waits for all routes to accumulate in best-fee mode before presenting choices, and fast mode always auto-sends immediately
- Fix approved P2P transactions failing to send (daemon crash: "Required field 'time' is missing") — the rp2p record was not inserted before calling sendP2pEiou in the GUI/CLI/API approval flows, causing processOutgoingP2p to crash when looking up the route data
- Lower `HELD_TX_SYNC_TIMEOUT_SECONDS` from 600s (10 min) to 120s — must be shorter than `P2P_DEFAULT_EXPIRATION_SECONDS` (300s) since P2P hops expire independently on every relay node
- Add P2P expiration timestamp check in `isP2pExpiredOrCancelled` — checks actual expiration time, not just status field (cleanup cycle may lag behind real expiry)
- Skip proactive hold for P2P transactions with insufficient remaining lifetime — prevents holding transactions that will become zombies because the P2P expires on all other relay nodes before sync can complete

### Docs
- Document transport selection behavior in `CLI_REFERENCE.md` `send` command — clarifies that passing an explicit address scheme (e.g. `http://Bob`) uses that scheme directly, while passing a contact name falls back to the `defaultTransportMode` setting (default: `tor`); both forms address the same contact but differ in delivery mechanism by design

## 2026-02-19 -- 2026-02-27

### Added
- Add Tor connectivity notification in wallet GUI — warning banner when SOCKS5 proxy failure is detected, spinner during restart, success toast on recovery; status communicated via `/tmp/tor-gui-status` between TransportUtilityService, watchdog, and the GUI
- Add dynamic route count update in GUI approval view — candidate count header refreshes via AJAX as late-arriving routes are received
- Add CLI and API support for P2P approval gate: `eiou p2p` commands (list, candidates, approve, reject) and REST API endpoints (`/api/v1/p2p/*`) allow users to manage P2P transactions awaiting approval when `autoAcceptTransaction` is disabled — previously only the GUI could do this
- Add `getAwaitingApprovalList()` query to P2pRepository for retrieving originator P2P records in `awaiting_approval` status
- Add P2P transaction approval gate: configurable toggle (`AUTO_ACCEPT_TRANSACTION`) that pauses P2P transactions at the RP2P response stage so the originator can review route fees before committing; relay nodes always auto-forward regardless of the setting (#663)
- Add `awaiting_approval` P2P status and `rp2p_amount` column to store total route cost for the approval UI
- Add approve/reject AJAX endpoints in GUI with fee breakdown (amount, route fee, total cost) and confirmation prompts
- Add `autoAcceptTransaction` to CLI changesettings (item 14), GUI settings toggle, and settings display
- Show chain gap transaction details in GUI: displays the last valid txid before each gap, the missing txid, and the first valid txid after each gap (with full txid on hover) so users can identify exactly where chain breaks occur
- Display chain gap count in GUI — badge shows "Chain Gap (N)" when multiple gaps exist, and chain drop section shows "Gap 1 of N" context with multi-gap info text
- Add `AUTO_CHAIN_DROP_PROPOSE` and `AUTO_CHAIN_DROP_ACCEPT` toggles in Constants.php with env var overrides (`EIOU_AUTO_CHAIN_DROP_PROPOSE`, `EIOU_AUTO_CHAIN_DROP_ACCEPT`)
- Add auto-accept for incoming chain drop proposals with balance guard to prevent debt erasure
- Balance guard compares stored balance vs transaction-calculated balance to detect if missing transactions include payments owed to us; blocks auto-accept when net missing favors proposer
- Show "Propose Dropping Missing Transaction(s)" button in GUI contact modal when chain gap detected but no proposal exists yet
- CI workflow to check base image digest monthly and on Dockerfile PRs; opens GitHub issue when upstream publishes a new digest (#523)
- `scripts/check-base-image.sh` for local verification of base image digest

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
- **M-13**: Pin base Docker image (`debian:12-slim`) to SHA256 digest to prevent supply chain attacks and ensure reproducible builds (#523)
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

### Fixed
- Fix P2P approval gate GUI "Network error" when choosing a route: CSRF token was consumed by the candidate-loading AJAX call on page load, making subsequent approve/reject calls fail with 403; AJAX endpoints now use non-rotating CSRF validation
- Fix P2P route count changing after selection: late-arriving RP2P candidates were still inserted into the database after route selection had completed, causing the displayed route count to increment on page refresh
- Fix GUI "Prior Contact" badge showing on every pending contact request: the history check included the contact request transaction itself (`tx_type='contact'`), causing false positives; now only flags contacts with real (standard/p2p) transaction history
- Fix missing dual-signature on contact transactions after wallet wipe + re-add: the "already exists" and "updated" response paths now include the original contact TX's txid and recipient signature, and the sender syncs the original TX instead of creating a divergent one
- Fix false chain gap reports during in-flight transactions: `verifyChainIntegrity()` now only checks `previous_txid` links on settled transactions (completed, accepted, paid) while keeping all active txids in the lookup set, so in-flight transactions don't report false gaps from unsynced references but their txids remain available as valid chain targets
- Fix all GUI POST actions (ping, send, chain drop, settings) returning 403: global CSRF check added in PR #644 consumed (rotated) the token before controllers could validate it, causing every authenticated POST to fail with "CSRF token validation failed"
- Fix Tor hidden service GUI inaccessible: HTTP→HTTPS redirect (from PR #644) blocked .onion access because port 443 is not mapped through the hidden service; skip HTTPS redirect for .onion hosts since Tor already provides end-to-end encryption
- Fix simultaneous chain drop proposals causing both sides stuck in "Awaiting Acceptance": when both contacts propose the same gap, the node with the lower pubkey hash auto-accepts using a deterministic tiebreaker
- Fix `bestFeeRoutingTest` Test 11 and `cascadeCancelTest` Tests 5-7 failing on http4 topology: dead-end cancel tests used hardcoded `containerAddresses[A12]` which only exists in collisions/http13 topologies; now dynamically finds an isolated node (0 expected contacts) via `expectedContacts`, falling back to a MODE-appropriate generated address
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
- Tor hidden service address mismatch on container restart: HS key regeneration check compared file existence but Tor had already started and generated random keys — now compares actual .onion address against userconfig to detect mismatches and regenerate correct keys from seed
- Tor watchdog initial boot: first self-check now waits 120s (descriptor propagation grace period) instead of firing immediately on the first watchdog loop — prevents restart doom loop on fresh container start while avoiding a 5-minute blind spot
- Tor watchdog recovery: increase post-restart verification window from 30s to 90s to match descriptor propagation time, allow follow-up restart after 90s instead of waiting full 5-minute cooldown, increase self-check timeout for slow Tor circuits
- Mutual contact request recognition: when both users send contact requests to each other, the second request to arrive now auto-accepts on both sides instead of leaving both stuck at "Pending Response"
- Wire up dead-code `buildMutuallyAccepted()` payload in `ContactPayload.php` with `$txid` parameter for transaction synchronization
- Fix sync inquiry misidentifying mutual pending contacts as "unknown" — `hasPendingContactInserted()` now checked for the case where both sides initiated requests
- Fix stale `$status` variable in `syncSingleContact()` re-send path — response was never decoded and status check always used the original rejected value, causing sync to report failure even after successful mutual acceptance

### Changed
- Trusted proxies now configurable via CLI (`changesettings trustedProxies`) instead of requiring container rebuild
- Rename `Security::sanitizeInput()` to `stripNullBytes()` for accuracy; deprecated alias retained
- P2P transport payload moved from URL query parameter to POST body for privacy (backward-compatible receiver fallback)
- P2P nonce changed from `time()` to `bin2hex(random_bytes(16))` for cryptographic uniqueness
- Exception detail display gated behind `Constants::isDebug()` instead of `APP_ENV !== 'production'`
- API authentication error messages normalized to prevent key state enumeration

### Docs
- Document container security hardening and log rotation in `DOCKER_CONFIGURATION.md`

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
- Wallet dashboard balance and earnings cards now display per-currency rows — future-proofed for multi-currency support, matching the existing Total Available Credit pattern
- Dollar sign (`$`) prefix removed from all transaction amount displays — amounts now show as `83.32 USD` instead of `$83.32 USD` across recent transactions, transaction detail modals, contact modal transactions, in-progress transactions, P2P details, and toast notifications
- `getUserTotalEarningsByCurrency()` method added to `P2pRepository` and `P2pService` — returns fee earnings grouped by currency

### Fixed
- CA-signed SSL certificate generation in `startup.sh` — openssl errors were silently discarded (`2>/dev/null`), so if `/ssl-ca/` mount had permission issues or corrupt keys, Apache got an invalid cert and the container crashed with no explanation; now logs errors and falls back to self-signed
- CA-signed SSL serial file written to `/tmp/ca.srl` instead of `/ssl-ca/ca.srl` — `-CAcreateserial` tried to write into the read-only `/ssl-ca/` mount, causing signing to fail on every `:ro` mount
- SSL certificate CN and SANs included port number when QUICKSTART/EIOU_HOST contained a port (e.g. `88.99.69.172:1152`) — ports are not valid in certificate fields; now stripped before certificate generation
- SSL certificate used `DNS:` SAN prefix for IP addresses — IP addresses require the `IP:` prefix per RFC 5280; now auto-detected and prefixed correctly
- `viewsettings` CLI command and `GET /api/v1/system/settings` now include `hostname` and `hostname_secure` fields — previously these were settable via `changesettings` option 10 but not visible in the settings display
- API `GET /api/v1/system/settings` now includes `auto_backup_enabled` field
- Idempotency guards on P2P and transaction balance updates — `MessageService::handleTransactionMessageRequest` and `CleanupService::syncAndCompleteP2p` now check whether a P2P/transaction is already completed before calling `updateBalanceGivenTransactions`, preventing double balance increments when both the normal completion flow and cleanup recovery fire for the same hash
- Benchmark `benchmark-routing.sh` no longer filters P2P lookup by `fast` flag — the Tor fast-mode override stores `fast=1` even when the user requested best-fee (`fast=0`), causing the benchmark to find nothing and report N/A; `id > max_id` scoping is sufficient since the benchmark is sequential
- Ping/pong fatal error — `ContactStatusService::handlePingRequest()` called `protected` method `findByColumn()` on `AbstractRepository`; replaced with public `getContactByPubkey()`

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
