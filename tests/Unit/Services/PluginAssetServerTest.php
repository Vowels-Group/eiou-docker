<?php
/**
 * Unit Tests for PluginAssetServer
 *
 * Phase 6 of plugin-GUI-hooks. Covers path validation, MIME gating,
 * content-hash cache headers, ETag round-trip, and 404 paths. The
 * server is a pure request → response struct, so all tests work
 * without a real HTTP layer.
 *
 * See docs/PLUGIN_GUI_HOOKS.md.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\PluginAssetServer;

#[CoversClass(PluginAssetServer::class)]
class PluginAssetServerTest extends TestCase
{
    private string $root;
    private PluginAssetServer $server;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/eiou-plugin-asset-test-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->server = new PluginAssetServer($this->root);
        // Reset the IF_NONE_MATCH between tests since the server
        // reads it directly from $_SERVER. The test runner shares
        // the superglobal across tests.
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeAsset(string $pluginId, string $relPath, string $content): string
    {
        $dir = $this->root . '/' . $pluginId . '/' . dirname($relPath);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $abs = $this->root . '/' . $pluginId . '/' . $relPath;
        file_put_contents($abs, $content);
        return $abs;
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function testServesValidCssAsset(): void
    {
        $this->writeAsset('hello-eiou', 'main.css', 'body { color: red; }');

        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/main.css');
        $this->assertSame(200, $r['status']);
        $this->assertSame('text/css; charset=utf-8', $r['headers']['Content-Type']);
        $this->assertSame('body { color: red; }', $r['body']);
        $this->assertArrayHasKey('ETag', $r['headers']);
    }

    public function testServesValidJsAsset(): void
    {
        $this->writeAsset('hello-eiou', 'app.js', 'console.log("ok");');
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/app.js');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('javascript', $r['headers']['Content-Type']);
    }

    public function testServesNestedPath(): void
    {
        $this->writeAsset('hello-eiou', 'lib/sub/x.css', 'a{}');
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/lib/sub/x.css');
        $this->assertSame(200, $r['status']);
        $this->assertSame('a{}', $r['body']);
    }

    // =========================================================================
    // Cache headers — content hash matched vs missed
    // =========================================================================

    public function testMatchingHashEmitsImmutableCache(): void
    {
        $this->writeAsset('hello-eiou', 'main.css', 'body{}');
        $hash = substr(hash('sha256', 'body{}'), 0, 16);

        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/main.css', $hash);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('immutable', $r['headers']['Cache-Control']);
        $this->assertStringContainsString('max-age=31536000', $r['headers']['Cache-Control']);
    }

    public function testNoHashUsesShortCache(): void
    {
        $this->writeAsset('hello-eiou', 'main.css', 'body{}');

        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/main.css', '');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('max-age=60', $r['headers']['Cache-Control']);
        $this->assertStringNotContainsString('immutable', $r['headers']['Cache-Control']);
    }

    public function testMismatchedHashUsesShortCache(): void
    {
        $this->writeAsset('hello-eiou', 'main.css', 'body{}');
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/main.css', 'deadbeefdeadbeef');
        $this->assertStringContainsString('max-age=60', $r['headers']['Cache-Control']);
    }

    public function testIfNoneMatchReturns304(): void
    {
        $this->writeAsset('hello-eiou', 'main.css', 'body{}');
        $hash = substr(hash('sha256', 'body{}'), 0, 16);
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $hash . '"';

        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/main.css', $hash);
        $this->assertSame(304, $r['status']);
        $this->assertSame('', $r['body']);
        $this->assertSame('"' . $hash . '"', $r['headers']['ETag']);
    }

    public function testIfNoneMatchWrongHashReturns200(): void
    {
        $this->writeAsset('hello-eiou', 'main.css', 'body{}');
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"oldhash00000000"';

        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/main.css');
        $this->assertSame(200, $r['status']);
        $this->assertSame('body{}', $r['body']);
    }

    // =========================================================================
    // Path validation — same shape rules PluginAssetRegistry enforces
    // =========================================================================

    public function testRejectsPathTraversal(): void
    {
        $this->writeAsset('hello-eiou', 'main.css', 'body{}');
        // Even if the resolved path would land in the plugin root, the
        // raw `..` segment is rejected before disk lookup.
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/../hello-eiou/main.css');
        $this->assertSame(404, $r['status']);
    }

    public function testRejectsInvalidPluginId(): void
    {
        $r = $this->server->handle('/gui/plugin-assets/Bad-ID/main.css');
        $this->assertSame(404, $r['status']);

        $r2 = $this->server->handle('/gui/plugin-assets/CamelCase/main.css');
        $this->assertSame(404, $r2['status']);
    }

    public function testRejectsMissingFile(): void
    {
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/missing.css');
        $this->assertSame(404, $r['status']);
    }

    public function testRejectsOffPrefix(): void
    {
        $r = $this->server->handle('/somewhere/else/foo.css');
        $this->assertSame(404, $r['status']);
    }

    public function testRejectsEmptyPath(): void
    {
        $r = $this->server->handle('/gui/plugin-assets/');
        $this->assertSame(404, $r['status']);
    }

    public function testRejectsPluginIdWithoutPath(): void
    {
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou');
        $this->assertSame(404, $r['status']);
    }

    // =========================================================================
    // MIME gate — only the allow-list is served
    // =========================================================================

    public function testRejectsPhpExtension(): void
    {
        $this->writeAsset('hello-eiou', 'evil.php', '<?php echo "nope"; ?>');
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/evil.php');
        $this->assertSame(415, $r['status']);
    }

    public function testRejectsHtmlExtension(): void
    {
        $this->writeAsset('hello-eiou', 'evil.html', '<html></html>');
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/evil.html');
        $this->assertSame(415, $r['status']);
    }

    public function testAcceptsSvg(): void
    {
        $this->writeAsset('hello-eiou', 'icon.svg', '<svg></svg>');
        $r = $this->server->handle('/gui/plugin-assets/hello-eiou/icon.svg');
        $this->assertSame(200, $r['status']);
        $this->assertSame('image/svg+xml', $r['headers']['Content-Type']);
    }

    // =========================================================================
    // isValidPluginId static — used by registry to gate URL emission
    // =========================================================================

    public function testIsValidPluginIdMatchesKebabCase(): void
    {
        $this->assertTrue(PluginAssetServer::isValidPluginId('hello'));
        $this->assertTrue(PluginAssetServer::isValidPluginId('hello-eiou'));
        $this->assertTrue(PluginAssetServer::isValidPluginId('plugin_v2'));
        $this->assertFalse(PluginAssetServer::isValidPluginId(''));
        $this->assertFalse(PluginAssetServer::isValidPluginId('Hello'));
        $this->assertFalse(PluginAssetServer::isValidPluginId('-leading'));
        $this->assertFalse(PluginAssetServer::isValidPluginId('with space'));
    }
}
