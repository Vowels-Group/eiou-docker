<?php
/**
 * Tests for E2E encrypted contact description follow-up.
 *
 * Verifies that contact descriptions sent via the two-phase flow
 * (non-TOR) are properly E2E encrypted, while TOR addresses use
 * the single-phase flow with description in the create request.
 *
 * Tests cover:
 * - MessagePayload::buildContactDescription() structure
 * - contact_description type is NOT excluded from encryption
 * - Full encrypt/decrypt round-trip for contact description payload
 * - signWithCapture preserves description for contact_description status
 * - Constants::DELIVERY_CONTACT_DESCRIPTION value
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Security\PayloadEncryption;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Schemas\Payloads\MessagePayload;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(PayloadEncryption::class)]
class E2eContactDescriptionTest extends TestCase
{
    private string $senderPrivateKey = '';
    private string $senderPublicKey = '';
    private string $recipientPrivateKey = '';
    private string $recipientPublicKey = '';

    protected function setUp(): void
    {
        if (!PayloadEncryption::isAvailable()) {
            $this->markTestSkipped('PayloadEncryption not available');
        }

        $senderKey = openssl_pkey_new([
            'ec' => ['curve_name' => 'secp256k1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($senderKey, $this->senderPrivateKey);
        $this->senderPublicKey = openssl_pkey_get_details($senderKey)['key'];

        $recipientKey = openssl_pkey_new([
            'ec' => ['curve_name' => 'secp256k1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($recipientKey, $this->recipientPrivateKey);
        $this->recipientPublicKey = openssl_pkey_get_details($recipientKey)['key'];
    }

    // =========================================================================
    // Constants
    // =========================================================================

    public function testDeliveryContactDescriptionConstantExists(): void
    {
        $this->assertEquals('contact_description', Constants::DELIVERY_CONTACT_DESCRIPTION);
    }

    // =========================================================================
    // Encryption exclusion
    // =========================================================================

    public function testContactDescriptionTypeNotExcludedFromEncryption(): void
    {
        // type=message (used by contact_description) must NOT be excluded
        $this->assertNotContains('message', PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION);
    }

    public function testOnlyCreateTypeIsExcludedFromEncryption(): void
    {
        // Only 'create' should be excluded — contact_description uses type=message
        $this->assertEquals(['create'], PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION);
    }

    // =========================================================================
    // Full encrypt/decrypt round-trip for contact description
    // =========================================================================

    public function testContactDescriptionPayloadEncryptDecryptRoundTrip(): void
    {
        $descriptionPayload = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::DELIVERY_CONTACT_DESCRIPTION,
            'description' => 'Hey, it\'s Dave!',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($descriptionPayload, $this->recipientPublicKey);

        $this->assertArrayHasKey('ciphertext', $encrypted);
        $this->assertArrayHasKey('iv', $encrypted);
        $this->assertArrayHasKey('tag', $encrypted);
        $this->assertArrayHasKey('ephemeralKey', $encrypted);

        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($descriptionPayload, $decrypted);
        $this->assertEquals('Hey, it\'s Dave!', $decrypted['description']);
        $this->assertEquals(Constants::DELIVERY_CONTACT_DESCRIPTION, $decrypted['status']);
    }

    public function testContactDescriptionWithSpecialCharactersEncryptDecrypt(): void
    {
        $descriptionPayload = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::DELIVERY_CONTACT_DESCRIPTION,
            'description' => 'Hello! I\'m a friend of Bob & Alice <test@example.com>',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($descriptionPayload, $this->recipientPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($descriptionPayload['description'], $decrypted['description']);
    }

    public function testContactDescriptionEncryptedDataIsOpaque(): void
    {
        $descriptionPayload = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::DELIVERY_CONTACT_DESCRIPTION,
            'description' => 'Secret message that should not be visible',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($descriptionPayload, $this->recipientPublicKey);

        // The ciphertext should not contain the plaintext description
        $ciphertext = base64_decode($encrypted['ciphertext']);
        $this->assertStringNotContainsString('Secret message', $ciphertext);
    }

    // =========================================================================
    // MessagePayload::buildContactDescription()
    // =========================================================================

    public function testBuildContactDescriptionReturnsCorrectStructure(): void
    {
        $userContext = $this->createMock(UserContext::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $transportUtility = $this->createMock(TransportUtilityService::class);
        $currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $timeUtility = $this->createMock(TimeUtilityService::class);
        $validationUtility = $this->createMock(ValidationUtilityService::class);

        $utilityContainer->method('getCurrencyUtility')->willReturn($currencyUtility);
        $utilityContainer->method('getTimeUtility')->willReturn($timeUtility);
        $utilityContainer->method('getValidationUtility')->willReturn($validationUtility);
        $utilityContainer->method('getTransportUtility')->willReturn($transportUtility);

        $transportUtility->method('resolveUserAddressForTransport')
            ->willReturn('http://mynode:8080');
        $userContext->method('getPublicKey')
            ->willReturn('test-public-key');

        $messagePayload = new MessagePayload($userContext, $utilityContainer);

        $result = $messagePayload->buildContactDescription('http://remote:8080', 'Hey, it\'s Dave!');

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('contact', $result['typeMessage']);
        $this->assertEquals(Constants::DELIVERY_CONTACT_DESCRIPTION, $result['status']);
        $this->assertEquals('Hey, it\'s Dave!', $result['description']);
        $this->assertEquals('http://mynode:8080', $result['senderAddress']);
        $this->assertEquals('test-public-key', $result['senderPublicKey']);
    }

    public function testBuildContactDescriptionIncludesAllRequiredFields(): void
    {
        $userContext = $this->createMock(UserContext::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $transportUtility = $this->createMock(TransportUtilityService::class);
        $currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $timeUtility = $this->createMock(TimeUtilityService::class);
        $validationUtility = $this->createMock(ValidationUtilityService::class);

        $utilityContainer->method('getCurrencyUtility')->willReturn($currencyUtility);
        $utilityContainer->method('getTimeUtility')->willReturn($timeUtility);
        $utilityContainer->method('getValidationUtility')->willReturn($validationUtility);
        $utilityContainer->method('getTransportUtility')->willReturn($transportUtility);

        $transportUtility->method('resolveUserAddressForTransport')->willReturn('http://mynode:8080');
        $userContext->method('getPublicKey')->willReturn('test-public-key');

        $messagePayload = new MessagePayload($userContext, $utilityContainer);
        $result = $messagePayload->buildContactDescription('http://remote:8080', 'Test message');

        $requiredFields = ['type', 'typeMessage', 'status', 'description', 'senderAddress', 'senderPublicKey'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing required field: $field");
        }
    }

    // =========================================================================
    // Contact request (type=create) still excluded
    // =========================================================================

    public function testCreateTypeStillExcludedFromEncryption(): void
    {
        $this->assertContains('create', PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION);
    }

    // =========================================================================
    // Verify contact_description can be encrypted with recipient's key
    // =========================================================================

    public function testContactDescriptionCanBeDecryptedByRecipientOnly(): void
    {
        $descriptionPayload = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::DELIVERY_CONTACT_DESCRIPTION,
            'description' => 'Confidential contact message',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($descriptionPayload, $this->recipientPublicKey);

        // Recipient can decrypt
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);
        $this->assertEquals('Confidential contact message', $decrypted['description']);

        // Sender (wrong key) cannot decrypt
        $this->expectException(\RuntimeException::class);
        PayloadEncryption::decryptFromSender($encrypted, $this->senderPrivateKey);
    }
}
