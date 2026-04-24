<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\SettlementPrecisionService;

#[CoversClass(SettlementPrecisionService::class)]
class SettlementPrecisionServiceTest extends TestCase
{
    private SettlementPrecisionService $p;

    protected function setUp(): void
    {
        $this->p = new SettlementPrecisionService();
    }

    // =========================================================================
    // defaultFor — bank_wire + custom + unknown-type fallback
    // Plugin-registered rail types will extend the DEFAULTS table; until
    // then the generic fallback in `genericFor()` covers any (type,
    // currency) pair the core ships.
    // =========================================================================

    public function testBankWireFiatDefaultsToCent(): void
    {
        $this->assertEquals([1, -2], $this->p->defaultFor('bank_wire', 'USD'));
        $this->assertEquals([1, -2], $this->p->defaultFor('bank_wire', 'EUR'));
        $this->assertEquals([1, -2], $this->p->defaultFor('bank_wire', 'GBP'));
        $this->assertEquals([1, -2], $this->p->defaultFor('bank_wire', 'MXN'));
    }

    public function testZeroDecimalFiatReturnsExponentZero(): void
    {
        // ISO-listed zero-decimal currencies — JPY, KRW, etc. — settle at
        // integer major unit, not cent.
        $this->assertEquals([1, 0], $this->p->defaultFor('bank_wire', 'JPY'));
        $this->assertEquals([1, 0], $this->p->defaultFor('bank_wire', 'KRW'));
    }

    public function testCustomFiatDefaultsToCent(): void
    {
        // Custom passes through the generic fallback — fiat codes still
        // get cent precision even without an entry in DEFAULTS.
        $this->assertEquals([1, -2], $this->p->defaultFor('custom', 'USD'));
        $this->assertEquals([1, -2], $this->p->defaultFor('custom', 'BRL'));
    }

    public function testCustomNonFiatCodeDefaultsToNodeFloor(): void
    {
        // User-declared currency codes that aren't ISO-4217 fiat fall back
        // to 10⁻⁸ — matches the node's internal SplitAmount precision.
        $this->assertEquals([1, -8], $this->p->defaultFor('custom', 'XRP'));
    }

    public function testUnknownTypeFallsThroughToGeneric(): void
    {
        // A plugin-registered type that hasn't yet declared its precision
        // (or a typo'd type) gets the generic fiat/crypto fallback rather
        // than crashing.
        $this->assertEquals([1, -8], $this->p->defaultFor('some_future_rail', 'BTC'));
        $this->assertEquals([1, -2], $this->p->defaultFor('some_future_rail', 'USD'));
    }

    // =========================================================================
    // roundUpToMinUnit
    // These tests require php-bcmath, available inside the Docker image.
    // =========================================================================

    private function requireBcmath(): void
    {
        if (!function_exists('bcadd')) {
            $this->markTestSkipped('bcmath extension required');
        }
    }

    public function testRoundUpAlreadyExactNoop(): void
    {
        $this->requireBcmath();
        $this->assertEquals('0.12345678', $this->p->roundUpToMinUnit('0.12345678', -8));
    }

    public function testRoundUpPastSatoshiAddsOne(): void
    {
        $this->requireBcmath();
        // 0.123456789 (9 decimals) should round up to 0.12345679 at -8.
        $this->assertEquals('0.12345679', $this->p->roundUpToMinUnit('0.123456789', -8));
    }

    public function testRoundUpCent(): void
    {
        $this->requireBcmath();
        $this->assertEquals('10.51', $this->p->roundUpToMinUnit('10.505', -2));
    }

    public function testRoundUpFromSubCent(): void
    {
        $this->requireBcmath();
        // $0.001 should round up to $0.01.
        $this->assertEquals('0.01', $this->p->roundUpToMinUnit('0.001', -2));
    }

    public function testRoundUpLargeValuePreservesInteger(): void
    {
        $this->requireBcmath();
        $this->assertEquals('1234.56', $this->p->roundUpToMinUnit('1234.56', -2));
    }

    public function testRoundUpAtCentExactNoop(): void
    {
        $this->requireBcmath();
        $this->assertEquals('0.10', $this->p->roundUpToMinUnit('0.1', -2));
    }
}
