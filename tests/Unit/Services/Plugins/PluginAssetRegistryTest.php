<?php
/**
 * Unit Tests for PluginAssetRegistry
 *
 * Plugins enqueue CSS / JS files; the host renders them inline with
 * the page's CSP nonce. Tests cover the registration validators
 * (kebab-case plugin id, no path traversal), inline rendering
 * shape, priority ordering, head-vs-body script split, missing-file
 * graceful skip. See docs/PLUGINS.md "Extending the GUI".
 */

namespace Eiou\Tests\Services\Plugins;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Plugins\PluginAssetRegistry;

#[CoversClass(PluginAssetRegistry::class)]
class PluginAssetRegistryTest extends TestCase
{
    /** Per-test plugin sandbox that lives under sys_get_temp_dir(). */
    private string $pluginRoot;
    private PluginAssetRegistry $registry;

    protected function setUp(): void
    {
        $this->pluginRoot = sys_get_temp_dir() . '/eiou-asset-registry-' . bin2hex(random_bytes(4));
        mkdir($this->pluginRoot, 0700, true);
        $this->registry = new PluginAssetRegistry($this->pluginRoot);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->pluginRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function writePluginAsset(string $pluginId, string $relPath, string $body): string
    {
        $abs = $this->pluginRoot . '/' . $pluginId . '/' . $relPath;
        $dir = dirname($abs);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($abs, $body);
        return $abs;
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function testEnqueueRejectsInvalidPluginId(): void
    {
        $this->assertFalse($this->registry->enqueueStyle('Bad ID', 'a.css'));
        $this->assertFalse($this->registry->enqueueStyle('foo.bar', 'a.css'));
        $this->assertFalse($this->registry->enqueueStyle('', 'a.css'));
        $this->assertEmpty($this->registry->listStyles());
    }

    public function testEnqueueAcceptsValidKebabCasePluginId(): void
    {
        $this->assertTrue($this->registry->enqueueStyle('hello-eiou', 'a.css'));
        $this->assertTrue($this->registry->enqueueStyle('plugin_with_underscores', 'a.css'));
        $this->assertCount(2, $this->registry->listStyles());
    }

    public function testEnqueueRejectsPathTraversal(): void
    {
        $this->assertFalse($this->registry->enqueueScript('hello-eiou', '../../../etc/passwd'));
        $this->assertFalse($this->registry->enqueueScript('hello-eiou', 'a/../../b.js'));
        $this->assertFalse($this->registry->enqueueScript('hello-eiou', '/abs/path.js'));
        $this->assertFalse($this->registry->enqueueScript('hello-eiou', 'a/./b.js')); // single-dot also rejected
        $this->assertEmpty($this->registry->listScripts());
    }

    public function testEnqueueRejectsBackslashTraversal(): void
    {
        $this->assertFalse($this->registry->enqueueStyle('hello-eiou', '..\\evil.css'));
    }

    // =========================================================================
    // Inline rendering
    // =========================================================================

    public function testRenderStylesEmitsInlineStyleWithNonce(): void
    {
        $this->writePluginAsset('hello-eiou', 'main.css', '.x { color: red; }');
        $this->registry->enqueueStyle('hello-eiou', 'main.css');

        $html = $this->registry->renderStyles('NONCE-XYZ');
        $this->assertStringContainsString('<style nonce="NONCE-XYZ">', $html);
        $this->assertStringContainsString('.x { color: red; }', $html);
        $this->assertStringContainsString('hello-eiou/main.css', $html); // source marker
        $this->assertStringContainsString('</style>', $html);
    }

    public function testRenderScriptsRespectsHeadVsBodySplit(): void
    {
        $this->writePluginAsset('p1', 'a.js', 'console.log("a")');
        $this->writePluginAsset('p1', 'b.js', 'console.log("b")');
        $this->registry->enqueueScript('p1', 'a.js', ['head' => true]);
        $this->registry->enqueueScript('p1', 'b.js'); // body (default)

        $head = $this->registry->renderScripts('N', true);
        $body = $this->registry->renderScripts('N', false);

        $this->assertStringContainsString('console.log("a")', $head);
        $this->assertStringNotContainsString('console.log("b")', $head);
        $this->assertStringContainsString('console.log("b")', $body);
        $this->assertStringNotContainsString('console.log("a")', $body);
    }

    public function testRenderHonoursPriority(): void
    {
        $this->writePluginAsset('p', 'a.css', '/*A*/');
        $this->writePluginAsset('p', 'b.css', '/*B*/');
        $this->writePluginAsset('p', 'c.css', '/*C*/');
        // Register in order C(20), A(5), B(10) — should render A,B,C.
        $this->registry->enqueueStyle('p', 'c.css', ['priority' => 20]);
        $this->registry->enqueueStyle('p', 'a.css', ['priority' => 5]);
        $this->registry->enqueueStyle('p', 'b.css', ['priority' => 10]);

        $html = $this->registry->renderStyles('');
        $posA = strpos($html, '/*A*/');
        $posB = strpos($html, '/*B*/');
        $posC = strpos($html, '/*C*/');
        $this->assertTrue($posA !== false && $posB !== false && $posC !== false);
        $this->assertLessThan($posB, $posA);
        $this->assertLessThan($posC, $posB);
    }

    public function testRenderPreservesRegistrationOrderAtSamePriority(): void
    {
        $this->writePluginAsset('p', 'a.js', '/*A*/');
        $this->writePluginAsset('p', 'b.js', '/*B*/');
        $this->writePluginAsset('p', 'c.js', '/*C*/');
        $this->registry->enqueueScript('p', 'a.js');
        $this->registry->enqueueScript('p', 'b.js');
        $this->registry->enqueueScript('p', 'c.js');

        $html = $this->registry->renderScripts('', false);
        $posA = strpos($html, '/*A*/');
        $posB = strpos($html, '/*B*/');
        $posC = strpos($html, '/*C*/');
        $this->assertLessThan($posB, $posA);
        $this->assertLessThan($posC, $posB);
    }

    public function testEmptyRegistryRendersEmptyString(): void
    {
        $this->assertSame('', $this->registry->renderStyles('N'));
        $this->assertSame('', $this->registry->renderScripts('N', true));
        $this->assertSame('', $this->registry->renderScripts('N', false));
    }

    public function testMissingFileSilentlySkipsAtRender(): void
    {
        // Asset enqueued but file never written — registry skips +
        // logs rather than crashing the host page.
        $this->registry->enqueueStyle('p', 'never-existed.css');
        $this->assertSame('', $this->registry->renderStyles(''));
    }

    public function testRenderEmitsEmptyStringWhenAssetIsBlank(): void
    {
        // A zero-byte asset doesn't crash; emits an empty <style> tag.
        $this->writePluginAsset('p', 'empty.css', '');
        $this->registry->enqueueStyle('p', 'empty.css');
        $html = $this->registry->renderStyles('');
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('p/empty.css', $html);
    }

    public function testNonceIsEscaped(): void
    {
        // CSP nonces are base64; this mostly defends against the
        // operator passing a malformed nonce containing quotes or
        // tags. htmlspecialchars at emit time keeps the output safe.
        $this->writePluginAsset('p', 'a.css', '/*x*/');
        $this->registry->enqueueStyle('p', 'a.css');
        $html = $this->registry->renderStyles('"><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testNoNonceAttributeWhenEmpty(): void
    {
        // Empty nonce → no nonce attribute (unit-test convenience).
        // The production caller always passes cspNonce() so this only
        // matters for tests / non-CSP environments.
        $this->writePluginAsset('p', 'a.css', '/*x*/');
        $this->registry->enqueueStyle('p', 'a.css');
        $html = $this->registry->renderStyles('');
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringNotContainsString('nonce=', $html);
    }

    // =========================================================================
    // URL-mode rendering — Phase 6
    //
    // Files larger than URL_MODE_THRESHOLD switch from inline-blob to
    // a `<link href="/gui/plugin-assets/...">` tag with a content-hash
    // cache-bust. Inline-mode for small files keeps a single round-
    // trip; URL-mode for large files lets the browser cache them.
    // =========================================================================

    public function testRendersLargeFileAsLinkTagWithContentHash(): void
    {
        $body = str_repeat('x', PluginAssetRegistry::URL_MODE_THRESHOLD + 100);
        $this->writePluginAsset('p', 'big.css', $body);
        $this->registry->enqueueStyle('p', 'big.css');

        $html = $this->registry->renderStyles('nonce-x');
        $expectedHash = substr(hash('sha256', $body), 0, 16);

        $this->assertStringContainsString('<link rel="stylesheet"', $html);
        $this->assertStringContainsString('href="/gui/plugin-assets/p/big.css?v=' . $expectedHash . '"', $html);
        $this->assertStringContainsString('nonce="nonce-x"', $html);
        $this->assertStringNotContainsString('<style', $html);
    }

    public function testRendersLargeJsFileAsScriptSrc(): void
    {
        $body = str_repeat('a', PluginAssetRegistry::URL_MODE_THRESHOLD + 1);
        $this->writePluginAsset('p', 'big.js', $body);
        $this->registry->enqueueScript('p', 'big.js');

        $html = $this->registry->renderScripts('n', false);
        $hash = substr(hash('sha256', $body), 0, 16);
        $this->assertStringContainsString('<script src="/gui/plugin-assets/p/big.js?v=' . $hash . '"', $html);
        $this->assertStringContainsString('></script>', $html);
    }

    public function testInlineOptForcesInlineEvenWhenLarge(): void
    {
        $body = str_repeat('x', PluginAssetRegistry::URL_MODE_THRESHOLD + 1);
        $this->writePluginAsset('p', 'big.css', $body);
        $this->registry->enqueueStyle('p', 'big.css', ['inline' => true]);

        $html = $this->registry->renderStyles('n');
        $this->assertStringContainsString('<style', $html);
        $this->assertStringNotContainsString('<link', $html);
    }

    public function testInlineFalseForcesUrlEvenWhenSmall(): void
    {
        $this->writePluginAsset('p', 'tiny.css', '/*x*/');
        $this->registry->enqueueStyle('p', 'tiny.css', ['inline' => false]);

        $html = $this->registry->renderStyles('n');
        $this->assertStringContainsString('<link rel="stylesheet"', $html);
        $this->assertStringNotContainsString('<style', $html);
    }
}
