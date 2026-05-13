<?php
namespace Eiou\Tests\Services\Plugins;

use Eiou\Services\Plugins\PluginGatewayTokenService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginGatewayTokenService::class)]
class PluginGatewayTokenServiceTest extends TestCase
{
    private string $tmpRoot;
    private string $pluginRoot;
    private string $tokensPath;
    private PluginGatewayTokenService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/eiou-tok-test-' . uniqid('', true);
        $this->pluginRoot = $this->tmpRoot . '/plugins';
        $this->tokensPath = $this->tmpRoot . '/tokens.json';
        @mkdir($this->pluginRoot, 0777, true);
        $this->svc = new PluginGatewayTokenService($this->tokensPath, $this->pluginRoot);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    #[Test]
    public function rotateGeneratesNewTokenAndPersistsBothHalves(): void
    {
        @mkdir($this->pluginRoot . '/demo', 0755, true);
        $token = $this->svc->rotate('demo');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertFileExists($this->pluginRoot . '/demo/.gateway-token');
        $this->assertSame($token, trim((string) file_get_contents($this->pluginRoot . '/demo/.gateway-token')));

        // Per-plugin file is restrictive (600) — only the plugin's
        // own pool user can read it. Permission check is platform-
        // dependent and PHP's stat doesn't surface it portably; we
        // verify the file exists and contains the token, which is the
        // observable behaviour tests care about.
        $this->assertFileExists($this->tokensPath);
        $index = json_decode(file_get_contents($this->tokensPath), true);
        $this->assertSame('demo', $index[$token]);
    }

    #[Test]
    public function pluginIdForTokenResolvesARegisteredToken(): void
    {
        @mkdir($this->pluginRoot . '/lookup', 0755, true);
        $token = $this->svc->rotate('lookup');

        $this->assertSame('lookup', $this->svc->pluginIdForToken($token));
    }

    #[Test]
    public function pluginIdForTokenReturnsNullForUnknownToken(): void
    {
        $bogus = str_repeat('a', 64);
        $this->assertNull($this->svc->pluginIdForToken($bogus));
    }

    #[Test]
    public function pluginIdForTokenRejectsMalformedToken(): void
    {
        // Shape mismatch — short-circuits without touching the file.
        $this->assertNull($this->svc->pluginIdForToken('not-a-token'));
        $this->assertNull($this->svc->pluginIdForToken(''));
    }

    #[Test]
    public function rotateInvalidatesAnyPreviousTokenForThePlugin(): void
    {
        @mkdir($this->pluginRoot . '/rotates', 0755, true);
        $first = $this->svc->rotate('rotates');
        $second = $this->svc->rotate('rotates');

        $this->assertNotSame($first, $second);
        $this->assertNull($this->svc->pluginIdForToken($first), 'old token no longer resolves');
        $this->assertSame('rotates', $this->svc->pluginIdForToken($second));
    }

    #[Test]
    public function revokeRemovesBothCentralAndPerPluginEntries(): void
    {
        @mkdir($this->pluginRoot . '/revoke-me', 0755, true);
        $token = $this->svc->rotate('revoke-me');

        $this->svc->revoke('revoke-me');

        $this->assertNull($this->svc->pluginIdForToken($token));
        $this->assertFileDoesNotExist($this->pluginRoot . '/revoke-me/.gateway-token');
    }

    #[Test]
    public function revokeIsIdempotentForUnknownPlugin(): void
    {
        // No-op, no exception.
        $this->svc->revoke('does-not-exist');
        $this->assertTrue(true);
    }

    #[Test]
    public function rotateRefusesInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->rotate('Has-Capitals');
    }

    #[Test]
    public function rotateRefusesPathTraversalInId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->rotate('../escape');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
