<?php
/**
 * Unit Tests for UpdateCheckService
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\UpdateCheckService;
use Eiou\Core\Constants;

#[CoversClass(UpdateCheckService::class)]
class UpdateCheckServiceTest extends TestCase
{
    /**
     * Test isNewerVersion with newer version
     */
    public function testIsNewerVersionReturnsTrueForNewerVersion(): void
    {
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.2.0', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.6-alpha', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('1.0.0', '0.1.5-alpha'));
    }

    /**
     * Test isNewerVersion with same version
     */
    public function testIsNewerVersionReturnsFalseForSameVersion(): void
    {
        $this->assertFalse(UpdateCheckService::isNewerVersion('0.1.5-alpha', '0.1.5-alpha'));
    }

    /**
     * Test isNewerVersion with older version
     */
    public function testIsNewerVersionReturnsFalseForOlderVersion(): void
    {
        $this->assertFalse(UpdateCheckService::isNewerVersion('0.1.4-alpha', '0.1.5-alpha'));
        $this->assertFalse(UpdateCheckService::isNewerVersion('0.1.0', '0.1.5-alpha'));
    }

    /**
     * Test isNewerVersion strips v prefix
     */
    public function testIsNewerVersionStripsVPrefix(): void
    {
        $this->assertTrue(UpdateCheckService::isNewerVersion('v0.2.0', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('v0.2.0', 'v0.1.5-alpha'));
        $this->assertFalse(UpdateCheckService::isNewerVersion('v0.1.5-alpha', 'v0.1.5-alpha'));
    }

    /**
     * Test prerelease ordering (alpha < beta < stable)
     */
    public function testPrereleaseOrdering(): void
    {
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.5-beta', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.5', '0.1.5-alpha'));
        $this->assertTrue(UpdateCheckService::isNewerVersion('0.1.5', '0.1.5-beta'));
    }

    /**
     * Test getStatus returns expected keys
     */
    public function testGetStatusReturnsExpectedKeys(): void
    {
        $status = UpdateCheckService::getStatus();

        $this->assertArrayHasKey('available', $status);
        $this->assertArrayHasKey('current_version', $status);
        $this->assertArrayHasKey('latest_version', $status);
        $this->assertArrayHasKey('last_checked', $status);
        $this->assertArrayHasKey('source', $status);
        $this->assertArrayHasKey('error', $status);
    }

    /**
     * Test getStatus returns current version
     */
    public function testGetStatusReturnsCurrentVersion(): void
    {
        $status = UpdateCheckService::getStatus();
        $this->assertEquals(Constants::APP_VERSION, $status['current_version']);
    }

    /**
     * Test getStatus returns boolean for available
     */
    public function testGetStatusAvailableIsBoolean(): void
    {
        $status = UpdateCheckService::getStatus();
        $this->assertIsBool($status['available']);
    }

    /**
     * Test getCached returns null when no cache file exists
     */
    public function testGetCachedReturnsNullWithoutCacheFile(): void
    {
        if (file_exists('/etc/eiou/config/update-check.json')) {
            $this->markTestSkipped('Cache file exists (running in Docker)');
        }

        $this->assertNull(UpdateCheckService::getCached());
    }
}
