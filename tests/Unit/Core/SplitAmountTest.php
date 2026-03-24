<?php

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\SplitAmount;

#[CoversClass(SplitAmount::class)]
class SplitAmountTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('bcmul')) {
            $this->markTestSkipped('bcmath extension required');
        }
    }

    public function testZero(): void
    {
        $z = SplitAmount::zero();
        $this->assertEquals(0, $z->whole);
        $this->assertEquals(0, $z->frac);
        $this->assertTrue($z->isZero());
    }

    public function testFromMajorUnitsSimple(): void
    {
        $a = SplitAmount::fromMajorUnits(1234.56);
        $this->assertEquals(1234, $a->whole);
        $this->assertEquals(56000000, $a->frac);
    }

    public function testFromMajorUnitsNoFraction(): void
    {
        $a = SplitAmount::fromMajorUnits(100.0);
        $this->assertEquals(100, $a->whole);
        $this->assertEquals(0, $a->frac);
    }

    public function testFromMinorUnits(): void
    {
        $a = SplitAmount::fromMinorUnits(123456000000);
        $this->assertEquals(1234, $a->whole);
        $this->assertEquals(56000000, $a->frac);
    }

    public function testFromMinorUnitsNegative(): void
    {
        $a = SplitAmount::fromMinorUnits(-150000000); // -1.50
        $this->assertEquals(-2, $a->whole);
        $this->assertEquals(50000000, $a->frac);
        $this->assertEqualsWithDelta(-1.50, $a->toMajorUnits(), 0.00000001);
    }

    public function testToMinorUnitsRoundTrip(): void
    {
        $original = 9876543210;
        $a = SplitAmount::fromMinorUnits($original);
        $this->assertEquals($original, $a->toMinorUnits());
    }

    public function testToMinorUnitsThrowsOnOverflow(): void
    {
        // whole=100 billion → 100B * 10^8 overflows PHP_INT_MAX
        $a = new SplitAmount(100000000000, 0);
        $this->expectException(\OverflowException::class);
        $a->toMinorUnits();
    }

    public function testAddSimple(): void
    {
        $a = new SplitAmount(10, 50000000); // 10.50
        $b = new SplitAmount(5, 30000000);  // 5.30
        $c = $a->add($b);
        $this->assertEquals(15, $c->whole);
        $this->assertEquals(80000000, $c->frac);
    }

    public function testAddWithCarry(): void
    {
        $a = new SplitAmount(10, 70000000); // 10.70
        $b = new SplitAmount(5, 50000000);  // 5.50
        $c = $a->add($b);
        $this->assertEquals(16, $c->whole); // carry from frac
        $this->assertEquals(20000000, $c->frac);
    }

    /**
     * Simulate relay fee accumulation: amount + fee → new amount.
     * This is the core operation that broke with the old single-int design.
     */
    public function testAddFeeToLargeAmount(): void
    {
        // $500 billion base + $5 billion fee (1%)
        $base = new SplitAmount(500000000000, 0);
        $fee  = new SplitAmount(5000000000, 0);
        $total = $base->add($fee);

        $this->assertEquals(505000000000, $total->whole);
        $this->assertEquals(0, $total->frac);
    }

    public function testAddOverflowThrows(): void
    {
        $a = new SplitAmount(PHP_INT_MAX, 0);
        $b = new SplitAmount(1, 0);
        $this->expectException(\OverflowException::class);
        $a->add($b);
    }

    public function testSubtractSimple(): void
    {
        $a = new SplitAmount(10, 50000000);
        $b = new SplitAmount(3, 20000000);
        $c = $a->subtract($b);
        $this->assertEquals(7, $c->whole);
        $this->assertEquals(30000000, $c->frac);
    }

    public function testSubtractWithBorrow(): void
    {
        $a = new SplitAmount(10, 20000000); // 10.20
        $b = new SplitAmount(3, 50000000);  // 3.50
        $c = $a->subtract($b);
        $this->assertEquals(6, $c->whole);  // 10 - 3 - 1(borrow) = 6
        $this->assertEquals(70000000, $c->frac); // 20000000 + 100000000 - 50000000
    }

    // =========================================================================
    // Fee calculation tests — the critical path for relay routing
    // =========================================================================

    public function testMultiplyPercentBasic(): void
    {
        $a = new SplitAmount(100, 0); // $100
        $fee = $a->multiplyPercent(2.5); // 2.5% = $2.50
        $this->assertEquals(2, $fee->whole);
        $this->assertEquals(50000000, $fee->frac);
    }

    public function testMultiplyPercentSmall(): void
    {
        $a = new SplitAmount(10, 0); // $10
        $fee = $a->multiplyPercent(0.01); // 0.01% = $0.001
        $this->assertEquals(0, $fee->whole);
        $this->assertEquals(100000, $fee->frac); // 0.001 * 10^8 = 100000
    }

    public function testMultiplyPercentZero(): void
    {
        $a = new SplitAmount(1000000, 0);
        $this->assertTrue($a->multiplyPercent(0.0)->isZero());
    }

    /**
     * Critical test: fee on a very large amount.
     * With the old single-int design, $500B * 10^8 overflowed PHP_INT_MAX.
     * With split amounts + bcmath strings, this must work correctly.
     */
    public function testMultiplyPercentOnLargeAmount(): void
    {
        // $500 billion, 1% fee = $5 billion
        $amount = new SplitAmount(500000000000, 0);
        $fee = $amount->multiplyPercent(1.0);
        $this->assertEquals(5000000000, $fee->whole);
        $this->assertEquals(0, $fee->frac);
    }

    /**
     * Test fee on amount with fractional part.
     * 2.5% of $1000.50 = $25.0125
     */
    public function testMultiplyPercentWithFractionalAmount(): void
    {
        $amount = new SplitAmount(1000, 50000000); // $1000.50
        $fee = $amount->multiplyPercent(2.5);
        // 2.5% of 1000.50 = 25.0125
        $this->assertEquals(25, $fee->whole);
        $this->assertEquals(1250000, $fee->frac); // 0.0125 * 10^8 = 1250000
    }

    /**
     * Test fee on amount beyond the old 92B limit.
     * This is the scenario that proves split amounts work.
     */
    public function testMultiplyPercentBeyondOld92BLimit(): void
    {
        // $1 trillion, 5% fee = $50 billion
        $amount = new SplitAmount(1000000000000, 0);
        $fee = $amount->multiplyPercent(5.0);
        $this->assertEquals(50000000000, $fee->whole);
        $this->assertEquals(0, $fee->frac);
    }

    /**
     * Simulate full relay chain: amount + fee, then next relay charges fee on total.
     * This is the compounding fee scenario from Rp2pService.
     */
    public function testCompoundingRelayFees(): void
    {
        // Original amount: $1 trillion
        $amount = new SplitAmount(1000000000000, 0);

        // Relay 1: 2% fee
        $fee1 = $amount->multiplyPercent(2.0);
        $this->assertEquals(20000000000, $fee1->whole); // $20 billion
        $afterRelay1 = $amount->add($fee1);
        $this->assertEquals(1020000000000, $afterRelay1->whole);

        // Relay 2: 1.5% fee on accumulated total
        $fee2 = $afterRelay1->multiplyPercent(1.5);
        $this->assertEquals(15300000000, $fee2->whole); // $15.3 billion
        $afterRelay2 = $afterRelay1->add($fee2);
        $this->assertEquals(1035300000000, $afterRelay2->whole);

        // Relay 3: 0.5% fee
        $fee3 = $afterRelay2->multiplyPercent(0.5);
        $afterRelay3 = $afterRelay2->add($fee3);

        // All amounts still fit, no overflow
        $this->assertGreaterThan(1035300000000, $afterRelay3->whole);
    }

    /**
     * Test that fee calculation + add doesn't silently overflow
     * for amounts near the practical max.
     */
    public function testMaxAmountPlusFeeWithinBounds(): void
    {
        // TRANSACTION_MAX_AMOUNT = PHP_INT_MAX / 4
        $maxWhole = 2305843009213693951;
        $amount = new SplitAmount($maxWhole, 0);

        // 100% fee = doubles the amount
        $fee = $amount->multiplyPercent(100.0);
        $this->assertEquals($maxWhole, $fee->whole);

        // amount + fee = 2 * maxWhole = PHP_INT_MAX / 2 — should fit
        $total = $amount->add($fee);
        $this->assertEquals($maxWhole * 2, $total->whole);
    }

    // =========================================================================
    // Comparison tests
    // =========================================================================

    public function testComparisons(): void
    {
        $a = new SplitAmount(10, 50000000);
        $b = new SplitAmount(10, 50000000);
        $c = new SplitAmount(10, 60000000);

        $this->assertEquals(0, $a->compareTo($b));
        $this->assertTrue($a->gte($b));
        $this->assertFalse($a->lt($b));
        $this->assertTrue($a->lt($c));
        $this->assertTrue($c->gte($a));
    }

    // =========================================================================
    // Serialization tests
    // =========================================================================

    public function testToArray(): void
    {
        $a = new SplitAmount(42, 12345678);
        $this->assertEquals(['whole' => 42, 'frac' => 12345678], $a->toArray());
    }

    public function testFromArray(): void
    {
        $a = SplitAmount::fromArray(['whole' => 42, 'frac' => 12345678]);
        $this->assertEquals(42, $a->whole);
        $this->assertEquals(12345678, $a->frac);
    }

    public function testJsonSerialize(): void
    {
        $a = new SplitAmount(100, 50000000);
        $json = json_encode($a);
        $decoded = json_decode($json, true);
        $this->assertEquals(['whole' => 100, 'frac' => 50000000], $decoded);
    }

    public function testToString(): void
    {
        $a = new SplitAmount(42, 100000);
        $this->assertEquals('42.00100000', (string) $a);
    }

    // =========================================================================
    // Validation tests
    // =========================================================================

    public function testInvalidFracThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SplitAmount(1, -1);
    }

    public function testInvalidFracTooLargeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SplitAmount(1, 100000000);
    }

    public function testIsPositive(): void
    {
        $this->assertTrue((new SplitAmount(1, 0))->isPositive());
        $this->assertTrue((new SplitAmount(0, 1))->isPositive());
        $this->assertFalse(SplitAmount::zero()->isPositive());
        $this->assertFalse((new SplitAmount(-1, 0))->isPositive());
    }

    public function testIsNegative(): void
    {
        $this->assertTrue((new SplitAmount(-1, 0))->isNegative());
        $this->assertFalse((new SplitAmount(0, 0))->isNegative());
        $this->assertFalse((new SplitAmount(1, 0))->isNegative());
    }

    public function testVeryLargeWhole(): void
    {
        // The whole point: amounts far beyond 92 billion
        $a = new SplitAmount(1000000000000, 50000000); // 1 trillion + 0.50
        $b = new SplitAmount(2000000000000, 25000000); // 2 trillion + 0.25
        $c = $a->add($b);
        $this->assertEquals(3000000000000, $c->whole);
        $this->assertEquals(75000000, $c->frac);
    }

    // =========================================================================
    // toDisplayString tests
    // =========================================================================

    public function testToDisplayStringDefault2Decimals(): void
    {
        $a = new SplitAmount(100, 50000000); // 100.50
        $this->assertSame('100.50', $a->toDisplayString());
    }

    public function testToDisplayStringZeroDecimals(): void
    {
        $a = new SplitAmount(42, 99999999);
        $this->assertSame('43', $a->toDisplayString(0));
    }

    public function testToDisplayString8Decimals(): void
    {
        $a = new SplitAmount(100, 1); // 100.00000001
        $this->assertSame('100.00000001', $a->toDisplayString(8));
    }

    public function testToDisplayString4Decimals(): void
    {
        $a = new SplitAmount(1000, 12345678); // 1000.12345678
        $this->assertSame('1000.1235', $a->toDisplayString(4));
    }

    public function testToDisplayStringZeroAmount(): void
    {
        $z = SplitAmount::zero();
        $this->assertSame('0.00', $z->toDisplayString());
        $this->assertSame('0.00000000', $z->toDisplayString(8));
    }

    public function testToDisplayStringLargeAmount(): void
    {
        $a = new SplitAmount(1000000000000, 50000000); // 1 trillion + 0.50
        $this->assertSame('1000000000000.50', $a->toDisplayString());
    }

    public function testToDisplayStringNegativeAmount(): void
    {
        // -1.50 = whole=-2, frac=50000000
        $a = new SplitAmount(-2, 50000000);
        $this->assertSame('-1.50', $a->toDisplayString());
    }

    public function testToDisplayStringMaxFrac(): void
    {
        // 0.99999999 — max fractional value
        $a = new SplitAmount(0, 99999999);
        $this->assertSame('1.00', $a->toDisplayString()); // rounds to 1.00 at 2 decimals
        $this->assertSame('0.99999999', $a->toDisplayString(8));
    }

    // =========================================================================
    // fromMajorUnits precision tests
    // =========================================================================

    public function testFromMajorUnitsExtraPrecisionTruncated(): void
    {
        // Float with >8 decimal places — truncated, not rounded
        $a = SplitAmount::fromMajorUnits(1.123456789);
        $this->assertEquals(1, $a->whole);
        // Float precision means exact comparison may vary, but frac should be ~12345679
        $this->assertLessThan(SplitAmount::FRAC_MODULUS, $a->frac);
        $this->assertGreaterThan(0, $a->frac);
    }

    public function testFromMajorUnitsVerySmall(): void
    {
        $a = SplitAmount::fromMajorUnits(0.00000001);
        $this->assertEquals(0, $a->whole);
        $this->assertEquals(1, $a->frac);
    }

    public function testFromMajorUnitsNegativeWithFrac(): void
    {
        $a = SplitAmount::fromMajorUnits(-1.50);
        $this->assertEquals(-2, $a->whole);
        $this->assertEquals(50000000, $a->frac);
        $this->assertEqualsWithDelta(-1.50, $a->toMajorUnits(), 0.00000001);
    }

    public function testFromMajorUnitsNegativeNoFrac(): void
    {
        $a = SplitAmount::fromMajorUnits(-100.0);
        $this->assertEquals(-100, $a->whole);
        $this->assertEquals(0, $a->frac);
    }

    // =========================================================================
    // fromString tests — scientific notation, leading zeros, edge cases
    // =========================================================================

    public function testFromStringScientificNotation(): void
    {
        $a = SplitAmount::fromString('1e5');
        $this->assertEquals(100000, $a->whole);
        $this->assertEquals(0, $a->frac);
    }

    public function testFromStringScientificNotationDecimal(): void
    {
        $a = SplitAmount::fromString('1.5e2');
        $this->assertEquals(150, $a->whole);
        $this->assertEquals(0, $a->frac);
    }

    public function testFromStringScientificNotationSmall(): void
    {
        $a = SplitAmount::fromString('1.5e-2');
        $this->assertEquals(0, $a->whole);
        $this->assertEquals(1500000, $a->frac); // 0.015 * 10^8
    }

    public function testFromStringLeadingZeros(): void
    {
        $a = SplitAmount::fromString('00100.50');
        $this->assertEquals(100, $a->whole);
        $this->assertEquals(50000000, $a->frac);
    }

    public function testFromStringLeadingZerosFractionOnly(): void
    {
        $a = SplitAmount::fromString('000.00000001');
        $this->assertEquals(0, $a->whole);
        $this->assertEquals(1, $a->frac);
    }

    public function testFromStringTrailingZerosBeyondPrecision(): void
    {
        $a = SplitAmount::fromString('100.50000000000000');
        $this->assertEquals(100, $a->whole);
        $this->assertEquals(50000000, $a->frac);
    }

    public function testFromStringExcessDecimalsTruncated(): void
    {
        // 12 decimal places → truncated to 8
        $a = SplitAmount::fromString('100.123456789012');
        $this->assertEquals(100, $a->whole);
        $this->assertEquals(12345678, $a->frac); // truncated, not rounded
    }

    public function testFromStringNegative(): void
    {
        $a = SplitAmount::fromString('-1.50');
        $this->assertEquals(-2, $a->whole);
        $this->assertEquals(50000000, $a->frac);
        $this->assertEqualsWithDelta(-1.50, $a->toMajorUnits(), 0.00000001);
    }

    public function testFromStringNegativeWholeOnly(): void
    {
        $a = SplitAmount::fromString('-100');
        $this->assertEquals(-100, $a->whole);
        $this->assertEquals(0, $a->frac);
    }

    public function testFromStringOverflowThrows(): void
    {
        // Number larger than PHP_INT_MAX
        $this->expectException(\OverflowException::class);
        SplitAmount::fromString('99999999999999999999');
    }

    public function testFromStringEmptyReturnsZero(): void
    {
        $this->assertTrue(SplitAmount::fromString('')->isZero());
        $this->assertTrue(SplitAmount::fromString('0')->isZero());
    }

    // =========================================================================
    // Negative amount representation tests
    // =========================================================================

    public function testNegativeWithFracRoundTrip(): void
    {
        // -1.50 stored as whole=-2, frac=50000000
        $a = new SplitAmount(-2, 50000000);
        $this->assertTrue($a->isNegative());
        $this->assertEqualsWithDelta(-1.50, $a->toMajorUnits(), 0.00000001);

        // Round-trip through fromMinorUnits
        $minor = -150000000; // -1.50 * 10^8
        $b = SplitAmount::fromMinorUnits($minor);
        $this->assertEquals($a->whole, $b->whole);
        $this->assertEquals($a->frac, $b->frac);
    }

    public function testNegativeSmallFrac(): void
    {
        // -0.01 → stored as whole=-1, frac=99000000 (i.e., -1 + 0.99 = -0.01)
        $a = SplitAmount::fromMinorUnits(-1000000);
        $this->assertEquals(-1, $a->whole);
        $this->assertEquals(99000000, $a->frac);
        $this->assertEqualsWithDelta(-0.01, $a->toMajorUnits(), 0.00000001);
    }

    public function testSubtractResultingInNegative(): void
    {
        $a = new SplitAmount(5, 0);    // 5.00
        $b = new SplitAmount(10, 50000000); // 10.50
        $c = $a->subtract($b);
        $this->assertTrue($c->isNegative());
        $this->assertEqualsWithDelta(-5.50, $c->toMajorUnits(), 0.00000001);
    }

    // =========================================================================
    // toMajorUnits precision edge cases
    // =========================================================================

    public function testToMajorUnitsMaxFrac(): void
    {
        $a = new SplitAmount(0, 99999999);
        $this->assertEqualsWithDelta(0.99999999, $a->toMajorUnits(), 0.000000001);
    }

    public function testToMajorUnitsMinFrac(): void
    {
        $a = new SplitAmount(0, 1);
        $this->assertEqualsWithDelta(0.00000001, $a->toMajorUnits(), 0.000000001);
    }

    // =========================================================================
    // Comparison edge cases
    // =========================================================================

    public function testCompareNegativeAmounts(): void
    {
        $a = new SplitAmount(-2, 50000000); // -1.50
        $b = new SplitAmount(-1, 0);        // -1.00
        $this->assertTrue($a->lt($b)); // -1.50 < -1.00
        $this->assertFalse($b->lt($a));
    }

    public function testCompareZeroWithSmallPositive(): void
    {
        $z = SplitAmount::zero();
        $small = new SplitAmount(0, 1); // 0.00000001
        $this->assertTrue($z->lt($small));
        $this->assertFalse($small->lt($z));
    }

    // =========================================================================
    // Fee calculation edge cases
    // =========================================================================

    public function testMultiplyPercentVerySmallOnLargeAmount(): void
    {
        // 0.001% of 1 trillion = $10 million
        $amount = new SplitAmount(1000000000000, 0);
        $fee = $amount->multiplyPercent(0.001);
        $this->assertEquals(10000000, $fee->whole);
        $this->assertEquals(0, $fee->frac);
    }

    public function testMultiplyPercentOnSmallAmount(): void
    {
        // 0.01% of $0.01 = $0.000001
        $amount = new SplitAmount(0, 1000000); // $0.01
        $fee = $amount->multiplyPercent(0.01);
        $this->assertEquals(0, $fee->whole);
        // 0.01 * 0.01 / 100 = 0.000001 → frac=100
        $this->assertEquals(100, $fee->frac);
    }

    public function testMultiplyPercent100(): void
    {
        // 100% of $50.25 = $50.25
        $amount = new SplitAmount(50, 25000000);
        $fee = $amount->multiplyPercent(100.0);
        $this->assertEquals(50, $fee->whole);
        $this->assertEquals(25000000, $fee->frac);
    }

    // =========================================================================
    // from() universal factory edge cases
    // =========================================================================

    public function testFromNull(): void
    {
        $this->assertTrue(SplitAmount::from(null)->isZero());
    }

    public function testFromSplitAmountPassthrough(): void
    {
        $original = new SplitAmount(42, 12345678);
        $this->assertSame($original, SplitAmount::from($original));
    }

    public function testFromIntWhole(): void
    {
        $a = SplitAmount::from(100);
        $this->assertEquals(100, $a->whole);
        $this->assertEquals(0, $a->frac);
    }

    public function testFromFloat(): void
    {
        $a = SplitAmount::from(100.50);
        $this->assertEquals(100, $a->whole);
        $this->assertEquals(50000000, $a->frac);
    }

    public function testFromNumericString(): void
    {
        $a = SplitAmount::from('100.12345678');
        $this->assertEquals(100, $a->whole);
        $this->assertEquals(12345678, $a->frac);
    }

    public function testFromArrayWithWholeAndFrac(): void
    {
        $a = SplitAmount::from(['whole' => 42, 'frac' => 50000000]);
        $this->assertEquals(42, $a->whole);
        $this->assertEquals(50000000, $a->frac);
    }
}
