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
    // =========================================================================
    // validateAmount Tests
    // =========================================================================

    public function testValidateAmountWithValidNumber(): void
    {
        $result = InputValidator::validateAmount(100.50);

        $this->assertTrue($result['valid']);
        $this->assertEquals(100.50, $result['value']);
        $this->assertNull($result['error']);
    }

    public function testValidateAmountWithStringNumber(): void
    {
        $result = InputValidator::validateAmount('250.75');

        $this->assertTrue($result['valid']);
        $this->assertEquals(250.75, $result['value']);
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

    public function testValidateAmountExceedsMaximum(): void
    {
        $result = InputValidator::validateAmount(Constants::TRANSACTION_MAX_AMOUNT + 1);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Amount exceeds maximum allowed value', $result['error']);
    }

    public function testValidateAmountAtMaximum(): void
    {
        $result = InputValidator::validateAmount(Constants::TRANSACTION_MAX_AMOUNT);

        $this->assertTrue($result['valid']);
        $this->assertEquals(Constants::TRANSACTION_MAX_AMOUNT, $result['value']);
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

    public function testValidateAllowedCurrencyRejectsWithoutConversionFactor(): void
    {
        $result = InputValidator::validateAllowedCurrency('EUR');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('No conversion factor defined', $result['error']);
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
    // validateCreditLimit Tests
    // =========================================================================

    public function testValidateCreditLimitWithValid(): void
    {
        $result = InputValidator::validateCreditLimit(1000);

        $this->assertTrue($result['valid']);
        $this->assertEquals(1000, $result['value']);
    }

    public function testValidateCreditLimitWithZero(): void
    {
        $result = InputValidator::validateCreditLimit(0);

        $this->assertTrue($result['valid']);
        $this->assertEquals(0, $result['value']);
    }

    public function testValidateCreditLimitNegative(): void
    {
        $result = InputValidator::validateCreditLimit(-100);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Credit limit cannot be negative', $result['error']);
    }

    public function testValidateCreditLimitExceedsMax(): void
    {
        $result = InputValidator::validateCreditLimit(Constants::TRANSACTION_MAX_AMOUNT + 1);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Credit limit exceeds maximum allowed value', $result['error']);
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
        $result = InputValidator::validateDateFormat('Y-m-d H:i:s');
        $this->assertTrue($result['valid']);
        $this->assertSame('Y-m-d H:i:s', $result['value']);
    }

    public function testValidateDateFormatEmpty(): void
    {
        $result = InputValidator::validateDateFormat('');
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
}
