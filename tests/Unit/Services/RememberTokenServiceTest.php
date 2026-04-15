<?php
/**
 * Unit tests for RememberTokenService — the GUI "Remember me" login feature.
 *
 * Covers token minting with LRU eviction when the device cap is hit,
 * rotation on consume (stolen-cookie catch), revocation of one or all,
 * listing with current-device flagging, and the privacy-safe User-Agent
 * summariser.
 *
 * Network/DB layers are mocked at the repository boundary.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\RememberTokenService;
use Eiou\Database\RememberTokenRepository;

#[CoversClass(RememberTokenService::class)]
class RememberTokenServiceTest extends TestCase
{
    private $repo;
    private RememberTokenService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(RememberTokenRepository::class);
        $this->service = new RememberTokenService($this->repo);
    }

    // =========================================================================
    // issueToken()
    // =========================================================================

    public function testIssueTokenMintsAndStoresHashedCopy(): void
    {
        $this->repo->method('countActiveForUser')->willReturn(0);
        $captured = null;
        $this->repo->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(function ($hash) use (&$captured) {
                    $captured = $hash;
                    return is_string($hash) && strlen($hash) === 64; // sha256 hex
                }),
                'user-hash',
                'Firefox 128 · Linux',
                $this->equalTo(30 * 86400)
            )
            ->willReturn(true);

        $raw = $this->service->issueToken(
            'user-hash',
            'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
            30,
            3
        );

        $this->assertIsString($raw);
        $this->assertSame(64, strlen($raw), 'raw token should be 32 bytes = 64 hex chars');
        $this->assertSame(hash('sha256', $raw), $captured, 'DB receives hash of raw, not raw');
    }

    public function testIssueTokenEvictsOldestWhenDeviceCapReached(): void
    {
        // Cap=3, already 3 active → evict one before creating the 4th.
        $this->repo->method('countActiveForUser')
            ->willReturnOnConsecutiveCalls(3, 2); // after eviction, count drops
        $this->repo->expects($this->once())->method('revokeOldestForUser')->willReturn(true);
        $this->repo->expects($this->once())->method('create')->willReturn(true);

        $raw = $this->service->issueToken('u', null, 30, 3);
        $this->assertNotNull($raw);
    }

    public function testIssueTokenReturnsNullWhenRepositoryCreateFails(): void
    {
        $this->repo->method('countActiveForUser')->willReturn(0);
        $this->repo->method('create')->willReturn(false);

        $this->assertNull($this->service->issueToken('u', null, 30, 3));
    }

    public function testIssueTokenRefusesZeroDevicesOrZeroDays(): void
    {
        $this->repo->expects($this->never())->method('create');
        $this->assertNull($this->service->issueToken('u', null, 0, 3));
        $this->assertNull($this->service->issueToken('u', null, 30, 0));
    }

    // =========================================================================
    // rotateToken() — single-use rotation on consume
    // =========================================================================

    public function testRotateTokenReplacesValidTokenAndRevokesOld(): void
    {
        $raw = bin2hex(random_bytes(32));
        $oldHash = hash('sha256', $raw);

        // 5 days left on the old row
        $this->repo->method('findActiveByTokenHash')
            ->with($oldHash)
            ->willReturn([
                'pubkey_hash' => 'user-hash',
                'expires_at' => date('Y-m-d H:i:s', time() + 5 * 86400),
            ]);

        $this->repo->expects($this->once())->method('create')->willReturn(true);
        $this->repo->expects($this->once())->method('revokeByTokenHash')->with($oldHash);

        $result = $this->service->rotateToken($raw, 'Mozilla/5.0 Firefox/128');

        $this->assertIsArray($result);
        $this->assertSame('user-hash', $result['pubkey_hash']);
        $this->assertNotSame($raw, $result['new_token']);
        // New row's lifetime should be roughly the old remaining time (5 days)
        $this->assertGreaterThan(time() + 4 * 86400, $result['expires_at_unix']);
        $this->assertLessThanOrEqual(time() + 5 * 86400 + 2, $result['expires_at_unix']);
    }

    public function testRotateTokenReturnsNullWhenTokenUnknown(): void
    {
        $this->repo->method('findActiveByTokenHash')->willReturn(null);
        $this->repo->expects($this->never())->method('create');
        $this->repo->expects($this->never())->method('revokeByTokenHash');

        $this->assertNull($this->service->rotateToken('bogus', null));
    }

    // =========================================================================
    // Revocation + listing
    // =========================================================================

    public function testRevokeTokenHashesRawBeforeRepoCall(): void
    {
        $raw = 'raw-cookie-value';
        $this->repo->expects($this->once())
            ->method('revokeByTokenHash')
            ->with(hash('sha256', $raw))
            ->willReturn(true);

        $this->assertTrue($this->service->revokeToken($raw));
    }

    public function testListForUserTagsCurrentDeviceAndStripsHash(): void
    {
        $currentRaw = 'current-raw';
        $currentHash = hash('sha256', $currentRaw);

        $this->repo->method('listActiveForUser')
            ->willReturn([
                ['id' => 1, 'token_hash' => 'other-hash', 'user_agent_family' => 'Firefox 128 · Linux'],
                ['id' => 2, 'token_hash' => $currentHash, 'user_agent_family' => 'Chrome 120 · macOS'],
            ]);

        $rows = $this->service->listForUser('u', $currentRaw);

        $this->assertCount(2, $rows);
        $this->assertFalse($rows[0]['is_current']);
        $this->assertTrue($rows[1]['is_current']);
        // token_hash must NEVER leak from the service layer
        $this->assertArrayNotHasKey('token_hash', $rows[0]);
        $this->assertArrayNotHasKey('token_hash', $rows[1]);
    }

    public function testListForUserHandlesNoCurrentToken(): void
    {
        $this->repo->method('listActiveForUser')->willReturn([
            ['id' => 1, 'token_hash' => 'x', 'user_agent_family' => 'Firefox 128 · Linux'],
        ]);
        $rows = $this->service->listForUser('u', null);
        $this->assertFalse($rows[0]['is_current']);
    }

    // =========================================================================
    // summariseUserAgent() — privacy-safe UA summary
    // =========================================================================

    /**
     * @dataProvider userAgentProvider
     */
    public function testSummariseUserAgentRecognisesCommonBrowsers(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->service->summariseUserAgent($raw));
    }

    public static function userAgentProvider(): array
    {
        return [
            'firefox-linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
                'Firefox 128 · Linux',
            ],
            'chrome-windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Chrome 120 · Windows',
            ],
            'edge-windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
                'Edge 120 · Windows',
            ],
            'safari-macos' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
                'Safari 17 · macOS',
            ],
            'iphone-safari' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                'Safari 17 · iOS',
            ],
            'null-safe' => [
                '',
                'Unknown device',
            ],
            'opaque-ua' => [
                'SomeUnknownThing/1.0',
                'Unknown OS · Unknown browser',
            ],
        ];
    }
}
