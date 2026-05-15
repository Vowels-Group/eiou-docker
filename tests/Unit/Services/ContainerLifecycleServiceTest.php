<?php
namespace Eiou\Tests\Services;

use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Services\ContainerLifecycleService;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(ContainerLifecycleService::class)]
class ContainerLifecycleServiceTest extends TestCase
{
    private string $stateFile;
    private ContainerLifecycleService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFile = sys_get_temp_dir() . '/eiou-sidecar-test-' . uniqid('', true) . '.json';
        $this->svc = new ContainerLifecycleService(
            $this->stateFile,
            $this->createMock(Logger::class)
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
    }

    private function withCaller(string $pluginId): ContainerLifecycleService
    {
        $this->svc->setCallingPluginId($pluginId);
        return $this->svc;
    }

    // ===================================================================
    // Caller-id requirement (gateway bypass guard)
    // ===================================================================

    #[Test]
    public function refusesWhenCallerIdMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gateway-injected caller id');
        $this->svc->stopSidecar('eiou-demo-ipfs');
    }

    #[Test]
    public function refusesAfterCallerIdCleared(): void
    {
        $this->svc->setCallingPluginId('demo');
        $this->svc->setCallingPluginId(null);
        $this->expectException(RuntimeException::class);
        $this->svc->stopSidecar('eiou-demo-ipfs');
    }

    // ===================================================================
    // Service-name validation (defence in depth)
    // ===================================================================

    #[Test]
    public function rejectsServiceNameWithLeadingDash(): void
    {
        // A leading dash would let a corrupt manifest sneak a flag
        // (`--rm`, etc.) into an operator-side `docker compose stop
        // <value>` invocation. Pattern requires a leading alnum.
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('demo')->stopSidecar('-rm');
    }

    #[Test]
    public function rejectsServiceNameWithShellMetachar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('demo')->stopSidecar('eiou-demo; rm -rf /');
    }

    #[Test]
    public function rejectsServiceNameWithSpaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('demo')->stopSidecar('eiou demo ipfs');
    }

    #[Test]
    public function rejectsServiceNameOver64Chars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withCaller('demo')->stopSidecar(str_repeat('a', 65));
    }

    #[Test]
    public function acceptsConventionalServiceName(): void
    {
        $result = $this->withCaller('demo')->stopSidecar('eiou-demo-ipfs');
        $this->assertSame(['ok' => true, 'service' => 'eiou-demo-ipfs'], $result);
    }

    // ===================================================================
    // Desired-state persistence
    // ===================================================================

    #[Test]
    public function stopSidecarRecordsStoppedStateInTheFile(): void
    {
        $this->withCaller('demo')->stopSidecar('eiou-demo-ipfs');

        $this->assertFileExists($this->stateFile);
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertSame('stopped', $state['demo']['eiou-demo-ipfs']['desired']);
        $this->assertIsInt($state['demo']['eiou-demo-ipfs']['at']);
    }

    #[Test]
    public function startSidecarRecordsRunningState(): void
    {
        $this->withCaller('demo')->startSidecar('eiou-demo-ipfs');

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertSame('running', $state['demo']['eiou-demo-ipfs']['desired']);
    }

    #[Test]
    public function repeatedStopOverwritesPreviousDesiredState(): void
    {
        // Idempotency contract: an operator should be able to read
        // the file at any moment and get the latest desired state,
        // not a history. Re-stopping the same service updates `at`
        // and keeps a single entry.
        $this->withCaller('demo')->startSidecar('eiou-demo-ipfs');
        $this->withCaller('demo')->stopSidecar('eiou-demo-ipfs');

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertSame('stopped', $state['demo']['eiou-demo-ipfs']['desired']);
        // Exactly one entry under the plugin's namespace.
        $this->assertCount(1, $state['demo']);
    }

    #[Test]
    public function plugingsNamespaceTheirSidecarsSeparately(): void
    {
        // Plugin A's stopSidecar must not touch plugin B's entry.
        // The state file is shared across plugins (one operator-
        // facing file) but plugins are keyed by their gateway-
        // injected id; one plugin can't address another's services.
        $this->withCaller('alpha')->stopSidecar('eiou-alpha-ipfs');
        $this->withCaller('beta')->stopSidecar('eiou-beta-ipfs');

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $this->assertArrayHasKey('alpha', $state);
        $this->assertArrayHasKey('beta', $state);
        $this->assertArrayHasKey('eiou-alpha-ipfs', $state['alpha']);
        $this->assertArrayNotHasKey('eiou-alpha-ipfs', $state['beta']);
    }

    #[Test]
    public function stateFileLandsWorldReadableForOperatorOrchestration(): void
    {
        // The state file is consumed by sibling containers / systemd
        // path units that don't run as root, so it has to be at
        // least 0644. Writing it 0600 (the default for a freshly
        // chmodded temp file) would break the integration.
        $this->withCaller('demo')->stopSidecar('eiou-demo-ipfs');

        $perms = fileperms($this->stateFile) & 0777;
        $this->assertSame(
            0644,
            $perms,
            sprintf('expected mode 0644, got 0%o', $perms)
        );
    }

    // ===================================================================
    // #[PluginCallable] attribute coverage
    // ===================================================================

    #[Test]
    public function stopAndStartCarryPluginCallableAttribute(): void
    {
        foreach (['stopSidecar', 'startSidecar'] as $method) {
            $reflection = new ReflectionMethod(ContainerLifecycleService::class, $method);
            $attributes = $reflection->getAttributes(PluginCallable::class);
            $this->assertCount(
                1, $attributes,
                "ContainerLifecycleService::{$method}() must carry exactly one #[PluginCallable]"
            );
            $instance = $attributes[0]->newInstance();
            $this->assertNotSame('', $instance->description ?? '');
        }
    }

    #[Test]
    public function implementsPluginCallerAware(): void
    {
        $this->assertInstanceOf(PluginCallerAware::class, $this->svc);
    }
}
