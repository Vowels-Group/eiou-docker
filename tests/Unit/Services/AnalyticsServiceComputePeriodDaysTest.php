<?php
/**
 * Unit Tests for AnalyticsService::computePeriodDays
 *
 * Covers the rollup-window calculation used by the heartbeat cron to
 * decide how many days of history to include in a single submission.
 *
 * The contract: periodDays = clamp(now - max(lastSubmitted, optInAt), 1, 365).
 * When both inputs are null/empty, return 1 (pre-change default).
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Services\AnalyticsService;

#[CoversClass(AnalyticsService::class)]
class AnalyticsServiceComputePeriodDaysTest extends TestCase
{
    /**
     * Fixed "now" — 2026-04-15T12:00:00Z. All test dates are derived
     * from this so the assertions stay stable.
     */
    private const NOW = 1776254400;

    public function testBothNullReturnsOne(): void
    {
        $this->assertSame(
            1,
            AnalyticsService::computePeriodDays(null, null, self::NOW)
        );
    }

    public function testBothEmptyStringReturnsOne(): void
    {
        $this->assertSame(
            1,
            AnalyticsService::computePeriodDays('', '', self::NOW)
        );
    }

    public function testRecentLastSubmittedReturnsOne(): void
    {
        // Submitted ~23 hours ago — floor(23h / 24h) = 0 → clamp to 1
        $lastSubmitted = gmdate('c', self::NOW - 23 * 3600);
        $this->assertSame(
            1,
            AnalyticsService::computePeriodDays($lastSubmitted, null, self::NOW)
        );
    }

    public function testExactlyOneDayAgoReturnsOne(): void
    {
        $lastSubmitted = gmdate('c', self::NOW - 86400);
        $this->assertSame(
            1,
            AnalyticsService::computePeriodDays($lastSubmitted, null, self::NOW)
        );
    }

    public function testRunuserScenarioUsesOptInFloor(): void
    {
        // The exact scenario this PR targets: user opted in on 2026-04-06,
        // runuser bug silently dropped every submission, bug fixed on 04-15.
        // last_submitted is null (nothing ever landed). Without opt-in
        // tracking the cron would fall back to 1 and lose the gap.
        $optInAt = '2026-04-06T00:00:00Z'; // 9 days 12 hours before NOW
        $this->assertSame(
            9,
            AnalyticsService::computePeriodDays(null, $optInAt, self::NOW)
        );
    }

    public function testLastSubmittedWinsWhenMoreRecentThanOptIn(): void
    {
        // Happy-path steady state — nodes opted in months ago and
        // submit daily. The floor must come from last_submitted, not
        // opt-in, or we'd be reporting months of duplicate data.
        $optInAt = '2025-01-01T00:00:00Z';
        $lastSubmitted = gmdate('c', self::NOW - 86400); // yesterday
        $this->assertSame(
            1,
            AnalyticsService::computePeriodDays($lastSubmitted, $optInAt, self::NOW)
        );
    }

    public function testOptInWinsWhenMoreRecentThanLastSubmitted(): void
    {
        // User opted out, then opted back in a week ago. The off→on
        // transition overwrites opt_in_at, so that becomes the floor —
        // last_submitted (stale from before the opt-out) is older.
        $lastSubmitted = '2025-06-01T00:00:00Z';
        $optInAt = gmdate('c', self::NOW - 7 * 86400);
        $this->assertSame(
            7,
            AnalyticsService::computePeriodDays($lastSubmitted, $optInAt, self::NOW)
        );
    }

    public function testCatchupGapClampsAtMax(): void
    {
        // Pathological: a node offline for 2 years. Must not exceed
        // MAX_PERIOD_DAYS (365 — keeps parity with worker.js clamp).
        $lastSubmitted = gmdate('c', self::NOW - 2 * 365 * 86400);
        $this->assertSame(
            AnalyticsService::MAX_PERIOD_DAYS,
            AnalyticsService::computePeriodDays($lastSubmitted, null, self::NOW)
        );
    }

    public function testFutureTimestampReturnsOne(): void
    {
        // Clock skew / bad data: floor is in the future. Guard against
        // negative periodDays and just report 1.
        $lastSubmitted = gmdate('c', self::NOW + 3600);
        $this->assertSame(
            1,
            AnalyticsService::computePeriodDays($lastSubmitted, null, self::NOW)
        );
    }

    public function testMalformedTimestampTreatedAsAbsent(): void
    {
        // strtotime returns false on garbage → cast to 0 → treated as
        // "no floor" so we return the default of 1.
        $this->assertSame(
            1,
            AnalyticsService::computePeriodDays('not-a-date', 'also-not-a-date', self::NOW)
        );
    }

    #[DataProvider('gapDaysProvider')]
    public function testGapMapsToExpectedPeriodDays(int $gapSeconds, int $expected): void
    {
        $lastSubmitted = gmdate('c', self::NOW - $gapSeconds);
        $this->assertSame(
            $expected,
            AnalyticsService::computePeriodDays($lastSubmitted, null, self::NOW)
        );
    }

    public static function gapDaysProvider(): array
    {
        return [
            '2 days exactly'         => [2 * 86400, 2],
            '2 days 23 hours (floor)' => [2 * 86400 + 23 * 3600, 2],
            '3 days exactly'         => [3 * 86400, 3],
            '9 days exactly'         => [9 * 86400, 9],
            '30 days'                => [30 * 86400, 30],
            '365 days exactly'       => [365 * 86400, 365],
            '366 days (clamped)'     => [366 * 86400, 365],
        ];
    }

    public function testUsesNowDefaultWhenNotProvided(): void
    {
        // Sanity check that the default $now = time() branch works —
        // we can't pin the clock here, but we can assert the return is
        // in the valid range.
        $result = AnalyticsService::computePeriodDays(
            gmdate('c', time() - 5 * 86400),
            null
        );
        $this->assertGreaterThanOrEqual(1, $result);
        $this->assertLessThanOrEqual(AnalyticsService::MAX_PERIOD_DAYS, $result);
    }
}
