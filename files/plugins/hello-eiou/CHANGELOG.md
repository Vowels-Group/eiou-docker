# Changelog — hello-eiou

All notable changes to the `hello-eiou` plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this plugin follows [Semantic Versioning](https://semver.org/).

## 1.5.0

### Changed
- **Moved from a top-level Fortunes tab to a sub-panel under the host's
  new Plugins tab.** The wallet GUI now owns a single "Plugins" tab
  between Activity and Settings; each installed plugin registers a
  sub-panel that's selectable from a dropdown at the top of that tab.
  Manifest changes:
  - Removed the `tabs: [{id:"hello-eiou-fortunes", ...}]` entry.
  - Added `plugin_tab_panel: {label:"Hello eIOU", icon:"fas fa-cookie-bite"}`.
- Dispatcher's `render` handler renames from `tab:hello-eiou-fortunes`
  to `plugin_tab_panel` (fixed name — each plugin gets at most one
  panel, no per-tab id needed). The host POSTs that name when it needs
  the panel's HTML.
- Panel chrome simplified: the host's Plugins tab provides the
  outer container and tab title, so the dispatcher's body drops the
  `form-container` wrapper and the duplicate `<h2>Fortunes</h2>` —
  the panel renders a smaller `<h3>` sub-header inside the host's
  shared container.

## 1.4.0

### Added
- **Self-introspection demo** in the Fortunes tab — fetches and renders the
  plugin's own granted permissions via `PluginLookupService.getOwnPermissions`
  and its own manifest fields via `PluginLookupService.getOwnManifest`. Useful
  reference for plugin authors who want to fail-fast at boot if a required
  permission isn't granted, or render their own "this app uses…" panel.
  Neither method requires a permission key — scope is the calling plugin's
  own row only.
- **Permission-gated demo**: `ContactLookupService.listAccepted` is now in
  `core_services` and `contact_address_book_enumerate` is declared in the
  new top-level `permissions` manifest field. The Fortunes tab picks a
  random accepted contact and personalises one of the fortunes
  ("Hello, Alice! …"). Falls back gracefully when the operator hasn't
  granted the permission (gateway returns null) or when the wallet has no
  accepted contacts. Demonstrates the louder-consent permission tier that
  sits on top of `core_services` — see `docs/PLUGINS.md` for the catalog.

## 1.3.0

### Changed
- Migrated to the sandboxed plugin contract — `hello-eiou` now runs in its
  own per-plugin PHP-FPM pool under a dedicated Unix user
  (`eiou-p-<8-hex-sha256>`) with `open_basedir` + `disable_functions` and no
  read access to the seed/master-key/userconfig files. All core-service
  interactions go through the per-plugin bearer-authenticated gateway at
  `/__plugin_gateway`; in-process `EventDispatcher::subscribe` /
  `$container->getPluginCliRegistry()->register(…)` / direct
  `Logger::getInstance()` calls are gone.
- `plugin.json` now declares `sandboxed: true` and lists every previously
  imperative surface declaratively:
  - `subscribes_to: ["sync.completed"]` (was `EventDispatcher::subscribe`)
  - `render_hooks: ["gui.dashboard.after"]` (was `RenderRegistry::register`)
  - `filter_hooks: ["gui.dashboard.widgets", "gui.contact.actions"]`
    (was `FilterRegistry::register`)
  - `core_services: ["Logger.info", "Logger.warning", "Logger.error",
    "Logger.debug"]` allow-list for core_call back through the gateway
  - `gui_assets`, `tabs`, `gui_actions`, `api_routes`, `cli_commands`
    (were imperative `enqueueStyle` / `TabRegistry` /
    `GuiActionRegistry::register` / `PluginApiRegistry::register` /
    `PluginCliRegistry::register` calls)
- Real runtime is now `__dispatch.php` at the plugin root. The core calls
  it over FastCGI on the plugin's FPM pool for events, render hooks,
  filters, GUI actions, REST routes, and CLI commands. The dispatcher
  reads the bundled bearer token from `/etc/eiou/plugins/hello-eiou/
  .gateway-token` and uses it for any core_call back into the main node.

### Removed
- `src/HelloEiouPlugin.php`'s imperative `register()` / `boot()` lifecycle
  is no longer wired up — the sandbox dispatcher is the entry point. The
  file is kept for now as a metadata holder (`getName`, `getVersion`) but
  none of its `->register` / `->subscribe` calls run anymore; they were
  superseded by the manifest's declarative surfaces.

## 1.2.0

### Added
- GUI hooks demonstration — exercises every surface added in the
  plugin-GUI-hooks branch:
  - `gui.dashboard.after` render hook → fortune widget on the dashboard.
  - `PluginAssetRegistry::enqueueStyle` → `assets/styles.css` for the widget
    (rendered inline with the page CSP nonce).
  - `TabRegistry::register` → top-level "Fortunes" tab between Activity
    and Settings, body provided by a render callable.
  - `GuiActionRegistry::register('helloEiouFortune', …, TIER_CSRF)` →
    POST endpoint that returns a JSON fortune.
  - `gui.dashboard.widgets` filter → contributes a small fortune line
    after the core dashboard widgets.
  - `gui.contact.actions` filter → adds a "Fortune" button to the
    contact-modal settings tab that posts to the registered action.

## 1.1.0

### Added
- `eiou hello-eiou` CLI subcommand returning a random fortune — demonstrates
  the `PluginCliRegistry` surface.
- `GET /api/v1/plugins/hello-eiou/fortune` REST endpoint returning the same —
  demonstrates the `PluginApiRegistry` surface.

## 1.0.0

### Added
- Initial release. Subscribes to `SyncEvents::SYNC_COMPLETED` and logs a
  random eIOU fortune after each successful sync — a minimal, readable
  demonstration of the plugin API in the spirit of WordPress's Hello Dolly.
