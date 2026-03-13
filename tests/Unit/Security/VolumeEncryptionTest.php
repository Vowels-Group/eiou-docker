<?php
/**
 * Unit Tests for VolumeEncryption
 *
 * Tests the volume encryption service that protects the master key at rest
 * using a passphrase-derived key (Argon2id + AES-256-GCM).
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Security\VolumeEncryption;

#[CoversClass(VolumeEncryption::class)]
class VolumeEncryptionTest extends TestCase
{
    /**
     * Test isAvailable returns boolean
     */
    public function testIsAvailableReturnsBoolean(): void
    {
        $result = VolumeEncryption::isAvailable();
        $this->assertIsBool($result);
    }

    /**
     * Test isAvailable checks for sodium
     */
    public function testIsAvailableChecksSodium(): void
    {
        if (function_exists('sodium_crypto_pwhash') && extension_loaded('openssl')) {
            $this->assertTrue(VolumeEncryption::isAvailable());
        } else {
            $this->assertFalse(VolumeEncryption::isAvailable());
        }
    }

    /**
     * Test getStatus returns expected keys
     */
    public function testGetStatusReturnsExpectedKeys(): void
    {
        $status = VolumeEncryption::getStatus();

        $this->assertArrayHasKey('available', $status);
        $this->assertArrayHasKey('active', $status);
        $this->assertArrayHasKey('encrypted_key_exists', $status);
        $this->assertArrayHasKey('plaintext_key_exists', $status);
        $this->assertArrayHasKey('runtime_key_exists', $status);
        $this->assertArrayHasKey('sodium_available', $status);
    }

    /**
     * Test getStatus values are booleans
     */
    public function testGetStatusValuesAreBooleans(): void
    {
        $status = VolumeEncryption::getStatus();

        foreach ($status as $key => $value) {
            $this->assertIsBool($value, "Status key '$key' should be boolean");
        }
    }

    /**
     * Test isActive returns false when no volume key file exists
     */
    public function testIsActiveReturnsFalseWithoutVolumeKey(): void
    {
        // Outside Docker, /dev/shm/.volume_key won't exist
        if (file_exists('/dev/shm/.volume_key')) {
            $this->markTestSkipped('Volume key exists (active session)');
        }

        $this->assertFalse(VolumeEncryption::isActive());
    }

    /**
     * Test getPassphrase returns null when no volume key file exists
     */
    public function testGetPassphraseReturnsNullWithoutVolumeKey(): void
    {
        if (file_exists('/dev/shm/.volume_key')) {
            $this->markTestSkipped('Volume key exists (active session)');
        }

        $this->assertNull(VolumeEncryption::getPassphrase());
    }

    /**
     * Test getMasterKeyPath returns persistent path when no runtime key exists
     */
    public function testGetMasterKeyPathReturnsPersistentPath(): void
    {
        if (file_exists('/dev/shm/.master.key')) {
            $this->markTestSkipped('Runtime key exists');
        }

        $path = VolumeEncryption::getMasterKeyPath();
        $this->assertEquals('/etc/eiou/config/.master.key', $path);
    }

    /**
     * Test RUNTIME_KEY_FILE constant is in /dev/shm
     */
    public function testRuntimeKeyFileIsInDevShm(): void
    {
        $this->assertStringStartsWith('/dev/shm/', VolumeEncryption::RUNTIME_KEY_FILE);
    }

    /**
     * Test init with null passphrase and no keys returns disabled status
     */
    public function testInitWithNullPassphraseAndNoKeysReturnsDisabled(): void
    {
        // Skip if either key file exists (running in Docker or with volume encryption)
        if (file_exists('/etc/eiou/config/.master.key') ||
            file_exists('/etc/eiou/config/.master.key.enc')) {
            $this->markTestSkipped('Key files exist (running in Docker)');
        }

        $result = VolumeEncryption::init(null);
        $this->assertStringContainsString('disabled', $result);
    }

    /**
     * Test init with empty passphrase and no keys returns disabled status
     */
    public function testInitWithEmptyPassphraseAndNoKeysReturnsDisabled(): void
    {
        if (file_exists('/etc/eiou/config/.master.key') ||
            file_exists('/etc/eiou/config/.master.key.enc')) {
            $this->markTestSkipped('Key files exist (running in Docker)');
        }

        $result = VolumeEncryption::init('');
        $this->assertStringContainsString('disabled', $result);
    }

    /**
     * Test init with passphrase but no keys returns ready status
     */
    public function testInitWithPassphraseButNoKeysReturnsReady(): void
    {
        if (file_exists('/etc/eiou/config/.master.key') ||
            file_exists('/etc/eiou/config/.master.key.enc')) {
            $this->markTestSkipped('Key files exist (running in Docker)');
        }

        $result = VolumeEncryption::init('test-passphrase');
        $this->assertStringContainsString('ready', $result);
    }

    /**
     * Test init throws when encrypted key exists but no passphrase provided
     */
    public function testInitThrowsWhenEncryptedKeyExistsWithoutPassphrase(): void
    {
        if (!file_exists('/etc/eiou/config/.master.key.enc')) {
            $this->markTestSkipped('No encrypted key file (not running with volume encryption)');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EIOU_VOLUME_KEY was not provided');

        VolumeEncryption::init(null);
    }

    /**
     * Test encryptNewMasterKey throws when master key file missing
     */
    public function testEncryptNewMasterKeyThrowsWhenFileMissing(): void
    {
        if (file_exists('/etc/eiou/config/.master.key')) {
            $this->markTestSkipped('Master key exists (running in Docker)');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Master key file not found');

        VolumeEncryption::encryptNewMasterKey('test-passphrase');
    }
}
