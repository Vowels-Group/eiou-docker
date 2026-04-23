<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use Eiou\Gui\Controllers\PluginController;
use Eiou\Gui\Controllers\PluginControllerResponseSent;
use Eiou\Gui\Includes\Session;
use Eiou\Services\PluginLoader;
use Eiou\Services\RestartRequestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass — captures the JSON response instead of echoing it.
 */
class CapturingPluginController extends PluginController
{
    /** @var array<int, array{status:int, payload:array<string,mixed>}> */
    public array $responses = [];

    protected function respond(array $payload, int $status = 200): void
    {
        $this->responses[] = ['status' => $status, 'payload' => $payload];
        throw new PluginControllerResponseSent($status);
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

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];

        $this->session = $this->createMock(Session::class);
        $this->loader = $this->createMock(PluginLoader::class);

        // Real RestartRequestService writing to a tmp file we can inspect.
        $this->restartFile = sys_get_temp_dir() . '/eiou-pc-test-' . uniqid() . '.json';
        $this->restartRequester = new RestartRequestService($this->restartFile);

        $this->controller = new CapturingPluginController(
            $this->session,
            $this->loader,
            $this->restartRequester
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->restartFile)) {
            @unlink($this->restartFile);
        }
        $_SESSION = [];
        $_POST = [];
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
        $this->assertSame('csrf_error', $result['payload']['error']);
    }

    #[Test]
    public function unknownActionReturns400(): void
    {
        $_POST = ['action' => 'pluginsBogus', 'csrf_token' => 'x'];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('unknown_action', $result['payload']['error']);
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
        $this->assertSame('not_found', $result['payload']['error']);
    }

    #[Test]
    public function pluginChangelogRejectsInvalidName(): void
    {
        $_POST = ['action' => 'pluginChangelog', 'csrf_token' => 'x', 'name' => '../etc/passwd'];
        $this->withCsrf();

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_name', $result['payload']['error']);
    }
}
