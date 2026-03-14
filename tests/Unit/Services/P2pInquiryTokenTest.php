<?php
/**
 * Unit Tests for P2P Inquiry Token Authentication
 *
 * Tests the hash-committed inquiry secret scheme:
 * - Deterministic secret derivation via HMAC(private_key, salt + time)
 * - Inquiry token = sha256(inquiry_secret)
 * - P2P hash = sha256(receiver + salt + time + inquiry_token)
 * - AES-256-GCM description encryption/decryption
 * - Salt+time extraction from encrypted blob for recovery
 * - Secret verification in checkMessageValidity
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use Eiou\Services\P2pService;
use Eiou\Core\Constants;

class P2pInquiryTokenTest extends TestCase
{
    private string $testSalt;
    private string $testTime;
    private string $testPrivateKey;

    protected function setUp(): void
    {
        $this->testSalt = bin2hex(random_bytes(16));
        $this->testTime = (string)(int)(microtime(true) * 1000000);
        // Use a deterministic test key
        $this->testPrivateKey = '-----BEGIN EC PRIVATE KEY-----testkey-----END EC PRIVATE KEY-----';
    }

    // =========================================================================
    // HMAC Secret Derivation
    // =========================================================================

    public function testSecretIsDeterministicFromSameInputs(): void
    {
        $secret1 = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $secret2 = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);

        $this->assertSame($secret1, $secret2, 'Same inputs must produce same secret');
    }

    public function testSecretDiffersWithDifferentSalt(): void
    {
        $secret1 = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $differentSalt = bin2hex(random_bytes(16));
        $secret2 = hash_hmac(Constants::HASH_ALGORITHM, $differentSalt . $this->testTime, $this->testPrivateKey);

        $this->assertNotSame($secret1, $secret2, 'Different salt must produce different secret');
    }

    public function testSecretDiffersWithDifferentTime(): void
    {
        $secret1 = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $differentTime = (string)((int)$this->testTime + 1);
        $secret2 = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $differentTime, $this->testPrivateKey);

        $this->assertNotSame($secret1, $secret2, 'Different time must produce different secret');
    }

    public function testSecretDiffersWithDifferentKey(): void
    {
        $secret1 = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $differentKey = '-----BEGIN EC PRIVATE KEY-----otherkey-----END EC PRIVATE KEY-----';
        $secret2 = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $differentKey);

        $this->assertNotSame($secret1, $secret2, 'Different key must produce different secret');
    }

    // =========================================================================
    // Token Derivation
    // =========================================================================

    public function testTokenIsSha256OfSecret(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $token = hash(Constants::HASH_ALGORITHM, $secret);

        $this->assertSame(64, strlen($token), 'Token must be 64 hex chars (sha256)');
        $this->assertSame($token, hash('sha256', $secret));
    }

    public function testTokenCannotReverseToSecret(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $token = hash(Constants::HASH_ALGORITHM, $secret);

        // Token and secret must be different (hash is one-way)
        $this->assertNotSame($secret, $token);
    }

    // =========================================================================
    // P2P Hash Construction
    // =========================================================================

    public function testP2pHashIncludesToken(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $token = hash(Constants::HASH_ALGORITHM, $secret);
        $receiver = 'http://httpC';

        $hashWith = hash(Constants::HASH_ALGORITHM, $receiver . $this->testSalt . $this->testTime . $token);
        $hashWithout = hash(Constants::HASH_ALGORITHM, $receiver . $this->testSalt . $this->testTime);

        $this->assertNotSame($hashWith, $hashWithout, 'Hash with token must differ from hash without');
    }

    public function testSwappingTokenBreaksHash(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $token = hash(Constants::HASH_ALGORITHM, $secret);
        $receiver = 'http://httpC';

        $originalHash = hash(Constants::HASH_ALGORITHM, $receiver . $this->testSalt . $this->testTime . $token);

        // Attacker tries to swap with their own token
        $fakeSecret = bin2hex(random_bytes(32));
        $fakeToken = hash(Constants::HASH_ALGORITHM, $fakeSecret);
        $tamperedHash = hash(Constants::HASH_ALGORITHM, $receiver . $this->testSalt . $this->testTime . $fakeToken);

        $this->assertNotSame($originalHash, $tamperedHash, 'Swapped token must break the hash');
    }

    // =========================================================================
    // Secret Verification
    // =========================================================================

    public function testValidSecretMatchesToken(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $token = hash(Constants::HASH_ALGORITHM, $secret);

        $this->assertSame($token, hash(Constants::HASH_ALGORITHM, $secret));
    }

    public function testFakeSecretDoesNotMatchToken(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $token = hash(Constants::HASH_ALGORITHM, $secret);

        $fakeSecret = bin2hex(random_bytes(32));
        $this->assertNotSame($token, hash(Constants::HASH_ALGORITHM, $fakeSecret));
    }

    public function testRelayCannotDeriveSecret(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $token = hash(Constants::HASH_ALGORITHM, $secret);

        // Relay has salt, time, token — but not the private key
        // Trying with wrong key should produce wrong secret
        $relayKey = '-----BEGIN EC PRIVATE KEY-----relaykey-----END EC PRIVATE KEY-----';
        $relaySecret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $relayKey);

        $this->assertNotSame($token, hash(Constants::HASH_ALGORITHM, $relaySecret),
            'Relay must not be able to derive a secret that matches the token');
    }

    // =========================================================================
    // Description Encryption / Decryption
    // =========================================================================

    public function testEncryptDecryptRoundTrip(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $description = 'Payment for invoice #12345';

        $encrypted = P2pService::encryptDescription($description, $secret, $this->testSalt, $this->testTime);
        $decrypted = P2pService::decryptDescription($encrypted, $secret);

        $this->assertSame($description, $decrypted);
    }

    public function testEncryptedDataIsBase64(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $encrypted = P2pService::encryptDescription('test', $secret, $this->testSalt, $this->testTime);

        $this->assertNotFalse(base64_decode($encrypted, true), 'Encrypted output must be valid base64');
    }

    public function testEncryptedDataDiffersFromPlaintext(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $description = 'secret payment info';
        $encrypted = P2pService::encryptDescription($description, $secret, $this->testSalt, $this->testTime);

        $this->assertNotSame($description, $encrypted);
        $this->assertStringNotContainsString($description, base64_decode($encrypted));
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $encrypted = P2pService::encryptDescription('test', $secret, $this->testSalt, $this->testTime);

        $wrongKey = bin2hex(random_bytes(32));
        $result = P2pService::decryptDescription($encrypted, $wrongKey);

        $this->assertFalse($result, 'Decryption with wrong key must fail');
    }

    public function testDecryptWithTamperedCiphertextFails(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $encrypted = P2pService::encryptDescription('test', $secret, $this->testSalt, $this->testTime);

        // Tamper with the last byte
        $raw = base64_decode($encrypted);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $tampered = base64_encode($raw);

        $result = P2pService::decryptDescription($tampered, $secret);
        $this->assertFalse($result, 'Decryption of tampered ciphertext must fail (GCM auth tag)');
    }

    public function testDifferentPlaintextsProduceDifferentCiphertexts(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);

        $enc1 = P2pService::encryptDescription('description A', $secret, $this->testSalt, $this->testTime);
        $enc2 = P2pService::encryptDescription('description B', $secret, $this->testSalt, $this->testTime);

        $this->assertNotSame($enc1, $enc2);
    }

    public function testSamePlaintextProducesDifferentCiphertexts(): void
    {
        // Due to random IV, same plaintext encrypted twice should differ
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);

        $enc1 = P2pService::encryptDescription('same text', $secret, $this->testSalt, $this->testTime);
        $enc2 = P2pService::encryptDescription('same text', $secret, $this->testSalt, $this->testTime);

        $this->assertNotSame($enc1, $enc2, 'Random IV should make each encryption unique');

        // But both should decrypt to the same value
        $this->assertSame('same text', P2pService::decryptDescription($enc1, $secret));
        $this->assertSame('same text', P2pService::decryptDescription($enc2, $secret));
    }

    // =========================================================================
    // Salt+Time Extraction for Recovery
    // =========================================================================

    public function testExtractSaltTimeFromEncrypted(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $encrypted = P2pService::encryptDescription('recovery test', $secret, $this->testSalt, $this->testTime);

        $extracted = P2pService::extractSaltTimeFromEncrypted($encrypted);

        $this->assertNotFalse($extracted);
        $this->assertSame($this->testSalt, $extracted['salt']);
        $this->assertSame($this->testTime, $extracted['time']);
    }

    public function testRecoveryFlowWithExtractedSaltTime(): void
    {
        // Simulate: originator creates P2P, encrypts description
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $encrypted = P2pService::encryptDescription('invoice #999', $secret, $this->testSalt, $this->testTime);

        // Simulate: originator loses DB, restores from seed (gets private key back)
        // Syncs with relay, gets transaction with encrypted_description
        // Extracts salt+time from the blob
        $extracted = P2pService::extractSaltTimeFromEncrypted($encrypted);

        // Rederive secret from restored private key + extracted salt/time
        $recoveredSecret = hash_hmac(Constants::HASH_ALGORITHM, $extracted['salt'] . $extracted['time'], $this->testPrivateKey);

        // Must match the original secret
        $this->assertSame($secret, $recoveredSecret);

        // Must decrypt successfully
        $decrypted = P2pService::decryptDescription($encrypted, $recoveredSecret);
        $this->assertSame('invoice #999', $decrypted);
    }

    public function testExtractFromInvalidDataReturnsFalse(): void
    {
        $this->assertFalse(P2pService::extractSaltTimeFromEncrypted(''));
        $this->assertFalse(P2pService::extractSaltTimeFromEncrypted('not-base64!!!'));
        $this->assertFalse(P2pService::extractSaltTimeFromEncrypted(base64_encode('tooshort')));
    }

    // =========================================================================
    // Unicode / Edge Cases
    // =========================================================================

    public function testEncryptDecryptUnicodeDescription(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $description = 'Zahlung für Rechnung #42 — 日本語テスト 🎉';

        $encrypted = P2pService::encryptDescription($description, $secret, $this->testSalt, $this->testTime);
        $decrypted = P2pService::decryptDescription($encrypted, $secret);

        $this->assertSame($description, $decrypted);
    }

    public function testEncryptDecryptEmptyString(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);

        $encrypted = P2pService::encryptDescription('', $secret, $this->testSalt, $this->testTime);
        $decrypted = P2pService::decryptDescription($encrypted, $secret);

        $this->assertSame('', $decrypted);
    }

    public function testEncryptDecryptLongDescription(): void
    {
        $secret = hash_hmac(Constants::HASH_ALGORITHM, $this->testSalt . $this->testTime, $this->testPrivateKey);
        $description = str_repeat('Long description for stress test. ', 100);

        $encrypted = P2pService::encryptDescription($description, $secret, $this->testSalt, $this->testTime);
        $decrypted = P2pService::decryptDescription($encrypted, $secret);

        $this->assertSame($description, $decrypted);
    }
}
