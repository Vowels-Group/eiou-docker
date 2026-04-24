<?php
/**
 * Unit Tests for PluginLoader
 *
 * Covers manifest discovery, PSR-4 autoload registration, lifecycle phases
 * (register/boot), failure isolation, and persisted enable/disable state.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Services\PluginLoader;
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
        return new PluginLoader($this->pluginRoot(), $this->logger, $this->stateFile);
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
        $plugins = $loader->discover();

        $this->assertArrayHasKey('valid', $plugins);
        $this->assertSame('1.0.0', $plugins['valid']->getVersion());
        $this->assertSame('discovered', $loader->getLoadedPlugins()['valid']['status']);
        $this->assertTrue($loader->getLoadedPlugins()['valid']['enabled']);
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

    public function testDiscoverSkipsClassNotImplementingInterface(): void
    {
        $source = "<?php\nnamespace Eiou\\Tests\\Plugins\\Bad;\nclass BadPlugin { public function getName(): string { return 'bad'; } }\n";
        $this->writePlugin('bad', 'Eiou\\Tests\\Plugins\\Bad\\BadPlugin', $source);

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->assertSame([], $this->loader()->discover());
    }

    public function testRegisterAllCallsRegisterOnEachPlugin(): void
    {
        $this->writePlugin('reg', 'Eiou\\Tests\\Plugins\\Reg\\RegPlugin', $this->validPluginSource('Reg'));

        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);

        $this->assertSame('registered', $loader->getLoadedPlugins()['reg']['status']);
    }

    public function testBootAllCallsBootOnEachPlugin(): void
    {
        $this->writePlugin('booted', 'Eiou\\Tests\\Plugins\\Booted\\BootedPlugin', $this->validPluginSource('Booted'));

        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);
        $loader->bootAll($container);

        $this->assertSame('booted', $loader->getLoadedPlugins()['booted']['status']);
    }

    public function testRegisterFailureDisablesPluginWithoutAffectingSiblings(): void
    {
        $this->writePlugin('throwing', 'Eiou\\Tests\\Plugins\\Throwing\\ThrowingPlugin',
            $this->throwingPluginSource('Throwing', 'register'));
        $this->writePlugin('healthy', 'Eiou\\Tests\\Plugins\\Healthy\\HealthyPlugin',
            $this->validPluginSource('Healthy'));

        $this->logger->expects($this->atLeastOnce())->method('error');
        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);
        $loader->bootAll($container);

        $meta = $loader->getLoadedPlugins();
        $this->assertSame('failed', $meta['throwing']['status']);
        $this->assertStringContainsString('register', $meta['throwing']['error']);
        $this->assertSame('booted', $meta['healthy']['status']);
    }

    public function testBootFailureDisablesOnlyThatPlugin(): void
    {
        $this->writePlugin('boot-fail', 'Eiou\\Tests\\Plugins\\BootFail\\BootFailPlugin',
            $this->throwingPluginSource('BootFail', 'boot'));
        $this->writePlugin('boot-ok', 'Eiou\\Tests\\Plugins\\BootOk\\BootOkPlugin',
            $this->validPluginSource('BootOk'));

        $this->logger->expects($this->atLeastOnce())->method('error');
        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);
        $loader->bootAll($container);

        $meta = $loader->getLoadedPlugins();
        $this->assertSame('failed', $meta['boot-fail']['status']);
        $this->assertSame('booted', $meta['boot-ok']['status']);
    }

    public function testFailedPluginIsSkippedInBootPhase(): void
    {
        $this->writePlugin('skip-boot', 'Eiou\\Tests\\Plugins\\SkipBoot\\SkipBootPlugin',
            $this->throwingPluginSource('SkipBoot', 'register'));

        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);
        $loader->bootAll($container);

        $meta = $loader->getLoadedPlugins();
        $this->assertStringContainsString('register', $meta['skip-boot']['error']);
    }

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
        ]));
        file_put_contents($secondPath . '/src/DupTwoPlugin.php', $this->validPluginSource('DupTwo'));

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $loader = $this->loader();
        $plugins = $loader->discover();

        $this->assertCount(1, $plugins);
        $this->assertSame('1.0.0', $loader->getLoadedPlugins()['dup']['version']);
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
        $plugins = $loader->discover();

        $this->assertArrayHasKey('explicitly-on', $plugins);
        $this->assertTrue($loader->getLoadedPlugins()['explicitly-on']['enabled']);
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

    // -- Lifecycle event dispatch ------------------------------------------

    public function testRegisterAllDispatchesPluginRegistered(): void
    {
        $this->writePlugin('lifecycle-ok', 'Eiou\\Tests\\Plugins\\LifecycleOk\\LifecycleOkPlugin',
            $this->validPluginSource('LifecycleOk'));
        $loader = $this->loader();
        $loader->discover();

        $fired = [];
        EventDispatcher::getInstance()->subscribe(PluginEvents::PLUGIN_REGISTERED, function (array $data) use (&$fired) {
            $fired[] = $data;
        });

        $loader->registerAll($this->createMock(ServiceContainer::class));

        $this->assertCount(1, $fired);
        $this->assertSame('lifecycle-ok', $fired[0]['name']);
        $this->assertSame('1.0.0', $fired[0]['version']);
    }

    public function testBootAllDispatchesPluginBooted(): void
    {
        $this->writePlugin('boot-ok', 'Eiou\\Tests\\Plugins\\BootOk\\BootOkPlugin',
            $this->validPluginSource('BootOk'));
        $loader = $this->loader();
        $loader->discover();
        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);

        $fired = [];
        EventDispatcher::getInstance()->subscribe(PluginEvents::PLUGIN_BOOTED, function (array $data) use (&$fired) {
            $fired[] = $data;
        });

        $loader->bootAll($container);

        $this->assertCount(1, $fired);
        $this->assertSame('boot-ok', $fired[0]['name']);
    }

    public function testFailedPluginDispatchesPluginFailed(): void
    {
        // Plugin that throws inside boot() — covers the disablePlugin path
        // for the 'boot' phase. The register phase uses the same disable
        // helper so this single test covers both.
        $this->writePlugin('boom',
            'Eiou\\Tests\\Plugins\\Boom\\BoomPlugin',
            $this->throwingPluginSource('Boom', 'boot')
        );
        $loader = $this->loader();
        $loader->discover();
        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);

        $fired = [];
        EventDispatcher::getInstance()->subscribe(PluginEvents::PLUGIN_FAILED, function (array $data) use (&$fired) {
            $fired[] = $data;
        });

        $loader->bootAll($container);

        $this->assertCount(1, $fired);
        $this->assertSame('boom', $fired[0]['name']);
        $this->assertSame('boot', $fired[0]['phase']);
        $this->assertStringContainsString('boot exploded', $fired[0]['error']);
    }

    // -- Lifecycle idempotency ---------------------------------------------

    public function testRegisterAllIsIdempotent(): void
    {
        // Plugin counts register() calls in a static so tests can observe
        // how many times the lifecycle ran on the same loader.
        $this->writePlugin('count-reg', 'Eiou\\Tests\\Plugins\\CountReg\\CountRegPlugin',
            $this->countingPluginSource('CountReg'));

        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);
        $loader->registerAll($container);
        $loader->registerAll($container);

        $registerCount = \Eiou\Tests\Plugins\CountReg\CountRegPlugin::$registerCalls;
        $this->assertSame(1, $registerCount,
            'registerAll() called 3 times but plugin->register() should only fire once');
    }

    public function testBootAllIsIdempotent(): void
    {
        // The real bug we're guarding against: a second bootAll() must NOT
        // re-call boot(). If boot() re-ran, it would double-subscribe event
        // listeners and one event would trigger N reactions per worker.
        $this->writePlugin('count-boot', 'Eiou\\Tests\\Plugins\\CountBoot\\CountBootPlugin',
            $this->countingPluginSource('CountBoot'));

        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);
        $loader->bootAll($container);
        $loader->bootAll($container);
        $loader->bootAll($container);

        $bootCount = \Eiou\Tests\Plugins\CountBoot\CountBootPlugin::$bootCalls;
        $this->assertSame(1, $bootCount,
            'bootAll() called 3 times but plugin->boot() should only fire once');
    }

    public function testFailedPluginNotRetriedOnSecondRegisterAll(): void
    {
        // Once register() throws, the plugin is marked failed. Subsequent
        // registerAll() calls should leave it failed, not retry it.
        $this->writePlugin('fail-once', 'Eiou\\Tests\\Plugins\\FailOnce\\FailOncePlugin',
            $this->throwingPluginSource('FailOnce', 'register'));

        $this->logger->expects($this->atLeastOnce())->method('error');
        $loader = $this->loader();
        $loader->discover();

        $container = $this->createMock(ServiceContainer::class);
        $loader->registerAll($container);
        $loader->registerAll($container);

        $this->assertSame('failed', $loader->getLoadedPlugins()['fail-once']['status']);
    }

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

    // -- database block (manifest-side validation, phase 1) ---------------
    // Phase 1 of plugin DB isolation only validates and surfaces the manifest
    // `database` block; it does not create MySQL users or touch grants yet.
    // See docs/PLUGIN_ISOLATION.md for the full design.

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
        // drop anything. See docs/PLUGIN_ISOLATION.md §4.
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
}
