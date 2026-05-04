<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;

/**
 * TabRegistry — top-level tabs the wallet GUI renders.
 *
 * Plugins (and the host itself) register tabs here; wallet.html
 * iterates `all()` to build the desktop tab-bar, the mobile tab-bar,
 * and the tab-panel sections. Tabs are sorted by `order` (asc, ties
 * broken by registration sequence).
 *
 * Tab entry schema:
 *
 *   [
 *     'id'          => 'string',                 // required, kebab-case
 *     'label'       => 'Dashboard',              // required, desktop label
 *     'mobileLabel' => 'Home',                   // optional, defaults to label
 *     'icon'        => 'fas fa-home',            // required, FontAwesome class
 *     'order'       => 10,                       // required, lower = earlier
 *     'include'     => '/abs/path/partial.html', // optional, require_once'd in
 *                                                // wallet.html scope
 *     'render'      => callable(): string,       // optional, returns panel HTML
 *     'badge'       => int|callable(): int,      // optional, defaults to 0
 *     'badgeTitle'  => 'string',                 // optional, badge tooltip
 *   ]
 *
 * `include` and `render` are alternatives — at least one is required.
 * `include` runs the partial in wallet.html's scope (so $user,
 * $paymentRequests, etc. remain available). `render` is a closure
 * returning HTML — used when the tab body is generated dynamically
 * by a plugin without a separate template file.
 *
 * The `gui.tabs` filter (added in Phase 5) lets plugins inject /
 * remove / reorder entries without a fork of the host. The registry
 * doesn't apply that filter itself — wallet.html does, so the
 * registry stays a pure data store.
 *
 * See docs/PLUGINS.md "Extending the GUI".
 */
class TabRegistry
{
    /** @var array<int, array<string,mixed>> */
    private array $tabs = [];

    /**
     * Register a tab. Validates the required fields; logs + ignores
     * malformed entries so a buggy plugin can't take down the page.
     *
     * Calling register() twice with the same id replaces the prior
     * entry — last-write-wins. This lets a plugin override a core tab
     * (e.g. ship a richer Dashboard) without modifying the host. Use
     * carefully.
     *
     * @return bool true if the registration was accepted
     */
    public function register(array $entry): bool
    {
        if (!$this->validate($entry)) {
            return false;
        }
        // last-write-wins on id collisions
        foreach ($this->tabs as $i => $existing) {
            if ($existing['id'] === $entry['id']) {
                $this->tabs[$i] = $this->normalize($entry);
                return true;
            }
        }
        $this->tabs[] = $this->normalize($entry);
        return true;
    }

    /**
     * Return all registered tabs sorted by `order` ascending. Within
     * the same order, registration sequence is preserved (stable
     * sort).
     *
     * @return array<int, array<string,mixed>>
     */
    public function all(): array
    {
        $indexed = [];
        foreach ($this->tabs as $i => $entry) {
            $indexed[] = [$i, $entry['order'], $entry];
        }
        usort($indexed, fn($a, $b) => $a[1] <=> $b[1] ?: $a[0] <=> $b[0]);
        return array_map(fn($t) => $t[2], $indexed);
    }

    /** Look up a single tab by id; null if not registered. */
    public function find(string $id): ?array
    {
        foreach ($this->tabs as $entry) {
            if ($entry['id'] === $id) return $entry;
        }
        return null;
    }

    /**
     * Resolve an entry's badge value. Accepts int or callable; a
     * callable that throws yields 0 (so a plugin's bad badge query
     * doesn't break the page).
     */
    public static function resolveBadge(array $entry): int
    {
        $b = $entry['badge'] ?? 0;
        if (is_callable($b)) {
            try {
                $b = $b();
            } catch (\Throwable $e) {
                try { Logger::getInstance()->warning("TabRegistry: badge callback threw on '{$entry['id']}'", ['error' => $e->getMessage()]); }
                catch (\Throwable $_) {}
                return 0;
            }
        }
        return is_int($b) && $b > 0 ? $b : 0;
    }

    private function validate(array $entry): bool
    {
        $required = ['id', 'label', 'icon', 'order'];
        foreach ($required as $f) {
            if (!isset($entry[$f])) {
                $this->log("missing required field '{$f}'");
                return false;
            }
        }
        if (!is_string($entry['id']) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $entry['id'])) {
            $this->log("invalid tab id: " . var_export($entry['id'], true));
            return false;
        }
        if (!is_int($entry['order'])) {
            $this->log("tab order must be int: id={$entry['id']}");
            return false;
        }
        if (empty($entry['include']) && empty($entry['render'])) {
            $this->log("tab '{$entry['id']}' has neither include nor render");
            return false;
        }
        if (!empty($entry['render']) && !is_callable($entry['render'])) {
            $this->log("tab '{$entry['id']}' render is not callable");
            return false;
        }
        return true;
    }

    private function normalize(array $entry): array
    {
        $entry['mobileLabel'] = $entry['mobileLabel'] ?? $entry['label'];
        $entry['badge']       = $entry['badge']       ?? 0;
        $entry['badgeTitle']  = $entry['badgeTitle']  ?? '';
        return $entry;
    }

    private function log(string $msg): void
    {
        try {
            Logger::getInstance()->warning("TabRegistry: {$msg}");
        } catch (\Throwable $_) {}
    }
}
