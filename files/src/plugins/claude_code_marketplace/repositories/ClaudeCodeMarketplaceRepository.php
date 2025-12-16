<?php
# Copyright 2025

/**
 * Marketplace Repository
 *
 * Data access layer for marketplace database operations
 *
 * @package Plugins\Marketplace\Repositories
 */

class ClaudeCodeMarketplaceRepository {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ==================== Plugin Operations ====================

    /**
     * Get all plugins with optional filtering
     *
     * @param array $filters Filter options
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of Plugin objects
     */
    public function getPlugins(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'category = :category';
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :search OR description LIKE :search OR tags LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['repository_id'])) {
            $where[] = 'repository_id = :repository_id';
            $params[':repository_id'] = $filters['repository_id'];
        }

        $orderBy = match ($filters['sort'] ?? 'downloads') {
            'rating' => 'rating DESC, rating_count DESC',
            'name' => 'name ASC',
            'updated' => 'updated_at DESC',
            'created' => 'created_at DESC',
            default => 'downloads DESC'
        };

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT * FROM marketplace_plugins WHERE {$whereClause} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $plugins = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $plugins[] = Plugin::fromRow($row);
        }

        return $plugins;
    }

    /**
     * Get a plugin by ID
     *
     * @param string $pluginId Plugin identifier
     * @return Plugin|null
     */
    public function getPluginById(string $pluginId): ?Plugin {
        $stmt = $this->pdo->prepare("SELECT * FROM marketplace_plugins WHERE plugin_id = :plugin_id");
        $stmt->execute([':plugin_id' => $pluginId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Plugin::fromRow($row) : null;
    }

    /**
     * Create or update a plugin
     *
     * @param Plugin $plugin Plugin to save
     * @return bool Success
     */
    public function savePlugin(Plugin $plugin): bool {
        $data = $plugin->toArray();

        if ($this->getPluginById($plugin->pluginId)) {
            // Update existing plugin
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':id'] = $plugin->pluginId;

            $sql = "UPDATE marketplace_plugins SET " . implode(', ', $sets) . " WHERE plugin_id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new plugin
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO marketplace_plugins ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Delete a plugin
     *
     * @param string $pluginId Plugin identifier
     * @return bool Success
     */
    public function deletePlugin(string $pluginId): bool {
        $stmt = $this->pdo->prepare("DELETE FROM marketplace_plugins WHERE plugin_id = :plugin_id");
        return $stmt->execute([':plugin_id' => $pluginId]);
    }

    /**
     * Increment plugin download count
     *
     * @param string $pluginId Plugin identifier
     * @return bool Success
     */
    public function incrementDownloads(string $pluginId): bool {
        $stmt = $this->pdo->prepare("UPDATE marketplace_plugins SET downloads = downloads + 1 WHERE plugin_id = :plugin_id");
        return $stmt->execute([':plugin_id' => $pluginId]);
    }

    /**
     * Get plugin count
     *
     * @param array $filters Optional filters
     * @return int
     */
    public function getPluginCount(array $filters = []): int {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'category = :category';
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :search OR description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM marketplace_plugins WHERE {$whereClause}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get all unique categories
     *
     * @return array
     */
    public function getCategories(): array {
        $stmt = $this->pdo->query("
            SELECT category, COUNT(*) as count
            FROM marketplace_plugins
            GROUP BY category
            ORDER BY count DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==================== Repository Operations ====================

    /**
     * Get all repositories
     *
     * @param bool $enabledOnly Only return enabled repositories
     * @return array Array of Repository objects
     */
    public function getRepositories(bool $enabledOnly = false): array {
        $sql = "SELECT * FROM marketplace_repositories";
        if ($enabledOnly) {
            $sql .= " WHERE is_enabled = 1";
        }
        $sql .= " ORDER BY priority ASC, name ASC";

        $stmt = $this->pdo->query($sql);
        $repositories = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $repositories[] = Repository::fromRow($row);
        }

        return $repositories;
    }

    /**
     * Get a repository by ID
     *
     * @param int $id Repository ID
     * @return Repository|null
     */
    public function getRepositoryById(int $id): ?Repository {
        $stmt = $this->pdo->prepare("SELECT * FROM marketplace_repositories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Repository::fromRow($row) : null;
    }

    /**
     * Get a repository by URL
     *
     * @param string $url Repository URL
     * @return Repository|null
     */
    public function getRepositoryByUrl(string $url): ?Repository {
        $stmt = $this->pdo->prepare("SELECT * FROM marketplace_repositories WHERE url = :url");
        $stmt->execute([':url' => $url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Repository::fromRow($row) : null;
    }

    /**
     * Create or update a repository
     *
     * @param Repository $repository Repository to save
     * @return bool Success
     */
    public function saveRepository(Repository $repository): bool {
        $data = $repository->toArray();

        if ($repository->id) {
            // Update existing repository
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':id'] = $repository->id;

            $sql = "UPDATE marketplace_repositories SET " . implode(', ', $sets) . " WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new repository
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO marketplace_repositories ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Delete a repository
     *
     * @param int $id Repository ID
     * @return bool Success
     */
    public function deleteRepository(int $id): bool {
        // First delete all plugins from this repository
        $this->pdo->prepare("DELETE FROM marketplace_plugins WHERE repository_id = :id")->execute([':id' => $id]);

        // Then delete the repository
        $stmt = $this->pdo->prepare("DELETE FROM marketplace_repositories WHERE id = :id AND is_official = 0");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Update repository sync status
     *
     * @param int $id Repository ID
     * @param string $status Status (pending, syncing, success, error)
     * @param int|null $pluginCount Plugin count
     * @return bool Success
     */
    public function updateRepositorySyncStatus(int $id, string $status, ?int $pluginCount = null): bool {
        $sql = "UPDATE marketplace_repositories SET sync_status = :status, last_sync = NOW()";
        $params = [':status' => $status, ':id' => $id];

        if ($pluginCount !== null) {
            $sql .= ", plugin_count = :plugin_count";
            $params[':plugin_count'] = $pluginCount;
        }

        $sql .= " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    // ==================== Installed Plugin Operations ====================

    /**
     * Get all installed plugins
     *
     * @param bool $activeOnly Only return active plugins
     * @return array Array of InstalledPlugin objects
     */
    public function getInstalledPlugins(bool $activeOnly = false): array {
        $sql = "SELECT * FROM marketplace_installed";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY installed_at DESC";

        $stmt = $this->pdo->query($sql);
        $installed = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $installed[] = InstalledPlugin::fromRow($row);
        }

        return $installed;
    }

    /**
     * Get an installed plugin by ID
     *
     * @param string $pluginId Plugin identifier
     * @return InstalledPlugin|null
     */
    public function getInstalledPlugin(string $pluginId): ?InstalledPlugin {
        $stmt = $this->pdo->prepare("SELECT * FROM marketplace_installed WHERE plugin_id = :plugin_id");
        $stmt->execute([':plugin_id' => $pluginId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? InstalledPlugin::fromRow($row) : null;
    }

    /**
     * Save an installed plugin record
     *
     * @param InstalledPlugin $installed Installed plugin to save
     * @return bool Success
     */
    public function saveInstalledPlugin(InstalledPlugin $installed): bool {
        $data = $installed->toArray();

        if ($this->getInstalledPlugin($installed->pluginId)) {
            // Update existing
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':id'] = $installed->pluginId;

            $sql = "UPDATE marketplace_installed SET " . implode(', ', $sets) . " WHERE plugin_id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO marketplace_installed ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Remove an installed plugin record
     *
     * @param string $pluginId Plugin identifier
     * @return bool Success
     */
    public function removeInstalledPlugin(string $pluginId): bool {
        $stmt = $this->pdo->prepare("DELETE FROM marketplace_installed WHERE plugin_id = :plugin_id");
        return $stmt->execute([':plugin_id' => $pluginId]);
    }

    /**
     * Update available version for installed plugins
     *
     * @param array $updates Array of [plugin_id => available_version]
     * @return int Number of updated records
     */
    public function updateAvailableVersions(array $updates): int {
        $count = 0;
        $stmt = $this->pdo->prepare("UPDATE marketplace_installed SET available_version = :version WHERE plugin_id = :plugin_id");

        foreach ($updates as $pluginId => $version) {
            if ($stmt->execute([':plugin_id' => $pluginId, ':version' => $version])) {
                $count++;
            }
        }

        return $count;
    }

    // ==================== Download Tracking ====================

    /**
     * Record a plugin download
     *
     * @param string $pluginId Plugin identifier
     * @param string $version Plugin version
     * @param string|null $ipHash Hashed IP address
     * @param string|null $userAgent User agent
     * @return bool Success
     */
    public function recordDownload(string $pluginId, string $version, ?string $ipHash = null, ?string $userAgent = null): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO marketplace_downloads (plugin_id, version, ip_hash, user_agent)
            VALUES (:plugin_id, :version, :ip_hash, :user_agent)
        ");
        $result = $stmt->execute([
            ':plugin_id' => $pluginId,
            ':version' => $version,
            ':ip_hash' => $ipHash,
            ':user_agent' => $userAgent
        ]);

        if ($result) {
            $this->incrementDownloads($pluginId);
        }

        return $result;
    }

    // ==================== Review Operations ====================

    /**
     * Get reviews for a plugin
     *
     * @param string $pluginId Plugin identifier
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function getPluginReviews(string $pluginId, int $limit = 20, int $offset = 0): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM marketplace_reviews
            WHERE plugin_id = :plugin_id
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':plugin_id', $pluginId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add or update a review
     *
     * @param string $pluginId Plugin identifier
     * @param string $userHash Hashed user identifier
     * @param int $rating Rating (1-5)
     * @param string|null $title Review title
     * @param string|null $review Review text
     * @return bool Success
     */
    public function saveReview(string $pluginId, string $userHash, int $rating, ?string $title = null, ?string $review = null): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO marketplace_reviews (plugin_id, user_hash, rating, title, review)
            VALUES (:plugin_id, :user_hash, :rating, :title, :review)
            ON DUPLICATE KEY UPDATE rating = :rating2, title = :title2, review = :review2, updated_at = NOW()
        ");
        $result = $stmt->execute([
            ':plugin_id' => $pluginId,
            ':user_hash' => $userHash,
            ':rating' => $rating,
            ':title' => $title,
            ':review' => $review,
            ':rating2' => $rating,
            ':title2' => $title,
            ':review2' => $review
        ]);

        if ($result) {
            $this->updatePluginRating($pluginId);
        }

        return $result;
    }

    /**
     * Update a plugin's average rating
     *
     * @param string $pluginId Plugin identifier
     * @return bool Success
     */
    private function updatePluginRating(string $pluginId): bool {
        $stmt = $this->pdo->prepare("
            UPDATE marketplace_plugins
            SET rating = (SELECT AVG(rating) FROM marketplace_reviews WHERE plugin_id = :plugin_id1),
                rating_count = (SELECT COUNT(*) FROM marketplace_reviews WHERE plugin_id = :plugin_id2)
            WHERE plugin_id = :plugin_id3
        ");
        return $stmt->execute([
            ':plugin_id1' => $pluginId,
            ':plugin_id2' => $pluginId,
            ':plugin_id3' => $pluginId
        ]);
    }
}
