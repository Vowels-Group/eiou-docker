<?php
namespace Eiou\Tests\Services;

use Eiou\Services\PluginNginxConfigService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    // =========================================================================
    // public_routes — gated by the EIOU_PUBLIC_PLUGIN_ROUTES feature flag.
    // Off-by-default keeps the surface invisible until an operator opts in.
    // =========================================================================

    #[Test]
    public function publicRoutesAreSilentlyDroppedWhenFlagOff(): void
    {
        $offSvc = new PluginNginxConfigService(false);
        $snippet = $offSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    ['method' => 'POST', 'action' => 'chat'],
                ],
            ],
        ]);
        $this->assertStringNotContainsString('/p/demo/chat', $snippet);
        // The IPC block still renders — public routes are additive.
        $this->assertStringContainsString('/gui/plugin/demo/', $snippet);
    }

    #[Test]
    public function publicRouteBlockRendersWhenFlagOn(): void
    {
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    ['method' => 'POST', 'action' => 'chat', 'max_body_bytes' => 65536],
                ],
            ],
        ]);
        $this->assertStringContainsString('location = /p/demo/chat {', $snippet);
        $this->assertStringContainsString('if ($request_method != POST)', $snippet);
        $this->assertStringContainsString("if (\$http_authorization !~ '^Bearer", $snippet);
        $this->assertStringContainsString('client_max_body_size 65536', $snippet);
        $this->assertStringContainsString('EIOU_PLUGIN_PUBLIC_ROUTE 1', $snippet);
        $this->assertStringContainsString('EIOU_PLUGIN_PUBLIC_ACTION chat', $snippet);
    }

    #[Test]
    public function publicRouteWithoutMaxBodyDefaultsTo64K(): void
    {
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    ['method' => 'POST', 'action' => 'chat'],
                ],
            ],
        ]);
        $this->assertStringContainsString('client_max_body_size 65536', $snippet);
    }

    #[Test]
    public function publicRouteWithBadShapeIsSkipped(): void
    {
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    ['method' => 'WAT',  'action' => 'chat'],       // bad verb
                    ['method' => 'POST', 'action' => 'Bad-Caps'],   // bad action
                    ['method' => 'POST', 'action' => 'ok-one'],     // good
                ],
            ],
        ]);
        $this->assertStringNotContainsString('chat', $snippet);
        $this->assertStringNotContainsString('Bad-Caps', $snippet);
        $this->assertStringContainsString('/p/demo/ok-one', $snippet);
    }

    #[Test]
    public function publicRouteMaxBodyOutOfRangeFallsBackToDefault(): void
    {
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    ['method' => 'POST', 'action' => 'chat', 'max_body_bytes' => 9_999_999],
                ],
            ],
        ]);
        // 9_999_999 > 1MiB cap → renderer drops back to default.
        $this->assertStringContainsString('client_max_body_size 65536', $snippet);
        $this->assertStringNotContainsString('9999999', $snippet);
    }
}
