<?php
/**
 * Unit Tests for RestartRequestService
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\RestartRequestService;

#[CoversClass(RestartRequestService::class)]
class RestartRequestServiceTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/eiou-restart-request-test-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testRequestWritesValidJsonWithExpectedFields(): void
    {
        $service = new RestartRequestService($this->tmpFile);
        $before = time();
        $this->assertTrue($service->request('gui', 'pubkey-hash-abc'));
        $after = time();

        $this->assertFileExists($this->tmpFile);
        $payload = json_decode(file_get_contents($this->tmpFile), true);

        $this->assertIsArray($payload);
        $this->assertSame('gui', $payload['source']);
        $this->assertSame('pubkey-hash-abc', $payload['requestor']);
        $this->assertGreaterThanOrEqual($before, $payload['ts']);
        $this->assertLessThanOrEqual($after, $payload['ts']);
    }

    public function testRequestRejectsUnknownSource(): void
    {
        $service = new RestartRequestService($this->tmpFile);
        // Only gui/api/cli are valid. Anything else is silently rejected
        // so a typo in caller code can't masquerade as a legitimate trigger.
        $this->assertFalse($service->request('hax', 'whatever'));
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    public function testRequestAcceptsAllValidSources(): void
    {
        foreach (['gui', 'api', 'cli'] as $source) {
            $path = sys_get_temp_dir() . '/eiou-restart-' . $source . '-' . uniqid() . '.json';
            $service = new RestartRequestService($path);
            $this->assertTrue($service->request($source));
            $this->assertFileExists($path);
            $payload = json_decode(file_get_contents($path), true);
            $this->assertSame($source, $payload['source']);
            unlink($path);
        }
    }

    public function testIsRequestedTracksFilePresence(): void
    {
        $service = new RestartRequestService($this->tmpFile);
        $this->assertFalse($service->isRequested());
        $service->request('cli', '');
        $this->assertTrue($service->isRequested());
        unlink($this->tmpFile);
        $this->assertFalse($service->isRequested());
    }

    public function testRequestReturnsFalseWhenWriterFails(): void
    {
        $service = new RestartRequestService(
            $this->tmpFile,
            fn(string $p, string $c): bool => false
        );
        $this->assertFalse($service->request('gui'));
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    public function testRequestorIsOptional(): void
    {
        $service = new RestartRequestService($this->tmpFile);
        $this->assertTrue($service->request('cli'));
        $payload = json_decode(file_get_contents($this->tmpFile), true);
        $this->assertSame('', $payload['requestor']);
    }
}
