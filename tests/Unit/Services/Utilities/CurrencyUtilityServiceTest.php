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
     * Test convert cents to dollars basic conversion
     */
    public function testConvertCentsToDollarsBasic(): void
    {
        $this->assertEquals(1.00, $this->service->convertCentsToDollars(100));
        $this->assertEquals(10.50, $this->service->convertCentsToDollars(1050));
        $this->assertEquals(0.01, $this->service->convertCentsToDollars(1));
    }

    /**
     * Test convert cents to dollars with zero
     */
    public function testConvertCentsToDollarsWithZero(): void
    {
        $this->assertEquals(0.00, $this->service->convertCentsToDollars(0));
    }

    /**
     * Test convert cents to dollars with negative amounts
     */
    public function testConvertCentsToDollarsWithNegative(): void
    {
        $this->assertEquals(-5.00, $this->service->convertCentsToDollars(-500));
    }

    /**
     * Test convert dollars to cents basic conversion
     */
    public function testConvertDollarsToCentsBasic(): void
    {
        $this->assertEquals(100, $this->service->convertDollarsToCents(1.00));
        $this->assertEquals(1050, $this->service->convertDollarsToCents(10.50));
        $this->assertEquals(1, $this->service->convertDollarsToCents(0.01));
    }

    /**
     * Test convert dollars to cents with zero
     */
    public function testConvertDollarsToCentsWithZero(): void
    {
        $this->assertEquals(0, $this->service->convertDollarsToCents(0.00));
    }

    /**
     * Test convert dollars to cents rounds correctly
     */
    public function testConvertDollarsToCentsRounding(): void
    {
        // 1.999 should round to 200 cents
        $this->assertEquals(200, $this->service->convertDollarsToCents(1.999));
        // 1.994 should round to 199 cents
        $this->assertEquals(199, $this->service->convertDollarsToCents(1.994));
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
        $originalCents = 1234;
        $dollars = $this->service->convertCentsToDollars($originalCents);
        $backToCents = $this->service->convertDollarsToCents($dollars);

        $this->assertEquals($originalCents, $backToCents);
    }

    /**
     * Test large amounts
     */
    public function testLargeAmounts(): void
    {
        $largeCents = 100000000; // 1 million dollars
        $dollars = $this->service->convertCentsToDollars($largeCents);

        $this->assertEquals(1000000.00, $dollars);
        $this->assertEquals('1,000,000.00 USD', $this->service->formatCurrency($largeCents));
    }

    /**
     * Test fractional cents handling
     */
    public function testFractionalCentsHandling(): void
    {
        // Float input with sub-cent precision
        $result = $this->service->convertCentsToDollars(99.5);

        $this->assertIsFloat($result);
        $this->assertEquals(0.995, $result);
    }
}
