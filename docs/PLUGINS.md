# Plugins

Plugins extend an eIOU node with optional add-on code — event listeners, new
services, new repositories, CLI or API extensions — without modifying core.
They live on disk, are discovered automatically on boot, and are disabled by
default until the operator explicitly enables them.

## Table of Contents

1. [Overview](#overview)
2. [Directory Layout](#directory-layout)
3. [Manifest Schema](#manifest-schema)
4. [Database Isolation](#database-isolation)
5. [Plugin Signatures](#plugin-signatures)
6. [Lifecycle](#lifecycle)
7. [Installing Plugins](#installing-plugins)
8. [Upgrading Plugins](#upgrading-plugins)
9. [Managing Plugins in the GUI](#managing-plugins-in-the-gui)
10. [Managing Plugins from the CLI](#managing-plugins-from-the-cli)
11. [Managing Plugins over the REST API](#managing-plugins-over-the-rest-api)
12. [Sandboxed Plugin Authoring](#sandboxed-plugin-authoring)
13. [Events a Plugin Can Subscribe To](#events-a-plugin-can-subscribe-to)
14. [Writing a Plugin](#writing-a-plugin)
15. [Extending the CLI and REST API](#extending-the-cli-and-rest-api)
16. [Extending the GUI](#extending-the-gui)
17. [Registering Payback-Method Rail Types](#registering-payback-method-rail-types)
18. [Testing a Plugin](#testing-a-plugin)
19. [Safety Model and Limitations](#safety-model-and-limitations)
20. [Troubleshooting](#troubleshooting)
21. [Related Documentation](#related-documentation)

---

## Overview

A plugin is a directory containing a `plugin.json` manifest and an entry class
implementing `Eiou\Contracts\PluginInterface`. The `PluginLoader` service
scans `/etc/eiou/plugins/`, reads each manifest, autoloads the entry class via
the declared PSR-4 map, and calls its lifecycle methods during `Application`
boot.

### What plugins can do

Plugins declare what they want in `plugin.json` — core's IPC
forwarder bridges each declared surface into the plugin's
`__dispatch.php` at runtime. The full surface list:

- Subscribe to core events (sync, delivery, chain-drop, transaction,
  contact, P2P, plugin lifecycle) via the manifest's `subscribes_to`
  field
- Contribute filter values for host hooks (e.g. `gui.dashboard.widgets`,
  `gui.contact.actions`) via `filter_hooks`
- Inject HTML at named render slots (e.g. `gui.dashboard.after`) via
  `render_hooks`
- Add top-level GUI tabs (`tabs`), POST action handlers (`gui_actions`),
  and CSS/JS asset enqueues (`gui_assets`)
- Add admin-scoped REST endpoints (`api_routes`) at
  `/api/v1/plugins/<plugin>/<action>`
- Add top-level CLI subcommands (`cli_commands`) — operators invoke as
  `eiou <plugin> ...`
- Expose non-admin HTTP endpoints (`public_routes`) under
  `/p/<plugin>/<action>` for customer-bearer-token-authenticated traffic
  (off by default behind `EIOU_PUBLIC_PLUGIN_ROUTES`)
- Reach a curated subset of core services via `core_call()` declared in
  the `core_services` allow-list (`Logger.*`, the `*LookupService`
  family for read-only facades over node identity / transactions /
  contacts / runtime config, `PluginEventPublisher.publish` for
  namespaced cross-plugin events, `WalletOutboundService.send` for
  autonomous outbound transfers, `PaymentRequestService.create` for
  billing contacts, `ContainerLifecycleService.start/stopSidecar`
  for companion-container orchestration; full table in
  [Plugin-callable surface — policy](#plugin-callable-surface--policy))
- Own database tables under their `database.user` block, accessed
  through a per-plugin MySQL user with grants scoped to those tables
- Register new payback-method rail types (Bitcoin, PayPal, Bizum, …)
  via `PaybackMethodTypeRegistry` so they appear in the GUI type
  picker and route through validation/masking/precision like core types

### What plugins cannot do (by design)

- Run in the wallet pool process. Sandboxed plugins live in their own
  PHP-FPM pool as their own Unix user; the wallet pool sees them only
  through the IPC forwarder's HTTP calls to the plugin's
  `__dispatch.php`.
- Read core tables (`contacts`, `transactions`, `api_keys`, `balances`,
  `payback_methods`, …) or other plugins' tables via raw SQL — the
  plugin's own MySQL user has grants only on its `owned_tables`. Core
  data is reached through the `core_call()` gateway against allow-listed
  services. See [How plugins interact with core
  data](#how-plugins-interact-with-core-data).
- Read `/etc/eiou/config/.master.key`, `userconfig.json`, or any other
  wallet secret. The plugin pool's `open_basedir` and Unix UID block
  filesystem access; `disable_functions` blocks shell-out and `eval`.
- Crash the node during discovery, registration, or boot — failures
  are caught per-plugin, logged, and the plugin is marked as `failed`;
  core keeps running.
- Persist state outside their own directory or their own MySQL tables.
- Take effect on event subscriptions / filter / render hooks without a
  node restart — the wallet pool's IPC forwarder binds those at boot.
  Enable / disable for sandboxed plugins applies *immediately* though
  (the supervisor brings their FPM pool up or down on the toggle).


### Disabled by default

A freshly-dropped plugin folder will **not** run until the operator enables it
through the GUI and restarts the node. This opt-in posture exists so a buggy
or untrusted plugin cannot crash the node on its first boot — you get a chance
to read the manifest, inspect the code, and then deliberately turn it on.

---

## Directory Layout

Each plugin lives in its own subdirectory under `/etc/eiou/plugins/`. The
canonical layout:

```
/etc/eiou/plugins/
└── hello-eiou/
    ├── plugin.json         # required — manifest
    ├── CHANGELOG.md        # optional — bundled, rendered in the GUI
    ├── README.md           # optional — for humans reading the directory
    └── src/
        └── HelloEiouPlugin.php   # entry class, loaded via PSR-4
```

Only `plugin.json` and the entry class are required. Everything else is
optional. Additional `src/` files are loaded lazily through the declared PSR-4
autoload map — you can structure the plugin however you like.

### On-disk state

The persisted enabled/disabled flag for every plugin lives in a shared file:

```
/etc/eiou/config/plugins.json
```

Schema: `{ "<plugin-name>": { "enabled": true|false }, ... }`. This file is
written by `PluginLoader::setEnabled()` when the operator toggles a plugin in
the GUI. Manual edits take effect on the next restart.

### Bundled plugins

Plugins shipped inside the Docker image (currently just `hello-eiou`) live at
`/app/plugins/` in the image and are seeded into `/etc/eiou/plugins/` on first
boot via `cp -rn` — `-n` means "no clobber", so if an operator has removed or
modified a bundled plugin, the change persists across container rebuilds.

### Volume persistence

`/etc/eiou/plugins/` is mounted on a named Docker volume (`{node}-plugins`,
declared in `docker-compose.yml` and in the Dockerfile `VOLUME` directive).
The volume is what makes the `cp -rn` behaviour above actually hold: on a
container rebuild (`docker compose down && docker compose up --build`) the
volume persists, so operator-installed plugins, operator-removed bundled
plugins, and any plugin-owned on-disk state all survive unchanged. Without
the volume an image rebuild would re-seed every bundled plugin from scratch
and silently drop everything the operator added. See
[DOCKER_CONFIGURATION.md](DOCKER_CONFIGURATION.md) for the full volume list
and backup-priority guidance.

---

## Manifest Schema

`plugin.json` is a JSON object. Minimum viable manifest:

```json
{
  "name": "my-plugin",
  "version": "1.0.0",
  "entryClass": "Eiou\\Plugins\\MyPlugin\\MyPlugin",
  "autoload": {
    "psr-4": {
      "Eiou\\Plugins\\MyPlugin\\": "src/"
    }
  },
  "sandboxed": true
}
```

`"sandboxed": true` is required — the loader refuses to load plugins
without it (see [Sandboxing is mandatory](#sandboxing-is-mandatory)).
The four other required fields (`name`, `version`, `entryClass`,
`autoload`) shape the same way they always did.

Full manifest with all the surfaces a sandboxed plugin can declare,
plus the optional metadata and the `database` block for plugins that
want their own MySQL user (see [Database
Isolation](#database-isolation)):

```json
{
  "name": "my-plugin",
  "version": "1.0.0",
  "description": "Short one-liner shown in the Plugins table.",
  "entryClass": "Eiou\\Plugins\\MyPlugin\\MyPlugin",
  "autoload": {
    "psr-4": {
      "Eiou\\Plugins\\MyPlugin\\": "src/"
    }
  },

  "sandboxed": true,

  "author": {
    "name": "Acme Co.",
    "url": "https://acme.example"
  },
  "homepage": "https://acme.example/plugins/my-plugin",
  "changelog": "https://acme.example/plugins/my-plugin/CHANGELOG.md",
  "license": "MIT",

  "min_upgradable_from": "1.0.0",

  "core_services": ["Logger.info", "TransactionLookupService.getByTxid"],

  "subscribes_to": ["transaction.received", "sync.completed"],
  "filter_hooks":  ["gui.dashboard.widgets"],
  "render_hooks":  ["gui.dashboard.after"],

  "tabs":         [{"id": "my-tab", "label": "My Tab", "icon": "fas fa-puzzle-piece", "order": 50}],
  "gui_actions":  [{"name": "myPluginAction", "tier": "csrf"}],
  "gui_assets":   [{"type": "css", "path": "assets/styles.css"}],
  "api_routes":   [{"method": "GET", "action": "fortune"}],
  "cli_commands": [{"name": "my-plugin"}],

  "public_routes": [
    {
      "method": "POST",
      "action": "chat",
      "auth": "bearer",
      "rate_per_minute": 60,
      "max_body_bytes": 65536,
      "cors_allowed_origins": ["https://example.com"]
    }
  ],

  "payback_method_types": [
    {
      "id": "btc",
      "catalog": {
        "id": "btc",
        "label": "Bitcoin",
        "group": "crypto",
        "icon": "fab fa-bitcoin",
        "currencies": ["BTC"],
        "fields": [
          {"name": "address", "label": "Bitcoin address", "type": "text", "required": true}
        ]
      }
    }
  ],

  "database": {
    "user": true,
    "owned_tables": [
      "plugin_my_plugin_subscriptions",
      "plugin_my_plugin_notifications"
    ],
    "db_limits": {
      "max_queries_per_hour": 20000,
      "max_user_connections": 20
    }
  }
}
```

The declarative surface fields (`subscribes_to`, `filter_hooks`,
`render_hooks`, `tabs`, `gui_actions`, `gui_assets`, `api_routes`,
`cli_commands`, `public_routes`) replace the in-process registry
calls plugins used to make in `boot()`. Core's IPC forwarder reads
each list at boot and bridges the surface into your `__dispatch.php`
when an event fires, a hook resolves, a request hits, etc. See
[Sandboxed Plugin Authoring](#sandboxed-plugin-authoring) for the
contract and [The `__dispatch.php`
contract](#the-__dispatchphp-contract) for the wire shape.

### Field reference

| Field                  | Required | Type                  | Notes                                                                                          |
| ---------------------- | -------- | --------------------- | ---------------------------------------------------------------------------------------------- |
| `name`                 | yes      | string (kebab-case)   | Used as the key in `plugins.json` and in all API responses. Should match the subdirectory name (loader doesn't enforce, but mismatches make the GUI/CLI surfaces confusing). |
| `version`              | yes      | string (semver)       | Displayed in the Plugins table. Used for log correlation and `version_compare()` in the upgrade flow. |
| `entryClass`           | yes      | string (FQCN)         | Must implement `Eiou\Contracts\PluginInterface`. Optionally also `UninstallablePlugin` for cleanup hooks or `UpgradablePlugin` for cross-version migration hooks. |
| `autoload`             | yes      | object                | PSR-4 map: `{ "psr-4": { "Namespace\\": "src/" } }`. Relative to the plugin directory.        |
| `sandboxed`            | yes      | boolean (`true`)      | Must be `true`. Plugins missing the flag are refused at install, refused at enable, and skipped at discover. See [Sandboxing is mandatory](#sandboxing-is-mandatory). |
| `description`          | no       | string                | One-line summary, shown in the table and detail modal.                                         |
| `author`               | no       | string or object      | `"Acme Co."` or `{"name": "Acme Co.", "url": "https://..."}`. URL is validated as http(s).     |
| `homepage`             | no       | absolute http(s) URL  | Rendered as an external link in the detail modal.                                              |
| `changelog`            | no       | absolute http(s) URL  | Fallback when no bundled `CHANGELOG.md` is present. Bundled file wins when both exist.         |
| `license`              | no       | string (≤ 64 chars)   | SPDX identifier preferred (`MIT`, `Apache-2.0`, etc.). Shown next to version.                  |
| `database`             | no       | object                | Enables per-plugin MySQL user isolation. See [Database Isolation](#database-isolation).        |
| `min_upgradable_from`  | no       | string (semver)       | Lowest version this manifest can be upgraded from. `PluginUpgradeService` refuses upgrades whose installed version is below this floor. See [Upgrading Plugins → `min_upgradable_from`](#min_upgradable_from-manifest-field). |
| `core_services`        | no       | list&lt;string&gt;    | Allow-list of `<Service>.<method>` entries the plugin will call via `core_call()`. Methods not in this list are 403'd by the gateway even if they carry `#[PluginCallable]`. See [Plugin-callable surface — policy](#plugin-callable-surface--policy). |
| `subscribes_to`        | no       | list&lt;string&gt;    | Event names this plugin handles. Core's IPC forwarder POSTs each fired event into your `__dispatch.php` with `type: "event"`. See [Events a Plugin Can Subscribe To](#events-a-plugin-can-subscribe-to). |
| `filter_hooks`         | no       | list&lt;string&gt;    | Filter hook names this plugin transforms. IPC forwarder routes each `applyFilter` call into the dispatcher with `type: "filter"`. See [Extending the GUI](#extending-the-gui). |
| `render_hooks`         | no       | list&lt;string&gt;    | Render hook names this plugin contributes HTML to. IPC forwarder routes each `doRender` call into the dispatcher with `type: "render"`. |
| `tabs`                 | no       | list&lt;object&gt;    | Top-level GUI tabs this plugin adds. Each entry: `{id, label, icon, order?}`. |
| `gui_actions`          | no       | list&lt;object&gt;    | POST handlers this plugin exposes inside the wallet GUI. Each entry: `{name, tier?}`. |
| `gui_assets`           | no       | list&lt;object&gt;    | CSS/JS files to enqueue. Each entry: `{type: "css"\|"js", path}`. Paths are plugin-dir-relative. |
| `api_routes`           | no       | list&lt;object&gt;    | Admin-scoped REST endpoints under `/api/v1/plugins/<id>/<action>`. Each entry: `{method, action}`. |
| `cli_commands`         | no       | list&lt;object&gt;    | Top-level CLI subcommands. Each entry: `{name}` — operators invoke as `eiou <name> [args...]`. |
| `public_routes`        | no       | list&lt;object&gt;    | Non-admin HTTP endpoints under `/p/<plugin-id>/<action>`, off by default behind `EIOU_PUBLIC_PLUGIN_ROUTES`. See [Public routes](#public-routes--non-admin-http-under-pplugin-idaction) for the entry shape. |
| `payback_method_types` | no       | list&lt;object&gt;    | Plugin-provided payback-method rail types (Bitcoin, PayPal, Bizum, etc.). Each entry: `{id, catalog}`. See [Registering Payback-Method Rail Types](#registering-payback-method-rail-types). |
| `cron`                 | no       | list&lt;object&gt;    | Host-driven scheduled tasks. Each entry: `{interval_minutes, action, timeout_ms?}` (`interval_minutes` bounded `[1, 1440]`, `action` kebab-case, optional `timeout_ms` overrides the default 5s cron dispatch budget, clamped to 25s — see [Per-entry timeout](#per-entry-timeout-optional)). See [Scheduled Tasks (cron)](#scheduled-tasks-cron). |

### Validation

`PluginLoader::listAllPlugins()` normalizes every optional field before
emitting it to the GUI:

- URLs must be absolute `http://` or `https://`. Anything else (`javascript:`,
  `data:`, relative paths) is silently dropped, so a hostile manifest cannot
  slip arbitrary schemes into the clickable `<a href>` tags the GUI renders.
- `author` accepts a plain string (wrapped to `{"name": ...}`) or an object;
  the object's `url` goes through the same URL validation.
- `license` is capped at 64 characters.

Invalid values are dropped, not rejected — a manifest with one bad field still
loads the plugin with the rest of its metadata intact.

**The `database` block is the one exception.** A malformed `database` block
aborts the plugin load and the plugin surfaces in the list as
`status: failed` with an explanatory error — silently dropping a
half-broken DB declaration would leave the plugin running with no grants
and no tables, producing obscure "Unknown table" errors at runtime instead
of an honest "your manifest is broken" surface. See
[Database Isolation](#database-isolation).

### Bundled `CHANGELOG.md`

If a plugin ships a `CHANGELOG.md` file next to its `plugin.json`, the GUI's
detail modal surfaces a **View bundled CHANGELOG.md** button instead of a
clickable external link. Clicking it opens a nested modal that renders the
file's markdown server-side through
`UpdateCheckService::markdownToHtml()` (the same parser that powers the
What's New modal, so the same
`htmlspecialchars`-first escaping applies to plugin content).

Advantages over an external `changelog` URL:

- Works offline — Tor-only nodes, air-gapped deployments, and operators
  without browser access to the open internet still see release notes
- Keeps the operator inside the wallet UI instead of punching out to a browser
- Ships with the plugin — no version skew between the code and its release
  notes

The file is capped at 256 KB and read by name through
`PluginLoader::readChangelog()`, which cross-checks the plugin name against
the on-disk listing before touching the filesystem to prevent
`../etc/passwd`-style traversal.

---

## Database Isolation

Plugins that need to store data get their own MySQL user and their own table
namespace. Core tables (`contacts`, `transactions`, `api_keys`, etc.) are
completely unreachable from a plugin's PDO handle — the isolation is enforced
at the MySQL privilege level, not at the application layer.

### Opting in

Declare a `database` block in your `plugin.json`:

```json
"database": {
  "user": true,
  "owned_tables": [
    "plugin_my_plugin_subscriptions",
    "plugin_my_plugin_notifications"
  ],
  "db_limits": {
    "max_queries_per_hour": 20000,
    "max_updates_per_hour": 5000,
    "max_connections_per_hour": 500,
    "max_user_connections": 10
  }
}
```

- `user: true` is a required explicit acknowledgement — a typo on a truthy
  value (`1`, `"yes"`) is rejected. If you don't want a DB user, omit the
  block entirely.
- `owned_tables` lists every table this plugin will create. Each entry must
  match `/^plugin_[a-z0-9_]+$/` and start with `plugin_<snake_case(plugin_name)>_`
  (e.g. plugin `my-plugin` owns tables starting with `plugin_my_plugin_`).
  Listing them explicitly means the uninstall flow knows exactly what to
  drop — no prefix-scanning heuristic that could accidentally catch a
  neighbour's table.
- `db_limits` is optional; core defaults are `10000 / 5000 / 500 / 10`.
  Invalid values fall back to defaults silently so a single-limit typo
  doesn't brick the whole plugin.

Plugin names are kebab-case; table prefixes snake-case the plugin name
(`my-plugin` → `plugin_my_plugin_`). Plugin names are capped at 24 chars
for the table-name budget so the full prefix + suffix fits MySQL's 64-char
identifier limit.

### What a plugin user can do

Each plugin user gets exactly these privileges, **issued per-table** for every
entry in the manifest's `owned_tables`:

```
CREATE, ALTER, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE ON eiou.<owned_table>
```

(One `GRANT` statement per entry. MySQL/MariaDB only treats `%`/`_` as LIKE
wildcards in the *database* portion of `db.tbl`; the *table* portion is
stored as a literal name in `mysql.tables_priv`, which is why the grants
have to enumerate each table individually.)

Not included:

- **`REFERENCES`** — plugins cannot create foreign keys pointing at core
  tables. They can FK between their own tables freely.
- **`GRANT OPTION`** — plugins cannot sub-grant privileges to other users.
- **Any privilege on `eiou.*`** — there is no database-level grant. Core
  tables (`contacts`, `transactions`, `api_keys`, `payback_methods`, …) are
  invisible and inaccessible, and the plugin user cannot even `SHOW TABLES`.
- **Any privilege on other plugins' tables** — Plugin A cannot read,
  modify, or even enumerate Plugin B's tables.
- **Any privilege on tables not in this plugin's `owned_tables`** — adding
  a new table at runtime requires updating the manifest and re-enabling
  (or re-running boot-time reconciliation) so the per-table grant is
  issued. A plugin that tries to `CREATE TABLE plugin_<id>_<unlisted>` at
  runtime will be denied at the privilege check.

The user is bound to `'plugin_<snake_id>'@'localhost'` — never `'%'`. A
network-layer compromise cannot reach it via remote MySQL auth.

### How plugins interact with core data

The MySQL grants above describe what the plugin's *own database connection*
can touch. They are not the whole picture — plugins also interact with
core data, but the two paths run through different mechanisms:

**Route 1 — Plugin-owned tables via direct PDO**

Each enabled plugin gets a credentials file at
`/etc/eiou/credentials/plugin-<id>.json` (mode `0640`, owned by
`root:eiou-pc-<hash>`). The supervisor adds the plugin's pool user
(`eiou-p-<same-hash>`) to that group on every apply, and the FPM pool's
`open_basedir` is extended to admit the file's exact path. The plugin
reads the file from inside `__dispatch.php` and constructs its own PDO,
authenticated as `plugin_<snake_id>` — the same user whose MySQL grants
are scoped to `owned_tables`. No gateway round-trip, no allow-list
entry, no JSON-encoded query body. The master key never enters the pool
process; the plaintext password does, but that password is bound to
`@'localhost'` and useless without already being inside the pool
(MySQL's grants are what gate the surface, not the password's secrecy).

What it sees: only the tables listed in this plugin's `owned_tables`.
Core tables (`contacts`, `transactions`, `api_keys`, `payback_methods`,
`balances`, …) and other plugins' tables are not just hidden — they
are denied at the MySQL privilege check. `SELECT * FROM contacts` from
this PDO returns MySQL error 1142.

This is the route you use for anything the plugin *owns*: storing its
own state, building its own indexes, running its own analytics on its
own rows. See *Running queries* below for the dispatch-side code.

**Route 2 — Host-curated services via `core_call($service, $method, …)`**

What it sees: whatever each host service chooses to expose through methods
marked `#[PluginCallable]`. Those methods run inside the wallet process
with full app-user database privileges and return whatever shape they
normally return. The plugin's manifest must allow-list each
`<Service>.<method>` it intends to call in `core_services`.

This is the route you use for anything the plugin needs to *read* or
*react to* in core data. A notifications plugin doesn't query
`transactions` directly — it subscribes via `subscribes_to` and/or calls
`TransactionLookupService::getRecent()`. A custom payback-method type
doesn't query `payback_methods` directly — it registers via the
manifest's `payback_method_types` and gets invoked with the rows already
loaded.

Why the split: the host services act as a typed, business-rule-aware
boundary. They redact what shouldn't leave the core, gate sensitive
operations behind sensitive-access, and stay stable across schema
changes. A direct `SELECT * FROM api_keys` from a plugin would be a
disaster on multiple axes (schema coupling, no redaction, no
authorization, no audit) — `ApiKeyService::list()` returns hashed
identifiers and never plaintext, regardless of caller.

What this means in practice:

| Goal | Right path |
| ---- | ---------- |
| Read recent transactions | `core_call('TransactionLookupService', 'getReceivedUserTransactions', …)` |
| Look up a contact by pubkey hash | `core_call('ContactLookupService', 'getByPubkeyHash', …)` |
| List accepted contacts (paginated) | `core_call('ContactLookupService', 'listAccepted', …)` |
| Bill a contact (mint a payment request) | `core_call('PaymentRequestService', 'create', …)` |
| Stop your sidecar container on disable | `core_call('ContainerLifecycleService', 'stopSidecar', …)` |
| React to a sync event | declare `subscribes_to: ["sync.completed"]` in the manifest |
| Store the plugin's own state | direct PDO against an `owned_tables` entry (see *Running queries*) |

Common asks that are deliberately unreachable:

- Reading all wallet keys — no service exposes plaintext private keys; the
  per-plugin user can't read the wallet table either.
- Reading API key plaintext — `ApiKeyService` returns only hashed
  identifiers; the per-plugin user can't read `api_keys` either.
- `SELECT * FROM contacts` — privilege denied at MySQL.
- Modifying another plugin's tables — privilege denied at MySQL.

If a host service doesn't yet expose the data your plugin needs, the
right move is to add or extend a `#[PluginCallable]` method — not to
widen MySQL grants.

### Resource limits

The four `db_limits` keys map directly to MySQL's per-user resource caps:

| Manifest key                 | MySQL equivalent          | Default |
| ---------------------------- | ------------------------- | ------- |
| `max_queries_per_hour`       | `MAX_QUERIES_PER_HOUR`    | 10000   |
| `max_updates_per_hour`       | `MAX_UPDATES_PER_HOUR`    | 5000    |
| `max_connections_per_hour`   | `MAX_CONNECTIONS_PER_HOUR`| 500     |
| `max_user_connections`       | `MAX_USER_CONNECTIONS`    | 10      |

Defaults cap a runaway loop at roughly 3 queries per second sustained —
non-restrictive for honest plugins, visible enough to halt a bug.

### Running queries

The plugin reads its credentials file from inside `__dispatch.php` and
opens a PDO directly. Schema setup happens lazily the first time the
plugin sees a relevant envelope (or once on `type: "cli"` from an
install-time command) rather than at boot — there is no `boot()`
callback running inside the sandbox.

```php
// Inside __dispatch.php (runs as eiou-p-<hash> in the plugin's pool):

function pluginPdo(PluginLog $log): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pluginId  = basename(__DIR__);
    $credsPath = '/etc/eiou/credentials/plugin-' . $pluginId . '.json';
    $raw       = @file_get_contents($credsPath);
    if ($raw === false) {
        $log->error('credentials file unreadable', ['path' => $credsPath]);
        return null;
    }
    $cfg = json_decode($raw, true);
    if (!is_array($cfg) || !isset($cfg['host'], $cfg['database'], $cfg['username'], $cfg['password'])) {
        $log->error('credentials file malformed');
        return null;
    }

    try {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        $log->error('plugin PDO connect failed', ['error' => $e->getMessage()]);
        return null;
    }
    return $pdo;
}

function ensureSchema(PluginLog $log): bool {
    static $applied = false;
    if ($applied) return true;
    $pdo = pluginPdo($log);
    if ($pdo === null) return false;
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS plugin_my_plugin_subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    topic       VARCHAR(64) NOT NULL,
    created_at  TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY ux_topic (topic)
) ENGINE=InnoDB
SQL);
    $applied = true;
    return true;
}

// SELECT
$stmt = pluginPdo($log)->prepare(
    'SELECT id, topic FROM plugin_my_plugin_subscriptions WHERE topic = ?'
);
$stmt->execute([$topic]);
$rows = $stmt->fetchAll();

// INSERT / UPDATE / DELETE
$stmt = pluginPdo($log)->prepare(
    'INSERT IGNORE INTO plugin_my_plugin_subscriptions (topic) VALUES (?)'
);
$stmt->execute([$topic]);
$insertedId = $pdo->lastInsertId();
$affected   = $stmt->rowCount();
```

The PDO is local to the FPM worker, so transactions, prepared-statement
caching, and `lastInsertId()` work the way they normally do —
`BEGIN`/`COMMIT` around a multi-step write is fine when the plugin
needs cross-statement atomicity.

No `core_services` allow-list entry is needed for owned-table access —
the path is filesystem + MySQL privileges. The credentials file is
readable only to the plugin's own pool user (via `eiou-pc-<hash>` group
membership) and only at one exact path under `/etc/eiou/credentials/`
(the rest of the directory is outside the pool's `open_basedir`).
Privileges are enforced at the MySQL layer — the per-plugin user can
only touch the manifest's `owned_tables`, so `SELECT * FROM contacts`
(or any other core table) is denied with MySQL error 1142 even though
the PDO connection itself is unrestricted.

### Credential storage

Each plugin's MySQL password is 32 bytes of `random_bytes` base64-encoded,
wrapped via `KeyEncryption` with the plugin id baked into the AAD, and
stored in the `plugin_credentials` core table. If the operator has set
`EIOU_VOLUME_KEY` for passphrase protection, plugin credentials inherit
that protection automatically — the master key is encrypted at rest,
which transitively protects every wrapped blob.

The password is generated on first enable and persists across restarts.
It is never shown to the operator or logged; rotation replaces the
ciphertext in-place and runs `ALTER USER ... IDENTIFIED BY NEW` in the
same transaction.

### Sibling-container credentials

Some plugins are easier to ship as the eIOU plugin **plus a sibling
Docker container** the operator deploys separately — e.g. a heavy
provider-client library that doesn't fit cleanly into a per-request
PHP-FPM pool, or a long-running daemon that needs to live somewhere
the FPM lifecycle doesn't reach. To let the sibling share state with
the plugin's MySQL user, the host writes a credentials JSON file at
plugin-enable time that the operator can mount read-only into the
sibling.

**Path and contents:**

```
/etc/eiou/credentials/plugin-<plugin-id>.json
```

```json
{
  "host":      "127.0.0.1",
  "port":      3306,
  "database":  "eiou",
  "username":  "plugin_my_plugin",
  "password":  "<plaintext>",
  "issued_at": "2026-05-13T11:56:00Z"
}
```

`host` is what the wallet sees inside its own container (typically
`127.0.0.1`). The operator's sibling will almost certainly need to
override this in its own configuration to point at the eIOU
container's address on the docker network — the wallet has no way
to know the operator's network topology, so it writes what's
locally true and leaves the override to the deployer.

**File permissions and per-plugin group:**

The file is mode `0640` owned by `root:eiou-pc-<8hex>` where the
hex is `sha256(pluginId)[0:8]` — the same shape used elsewhere
in plugin sandboxing (the per-plugin system user is
`eiou-p-<same-8hex>`, so operators can recognize the user/group
pair belongs to the same plugin). The supervisor creates the
group at first apply (`groupadd -rf`, system-range GID,
idempotent) and removes it on plugin disable / uninstall
(`groupdel`, refused while members exist so a sibling deployment
that's still attached doesn't get its group pulled out from
under it).

The credentials directory itself is mode `0711 root:root`:
traverse-only, no list. Unprivileged host users can't enumerate
which plugins exist; the per-file group on each `plugin-<id>.json`
governs actual reads.

**Operator workflow on the sibling-container side:**

Pick a host-side uid for the sibling container's process and add
it to the specific plugin's group. The plugin id whose
credentials the sibling needs determines which group:

```bash
# On the docker host, AFTER the plugin has been enabled in the wallet
# (the group only exists once apply-credentials has run).
sudo usermod -a -G eiou-pc-$(printf '%s' my-plugin | sha256sum | cut -c1-8) <sibling-uid-name>
```

Then bind-mount the specific credentials file into the sibling:

```yaml
# docker-compose.yml for the sibling container
services:
  my-plugin-companion:
    image: vendor/my-plugin-companion:1.0.0
    user: "1500:1500"   # uid added to eiou-pc-<hex> above
    volumes:
      - /etc/eiou/credentials/plugin-my-plugin.json:/run/credentials.json:ro
```

Inside the sibling, read `/run/credentials.json`, override `host`
to the eIOU container's network address, and use the values to
open a MariaDB connection.

**Trust model:**

- Each plugin's credentials file lives in its own group; no
  cross-plugin read access on the host. A sibling configured for
  plugin A can't drift into reading plugin B's credentials by
  mistake (it doesn't have the group).
- The wallet pool (`www-data`) is **not** in any of these groups.
  It doesn't need to read the credential files — it has the master
  key and decrypts credentials in memory whenever it needs to act
  on them. So a wallet pool compromise doesn't grant access to the
  on-disk credential files beyond what it already has via the DB
  directly.
- Plugin FPM pools run as `eiou-p-<hex>` users. Each pool user is
  added to its **own** `eiou-pc-<same-hex>` group on apply, and the
  pool's `open_basedir` admits **its own** credentials file as an
  exact path (no trailing slash, so the rest of
  `/etc/eiou/credentials/` is outside basedir). A plugin can read its
  own credentials file; reading a sibling plugin's is denied at both
  the group layer and the basedir layer.
- The file holds **plaintext**, not encrypted — sibling containers
  can't decrypt the wrapped form (they don't have the master key,
  by design) so the protection is purely filesystem-level.
  Operators with full host root can read every credentials file;
  this is unchanged from any other secret on disk and is
  intentional.

The file is rewritten on every plugin enable and on every boot
reconcile (so a `/etc/eiou` volume recreation or manual file
deletion self-heals on the next boot). It is removed on plugin
disable and on uninstall.

**Operator obligation when retiring a plugin with a sibling:**

`groupdel` refuses to remove a group with live members. If the
operator added a sibling-container uid to `eiou-pc-<hex>` and then
uninstalls the plugin without first removing that uid from the
group, the supervisor's `groupdel` is rejected, the group persists,
and the sibling uid retains its group membership. If the same
plugin id is later reinstalled, the group already exists with the
sibling still attached — meaning the sibling would inherit access
to the new plugin's credentials without the operator opting in
again. Two ways to avoid this:

- **Recommended.** Detach the sibling before uninstall: stop the
  sibling container, then `gpasswd -d <sibling-uid-name>
  eiou-pc-<hex>` to remove it from the group, then `eiou plugin
  uninstall <name>`. The supervisor's `groupdel` succeeds and the
  group is fully torn down.

- **Defensive.** If you only operate one set of plugins on a
  host, reusing a plugin id with a new author / new database
  schema is uncommon — but if you do reuse ids, also run
  `getent group eiou-pc-<hex>` after uninstall to spot any
  lingering members and clean them up before reinstalling.

### Boot-time reconciliation

On every node boot, after the master key is loaded and before plugins'
`register()` runs, the loader runs an idempotent reconcile pass:

- For each enabled plugin with `database.user: true`: `CREATE USER IF NOT
  EXISTS` + `ALTER USER` + one `GRANT` per `owned_tables` entry. Self-heals
  after a `mysql-data` volume recreation, a manual `DROP USER`, an operator
  `db_limits` edit, or a master-key rotation. A manifest that adds a new
  table is picked up automatically on the next reconcile.
- For each disabled plugin that still has a credential row: `REVOKE ALL
  PRIVILEGES, GRANT OPTION FROM <plugin_user>` (the no-`ON` form, which
  drops every grant the user holds without needing to know which tables
  were granted). Self-heals cases where `setEnabled(false)` flipped the
  flag but didn't revoke (shouldn't happen in current code, but the
  reconciler is defensive).

Per-plugin reconcile errors are logged and surfaced as `error:<msg>` in
the plugin list's status field. They do **not** block node boot — the
operator investigates via the GUI/CLI list.

### App DB user privileges

The plugin isolation feature requires the application's own MySQL user
(`eiou_user_<hex>`, generated by `DatabaseSetup::freshInstall`) to hold
two upstream privileges so it can in turn create plugin users and grant
them their per-table access:

```
GRANT ALL ON `eiou`.* TO 'eiou_user_<hex>'@'localhost' WITH GRANT OPTION;
GRANT CREATE USER ON *.* TO 'eiou_user_<hex>'@'localhost';
```

Fresh installs receive these directly during database provisioning. On
container boot a small root-credentialed helper (`files/scripts/grant-app-user-plugin-privileges.php`,
invoked from `startup.sh` after MariaDB is up) re-applies them
idempotently — no-op when the master key isn't loadable yet, and no-op
when the user already holds them. This exists so installs that pre-date
the plugin isolation feature pick up the required grants on first boot
under a new image, and so a manual `REVOKE` against the app user
self-heals on the next restart.

These privileges are scoped to user creation and `eiou.*`-grant
delegation only — the app user cannot read `mysql.*`, cannot `FILE`,
`PROCESS`, or `SHUTDOWN`, and cannot escalate to MariaDB root. The
threat-model trade-off is that an attacker who already controls the app
user (which has full read/write on `eiou.*`, including wallet keys) can
now also create persistent backdoor users — but they could already
self-grant equivalent access via `WITH GRANT OPTION`, so this is a
timing change, not a privilege-surface widening.

### Uninstall

Uninstall is a separate, destructive action from disable. The plugin must
be **disabled first** — the service refuses to uninstall an enabled plugin
regardless of whether the request arrives via CLI, REST, or GUI.

Uninstall runs these steps, in order, on a best-effort basis (a failure in
any single step does not abort the rest — the response reports per-step
status so the operator can investigate):

1. **`onUninstall()`** hook — if the plugin implements the optional
   [`UninstallablePlugin`](#uninstallable-plugins) interface, its
   `onUninstall()` method runs while the plugin still has MySQL grants
   so it can clean up its own data. Exceptions are logged but do not
   block the remaining steps.
2. **`REVOKE ALL PRIVILEGES, GRANT OPTION FROM <plugin_user>`** — the
   no-`ON` form drops every privilege the user holds in one statement,
   regardless of which tables had grants. Locks out the plugin user before
   the table drops so a hostile plugin can't race the next steps.
3. **`DROP TABLE IF EXISTS`** for every table in `owned_tables`. Each
   name is revalidated against the `/^plugin_[a-z0-9_]+$/` shape so a
   manifest edited between install and uninstall cannot inject
   `contacts` or `api_keys` into the drop list.
4. **`DROP USER IF EXISTS`** for the plugin user.
5. **Delete** the `plugin_credentials` row and purge the in-memory PDO
   cache so any lingering connection doesn't outlive the user it was
   authenticated as.
6. **`rm -rf /etc/eiou/plugins/<name>/`** — the plugin's files.
7. **Remove** the plugin's entry from `plugins.json`.

Each step emits `ok`, `skipped`, or `error:<msg>`. Uninstall fires
`PLUGIN_UNINSTALLING` before step 1 and `PLUGIN_UNINSTALLED` after step 7
with the full step-status map in the payload.

### Uninstallable plugins

Plugins that need a cleanup hook implement the optional
`Eiou\Contracts\UninstallablePlugin` interface (an extension of
`PluginInterface` with one additional method):

```php
use Eiou\Contracts\UninstallablePlugin;
use Eiou\Services\ServiceContainer;

class MyPlugin implements UninstallablePlugin
{
    // ... normal getName / getVersion / register / boot ...

    public function onUninstall(ServiceContainer $container): void
    {
        // Runs BEFORE MySQL revoke — full grants still available, so
        // the plugin's own PDO (constructed from
        // `/etc/eiou/credentials/plugin-<id>.json` inside the pool,
        // see *Running queries*) still works against `owned_tables`.
        // Sandboxed plugins reach this hook through an
        // `__dispatch.php` envelope with `type: "uninstall"`; the
        // `$container` argument is retained on the interface for
        // signature compatibility but the in-pool ServiceContainer
        // cannot reach the master key or `dbconfig.json`.
        //
        // Typical uses: ping an external service to revoke a
        // subscription, purge a remote cache, write a final audit row.
        //
        // Implementations MUST be idempotent — uninstall may retry
        // after a partial failure.
    }
}
```

Plugins that don't need cleanup simply don't implement the interface and
step 1 is skipped. Most plugins won't need it — table removal is handled
automatically from the manifest.

### Threat model notes

This design isolates the **database layer**, not PHP execution. A malicious
plugin still runs arbitrary PHP in the node process and can do anything
the filesystem permits. The DB isolation closes the most valuable target
(wallet data), but doesn't turn plugins into a sandbox. For truly hostile
plugins, the mitigations are upstream: manifest signatures, operator-
vetted install sources, code review. See also [What plugins cannot do
(by design)](#what-plugins-cannot-do-by-design).

Plugins sharing a single MySQL instance with core means a pathological
query can still starve the instance (the `MAX_*_PER_HOUR` caps reduce but
don't eliminate this). Separate MySQL instances would be stronger but are
an operational step-change — revisit if a real starvation incident
materializes.

---

## Plugin Signatures

Plugins can ship with an **Ed25519 detached signature** that binds every
byte of the manifest and source tree. Operators trust a set of public keys
up front; unsigned plugins (or plugins signed by an untrusted key) are
rejected when signature enforcement is on. This closes the "a plugin I
installed yesterday was swapped for a backdoored copy today" supply-chain
window at the file-on-disk level.

Signatures are **complementary** to, not a replacement for,
[Database Isolation](#database-isolation). DB isolation limits what a
running plugin can touch; signatures limit what code can run in the first
place.

### Trust model

Two layers of trusted-key directories, both scanned on every plugin-load
pass:

| Layer | Path | Source | When to use |
|-------|------|--------|-------------|
| Baked-in | `/app/eiou/plugins/trusted-keys/` | Image (read-only) | First-party / eIOU-official keys that ship with every node |
| Operator | `/etc/eiou/plugins/trusted-keys/` | Config volume | Third-party publishers you've vetted and decided to trust |

Both directories accept `*.pub` files — plain text, one or more base64
Ed25519 public keys per file, `#` lines are comments. Multiple keys per
file are fine; duplicates across files are de-duplicated silently.

Adding a key is a **deliberate operator action** that says "I trust
whoever holds the corresponding private key to publish plugins on my
node." The verifier cannot distinguish "this plugin is safe" from "this
key is trusted" — trust is a human decision, signatures are the
machine-enforceable bit.

### Enforcement modes

Controlled by `Constants::PLUGIN_SIGNATURE_MODE`:

| Mode | Behaviour |
|------|-----------|
| `off` (default) | Don't verify. Load every plugin regardless of signature state. Backwards-compatible default so existing unsigned plugins keep working during rollout. |
| `warn` | Verify, log failures, but still load the plugin. The plugin list surfaces `signature.status` (`ok` / `unsigned` / `untrusted_key` / `bad_signature` / `malformed_sig` / `malformed_manifest`) so operators can fix signing before flipping to `require`. |
| `require` | Verify and **refuse to load** any plugin whose signature is missing, malformed, bound to an untrusted key, or fails verification. Failed plugins surface in the plugin list with `status: failed` and an explanatory error. |

Recommended rollout: ship an image with `off` as the default → turn your
node to `warn` locally to see what would fail → sign everything you want
to keep → flip to `require`. The verifier cost is roughly one Ed25519
verification per plugin per boot (~1ms each) so leaving it on `warn` or
`require` long-term is free.

### Wire format

Every signed plugin has a `plugin.sig` file alongside `plugin.json`:

```json
{
  "algorithm": "ed25519",
  "key_fingerprint": "sha256:<64-hex-chars>",
  "signature": "<base64 of raw 64-byte Ed25519 signature>"
}
```

The signed payload is deterministic:

```
plugin.json bytes  +  0x00  +  sha256-hex-of-src-tree
```

Where `sha256-hex-of-src-tree` is:

```
SHA-256 ( concat, in sorted path order, for every file under src/:
    relpath + 0x00 + SHA-256(file contents) + 0x00
)
```

Any byte change in the manifest or any source file invalidates the
signature on the next verification pass — the attack window between sign
time and install time doesn't extend past boot.

### Key format

`*.pub` files — one or more keys per file, plain text:

```
# eIOU official release signing key
# fingerprint: sha256:abc123...
# issued: 2026-04-24
Ab3+k/...base64 of raw 32-byte Ed25519 public key...==
```

Private keys use the same format (base64 of the raw 64-byte Ed25519
secret key, with a leading comment). File mode should be `0600` — the
signing helper chmods it for you on generation.

### Signing your own plugins

The runtime image bundles `plugin-sign.php` at
`/app/eiou/scripts/plugin-sign.php`. Three subcommands:

**1. Generate a keypair** (one-time):

```bash
docker exec -it <node> sh -c 'cd /tmp && php /app/eiou/scripts/plugin-sign.php generate-key'
# Writes <fingerprint>.pub and <fingerprint>.key into /tmp.
# Copy them out:
docker cp <node>:/tmp/<fingerprint>.key ./my-plugins.key
docker cp <node>:/tmp/<fingerprint>.pub ./my-plugins.pub
```

Keep `.key` secret — it's the signing authority for anything that says
"I'm this publisher." Treat it like an SSH private key: chmod 600, keep
out of backups/CI images, use a password manager or hardware token if
available. The `.pub` is safe to share.

**2. Install the public key into the operator trust store**:

```bash
docker cp ./my-plugins.pub <node>:/etc/eiou/plugins/trusted-keys/my-plugins.pub
```

From this moment, any plugin signed with the corresponding private key
loads on this node.

**3. Sign a plugin**:

```bash
docker cp ./my-plugins.key <node>:/tmp/my-plugins.key
docker exec <node> php /app/eiou/scripts/plugin-sign.php sign \
    --key=/tmp/my-plugins.key \
    --plugin=/etc/eiou/plugins/my-plugin
# Writes /etc/eiou/plugins/my-plugin/plugin.sig
docker exec <node> rm /tmp/my-plugins.key   # remove the private key ASAP
```

Re-sign whenever you change `plugin.json` or any source file — the
deterministic hash means even a whitespace edit invalidates the previous
signature.

**4. Verify** (useful in CI before publishing):

```bash
docker exec <node> php /app/eiou/scripts/plugin-sign.php verify \
    --plugin=/etc/eiou/plugins/my-plugin
# Exit 0 on valid, 1 on any failure.
```

### What you can and can't do with a stolen private key

- An attacker with your private key **can** publish plugins that load on
  any node that trusts your public key.
- An attacker with your private key **cannot** reach into nodes where
  your public key isn't in the trust directory.
- If you suspect a key is compromised: remove the `.pub` from every
  node's `/etc/eiou/plugins/trusted-keys/` immediately. On next
  boot every plugin signed by that key becomes `untrusted_key`. Generate
  a new keypair, distribute the new public key, re-sign your plugins.

### Threat model honesty

Signatures close the "installed file was tampered with / swapped after
install" attack surface — that's the most common real-world supply-chain
vector. They don't close:

- **Compromised publisher** — if the private-key holder is itself
  malicious (or their key was stolen and is being used to sign a
  backdoored plugin by the attacker), verification succeeds and the
  plugin loads. Mitigations there are human: review the code, pin
  plugin versions, publish reproducible builds.
- **Malicious plugin that was always malicious** — signatures don't
  attest to behaviour, only to origin. A well-known attacker with a
  trusted key can still ship a well-signed malicious plugin.
- **PHP execution sandbox** — plugins still run in the node process.
  See [Safety Model and Limitations](#safety-model-and-limitations).

---

## Lifecycle

Two distinct lifecycles run in parallel because the wallet and the
plugin live in different PHP-FPM pools.

### Wallet-side lifecycle — IPC forwarder registration

`PluginLoader` runs three phases inside `Application::__construct`,
all in the **wallet's** PHP-FPM worker (not the plugin's):

1. **`discover()`** — Scans `/etc/eiou/plugins/` for subdirectories
   with a `plugin.json`. Parses each manifest. Records the plugin's
   declarative surfaces (`subscribes_to`, `filter_hooks`,
   `render_hooks`, `tabs`, `gui_actions`, `gui_assets`, `api_routes`,
   `cli_commands`, `public_routes`). Plugins missing
   `"sandboxed": true` are recorded with status `legacy_unsupported`
   and skipped.

2. **`reconcileIsolation()` + `reconcileSandbox()`** — Boot-time
   self-heal. Idempotent: re-applies the per-plugin MySQL user +
   grants from the manifest; re-applies the per-plugin FPM pool
   config; prunes upgrade backups older than 30 days. Failures here
   are logged but don't abort the wallet boot.

3. **`PluginIpcForwarder::registerAll()`** — Walks every enabled
   sandboxed plugin's manifest surfaces and registers in-process
   bridges:
   - For each `subscribes_to` entry, subscribes the
     `EventDispatcher` so that when an in-process event fires, the
     forwarder HTTPS-POSTs the event payload into the plugin's
     `__dispatch.php` with `type: "event"`.
   - For each `filter_hooks` / `render_hooks` entry, registers an
     `onFilter` / `onRender` listener that does the same.
   - For each `gui_actions`, `api_routes`, `cli_commands` entry,
     registers a handler in the matching in-process registry
     (`GuiActionRegistry`, `PluginApiRegistry`, `PluginCliRegistry`)
     that forwards the invocation as an IPC call.

The wallet pool **does not load the plugin's PHP code** — sandboxed
plugins live in their own FPM pool and only that pool sees their
classes. `PluginInterface::register()` and `PluginInterface::boot()`
are still on the interface for compatibility but they run inside the
plugin's pool on each `__dispatch.php` request, not in the wallet
pool's boot path.

### Plugin-side lifecycle — per-request init in the plugin pool

Each call into the plugin's pool — whether from the IPC forwarder
(`type: "event"` / `"filter"` / `"render"` / `"action"` / `"rest"` /
`"cli"`) or from a customer (`type: "public"`) — runs the dispatcher
in a fresh-or-recycled FPM worker. The dispatcher:

- Reads the request envelope
- Loads the plugin's autoload + entry class
- Runs the plugin's per-type handler (which the plugin author writes
  in `__dispatch.php`)
- Buffers any log lines into `_log`, returns the response

Workers cycle on `pm.max_requests` / `pm.process_idle_timeout` —
opcache picks up new code via mtime, so the upgrade flow's directory
swap plus FPM SIGUSR2 reload is what causes workers to pick up the
new on-disk version.

### One-shot `on_enable` hook

When `PluginLoader::setEnabled($id, true)` succeeds, the host
dispatches a single `lifecycle` envelope at the plugin's
`__dispatch.php` before returning to the caller:

    {
      "type":    "lifecycle",
      "name":    "on_enable",
      "context": {"fired_at": 1715635200}
    }

This is the sandbox-model replacement for the pre-sandbox `boot()`
method. Plugins that need to wire sidecars (call
`ContainerLifecycleService.startSidecar`), prime caches, verify a
provider, or do any other one-shot setup do it here. The dispatch
budget is 5 seconds (same as `cron`); failures are logged but do
**not** roll back the enable — the plugin's state is already
committed by the time the hook fires. Disable does NOT mirror this:
by the time we'd dispatch the plugin's pool has already been
dropped, and the call would never reach a live worker. Plugins that
need cleanup work should register it inside the `on_enable` handler
(e.g. via a manifest-declared cron that drains a wind-down queue).

Plugin-side handling looks like every other envelope:

    if ($type === 'lifecycle' && $name === 'on_enable') {
        // one-shot setup work — verify, prime, register sidecars …
        return ['ok' => true];
    }

### Why GUI / event subscriptions need a wallet restart

Toggling a plugin on or off in `/etc/eiou/config/plugins.json` is
immediate, and the supervisor brings the plugin's pool up or down on
the toggle — so **plugin endpoints work immediately**. But the
wallet pool's in-process IPC forwarder binds its event / hook
listeners at boot (`registerAll`'s pass). Those bindings are frozen
in the running wallet workers; a wallet restart recycles them
through `registerAll` again so the new on-disk subscriptions list
takes effect.

The GUI's **Restart node** button (shown inside the yellow "changes
saved" banner when the wallet's on-disk plugin state diverges from
what's bound in the running workers) triggers this via the
request-marker pattern documented in [ARCHITECTURE.md](ARCHITECTURE.md)
— see `RestartRequestService` and `NodeRestartService`.

---

## Installing Plugins

There are three ways to land a plugin's files in `/etc/eiou/plugins/<name>/`:

1. **Drop the directory in by hand** — log in to the container, copy the
   plugin's files into `/etc/eiou/plugins/`, then enable it in the GUI / CLI.
   This is the path operators with shell access have always had. The plugin
   is disabled by default ([Disabled by default](#disabled-by-default)).

2. **Bundled** — plugins shipped inside the Docker image are seeded into the
   plugins volume on first boot via `cp -rn` ([Bundled
   plugins](#bundled-plugins)).

3. **Upload a `.zip` through the GUI** — the operator picks a `.zip` from
   their machine and the node stages it disabled, after a layered validation
   pass. The rest of this section documents that path.

### Why a separate code path

A plugin installs at PHP-FPM privilege — once it runs, it can do anything PHP
can do (see [No sandboxing](#no-sandboxing)). The upload path's job is not to
make every plugin safe — it can't — but to make the *install decision*
deliberate and to ensure that a malformed, oversized, or hostile zip cannot
exploit the node before the operator has even decided whether to enable the
plugin. Trust comes from the operator (and optionally the signature trust
chain — see [Plugin Signatures](#plugin-signatures)). Mechanical safety
comes from the gates below.

### Service

`Eiou\Services\Plugins\PluginInstallService` owns the validation and extraction
pipeline. The GUI's `pluginsUpload` action delegates to it. The CLI does
not yet expose this — operators with shell access can already drop files in
directly, so a CLI form would just duplicate the existing path.

### Validation pipeline

Validations run in the order listed. The pipeline short-circuits on the
first failure, and any bytes already written to the staging directory are
removed before the error returns.

| Gate                          | Limit / Rule                                                                                         | Why                                                                                              |
| ----------------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| File readable, non-empty      | `PluginInstallService::MAX_ZIP_BYTES` (25 MiB)                                                       | First-line DoS guard; rejects before the zip is even opened.                                     |
| Magic bytes                   | First 4 bytes must be `PK\x03\x04`                                                                   | Client-supplied MIME / extension aren't trusted; the magic header is.                            |
| Path traversal                | No `..`, no leading `/`, no `\`, no `./`                                                             | Zip-slip — prevents extraction from escaping `/etc/eiou/plugins/`.                               |
| Single top-level directory    | All entries share one root, matching `^[a-z0-9][a-z0-9_-]{0,63}$`                                    | A plugin is one named directory; multiple roots or capital-letter names break the loader's contract. |
| Per-file uncompressed size    | `MAX_FILE_BYTES` (15 MiB)                                                                            | No single file can dominate the install; sized to forgive heavy single assets (large splash images, bundled fonts) without raising the aggregate cap. |
| Total uncompressed size       | `MAX_UNCOMPRESSED_BYTES` (50 MiB)                                                                    | Disk-space cap.                                                                                  |
| File count                    | `MAX_FILE_COUNT` (500)                                                                               | Inode-pressure cap.                                                                              |
| Compression ratio             | `MAX_COMPRESSION_RATIO` (100:1)                                                                      | Zip-bomb sentinel; legitimate plugin zips sit well under 20:1.                                   |
| File extension allow-list     | `php, json, md, txt, css, js, map, html, htm, svg, png, jpg, jpeg, gif, webp, ico, woff, woff2, ttf, otf, eot` | Refuses `.phar`, `.so`, `.htaccess`, hidden dotfiles, anything outside the plugin asset contract. |
| Symlink rejection             | No `is_link()` paths in the extracted tree                                                           | Belt-and-suspenders: even if entry walk approved a name, an actual symlink on disk is anomalous. |
| Extraction stays inside root  | `realpath()` of every extracted path is under the staging dir                                        | Cross-checks the entry walk against the post-extraction filesystem state.                        |
| Manifest validation           | `plugin.json` parses, `name` matches directory, `version` and `entryClass` present                   | Catches corrupt manifests before the loader has to.                                              |
| No overwrite                  | Target dir must not already exist                                                                    | Install is not update; refresh through uninstall → upload so the destructive step is explicit.   |
| Signature (mode dependent)    | In `require` mode, the zip must include a valid `plugin.sig` against a trusted key                   | See [Enforcement modes](#enforcement-modes). `warn` and `off` modes accept the upload but report the verifier's verdict in the response. |

The numeric limits and the allow-list are returned to the GUI by the
`pluginsUploadLimits` action so the upload form can echo them without
duplicating constants.

### Staging and atomic publish

Uploaded bytes are not written under their final name. The pipeline:

1. The upload tmp file (provided by PHP under `$_FILES['plugin_zip']['tmp_name']`)
   is opened read-only via `ZipArchive`.
2. Every entry is walked with `statIndex()` — no extraction yet — and the
   gates above run against the metadata.
3. If the walk passes, extraction targets a sibling directory:
   `/etc/eiou/plugins/.staging-<16-hex-chars>/`. A failure here leaves the
   staging directory in place, which the service immediately recursive-deletes
   inside the catch block.
4. The extracted tree is walked again for symlinks / realpath escapes, and
   the manifest is parsed.
5. If signature mode is `require`, the verifier runs against the staged tree.
   A bad verdict aborts the install; the staging directory is cleaned up.
6. `rename()` moves `.staging-…/<plugin_id>/` to
   `/etc/eiou/plugins/<plugin_id>/`. `rename()` inside the same filesystem is
   atomic, so `PluginLoader::discover()` never sees a half-written plugin.
7. The staging parent is removed. The `PLUGIN_INSTALLED` event fires.

If anything between steps 3 and 6 fails, the catch block recursive-deletes
the staging directory before re-throwing, so failed uploads leave no
artefacts on the volume.

### What the upload does *not* do

- **Enable.** Installed plugins land disabled. No `register()` or `boot()`
  runs. The operator must toggle the plugin on and restart the node, exactly
  like a manually-dropped-in plugin. This matches the
  [Disabled by default](#disabled-by-default) stance.
- **Replace an existing plugin.** Re-uploading a plugin whose `name` is
  already installed returns `409 already_installed` with a message
  pointing at the upgrade flow. To replace the plugin while preserving
  its data, the GUI offers a confirm modal that re-routes the same zip
  through `pluginsUploadAsUpgrade`. See [Upgrading
  Plugins](#upgrading-plugins) for the full story. (Operators who
  *do* want a destructive replace — losing the plugin's DB tables,
  credentials, and gateway token — uninstall first and then upload as
  a fresh install.)
- **Bypass signature enforcement.** If `PLUGIN_SIGNATURE_MODE` is set to
  `require`, the upload path applies the same verifier as the loader.

### Response shape

```json
{
  "success": true,
  "plugin_id": "my-plugin",
  "version": "1.2.3",
  "signature": {
    "status": "ok",
    "key_fingerprint": "sha256:…",
    "enforced": true
  },
  "enabled": false,
  "restart_required": false,
  "message": "Plugin uploaded and staged as disabled. Enable it and restart the node to activate."
}
```

`signature.status` mirrors `PluginSignatureVerifier::verify()`: `ok`,
`unsigned`, `untrusted_key`, `bad_signature`, `malformed_sig`,
`malformed_manifest`, or `not_checked` when no verifier is wired.

### Errors

| HTTP | Code                 | When                                                                   |
| ---- | -------------------- | ---------------------------------------------------------------------- |
| 400  | `invalid_upload`     | No file, partial upload, PHP `UPLOAD_ERR_*`, or forged `tmp_name`      |
| 400  | `invalid_zip`        | Magic-byte / zip-slip / oversize / bad-extension / manifest failures   |
| 409  | `already_installed`  | Target directory already exists                                        |
| 500  | `install_unavailable`| Service not wired (early-boot / no-wallet state)                       |
| 500  | `install_failed`     | Filesystem failure, signature required but verification failed, etc.   |

### Trust boundary

Every check above is mechanical. None of them can answer "should I trust
this plugin's code?" — that is a human decision, and a plugin you trust
enough to enable runs at PHP-FPM privilege ([No
sandboxing](#no-sandboxing)). Before enabling an uploaded plugin:

- Read the manifest.
- Read the changelog (the detail modal renders it).
- Note the signature status — `ok` proves only that the plugin was signed
  by a key in `trusted-keys/`, not that the code is safe.
- If you're running with `database.user` declarations, review them —
  enabling triggers `CREATE USER` / `GRANT`.

---

## Upgrading Plugins

Replacing a plugin's on-disk code with a newer version preserves the
operator's state — MySQL tables, plugin user, credentials, the gateway
bearer token. Doing this through uninstall-then-install would lose all
of that (DROP TABLE for every owned table, DROP USER, delete credential
row), which is why upgrade is a separate flow with its own service and
its own end-to-end test coverage.

Two paths feed into the same engine:

| Path                       | When it fires                                                                                          |
| -------------------------- | ------------------------------------------------------------------------------------------------------ |
| **Zip upload (`pluginsUploadAsUpgrade` / GUI)** | Operator uploads a newer-version `.zip` of an already-installed plugin and confirms the replace.       |
| **Bundled (`eiou plugin upgrade <name>` / `pluginsUpgrade`)** | The image (`/app/plugins/<name>/`) ships a newer version than the operator's plugins volume holds.    |

Both routes call the same `PluginUpgradeService` and produce the same
step-status envelope.

### What the upgrade flow does

1. **Validate the new bundle.** Zip path runs the same magic-bytes /
   size cap / entry walk / manifest / signature checks that
   `pluginsUpload` runs. Bundled path validates the on-disk
   `/app/plugins/<name>/plugin.json` shape but skips zip ceremony.
2. **Read the old manifest.** Refuses if the plugin isn't installed
   (this is upgrade, not install).
3. **Version compare** via `version_compare()`. Refuses:
   - Equal versions — nothing to do.
   - Downgrades — the operator would have to uninstall + install
     explicitly to acknowledge the destructive intent.
4. **Honour `min_upgradable_from`** in the new manifest. If the
   installed version is below that floor, the operator gets a clear
   error telling them to install an intermediate version first (or
   to uninstall + reinstall and accept the data loss).
5. **Snapshot old → backup.** The current plugin dir is renamed to
   `<pluginDir>/<name>.backup-<oldver>-<YYYYMMDD-HHMMSS>/` next to
   the live plugin. Kept for 30 days by default
   (`BACKUP_RETENTION_DAYS`); the boot reconcile prunes anything older.
6. **Swap new in.** Staged dir renamed into the canonical location.
   On rename failure, step 5's snapshot is restored — the operator's
   plugin doesn't disappear.
7. **`onUpgrade()` hook.** If the new entry class implements
   `Eiou\Contracts\UpgradablePlugin`, its `onUpgrade(ServiceContainer,
   $oldVersion, $newVersion)` runs with the **old MySQL grants still
   active** (so the plugin can read/transform its existing data via
   the unchanged plugin user) and the **new code loaded** (`$this`
   resolves to the new entry class). A thrown exception triggers full
   rollback to the backup snapshot; the failed bundle is preserved at
   `<name>.failed-<ts>/` for post-mortem.
8. **Reconcile MySQL grants.** REVOKE ALL clears the old set in one
   statement; GRANT per-table from the new manifest's `owned_tables`
   rebuilds the new set. Handles both growth and shrinkage. The
   plugin's MySQL user itself is unchanged.
9. **Re-export the sibling-mountable credentials file** if the plugin
   is currently enabled. Picks up any manifest-driven changes to the
   file shape across versions.
10. **Reload the FPM pool** if the plugin is currently enabled —
    triggers the supervisor's SIGUSR2 to FPM so workers pick up the
    new on-disk code rather than continuing to hold stale class
    definitions. Disabled plugins skip this step; their next enable
    runs through the normal enable path which loads the new code.
11. **Fire `PluginEvents::PLUGIN_UPGRADED`** with `{name, old_version,
    new_version, source}`. Only fires on full success; subscribers
    never observe a "half upgraded" state because partial failures
    throw before reaching the dispatch site.

### `UpgradablePlugin` hook

Plugins that need cross-version data migration implement
`Eiou\Contracts\UpgradablePlugin`:

```php
use Eiou\Contracts\UpgradablePlugin;
use Eiou\Services\ServiceContainer;

class MyPlugin implements UpgradablePlugin
{
    public function getName(): string    { return 'my-plugin'; }
    public function getVersion(): string { return '1.1.0'; }

    public function register(ServiceContainer $c): void { /* ... */ }
    public function boot(ServiceContainer $c): void     { /* ... */ }

    public function onUpgrade(
        ServiceContainer $container,
        string $oldVersion,
        string $newVersion
    ): void {
        // Sandboxed plugins receive this hook through an
        // `__dispatch.php` envelope with `type: "upgrade"`. Run schema
        // migrations against the plugin's own PDO (constructed inside
        // the pool from `/etc/eiou/credentials/plugin-<id>.json`, see
        // *Running queries*); the `$container` argument is retained
        // on the interface for signature compatibility but the in-pool
        // ServiceContainer cannot reach the master key or
        // `dbconfig.json`.
        if (version_compare($oldVersion, '1.1.0', '<')) {
            // From __dispatch.php on a "type: upgrade" envelope:
            //
            //   pluginPdo($log)->exec(
            //     'ALTER TABLE plugin_my_plugin_keys '
            //     . 'ADD COLUMN expires_at INT NULL'
            //   );
        }
    }
}
```

Plugins that don't need migration simply don't implement the
interface — the upgrade flow handles the directory swap, grant
reconcile, and pool reload automatically.

### `min_upgradable_from` manifest field

An optional declaration in the new manifest that refuses upgrades
from a version below the declared floor:

```json
{
  "name": "my-plugin",
  "version": "2.0.0",
  "min_upgradable_from": "1.0.0",
  ...
}
```

When set, the upgrade service refuses transitions whose
`$installedVersion < $minUpgradableFrom` per `version_compare()`. Use
this when v2.0 ships a schema migration that assumes the v1.0 schema
shape — operators on v0.x must install v1.x first (so v1's
`onUpgrade` runs the intermediate migration) before stepping to v2.0.

### Backup retention

Upgrade backups live at `<pluginDir>/<name>.backup-<oldver>-<ts>/`.
The boot reconcile prunes anything older than
`PluginUpgradeService::BACKUP_RETENTION_DAYS` (30 days). Operators who
want to preserve a specific backup past the window can rename it out
of the `.backup-<ver>-<ts>` shape (the prune regex is anchored on
exactly that pattern).

To roll back manually within the retention window:

```bash
# Stop the plugin pool so it doesn't see the swap mid-request.
eiou plugin disable my-plugin
sudo rm -rf /etc/eiou/plugins/my-plugin
sudo mv /etc/eiou/plugins/my-plugin.backup-1.0.0-20260513-140000 \
        /etc/eiou/plugins/my-plugin
eiou plugin enable my-plugin
```

(`onUpgrade` was already idempotent per the contract, so the rollback
side doesn't need a reverse hook — the old code expects its own
schema shape and operates against it.)

### What's NOT preserved across upgrade

The upgrade flow preserves the plugin user, its credentials, its
owned tables, and its gateway token. It does **not** preserve:

- The plugin's PSR-4-autoloaded class instances inside the FPM
  workers. The supervisor reload recycles workers so the new code
  takes effect; any in-memory state in the old workers is lost.
- The plugin's on-disk scratch space if the new manifest's
  open_basedir paths differ from the old (rare; scratch is keyed on
  `eiou-p-<system_user>` which is plugin-id-derived, not version-
  derived).
- Custom additions a plugin made to its own directory at runtime
  that weren't in the new bundle. The swap is wholesale — anything
  in the old dir that isn't in the new bundle ends up only in the
  backup snapshot.

If your plugin writes runtime state into its own directory (rare;
DB-backed state is the supported pattern), include a stub in the
new bundle that triggers regeneration on first request, or stage
the migration via `onUpgrade`.

### Surface summary

| Surface                              | Drives                                      | Notes                                                                              |
| ------------------------------------ | ------------------------------------------- | ---------------------------------------------------------------------------------- |
| GUI: "Upgrade available" badge       | `pluginsUpgrade` → `upgradeFromBundle()`    | Row on a plugin appears with badge when image's bundled version > installed.       |
| GUI: Zip upload of installed plugin  | `pluginsUploadAsUpgrade` → `upgradeFromZip()` | After `pluginsUpload` returns 409 already_installed, operator confirms the replace. |
| CLI: `eiou plugin upgrade <name>`    | `upgradeFromBundle()`                       | Same logic as the GUI badge, accessible without the wallet's web pool.             |
| Direct PHP: `PluginUpgradeService`   | both methods                                | For test-harness and bundled scripts that don't go through CLI/GUI.                |

---

## Managing Plugins in the GUI

The Plugins section lives under **Settings → Plugins**.

### Plugins table

Each discovered plugin appears as a row:

| Column      | Shown on                  | Contents                                                                         |
| ----------- | ------------------------- | -------------------------------------------------------------------------------- |
| status dot  | always                    | Green = enabled and running · grey = disabled · red = failed to load (under the Western status-colour scheme; the Neutral scheme in Settings collapses green and red to grey so the dot only distinguishes disabled-vs-other states) |
| name        | always                    | Matches the manifest `name`                                                      |
| version     | always                    | Matches the manifest `version`                                                   |
| description | desktop only              | Manifest `description`, truncated with ellipsis (full text on hover or in modal) |
| Enabled     | desktop only              | Toggle switch — flips the persisted enabled flag                                 |

On mobile (viewport ≤ 600px), description and the toggle column are hidden —
**tap the row** to open the detail modal, which carries the toggle inside.

### Detail modal

Clicking any row opens a detail modal showing:

- Version (with `· <license>` suffix if license is set)
- Status badge
- Author (linked if a URL was provided in the manifest)
- Website (if `homepage` set)
- Changelog (bundled file button, or external URL, see above)
- Description (full, not truncated)
- Enabled toggle
- Error block (red alert) if the plugin failed to load
- **Uninstall** button — shown **only when the plugin is disabled**. Clicking
  it opens a second red-accented modal that requires typing the plugin's
  name verbatim to confirm. On submit the modal shows the per-step
  uninstall result (✓ ok, − skipped, ✕ error) inline. The UI forces the
  two-step "disable → confirm uninstall" flow; the service refuses to
  uninstall an enabled plugin regardless of how the request arrives. See
  [Database Isolation → Uninstall](#uninstall).

### Uploading a `.zip`

The section header carries an **Upload .zip** button next to **Refresh**.
Clicking it opens the OS file picker; selecting a `.zip` POSTs it to the
`pluginsUpload` action.

Three outcomes:

- **Fresh install.** The plugin's `name` isn't already on disk. The new
  plugin appears in the table as disabled (grey dot); the toast carries
  the signature status (`ok`, `unsigned`, etc.). To activate, toggle the
  plugin on and use the restart banner.

- **Plugin already installed.** The server returns `409 already_installed`.
  The GUI catches the 409 and pops a confirm modal showing the
  installed version and asking whether to upgrade. On confirm, the same
  zip is re-POSTed to `pluginsUploadAsUpgrade`, which drives the
  upgrade flow (atomic swap, `onUpgrade` hook, grant reconcile, pool
  reload) — the plugin's DB tables, credentials, and gateway token are
  preserved. See [Upgrading Plugins](#upgrading-plugins).

- **Bundled-version newer than installed.** When `/app/plugins/<id>/`
  ships a higher `version` than the operator's `/etc/eiou/plugins/<id>/`,
  the row in the table surfaces an **Upgrade available** affordance.
  Clicking it POSTs to `pluginsUpgrade` (no zip — drives the bundled
  upgrade path), same engine as `pluginsUploadAsUpgrade`.

The full validation contract and threat model for fresh installs is
documented in [Installing Plugins](#installing-plugins); the upgrade
flow lives in [Upgrading Plugins](#upgrading-plugins).

### Restart banner

A yellow banner appears above the table when the desired enabled state
diverges from the runtime state for any plugin. "Divergence" is computed per
plugin as:

```
enabled  !==  (status === 'booted')
```

So toggling a plugin on and then back off again — landing on the same state
as before — clears the banner: no restart is actually needed, and the UI
reflects that. The success toast copy mirrors the same logic: "restart the
node for the change to take effect" when divergent, "matches the current
runtime, no restart needed" when not.

---

## Managing Plugins from the CLI

Five subcommands. Toggle / uninstall don't restart on their own; the
operator follows up with `eiou restart` once they're done. The
upgrade path *does* recycle the plugin's FPM pool (so the new code
takes effect immediately for enabled plugins) but a wallet restart is
still required for the new code's event subscriptions and other
manifest-declared surfaces to bind in the wallet pool's IPC forwarder.

### `eiou plugin list`

Prints a compact table of every installed plugin: name, version, enabled
flag, runtime status, license. `--json` emits the full
`listAllPlugins()` payload so scripts see author, homepage, changelog, and
description without a schema split.

```bash
eiou plugin
eiou plugin list --json
```

### `eiou plugin enable <name>` / `eiou plugin disable <name>`

Persists the enabled flag in `/etc/eiou/config/plugins.json`. Rejects
unknown plugin names (scoped against the on-disk listing) and names that
don't match the kebab-case regex — no `../` traversal, no arbitrary state
keys. On success emits a reminder to `eiou restart`.

For plugins that declare `database.user: true`, `enable` triggers
`CREATE USER` + `GRANT` in MySQL; `disable` triggers `REVOKE`. Credentials
are generated on first enable and persisted encrypted. See
[Database Isolation](#database-isolation).

### `eiou plugin uninstall <name>`

Runs the full uninstall flow — `onUninstall()` hook, `REVOKE`, `DROP TABLE`
for every owned table, `DROP USER`, credential deletion, plugin-directory
removal, and state-file cleanup. The plugin **must be disabled first**;
the CLI returns an error otherwise (match the REST `409 Conflict`).

```bash
eiou plugin uninstall my-plugin
```

Per-step status is printed in the JSON response (`ok` / `skipped` /
`error:<msg>`) so operators can see exactly what succeeded. This is a
**permanent** action — a fresh install issues new credentials, new tables,
and a new MySQL user.

### `eiou plugin upgrade <name>`

Replaces the installed plugin code with the image-baked version under
`/app/plugins/<name>/`. The plugin's state (MySQL tables, plugin
user, credentials, gateway token) is preserved across the upgrade —
the directory swap is atomic, the old version is snapshotted to
`<name>.backup-<oldver>-<ts>/` next to the live plugin, the plugin's
`onUpgrade(...)` hook (if implemented) runs against the new code with
the old grants still active, grants are reconciled against the new
`owned_tables`, and the FPM pool reloads so workers pick up the new
code.

Refused for:

- Same version (`error: Plugin '<name>' is already at version X.Y.Z`)
- Downgrades (`error: Refusing downgrade of '<name>' from A to B …`)
- `min_upgradable_from` violations — the new manifest declares a
  floor and the installed version is below it
- No bundled version on disk

```bash
eiou plugin upgrade hello-eiou
```

Backups are pruned automatically after 30 days by the boot reconcile.
See [Upgrading Plugins](#upgrading-plugins) for the full flow, the
`UpgradablePlugin` hook contract, and the manual rollback recipe.

```bash
eiou plugin enable hello-eiou
eiou plugin disable hello-eiou
eiou restart                # once you're done toggling
```

---

## Managing Plugins over the REST API

Endpoints under the `admin` scope. Same semantics as the CLI: toggles
persist but do not restart. Pair with `POST /api/v1/system/restart` (same
scope) when you're ready to apply.

| Method | Path                                 | Action                          |
| ------ | ------------------------------------ | ------------------------------- |
| GET    | `/api/v1/plugins`                    | List installed plugins          |
| POST   | `/api/v1/plugins/{name}/enable`      | Set `enabled = true`            |
| POST   | `/api/v1/plugins/{name}/disable`     | Set `enabled = false`           |
| DELETE | `/api/v1/plugins/{name}`             | Uninstall (must be disabled)    |

`DELETE /api/v1/plugins/{name}` returns `409 Conflict` if the plugin is
still enabled, `404 Not Found` if unknown, `200` with `success: true` on a
fully-clean uninstall, and `200` with `success: false` + the per-step map
if any step reported an error. See
[Database Isolation → Uninstall](#uninstall) for the full step sequence.

**Install and upgrade are GUI-only.** Uploading a `.zip` or driving a
bundled upgrade goes through the `pluginsUpload`,
`pluginsUploadAsUpgrade`, and `pluginsUpgrade` AJAX actions on the
admin-authenticated GUI (see [Managing Plugins in the
GUI](#managing-plugins-in-the-gui)). REST API-driven install / upgrade
isn't exposed today — operators using the API can drop plugin files
into `/etc/eiou/plugins/<name>/` over `docker cp` and then call the
enable / disable endpoints above; the upgrade flow doesn't have a
REST equivalent. CLI alternatives: `eiou plugin upgrade <name>` for
the bundled-source path.

### Example

```bash
# List
curl -s -H "X-API-Key: $KEY" https://localhost/api/v1/plugins

# Enable + restart
curl -s -X POST -H "X-API-Key: $KEY" https://localhost/api/v1/plugins/hello-eiou/enable
curl -s -X POST -H "X-API-Key: $KEY" https://localhost/api/v1/system/restart
```

### Response shape

`GET /api/v1/plugins` returns the same per-plugin objects as the GUI's
`pluginsList` action — including the optional metadata fields:

```json
{
  "success": true,
  "data": {
    "plugins": [
      {
        "name": "hello-eiou",
        "version": "1.0.0",
        "description": "...",
        "enabled": true,
        "status": "booted",
        "author": {"name": "EIOU", "url": "https://eiou.org"},
        "homepage": "https://github.com/...",
        "license": "Apache-2.0",
        "has_changelog": true
      }
    ]
  }
}
```

`POST /api/v1/plugins/{name}/{enable,disable}` returns:

```json
{
  "success": true,
  "data": {
    "plugin": "hello-eiou",
    "enabled": true,
    "restart_required": true,
    "message": "Plugin state persisted. POST /api/v1/system/restart to apply."
  }
}
```

Errors: `400 invalid_name` (regex mismatch), `404 unknown_plugin`
(not on disk), `500 persist_failed` (state file unwritable),
`500 plugin_loader_unavailable` (the plugin system didn't initialize
during boot — usually means the node is in an incomplete state).

---

## Sandboxed Plugin Authoring

Plugins can opt into running in an isolated PHP-FPM pool as their own
Unix user, with no access to `/etc/eiou/config/` (and therefore no
access to the master key, the seed phrase, the encrypted private key,
or any other wallet secret). Set `"sandboxed": true` in the manifest.

The full architecture lives in this document; the subsections below
walk through what you need to know as a plugin author.

### Sandboxing is mandatory

All plugins must declare `"sandboxed": true` in their manifest. The
in-process plugin model has been removed — non-sandboxed plugins
could read the master key and decrypt the seed phrase, so the loader
refuses to load them. Operator-facing behaviour:

| Manifest | At install | At enable | At boot |
|---|---|---|---|
| `"sandboxed": true` | Accepted | Accepted | Pool spawned, IPC plumbed |
| Missing flag *or* `"sandboxed": false` | **Rejected** with `sandboxed_required` | **Rejected** — `setEnabled` returns false | `legacy_unsupported` status, auto-flipped to disabled in `plugins.json` |

Plugins run in their own per-plugin PHP-FPM pool as their own Unix
user (`eiou-p-<hash>`), with `open_basedir` restricted to the
plugin's dir + scratch, `disable_functions` blocking shell-out and
eval, and zero filesystem access to wallet secrets.

### Manifest surfaces a sandboxed plugin declares

Everything a plugin would have wired in `boot()` becomes manifest
metadata:

```json
{
  "name": "my-plugin",
  "version": "1.0.0",
  "entryClass": "Vendor\\MyPlugin\\Entry",
  "autoload": { "psr-4": { "Vendor\\MyPlugin\\": "src/" } },

  "sandboxed": true,

  "subscribes_to":  ["sync.completed", "contact.created"],
  "render_hooks":   ["gui.dashboard.after"],
  "filter_hooks":   ["gui.dashboard.widgets"],
  "tabs":           [{"id": "my-tab", "label": "My Tab", "icon": "fas fa-puzzle-piece", "order": 50}],
  "gui_actions":    [{"name": "myPluginAction", "tier": "csrf"}],
  "gui_assets":     [{"type": "css", "path": "assets/styles.css"}],
  "api_routes":     [{"method": "GET", "action": "fortune"}],
  "cli_commands":   [{"name": "my-plugin"}],

  "core_services":  ["Logger.info", "Logger.warning"]
}
```

Core reads each list at boot and registers IPC forwarders that route
each surface into the plugin's `__dispatch.php`. The plugin's own
PHP code never executes in-process.

### The `__dispatch.php` contract

The plugin ships **one** PHP file as its sandboxed entry point:
`<plugin-dir>/__dispatch.php`. nginx is configured to route
`/gui/plugin/<plugin-id>/*` to the plugin's FPM socket with
`SCRIPT_FILENAME` pinned to that file — the plugin never picks its
own entry.

Wire shape (request):

```http
POST /gui/plugin/<id>/__dispatch HTTP/1.1
Content-Type: application/json

{
  "type":    "event" | "filter" | "render" | "action" | "rest" | "cli",
  "name":    "<event_name | hook_name | action_name | route_action | command>",
  "context": <type-specific payload>
}
```

Response:

```json
{
  "ok":     true,
  "result": <type-specific>,
  "_log":   [
    {"level": "info", "message": "...", "context": {...}},
    ...
  ]
}
```

The `_log` array is copied into the wallet's central log under the
plugin's name when the dispatch returns — so log lines emitted by
the plugin appear next to wallet log lines for the same operation,
even though the plugin couldn't write `/var/log/` directly.

Use the bundled template at
`files/src/templates/plugin-dispatch-template.php` as the starting
point for your dispatcher. The template carries a
`PLUGIN_DISPATCH_VERSION` constant — bump it in lockstep with
upstream so the wallet can warn operators if your plugin's
dispatcher is older than the current contract.

### Public routes — non-admin HTTP under `/p/<plugin-id>/<action>`

The IPC contract above only carries internal core→plugin traffic
(events, filters, REST routes from authenticated admin callers). A
plugin that wants to serve **non-admin** customers — anything where
the caller holds a per-customer bearer token rather than the wallet's
admin session — declares `public_routes` in its manifest. Each
declared route is routed by nginx directly to the plugin's FPM pool
under a separate URL prefix `/p/<plugin-id>/<action>` that lives
outside the admin gate.

The feature is **off by default**. Operators opt in at the node level
by setting `EIOU_PUBLIC_PLUGIN_ROUTES=on` in the container's
environment. With the flag off, the nginx renderer skips
public-route blocks entirely — the manifest field still validates,
but no requests reach the plugin until the operator flips the flag.

Manifest shape:

```json
{
  "sandboxed": true,
  "public_routes": [
    {
      "method": "POST",
      "action": "chat",
      "auth": "bearer",
      "rate_per_minute": 60,
      "max_body_bytes": 65536,
      "cors_allowed_origins": [
        "https://example.com",
        "https://app.example.com"
      ]
    }
  ]
}
```

Per-entry fields:

| Field                  | Required | Default | Notes                                                                                                                     |
| ---------------------- | -------- | ------- | ------------------------------------------------------------------------------------------------------------------------- |
| `method`               | yes      | —       | One of `GET` / `POST` / `PUT` / `PATCH` / `DELETE`. Pinned — wrong verb returns `405` without invoking the plugin.        |
| `action`               | yes      | —       | Kebab-case, `^[a-z][a-z0-9-]{0,63}$`. Becomes the final URL segment.                                                       |
| `auth`                 | no       | `"bearer"` | Only `"bearer"` is supported today. Host validates the `Authorization` header *shape*; the plugin does the real auth check. |
| `rate_per_minute`      | no       | `60`    | Per-bearer-per-route rate cap. Bounded `[1, 6000]`. Enforced server-side via nginx `limit_req_zone`.                       |
| `max_body_bytes`       | no       | `65536` | Body size cap; bounded `[1, 1048576]`.                                                                                     |
| `cors_allowed_origins` | no       | —       | List of explicit origin strings. No wildcard `*`. Max 10 entries. When present, the host emits CORS headers and short-circuits `OPTIONS` preflight. |

What the host does, before any plugin code runs:

1. **Method gate** — wrong verb → 405.
2. **Bearer shape preflight** — `Authorization: Bearer [A-Za-z0-9._~+/=-]{8,256}` is the only shape accepted. Anything else → 401. The plugin still does the real credential check against its own state; this just refuses values that obviously can't be a token.
3. **Rate limit** — one `limit_req_zone` per route at http{} scope, keyed on `$http_authorization` (so the window is per-bearer-per-route, not per-IP — a single misbehaving customer can't starve well-behaved ones sharing the same plugin). Excess requests → 429.
4. **Body size cap** — `client_max_body_size` per route. Excess → 413.
5. **CORS** (only when `cors_allowed_origins` is declared) — `OPTIONS` preflight returns `204` with the appropriate `Access-Control-*` headers without invoking the plugin; cross-origin requests from non-allow-listed origins get an empty `Access-Control-Allow-Origin` value, which the browser's same-origin check then refuses to surface to the calling page. The host also `fastcgi_hide_header`'s `Access-Control-*` and `Vary` headers from the plugin's response — a plugin handler that emitted its own `Access-Control-Allow-Origin: *` can't fight the allow-list (browsers reject duplicate ACAO anyway, but stripping the upstream value keeps the host's intent authoritative). Don't bother setting CORS headers from your handler when `cors_allowed_origins` is configured; they'll be dropped.

What the plugin sees in `__dispatch.php`:

The dispatcher template detects public-route invocations via the
`EIOU_PLUGIN_PUBLIC_ROUTE=1` fastcgi param and synthesizes an
envelope from the raw HTTP request. From the handler's point of
view, the wire shape is the same uniform envelope as IPC:

```json
{
  "type": "public",
  "name": "<action>",
  "context": {
    "method":    "POST",
    "bearer":    "<the raw bearer token, prefix stripped>",
    "body":      "<raw request body>",
    "remote_ip": "1.2.3.4"
  }
}
```

The same `respond($status, $body, $log)` helper from the template
sends the response back. Returning JSON works as you'd expect; the
plugin is free to set its own content type via PHP's `header()` if
it needs to.

A minimal public-route handler:

```php
// inside the dispatch switch
case 'public':
    if ($name !== 'chat') {
        respond(404, ['ok' => false, 'error' => ['code' => 'unknown_action']], $log);
    }
    $bearer = $context['bearer'] ?? '';
    $customer = $myKeysTable->lookupByBearer($bearer); // your own state
    if ($customer === null) {
        respond(401, ['ok' => false, 'error' => ['code' => 'invalid_token']], $log);
    }
    $body = json_decode($context['body'] ?? '', true);
    // … do the work, charge the customer …
    respond(200, ['ok' => true, 'result' => $result], $log);
    break;
```

**Authoring constraints to keep in mind:**

- **Bearer storage.** The host doesn't know what a valid bearer is — that's plugin state. Store bearer hashes (bcrypt / argon2id) in your plugin DB, not plaintext. Match incoming tokens by constant-time compare against the hash.
- **Rate limit semantics.** `burst=rate_per_minute` lets one minute of capacity absorb a small retry storm without 429'ing every retry; `nodelay` means excess requests are rejected immediately rather than queued (so a misbehaving client sees 429 fast instead of latency-bombed responses).
- **No cookies.** The customer-bearer auth flow is intentionally stateless — sessions belong to the admin GUI, not customer-facing services. If your plugin needs per-customer state across requests, store it keyed on the bearer (or a customer id derived from it) in your plugin DB.
- **CORS is allow-list only.** Don't try to accept arbitrary origins via wildcard — the manifest validator refuses `*` and the renderer's defence-in-depth filter would drop it anyway. Add explicit origin strings; if your plugin's web frontend moves to a new origin, update the manifest and re-enable.
- **Companion-container deployment.** Some plugins are easier to ship as the eIOU plugin + a sibling Docker container the operator deploys separately (e.g. a heavy provider-client library that doesn't belong in a PHP-FPM pool). The sibling can authenticate to the plugin's MySQL user via the on-disk credentials file — see [Sibling-container credentials](#sibling-container-credentials) under Database Isolation.

### Calling core services from your handler — `core_call()`

The dispatcher template includes a `core_call($service, $method, $args, $log)`
helper. It POSTs an authenticated request to the wallet's gateway
endpoint, which validates:

1. The plugin's bearer token (loaded from `.gateway-token` in the
   plugin's dir, written there by core at enable time).
2. The plugin's manifest declares `"<Service>.<method>"` in
   `core_services` (this manifest gate is *operator-visible* —
   operators see which APIs the plugin wants to use *before*
   they enable it).
3. The target method on the core service carries the
   `#[\Eiou\Contracts\PluginCallable]` attribute. This is a per-
   method allow-list in the core codebase, reviewable in
   `git grep PluginCallable`.

Example:

```php
core_call('Logger', 'info', [
    "Sync completed for {$contactPubkey}",
    ['plugin' => 'my-plugin', 'contact_pubkey' => $contactPubkey],
], $log);
```

Returns the method's return value on success, `null` on failure
(transport error / validation rejection / handler exception).
Failure cases log to `$log` with structured context.

### Plugin-callable surface — policy

The set of methods reachable through `core_call()` is intentionally
small. As of this writing, the callable surface is:

| Service                         | Methods                                                                                              |
| ------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `Logger`                        | `debug`, `info`, `warning`, `error`                                                                  |
| `TransactionLookupService`      | `getByTxid`, `getStatusByTxid`, `existingTxid`, `isCompletedByTxid`, `getReceivedUserTransactions`   |
| `ContactLookupService`          | `getByPubkeyHash`, `listAccepted`                                                                    |
| `IdentityLookupService`         | `getPublicKey`, `getPublicKeyHash`, `getName`                                                        |
| `NodeInfoLookupService`         | `getAppEnv`, `isDebug`, `getHttpsAddress`, `getTorAddress`                                           |
| `PluginEventPublisher`          | `publish`                                                                                            |
| `WalletOutboundService`         | `send`                                                                                               |
| `PaymentRequestService`         | `create`                                                                                             |
| `ContainerLifecycleService`     | `startSidecar`, `stopSidecar`                                                                        |

That's it. The list grows **on demand**, when a concrete plugin
needs something — not speculatively. The reasoning is straightforward:
every `#[PluginCallable]` method is operator-visible attack surface.
Operators reading a plugin's manifest see the list of methods that
plugin will call; the wider the underlying surface, the harder that
review becomes. A small, deliberately-curated surface keeps the
review meaningful.

Two principles guide what gets added:

1. **Default-deny, expand for a concrete need.** A repository method
   is exposed only when a real plugin (in this repo's plugin
   directory, or a downstream plugin whose author has filed an
   issue) has a use case that can't be satisfied any other way. We
   don't preemptively expose "this looks useful" — useful is
   measured by an actual handler that would call it.

2. **Prefer narrow single-row lookups over bulk listings.** When a
   plugin needs to enrich one event payload's identifier, a
   `lookupBy<X>(id): ?array` method is strictly additive to what
   the event already told the plugin. A `getAllX(): array` method
   is a different shape of trust — it's a one-call exfiltration
   primitive for the underlying table. The first kind is easy to
   justify case-by-case; the second kind needs a concrete export
   plugin (or similar) and a deliberate decision.

If a plugin needs a method that isn't exposed, the path is: file an
issue describing the use case, propose the method signature, and
include the smallest read-only repository method that satisfies it.
The maintainer adds it as a thin `Lookup/` service if the use case
holds up. Decoration on the repository itself is *not* the path —
those classes stay pure data-access.

### Where plugin-owned state and code lives

Sandboxed plugins are full PHP applications inside their own FPM pool.
Anything beyond the dispatcher is **plugin-owned and plugin-managed** —
core doesn't load it, doesn't audit it, doesn't migrate it. A
typical layout for a non-trivial plugin:

    /etc/eiou/plugins/payback-btc/
      plugin.json                       ← manifest (declares autoload, surfaces, etc.)
      __dispatch.php                    ← entry; routes envelope types
      src/
        PaybackBtcPlugin.php            ← entry class (PluginInterface)
        CustomerRepository.php          ← plugin-owned, queries the plugin's own MySQL schema
        RefundPolicyService.php         ← plugin-owned, enforces the plugin's own caps
        OutboundAuditRepository.php     ← plugin-owned, writes to the plugin's own audit table
        ...

Your `__dispatch.php` is responsible for loading these classes
itself. The manifest's `autoload.psr-4` field is metadata that
tooling (and the pre-sandbox in-process load path) read, but the
sandboxed FPM pool **does not register an autoloader on your behalf**
— the dispatcher template ships without one. Two practical paths:

**`require_once` from the top of `__dispatch.php`:**

    require_once __DIR__ . '/src/CustomerRepository.php';
    require_once __DIR__ . '/src/RefundPolicyService.php';
    require_once __DIR__ . '/src/OutboundAuditRepository.php';

Simple and explicit. Fine for small plugins. The plugin's
`open_basedir` includes its own dir, so the includes succeed; the
disabled-functions list does not block `require_once`.

**Register a PSR-4 autoloader from the top of `__dispatch.php`:**

    spl_autoload_register(function (string $class): void {
        $prefix = 'Eiou\\Plugins\\PaybackBtc\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) require_once $file;
    });

Cleaner for plugins with many classes — instantiate by FQCN and the
file gets loaded on demand. Mirrors the autoloader core used to run
on the in-process load path; nothing stops you from porting that
exact closure into your dispatcher.

Your handler then instantiates the classes as plain PHP, and they
talk to your per-plugin MySQL user via the connection your handler
opens itself.

Core doesn't have a "factory that auto-loads plugin services" — and
deliberately so:

- **It would defeat the sandbox.** Loading plugin code into the
  wallet's FPM pool would give that code the wallet's master key,
  full DB access, and no `open_basedir` restriction. That's the
  in-process model the mandatory-sandboxing change deliberately
  removed.
- **The plugin's pool is the right home anyway.** Plugin state
  belongs in the plugin's own DB schema (isolated MySQL user, can't
  reach wallet tables); plugin business logic runs as the plugin's
  Unix user under restricted `open_basedir`. There's no reason for
  the wallet pool to know about either.

What core DOES provide for plugin-owned tables: per-plugin database
isolation (see [Database Isolation](#database-isolation)). Your
plugin gets a MySQL user, a credential entry, optional
sibling-container credential export, and grants on tables your
manifest declares via `owned_tables` (or unrestricted DDL if you
prefer to `CREATE TABLE IF NOT EXISTS` from your own boot path).
What core does NOT provide is a class loader for your services —
load them yourself from `__dispatch.php` using one of the two
patterns above.

The trade-off this design accepts: plugin-managed state is opaque to
the operator. If a plugin maintains its own audit log of, say,
outbound spending, the operator's view of that log is whatever the
plugin chooses to show. The honest framing is that this matches the
trust model — if you trust a plugin enough to allow-list a
mutating core service like `WalletOutboundService.send`, you trust
it enough to keep its own honest audit. If you don't, the right
path is the event-publish + operator-approval flow, where the
operator is the one clicking through the existing wallet send GUI
and the canonical record is the wallet's own `transactions` table.

### Where plugin runtime files (caches, locks, state files) live

**Not your own plugin folder.** `/etc/eiou/plugins/<your-id>/` is
owned by `www-data` (the wallet pool extracted the zip and chowned
it that way during install). Your pool runs as `eiou-p-<hash>`, a
different uid that has *read* access to those files (via the
filesystem's other-permission bits) but no *write* access. A naïve
`file_put_contents(__DIR__ . '/cache/foo.json', …)` from your
dispatcher returns false with EACCES.

**Use the scratch dir instead.** Each sandboxed pool gets a
private writable directory at
`/var/lib/eiou/plugin-scratch/<your-system-user>/`, created by the
supervisor on every `apply-pool` and chowned `<your-system-user>:<your-system-user>`
with mode `0700`. It's the only path under `/var/` your `open_basedir`
admits — perfect home for SQLite databases, decoded caches, lock
files, last-tick timestamps, anything the pool itself produces.
Derive the directory from the plugin id without trusting any
external input:

    $systemUser = 'eiou-p-' . substr(hash('sha256', basename(__DIR__)), 0, 8);
    $scratch    = '/var/lib/eiou/plugin-scratch/' . $systemUser;

The scratch dir survives container restarts (it's on the
`/var/lib/eiou` volume) and survives plugin upgrades, but is torn
down on uninstall.

If you need durable, queryable state — schedule rows, customer
records, an audit log the operator can inspect via MySQL CLI — use
your per-plugin MySQL user (see [Database Isolation](#database-isolation))
rather than scratch. SQLite-in-scratch is the right tool for
plugin-private caches that don't need to outlive the pool's idle
recycle; MySQL is the right tool for everything else.

### What you CAN do as a sandboxed plugin

- Subscribe to events; emit log lines via the `_log` envelope.
- Render dashboard widgets, register tabs, contribute filter values.
- Handle GUI actions (CSRF + auth tier validated by core before
  dispatch).
- Serve REST endpoints at `/api/v1/plugins/<your-id>/<action>`.
- Register CLI subcommands; the `eiou` binary dispatches to your
  plugin via IPC.
- Read your own files and the scratch dir at
  `/var/lib/eiou/plugin-scratch/<your-system-user>/`.
- Open MySQL connections to your own per-plugin database user (your
  manifest's `database.user: true` flag still works the same way it
  did pre-sandboxing).
- Ship your own PHP classes (repositories, services, value objects,
  whatever) — `require_once` them or register your own autoloader
  from `__dispatch.php`. Core doesn't load them.
- Call whitelisted core services via `core_call`.

### What you CANNOT do as a sandboxed plugin

- Read `/etc/eiou/config/.master.key` or any wallet secret. **EACCES
  at the kernel level.** This is the whole point.
- Decorate / wrap a core service. Plugin code runs in a different
  process from core; there's no shared address space to override.
  Use events to observe core actions instead.
- Call into another plugin directly. Plugins talk to core only;
  cross-plugin coordination happens via shared events or
  database tables that core mediates.
- Execute shell-out functions (`exec`, `shell_exec`, `passthru`,
  `proc_open`, `popen`, `system`, `pcntl_exec`, `eval`, `assert`).
- Open arbitrary file URLs (`allow_url_fopen=0`,
  `allow_url_include=0`).

### Constraints to design around

- **Handler timeout**: 500ms per IPC call. Long-running work needs to
  be queued in the plugin's own DB tables and processed
  asynchronously by the plugin's own processor (if you have one).
- **Event-mid-transaction**: if core fires an event while inside an
  uncommitted DB transaction, your handler reads from a separate DB
  connection and won't see the in-flight rows. (Core's own dispatch
  call sites commit before firing for this reason.)
- **Per-render IPC latency**: each render-hook fire adds ~1-5ms.
  Negligible for a dashboard widget, expensive if your plugin
  subscribes to a render hook that fires on every page render.

### Migration checklist for an existing in-process plugin

1. Take inventory of every `boot()` / `register()` action: events
   subscribed, hooks registered, registries called, services added.
2. For every service registration via `$container->setService(...)`
   — **stop**. This pattern doesn't work sandboxed. Replace with
   event-based observation or document the constraint.
3. Move every declarative surface (event names, hook names, tab
   metadata, asset paths, route paths, CLI names) into the manifest
   fields above.
4. Write `__dispatch.php` from the bundled template. Add a `case`
   in the switch for each `type` your manifest declared.
5. Declare every core service method your handler calls in
   `core_services` (e.g. `"Logger.info"`).
6. Set `"sandboxed": true`.
7. Disable, then re-enable the plugin (rotates the gateway token,
   triggers a fresh FPM pool reload).

### Security note for plugin authors

Even with sandboxing, **a plugin that's installed and enabled has
real reach inside the operator's wallet** — it can:

- Read every contact (via `ContactService` calls if its manifest
  declares them).
- Read every transaction (via `TransactionService` calls if
  declared).
- Sign messages on the wallet's behalf (if `MessageDeliveryService`
  is whitelisted in the manifest *and* the core method is
  `#[PluginCallable]`).

Sandboxing prevents *seed-phrase exfiltration*. It doesn't make a
plugin trustworthy. Operators reading your manifest's
`core_services` list see the surface you're asking for — don't ask
for more than you need.

---

## Events a Plugin Can Subscribe To

Events are dispatched through `Eiou\Events\EventDispatcher::getInstance()`.
Subscribe in `boot()`:

```php
EventDispatcher::getInstance()->subscribe(SyncEvents::SYNC_COMPLETED, function(array $data): void {
    // $data carries event-specific context; see each event class for the shape.
});
```

The dispatched event set covers sync, delivery, chain-drop, transaction,
contact, P2P, and plugin-lifecycle events. Every constant documented
below has a live emit point.

### `SyncEvents`

| Constant                         | When it fires                                           |
| -------------------------------- | ------------------------------------------------------- |
| `SYNC_STARTED`                   | A sync round is about to begin (companion to `SYNC_COMPLETED`); listeners may throw to abort |
| `SYNC_COMPLETED`                 | A sync round with a contact completed successfully      |
| `SYNC_FAILED`                    | A sync round failed                                     |
| `CHAIN_GAP_DETECTED`             | A missing chain link was detected during sync           |
| `CONTACT_SYNCED`                 | A contact's metadata was synchronized                   |
| `BALANCE_SYNCED`                 | A balance was reconciled                                |
| `CHAIN_CONFLICT_RESOLVED`        | A fork in the chain was resolved                        |
| `BIDIRECTIONAL_SYNC_STARTED`     | A bidirectional sync round started                      |
| `BIDIRECTIONAL_SYNC_COMPLETED`   | A bidirectional sync round completed                    |
| `ALL_CONTACTS_SYNCED`            | All contacts have been synced (end of broad sync)       |
| `ALL_TRANSACTIONS_SYNCED`        | All transactions have been synced                       |
| `ALL_BALANCES_SYNCED`            | All balances have been synced                           |

### `ChainDropEvents`

| Constant                                 | When it fires                                                  |
| ---------------------------------------- | -------------------------------------------------------------- |
| `CHAIN_DROP_PROPOSED`                    | A chain-drop was proposed                                       |
| `CHAIN_DROP_ACCEPTED`                    | A chain-drop was accepted                                       |
| `CHAIN_DROP_REJECTED`                    | A chain-drop was rejected                                       |
| `CHAIN_DROP_EXECUTED`                    | A chain-drop completed                                          |
| `TRANSACTION_RECOVERED_FROM_BACKUP`      | A transaction was recovered from backup as part of a chain-drop |

### `DeliveryEvents`

| Constant                   | When it fires                                           |
| -------------------------- | ------------------------------------------------------- |
| `RETRY_DELIVERY_COMPLETED` | A DLQ-backed retry finished (success or final failure)  |

The exact shape of each event's `$data` payload is documented in each event
class's docblock. Read the class, don't trust docs alone — the payload is the
contract.

### `TransactionEvents`

| Constant              | When it fires                                                                |
| --------------------- | ---------------------------------------------------------------------------- |
| `PRE_VALIDATE`        | Before a transaction is validated; listeners may throw to veto and abort     |
| `TRANSACTION_CREATED` | A pending outbound tx was inserted in `SendOperationService` (direct or P2P) |
| `TRANSACTION_SENT`    | After successful direct delivery (`handleAcceptedTransaction`)               |
| `TRANSACTION_RECEIVED`| An inbound direct transaction was persisted (`processStandardIncoming`)      |
| `TRANSACTION_FAILED`  | Delivery attempts exhausted and DLQ-cancelled (`processOutgoingDirect`)      |

### `ContactEvents`

| Constant            | When it fires                                                                        |
| ------------------- | ------------------------------------------------------------------------------------ |
| `CONTACT_ADDED`     | After `insertContact()` / `addPendingContact()` — includes outgoing, incoming, and wallet-restore paths |
| `CONTACT_ACCEPTED`  | After `ContactManagementService::acceptContact()` commits                            |
| `CONTACT_REJECTED`  | After an incoming contact request is auto-rejected for an unsupported currency       |
| `CONTACT_BLOCKED`   | After `ContactManagementService::blockContact()` commits                             |

### `P2pEvents`

| Constant        | When it fires                                                                                          |
| --------------- | ------------------------------------------------------------------------------------------------------ |
| `P2P_RECEIVED`  | An inbound P2P leg was persisted (both relay and end-recipient legs)                                   |
| `P2P_APPROVED`  | After the operator approves a P2P transaction (via CLI, REST, or GUI — all route through `P2pApprovalService::approve()`) |
| `P2P_REJECTED`  | After the operator rejects a P2P transaction (same shared service commit point)                       |
| `P2P_COMPLETED` | A P2P transaction reached its final destination (end-recipient leg)                                    |

### `PluginEvents`

Plugin-lifecycle events dispatched by `PluginLoader` and the plugin
management services. Use them to observe other plugins' lifecycle
transitions or to record audit trails.

| Constant              | When it fires                                                 | Payload                                                            |
| --------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------ |
| `PLUGIN_REGISTERED`   | After a plugin's `register()` completes                       | `{name, version}`                                                  |
| `PLUGIN_BOOTED`       | After a plugin's `boot()` completes                           | `{name, version}`                                                  |
| `PLUGIN_FAILED`       | A plugin threw during `register()` or `boot()`                | `{name, version, phase, error}`                                    |
| `PLUGIN_INSTALLED`    | After a successful `.zip` install via `pluginsUpload`         | `{name, version, source: "zip_upload"}`                            |
| `PLUGIN_UNINSTALLING` | Just before uninstall begins, while grants are still in place | `{name}`                                                           |
| `PLUGIN_UNINSTALLED`  | After every uninstall step has run                            | `{name, success, steps}` — per-step status map (`ok` / `skipped` / `error:<msg>`) |
| `PLUGIN_UPGRADED`     | After a successful upgrade (atomic swap + hook + reload)      | `{name, old_version, new_version, source: "zip_upload" \| "bundled"}` |

Subscriptions made in `register()` won't observe other plugins'
`PLUGIN_REGISTERED` events that ran ahead of you in iteration order —
subscribe in `boot()` (or via the manifest's `subscribes_to` for
sandboxed plugins, which always sees all of them) to catch the full
registration pass.

### Plugin-published events

Beyond subscribing to core events, a sandboxed plugin can **emit** its
own events for other plugins or in-process subscribers to react to. The
host namespaces every plugin-emitted event so subscribers can identify
the origin and one plugin cannot spoof another:

    plugin.<source-plugin-id>.<event-name>

Where `<source-plugin-id>` is the trusted plugin id resolved by the
plugin gateway from the bearer token (not a value the plugin passes —
attempts to do so are ignored), and `<event-name>` is the local event
name supplied by the publisher, constrained to `^[a-z][a-z0-9_-]{0,63}$`.

A plugin opts into the publish surface by allow-listing
`PluginEventPublisher.publish` in its manifest `core_services` list and
calling it through `core_call()`:

    $envelope = [
      'service' => 'PluginEventPublisher',
      'method'  => 'publish',
      'args'    => ['refund-issued', ['txid' => $txid, 'amount' => $amount]],
    ];
    core_call($envelope);

The host dispatches `plugin.<your-plugin-id>.refund-issued` with the
payload, augmented with `_source_plugin` for trace-ability. Subscribers
declare the full namespaced name in their manifest `subscribes_to` list
(the existing regex already admits the dotted form):

    "subscribes_to": ["plugin.payback-btc.refund-issued"]

Constraints applied by the host:

- Event name pattern: `^[a-z][a-z0-9_-]{0,63}$`.
- JSON-encoded payload size cap: 16 KiB (after `_source_plugin` is
  added).
- Per-plugin rate cap: 600 publishes per minute (via the
  `#[PluginCallable]` `ratePerMinute`).
- The publishing plugin's id is host-injected via `PluginCallerAware`
  on the gateway path; plugins cannot publish under another plugin's
  id by passing it as an argument.

Subscribers see plugin-emitted events through the same EventDispatcher
that fans out core events — in-process subscribers receive them
synchronously; sandboxed plugins receive them as `event`-typed envelopes
via `PluginIpcForwarder`, same as for core events. The dispatch path is
asymmetric (publish is plugin → wallet via gateway; receive is wallet →
plugin via IPC) because sandboxed plugins run in their own FPM pool and
cannot share an EventDispatcher instance with the wallet pool.

---

## Scheduled Tasks (cron)

Sandboxed plugins can declare manifest-driven scheduled tasks that the
host fires on a regular interval. Use cases: batched usage flushes,
key-TTL sweeps, daily summary rollups, periodic cache refreshes —
anything the plugin would otherwise need to ship a separate cron
container for.

### Manifest field

    "cron": [
      {"interval_minutes": 60,   "action": "flush-usage"},
      {"interval_minutes": 1440, "action": "daily-summary"}
    ]

`interval_minutes` is bounded `[1, 1440]` (one minute to one day).
`action` matches the same kebab-case shape as `public_routes`
(`^[a-z][a-z0-9-]{0,63}$`) and is the discriminator the plugin's
`__dispatch.php` switches on to route the work.

### Why `interval_minutes` instead of a full cron expression

The host scheduler ticks once per minute (driven by `startup.sh`'s
`plugin_cron_poller`), so sub-minute precision is impossible regardless
of expression syntax. Cron expressions also bring timezone and DST
traps that don't pay off for the use cases this is designed for. A
plugin that needs "daily at 03:00 UTC" can fold the hour-of-day check
into its handler — the dispatch envelope carries `scheduled_at` (Unix
timestamp), so the handler knows the exact moment the host invoked it.

### Dispatch envelope

When an entry's interval has elapsed since its last fire, the host
POSTs to the plugin's `__dispatch.php` with:

    {
      "type":    "cron",
      "name":    "flush-usage",
      "context": {
        "scheduled_at":     1715635200,
        "interval_minutes": 60
      }
    }

The plugin handles it in the same dispatch switch as other envelope
types:

    if ($type === 'cron') {
      if ($name === 'flush-usage') {
        // ... do the work, return ok ...
      }
    }

### State and locking

The host persists last-fire timestamps in
`/var/lib/eiou/plugin-cron-state.json` (mode `0640 root:www-data` —
same multi-writer permissions as `plugins.json`). Each
`(plugin-id, action)` pair has its own entry. State entries for actions
that are no longer declared (plugin uninstalled or entry removed from
manifest) are pruned automatically on each tick, so the file doesn't
grow without bound.

Each `(plugin-id, action)` also takes a non-blocking `flock` on a
lockfile under `/tmp` before dispatch. If a previous tick's invocation
is still running — a slow plugin handler, a stalled IPC connection —
the new tick skips with `reason=lock_held` and relies on the next
minute's tick to retry once the lock is free. This prevents pile-up
under a hanging handler.

### Failure handling

A transport failure (plugin pool down, FPM unreachable) records
`reason=dispatch_failed` and **does not advance the last-fire window** —
the next tick retries. A handler that throws records `reason=threw:<msg>`
and likewise does not advance — same retry semantics. A handler that
returns `{"ok": true}` advances the window normally.

### CLI

    eiou plugin cron-tick

Runs one tick manually. Useful for diagnostics, forced fires during
development, and `--json` output for scripting. The startup poller
invokes this same command — there is no separate code path for
operator-triggered vs automated ticks.

### Per-entry timeout (optional)

The host caps each dispatch envelope's wall-clock budget. Cron's
default cap is 5 seconds (much longer than the 500ms ceiling on
user-blocking surfaces like `event` / `filter` / `render` — those
park a real user's request, so a slow plugin must not extend the
wait). Plugins that need more than 5s for a single tick can declare:

    "cron": [
      {"interval_minutes": 60, "action": "flush-usage", "timeout_ms": 15000}
    ]

The host clamps `timeout_ms` to a hard ceiling below the plugin
pool's own FPM `request_terminate_timeout` (currently 25s vs 30s) so
the host always times out first and logs the failure under our
control. Bigger budgets indicate the tick is doing too much in one
invocation — prefer the queue-and-drain pattern below.

---

## Async work pattern (cron + queue)

Most non-trivial plugin work belongs in a cron tick, NOT in the
event/filter/render handlers that fire on user requests. The hard
constraint: those handlers have a **500ms host-side budget** and the
user is parked waiting for the response. A handler that takes 2s to
fetch a remote IPFS pin from a Kubo sidecar will:

  1. Trip the host's 500ms timeout. The host logs
     `plugin_ipc_transport_failed` and abandons the call.
  2. Keep running inside the plugin's FPM worker — the worker
     doesn't know its caller has hung up — until either (a) the
     plugin's own work finishes and the worker silently drops the
     response, or (b) the FPM `request_terminate_timeout` (30s)
     fires.
  3. Hold an FPM worker for the duration. With `pm.max_children = 4`
     in the default pool render, four concurrent slow handlers
     wedge the entire plugin.

The right shape is "enqueue in the handler, drain in cron". The
handler does only what fits in 500ms (insert a row, write a JSON
file), and a cron tick walks the queue at its own pace.

### Sketch — plugin-side queue table

Add a small queue table in the plugin's MySQL schema (via
`database.migrations`, see [Database Isolation](#database-isolation)):

```sql
CREATE TABLE my_plugin_pending_pins (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txid        VARCHAR(64)  NOT NULL,
  payload     JSON         NOT NULL,
  status      VARCHAR(16)  NOT NULL DEFAULT 'pending',
  attempts    INT UNSIGNED NOT NULL DEFAULT 0,
  next_run_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  created_at  DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY status_run (status, next_run_at)
);
```

### Sketch — `__dispatch.php` handler enqueues, doesn't do work

```php
if ($type === 'event' && $name === 'transaction.received') {
    // 500ms budget. Insert + return; the actual work is for cron.
    $pdo->prepare('
        INSERT INTO my_plugin_pending_pins (txid, payload)
        VALUES (:txid, :payload)
    ')->execute([
        ':txid'    => $context['data']['txid'] ?? '',
        ':payload' => json_encode($context['data'] ?? []),
    ]);
    return ['ok' => true];
}
```

### Sketch — cron tick drains the queue

```php
if ($type === 'cron' && $name === 'drain-pins') {
    // 5s budget by default; declare timeout_ms in manifest if more
    // is genuinely needed for a single tick.
    $batch = $pdo->query('
        SELECT id, txid, payload FROM my_plugin_pending_pins
        WHERE status = "pending" AND next_run_at <= NOW(6)
        ORDER BY next_run_at LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($batch as $row) {
        try {
            // Do the actual work — pin to IPFS, hit a remote API,
            // whatever. Each iteration is its own try/catch so one
            // poisonous row doesn't abort the batch.
            doTheWork($row);
            $pdo->prepare('
                UPDATE my_plugin_pending_pins
                SET status = "done"
                WHERE id = :id
            ')->execute([':id' => $row['id']]);
        } catch (Throwable $e) {
            // Backoff — exponential by attempts, capped. Mark
            // 'failed' once attempts hit a manifest-defined ceiling
            // so the row stops eating budget on every tick.
            $pdo->prepare('
                UPDATE my_plugin_pending_pins
                SET attempts = attempts + 1,
                    next_run_at = DATE_ADD(NOW(6), INTERVAL POW(2, attempts) MINUTE)
                WHERE id = :id
            ')->execute([':id' => $row['id']]);
        }
    }
    return ['ok' => true, 'drained' => count($batch)];
}
```

Manifest declares both surfaces:

```json
{
  "subscribes_to": ["transaction.received"],
  "cron": [{"interval_minutes": 1, "action": "drain-pins"}]
}
```

### Sizing the batch

Default LIMIT 5 leaves margin under the 5s cron budget for the
slowest realistic iteration (a single IPFS pin can take ~1s on a
warm sidecar; cold-cache walks can run to several). Tune per
workload by measuring how long one iteration takes in production and
choosing `batch_size * iteration_p99 < timeout_ms - 500ms_overhead`.

If the queue grows faster than the cron tick can drain it, raise
`interval_minutes` (smaller batches more often is usually better
than huge batches less often — it bounds the per-tick blast radius).
Don't be tempted to raise `timeout_ms` past ~10s; longer ticks just
push the operational pain to discovery time (a stuck handler delays
the next tick, the lockfile-skip pattern in [State and
locking](#state-and-locking) only protects within one
(plugin, action) pair).

### When to skip the queue and just do the work synchronously

If the work genuinely fits in 500ms (a cache read, a single
in-process computation, a small DB query) the enqueue-and-drain
pattern is overhead. The smell test: if the worst-case latency for a
single invocation, including a cold network round-trip, comfortably
fits in 500ms, do it in the event handler. If you're not sure, ship
it in cron — moving from "synchronous in handler" to "queued for
cron" is a refactor; moving back is not.

---

## Writing a Plugin

The reference plugin `hello-eiou` is ~80 lines and demonstrates the
full sandboxed-plugin contract end-to-end. Start by copying it:

```bash
cp -r /etc/eiou/plugins/hello-eiou /etc/eiou/plugins/my-plugin
```

A sandboxed plugin is two things on disk: a `plugin.json` manifest
that **declares** what surfaces the plugin contributes, and an
`__dispatch.php` that **handles** invocations of those surfaces when
core calls into the plugin's FPM pool. The entry class (your
`MyPlugin` class) exists for the optional lifecycle hooks
(`UninstallablePlugin`, `UpgradablePlugin`) but does **not** run in
the wallet pool — see [Lifecycle](#lifecycle) for why.

### 1. Edit the manifest

```json
{
  "name": "my-plugin",
  "version": "1.0.0",
  "description": "What it does, in one sentence.",
  "entryClass": "Eiou\\Plugins\\MyPlugin\\MyPlugin",
  "autoload": {
    "psr-4": {
      "Eiou\\Plugins\\MyPlugin\\": "src/"
    }
  },
  "sandboxed": true,
  "subscribes_to": ["sync.completed"],
  "core_services": ["Logger.info"]
}
```

The two surface fields say:

- `subscribes_to` — when the host fires `sync.completed`, route the
  payload into our `__dispatch.php` as `{type: "event", name:
  "sync.completed", context: <event data>}`.
- `core_services` — the plugin may call `Logger.info` via the
  service gateway. Adding a method here without the host actually
  exposing it via `#[PluginCallable]` is a no-op; the gateway 403s
  unknown methods. See [Plugin-callable surface —
  policy](#plugin-callable-surface--policy) for the full set.

Every other surface (`tabs`, `gui_actions`, `gui_assets`,
`api_routes`, `cli_commands`, `public_routes`, `filter_hooks`,
`render_hooks`, `database`, `min_upgradable_from`) is the same shape
— declare in the manifest, handle in `__dispatch.php`. Add them as
your plugin needs them.

### 2. Rename the entry class

Move `src/HelloEiouPlugin.php` to `src/MyPlugin.php`, update the
namespace and class name. The class is minimal — it exists for
identity (`getName`/`getVersion`) and optional cleanup /
migration hooks:

```php
<?php
namespace Eiou\Plugins\MyPlugin;

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;

class MyPlugin implements PluginInterface
{
    public function getName(): string    { return 'my-plugin'; }
    public function getVersion(): string { return '1.0.0'; }

    public function register(ServiceContainer $container): void { /* unused in sandboxed model */ }
    public function boot(ServiceContainer $container): void     { /* unused in sandboxed model */ }
}
```

`register()` and `boot()` are on the interface for compatibility
but don't run in the wallet pool for sandboxed plugins — the
wallet pool only reads your manifest. They *do* run inside the
plugin's own FPM pool on each `__dispatch.php` request (the
dispatcher calls them), so authors who do need shared init can
put it there — but most plugins don't need either.

### 3. Wire the dispatcher

Copy the bundled template to `__dispatch.php` and replace the
`501` stub for each `type` you declared in the manifest:

```bash
cp /app/eiou/src/templates/plugin-dispatch-template.php \
   /etc/eiou/plugins/my-plugin/__dispatch.php
```

```php
// inside the dispatch switch — replace the stub
case 'event':
    if ($name === 'sync.completed') {
        $contactPubkey = $context['contact_pubkey'] ?? 'unknown';
        core_call('Logger', 'info', [
            "[my-plugin] sync completed for {$contactPubkey}",
            ['plugin' => 'my-plugin'],
        ], $log);
        respond(200, ['ok' => true], $log);
    }
    respond(501, [
        'ok' => false,
        'error' => ['code' => 'handler_not_found', 'message' => "no handler for event {$name}"],
    ], $log);
```

The template already imports `core_call($service, $method, $args,
$log)` which authenticates to the wallet's service gateway with the
plugin's per-pool bearer token (mounted at `.gateway-token`). The
gateway validates the bearer, the manifest allow-list, and the
`#[PluginCallable]` attribute before dispatching. See [The
`__dispatch.php` contract](#the-__dispatchphp-contract) for the
envelope shape and [Calling core services from your handler —
`core_call()`](#calling-core-services-from-your-handler--core_call)
for the call's full validation chain.

### 4. Enable and apply

```bash
eiou plugin enable my-plugin
```

The supervisor brings up the plugin's FPM pool immediately. The
plugin's endpoints respond from this moment on — there's no boot
wait. A wallet restart is still required for the wallet pool's IPC
forwarder to bind the new `subscribes_to` / `filter_hooks` /
`render_hooks` entries, so until you restart, the events / hooks
won't fire your handler. `gui_actions`, `api_routes`,
`cli_commands`, `public_routes`, and `tabs` all bind on the same
restart pass.

```bash
eiou restart
```

The status dot next to `my-plugin` in the GUI plugin list should
turn green — your plugin is live.

### 5. Ship a CHANGELOG

Drop a `CHANGELOG.md` next to `plugin.json`. The GUI will
automatically expose a **View bundled CHANGELOG.md** button in the
detail modal. See `hello-eiou/CHANGELOG.md` for a minimal example.

### 6. Plan for upgrades

When you ship a `1.1.0`, give operators a clean upgrade path:

- Bump `version` in the manifest.
- If you changed `owned_tables` (added or removed a table) or your
  on-table schema, implement `UpgradablePlugin::onUpgrade()` on the
  entry class to migrate data — runs against the unchanged plugin
  user with the old grants still active, see [Upgrading
  Plugins](#upgrading-plugins).
- If your `1.1.0` can't safely migrate from earlier than some
  version, declare a `"min_upgradable_from": "0.5.0"` in the
  manifest so operators on older versions get a clear refusal
  instead of a corrupted migration.

---

## Extending the CLI and REST API

Plugins can add top-level `eiou <plugin> ...` CLI subcommands and
admin-scoped REST endpoints under `/api/v1/plugins/<plugin>/<action>`.
Both surfaces are **manifest-declared** and **dispatcher-handled**:

- The manifest's `cli_commands` and `api_routes` arrays tell core's
  IPC forwarder to bind handlers in the wallet pool's registries.
- When an operator invokes `eiou <plugin> ...` or hits the REST
  endpoint, the bound handler HTTP-POSTs an envelope into the plugin's
  `__dispatch.php` with `type: "cli"` or `type: "rest"` respectively.
- Your dispatcher's `case 'cli':` and `case 'rest':` arms run the
  actual work and respond.

Both surfaces are admin-only: the CLI runs as the local operator, and
plugin-owned REST endpoints inherit the admin scope gate from
`/api/v1/plugins`. For non-admin HTTP from customers, see [Public
routes](#public-routes--non-admin-http-under-pplugin-idaction).

### CLI subcommand

**Manifest:**

```json
{
  "cli_commands": [{"name": "my-plugin"}]
}
```

Naming rules — kebab-case, 1–32 chars, starts with a letter, doesn't
collide with a reserved core command (`send`, `add`, `plugin`,
`restart`, etc. — the registry has a hard-coded list and the manifest
validator drops bad entries before they reach core).

**Dispatcher handler:**

```php
// inside __dispatch.php's switch ($type)
case 'cli':
    // $context carries the parsed argv and a writable output struct
    $argv = $context['argv'] ?? [];
    $sub  = $argv[2] ?? 'help';
    if ($sub === 'status') {
        respond(200, ['result' => ['status' => 'ok']], $log);
    }
    respond(404, [
        'ok' => false,
        'error' => ['code' => 'unknown_subcommand', 'message' => "Unknown subcommand: {$sub}"],
    ], $log);
```

Operators invoke as `eiou my-plugin status`. Handler failures (a
thrown exception inside the dispatcher) come back as a 500 in the
response envelope; the CLI's output manager surfaces them as an
error without crashing the CLI process.

### REST endpoint

**Manifest:**

```json
{
  "api_routes": [{"method": "GET", "action": "status"}]
}
```

Rules: `method` is one of `GET / POST / PUT / PATCH / DELETE`;
`action` is kebab-case, 1–64 chars; the action names `enable` and
`disable` are reserved for the core plugin-management endpoints; the
same `(plugin, method, action)` tuple is registered once.

**Dispatcher handler:**

```php
// inside __dispatch.php's switch ($type)
case 'rest':
    // $context carries: method, params (query/path), body (raw string)
    if ($name === 'status') {
        respond(200, ['result' => ['status' => 'ok', 'ts' => time()]], $log);
    }
    respond(404, [
        'ok' => false,
        'error' => ['code' => 'unknown_route', 'message' => "no handler for {$name}"],
    ], $log);
```

Callers invoke as `GET /api/v1/plugins/my-plugin/status` (admin
auth required — the wallet's `ApiController::handlePlugins` checks
the admin scope before routing into your handler). The handler's
`result` is wrapped in the standard `successResponse` shape by the
core controller. Path shape is single-level only — nested paths
aren't supported in v1; encode sub-resources as query params
(`?id=123`) or as a compound action name.

### Reference

See `hello-eiou` — `eiou hello-eiou` and `GET /api/v1/plugins/hello-eiou/fortune`
both return a random fortune. Both declarations live in `plugin.json`
(`cli_commands` + `api_routes`); both handlers live in
`__dispatch.php`'s switch.

---

## Extending the GUI

Plugins extend the wallet GUI through five complementary surfaces —
render hooks, filter hooks, asset enqueues, top-level tabs, and POST
action handlers. All five are **manifest-declared, dispatcher-handled**:

| Surface         | Manifest field   | Dispatcher type | What the handler returns         |
| --------------- | ---------------- | --------------- | -------------------------------- |
| Render slot     | `render_hooks`   | `"render"`      | An HTML string                   |
| Filter slot     | `filter_hooks`   | `"filter"`      | The transformed filter value     |
| Top-level tab   | `tabs`           | `"render"` (via the tab's own slot, see below) | HTML for the tab body |
| POST action     | `gui_actions`    | `"action"`      | A response envelope (JSON or redirect) |
| CSS / JS asset  | `gui_assets`     | — purely declarative; no handler runs |  Asset file at the declared path |

The IPC forwarder reads each manifest list at wallet boot and binds
matching in-process listeners; when a slot fires / filter resolves /
action POSTs / tab renders, the forwarder HTTP-POSTs an envelope into
the plugin's `__dispatch.php` and surfaces the response back to the
host caller. This section is the GUI-surface reference plugin authors
need day-to-day; the underlying IPC contract is documented in [The
`__dispatch.php` contract](#the-__dispatchphp-contract).

### Render slots

Render hooks let plugins inject HTML at named points in the templates. Each
listener returns a string; the host concatenates them in priority order
(lower runs first; default 10) and emits the result. Listener exceptions
are logged and skipped.

| Hook | Where | Typical use |
|---|---|---|
| `gui.head.styles` | `<head>` | Register `<style>` / `<link>` tags. The asset registry already drains here — most plugins enqueue rather than subscribe directly. |
| `gui.head.scripts` | `<head>` | Head-mode `<script>` tags. Asset registry drains here for `enqueueScript(..., ['head' => true])`. |
| `gui.footer.scripts` | end of `<body>` | Late-init `<script>` tags. Default destination of `enqueueScript`. |
| `gui.dashboard.before` | dashboard tab top | Hero widget above the wallet-information block. |
| `gui.dashboard.after` | dashboard tab bottom | Sidebar widget after the payback methods. |
| `gui.contacts.after` | contacts tab bottom | Bulk-action panel under the contact list. |
| `gui.activity.after` | activity tab bottom | Custom analytics under the transaction history. |
| `gui.settings.section` | settings tab bottom | Plugin-owned settings section. |

**Manifest:**

```json
{
  "render_hooks": ["gui.dashboard.after"]
}
```

**Dispatcher handler:**

```php
// inside __dispatch.php's switch ($type)
case 'render':
    if ($name === 'gui.dashboard.after') {
        // $context carries whatever the host passed at the fire site —
        // every wallet template fires with `{'user': <user>}`.
        $display = htmlspecialchars($context['user']['display_name'] ?? '');
        respond(200, [
            'result' => "<section class=\"plugin-myplugin-widget\"><h3>Hi {$display}</h3></section>",
        ], $log);
    }
    respond(200, ['result' => ''], $log); // no contribution for unknown hooks
```

The host concatenates render-hook contributions in priority order
(plugin handlers get the default priority; core's own listeners
register their own). A handler that throws is logged and skipped —
other plugins' contributions still appear.

### Filter slots

Filter hooks let plugins transform a host value before render. Each
listener receives the value from the previous stage and must return the
next stage. Listeners that throw fall back to the previous value (so
other listeners aren't punished for a misbehaving one).

| Hook | Value shape | Use case |
|---|---|---|
| `gui.tabs` | array of tab entries (`id`, `label`, `icon`, `order`, …) | Add, hide, or reorder top-level tabs. Filters fire after `TabRegistry::all()` so registered plugin tabs are already present. |
| `gui.dashboard.widgets` | array of `{id, html, order}` | Contribute ordered widget chunks; sorted by `order` (default 100) before render. |
| `gui.contact_modal.tabs` | array of `{id, label, icon}` | Add an inner tab to the contact-detail modal. Pair with `gui.contact_modal.body` on a shared `id`. |
| `gui.contact_modal.body` | array of `{id, html}` | Body HTML for the matching modal tab. Host renders it inside `<div id="<id>-tab" class="modal-tab-content">`. |
| `gui.contact.actions` | array of `{label, icon, action}` | Buttons on the contact-modal Settings tab. The host wraps each entry in a CSRF-protected POST form whose hidden `contact_address` input is auto-populated when the modal opens. |

**Manifest:**

```json
{
  "filter_hooks": ["gui.contact.actions"]
}
```

**Dispatcher handler:**

```php
// inside __dispatch.php's switch ($type)
case 'filter':
    if ($name === 'gui.contact.actions') {
        // $context['value'] is the incoming filter value
        $actions = is_array($context['value'] ?? null) ? $context['value'] : [];
        $actions[] = [
            'label'  => 'Bookmark',
            'icon'   => 'fas fa-star',
            'action' => 'myPluginBookmark',   // must match a declared gui_action
        ];
        respond(200, ['result' => $actions], $log);
    }
    // Pass-through if we don't transform this hook
    respond(200, ['result' => $context['value'] ?? null], $log);
```

The handler's `result` becomes the value the next listener in the
chain sees (or the final value the host renders). Throwing falls
back to the previous value, so a buggy plugin doesn't punish other
contributors to the same filter.

### Asset enqueue

CSS and JS files are declared purely in the manifest — there's no
dispatcher handler for assets. Paths resolve under the plugin root
(`/etc/eiou/plugins/<id>/`); path traversal (`..`, leading slash,
backslashes) is rejected at manifest validation and re-validated at
render against `realpath()` so a symlinked target can't escape the
plugin tree.

**Manifest:**

```json
{
  "gui_assets": [
    {"type": "css", "path": "assets/styles.css"},
    {"type": "js",  "path": "assets/main.js"},
    {"type": "js",  "path": "assets/early.js", "head": true},
    {"type": "css", "path": "assets/big.css", "priority": 5}
  ]
}
```

Files smaller than `URL_MODE_THRESHOLD` (4 KiB) inline as
`<style nonce>` / `<script nonce>` blocks; larger files get a
`<link href="…?v=<hash>">` / `<script src="…?v=<hash>">` tag served
by the `/gui/plugin-assets/<id>/<path>` route (handled by core's
`PluginAssetServer`). Force a mode with `"inline": true` or
`"inline": false`.

CSP nonce stamping is automatic. Plugin authors don't think about it.

CSS isolation is convention-only — namespace selectors with
`.plugin-<id>` (`.plugin-myplugin .widget-title { … }`) or use Web
Components / Shadow DOM. A misbehaving plugin's `body { … }` will affect
the host page; treat plugin code with the same scrutiny you'd give any
unsigned CSS bundle.

### Section helper (`renderSection()`)

Every wallet section — the Plugins table, Failed Messages queue,
Payback Methods card list, API Keys table, Settings, etc. — wraps its
content in the same outer shape: a `form-container fade-in-up` div, a
`section-header` with icon + h2 + optional inline buttons, an optional
`<details>` "About this …" disclosure, and the body. `renderSection()`
in `WalletTemplateHelpers.php` consolidates the wrapper so core's own
sections stay visually consistent when CSS/UX refinements happen.

**Sandboxed plugins don't call `renderSection()` directly** — it runs
in the wallet pool when wallet.html renders, not in the plugin's
pool. Plugin authors who want their HTML to match the host's section
shape have two options:

- **Mirror the markup yourself.** Return an HTML string from your
  render hook handler that uses the same `form-container fade-in-up`
  + `section-header` outer shape. The CSS will style it identically.
- **Use a tab.** Declare a `tabs` entry in the manifest (see below).
  Core's wallet.html iterates the tab registry and runs each tab's
  body through `renderSection()` automatically, so tab bodies get
  the native chrome for free.

The helper also auto-fires two render hooks around every section it
renders, and these *are* available to plugins via `render_hooks`:

- `gui.section.before.<id>` — fired immediately before the section
  opens. Plugins can inject a banner above someone else's section
  without forking the template.
- `gui.section.after.<id>` — fired after the section closes. Useful
  for "additional details" panels that should attach to a specific
  core section.

The hook context is the full spec array, so listeners can adapt to
which section they're inside (e.g. only inject for `id === 'dlq'`).

```json
{
  "render_hooks": ["gui.section.after.dlq"]
}
```

```php
// inside __dispatch.php's switch ($type), case 'render':
if ($name === 'gui.section.after.dlq') {
    respond(200, ['result' => '<div class="my-plugin-dlq-followup">…</div>'], $log);
}
```

Every standard wallet section is rendered through `renderSection()`:
`plugins-section`, `payback-methods-section`, `dlq`, `api-keys-section`,
`transactions`, `payment-requests-section`, `debug-section`,
`settings`, `contacts`, `pending-contacts`. The
`gui.section.before.<id>` / `gui.section.after.<id>` hooks fire for
every one of them.

### Table helper (`renderTable()`)

Every paginated table in the wallet wraps its `<table>` in
`<div class="contacts-table-wrapper">` and adds a `contacts-table
{variant}-table` class. `renderTable()` hides that boilerplate so
core tables stay consistent.

As with `renderSection`, sandboxed plugins don't call `renderTable()`
directly — return HTML from your render hook handler that uses the
same outer shape (`<div class="contacts-table-wrapper">` containing a
`<table class="contacts-table <variant>-table">`) and the CSS will
style it identically.

### Top-level tabs

**Manifest:**

```json
{
  "tabs": [
    {"id": "myplugin", "label": "My Plugin", "icon": "fas fa-puzzle-piece", "order": 50}
  ]
}
```

Field rules: `id` is kebab-case and must not collide with a core tab
(`dashboard`, `payment`, `contacts`, `activity`, `settings`); `label`
is plain text; `icon` is a Font Awesome class; `order` is a number
(core tabs use 10/20/30/40/50). Optional `badge` (int) and
`badgeTitle` (string) for a numeric pill on the tab button.

The host renders the tab nav and an empty panel container; the panel
body is fetched lazily via a render hook fired on the tab's `id`. To
fill the body, register a render hook for the tab's slot:

```json
{
  "tabs": [{"id": "myplugin", "label": "My Plugin", "icon": "fas fa-puzzle-piece", "order": 50}],
  "render_hooks": ["gui.tab.myplugin"]
}
```

```php
// inside __dispatch.php's switch ($type), case 'render':
if ($name === 'gui.tab.myplugin') {
    respond(200, ['result' => '<div class="plugin-myplugin">…</div>'], $log);
}
```

`wallet.html` iterates the tab registry once and emits the desktop
nav, the mobile nav, and the empty panel sections from a single
source of truth — your tab automatically appears in all three places
without any plugin-side wiring.

### POST action handlers

GUI POST actions (form submits + AJAX) are declared in the manifest
and handled in the dispatcher with `type: "action"`. The wallet's
`GuiActionRegistry` registers an IPC-forwarding handler for each
declared `gui_actions` entry; when a request arrives with the
matching `$_POST['action']`, the forwarder bridges it into the
plugin's dispatcher.

**Manifest:**

```json
{
  "gui_actions": [
    {"name": "myPluginBookmark", "tier": "csrf"}
  ]
}
```

Field rules: `name` is camelCase, 1–64 chars; `tier` is one of:

| Tier         | Gate the registry enforces before reaching your handler |
| ------------ | ------------------------------------------------------- |
| `public`     | None — anonymous callers OK. Use sparingly. |
| `auth`       | Authenticated session required; CSRF check left to the handler. |
| `csrf`       | Auth + valid CSRF token (non-rotating). On failure the registry emits `{"success":false,"error":"csrf_error","message":"Invalid CSRF token"}` with HTTP 403 — your handler never runs. **Default for new plugin AJAX handlers.** |
| `sensitive`  | Auth + CSRF + recent sensitive-access grant (same gate that protects "Reveal API key", "Delete account", etc.). |

**Dispatcher handler:**

```php
// inside __dispatch.php's switch ($type)
case 'action':
    if ($name === 'myPluginBookmark') {
        // $context carries the POST body as 'request' and the
        // contact_address (if any) automatically populated by the GUI's
        // contact-action wiring.
        $address = $context['request']['contact_address'] ?? '';
        // … do the work …
        respond(200, [
            'result' => ['success' => true, 'message' => 'Bookmarked!'],
        ], $log);
    }
    respond(404, [
        'ok' => false,
        'error' => ['code' => 'unknown_action', 'message' => "no handler for {$name}"],
    ], $log);
```

The handler's `result` becomes the response body the host emits to
the browser. For redirect-style responses (legacy form submits), set
the `redirect` field instead of `result`; the host emits a 303 to
the given URL with a flash message attached.

**Last-write-wins on collisions** — a plugin declaring an action
name that core or another plugin already registered overrides the
earlier handler. The order is: core's own actions register first,
then plugins in the order `bootAll()` iterates them. Overriding
core actions (e.g. `addContact`, `sendEIOU`, `apiKeysCreate`) is
permitted but **fragile** — the JS client expects specific response
shapes and will misbehave if your override changes them. Do it
deliberately or not at all.

Forms rendered by the `gui.contact.actions` filter post to
`/wallet?action=<name>`; you don't need a separate route.

### Wiring `gui.contact.actions` to a registered handler

Each `gui.contact.actions` entry renders as:

```html
<form method="POST" class="plugin-contact-action">
  <input type="hidden" name="csrf_token" value="…">
  <input type="hidden" name="action" value="myPluginBookmark">
  <input type="hidden" name="contact_address" class="plugin-contact-action-address">
  <button type="submit" class="btn btn-secondary">Bookmark</button>
</form>
```

The host's `openContactModal()` JS populates every
`.plugin-contact-action-address` input with the open contact's address,
so the handler receives `$_POST['contact_address']` without any
plugin-side wiring.

### Discovering hooks at runtime (`PLUGIN_HOOKS_TRACE`)

Set `PLUGIN_HOOKS_TRACE=1` in the node environment to log every hook
fire (kind, name, listener count, errors) at INFO level. Useful for
plugin authors discovering which hooks the host actually calls without
grepping templates. Off by default — costs zero in production.

```bash
docker-compose exec alice sh -c 'PLUGIN_HOOKS_TRACE=1 php-fpm -D'
# or set in docker-compose / .env and restart
```

The trace is also available programmatically as `Hooks::getTrace()` for
test assertions.

### Versioning + discoverability

Hook names + payloads form an API. Breaking changes follow the same
deprecation policy as any host-side API. New hooks are added as needed
— file an issue if your plugin needs an injection point that doesn't
yet exist.

The `hello-eiou` example plugin (see `files/plugins/hello-eiou/`)
exercises every surface above: a declared CSS asset, a dashboard
render hook, a `Fortunes` top-level tab, the `helloEiouFortune` POST
action at the `csrf` tier, the `gui.dashboard.widgets` filter, and
the `gui.contact.actions` filter. Manifest declarations live in
`plugin.json`; the corresponding handlers live in
`__dispatch.php`'s switch. It's the smallest end-to-end reference
for a sandboxed plugin's GUI surface.

---

## Registering Payback-Method Rail Types

Core ships two payback-method rail types — `bank_wire` (with SEPA /
Faster Payments / ACH / FedNow / SWIFT) and `custom` (free-text
instructions). Every other rail — Bitcoin, PayPal, Bizum, Lightning,
EVM, Pix, UPI, etc. — is a plugin opportunity.

Sandboxed plugins declare rail types in the manifest's
`payback_method_types` list and handle the dynamic contract methods
(`validate`, `mask`, `defaultPrecision`) in `__dispatch.php` under a
new `case 'payback_method':` arm. The wallet pool's
`PaybackMethodTypeRegistry` is populated at boot with an
`IpcPaybackMethodTypeProxy` per declared type — proxies hold the
static catalog row in memory and IPC into the plugin's dispatcher for
each dynamic method call.

**Manifest:**

```json
{
  "name": "payback-btc",
  "version": "0.1.0",
  "entryClass": "Eiou\\Plugins\\PaybackBtc\\PaybackBtcPlugin",
  "autoload": { "psr-4": { "Eiou\\Plugins\\PaybackBtc\\": "src/" } },
  "sandboxed": true,

  "payback_method_types": [
    {
      "id": "btc",
      "catalog": {
        "id": "btc",
        "label": "Bitcoin",
        "group": "crypto",
        "icon": "fab fa-bitcoin",
        "description": "Settle in BTC. Accepts a mainnet address.",
        "currencies": ["BTC"],
        "fields": [
          {
            "name": "address",
            "label": "Bitcoin address",
            "type": "text",
            "required": true,
            "placeholder": "bc1q…"
          }
        ]
      }
    }
  ]
}
```

Multiple types per plugin are supported — declare each one as a
separate `payback_method_types` entry. `id` must match
`^[a-z][a-z0-9_]{0,31}$` and must not be one of the reserved core ids
(`bank_wire`, `custom`); the manifest validator drops bad entries
before they reach the registry. `catalog` is the static GUI row
documented under [What the contract plugs
into](#what-the-contract-plugs-into).

**Dispatcher handler:**

```php
// inside __dispatch.php's switch ($type)
case 'payback_method':
    $typeId   = $context['type_id'] ?? '';
    $currency = $context['currency'] ?? '';
    $fields   = $context['fields'] ?? [];

    if ($typeId !== 'btc') {
        respond(404, [
            'ok' => false,
            'error' => ['code' => 'unknown_type', 'message' => "no handler for type '{$typeId}'"],
        ], $log);
    }

    if ($name === 'validate') {
        // Return a list of {field, code, message} records. [] = success.
        if ($currency !== 'BTC') {
            respond(200, ['result' => [[
                'field' => 'currency', 'code' => 'invalid_currency_for_type',
                'message' => 'Bitcoin settles in BTC',
            ]]], $log);
        }
        if (empty($fields['address'])) {
            respond(200, ['result' => [[
                'field' => 'address', 'code' => 'required',
                'message' => 'address is required',
            ]]], $log);
        }
        respond(200, ['result' => []], $log);
    }

    if ($name === 'mask') {
        $a = (string) ($fields['address'] ?? '');
        $masked = $a === '' ? '•••' : substr($a, 0, 6) . '…' . substr($a, -4);
        respond(200, ['result' => $masked], $log);
    }

    if ($name === 'defaultPrecision') {
        // [min_unit, exponent] — satoshi precision when currency is BTC,
        // null otherwise so SettlementPrecisionService falls back to the
        // generic crypto / fiat default.
        respond(200, [
            'result' => $currency === 'BTC' ? [1, -8] : null,
        ], $log);
    }

    respond(404, [
        'ok' => false,
        'error' => ['code' => 'unknown_method', 'message' => "no handler for {$name}"],
    ], $log);
```

The proxy's IPC-failure behaviour is operator-friendly: a transport
failure on `validate` surfaces as a top-level `plugin_ipc_failed`
error record (the operator sees "could not check" rather than the
form silently passing); `mask` falls back to `'•••'` (list-view
shouldn't break a row over a transient plugin blip); `defaultPrecision`
falls back to null (`SettlementPrecisionService`'s generic default
applies). Authors don't need to worry about transport — the proxy
handles every failure mode with a sensible degradation.

### In-process equivalent (core types only)

Core's own rail types (`bank_wire`, `custom`) register the in-process
way because they live in the wallet pool by design. The interface they
implement is `Eiou\Contracts\PaybackMethodTypeContract`:

```php
class BtcType implements PaybackMethodTypeContract
{
    public function getId(): string { return 'btc'; }
    public function getCatalogEntry(): array { /* the catalog block above */ }
    public function validate(string $currency, array $fields): array { /* … */ }
    public function mask(array $fields): string { /* … */ }
    public function defaultPrecision(string $currency): ?array { /* … */ }
}
```

The contract is documented for anyone porting an existing in-process
implementation to the sandboxed model — the method bodies translate
1:1 into the dispatcher case shown above. Sandboxed plugins don't
implement this contract directly; the proxy does it on their behalf.

### What the contract plugs into

The registry is consulted from four places in core:

| Caller                                          | Uses                       |
| ----------------------------------------------- | -------------------------- |
| `PaybackMethodTypeValidator::getCatalog()`      | `getCatalogEntry()` — merges the entry into the GUI type-picker catalog. Any new `group` id declared by a plugin is auto-injected into the groups list (between `bank` and `other`). |
| `PaybackMethodTypeValidator::validate()`        | `validate()` — delegated for unknown type ids. Return `[]` on success, or a list of `['field' => string\|null, 'code' => string, 'message' => string]` error records. |
| `PaybackMethodService::maskForType()`           | `mask()` — short redacted string for the list-row cell. Return `'•••'` on missing fields rather than throwing. |
| `SettlementPrecisionService::defaultFor()`      | `defaultPrecision()` — return `[min_unit, exponent]` (e.g. `[1, -8]` for satoshi) or `null` to fall back to the generic fiat/crypto defaults. |

### Registration rules

- `id` must match `^[a-z][a-z0-9_]{0,31}$`
- `bank_wire` and `custom` are reserved — a plugin can't shadow them.
  The manifest validator drops entries with reserved ids before they
  reach the registry; the registry's own collision check is the
  defence-in-depth net.
- Each id can only be registered once across all enabled plugins;
  the second-arriving plugin's entry is skipped with a logged warning
  and the first plugin keeps the slot.
- For sandboxed plugins, registration happens at wallet boot through
  the IPC forwarder — no in-process call from the plugin's PHP. A
  wallet restart is required after enabling a plugin that declares
  `payback_method_types` (same as any other manifest-declared
  surface), so the forwarder picks the new entries up.

### Field schema — what the GUI renders

`getCatalogEntry()['fields']` is an array of field descriptors the
GUI's two-step "Add method" modal iterates over. Each entry has:

| Key         | Required | Notes                                                                            |
| ----------- | -------- | -------------------------------------------------------------------------------- |
| `name`      | yes      | POSTed field key. Must be unique within the type's fields.                       |
| `label`     | yes      | Shown above the input.                                                           |
| `type`      | yes      | `text` \| `email` \| `tel` \| `number` \| `select` \| `textarea`                 |
| `required`  | no       | Adds the red `*` and sets HTML5 `required`. Default false.                       |
| `placeholder` | no     | Shown inside empty inputs.                                                       |
| `help`      | no       | Small muted text under the input.                                                |
| `options`   | no       | `select`-only. Array of `{value, label}` entries.                                |
| `showWhen`  | no       | Conditional visibility: `{field: 'otherFieldName', in: ['value1', 'value2']}`. Use it to hide per-sub-rail fields (e.g. a `memo` that only applies for one of several options in a select above). |

The validator is the source of truth for validity — `required` in the
schema is a UX hint, not a check. Your `validate()` implementation is
what the server enforces.

### Long-form info (catalog `info` key)

In addition to `description` (one-liner, shown on the tile), an entry
may include `info` — an HTML string rendered as a collapsible **About
<rail name>** banner at the top of step 2 of the Add Payback Method
modal. Use it for the friction points that trip users up: which
address format to paste, what the per-rail masking looks like, whether
a given currency is supported, external constraints the validator
can't enforce (like "your PayPal account must have Send-to-email
enabled"). The GUI sanitises only structural aspects — the string is
inserted as-is into the DOM, so stick to the same restricted HTML
subset the rest of the app uses (`<strong>`, `<em>`, `<code>`, `<br>`,
`<ul>`, `<li>` are common and safe). Core's `bank_wire` and `custom`
entries both populate `info`; the three reference plugins
(`payback-btc`, `payback-paypal`, `payback-bizum`) demonstrate how a
plugin fills this out.

### Currency binding

- `currencies: ['BTC']` — method can only be saved with that currency
- `currencies: ['EUR', 'GBP', 'USD']` — restricted to a set
- `currencies: null` — accept any ISO-4217 code (useful for rails like
  PayPal that auto-convert on receipt). The GUI falls back to the
  shared ISO-4217 dropdown in the catalog when `currencies` is null

The validator enforces the binding, not the GUI. If a plugin declares
`currencies: ['EUR']` but the incoming `$currency` is `'USD'`, `validate()`
should return a `invalid_currency_for_type` error record.

### Testing a payback-method type plugin

Instantiate the type directly and exercise its surface:

```php
public function testValidateRejectsWrongCurrency(): void
{
    $t = new BtcType();
    $errs = $t->validate('USD', ['address' => 'bc1q...xyz']);
    $this->assertSame('currency', $errs[0]['field']);
    $this->assertSame('invalid_currency_for_type', $errs[0]['code']);
}
```

For end-to-end coverage, drive the validator directly with a real
`PaybackMethodTypeRegistry`:

```php
$reg = new PaybackMethodTypeRegistry();
$reg->register(new BtcType());
$v = new PaybackMethodTypeValidator($reg);
$this->assertSame([], $v->validate('btc', 'BTC', ['address' => 'bc1q...xyz']));
```

The core test suite's `PaybackMethodTypeRegistryTest` is a good
reference for id-shape / core-shadow / duplicate-registration /
catalog-merge / precision-override patterns.

---

## Testing a Plugin

Plugin code is ordinary PHP and can be tested with the same toolchain as core:

```bash
cd files
./vendor/bin/phpunit tests/Unit/Plugins/MyPluginTest.php
```

The test helpers in `tests/Unit/Services/PluginLoaderTest.php` (see
`writePluginWithExtras` and `validPluginSource`) show how to build temporary
plugin directories for test fixtures without touching the shared plugin root.

When testing event subscriptions, dispatch events directly on the
`EventDispatcher` singleton and assert on the plugin's observable side
effects (log calls, counters, service-container state).

---

## Safety Model and Limitations

### Failure isolation

A plugin that throws during `discover`, `register`, or `boot` is caught and
marked `failed` with the exception message attached. The failure is logged at
`error` level with `plugin_loader` context, and the GUI surfaces the error
inline in the plugin's detail modal. Core bootstrap continues.

### Idempotency

`registerAll()` and `bootAll()` skip plugins that have already completed that
phase — the lifecycle is once-per-loader regardless of how many times the
entry points are called. This is a defensive measure against some
edge cases in the PHP-FPM request lifecycle where the same worker PID would
otherwise run the lifecycle twice and double-subscribe event listeners.

### Sandboxing is mandatory

Every plugin must declare `"sandboxed": true` in its manifest. The
in-process plugin model has been removed — a non-sandboxed plugin
could read the master key and decrypt the seed phrase, so the loader
refuses to load plugins missing the flag.

In practice:

- **At install** — `PluginInstallService` rejects zip uploads whose
  manifest lacks `"sandboxed": true`. The plugin's bytes never reach
  the plugins directory.
- **At enable** — `PluginLoader::setEnabled(true)` refuses non-
  sandboxed plugins. The state file is not modified.
- **At boot** — `discover()` records non-sandboxed plugins with
  status `legacy_unsupported`, never registers their autoloader,
  never instantiates their entry class. Any plugin still marked
  enabled in `plugins.json` after a manifest downgrade gets auto-
  flipped to disabled and a single notice is written to the wallet
  log so the operator knows what happened.

Plugins run in their own per-plugin PHP-FPM pool as their own Unix
user (`eiou-p-<hash>`), with `open_basedir` restricted to the
plugin's dir + scratch, `disable_functions` blocking shell-out and
eval, and zero filesystem access to wallet secrets. Communication
with core happens over loopback HTTP through a per-plugin bearer
token to a whitelisted service gateway. See [Sandboxed Plugin
Authoring](#sandboxed-plugin-authoring) for the contract.

The disabled-by-default rule still applies — a freshly-discovered
plugin doesn't run until the operator explicitly enables it.

### URL validation for GUI rendering

`homepage`, `changelog`, and `author.url` are validated as absolute http(s)
URLs via `filter_var(..., FILTER_VALIDATE_URL)` plus an explicit
`^https?://` regex. A manifest with a `javascript:alert(1)` URL has that
field silently dropped — the `<a href>` the GUI renders cannot be coerced
into executing script. Bundled-CHANGELOG rendering goes through
`UpdateCheckService::markdownToHtml()`, which wraps every code/text span in
`htmlspecialchars` before inserting structural tags.

### State file

`/etc/eiou/config/plugins.json` is the sole source of truth for the persisted
enabled flag. It is written atomically via temp-file + rename. Corrupted or
unreadable state falls back to "all plugins disabled" — no crash, no ghost
state.

Two writers produce the file in normal operation: the wallet pool (running as
`www-data`, via GUI/REST plugin toggles) and the operator CLI (`eiou plugin
enable|disable`, typically running as root inside the container via `docker
exec`). Both must produce a file the wallet pool can read, otherwise CLI-driven
changes would be invisible to HTTP requests until a subsequent www-data-owned
write re-established readability. `writeState()` chmods the temp file to `0640`
and chgrps it to `www-data` before the atomic rename, so the root-write path
ends up `root:www-data 0640` and the www-data-write path ends up
`www-data:www-data 0640`; in both cases the wallet pool can read its own state.
Mirrors the multi-writer ownership pattern used elsewhere for plugin-gateway
tokens.

### Privileged writes into the plugin directory

**Rule:** host code that needs to write a file under
`/etc/eiou/plugins/<id>/` MUST route the write through the supervisor's
`plugin_routing_poller` (via the `/tmp/eiou-routing-req-*.json` request-file
protocol), not write directly from the wallet pool. The wallet pool runs as
`www-data` and cannot reliably write into the per-plugin dir; the supervisor
runs as root and can.

**Why:** the directory's owner varies by install topology. Production
zip-uploads land the dir owned by `www-data` (the wallet pool extracted the
zip and chowned it that way), so a direct www-data write would actually
succeed. Dev bind-mount layouts — where `/etc/eiou/plugins/` is mounted from
the host filesystem — inherit the host operator's uid. On a typical host
that uid is 1000, which inside the container collides with the
`eiou-p-<8hex>` plugin pool user (also derived to land low) rather than
with `www-data`. Direct writes from www-data into that dir then fail with
EACCES, the failure surfaces as "plugin won't enable" with no obvious cause,
and the operator's workaround (`usermod -aG eiou-p-<hash> www-data`) doesn't
even work without a full container restart because FPM workers' supplementary
groups are fixed at master start. Routing through the supervisor sidesteps
the whole class of perm issues — root writes anywhere.

**Reference implementation:** `PluginGatewayTokenService::mint()` generates
the token in memory (no filesystem touch), `PluginPoolService::applyPool()`
ships it through the `apply-pool` request payload as the `gateway_token`
field, and `plugin_routing_poller` in `startup.sh` writes the per-plugin
`.gateway-token` file as root with `chown <system_user>:<system_user>` plus
mode `0600`. The request handler validates the token shape (`^[a-f0-9]{64}$`)
as defence in depth against a corrupted-request write primitive against
arbitrary paths.

**Applies to future features too.** Anything that needs the host to write
into the plugin dir — config snapshots, per-plugin certs, manifest
overrides, generated assets — should extend the supervisor poller protocol
with a new request type rather than try to write from the wallet pool
directly. Doing so keeps the operational story consistent across production
and dev bind-mount, and concentrates "wrote a privileged file" auditing in
one place (the supervisor's stdout).

---

## Troubleshooting

### Plugin shows status `failed` with a red dot

Click the row. The detail modal shows the exception message in a red alert
block. Common causes:

- **Entry class not found** — PSR-4 map in manifest doesn't match the actual
  namespace + file path. Check that `autoload.psr-4["Your\\Namespace\\"]`
  matches the namespace declared at the top of the entry class, and that the
  directory (e.g. `"src/"`) matches where the file lives.
- **Entry class doesn't implement `PluginInterface`** — add the `implements
  PluginInterface` clause and all four required methods.
- **Exception thrown in `register()` or `boot()`** — the message will be the
  actual exception message. Check `/var/log/app.log` for the full stack trace
  (the `plugin_loader` context field scopes the search).

### Plugin doesn't appear at all

The plugin was skipped during discovery. Possible causes:

- `plugin.json` is missing, unreadable, or invalid JSON — `json_decode`
  returned non-array
- `name` field is missing or empty in the manifest
- The directory lives somewhere other than `/etc/eiou/plugins/` — the path
  is set in the `PluginLoader` constructor and isn't exposed as an env var;
  changing it requires modifying how `Application` instantiates the loader

`PluginLoader` swallows discovery-time errors silently to avoid one bad
plugin taking the node down. For verbose logging, bump
`APP_DEBUG` in the environment and re-read `/var/log/app.log`.

### Toggle doesn't take effect

A plugin toggle updates `/etc/eiou/config/plugins.json` immediately but does
not rewire the running processes. You must **restart the node** for event
subscriptions and service registrations to take effect. The GUI shows a
yellow restart banner whenever the desired state diverges from the runtime;
click **Restart node** or run `eiou restart` in a terminal.

### Plugin boots but events don't fire

Double-check:

- The event subscription is in `boot()`, not `register()` — services aren't
  wired yet during `register()`, so the dispatcher may not behave as expected
- You're subscribing to the constant, not a hand-typed string — typos on
  `sync.completed` will subscribe to a phantom event that never fires
- The process you're testing from is actually the one dispatching the event.
  Remember that every PHP process has its own subscription table: a sync
  running in a P2P worker won't fire listeners that were subscribed from a
  PHP-FPM worker

### Bundled CHANGELOG.md doesn't render

- The file must be named exactly `CHANGELOG.md` (case-sensitive) and live in
  the plugin's root directory, next to `plugin.json`
- File size is capped at 256 KB — oversized files fall back to the URL (or
  hide the Changelog row entirely if no URL is set)
- The markdown parser is the same CommonMark-ish subset used by the What's
  New modal. Complex GFM features (tables, task lists, footnotes) are not
  supported — keep the changelog to headings, bullets, and paragraphs

---

## Related Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) — overall system architecture, service
  container, event dispatcher
- [CLI_REFERENCE.md](CLI_REFERENCE.md) — `eiou restart` and other node
  management commands
- [GUI_REFERENCE.md](GUI_REFERENCE.md) — Settings section walkthrough
- [DOCKER_CONFIGURATION.md](DOCKER_CONFIGURATION.md) — volume mounts and
  environment variables, including `/etc/eiou/plugins` and `/etc/eiou/config`
