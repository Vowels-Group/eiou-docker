<?php
namespace Eiou\Tests\Services;

use Eiou\Core\AppConfig;
use Eiou\Events\EventDispatcher;
use Eiou\Services\Hooks;
use Eiou\Services\GuiActionRegistry;
use Eiou\Services\PluginAssetRegistry;
use Eiou\Services\PluginIpcForwarder;
use Eiou\Services\PluginLoader;
use Eiou\Services\TabRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 of plugin sandboxing — exercises the bridge from in-process
 * event/filter/render firing to sandboxed plugins' __dispatch.php HTTP
 * endpoints. The HTTP client is mocked via the constructor seam so no
 * actual sockets are touched.
 *
 * See docs/PLUGIN_SANDBOXING.md.
 */
#[CoversClass(PluginIpcForwarder::class)]
class PluginIpcForwarderTest extends TestCase
{
    /** @var array<int, array{url:string, body:array}> */
    private array $httpLog = [];

    private array $nextResponse = [
        'ok' => true, 'status' => 200,
        'body' => ['ok' => true, 'result' => null],
    ];

    /** @var PluginLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $loader;
    private Hooks $hooks;
    private PluginIpcForwarder $svc;

    protected function setUp(): void
    {
        parent::setUp();
        EventDispatcher::resetInstance();
        $this->httpLog = [];

        $this->loader = $this->createMock(PluginLoader::class);
        $this->hooks = new Hooks(AppConfig::fromEnvironment());
        $this->svc = new PluginIpcForwarder(
            $this->loader,
            null,
            function (string $url, string $body): array {
                $decoded = json_decode($body, true);
                $this->httpLog[] = ['url' => $url, 'body' => is_array($decoded) ? $decoded : []];
                return $this->nextResponse;
            }
        );
    }

    protected function tearDown(): void
    {
        EventDispatcher::resetInstance();
    }

    private function pluginRow(string $name, array $extra): array
    {
        return array_merge([
            'name' => $name, 'enabled' => true, 'sandboxed' => true,
            'subscribes_to' => [], 'filter_hooks' => [], 'render_hooks' => [],
        ], $extra);
    }

    // ===================================================================
    // Registration filters
    // ===================================================================

    #[Test]
    public function skipsPluginsNotEnabled(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('disabled', ['enabled' => false, 'subscribes_to' => ['sync.completed']]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['events']);
    }

    #[Test]
    public function skipsPluginsNotSandboxed(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('legacy', ['sandboxed' => false, 'subscribes_to' => ['sync.completed']]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['events']);
    }

    // ===================================================================
    // Event forwarding
    // ===================================================================

    #[Test]
    public function dispatchesEventToPluginViaHttp(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', ['subscribes_to' => ['sync.completed']]),
        ]);
        $this->svc->registerAll($this->hooks);

        EventDispatcher::getInstance()->dispatch('sync.completed', ['contact_pubkey' => 'abc']);

        $this->assertCount(1, $this->httpLog);
        $this->assertStringContainsString('/gui/plugin/hello-eiou/__dispatch', $this->httpLog[0]['url']);
        $this->assertSame('event', $this->httpLog[0]['body']['type']);
        $this->assertSame('sync.completed', $this->httpLog[0]['body']['name']);
        $this->assertSame(['contact_pubkey' => 'abc'], $this->httpLog[0]['body']['context']['data']);
    }

    #[Test]
    public function multiplePluginsBothReceiveTheSameEvent(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('alpha', ['subscribes_to' => ['sync.completed']]),
            $this->pluginRow('beta',  ['subscribes_to' => ['sync.completed']]),
        ]);
        $this->svc->registerAll($this->hooks);

        EventDispatcher::getInstance()->dispatch('sync.completed', []);
        $this->assertCount(2, $this->httpLog);
        $urls = array_column($this->httpLog, 'url');
        $this->assertStringContainsString('alpha', $urls[0]);
        $this->assertStringContainsString('beta', $urls[1]);
    }

    #[Test]
    public function eventTransportFailureDoesNotBreakChain(): void
    {
        $this->nextResponse = ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'connection refused'];
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('flaky', ['subscribes_to' => ['sync.completed']]),
        ]);
        $this->svc->registerAll($this->hooks);

        // Dispatch must not throw.
        EventDispatcher::getInstance()->dispatch('sync.completed', []);
        $this->assertTrue(true); // got here without exception
    }

    // ===================================================================
    // Filter forwarding
    // ===================================================================

    #[Test]
    public function filterReceivesValueAndAppliesReturnedResult(): void
    {
        $this->nextResponse = [
            'ok' => true, 'status' => 200,
            'body' => ['ok' => true, 'result' => ['added' => true]],
        ];
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', ['filter_hooks' => ['gui.dashboard.widgets']]),
        ]);
        $this->svc->registerAll($this->hooks);

        $out = $this->hooks->applyFilter('gui.dashboard.widgets', ['initial' => true]);
        $this->assertSame(['added' => true], $out);
        $this->assertSame('filter', $this->httpLog[0]['body']['type']);
        $this->assertSame(['initial' => true], $this->httpLog[0]['body']['context']['value']);
    }

    #[Test]
    public function filterFailurePassesInputThroughUnchanged(): void
    {
        $this->nextResponse = ['ok' => false, 'status' => 500, 'body' => null, 'error' => 'boom'];
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('flaky', ['filter_hooks' => ['gui.dashboard.widgets']]),
        ]);
        $this->svc->registerAll($this->hooks);

        $original = ['only' => 'one'];
        $out = $this->hooks->applyFilter('gui.dashboard.widgets', $original);
        $this->assertSame($original, $out, 'On failure the filter chain must pass input through unchanged');
    }

    // ===================================================================
    // Render forwarding
    // ===================================================================

    #[Test]
    public function renderConcatenatesPluginHtmlIntoOutput(): void
    {
        $this->nextResponse = [
            'ok' => true, 'status' => 200,
            'body' => ['ok' => true, 'result' => '<p>fortune of the day</p>'],
        ];
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', ['render_hooks' => ['gui.dashboard.after']]),
        ]);
        $this->svc->registerAll($this->hooks);

        $out = $this->hooks->doRender('gui.dashboard.after');
        $this->assertStringContainsString('<p>fortune of the day</p>', $out);
    }

    #[Test]
    public function renderFailureContributesEmptyString(): void
    {
        $this->nextResponse = ['ok' => false, 'status' => 0, 'body' => null];
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('flaky', ['render_hooks' => ['gui.dashboard.after']]),
        ]);
        $this->svc->registerAll($this->hooks);

        $out = $this->hooks->doRender('gui.dashboard.after');
        // The render hook MUST return a string. Empty is the only safe
        // fallback when the plugin's dispatch fails.
        $this->assertSame('', $out);
    }

    // ===================================================================
    // GUI action IPC (Phase 5e)
    // ===================================================================

    #[Test]
    public function actionRegistrationProducesAWorkingIpcHandler(): void
    {
        $this->nextResponse = [
            'ok' => true, 'status' => 200,
            'body' => ['ok' => true, 'result' => ['success' => true, 'fortune' => 'test']],
        ];
        $actions = new GuiActionRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', [
                'gui_actions' => [['name' => 'helloEiouFortune', 'tier' => 'csrf']],
            ]),
        ]);

        $report = $this->svc->registerAll($this->hooks, null, null, $actions);

        $this->assertSame([['plugin' => 'hello-eiou', 'name' => 'helloEiouFortune']], $report['actions']);
        $this->assertTrue($actions->has('helloEiouFortune'));
        $this->assertSame('csrf', $actions->getTier('helloEiouFortune'));
        $this->assertSame('hello-eiou', $actions->getPluginId('helloEiouFortune'));

        // Invoke the handler — captures stdout to verify the JSON
        // envelope. headers_sent() can't be exercised in CLI tests; we
        // just check the body got echoed.
        $handler = $actions->getHandler('helloEiouFortune');
        ob_start();
        $handler(['arg' => 'foo']);
        $body = (string) ob_get_clean();

        $this->assertJson($body);
        $payload = json_decode($body, true);
        $this->assertTrue($payload['success']);
        $this->assertSame('test', $payload['fortune']);

        // Forwarder posted POST data inside context.post.
        $this->assertSame('action', $this->httpLog[0]['body']['type']);
        $this->assertSame('helloEiouFortune', $this->httpLog[0]['body']['name']);
        $this->assertSame(['arg' => 'foo'], $this->httpLog[0]['body']['context']['post']);
    }

    #[Test]
    public function actionPluginUnavailableSurfacesAs502Body(): void
    {
        $this->nextResponse = ['ok' => false, 'status' => 0, 'body' => null];
        $actions = new GuiActionRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('flaky', ['gui_actions' => [['name' => 'doSomething']]]),
        ]);

        $this->svc->registerAll($this->hooks, null, null, $actions);

        ob_start();
        ($actions->getHandler('doSomething'))([]);
        $body = (string) ob_get_clean();

        $payload = json_decode($body, true);
        $this->assertSame('plugin_unavailable', $payload['error']);
    }

    #[Test]
    public function actionRegistryIsOptional(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('demo', ['gui_actions' => [['name' => 'foo']]]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['actions']);
    }

    // ===================================================================
    // Tab IPC (Phase 5d)
    // ===================================================================

    #[Test]
    public function tabRegistrationProducesAWorkingIpcRender(): void
    {
        $this->nextResponse = [
            'ok' => true, 'status' => 200,
            'body' => ['ok' => true, 'result' => '<div>tab body from plugin</div>'],
        ];
        $tabs = new TabRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', [
                'tabs' => [
                    ['id' => 'fortunes', 'label' => 'Fortunes', 'icon' => 'fa-x', 'order' => 45],
                ],
            ]),
        ]);

        $report = $this->svc->registerAll($this->hooks, null, $tabs);

        $this->assertSame([['plugin' => 'hello-eiou', 'id' => 'fortunes']], $report['tabs']);

        $registered = $tabs->find('fortunes');
        $this->assertNotNull($registered);
        $this->assertSame('Fortunes', $registered['label']);
        $this->assertSame(45, $registered['order']);

        // The render closure IPCs to the plugin and returns the result.
        $html = ($registered['render'])();
        $this->assertSame('<div>tab body from plugin</div>', $html);
        // Verify the envelope shape — name carries the tab: prefix so
        // the dispatcher can disambiguate from regular render hooks.
        $this->assertSame('render', $this->httpLog[0]['body']['type']);
        $this->assertSame('tab:fortunes', $this->httpLog[0]['body']['name']);
    }

    #[Test]
    public function tabRegistrationDefaultsMissingIconAndOrder(): void
    {
        $tabs = new TabRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            // Manifest only required id + label per Phase 5a's shape
            // validator. Icon + order must default to keep TabRegistry
            // happy.
            $this->pluginRow('demo', [
                'tabs' => [['id' => 'minimal', 'label' => 'Minimal']],
            ]),
        ]);

        $this->svc->registerAll($this->hooks, null, $tabs);

        $entry = $tabs->find('minimal');
        $this->assertNotNull($entry, 'tab with minimal manifest entry must register');
        $this->assertSame('fas fa-puzzle-piece', $entry['icon']);
        $this->assertSame(50, $entry['order']);
    }

    #[Test]
    public function tabRegistryIsOptional(): void
    {
        // Test scaffolds that only care about events keep working.
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('demo', [
                'tabs' => [['id' => 'will-skip', 'label' => 'Skipped']],
            ]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['tabs']);
    }

    // ===================================================================
    // Declarative gui_assets (Phase 5h) — no IPC, direct registry call
    // ===================================================================

    #[Test]
    public function declaredCssAssetEnqueuesIntoAssetRegistry(): void
    {
        $assets = new PluginAssetRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', [
                'gui_assets' => [
                    ['type' => 'css', 'path' => 'assets/styles.css'],
                ],
            ]),
        ]);
        $report = $this->svc->registerAll($this->hooks, $assets);

        $this->assertSame(
            [['plugin' => 'hello-eiou', 'type' => 'css', 'path' => 'assets/styles.css']],
            $report['assets']
        );
        $styles = $assets->listStyles();
        $this->assertCount(1, $styles);
        $this->assertSame('hello-eiou', $styles[0]['pluginId']);
        $this->assertSame('assets/styles.css', $styles[0]['relPath']);
    }

    #[Test]
    public function declaredJsAssetEnqueuesAsScript(): void
    {
        $assets = new PluginAssetRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', [
                'gui_assets' => [
                    ['type' => 'js', 'path' => 'assets/widget.js'],
                ],
            ]),
        ]);
        $report = $this->svc->registerAll($this->hooks, $assets);

        $this->assertCount(1, $report['assets']);
        $this->assertCount(1, $assets->listScripts());
        $this->assertSame('assets/widget.js', $assets->listScripts()[0]['relPath']);
    }

    #[Test]
    public function assetRegistryIsOptional(): void
    {
        // Existing test scaffolds call registerAll($hooks) without an
        // asset registry. Backward-compat: asset entries get silently
        // skipped, no exception thrown.
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', [
                'gui_assets' => [
                    ['type' => 'css', 'path' => 'assets/styles.css'],
                ],
            ]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['assets']);
    }

    // ===================================================================
    // Log forwarding
    // ===================================================================

    #[Test]
    public function pluginLogEntriesAreForwardedToCoreLogger(): void
    {
        $logger = $this->createMock(\Eiou\Utils\Logger::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('[hello-eiou]'),
                $this->callback(fn($ctx) => ($ctx['plugin'] ?? null) === 'hello-eiou')
            );

        $svc = new PluginIpcForwarder(
            $this->loader,
            $logger,
            function (string $u, string $b): array {
                return [
                    'ok' => true, 'status' => 200,
                    'body' => [
                        'ok' => true,
                        'result' => null,
                        '_log' => [
                            ['level' => 'info', 'message' => 'fortune logged', 'context' => []],
                        ],
                    ],
                ];
            }
        );
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', ['subscribes_to' => ['sync.completed']]),
        ]);
        $svc->registerAll($this->hooks);

        EventDispatcher::getInstance()->dispatch('sync.completed', []);
    }
}
