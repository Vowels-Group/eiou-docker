<?php
namespace Eiou\Tests\Services;

use Eiou\Services\PluginNginxConfigService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 of plugin sandboxing — see docs/PLUGIN_SANDBOXING.md.
 */
#[CoversClass(PluginNginxConfigService::class)]
class PluginNginxConfigServiceTest extends TestCase
{
    private PluginNginxConfigService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PluginNginxConfigService();
    }

    #[Test]
    public function emptySnippetWhenNoSandboxedPluginsEnabled(): void
    {
        $snippet = $this->svc->renderSnippet([]);
        // Header comments are present; no location block exists.
        $this->assertStringContainsString('Auto-generated', $snippet);
        $this->assertStringNotContainsString('location ^~', $snippet);
        $this->assertStringContainsString('no sandboxed plugins enabled', $snippet);
    }

    #[Test]
    public function singlePluginRendersLocationBlockToItsSocket(): void
    {
        $snippet = $this->svc->renderSnippet([
            ['plugin_id' => 'hello-eiou', 'system_user' => 'eiou-p-deadbeef'],
        ]);
        $this->assertStringContainsString('location ^~ /gui/plugin/hello-eiou/', $snippet);
        $this->assertStringContainsString(
            'fastcgi_pass unix:/run/php/eiou-plugin-eiou-p-deadbeef.sock',
            $snippet
        );
        // The plugin's __dispatch.php is the only entry point that
        // exists for it — nginx never lets the request pick a path.
        $this->assertStringContainsString(
            '/etc/eiou/plugins/hello-eiou/__dispatch.php',
            $snippet
        );
    }

    #[Test]
    public function multiplePluginsRenderInOrder(): void
    {
        $snippet = $this->svc->renderSnippet([
            ['plugin_id' => 'alpha', 'system_user' => 'eiou-p-00000001'],
            ['plugin_id' => 'beta',  'system_user' => 'eiou-p-00000002'],
        ]);
        // Two distinct location blocks, in the order given.
        $alphaPos = strpos($snippet, '/gui/plugin/alpha/');
        $betaPos = strpos($snippet, '/gui/plugin/beta/');
        $this->assertNotFalse($alphaPos);
        $this->assertNotFalse($betaPos);
        $this->assertLessThan($betaPos, $alphaPos, 'Plugins render in given order');
    }

    #[Test]
    public function rendererRejectsInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->renderSnippet([
            ['plugin_id' => 'Bad-Caps', 'system_user' => 'eiou-p-deadbeef'],
        ]);
    }

    #[Test]
    public function rendererRejectsUnsafeSystemUser(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Even with a valid plugin id, refuse to render a route to
        // anything not matching the eiou-p-<hex> shape. Defence in
        // depth against a corrupted call site.
        $this->svc->renderSnippet([
            ['plugin_id' => 'demo', 'system_user' => 'www-data'],
        ]);
    }
}
