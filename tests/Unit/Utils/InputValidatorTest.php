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
}
