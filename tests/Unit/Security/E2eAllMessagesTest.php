<?php
/**
 * Tests for E2E encryption of all contact message types.
 *
 * Verifies that P2P, RP2P, relay transactions, messages, pings, and
 * route cancellations are all encrypted when sent to known contacts.
 * Only contact requests (type=create) are excluded.
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Security\PayloadEncryption;

#[CoversClass(PayloadEncryption::class)]
class E2eAllMessagesTest extends TestCase
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

    public function testTypesExcludedFromEncryptionContainsCreate(): void
    {
        $this->assertContains('create', PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION);
    }

    public function testTypesExcludedDoesNotContainP2p(): void
    {
        $this->assertNotContains('p2p', PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION);
    }

    public function testTypesExcludedDoesNotContainRp2p(): void
    {
        $this->assertNotContains('rp2p', PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION);
    }

    public function testTypesExcludedDoesNotContainSend(): void
    {
        $this->assertNotContains('send', PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION);
    }

    public function testFullP2pPayloadEncryptDecrypt(): void
    {
        $p2pPayload = [
            'type' => 'p2p',
            'hash' => 'abc123def456',
            'salt' => 'random_salt_value',
            'time' => 1710000000000000,
            'expiration' => 1710000300000000,
            'currency' => 'USD',
            'amount' => 10000,
            'requestLevel' => 0,
            'maxRequestLevel' => 6,
            'fast' => true,
            'hopWait' => 15,
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($p2pPayload, $this->recipientPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($p2pPayload, $decrypted);
        // Verify type is inside the encrypted block (not visible to observers)
        $this->assertEquals('p2p', $decrypted['type']);
    }

    public function testFullRp2pPayloadEncryptDecrypt(): void
    {
        $rp2pPayload = [
            'type' => 'rp2p',
            'hash' => 'abc123def456',
            'time' => 1710000000000000,
            'amount' => 10225,
            'currency' => 'USD',
            'signature' => 'base64_signature_here',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($rp2pPayload, $this->recipientPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($rp2pPayload, $decrypted);
    }

    public function testRelayTransactionEncryptDecrypt(): void
    {
        // P2P relay transaction: type=send, memo=hash (not 'standard')
        $relayTxPayload = [
            'type' => 'send',
            'time' => 1710000000000000,
            'receiverAddress' => 'https://relay.example.com',
            'receiverPublicKey' => $this->recipientPublicKey,
            'amount' => 5000,
            'currency' => 'USD',
            'txid' => 'relay-txid-123',
            'previousTxid' => 'prev-txid-456',
            'memo' => 'abc123_p2p_hash',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($relayTxPayload, $this->recipientPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($relayTxPayload, $decrypted);
    }

    public function testRouteCancelEncryptDecrypt(): void
    {
        $cancelPayload = [
            'type' => 'route_cancel',
            'hash' => 'cancel_hash_123',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($cancelPayload, $this->recipientPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($cancelPayload, $decrypted);
    }

    public function testMessagePayloadEncryptDecrypt(): void
    {
        $messagePayload = [
            'type' => 'message',
            'typeMessage' => 'transaction',
            'inquiry' => false,
            'status' => 'completed',
            'hash' => 'msg_hash_123',
            'hashType' => 'memo',
            'amount' => 10000,
            'currency' => 'USD',
            'message' => 'transaction completed successfully',
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($messagePayload, $this->recipientPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($messagePayload, $decrypted);
    }

    public function testPingPayloadEncryptDecrypt(): void
    {
        $pingPayload = [
            'type' => 'ping',
            'time' => 1710000000000000,
        ];

        $encrypted = PayloadEncryption::encryptForRecipient($pingPayload, $this->recipientPublicKey);
        $decrypted = PayloadEncryption::decryptFromSender($encrypted, $this->recipientPrivateKey);

        $this->assertEquals($pingPayload, $decrypted);
    }

    public function testEncryptedMessageLooksUniform(): void
    {
        // Verify that different message types produce the same structure
        // An observer should not be able to distinguish them
        $p2p = PayloadEncryption::encryptForRecipient(
            ['type' => 'p2p', 'hash' => 'abc', 'amount' => 10000, 'currency' => 'USD'],
            $this->recipientPublicKey
        );

        $rp2p = PayloadEncryption::encryptForRecipient(
            ['type' => 'rp2p', 'hash' => 'abc', 'amount' => 10225, 'currency' => 'USD'],
            $this->recipientPublicKey
        );

        $send = PayloadEncryption::encryptForRecipient(
            ['type' => 'send', 'amount' => 5000, 'currency' => 'USD', 'txid' => 'tx1', 'memo' => 'standard'],
            $this->recipientPublicKey
        );

        // All have the same structure: ciphertext, iv, tag, ephemeralKey
        $expectedKeys = ['ciphertext', 'iv', 'tag', 'ephemeralKey'];
        $this->assertEquals($expectedKeys, array_keys($p2p));
        $this->assertEquals($expectedKeys, array_keys($rp2p));
        $this->assertEquals($expectedKeys, array_keys($send));
    }

    public function testSignedMessageWithFullEncryption(): void
    {
        // Simulate the full signWithCapture flow:
        // All fields encrypted, only 'encrypted' and 'nonce' in cleartext
        $originalPayload = [
            'type' => 'p2p',
            'hash' => 'discovery_hash',
            'amount' => 10000,
            'currency' => 'USD',
            'requestLevel' => 0,
            'maxRequestLevel' => 6,
        ];

        // Encrypt all fields
        $encrypted = PayloadEncryption::encryptForRecipient($originalPayload, $this->recipientPublicKey);

        // Build signed message (mirrors signWithCapture)
        $messageContent = [
            'encrypted' => $encrypted,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $message = json_encode($messageContent);

        // Sign
        openssl_sign($message, $signature, openssl_pkey_get_private($this->senderPrivateKey));

        // Verify message does NOT contain type, amount, hash in cleartext
        $this->assertStringNotContainsString('"type"', $message);
        $this->assertStringNotContainsString('"amount"', $message);
        $this->assertStringNotContainsString('"hash"', $message);
        $this->assertStringNotContainsString('discovery_hash', $message);

        // Verify signature (before decryption — encrypt-then-sign)
        $verified = openssl_verify($message, $signature, openssl_pkey_get_public($this->senderPublicKey));
        $this->assertEquals(1, $verified);

        // Decrypt (recipient side)
        $decoded = json_decode($message, true);
        $decrypted = PayloadEncryption::decryptFromSender($decoded['encrypted'], $this->recipientPrivateKey);

        // All original fields restored
        $this->assertEquals($originalPayload, $decrypted);
        $this->assertEquals('p2p', $decrypted['type']);
    }

    public function testWrongRecipientCannotDecryptP2p(): void
    {
        $p2pPayload = [
            'type' => 'p2p',
            'hash' => 'secret_hash',
            'amount' => 50000,
            'currency' => 'USD',
        ];

        // Encrypted for intended recipient
        $encrypted = PayloadEncryption::encryptForRecipient($p2pPayload, $this->recipientPublicKey);

        // Third party (sender) cannot decrypt
        $this->expectException(\RuntimeException::class);
        PayloadEncryption::decryptFromSender($encrypted, $this->senderPrivateKey);
    }
}
