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
use Eiou\Database\RepositoryFactory;

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

        // Configure service container to return the mock balance repository via factory
        $mockFactory = $this->createMock(RepositoryFactory::class);
        $mockFactory->method('get')
            ->with(BalanceRepository::class)
            ->willReturn($this->balanceRepository);
        $this->serviceContainer->expects($this->any())
            ->method('getRepositoryFactory')
            ->willReturn($mockFactory);

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
     * Test validateRequestLevel rejects negative request levels (M-9)
     */
    public function testValidateRequestLevelRejectsNegativeRequestLevel(): void
    {
        $request = [
            'requestLevel' => -1,
            'maxRequestLevel' => 0
        ];

        $result = $this->service->validateRequestLevel($request);

        $this->assertFalse($result);
    }

    /**
     * Test validateRequestLevel caps maxRequestLevel with server-side max (M-9)
     *
     * The formula is: effectiveMax = min(clientMax, requestLevel + serverMax)
     * With serverMax=6, a clientMax of 100 gets capped to requestLevel+6.
     * The (int) cast prevents type-juggling attacks.
     */
    public function testValidateRequestLevelCapsWithServerSideMax(): void
    {
        // Server-side default is Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL (6)
        // With requestLevel=0 and clientMax=100, effective = min(100, 0+6) = 6
        // requestLevel=0 <= 6: passes
        $this->assertTrue($this->service->validateRequestLevel([
            'requestLevel' => 0,
            'maxRequestLevel' => 100
        ]));

        // The int cast ensures string values are properly handled
        $this->assertTrue($this->service->validateRequestLevel([
            'requestLevel' => '2',
            'maxRequestLevel' => '5'
        ]));
    }

    /**
     * Test validateRequestLevel enforces non-negative request levels (M-9)
     */
    public function testValidateRequestLevelEnforcesNonNegative(): void
    {
        // Zero is valid
        $this->assertTrue($this->service->validateRequestLevel([
            'requestLevel' => 0,
            'maxRequestLevel' => 5
        ]));

        // Negative is invalid even if within maxRequestLevel range
        $this->assertFalse($this->service->validateRequestLevel([
            'requestLevel' => -1,
            'maxRequestLevel' => 5
        ]));
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
        $request = ['senderPublicKey' => 'test-pubkey-123', 'currency' => 'USD'];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(100, 0));
        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(30, 0));

        $result = $this->service->calculateAvailableFunds($request);

        $this->assertInstanceOf(\Eiou\Core\SplitAmount::class, $result);
        $this->assertEquals(70, $result->whole);
    }

    public function testCalculateAvailableFundsWithZeroBalance(): void
    {
        $request = ['senderPublicKey' => 'test-pubkey-123', 'currency' => 'USD'];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(50, 0));
        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(50, 0));

        $result = $this->service->calculateAvailableFunds($request);
        $this->assertTrue($result->isZero());
    }

    public function testCalculateAvailableFundsWithNegativeBalance(): void
    {
        $request = ['senderPublicKey' => 'test-pubkey-123', 'currency' => 'USD'];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(20, 0));
        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(50, 0));

        $result = $this->service->calculateAvailableFunds($request);
        $this->assertTrue($result->isNegative());
        $this->assertEquals(-30, $result->whole);
    }

    public function testCalculateAvailableFundsWithAlternativePublicKeyField(): void
    {
        $request = ['sender_public_key' => 'alternate-pubkey', 'currency' => 'EUR'];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(150, 0));
        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->willReturn(new \Eiou\Core\SplitAmount(100, 0));

        $result = $this->service->calculateAvailableFunds($request);
        $this->assertEquals(50, $result->whole);
    }

    public function testCalculateAvailableFundsPrefersStandardField(): void
    {
        $request = ['senderPublicKey' => 'primary-pubkey', 'sender_public_key' => 'alternate-pubkey', 'currency' => 'USD'];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->with('primary-pubkey', 'USD')
            ->willReturn(new \Eiou\Core\SplitAmount(80, 0));
        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->with('primary-pubkey', 'USD')
            ->willReturn(new \Eiou\Core\SplitAmount(30, 0));

        $result = $this->service->calculateAvailableFunds($request);
        $this->assertEquals(50, $result->whole);
    }

    public function testCalculateAvailableFundsWithDifferentCurrencies(): void
    {
        $this->balanceRepository->expects($this->exactly(2))
            ->method('getContactSentBalance')
            ->willReturnCallback(fn($p, $c) => $c === 'USD' ? new \Eiou\Core\SplitAmount(100, 0) : new \Eiou\Core\SplitAmount(50, 0));
        $this->balanceRepository->expects($this->exactly(2))
            ->method('getContactReceivedBalance')
            ->willReturnCallback(fn($p, $c) => $c === 'USD' ? new \Eiou\Core\SplitAmount(20, 0) : new \Eiou\Core\SplitAmount(10, 0));

        $resultUSD = $this->service->calculateAvailableFunds(['senderPublicKey' => 'test', 'currency' => 'USD']);
        $resultEUR = $this->service->calculateAvailableFunds(['senderPublicKey' => 'test', 'currency' => 'EUR']);

        $this->assertEquals(80, $resultUSD->whole);
        $this->assertEquals(40, $resultEUR->whole);
    }

    public function testCalculateAvailableFundsWithNoTransactions(): void
    {
        $request = ['senderPublicKey' => 'new-contact-pubkey', 'currency' => 'USD'];

        $this->balanceRepository->expects($this->once())
            ->method('getContactSentBalance')
            ->willReturn(\Eiou\Core\SplitAmount::zero());
        $this->balanceRepository->expects($this->once())
            ->method('getContactReceivedBalance')
            ->willReturn(\Eiou\Core\SplitAmount::zero());

        $result = $this->service->calculateAvailableFunds($request);
        $this->assertTrue($result->isZero());
    }
}
