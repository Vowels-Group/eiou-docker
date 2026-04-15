<?php
/**
 * Unit Tests for AnalyticsService::applyOptInAtTransition
 *
 * Shared helper called by all four code paths that toggle
 * analyticsEnabled (SettingsController GUI form, SettingsController
 * consent modal, CliSettingsService CLI, ApiController API). Covers
 * the four transition states plus defensive cases.
 *
 * Contract:
 *   - off → on: stamps now, overwriting any existing analyticsOptInAt
 *   - off → off, on → on, on → off: config unchanged
 *   - All other keys in the config are preserved unchanged
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\AnalyticsService;

#[CoversClass(AnalyticsService::class)]
class AnalyticsServiceApplyOptInAtTransitionTest extends TestCase
{
    private const NOW = 1776254400; // 2026-04-15T12:00:00Z
    private const EXPECTED_TS = '2026-04-15T12:00:00+00:00';

    public function testOffToOnStampsTimestamp(): void
    {
        $config = ['other' => 'value'];
        $result = AnalyticsService::applyOptInAtTransition(
            $config, false, true, self::NOW
        );
        $this->assertSame(self::EXPECTED_TS, $result['analyticsOptInAt']);
    }

    public function testOffToOffIsUnchanged(): void
    {
        $config = ['other' => 'value'];
        $result = AnalyticsService::applyOptInAtTransition(
            $config, false, false, self::NOW
        );
        $this->assertSame($config, $result);
        $this->assertArrayNotHasKey('analyticsOptInAt', $result);
    }

    public function testOnToOnIsUnchanged(): void
    {
        // Happy path: user already had analytics on and just saved
        // other settings. Must not re-stamp opt-in date or the rollup
        // floor would silently reset every save.
        $existing = '2026-01-01T00:00:00+00:00';
        $config = ['analyticsOptInAt' => $existing, 'other' => 'value'];
        $result = AnalyticsService::applyOptInAtTransition(
            $config, true, true, self::NOW
        );
        $this->assertSame($existing, $result['analyticsOptInAt']);
    }

    public function testOnToOffIsUnchanged(): void
    {
        // Opting out leaves analyticsOptInAt in place. The cron
        // won't read it (analyticsEnabled=false short-circuits the
        // script) and if the user re-opts-in later, that path will
        // stamp a fresh timestamp. So preserving is benign and
        // cheaper than clearing.
        $existing = '2026-01-01T00:00:00+00:00';
        $config = ['analyticsOptInAt' => $existing];
        $result = AnalyticsService::applyOptInAtTransition(
            $config, true, false, self::NOW
        );
        $this->assertSame($existing, $result['analyticsOptInAt']);
    }

    public function testOffToOnOverwritesStaleTimestamp(): void
    {
        // Re-enabling after opting out is a fresh consent event —
        // the old timestamp from a prior consent session must not
        // be used as the rollup floor, or catch-up logic would pull
        // transactions from the opt-out window.
        $config = ['analyticsOptInAt' => '2025-01-01T00:00:00+00:00'];
        $result = AnalyticsService::applyOptInAtTransition(
            $config, false, true, self::NOW
        );
        $this->assertSame(self::EXPECTED_TS, $result['analyticsOptInAt']);
    }

    public function testPreservesUnrelatedKeys(): void
    {
        $config = [
            'analyticsEnabled' => true,
            'analyticsConsentAsked' => true,
            'hostname' => 'example.local',
            'nested' => ['deep' => 'value'],
        ];
        $result = AnalyticsService::applyOptInAtTransition(
            $config, false, true, self::NOW
        );
        $this->assertTrue($result['analyticsEnabled']);
        $this->assertTrue($result['analyticsConsentAsked']);
        $this->assertSame('example.local', $result['hostname']);
        $this->assertSame(['deep' => 'value'], $result['nested']);
        $this->assertSame(self::EXPECTED_TS, $result['analyticsOptInAt']);
    }

    public function testReturnedArrayIsIndependent(): void
    {
        // Defensive: the helper must not mutate the caller's array
        // under their feet (PHP passes arrays by value but copy-on-
        // write is subtle — verify explicitly).
        $config = ['original' => 'state'];
        AnalyticsService::applyOptInAtTransition($config, false, true, self::NOW);
        $this->assertArrayNotHasKey('analyticsOptInAt', $config);
    }

    public function testEmptyConfigOffToOn(): void
    {
        $result = AnalyticsService::applyOptInAtTransition(
            [], false, true, self::NOW
        );
        $this->assertSame(self::EXPECTED_TS, $result['analyticsOptInAt']);
        $this->assertCount(1, $result);
    }

    public function testUsesCurrentTimeWhenNowNotProvided(): void
    {
        $before = time();
        $result = AnalyticsService::applyOptInAtTransition([], false, true);
        $after = time();

        $stamped = strtotime($result['analyticsOptInAt']);
        $this->assertGreaterThanOrEqual($before, $stamped);
        $this->assertLessThanOrEqual($after, $stamped);
    }
}
