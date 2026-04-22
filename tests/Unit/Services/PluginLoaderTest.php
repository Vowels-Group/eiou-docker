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
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
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
