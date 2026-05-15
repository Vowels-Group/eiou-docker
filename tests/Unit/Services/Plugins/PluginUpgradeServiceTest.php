<?php
namespace Eiou\Tests\Services\Plugins;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Services\Plugins\PluginCredentialService;
use Eiou\Services\Plugins\PluginCredentialsExportService;
use Eiou\Services\Plugins\PluginDbUserService;
use Eiou\Services\Plugins\PluginInstallService;
use Eiou\Services\Plugins\PluginLoader;
use Eiou\Services\Plugins\PluginPoolService;
use Eiou\Services\Plugins\PluginUpgradeService;
use Eiou\Services\Plugins\PluginUserService;
use InvalidArgumentException;
use RuntimeException;

#[CoversClass(PluginUpgradeService::class)]
class PluginUpgradeServiceTest extends TestCase
{
    private string $tmpRoot;
    private string $pluginDir;
    private string $stateFile;

    /** @var PluginInstallService&\PHPUnit\Framework\MockObject\MockObject */
    private $installService;
    /** @var PluginLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $loader;
    /** @var PluginPoolService&\PHPUnit\Framework\MockObject\MockObject */
    private $poolService;
    /** @var PluginUserService&\PHPUnit\Framework\MockObject\MockObject */
    private $userService;
    /** @var PluginDbUserService&\PHPUnit\Framework\MockObject\MockObject */
    private $dbUserService;
    /** @var PluginCredentialService&\PHPUnit\Framework\MockObject\MockObject */
    private $credentialService;
    /** @var PluginCredentialsExportService&\PHPUnit\Framework\MockObject\MockObject */
    private $credentialsExport;

    private PluginUpgradeService $svc;

    protected function setUp(): void
    {
        $this->tmpRoot   = sys_get_temp_dir() . '/upgrade-test-' . uniqid('', true);
        $this->pluginDir = $this->tmpRoot . '/plugins';
        mkdir($this->pluginDir, 0o777, true);
        $this->stateFile = $this->tmpRoot . '/state.json';
        EventDispatcher::resetInstance();

        $this->installService    = $this->createMock(PluginInstallService::class);
        $this->loader            = $this->createMock(PluginLoader::class);
        $this->poolService       = $this->createMock(PluginPoolService::class);
        $this->userService       = $this->createMock(PluginUserService::class);
        $this->dbUserService     = $this->createMock(PluginDbUserService::class);
        $this->credentialService = $this->createMock(PluginCredentialService::class);
        $this->credentialsExport = $this->createMock(PluginCredentialsExportService::class);

        // Default container is a tiny stdClass — onUpgrade hook isn't
        // invoked in most tests, and a fixture plugin can declare a
        // class that uses or ignores it. Tests that exercise the
        // hook construct their own.
        $container = new \stdClass();

        $this->svc = new PluginUpgradeService(
            $this->installService,
            $this->loader,
            $this->poolService,
            $this->userService,
            $this->dbUserService,
            $this->credentialService,
            $this->credentialsExport,
            $container,
            null,
            $this->pluginDir
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        EventDispatcher::resetInstance();
    }

    // =========================================================================
    // Preconditions: refuse upgrades that can't possibly work
    // =========================================================================

    public function testUpgradeFromZipRejectsWhenPluginNotInstalled(): void
    {
        // Stage a v1.1.0 zip for a plugin that isn't on disk.
        $stagedDir = $this->stageFixture('not-installed', '1.1.0', []);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'not-installed',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('not-installed', '1.1.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->installService->expects($this->once())
            ->method('discardStaging')
            ->with(dirname($stagedDir));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not installed/');
        $this->svc->upgradeFromZip('/dev/null');
    }

    public function testUpgradeRejectsSameVersion(): void
    {
        $this->installFixture('demo', '1.0.0', []);
        $stagedDir = $this->stageFixture('demo', '1.0.0', []);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.0.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->installService->expects($this->once())->method('discardStaging');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already at version 1\.0\.0/');
        $this->svc->upgradeFromZip('/dev/null');
    }

    public function testUpgradeRejectsDowngrade(): void
    {
        $this->installFixture('demo', '2.0.0', []);
        $stagedDir = $this->stageFixture('demo', '1.0.0', []);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.0.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->installService->expects($this->once())->method('discardStaging');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Refusing downgrade .*2\.0\.0.* 1\.0\.0/');
        $this->svc->upgradeFromZip('/dev/null');
    }

    public function testUpgradeRespectsMinUpgradableFrom(): void
    {
        $this->installFixture('demo', '0.9.0', []);
        $stagedDir = $this->stageFixture('demo', '2.0.0', [
            'min_upgradable_from' => '1.0.0',
        ]);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '2.0.0', [
                'min_upgradable_from' => '1.0.0',
            ]),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->installService->expects($this->once())->method('discardStaging');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/min_upgradable_from=1\.0\.0/');
        $this->svc->upgradeFromZip('/dev/null');
    }

    // =========================================================================
    // Happy path: directory swap + backup
    // =========================================================================

    public function testUpgradeSwapsDirectoriesAndKeepsBackup(): void
    {
        $this->installFixture('demo', '1.0.0', [], 'old marker');
        $stagedDir = $this->stageFixture('demo', '1.1.0', [], 'new marker');

        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.1.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'demo', 'enabled' => false], // disabled → no reload
        ]);
        $this->installService->expects($this->once())->method('discardStaging');

        $result = $this->svc->upgradeFromZip('/dev/null');

        $this->assertSame('1.0.0', $result['old_version']);
        $this->assertSame('1.1.0', $result['new_version']);
        $this->assertStringContainsString('.backup-1.0.0-', $result['backup_dir']);
        $this->assertTrue(is_dir($result['backup_dir']));
        $this->assertSame('old marker', trim((string) @file_get_contents($result['backup_dir'] . '/MARKER.txt')));
        $this->assertSame('new marker', trim((string) @file_get_contents($this->pluginDir . '/demo/MARKER.txt')));
        $this->assertSame('1.1.0', $this->readManifestVersion($this->pluginDir . '/demo'));
    }

    public function testUpgradeFiresPluginUpgradedEvent(): void
    {
        $this->installFixture('demo', '1.0.0', []);
        $stagedDir = $this->stageFixture('demo', '1.1.0', []);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.1.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->loader->method('listAllPlugins')->willReturn([['name' => 'demo', 'enabled' => false]]);

        $captured = null;
        EventDispatcher::getInstance()->subscribe(PluginEvents::PLUGIN_UPGRADED, function ($data) use (&$captured) {
            $captured = $data;
        });

        $this->svc->upgradeFromZip('/dev/null', 'demo-1.1.0.zip');

        $this->assertNotNull($captured);
        $this->assertSame('demo', $captured['name']);
        $this->assertSame('1.0.0', $captured['old_version']);
        $this->assertSame('1.1.0', $captured['new_version']);
        $this->assertSame('zip_upload', $captured['source']);
    }

    // =========================================================================
    // Grant reconciliation
    // =========================================================================

    public function testUpgradeRevokesAndReGrantsForNewOwnedTables(): void
    {
        // Old manifest has database.user=true with one owned_table; new
        // adds a second one. Service should REVOKE then GRANT both.
        $this->installFixture('demo', '1.0.0', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_demo_keys'],
            ],
        ]);
        $stagedDir = $this->stageFixture('demo', '1.1.0', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_demo_keys', 'plugin_demo_balances'],
            ],
        ]);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.1.0', [
                'database' => ['user' => true, 'owned_tables' => ['plugin_demo_keys', 'plugin_demo_balances']],
            ]),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->loader->method('listAllPlugins')->willReturn([['name' => 'demo', 'enabled' => false]]);
        $this->credentialService->method('exists')->with('demo')->willReturn(true);

        $this->dbUserService->expects($this->once())->method('revoke')->with('demo');
        $this->dbUserService->expects($this->once())
            ->method('grant')
            ->with('demo', ['plugin_demo_keys', 'plugin_demo_balances']);

        $result = $this->svc->upgradeFromZip('/dev/null');
        $this->assertSame('ok', $result['steps']['reconcile_grants']);
    }

    public function testUpgradeSkipsGrantsWhenPluginHasNoDatabaseBlock(): void
    {
        $this->installFixture('demo', '1.0.0', []);
        $stagedDir = $this->stageFixture('demo', '1.1.0', []);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.1.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->loader->method('listAllPlugins')->willReturn([['name' => 'demo', 'enabled' => false]]);

        $this->dbUserService->expects($this->never())->method('revoke');
        $this->dbUserService->expects($this->never())->method('grant');

        $result = $this->svc->upgradeFromZip('/dev/null');
        $this->assertSame('skipped', $result['steps']['reconcile_grants']);
    }

    // =========================================================================
    // Pool reload only when enabled
    // =========================================================================

    public function testUpgradeReloadsPoolWhenPluginEnabled(): void
    {
        $this->installFixture('demo', '1.0.0', []);
        $stagedDir = $this->stageFixture('demo', '1.1.0', []);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.1.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->loader->method('listAllPlugins')->willReturn([['name' => 'demo', 'enabled' => true]]);
        $this->loader->method('renderActiveSandboxArtifacts')->willReturn(['snippet text', 'zones text']);
        $this->userService->method('systemUsername')->with('demo')->willReturn('eiou-p-deadbeef');

        $this->poolService->expects($this->once())
            ->method('applyPool')
            ->with('demo', 'eiou-p-deadbeef', 'snippet text', false, 'zones text')
            ->willReturn(true);

        $result = $this->svc->upgradeFromZip('/dev/null');
        $this->assertSame('ok', $result['steps']['reload_pool']);
    }

    public function testUpgradeSkipsPoolReloadWhenPluginDisabled(): void
    {
        $this->installFixture('demo', '1.0.0', []);
        $stagedDir = $this->stageFixture('demo', '1.1.0', []);
        $this->installService->method('stageAndValidate')->willReturn([
            'plugin_id' => 'demo',
            'staged_dir' => $stagedDir,
            'staging_parent' => dirname($stagedDir),
            'manifest' => $this->minimalManifest('demo', '1.1.0'),
            'signature' => ['status' => 'unsigned', 'key_fingerprint' => null],
        ]);
        $this->loader->method('listAllPlugins')->willReturn([['name' => 'demo', 'enabled' => false]]);

        $this->poolService->expects($this->never())->method('applyPool');

        $result = $this->svc->upgradeFromZip('/dev/null');
        $this->assertSame('skipped', $result['steps']['reload_pool']);
    }

    // =========================================================================
    // Bundled-upgrade path
    // =========================================================================

    public function testAvailableBundledUpgradesReturnsEmptyWhenBundleDirAbsent(): void
    {
        // BUNDLED_PLUGINS_DIR points at /app/plugins which doesn't exist
        // in the test environment. The method should return [] without
        // throwing.
        $result = $this->svc->availableBundledUpgrades();
        $this->assertSame([], $result);
    }

    public function testAvailableBundledUpgradesFiltersByVersionAndPresence(): void
    {
        // This is the production load-bearing path for the boot-time
        // auto-upgrade flow (Application::autoUpgradeBundledPlugins
        // iterates whatever this returns). Verify only plugins where
        // bundled > installed are surfaced — equal versions, older
        // bundled, and missing-counterpart cases must be excluded so
        // the boot loop doesn't waste cycles on no-op upgrade calls
        // that would just throw "already at version N".
        //
        // BUNDLED_PLUGINS_DIR is the literal `/app/plugins` path; in
        // the Docker test runner it's writable, on a dev workstation
        // it usually isn't. Skip with a clear message in the latter
        // case — the empty-dir branch is already covered above.
        $bundleRoot = PluginUpgradeService::BUNDLED_PLUGINS_DIR;
        if (!is_dir($bundleRoot) && !@mkdir($bundleRoot, 0o755, true)) {
            $this->markTestSkipped(
                $bundleRoot . ' is not writable in this env. '
                . 'The empty-dir branch is covered by '
                . 'testAvailableBundledUpgradesReturnsEmptyWhenBundleDirAbsent.'
            );
        }

        // Plugin A: bundled 1.2.0 > installed 1.1.0 → SHOULD be included
        $this->installFixture('plugin-a', '1.1.0');
        $this->writeBundle($bundleRoot, 'plugin-a', '1.2.0');

        // Plugin B: bundled 1.0.0 == installed 1.0.0 → SHOULD NOT
        $this->installFixture('plugin-b', '1.0.0');
        $this->writeBundle($bundleRoot, 'plugin-b', '1.0.0');

        // Plugin C: bundled 0.9.0 < installed 1.0.0 → SHOULD NOT
        $this->installFixture('plugin-c', '1.0.0');
        $this->writeBundle($bundleRoot, 'plugin-c', '0.9.0');

        // Plugin D: bundled exists, never installed → SHOULD NOT (upgrade
        // path is only for already-installed plugins)
        $this->writeBundle($bundleRoot, 'plugin-d', '1.0.0');

        // Plugin E: installed exists, never bundled → SHOULD NOT
        $this->installFixture('plugin-e', '1.0.0');

        try {
            $result = $this->svc->availableBundledUpgrades();
        } finally {
            // Clean up the fixtures we wrote to the shared dir.
            $this->rrmdir($bundleRoot . '/plugin-a');
            $this->rrmdir($bundleRoot . '/plugin-b');
            $this->rrmdir($bundleRoot . '/plugin-c');
            $this->rrmdir($bundleRoot . '/plugin-d');
        }

        $this->assertArrayHasKey('plugin-a', $result);
        $this->assertSame('1.1.0', $result['plugin-a']['installed_version']);
        $this->assertSame('1.2.0', $result['plugin-a']['bundled_version']);
        $this->assertArrayNotHasKey('plugin-b', $result);
        $this->assertArrayNotHasKey('plugin-c', $result);
        $this->assertArrayNotHasKey('plugin-d', $result);
        $this->assertArrayNotHasKey('plugin-e', $result);
    }

    private function writeBundle(string $bundleRoot, string $name, string $version): void
    {
        $dir = $bundleRoot . '/' . $name;
        if (!is_dir($dir)) mkdir($dir, 0o755, true);
        file_put_contents(
            $dir . '/plugin.json',
            json_encode($this->minimalManifest($name, $version), JSON_PRETTY_PRINT)
        );
    }

    // =========================================================================
    // Backup retention
    // =========================================================================

    public function testPruneOldBackupsRemovesAnythingPastRetention(): void
    {
        // Fresh backup — under retention, should NOT be removed.
        $fresh = $this->pluginDir . '/demo.backup-1.0.0-' . date('Ymd-His');
        mkdir($fresh, 0o755, true);
        touch($fresh . '/marker', time()); // current mtime

        // Stale backup — pretend it's 60 days old.
        $stale = $this->pluginDir . '/demo.backup-0.9.0-20251101-120000';
        mkdir($stale, 0o755, true);
        touch($stale, time() - (60 * 86400));

        // A random sibling dir — should be ignored even if old.
        $sibling = $this->pluginDir . '/some-other-thing';
        mkdir($sibling, 0o755, true);
        touch($sibling, time() - (60 * 86400));

        $removed = $this->svc->pruneOldBackups();

        $this->assertContains($stale, $removed);
        $this->assertNotContains($fresh, $removed);
        $this->assertNotContains($sibling, $removed);
        $this->assertFalse(is_dir($stale));
        $this->assertTrue(is_dir($fresh));
        $this->assertTrue(is_dir($sibling));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Drop a "fixture installed plugin" at $this->pluginDir/$name with the
     * given version and optional manifest extras. Used as the pre-existing
     * v1.0.0 plugin the upgrade is supposed to replace.
     */
    private function installFixture(string $name, string $version, array $extras = [], string $markerText = 'old'): void
    {
        $dir = $this->pluginDir . '/' . $name;
        if (!is_dir($dir)) mkdir($dir, 0o755, true);
        file_put_contents(
            $dir . '/plugin.json',
            json_encode($this->minimalManifest($name, $version, $extras), JSON_PRETTY_PRINT)
        );
        file_put_contents($dir . '/MARKER.txt', $markerText);
    }

    /**
     * Stage a "ready-to-swap" plugin under a fresh staging-parent. Returns
     * the staged plugin's absolute path; the caller plugs it into
     * stageAndValidate()'s return value.
     */
    private function stageFixture(string $name, string $version, array $extras = [], string $markerText = 'new'): string
    {
        $staging = $this->pluginDir . '/.staging-test-' . uniqid('', true);
        mkdir($staging, 0o755, true);
        $staged = $staging . '/' . $name;
        mkdir($staged, 0o755, true);
        file_put_contents(
            $staged . '/plugin.json',
            json_encode($this->minimalManifest($name, $version, $extras), JSON_PRETTY_PRINT)
        );
        file_put_contents($staged . '/MARKER.txt', $markerText);
        return $staged;
    }

    private function minimalManifest(string $name, string $version, array $extras = []): array
    {
        return array_merge([
            'name' => $name,
            'version' => $version,
            'entryClass' => 'Vendor\\Plugins\\' . str_replace('-', '', ucwords($name, '-')) . '\\Entry',
            'autoload' => ['psr-4' => ['Vendor\\Plugins\\' . str_replace('-', '', ucwords($name, '-')) . '\\' => 'src/']],
            'sandboxed' => true,
        ], $extras);
    }

    private function readManifestVersion(string $dir): string
    {
        $raw = (string) @file_get_contents($dir . '/plugin.json');
        $decoded = json_decode($raw, true);
        return (string) ($decoded['version'] ?? '');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
