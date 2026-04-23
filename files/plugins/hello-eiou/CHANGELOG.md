# Changelog — hello-eiou

All notable changes to the `hello-eiou` plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this plugin follows [Semantic Versioning](https://semver.org/).

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
