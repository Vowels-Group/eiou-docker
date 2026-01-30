<?php
/**
 * Unit Tests for RateLimiterService
 *
 * Tests rate limiting logic and client IP detection.
 * Note: Full rate limiting tests require mocked repository.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\RateLimiterService;

#[CoversClass(RateLimiterService::class)]
class RateLimiterServiceTest extends TestCase
{
    /**
     * Test getClientIp returns string
     */
    public function testGetClientIpReturnsString(): void
    {
        $ip = RateLimiterService::getClientIp();

        $this->assertIsString($ip);
    }

    /**
     * Test getClientIp uses REMOTE_ADDR
     */
    public function testGetClientIpUsesRemoteAddr(): void
    {
        $backup = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $ip = RateLimiterService::getClientIp();

        $_SERVER = $backup;

        $this->assertEquals('192.168.1.100', $ip);
    }

    /**
     * Test getClientIp prefers Cloudflare IP
     */
    public function testGetClientIpPrefersCloudflareIp(): void
    {
        $backup = $_SERVER;
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $ip = RateLimiterService::getClientIp();

        $_SERVER = $backup;

        $this->assertEquals('1.2.3.4', $ip);
    }

    /**
     * Test getClientIp handles X-Forwarded-For with multiple IPs
     */
    public function testGetClientIpHandlesMultipleForwardedIps(): void
    {
        $backup = $_SERVER;
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 10.0.0.2, 10.0.0.3';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        unset($_SERVER['REMOTE_ADDR']);

        $ip = RateLimiterService::getClientIp();

        $_SERVER = $backup;

        // Should return first IP in the list
        $this->assertEquals('10.0.0.1', $ip);
    }

    /**
     * Test getClientIp returns default when no server vars
     */
    public function testGetClientIpReturnsDefaultWhenEmpty(): void
    {
        $backup = $_SERVER;
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['REMOTE_ADDR']);

        $ip = RateLimiterService::getClientIp();

        $_SERVER = $backup;

        $this->assertEquals('0.0.0.0', $ip);
    }

    /**
     * Test getClientIp trims whitespace
     */
    public function testGetClientIpTrimsWhitespace(): void
    {
        $backup = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '  192.168.1.1  ';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $ip = RateLimiterService::getClientIp();

        $_SERVER = $backup;

        $this->assertEquals('192.168.1.1', $ip);
    }

    /**
     * Test checkLimit with mocked repository returns allowed
     */
    public function testCheckLimitWithTestModeReturnsAllowed(): void
    {
        // Set test mode environment variable
        $oldEnv = getenv('EIOU_TEST_MODE');
        putenv('EIOU_TEST_MODE=true');

        $mockRepo = $this->createMock(\Eiou\Database\RateLimiterRepository::class);
        $service = new RateLimiterService($mockRepo);

        $result = $service->checkLimit('test-user', 'login', 5, 60, 300);

        putenv('EIOU_TEST_MODE=' . ($oldEnv ?: ''));

        $this->assertTrue($result['allowed']);
        $this->assertEquals(5, $result['remaining']); // Max attempts
    }

    /**
     * Test reset calls repository reset
     */
    public function testResetCallsRepositoryReset(): void
    {
        $mockRepo = $this->createMock(\Eiou\Database\RateLimiterRepository::class);
        $mockRepo->expects($this->once())
            ->method('reset')
            ->with('test-user', 'login');

        $service = new RateLimiterService($mockRepo);
        $service->reset('test-user', 'login');
    }
}
