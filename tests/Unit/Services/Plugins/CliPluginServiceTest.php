<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Plugins;

use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use Eiou\Services\Plugins\CliPluginService;
use Eiou\Services\Plugins\PluginLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CliPluginService::class)]
class CliPluginServiceTest extends TestCase
{
    private string $pluginRoot;
    private string $stateFile;
    private PluginLoader $loader;
    private MockObject|CliOutputManager $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginRoot = sys_get_temp_dir() . '/eiou-cli-plugin-' . uniqid();
        $this->stateFile = $this->pluginRoot . '-state.json';
        mkdir($this->pluginRoot, 0777, true);
        $this->loader = new PluginLoader($this->pluginRoot, null, $this->stateFile);
        $this->output = $this->createMock(CliOutputManager::class);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->pluginRoot);
        @unlink($this->stateFile);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) { $this->rrmdir($path); } else { @unlink($path); }
        }
        @rmdir($dir);
    }

    private function writeFixturePlugin(string $name): void
    {
        $dir = $this->pluginRoot . '/' . $name;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => $name,
            'version' => '1.0.0',
            'description' => 'fixture',
            'entryClass' => 'Nope\\Nope',
            'autoload' => ['psr-4' => ['Nope\\' => 'src/']],
        ]));
    }

    public function testListJsonModeEmitsAllPlugins(): void
    {
        $this->writeFixturePlugin('alpha');
        $this->writeFixturePlugin('beta');

        $captured = null;
        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success')
            ->willReturnCallback(function (string $msg, $data) use (&$captured) {
                $captured = $data;
            });

        (new CliPluginService($this->loader))->listPlugins(['eiou','plugin'], $this->output);

        $names = array_column($captured['plugins'], 'name');
        sort($names);
        $this->assertSame(['alpha', 'beta'], $names);
    }

    public function testListTextModeRendersTable(): void
    {
        $this->writeFixturePlugin('alpha');

        $this->output->method('isJsonMode')->willReturn(false);
        $this->output->expects($this->once())->method('table')
            ->with(
                $this->equalTo(['NAME', 'VERSION', 'ENABLED', 'STATUS', 'LICENSE']),
                $this->callback(fn($rows) => count($rows) === 1 && $rows[0][0] === 'alpha'),
                $this->anything()
            );

        (new CliPluginService($this->loader))->listPlugins(['eiou','plugin'], $this->output);
    }

    public function testEnablePersistsFlagAndReportsRestartRequired(): void
    {
        $this->writeFixturePlugin('alpha');

        $captured = null;
        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success')
            ->willReturnCallback(function (string $msg, $data) use (&$captured) {
                $captured = $data;
            });

        (new CliPluginService($this->loader))
            ->enablePlugin(['eiou','plugin','enable','alpha'], $this->output);

        $this->assertTrue($captured['enabled']);
        $this->assertTrue($captured['restart_required']);
        $this->assertSame('alpha', $captured['plugin']);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertTrue($state['alpha']['enabled']);
    }

    public function testDisableFlipsFlagOff(): void
    {
        $this->writeFixturePlugin('alpha');
        file_put_contents($this->stateFile, json_encode(['alpha' => ['enabled' => true]]));

        $this->output->expects($this->once())->method('success');

        (new CliPluginService($this->loader))
            ->disablePlugin(['eiou','plugin','disable','alpha'], $this->output);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertFalse($state['alpha']['enabled']);
    }

    public function testEnableRejectsUnknownPlugin(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('Unknown plugin'),
                ErrorCodes::NOT_FOUND
            );

        (new CliPluginService($this->loader))
            ->enablePlugin(['eiou','plugin','enable','ghost'], $this->output);
    }

    public function testEnableRejectsInvalidName(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->anything(),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($this->loader))
            ->enablePlugin(['eiou','plugin','enable','../etc/passwd'], $this->output);
    }

    public function testListEmptyShowsHelpfulMessage(): void
    {
        $this->output->method('isJsonMode')->willReturn(false);
        $this->output->expects($this->once())->method('info')
            ->with($this->stringContains('No plugins installed'));

        (new CliPluginService($this->loader))->listPlugins(['eiou','plugin'], $this->output);
    }

    // =========================================================================
    // Permission-consent flag parsing — exercises --grant-all, --grant key,key
    // and the no-flag non-TTY refusal. Uses a sandboxed fixture with declared
    // permissions so the consent path actually fires (the legacy non-sandboxed
    // fixture in writeFixturePlugin is refused before consent runs).
    // =========================================================================

    public function testEnableWithGrantAllPersistsApprovedPermissions(): void
    {
        $this->writeSandboxedPluginWithPerms('grantall', ['contact_address_book_enumerate']);
        $loader = $this->loaderWithSandbox();

        $captured = null;
        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success')
            ->willReturnCallback(function (string $msg, $data) use (&$captured) {
                $captured = $data;
            });

        (new CliPluginService($loader))
            ->enablePlugin(['eiou','plugin','enable','grantall','--grant-all'], $this->output);

        $this->assertTrue($captured['enabled']);
        $this->assertSame(['contact_address_book_enumerate'], $captured['approved_permissions']);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $state['grantall']['approved_permissions']
        );
        $this->assertArrayHasKey('approved_at', $state['grantall']);
    }

    public function testEnableWithGrantSubsetApprovesOnlyNamedKeys(): void
    {
        $this->writeSandboxedPluginWithPerms('grantsubset', [
            'contact_address_book_enumerate',
            'wallet_balance_read',
        ]);
        $loader = $this->loaderWithSandbox();

        $captured = null;
        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success')
            ->willReturnCallback(function (string $msg, $data) use (&$captured) {
                $captured = $data;
            });

        (new CliPluginService($loader))->enablePlugin(
            ['eiou','plugin','enable','grantsubset','--grant','contact_address_book_enumerate'],
            $this->output
        );

        $this->assertSame(['contact_address_book_enumerate'], $captured['approved_permissions']);
    }

    public function testEnableRejectsGrantKeyNotInManifest(): void
    {
        $this->writeSandboxedPluginWithPerms('refused', ['contact_address_book_enumerate']);
        $loader = $this->loaderWithSandbox();

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('wallet_outbound_send'),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($loader))->enablePlugin(
            ['eiou','plugin','enable','refused','--grant','wallet_outbound_send'],
            $this->output
        );

        $state = file_exists($this->stateFile)
            ? json_decode(file_get_contents($this->stateFile), true)
            : [];
        $this->assertArrayNotHasKey('refused', $state ?? []);
    }

    public function testEnableRejectsBothGrantAllAndGrant(): void
    {
        $this->writeSandboxedPluginWithPerms('both', ['contact_address_book_enumerate']);
        $loader = $this->loaderWithSandbox();

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('either --grant-all or --grant'),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($loader))->enablePlugin(
            ['eiou','plugin','enable','both','--grant-all','--grant','contact_address_book_enumerate'],
            $this->output
        );
    }

    public function testEnableNoFlagNonTtyRefusesWithGuidance(): void
    {
        // PHPUnit runs without a TTY on STDIN, so posix_isatty returns
        // false and the no-flag path falls into the non-TTY refusal.
        $this->writeSandboxedPluginWithPerms('ttyguard', ['contact_address_book_enumerate']);
        $loader = $this->loaderWithSandbox();

        $errMsg = '';
        $this->output->expects($this->once())->method('error')
            ->willReturnCallback(function (string $msg) use (&$errMsg) {
                $errMsg = $msg;
            });

        (new CliPluginService($loader))
            ->enablePlugin(['eiou','plugin','enable','ttyguard'], $this->output);

        $this->assertStringContainsString('contact_address_book_enumerate', $errMsg);
        $this->assertStringContainsString('--grant-all', $errMsg);
    }

    public function testEnableWithoutPermsAcceptsNoFlag(): void
    {
        // Plugin manifest declares no permissions — enable must succeed
        // without flags and without prompting (consent path skipped).
        $this->writeSandboxedPluginWithPerms('noperms', []);
        $loader = $this->loaderWithSandbox();

        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success');

        (new CliPluginService($loader))
            ->enablePlugin(['eiou','plugin','enable','noperms'], $this->output);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertTrue($state['noperms']['enabled']);
        $this->assertArrayNotHasKey('approved_permissions', $state['noperms']);
    }

    private function writeSandboxedPluginWithPerms(string $name, array $permissions): void
    {
        $dir = $this->pluginRoot . '/' . $name;
        mkdir($dir . '/src', 0777, true);
        $manifest = [
            'name' => $name,
            'version' => '1.0.0',
            'description' => 'sandboxed fixture',
            'entryClass' => 'Eiou\\Tests\\Plugins\\Cli\\' . ucfirst($name) . '\\Plugin',
            'autoload' => ['psr-4' => ['Eiou\\Tests\\Plugins\\Cli\\' . ucfirst($name) . '\\' => 'src/']],
            'sandboxed' => true,
        ];
        if ($permissions !== []) {
            $manifest['permissions'] = $permissions;
        }
        file_put_contents($dir . '/plugin.json', json_encode($manifest));
    }

    private function loaderWithSandbox(): PluginLoader
    {
        $loader = new PluginLoader($this->pluginRoot, null, $this->stateFile);
        $userSvc = $this->createMock(\Eiou\Services\Plugins\PluginUserService::class);
        $userSvc->method('ensureUser')->willReturn(true);
        $userSvc->method('dropUser')->willReturn(true);
        $userSvc->method('systemUsername')->willReturnCallback(
            fn(string $id) => 'eiou-p-' . substr(hash('sha256', $id), 0, 8)
        );
        $poolSvc = $this->createMock(\Eiou\Services\Plugins\PluginPoolService::class);
        $poolSvc->method('applyPool')->willReturn(true);
        $poolSvc->method('dropPool')->willReturn(true);
        $nginxSvc = $this->createMock(\Eiou\Services\Plugins\PluginNginxConfigService::class);
        $nginxSvc->method('renderSnippet')->willReturn('');
        $loader->setSandboxServices($userSvc, $poolSvc, $nginxSvc);
        return $loader;
    }

    // =========================================================================
    // upgrade subcommand
    // =========================================================================

    public function testUpgradeRejectsInvalidName(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('Usage: eiou plugin upgrade <name>'),
                ErrorCodes::VALIDATION_ERROR
            );

        $svc = new CliPluginService(
            $this->loader,
            null,
            $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class)
        );
        $svc->upgradePlugin(['eiou','plugin','upgrade','../etc/passwd'], $this->output);
    }

    public function testUpgradeRefusedWhenServiceNotAvailable(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('not available'),
                ErrorCodes::GENERAL_ERROR
            );

        // No upgrade service wired (e.g. early-boot context) — refuse
        // cleanly rather than fatal.
        (new CliPluginService($this->loader))
            ->upgradePlugin(['eiou','plugin','upgrade','alpha'], $this->output);
    }

    public function testUpgradeSurfacesInvalidArgumentAs400(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('upgradeFromBundle')
            ->willThrowException(new \InvalidArgumentException('already at version 1.0.0'));

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('already at version'),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade','alpha'], $this->output);
    }

    public function testUpgradeSurfacesRuntimeExceptionAs500(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('upgradeFromBundle')
            ->willThrowException(new \RuntimeException('supervisor failed'));

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('supervisor failed'),
                ErrorCodes::GENERAL_ERROR
            );

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade','alpha'], $this->output);
    }

    public function testUpgradeSuccessIncludesVersionDeltaInMessage(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('upgradeFromBundle')->willReturn([
            'plugin_id' => 'alpha',
            'old_version' => '1.0.0',
            'new_version' => '1.1.0',
            'backup_dir' => '/etc/eiou/plugins/alpha.backup-1.0.0-20260513-140000',
            'steps' => [
                'swap' => 'ok',
                'on_upgrade' => 'skipped',
                'reconcile_grants' => 'skipped',
                're_export_credentials' => 'skipped',
                'reload_pool' => 'skipped',
            ],
        ]);

        $this->output->expects($this->once())->method('success')
            ->with($this->stringContains('1.0.0 → 1.1.0'));

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade','alpha'], $this->output);
    }

    public function testUpgradeReportsPartialFailureWhenStepHasError(): void
    {
        // Directory swap succeeded but the pool reload failed. The
        // upgrade is committed (new code on disk) but the operator
        // needs to know one step didn't complete cleanly.
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('upgradeFromBundle')->willReturn([
            'plugin_id' => 'alpha',
            'old_version' => '1.0.0',
            'new_version' => '1.1.0',
            'backup_dir' => '/etc/eiou/plugins/alpha.backup-1.0.0-20260513-140000',
            'steps' => [
                'swap' => 'ok',
                'on_upgrade' => 'skipped',
                'reconcile_grants' => 'ok',
                're_export_credentials' => 'ok',
                'reload_pool' => 'error:apply_pool_failed',
            ],
        ]);

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('post-swap steps had errors'),
                ErrorCodes::GENERAL_ERROR,
                500,
                $this->anything()
            );

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade','alpha'], $this->output);
    }
}
