<?php
/**
 * Unit Tests for KeyEncryption
 *
 * Tests AES-256-GCM encryption/decryption for private keys.
 * Note: Some tests require filesystem access for master key.
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Security\KeyEncryption;

#[CoversClass(KeyEncryption::class)]
class KeyEncryptionTest extends TestCase
{
    /**
     * Test isAvailable returns boolean
     */
    public function testIsAvailableReturnsBoolean(): void
    {
        $result = KeyEncryption::isAvailable();

        $this->assertIsBool($result);
    }

    /**
     * Test isAvailable checks for openssl
     */
    public function testIsAvailableChecksOpenssl(): void
    {
        // If openssl is not loaded, isAvailable should return false
        if (!extension_loaded('openssl')) {
            $this->assertFalse(KeyEncryption::isAvailable());
        } else {
            // OpenSSL is available, check further requirements
            $this->assertTrue(KeyEncryption::isAvailable());
        }
    }

    /**
     * Test getInfo returns expected keys
     */
    public function testGetInfoReturnsExpectedKeys(): void
    {
        $info = KeyEncryption::getInfo();

        $this->assertArrayHasKey('cipher', $info);
        $this->assertArrayHasKey('key_size', $info);
        $this->assertArrayHasKey('iv_length', $info);
        $this->assertArrayHasKey('tag_length', $info);
        $this->assertArrayHasKey('openssl_available', $info);
        $this->assertArrayHasKey('sodium_available', $info);
        $this->assertArrayHasKey('master_key_exists', $info);
    }

    /**
     * Test getInfo returns correct cipher
     */
    public function testGetInfoReturnsCorrectCipher(): void
    {
        $info = KeyEncryption::getInfo();

        $this->assertEquals('aes-256-gcm', $info['cipher']);
        $this->assertEquals(256, $info['key_size']);
    }

    /**
     * Test getInfo returns correct lengths
     */
    public function testGetInfoReturnsCorrectLengths(): void
    {
        $info = KeyEncryption::getInfo();

        $this->assertEquals(12, $info['iv_length']); // 96 bits
        $this->assertEquals(16, $info['tag_length']); // 128 bits
    }

    /**
     * Test secure clear clears string
     */
    public function testSecureClearClearsString(): void
    {
        $data = 'sensitive-secret-data';
        KeyEncryption::secureClear($data);

        $this->assertEquals('', $data);
    }

    /**
     * Test secure clear handles empty string
     */
    public function testSecureClearHandlesEmptyString(): void
    {
        $data = '';
        KeyEncryption::secureClear($data);

        $this->assertEquals('', $data);
    }

    /**
     * Test encrypt throws on empty data
     */
    public function testEncryptThrowsOnEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot encrypt empty data');

        KeyEncryption::encrypt('');
    }

    /**
     * Test decrypt throws on invalid format
     */
    public function testDecryptThrowsOnInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid encrypted data format');

        KeyEncryption::decrypt(['invalid' => 'data']);
    }

    /**
     * Test decrypt throws on missing ciphertext
     */
    public function testDecryptThrowsOnMissingCiphertext(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KeyEncryption::decrypt([
            'iv' => base64_encode('test'),
            'tag' => base64_encode('test')
        ]);
    }

    /**
     * Test decrypt throws on missing iv
     */
    public function testDecryptThrowsOnMissingIv(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KeyEncryption::decrypt([
            'ciphertext' => base64_encode('test'),
            'tag' => base64_encode('test')
        ]);
    }

    /**
     * Test decrypt throws on missing tag
     */
    public function testDecryptThrowsOnMissingTag(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KeyEncryption::decrypt([
            'ciphertext' => base64_encode('test'),
            'iv' => base64_encode('test')
        ]);
    }

    // =========================================================================
    // Seed-based Master Key Derivation Tests (M-13)
    // =========================================================================

    /**
     * Test deriveMasterKeyFromSeed returns 32 bytes
     */
    public function testDeriveMasterKeyFromSeedReturns32Bytes(): void
    {
        $seed = random_bytes(64); // BIP39 seed is 64 bytes
        $key = KeyEncryption::deriveMasterKeyFromSeed($seed);

        $this->assertEquals(32, strlen($key));
    }

    /**
     * Test deriveMasterKeyFromSeed is deterministic
     */
    public function testDeriveMasterKeyFromSeedIsDeterministic(): void
    {
        $seed = random_bytes(64);

        $key1 = KeyEncryption::deriveMasterKeyFromSeed($seed);
        $key2 = KeyEncryption::deriveMasterKeyFromSeed($seed);

        $this->assertEquals($key1, $key2);
    }

    /**
     * Test deriveMasterKeyFromSeed produces different keys for different seeds
     */
    public function testDeriveMasterKeyFromSeedDifferentSeeds(): void
    {
        $seed1 = random_bytes(64);
        $seed2 = random_bytes(64);

        $key1 = KeyEncryption::deriveMasterKeyFromSeed($seed1);
        $key2 = KeyEncryption::deriveMasterKeyFromSeed($seed2);

        $this->assertNotEquals($key1, $key2);
    }

    /**
     * Test getMasterKey throws when master key file does not exist
     */
    public function testGetMasterKeyThrowsWhenNoFile(): void
    {
        // Outside Docker, the master key file won't exist
        if (file_exists('/etc/eiou/config/.master.key')) {
            $this->markTestSkipped('Master key file exists (running in Docker)');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Master key not found');

        // Trigger getMasterKey() via encrypt()
        KeyEncryption::encrypt('test-data');
    }

    /**
     * Test master key derivation uses distinct HMAC context from EC key derivation
     *
     * Verifies domain separation: the same seed produces different outputs
     * for master key vs EC key derivation (different HMAC context strings).
     */
    public function testDeriveMasterKeyDomainSeparation(): void
    {
        $seed = random_bytes(64);

        $masterKey = KeyEncryption::deriveMasterKeyFromSeed($seed);
        // EC key uses 'eiou-ec-key' context; master key uses 'eiou-master-key'
        $ecKey = hash_hmac('sha256', $seed, 'eiou-ec-key', true);

        $this->assertNotEquals($masterKey, $ecKey,
            'Master key and EC key must differ (domain separation via HMAC context)');
    }

    // =========================================================================
    // Encryption Format v2 (AAD context) Tests
    // =========================================================================

    /**
     * Test encrypt accepts optional context parameter
     */
    public function testEncryptAcceptsContextParameter(): void
    {
        // Verify the method signature accepts context — actual encryption
        // requires the master key file (Docker-only), so we test the
        // empty-data guard path with context param to confirm the signature.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot encrypt empty data');

        KeyEncryption::encrypt('', 'private_key');
    }

    /**
     * Test decrypt rejects v1 format (no version field)
     */
    public function testDecryptRejectsV1Format(): void
    {
        $encrypted = [
            'ciphertext' => base64_encode('dummy'),
            'iv' => base64_encode(str_repeat("\0", 12)),
            'tag' => base64_encode(str_repeat("\0", 16))
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported encryption format: v2+ required');

        KeyEncryption::decrypt($encrypted);
    }

    /**
     * Test decrypt accepts v2 format (with version and aad fields)
     */
    public function testDecryptAcceptsV2Format(): void
    {
        $encrypted = [
            'ciphertext' => base64_encode('dummy'),
            'iv' => base64_encode(str_repeat("\0", 12)),
            'tag' => base64_encode(str_repeat("\0", 16)),
            'version' => 2,
            'aad' => 'private_key'
        ];

        // Should NOT throw InvalidArgumentException (format is valid)
        // Will throw RuntimeException from missing master key outside Docker
        try {
            KeyEncryption::decrypt($encrypted);
            $this->fail('Expected RuntimeException from missing master key');
        } catch (\RuntimeException $e) {
            $this->assertTrue(true);
        }
    }
}
