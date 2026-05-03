<?php
/**
 * Unit tests for ContactValidator — bulk validation of the standard
 * contact field set. Asserts the contract that the inlined ladder in
 * ContactController used to enforce: bail on first error, error
 * format "Invalid {label}: {validator-error}", values returned
 * sanitized, missing-from-input fields skipped.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\ContactValidator;

#[CoversClass(ContactValidator::class)]
class ContactValidatorTest extends TestCase
{
    /** Several validators in the ladder (fee, credit) call into bcmath
     *  via InputValidator. Skip those tests cleanly on environments
     *  where the bcmath extension isn't loaded — same pattern used by
     *  ContactManagementServiceTest / PaymentRequestServiceTest. */
    private function requireBcmath(): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('bcmath extension required for fee/credit validators');
        }
    }

    public function testValidContactFieldsReturnsOkWithSanitizedValues(): void
    {
        $this->requireBcmath();
        $result = ContactValidator::validateContactFields([
            'address'  => 'http://peer',
            'name'     => 'Bob',
            'fee'      => '0.5',
            'credit'   => '100',
            'currency' => 'USD',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertNull($result['errorField']);
        $this->assertSame('Bob', $result['values']['name']);
        $this->assertSame('USD', $result['values']['currency']);
    }

    public function testInvalidAddressShortCircuitsAndReturnsErrorFieldAddress(): void
    {
        $result = ContactValidator::validateContactFields([
            'address'  => '',
            'name'     => 'Bob',
            'fee'      => '0.5',
            'credit'   => '100',
            'currency' => 'USD',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('address', $result['errorField']);
        $this->assertStringStartsWith('Invalid address: ', $result['error']);
    }

    public function testInvalidNameAfterValidAddressReturnsErrorFieldName(): void
    {
        $result = ContactValidator::validateContactFields([
            'address'  => 'http://peer',
            'name'     => '', // empty fails
            'fee'      => '0.5',
            'credit'   => '100',
            'currency' => 'USD',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('name', $result['errorField']);
        $this->assertStringStartsWith('Invalid contact name: ', $result['error']);
    }

    public function testInvalidFeeReportsFeeLabel(): void
    {
        $result = ContactValidator::validateContactFields([
            'address'  => 'http://peer',
            'name'     => 'Bob',
            'fee'      => 'not-a-number',
            'credit'   => '100',
            'currency' => 'USD',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('fee', $result['errorField']);
        $this->assertStringStartsWith('Invalid fee: ', $result['error']);
    }

    public function testInvalidCreditReportsCreditLimitLabel(): void
    {
        $this->requireBcmath();
        $result = ContactValidator::validateContactFields([
            'address'  => 'http://peer',
            'name'     => 'Bob',
            'fee'      => '0.5',
            'credit'   => '-1',
            'currency' => 'USD',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('credit', $result['errorField']);
        $this->assertStringStartsWith('Invalid credit limit: ', $result['error']);
    }

    public function testInvalidCurrencyReportsCurrencyLabel(): void
    {
        $this->requireBcmath();
        $result = ContactValidator::validateContactFields([
            'address'  => 'http://peer',
            'name'     => 'Bob',
            'fee'      => '0.5',
            'credit'   => '100',
            'currency' => 'NOT-A-VALID-CURRENCY-CODE',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('currency', $result['errorField']);
        $this->assertStringStartsWith('Invalid currency: ', $result['error']);
    }

    public function testMissingFieldsAreSkippedNotRejected(): void
    {
        // Address-only validation (used by delete/block/unblock). Other
        // fields not present in the input — validator MUST skip them
        // rather than treat absence as failure.
        $result = ContactValidator::validateContactFields([
            'address' => 'http://peer',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['values']);
        $this->assertArrayHasKey('address', $result['values']);
    }

    public function testValidateAddressOnlyConvenience(): void
    {
        $ok = ContactValidator::validateAddressOnly('http://peer');
        $this->assertTrue($ok['ok']);
        $this->assertSame('http://peer', $ok['values']['address']);

        $bad = ContactValidator::validateAddressOnly('');
        $this->assertFalse($bad['ok']);
        $this->assertSame('address', $bad['errorField']);
    }

    public function testFieldOrderHonoredForFirstFailureWins(): void
    {
        // address is invalid AND name is invalid — address must
        // surface as the failure (it's first in the ladder), name
        // never gets validated.
        $result = ContactValidator::validateContactFields([
            'address'  => '',
            'name'     => '',
            'fee'      => 'not-a-number',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('address', $result['errorField']);
    }
}
