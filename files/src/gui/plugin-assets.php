<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Plugin asset entry point.
 *
 * nginx routes every `/gui/plugin-assets/...` request here. The route
 * is intentionally tiny — it loads the autoloader, hands the request
 * to PluginAssetServer, and emits the response struct. All validation
 * + path-resolution + cache-header logic lives in the server class so
 * it is unit-testable without spinning up nginx.
 *
 * See docs/PLUGIN_GUI_HOOKS.md (Phase 6).
 */

require_once '/app/eiou/vendor/autoload.php';

$server = new \Eiou\Services\PluginAssetServer();
$response = $server->handle();

http_response_code($response['status']);
foreach ($response['headers'] as $k => $v) {
    header("{$k}: {$v}");
}
echo $response['body'];
