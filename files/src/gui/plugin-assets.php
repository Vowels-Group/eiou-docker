<?php
# Copyright 2025-2026 Vowels Group, LLC

use Eiou\Services\Plugins\PluginAssetServer;

/**
 * Plugin asset entry point.
 *
 * nginx routes every `/gui/plugin-assets/...` request here. The route
 * is intentionally tiny — it loads the autoloader, hands the request
 * to PluginAssetServer, and emits the response struct. All validation
 * + path-resolution + cache-header logic lives in the server class so
 * it is unit-testable without spinning up nginx.
 *
 * See docs/PLUGINS.md "Extending the GUI" for the asset registry API.
 */

require_once '/app/eiou/vendor/autoload.php';

$server = new PluginAssetServer();
$response = $server->handle();

http_response_code($response['status']);
foreach ($response['headers'] as $k => $v) {
    header("{$k}: {$v}");
}
echo $response['body'];
