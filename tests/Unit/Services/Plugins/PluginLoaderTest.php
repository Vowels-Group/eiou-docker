<?php
/**
 * Unit Tests for PluginLoader
 *
 * Covers manifest discovery, PSR-4 autoload registration, lifecycle phases
 * (register/boot), failure isolation, and persisted enable/disable state.
 */

namespace Eiou\Tests\Services\Plugins;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Services\Plugins\PluginLoader;
use Eiou\Services\ServiceContainer;
use Eiou\Utils\Logger;

#[CoversClass(PluginLoader::class)]
class PluginLoaderTest extends TestCase
{
    private string $tmpRoot;
    private string $stateFile;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/eiou-plugin-test-' . uniqid('', true);
        mkdir($this->tmpRoot, 0777, true);
        $this->stateFile = $this->tmpRoot . '/plugins-state.json';
        $this->logger = $this->createMock(Logger::class);
        // Reset so each test sees a clean subscription table — otherwise
        // listeners registered in one test leak into the next.
        EventDispatcher::resetInstance();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
        EventDispatcher::resetInstance();
    }

    private function loader(): PluginLoader
    {
        $l = new PluginLoader($this->pluginRoot(), $this->logger, $this->stateFile);
        // Sandboxing is mandatory now. Every test fixture wires inert
        // mock sandbox services so setEnabled() doesn't refuse with
        // "services not wired". Tests that specifically care about
        // sandbox-service behaviour build their own loader.
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
        $l->setSandboxServices($userSvc, $poolSvc, $nginxSvc);
        return $l;
    }

    private function pluginRoot(): string
    {
        $dir = $this->tmpRoot . '/plugins-dir';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public function testDiscoverReturnsEmptyWhenDirectoryMissing(): void
    {
        $loader = new PluginLoader($this->tmpRoot . '/does-not-exist', $this->logger, $this->stateFile);
        $this->assertSame([], $loader->discover());
        $this->assertSame([], $loader->getLoadedPlugins());
    }

    public function testDiscoverReturnsEmptyWhenDirectoryEmpty(): void
    {
        $this->assertSame([], $this->loader()->discover());
    }

    public function testDiscoverLoadsValidPlugin(): void
    {
        $this->writePlugin('valid', 'Eiou\\Tests\\Plugins\\Valid\\ValidPlugin', $this->validPluginSource('Valid'));

        $loader = $this->loader();
        $loader->discover();

        // Sandboxed plugins never load in-process (no entry-class
        // instance lives in $plugins), but they're recorded in metadata
        // so listAllPlugins / the GUI can show them.
        $meta = $loader->getLoadedPlugins();
        $this->assertArrayHasKey('valid', $meta);
        $this->assertSame('sandboxed', $meta['valid']['status']);
        $this->assertTrue($meta['valid']['enabled']);
    }

    public function testDiscoverSkipsPluginWithMissingManifest(): void
    {
        mkdir($this->pluginRoot() . '/no-manifest', 0777, true);
        $this->assertSame([], $this->loader()->discover());
    }

    public function testDiscoverSkipsManifestWithMissingFields(): void
    {
        mkdir($this->pluginRoot() . '/incomplete', 0777, true);
        file_put_contents(
            $this->pluginRoot() . '/incomplete/plugin.json',
            json_encode(['name' => 'incomplete', 'version' => '1.0.0'])
        );

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->assertSame([], $this->loader()->discover());
    }

    public function testDiscoverSkipsInvalidJson(): void
    {
        mkdir($this->pluginRoot() . '/bad-json', 0777, true);
        file_put_contents($this->pluginRoot() . '/bad-json/plugin.json', '{ not valid json');

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->assertSame([], $this->loader()->discover());
    }

    // [removed] testDiscoverSkipsClassNotImplementingInterface: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testRegisterAllCallsRegisterOnEachPlugin: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testBootAllCallsBootOnEachPlugin: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testRegisterFailureDisablesPluginWithoutAffectingSiblings: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testBootFailureDisablesOnlyThatPlugin: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testFailedPluginIsSkippedInBootPhase: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    public function testDuplicatePluginNameIsSkipped(): void
    {
        $this->writePlugin('dup', 'Eiou\\Tests\\Plugins\\DupOne\\DupOnePlugin',
            $this->validPluginSource('DupOne'));

        $secondPath = $this->pluginRoot() . '/dup-second';
        mkdir($secondPath . '/src', 0777, true);
        file_put_contents($secondPath . '/plugin.json', json_encode([
            'name' => 'dup',
            'version' => '2.0.0',
            'entryClass' => 'Eiou\\Tests\\Plugins\\DupTwo\\DupTwoPlugin',
            'autoload' => ['psr-4' => ['Eiou\\Tests\\Plugins\\DupTwo\\' => 'src/']],
            'sandboxed' => true,
        ]));
        file_put_contents($secondPath . '/src/DupTwoPlugin.php', $this->validPluginSource('DupTwo'));

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $loader = $this->loader();
        $loader->discover();

        // First-write-wins: dup-second's metadata entry never lands
        // because the name was already claimed.
        $meta = $loader->getLoadedPlugins();
        $this->assertArrayHasKey('dup', $meta);
        $this->assertSame('1.0.0', $meta['dup']['version']);
    }

    // -- Enable / disable persistence --------------------------------------

    public function testDisabledPluginIsNotLoaded(): void
    {
        $this->writePlugin('skipme', 'Eiou\\Tests\\Plugins\\SkipMe\\SkipMePlugin',
            $this->validPluginSource('SkipMe'));
        file_put_contents($this->stateFile, json_encode([
            'skipme' => ['enabled' => false],
        ]));

        $loader = $this->loader();
        $plugins = $loader->discover();

        // The plugin instance is NOT created (autoloader not registered, no boot).
        $this->assertArrayNotHasKey('skipme', $plugins);
        // But it's still tracked in metadata so the GUI can show it as disabled.
        $meta = $loader->getLoadedPlugins();
        $this->assertSame('disabled', $meta['skipme']['status']);
        $this->assertFalse($meta['skipme']['enabled']);
    }

    public function testDisabledIsDefaultWhenStateMissing(): void
    {
        // Write the plugin manifest WITHOUT touching the state file —
        // bypass writePlugin() which auto-enables for testing convenience.
        $this->writeFullManifestPluginRaw('default-off',
            'Eiou\\Tests\\Plugins\\DefaultOff\\DefaultOffPlugin',
            $this->validPluginSource('DefaultOff'));

        $loader = $this->loader();
        $plugins = $loader->discover();

        // Safety stance: a plugin sitting on disk with no explicit opt-in
        // does NOT load. The user must enable it deliberately.
        $this->assertArrayNotHasKey('default-off', $plugins);
        $meta = $loader->getLoadedPlugins();
        $this->assertSame('disabled', $meta['default-off']['status']);
        $this->assertFalse($meta['default-off']['enabled']);
    }

    public function testExplicitlyEnabledPluginIsLoaded(): void
    {
        $this->writePlugin('explicitly-on', 'Eiou\\Tests\\Plugins\\ExplicitlyOn\\ExplicitlyOnPlugin',
            $this->validPluginSource('ExplicitlyOn'));

        $loader = $this->loader();
        $loader->discover();

        $meta = $loader->getLoadedPlugins();
        $this->assertArrayHasKey('explicitly-on', $meta);
        $this->assertTrue($meta['explicitly-on']['enabled']);
    }

    public function testSetEnabledPersistsToStateFile(): void
    {
        $loader = $this->loader();
        $this->assertTrue($loader->setEnabled('whatever', false));

        $this->assertFileExists($this->stateFile);
        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertFalse($state['whatever']['enabled']);

        $this->assertTrue($loader->setEnabled('whatever', true));
        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertTrue($state['whatever']['enabled']);
    }

    public function testSetEnabledStateFileIsReadableByWwwData(): void
    {
        // Operator CLI runs as root, wallet pool runs as www-data, and
        // both writers and readers share `/etc/eiou/config/plugins.json`.
        // Without an explicit chgrp the root-written file is
        // root:root 0640 and the pool can't read it — every CLI-enabled
        // plugin then looks disabled to the wallet.
        if (!function_exists('posix_getgrnam') || !function_exists('posix_getgrgid')) {
            $this->markTestSkipped('posix functions unavailable');
        }
        $wwwData = posix_getgrnam('www-data');
        if ($wwwData === false) {
            $this->markTestSkipped('www-data group not present on this host');
        }
        // chgrp(www-data) only succeeds if the current user is root or
        // a member of www-data. Otherwise we'd be testing the @-swallow
        // path, not the fix. Skip in that case — the docker image runs
        // as root, which is the case this protects.
        $canChgrp = (function_exists('posix_geteuid') && posix_geteuid() === 0)
            || in_array('www-data', posix_getgroups()
                ? array_map(fn($g) => posix_getgrgid($g)['name'] ?? '', posix_getgroups())
                : [], true);
        if (!$canChgrp) {
            $this->markTestSkipped('current user cannot chgrp to www-data (test runs as root in the container)');
        }

        $loader = $this->loader();
        $this->assertTrue($loader->setEnabled('group-check', true));
        $this->assertFileExists($this->stateFile);

        clearstatcache(true, $this->stateFile);
        $gid = filegroup($this->stateFile);
        $info = posix_getgrgid($gid);
        $this->assertSame('www-data', $info['name'] ?? null,
            'state file must be group www-data so the wallet pool can read it');
    }

    public function testListAllPluginsIncludesDisabledOnes(): void
    {
        $this->writePlugin('on', 'Eiou\\Tests\\Plugins\\On\\OnPlugin', $this->validPluginSource('On'));
        // 'off' is on disk but never enabled in state — covers the
        // disabled-by-default + listAllPlugins surfacing path.
        $this->writeFullManifestPluginRaw('off', 'Eiou\\Tests\\Plugins\\Off\\OffPlugin',
            $this->validPluginSource('Off'));

        $loader = $this->loader();
        $loader->discover();
        $rows = $loader->listAllPlugins();

        $this->assertCount(2, $rows);
        $byName = array_column($rows, null, 'name');
        $this->assertTrue($byName['on']['enabled']);
        $this->assertFalse($byName['off']['enabled']);
        $this->assertSame('off', $rows[0]['name']);
        $this->assertSame('on', $rows[1]['name']);
    }

    public function testListAllPluginsIncludesDescriptionFromManifest(): void
    {
        $this->writeFullManifestPlugin(
            'with-desc',
            'Eiou\\Tests\\Plugins\\WithDesc\\WithDescPlugin',
            'A nice description',
            $this->validPluginSource('WithDesc')
        );

        $rows = $this->loader()->listAllPlugins();
        $this->assertSame('A nice description', $rows[0]['description']);
    }

    // -- Optional metadata (author / homepage / changelog / license) -------

    public function testListAllPluginsOmitsOptionalFieldsWhenManifestHasNone(): void
    {
        $this->writePlugin('bare', 'Eiou\\Tests\\Plugins\\Bare\\BarePlugin',
            $this->validPluginSource('Bare'));

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertArrayNotHasKey('author', $row);
        $this->assertArrayNotHasKey('homepage', $row);
        $this->assertArrayNotHasKey('changelog', $row);
        $this->assertArrayNotHasKey('license', $row);
    }

    public function testListAllPluginsExposesAuthorAsStringAndObject(): void
    {
        $this->writePluginWithExtras('str-author',
            ['author' => 'Acme Co.']);
        $this->writePluginWithExtras('obj-author',
            ['author' => ['name' => 'Beta Corp', 'url' => 'https://beta.example']]);

        $rows = array_column($this->loader()->listAllPlugins(), null, 'name');
        $this->assertSame(['name' => 'Acme Co.'], $rows['str-author']['author']);
        $this->assertSame(
            ['name' => 'Beta Corp', 'url' => 'https://beta.example'],
            $rows['obj-author']['author']
        );
    }

    public function testListAllPluginsRejectsNonHttpUrls(): void
    {
        // javascript:, data:, and missing-scheme values must not survive into
        // the GUI — it renders homepage/changelog as clickable <a href> tags.
        $this->writePluginWithExtras('hostile', [
            'author'    => ['name' => 'Mallory', 'url' => 'javascript:alert(1)'],
            'homepage'  => 'javascript:alert(2)',
            'changelog' => 'not-a-url',
        ]);

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame(['name' => 'Mallory'], $row['author']);
        $this->assertArrayNotHasKey('homepage', $row);
        $this->assertArrayNotHasKey('changelog', $row);
    }

    public function testListAllPluginsExposesHomepageChangelogAndLicense(): void
    {
        $this->writePluginWithExtras('full-meta', [
            'homepage'  => 'https://example.com/plugin',
            'changelog' => 'http://example.com/CHANGELOG.md',
            'license'   => 'MIT',
        ]);

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame('https://example.com/plugin', $row['homepage']);
        $this->assertSame('http://example.com/CHANGELOG.md', $row['changelog']);
        $this->assertSame('MIT', $row['license']);
    }

    public function testListAllPluginsDropsOverlongLicense(): void
    {
        $this->writePluginWithExtras('long-license', [
            'license' => str_repeat('x', 65),
        ]);

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertArrayNotHasKey('license', $row);
    }

    // -- Bundled CHANGELOG.md detection + read -----------------------------

    public function testListAllPluginsFlagsBundledChangelog(): void
    {
        $this->writePluginWithExtras('with-log', []);
        file_put_contents(
            $this->pluginRoot() . '/with-log/CHANGELOG.md',
            "# Changelog\n\n## 1.0.0\n- Initial release\n"
        );

        $this->writePluginWithExtras('without-log', []);

        $rows = array_column($this->loader()->listAllPlugins(), null, 'name');
        $this->assertTrue($rows['with-log']['has_changelog']);
        $this->assertArrayNotHasKey('has_changelog', $rows['without-log']);
    }

    public function testReadChangelogReturnsMarkdownForKnownPlugin(): void
    {
        $this->writePluginWithExtras('readme-plugin', []);
        $content = "# Changelog\n\n## 1.0.0\n- First release\n";
        file_put_contents($this->pluginRoot() . '/readme-plugin/CHANGELOG.md', $content);

        $this->assertSame($content, $this->loader()->readChangelog('readme-plugin'));
    }

    public function testReadChangelogReturnsNullForUnknownPlugin(): void
    {
        // No plugin written — name is unknown on disk.
        $this->assertNull($this->loader()->readChangelog('ghost'));
    }

    public function testReadChangelogReturnsNullWhenFileMissing(): void
    {
        // Plugin exists but has no CHANGELOG.md on disk.
        $this->writePluginWithExtras('no-log', []);
        $this->assertNull($this->loader()->readChangelog('no-log'));
    }

    public function testReadChangelogRejectsOversizedFile(): void
    {
        $this->writePluginWithExtras('huge-log', []);
        // 256KB + 1 byte — one past the cap.
        file_put_contents(
            $this->pluginRoot() . '/huge-log/CHANGELOG.md',
            str_repeat('x', 256 * 1024 + 1)
        );

        $this->assertNull($this->loader()->readChangelog('huge-log'));
    }

    // -- public_routes manifest field --------------------------------------

    public function testListAllPluginsCarriesValidPublicRoutes(): void
    {
        $this->writePluginWithExtras('pub-good', [
            'public_routes' => [
                ['method' => 'POST', 'action' => 'chat', 'rate_per_minute' => 60, 'max_body_bytes' => 65536],
                ['method' => 'GET',  'action' => 'status'],
            ],
        ]);

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertCount(2, $row['public_routes']);
        $this->assertSame('chat', $row['public_routes'][0]['action']);
        $this->assertSame('status', $row['public_routes'][1]['action']);
    }

    public function testListAllPluginsRejectsBadPublicRouteEntries(): void
    {
        $this->writePluginWithExtras('pub-bad', [
            'public_routes' => [
                ['method' => 'TEAPOT', 'action' => 'chat'],          // bad verb
                ['method' => 'POST',   'action' => 'Bad-Caps'],      // bad action
                ['method' => 'POST',   'action' => 'chat', 'auth' => 'oauth'], // bad auth
                ['method' => 'POST',   'action' => 'chat', 'rate_per_minute' => 100000], // out of bounds
                ['method' => 'POST',   'action' => 'chat', 'max_body_bytes' => 99999999], // out of bounds
                ['method' => 'POST',   'action' => 'survivor'],      // good
            ],
        ]);

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertCount(1, $row['public_routes']);
        $this->assertSame('survivor', $row['public_routes'][0]['action']);
    }

    public function testListAllPluginsDefaultsPublicRoutesToEmptyList(): void
    {
        $this->writePluginWithExtras('pub-none', []);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['public_routes']);
    }

    public function testListAllPluginsPersistsKnownPermissions(): void
    {
        $this->writePluginWithExtras('perms-ok', [
            'permissions' => ['contact_address_book_enumerate'],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame(['contact_address_book_enumerate'], $row['permissions']);
    }

    public function testListAllPluginsDropsUnknownPermissionKeys(): void
    {
        // Loader filters defensively even though PluginInstallService
        // rejects unknown keys at upload time — a row that pre-dates a
        // permission rename should fail closed rather than carrying a
        // stale key through to the gateway.
        $this->writePluginWithExtras('perms-stale', [
            'permissions' => [
                'contact_address_book_enumerate',
                'was_renamed_or_removed',
            ],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame(['contact_address_book_enumerate'], $row['permissions']);
    }

    public function testListAllPluginsDefaultsPermissionsToEmptyList(): void
    {
        $this->writePluginWithExtras('perms-none', []);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['permissions']);
    }

    public function testListAllPluginsPersistsPluginTabPanel(): void
    {
        $this->writePluginWithExtras('panel-full', [
            'plugin_tab_panel' => ['label' => 'Hello', 'icon' => 'fa-x', 'order' => 80],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame(
            ['label' => 'Hello', 'icon' => 'fa-x', 'order' => 80],
            $row['plugin_tab_panel']
        );
    }

    public function testListAllPluginsOmitsPluginTabPanelWhenNotDeclared(): void
    {
        $this->writePluginWithExtras('panel-none', []);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertArrayNotHasKey('plugin_tab_panel', $row);
    }

    public function testListAllPluginsDropsPluginTabPanelWithMissingLabel(): void
    {
        // Defence in depth: a row that pre-dates a schema change must
        // not surface a panel without a label.
        $this->writePluginWithExtras('panel-bad', [
            'plugin_tab_panel' => ['icon' => 'fa-x'],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertArrayNotHasKey('plugin_tab_panel', $row);
    }

    public function testListAllPluginsAcceptsValidCorsAllowedOrigins(): void
    {
        $this->writePluginWithExtras('pub-cors', [
            'public_routes' => [
                [
                    'method' => 'POST',
                    'action' => 'chat',
                    'cors_allowed_origins' => [
                        'https://example.com',
                        'https://app.example.com',
                        'http://localhost:3000',
                    ],
                ],
            ],
        ]);

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertCount(1, $row['public_routes']);
        $this->assertSame(
            ['https://example.com', 'https://app.example.com', 'http://localhost:3000'],
            $row['public_routes'][0]['cors_allowed_origins']
        );
    }

    public function testListAllPluginsRejectsWildcardCorsOrigin(): void
    {
        $this->writePluginWithExtras('pub-wild', [
            'public_routes' => [
                ['method' => 'POST', 'action' => 'chat', 'cors_allowed_origins' => ['*']],
            ],
        ]);
        // Whole entry is dropped — an entry with a malformed CORS list
        // is not silently CORS-stripped, because shipping the route
        // sans CORS could surprise a plugin author who thought they'd
        // declared CORS protection.
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['public_routes']);
    }

    public function testListAllPluginsRejectsCorsOriginWithPath(): void
    {
        $this->writePluginWithExtras('pub-path', [
            'public_routes' => [
                ['method' => 'POST', 'action' => 'chat', 'cors_allowed_origins' => ['https://example.com/api']],
            ],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['public_routes']);
    }

    public function testListAllPluginsRejectsOversizedCorsList(): void
    {
        $tooMany = [];
        for ($i = 0; $i < 11; $i++) {
            $tooMany[] = "https://origin{$i}.example.com";
        }
        $this->writePluginWithExtras('pub-many', [
            'public_routes' => [
                ['method' => 'POST', 'action' => 'chat', 'cors_allowed_origins' => $tooMany],
            ],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['public_routes']);
    }

    // -- payback_method_types manifest field -------------------------------

    public function testListAllPluginsAcceptsValidPaybackMethodType(): void
    {
        $this->writePluginWithExtras('pmt-good', [
            'payback_method_types' => [
                [
                    'id' => 'btc',
                    'catalog' => [
                        'id' => 'btc',
                        'label' => 'Bitcoin',
                        'group' => 'crypto',
                        'icon' => 'fab fa-bitcoin',
                        'description' => 'On-chain Bitcoin',
                        'currencies' => ['BTC'],
                        'fields' => [
                            ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'required' => true],
                        ],
                    ],
                ],
            ],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertCount(1, $row['payback_method_types']);
        $this->assertSame('btc', $row['payback_method_types'][0]['id']);
        $this->assertSame('Bitcoin', $row['payback_method_types'][0]['catalog']['label']);
    }

    public function testListAllPluginsRejectsPaybackMethodTypeWithBadId(): void
    {
        $this->writePluginWithExtras('pmt-badid', [
            'payback_method_types' => [
                ['id' => 'BadCase', 'catalog' => ['id' => 'BadCase', 'label' => 'X']],
            ],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['payback_method_types']);
    }

    public function testListAllPluginsRejectsPaybackMethodTypeShadowingCore(): void
    {
        $this->writePluginWithExtras('pmt-shadow', [
            'payback_method_types' => [
                ['id' => 'bank_wire', 'catalog' => ['id' => 'bank_wire', 'label' => 'My Bank']],
                ['id' => 'custom',    'catalog' => ['id' => 'custom',    'label' => 'My Custom']],
            ],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['payback_method_types']);
    }

    public function testListAllPluginsRejectsPaybackMethodTypeMissingCatalog(): void
    {
        $this->writePluginWithExtras('pmt-no-cat', [
            'payback_method_types' => [
                ['id' => 'btc'], // no catalog → drop
            ],
        ]);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['payback_method_types']);
    }

    public function testListAllPluginsDefaultsPaybackMethodTypesToEmptyList(): void
    {
        $this->writePluginWithExtras('pmt-none', []);
        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame([], $row['payback_method_types']);
    }

    // -- Lifecycle event dispatch ------------------------------------------

    // [removed] testRegisterAllDispatchesPluginRegistered: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testBootAllDispatchesPluginBooted: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testFailedPluginDispatchesPluginFailed: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // -- Lifecycle idempotency ---------------------------------------------

    // [removed] testRegisterAllIsIdempotent: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testBootAllIsIdempotent: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // [removed] testFailedPluginNotRetriedOnSecondRegisterAll: tested in-process plugin lifecycle (register/boot/instantiation). Sandboxing is now mandatory; the
    // loader no longer instantiates plugin entry classes — these assertions are about a deleted code path.


    // -- Helpers --------------------------------------------------------------

    private function writePlugin(string $dirName, string $entryClass, string $sourceCode): void
    {
        $this->writeFullManifestPlugin($dirName, $entryClass, '', $sourceCode);
    }

    private function writeFullManifestPlugin(string $dirName, string $entryClass, string $description, string $sourceCode): void
    {
        $path = $this->pluginRoot() . '/' . $dirName;
        mkdir($path . '/src', 0777, true);

        $parts = explode('\\', $entryClass);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);

        $manifest = [
            'name' => $dirName,
            'version' => '1.0.0',
            'entryClass' => $entryClass,
            'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
            // Sandboxing is mandatory — every test plugin opts in so
            // the loader processes it. Tests that care about the
            // refuse-non-sandboxed path build their manifest manually.
            'sandboxed' => true,
        ];
        if ($description !== '') {
            $manifest['description'] = $description;
        }

        file_put_contents($path . '/plugin.json', json_encode($manifest));
        file_put_contents($path . '/src/' . $className . '.php', $sourceCode);

        // Plugins are disabled by default. Most tests want to verify load
        // behavior, so opt the plugin in via the state file. Tests that
        // care about the disabled-default codepath bypass writePlugin().
        $this->enableInState($dirName);
    }

    private function enableInState(string $name): void
    {
        $state = is_file($this->stateFile)
            ? (json_decode(file_get_contents($this->stateFile), true) ?: [])
            : [];
        $state[$name] = ['enabled' => true];
        file_put_contents($this->stateFile, json_encode($state));
    }

    /**
     * Write an enabled plugin whose manifest merges $extras on top of the
     * minimum-viable manifest — used by the optional-metadata tests.
     *
     * @param array<string, mixed> $extras
     */
    private function writePluginWithExtras(string $dirName, array $extras): void
    {
        $classBase = str_replace(' ', '', ucwords(str_replace('-', ' ', $dirName)));
        $className = $classBase . 'Plugin';
        $namespace = 'Eiou\\Tests\\Plugins\\' . $classBase;
        $entryClass = $namespace . '\\' . $className;

        $path = $this->pluginRoot() . '/' . $dirName;
        mkdir($path . '/src', 0777, true);

        $manifest = array_merge([
            'name' => $dirName,
            'version' => '1.0.0',
            'entryClass' => $entryClass,
            'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
            'sandboxed' => true,
        ], $extras);

        file_put_contents($path . '/plugin.json', json_encode($manifest));
        file_put_contents(
            $path . '/src/' . $className . '.php',
            $this->validPluginSource($classBase)
        );
        $this->enableInState($dirName);
    }

    /**
     * Write a plugin without auto-enabling it — for tests that exercise
     * the disabled-by-default codepath.
     */
    private function writeFullManifestPluginRaw(string $dirName, string $entryClass, string $sourceCode): void
    {
        $path = $this->pluginRoot() . '/' . $dirName;
        mkdir($path . '/src', 0777, true);

        $parts = explode('\\', $entryClass);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);

        $manifest = [
            'name' => $dirName,
            'version' => '1.0.0',
            'entryClass' => $entryClass,
            'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
        ];
        file_put_contents($path . '/plugin.json', json_encode($manifest));
        file_put_contents($path . '/src/' . $className . '.php', $sourceCode);
    }

    private function validPluginSource(string $classBase): string
    {
        $namespace = "Eiou\\Tests\\Plugins\\{$classBase}";
        return <<<PHP
<?php
namespace {$namespace};

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;

class {$classBase}Plugin implements PluginInterface
{
    public function getName(): string { return '{$classBase}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function register(ServiceContainer \$c): void {}
    public function boot(ServiceContainer \$c): void {}
}
PHP;
    }

    /**
     * Plugin that counts register/boot invocations on static class properties
     * so tests can assert the lifecycle ran the expected number of times.
     */
    private function countingPluginSource(string $classBase): string
    {
        $namespace = "Eiou\\Tests\\Plugins\\{$classBase}";
        return <<<PHP
<?php
namespace {$namespace};

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;

class {$classBase}Plugin implements PluginInterface
{
    public static int \$registerCalls = 0;
    public static int \$bootCalls = 0;

    public function getName(): string { return '{$classBase}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function register(ServiceContainer \$c): void { self::\$registerCalls++; }
    public function boot(ServiceContainer \$c): void { self::\$bootCalls++; }
}
PHP;
    }

    private function throwingPluginSource(string $classBase, string $phase): string
    {
        $namespace = "Eiou\\Tests\\Plugins\\{$classBase}";
        $registerBody = $phase === 'register'
            ? "throw new \\RuntimeException('register exploded');"
            : '';
        $bootBody = $phase === 'boot'
            ? "throw new \\RuntimeException('boot exploded');"
            : '';
        return <<<PHP
<?php
namespace {$namespace};

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;

class {$classBase}Plugin implements PluginInterface
{
    public function getName(): string { return '{$classBase}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function register(ServiceContainer \$c): void { {$registerBody} }
    public function boot(ServiceContainer \$c): void { {$bootBody} }
}
PHP;
    }

    // -- signature enforcement --------------------------------------------
    // Verifier wiring + mode semantics. Uses ephemeral sodium keypairs
    // so we never need a real .pub file on disk.

    public function testSignatureModeOffSkipsVerificationEntirely(): void
    {
        $this->writePlugin('no-check', 'Eiou\\Tests\\Plugins\\NoCheck\\NoCheckPlugin',
            $this->validPluginSource('NoCheck'));

        $verifier = $this->createMock(\Eiou\Services\Plugins\PluginSignatureVerifier::class);
        $verifier->expects($this->never())->method('verify');

        $loader = $this->loader();
        $loader->setSignatureVerifier($verifier, \Eiou\Services\Plugins\PluginSignatureVerifier::MODE_OFF);
        $loader->discover();
        $this->assertArrayHasKey('no-check', $loader->getLoadedPlugins());
    }

    public function testSignatureModeRequireBlocksUnsignedPlugin(): void
    {
        $this->writePlugin('unsigned', 'Eiou\\Tests\\Plugins\\Unsigned\\UnsignedPlugin',
            $this->validPluginSource('Unsigned'));

        $verifier = $this->createMock(\Eiou\Services\Plugins\PluginSignatureVerifier::class);
        $verifier->method('verify')->willReturn(['status' => 'unsigned']);

        $loader = $this->loader();
        $loader->setSignatureVerifier($verifier, \Eiou\Services\Plugins\PluginSignatureVerifier::MODE_REQUIRE);
        $plugins = $loader->discover();
        $this->assertArrayNotHasKey('unsigned', $plugins);
        $meta = $loader->getLoadedPlugins();
        $this->assertArrayHasKey('unsigned', $meta);
        $this->assertSame('failed', $meta['unsigned']['status']);
        $this->assertStringStartsWith('signature: unsigned', $meta['unsigned']['error']);
    }

    public function testSignatureModeWarnAllowsLoadButRecordsStatus(): void
    {
        $this->writePlugin('warn-only', 'Eiou\\Tests\\Plugins\\WarnOnly\\WarnOnlyPlugin',
            $this->validPluginSource('WarnOnly'));

        $verifier = $this->createMock(\Eiou\Services\Plugins\PluginSignatureVerifier::class);
        $verifier->method('verify')->willReturn([
            'status' => 'untrusted_key',
            'key_fingerprint' => 'sha256:abc',
        ]);

        $loader = $this->loader();
        $loader->setSignatureVerifier($verifier, \Eiou\Services\Plugins\PluginSignatureVerifier::MODE_WARN);
        $loader->discover();
        $meta = $loader->getLoadedPlugins();
        $this->assertArrayHasKey('warn-only', $meta, 'warn mode must not block load');
        $this->assertSame('sandboxed', $meta['warn-only']['status']);
        $this->assertSame('untrusted_key', $meta['warn-only']['signature']['status']);
    }

    public function testSignatureOkPluginLoadsNormally(): void
    {
        $this->writePlugin('signed-ok', 'Eiou\\Tests\\Plugins\\SignedOk\\SignedOkPlugin',
            $this->validPluginSource('SignedOk'));

        $verifier = $this->createMock(\Eiou\Services\Plugins\PluginSignatureVerifier::class);
        $verifier->method('verify')->willReturn([
            'status' => 'ok',
            'key_fingerprint' => 'sha256:abc',
        ]);

        $loader = $this->loader();
        $loader->setSignatureVerifier($verifier, \Eiou\Services\Plugins\PluginSignatureVerifier::MODE_REQUIRE);
        $loader->discover();
        $meta = $loader->getLoadedPlugins();
        $this->assertArrayHasKey('signed-ok', $meta);
        $this->assertSame('ok', $meta['signed-ok']['signature']['status']);
    }

    public function testInvalidSignatureModeCollapsesToOff(): void
    {
        // Typo in the mode setting — prefer "silently off" over "silently
        // enforcing something the operator didn't ask for", which would
        // surprise them if it blocked plugins.
        $this->writePlugin('bogus-mode', 'Eiou\\Tests\\Plugins\\BogusMode\\BogusModePlugin',
            $this->validPluginSource('BogusMode'));

        $verifier = $this->createMock(\Eiou\Services\Plugins\PluginSignatureVerifier::class);
        $verifier->expects($this->never())->method('verify');

        $loader = $this->loader();
        $loader->setSignatureVerifier($verifier, 'REQUIRED'); // not 'require'
        $loader->discover();
        $this->assertArrayHasKey('bogus-mode', $loader->getLoadedPlugins());
    }

    public function testListAllPluginsCarriesSignatureStatus(): void
    {
        $this->writePlugin('listed', 'Eiou\\Tests\\Plugins\\Listed\\ListedPlugin',
            $this->validPluginSource('Listed'));

        $verifier = $this->createMock(\Eiou\Services\Plugins\PluginSignatureVerifier::class);
        $verifier->method('verify')->willReturn([
            'status' => 'ok',
            'key_fingerprint' => 'sha256:abcdef',
        ]);

        $loader = $this->loader();
        $loader->setSignatureVerifier($verifier, \Eiou\Services\Plugins\PluginSignatureVerifier::MODE_WARN);
        $rows = array_column($loader->listAllPlugins(), null, 'name');
        $this->assertArrayHasKey('signature', $rows['listed']);
        $this->assertSame('ok', $rows['listed']['signature']['status']);
        $this->assertSame('warn', $rows['listed']['signature']['mode']);
    }

    // -- reconcileIsolation (boot-time replay) ----------------------------
    // Boot-time replay of CREATE USER / GRANT / REVOKE for every plugin.
    // Self-heals against mysql-data volume loss, manual user drops, and
    // operator db_limits changes between boots.

    public function testReconcileReturnsEmptyWhenNoIsolationServicesWired(): void
    {
        $this->writePluginWithExtras('has-db', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_has_db_t']],
        ]);
        $this->assertSame([], $this->loader()->reconcileIsolation());
    }

    public function testReconcileReGrantsEnabledPluginWithExistingCredentials(): void
    {
        $this->writePluginWithExtras('has-db', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_has_db_t'],
                'db_limits' => ['max_queries_per_hour' => 99999],
            ],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(true);
        $cred->method('getPlaintext')->willReturn('existing-pw');
        $cred->expects($this->never())->method('generate');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->once())->method('ensureUser')
            ->with('has-db', 'existing-pw', $this->callback(function ($limits) {
                return ($limits['max_queries_per_hour'] ?? null) === 99999;
            }));
        $dbUser->expects($this->once())->method('grant')
            ->with('has-db', ['plugin_has_db_t']);
        $dbUser->expects($this->never())->method('revoke');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $results = $loader->reconcileIsolation();
        $this->assertSame(['has-db' => 'granted'], $results);
    }

    public function testReconcileRegeneratesMissingCredentialsForEnabledPlugin(): void
    {
        // Credentials row was lost (mysql-data volume was recreated) but
        // the plugin is still enabled in plugins.json. Reconciler must
        // regenerate + re-provision from scratch.
        $this->writePluginWithExtras('has-db', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_has_db_t']],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(false);
        $cred->expects($this->once())->method('generate')->willReturn('new-pw');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->once())->method('ensureUser');
        $dbUser->expects($this->once())->method('grant');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $this->assertSame(['has-db' => 'granted'], $loader->reconcileIsolation());
    }

    public function testReconcileRevokesDisabledPluginStillHoldingCredentials(): void
    {
        // Plugin disabled in plugins.json but credential row exists — self
        // heals cases where a pre-phase-4 partial failure left a grant behind.
        $this->writePluginWithExtras('has-db', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_has_db_t']],
        ]);
        // Override to disabled.
        file_put_contents($this->stateFile, json_encode(['has-db' => ['enabled' => false]]));

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(true);

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->once())->method('revoke')->with('has-db');
        $dbUser->expects($this->never())->method('ensureUser');
        $dbUser->expects($this->never())->method('grant');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $this->assertSame(['has-db' => 'revoked'], $loader->reconcileIsolation());
    }

    public function testReconcileSkipsDisabledPluginWithoutCredentials(): void
    {
        $this->writePluginWithExtras('has-db', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_has_db_t']],
        ]);
        file_put_contents($this->stateFile, json_encode(['has-db' => ['enabled' => false]]));

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(false);

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->never())->method('revoke');
        $dbUser->expects($this->never())->method('ensureUser');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $this->assertSame(['has-db' => 'skipped'], $loader->reconcileIsolation());
    }

    public function testReconcileSkipsPluginsWithoutDatabaseBlock(): void
    {
        $this->writePluginWithExtras('plain', []);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->never())->method('ensureUser');
        $dbUser->expects($this->never())->method('grant');
        $dbUser->expects($this->never())->method('revoke');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $this->assertSame(['plain' => 'skipped'], $loader->reconcileIsolation());
    }

    public function testReconcileCapturesErrorAndContinuesToNextPlugin(): void
    {
        // Two plugins both need reconciling. First one throws; second
        // must still get reconciled — reconcile is not all-or-nothing.
        $this->writePluginWithExtras('broken', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_broken_t']],
        ]);
        $this->writePluginWithExtras('healthy', [
            'database' => ['user' => true, 'owned_tables' => ['plugin_healthy_t']],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(true);
        $cred->method('getPlaintext')->willReturnCallback(function ($id) {
            return $id === 'broken' ? null : 'pw-healthy';
        });

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        // broken never reaches ensureUser (throws before); healthy does.
        $dbUser->expects($this->once())->method('ensureUser')
            ->with('healthy', 'pw-healthy', $this->anything());
        $dbUser->expects($this->once())->method('grant')->with('healthy', ['plugin_healthy_t']);

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $results = $loader->reconcileIsolation();

        $this->assertStringStartsWith('error:', $results['broken']);
        $this->assertStringContainsString('master-key mismatch', $results['broken']);
        $this->assertSame('granted', $results['healthy']);
    }

    // -- isolation side effects (enable/disable wiring) -------------------
    // setEnabled() wires to PluginCredentialService + PluginDbUserService
    // when they're injected. Without them, it's a pure state-file flip.
    // These tests exercise the wiring contract: correct services called,
    // in the correct order, only for plugins declaring `database.user: true`.

    public function testSetEnabledWithoutIsolationServicesJustFlipsFlag(): void
    {
        $this->writePluginWithExtras('no-iso', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_no_iso_t'],
            ],
        ]);

        // No services wired — default constructor path.
        $loader = $this->loader();
        $this->assertTrue($loader->setEnabled('no-iso', false));
        $this->assertTrue($loader->setEnabled('no-iso', true));
    }

    public function testSetEnabledTrueWithIsolationTriggersGenerateAndGrant(): void
    {
        $this->writePluginWithExtras('has-db', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_has_db_subs'],
                'db_limits' => ['max_queries_per_hour' => 20000],
            ],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->with('has-db')->willReturn(false);
        $cred->expects($this->once())->method('generate')->with('has-db')->willReturn('pw123');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->once())->method('ensureUser')
            ->with('has-db', 'pw123', $this->callback(function (array $limits) {
                return ($limits['max_queries_per_hour'] ?? null) === 20000;
            }));
        $dbUser->expects($this->once())->method('grant')
            ->with('has-db', ['plugin_has_db_subs']);
        $dbUser->expects($this->never())->method('revoke');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $this->assertTrue($loader->setEnabled('has-db', true));
    }

    public function testSetEnabledTrueWithExistingCredentialsReusesPlaintext(): void
    {
        $this->writePluginWithExtras('has-db', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_has_db_t'],
            ],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(true);
        $cred->expects($this->never())->method('generate');
        $cred->expects($this->once())->method('getPlaintext')->willReturn('existing-pw');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->once())->method('ensureUser')
            ->with('has-db', 'existing-pw', $this->anything());
        $dbUser->expects($this->once())->method('grant');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $loader->setEnabled('has-db', true);
    }

    public function testSetEnabledFalseWithIsolationRevokesGrants(): void
    {
        $this->writePluginWithExtras('has-db', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_has_db_t'],
            ],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->expects($this->never())->method('generate');
        $cred->expects($this->never())->method('getPlaintext');
        $cred->expects($this->never())->method('delete');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->once())->method('revoke')->with('has-db');
        $dbUser->expects($this->never())->method('ensureUser');
        $dbUser->expects($this->never())->method('grant');
        $dbUser->expects($this->never())->method('dropUser');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $loader->setEnabled('has-db', false);
    }

    public function testSetEnabledSkipsIsolationWhenManifestHasNoDbBlock(): void
    {
        $this->writePluginWithExtras('plain', []); // no `database` key

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->expects($this->never())->method('generate');
        $cred->expects($this->never())->method('getPlaintext');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->never())->method('ensureUser');
        $dbUser->expects($this->never())->method('grant');
        $dbUser->expects($this->never())->method('revoke');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $this->assertTrue($loader->setEnabled('plain', true));
        $this->assertTrue($loader->setEnabled('plain', false));
    }

    public function testSetEnabledSkipsIsolationWhenUserFlagIsFalse(): void
    {
        // `database` block present but `user: false` — technically this would
        // fail manifest validation, so normalizeDatabase() returns null. The
        // loader then behaves as if there were no database block at all.
        $this->writePluginWithExtras('flagged-off', [
            'database' => ['user' => false, 'owned_tables' => []],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->expects($this->never())->method('generate');
        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->never())->method('ensureUser');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $loader->setEnabled('flagged-off', true);
    }

    public function testSetEnabledFailsWithoutFlippingFlagWhenDdlThrows(): void
    {
        $this->writePluginWithExtras('ddl-broken', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_ddl_broken_t'],
            ],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(false);
        $cred->method('generate')->willReturn('pw');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->method('ensureUser')->willThrowException(
            new \RuntimeException('MySQL denied')
        );

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        // setEnabled returns false; the state file is not updated.
        $this->assertFalse($loader->setEnabled('ddl-broken', true));

        // Verify the flag wasn't flipped — a second load still sees disabled.
        $state = is_file($this->stateFile)
            ? json_decode(file_get_contents($this->stateFile), true)
            : [];
        // The plugin was auto-enabled by writePluginWithExtras, so the
        // state file has {"ddl-broken": {"enabled": true}} from the helper.
        // Our assertion is that setEnabled(false, after DDL failure) didn't
        // flip it; here we're in the opposite direction so test a fresh run.
        $this->assertTrue($state['ddl-broken']['enabled']);
    }

    public function testSetEnabledRotatesWhenExistingCredentialsUnreadable(): void
    {
        // Simulates "exists() says yes, but getPlaintext() returned null".
        // This can happen if the row was written under a different master
        // key — rotate recovers rather than getting stuck.
        $this->writePluginWithExtras('unreadable-cred', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_unreadable_cred_t'],
            ],
        ]);

        $cred = $this->createMock(\Eiou\Services\Plugins\PluginCredentialService::class);
        $cred->method('exists')->willReturn(true);
        $cred->method('getPlaintext')->willReturn(null);
        $cred->expects($this->once())->method('rotate')->willReturn('fresh-pw');

        $dbUser = $this->createMock(\Eiou\Services\Plugins\PluginDbUserService::class);
        $dbUser->expects($this->once())->method('ensureUser')
            ->with('unreadable-cred', 'fresh-pw', $this->anything());
        $dbUser->expects($this->once())->method('grant');

        $loader = $this->loader();
        $loader->setIsolationServices($cred, $dbUser);
        $loader->setEnabled('unreadable-cred', true);
    }

    // -- database block (manifest-side validation) ------------------------
    // These tests cover the manifest-parser side of plugin DB isolation —
    // validation and surfacing of the `database` block. The setEnabled()
    // wiring tests above cover the DDL side. See docs/PLUGINS.md for the
    // operator-facing write-up.

    public function testListAllPluginsOmitsDatabaseWhenAbsent(): void
    {
        $this->writePlugin('no-db', 'Eiou\\Tests\\Plugins\\NoDb\\NoDbPlugin',
            $this->validPluginSource('NoDb'));

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertArrayNotHasKey('database', $row);
    }

    public function testListAllPluginsExposesWellFormedDatabaseBlock(): void
    {
        $this->writePluginWithExtras('has-db', [
            'database' => [
                'user' => true,
                'owned_tables' => [
                    'plugin_has_db_subscriptions',
                    'plugin_has_db_notifications',
                ],
                'db_limits' => [
                    'max_queries_per_hour' => 50000,
                    'max_user_connections' => 25,
                ],
            ],
        ]);

        $row = $this->loader()->listAllPlugins()[0];
        $this->assertSame(true, $row['database']['user']);
        $this->assertSame(
            ['plugin_has_db_subscriptions', 'plugin_has_db_notifications'],
            $row['database']['owned_tables']
        );
        // Supplied overrides win, unsupplied limits fall back to core defaults.
        $this->assertSame(50000, $row['database']['db_limits']['max_queries_per_hour']);
        $this->assertSame(25,    $row['database']['db_limits']['max_user_connections']);
        $this->assertSame(5000,  $row['database']['db_limits']['max_updates_per_hour']);
        $this->assertSame(500,   $row['database']['db_limits']['max_connections_per_hour']);
    }

    public function testMalformedDatabaseBlockRejectsPluginLoad(): void
    {
        // user flag missing → plugin should not load (no entry in getLoadedPlugins).
        $this->writePluginWithExtras('bad-db', [
            'database' => [
                'owned_tables' => ['plugin_bad_db_t'],
            ],
        ]);

        $loader = $this->loader();
        $this->assertArrayNotHasKey('bad-db', $loader->getLoadedPlugins());

        // But it should still surface in listAllPlugins with a failed status
        // + error message so the operator can see why it didn't load instead
        // of wondering where it went.
        $rows = array_column($loader->listAllPlugins(), null, 'name');
        $this->assertArrayHasKey('bad-db', $rows);
        $this->assertSame('failed', $rows['bad-db']['status']);
        $this->assertStringContainsString('database.user', $rows['bad-db']['error']);
    }

    public function testOwnedTablesMustStartWithPluginNamePrefix(): void
    {
        // Table named for a different plugin — trying to claim tables we don't own.
        $this->writePluginWithExtras('sneaky', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_other_plugin_contacts'],
            ],
        ]);

        $loader = $this->loader();
        $this->assertArrayNotHasKey('sneaky', $loader->getLoadedPlugins());
        $rows = array_column($loader->listAllPlugins(), null, 'name');
        $this->assertSame('failed', $rows['sneaky']['status']);
        $this->assertStringContainsString('plugin_sneaky_', $rows['sneaky']['error']);
    }

    public function testKebabCasePluginNameSnakesForTablePrefix(): void
    {
        // `my-awesome-plugin` → `plugin_my_awesome_plugin_` (hyphen→underscore).
        $this->writePluginWithExtras('my-awesome-plugin', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_my_awesome_plugin_rows'],
            ],
        ]);

        $loader = $this->loader();
        $loader->discover();
        $this->assertArrayHasKey('my-awesome-plugin', $loader->getLoadedPlugins());
    }

    public function testOwnedTableMustNotBeJustThePrefix(): void
    {
        // `plugin_bare_` with no suffix — not a real table name.
        $this->writePluginWithExtras('bare', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_bare_'],
            ],
        ]);
        $rows = array_column($this->loader()->listAllPlugins(), null, 'name');
        $this->assertSame('failed', $rows['bare']['status']);
    }

    public function testOwnedTableRejectsUppercaseAndSpecialChars(): void
    {
        $this->writePluginWithExtras('upper', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_upper_MyTable'],
            ],
        ]);
        $rows = array_column($this->loader()->listAllPlugins(), null, 'name');
        $this->assertSame('failed', $rows['upper']['status']);
    }

    public function testOwnedTableRejectsOverlongIdentifier(): void
    {
        $longSuffix = str_repeat('x', 80);
        $this->writePluginWithExtras('longtbl', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_longtbl_' . $longSuffix],
            ],
        ]);
        $rows = array_column($this->loader()->listAllPlugins(), null, 'name');
        $this->assertSame('failed', $rows['longtbl']['status']);
        $this->assertStringContainsString('64-char', $rows['longtbl']['error']);
    }

    public function testUserFlagMustBeLiteralTrue(): void
    {
        // `"user": 1` is truthy but not literally true — reject so plugins
        // can't accidentally succeed with a typo.
        $this->writePluginWithExtras('truthy', [
            'database' => [
                'user' => 1,
                'owned_tables' => ['plugin_truthy_t'],
            ],
        ]);
        $rows = array_column($this->loader()->listAllPlugins(), null, 'name');
        $this->assertSame('failed', $rows['truthy']['status']);
        $this->assertStringContainsString('literally `true`', $rows['truthy']['error']);
    }

    public function testEmptyOwnedTablesListIsAllowed(): void
    {
        // Empty list is a deliberate "I'll run CREATE TABLE at runtime but
        // haven't listed them yet" trade-off — legal but uninstall won't
        // drop anything. See docs/PLUGINS.md (Database Isolation).
        $this->writePluginWithExtras('blank-tables', [
            'database' => [
                'user' => true,
                'owned_tables' => [],
            ],
        ]);
        $loader = $this->loader();
        $loader->discover();
        $this->assertArrayHasKey('blank-tables', $loader->getLoadedPlugins());
        $rows = array_column($loader->listAllPlugins(), null, 'name');
        $this->assertSame([], $rows['blank-tables']['database']['owned_tables']);
    }

    public function testInvalidDbLimitsAreDroppedNotFailed(): void
    {
        // An operator typo on a limit value shouldn't brick the plugin —
        // defaults should kick in and the plugin still loads.
        $this->writePluginWithExtras('bad-limits', [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_bad_limits_t'],
                'db_limits' => [
                    'max_queries_per_hour' => 'not-a-number',
                    'max_user_connections' => -5,
                ],
            ],
        ]);
        $loader = $this->loader();
        $loader->discover();
        $this->assertArrayHasKey('bad-limits', $loader->getLoadedPlugins());
        $row = array_column($loader->listAllPlugins(), null, 'name')['bad-limits'];
        $this->assertSame(10000, $row['database']['db_limits']['max_queries_per_hour']);
        $this->assertSame(10,    $row['database']['db_limits']['max_user_connections']);
    }

    public function testOverlongPluginIdRejectedForTableBudget(): void
    {
        $longId = str_repeat('a', 30); // > 24-char budget
        $this->writePluginWithExtras($longId, [
            'database' => [
                'user' => true,
                'owned_tables' => ['plugin_' . $longId . '_t'],
            ],
        ]);
        $rows = array_column($this->loader()->listAllPlugins(), null, 'name');
        $this->assertSame('failed', $rows[$longId]['status']);
        $this->assertStringContainsString('too long', $rows[$longId]['error']);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = array_diff(scandir($path), ['.', '..']);
        foreach ($items as $item) {
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    // ===================================================================
    // Permission-consent state — approved_permissions + approved_at
    // persistence, drift detection, and the resolveApprovalSet
    // refused-stage failure path. Sibling fixture writePluginWithExtras
    // lets us inject the manifest's `permissions: [...]` list directly.
    // ===================================================================

    public function testEnablePersistsApprovedPermissionsAndTimestamp(): void
    {
        $this->writePluginWithExtras('beta-perm', [
            'permissions' => ['contact_address_book_enumerate'],
        ]);
        // Tear down the auto-enabled state — writePluginWithExtras flips
        // enabled=true on disk before consent is recorded. Tests of
        // first-time enable must start from disabled.
        file_put_contents($this->stateFile, json_encode([]));

        $loader = $this->loader();
        $this->assertTrue(
            $loader->setEnabled('beta-perm', true, ['contact_address_book_enumerate']),
            'setEnabled with matching approval must succeed'
        );
        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertTrue($state['beta-perm']['enabled']);
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $state['beta-perm']['approved_permissions']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $state['beta-perm']['approved_at']
        );
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $loader->getApprovedPermissions('beta-perm')
        );
    }

    public function testEnableRefusesWhenApprovalSupersetsManifest(): void
    {
        // Operator can't escalate via the approval set — must be a
        // subset of what the manifest declares. wallet_outbound_send
        // is a different permission key from what the manifest
        // requests, so the loader refuses (stage=refused).
        $this->writePluginWithExtras('escalate', [
            'permissions' => ['contact_address_book_enumerate'],
        ]);
        file_put_contents($this->stateFile, json_encode([]));

        $loader = $this->loader();
        $this->assertFalse(
            $loader->setEnabled('escalate', true, ['wallet_outbound_send']),
            'must refuse approval set with keys outside the manifest'
        );
        $failure = $loader->getLastSetEnabledFailure();
        $this->assertSame('refused', $failure['stage']);
        $this->assertStringContainsString('wallet_outbound_send', $failure['message']);
    }

    public function testReEnableWithoutApprovalsUsesExistingApprovedSet(): void
    {
        // Plugin enabled with consent on a previous boot. A re-enable
        // without a fresh approval list must succeed by re-using the
        // recorded grant — the GUI / CLI shouldn't have to know the
        // previously-approved set to re-toggle.
        $this->writePluginWithExtras('reenable', [
            'permissions' => ['contact_address_book_enumerate'],
        ]);
        file_put_contents($this->stateFile, json_encode([
            'reenable' => [
                'enabled' => false,
                'approved_permissions' => ['contact_address_book_enumerate'],
                'approved_at' => '2026-05-14T10:00:00Z',
            ],
        ]));

        $loader = $this->loader();
        $this->assertTrue($loader->setEnabled('reenable', true));
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $loader->getApprovedPermissions('reenable')
        );
    }

    public function testEnableRefusesWhenManifestDriftedFromApprovedSet(): void
    {
        // Plugin was approved for one key. Manifest now requests two.
        // A re-enable without explicit consent must refuse — operator
        // must go through the CLI / GUI consent path for the new key.
        $this->writePluginWithExtras('drift', [
            'permissions' => ['contact_address_book_enumerate', 'wallet_balance_read'],
        ]);
        file_put_contents($this->stateFile, json_encode([
            'drift' => [
                'enabled' => false,
                'approved_permissions' => ['contact_address_book_enumerate'],
                'approved_at' => '2026-05-14T10:00:00Z',
            ],
        ]));

        $loader = $this->loader();
        $this->assertFalse($loader->setEnabled('drift', true));
        $failure = $loader->getLastSetEnabledFailure();
        $this->assertSame('refused', $failure['stage']);
        $this->assertStringContainsString('wallet_balance_read', $failure['message']);
    }

    public function testDisablePreservesApprovedSet(): void
    {
        // Disable must not clear approvals — re-enabling later with
        // an unchanged manifest can skip the prompt.
        $this->writePluginWithExtras('keep', [
            'permissions' => ['contact_address_book_enumerate'],
        ]);
        file_put_contents($this->stateFile, json_encode([
            'keep' => [
                'enabled' => true,
                'approved_permissions' => ['contact_address_book_enumerate'],
                'approved_at' => '2026-05-14T10:00:00Z',
            ],
        ]));

        $loader = $this->loader();
        $this->assertTrue($loader->setEnabled('keep', false));
        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertFalse($state['keep']['enabled']);
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $state['keep']['approved_permissions']
        );
    }

    public function testDiscoverAutoDisablesPluginsWithManifestDrift(): void
    {
        // Plugin enabled with consent for one key; manifest now
        // requests an additional key the operator never approved.
        // discover() must auto-flip enabled→false so the gateway can't
        // route any call into the un-consented surface.
        $this->writePluginWithExtras('drift-boot', [
            'permissions' => ['contact_address_book_enumerate', 'wallet_balance_read'],
        ]);
        file_put_contents($this->stateFile, json_encode([
            'drift-boot' => [
                'enabled' => true,
                'approved_permissions' => ['contact_address_book_enumerate'],
                'approved_at' => '2026-05-14T10:00:00Z',
            ],
        ]));

        $loader = $this->loader();
        $loader->discover();

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertFalse(
            $state['drift-boot']['enabled'],
            'drift-affected plugin must be auto-disabled on discover()'
        );
        // Existing approvals stay on file so a future re-enable can
        // diff against them and prompt only for the new key.
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $state['drift-boot']['approved_permissions']
        );
    }

    public function testListAllPluginsSurfacesApprovalStateAndDrift(): void
    {
        $this->writePluginWithExtras('surfaced', [
            'permissions' => ['contact_address_book_enumerate', 'wallet_balance_read'],
        ]);
        file_put_contents($this->stateFile, json_encode([
            'surfaced' => [
                'enabled' => false,
                'approved_permissions' => ['contact_address_book_enumerate'],
                'approved_at' => '2026-05-14T10:00:00Z',
            ],
        ]));

        $loader = $this->loader();
        $rows = array_values(array_filter(
            $loader->listAllPlugins(),
            fn($r) => ($r['name'] ?? null) === 'surfaced'
        ));
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(
            ['contact_address_book_enumerate', 'wallet_balance_read'],
            $row['permissions']
        );
        $this->assertSame(
            ['contact_address_book_enumerate'],
            $row['approved_permissions']
        );
        $this->assertSame('2026-05-14T10:00:00Z', $row['approved_at']);
        $this->assertSame(['wallet_balance_read'], $row['permission_drift']);
    }
}
