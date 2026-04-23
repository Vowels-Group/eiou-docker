<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use Eiou\Services\CliPluginService;
use Eiou\Services\PluginLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CliPluginService::class)]
class CliPluginServiceTest extends TestCase
{
    private string $pluginRoot;
    private string $stateFile;
    private PluginLoader $loader;
    private MockObject|CliOutputManager $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginRoot = sys_get_temp_dir() . '/eiou-cli-plugin-' . uniqid();
        $this->stateFile = $this->pluginRoot . '-state.json';
        mkdir($this->pluginRoot, 0777, true);
        $this->loader = new PluginLoader($this->pluginRoot, null, $this->stateFile);
        $this->output = $this->createMock(CliOutputManager::class);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->pluginRoot);
        @unlink($this->stateFile);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) { $this->rrmdir($path); } else { @unlink($path); }
        }
        @rmdir($dir);
    }

    private function writeFixturePlugin(string $name): void
    {
        $dir = $this->pluginRoot . '/' . $name;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => $name,
            'version' => '1.0.0',
            'description' => 'fixture',
            'entryClass' => 'Nope\\Nope',
            'autoload' => ['psr-4' => ['Nope\\' => 'src/']],
        ]));
    }

    public function testListJsonModeEmitsAllPlugins(): void
    {
        $this->writeFixturePlugin('alpha');
        $this->writeFixturePlugin('beta');

        $captured = null;
        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success')
            ->willReturnCallback(function (string $msg, $data) use (&$captured) {
                $captured = $data;
            });

        (new CliPluginService($this->loader))->listPlugins(['eiou','plugin'], $this->output);

        $names = array_column($captured['plugins'], 'name');
        sort($names);
        $this->assertSame(['alpha', 'beta'], $names);
    }

    public function testListTextModeRendersTable(): void
    {
        $this->writeFixturePlugin('alpha');

        $this->output->method('isJsonMode')->willReturn(false);
        $this->output->expects($this->once())->method('table')
            ->with(
                $this->equalTo(['NAME', 'VERSION', 'ENABLED', 'STATUS', 'LICENSE']),
                $this->callback(fn($rows) => count($rows) === 1 && $rows[0][0] === 'alpha'),
                $this->anything()
            );

        (new CliPluginService($this->loader))->listPlugins(['eiou','plugin'], $this->output);
    }

    public function testEnablePersistsFlagAndReportsRestartRequired(): void
    {
        $this->writeFixturePlugin('alpha');

        $captured = null;
        $this->output->method('isJsonMode')->willReturn(true);
        $this->output->expects($this->once())->method('success')
            ->willReturnCallback(function (string $msg, $data) use (&$captured) {
                $captured = $data;
            });

        (new CliPluginService($this->loader))
            ->enablePlugin(['eiou','plugin','enable','alpha'], $this->output);

        $this->assertTrue($captured['enabled']);
        $this->assertTrue($captured['restart_required']);
        $this->assertSame('alpha', $captured['plugin']);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertTrue($state['alpha']['enabled']);
    }

    public function testDisableFlipsFlagOff(): void
    {
        $this->writeFixturePlugin('alpha');
        file_put_contents($this->stateFile, json_encode(['alpha' => ['enabled' => true]]));

        $this->output->expects($this->once())->method('success');

        (new CliPluginService($this->loader))
            ->disablePlugin(['eiou','plugin','disable','alpha'], $this->output);

        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertFalse($state['alpha']['enabled']);
    }

    public function testEnableRejectsUnknownPlugin(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->stringContains('Unknown plugin'),
                ErrorCodes::NOT_FOUND
            );

        (new CliPluginService($this->loader))
            ->enablePlugin(['eiou','plugin','enable','ghost'], $this->output);
    }

    public function testEnableRejectsInvalidName(): void
    {
        $this->output->expects($this->once())->method('error')
            ->with(
                $this->anything(),
                ErrorCodes::VALIDATION_ERROR
            );

        (new CliPluginService($this->loader))
            ->enablePlugin(['eiou','plugin','enable','../etc/passwd'], $this->output);
    }

    public function testListEmptyShowsHelpfulMessage(): void
    {
        $this->output->method('isJsonMode')->willReturn(false);
        $this->output->expects($this->once())->method('info')
            ->with($this->stringContains('No plugins installed'));

        (new CliPluginService($this->loader))->listPlugins(['eiou','plugin'], $this->output);
    }
}
