<?php
# Copyright 2025

/**
 * Repository Model
 *
 * Represents a plugin repository source
 *
 * @package Plugins\Marketplace\Models
 */

class Repository {
    /**
     * @var int|null Database ID
     */
    public ?int $id = null;

    /**
     * @var string Repository name
     */
    public string $name;

    /**
     * @var string Repository URL
     */
    public string $url;

    /**
     * @var string Repository description
     */
    public string $description = '';

    /**
     * @var bool Whether this is an official repository
     */
    public bool $isOfficial = false;

    /**
     * @var bool Whether repository is enabled
     */
    public bool $isEnabled = true;

    /**
     * @var int Priority (lower = higher priority)
     */
    public int $priority = 100;

    /**
     * @var string|null Last sync timestamp
     */
    public ?string $lastSync = null;

    /**
     * @var string Sync status (pending, syncing, success, error)
     */
    public string $syncStatus = 'pending';

    /**
     * @var int Number of plugins in this repository
     */
    public int $pluginCount = 0;

    /**
     * @var string Created timestamp
     */
    public string $createdAt;

    /**
     * @var string Updated timestamp
     */
    public string $updatedAt;

    /**
     * Create a Repository from database row
     *
     * @param array $row Database row
     * @return Repository
     */
    public static function fromRow(array $row): Repository {
        $repo = new self();
        $repo->id = (int) ($row['id'] ?? 0);
        $repo->name = $row['name'] ?? '';
        $repo->url = $row['url'] ?? '';
        $repo->description = $row['description'] ?? '';
        $repo->isOfficial = (bool) ($row['is_official'] ?? false);
        $repo->isEnabled = (bool) ($row['is_enabled'] ?? true);
        $repo->priority = (int) ($row['priority'] ?? 100);
        $repo->lastSync = $row['last_sync'] ?? null;
        $repo->syncStatus = $row['sync_status'] ?? 'pending';
        $repo->pluginCount = (int) ($row['plugin_count'] ?? 0);
        $repo->createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');
        $repo->updatedAt = $row['updated_at'] ?? date('Y-m-d H:i:s');

        return $repo;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'description' => $this->description,
            'is_official' => $this->isOfficial ? 1 : 0,
            'is_enabled' => $this->isEnabled ? 1 : 0,
            'priority' => $this->priority,
            'last_sync' => $this->lastSync,
            'sync_status' => $this->syncStatus,
            'plugin_count' => $this->pluginCount
        ];
    }

    /**
     * Convert to API response format
     *
     * @return array
     */
    public function toApiResponse(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'description' => $this->description,
            'is_official' => $this->isOfficial,
            'is_enabled' => $this->isEnabled,
            'priority' => $this->priority,
            'last_sync' => $this->lastSync,
            'sync_status' => $this->syncStatus,
            'plugin_count' => $this->pluginCount
        ];
    }

    /**
     * Validate repository data
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'Repository name is required';
        }

        if (empty($this->url)) {
            $errors[] = 'Repository URL is required';
        } elseif (!filter_var($this->url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Repository URL must be a valid URL';
        }

        if ($this->priority < 1 || $this->priority > 1000) {
            $errors[] = 'Priority must be between 1 and 1000';
        }

        return $errors;
    }

    /**
     * Get the full API endpoint URL
     *
     * @param string $endpoint Endpoint path
     * @return string Full URL
     */
    public function getEndpoint(string $endpoint): string {
        $baseUrl = rtrim($this->url, '/');
        $endpoint = ltrim($endpoint, '/');
        return "{$baseUrl}/{$endpoint}";
    }
}
