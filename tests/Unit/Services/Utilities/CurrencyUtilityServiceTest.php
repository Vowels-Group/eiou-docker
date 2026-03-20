<?php
/**
 * Unit Tests for CurrencyUtilityService
 *
 * Tests currency conversion, formatting, and fee calculations.
 * All amounts use the internal conversion factor of 10^8 (INTERNAL_CONVERSION_FACTOR).
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\Constants;

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
    // convertMinorToMajor Tests (internal 10^8 minor units → major units)
    // =========================================================================

    public function testConvertMinorToMajorBasic(): void
    {
        // 100000000 minor units = 1.00 major unit
        $this->assertEquals(1.00, $this->service->convertMinorToMajor(100000000));
        // 1050000000 minor units = 10.50
        $this->assertEquals(10.50, $this->service->convertMinorToMajor(1050000000));
        // 1 minor unit = 0.00000001
        $this->assertEquals(0.00000001, $this->service->convertMinorToMajor(1));
    }

    public function testConvertMinorToMajorWithZero(): void
    {
        $this->assertEquals(0.00, $this->service->convertMinorToMajor(0));
    }

    public function testConvertMinorToMajorWithNegative(): void
    {
        $this->assertEquals(-5.00, $this->service->convertMinorToMajor(-500000000));
    }

    // =========================================================================
    // convertMajorToMinor Tests (major units → internal 10^8 minor units)
    // =========================================================================

    public function testConvertMajorToMinorBasic(): void
    {
        $this->assertEquals(100000000, $this->service->convertMajorToMinor(1.00));
        $this->assertEquals(1050000000, $this->service->convertMajorToMinor(10.50));
        $this->assertEquals(1000000, $this->service->convertMajorToMinor(0.01));
    }

    public function testConvertMajorToMinorWithZero(): void
    {
        $this->assertEquals(0, $this->service->convertMajorToMinor(0.00));
    }

    public function testConvertMajorToMinorBcmulPrecision(): void
    {
        // bcmul should give exact results — 1.999 * 10^8 = 199900000
        $this->assertEquals(199900000, $this->service->convertMajorToMinor(1.999));
        // 1.99400000 * 10^8 = 199400000
        $this->assertEquals(199400000, $this->service->convertMajorToMinor(1.994));
    }

    // =========================================================================
    // formatCurrency Tests
    // =========================================================================

    public function testFormatCurrencyBasic(): void
    {
        // Uses getDisplayDecimals — USD defaults to 2
        $this->assertEquals('1.00 USD', $this->service->formatCurrency(100000000));
        $this->assertEquals('10.50 USD', $this->service->formatCurrency(1050000000));
        $this->assertEquals('1,000.00 USD', $this->service->formatCurrency(100000000000));
    }

    public function testFormatCurrencyWithZero(): void
    {
        $this->assertEquals('0.00 USD', $this->service->formatCurrency(0));
    }

    // =========================================================================
    // calculateFeePercent Tests
    // =========================================================================

    public function testCalculateFeePercentWithValidAmounts(): void
    {
        // 10% fee: total 110, base 100 (ratios are unit-independent)
        $this->assertEquals(10.0, $this->service->calculateFeePercent(110, 100));
        // 5% fee: total 105, base 100
        $this->assertEquals(5.0, $this->service->calculateFeePercent(105, 100));
    }

    public function testCalculateFeePercentWithZeroBase(): void
    {
        $this->assertEquals(0.0, $this->service->calculateFeePercent(100, 0));
    }

    public function testCalculateFeePercentWithSameAmounts(): void
    {
        $this->assertEquals(0.0, $this->service->calculateFeePercent(100, 100));
    }

    // =========================================================================
    // Conversion Round-Trip Tests
    // =========================================================================

    public function testConversionRoundTrip(): void
    {
        $originalMinorUnits = 123400000; // 1.234 major units
        $majorUnits = $this->service->convertMinorToMajor($originalMinorUnits);
        $backToMinorUnits = $this->service->convertMajorToMinor($majorUnits);

        $this->assertEquals($originalMinorUnits, $backToMinorUnits);
    }

    public function testConversionRoundTripSmallAmount(): void
    {
        // 1 minor unit (smallest possible)
        $majorUnits = $this->service->convertMinorToMajor(1);
        $backToMinor = $this->service->convertMajorToMinor($majorUnits);
        $this->assertEquals(1, $backToMinor);
    }

    // =========================================================================
    // Large Amount Tests
    // =========================================================================

    public function testLargeAmounts(): void
    {
        // 1 million major units = 10^14 minor units
        $largeMinorUnits = 100000000000000;
        $majorUnits = $this->service->convertMinorToMajor($largeMinorUnits);
        $this->assertEquals(1000000.00, $majorUnits);
    }

    public function testLargeAmountBcmulPrecision(): void
    {
        // 90 million — previously borderline for float, now exact with bcmul
        $this->assertEquals(9000000000000000, $this->service->convertMajorToMinor(90000000.00));
        // 500 million
        $this->assertEquals(50000000000000000, $this->service->convertMajorToMinor(500000000.00));
    }

    // =========================================================================
    // calculateFee Tests (with 10^8 factor)
    // =========================================================================

    public function testCalculateFeeBasic(): void
    {
        // 10% fee on $10.00 (1000000000 minor units) = $1.00 = 100000000 minor units
        $fee = $this->service->calculateFee(1000000000, 10.0, 0.01, 'USD');
        $this->assertEquals(100000000, $fee);
    }

    public function testCalculateFeeSmallPercentage(): void
    {
        // 1% fee on $100 (10000000000 minor units) = $1.00 = 100000000 minor units
        $fee = $this->service->calculateFee(10000000000, 1.0, 0.01, 'USD');
        $this->assertEquals(100000000, $fee);
    }

    public function testCalculateFeeMinimumFeeFloor(): void
    {
        // 0.01% of $1.00 (100000000) = 10000 → below minFee $0.01 (1000000) → returns minFee
        $fee = $this->service->calculateFee(100000000, 0.01, 0.01, 'USD');
        $this->assertEquals(1000000, $fee);

        // 0.01% of $10.00 (1000000000) = 100000 → below minFee $0.01 (1000000) → returns minFee
        $fee = $this->service->calculateFee(1000000000, 0.01, 0.01, 'USD');
        $this->assertEquals(1000000, $fee);
    }

    public function testCalculateFeeVariousPercentages(): void
    {
        // 1% fee on $100 = $1.00 = 100000000
        $fee = $this->service->calculateFee(10000000000, 1.0, 0.01, 'USD');
        $this->assertEquals(100000000, $fee);

        // 0.1% fee on $1000 = $1.00 = 100000000
        $fee = $this->service->calculateFee(100000000000, 0.1, 0.01, 'USD');
        $this->assertEquals(100000000, $fee);

        // 0.01% fee on $10000 = $1.00 = 100000000
        $fee = $this->service->calculateFee(1000000000000, 0.01, 0.01, 'USD');
        $this->assertEquals(100000000, $fee);
    }

    // =========================================================================
    // calculateFee with zero fee / zero minFee
    // =========================================================================

    public function testCalculateFeeZeroPercentAppliesMinFee(): void
    {
        // 0% fee on $100 = 0, but minFee $0.01 = 1000000 minor units
        $fee = $this->service->calculateFee(10000000000, 0.0, 0.01, 'USD');
        $this->assertEquals(1000000, $fee);
    }

    public function testCalculateFeeZeroPercentZeroMinFeeReturnsZero(): void
    {
        // 0% fee, 0 minFee = truly free relaying
        $fee = $this->service->calculateFee(10000000000, 0.0, 0.0, 'USD');
        $this->assertEquals(0, $fee);
    }

    public function testCalculateFeePositivePercentZeroMinFeeUsesCalculated(): void
    {
        // 1% fee on $100 = 100000000, minFee=0 doesn't interfere
        $fee = $this->service->calculateFee(10000000000, 1.0, 0.0, 'USD');
        $this->assertEquals(100000000, $fee);
    }

    public function testCalculateFeeSmallAmountZeroMinFeeCanReturnZero(): void
    {
        // 0.01% fee on $0.50 (50000000) = 5000 → rounds to 5000 (not zero with bcmul)
        // but minFee=0 so it returns the calculated fee
        $fee = $this->service->calculateFee(50000000, 0.01, 0.0, 'USD');
        $this->assertEquals(5000, $fee);
    }

    // =========================================================================
    // Internal factor is universal — currency param doesn't change conversion
    // =========================================================================

    public function testConversionFactorIsSameForAllCurrencies(): void
    {
        $this->assertEquals(
            $this->service->convertMajorToMinor(1.00, 'USD'),
            $this->service->convertMajorToMinor(1.00, 'EUR')
        );
    }
}
