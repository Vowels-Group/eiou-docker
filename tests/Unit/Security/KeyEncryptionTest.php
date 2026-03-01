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
