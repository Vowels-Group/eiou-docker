<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Services\PluginCredentialService;
use Eiou\Services\PluginDbUserService;
use Eiou\Services\PluginLoader;
use Eiou\Services\PluginPdoFactory;
use Eiou\Services\PluginUninstallService;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

#[CoversClass(PluginUninstallService::class)]
class PluginUninstallServiceTest extends TestCase
{
    private string $tmpRoot;
    private string $stateFile;
    private string $pluginDir;
    private PluginLoader $loader;
    private $credentials;
    private $dbUser;
    private $pdoFactory;
    /** @var PDO&\PHPUnit\Framework\MockObject\MockObject */
    private $rootPdo;
    private PluginUninstallService $svc;
    /** @var string[] Captured DDL statements from the root PDO */
    private array $rootExecCalls = [];

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/uninst-test-' . uniqid('', true);
        $this->pluginDir = $this->tmpRoot . '/plugins';
        mkdir($this->pluginDir, 0777, true);
        $this->stateFile = $this->tmpRoot . '/state.json';
        EventDispatcher::resetInstance();

        $this->loader = new PluginLoader(
            $this->pluginDir,
            null,
            $this->stateFile
        );

        $this->credentials = $this->createMock(PluginCredentialService::class);
        $this->dbUser = $this->createMock(PluginDbUserService::class);
        $this->pdoFactory = $this->createMock(PluginPdoFactory::class);

        $this->rootExecCalls = [];
        $this->rootPdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exec'])
            ->getMock();
        $this->rootPdo->method('exec')->willReturnCallback(function ($sql) {
            $this->rootExecCalls[] = $sql;
            return 0;
        });

        $this->svc = new PluginUninstallService(
            $this->loader,
            $this->credentials,
            $this->dbUser,
            $this->pdoFactory,
            $this->rootPdo,
            null,
            $this->pluginDir
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
        EventDispatcher::resetInstance();
    }

    // =========================================================================
    // Preconditions
    // =========================================================================

    public function testUninstallRejectsUnknownPlugin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $this->svc->uninstall('never-existed');
    }

    public function testUninstallRefusesEnabledPlugin(): void
    {
        $this->writePlugin('my-plugin', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_my_plugin_t']],
        ]);
        file_put_contents($this->stateFile, json_encode(['my-plugin' => ['enabled' => true]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/disable it first/');
        $this->svc->uninstall('my-plugin');
    }

    public function testUninstallRejectsInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->uninstall('BAD_NAME');
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function testUninstallRunsEverySetpInOrder(): void
    {
        $this->writePlugin('my-plugin', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_my_plugin_subs', 'plugin_my_plugin_notif'],
            ],
        ]);
        file_put_contents($this->stateFile, json_encode(['my-plugin' => ['enabled' => false]]));

        $this->credentials->method('exists')->willReturn(true);
        $this->credentials->expects($this->once())->method('delete')->with('my-plugin')->willReturn(true);
        $this->dbUser->method('userExists')->willReturn(true);
        $this->dbUser->expects($this->once())->method('revoke')->with('my-plugin');
        $this->dbUser->expects($this->once())->method('dropUser')->with('my-plugin');
        $this->pdoFactory->expects($this->once())->method('purge')->with('my-plugin');

        $result = $this->svc->uninstall('my-plugin');

        $this->assertTrue($result['success']);
        // on_uninstall is 'skipped' because the plugin wasn't instantiated
        // (loader didn't load it — we only wrote the manifest to disk).
        // Every other step must be 'ok'.
        $this->assertSame('skipped', $result['steps']['on_uninstall']);
        foreach (['revoke', 'drop_tables', 'drop_user', 'drop_credentials', 'remove_files', 'remove_state'] as $step) {
            $this->assertSame('ok', $result['steps'][$step], "step '$step' was not ok");
        }

        // Two DROP TABLE statements emitted.
        $this->assertCount(2, $this->rootExecCalls);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `plugin_my_plugin_subs`', $this->rootExecCalls[0]);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `plugin_my_plugin_notif`', $this->rootExecCalls[1]);

        // Files removed.
        $this->assertDirectoryDoesNotExist($this->pluginDir . '/my-plugin');

        // State entry cleared.
        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertArrayNotHasKey('my-plugin', $state);
    }

    public function testUninstallFiresEvents(): void
    {
        $this->writePlugin('my-plugin', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_my_plugin_t']],
        ]);
        file_put_contents($this->stateFile, json_encode(['my-plugin' => ['enabled' => false]]));

        $this->credentials->method('exists')->willReturn(false);
        $this->dbUser->method('userExists')->willReturn(false);

        $uninstalling = [];
        $uninstalled = [];
        EventDispatcher::getInstance()->subscribe(PluginEvents::PLUGIN_UNINSTALLING, function ($data) use (&$uninstalling) {
            $uninstalling[] = $data;
        });
        EventDispatcher::getInstance()->subscribe(PluginEvents::PLUGIN_UNINSTALLED, function ($data) use (&$uninstalled) {
            $uninstalled[] = $data;
        });

        $this->svc->uninstall('my-plugin');

        $this->assertCount(1, $uninstalling);
        $this->assertSame('my-plugin', $uninstalling[0]['name']);
        $this->assertCount(1, $uninstalled);
        $this->assertSame('my-plugin', $uninstalled[0]['name']);
        $this->assertArrayHasKey('success', $uninstalled[0]);
        $this->assertArrayHasKey('steps', $uninstalled[0]);
    }

    public function testUninstallSkipsDdlWhenNoCredentialsExist(): void
    {
        // A plugin that was installed but never enabled (so credentials
        // + user were never created) should still have its files
        // removed and state cleaned without attempting revoke/dropUser.
        $this->writePlugin('never-enabled', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_never_enabled_t']],
        ]);
        file_put_contents($this->stateFile, json_encode(['never-enabled' => ['enabled' => false]]));

        $this->credentials->method('exists')->willReturn(false);
        $this->dbUser->method('userExists')->willReturn(false);
        $this->credentials->expects($this->never())->method('delete');
        $this->dbUser->expects($this->never())->method('revoke');
        $this->dbUser->expects($this->never())->method('dropUser');

        $result = $this->svc->uninstall('never-enabled');

        $this->assertTrue($result['success']);
        $this->assertSame('skipped', $result['steps']['revoke']);
        $this->assertSame('skipped', $result['steps']['drop_user']);
        $this->assertSame('skipped', $result['steps']['drop_credentials']);
    }

    public function testUninstallWithoutDatabaseBlockStillCleansFiles(): void
    {
        $this->writePlugin('plain', []);
        file_put_contents($this->stateFile, json_encode(['plain' => ['enabled' => false]]));
        $this->credentials->method('exists')->willReturn(false);
        $this->dbUser->method('userExists')->willReturn(false);

        $result = $this->svc->uninstall('plain');
        $this->assertTrue($result['success']);
        $this->assertSame('skipped', $result['steps']['drop_tables']);
        $this->assertDirectoryDoesNotExist($this->pluginDir . '/plain');
    }

    // =========================================================================
    // Partial failure
    // =========================================================================

    public function testDropTableFailureDoesNotAbortRemainingSteps(): void
    {
        $this->writePlugin('my-plugin', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_my_plugin_t']],
        ]);
        file_put_contents($this->stateFile, json_encode(['my-plugin' => ['enabled' => false]]));

        $this->credentials->method('exists')->willReturn(true);
        $this->credentials->method('delete')->willReturn(true);
        $this->dbUser->method('userExists')->willReturn(true);

        // DROP TABLE fails but the rest of the flow must continue.
        $this->rootPdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exec'])
            ->getMock();
        $this->rootPdo->method('exec')->willThrowException(
            new PDOException('table locked')
        );
        $this->svc = new PluginUninstallService(
            $this->loader, $this->credentials, $this->dbUser,
            $this->pdoFactory, $this->rootPdo, null, $this->pluginDir
        );

        $result = $this->svc->uninstall('my-plugin');

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('error:', $result['steps']['drop_tables']);
        $this->assertSame('ok', $result['steps']['revoke']);
        $this->assertSame('ok', $result['steps']['drop_user']);
        // Files still removed despite DDL error.
        $this->assertDirectoryDoesNotExist($this->pluginDir . '/my-plugin');
    }

    public function testUninstallRejectsMalformedOwnedTable(): void
    {
        // Manifest owned_tables was valid at install time but someone edited
        // it on disk to include a core-table name. The shape-revalidation
        // in dropOwnedTables catches it before any DROP fires.
        $this->writePlugin('my-plugin', [
            'database' => [
                'user' => true,
                // Shape check: must match `plugin_[a-z0-9_]+$`. `contacts`
                // fails the prefix check.
                'owned_tables' => ['contacts'],
            ],
        ]);
        file_put_contents($this->stateFile, json_encode(['my-plugin' => ['enabled' => false]]));

        $this->credentials->method('exists')->willReturn(false);
        $this->dbUser->method('userExists')->willReturn(false);

        $result = $this->svc->uninstall('my-plugin');

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('error:', $result['steps']['drop_tables']);
        $this->assertStringContainsString('rejected by shape check', $result['steps']['drop_tables']);
        // No DROP TABLE fired for `contacts`.
        $this->assertCount(0, $this->rootExecCalls);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function writePlugin(string $name, array $manifestExtras = []): void
    {
        $path = $this->pluginDir . '/' . $name;
        mkdir($path, 0777, true);
        $manifest = array_merge([
            'name' => $name,
            'version' => '1.0.0',
            'entryClass' => 'Stub\\Plugin',
            'autoload' => ['psr-4' => ['Stub\\' => 'src/']],
        ], $manifestExtras);
        file_put_contents($path . '/plugin.json', json_encode($manifest));
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = array_diff(scandir($path), ['.', '..']);
        foreach ($items as $item) {
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }
}
