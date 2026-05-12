<?php
namespace Eiou\Tests\Services;

use Eiou\Services\PluginPoolService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 of plugin sandboxing — see docs/PLUGIN_SANDBOXING.md.
 *
 * PluginPoolService renders FPM pool configuration and orchestrates
 * the supervisor RPC. These tests cover both the rendering contract
 * (so a pool file's shape stays stable for review) and the executor
 * dispatch (so the supervisor protocol stays predictable).
 */
#[CoversClass(PluginPoolService::class)]
class PluginPoolServiceTest extends TestCase
{
    /** @var array<int, array{action:string, payload:array}> */
    private array $actionLog = [];

    private array $nextResult = ['status' => 'ok'];

    private PluginPoolService $svc;
    private string $tmpRoot;
    private string $template;
    private string $pluginRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actionLog = [];
        $this->nextResult = ['status' => 'ok'];

        // Tmp dispatcher template + plugin root so installDispatcher()
        // has somewhere to write into during applyPool() tests. Real
        // /etc/eiou/plugins/<id>/ doesn't exist in the test environment.
        $this->tmpRoot = sys_get_temp_dir() . '/eiou-pool-test-' . uniqid('', true);
        $this->pluginRoot = $this->tmpRoot . '/plugins';
        @mkdir($this->pluginRoot, 0777, true);
        $this->template = $this->tmpRoot . '/dispatch.php';
        file_put_contents($this->template, "<?php /* test dispatcher */");

        $executor = function (string $action, array $payload): array {
            $this->actionLog[] = ['action' => $action, 'payload' => $payload];
            return $this->nextResult;
        };
        $this->svc = new PluginPoolService(
            null,
            $executor,
            $this->template,
            $this->pluginRoot
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

    // ===================================================================
    // renderPoolConfig — security-critical content
    // ===================================================================

    #[Test]
    public function renderedPoolPinsUserGroupToTheSandboxedAccount(): void
    {
        $config = $this->svc->renderPoolConfig('hello-eiou', 'eiou-p-deadbeef');
        $this->assertStringContainsString('user = eiou-p-deadbeef', $config);
        $this->assertStringContainsString('group = eiou-p-deadbeef', $config);
    }

    #[Test]
    public function renderedPoolListensOnPerPluginSocket(): void
    {
        $config = $this->svc->renderPoolConfig('demo-plugin', 'eiou-p-abcdef01');
        $this->assertStringContainsString(
            'listen = /run/php/eiou-plugin-eiou-p-abcdef01.sock',
            $config
        );
        // nginx connects as www-data — the socket has to be readable by it.
        $this->assertStringContainsString('listen.owner = www-data', $config);
        $this->assertStringContainsString('listen.group = www-data', $config);
    }

    #[Test]
    public function openBasedirRestrictsToPluginDirAndScratch(): void
    {
        $config = $this->svc->renderPoolConfig('my-plugin', 'eiou-p-12345678');
        // open_basedir is the load-bearing file-access boundary — make
        // sure both the plugin source dir and its scratch dir are in
        // the allow-list, and nothing else from /etc/eiou/.
        $this->assertMatchesRegularExpression(
            '#open_basedir\]\s*=\s*/etc/eiou/plugins/my-plugin/:/var/lib/eiou/plugin-scratch/eiou-p-12345678/:/tmp/#',
            $config
        );
        $this->assertStringNotContainsString('/etc/eiou/config', $config);
    }

    #[Test]
    public function disableFunctionsBlocksShellOutAndCodeExecutionPaths(): void
    {
        $config = $this->svc->renderPoolConfig('my-plugin', 'eiou-p-12345678');
        // Every function listed in DISABLED_FUNCTIONS appears in the
        // rendered config. This list is the security contract, so if it
        // changes the renderer change has to be deliberate and reviewed.
        foreach (PluginPoolService::DISABLED_FUNCTIONS as $fn) {
            $this->assertStringContainsString($fn, $config);
        }
        // URL-fopen / URL-include explicitly off. Match the rendered
        // `php_admin_value[...]` wrapper exactly so an alignment-only
        // refactor still trips this assertion.
        $this->assertMatchesRegularExpression('#allow_url_fopen\]\s*=\s*0#', $config);
        $this->assertMatchesRegularExpression('#allow_url_include\]\s*=\s*0#', $config);
    }

    #[Test]
    public function rendererRejectsInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->renderPoolConfig('Has-Capitals', 'eiou-p-12345678');
    }

    #[Test]
    public function rendererRejectsInvalidSystemUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->renderPoolConfig('valid-id', 'root');
    }

    // ===================================================================
    // poolPath
    // ===================================================================

    #[Test]
    public function poolPathIsDeterministicAndInFpmPoolDir(): void
    {
        $path = $this->svc->poolPath('hello-eiou');
        $this->assertSame('/etc/php/8.3/fpm/pool.d/eiou-plugin-hello-eiou.conf', $path);
    }

    #[Test]
    public function poolPathRejectsInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->poolPath('../escape');
    }

    // ===================================================================
    // applyPool / dropPool dispatch
    // ===================================================================

    #[Test]
    public function applyPoolSendsRenderedConfigToExecutor(): void
    {
        @mkdir($this->pluginRoot . '/demo', 0755, true);
        $snippet = "# nginx snippet placeholder\n";
        $ok = $this->svc->applyPool('demo', 'eiou-p-12345678', $snippet);

        $this->assertTrue($ok);
        $this->assertCount(1, $this->actionLog);
        $this->assertSame('apply-pool', $this->actionLog[0]['action']);
        $payload = $this->actionLog[0]['payload'];
        $this->assertSame('demo', $payload['plugin_id']);
        $this->assertSame('eiou-p-12345678', $payload['system_user']);
        $this->assertStringContainsString('user = eiou-p-12345678', $payload['pool_config']);
        $this->assertSame($snippet, $payload['nginx_snippet']);
    }

    #[Test]
    public function applyPoolReturnsFalseOnExecutorFailure(): void
    {
        @mkdir($this->pluginRoot . '/demo', 0755, true);
        $this->nextResult = ['status' => 'failed', 'error' => 'nginx -t: bad block'];
        $this->assertFalse($this->svc->applyPool('demo', 'eiou-p-12345678', 'snippet'));
    }

    #[Test]
    public function applyPoolInstallsDispatcherBeforeSendingToSupervisor(): void
    {
        @mkdir($this->pluginRoot . '/demo', 0755, true);
        $this->assertTrue($this->svc->applyPool('demo', 'eiou-p-12345678', 'snippet'));
        // Dispatcher landed in the plugin's dir as __dispatch.php.
        $this->assertFileExists($this->pluginRoot . '/demo/__dispatch.php');
        $this->assertStringContainsString(
            'test dispatcher',
            (string) file_get_contents($this->pluginRoot . '/demo/__dispatch.php')
        );
    }

    #[Test]
    public function applyPoolRefusesWhenPluginDirMissing(): void
    {
        // /etc/eiou/plugins/ghost/ doesn't exist — installDispatcher
        // refuses, applyPool returns false, no supervisor call made.
        $this->assertFalse($this->svc->applyPool('ghost', 'eiou-p-12345678', 'snippet'));
        $this->assertSame([], $this->actionLog);
    }

    #[Test]
    public function dropPoolSendsCorrectPayload(): void
    {
        $snippet = "# nginx snippet without demo\n";
        $this->svc->dropPool('demo', $snippet);

        $this->assertCount(1, $this->actionLog);
        $this->assertSame('drop-pool', $this->actionLog[0]['action']);
        $this->assertSame('demo', $this->actionLog[0]['payload']['plugin_id']);
        $this->assertArrayNotHasKey('system_user', $this->actionLog[0]['payload']);
        $this->assertSame($snippet, $this->actionLog[0]['payload']['nginx_snippet']);
    }

    #[Test]
    public function applyPoolRefusesUnsafeSystemUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->applyPool('demo', 'root', 'snippet');
    }
}
