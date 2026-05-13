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

    // =========================================================================
    // CORS — cors_allowed_origins per route. No wildcard, explicit list only.
    // =========================================================================

    #[Test]
    public function publicRouteWithoutCorsOmitsCorsBlock(): void
    {
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [['method' => 'POST', 'action' => 'chat']],
            ],
        ]);
        $this->assertStringNotContainsString('Access-Control-Allow-Origin', $snippet);
        $this->assertStringNotContainsString('cors_origin', $snippet);
        $this->assertStringNotContainsString('OPTIONS', $snippet);
    }

    #[Test]
    public function publicRouteWithSingleOriginEmitsCorsBlock(): void
    {
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    [
                        'method' => 'POST',
                        'action' => 'chat',
                        'cors_allowed_origins' => ['https://example.com'],
                    ],
                ],
            ],
        ]);
        $this->assertStringContainsString('set $cors_origin "";', $snippet);
        $this->assertStringContainsString('if ($http_origin = "https://example.com")', $snippet);
        $this->assertStringContainsString('Access-Control-Allow-Origin  $cors_origin always', $snippet);
        $this->assertStringContainsString('Access-Control-Allow-Methods "POST, OPTIONS" always', $snippet);
        // Preflight short-circuits without invoking the plugin pool.
        $this->assertStringContainsString('if ($request_method = OPTIONS) { return 204; }', $snippet);
        // Vary header so caches don't reuse the wrong CORS response.
        $this->assertStringContainsString('Vary                          "Origin"', $snippet);
    }

    #[Test]
    public function publicRouteWithMultipleOriginsEmitsOneCheckPerOrigin(): void
    {
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
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
            ],
        ]);
        $this->assertStringContainsString('"https://example.com"', $snippet);
        $this->assertStringContainsString('"https://app.example.com"', $snippet);
        $this->assertStringContainsString('"http://localhost:3000"', $snippet);
    }

    #[Test]
    public function publicRouteCorsRendererDropsMalformedOrigin(): void
    {
        // Manifest validator should already drop these, but the renderer
        // double-checks before they land in the nginx config verbatim.
        $onSvc = new PluginNginxConfigService(true);
        $snippet = $onSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    [
                        'method' => 'POST',
                        'action' => 'chat',
                        'cors_allowed_origins' => [
                            '*',                                  // wildcard rejected
                            'javascript:alert(1)',                // hostile scheme
                            'https://example.com/path',           // path component
                            'https://"; rm -rf /; #.example.com', // injection attempt
                            'https://ok.example.com',             // good
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertStringContainsString('"https://ok.example.com"', $snippet);
        $this->assertStringNotContainsString('"*"', $snippet);
        $this->assertStringNotContainsString('javascript:', $snippet);
        $this->assertStringNotContainsString('rm -rf', $snippet);
        $this->assertStringNotContainsString('/path"', $snippet);
    }

    #[Test]
    public function publicRouteCorsBlockOnlyAddsOnceWhenFlagOff(): void
    {
        // Even when the manifest has CORS configured, with the feature
        // flag off the whole /p/ block doesn't render — so CORS lines
        // don't either.
        $offSvc = new PluginNginxConfigService(false);
        $snippet = $offSvc->renderSnippet([
            [
                'plugin_id'   => 'demo',
                'system_user' => 'eiou-p-deadbeef',
                'public_routes' => [
                    [
                        'method' => 'POST',
                        'action' => 'chat',
                        'cors_allowed_origins' => ['https://example.com'],
                    ],
                ],
            ],
        ]);
        $this->assertStringNotContainsString('Access-Control-Allow-Origin', $snippet);
    }
}
