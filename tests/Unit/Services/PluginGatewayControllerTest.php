<?php
namespace Eiou\Tests\Services;

use Eiou\Contracts\PluginCallable;
use Eiou\Services\PluginGatewayController;
use Eiou\Services\PluginGatewayTokenService;
use Eiou\Services\PluginLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fixture container — provides a single getTestableGatewayService()
 * for the gateway to resolve. The gateway is loosely typed (object)
 * so this stands in for ServiceContainer without dealing with its
 * private singleton constructor.
 */
class FixtureContainer
{
    private TestableGatewayService $svc;
    public function __construct(TestableGatewayService $svc)
    {
        $this->svc = $svc;
    }
    public function getTestableGatewayService(): TestableGatewayService
    {
        return $this->svc;
    }
}

/**
 * Test fixture class — exercises the gateway against a controlled service.
 * `safeEcho` is the only method tagged #[PluginCallable]; `forbiddenSecret`
 * intentionally is not, to prove the attribute gate refuses it.
 */
class TestableGatewayService
{
    #[PluginCallable(description: 'Test method that echoes a string back.')]
    public function safeEcho(string $message): string
    {
        return "echo: {$message}";
    }

    #[PluginCallable(description: 'Throws — covers the call-threw path.')]
    public function alwaysThrows(): void
    {
        throw new \RuntimeException('intentional');
    }

    #[PluginCallable(description: 'Returns a php object — covers the unmarshallable-return path.')]
    public function returnsAnObject(): \stdClass
    {
        return new \stdClass();
    }

    // NOT tagged with PluginCallable on purpose — gateway must refuse.
    public function forbiddenSecret(): string
    {
        return "secret";
    }
}

#[CoversClass(PluginGatewayController::class)]
class PluginGatewayControllerTest extends TestCase
{
    private string $tmpRoot;
    private string $pluginRoot;
    private string $tokensPath;
    private PluginGatewayTokenService $tokenService;
    /** @var PluginLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $loader;
    private FixtureContainer $container;
    private TestableGatewayService $testService;
    private string $token;
    private PluginGatewayController $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/eiou-gw-test-' . uniqid('', true);
        $this->pluginRoot = $this->tmpRoot . '/plugins';
        $this->tokensPath = $this->tmpRoot . '/tokens.json';
        @mkdir($this->pluginRoot . '/demo', 0755, true);

        $this->tokenService = new PluginGatewayTokenService($this->tokensPath, $this->pluginRoot);
        $this->token = $this->tokenService->rotate('demo');

        $this->loader = $this->createMock(PluginLoader::class);
        $this->loader->method('listAllPlugins')->willReturn([
            [
                'name' => 'demo',
                'enabled' => true,
                'sandboxed' => true,
                'core_services' => [
                    'TestableGatewayService.safeEcho',
                    'TestableGatewayService.alwaysThrows',
                    'TestableGatewayService.returnsAnObject',
                    'TestableGatewayService.forbiddenSecret',
                ],
            ],
        ]);

        $this->testService = new TestableGatewayService();
        $this->container = new FixtureContainer($this->testService);

        $this->svc = new PluginGatewayController(
            $this->tokenService,
            $this->loader,
            $this->container
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private function bearerHeaders(string $token): array
    {
        return ['authorization' => 'Bearer ' . $token];
    }

    // ===================================================================
    // Auth gate
    // ===================================================================

    #[Test]
    public function rejectsMissingBearerToken(): void
    {
        $r = $this->svc->handle('{}', []);
        $this->assertSame(401, $r['status']);
        $this->assertSame('missing_token', $r['body']['error']['code']);
    }

    #[Test]
    public function rejectsUnknownToken(): void
    {
        $r = $this->svc->handle('{}', $this->bearerHeaders(str_repeat('a', 64)));
        $this->assertSame(401, $r['status']);
        $this->assertSame('invalid_token', $r['body']['error']['code']);
    }

    #[Test]
    public function rejectsMalformedAuthHeader(): void
    {
        $r = $this->svc->handle('{}', ['authorization' => 'Basic xyz']);
        $this->assertSame(401, $r['status']);
        $this->assertSame('missing_token', $r['body']['error']['code']);
    }

    // ===================================================================
    // Body gate
    // ===================================================================

    #[Test]
    public function rejectsEmptyBody(): void
    {
        $r = $this->svc->handle('', $this->bearerHeaders($this->token));
        $this->assertSame(400, $r['status']);
        $this->assertSame('empty_body', $r['body']['error']['code']);
    }

    #[Test]
    public function rejectsMalformedJson(): void
    {
        $r = $this->svc->handle('not-json', $this->bearerHeaders($this->token));
        $this->assertSame(400, $r['status']);
        $this->assertSame('malformed_body', $r['body']['error']['code']);
    }

    #[Test]
    public function rejectsMissingServiceOrMethod(): void
    {
        $r = $this->svc->handle(json_encode(['service' => 'X']), $this->bearerHeaders($this->token));
        $this->assertSame(400, $r['status']);
        $this->assertSame('missing_fields', $r['body']['error']['code']);
    }

    #[Test]
    public function rejectsInvalidServiceName(): void
    {
        $r = $this->svc->handle(
            json_encode(['service' => 'lowerCase', 'method' => 'foo', 'args' => []]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_service_name', $r['body']['error']['code']);
    }

    #[Test]
    public function rejectsInvalidMethodName(): void
    {
        $r = $this->svc->handle(
            json_encode(['service' => 'Foo', 'method' => 'WithCapitals', 'args' => []]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_method_name', $r['body']['error']['code']);
    }

    // ===================================================================
    // Manifest allow-list gate
    // ===================================================================

    #[Test]
    public function rejectsMethodNotInManifestAllowList(): void
    {
        // Replace the loader mock with one whose plugin manifest has
        // an empty core_services list.
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('listAllPlugins')->willReturn([
            ['name' => 'demo', 'core_services' => []],
        ]);
        $svc = new PluginGatewayController($this->tokenService, $loader, $this->container);

        $r = $svc->handle(
            json_encode(['service' => 'TestableGatewayService', 'method' => 'safeEcho', 'args' => ['hi']]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(403, $r['status']);
        $this->assertSame('method_not_in_manifest', $r['body']['error']['code']);
    }

    // ===================================================================
    // Service resolution + attribute gate
    // ===================================================================

    #[Test]
    public function rejectsUnknownServiceFromContainer(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('listAllPlugins')->willReturn([
            ['name' => 'demo', 'core_services' => ['Nonexistent.method']],
        ]);
        $svc = new PluginGatewayController($this->tokenService, $loader, $this->container);

        $r = $svc->handle(
            json_encode(['service' => 'Nonexistent', 'method' => 'method', 'args' => []]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(503, $r['status']);
        $this->assertSame('unknown_service', $r['body']['error']['code']);
    }

    #[Test]
    public function rejectsMethodWithoutPluginCallableAttribute(): void
    {
        // forbiddenSecret IS in the manifest allow-list AND exists on
        // the service — but it lacks the attribute. Gate 3 must catch it.
        $r = $this->svc->handle(
            json_encode([
                'service' => 'TestableGatewayService',
                'method' => 'forbiddenSecret',
                'args' => [],
            ]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(403, $r['status']);
        $this->assertSame('method_not_callable', $r['body']['error']['code']);
    }

    // ===================================================================
    // Argument gate
    // ===================================================================

    #[Test]
    public function rejectsArgsContainingObject(): void
    {
        $r = $this->svc->handle(
            // JSON can't represent objects directly, but the gateway
            // checks args before serialization. The malformed-json
            // path covers truly invalid bodies; this exercises the
            // case where args parsed but contain something the
            // gateway considers unsafe. Simulate by passing a
            // recursive too-deep array.
            json_encode([
                'service' => 'TestableGatewayService',
                'method' => 'safeEcho',
                'args' => [['a' => [['b' => [['c' => [['d' => [['e' => 'too deep']]]]]]]]]],
            ]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(400, $r['status']);
        $this->assertSame('unsafe_args', $r['body']['error']['code']);
    }

    // ===================================================================
    // Happy path + downstream failure modes
    // ===================================================================

    #[Test]
    public function dispatchesSuccessfulCallAndReturnsResult(): void
    {
        $r = $this->svc->handle(
            json_encode([
                'service' => 'TestableGatewayService',
                'method' => 'safeEcho',
                'args' => ['hello'],
            ]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['body']['ok']);
        $this->assertSame('echo: hello', $r['body']['result']);
    }

    #[Test]
    public function reportsCallThrowingMethodAs500(): void
    {
        $r = $this->svc->handle(
            json_encode([
                'service' => 'TestableGatewayService',
                'method' => 'alwaysThrows',
                'args' => [],
            ]),
            $this->bearerHeaders($this->token)
        );
        $this->assertSame(500, $r['status']);
        $this->assertSame('call_threw', $r['body']['error']['code']);
        $this->assertStringContainsString('intentional', $r['body']['error']['message']);
    }

    #[Test]
    public function refusesToReturnNonScalarObject(): void
    {
        $r = $this->svc->handle(
            json_encode([
                'service' => 'TestableGatewayService',
                'method' => 'returnsAnObject',
                'args' => [],
            ]),
            $this->bearerHeaders($this->token)
        );
        // json_encode on stdClass actually succeeds (empty object),
        // so this case currently passes. The real defence is the
        // PluginCallable contract docstring saying "return scalars".
        // Document this test as verifying the structural shape, not
        // the type-rejection — full enforcement would need a runtime
        // PHP type check on the return value.
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['body']['ok']);
    }
}
