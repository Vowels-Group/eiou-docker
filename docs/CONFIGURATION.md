# Configuration

The eIOU node reads configuration from four layers, in priority order. Settings in higher-numbered layers override lower-numbered ones for the keys they expose.

| Layer | Source | Lifetime | Who edits it |
|---|---|---|---|
| 1 | `Eiou\Core\Constants` | code-defined | core developers |
| 2 | environment variables (`.env`, `docker-compose.yml`) | per-container | operator at deploy time |
| 3 | JSON config files in `/etc/eiou/config/` | per-node | `eiou` setup commands + GUI / CLI settings handlers |
| 4 | `Eiou\Core\UserContext` getters | per-session | the wallet user (operator) at runtime |

The `Eiou\Core\AppConfig` value object is the typed seam over layers 1–2 for runtime code paths. Build it once at boot via `AppConfig::fromEnvironment()` (or read from `ServiceContainer::getAppConfig()`), then pass it explicitly — don't call `getenv()` in service code.

---

## Layer 1 — `Constants`

`files/src/core/Constants.php`. Code-defined defaults: protocol limits, retry policies, timeout windows, schema version, port numbers, etc. Operators don't edit this; changes ship in code releases.

A handful of constants resolve via `getenv()` at construction time (`Constants::isContactStatusEnabled()`, `Constants::getAnalyticsEnabled()`, etc.) — those reads are the layer-1 → layer-2 bridge.

## Layer 2 — environment variables

Set via `.env`, `docker-compose.yml`, or the host environment. The canonical list is `.env.example`. Examples by category:

- **Identity:** `QUICKSTART`, `EIOU_NAME`, `EIOU_HOST`, `EIOU_PORT`
- **Wallet restore:** `RESTORE_FILE`, `RESTORE`
- **TLS:** `SSL_DOMAIN`, `SSL_EXTRA_SANS`, `LETSENCRYPT_*`
- **Tor:** `EIOU_HS_TIMEOUT`, `EIOU_TOR_TIMEOUT`
- **Behavior toggles:** `APP_DEBUG`, `EIOU_CONTACT_STATUS_ENABLED`, `EIOU_BACKUP_AUTO_ENABLED`, `EIOU_AUTO_CHAIN_DROP_PROPOSE`, `EIOU_AUTO_CHAIN_DROP_ACCEPT`
- **Trust / proxy:** `TRUSTED_PROXIES`, `P2P_SSL_VERIFY`, `P2P_CA_CERT`
- **PHP-FPM / nginx tuning:** `PHP_FPM_*`, `NGINX_*`
- **Test-only:** `EIOU_TEST_MODE` — a constant set by `tests/bootstrap.php`, not honored as an env var on production builds (security invariant; see `RateLimiterService` / `P2pService` / `TransportUtilityService` for the loud-warning telemetry).

`AppConfig::fromEnvironment()` snapshots the security-relevant subset (`pluginHooksTrace`, `p2pSslVerify`, `p2pCaCert`, `trustedProxies`, `sslExtraSans`, `appEnv`, `appDebug`) into typed properties. Code that needs one of these reads from `AppConfig`, never `getenv()`.

## Layer 3 — JSON config files

Live under `/etc/eiou/config/` inside the container:

| File | Purpose | Schema |
|---|---|---|
| `dbconfig.json` | Database connection (host, name, user, encrypted password) | written once on first boot by `Application::init()`; never user-edited |
| `defaultconfig.json` | Operator preferences (analytics opt-in, backup schedule, Tor circuit health, etc.) | flat key/value; writes go through GUI / CLI settings handlers |
| `userconfig.json` | Per-user wallet identity + per-user preferences (display name, fee defaults, etc.) | flat key/value; merged on top of `defaultconfig.json` for lookups |
| `.schema_version` | Integer recording the last `Constants::SCHEMA_VERSION` the migration runner saw — used to skip already-applied migrations on subsequent boots | not meant for human edit |

`UserContext::loadDefaultConfig()` (`files/src/core/UserContext.php:54`) reads `defaultconfig.json` first, then merges `userconfig.json` on top. New defaults are upserted into `defaultconfig.json` automatically when `Constants::SCHEMA_VERSION` advances.

## Layer 4 — `UserContext` getters

`files/src/core/UserContext.php`. Per-session view of the merged config. Service-layer reads should always go through `UserContext::getX()` rather than re-parsing the JSON files; the getter handles the layer-3 → layer-2 → layer-1 fallback for fields the user hasn't overridden.

Plugins MAY read `UserContext` but must NOT mutate it. Plugin per-plugin state goes through `Eiou\Services\PluginSessionStore` (session-scoped) or `PluginPdoFactory` (DB-scoped) — never into the JSON config files.

---

## Plugins (read-only seam, not a layer)

`plugin.json` (one per plugin under `files/plugins/<id>/plugin.json`) is a manifest read at plugin-discovery time. It describes the plugin's id, version, hooks it subscribes to, and asset paths. It does NOT participate in layers 1–4 — plugins cannot override core configuration.

---

## Decision tree: where should this setting live?

- It's a hard limit baked into the protocol or a default that should never change without a code review → **Constants**.
- It's set per deployment (different per node) and the operator might change it without a code release → **env var**, documented in `.env.example`.
- The operator changes it through the `eiou` CLI or the GUI Settings tab → **`defaultconfig.json`** (operator-wide) or **`userconfig.json`** (per-user).
- The wallet user adjusts it interactively at runtime → flows through **`UserContext`**.

Conflict resolution: layer 4 wins for keys it exposes; otherwise layer 3; otherwise layer 2; otherwise layer 1. There is intentionally no layer-skipping (e.g. an env var can't override a `userconfig.json` value the user set deliberately) — the operator is expected to remove the JSON entry first.
