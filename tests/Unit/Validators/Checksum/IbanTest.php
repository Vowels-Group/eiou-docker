<?php
namespace Eiou\Tests\Validators\Checksum;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Validators\Checksum\Iban;

#[CoversClass(Iban::class)]
class IbanTest extends TestCase
{
    /**
     * Public test vectors from the SWIFT / ECBS IBAN registry.
     * Each example is a canonical valid IBAN for its country.
     */
    public static function validIbans(): array
    {
        return [
            ['DE89370400440532013000'],           // Germany
            ['GB82WEST12345698765432'],           // UK
            ['FR1420041010050500013M02606'],      // France
            ['ES9121000418450200051332'],         // Spain
            ['IT60X0542811101000000123456'],      // Italy
            ['NL91ABNA0417164300'],               // Netherlands
            ['CH9300762011623852957'],            // Switzerland
            ['BE68539007547034'],                 // Belgium
            ['AT611904300234573201'],             // Austria
            ['PL61109010140000071219812874'],     // Poland
        ];
    }

    #[DataProvider('validIbans')]
    public function testAcceptsValidIban(string $iban): void
    {
        $this->assertTrue(Iban::isValid($iban), "Expected $iban to be valid");
    }

    public function testAcceptsIbanWithSpaces(): void
    {
        $this->assertTrue(Iban::isValid('DE89 3704 0044 0532 0130 00'));
    }

    public function testAcceptsIbanWithDashes(): void
    {
        $this->assertTrue(Iban::isValid('GB82-WEST-1234-5698-7654-32'));
    }

    public function testLowercaseIsAccepted(): void
    {
        $this->assertTrue(Iban::isValid('de89370400440532013000'));
    }

    public function testRejectsBadChecksum(): void
    {
        // Flip one digit of a valid IBAN.
        $this->assertFalse(Iban::isValid('DE89370400440532013001'));
    }

    public function testRejectsUnknownCountry(): void
    {
        $this->assertFalse(Iban::isValid('ZZ89370400440532013000'));
    }

    public function testRejectsWrongLengthForCountry(): void
    {
        // Germany is 22 chars — strip one.
        $this->assertFalse(Iban::isValid('DE8937040044053201300'));
    }

    public function testRejectsEmptyString(): void
    {
        $this->assertFalse(Iban::isValid(''));
    }

    public function testRejectsInvalidCharacters(): void
    {
        $this->assertFalse(Iban::isValid('DE89370400440532013!@#'));
    }

    public function testNormaliseStripsWhitespaceAndUppercases(): void
    {
        $this->assertEquals(
            'DE89370400440532013000',
            Iban::normalise('de89 3704 0044 0532 0130 00')
        );
    }
}
