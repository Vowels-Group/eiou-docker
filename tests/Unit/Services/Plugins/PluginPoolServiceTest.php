<?php
namespace Eiou\Tests\Services\Plugins;

use Eiou\Services\Plugins\PluginGatewayTokenService;
use Eiou\Services\Plugins\PluginPoolService;
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
        // Token service writes into tmp so it doesn't touch /etc/eiou/config.
        $tokenService = new PluginGatewayTokenService(
            $this->tmpRoot . '/plugin-gateway-tokens.json',
            $this->pluginRoot
        );

        $this->svc = new PluginPoolService(
            null,
            $executor,
            $this->template,
            $this->pluginRoot,
            $tokenService
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
        // open_basedir is the load-bearing file-access boundary. The
        // allow-list admits: plugin source dir, scratch dir, the
        // plugin's OWN credentials file (exact path — no trailing slash
        // so sibling files under /etc/eiou/credentials/ aren't readable
        // even at the basedir layer), and /tmp/. The master key, the
        // rest of /etc/eiou/config/, and any other plugin's credentials
        // file remain unreachable.
        $this->assertMatchesRegularExpression(
            '#open_basedir\]\s*=\s*/etc/eiou/plugins/my-plugin/:/var/lib/eiou/plugin-scratch/eiou-p-12345678/:/etc/eiou/credentials/plugin-my-plugin\.json:/tmp/#',
            $config
        );
        $this->assertStringNotContainsString('/etc/eiou/config', $config);
        // Defence in depth — exact path, not the directory.
        $this->assertStringNotContainsString('/etc/eiou/credentials/:', $config);
        $this->assertStringNotContainsString('/etc/eiou/credentials/ ', $config);
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
        // The version directory is resolved at runtime via glob() to
        // match whatever PHP version the image has installed. Test the
        // shape, not a specific version pin.
        $this->assertMatchesRegularExpression(
            '#^/etc/php/[0-9]\.[0-9]+/fpm/pool\.d/eiou-plugin-hello-eiou\.conf$#',
            $path
        );
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
    public function applyPoolDoesNotCommitTokenIndexWhenSupervisorFails(): void
    {
        // Regression guard for the token-sync bug. Pre-fix code called
        // mintAndIndex() up front, so a supervisor failure left the
        // index holding a token the per-plugin file never received —
        // dispatcher's bearer was forever stale until an operator ran
        // a manual sync. With the fix, the candidate token is held in
        // memory until the supervisor returns ok; on failure, the
        // index is unchanged.
        @mkdir($this->pluginRoot . '/demo', 0755, true);
        $tokensPath = $this->tmpRoot . '/plugin-gateway-tokens.json';

        $this->nextResult = ['status' => 'failed', 'error' => 'nginx -t: bad block'];
        $this->assertFalse($this->svc->applyPool('demo', 'eiou-p-12345678', 'snippet'));

        // No index file was written, OR if it exists it has no entry
        // for 'demo'. Either represents "supervisor failure didn't
        // commit drift" — which is the property under test.
        if (is_file($tokensPath)) {
            $index = json_decode((string) file_get_contents($tokensPath), true) ?: [];
            $entriesForDemo = array_filter($index, fn($v) => $v === 'demo');
            $this->assertSame([], $entriesForDemo, 'no index entry was committed for failed apply');
        }

        // Subsequent successful apply commits cleanly.
        $this->nextResult = ['status' => 'ok'];
        $this->assertTrue($this->svc->applyPool('demo', 'eiou-p-12345678', 'snippet'));
        $this->assertFileExists($tokensPath);
        $index = json_decode((string) file_get_contents($tokensPath), true);
        $this->assertContains('demo', $index, 'successful apply commits exactly one entry');
    }

    #[Test]
    public function applyPoolCommitsTokenIndexOnlyAfterSupervisorOk(): void
    {
        // Walk through the sequencing: mint() happens before the
        // executor is called (the payload carries the candidate
        // token), but commitToken() only runs after ok. Verified by
        // asserting the index is empty during the executor callback
        // and populated after applyPool returns.
        @mkdir($this->pluginRoot . '/demo', 0755, true);
        $tokensPath = $this->tmpRoot . '/plugin-gateway-tokens.json';

        $sawIndexBeforeCommit = null;
        $observer = function (string $action, array $payload) use ($tokensPath, &$sawIndexBeforeCommit): array {
            $this->actionLog[] = ['action' => $action, 'payload' => $payload];
            $sawIndexBeforeCommit = is_file($tokensPath)
                ? json_decode((string) file_get_contents($tokensPath), true)
                : null;
            return ['status' => 'ok'];
        };

        $tokenService = new \Eiou\Services\Plugins\PluginGatewayTokenService(
            $tokensPath,
            $this->pluginRoot
        );
        $svc = new \Eiou\Services\Plugins\PluginPoolService(
            null,
            $observer,
            $this->template,
            $this->pluginRoot,
            $tokenService
        );

        $this->assertTrue($svc->applyPool('demo', 'eiou-p-12345678', 'snippet'));
        // While the executor was running, the index either didn't
        // exist or had no entry for 'demo' (commit happens AFTER).
        if ($sawIndexBeforeCommit !== null) {
            $this->assertNotContains('demo', $sawIndexBeforeCommit);
        }
        // Post-call: the candidate token shipped to the supervisor is
        // also the one now in the index.
        $candidate = $this->actionLog[0]['payload']['gateway_token'];
        $index = json_decode((string) file_get_contents($tokensPath), true);
        $this->assertSame('demo', $index[$candidate]);
    }

    #[Test]
    public function applyPoolRotatesTokenWhenForced(): void
    {
        @mkdir($this->pluginRoot . '/demo', 0755, true);
        // First apply mints a token and ships it through the
        // supervisor (which writes the per-plugin file as root). The
        // mock executor here just records the payload — the on-disk
        // file is asserted on the integration side.
        $this->assertTrue($this->svc->applyPool('demo', 'eiou-p-12345678', 'snippet'));
        $this->assertArrayHasKey('gateway_token', $this->actionLog[0]['payload']);
        $first = $this->actionLog[0]['payload']['gateway_token'];
        $this->assertNotEmpty($first);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);

        // Simulate the supervisor having written the per-plugin file
        // so the second apply's "mint-if-missing" branch sees it on
        // disk and skips the rotation.
        file_put_contents($this->pluginRoot . '/demo/.gateway-token', $first);

        // Idempotent applyPool — no new token shipped (file exists
        // and rotation wasn't forced).
        $this->assertTrue($this->svc->applyPool('demo', 'eiou-p-12345678', 'snippet'));
        $this->assertArrayNotHasKey('gateway_token', $this->actionLog[1]['payload']);

        // Force-rotate — new token shipped, distinct from the first.
        $this->assertTrue($this->svc->applyPool(
            'demo', 'eiou-p-12345678', 'snippet', true
        ));
        $this->assertArrayHasKey('gateway_token', $this->actionLog[2]['payload']);
        $rotated = $this->actionLog[2]['payload']['gateway_token'];
        $this->assertNotSame($first, $rotated);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rotated);
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
    public function isPoolUpToDateReturnsFalseWhenPoolMissing(): void
    {
        // No pool config has been written to /etc/php/*/fpm/pool.d/ —
        // expected behavior in any test environment. isPoolUpToDate
        // returns false → reconcileSandbox will trigger applyPool.
        $this->assertFalse($this->svc->isPoolUpToDate('missing', 'eiou-p-deadbeef', 'snippet'));
    }

    #[Test]
    public function isPoolUpToDateReturnsFalseForInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->isPoolUpToDate('Bad-Caps', 'eiou-p-deadbeef', 'snippet');
    }

    #[Test]
    public function dispatcherStaleVersionLogsDeprecationWarning(): void
    {
        // Set the template to v2.
        file_put_contents($this->template, "<?php const PLUGIN_DISPATCH_VERSION = 2;\n// template body\n");
        @mkdir($this->pluginRoot . '/oldplug', 0755, true);
        // Plugin ships a v1 dispatcher — should warn but not overwrite.
        file_put_contents(
            $this->pluginRoot . '/oldplug/__dispatch.php',
            "<?php const PLUGIN_DISPATCH_VERSION = 1;\n// old plugin body\n"
        );

        $captured = [];
        $logger = $this->createMock(\Eiou\Utils\Logger::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $msg, array $ctx) use (&$captured): void {
                $captured[] = ['msg' => $msg, 'ctx' => $ctx];
            });
        $svc = new \Eiou\Services\Plugins\PluginPoolService(
            $logger,
            fn() => ['status' => 'ok'],
            $this->template,
            $this->pluginRoot,
            new \Eiou\Services\Plugins\PluginGatewayTokenService(
                $this->tmpRoot . '/tokens.json',
                $this->pluginRoot
            )
        );
        $svc->applyPool('oldplug', 'eiou-p-12345678', 'snippet');

        $warning = null;
        foreach ($captured as $c) {
            if ($c['msg'] === 'plugin_dispatcher_version_stale') { $warning = $c; break; }
        }
        $this->assertNotNull($warning, 'Expected version-stale warning');
        $this->assertSame(1, $warning['ctx']['bundled_version']);
        $this->assertSame(2, $warning['ctx']['current_version']);

        // File NOT overwritten — plugin author owns it.
        $contents = (string) file_get_contents($this->pluginRoot . '/oldplug/__dispatch.php');
        $this->assertStringContainsString('old plugin body', $contents);
    }

    #[Test]
    public function dispatcherCurrentVersionDoesNotWarn(): void
    {
        file_put_contents($this->template, "<?php const PLUGIN_DISPATCH_VERSION = 1;\n");
        @mkdir($this->pluginRoot . '/current', 0755, true);
        file_put_contents(
            $this->pluginRoot . '/current/__dispatch.php',
            "<?php const PLUGIN_DISPATCH_VERSION = 1;\n"
        );

        $captured = [];
        $logger = $this->createMock(\Eiou\Utils\Logger::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $msg) use (&$captured): void {
                $captured[] = $msg;
            });
        $svc = new \Eiou\Services\Plugins\PluginPoolService(
            $logger,
            fn() => ['status' => 'ok'],
            $this->template,
            $this->pluginRoot,
            new \Eiou\Services\Plugins\PluginGatewayTokenService(
                $this->tmpRoot . '/tokens.json',
                $this->pluginRoot
            )
        );
        $svc->applyPool('current', 'eiou-p-12345678', 'snippet');

        $this->assertNotContains('plugin_dispatcher_version_stale', $captured);
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
