<?php
/**
 * Unit Tests for PayloadEncryption
 *
 * Tests ECDH + AES-256-GCM hybrid encryption for E2E payload encryption.
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Security\PayloadEncryption;
use InvalidArgumentException;
use RuntimeException;

#[CoversClass(PayloadEncryption::class)]
class PayloadEncryptionTest extends TestCase
{
    private string $alicePrivateKey = '';
    private string $alicePublicKey = '';
    private string $bobPrivateKey = '';
    private string $bobPublicKey = '';

    protected function setUp(): void
    {
        if (!PayloadEncryption::isAvailable()) {
            $this->markTestSkipped('PayloadEncryption not available (missing openssl_pkey_derive or hash_hkdf)');
        }

        // Generate two EC key pairs for testing
        $aliceKey = openssl_pkey_new([
            'ec' => ['curve_name' => 'prime256v1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($aliceKey, $this->alicePrivateKey);
        $this->alicePublicKey = openssl_pkey_get_details($aliceKey)['key'];

        $bobKey = openssl_pkey_new([
            'ec' => ['curve_name' => 'prime256v1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($bobKey, $this->bobPrivateKey);
        $this->bobPublicKey = openssl_pkey_get_details($bobKey)['key'];
    }

    public function testIsAvailableReturnsBoolean(): void
    {
        $result = PayloadEncryption::isAvailable();
        $this->assertIsBool($result);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $sensitiveFields = [
            'amount' => 1000,
            'currency' => 'USD',
            'txid' => 'test-txid-123',
            'previousTxid' => 'prev-txid-456',
            'memo' => 'standard',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($sensitiveFields, $this->bobPublicKey);

        $this->assertArrayHasKey('ciphertext', $encrypted);
        $this->assertArrayHasKey('iv', $encrypted);
        $this->assertArrayHasKey('tag', $encrypted);
        $this->assertArrayHasKey('ephemeralKey', $encrypted);

        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->bobPrivateKey);

        $this->assertEquals($sensitiveFields, $decrypted);
    }

    public function testEncryptDecryptWithIntegerAndNullValues(): void
    {
        $sensitiveFields = [
            'amount' => 0,
            'currency' => 'USD',
            'txid' => 'test-txid',
            'previousTxid' => null,
            'memo' => 'standard',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($sensitiveFields, $this->bobPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->bobPrivateKey);

        $this->assertEquals($sensitiveFields, $decrypted);
    }

    public function testDifferentEncryptionsProduceDifferentCiphertext(): void
    {
        $fields = ['amount' => 1000, 'currency' => 'USD'];

        $encrypted1 = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);
        $encrypted2 = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);

        // Ephemeral keys should differ (fresh key per encryption)
        $this->assertNotEquals($encrypted1['ephemeralKey'], $encrypted2['ephemeralKey']);
        // Ciphertext should differ (different IV + different ECDH shared secret)
        $this->assertNotEquals($encrypted1['ciphertext'], $encrypted2['ciphertext']);
    }

    public function testWrongRecipientKeyFailsDecryption(): void
    {
        $fields = ['amount' => 500, 'currency' => 'USD'];

        // Encrypt for Bob
        $encrypted = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);

        // Try to decrypt with Alice's key — should fail
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payload decryption failed');
        PayloadEncryption::decryptFromSender($encrypted, $this->alicePrivateKey);
    }

    public function testTamperedCiphertextFailsDecryption(): void
    {
        $fields = ['amount' => 1000, 'currency' => 'USD'];
        $encrypted = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);

        // Tamper with ciphertext
        $rawCiphertext = base64_decode($encrypted['ciphertext']);
        $rawCiphertext[0] = chr(ord($rawCiphertext[0]) ^ 0xFF);
        $encrypted['ciphertext'] = base64_encode($rawCiphertext);

        $this->expectException(RuntimeException::class);
        PayloadEncryption::decryptFromSender($encrypted, $this->bobPrivateKey);
    }

    public function testTamperedTagFailsDecryption(): void
    {
        $fields = ['amount' => 1000, 'currency' => 'USD'];
        $encrypted = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);

        // Tamper with auth tag
        $rawTag = base64_decode($encrypted['tag']);
        $rawTag[0] = chr(ord($rawTag[0]) ^ 0xFF);
        $encrypted['tag'] = base64_encode($rawTag);

        $this->expectException(RuntimeException::class);
        PayloadEncryption::decryptFromSender($encrypted, $this->bobPrivateKey);
    }

    public function testEmptyPayloadThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayloadEncryption::encryptForRecipient([], $this->bobPublicKey);
    }

    public function testEmptyPublicKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PayloadEncryption::encryptForRecipient(['amount' => 100], '');
    }

    public function testInvalidPublicKeyThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        PayloadEncryption::encryptForRecipient(['amount' => 100], 'not-a-valid-key');
    }

    public function testMissingEncryptedFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required encrypted field');
        PayloadEncryption::decryptFromSender(
            ['ciphertext' => 'abc', 'iv' => 'def'],  // missing tag and ephemeralKey
            $this->bobPrivateKey
        );
    }

    public function testEncryptedFieldsConstant(): void
    {
        $expected = ['amount', 'currency', 'txid', 'previousTxid', 'memo'];
        $this->assertEquals($expected, PayloadEncryption::ENCRYPTED_FIELDS);
    }

    public function testLargePayloadEncryption(): void
    {
        $fields = [
            'amount' => 999999999999,
            'currency' => 'USD',
            'txid' => str_repeat('a', 255),
            'previousTxid' => str_repeat('b', 255),
            'memo' => 'standard',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->bobPrivateKey);

        $this->assertEquals($fields, $decrypted);
    }

    public function testSecp256k1KeysIfAvailable(): void
    {
        $curves = openssl_get_curve_names();
        if (!in_array('secp256k1', $curves)) {
            $this->markTestSkipped('secp256k1 not available');
        }

        $key1 = openssl_pkey_new([
            'ec' => ['curve_name' => 'secp256k1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($key1, $privateKey1);
        $publicKey1 = openssl_pkey_get_details($key1)['key'];

        $key2 = openssl_pkey_new([
            'ec' => ['curve_name' => 'secp256k1'],
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($key2, $privateKey2);
        $publicKey2 = openssl_pkey_get_details($key2)['key'];

        $fields = ['amount' => 100000000, 'currency' => 'BTC'];

        $encrypted = PayloadEncryption::encryptForRecipient($fields, $publicKey2);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $privateKey2);

        $this->assertEquals($fields, $decrypted);
    }

    public function testCrossNodeSimulation(): void
    {
        // Simulate two nodes with different key pairs
        // Node A (sender) encrypts for Node B (recipient)
        $fields = [
            'amount' => 2000,
            'currency' => 'USD',
            'txid' => 'cross-node-txid',
            'previousTxid' => null,
            'memo' => 'standard',
        ];

        // Alice encrypts for Bob using Bob's public key
        $encrypted = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);

        // Bob decrypts using Bob's private key
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->bobPrivateKey);

        $this->assertEquals($fields, $decrypted);

        // Verify Alice cannot decrypt (she is not the intended recipient)
        $this->expectException(RuntimeException::class);
        PayloadEncryption::decryptFromSender($encrypted, $this->alicePrivateKey);
    }

    public function testEncryptThenSignWorkflow(): void
    {
        $fields = ['amount' => 5000, 'currency' => 'USD', 'txid' => 'sign-test'];

        // Encrypt for Bob
        $encrypted = PayloadEncryption::encryptForRecipient($fields, $this->bobPublicKey);

        // Build message content with encrypted block (mimicking signWithCapture)
        $messageContent = [
            'type' => 'send',
            'time' => 1710000000,
            'receiverAddress' => 'https://bob.example.com',
            'receiverPublicKey' => $this->bobPublicKey,
            'encrypted' => $encrypted,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $message = json_encode($messageContent);

        // Alice signs the message (which includes encrypted block)
        openssl_sign($message, $signature, openssl_pkey_get_private($this->alicePrivateKey));

        // Bob verifies signature WITHOUT decryption (encrypt-then-sign)
        $verified = openssl_verify($message, $signature, openssl_pkey_get_public($this->alicePublicKey));
        $this->assertEquals(1, $verified);

        // Bob decrypts the sensitive fields
        $decoded = json_decode($message, true);
        $decrypted = PayloadEncryption::decryptFromSender($decoded['encrypted'], $this->bobPrivateKey);

        $this->assertEquals($fields, $decrypted);
    }

    public function testInvalidBase64InEncryptedData(): void
    {
        $this->expectException(RuntimeException::class);
        PayloadEncryption::decryptFromSender([
            'ciphertext' => '!!!invalid!!!',
            'iv' => base64_encode('test'),
            'tag' => base64_encode('test'),
            'ephemeralKey' => base64_encode('test'),
        ], $this->bobPrivateKey);
    }
}
