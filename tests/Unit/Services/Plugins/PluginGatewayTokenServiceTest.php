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

    #[Test]
    public function mintProducesAShapeValidTokenWithoutTouchingState(): void
    {
        // mint() is pure — no file I/O, no index update. Used by the
        // supervisor-mediated apply-pool path so the index is only
        // committed AFTER the supervisor confirms the file write.
        $token = $this->svc->mint();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertFileDoesNotExist($this->tokensPath);
        $this->assertNull($this->svc->pluginIdForToken($token));
    }

    #[Test]
    public function commitTokenIndexesAFreshTokenAndReplacesPriorEntry(): void
    {
        @mkdir($this->pluginRoot . '/demo', 0755, true);

        $first = $this->svc->mint();
        $this->svc->commitToken('demo', $first);
        $this->assertSame('demo', $this->svc->pluginIdForToken($first));

        // Second commit replaces — old token unresolvable.
        $second = $this->svc->mint();
        $this->svc->commitToken('demo', $second);
        $this->assertNull($this->svc->pluginIdForToken($first), 'old token replaced');
        $this->assertSame('demo', $this->svc->pluginIdForToken($second));

        // Exactly one entry for this plugin — no stale duplicates.
        $index = json_decode((string) file_get_contents($this->tokensPath), true);
        $entriesForDemo = array_filter($index, fn($v) => $v === 'demo');
        $this->assertCount(1, $entriesForDemo);
    }

    #[Test]
    public function commitTokenRejectsMalformedToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->commitToken('demo', 'not-a-token');
    }

    #[Test]
    public function commitTokenRejectsInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->commitToken('Bad-Caps', str_repeat('a', 64));
    }

    #[Test]
    public function reconcileFromFileTreatsThePerPluginFileAsAuthoritative(): void
    {
        // Simulate the drift the boot-time reconcile exists to repair:
        // the index has token A for 'drift', but the on-disk file has
        // token B (because a pre-fix apply-pool committed A to the
        // index, then the supervisor failed to write A to the file —
        // the file still has the previous-cycle's B).
        @mkdir($this->pluginRoot . '/drift', 0755, true);
        $indexed = str_repeat('a', 64);
        $onDisk  = str_repeat('b', 64);
        $this->svc->commitToken('drift', $indexed);
        file_put_contents($this->pluginRoot . '/drift/.gateway-token', $onDisk);

        $reconciled = $this->svc->reconcileFromFile('drift');

        $this->assertSame($onDisk, $reconciled);
        $this->assertSame('drift', $this->svc->pluginIdForToken($onDisk));
        $this->assertNull($this->svc->pluginIdForToken($indexed), 'pre-drift index entry gone');
    }

    #[Test]
    public function reconcileFromFileReturnsNullWhenFileAbsent(): void
    {
        // No per-plugin file → nothing to reconcile against → return
        // null and leave the index untouched. The boot-time reconcile
        // in startup.sh iterates files found on disk, so this case
        // only matters when reconcile is called for a plugin id with
        // no token file — defensive no-op.
        @mkdir($this->pluginRoot . '/noisey', 0755, true);
        $this->assertNull($this->svc->reconcileFromFile('noisey'));
    }

    #[Test]
    public function reconcileFromFileIsAStableNoopWhenAlreadyAligned(): void
    {
        @mkdir($this->pluginRoot . '/aligned', 0755, true);
        $token = $this->svc->rotate('aligned');

        $reconciled = $this->svc->reconcileFromFile('aligned');
        $this->assertSame($token, $reconciled);
        // Index still maps token → plugin exactly once.
        $index = json_decode((string) file_get_contents($this->tokensPath), true);
        $entries = array_filter($index, fn($v) => $v === 'aligned');
        $this->assertCount(1, $entries);
    }

    #[Test]
    public function reconcileFromFileSkipsMalformedFileContent(): void
    {
        @mkdir($this->pluginRoot . '/garbage', 0755, true);
        file_put_contents($this->pluginRoot . '/garbage/.gateway-token', 'not-hex-data');

        $this->assertNull($this->svc->reconcileFromFile('garbage'));
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
