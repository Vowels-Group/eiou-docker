<?php
# Copyright 2025

/**
 * MarketplaceService Test Suite
 *
 * Comprehensive tests for the marketplace plugin service layer
 *
 * @package Tests\Marketplace
 */

require_once dirname(__DIR__, 2) . '/files/src/plugins/marketplace/MarketplacePlugin.php';

class MarketplaceServiceTest {
    /**
     * @var PDO Test database connection
     */
    private PDO $pdo;

    /**
     * @var MarketplaceRepository Repository instance
     */
    private MarketplaceRepository $repository;

    /**
     * @var MarketplaceService Service instance
     */
    private MarketplaceService $service;

    /**
     * @var array Test results
     */
    private array $results = [];

    /**
     * Setup test environment
     */
    public function setUp(): void {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables
        $this->createTables();

        // Initialize repository and service
        $this->repository = new MarketplaceRepository($this->pdo);
        $this->service = new MarketplaceService(
            $this->repository,
            null, // UtilityServiceContainer mock
            null, // Logger mock
            ['settings' => ['allow_unsigned_plugins' => true]]
        );
    }

    /**
     * Create test database tables
     */
    private function createTables(): void {
        $this->pdo->exec("
            CREATE TABLE marketplace_plugins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plugin_id VARCHAR(128) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                version VARCHAR(32) NOT NULL,
                description TEXT,
                author VARCHAR(255),
                homepage VARCHAR(512),
                license VARCHAR(64),
                category VARCHAR(64) DEFAULT 'general',
                tags TEXT,
                downloads INTEGER DEFAULT 0,
                rating DECIMAL(3,2) DEFAULT 0.00,
                rating_count INTEGER DEFAULT 0,
                manifest TEXT NOT NULL,
                checksum VARCHAR(128),
                signature TEXT,
                repository_id INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE marketplace_repositories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(512) UNIQUE NOT NULL,
                description TEXT,
                is_official INTEGER DEFAULT 0,
                is_enabled INTEGER DEFAULT 1,
                priority INTEGER DEFAULT 100,
                last_sync TIMESTAMP,
                sync_status VARCHAR(32) DEFAULT 'pending',
                plugin_count INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE marketplace_installed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plugin_id VARCHAR(128) NOT NULL UNIQUE,
                installed_version VARCHAR(32) NOT NULL,
                available_version VARCHAR(32),
                install_path TEXT NOT NULL,
                is_active INTEGER DEFAULT 1,
                auto_update INTEGER DEFAULT 0,
                installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                settings TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE marketplace_reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plugin_id VARCHAR(128) NOT NULL,
                user_hash VARCHAR(64) NOT NULL,
                rating INTEGER NOT NULL,
                title VARCHAR(255),
                review TEXT,
                is_verified INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(plugin_id, user_hash)
            )
        ");
    }

    /**
     * Run all tests
     */
    public function runAllTests(): array {
        $this->setUp();

        $tests = [
            'testPluginCreation',
            'testPluginRetrieval',
            'testPluginSearch',
            'testPluginPublishing',
            'testPluginValidation',
            'testCategoryListing',
            'testRepositoryManagement',
            'testInstalledPluginTracking',
            'testUpdateChecking',
            'testPluginReviews',
            'testPagination'
        ];

        foreach ($tests as $test) {
            try {
                $this->$test();
                $this->results[$test] = ['status' => 'PASS'];
            } catch (Exception $e) {
                $this->results[$test] = [
                    'status' => 'FAIL',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $this->results;
    }

    // ==================== Plugin Tests ====================

    /**
     * Test plugin creation
     */
    public function testPluginCreation(): void {
        $plugin = new Plugin();
        $plugin->pluginId = 'test-plugin';
        $plugin->name = 'Test Plugin';
        $plugin->version = '1.0.0';
        $plugin->description = 'A test plugin';
        $plugin->author = 'Test Author';
        $plugin->category = 'tools';
        $plugin->manifest = ['id' => 'test-plugin', 'name' => 'Test Plugin'];

        $result = $this->repository->savePlugin($plugin);

        $this->assertTrue($result, 'Plugin should be saved successfully');
    }

    /**
     * Test plugin retrieval
     */
    public function testPluginRetrieval(): void {
        // Create test plugin first
        $this->createTestPlugin('retrieve-test', '1.0.0');

        $plugin = $this->repository->getPluginById('retrieve-test');

        $this->assertNotNull($plugin, 'Plugin should be retrieved');
        $this->assertEquals('retrieve-test', $plugin->pluginId, 'Plugin ID should match');
        $this->assertEquals('1.0.0', $plugin->version, 'Version should match');
    }

    /**
     * Test plugin search
     */
    public function testPluginSearch(): void {
        // Create test plugins
        $this->createTestPlugin('search-tool-1', '1.0.0', 'tools', 'Code formatting tool');
        $this->createTestPlugin('search-tool-2', '1.0.0', 'tools', 'Another code tool');
        $this->createTestPlugin('search-util', '1.0.0', 'utilities', 'Utility helper');

        // Search by name
        $results = $this->repository->getPlugins(['search' => 'tool'], 10, 0);
        $this->assertEquals(2, count($results), 'Should find 2 plugins matching "tool"');

        // Search by category
        $results = $this->repository->getPlugins(['category' => 'tools'], 10, 0);
        $this->assertEquals(2, count($results), 'Should find 2 plugins in tools category');
    }

    /**
     * Test plugin publishing
     */
    public function testPluginPublishing(): void {
        $manifest = [
            'id' => 'published-plugin',
            'name' => 'Published Plugin',
            'version' => '1.0.0',
            'description' => 'A published plugin',
            'author' => 'Publisher'
        ];

        $result = $this->service->publishPlugin($manifest);

        $this->assertTrue($result['success'], 'Plugin should be published');
        $this->assertEquals('published-plugin', $result['plugin_id'], 'Plugin ID should match');

        // Verify plugin exists
        $plugin = $this->repository->getPluginById('published-plugin');
        $this->assertNotNull($plugin, 'Published plugin should exist');
    }

    /**
     * Test plugin validation
     */
    public function testPluginValidation(): void {
        // Test invalid plugin ID
        $plugin = new Plugin();
        $plugin->pluginId = 'Invalid_Plugin_ID';
        $plugin->name = 'Test';
        $plugin->version = '1.0.0';

        $errors = $plugin->validate();
        $this->assertNotEmpty($errors, 'Should have validation errors for invalid ID');

        // Test invalid version
        $plugin->pluginId = 'valid-id';
        $plugin->version = 'invalid';

        $errors = $plugin->validate();
        $this->assertNotEmpty($errors, 'Should have validation errors for invalid version');

        // Test valid plugin
        $plugin->version = '1.0.0';
        $errors = $plugin->validate();
        $this->assertEmpty($errors, 'Should have no validation errors');
    }

    /**
     * Test category listing
     */
    public function testCategoryListing(): void {
        // Create plugins in different categories
        $this->createTestPlugin('cat-tools-1', '1.0.0', 'tools');
        $this->createTestPlugin('cat-tools-2', '1.0.0', 'tools');
        $this->createTestPlugin('cat-util-1', '1.0.0', 'utilities');

        $categories = $this->repository->getCategories();

        $this->assertGreaterThan(0, count($categories), 'Should have categories');

        $toolsCategory = array_filter($categories, fn($c) => $c['category'] === 'tools');
        $this->assertEquals(2, reset($toolsCategory)['count'], 'Tools category should have 2 plugins');
    }

    // ==================== Repository Tests ====================

    /**
     * Test repository management
     */
    public function testRepositoryManagement(): void {
        // Create repository
        $repo = new Repository();
        $repo->name = 'Test Repository';
        $repo->url = 'https://test-repo.example.com/api';
        $repo->description = 'A test repository';
        $repo->isEnabled = true;

        $result = $this->repository->saveRepository($repo);
        $this->assertTrue($result, 'Repository should be saved');

        // Retrieve repository
        $retrieved = $this->repository->getRepositoryByUrl('https://test-repo.example.com/api');
        $this->assertNotNull($retrieved, 'Repository should be retrieved');
        $this->assertEquals('Test Repository', $retrieved->name, 'Name should match');

        // Update sync status
        $this->repository->updateRepositorySyncStatus($retrieved->id, 'success', 10);
        $updated = $this->repository->getRepositoryById($retrieved->id);
        $this->assertEquals('success', $updated->syncStatus, 'Sync status should be updated');
        $this->assertEquals(10, $updated->pluginCount, 'Plugin count should be updated');
    }

    // ==================== Installed Plugin Tests ====================

    /**
     * Test installed plugin tracking
     */
    public function testInstalledPluginTracking(): void {
        // Create installed plugin record
        $installed = new InstalledPlugin();
        $installed->pluginId = 'installed-test';
        $installed->installedVersion = '1.0.0';
        $installed->installPath = '/etc/eiou/plugins/installed/installed-test';
        $installed->isActive = true;

        $result = $this->repository->saveInstalledPlugin($installed);
        $this->assertTrue($result, 'Installed plugin should be saved');

        // Retrieve installed plugin
        $retrieved = $this->repository->getInstalledPlugin('installed-test');
        $this->assertNotNull($retrieved, 'Installed plugin should be retrieved');
        $this->assertEquals('1.0.0', $retrieved->installedVersion, 'Version should match');
        $this->assertTrue($retrieved->isActive, 'Should be active');

        // Update available version
        $this->repository->updateAvailableVersions(['installed-test' => '1.1.0']);
        $updated = $this->repository->getInstalledPlugin('installed-test');
        $this->assertEquals('1.1.0', $updated->availableVersion, 'Available version should be set');
        $this->assertTrue($updated->hasUpdate(), 'Should have update available');
    }

    /**
     * Test update checking
     */
    public function testUpdateChecking(): void {
        // Create marketplace plugin
        $this->createTestPlugin('update-check-plugin', '2.0.0');

        // Create installed plugin with older version
        $installed = new InstalledPlugin();
        $installed->pluginId = 'update-check-plugin';
        $installed->installedVersion = '1.0.0';
        $installed->installPath = '/test/path';
        $this->repository->saveInstalledPlugin($installed);

        // Check for updates
        $updates = $this->service->checkUpdates();

        $this->assertGreaterThan(0, $updates['updates_available'], 'Should have updates available');

        $hasUpdate = false;
        foreach ($updates['updates'] as $update) {
            if ($update['plugin_id'] === 'update-check-plugin') {
                $hasUpdate = true;
                $this->assertEquals('1.0.0', $update['installed_version']);
                $this->assertEquals('2.0.0', $update['available_version']);
            }
        }
        $this->assertTrue($hasUpdate, 'update-check-plugin should be in updates list');
    }

    // ==================== Review Tests ====================

    /**
     * Test plugin reviews
     */
    public function testPluginReviews(): void {
        $this->createTestPlugin('reviewed-plugin', '1.0.0');

        // Add review
        $result = $this->repository->saveReview(
            'reviewed-plugin',
            'user123',
            5,
            'Great plugin!',
            'This plugin works perfectly.'
        );
        $this->assertTrue($result, 'Review should be saved');

        // Add another review
        $this->repository->saveReview('reviewed-plugin', 'user456', 4, 'Good', 'Works well');

        // Get reviews
        $reviews = $this->repository->getPluginReviews('reviewed-plugin');
        $this->assertEquals(2, count($reviews), 'Should have 2 reviews');

        // Check rating update
        $plugin = $this->repository->getPluginById('reviewed-plugin');
        $this->assertEquals(4.5, $plugin->rating, 'Rating should be averaged');
        $this->assertEquals(2, $plugin->ratingCount, 'Rating count should be 2');
    }

    // ==================== Pagination Tests ====================

    /**
     * Test pagination
     */
    public function testPagination(): void {
        // Create 25 plugins
        for ($i = 1; $i <= 25; $i++) {
            $this->createTestPlugin("pagination-plugin-{$i}", '1.0.0');
        }

        // Get first page
        $result = $this->service->listPlugins([], 1, 10);
        $this->assertEquals(10, count($result['plugins']), 'First page should have 10 plugins');
        $this->assertEquals(1, $result['pagination']['page'], 'Should be page 1');
        $this->assertEquals(3, $result['pagination']['total_pages'], 'Should have 3 pages');

        // Get second page
        $result = $this->service->listPlugins([], 2, 10);
        $this->assertEquals(10, count($result['plugins']), 'Second page should have 10 plugins');

        // Get third page
        $result = $this->service->listPlugins([], 3, 10);
        $this->assertEquals(5, count($result['plugins']), 'Third page should have 5 plugins');
    }

    // ==================== Helper Methods ====================

    /**
     * Create a test plugin
     */
    private function createTestPlugin(string $id, string $version, string $category = 'general', string $description = ''): void {
        $plugin = new Plugin();
        $plugin->pluginId = $id;
        $plugin->name = ucfirst(str_replace('-', ' ', $id));
        $plugin->version = $version;
        $plugin->description = $description ?: "Test plugin: {$id}";
        $plugin->category = $category;
        $plugin->manifest = ['id' => $id, 'name' => $plugin->name, 'version' => $version];

        $this->repository->savePlugin($plugin);
    }

    /**
     * Assert true
     */
    private function assertTrue($condition, string $message): void {
        if (!$condition) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    /**
     * Assert equals
     */
    private function assertEquals($expected, $actual, string $message): void {
        if ($expected !== $actual) {
            throw new Exception("Assertion failed: {$message} (expected: {$expected}, actual: {$actual})");
        }
    }

    /**
     * Assert not null
     */
    private function assertNotNull($value, string $message): void {
        if ($value === null) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    /**
     * Assert not empty
     */
    private function assertNotEmpty($value, string $message): void {
        if (empty($value)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    /**
     * Assert empty
     */
    private function assertEmpty($value, string $message): void {
        if (!empty($value)) {
            throw new Exception("Assertion failed: {$message}");
        }
    }

    /**
     * Assert greater than
     */
    private function assertGreaterThan($expected, $actual, string $message): void {
        if ($actual <= $expected) {
            throw new Exception("Assertion failed: {$message}");
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    $test = new MarketplaceServiceTest();
    $results = $test->runAllTests();

    echo "\n=== Marketplace Service Test Results ===\n\n";

    $passed = 0;
    $failed = 0;

    foreach ($results as $testName => $result) {
        $status = $result['status'];
        $icon = $status === 'PASS' ? '[PASS]' : '[FAIL]';

        echo "{$icon} {$testName}\n";

        if ($status === 'PASS') {
            $passed++;
        } else {
            $failed++;
            echo "       Error: {$result['error']}\n";
        }
    }

    echo "\n=== Summary ===\n";
    echo "Passed: {$passed}\n";
    echo "Failed: {$failed}\n";
    echo "Total:  " . count($results) . "\n\n";

    exit($failed > 0 ? 1 : 0);
}
