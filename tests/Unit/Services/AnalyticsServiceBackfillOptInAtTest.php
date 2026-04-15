<?php
/**
 * Unit Tests for AnalyticsService::backfillOptInAtIfMissing
 *
 * Covers the one-shot backfill that stamps analyticsOptInAt on nodes
 * that opted in before the field existed. Contract:
 *   - analyticsEnabled=true AND analyticsOptInAt missing/empty → write now, return timestamp
 *   - Already populated → no-op, return null (preserve original opt-in moment)
 *   - analyticsEnabled=false → no-op, return null (never stamp consent without it)
 *   - File missing / unreadable / malformed JSON → no-op, return null
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\AnalyticsService;

#[CoversClass(AnalyticsService::class)]
class AnalyticsServiceBackfillOptInAtTest extends TestCase
{
    private const NOW = 1776254400; // 2026-04-15T12:00:00Z
    private const EXPECTED_TS = '2026-04-15T12:00:00+00:00';

    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'eiou-config-');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function writeConfig(array $config): void
    {
        file_put_contents($this->tmpFile, json_encode($config));
    }

    private function readConfig(): array
    {
        return json_decode(file_get_contents($this->tmpFile), true) ?? [];
    }

    public function testStampsWhenEnabledAndMissing(): void
    {
        $this->writeConfig(['analyticsEnabled' => true]);

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertSame(self::EXPECTED_TS, $result);
        $this->assertSame(self::EXPECTED_TS, $this->readConfig()['analyticsOptInAt']);
    }

    public function testStampsWhenOptInAtIsEmptyString(): void
    {
        // Empty string would silently break computePeriodDays (strtotime
        // returns false, floor goes to 0, period collapses to 1). Treat
        // it as missing and re-stamp.
        $this->writeConfig(['analyticsEnabled' => true, 'analyticsOptInAt' => '']);

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertSame(self::EXPECTED_TS, $result);
    }

    public function testPreservesExistingOptInAt(): void
    {
        // Critical invariant: never overwrite an existing consent
        // timestamp. The original opt-in moment is the consent of
        // record; a backfill must not erase it.
        $existing = '2026-04-06T00:00:00+00:00';
        $this->writeConfig(['analyticsEnabled' => true, 'analyticsOptInAt' => $existing]);

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertNull($result);
        $this->assertSame($existing, $this->readConfig()['analyticsOptInAt']);
    }

    public function testSkipsWhenAnalyticsDisabled(): void
    {
        // Consent boundary: never stamp an opt-in date on a node that
        // hasn't opted in. If analyticsEnabled is false or missing,
        // the backfill must not write anything.
        $this->writeConfig(['analyticsEnabled' => false]);

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertNull($result);
        $this->assertArrayNotHasKey('analyticsOptInAt', $this->readConfig());
    }

    public function testSkipsWhenAnalyticsEnabledIsMissing(): void
    {
        $this->writeConfig(['someOtherKey' => 'value']);

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertNull($result);
        $this->assertArrayNotHasKey('analyticsOptInAt', $this->readConfig());
    }

    public function testSkipsWhenFileDoesNotExist(): void
    {
        unlink($this->tmpFile);

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    public function testSkipsWhenFileIsMalformedJson(): void
    {
        file_put_contents($this->tmpFile, 'not valid json {{{');

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertNull($result);
    }

    public function testSkipsWhenFileContainsNonObjectJson(): void
    {
        // Valid JSON but not an object — shouldn't crash, shouldn't write.
        file_put_contents($this->tmpFile, '[1, 2, 3]');

        $result = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $this->assertNull($result);
    }

    public function testDoesNotCorruptOtherConfigKeys(): void
    {
        $this->writeConfig([
            'analyticsEnabled' => true,
            'unrelatedKey' => 'preserve me',
            'nested' => ['keep' => 'this'],
        ]);

        AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);

        $config = $this->readConfig();
        $this->assertSame('preserve me', $config['unrelatedKey']);
        $this->assertSame(['keep' => 'this'], $config['nested']);
        $this->assertSame(self::EXPECTED_TS, $config['analyticsOptInAt']);
    }

    public function testSubsequentCallIsNoOp(): void
    {
        // Called every cron run — must be idempotent. First call
        // stamps; every subsequent call short-circuits on the
        // already-populated check.
        $this->writeConfig(['analyticsEnabled' => true]);

        $first = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW);
        $second = AnalyticsService::backfillOptInAtIfMissing($this->tmpFile, self::NOW + 86400);

        $this->assertSame(self::EXPECTED_TS, $first);
        $this->assertNull($second);
        $this->assertSame(self::EXPECTED_TS, $this->readConfig()['analyticsOptInAt']);
    }
}
