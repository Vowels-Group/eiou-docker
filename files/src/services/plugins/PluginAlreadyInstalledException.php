<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use InvalidArgumentException;

/**
 * Thrown by `PluginInstallService::installFromZip` when the operator
 * uploads a zip for a plugin id that's already installed on the
 * plugins volume. Carries both the would-be-installed version (from
 * the zip's manifest) and the currently-installed version (from the
 * on-disk manifest) so the GUI can render a "Replace v{current} with
 * v{new}?" confirmation dialog and route the same file through the
 * upgrade path (`pluginsUploadAsUpgrade`) on operator consent.
 *
 * Extends `InvalidArgumentException` so existing controller catch
 * blocks that target that base class still match — the GUI
 * controller has a more specific catch ahead of the generic one to
 * pick up these fields when present.
 */
class PluginAlreadyInstalledException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $newVersion,
        public readonly string $currentVersion
    ) {
        $currentLabel = $currentVersion !== '' ? "v{$currentVersion}" : 'an existing version';
        $newLabel = $newVersion !== '' ? "v{$newVersion}" : 'this upload';
        parent::__construct(
            "Plugin '{$pluginId}' is already installed ({$currentLabel}). "
            . "Use the upgrade flow to replace with {$newLabel}."
        );
    }
}
