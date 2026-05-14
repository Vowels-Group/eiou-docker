<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Throwable;

/**
 * PluginsTabPanelRegistry — sub-panels inside the host's Plugins tab.
 *
 * Replaces the per-plugin top-level tab pattern: instead of every
 * plugin pushing its own entry into TabRegistry, plugins register a
 * single panel here and the host owns one Plugins tab in the
 * top-level nav. Operators see a dropdown of installed plugins at
 * the top of the Plugins tab and the selected plugin's body below.
 *
 * Why a dedicated registry and not a TabRegistry sub-mechanism: the
 * top-level tab list is the operator's primary navigation surface
 * and stays small + stable; plugin-supplied panels are an unbounded
 * set whose shape and ordering are policy-controlled by the host
 * (alphabetical / stable / empty-state). Keeping the two registries
 * separate lets the constraints diverge as the surfaces evolve.
 *
 * Entry schema:
 *
 *   [
 *     'plugin_id' => 'string',                  // required, source-of-truth id
 *     'label'     => 'Hello eIOU',              // required, dropdown text
 *     'icon'      => 'fas fa-puzzle-piece',     // optional, defaults to puzzle piece
 *     'order'     => 100,                       // optional, lower = earlier
 *     'render'    => callable(): string,        // required, returns panel HTML
 *   ]
 *
 * Last-write-wins on plugin_id collisions — same shape as
 * TabRegistry::register so a plugin reloading mid-request doesn't
 * accumulate duplicate entries.
 */
class PluginsTabPanelRegistry
{
    /** @var array<int, array<string,mixed>> */
    private array $panels = [];

    /**
     * Register a panel. Logs + ignores malformed entries so a buggy
     * plugin doesn't take down the page.
     *
     * @return bool true if the registration was accepted
     */
    public function register(array $entry): bool
    {
        if (!$this->validate($entry)) {
            return false;
        }
        $normalised = $this->normalize($entry);
        foreach ($this->panels as $i => $existing) {
            if ($existing['plugin_id'] === $normalised['plugin_id']) {
                $this->panels[$i] = $normalised;
                return true;
            }
        }
        $this->panels[] = $normalised;
        return true;
    }

    /**
     * Return all registered panels sorted by order asc, then by
     * plugin_id asc for stable ties. Stable ordering matters because
     * the dropdown's "first" entry is the default selection when the
     * operator opens the Plugins tab for the first time.
     *
     * @return array<int, array<string,mixed>>
     */
    public function all(): array
    {
        $sorted = $this->panels;
        usort($sorted, function (array $a, array $b): int {
            return $a['order'] <=> $b['order']
                ?: strcmp($a['plugin_id'], $b['plugin_id']);
        });
        return $sorted;
    }

    /** Look up a single panel by plugin_id; null if not registered. */
    public function find(string $pluginId): ?array
    {
        foreach ($this->panels as $entry) {
            if ($entry['plugin_id'] === $pluginId) {
                return $entry;
            }
        }
        return null;
    }

    /** True when no plugins have registered a panel. */
    public function isEmpty(): bool
    {
        return $this->panels === [];
    }

    /**
     * Invoke the panel's render closure and return its HTML. A
     * closure that throws yields an empty string and a warning in the
     * log — same posture as TabRegistry::resolveBadge — so a buggy
     * plugin doesn't take down the host's render.
     */
    public function renderPanel(string $pluginId): string
    {
        $entry = $this->find($pluginId);
        if ($entry === null) {
            return '';
        }
        $render = $entry['render'] ?? null;
        if (!is_callable($render)) {
            return '';
        }
        try {
            $out = $render();
        } catch (Throwable $e) {
            try {
                Logger::getInstance()->warning(
                    "PluginsTabPanelRegistry: render callback for '{$pluginId}' threw",
                    ['error' => $e->getMessage()]
                );
            } catch (Throwable $_) {}
            return '';
        }
        return is_string($out) ? $out : '';
    }

    private function validate(array $entry): bool
    {
        foreach (['plugin_id', 'label', 'render'] as $required) {
            if (!isset($entry[$required])) {
                $this->log("missing required field '{$required}'");
                return false;
            }
        }
        if (!is_string($entry['plugin_id']) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $entry['plugin_id'])) {
            $this->log('invalid plugin_id: ' . var_export($entry['plugin_id'], true));
            return false;
        }
        if (!is_string($entry['label']) || $entry['label'] === '') {
            $this->log("panel '{$entry['plugin_id']}': label must be a non-empty string");
            return false;
        }
        if (!is_callable($entry['render'])) {
            $this->log("panel '{$entry['plugin_id']}': render must be callable");
            return false;
        }
        if (isset($entry['order']) && !is_int($entry['order'])) {
            $this->log("panel '{$entry['plugin_id']}': order must be int when set");
            return false;
        }
        return true;
    }

    private function normalize(array $entry): array
    {
        return [
            'plugin_id' => $entry['plugin_id'],
            'label'     => $entry['label'],
            'icon'      => isset($entry['icon']) && is_string($entry['icon']) && $entry['icon'] !== ''
                ? $entry['icon']
                : 'fas fa-puzzle-piece',
            'order'     => isset($entry['order']) && is_int($entry['order']) ? $entry['order'] : 100,
            'render'    => $entry['render'],
        ];
    }

    private function log(string $msg): void
    {
        try {
            Logger::getInstance()->warning("PluginsTabPanelRegistry: {$msg}");
        } catch (Throwable $_) {}
    }
}
