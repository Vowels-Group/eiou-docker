<?php
# Copyright 2025

/**
 * Plugin Model
 *
 * Represents a plugin available in the marketplace
 *
 * @package Plugins\Marketplace\Models
 */

class Plugin {
    /**
     * @var int|null Database ID
     */
    public ?int $id = null;

    /**
     * @var string Unique plugin identifier
     */
    public string $pluginId;

    /**
     * @var string Plugin name
     */
    public string $name;

    /**
     * @var string Semantic version
     */
    public string $version;

    /**
     * @var string Plugin description
     */
    public string $description = '';

    /**
     * @var string Author name
     */
    public string $author = '';

    /**
     * @var string Homepage URL
     */
    public string $homepage = '';

    /**
     * @var string License identifier
     */
    public string $license = 'MIT';

    /**
     * @var string Plugin category
     */
    public string $category = 'general';

    /**
     * @var array Plugin tags
     */
    public array $tags = [];

    /**
     * @var int Download count
     */
    public int $downloads = 0;

    /**
     * @var float Average rating (1-5)
     */
    public float $rating = 0.0;

    /**
     * @var int Number of ratings
     */
    public int $ratingCount = 0;

    /**
     * @var array Full plugin manifest
     */
    public array $manifest = [];

    /**
     * @var string SHA256 checksum of plugin package
     */
    public string $checksum = '';

    /**
     * @var string|null Digital signature
     */
    public ?string $signature = null;

    /**
     * @var int|null Repository ID
     */
    public ?int $repositoryId = null;

    /**
     * @var string Created timestamp
     */
    public string $createdAt;

    /**
     * @var string Updated timestamp
     */
    public string $updatedAt;

    /**
     * Create a Plugin from database row
     *
     * @param array $row Database row
     * @return Plugin
     */
    public static function fromRow(array $row): Plugin {
        $plugin = new self();
        $plugin->id = (int) ($row['id'] ?? 0);
        $plugin->pluginId = $row['plugin_id'] ?? '';
        $plugin->name = $row['name'] ?? '';
        $plugin->version = $row['version'] ?? '0.0.0';
        $plugin->description = $row['description'] ?? '';
        $plugin->author = $row['author'] ?? '';
        $plugin->homepage = $row['homepage'] ?? '';
        $plugin->license = $row['license'] ?? 'MIT';
        $plugin->category = $row['category'] ?? 'general';
        $plugin->tags = json_decode($row['tags'] ?? '[]', true) ?: [];
        $plugin->downloads = (int) ($row['downloads'] ?? 0);
        $plugin->rating = (float) ($row['rating'] ?? 0.0);
        $plugin->ratingCount = (int) ($row['rating_count'] ?? 0);
        $plugin->manifest = json_decode($row['manifest'] ?? '{}', true) ?: [];
        $plugin->checksum = $row['checksum'] ?? '';
        $plugin->signature = $row['signature'] ?? null;
        $plugin->repositoryId = isset($row['repository_id']) ? (int) $row['repository_id'] : null;
        $plugin->createdAt = $row['created_at'] ?? date('Y-m-d H:i:s');
        $plugin->updatedAt = $row['updated_at'] ?? date('Y-m-d H:i:s');

        return $plugin;
    }

    /**
     * Create a Plugin from manifest array
     *
     * @param array $manifest Plugin manifest
     * @return Plugin
     */
    public static function fromManifest(array $manifest): Plugin {
        $plugin = new self();
        $plugin->pluginId = $manifest['id'] ?? '';
        $plugin->name = $manifest['name'] ?? '';
        $plugin->version = $manifest['version'] ?? '0.0.0';
        $plugin->description = $manifest['description'] ?? '';
        $plugin->author = $manifest['author'] ?? '';
        $plugin->homepage = $manifest['homepage'] ?? '';
        $plugin->license = $manifest['license'] ?? 'MIT';
        $plugin->category = $manifest['category'] ?? 'general';
        $plugin->tags = $manifest['keywords'] ?? $manifest['tags'] ?? [];
        $plugin->manifest = $manifest;

        return $plugin;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'plugin_id' => $this->pluginId,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'homepage' => $this->homepage,
            'license' => $this->license,
            'category' => $this->category,
            'tags' => json_encode($this->tags),
            'downloads' => $this->downloads,
            'rating' => $this->rating,
            'rating_count' => $this->ratingCount,
            'manifest' => json_encode($this->manifest),
            'checksum' => $this->checksum,
            'signature' => $this->signature,
            'repository_id' => $this->repositoryId
        ];
    }

    /**
     * Convert to API response format
     *
     * @return array
     */
    public function toApiResponse(): array {
        return [
            'id' => $this->pluginId,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'homepage' => $this->homepage,
            'license' => $this->license,
            'category' => $this->category,
            'tags' => $this->tags,
            'downloads' => $this->downloads,
            'rating' => round($this->rating, 2),
            'rating_count' => $this->ratingCount,
            'checksum' => $this->checksum,
            'signed' => !empty($this->signature),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }

    /**
     * Validate plugin data
     *
     * @return array Validation errors (empty if valid)
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->pluginId)) {
            $errors[] = 'Plugin ID is required';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $this->pluginId)) {
            $errors[] = 'Plugin ID must be lowercase alphanumeric with hyphens';
        }

        if (empty($this->name)) {
            $errors[] = 'Plugin name is required';
        }

        if (empty($this->version)) {
            $errors[] = 'Version is required';
        } elseif (!preg_match('/^\d+\.\d+\.\d+(-[a-z0-9]+)?$/i', $this->version)) {
            $errors[] = 'Version must follow semantic versioning (e.g., 1.0.0)';
        }

        if (strlen($this->description) > 5000) {
            $errors[] = 'Description must be less than 5000 characters';
        }

        return $errors;
    }

    /**
     * Compare versions
     *
     * @param string $otherVersion Version to compare
     * @return int -1 if this < other, 0 if equal, 1 if this > other
     */
    public function compareVersion(string $otherVersion): int {
        return version_compare($this->version, $otherVersion);
    }
}
