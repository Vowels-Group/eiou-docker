<?php
namespace Eiou\Tests\Services\Plugins;

use Eiou\Core\AppConfig;
use Eiou\Events\EventDispatcher;
use Eiou\Services\Hooks;
use Eiou\Services\GuiActionRegistry;
use Eiou\Services\Plugins\PluginApiRegistry;
use Eiou\Services\Plugins\PluginAssetRegistry;
use Eiou\Services\Plugins\PluginCliRegistry;
use Eiou\Services\Plugins\PluginIpcForwarder;
use Eiou\Services\Plugins\PluginLoader;
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
            function (string $url, string $body, int $timeoutMs): array {
                $decoded = json_decode($body, true);
                $this->httpLog[] = [
                    'url'     => $url,
                    'body'    => is_array($decoded) ? $decoded : [],
                    'timeout' => $timeoutMs,
                ];
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
    // REST IPC (Phase 5f)
    // ===================================================================

    #[Test]
    public function apiRouteRegistrationProducesAWorkingIpcHandler(): void
    {
        $this->nextResponse = [
            'ok' => true, 'status' => 200,
            'body' => ['ok' => true, 'result' => ['fortune' => 'wisdom']],
        ];
        $api = new PluginApiRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', [
                'api_routes' => [['method' => 'GET', 'action' => 'fortune']],
            ]),
        ]);

        $report = $this->svc->registerAll($this->hooks, null, null, null, $api);

        $this->assertSame(
            [['plugin' => 'hello-eiou', 'method' => 'GET', 'action' => 'fortune']],
            $report['api_routes']
        );
        $this->assertTrue($api->has('hello-eiou', 'GET', 'fortune'));

        $result = $api->dispatch('hello-eiou', 'GET', 'fortune', [], '');
        $this->assertSame(200, $result['status']);
        $this->assertSame('wisdom', $result['payload']['fortune']);
        // Forwarder serialized correctly into the envelope.
        $this->assertSame('rest', $this->httpLog[0]['body']['type']);
        $this->assertSame('fortune', $this->httpLog[0]['body']['name']);
        $this->assertSame('GET', $this->httpLog[0]['body']['context']['method']);
    }

    #[Test]
    public function apiRoutePluginUnavailableReturnsErrorPayload(): void
    {
        $this->nextResponse = ['ok' => false, 'status' => 0, 'body' => null];
        $api = new PluginApiRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('flaky', [
                'api_routes' => [['method' => 'GET', 'action' => 'broken']],
            ]),
        ]);

        $this->svc->registerAll($this->hooks, null, null, null, $api);
        $result = $api->dispatch('flaky', 'GET', 'broken', [], '');

        // Dispatch wraps the handler's return as-is when it's an array
        // — our handler emits {success:false, error:...} on failure.
        $this->assertSame('plugin_unavailable', $result['payload']['error']);
    }

    #[Test]
    public function apiRegistryIsOptional(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('demo', [
                'api_routes' => [['method' => 'GET', 'action' => 'noop']],
            ]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['api_routes']);
    }

    // ===================================================================
    // CLI IPC (Phase 5g)
    // ===================================================================

    #[Test]
    public function cliCommandRegistrationProducesAWorkingIpcHandler(): void
    {
        $this->nextResponse = [
            'ok' => true, 'status' => 200,
            'body' => ['ok' => true, 'result' => [
                'exit_code' => 0,
                'stdout' => 'hello from plugin',
                'fortune' => 'demo line',
            ]],
        ];
        $cli = new PluginCliRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', [
                'cli_commands' => [['name' => 'hello-eiou']],
            ]),
        ]);

        $report = $this->svc->registerAll($this->hooks, null, null, null, null, $cli);

        $this->assertSame([['plugin' => 'hello-eiou', 'name' => 'hello-eiou']], $report['cli_commands']);
        $this->assertTrue($cli->has('hello-eiou'));

        // The forwarder built the right envelope shape.
        $output = $this->createMock(\Eiou\Cli\CliOutputManager::class);
        $output->expects($this->once())
            ->method('success')
            ->with(
                'hello from plugin',
                $this->callback(fn($extras) => is_array($extras) && ($extras['fortune'] ?? null) === 'demo line')
            );
        $cli->dispatch('hello-eiou', ['hello-eiou', 'arg1'], $output);

        $this->assertSame('cli', $this->httpLog[0]['body']['type']);
        $this->assertSame('hello-eiou', $this->httpLog[0]['body']['name']);
        $this->assertSame(['hello-eiou', 'arg1'], $this->httpLog[0]['body']['context']['argv']);
    }

    #[Test]
    public function cliDispatchFailureReportsViaOutputError(): void
    {
        // The CLI handler's dispatch-null branch references
        // \Eiou\Core\ErrorCodes::GENERAL_ERROR. A typo there (e.g.
        // \Eiou\Cli\ErrorCodes) surfaces as a fatal "Class not found"
        // exactly when something has already gone wrong, masking the
        // real failure. Exercise the branch explicitly.
        $this->nextResponse = [
            'ok' => false, 'status' => 502,
            'body' => ['ok' => false, 'error' => 'transport failed'],
        ];
        $cli = new PluginCliRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('demo', ['cli_commands' => [['name' => 'demo']]]),
        ]);
        $this->svc->registerAll($this->hooks, null, null, null, null, $cli);

        $output = $this->createMock(\Eiou\Cli\CliOutputManager::class);
        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains("Plugin 'demo' did not respond"),
                \Eiou\Core\ErrorCodes::GENERAL_ERROR,
                502
            );

        $cli->dispatch('demo', ['demo'], $output);
    }

    #[Test]
    public function cliRegistryIsOptional(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('demo', ['cli_commands' => [['name' => 'demo']]]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['cli_commands']);
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
            function (string $u, string $b, int $timeoutMs): array {
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

    // ===================================================================
    // Payback-method type bridging
    // ===================================================================

    #[Test]
    public function registersPaybackMethodTypeProxyAgainstTheRegistry(): void
    {
        $registry = new \Eiou\Services\PaybackMethodTypeRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('payback-btc', [
                'payback_method_types' => [[
                    'id' => 'btc',
                    'catalog' => [
                        'id' => 'btc',
                        'label' => 'Bitcoin',
                        'group' => 'crypto',
                    ],
                ]],
            ]),
        ]);

        $report = $this->svc->registerAll(
            $this->hooks,
            null, null, null, null, null,
            $registry
        );

        $this->assertCount(1, $report['payback_method_types']);
        $this->assertSame('payback-btc', $report['payback_method_types'][0]['plugin']);
        $this->assertSame('btc', $report['payback_method_types'][0]['id']);

        // Registry now contains the proxy under id 'btc'.
        $this->assertTrue($registry->has('btc'));
        $type = $registry->get('btc');
        $this->assertNotNull($type);
        $this->assertSame('btc', $type->getId());
        $this->assertSame('Bitcoin', $type->getCatalogEntry()['label']);
    }

    #[Test]
    public function proxyValidateForwardsThroughIpcDispatch(): void
    {
        $registry = new \Eiou\Services\PaybackMethodTypeRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('payback-btc', [
                'payback_method_types' => [[
                    'id' => 'btc',
                    'catalog' => ['id' => 'btc', 'label' => 'Bitcoin'],
                ]],
            ]),
        ]);
        // Override the canned response for this test.
        $this->nextResponse = [
            'ok' => true, 'status' => 200,
            'body' => [
                'ok' => true,
                'result' => [
                    ['field' => 'address', 'code' => 'invalid_format', 'message' => 'Bad address'],
                ],
            ],
        ];

        $this->svc->registerAll($this->hooks, null, null, null, null, null, $registry);

        $errors = $registry->get('btc')->validate('BTC', ['address' => 'oops']);

        $this->assertCount(1, $this->httpLog);
        $this->assertStringEndsWith('/gui/plugin/payback-btc/__dispatch', $this->httpLog[0]['url']);
        $this->assertSame('payback_method', $this->httpLog[0]['body']['type']);
        $this->assertSame('validate', $this->httpLog[0]['body']['name']);
        $this->assertSame('btc', $this->httpLog[0]['body']['context']['type_id']);
        $this->assertSame('BTC', $this->httpLog[0]['body']['context']['currency']);
        $this->assertSame('invalid_format', $errors[0]['code']);
    }

    #[Test]
    public function paybackMethodTypeSkippedWhenRegistryIsNull(): void
    {
        // Old caller that doesn't pass the registry (test scaffolds,
        // backwards-compat) — payback types just get skipped silently.
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('payback-btc', [
                'payback_method_types' => [[
                    'id' => 'btc',
                    'catalog' => ['id' => 'btc', 'label' => 'Bitcoin'],
                ]],
            ]),
        ]);
        $report = $this->svc->registerAll($this->hooks);
        $this->assertSame([], $report['payback_method_types']);
    }

    #[Test]
    public function paybackMethodTypeSkippedWhenIdCollidesWithCore(): void
    {
        // The loader's filter normally drops these, but defence in
        // depth — if a corrupted row arrives with id 'bank_wire',
        // PaybackMethodTypeRegistry::register() throws and the
        // forwarder catches + logs without aborting siblings.
        $registry = new \Eiou\Services\PaybackMethodTypeRegistry();
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('payback-evil', [
                'payback_method_types' => [
                    ['id' => 'bank_wire', 'catalog' => ['id' => 'bank_wire', 'label' => 'Hijack']],
                    ['id' => 'btc',       'catalog' => ['id' => 'btc',       'label' => 'Bitcoin']],
                ],
            ]),
        ]);
        $report = $this->svc->registerAll($this->hooks, null, null, null, null, null, $registry);

        // Only the survivor registers.
        $this->assertCount(1, $report['payback_method_types']);
        $this->assertSame('btc', $report['payback_method_types'][0]['id']);
        $this->assertFalse($registry->has('bank_wire'));
    }

    // ===================================================================
    // Per-envelope timeout budgets
    // ===================================================================

    #[Test]
    public function eventDispatchUsesTheUserBlockingDefaultTimeout(): void
    {
        // event / filter / render / action stay on the 500ms cap —
        // these all park a user-blocking request on the plugin's
        // reply, so a slow plugin must not extend the user's wait.
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('hello-eiou', ['subscribes_to' => ['sync.completed']]),
        ]);
        $this->svc->registerAll($this->hooks);
        EventDispatcher::getInstance()->dispatch('sync.completed', []);

        $this->assertSame(
            PluginIpcForwarder::TIMEOUT_BY_TYPE_MS['event'],
            $this->httpLog[0]['timeout']
        );
    }

    #[Test]
    public function cronDispatchUsesTheLongerCronTimeout(): void
    {
        // cron runs off the user path; the earlier 500ms cap forced
        // plugins to narrow each tick artificially and logged
        // `plugin_ipc_transport_failed` on dispatches that the
        // plugin's worker actually completed within its FPM 30s
        // ceiling. 5s aligns the host timeout with the cron cadence.
        $this->svc->dispatchCron('hello-eiou', 'drain', time(), 1);

        $this->assertCount(1, $this->httpLog);
        $this->assertSame('cron', $this->httpLog[0]['body']['type']);
        $this->assertSame(
            PluginIpcForwarder::TIMEOUT_BY_TYPE_MS['cron'],
            $this->httpLog[0]['timeout']
        );
    }

    #[Test]
    public function perEntryTimeoutOverridesEnvelopeTypeDefault(): void
    {
        // PluginCronService reads `timeout_ms` off a manifest's
        // cron_actions entry and calls setEntryTimeout() before
        // dispatching. The forwarder uses that value instead of the
        // type default.
        $this->svc->setEntryTimeout('hello-eiou', 'cron', 'long-drain', 12000);
        $this->svc->dispatchCron('hello-eiou', 'long-drain', time(), 1);

        $this->assertSame(12000, $this->httpLog[0]['timeout']);
    }

    #[Test]
    public function perEntryTimeoutIsClampedAtMaxTimeoutMs(): void
    {
        // A misbehaving manifest can't reserve a worker beyond the
        // FPM `request_terminate_timeout` ceiling. The clamp leaves
        // a 5s gap so the host always times out first and logs the
        // failure under our control.
        $this->svc->setEntryTimeout('hello-eiou', 'cron', 'huge', 999999);
        $this->svc->dispatchCron('hello-eiou', 'huge', time(), 1);

        $this->assertSame(
            PluginIpcForwarder::MAX_TIMEOUT_MS,
            $this->httpLog[0]['timeout']
        );
    }

    #[Test]
    public function perEntryTimeoutDoesNotLeakAcrossActions(): void
    {
        // setEntryTimeout's key is (plugin, type, name). A long
        // timeout on one action must not apply to a sibling action
        // on the same plugin.
        $this->svc->setEntryTimeout('hello-eiou', 'cron', 'slow', 8000);
        $this->svc->dispatchCron('hello-eiou', 'slow', time(), 1);
        $this->svc->dispatchCron('hello-eiou', 'fast', time(), 1);

        $this->assertSame(8000, $this->httpLog[0]['timeout']);
        $this->assertSame(
            PluginIpcForwarder::TIMEOUT_BY_TYPE_MS['cron'],
            $this->httpLog[1]['timeout']
        );
    }

    #[Test]
    public function constructorTimeoutOverrideStillWinsForTestSeams(): void
    {
        // Pre-refactor tests pinned timeoutMs via the constructor.
        // That contract is preserved: a non-null constructor argument
        // forces every dispatch to use that value, regardless of
        // envelope type or per-entry override.
        $log = [];
        $svc = new PluginIpcForwarder(
            $this->loader,
            null,
            function (string $u, string $b, int $tms) use (&$log): array {
                $log[] = $tms;
                return ['ok' => true, 'status' => 200, 'body' => ['ok' => true, 'result' => null]];
            },
            null,
            123  // explicit timeoutMs
        );
        $svc->setEntryTimeout('plug', 'cron', 'name', 9999);
        $svc->dispatchCron('plug', 'name', time(), 1);

        $this->assertSame([123], $log);
    }
}
