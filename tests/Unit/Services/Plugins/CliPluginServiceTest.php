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

    public function testReEnableWithoutFlagSucceedsWhenPriorApprovalsCoverManifest(): void
    {
        // A previously-enabled plugin that was then disabled keeps its
        // approved_permissions on file. A re-enable without any flag
        // (and from a non-TTY context like PHPUnit) must succeed by
        // re-using the existing approval set — operators shouldn't
        // have to re-consent for a routine off/on cycle.
        $this->writeSandboxedPluginWithPerms('reenable', ['contact_address_book_enumerate']);
        file_put_contents($this->stateFile, json_encode([
            'reenable' => [
                'enabled' => false,
                'approved_permissions' => ['contact_address_book_enumerate'],
                'approved_at' => '2026-05-14T10:00:00Z',
            ],
        ]));
        $loader = $this->loaderWithSandbox();

        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success');
        $this->output->expects($this->never())->method('error');

        (new CliPluginService($loader))
            ->enablePlugin(['eiou','plugin','enable','reenable'], $this->output);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertTrue($state['reenable']['enabled']);
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $state['reenable']['approved_permissions']
        );
        // approved_at must NOT have been refreshed — re-enable
        // re-uses the existing approval, it doesn't re-grant.
        $this->assertSame('2026-05-14T10:00:00Z', $state['reenable']['approved_at']);
    }

    public function testReEnableWithoutFlagRefusesWhenManifestDrifted(): void
    {
        // Previously approved one key; manifest now requests two.
        // Non-TTY refusal must name the *new* key specifically so the
        // operator's --grant flag can target just the addition.
        $this->writeSandboxedPluginWithPerms('drift', [
            'contact_address_book_enumerate', 'wallet_balance_read',
        ]);
        file_put_contents($this->stateFile, json_encode([
            'drift' => [
                'enabled' => false,
                'approved_permissions' => ['contact_address_book_enumerate'],
                'approved_at' => '2026-05-14T10:00:00Z',
            ],
        ]));
        $loader = $this->loaderWithSandbox();

        $errMsg = '';
        $this->output->expects($this->once())->method('error')
            ->willReturnCallback(function (string $msg) use (&$errMsg) {
                $errMsg = $msg;
            });

        (new CliPluginService($loader))
            ->enablePlugin(['eiou','plugin','enable','drift'], $this->output);

        $this->assertStringContainsString('wallet_balance_read', $errMsg);
        $this->assertStringContainsString('previously approved', $errMsg);
        $this->assertStringNotContainsString(
            'contact_address_book_enumerate',
            $errMsg,
            'already-approved keys must not be in the new-permission list'
        );
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

    public function testUpgradeRejectsMissingArgument(): void
    {
        // Empty argument still triggers the usage error — the
        // polymorphism kicks in only after we have something to
        // route on.
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('Usage: eiou plugin upgrade <name|zip-path>'),
                ErrorCodes::VALIDATION_ERROR
            );

        $svc = new CliPluginService(
            $this->loader,
            null,
            $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class)
        );
        $svc->upgradePlugin(['eiou','plugin','upgrade'], $this->output);
    }

    public function testUpgradeHostileNonNameArgumentTreatedAsMissingPath(): void
    {
        // A garbage argument that doesn't match the plugin-name
        // pattern (slashes, dots, leading `..`) routes to the
        // zip-upgrade path. The resolver then refuses with a
        // file-not-found error rather than ever calling
        // upgradeFromBundle with a value that would have escaped
        // the kebab-case regex previously.
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->expects($this->never())->method('upgradeFromBundle');
        $upgrade->expects($this->never())->method('upgradeFromZip');

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('not found'),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade','../etc/passwd'], $this->output);
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

    // =========================================================================
    // install subcommand (from zip path)
    // =========================================================================

    public function testInstallRejectsMissingArgument(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('Usage: eiou plugin install <zip-path>'),
                ErrorCodes::VALIDATION_ERROR
            );

        $svc = new CliPluginService(
            $this->loader, null, null, null,
            $this->createMock(\Eiou\Services\Plugins\PluginInstallService::class)
        );
        $svc->installPlugin(['eiou','plugin','install'], $this->output);
    }

    public function testInstallRefusedWhenServiceNotAvailable(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('not available'),
                ErrorCodes::GENERAL_ERROR
            );

        // No install service wired — refuse cleanly rather than fatal.
        (new CliPluginService($this->loader))
            ->installPlugin(['eiou','plugin','install','/tmp/x.zip'], $this->output);
    }

    public function testInstallRefusedWhenFileMissing(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('not found'),
                ErrorCodes::VALIDATION_ERROR
            );

        $install = $this->createMock(\Eiou\Services\Plugins\PluginInstallService::class);
        // The install service should never be invoked when the
        // resolver rejects the path — guard against accidental
        // pass-through.
        $install->expects($this->never())->method('installFromZip');

        $svc = new CliPluginService($this->loader, null, null, null, $install);
        $svc->installPlugin(
            ['eiou','plugin','install','/tmp/definitely-not-here-' . uniqid() . '.zip'],
            $this->output
        );
    }

    public function testInstallSurfacesAlreadyInstalledWithBothVersionsAndUpgradeHint(): void
    {
        // The PluginAlreadyInstalledException carries plugin_id +
        // new_version + current_version; the CLI surfaces all three
        // through error()'s additionalData and the human message
        // points at the upgrade subcommand so the operator has a
        // copy-paste retry path. Not auto-routing to upgrade is
        // deliberate — see installPlugin docstring.
        $zip = $this->makeRealZip();
        $install = $this->createMock(\Eiou\Services\Plugins\PluginInstallService::class);
        $install->method('installFromZip')
            ->willThrowException(new \Eiou\Services\Plugins\PluginAlreadyInstalledException(
                'foo', '1.3.0', '1.2.0'
            ));

        $captured = null;
        $this->output->expects($this->once())->method('error')
            ->willReturnCallback(function ($msg, $code, $status = null, $extra = []) use (&$captured) {
                $captured = ['msg' => $msg, 'code' => $code, 'status' => $status, 'extra' => $extra];
            });

        $svc = new CliPluginService($this->loader, null, null, null, $install);
        $svc->installPlugin(['eiou','plugin','install',$zip], $this->output);

        $this->assertStringContainsString("eiou plugin upgrade", $captured['msg']);
        $this->assertStringContainsString("eiou plugin uninstall foo", $captured['msg']);
        $this->assertSame(409, $captured['status']);
        $this->assertSame('foo', $captured['extra']['plugin_id']);
        $this->assertSame('1.3.0', $captured['extra']['new_version']);
        $this->assertSame('1.2.0', $captured['extra']['current_version']);
    }

    public function testInstallSurfacesInvalidArgumentAs400(): void
    {
        $zip = $this->makeRealZip();
        $install = $this->createMock(\Eiou\Services\Plugins\PluginInstallService::class);
        $install->method('installFromZip')
            ->willThrowException(new \InvalidArgumentException("Zip could not be opened (code 19)"));

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('could not be opened'),
                ErrorCodes::VALIDATION_ERROR
            );

        $svc = new CliPluginService($this->loader, null, null, null, $install);
        $svc->installPlugin(['eiou','plugin','install',$zip], $this->output);
    }

    public function testInstallSurfacesRuntimeExceptionAs500(): void
    {
        $zip = $this->makeRealZip();
        $install = $this->createMock(\Eiou\Services\Plugins\PluginInstallService::class);
        $install->method('installFromZip')
            ->willThrowException(new \RuntimeException("Signature required but plugin is unsigned"));

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('Signature required'),
                ErrorCodes::GENERAL_ERROR
            );

        $svc = new CliPluginService($this->loader, null, null, null, $install);
        $svc->installPlugin(['eiou','plugin','install',$zip], $this->output);
    }

    public function testInstallSuccessReportsStagedDisabled(): void
    {
        $zip = $this->makeRealZip();
        $install = $this->createMock(\Eiou\Services\Plugins\PluginInstallService::class);
        $install->method('installFromZip')->willReturn([
            'plugin_id' => 'foo',
            'version' => '1.0.0',
            'signature' => [
                'status' => 'ok',
                'key_fingerprint' => 'a1b2c3',
                'enforced' => false,
            ],
        ]);

        $captured = null;
        $this->output->expects($this->once())->method('success')
            ->willReturnCallback(function ($msg, $data) use (&$captured) {
                $captured = ['msg' => $msg, 'data' => $data];
            });

        $svc = new CliPluginService($this->loader, null, null, null, $install);
        $svc->installPlugin(['eiou','plugin','install',$zip], $this->output);

        // Operator-facing message must spell out the staged-disabled
        // state AND the enable-then-restart sequence — otherwise an
        // installed plugin sits invisible after the command returns
        // and operators assume the install didn't take.
        $this->assertStringContainsString('foo v1.0.0', $captured['msg']);
        $this->assertStringContainsString('DISABLED', $captured['msg']);
        $this->assertStringContainsString('eiou plugin enable foo', $captured['msg']);
        $this->assertStringContainsString('restart', $captured['msg']);
        // Signature line included when present.
        $this->assertStringContainsString('Signature: ok', $captured['msg']);
    }

    // =========================================================================
    // upgrade subcommand polymorphism (name vs. zip path)
    // =========================================================================

    public function testUpgradeWithBundledNameStillUsesBundlePath(): void
    {
        // Regression: bare plugin name continues to route to
        // upgradeFromBundle. The new zip-path branch must NOT
        // shadow the existing `eiou plugin upgrade hello-eiou` UX.
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->expects($this->once())->method('upgradeFromBundle')
            ->with('hello-eiou')
            ->willReturn([
                'plugin_id' => 'hello-eiou',
                'old_version' => '1.2.0',
                'new_version' => '1.6.0',
                'backup_dir' => '/etc/eiou/plugins/hello-eiou.backup-1.2.0-20260515-130000',
                'steps' => ['swap' => 'ok'],
            ]);
        $upgrade->expects($this->never())->method('upgradeFromZip');

        $this->output->expects($this->once())->method('success')
            ->with($this->stringContains('1.2.0 → 1.6.0'));

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade','hello-eiou'], $this->output);
    }

    public function testUpgradeWithZipPathCallsUpgradeFromZip(): void
    {
        $zip = $this->makeRealZip();
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->expects($this->never())->method('upgradeFromBundle');
        $upgrade->expects($this->once())->method('upgradeFromZip')
            ->with($zip, basename($zip))
            ->willReturn([
                'plugin_id' => 'foo',
                'old_version' => '1.2.0',
                'new_version' => '1.3.0',
                'backup_dir' => '/etc/eiou/plugins/foo.backup-1.2.0-20260515-130000',
                'steps' => ['swap' => 'ok'],
            ]);

        $this->output->expects($this->once())->method('success')
            ->with($this->stringContains('1.2.0 → 1.3.0'));

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade',$zip], $this->output);
    }

    public function testUpgradeWithZipPathRejectsMissingFile(): void
    {
        // A path-shaped argument (contains '/' so it doesn't match
        // PLUGIN_NAME_RE) that doesn't resolve to a real file must
        // error out at the resolver, NOT hit the upgrade service.
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->expects($this->never())->method('upgradeFromBundle');
        $upgrade->expects($this->never())->method('upgradeFromZip');

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('not found'),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(
                ['eiou','plugin','upgrade','/tmp/missing-' . uniqid() . '.zip'],
                $this->output
            );
    }

    public function testUpgradeWithDottedArgumentTreatedAsPath(): void
    {
        // Plugin names are strict kebab-case (no dots). Anything
        // containing a dot is unambiguously a path — including the
        // common `./plugin.zip` shorthand.
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->expects($this->never())->method('upgradeFromBundle');

        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('not found'),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($this->loader, null, $upgrade))
            ->upgradePlugin(['eiou','plugin','upgrade','./nonexistent.zip'], $this->output);
    }

    /**
     * Create a real on-disk file that resolveZipPath() can accept.
     * Content doesn't need to be a valid zip — the install service
     * is mocked in these tests, so its inner validation never runs.
     * realpath() and is_readable() are the only filesystem checks
     * that matter here.
     */
    private function makeRealZip(): string
    {
        $path = $this->pluginRoot . '/' . uniqid('upload-', true) . '.zip';
        file_put_contents($path, "PK\x03\x04 placeholder");
        return $path;
    }
}
