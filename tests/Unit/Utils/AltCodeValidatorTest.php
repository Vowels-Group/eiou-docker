<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Tests\Utils;

use Eiou\Utils\AltCodeValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AltCodeValidator::class)]
class AltCodeValidatorTest extends TestCase
{
    public function testValidCandidatePasses(): void
    {
        $r = AltCodeValidator::validate('Tr0ub4dor!correct');
        $this->assertTrue($r['valid'], 'Expected valid candidate to pass; errors: ' . implode(' ', $r['errors']));
        $this->assertSame([], $r['errors']);
    }

    public function testRejectsTooShort(): void
    {
        $r = AltCodeValidator::validate('Aa1!short');
        $this->assertFalse($r['valid']);
        $this->assertNotEmpty($r['errors']);
        $this->assertStringContainsString('at least', implode(' ', $r['errors']));
    }

    public function testRejectsAllLowercase(): void
    {
        $r = AltCodeValidator::validate('alllowercase1!ab');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'uppercase'));
    }

    public function testRejectsAllUppercase(): void
    {
        $r = AltCodeValidator::validate('ALLUPPERCASE1!AB');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'lowercase'));
    }

    public function testRejectsMissingDigit(): void
    {
        $r = AltCodeValidator::validate('NoDigitsHere!abc');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'digit'));
    }

    public function testRejectsMissingSymbol(): void
    {
        $r = AltCodeValidator::validate('NoSymbolsHere123');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'symbol'));
    }

    public function testRejectsTripleRepeatedCharacter(): void
    {
        $r = AltCodeValidator::validate('Goodaaa1!extra');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'repeated'));
    }

    public function testRejectsAscendingSequence(): void
    {
        // Lowercase abcd is a 4-character run with delta +1 (a=97, b=98,
        // c=99, d=100). The capital A in the earlier test version broke
        // the run since A=65, b=98 delta 33 — only "bcd" of length 3.
        $r = AltCodeValidator::validate('Hello!abcd9XYZ');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'sequence'));
    }

    public function testRejectsDescendingSequence(): void
    {
        $r = AltCodeValidator::validate('Wxyz!4321Padding');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'sequence'));
    }

    public function testRejectsCommonPasswordSubstring(): void
    {
        $r = AltCodeValidator::validate('Password1!extra2');
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'commonly'));
    }

    public function testRejectsBrandedCommonPassword(): void
    {
        $r = AltCodeValidator::validate('Eiou!Wallet1234abc');
        $this->assertFalse($r['valid']);
    }

    public function testAcceptsPassphraseWithSpaces(): void
    {
        // Spaces count as symbols and passphrases are explicitly allowed.
        $r = AltCodeValidator::validate('Correct horse 9X');
        $this->assertTrue($r['valid'], implode(' ', $r['errors']));
    }

    public function testRejectsTooLong(): void
    {
        $candidate = str_repeat('Aa1!', 100); // 400 chars > MAX_LENGTH
        $r = AltCodeValidator::validate($candidate);
        $this->assertFalse($r['valid']);
        $this->assertTrue($this->errorsMention($r['errors'], 'at most'));
    }

    public function testMinLengthIsTwelve(): void
    {
        $this->assertSame(12, AltCodeValidator::MIN_LENGTH);
    }

    /**
     * @param string[] $errors
     */
    private function errorsMention(array $errors, string $needle): bool
    {
        foreach ($errors as $e) {
            if (stripos($e, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
