<?php
/**
 * Unit Tests for CurrencyUtilityService
 *
 * Tests currency conversion, formatting, and fee calculations.
 * All amounts use SplitAmount (whole + frac) representation.
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;

#[CoversClass(CurrencyUtilityService::class)]
class CurrencyUtilityServiceTest extends TestCase
{
    private CurrencyUtilityService $service;

    protected function setUp(): void
    {
        if (!function_exists('bcmul')) {
            $this->markTestSkipped('bcmath extension not available — required in production (php-bcmath)');
        }
        $this->service = new CurrencyUtilityService();
    }

    // =========================================================================
    // convertMinorToMajor Tests (SplitAmount → float)
    // =========================================================================

    public function testConvertMinorToMajorBasic(): void
    {
        // 1.00 major unit
        $this->assertEquals(1.00, $this->service->convertMinorToMajor(new SplitAmount(1, 0)));
        // 10.50
        $this->assertEquals(10.50, $this->service->convertMinorToMajor(new SplitAmount(10, 50000000)));
        // 0.00000001
        $this->assertEquals(0.00000001, $this->service->convertMinorToMajor(new SplitAmount(0, 1)));
    }

    public function testConvertMinorToMajorWithZero(): void
    {
        $this->assertEquals(0.00, $this->service->convertMinorToMajor(SplitAmount::zero()));
    }

    public function testConvertMinorToMajorWithNegative(): void
    {
        // -5.00 = whole=-5, frac=0
        $this->assertEquals(-5.00, $this->service->convertMinorToMajor(new SplitAmount(-5, 0)));
    }

    // =========================================================================
    // convertMajorToMinor Tests (float → SplitAmount)
    // =========================================================================

    public function testConvertMajorToMinorBasic(): void
    {
        $result = $this->service->convertMajorToMinor(1.00);
        $this->assertEquals(1, $result->whole);
        $this->assertEquals(0, $result->frac);

        $result = $this->service->convertMajorToMinor(10.50);
        $this->assertEquals(10, $result->whole);
        $this->assertEquals(50000000, $result->frac);

        $result = $this->service->convertMajorToMinor(0.01);
        $this->assertEquals(0, $result->whole);
        $this->assertEquals(1000000, $result->frac);
    }

    public function testConvertMajorToMinorWithZero(): void
    {
        $result = $this->service->convertMajorToMinor(0.00);
        $this->assertTrue($result->isZero());
    }

    // =========================================================================
    // formatCurrency Tests
    // =========================================================================

    public function testFormatCurrencyBasic(): void
    {
        $this->assertEquals('1.00 USD', $this->service->formatCurrency(new SplitAmount(1, 0)));
        $this->assertEquals('10.50 USD', $this->service->formatCurrency(new SplitAmount(10, 50000000)));
        $this->assertEquals('1,000.00 USD', $this->service->formatCurrency(new SplitAmount(1000, 0)));
    }

    public function testFormatCurrencyWithZero(): void
    {
        $this->assertEquals('0.00 USD', $this->service->formatCurrency(SplitAmount::zero()));
    }

    // =========================================================================
    // calculateFeePercent Tests
    // =========================================================================

    public function testCalculateFeePercentWithValidAmounts(): void
    {
        // 10% fee: total 110, base 100
        $this->assertEquals(10.0, $this->service->calculateFeePercent(new SplitAmount(110, 0), new SplitAmount(100, 0)));
        // 5% fee: total 105, base 100
        $this->assertEquals(5.0, $this->service->calculateFeePercent(new SplitAmount(105, 0), new SplitAmount(100, 0)));
    }

    public function testCalculateFeePercentWithZeroBase(): void
    {
        $this->assertEquals(0.0, $this->service->calculateFeePercent(new SplitAmount(100, 0), SplitAmount::zero()));
    }

    public function testCalculateFeePercentWithSameAmounts(): void
    {
        $this->assertEquals(0.0, $this->service->calculateFeePercent(new SplitAmount(100, 0), new SplitAmount(100, 0)));
    }

    // =========================================================================
    // Conversion Round-Trip Tests
    // =========================================================================

    public function testConversionRoundTrip(): void
    {
        $original = new SplitAmount(1, 23400000); // 1.234
        $majorUnits = $this->service->convertMinorToMajor($original);
        $backToSplit = $this->service->convertMajorToMinor($majorUnits);

        $this->assertEquals($original->whole, $backToSplit->whole);
        $this->assertEquals($original->frac, $backToSplit->frac);
    }

    public function testConversionRoundTripSmallAmount(): void
    {
        $original = new SplitAmount(0, 1); // 0.00000001
        $majorUnits = $this->service->convertMinorToMajor($original);
        $backToSplit = $this->service->convertMajorToMinor($majorUnits);
        $this->assertEquals(0, $backToSplit->whole);
        $this->assertEquals(1, $backToSplit->frac);
    }

    // =========================================================================
    // Large Amount Tests — the whole point of split amounts!
    // =========================================================================

    public function testLargeAmounts(): void
    {
        // 1 million major units
        $amount = new SplitAmount(1000000, 0);
        $majorUnits = $this->service->convertMinorToMajor($amount);
        $this->assertEquals(1000000.00, $majorUnits);
    }

    public function testVeryLargeAmountsBeyondOldLimit(): void
    {
        // 1 trillion — impossible with the old single-int approach
        $amount = new SplitAmount(1000000000000, 50000000);
        $this->assertEquals(1000000000000.50, $amount->toMajorUnits());
    }

    // =========================================================================
    // calculateFee Tests
    // =========================================================================

    public function testCalculateFeeBasic(): void
    {
        // 10% fee on $10.00 = $1.00
        $fee = $this->service->calculateFee(new SplitAmount(10, 0), 10.0, 0.01, 'USD');
        $this->assertEquals(1, $fee->whole);
        $this->assertEquals(0, $fee->frac);
    }

    public function testCalculateFeeSmallPercentage(): void
    {
        // 1% fee on $100 = $1.00
        $fee = $this->service->calculateFee(new SplitAmount(100, 0), 1.0, 0.01, 'USD');
        $this->assertEquals(1, $fee->whole);
        $this->assertEquals(0, $fee->frac);
    }

    public function testCalculateFeeMinimumFeeFloor(): void
    {
        // 0.01% of $1.00 = $0.0001 → below minFee $0.01 → returns minFee
        $fee = $this->service->calculateFee(new SplitAmount(1, 0), 0.01, 0.01, 'USD');
        $this->assertEquals(0, $fee->whole);
        $this->assertEquals(1000000, $fee->frac); // 0.01 = frac 1000000
    }

    public function testCalculateFeeZeroPercentAppliesMinFee(): void
    {
        // 0% fee on $100 = 0, but minFee $0.01
        $fee = $this->service->calculateFee(new SplitAmount(100, 0), 0.0, 0.01, 'USD');
        $this->assertEquals(0, $fee->whole);
        $this->assertEquals(1000000, $fee->frac);
    }

    public function testCalculateFeeZeroPercentZeroMinFeeReturnsZero(): void
    {
        // 0% fee, 0 minFee = truly free relaying
        $fee = $this->service->calculateFee(new SplitAmount(100, 0), 0.0, 0.0, 'USD');
        $this->assertTrue($fee->isZero());
    }

    public function testCalculateFeePositivePercentZeroMinFeeUsesCalculated(): void
    {
        // 1% fee on $100 = $1.00, minFee=0 doesn't interfere
        $fee = $this->service->calculateFee(new SplitAmount(100, 0), 1.0, 0.0, 'USD');
        $this->assertEquals(1, $fee->whole);
        $this->assertEquals(0, $fee->frac);
    }

    // =========================================================================
    // Internal factor is universal — currency param doesn't change conversion
    // =========================================================================

    public function testConversionFactorIsSameForAllCurrencies(): void
    {
        $usd = $this->service->convertMajorToMinor(1.00, 'USD');
        $eur = $this->service->convertMajorToMinor(1.00, 'EUR');
        $this->assertEquals($usd->whole, $eur->whole);
        $this->assertEquals($usd->frac, $eur->frac);
    }
}
