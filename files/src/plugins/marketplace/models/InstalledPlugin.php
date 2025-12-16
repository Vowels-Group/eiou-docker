<?php
# Copyright 2025

/**
 * InstalledPlugin Model
 *
 * Represents a plugin installed on the local system
 *
 * @package Plugins\Marketplace\Models
 */

class InstalledPlugin {
    /**
     * @var int|null Database ID
     */
    public ?int $id = null;

    /**
     * @var string Plugin identifier
     */
    public string $pluginId;

    /**
     * @var string Currently installed version
     */
    public string $installedVersion;

    /**
     * @var string|null Available version for update
     */
    public ?string $availableVersion = null;

    /**
     * @var string Installation path
     */
    public string $installPath;

    /**
     * @var bool Whether the plugin is active
     */
    public bool $isActive = true;

    /**
     * @var bool Whether to auto-update
     */
    public bool $autoUpdate = false;

    /**
     * @var string Installation timestamp
     */
    public string $installedAt;

    /**
     * @var string Last update timestamp
     */
    public string $updatedAt;

    /**
     * @var array Plugin-specific settings
     */
    public array $settings = [];

    /**
     * Create an InstalledPlugin from database row
     *
     * @param array $row Database row
     * @return InstalledPlugin
     */
    public static function fromRow(array $row): InstalledPlugin {
        $installed = new self();
        $installed->id = (int) ($row['id'] ?? 0);
        $installed->pluginId = $row['plugin_id'] ?? '';
        $installed->installedVersion = $row['installed_version'] ?? '0.0.0';
        $installed->availableVersion = $row['available_version'] ?? null;
        $installed->installPath = $row['install_path'] ?? '';
        $installed->isActive = (bool) ($row['is_active'] ?? true);
        $installed->autoUpdate = (bool) ($row['auto_update'] ?? false);
        $installed->installedAt = $row['installed_at'] ?? date('Y-m-d H:i:s');
        $installed->updatedAt = $row['updated_at'] ?? date('Y-m-d H:i:s');
        $installed->settings = json_decode($row['settings'] ?? '{}', true) ?: [];

        return $installed;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'plugin_id' => $this->pluginId,
            'installed_version' => $this->installedVersion,
            'available_version' => $this->availableVersion,
            'install_path' => $this->installPath,
            'is_active' => $this->isActive ? 1 : 0,
            'auto_update' => $this->autoUpdate ? 1 : 0,
            'settings' => json_encode($this->settings)
        ];
    }

    /**
     * Convert to API response format
     *
     * @return array
     */
    public function toApiResponse(): array {
        return [
            'plugin_id' => $this->pluginId,
            'installed_version' => $this->installedVersion,
            'available_version' => $this->availableVersion,
            'has_update' => $this->hasUpdate(),
            'install_path' => $this->installPath,
            'is_active' => $this->isActive,
            'auto_update' => $this->autoUpdate,
            'installed_at' => $this->installedAt,
            'updated_at' => $this->updatedAt
        ];
    }

    /**
     * Check if an update is available
     *
     * @return bool
     */
    public function hasUpdate(): bool {
        if (empty($this->availableVersion)) {
            return false;
        }
        return version_compare($this->availableVersion, $this->installedVersion, '>');
    }

    /**
     * Get the plugin's manifest file path
     *
     * @return string
     */
    public function getManifestPath(): string {
        return rtrim($this->installPath, '/') . '/plugin.json';
    }

    /**
     * Load the plugin's manifest
     *
     * @return array|null Manifest data or null if not found
     */
    public function loadManifest(): ?array {
        $manifestPath = $this->getManifestPath();
        if (!file_exists($manifestPath)) {
            return null;
        }

        $json = file_get_contents($manifestPath);
        return json_decode($json, true);
    }

    /**
     * Get a setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getSetting(string $key, $default = null) {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Set a setting value
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     */
    public function setSetting(string $key, $value): void {
        $this->settings[$key] = $value;
    }
}
