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
}
