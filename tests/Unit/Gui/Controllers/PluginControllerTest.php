<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use Eiou\Gui\Controllers\PluginController;
use Eiou\Gui\Controllers\PluginControllerResponseSent;
use Eiou\Gui\Includes\Session;
use Eiou\Services\Plugins\PluginInstallService;
use Eiou\Services\Plugins\PluginLoader;
use Eiou\Services\Plugins\PluginUninstallService;
use Eiou\Services\RestartRequestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass — captures the JSON response instead of echoing it and
 * stubs the is_uploaded_file() seam so upload tests can hand the
 * controller a regular tmp file without standing up a real multipart
 * request.
 */
class CapturingPluginController extends PluginController
{
    /** @var array<int, array{status:int, payload:array<string,mixed>}> */
    public array $responses = [];

    /** Per-instance toggle for the is_uploaded_file() seam. */
    public bool $uploadedFileResult = true;

    protected function respond(array $payload, int $status = 200): void
    {
        $this->responses[] = ['status' => $status, 'payload' => $payload];
        throw new PluginControllerResponseSent($status);
    }

    protected function isUploadedFile(string $path): bool
    {
        return $this->uploadedFileResult && is_file($path);
    }
}

#[CoversClass(PluginController::class)]
class PluginControllerTest extends TestCase
{
    private Session $session;
    private PluginLoader $loader;
    private RestartRequestService $restartRequester;
    private CapturingPluginController $controller;
    private string $restartFile;
    /** @var PluginInstallService&\PHPUnit\Framework\MockObject\MockObject */
    private $installService;
    private string $uploadTmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];

        $this->session = $this->createMock(Session::class);
        $this->loader = $this->createMock(PluginLoader::class);
        $this->installService = $this->createMock(PluginInstallService::class);

        // Real RestartRequestService writing to a tmp file we can inspect.
        $this->restartFile = sys_get_temp_dir() . '/eiou-pc-test-' . uniqid() . '.json';
        $this->restartRequester = new RestartRequestService($this->restartFile);

        // A real tmp file the controller's isUploadedFile() seam can stat.
        $this->uploadTmpFile = sys_get_temp_dir() . '/eiou-pc-upload-' . uniqid() . '.zip';
        file_put_contents($this->uploadTmpFile, "PK\x03\x04");

        $this->controller = new CapturingPluginController(
            $this->session,
            $this->loader,
            $this->restartRequester,
            null,
            $this->installService
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->restartFile)) {
            @unlink($this->restartFile);
        }
        if (is_file($this->uploadTmpFile)) {
            @unlink($this->uploadTmpFile);
        }
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        parent::tearDown();
    }

    private function dispatch(): array
    {
        try {
            $this->controller->routeAction();
        } catch (PluginControllerResponseSent) {
            // expected
        }
        $this->assertNotEmpty($this->controller->responses, 'Controller produced no response');
        return $this->controller->responses[0];
    }

    private function withCsrf(): void
    {
        $this->session->method('validateCSRFToken')->willReturn(true);
    }

    #[Test]
    public function invalidCsrfReturns403(): void
    {
        $_POST = ['action' => 'pluginsList', 'csrf_token' => 'bad'];
        $this->session->method('validateCSRFToken')->willReturn(false);

        $result = $this->dispatch();

        $this->assertSame(403, $result['status']);
        // Canonical envelope: machine code at `code`; `error` carries
        // the human message. Code itself renamed to `csrf_invalid` to
        // match every other migrated GUI controller.
        $this->assertSame('csrf_invalid', $result['payload']['code']);
    }

    #[Test]
    public function unknownActionReturns400(): void
    {
        $_POST = ['action' => 'pluginsBogus', 'csrf_token' => 'x'];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('unknown_action', $result['payload']['code']);
    }

    #[Test]
    public function listPluginsReportsRestartRequiredFalseWhenStateMatchesLive(): void
    {
        $_POST = ['action' => 'pluginsList', 'csrf_token' => 'x'];
        $this->withCsrf();

        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'a', 'version' => '1.0.0', 'description' => '', 'enabled' => true,  'status' => 'booted'],
            ['name' => 'b', 'version' => '1.0.0', 'description' => '', 'enabled' => false, 'status' => 'disabled'],
        ]);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertFalse($result['payload']['restart_required']);
    }

    #[Test]
    public function listPluginsReportsRestartRequiredWhenEnabledButNotLoaded(): void
    {
        // Plugin was just enabled in the state file but the loader hasn't
        // booted it yet (this worker booted before the toggle). Banner
        // should appear.
        $_POST = ['action' => 'pluginsList', 'csrf_token' => 'x'];
        $this->withCsrf();

        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'just-enabled', 'version' => '1.0', 'description' => '', 'enabled' => true, 'status' => 'disabled'],
        ]);

        $result = $this->dispatch();

        $this->assertTrue($result['payload']['restart_required']);
    }

    #[Test]
    public function listPluginsReportsRestartRequiredWhenDisabledButStillLoaded(): void
    {
        // Plugin was just disabled but is still booted in this worker —
        // the subscription is still active until restart.
        $_POST = ['action' => 'pluginsList', 'csrf_token' => 'x'];
        $this->withCsrf();

        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'just-disabled', 'version' => '1.0', 'description' => '', 'enabled' => false, 'status' => 'booted'],
        ]);

        $result = $this->dispatch();

        $this->assertTrue($result['payload']['restart_required']);
    }

    #[Test]
    public function listPluginsTreatsFailedAsNotLoaded(): void
    {
        // 'failed' means the plugin tried to boot but threw. From the
        // user's perspective it's not running, so if state says enabled
        // we still want a restart available to retry.
        $_POST = ['action' => 'pluginsList', 'csrf_token' => 'x'];
        $this->withCsrf();

        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'broken', 'version' => '1.0', 'description' => '', 'enabled' => true, 'status' => 'failed'],
        ]);

        $result = $this->dispatch();

        $this->assertTrue($result['payload']['restart_required']);
    }

    #[Test]
    public function requestRestartWritesMarkerAndReturnsSuccess(): void
    {
        $_POST = ['action' => 'pluginsRequestRestart', 'csrf_token' => 'x'];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertSame(5, $result['payload']['expected_restart_within_seconds']);
        $this->assertFileExists($this->restartFile);

        $payload = json_decode(file_get_contents($this->restartFile), true);
        $this->assertSame('gui', $payload['source']);
    }

    #[Test]
    public function pluginChangelogReturnsRenderedHtmlForKnownPlugin(): void
    {
        $_POST = ['action' => 'pluginChangelog', 'csrf_token' => 'x', 'name' => 'hello-eiou'];
        $this->withCsrf();

        $this->loader->method('readChangelog')
            ->with('hello-eiou')
            ->willReturn("## 1.0.0\n- Initial release\n");

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertSame('hello-eiou', $result['payload']['plugin']);
        // Parser wraps headings + bullets; exact markup comes from the shared
        // UpdateCheckService rendered — we just need to see both structures.
        $this->assertStringContainsString('<h2>1.0.0</h2>', $result['payload']['html']);
        $this->assertStringContainsString('<li>Initial release</li>', $result['payload']['html']);
    }

    #[Test]
    public function pluginChangelogReturns404WhenLoaderHasNoFile(): void
    {
        $_POST = ['action' => 'pluginChangelog', 'csrf_token' => 'x', 'name' => 'ghost'];
        $this->withCsrf();
        $this->loader->method('readChangelog')->willReturn(null);

        $result = $this->dispatch();

        $this->assertSame(404, $result['status']);
        $this->assertSame('not_found', $result['payload']['code']);
    }

    #[Test]
    public function pluginChangelogRejectsInvalidName(): void
    {
        $_POST = ['action' => 'pluginChangelog', 'csrf_token' => 'x', 'name' => '../etc/passwd'];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_name', $result['payload']['code']);
    }

    // =================================================================
    // pluginsUpload
    // =================================================================

    #[Test]
    public function pluginsUploadRejectsMissingFileField(): void
    {
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_upload', $result['payload']['code']);
    }

    #[Test]
    public function pluginsUploadSurfacesPhpUploadErrors(): void
    {
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [
            'plugin_zip' => [
                'name' => 'big.zip',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ],
        ];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_upload', $result['payload']['code']);
        $this->assertStringContainsString('size limit', $result['payload']['error']);
    }

    #[Test]
    public function pluginsUploadRejectsForgedTmpName(): void
    {
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [
            'plugin_zip' => [
                'name' => 'evil.zip',
                'tmp_name' => $this->uploadTmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 4,
            ],
        ];
        $this->withCsrf();
        $this->controller->uploadedFileResult = false; // simulate not-from-request

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_upload', $result['payload']['code']);
        $this->assertStringContainsString('did not come from this request', $result['payload']['error']);
    }

    #[Test]
    public function pluginsUploadMapsInvalidZipExceptionTo400(): void
    {
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [
            'plugin_zip' => [
                'name' => 'bad.zip',
                'tmp_name' => $this->uploadTmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 4,
            ],
        ];
        $this->withCsrf();

        $this->installService->method('installFromZip')
            ->willThrowException(new \InvalidArgumentException("Entry name contains '..'"));

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_zip', $result['payload']['code']);
    }

    #[Test]
    public function pluginsUploadMapsAlreadyInstalledTo409(): void
    {
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [
            'plugin_zip' => [
                'name' => 'dup.zip',
                'tmp_name' => $this->uploadTmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 4,
            ],
        ];
        $this->withCsrf();

        $this->installService->method('installFromZip')
            ->willThrowException(new \Eiou\Services\Plugins\PluginAlreadyInstalledException(
                'foo', '1.3.0', '1.2.0'
            ));

        $result = $this->dispatch();

        $this->assertSame(409, $result['status']);
        $this->assertSame('already_installed', $result['payload']['code']);
        // The 409 carries both versions so the GUI can render a
        // "Replace v{current} with v{new}?" confirm and re-POST through
        // pluginsUploadAsUpgrade — without these fields the operator
        // would just see a generic already-installed toast and have
        // no in-GUI upgrade path.
        $this->assertSame('foo', $result['payload']['plugin_id']);
        $this->assertSame('1.3.0', $result['payload']['new_version']);
        $this->assertSame('1.2.0', $result['payload']['current_version']);
    }

    #[Test]
    public function pluginsUploadMapsRaceInstallCollisionTo409(): void
    {
        // The "appeared during install" race (another process created
        // the target dir between the existence check and the rename)
        // still maps to 409 via the plain-InvalidArgumentException
        // fallback in the controller, since the install service throws
        // a generic InvalidArgumentException for that case rather than
        // the typed PluginAlreadyInstalledException (no manifest was
        // available to populate the typed fields).
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [
            'plugin_zip' => [
                'name' => 'racey.zip',
                'tmp_name' => $this->uploadTmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 4,
            ],
        ];
        $this->withCsrf();

        $this->installService->method('installFromZip')
            ->willThrowException(new \InvalidArgumentException(
                "Plugin appeared during install — another upload won the race."
            ));

        $result = $this->dispatch();

        $this->assertSame(409, $result['status']);
        $this->assertSame('already_installed', $result['payload']['code']);
    }

    #[Test]
    public function pluginsUploadMapsRuntimeExceptionTo500(): void
    {
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [
            'plugin_zip' => [
                'name' => 'needs-sig.zip',
                'tmp_name' => $this->uploadTmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 4,
            ],
        ];
        $this->withCsrf();

        $this->installService->method('installFromZip')
            ->willThrowException(new \RuntimeException("Plugin signature required but verification returned: unsigned"));

        $result = $this->dispatch();

        $this->assertSame(500, $result['status']);
        $this->assertSame('install_failed', $result['payload']['code']);
    }

    #[Test]
    public function pluginsUploadReturnsSuccessEnvelopeWithSignatureInfo(): void
    {
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $_FILES = [
            'plugin_zip' => [
                'name' => 'good.zip',
                'tmp_name' => $this->uploadTmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 4,
            ],
        ];
        $this->withCsrf();

        $this->installService->method('installFromZip')
            ->with($this->uploadTmpFile, 'good.zip')
            ->willReturn([
                'plugin_id' => 'my-plugin',
                'version' => '1.2.3',
                'signature' => [
                    'status' => 'ok',
                    'key_fingerprint' => 'sha256:abc',
                    'enforced' => true,
                ],
            ]);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertSame('my-plugin', $result['payload']['plugin_id']);
        $this->assertSame('1.2.3', $result['payload']['version']);
        $this->assertSame('ok', $result['payload']['signature']['status']);
        $this->assertTrue($result['payload']['signature']['enforced']);
        // Upload alone does NOT activate the plugin — it stages disabled.
        // The restart banner should not light up purely from an install.
        $this->assertFalse($result['payload']['enabled']);
        $this->assertFalse($result['payload']['restart_required']);
    }

    #[Test]
    public function pluginsUploadReturnsUnavailableWhenServiceNotWired(): void
    {
        // Build a controller without the install service to simulate the
        // early-boot / no-wallet state where the service container can't
        // hand the controller an install service.
        $controller = new CapturingPluginController(
            $this->session,
            $this->loader,
            $this->restartRequester,
            null,
            null
        );
        $_POST = ['action' => 'pluginsUpload', 'csrf_token' => 'x'];
        $this->withCsrf();

        try {
            $controller->routeAction();
        } catch (PluginControllerResponseSent) {
            // expected
        }
        $this->assertNotEmpty($controller->responses);
        $result = $controller->responses[0];

        $this->assertSame(500, $result['status']);
        $this->assertSame('install_unavailable', $result['payload']['code']);
    }

    #[Test]
    public function pluginsUploadLimitsReturnsLimits(): void
    {
        $_POST = ['action' => 'pluginsUploadLimits', 'csrf_token' => 'x'];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertArrayHasKey('limits', $result['payload']);
        $this->assertSame(
            \Eiou\Services\Plugins\PluginInstallService::MAX_ZIP_BYTES,
            $result['payload']['limits']['max_zip_bytes']
        );
        $this->assertTrue($result['payload']['install_available']);
    }

    /**
     * registerActions populates the shared registry with every owned
     * plugins* action at TIER_AUTH so the dispatcher's CSRF gate
     * doesn't fire — routeAction() does its own non-rotating CSRF
     * check internally.
     */
    #[Test]
    public function registerActionsPopulatesRegistryWithCorrectTiers(): void
    {
        $registry = new \Eiou\Services\GuiActionRegistry();

        $this->controller->registerActions($registry);

        foreach ([
            'pluginsList', 'pluginsToggle', 'pluginsRequestRestart',
            'pluginChangelog', 'pluginsUninstall',
            'pluginsUpload', 'pluginsUploadAsUpgrade', 'pluginsUpgrade',
            'pluginsUploadLimits',
        ] as $a) {
            $this->assertSame(\Eiou\Services\GuiActionRegistry::TIER_AUTH, $registry->getTier($a), "{$a} should register at TIER_AUTH");
            $this->assertSame('core', $registry->getPluginId($a), "{$a} should be owned by 'core'");
            $this->assertNotNull($registry->getHandler($a), "{$a} should have a registered handler");
        }
    }

    // =========================================================================
    // pluginsUpgrade (bundled-source upgrade)
    // =========================================================================

    #[Test]
    public function pluginsUpgrade500WhenServiceNotWired(): void
    {
        // Default controller in setUp() has no upgrade service.
        $this->withCsrf();
        $_POST = ['action' => 'pluginsUpgrade', 'csrf_token' => 'x', 'plugin' => 'hello-eiou'];

        $r = $this->dispatch();
        $this->assertSame(500, $r['status']);
        $this->assertSame('upgrade_unavailable', $r['payload']['code']);
    }

    #[Test]
    public function pluginsUpgradeRejectsInvalidPluginName(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $this->controller = $this->makeControllerWithUpgrade($upgrade);
        $this->withCsrf();
        $_POST = ['action' => 'pluginsUpgrade', 'csrf_token' => 'x', 'plugin' => '../etc/passwd'];

        $r = $this->dispatch();
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_plugin_name', $r['payload']['code']);
    }

    #[Test]
    public function pluginsUpgradeMapsRefusalMessagesToCodes(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('upgradeFromBundle')
            ->willThrowException(new \InvalidArgumentException(
                "Plugin 'demo' is already at version 1.0.0"
            ));
        $this->controller = $this->makeControllerWithUpgrade($upgrade);
        $this->withCsrf();
        $_POST = ['action' => 'pluginsUpgrade', 'csrf_token' => 'x', 'plugin' => 'demo'];

        $r = $this->dispatch();
        $this->assertSame(400, $r['status']);
        $this->assertSame('same_version', $r['payload']['code']);
    }

    #[Test]
    public function pluginsUpgradeMapsDowngradeMessage(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('upgradeFromBundle')
            ->willThrowException(new \InvalidArgumentException(
                "Refusing downgrade of 'demo' from 2.0.0 to 1.0.0"
            ));
        $this->controller = $this->makeControllerWithUpgrade($upgrade);
        $this->withCsrf();
        $_POST = ['action' => 'pluginsUpgrade', 'csrf_token' => 'x', 'plugin' => 'demo'];

        $r = $this->dispatch();
        $this->assertSame(400, $r['status']);
        $this->assertSame('downgrade_refused', $r['payload']['code']);
    }

    #[Test]
    public function pluginsUpgradeReportsSuccessWithVersionDelta(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('upgradeFromBundle')->willReturn([
            'plugin_id' => 'demo',
            'old_version' => '1.0.0',
            'new_version' => '1.1.0',
            'backup_dir' => '/etc/eiou/plugins/demo.backup-1.0.0-20260513-140000',
            'steps' => ['swap' => 'ok'],
        ]);
        $this->controller = $this->makeControllerWithUpgrade($upgrade);
        $this->withCsrf();
        $_POST = ['action' => 'pluginsUpgrade', 'csrf_token' => 'x', 'plugin' => 'demo'];

        $r = $this->dispatch();
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['payload']['success']);
        $this->assertSame('1.0.0', $r['payload']['old_version']);
        $this->assertSame('1.1.0', $r['payload']['new_version']);
        $this->assertTrue($r['payload']['restart_required']);
    }

    // =========================================================================
    // listPlugins enrichment with bundled-upgrade availability
    // =========================================================================

    #[Test]
    public function pluginsListMergesBundledUpgradeAvailability(): void
    {
        $upgrade = $this->createMock(\Eiou\Services\Plugins\PluginUpgradeService::class);
        $upgrade->method('availableBundledUpgrades')->willReturn([
            'demo' => ['installed_version' => '1.0.0', 'bundled_version' => '1.1.0'],
        ]);
        $this->loader->method('listAllPlugins')->willReturn([
            ['name' => 'demo', 'enabled' => true,  'version' => '1.0.0', 'status' => 'booted'],
            ['name' => 'other', 'enabled' => false, 'version' => '2.0.0', 'status' => 'disabled'],
        ]);
        $this->controller = $this->makeControllerWithUpgrade($upgrade);
        $this->withCsrf();
        $_POST = ['action' => 'pluginsList', 'csrf_token' => 'x'];

        $r = $this->dispatch();
        $this->assertSame(200, $r['status']);
        $rows = array_column($r['payload']['plugins'], null, 'name');
        $this->assertArrayHasKey('upgrade_available', $rows['demo']);
        $this->assertSame('1.0.0', $rows['demo']['upgrade_available']['installed_version']);
        $this->assertSame('1.1.0', $rows['demo']['upgrade_available']['bundled_version']);
        $this->assertArrayNotHasKey('upgrade_available', $rows['other']);
    }

    private function makeControllerWithUpgrade(\Eiou\Services\Plugins\PluginUpgradeService $upgrade): CapturingPluginController
    {
        return new CapturingPluginController(
            $this->session,
            $this->loader,
            $this->restartRequester,
            null,
            $this->installService,
            $upgrade
        );
    }
}
