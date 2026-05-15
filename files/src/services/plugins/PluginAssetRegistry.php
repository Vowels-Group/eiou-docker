<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Utils\Logger;

/**
 * PluginAssetRegistry — plugins enqueue CSS / JS files; host renders
 * them inline with the page's CSP nonce.
 *
 * Two enqueue methods:
 *   enqueueStyle($pluginId, $relPath, $opts = [])   — emits in <head>
 *   enqueueScript($pluginId, $relPath, $opts = [])  — emits in <head> if
 *                                                     opts['head'] is
 *                                                     true, otherwise
 *                                                     end of <body>
 *
 * Path resolution: $relPath is joined to the plugin root
 * (default /etc/eiou/plugins/<pluginId>/$relPath). Path-traversal
 * attempts (..) are rejected at enqueue time. Missing files are
 * silently dropped + logged at render time so a renamed asset doesn't
 * crash the host page.
 *
 * Render mode is "inline" for v1: the file is read at render time and
 * embedded in <style nonce> / <script nonce> blocks. A static-asset
 * route + content-hash cache-bust for files larger than a threshold is
 * a planned follow-up; the registry's API stays the same.
 *
 * The registry doesn't fire hooks itself — Application boot (or the
 * GUI's Functions.php) wires the host render listeners that drain the
 * registry into the hook fires. That keeps the registry pure data and
 * lets tests exercise it without a Hooks instance.
 *
 * See docs/PLUGINS.md "Extending the GUI" for design rationale.
 */
class PluginAssetRegistry
{
    /**
     * Files larger than this byte threshold render as a `<link>` /
     * `<script src>` tag pointing at /gui/plugin-assets/<id>/<path>
     * with a content-hash cache-bust. Smaller files inline directly
     * with a CSP nonce so a single round-trip carries them.
     *
     * 4 KiB matches the smallest practical TCP-segment payload — below
     * that, an extra request is more expensive than the bytes saved.
     * The threshold is tweakable per-call via opts['inline'].
     */
    public const URL_MODE_THRESHOLD = 4096;

    /** @var array<int, array{pluginId:string,relPath:string,opts:array}> */
    private array $styles = [];

    /** @var array<int, array{pluginId:string,relPath:string,opts:array}> */
    private array $scripts = [];

    private string $pluginRoot;

    public function __construct(string $pluginRoot = '/etc/eiou/plugins')
    {
        $this->pluginRoot = rtrim($pluginRoot, '/');
    }

    /**
     * Register a CSS file. Renders inline as
     * <style nonce="…">…contents…</style> in <head>.
     *
     * Options:
     *   priority — int (default 10, lower = earlier)
     *
     * @return bool true if the registration was accepted
     */
    public function enqueueStyle(string $pluginId, string $relPath, array $opts = []): bool
    {
        if (!$this->validatePluginIdAndPath($pluginId, $relPath)) {
            return false;
        }
        $this->styles[] = [
            'pluginId' => $pluginId,
            'relPath'  => $relPath,
            'opts'     => $opts,
        ];
        return true;
    }

    /**
     * Register a JS file. Renders inline as
     * <script nonce="…">…contents…</script>.
     *
     * Options:
     *   priority — int (default 10)
     *   head     — bool, true to render in <head>, false (default) for
     *              end of <body>. Most plugin JS should default to body
     *              so it runs after the DOM has parsed.
     *
     * @return bool true if the registration was accepted
     */
    public function enqueueScript(string $pluginId, string $relPath, array $opts = []): bool
    {
        if (!$this->validatePluginIdAndPath($pluginId, $relPath)) {
            return false;
        }
        $this->scripts[] = [
            'pluginId' => $pluginId,
            'relPath'  => $relPath,
            'opts'     => $opts,
        ];
        return true;
    }

    /**
     * Build the inline-styles HTML block. Called by the host render
     * listener wired against the gui.head.styles hook.
     *
     * @param string $nonce CSP nonce stamped onto every emitted tag
     * @return string Concatenated <style> tags (may be empty)
     */
    public function renderStyles(string $nonce): string
    {
        return $this->renderEntries($this->styles, $nonce, 'style');
    }

    /**
     * Build the inline-scripts HTML block for one of the script slots.
     *
     * @param string $nonce
     * @param bool   $head true → only entries with opts['head']=true.
     *                     false → only entries without head=true.
     * @return string
     */
    public function renderScripts(string $nonce, bool $head): string
    {
        $filtered = array_filter($this->scripts, function ($e) use ($head) {
            $entryHead = !empty($e['opts']['head']);
            return $entryHead === $head;
        });
        return $this->renderEntries(array_values($filtered), $nonce, 'script');
    }

    /**
     * @return array<int, array{pluginId:string,relPath:string,opts:array}>
     */
    public function listStyles(): array { return $this->styles; }

    /**
     * @return array<int, array{pluginId:string,relPath:string,opts:array}>
     */
    public function listScripts(): array { return $this->scripts; }

    /**
     * Validate plugin id + path. Plugin id must be a kebab-case slug
     * (matches plugin.json schema); rel path must not contain ..
     * segments. We *don't* require the file to exist at enqueue time
     * — bootstrap order means plugins typically enqueue before the
     * file is known to be present in the runtime sandbox; missing
     * files are caught + skipped at render time.
     */
    private function validatePluginIdAndPath(string $pluginId, string $relPath): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $pluginId)) {
            $this->log("invalid plugin id: {$pluginId}");
            return false;
        }
        // Reject empty, leading slash, or any '..' segment. Treat
        // backslashes as separators too — defends against the rare
        // operator running on Windows-style filesystems.
        $normalized = str_replace('\\', '/', $relPath);
        if ($normalized === '' || str_starts_with($normalized, '/')) {
            $this->log("invalid asset path (empty / leading slash): {$relPath}");
            return false;
        }
        $segments = explode('/', $normalized);
        foreach ($segments as $seg) {
            if ($seg === '..' || $seg === '.') {
                $this->log("path traversal rejected: {$pluginId}/{$relPath}");
                return false;
            }
        }
        return true;
    }

    /**
     * Sort entries by opts.priority (asc, default 10) preserving
     * within-priority registration order, then read each file from
     * disk and emit a single tag per entry.
     *
     * @param array  $entries
     * @param string $nonce
     * @param string $tag    'style' or 'script'
     */
    private function renderEntries(array $entries, string $nonce, string $tag): string
    {
        if (empty($entries)) return '';

        // Stable-sort by priority. We keep the relative order of
        // entries with equal priority by tagging each with its
        // original index.
        $indexed = [];
        foreach ($entries as $i => $e) {
            $indexed[] = [$i, $e['opts']['priority'] ?? 10, $e];
        }
        usort($indexed, function ($a, $b) {
            return $a[1] <=> $b[1] ?: $a[0] <=> $b[0];
        });

        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';
        $out = '';
        foreach ($indexed as [, , $entry]) {
            $abs = $this->pluginRoot . '/' . $entry['pluginId'] . '/' . $entry['relPath'];
            // Re-validate the resolved path doesn't escape the plugin
            // root after symlink resolution. realpath() returns false
            // when the file doesn't exist; treat that as a silent skip
            // since plugins may enqueue assets they later install.
            $real = realpath($abs);
            if ($real === false) {
                $this->log("asset missing at render: {$abs}");
                continue;
            }
            $rootReal = realpath($this->pluginRoot);
            if ($rootReal === false || !str_starts_with($real, $rootReal . '/')) {
                $this->log("asset outside plugin root: {$real}");
                continue;
            }
            $body = @file_get_contents($real);
            if ($body === false) {
                $this->log("asset unreadable: {$real}");
                continue;
            }

            // Decide inline vs URL mode. Threshold-based by default;
            // opts['inline'] lets a plugin force one mode (e.g. tiny
            // bootstrap CSS that should always inline to avoid FOUC).
            $forceInline = !empty($entry['opts']['inline']);
            $forceUrl    = isset($entry['opts']['inline']) && $entry['opts']['inline'] === false;
            $useUrl = $forceUrl || (!$forceInline && strlen($body) > self::URL_MODE_THRESHOLD);

            if ($useUrl) {
                // Content-hash cache-bust: the same hash the asset
                // server computes, so a content change invalidates
                // the cache without operator intervention.
                $hash = substr(hash('sha256', $body), 0, 16);
                $url = '/gui/plugin-assets/'
                    . rawurlencode($entry['pluginId'])
                    . '/' . implode('/', array_map('rawurlencode', explode('/', $entry['relPath'])))
                    . '?v=' . $hash;
                $urlEscaped = htmlspecialchars($url, ENT_QUOTES);
                if ($tag === 'style') {
                    $out .= "<link rel=\"stylesheet\" href=\"{$urlEscaped}\"{$nonceAttr}>";
                } else {
                    $out .= "<script src=\"{$urlEscaped}\"{$nonceAttr}></script>";
                }
                continue;
            }

            // Source-marker comment so operators can spot which
            // plugin contributed which block in browser dev-tools.
            $source = '/* ' . htmlspecialchars($entry['pluginId'] . '/' . $entry['relPath'], ENT_QUOTES) . ' */';
            $sep = $tag === 'style' ? "\n" : ";\n";
            $out .= "<{$tag}{$nonceAttr}>{$source}{$sep}{$body}</{$tag}>";
        }
        return $out;
    }

    private function log(string $msg): void
    {
        try {
            Logger::getInstance()->warning("PluginAssetRegistry: {$msg}");
        } catch (\Throwable $_) {
            // Logger unavailable in tests — swallow.
        }
    }
}
