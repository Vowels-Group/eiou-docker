<?php
# Copyright 2025

/**
 * Marketplace API Controller
 *
 * REST API endpoints for marketplace operations
 *
 * Endpoints:
 * - GET    /api/v1/marketplace/plugins           - List available plugins
 * - GET    /api/v1/marketplace/plugins/:id       - Get plugin details
 * - POST   /api/v1/marketplace/plugins           - Publish a plugin
 * - PUT    /api/v1/marketplace/plugins/:id       - Update plugin info
 * - DELETE /api/v1/marketplace/plugins/:id       - Unpublish plugin
 * - GET    /api/v1/marketplace/categories        - List categories
 * - GET    /api/v1/marketplace/search            - Search plugins
 * - POST   /api/v1/marketplace/install/:id       - Install a plugin
 * - POST   /api/v1/marketplace/uninstall/:id     - Uninstall a plugin
 * - POST   /api/v1/marketplace/update/:id        - Update a plugin
 * - GET    /api/v1/marketplace/installed         - List installed plugins
 * - GET    /api/v1/marketplace/updates           - Check for updates
 * - POST   /api/v1/marketplace/sync              - Sync repositories
 * - GET    /api/v1/marketplace/repositories      - List repositories
 * - POST   /api/v1/marketplace/repositories      - Add repository
 * - DELETE /api/v1/marketplace/repositories/:id  - Remove repository
 *
 * @package Plugins\Marketplace\Api
 */

class ClaudeCodeMarketplaceApiController {
    /**
     * @var ClaudeCodeMarketplaceService Marketplace service
     */
    private ClaudeCodeMarketplaceService $service;

    /**
     * @var SecureLogger|null Logger
     */
    private ?SecureLogger $logger;

    /**
     * @var string|null Authenticated key ID
     */
    private ?string $authenticatedKeyId = null;

    /**
     * Constructor
     *
     * @param ClaudeCodeMarketplaceService $service Marketplace service
     * @param SecureLogger|null $logger Logger
     */
    public function __construct(ClaudeCodeMarketplaceService $service, ?SecureLogger $logger = null) {
        $this->service = $service;
        $this->logger = $logger;
    }

    /**
     * Set authenticated key ID for authorization
     *
     * @param string|null $keyId API key ID
     */
    public function setAuthenticatedKey(?string $keyId): void {
        $this->authenticatedKeyId = $keyId;
    }

    /**
     * Handle a marketplace API request
     *
     * @param string $method HTTP method
     * @param string|null $action Action path segment
     * @param string|null $id Resource ID
     * @param array $params Query parameters
     * @param string $body Request body
     * @return array Response data
     */
    public function handleRequest(
        string $method,
        ?string $action,
        ?string $id,
        array $params,
        string $body
    ): array {
        try {
            return match (true) {
                // Plugin discovery
                $method === 'GET' && $action === 'plugins' && !$id => $this->listPlugins($params),
                $method === 'GET' && $action === 'plugins' && $id => $this->getPlugin($id),
                $method === 'POST' && $action === 'plugins' && !$id => $this->publishPlugin($body),
                $method === 'PUT' && $action === 'plugins' && $id => $this->updatePluginInfo($id, $body),
                $method === 'DELETE' && $action === 'plugins' && $id => $this->unpublishPlugin($id),

                // Categories and search
                $method === 'GET' && $action === 'categories' => $this->listCategories(),
                $method === 'GET' && $action === 'search' => $this->searchPlugins($params),

                // Installation management
                $method === 'POST' && $action === 'install' && $id => $this->installPlugin($id),
                $method === 'POST' && $action === 'uninstall' && $id => $this->uninstallPlugin($id),
                $method === 'POST' && $action === 'update' && $id => $this->updatePlugin($id),
                $method === 'GET' && $action === 'installed' => $this->listInstalledPlugins(),
                $method === 'GET' && $action === 'updates' => $this->checkUpdates(),

                // Repository management
                $method === 'POST' && $action === 'sync' => $this->syncRepository($params),
                $method === 'GET' && $action === 'repositories' => $this->listRepositories(),
                $method === 'POST' && $action === 'repositories' && !$id => $this->addRepository($body),
                $method === 'DELETE' && $action === 'repositories' && $id => $this->removeRepository($id),

                default => $this->errorResponse('Unknown marketplace action', 404, 'unknown_action')
            };
        } catch (Exception $e) {
            $this->log('error', 'Marketplace API error', [
                'action' => $action,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($e->getMessage(), 400, 'marketplace_error');
        }
    }

    // ==================== Plugin Discovery Endpoints ====================

    /**
     * GET /api/v1/marketplace/plugins
     * List available plugins with optional filtering
     */
    private function listPlugins(array $params): array {
        $filters = [];
        if (!empty($params['category'])) {
            $filters['category'] = $params['category'];
        }
        if (!empty($params['sort'])) {
            $filters['sort'] = $params['sort'];
        }
        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

        $result = $this->service->listPlugins($filters, $page, $perPage);

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/marketplace/plugins/:id
     * Get plugin details
     */
    private function getPlugin(string $id): array {
        $plugin = $this->service->getPlugin(urldecode($id));
        if (!$plugin) {
            return $this->errorResponse('Plugin not found', 404, 'plugin_not_found');
        }

        return $this->successResponse(['plugin' => $plugin]);
    }

    /**
     * POST /api/v1/marketplace/plugins
     * Publish a new plugin
     */
    private function publishPlugin(string $body): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $data = json_decode($body, true);
        if (!$data || !isset($data['manifest'])) {
            return $this->errorResponse('Invalid request: manifest required', 400, 'invalid_request');
        }

        $result = $this->service->publishPlugin($data['manifest'], $data['package_path'] ?? null);

        return $this->successResponse($result, 201);
    }

    /**
     * PUT /api/v1/marketplace/plugins/:id
     * Update plugin information
     */
    private function updatePluginInfo(string $id, string $body): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $data = json_decode($body, true);
        if (!$data || !isset($data['manifest'])) {
            return $this->errorResponse('Invalid request: manifest required', 400, 'invalid_request');
        }

        // Ensure ID matches
        $data['manifest']['id'] = urldecode($id);

        $result = $this->service->publishPlugin($data['manifest']);

        return $this->successResponse($result);
    }

    /**
     * DELETE /api/v1/marketplace/plugins/:id
     * Unpublish a plugin
     */
    private function unpublishPlugin(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->unpublishPlugin(urldecode($id));

        return $this->successResponse($result);
    }

    // ==================== Discovery Endpoints ====================

    /**
     * GET /api/v1/marketplace/categories
     * List all plugin categories
     */
    private function listCategories(): array {
        $categories = $this->service->getCategories();

        return $this->successResponse(['categories' => $categories]);
    }

    /**
     * GET /api/v1/marketplace/search
     * Search for plugins
     */
    private function searchPlugins(array $params): array {
        $query = $params['q'] ?? $params['query'] ?? '';
        if (empty($query)) {
            return $this->errorResponse('Search query required', 400, 'missing_query');
        }

        $limit = min(100, max(1, (int) ($params['limit'] ?? 20)));
        $result = $this->service->searchPlugins($query, $limit);

        return $this->successResponse($result);
    }

    // ==================== Installation Endpoints ====================

    /**
     * POST /api/v1/marketplace/install/:id
     * Install a plugin
     */
    private function installPlugin(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->installPlugin(urldecode($id));

        return $this->successResponse($result, 201);
    }

    /**
     * POST /api/v1/marketplace/uninstall/:id
     * Uninstall a plugin
     */
    private function uninstallPlugin(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->uninstallPlugin(urldecode($id));

        return $this->successResponse($result);
    }

    /**
     * POST /api/v1/marketplace/update/:id
     * Update a plugin
     */
    private function updatePlugin(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->updatePlugin(urldecode($id));

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/marketplace/installed
     * List installed plugins
     */
    private function listInstalledPlugins(): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $installed = $this->service->getInstalledPlugins();

        return $this->successResponse(['installed' => $installed, 'count' => count($installed)]);
    }

    /**
     * GET /api/v1/marketplace/updates
     * Check for available updates
     */
    private function checkUpdates(): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $updates = $this->service->checkUpdates();

        return $this->successResponse($updates);
    }

    // ==================== Repository Endpoints ====================

    /**
     * POST /api/v1/marketplace/sync
     * Sync repositories
     */
    private function syncRepository(array $params): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $repositoryId = isset($params['repository_id']) ? (int) $params['repository_id'] : null;
        $result = $this->service->syncRepository($repositoryId);

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/marketplace/repositories
     * List configured repositories
     */
    private function listRepositories(): array {
        $repositories = $this->service->getRepositories();

        return $this->successResponse(['repositories' => $repositories, 'count' => count($repositories)]);
    }

    /**
     * POST /api/v1/marketplace/repositories
     * Add a new repository
     */
    private function addRepository(string $body): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['url'])) {
            return $this->errorResponse('Repository URL required', 400, 'missing_url');
        }

        $name = $data['name'] ?? parse_url($data['url'], PHP_URL_HOST);
        $description = $data['description'] ?? '';

        $result = $this->service->addRepository($data['url'], $name, $description);

        return $this->successResponse($result, 201);
    }

    /**
     * DELETE /api/v1/marketplace/repositories/:id
     * Remove a repository
     */
    private function removeRepository(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->removeRepository((int) $id);

        return $this->successResponse($result);
    }

    // ==================== Response Helpers ====================

    /**
     * Build success response
     */
    private function successResponse(array $data, int $statusCode = 200): array {
        return [
            'success' => true,
            'data' => $data,
            'error' => null,
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ];
    }

    /**
     * Build error response
     */
    private function errorResponse(string $message, int $statusCode, string $code): array {
        return [
            'success' => false,
            'data' => null,
            'error' => [
                'message' => $message,
                'code' => $code
            ],
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ];
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level("[Marketplace API] $message", $context);
        }
    }
}
