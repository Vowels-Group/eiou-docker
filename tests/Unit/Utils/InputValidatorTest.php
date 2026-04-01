<?php
/**
 * Unit Tests for InputValidator
 *
 * Tests all validation methods in the InputValidator class.
 * This is a security-critical class that validates all user input.
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\InputValidator;
use Eiou\Core\Constants;

#[CoversClass(InputValidator::class)]
class InputValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('bcmath extension required for InputValidator tests');
        }
    }

    // =========================================================================
    // validateAmount Tests
    // =========================================================================

    public function testValidateAmountWithValidNumber(): void
    {
        $result = InputValidator::validateAmount(100.50);

        $this->assertTrue($result['valid']);
        $this->assertSame('100.50000000', $result['value']);
        $this->assertNull($result['error']);
    }

    public function testValidateAmountWithStringNumber(): void
    {
        $result = InputValidator::validateAmount('250.75');

        $this->assertTrue($result['valid']);
        $this->assertSame('250.75000000', $result['value']);
    }

    public function testValidateAmountWithZero(): void
    {
        $result = InputValidator::validateAmount(0);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['value']);
        $this->assertEquals('Amount must be greater than zero', $result['error']);
    }

    public function testValidateAmountWithNegative(): void
    {
        $result = InputValidator::validateAmount(-50);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Amount must be greater than zero', $result['error']);
    }

    public function testValidateAmountWithNonNumeric(): void
    {
        $result = InputValidator::validateAmount('abc');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Amount must be a number', $result['error']);
    }

    public function testValidateAmountLargeAmountAccepted(): void
    {
        // With split amount storage, very large amounts are accepted
        $result = InputValidator::validateAmount(999999999999.0);

        $this->assertTrue($result['valid']);
    }

    public function testValidateAmountAtReasonableMax(): void
    {
        $result = InputValidator::validateAmount(1000000000);

        $this->assertTrue($result['valid']);
        $this->assertSame('1000000000.00000000', $result['value']);
    }

    public function testValidateAmountExceedsMaxRejected(): void
    {
        // TRANSACTION_MAX_AMOUNT + 1 should be rejected
        $result = InputValidator::validateAmount('2305843009213693952');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('maximum', $result['error']);
    }

    public function testValidateAmountBelowMinimumAfterRounding(): void
    {
        // With 8-decimal internal precision, amounts below 0.00000001 are rejected
        $result = InputValidator::validateAmount(0.000000001, 'USD');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['value']);
        $this->assertStringContainsString('minimum', $result['error']);
    }

    public function testValidateAmountSmallFractionsAccepted(): void
    {
        // 0.004 USD is valid with 8-decimal internal precision
        $result = InputValidator::validateAmount(0.004, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00400000', $result['value']);
    }

    public function testValidateAmountAtMinimum(): void
    {
        // 0.01 USD is a valid amount
        $result = InputValidator::validateAmount(0.01, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.01000000', $result['value']);
    }

    public function testValidateAmountSmallFractionValid(): void
    {
        // 0.005 USD is valid — no rounding to 2 decimals
        $result = InputValidator::validateAmount(0.005, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00500000', $result['value']);
    }

    public function testValidateAmountErrorIncludesCurrencyCode(): void
    {
        // Use a truly sub-minimum value (rounds to 0 at 8 decimal places)
        $result = InputValidator::validateAmount(0.000000001, 'USD');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('USD', $result['error']);
    }

    public function testValidateAmountSmallStringInputValid(): void
    {
        // String "0.001" is valid with 8-decimal precision
        $result = InputValidator::validateAmount('0.001', 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00100000', $result['value']);
    }

    public function testValidateAmountSmallFractionBoundary(): void
    {
        // 0.0049 is valid with 8-decimal precision
        $result = InputValidator::validateAmount(0.0049, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00490000', $result['value']);
    }

    public function testValidateAmountPreservesDecimalPrecision(): void
    {
        // 100.555 preserves full precision at 8 decimal places
        $result = InputValidator::validateAmount(100.555, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('100.55500000', $result['value']);
    }

    public function testValidateAmountSmallValidAmount(): void
    {
        // 0.014 is valid — preserved at full precision
        $result = InputValidator::validateAmount(0.014, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.01400000', $result['value']);
    }

    // =========================================================================
    // validateCurrency Tests
    // =========================================================================

    public function testValidateCurrencyWithUSD(): void
    {
        $result = InputValidator::validateCurrency('USD');

        $this->assertTrue($result['valid']);
        $this->assertEquals('USD', $result['value']);
    }

    public function testValidateCurrencyWithLowercase(): void
    {
        $result = InputValidator::validateCurrency('usd');

        $this->assertTrue($result['valid']);
        $this->assertEquals('USD', $result['value']);
    }

    public function testValidateCurrencyWithWhitespace(): void
    {
        $result = InputValidator::validateCurrency('  USD  ');

        $this->assertTrue($result['valid']);
        $this->assertEquals('USD', $result['value']);
    }

    public function testValidateCurrencyWithUnsupported(): void
    {
        $result = InputValidator::validateCurrency('EUR');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Unsupported currency code', $result['error']);
    }

    public function testValidateCurrencyWithInvalidLength(): void
    {
        $result = InputValidator::validateCurrency('US');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('characters', $result['error']);
    }

    public function testValidateCurrencyWithCustomAllowedList(): void
    {
        $result = InputValidator::validateCurrency('EUR', ['USD', 'EUR']);

        $this->assertTrue($result['valid']);
        $this->assertEquals('EUR', $result['value']);
    }

    public function testValidateCurrencyRejectsWhenNotInCustomList(): void
    {
        $result = InputValidator::validateCurrency('USD', ['EUR']);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Unsupported currency code', $result['error']);
    }

    // =========================================================================
    // validateAllowedCurrency Tests
    // =========================================================================

    public function testValidateAllowedCurrencyAcceptsWithConversionFactor(): void
    {
        $result = InputValidator::validateAllowedCurrency('USD');

        $this->assertTrue($result['valid']);
        $this->assertEquals('USD', $result['value']);
    }

    public function testValidateAllowedCurrencyAcceptsWithoutDisplayDecimals(): void
    {
        // Currencies without explicit display decimals default to INTERNAL_PRECISION (8)
        $result = InputValidator::validateAllowedCurrency('EUR');

        $this->assertTrue($result['valid']);
        $this->assertEquals('EUR', $result['value']);
    }

    public function testValidateAllowedCurrencyRejectsInvalidLength(): void
    {
        $result = InputValidator::validateAllowedCurrency('US');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('characters', $result['error']);
    }

    // =========================================================================
    // Currency Code Length and Format Tests
    // =========================================================================

    public function testValidateCurrencyAccepts3CharCode(): void
    {
        $result = InputValidator::validateCurrency('USD', ['USD']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('USD', $result['value']);
    }

    public function testValidateCurrencyAcceptsLongerCode(): void
    {
        $result = InputValidator::validateCurrency('EIOU', ['EIOU']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('EIOU', $result['value']);
    }

    public function testValidateCurrencyAccepts9CharCode(): void
    {
        $result = InputValidator::validateCurrency('ABCDEFGHI', ['ABCDEFGHI']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('ABCDEFGHI', $result['value']);
    }

    public function testValidateCurrencyRejects10CharCode(): void
    {
        $result = InputValidator::validateCurrency('ABCDEFGHIJ', ['ABCDEFGHIJ']);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('between', $result['error']);
    }

    public function testValidateCurrencyRejects2CharCode(): void
    {
        $result = InputValidator::validateCurrency('AB', ['AB']);
        $this->assertFalse($result['valid']);
    }

    public function testValidateCurrencyAcceptsAlphanumeric(): void
    {
        $result = InputValidator::validateCurrency('US1', ['US1']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('US1', $result['value']);
    }

    public function testValidateCurrencyUppercasesInput(): void
    {
        $result = InputValidator::validateCurrency('eiou', ['EIOU']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('EIOU', $result['value']);
    }

    public function testValidateCurrencyRejectsSpecialChars(): void
    {
        $result = InputValidator::validateCurrency('US$', ['US$']);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('uppercase letters', $result['error']);
    }

    public function testValidateCurrencyRejectsSpacesInCode(): void
    {
        $result = InputValidator::validateCurrency('U S D', ['U S D']);
        $this->assertFalse($result['valid']);
    }

    // =========================================================================
    // validateAddress Tests
    // =========================================================================

    public function testValidateAddressWithHttp(): void
    {
        $result = InputValidator::validateAddress('http://example.com');

        $this->assertTrue($result['valid']);
        $this->assertEquals('http://example.com', $result['value']);
        $this->assertEquals('http', $result['type']);
    }

    public function testValidateAddressWithHttps(): void
    {
        $result = InputValidator::validateAddress('https://secure.example.com');

        $this->assertTrue($result['valid']);
        $this->assertEquals('https', $result['type']);
    }

    public function testValidateAddressWithTorV3(): void
    {
        // Valid Tor v3 address (56 base32 characters + .onion)
        $torAddress = str_repeat('a', 56) . '.onion';
        $result = InputValidator::validateAddress($torAddress);

        $this->assertTrue($result['valid']);
        $this->assertEquals('tor', $result['type']);
    }

    public function testValidateAddressWithTorV2(): void
    {
        // Valid Tor v2 address (16 base32 characters + .onion)
        $torAddress = str_repeat('a', 16) . '.onion';
        $result = InputValidator::validateAddress($torAddress);

        $this->assertTrue($result['valid']);
        $this->assertEquals('tor', $result['type']);
    }

    public function testValidateAddressWithEmpty(): void
    {
        $result = InputValidator::validateAddress('');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Address cannot be empty', $result['error']);
    }

    public function testValidateAddressWithInvalid(): void
    {
        $result = InputValidator::validateAddress('not-a-valid-address');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid address format', $result['error']);
    }

    // =========================================================================
    // validateTxid Tests
    // =========================================================================

    public function testValidateTxidWithValidHash(): void
    {
        $validTxid = str_repeat('a', 64);
        $result = InputValidator::validateTxid($validTxid);

        $this->assertTrue($result['valid']);
        $this->assertEquals($validTxid, $result['value']);
    }

    public function testValidateTxidConvertsToLowercase(): void
    {
        $upperTxid = str_repeat('A', 64);
        $result = InputValidator::validateTxid($upperTxid);

        $this->assertTrue($result['valid']);
        $this->assertEquals(strtolower($upperTxid), $result['value']);
    }

    public function testValidateTxidWithWrongLength(): void
    {
        $shortTxid = str_repeat('a', 32);
        $result = InputValidator::validateTxid($shortTxid);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid transaction ID length', $result['error']);
    }

    public function testValidateTxidWithNonHex(): void
    {
        $invalidTxid = str_repeat('g', 64); // 'g' is not hex
        $result = InputValidator::validateTxid($invalidTxid);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Transaction ID must be hexadecimal', $result['error']);
    }

    public function testValidateTxidWithEmpty(): void
    {
        $result = InputValidator::validateTxid('');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Transaction ID cannot be empty', $result['error']);
    }

    // =========================================================================
    // validateContactName Tests
    // =========================================================================

    public function testValidateContactNameWithValid(): void
    {
        $result = InputValidator::validateContactName('Alice');

        $this->assertTrue($result['valid']);
        $this->assertEquals('Alice', $result['value']);
    }

    public function testValidateContactNameWithSpaces(): void
    {
        $result = InputValidator::validateContactName('Alice Bob');

        $this->assertTrue($result['valid']);
        $this->assertEquals('Alice Bob', $result['value']);
    }

    public function testValidateContactNameWithDashesAndUnderscores(): void
    {
        $result = InputValidator::validateContactName('Alice-Bob_123');

        $this->assertTrue($result['valid']);
    }

    public function testValidateContactNameTrimsWhitespace(): void
    {
        $result = InputValidator::validateContactName('  Alice  ');

        $this->assertTrue($result['valid']);
        $this->assertEquals('Alice', $result['value']);
    }

    public function testValidateContactNameWithInvalidCharacters(): void
    {
        $result = InputValidator::validateContactName('Alice@Bob!');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Contact name contains invalid characters', $result['error']);
    }

    public function testValidateContactNameTooShort(): void
    {
        $result = InputValidator::validateContactName('A');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('between', $result['error']);
    }

    public function testValidateContactNameEmpty(): void
    {
        $result = InputValidator::validateContactName('');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Contact name cannot be empty', $result['error']);
    }

    // =========================================================================
    // validateFeePercent Tests
    // =========================================================================

    public function testValidateFeePercentWithValid(): void
    {
        $result = InputValidator::validateFeePercent(1.5);

        $this->assertTrue($result['valid']);
        $this->assertEquals(1.5, $result['value']);
    }

    public function testValidateFeePercentWithZero(): void
    {
        $result = InputValidator::validateFeePercent(0);

        $this->assertTrue($result['valid']);
        $this->assertEquals(0, $result['value']);
    }

    public function testValidateFeePercentWithMax(): void
    {
        $result = InputValidator::validateFeePercent(100);

        $this->assertTrue($result['valid']);
        $this->assertEquals(100, $result['value']);
    }

    public function testValidateFeePercentAboveMax(): void
    {
        $result = InputValidator::validateFeePercent(101);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('between', $result['error']);
    }

    public function testValidateFeePercentNegative(): void
    {
        $result = InputValidator::validateFeePercent(-1);

        $this->assertFalse($result['valid']);
    }

    public function testValidateFeePercentNonNumeric(): void
    {
        $result = InputValidator::validateFeePercent('abc');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Fee must be a number', $result['error']);
    }

    // =========================================================================
    // validateFeeAmount Tests
    // =========================================================================

    public function testValidateFeeAmountWithPositive(): void
    {
        $result = InputValidator::validateFeeAmount(0.05);

        $this->assertTrue($result['valid']);
        $this->assertSame('0.05000000', $result['value']);
    }

    public function testValidateFeeAmountWithZero(): void
    {
        $result = InputValidator::validateFeeAmount(0);

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00000000', $result['value']);
    }

    public function testValidateFeeAmountWithZeroString(): void
    {
        $result = InputValidator::validateFeeAmount('0');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00000000', $result['value']);
    }

    public function testValidateFeeAmountWithNegative(): void
    {
        $result = InputValidator::validateFeeAmount(-0.01);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('negative', $result['error']);
    }

    public function testValidateFeeAmountWithNonNumeric(): void
    {
        $result = InputValidator::validateFeeAmount('abc');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('number', $result['error']);
    }

    public function testValidateFeeAmountPreservesPrecision(): void
    {
        $result = InputValidator::validateFeeAmount(0.005, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00500000', $result['value']); // preserved at 8 decimal precision
    }

    // =========================================================================
    // validateAmountFee Precision Tests
    // =========================================================================

    public function testValidateAmountFeeBelowMinimumAfterRounding(): void
    {
        // With 8-decimal precision, amounts below 0.00000001 are rejected
        $result = InputValidator::validateAmountFee(0.000000001, 'USD');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['value']);
        $this->assertStringContainsString('minimum', $result['error']);
    }

    public function testValidateAmountFeeAtMinimum(): void
    {
        $result = InputValidator::validateAmountFee(0.01, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.01000000', $result['value']);
    }

    public function testValidateAmountFeeErrorIncludesCurrencyCode(): void
    {
        $result = InputValidator::validateAmountFee(0.000000001, 'USD');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('USD', $result['error']);
    }

    public function testValidateAmountFeeSmallFractionValid(): void
    {
        // 0.005 USD is valid with 8-decimal precision
        $result = InputValidator::validateAmountFee(0.005, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00500000', $result['value']);
    }

    public function testValidateAmountFeeSmallStringValid(): void
    {
        // "0.004" is valid with 8-decimal precision
        $result = InputValidator::validateAmountFee('0.004', 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00400000', $result['value']);
    }

    public function testValidateAmountFeePreservesPrecision(): void
    {
        // 5.999 preserved at 8-decimal precision
        $result = InputValidator::validateAmountFee(5.999, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('5.99900000', $result['value']);
    }

    // =========================================================================
    // validateCreditLimit Tests
    // =========================================================================

    public function testValidateCreditLimitWithValid(): void
    {
        $result = InputValidator::validateCreditLimit(1000);

        $this->assertTrue($result['valid']);
        // Returns bcmath string with 8 decimal places
        $this->assertSame('1000.00000000', $result['value']);
    }

    public function testValidateCreditLimitWithZero(): void
    {
        $result = InputValidator::validateCreditLimit(0);

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00000000', $result['value']);
    }

    public function testValidateCreditLimitNegative(): void
    {
        $result = InputValidator::validateCreditLimit(-100);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Credit limit cannot be negative', $result['error']);
    }

    public function testValidateCreditLimitLargeAmountAccepted(): void
    {
        // With split amount storage, large credit limits are accepted
        $result = InputValidator::validateCreditLimit(999999999999.0);

        $this->assertTrue($result['valid']);
    }

    public function testValidateCreditLimitPreservesSmallFraction(): void
    {
        // 0.001 preserves precision with 8 decimal places
        $result = InputValidator::validateCreditLimit(0.001, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00100000', $result['value']);
    }

    public function testValidateFeeAmountSmallFractionPreserved(): void
    {
        // 0.001 preserved with 8-decimal precision
        $result = InputValidator::validateFeeAmount(0.001, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('0.00100000', $result['value']);
    }

    public function testValidateCreditLimitPreservesPrecision(): void
    {
        // 100.555 preserved at 8 decimal places
        $result = InputValidator::validateCreditLimit(100.555, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('100.55500000', $result['value']);
    }

    public function testValidateCreditLimitNonNumeric(): void
    {
        $result = InputValidator::validateCreditLimit('abc');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Credit limit must be a number', $result['error']);
    }

    public function testValidateCreditLimitStringInput(): void
    {
        $result = InputValidator::validateCreditLimit('500.00', 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('500.00000000', $result['value']);
    }

    public function testValidateCreditLimitPhpIntMax(): void
    {
        // PHP_INT_MAX should be preserved without precision loss
        $result = InputValidator::validateCreditLimit((string) PHP_INT_MAX, 'USD');

        $this->assertTrue($result['valid']);
        $this->assertSame('9223372036854775807.00000000', $result['value']);
    }

    // =========================================================================
    // validatePublicKey Tests
    // =========================================================================

    public function testValidatePublicKeyWithValid(): void
    {
        $validKey = "-----BEGIN PUBLIC KEY-----\n" . str_repeat('A', 200) . "\n-----END PUBLIC KEY-----";
        $result = InputValidator::validatePublicKey($validKey);

        $this->assertTrue($result['valid']);
        $this->assertEquals($validKey, $result['value']);
    }

    public function testValidatePublicKeyWithEmpty(): void
    {
        $result = InputValidator::validatePublicKey('');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Public key cannot be empty', $result['error']);
    }

    public function testValidatePublicKeyTooShort(): void
    {
        $shortKey = "-----BEGIN PUBLIC KEY-----\nshort\n-----END PUBLIC KEY-----";
        $result = InputValidator::validatePublicKey($shortKey);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Public key is too short', $result['error']);
    }

    public function testValidatePublicKeyInvalidFormat(): void
    {
        $invalidKey = str_repeat('A', 200);
        $result = InputValidator::validatePublicKey($invalidKey);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid public key format', $result['error']);
    }

    // =========================================================================
    // validateSignature Tests
    // =========================================================================

    public function testValidateSignatureWithValid(): void
    {
        $validSignature = str_repeat('A', 150) . '==';
        $result = InputValidator::validateSignature($validSignature);

        $this->assertTrue($result['valid']);
        $this->assertEquals($validSignature, $result['value']);
    }

    public function testValidateSignatureWithEmpty(): void
    {
        $result = InputValidator::validateSignature('');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Signature cannot be empty', $result['error']);
    }

    public function testValidateSignatureTooShort(): void
    {
        $shortSignature = 'ABC==';
        $result = InputValidator::validateSignature($shortSignature);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Signature is too short', $result['error']);
    }

    public function testValidateSignatureInvalidCharacters(): void
    {
        $invalidSignature = str_repeat('!', 150);
        $result = InputValidator::validateSignature($invalidSignature);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid signature format', $result['error']);
    }

    // =========================================================================
    // validatePositiveInteger Tests
    // =========================================================================

    public function testValidatePositiveIntegerWithValid(): void
    {
        $result = InputValidator::validatePositiveInteger(5);

        $this->assertTrue($result['valid']);
        $this->assertEquals(5, $result['value']);
    }

    public function testValidatePositiveIntegerWithOne(): void
    {
        $result = InputValidator::validatePositiveInteger(1);

        $this->assertTrue($result['valid']);
        $this->assertEquals(1, $result['value']);
    }

    public function testValidatePositiveIntegerBelowMin(): void
    {
        $result = InputValidator::validatePositiveInteger(0);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Value must be at least 1', $result['error']);
    }

    public function testValidatePositiveIntegerWithCustomMin(): void
    {
        $result = InputValidator::validatePositiveInteger(5, 10);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Value must be at least 10', $result['error']);
    }

    public function testValidatePositiveIntegerNonNumeric(): void
    {
        $result = InputValidator::validatePositiveInteger('abc');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Value must be numeric', $result['error']);
    }

    // =========================================================================
    // validateMemo Tests
    // =========================================================================

    public function testValidateMemoWithValid(): void
    {
        $result = InputValidator::validateMemo('Payment for services');

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['value']);
    }

    public function testValidateMemoWithEmpty(): void
    {
        $result = InputValidator::validateMemo('');

        $this->assertTrue($result['valid']);
        $this->assertEquals('', $result['value']);
    }

    public function testValidateMemoExceedsMaxLength(): void
    {
        $longMemo = str_repeat('a', Constants::VALIDATION_MEMO_MAX_LENGTH + 1);
        $result = InputValidator::validateMemo($longMemo);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds maximum length', $result['error']);
    }

    public function testValidateMemoWithCustomMaxLength(): void
    {
        $result = InputValidator::validateMemo('This is too long', 5);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('5 characters', $result['error']);
    }

    // =========================================================================
    // validateTransactionRequest Tests
    // =========================================================================

    public function testValidateTransactionRequestWithValidData(): void
    {
        $validKey = "-----BEGIN PUBLIC KEY-----\n" . str_repeat('A', 200) . "\n-----END PUBLIC KEY-----";
        $validSignature = str_repeat('B', 150) . '==';

        $request = [
            'senderAddress' => 'http://sender.example.com',
            'receiverAddress' => 'http://receiver.example.com',
            'amount' => 100,
            'currency' => 'USD',
            'senderPublicKey' => $validKey,
            'receiverPublicKey' => $validKey,
            'signature' => $validSignature,
        ];

        $result = InputValidator::validateTransactionRequest($request);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertNotNull($result['sanitized']);
    }

    public function testValidateTransactionRequestMissingFields(): void
    {
        $request = [];

        $result = InputValidator::validateTransactionRequest($request);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('senderAddress', $result['errors']);
        $this->assertArrayHasKey('receiverAddress', $result['errors']);
        $this->assertArrayHasKey('amount', $result['errors']);
        $this->assertArrayHasKey('senderPublicKey', $result['errors']);
        $this->assertArrayHasKey('receiverPublicKey', $result['errors']);
        $this->assertArrayHasKey('signature', $result['errors']);
    }

    public function testValidateTransactionRequestPartiallyInvalid(): void
    {
        $validKey = "-----BEGIN PUBLIC KEY-----\n" . str_repeat('A', 200) . "\n-----END PUBLIC KEY-----";
        $validSignature = str_repeat('B', 150) . '==';

        $request = [
            'senderAddress' => 'http://sender.example.com',
            'receiverAddress' => 'invalid-address', // Invalid
            'amount' => -100, // Invalid
            'currency' => 'USD',
            'senderPublicKey' => $validKey,
            'receiverPublicKey' => $validKey,
            'signature' => $validSignature,
        ];

        $result = InputValidator::validateTransactionRequest($request);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('receiverAddress', $result['errors']);
        $this->assertArrayHasKey('amount', $result['errors']);
        $this->assertNull($result['sanitized']);
    }

    // =========================================================================
    // validateArgvAmount Tests
    // =========================================================================

    public function testValidateArgvAmountWithSufficientArgs(): void
    {
        $argv = ['script.php', 'command', 'arg1', 'arg2'];
        $result = InputValidator::validateArgvAmount($argv, 4);

        $this->assertTrue($result['valid']);
    }

    public function testValidateArgvAmountWithInsufficientArgs(): void
    {
        $argv = ['script.php', 'command'];
        $result = InputValidator::validateArgvAmount($argv, 4);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('4 parameters', $result['error']);
    }

    // =========================================================================
    // validateLogLevel Tests
    // =========================================================================

    public function testValidateLogLevelValid(): void
    {
        foreach (['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'] as $level) {
            $result = InputValidator::validateLogLevel($level);
            $this->assertTrue($result['valid'], "Level $level should be valid");
            $this->assertSame($level, $result['value']);
        }
    }

    public function testValidateLogLevelCaseInsensitive(): void
    {
        $result = InputValidator::validateLogLevel('debug');
        $this->assertTrue($result['valid']);
        $this->assertSame('DEBUG', $result['value']);
    }

    public function testValidateLogLevelInvalid(): void
    {
        $result = InputValidator::validateLogLevel('TRACE');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be one of', $result['error']);
    }

    // =========================================================================
    // validateIntRange Tests
    // =========================================================================

    public function testValidateIntRangeValid(): void
    {
        $result = InputValidator::validateIntRange(5, 0, 10);
        $this->assertTrue($result['valid']);
        $this->assertSame(5, $result['value']);
    }

    public function testValidateIntRangeBelowMin(): void
    {
        $result = InputValidator::validateIntRange(-1, 0, 10, 'TestVal');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('between 0 and 10', $result['error']);
    }

    public function testValidateIntRangeAboveMax(): void
    {
        $result = InputValidator::validateIntRange(11, 0, 10, 'TestVal');
        $this->assertFalse($result['valid']);
    }

    public function testValidateIntRangeNonNumeric(): void
    {
        $result = InputValidator::validateIntRange('abc', 0, 10);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('numeric', $result['error']);
    }

    // =========================================================================
    // validateDateFormat Tests
    // =========================================================================

    public function testValidateDateFormatValid(): void
    {
        foreach (Constants::VALID_DATE_FORMATS as $fmt) {
            $result = InputValidator::validateDateFormat($fmt);
            $this->assertTrue($result['valid'], "Format '{$fmt}' should be valid");
            $this->assertSame($fmt, $result['value']);
        }
    }

    public function testValidateDateFormatEmpty(): void
    {
        $result = InputValidator::validateDateFormat('');
        $this->assertFalse($result['valid']);
    }

    public function testValidateDateFormatRejectsArbitrary(): void
    {
        $result = InputValidator::validateDateFormat('l, F j, Y g:i A');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid date format', $result['error']);
    }

    public function testValidateDateFormatRejectsNonsense(): void
    {
        $result = InputValidator::validateDateFormat('not-a-date-format');
        $this->assertFalse($result['valid']);
    }

    // =========================================================================
    // validateBoolean Tests
    // =========================================================================

    public function testValidateBooleanTrue(): void
    {
        foreach (['true', '1', 'on', 'yes', 'TRUE', 'Yes'] as $val) {
            $result = InputValidator::validateBoolean($val);
            $this->assertTrue($result['valid'], "Value '$val' should be valid");
            $this->assertTrue($result['value'], "Value '$val' should be true");
        }
    }

    public function testValidateBooleanFalse(): void
    {
        foreach (['false', '0', 'off', 'no', 'FALSE', 'No'] as $val) {
            $result = InputValidator::validateBoolean($val);
            $this->assertTrue($result['valid'], "Value '$val' should be valid");
            $this->assertFalse($result['value'], "Value '$val' should be false");
        }
    }

    public function testValidateBooleanInvalid(): void
    {
        $result = InputValidator::validateBoolean('maybe');
        $this->assertFalse($result['valid']);
    }

    public function testValidateBooleanNativeBool(): void
    {
        $result = InputValidator::validateBoolean(true);
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['value']);
    }

    // =========================================================================
    // End-to-end: validate → SplitAmount pipeline (requires bcmath)
    // =========================================================================

    public function testValidateAmountToSplitAmountPipeline(): void
    {
        $cases = [
            ['128.001',      128, 100000],
            ['0.1',          0,   10000000],
            ['50.50',        50,  50000000],
            ['100',          100, 0],
            ['0.00000001',   0,   1],
            ['999.99999999', 999, 99999999],
            ['128.99999999', 128, 99999999],
        ];

        foreach ($cases as [$input, $expectedWhole, $expectedFrac]) {
            $result = InputValidator::validateAmount($input, 'USD');
            $this->assertTrue($result['valid'], "Input {$input} should be valid");
            $this->assertIsString($result['value'], "Input {$input} should return string");

            $sa = \Eiou\Core\SplitAmount::from($result['value']);
            $this->assertSame($expectedWhole, $sa->whole, "Input {$input}: wrong whole");
            $this->assertSame($expectedFrac, $sa->frac, "Input {$input}: wrong frac");
        }
    }

    public function testValidateCreditLimitToSplitAmountPipeline(): void
    {
        // PHP_INT_MAX credit limit → SplitAmount preserves full precision
        $result = InputValidator::validateCreditLimit((string) PHP_INT_MAX, 'USD');
        $this->assertTrue($result['valid']);

        $sa = \Eiou\Core\SplitAmount::from($result['value']);
        $this->assertSame(PHP_INT_MAX, $sa->whole);
        $this->assertSame(0, $sa->frac);
    }

    public function testValidateAmountMaxTransactionAmount(): void
    {
        // Exactly at TRANSACTION_MAX_AMOUNT — accepted
        $maxStr = (string) Constants::TRANSACTION_MAX_AMOUNT;
        $result = InputValidator::validateAmount($maxStr, 'USD');
        $this->assertTrue($result['valid']);

        $sa = \Eiou\Core\SplitAmount::from($result['value']);
        $this->assertSame(Constants::TRANSACTION_MAX_AMOUNT, $sa->whole);
        $this->assertSame(0, $sa->frac);

        // One above — rejected
        $overMax = \bcadd($maxStr, '1', 0);
        $result = InputValidator::validateAmount($overMax, 'USD');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('maximum', $result['error']);
    }

    // =========================================================================
    // Amount edge cases: scientific notation
    // =========================================================================

    public function testValidateAmountScientificNotationAccepted(): void
    {
        // is_numeric('1e5') returns true, bcadd normalises to 100000
        $result = InputValidator::validateAmount('1e5', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('100000.00000000', $result['value']);
    }

    public function testValidateAmountScientificNotationSmall(): void
    {
        // 1.5e2 = 150
        $result = InputValidator::validateAmount('1.5e2', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('150.00000000', $result['value']);
    }

    public function testValidateAmountScientificNotationNegativeExponent(): void
    {
        // 1.5e-2 = 0.015
        $result = InputValidator::validateAmount('1.5e-2', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('0.01500000', $result['value']);
    }

    public function testValidateAmountScientificNotationExceedsMax(): void
    {
        // 1e20 far exceeds TRANSACTION_MAX_AMOUNT
        $result = InputValidator::validateAmount('1e20', 'USD');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('maximum', $result['error']);
    }

    public function testValidateAmountScientificNotationTinyBelowMinimum(): void
    {
        // 1e-10 = 0.0000000001 → truncated to 0.00000000 at 8 decimals → rejected
        $result = InputValidator::validateAmount('1e-10', 'USD');
        $this->assertFalse($result['valid']);
    }

    // =========================================================================
    // Amount edge cases: leading zeros, trailing zeros
    // =========================================================================

    public function testValidateAmountLeadingZeros(): void
    {
        $result = InputValidator::validateAmount('00100.50', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('100.50000000', $result['value']);
    }

    public function testValidateAmountTrailingZerosBeyondPrecision(): void
    {
        $result = InputValidator::validateAmount('100.5000000000000000', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('100.50000000', $result['value']);
    }

    // =========================================================================
    // Amount edge cases: boundary values
    // =========================================================================

    public function testValidateAmountJustBelowMax(): void
    {
        // MAX_AMOUNT - 1 should be accepted
        $justBelowMax = \bcsub((string) Constants::TRANSACTION_MAX_AMOUNT, '1', 0);
        $result = InputValidator::validateAmount($justBelowMax, 'USD');
        $this->assertTrue($result['valid']);
    }

    public function testValidateAmountMaxWithFraction(): void
    {
        // MAX_AMOUNT + 0.50 — whole part exceeds max
        $maxWithFrac = \bcadd((string) Constants::TRANSACTION_MAX_AMOUNT, '0.50', 8);
        $result = InputValidator::validateAmount($maxWithFrac, 'USD');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('maximum', $result['error']);
    }

    public function testValidateAmountSmallestPossible(): void
    {
        // 0.00000001 — the smallest representable amount at 8 decimal precision
        $result = InputValidator::validateAmount('0.00000001', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('0.00000001', $result['value']);

        $sa = \Eiou\Core\SplitAmount::from($result['value']);
        $this->assertSame(0, $sa->whole);
        $this->assertSame(1, $sa->frac);
    }

    public function testValidateAmountBelowSmallestRejected(): void
    {
        // 0.000000001 — 9 decimal places, truncated to 0.00000000 → rejected
        $result = InputValidator::validateAmount('0.000000001', 'USD');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('minimum', $result['error']);
    }

    public function testValidateAmountPrecisionBoundary9Decimals(): void
    {
        // 0.000000009 → truncated to 0.00000000 → rejected (below minimum)
        $result = InputValidator::validateAmount('0.000000009', 'USD');
        $this->assertFalse($result['valid']);
    }

    // =========================================================================
    // Amount edge cases: decimal truncation
    // =========================================================================

    public function testValidateAmountTruncatesNotRounds(): void
    {
        // 100.123456789 → truncated to 100.12345678 (not 100.12345679)
        $result = InputValidator::validateAmount('100.123456789', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('100.12345678', $result['value']);
    }

    public function testValidateAmountMaxFracValue(): void
    {
        // 0.99999999 — maximum fractional value at 8 decimals
        $result = InputValidator::validateAmount('0.99999999', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('0.99999999', $result['value']);

        $sa = \Eiou\Core\SplitAmount::from($result['value']);
        $this->assertSame(0, $sa->whole);
        $this->assertSame(99999999, $sa->frac);
    }

    public function testValidateAmountLargeWithMaxFrac(): void
    {
        // Test a large whole part combined with max fractional
        $result = InputValidator::validateAmount('999999999.99999999', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('999999999.99999999', $result['value']);
    }

    // =========================================================================
    // Currency validation edge cases
    // =========================================================================

    public function testValidateCurrencyEmptyString(): void
    {
        $result = InputValidator::validateCurrency('');
        $this->assertFalse($result['valid']);
    }

    public function testValidateCurrencyOnlyNumbers(): void
    {
        $result = InputValidator::validateCurrency('123', ['123']);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('uppercase letters', $result['error']);
    }

    // =========================================================================
    // validateFeeAmount edge cases
    // =========================================================================

    public function testValidateFeeAmountSmallNegative(): void
    {
        $result = InputValidator::validateFeeAmount(-0.001);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('negative', $result['error']);
    }

    public function testValidateFeeAmountVerySmall(): void
    {
        // 0.00000001 — smallest representable fee
        $result = InputValidator::validateFeeAmount('0.00000001', 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('0.00000001', $result['value']);
    }

    // =========================================================================
    // SplitAmount as validateAmount input
    // =========================================================================

    public function testValidateAmountAcceptsSplitAmountObject(): void
    {
        $sa = new \Eiou\Core\SplitAmount(50, 25000000); // 50.25
        $result = InputValidator::validateAmount($sa, 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('50.25000000', $result['value']);
    }

    public function testValidateAmountAcceptsWholeFragArray(): void
    {
        $result = InputValidator::validateAmount(['whole' => 100, 'frac' => 50000000], 'USD');
        $this->assertTrue($result['valid']);
        $this->assertSame('100.50000000', $result['value']);
    }

    // =========================================================================
    // checkVersionCompatibility
    // =========================================================================

    public function testVersionCompatibilityReturnsNullForCompatibleVersion(): void
    {
        $result = InputValidator::checkVersionCompatibility('0.1.5-alpha');
        $this->assertNull($result);
    }

    public function testVersionCompatibilityReturnsNullForMinimumVersion(): void
    {
        $result = InputValidator::checkVersionCompatibility('0.1.3-alpha');
        $this->assertNull($result);
    }

    public function testVersionCompatibilityRejectsNullVersion(): void
    {
        $result = InputValidator::checkVersionCompatibility(null);
        $this->assertNotNull($result);
        $this->assertSame('upgrade_remote', $result['action']);
        $this->assertStringContainsString('unknown', $result['reason']);
    }

    public function testVersionCompatibilityRejectsOldVersion(): void
    {
        $result = InputValidator::checkVersionCompatibility('0.1.0-alpha');
        $this->assertNotNull($result);
        $this->assertSame('upgrade_remote', $result['action']);
        $this->assertStringContainsString('0.1.0-alpha', $result['reason']);
    }

    public function testVersionCompatibilityRejectsPreAlphaVersion(): void
    {
        $result = InputValidator::checkVersionCompatibility('0.1.2-alpha');
        $this->assertNotNull($result);
        $this->assertSame('upgrade_remote', $result['action']);
    }

    public function testVersionCompatibilityAcceptsNewerVersion(): void
    {
        $result = InputValidator::checkVersionCompatibility('0.2.0-alpha');
        $this->assertNull($result);
    }

    public function testVersionCompatibilityAcceptsStableVersion(): void
    {
        $result = InputValidator::checkVersionCompatibility('1.0.0');
        $this->assertNull($result);
    }
}
