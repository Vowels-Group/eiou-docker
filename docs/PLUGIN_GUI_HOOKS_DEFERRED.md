# Plugin GUI Hooks — Deferred Work

Companion to `PLUGIN_GUI_HOOKS.md`. Tracks scope intentionally trimmed
out of the initial six-phase implementation so reviewers (and future
maintainers) know what is *known to be missing* versus *unconsidered*.

Every entry below was a conscious cut: either not load-bearing for the
core "plugins can extend the GUI" goal, or carrying enough regression
risk that bundling it into the foundation PR was the wrong trade-off.
Each item names the phase it belongs to and a sketch of the work.

---

## Phase 4 — Action registry

### Existing core actions are not migrated

The design doc proposed migrating every hardcoded action in
`Functions.php:53-102` (and the longer AJAX-only list down to ~line
500) into `GuiActionRegistry` entries owned by the existing
controllers. **Status: not done.**

That migration would touch ~1500 lines spread across
`ContactController`, `TransactionController`, `PaymentRequestController`,
`SettingsController`, `ApiKeysController`, `PluginController`, plus
the `Functions.php` whitelist itself. Each action has its own response
shape (HTML redirect vs. JSON-and-exit vs. mixed), its own CSRF /
sensitive-access pattern, and its own error-handling style. Migrating
them risks breaking auth flows, payment forms, and contact edits — for
zero behavior change.

The registry's primary value (plugins adding new POST handlers) is
already delivered. Migration of core actions can land incrementally,
one controller at a time, in follow-up PRs that can be reviewed and
verified per-controller.

**To resume:** pick the smallest controller (likely `SettingsController`
or `PaymentRequestController`), register every action it owns in its
`register()` method with the right tier, replace its branch of the
`Functions.php` whitelist with `$registry->has()` short-circuit, smoke
the existing flow, ship it, repeat.

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
- Define a `data-plugin-tab="<id>"` attribute the host's existing
  modal-tab JS already understands so plugin tabs participate in the
  showModalTab / hide-others routine without extra wiring.

The third bullet is the only one that's actually load-bearing — without
it, plugin modal tabs may not toggle correctly in all browsers. Test
in production-mode before promoting beyond design-partner plugins.

### `gui.contact.actions` does not auto-wire `contact_address`

Buttons rendered from `gui.contact.actions` get a hidden
`<input name="contact_address">` but the form is *not* populated by
the JS that populates the existing settings-tab forms. A plugin author
has to either:
- Subscribe to a (not-yet-existing) `openContactModal` JS event, or
- Read `data-modal-contact` themselves on submit.

**To resolve:** wire `script.js`'s `openContactModal` handler to also
populate any `.plugin-contact-action-address` input. Single-line
change; deferred only because it's outside the PHP layer this PR
focused on.

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

### Hooks introspection / dev-mode tracing

The design doc mentioned a `dev`-mode "Hooks Inspector" that logs
every fired hook so plugin authors can discover them at runtime.
**Status: not built.**

`Hooks::listRenderHooks()` and `listFilterHooks()` exist (return
listener counts per hook), but there's no fire-time trace, no
exposed-via-debug-page surface, no docs describing how to use them.

**To resume:** add an opt-in env flag (`PLUGIN_HOOKS_TRACE=1`) the
Hooks class checks at fire time and writes to a request-scoped log.
~30 LOC; matters once we have more than two plugins in the wild.

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

### CHANGELOG entry

The plugin-GUI-hooks branch hasn't yet landed an entry under
`[Unreleased]`. **Status: pending the merge PR.** Should describe
the four new services (`Hooks`, `TabRegistry`, `PluginAssetRegistry`,
`GuiActionRegistry`, `PluginAssetServer`) plus the asset URL route,
filter-slot list, and CSP-nonce stamping.

### `PLUGINS.md` reference section

The design doc pointed at `PLUGINS.md` for the canonical hook
reference. **Status: design-doc-only.** `PLUGINS.md` has not been
updated to list each render slot, each filter slot, the action-tier
contract, or the asset enqueue API.

This is the single highest-leverage doc-debt item, since plugin
authors can't discover the API without it. Folding it into the merge
PR is cheap.

### Smoke plugin

The phases referenced a "hello-eiou" example plugin that exercises
each new surface. **Status: shipped in this branch** as
`files/plugins/hello-eiou` v1.2.0. Demonstrates every GUI registry:

- `gui.dashboard.after` render hook → fortune widget
- `PluginAssetRegistry::enqueueStyle` → `assets/styles.css`
- `TabRegistry::register` → "Fortunes" top-level tab
- `GuiActionRegistry::register('helloEiouFortune', TIER_CSRF)` → JSON
- `gui.dashboard.widgets` + `gui.contact.actions` filters

The plugin is unsigned in this branch — the manifest doesn't yet
carry a signature block. Operators running with
`PLUGIN_SIGNATURE_MODE=enforce` need to sign or move it to a trusted
keys directory. Out of scope for the foundation PR.

---

## How to use this doc

When a deferred item gets picked up:
1. Open a tracking issue / PR referencing the section.
2. Move that section here to a `## Resolved` block at the bottom with
   the PR link, OR delete it if the resolution is captured well in the
   commit message.
3. Update `PLUGIN_GUI_HOOKS.md` if the resolution changes any of the
   load-bearing design decisions.
