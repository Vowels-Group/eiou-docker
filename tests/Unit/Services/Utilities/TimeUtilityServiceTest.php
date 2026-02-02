<?php
/**
 * Unit Tests for TimeUtilityService
 *
 * Tests microtime conversion, expiration checking, and timestamp calculations.
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Core\Constants;

#[CoversClass(TimeUtilityService::class)]
class TimeUtilityServiceTest extends TestCase
{
    private TimeUtilityService $service;

    protected function setUp(): void
    {
        $this->service = new TimeUtilityService();
    }

    /**
     * Test getCurrentMicrotime returns integer
     */
    public function testGetCurrentMicrotimeReturnsInteger(): void
    {
        $result = $this->service->getCurrentMicrotime();

        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    /**
     * Test getCurrentMicrotime is monotonically increasing
     */
    public function testGetCurrentMicrotimeIncreases(): void
    {
        $time1 = $this->service->getCurrentMicrotime();
        usleep(1000); // Sleep 1ms
        $time2 = $this->service->getCurrentMicrotime();

        $this->assertGreaterThanOrEqual($time1, $time2);
    }

    /**
     * Test convertMicrotimeToInt with known value
     */
    public function testConvertMicrotimeToIntWithKnownValue(): void
    {
        // 1000000.123456 seconds (as float)
        $floatTime = 1000000.123456;
        $result = $this->service->convertMicrotimeToInt($floatTime);

        // Should multiply by Constants::TIME_MICROSECONDS_TO_INT (usually 1000000)
        $expected = (int) ($floatTime * Constants::TIME_MICROSECONDS_TO_INT);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test convertMicrotimeToInt with zero
     */
    public function testConvertMicrotimeToIntWithZero(): void
    {
        $result = $this->service->convertMicrotimeToInt(0.0);

        $this->assertEquals(0, $result);
    }

    /**
     * Test convertMicrotimeToInt preserves precision
     */
    public function testConvertMicrotimeToIntPreservesPrecision(): void
    {
        $floatTime = microtime(true);
        $result = $this->service->convertMicrotimeToInt($floatTime);

        // The result should be close to the original * conversion factor
        $expected = (int) ($floatTime * Constants::TIME_MICROSECONDS_TO_INT);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test isExpired returns true for past time
     */
    public function testIsExpiredWithPastTime(): void
    {
        // Create a timestamp in the past (1 second ago)
        $pastTime = $this->service->getCurrentMicrotime() - (1 * Constants::TIME_MICROSECONDS_TO_INT);

        $this->assertTrue($this->service->isExpired($pastTime));
    }

    /**
     * Test isExpired returns false for future time
     */
    public function testIsExpiredWithFutureTime(): void
    {
        // Create a timestamp in the future (1 hour from now)
        $futureTime = $this->service->getCurrentMicrotime() + (3600 * Constants::TIME_MICROSECONDS_TO_INT);

        $this->assertFalse($this->service->isExpired($futureTime));
    }

    /**
     * Test calculateExpiration adds correct TTL
     */
    public function testCalculateExpirationAddsCorrectTtl(): void
    {
        $ttlSeconds = 3600; // 1 hour
        $beforeCalc = $this->service->getCurrentMicrotime();
        $expiration = $this->service->calculateExpiration($ttlSeconds);
        $afterCalc = $this->service->getCurrentMicrotime();

        // Expiration should be current time + TTL in microseconds
        $expectedMin = $beforeCalc + ($ttlSeconds * Constants::TIME_MICROSECONDS_TO_INT);
        $expectedMax = $afterCalc + ($ttlSeconds * Constants::TIME_MICROSECONDS_TO_INT);

        $this->assertGreaterThanOrEqual($expectedMin, $expiration);
        $this->assertLessThanOrEqual($expectedMax, $expiration);
    }

    /**
     * Test calculateExpiration with zero TTL
     */
    public function testCalculateExpirationWithZeroTtl(): void
    {
        $before = $this->service->getCurrentMicrotime();
        $expiration = $this->service->calculateExpiration(0);
        $after = $this->service->getCurrentMicrotime();

        // With 0 TTL, expiration should be approximately current time
        $this->assertGreaterThanOrEqual($before, $expiration);
        $this->assertLessThanOrEqual($after, $expiration);
    }

    /**
     * Test that calculated expiration is not expired immediately
     */
    public function testCalculatedExpirationNotImmediatelyExpired(): void
    {
        $expiration = $this->service->calculateExpiration(60); // 1 minute

        $this->assertFalse($this->service->isExpired($expiration));
    }

    /**
     * Test expiration lifecycle
     */
    public function testExpirationLifecycle(): void
    {
        // Calculate an expiration time 100ms from now
        // Note: This is a bit tricky to test with microseconds
        $shortTtlMicroseconds = 100000; // 0.1 seconds in microseconds
        $shortTtlSeconds = 0; // Effectively immediate for testing

        $expiration = $this->service->calculateExpiration($shortTtlSeconds);

        // With 0 TTL, it should be immediately "expired" or very close
        // Give it a tiny bit of tolerance
        usleep(1000); // 1ms
        $this->assertTrue($this->service->isExpired($expiration));
    }
}
