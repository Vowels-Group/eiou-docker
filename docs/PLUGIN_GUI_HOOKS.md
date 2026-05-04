# Plugin GUI Hooks — Design

A WordPress-style hook system for the eIOU GUI so plugins can inject HTML, register CSS/JS, add tabs, register form actions, and filter data the host renders, without modifying core templates.

This document is the spec the implementation phases follow. It captures intent + API + risks. Code-level rationale lives in the source comments.

## Table of Contents

1. [Goals](#goals)
2. [Foundation already in place](#foundation-already-in-place)
3. [Two primitives](#two-primitives)
4. [Asset enqueueing](#asset-enqueueing)
5. [Tab registry](#tab-registry)
6. [Action registry](#action-registry)
7. [Concrete render slots — Phase 1](#concrete-render-slots--phase-1)
8. [Implementation phases](#implementation-phases)
9. [Risks & open decisions](#risks--open-decisions)
10. [Out of scope (this design)](#out-of-scope-this-design)
11. [Migration checklist (per phase)](#migration-checklist-per-phase)
12. [Reference: example "hello-eiou" plugin (final-state target)](#reference-example-hello-eiou-plugin-final-state-target)

---

## Goals

1. **Plugins can render their own GUI** — inject sections, widgets, modal tabs, table rows.
2. **Plugins can ship their own CSS / JS** — host enqueues them in the right order with the right CSP nonce.
3. **Plugins can add top-level tabs** — without modifying `wallet.html`.
4. **Plugins can register form actions** — without modifying the `Functions.php` whitelist.
5. **Plugins can transform host data before render** — add menu items, filter currency lists, decorate row HTML.

Non-goals (for now): plugin-to-plugin communication, GUI-only (no PHP) plugins, runtime hot-reload.

---

## Foundation already in place

| Capability | Where | Status |
|---|---|---|
| Plugin discovery / lifecycle (`discover` → `register` → `boot`) | `files/src/services/PluginLoader.php` | Solid |
| `PluginInterface` (`getName / getVersion / register / boot`) | `files/src/contracts/PluginInterface.php` | Solid |
| Plugin manifest (`plugin.json`) — name, version, entry class, PSR-4, optional db isolation | `docs/PLUGINS.md` | Solid |
| Per-plugin MySQL user + table-name isolation | `PluginDbUserService` + `PluginPdoFactory` | Solid |
| Event dispatcher (`subscribe` / `dispatch`) — sync, exception-safe | `files/src/events/EventDispatcher.php` | Solid |
| Plugin uninstall flow | `PluginUninstallService` | Solid |
| Plugin signing (Ed25519 + trusted-keys directories) | (CHANGELOG entry) | Solid |
| CSP-nonce template helper | `cspNonce()` in `TemplateHelpers.php` | Solid |

What's **missing**: render-side surface area — the GUI is an opaque stack of `require_once` calls into hardcoded template partials (`wallet.html`), with hardcoded tabs and a hardcoded action whitelist. Plugins have no place to plug in.

---

## Two primitives

The whole design rides on two complementary primitives, both registered against named hook keys.

### 1. Render hooks — fire-and-collect

Used where the host wants to invite plugins to inject HTML at a specific point.

```php
// Plugin (boot()):
$container->getHooks()->onRender('gui.dashboard.after', function (array $ctx): string {
    return '<section class="my-plugin-widget">…</section>';
}, 20);

// Host (wallet.html):
<?= $hooks->doRender('gui.dashboard.after', ['user' => $user]) ?>
```

`doRender(string $name, array $context = []): string` calls every listener (in priority order, ascending — lower runs first), concatenates their string returns, and yields the final HTML. No-op if no listeners.

### 2. Filter hooks — value-pipeline

Used where the host wants to invite plugins to transform a value before render.

```php
// Plugin (boot()):
$container->getHooks()->onFilter('gui.contact.actions', function (array $actions, array $ctx): array {
    $actions[] = ['label' => 'Bookmark', 'icon' => 'fas fa-star', 'action' => 'myPluginBookmark'];
    return $actions;
});

// Host (controller / template):
$actions = $hooks->applyFilter('gui.contact.actions', $defaultActions, ['contact' => $c]);
```

`applyFilter(string $name, $value, array $context = []): mixed` chains the value through each listener; each returns the (possibly modified) value to pass to the next. The final return is what the host uses.

### Why two primitives, not unified

- Render hooks always return strings, are always concatenated. Filter hooks return arbitrary values; concatenation makes no sense.
- Different mental models for plugin authors.
- WordPress made the same split (`do_action` / `apply_filters`) and the convention is well-understood.

### Why a new service, not the existing EventDispatcher

- `EventDispatcher` is for fire-and-forget side effects (audit log, sync trigger). Listener return values are ignored.
- Hooks need return values (HTML strings or filtered values) to be useful.
- Mixing them would surprise existing event subscribers and complicate the dispatch contract.

### Priority + ordering

Each `onRender` / `onFilter` registration takes an optional `int $priority` (default 10, mirroring WordPress). Lower priority runs first. Stable ordering within a priority follows registration order.

```php
$hooks->onRender('gui.dashboard.after', $cb, 5);   // runs first
$hooks->onRender('gui.dashboard.after', $cb, 10);  // runs second
```

### Lazy evaluation

`doRender` / `applyFilter` are O(1) when no listener is registered for a hook (early return on empty listener-list lookup). This matters because we'll pepper `wallet.html` with hook fires that most plugins won't subscribe to.

### Exception safety

A listener that throws gets logged + skipped. The remaining listeners still run; the host gets back whatever was accumulated up to that point. Symmetric with `EventDispatcher`'s behavior.

---

## Asset enqueueing

Plugins can register CSS / JS assets during `boot()`:

```php
// Plugin:
$assets = $container->getAssetRegistry();
$assets->enqueueStyle('my-plugin', 'assets/styles.css');
$assets->enqueueScript('my-plugin', 'assets/main.js', ['defer' => true]);
```

The registry stores `(plugin-id, relative-path, options)` tuples. The host renders them in three slots:

| Slot | What ships | When |
|---|---|---|
| `gui.head.styles` | All registered `<link rel="stylesheet">` tags | Before main page CSS |
| `gui.head.scripts` | All registered `<script>` tags with explicit `defer` or in `<head>` | After main page CSS |
| `gui.footer.scripts` | All registered `<script>` tags without `defer` | End of `<body>` |

Asset paths resolve to `/gui/plugin-assets/<plugin-id>/<relative-path>`. nginx serves these via a new location block that:

1. Rejects requests where `<plugin-id>` doesn't match a registered plugin.
2. Resolves `<relative-path>` against `/etc/eiou/plugins/<plugin-id>/` and rejects path-traversal attempts.
3. Sets long cache headers (the path includes a content hash, so cache-bust on change).

For first iteration we'll inline plugin CSS / JS into the host page (no nginx changes) and switch to the static-route variant in Phase 2 once the plumbing is proven. Inline is simpler, fewer moving parts, fine for KB-sized plugin assets.

### CSP nonce

Each `<script>` and inline `<style>` tag the host emits gets the page's `cspNonce()`. The asset registry stamps the nonce automatically when rendering — plugin authors don't think about it.

### Asset CSS isolation convention

The host doesn't sandbox plugin CSS. Plugins are expected to namespace their selectors:

```css
.plugin-my-plugin .widget-title { … }
```

Or use Web Components / Shadow DOM. We'll document this convention in `PLUGINS.md`. Future iteration could auto-prefix plugin CSS with the plugin ID via a build-time sass-like transform; out of scope for v1.

---

## Tab registry

Plugins register top-level tabs:

```php
// Plugin:
$container->getTabRegistry()->register([
    'id'     => 'my-plugin',
    'label'  => 'My Plugin',
    'icon'   => 'fas fa-star',
    'order'  => 50,                       // < 100 = before settings
    'render' => [$this, 'renderMyTab'],   // returns HTML for the tab panel
]);
```

`wallet.html` iterates the registry to build both the nav-bar buttons and the tab panels. The five core tabs (Dashboard / Payment / Contacts / Activity / Settings) become entries in this registry too — in `Application::boot()` core registers them just like a plugin would.

Active-tab JS (`switchTab`) is unchanged; tabs are still vanilla `data-action="switchTab" data-tab="<id>"` buttons, the registry just generates them.

Plugin tabs that need their own per-tab routes / sub-content can call `doRender('gui.tab.<id>.before/after')` from inside their render callback. Composability is plugin-authored, not host-orchestrated.

---

## Action registry

Replaces `Functions.php:53-102` whitelist:

```php
// Today (hardcoded):
if (in_array($action, ['addContact', 'acceptContact', …, 'applyContactDecisions', …])) {
    $contactController->routeAction();
}
```

```php
// New (registry):
$registry = $container->getActionRegistry();
if ($registry->has($action)) {
    $registry->dispatch($action);  // CSRF + auth checks done by registry
}
```

Core action handlers register themselves in `register()`/`boot()`:

```php
// Core (ContactController.php register / boot):
$registry->register('addContact',           [$this, 'handleAddContact']);
$registry->register('applyContactDecisions',[$this, 'handleApplyContactDecisions']);
…
```

Plugins do the same:

```php
// Plugin:
$registry->register('myPluginBookmark', [$this, 'handleBookmark']);
```

The registry handler signature is `function (array $request): void`. CSRF verification, auth check, and the existing `MessageHelper::redirectMessage` flow stay unchanged — just lifted into the registry's `dispatch()` so every handler benefits without copying code.

Registry knows about a permission tier (`'auth'`, `'csrf'`, `'sensitive'`) declared at registration time, so high-risk actions (delete a contact, reveal an API key) require the existing sensitive-access grant automatically.

---

## Concrete render slots — Phase 1

Conservative starter set. Add more as plugin demand surfaces, *not* as design speculation.

| Hook | Type | Where | Use case |
|---|---|---|---|
| `gui.head.styles` | render | wallet `<head>` | Plugin CSS |
| `gui.head.scripts` | render | wallet `<head>` | Plugin JS |
| `gui.footer.scripts` | render | end of wallet `<body>` | Plugin JS late init |
| `gui.dashboard.before` | render | dashboard tab top | Hero widget |
| `gui.dashboard.after` | render | dashboard tab bottom | Side widget |
| `gui.contacts.after` | render | contacts tab bottom | Bulk-action panel |
| `gui.activity.after` | render | activity tab bottom | Custom analytics |
| `gui.settings.section` | render | settings tab | Settings panel |
| `gui.tabs` | filter | tab nav | Add / hide / reorder tabs |
| `gui.contact.actions` | filter | per-contact action column | Add row actions |
| `gui.contact_modal.tabs` | filter | contact-detail modal | Add inner tab |

Naming convention: `gui.<area>.<position>` for render slots, `gui.<area>` for filters that mutate a list.

---

## Implementation phases

Each phase is independently shippable + reviewable. After each phase: run unit tests, build a tiny example plugin that exercises the new surface, and verify on running containers before moving to the next phase.

### Phase 1 — Hooks registry + minimal render slots (foundation)

- New `files/src/services/Hooks.php` with `onRender` / `onFilter` / `doRender` / `applyFilter`.
- Wire into `ServiceContainer::getHooks()`.
- Three hook fires in `wallet.html`: `gui.head.styles`, `gui.dashboard.after`, `gui.footer.scripts`. Just enough to prove the round-trip.
- Unit tests for the registry (priority ordering, exception safety, lazy O(1) on empty, filter pipeline order, render concatenation).
- Smoke: a hello-world plugin that registers a render listener, hot-loaded into Alice + Bob, dashboard shows the plugin's widget.

**~250 LOC.** Zero behavior change for existing GUI.

### Phase 2 — Asset registry + CSP-nonce stamping + remaining slots

- New `files/src/services/PluginAssetRegistry.php` with `enqueueStyle` / `enqueueScript`.
- Inline-render mode (read file from disk, embed in `<style>` / `<script>` with nonce). Static-route mode deferred to Phase 6.
- All Phase-1 slots from the table above wired into `wallet.html`.
- Unit tests: registry ordering, nonce stamping, file-not-found = silent skip + log.
- Smoke: hello-world plugin gains its own CSS that styles its dashboard widget.

**~400 LOC.**

### Phase 3 — Tab registry

- New `files/src/services/TabRegistry.php`.
- Migrate the 5 core tabs into registry entries (registered in `Application::boot()` so they always exist).
- `wallet.html` iterates the registry instead of hardcoding the nav.
- Unit tests: ordering, hide via filter, plugin tab insertion.
- Smoke: hello-world plugin adds a 6th tab "Plugin Demo" between Activity and Settings.

**~300 LOC.** Touches `wallet.html` significantly — careful diff review.

### Phase 4 — Action registry

- New `files/src/services/GuiActionRegistry.php`.
- Migrate every existing hardcoded action in `Functions.php:53-102` into registry entries (in each controller's `register()`).
- Replace the whitelist with `$registry->has() + dispatch()`.
- Unit tests: handler dispatch, CSRF gate, sensitive-access gate, permission tiers, unknown action 404 path.
- Smoke: hello-world plugin adds a `myPluginPing` action; clicking the plugin's button posts the form, redirects with toast.

**~200 LOC.** Touches all controllers — non-trivial migration.

### Phase 5 — Filter slots + per-row + per-modal hooks

- Add `gui.contact.actions`, `gui.contact_modal.tabs`, `gui.tabs`, `gui.dashboard.widgets` to relevant render paths.
- Document each in `PLUGINS.md`.
- Unit tests for the filter chain.
- Smoke: hello-world plugin adds a "Notes" tab to the contact-detail modal.

**~150 LOC** + docs.

### Phase 6 — Static-asset route + hash-based cache busting

- nginx config: `location /gui/plugin-assets/<id>/<path>` proxies to PHP route that validates + serves.
- Asset registry switches from inline-mode to URL-mode for files larger than ~4KB.
- Cache headers + content-hash querystring.

**~250 LOC** + nginx + Dockerfile.

### Later phases (open-ended)

- Per-plugin event-namespacing in EventDispatcher so plugins can fire their own events.
- Plugin admin pages (registered via tab registry but with sub-routing).
- A `dev`-mode "Hooks Inspector" that logs every fired hook so plugin authors can discover them at runtime.
- Auto-prefix plugin CSS with `.plugin-<id>` at boot.

---

## Risks & open decisions

### 1. HTML safety of plugin output

Plugins return raw HTML strings from render hooks. Three trust models:

- **Trust plugins** (WordPress model). Operator vets plugins before installing. We have plugin signing + trusted-keys; operators can require signed plugins. **Recommended starting point.**
- **HTMLPurifier** allow-listing. Sanitizes plugin output. ~10ms per render, no flexibility for novel markup. Can layer on later.
- **Iframe-sandbox per plugin section.** Strongest isolation, breaks DOM interaction with host. Reserved for high-distrust scenarios.

We start with **trust + plugin signing**. Document the threat model clearly in `PLUGINS.md`.

### 2. CSS isolation

A plugin's `body { background: red }` would break the host. Convention: namespace selectors with `.plugin-<id>`. Documented in `PLUGINS.md`. Future: build-time auto-prefix.

### 3. Hook contract versioning

Hook names + payloads form an API. Breaking changes need a deprecation cycle. Each new hook fire site documents `@since <semver>`. Plugin manifest can declare `requires_host_version` so an outdated plugin fails clearly instead of misbehaving silently.

### 4. Discoverability for plugin authors

How does a plugin author know which hooks exist? Two complementary surfaces:

- Hand-maintained reference in `PLUGINS.md` (canonical).
- Runtime introspection: `Hooks::list()` returns all hooks fired so far in the request. A `dev` mode logs every fire to the debug log so plugin authors can grep.

### 5. Plugin ordering across hooks

Two plugins both fire on `gui.dashboard.after`. Whose widget renders first? Priority int (default 10) decides. Documented as part of hook contract.

### 6. Backward compatibility

The five core tabs become registered entries. Anything that hardcoded a tab ID (CSS rules, JS handlers, integration tests) keeps working — IDs are unchanged. Migration is internal restructuring only.

### 7. Performance budget

Per-request: each hook fire is one associative-array lookup + foreach. With 30 hook fires per page and 5 plugins each subscribing to 3 hooks, that's 30 lookups + 15 callable invocations. Negligible (microseconds). Empty-hook fast path keeps it at array-lookup-only when no plugin subscribes.

---

## Out of scope (this design)

- Server-side caching of plugin output (premature; profile first).
- Plugin GUI hot-reload during development (operator manually `eiou restart`s).
- A plugin marketplace / discovery UI (plugin discovery is operator-managed today; same model).
- React / Vue plugin SPAs (plugins ship vanilla JS like the host; no transpile pipeline in the host).
- Cross-plugin pubsub channels (use the existing EventDispatcher for that — it's already built).

---

## Migration checklist (per phase)

After each phase, before merging:

1. `php files/vendor/bin/phpunit --configuration tests/phpunit.xml.dist` — full suite green.
2. Lint sweep: `php -l` on every touched PHP file, JS parse-check via Node.
3. Hot-load + smoke: rebuild example plugin, hot-load into Alice + Bob, verify the new surface works in the browser.
4. CHANGELOG entry under `[Unreleased]`.
5. Per-phase docs added to `PLUGINS.md`.

---

## Reference: example "hello-eiou" plugin (final-state target)

```php
<?php
namespace HelloEiou;

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;

class HelloEiouPlugin implements PluginInterface
{
    public function getName(): string { return 'hello-eiou'; }
    public function getVersion(): string { return '0.1.0'; }

    public function register(ServiceContainer $container): void {}

    public function boot(ServiceContainer $container): void
    {
        $hooks  = $container->getHooks();
        $assets = $container->getAssetRegistry();
        $tabs   = $container->getTabRegistry();
        $actions = $container->getActionRegistry();

        // Phase 2 — own CSS / JS
        $assets->enqueueStyle('hello-eiou', 'assets/styles.css');
        $assets->enqueueScript('hello-eiou', 'assets/main.js', ['defer' => true]);

        // Phase 1 — render a dashboard widget
        $hooks->onRender('gui.dashboard.after', function () {
            return '<section class="plugin-hello-eiou widget"><h3>Hello eIOU</h3></section>';
        });

        // Phase 5 — add a "Notes" tab to contact-detail modal
        $hooks->onFilter('gui.contact_modal.tabs', function (array $tabs, array $ctx) {
            $tabs[] = ['id' => 'hello-notes', 'label' => 'Notes', 'icon' => 'fa-sticky-note',
                       'render' => fn() => '<textarea>…</textarea>'];
            return $tabs;
        });

        // Phase 3 — add a top-level tab
        $tabs->register([
            'id' => 'hello-eiou', 'label' => 'Hello', 'icon' => 'fa-smile',
            'order' => 50,
            'render' => fn() => '<div class="plugin-hello-eiou">Hello world.</div>',
        ]);

        // Phase 4 — add a form action
        $actions->register('helloEiouPing', [$this, 'handlePing']);
    }

    public function handlePing(array $request): void
    {
        // CSRF + auth already checked by the registry.
        \Eiou\Gui\Helpers\MessageHelper::redirectMessage('Pong from hello-eiou!', 'success');
    }
}
```

That's the target state. Each phase brings one piece of this online.
