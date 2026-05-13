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
8. [Managing Plugins in the GUI](#managing-plugins-in-the-gui)
9. [Managing Plugins from the CLI](#managing-plugins-from-the-cli)
10. [Managing Plugins over the REST API](#managing-plugins-over-the-rest-api)
11. [Sandboxed Plugin Authoring](#sandboxed-plugin-authoring)
12. [Events a Plugin Can Subscribe To](#events-a-plugin-can-subscribe-to)
13. [Writing a Plugin](#writing-a-plugin)
14. [Extending the CLI and REST API](#extending-the-cli-and-rest-api)
15. [Extending the GUI](#extending-the-gui)
16. [Registering Payback-Method Rail Types](#registering-payback-method-rail-types)
17. [Testing a Plugin](#testing-a-plugin)
18. [Safety Model and Limitations](#safety-model-and-limitations)
19. [Troubleshooting](#troubleshooting)
20. [Related Documentation](#related-documentation)

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
- Read core tables (`contacts`, `transactions`, `api_keys`, `balances`,
  `payback_methods`, …) or other plugins' tables via raw SQL — the plugin's
  own MySQL user has grants only on its `owned_tables`. Core data must be
  reached through host services on `ServiceContainer`. See
  [How plugins interact with core data](#how-plugins-interact-with-core-data)
  for the full split.
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
can touch. They are not the whole picture — plugins can still interact with
core data, but they have to go through a curated, in-process API. Two
distinct layers:

**Layer 1 — Raw SQL via the plugin's PDO (`$container->getPluginPdo($id)`)**

What it sees: only the tables listed in this plugin's `owned_tables`. Core
tables (`contacts`, `transactions`, `api_keys`, `payback_methods`,
`balances`, …) and other plugins' tables are not just hidden — they are
denied at the MySQL privilege check. A `SELECT * FROM contacts` from a
plugin's PDO returns MySQL error 1142.

This is the path you use for anything the plugin *owns*: storing its own
state, building its own indexes, running its own analytics on its own
rows.

**Layer 2 — Host services via the `ServiceContainer` (`$container->getXxxService()`)**

What it sees: whatever each host service chooses to expose. Plugins
receive `ServiceContainer` in `register()` and `boot()` and can call into
any registered core service — `ContactService`, `TransactionService`,
`BalanceService`, etc. Those services run inside the host process with
full app-user database privileges and return whatever shape they
normally return.

This is the path you use for anything the plugin needs to *read* or
*react to* in core data. A notifications plugin doesn't query
`transactions` directly — it subscribes to `TransactionEvents` and/or
calls `TransactionService::getRecent()`. A custom payback-method type
doesn't query `payback_methods` directly — it registers via
`PaybackMethodTypeRegistry` and gets called by the host with the rows
already loaded.

Why the split: the host services act as a typed, business-rule-aware
boundary. They redact what shouldn't leave the core, gate sensitive
operations behind sensitive-access, and stay stable across schema
changes. A direct `SELECT * FROM api_keys` by a plugin would be a
disaster on multiple axes (schema coupling, no redaction, no
authorization, no audit) — `ApiKeyService::list()` returns hashed
identifiers and never plaintext, regardless of caller.

What this means in practice:

| Goal | Right path |
| ---- | ---------- |
| Read recent transactions | `TransactionService::getRecent()` |
| Look up a contact by pubkey | `ContactService::getByPublicKey()` |
| React to a sync event | subscribe to `SyncEvents::SYNC_COMPLETED` |
| Store the plugin's own state | `getPluginPdo()` + own table |

Common asks that are deliberately unreachable:

- Reading all wallet keys — no service exposes plaintext private keys; the
  PDO can't read the wallet table either.
- Reading API key plaintext — `ApiKeyService` returns only hashed
  identifiers; the PDO can't read `api_keys` either.
- `SELECT * FROM contacts` — privilege denied at MySQL.
- Modifying another plugin's tables — privilege denied at MySQL.

If a host service doesn't yet expose the data your plugin needs, the
right move is to add or extend a service — not to widen MySQL grants.

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
- Plugin FPM pools run as `eiou-p-<hex>` users and their
  `open_basedir` doesn't admit `/etc/eiou/credentials/` anyway, so
  one plugin can't read another plugin's credentials via the
  plugin layer either.
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

`Eiou\Services\PluginInstallService` owns the validation and extraction
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
- **Replace an existing plugin.** Re-uploading a plugin that's already on
  disk returns `409 already_installed`. Updates require an explicit
  uninstall first, so the operator acknowledges the destructive step.
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
`pluginsUpload` action. On success the new plugin appears in the table as
disabled (grey dot); the row's signature status is reflected in the toast
(`ok`, `unsigned`, etc.). To activate, toggle the plugin on and use the
restart banner — same flow as a manually-dropped-in plugin.

The full validation contract and threat model is documented in
[Installing Plugins](#installing-plugins).

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
5. **CORS** (only when `cors_allowed_origins` is declared) — `OPTIONS` preflight returns `204` with the appropriate `Access-Control-*` headers without invoking the plugin; cross-origin requests from non-allow-listed origins get an empty `Access-Control-Allow-Origin` value, which the browser's same-origin check then refuses to surface to the calling page.

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

| Service                         | Methods                                                                                   |
| ------------------------------- | ----------------------------------------------------------------------------------------- |
| `Logger`                        | `debug`, `info`, `warning`, `error`                                                       |
| `TransactionLookupService`      | `getByTxid`, `getStatusByTxid`, `existingTxid`, `isCompletedByTxid`, `getReceivedUserTransactions` |

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

## Extending the GUI

Plugins extend the wallet GUI through five complementary surfaces — render
hooks, filter hooks, the asset registry, the tab registry, and the action
registry. Each is exposed via `ServiceContainer` and meant to be used from
`boot()`. This section is the API reference plugin authors need day-to-day.

```php
public function boot(ServiceContainer $container): void
{
    $hooks   = $container->getHooks();          // render + filter primitives
    $assets  = $container->getAssetRegistry();  // CSS / JS enqueue
    $tabs    = $container->getTabRegistry();    // top-level tabs
    $actions = $container->getActionRegistry(); // POST handlers
    // … register surfaces below …
}
```

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

```php
$hooks->onRender('gui.dashboard.after', function (array $ctx): string {
    $name = htmlspecialchars($ctx['user']->getDisplayName() ?? '');
    return "<section class=\"plugin-myplugin-widget\"><h3>Hi {$name}</h3></section>";
}, 20);
```

The `$ctx` array carries whatever the host passed at the fire site — at
present every wallet template fires with `['user' => $user]`. Listeners
that don't need the context can ignore the parameter.

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

```php
$hooks->onFilter('gui.contact.actions', function (array $actions): array {
    $actions[] = [
        'label'  => 'Bookmark',
        'icon'   => 'fas fa-star',
        'action' => 'myPluginBookmark',   // must match a registered action
    ];
    return $actions;
});
```

### Asset enqueue (`PluginAssetRegistry`)

```php
$assets->enqueueStyle('myplugin', 'assets/styles.css');
$assets->enqueueScript('myplugin', 'assets/main.js');
$assets->enqueueScript('myplugin', 'assets/early.js', ['head' => true]);
$assets->enqueueStyle('myplugin', 'assets/big.css', ['priority' => 5]);
```

Paths resolve under the plugin root (`/etc/eiou/plugins/<id>/`). Path
traversal (`..`, leading slash, backslashes) is rejected at enqueue and
re-validated against `realpath()` at render so a symlinked target can't
escape the plugin tree. Files smaller than `URL_MODE_THRESHOLD` (4 KiB)
inline as `<style nonce>` / `<script nonce>` blocks; larger files get a
`<link href="…?v=<hash>">` / `<script src="…?v=<hash>">` tag served by
the `/gui/plugin-assets/<id>/<path>` route. Force a mode with
`['inline' => true]` or `['inline' => false]`.

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
in `WalletTemplateHelpers.php` consolidates the wrapper so plugin
sections look native and core sections stay consistent when CSS/UX
refinements happen.

```php
echo renderSection([
    'id'    => 'my-plugin-stats',           // div id + hook namespace
    'icon'  => 'fas fa-chart-line',
    'title' => 'My Plugin Stats',

    // Optional: extra HTML rendered inside the header after the title
    // (badges, inline buttons). Raw HTML — caller is responsible for
    // any escaping inside the snippet.
    'headerExtras' => '<button class="btn btn-sm btn-primary"
                              data-action="myPluginRefresh">Refresh</button>',

    // Optional: explanatory copy in a <details> disclosure. Raw HTML;
    // pass null/'' to skip the disclosure entirely.
    'introTitle' => 'About my plugin',
    'intro'      => 'Plain prose with <strong>inline markup</strong> OK.',

    // Required: the section body — table, form, list, anything.
    'body'  => $myStatsHtml,
]);
```

The helper auto-fires two render hooks around every section it
renders:

- `gui.section.before.<id>` — fired immediately before the section
  opens. Plugins can inject a banner above someone else's section
  without forking the template.
- `gui.section.after.<id>` — fired after the section closes. Useful
  for "additional details" panels that should attach to a specific
  core section.

The hook context is the full spec array, so listeners can adapt to
which section they're inside (e.g. only inject for `id === 'dlq'`).

```php
// In a plugin's boot():
$container->getHooks()->onRender('gui.section.after.dlq', function (array $ctx): string {
    return '<div class="my-plugin-dlq-followup">…</div>';
});
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
plugin-authored tables get the same chrome.

```php
echo renderTable([
    'id'           => 'my-plugin-table-wrapper', // optional <div id>
    'wrapperClass' => 'contacts-table-wrapper d-none', // optional, default is just contacts-table-wrapper
    'variant'      => 'my-plugin',  // becomes class="contacts-table my-plugin-table"
    'headers'      => '<tr><th>Col 1</th><th>Col 2</th></tr>',
    'body'         => $rowsHtml,    // your <tr>…</tr> rows; empty for JS-populated tables
    'tbodyId'      => 'my-plugin-tbody', // optional <tbody id> for JS-populated tables
]);
```

Column definitions stay as raw HTML — they're domain-specific (sort
buttons, info-tooltip icons, custom `data-` attributes) and a generic
config array would just push complexity around. The helper is just
the wrapper.



```php
$tabs->register([
    'id'     => 'myplugin',           // kebab-case; must be unique
    'label'  => 'My Plugin',
    'icon'   => 'fas fa-puzzle-piece', // Font Awesome class
    'order'  => 50,                    // <100 = before settings
    'render' => fn() => '<div class="plugin-myplugin">…</div>',
    // OR
    // 'include' => '/etc/eiou/plugins/myplugin/views/tab.php',
]);
```

The five core tabs (Dashboard 10, Payment 20, Contacts 30, Activity 40,
Settings 50) are registered by Functions.php each request. Plugin tabs
slot in by `order`. `wallet.html` iterates the registry once to build
the desktop nav, mobile nav, and panel sections from a single source of
truth — your tab automatically appears in all three places.

Optional `badge` (int or callable returning int) renders a numeric pill
on the tab button; `badgeTitle` (string or callable) provides hover
text. Last-write-wins on `id` collision lets a plugin override a core
tab if it really wants to.

### Action registry (`GuiActionRegistry`)

Routes every authenticated POST in the wallet GUI through one
registry. Both core handlers and plugin handlers register against the
same registry; the dispatcher in `Functions.php` looks up `$_POST['action']`
and calls the matching closure.

```php
$actions->register('myPluginBookmark',
    function (array $request): void {
        // The registry has already enforced the tier you declared at
        // registration time (see the table below). The handler still
        // emits whatever response shape it wants — JSON + exit, or
        // MessageHelper::redirectMessage(...), or anything else a
        // normal POST handler does.
        \Eiou\Gui\Helpers\MessageHelper::redirectMessage('Bookmarked!', 'success');
    },
    GuiActionRegistry::TIER_CSRF,   // public | auth | csrf | sensitive
    'myplugin'                       // plugin id (for diagnostics)
);
```

| Tier | Constant | Gate the registry enforces |
|---|---|---|
| `public` | `TIER_PUBLIC` | None — anonymous callers OK. Use sparingly. |
| `auth` | `TIER_AUTH` | Authenticated session required. The registry routes but does NOT check CSRF — your handler is expected to do its own check (most useful when you need rotating CSRF, the legacy plain-text 403 body, or a per-handler envelope shape). |
| `csrf` | `TIER_CSRF` | Auth + valid CSRF token (non-rotating). On failure the registry emits `{"success":false,"error":"csrf_error","message":"Invalid CSRF token"}` with HTTP 403 and `Content-Type: application/json`. **Default for new plugin AJAX handlers.** |
| `sensitive` | `TIER_SENSITIVE` | Auth + CSRF + recent sensitive-access grant (the same gate that protects "Reveal API key", "Delete account", etc.). |

Action names are camelCase, 1–64 chars. **Last-write-wins on collisions:**
a plugin that registers an action with the same name as a core action
overrides core, and a plugin that registers after another plugin
overrides the earlier one. The registry runs in the order
`Application::bootAll()` boots plugins, then `index.html` registers
core controllers, then plugins' explicit late registrations. Naming
collisions with core are documented but not blocked — exercise the
override deliberately.

#### Core actions are registry entries

Every core POST action — contact / transaction / payment-request /
settings / API-keys / plugin / payback-methods management, the
remember-me revoke endpoints, the DLQ retry endpoints, the
search/loadMore AJAX endpoints, and the "What's New" dismiss/notes
endpoints — is registered with the registry by core's startup code at
the same time plugins register theirs. That means a plugin can
override `addContact`, `sendEIOU`, `apiKeysCreate`, etc. by
registering a handler with the same name. Use this with care; the JS
client expects specific response shapes and will misbehave if your
override changes them.

Core entries register with `'core'` as the plugin-id tag and at
`TIER_AUTH` so each handler keeps its existing inline CSRF semantics
(rotating for HTML form submits, non-rotating for AJAX) and its
existing failure-response shape (plain-text 403 for HTML form submits,
per-handler legacy JSON envelope for AJAX). When you write a plugin
that overrides a core action, mirror those semantics or expect the JS
to break.

Forms rendered by `gui.contact.actions` post to `/wallet?action=<name>`;
you don't need a separate route.

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
exercises every surface above: an enqueued stylesheet, a dashboard
render hook, a `Fortunes` top-level tab, the `helloEiouFortune` POST
action with `TIER_CSRF`, the `gui.dashboard.widgets` filter, and the
`gui.contact.actions` filter. It's the smallest end-to-end reference.

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
