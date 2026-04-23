<?php
namespace Eiou\Tests\Validators\Checksum;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Validators\Checksum\AbaRouting;

#[CoversClass(AbaRouting::class)]
class AbaRoutingTest extends TestCase
{
    /**
     * Public test vectors — real US bank routing numbers (well-known).
     */
    public static function validNumbers(): array
    {
        return [
            ['011000015'],   // Federal Reserve Bank (Boston)
            ['021000021'],   // JPMorgan Chase, NY
            ['026009593'],   // Bank of America, NY
            ['121000358'],   // Bank of America, CA
            ['122000247'],   // Wells Fargo
            ['063100277'],   // Bank of America FL
        ];
    }

    #[DataProvider('validNumbers')]
    public function testValid(string $rn): void
    {
        $this->assertTrue(AbaRouting::isValid($rn), "Expected $rn to be valid");
    }

    public function testRejectsNonDigits(): void
    {
        $this->assertFalse(AbaRouting::isValid('01100001X'));
    }

    public function testRejectsWrongLength(): void
    {
        $this->assertFalse(AbaRouting::isValid('01100001'));
        $this->assertFalse(AbaRouting::isValid('0110000155'));
    }

    public function testRejectsBadChecksum(): void
    {
        $this->assertFalse(AbaRouting::isValid('011000016'));
    }

    public function testAcceptsWithInternalWhitespaceStripped(): void
    {
        $this->assertTrue(AbaRouting::isValid(' 011000015 '));
    }
}
