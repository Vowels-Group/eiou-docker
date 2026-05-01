# Plugin GUI Hooks — Deferred Work

Companion to `PLUGIN_GUI_HOOKS.md`. Tracks scope intentionally trimmed
out of the initial six-phase implementation so reviewers (and future
maintainers) know what is *known to be missing* versus *unconsidered*.

Every entry below was a conscious cut: either not load-bearing for the
core "plugins can extend the GUI" goal, or carrying enough regression
risk that bundling it into the foundation PR was the wrong trade-off.
Each item names the phase it belongs to and a sketch of the work.

---

---

## Phase 5 — Filter slots

### `gui.contact_modal.body` rendering is HTML-string only

The modal-tab body slot accepts `{id, html}` entries. Plugins that
need richer behavior (loading state, JS event wiring, partial reload)
have to do it inline in the HTML string. **Status: minimal contract,
intentional.**

A future iteration could:
- Accept a `render` callable for lazy generation.
- Accept asset references the host enqueues automatically.

The earlier "load-bearing" concern about plugin modal tabs not
participating in `showModalTab` was moot — the host emits plugin tab
buttons with the standard `class="modal-tab" data-action="showModalTab"`
and panels with `class="modal-tab-content"`, and `showModalTab()` is
class-based, so plugin tabs already toggle correctly without any
`data-plugin-tab` attribute.

### Per-row contact-table buttons skipped

The design doc listed `gui.contact.actions` as a per-contact action-
column hook. The current contact list is a `<table>` with no actions
column — the actions live inside the contact modal's settings tab, so
that's where the slot was wired. A future redesign that adds an
inline actions column should restore the per-row hook fire site.

---

## Phase 6 — Static-asset route

### Plugin asset serving uses inline-OR-URL only

Files smaller than `URL_MODE_THRESHOLD` (4 KiB) inline; larger ones
get a `<link>` / `<script src>` tag with content-hash cache-bust.
**Status: shipped, but two refinements deferred.**

1. **Per-plugin asset bundling.** A plugin shipping ten 1 KiB CSS
   files emits ten `<style>` blocks. A future `bundleStyles($pluginId)`
   call could concatenate them into one inline block per plugin. Saves
   parser-blocking at the cost of plugin authors losing fine-grained
   priority control. Worth doing once a plugin actually trips this.

2. **Source-map support.** The inline branch and the URL branch both
   strip whatever sourcemap the plugin shipped because of how the
   render copies bytes. Plugins debugging in dev mode have to inline
   their sourcemaps with `//# sourceMappingURL=data:...`. Acceptable
   for v1 — plugins are server-side anyway and JS payload is usually
   small.

### Asset route does not preserve directory listings

Paths like `/gui/plugin-assets/<id>/dir/` (trailing slash, no file)
return 404 with `not_found`. There is no auto-index. **Intentional**
— plugins MUST address files by exact path so cache-bust hashes stay
stable. No deferred work; documenting in case a plugin author files a
bug.

### nginx config not pinned to PHP socket name

`/run/php/php-fpm.sock` is hardcoded in
`nginx/eiou-locations.conf`. If a future Docker base image ships a
differently-named socket, the asset route breaks. The same hardcoding
exists in the parent `location ~ \.(php|html)$` block, so this isn't
new debt — but worth folding into any future nginx-config refactor.

---

## Cross-phase

### Plugin admin pages

Tab registry can host them today (a plugin registers a tab at
`order: 1000` and renders an admin UI). **What's missing**: sub-routes
within that tab. Plugin admin UIs that span multiple "pages" have to
reinvent client-side routing today.

A future `PluginRouteRegistry` could expose `/gui/plugin/<id>/<route>`
with the same kind of validation `PluginAssetServer` does for
`/gui/plugin-assets/<id>/<path>`. Out of scope for the foundation PR.

### Auto-prefix plugin CSS with `.plugin-<id>`

Mentioned as a risk in `PLUGIN_GUI_HOOKS.md`. Convention only — no
enforcement. A misbehaving plugin's `body { background: red }` still
breaks the host. **Status: documented in `PLUGINS.md`, not enforced.**

Build-time auto-prefix would need a CSS parser (PHP-CSS-Parser) and
add ~5ms per render per plugin per stylesheet. Acceptable cost, but
a bigger change than fits the foundation PR. Plugin signing + an
operator-vetted plugin set is the v1 mitigation.

### Server-side caching of plugin output

Each request re-runs every render hook + filter listener even when
the result is deterministic for the user / context. **Status:
deferred — premature without profiling data.**

Most hook listeners are cheap (string concatenation, array filter).
The only expected hot spots are listeners that do DB queries; those
should cache themselves at the plugin layer. If profiling later shows
otherwise, add an opt-in `cache_for` parameter to `onRender` /
`onFilter` and key by hook name + serialized context.

### Per-plugin event namespacing

`EventDispatcher` is shared across all plugins; a plugin firing
`my-plugin.something` could collide with another plugin's listener for
the same name. **Status: convention-only namespace
(`<pluginId>.<event>`), no enforcement.**

A future `PluginEventDispatcher` wrapper could auto-prefix every
fire with the plugin id and refuse subscriptions outside the plugin's
own namespace. Not blocking — convention has worked for similar
WordPress hook-name conventions for two decades.

---

## Migration / hardening

### Hello-eiou plugin manifest signature

The plugin is unsigned in this branch — the manifest doesn't yet carry
a signature block. Operators running with
`PLUGIN_SIGNATURE_MODE=enforce` need to sign it or move it to a trusted
keys directory before enabling. Out of scope for the foundation PR.

---

## How to use this doc

When a deferred item gets picked up:
1. Open a tracking issue / PR referencing the section.
2. Move that section here to a `## Resolved` block at the bottom with
   the PR link, OR delete it if the resolution is captured well in the
   commit message.
3. Update `PLUGIN_GUI_HOOKS.md` if the resolution changes any of the
   load-bearing design decisions.

---

## Resolved

Picked up after the foundation commit, before the merge PR:

- **Phase 1 render slots completed.** `gui.head.scripts` (was wired in
  Functions.php but never fired in `wallet.html`),
  `gui.dashboard.before`, `gui.contacts.after`, `gui.activity.after`,
  and `gui.settings.section` are all wired in their respective tab
  partials. Plugins enqueueing head-mode JS now actually receive a
  fire site.
- **`gui.contact.actions` auto-wires `contact_address`.** `script.js`'s
  `openContactModal()` now populates every
  `.plugin-contact-action-address` input alongside the core
  block/unblock/delete pattern.
- **Hooks dev-mode tracing.** `PLUGIN_HOOKS_TRACE=1` env flag makes
  `Hooks::doRender` / `applyFilter` record every fire (kind, hook,
  listener count, errors) in a request-scoped trace buffer accessible
  via `Hooks::getTrace()` and mirrored to the logger at INFO. Costs
  zero when the flag is off.
- **CHANGELOG entry.** Landed under `[Unreleased]` in the foundation
  commit (and updated to mention the resolved items above).
- **`PLUGINS.md` reference section.** New "Extending the GUI" chapter
  documents every render slot, every filter slot, the asset enqueue
  API, the tab registry, the action tiers, the
  `.plugin-contact-action-address` auto-wire contract, and the
  `PLUGIN_HOOKS_TRACE` discoverability flag.
- **Smoke plugin.** `files/plugins/hello-eiou` v1.2.0 exercises every
  GUI registry — render hook, asset enqueue, tab register, action
  register (TIER_CSRF), `gui.dashboard.widgets` filter,
  `gui.contact.actions` filter.
- **Modal-tab `data-plugin-tab`.** Concern was moot — host emits
  plugin tab buttons with the standard `class="modal-tab"
  data-action="showModalTab"` and panels with
  `class="modal-tab-content"`, and `showModalTab()` is class-based,
  so plugin tabs already toggle without any per-plugin attribute.
- **Core actions migrated to `GuiActionRegistry`.** Every
  hardcoded `$_POST['action']` branch in `Functions.php` is gone —
  contact / transaction / payment-request / settings / API-keys /
  plugin / payback-methods / DLQ / search / loadMore / remember-me-
  revoke / What's-New all register against the same registry as
  plugin-contributed handlers. Tier choices preserve the existing
  rotating-vs-non-rotating CSRF semantics and per-handler legacy
  envelope shapes; no end-user behavior change. Two pre-existing
  unreachable handlers (`declineAllPaymentRequests`,
  `cancelAllPaymentRequests`) are now reachable via the registry
  (the GUI's "Decline All" / "Cancel All" buttons start working as
  a side-effect bug-fix). See the "Action registry" section of
  `PLUGINS.md` for the new last-write-wins override mechanic
  (plugins can now override core actions by name).
