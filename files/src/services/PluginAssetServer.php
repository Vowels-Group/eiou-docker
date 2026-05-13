<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;

/**
 * PluginAssetServer — validates + serves plugin static assets under
 * `/gui/plugin-assets/<id>/<path>`.
 *
 * Files larger than the inline-render threshold (see
 * PluginAssetRegistry) emit `<link rel="stylesheet" href="…">` /
 * `<script src="…">` tags; the URL is what nginx forwards into this
 * server.
 *
 * The server is intentionally minimal:
 *
 *   1. Parse the request path into pluginId + relPath. Reject anything
 *      that doesn't match the kebab-case id + safe-path shape — same
 *      rules PluginAssetRegistry enforces at enqueue time.
 *   2. Resolve to disk under $pluginRoot. realpath() must stay inside
 *      the plugin root after symlink resolution.
 *   3. Hash the file content. If the request carried `?v=<hash>` and
 *      it matches, send `Cache-Control: public, max-age=31536000,
 *      immutable` — so a content hash mismatch invalidates the cache
 *      automatically. Without `?v=` we send a short `max-age=60`
 *      defensive cache so a misconfigured plugin doesn't cache a
 *      mid-deploy file forever.
 *   4. ETag + If-None-Match handled before the body. Saves bandwidth
 *      on the URL-mode case where the host stamped the hash in the
 *      tag at render time.
 *
 * MIME types: derived from extension. Only css / js are accepted; the
 * URL route exists specifically for plugin CSS/JS that exceeds the
 * inline threshold. Everything else returns 415, so a plugin can't
 * smuggle .php or .html through the URL.
 *
 * The server intentionally does NOT consult Hooks or any other plugin
 * hook surface — it stays a pure file → response pipeline. Treat it
 * like the static-file route it functionally replaces, just with the
 * path-validation step PHP can do but nginx alias can't.
 *
 * See docs/PLUGINS.md "Extending the GUI".
 */
class PluginAssetServer
{
    private string $pluginRoot;

    private const ALLOWED_EXTENSIONS = [
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'mjs'  => 'application/javascript; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function __construct(string $pluginRoot = '/etc/eiou/plugins')
    {
        $this->pluginRoot = rtrim($pluginRoot, '/');
    }

    /**
     * Handle one request. Reads from $_SERVER['REQUEST_URI'] and
     * $_GET['v'] by default; injectable for tests.
     *
     * @param string|null $requestUri override (e.g. tests); defaults to REQUEST_URI
     * @param string|null $hashParam  override of `?v=`; defaults to $_GET['v']
     * @return array{status:int, headers: array<string,string>, body:string}
     *         The dispatcher emits the response; returning a struct
     *         keeps this testable without a real HTTP layer.
     */
    public function handle(?string $requestUri = null, ?string $hashParam = null): array
    {
        $uri = $requestUri ?? ($_SERVER['REQUEST_URI'] ?? '');
        $hash = $hashParam ?? (isset($_GET['v']) ? (string)$_GET['v'] : '');

        // Strip query string + leading prefix.
        $path = parse_url($uri, PHP_URL_PATH) ?? '';
        $prefix = '/gui/plugin-assets/';
        if (!str_starts_with($path, $prefix)) {
            return $this->errorResponse(404, 'not_found');
        }
        $rest = substr($path, strlen($prefix));
        $rest = ltrim($rest, '/');
        if ($rest === '') {
            return $this->errorResponse(404, 'not_found');
        }

        // Split into pluginId / relPath.
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return $this->errorResponse(404, 'not_found');
        }
        $pluginId = substr($rest, 0, $slash);
        $relPath  = substr($rest, $slash + 1);

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $pluginId)) {
            $this->log("invalid plugin id: {$pluginId}");
            return $this->errorResponse(404, 'invalid_plugin_id');
        }
        if (!$this->isSafeRelPath($relPath)) {
            $this->log("path traversal rejected: {$pluginId}/{$relPath}");
            return $this->errorResponse(404, 'invalid_path');
        }

        // MIME gate. Reject anything that isn't on the allow-list.
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_EXTENSIONS[$ext])) {
            return $this->errorResponse(415, 'unsupported_media_type');
        }

        $abs = $this->pluginRoot . '/' . $pluginId . '/' . $relPath;
        $real = realpath($abs);
        if ($real === false) {
            return $this->errorResponse(404, 'asset_not_found');
        }
        $rootReal = realpath($this->pluginRoot);
        if ($rootReal === false || !str_starts_with($real, $rootReal . '/')) {
            $this->log("asset outside plugin root: {$real}");
            return $this->errorResponse(404, 'outside_root');
        }

        $body = @file_get_contents($real);
        if ($body === false) {
            return $this->errorResponse(404, 'asset_unreadable');
        }

        $contentHash = substr(hash('sha256', $body), 0, 16);
        $headers = [
            'Content-Type' => self::ALLOWED_EXTENSIONS[$ext],
            'ETag'         => '"' . $contentHash . '"',
        ];

        // If the requester sent a hash and it matches the file, the URL
        // is content-addressed and safe to cache for a year. Otherwise
        // hold the cache short so a plugin upgrade isn't served stale.
        if ($hash !== '' && hash_equals($contentHash, $hash)) {
            $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        } else {
            $headers['Cache-Control'] = 'public, max-age=60';
        }

        // 304 short-circuit.
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch !== '' && trim($ifNoneMatch, '"') === $contentHash) {
            return [
                'status' => 304,
                'headers' => ['ETag' => $headers['ETag'], 'Cache-Control' => $headers['Cache-Control']],
                'body' => '',
            ];
        }

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * Public for tests + the registry's URL-mode renderer to compute
     * the cache-bust hash without re-implementing it.
     */
    public function computeContentHash(string $abs): ?string
    {
        $body = @file_get_contents($abs);
        if ($body === false) return null;
        return substr(hash('sha256', $body), 0, 16);
    }

    /**
     * Public so PluginAssetRegistry can validate before emitting URL.
     */
    public static function isValidPluginId(string $pluginId): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]*$/', $pluginId);
    }

    private function isSafeRelPath(string $relPath): bool
    {
        $normalized = str_replace('\\', '/', $relPath);
        if ($normalized === '' || str_starts_with($normalized, '/')) {
            return false;
        }
        foreach (explode('/', $normalized) as $seg) {
            if ($seg === '..' || $seg === '.' || $seg === '') {
                return false;
            }
        }
        return true;
    }

    /** @return array{status:int, headers: array<string,string>, body:string} */
    private function errorResponse(int $status, string $reason): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            'body' => $reason,
        ];
    }

    private function log(string $msg): void
    {
        try {
            Logger::getInstance()->warning("PluginAssetServer: {$msg}");
        } catch (\Throwable $_) {
            // Logger unavailable in tests.
        }
    }
}
