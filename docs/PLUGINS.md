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
5. [Lifecycle](#lifecycle)
6. [Managing Plugins in the GUI](#managing-plugins-in-the-gui)
7. [Managing Plugins from the CLI](#managing-plugins-from-the-cli)
8. [Managing Plugins over the REST API](#managing-plugins-over-the-rest-api)
9. [Events a Plugin Can Subscribe To](#events-a-plugin-can-subscribe-to)
10. [Writing a Plugin](#writing-a-plugin)
11. [Extending the CLI and REST API](#extending-the-cli-and-rest-api)
12. [Registering Payback-Method Rail Types](#registering-payback-method-rail-types)
13. [Testing a Plugin](#testing-a-plugin)
14. [Safety Model and Limitations](#safety-model-and-limitations)
15. [Troubleshooting](#troubleshooting)
16. [Related Documentation](#related-documentation)

---

## Overview

A plugin is a directory containing a `plugin.json` manifest and an entry class
implementing `Eiou\Contracts\PluginInterface`. The `PluginLoader` service
scans `/etc/eiou/plugins/`, reads each manifest, autoloads the entry class via
the declared PSR-4 map, and calls its lifecycle methods during `Application`
boot.

### What plugins can do

- Subscribe to core events (sync, delivery, chain-drop, transaction, contact,
  P2P, plugin lifecycle) via `EventDispatcher`
- Register new services in the `ServiceContainer`
- Add new repositories (including new database tables)
- Decorate existing services
- Register their own CLI subcommands via `PluginCliRegistry` (`eiou <plugin> ...`)
- Register their own REST endpoints via `PluginApiRegistry`
  (`* /api/v1/plugins/<plugin>/<action>`, admin-scoped)
- Register new payback-method rail types (Bitcoin, PayPal, Bizum, …)
  via `PaybackMethodTypeRegistry` so they appear in the GUI type
  picker and route through validation/masking/precision like core types

### What plugins cannot do (by design)

- Run before `UserContext` is initialized — the lifecycle is wired after core
  bootstrap, not before
- Crash the node during discovery, registration, or boot — failures are caught
  per-plugin, logged, and the plugin is marked as `failed`; core keeps running
- Persist state outside their own directory or the shared state file
- Take effect without a node restart — see [Lifecycle](#lifecycle) below


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
  }
}
```

Full manifest with all optional metadata (including the `database` block for
plugins that want their own MySQL user — see [Database Isolation](#database-isolation)):

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
  "author": {
    "name": "Acme Co.",
    "url": "https://acme.example"
  },
  "homepage": "https://acme.example/plugins/my-plugin",
  "changelog": "https://acme.example/plugins/my-plugin/CHANGELOG.md",
  "license": "MIT",
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

### Field reference

| Field         | Required | Type                  | Notes                                                                                          |
| ------------- | -------- | --------------------- | ---------------------------------------------------------------------------------------------- |
| `name`        | yes      | string (kebab-case)   | Used as the key in `plugins.json` and in all API responses. Should match the subdirectory name (loader doesn't enforce, but mismatches make the GUI/CLI surfaces confusing). |
| `version`     | yes      | string (semver)       | Displayed in the Plugins table. Used for log correlation.                                      |
| `entryClass`  | yes      | string (FQCN)         | Must implement `Eiou\Contracts\PluginInterface`.                                               |
| `autoload`    | yes      | object                | PSR-4 map: `{ "psr-4": { "Namespace\\": "src/" } }`. Relative to the plugin directory.        |
| `description` | no       | string                | One-line summary, shown in the table and detail modal.                                         |
| `author`      | no       | string or object      | `"Acme Co."` or `{"name": "Acme Co.", "url": "https://..."}`. URL is validated as http(s).     |
| `homepage`    | no       | absolute http(s) URL  | Rendered as an external link in the detail modal.                                              |
| `changelog`   | no       | absolute http(s) URL  | Fallback when no bundled `CHANGELOG.md` is present. Bundled file wins when both exist.         |
| `license`     | no       | string (≤ 64 chars)   | SPDX identifier preferred (`MIT`, `Apache-2.0`, etc.). Shown next to version.                  |
| `database`    | no       | object                | Enables per-plugin MySQL user isolation. See [Database Isolation](#database-isolation).        |

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

Each plugin user gets exactly these privileges on its own table namespace:

```
CREATE, ALTER, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE ON eiou.plugin_<snake_id>_%
```

Not included:

- **`REFERENCES`** — plugins cannot create foreign keys pointing at core
  tables. They can FK between their own tables freely.
- **`GRANT OPTION`** — plugins cannot sub-grant privileges to other users.
- **Any privilege on core tables** — `contacts`, `transactions`, `api_keys`,
  `payback_methods`, etc. are invisible and inaccessible.
- **Any privilege on other plugins' tables** — Plugin A cannot read,
  modify, or even enumerate Plugin B's tables.

The user is bound to `'plugin_<snake_id>'@'localhost'` — never `'%'`. A
network-layer compromise cannot reach it via remote MySQL auth.

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

### Getting a PDO

Use `ServiceContainer::getPluginPdo($pluginId)` from your `boot()` or
runtime code:

```php
class MyPlugin implements PluginInterface
{
    public function getName(): string    { return 'my-plugin'; }
    public function getVersion(): string { return '1.0.0'; }

    public function register(ServiceContainer $c): void {}

    public function boot(ServiceContainer $container): void
    {
        $pdo = $container->getPluginPdo($this->getName());

        // Create your tables (idempotent — called on every boot).
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plugin_my_plugin_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                topic VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB
        ");
    }
}
```

The PDO is cached per-plugin for the lifetime of the request; repeated
`getPluginPdo()` calls from the same request return the same connection.

**Do not call `$container->getPdo()`.** That returns the root/app PDO —
the credentials plugin users are specifically sandboxed away from.

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

### Boot-time reconciliation

On every node boot, after the master key is loaded and before plugins'
`register()` runs, the loader runs an idempotent reconcile pass:

- For each enabled plugin with `database.user: true`: `CREATE USER IF NOT
  EXISTS` + `ALTER USER` + `GRANT`. Self-heals after a `mysql-data` volume
  recreation, a manual `DROP USER`, an operator `db_limits` edit, or a
  master-key rotation.
- For each disabled plugin that still has a credential row: `REVOKE ALL
  PRIVILEGES`. Self-heals cases where `setEnabled(false)` flipped the flag
  but didn't revoke (shouldn't happen in current code, but the reconciler
  is defensive).

Per-plugin reconcile errors are logged and surfaced as `error:<msg>` in
the plugin list's status field. They do **not** block node boot — the
operator investigates via the GUI/CLI list.

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
2. **`REVOKE ALL PRIVILEGES`** — locks out the plugin user before the
   table drops, so a hostile plugin can't race the next steps.
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
        // Runs BEFORE MySQL revoke — full grants still available.
        // Plugin's getPluginPdo() still returns a working connection.
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

## Lifecycle

`PluginLoader` runs three phases, all driven by `Application::__construct`:

### 1. `discover()`

- Scans `/etc/eiou/plugins/` for subdirectories with a `plugin.json`
- Parses each manifest; malformed JSON or missing required fields skip the
  plugin silently
- Registers the PSR-4 autoload map with the SPL autoloader
- Instantiates the entry class (still without wiring to `ServiceContainer`)
- Marks each plugin's status as `discovered`

### 2. `registerAll(ServiceContainer $c)`

Runs **before** `ServiceContainer::wireAllServices()`.

- Calls `register()` on every enabled, successfully-discovered plugin
- Use this phase to add new services, register custom repositories, or
  reserve database tables
- Other plugins' services may not yet be available — don't assume they are
- A thrown exception disables just this plugin (logged, marked `failed`) but
  does not abort core bootstrap

### 3. `bootAll(ServiceContainer $c)`

Runs **after** `ServiceContainer::wireAllServices()`.

- Calls `boot()` on every plugin that survived `registerAll`
- All core services are wired and ready — subscribe to events, decorate
  services, register CLI/API extensions here
- Same failure isolation as `registerAll`

### Per-process lifecycle

Every process that loads the eIOU core — PHP-FPM worker, the `eiou` CLI, the
background processors, per-message P2P workers — constructs its own
`Application` singleton and runs the full lifecycle. Event subscriptions are
*process-local*: a plugin that subscribes to `sync.completed` inside a PHP-FPM
worker will not react to sync events happening inside the `P2pWorker`
process. Each runtime needs its own copy of the subscriptions, so each
runtime runs the lifecycle locally.

### Why changes need a restart

Toggling a plugin on or off updates `/etc/eiou/config/plugins.json`
immediately, but the running processes have already passed through
`registerAll` / `bootAll` — their service graphs are frozen and their event
subscriptions are wired. A restart recycles those processes through a fresh
lifecycle, picking up the new state.

The GUI's **Restart node** button (shown inside the yellow "changes saved"
banner when the on-disk state diverges from what's actually loaded) triggers
this via the request-marker pattern documented in
[ARCHITECTURE.md](ARCHITECTURE.md) — see `RestartRequestService` and
`NodeRestartService`.

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

Three subcommands, no restart triggered — operator must follow up with
`eiou restart` once they're done toggling.

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

Plugin-lifecycle events dispatched by `PluginLoader` itself.

| Constant            | When it fires                                                 |
| ------------------- | ------------------------------------------------------------- |
| `PLUGIN_REGISTERED` | After a plugin's `register()` completes                       |
| `PLUGIN_BOOTED`     | After a plugin's `boot()` completes                           |
| `PLUGIN_FAILED`     | A plugin threw during `register()` or `boot()` (plus phase)   |

Subscriptions made in `register()` won't observe other plugins'
`PLUGIN_REGISTERED` events that ran ahead of you in iteration order —
subscribe in `boot()` to catch the full registration pass.


---

## Writing a Plugin

The reference plugin `hello-eiou` is ~80 lines and demonstrates the full
API surface. Start by copying it:

```bash
cp -r /etc/eiou/plugins/hello-eiou /etc/eiou/plugins/my-plugin
```

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
  }
}
```

### 2. Rename the entry class

Move `src/HelloEiouPlugin.php` to `src/MyPlugin.php`, update the namespace
and class name:

```php
<?php
namespace Eiou\Plugins\MyPlugin;

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;
use Eiou\Events\EventDispatcher;
use Eiou\Events\SyncEvents;
use Eiou\Utils\Logger;

class MyPlugin implements PluginInterface
{
    public function getName(): string    { return 'my-plugin'; }
    public function getVersion(): string { return '1.0.0'; }

    public function register(ServiceContainer $container): void
    {
        // Register services here if needed. Runs before wireAllServices.
    }

    public function boot(ServiceContainer $container): void
    {
        EventDispatcher::getInstance()->subscribe(
            SyncEvents::SYNC_COMPLETED,
            function (array $data): void {
                Logger::getInstance()->info('[my-plugin] sync completed', $data);
            }
        );
    }
}
```

### 3. Enable and restart

- Settings → Plugins → toggle **my-plugin** on
- Click **Restart node** in the yellow banner
- Wait for the page to reload (the GUI polls every second until PHP-FPM is
  back online)
- The status dot next to `my-plugin` should turn green — your plugin is live

### 4. Ship a CHANGELOG

Drop a `CHANGELOG.md` next to `plugin.json`. The GUI will automatically
expose a **View bundled CHANGELOG.md** button in the detail modal. See
`hello-eiou/CHANGELOG.md` for a minimal example.

---

## Extending the CLI and REST API

Plugins can register top-level `eiou` subcommands and REST endpoints under
`/api/v1/plugins/<plugin>/`. Both registrations happen in `boot()` — the
registries are wired in by then — and both are admin/privileged by default:
the CLI runs as the local operator, and plugin-owned REST endpoints
inherit the admin scope gate from the `/api/v1/plugins` resource.

### CLI subcommand

```php
public function boot(ServiceContainer $container): void
{
    $container->getPluginCliRegistry()->register('myplugin',
        function (array $argv, CliOutputManager $output): void {
            // $argv is the full `eiou <cmd> [args...]` argv. Parse further
            // subcommands out of $argv[2+].
            $sub = $argv[2] ?? 'help';
            if ($sub === 'status') {
                $output->success('All systems nominal', ['status' => 'ok']);
                return;
            }
            $output->error("Unknown subcommand: {$sub}", 'COMMAND_NOT_FOUND', 404);
        }
    );
}
```

Operators invoke it as `eiou myplugin status`.

Naming rules:

- kebab-case, 1–32 chars, must start with a letter
- cannot collide with a core command (the registry has a hard-coded list of
  reserved names — `send`, `add`, `plugin`, `restart`, etc.; colliding names
  throw at `register()` time so plugin authors find out loudly)
- each plugin name can only be registered once per process

Handler failures are caught and reported via the output manager, so a
buggy command can't tear down the CLI process.

### REST endpoint

```php
public function boot(ServiceContainer $container): void
{
    $container->getPluginApiRegistry()->register('myplugin', 'GET', 'status',
        function (string $method, array $params, string $body): array {
            return ['status' => 'ok', 'ts' => time()];
        }
    );
}
```

Callers invoke it as `GET /api/v1/plugins/myplugin/status`. The handler's
return array is wrapped in the standard `successResponse` shape by the
core `ApiController`. Throwing becomes a 500 with a structured error body.

Rules:

- `action` is kebab-case, 1–64 chars
- `enable` and `disable` are reserved for core's plugin-management endpoints
- same `(plugin, method, action)` tuple can only be registered once
- admin scope is enforced by the core `handlePlugins` gate, so individual
  handlers don't need to re-check auth

Path shape is single-level only: `/api/v1/plugins/<plugin>/<action>`.
Nested paths like `/api/v1/plugins/myplugin/users/123` aren't supported in
v1 because the API router's path parser stops at the fifth segment —
encode sub-resources as query params (`?id=123`) or as a compound action
name.

### Reference

See `hello-eiou` — `eiou hello-eiou` returns a random fortune from the
CLI, and `GET /api/v1/plugins/hello-eiou/fortune` returns one as JSON.
Both registrations live in `HelloEiouPlugin::boot()`.

---

## Registering Payback-Method Rail Types

Core ships two payback-method rail types — `bank_wire` (with SEPA /
Faster Payments / ACH / FedNow / SWIFT) and `custom` (free-text
instructions). Every other rail — Bitcoin, PayPal, Bizum, Lightning,
EVM, Pix, UPI, etc. — is a plugin opportunity. A plugin registers one
type by implementing `Eiou\Contracts\PaybackMethodTypeContract` and
calling `PaybackMethodTypeRegistry::register()` from its `register()`
phase:

```php
namespace Eiou\Plugins\PaybackBtc;

use Eiou\Contracts\PluginInterface;
use Eiou\Contracts\PaybackMethodTypeContract;
use Eiou\Services\ServiceContainer;

class PaybackBtcPlugin implements PluginInterface
{
    public function getName(): string    { return 'payback-btc'; }
    public function getVersion(): string { return '0.1.0'; }

    public function register(ServiceContainer $container): void
    {
        $container->getPaybackMethodTypeRegistry()->register(new BtcType());
    }

    public function boot(ServiceContainer $container): void { /* no-op */ }
}

class BtcType implements PaybackMethodTypeContract
{
    public function getId(): string { return 'btc'; }

    public function getCatalogEntry(): array
    {
        return [
            'id'          => 'btc',
            'label'       => 'Bitcoin',
            'group'       => 'crypto',
            'icon'        => 'fab fa-bitcoin',
            'description' => 'Settle in BTC. Accepts a mainnet address.',
            'currencies'  => ['BTC'],          // null = any ISO-4217
            'fields'      => [
                ['name' => 'address', 'label' => 'Bitcoin address', 'type' => 'text',
                 'required' => true, 'placeholder' => 'bc1q…'],
            ],
        ];
    }

    public function validate(string $currency, array $fields): array
    {
        if ($currency !== 'BTC') {
            return [['field' => 'currency', 'code' => 'invalid_currency_for_type',
                     'message' => 'Bitcoin settles in BTC']];
        }
        if (empty($fields['address'])) {
            return [['field' => 'address', 'code' => 'required',
                     'message' => 'address is required']];
        }
        return [];
    }

    public function mask(array $fields): string
    {
        $a = (string) ($fields['address'] ?? '');
        return $a === '' ? '•••' : substr($a, 0, 6) . '…' . substr($a, -4);
    }

    public function defaultPrecision(string $currency): ?array
    {
        return $currency === 'BTC' ? [1, -8] : null;   // satoshi
    }
}
```

### What the contract plugs into

The registry is consulted from four places in core:

| Caller                                          | Uses                       |
| ----------------------------------------------- | -------------------------- |
| `PaybackMethodTypeValidator::getCatalog()`      | `getCatalogEntry()` — merges the entry into the GUI type-picker catalog. Any new `group` id declared by a plugin is auto-injected into the groups list (between `bank` and `other`). |
| `PaybackMethodTypeValidator::validate()`        | `validate()` — delegated for unknown type ids. Return `[]` on success, or a list of `['field' => string\|null, 'code' => string, 'message' => string]` error records. |
| `PaybackMethodService::maskForType()`           | `mask()` — short redacted string for the list-row cell. Return `'•••'` on missing fields rather than throwing. |
| `SettlementPrecisionService::defaultFor()`      | `defaultPrecision()` — return `[min_unit, exponent]` (e.g. `[1, -8]` for satoshi) or `null` to fall back to the generic fiat/crypto defaults. |

### Registration rules

- `getId()` must match `^[a-z][a-z0-9_]{0,31}$`
- `bank_wire` and `custom` are reserved — a plugin can't shadow them
- Each id can only be registered once per process; registering a
  duplicate raises `InvalidArgumentException`, which the plugin loader
  catches and marks the offending plugin `failed` without taking the
  node down
- Register in `register()`, not `boot()` — the validator is
  instantiated as part of `ServiceContainer::getPaybackMethodService()`
  which is wired during `ServiceContainer::wireAllServices()`, right
  after the `register()` phase

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

### No sandboxing

Plugins run with the same permissions and filesystem access as the core PHP
process (`www-data` inside the container). There is no opcode sandbox, no
capability filter, no per-plugin process isolation. **Only install plugins
from sources you trust.** The manifest opt-in model (disabled by default) is
a safety rail against *accidental* breakage, not a defence against malicious
code.

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
