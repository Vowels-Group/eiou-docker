# Changelog — hello-eiou

All notable changes to the `hello-eiou` plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this plugin follows [Semantic Versioning](https://semver.org/).

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
