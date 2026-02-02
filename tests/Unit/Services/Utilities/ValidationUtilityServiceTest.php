<?php
/**
 * Unit Tests for ValidationUtilityService
 *
 * Tests validation logic for P2P requests, cryptographic signature verification,
 * and available funds calculation.
 */

namespace Eiou\Tests\Services\Utilities;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\ServiceContainer;
use Eiou\Database\BalanceRepository;

#[CoversClass(ValidationUtilityService::class)]
class ValidationUtilityServiceTest extends TestCase
{
    private ServiceContainer $serviceContainer;
    private BalanceRepository $balanceRepository;
    private ValidationUtilityService $service;

    protected function setUp(): void
    {
        // Create mock objects
        $this->serviceContainer = $this->createMock(ServiceContainer::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);

        // Configure service container to return the mock balance repository
        $this->serviceContainer->expects($this->any())
            ->method('getBalanceRepository')
            ->willReturn($this->balanceRepository);

        // Create the service
        $this->service = new ValidationUtilityService($this->serviceContainer);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor sets up service container dependency
     */
    public function testConstructorSetsServiceContainer(): void
    {
        $serviceContainer = $this->createMock(ServiceContainer::class);

        $service = new ValidationUtilityService($serviceContainer);

        $this->assertInstanceOf(ValidationUtilityService::class, $service);
    }

    // =========================================================================
    // validateRequestLevel Tests
    // =========================================================================

    /**
     * Test validateRequestLevel returns true when request level is less than max
     */
    public function testValidateRequestLevelReturnsTrueWhenLessThanMax(): void
    {
        $request = [
            'requestLevel' => 2,
            'maxRequestLevel' => 5
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertTrue($result);
    }

    /**
     * Test validateRequestLevel returns true when request level equals max
     */
    public function testValidateRequestLevelReturnsTrueWhenEqual(): void
    {
        $request = [
            'requestLevel' => 5,
            'maxRequestLevel' => 5
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertTrue($result);
    }

    /**
     * Test validateRequestLevel returns false when request level exceeds max
     */
    public function testValidateRequestLevelReturnsFalseWhenExceedsMax(): void
    {
        $request = [
            'requestLevel' => 6,
            'maxRequestLevel' => 5
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertFalse($result);
    }

    /**
     * Test validateRequestLevel returns false when requestLevel is missing
     */
    public function testValidateRequestLevelReturnsFalseWhenRequestLevelMissing(): void
    {
        $request = [
            'maxRequestLevel' => 5
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertFalse($result);
    }

    /**
     * Test validateRequestLevel returns false when maxRequestLevel is missing
     */
    public function testValidateRequestLevelReturnsFalseWhenMaxRequestLevelMissing(): void
    {
        $request = [
            'requestLevel' => 2
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertFalse($result);
    }

    /**
     * Test validateRequestLevel returns false when both fields are missing
     */
    public function testValidateRequestLevelReturnsFalseWhenBothFieldsMissing(): void
    {
        $request = [];

        $result = $this->service->validateRequestLevel($request);

        $this->assertFalse($result);
    }

    /**
     * Test validateRequestLevel with zero values
     */
    public function testValidateRequestLevelWithZeroValues(): void
    {
        $request = [
            'requestLevel' => 0,
            'maxRequestLevel' => 0
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertTrue($result);
    }

    /**
     * Test validateRequestLevel with negative values
     */
    public function testValidateRequestLevelWithNegativeValues(): void
    {
        $request = [
            'requestLevel' => -1,
            'maxRequestLevel' => 0
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertTrue($result);
    }

    // =========================================================================
    // verifyRequestSignature Tests
    // =========================================================================

    /**
     * Test verifyRequestSignature returns false when senderPublicKey is missing
     */
    public function testVerifyRequestSignatureReturnsFalseWhenPublicKeyMissing(): void
    {
        $request = [
            'message' => 'test message',
            'signature' => 'signature'
        ];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertFalse($result);
    }

    /**
     * Test verifyRequestSignature returns false when message is missing
     */
    public function testVerifyRequestSignatureReturnsFalseWhenMessageMissing(): void
    {
        $request = [
            'senderPublicKey' => 'public key',
            'signature' => 'signature'
        ];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertFalse($result);
    }

    /**
     * Test verifyRequestSignature returns false when signature is missing
     */
    public function testVerifyRequestSignatureReturnsFalseWhenSignatureMissing(): void
    {
        $request = [
            'senderPublicKey' => 'public key',
            'message' => 'test message'
        ];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertFalse($result);
    }

    /**
     * Test verifyRequestSignature returns false when all fields are missing
     */
    public function testVerifyRequestSignatureReturnsFalseWhenAllFieldsMissing(): void
    {
        $request = [];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertFalse($result);
    }

    /**
     * Test verifyRequestSignature returns false with invalid public key
     */
    public function testVerifyRequestSignatureReturnsFalseWithInvalidPublicKey(): void
    {
        $request = [
            'senderPublicKey' => 'invalid-public-key-format',
            'message' => 'test message',
            'signature' => base64_encode('fake signature')
        ];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertFalse($result);
    }

    /**
     * Test verifyRequestSignature with valid RSA key pair
     */
    public function testVerifyRequestSignatureWithValidKeyPair(): void
    {
        // Generate a valid key pair for testing
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
        ]);

        // Extract public and private keys
        $privateKey = '';
        openssl_pkey_export($keyPair, $privateKey);
        $publicKeyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $publicKeyDetails['key'];

        // Create a message and sign it
        $message = json_encode(['type' => 'test', 'data' => 'test data']);
        $signature = '';
        openssl_sign($message, $signature, $keyPair);

        $request = [
            'senderPublicKey' => $publicKey,
            'message' => $message,
            'signature' => base64_encode($signature)
        ];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertTrue($result);
    }

    /**
     * Test verifyRequestSignature returns false with tampered message
     */
    public function testVerifyRequestSignatureReturnsFalseWithTamperedMessage(): void
    {
        // Generate a valid key pair for testing
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
        ]);

        // Extract public key
        $publicKeyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $publicKeyDetails['key'];

        // Create a message and sign it
        $originalMessage = json_encode(['type' => 'test', 'data' => 'original']);
        $signature = '';
        openssl_sign($originalMessage, $signature, $keyPair);

        // Tamper with the message
        $tamperedMessage = json_encode(['type' => 'test', 'data' => 'tampered']);

        $request = [
            'senderPublicKey' => $publicKey,
            'message' => $tamperedMessage,
            'signature' => base64_encode($signature)
        ];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertFalse($result);
    }

    /**
     * Test verifyRequestSignature returns false with wrong public key
     */
    public function testVerifyRequestSignatureReturnsFalseWithWrongPublicKey(): void
    {
        // Generate two different key pairs
        $keyPair1 = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
        ]);
        $keyPair2 = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
        ]);

        // Use public key from key pair 2
        $publicKeyDetails = openssl_pkey_get_details($keyPair2);
        $wrongPublicKey = $publicKeyDetails['key'];

        // Sign with key pair 1
        $message = json_encode(['type' => 'test']);
        $signature = '';
        openssl_sign($message, $signature, $keyPair1);

        $request = [
            'senderPublicKey' => $wrongPublicKey,
            'message' => $message,
            'signature' => base64_encode($signature)
        ];

        $result = $this->service->verifyRequestSignature($request);

        $this->assertFalse($result);
    }

    // =========================================================================
    // calculateAvailableFunds Tests
    // =========================================================================

    /**
     * Test calculateAvailableFunds with positive balance
     */
    public function testCalculateAvailableFundsWithPositiveBalance(): void
    {
        $request = [
            'senderPublicKey' => 'test-pubkey-123',
            'currency' => 'USD'
        ];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->with('test-pubkey-123', 'USD')
            ->willReturn(10000); // Sent to contact

        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with('test-pubkey-123', 'USD')
            ->willReturn(3000); // Received from contact

        $result = $this->service->calculateAvailableFunds($request);

        // Available funds = sent - received = 10000 - 3000 = 7000
        $this->assertEquals(7000, $result);
    }

    /**
     * Test calculateAvailableFunds with zero balance
     */
    public function testCalculateAvailableFundsWithZeroBalance(): void
    {
        $request = [
            'senderPublicKey' => 'test-pubkey-123',
            'currency' => 'USD'
        ];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->with('test-pubkey-123', 'USD')
            ->willReturn(5000);

        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with('test-pubkey-123', 'USD')
            ->willReturn(5000);

        $result = $this->service->calculateAvailableFunds($request);

        $this->assertEquals(0, $result);
    }

    /**
     * Test calculateAvailableFunds with negative balance
     */
    public function testCalculateAvailableFundsWithNegativeBalance(): void
    {
        $request = [
            'senderPublicKey' => 'test-pubkey-123',
            'currency' => 'USD'
        ];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->with('test-pubkey-123', 'USD')
            ->willReturn(2000);

        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with('test-pubkey-123', 'USD')
            ->willReturn(5000);

        $result = $this->service->calculateAvailableFunds($request);

        // Available funds = 2000 - 5000 = -3000
        $this->assertEquals(-3000, $result);
    }

    /**
     * Test calculateAvailableFunds with alternative public key field
     */
    public function testCalculateAvailableFundsWithAlternativePublicKeyField(): void
    {
        $request = [
            'sender_public_key' => 'alternate-pubkey',
            'currency' => 'EUR'
        ];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->with('alternate-pubkey', 'EUR')
            ->willReturn(15000);

        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with('alternate-pubkey', 'EUR')
            ->willReturn(10000);

        $result = $this->service->calculateAvailableFunds($request);

        $this->assertEquals(5000, $result);
    }

    /**
     * Test calculateAvailableFunds prefers senderPublicKey over sender_public_key
     */
    public function testCalculateAvailableFundsPrefersStandardField(): void
    {
        $request = [
            'senderPublicKey' => 'primary-pubkey',
            'sender_public_key' => 'alternate-pubkey',
            'currency' => 'USD'
        ];

        // Should use 'senderPublicKey' value
        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->with('primary-pubkey', 'USD')
            ->willReturn(8000);

        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with('primary-pubkey', 'USD')
            ->willReturn(3000);

        $result = $this->service->calculateAvailableFunds($request);

        $this->assertEquals(5000, $result);
    }

    /**
     * Test calculateAvailableFunds with different currencies
     */
    public function testCalculateAvailableFundsWithDifferentCurrencies(): void
    {
        $requestUSD = [
            'senderPublicKey' => 'test-pubkey',
            'currency' => 'USD'
        ];

        $requestEUR = [
            'senderPublicKey' => 'test-pubkey',
            'currency' => 'EUR'
        ];

        // Set up expectations for USD
        $this->balanceRepository->expects($this->exactly(2))
            ->method('getContactSentBalance')
            ->willReturnCallback(function ($pubkey, $currency) {
                if ($currency === 'USD') {
                    return 10000;
                }
                return 5000;
            });

        $this->balanceRepository->expects($this->exactly(2))
            ->method('getContactReceivedBalance')
            ->willReturnCallback(function ($pubkey, $currency) {
                if ($currency === 'USD') {
                    return 2000;
                }
                return 1000;
            });

        $resultUSD = $this->service->calculateAvailableFunds($requestUSD);
        $resultEUR = $this->service->calculateAvailableFunds($requestEUR);

        $this->assertEquals(8000, $resultUSD);
        $this->assertEquals(4000, $resultEUR);
    }

    /**
     * Test calculateAvailableFunds with no transactions (zero balances)
     */
    public function testCalculateAvailableFundsWithNoTransactions(): void
    {
        $request = [
            'senderPublicKey' => 'new-contact-pubkey',
            'currency' => 'USD'
        ];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->with('new-contact-pubkey', 'USD')
            ->willReturn(0);

        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with('new-contact-pubkey', 'USD')
            ->willReturn(0);

        $result = $this->service->calculateAvailableFunds($request);

        $this->assertEquals(0, $result);
    }
}
