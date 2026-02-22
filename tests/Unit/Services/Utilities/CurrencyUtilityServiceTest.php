<?php
/**
 * Unit Tests for CurrencyUtilityService
 *
 * Tests currency conversion, formatting, and fee calculations.
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\CurrencyUtilityService;

#[CoversClass(CurrencyUtilityService::class)]
class CurrencyUtilityServiceTest extends TestCase
{
    private CurrencyUtilityService $service;

    protected function setUp(): void
    {
        $this->service = new CurrencyUtilityService();
    }

    /**
     * Test convert minor to major units basic conversion
     */
    public function testConvertMinorToMajorBasic(): void
    {
        $this->assertEquals(1.00, $this->service->convertMinorToMajor(100));
        $this->assertEquals(10.50, $this->service->convertMinorToMajor(1050));
        $this->assertEquals(0.01, $this->service->convertMinorToMajor(1));
    }

    /**
     * Test convert minor to major units with zero
     */
    public function testConvertMinorToMajorWithZero(): void
    {
        $this->assertEquals(0.00, $this->service->convertMinorToMajor(0));
    }

    /**
     * Test convert minor to major units with negative amounts
     */
    public function testConvertMinorToMajorWithNegative(): void
    {
        $this->assertEquals(-5.00, $this->service->convertMinorToMajor(-500));
    }

    /**
     * Test convert major to minor units basic conversion
     */
    public function testConvertMajorToMinorBasic(): void
    {
        $this->assertEquals(100, $this->service->convertMajorToMinor(1.00));
        $this->assertEquals(1050, $this->service->convertMajorToMinor(10.50));
        $this->assertEquals(1, $this->service->convertMajorToMinor(0.01));
    }

    /**
     * Test convert major to minor units with zero
     */
    public function testConvertMajorToMinorWithZero(): void
    {
        $this->assertEquals(0, $this->service->convertMajorToMinor(0.00));
    }

    /**
     * Test convert major to minor units rounds correctly
     */
    public function testConvertMajorToMinorRounding(): void
    {
        // 1.999 should round to 200 minor units
        $this->assertEquals(200, $this->service->convertMajorToMinor(1.999));
        // 1.994 should round to 199 minor units
        $this->assertEquals(199, $this->service->convertMajorToMinor(1.994));
    }

    /**
     * Test format currency basic formatting
     */
    public function testFormatCurrencyBasic(): void
    {
        $this->assertEquals('1.00 USD', $this->service->formatCurrency(100));
        $this->assertEquals('10.50 USD', $this->service->formatCurrency(1050));
        $this->assertEquals('1,000.00 USD', $this->service->formatCurrency(100000));
    }

    /**
     * Test format currency with custom currency code
     */
    public function testFormatCurrencyWithCustomCode(): void
    {
        $this->assertEquals('10.00 EUR', $this->service->formatCurrency(1000, 'EUR'));
        $this->assertEquals('5.25 GBP', $this->service->formatCurrency(525, 'GBP'));
    }

    /**
     * Test format currency with zero
     */
    public function testFormatCurrencyWithZero(): void
    {
        $this->assertEquals('0.00 USD', $this->service->formatCurrency(0));
    }

    /**
     * Test calculate fee percent with valid amounts
     */
    public function testCalculateFeePercentWithValidAmounts(): void
    {
        // 10% fee: total 110, base 100
        $feePercent = $this->service->calculateFeePercent(110, 100);
        $this->assertEquals(10.0, $feePercent);

        // 5% fee: total 105, base 100
        $feePercent = $this->service->calculateFeePercent(105, 100);
        $this->assertEquals(5.0, $feePercent);
    }

    /**
     * Test calculate fee percent with zero base returns zero
     */
    public function testCalculateFeePercentWithZeroBase(): void
    {
        $this->assertEquals(0.0, $this->service->calculateFeePercent(100, 0));
    }

    /**
     * Test calculate fee percent with same amounts (0% fee)
     */
    public function testCalculateFeePercentWithSameAmounts(): void
    {
        $this->assertEquals(0.0, $this->service->calculateFeePercent(100, 100));
    }

    /**
     * Test conversion round-trip preserves value
     */
    public function testConversionRoundTrip(): void
    {
        $originalMinorUnits = 1234;
        $majorUnits = $this->service->convertMinorToMajor($originalMinorUnits);
        $backToMinorUnits = $this->service->convertMajorToMinor($majorUnits);

        $this->assertEquals($originalMinorUnits, $backToMinorUnits);
    }

    /**
     * Test large amounts
     */
    public function testLargeAmounts(): void
    {
        $largeMinorUnits = 100000000; // 1 million major units
        $majorUnits = $this->service->convertMinorToMajor($largeMinorUnits);

        $this->assertEquals(1000000.00, $majorUnits);
        $this->assertEquals('1,000,000.00 USD', $this->service->formatCurrency($largeMinorUnits));
    }

    /**
     * Test fractional minor units handling
     */
    public function testFractionalMinorUnitsHandling(): void
    {
        // Float input with sub-unit precision
        $result = $this->service->convertMinorToMajor(99.5);

        $this->assertIsFloat($result);
        $this->assertEquals(0.995, $result);
    }

    /**
     * Test convertMinorToMajor with explicit USD currency
     */
    public function testConvertMinorToMajorWithExplicitCurrency(): void
    {
        $this->assertEquals(1.00, $this->service->convertMinorToMajor(100, 'USD'));
        $this->assertEquals(10.50, $this->service->convertMinorToMajor(1050, 'USD'));
    }

    /**
     * Test convertMajorToMinor with explicit USD currency
     */
    public function testConvertMajorToMinorWithExplicitCurrency(): void
    {
        $this->assertEquals(100, $this->service->convertMajorToMinor(1.00, 'USD'));
        $this->assertEquals(1050, $this->service->convertMajorToMinor(10.50, 'USD'));
    }

    /**
     * Test calculateFee with explicit USD currency
     */
    public function testCalculateFeeWithExplicitCurrency(): void
    {
        // 10% fee on 1000 minor units ($10.00) = $1.00 = 100 minor units
        $fee = $this->service->calculateFee(1000, 10.0, 0.01, 'USD');
        $this->assertEquals(100, $fee);
    }

    /**
     * Test convertMinorToMajor throws for unknown currency
     */
    public function testConvertMinorToMajorThrowsForUnknownCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->convertMinorToMajor(100, 'XYZ');
    }

    /**
     * Test convertMajorToMinor throws for unknown currency
     */
    public function testConvertMajorToMinorThrowsForUnknownCurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->convertMajorToMinor(1.00, 'XYZ');
    }
}
