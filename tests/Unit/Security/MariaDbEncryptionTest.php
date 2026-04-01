<?php
/**
 * Unit Tests for MariaDbEncryption
 *
 * Tests the MariaDB Transparent Data Encryption setup service.
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Security\MariaDbEncryption;

#[CoversClass(MariaDbEncryption::class)]
class MariaDbEncryptionTest extends TestCase
{
    /**
     * Test getStatus returns expected keys
     */
    public function testGetStatusReturnsExpectedKeys(): void
    {
        $status = MariaDbEncryption::getStatus();

        $this->assertArrayHasKey('key_file_exists', $status);
        $this->assertArrayHasKey('config_exists', $status);
        $this->assertArrayHasKey('initialized', $status);
    }

    /**
     * Test getStatus values are booleans
     */
    public function testGetStatusValuesAreBooleans(): void
    {
        $status = MariaDbEncryption::getStatus();

        foreach ($status as $key => $value) {
            $this->assertIsBool($value, "Status key '$key' should be boolean");
        }
    }

    /**
     * Test isKeyFileReady returns false when no key file exists
     */
    public function testIsKeyFileReadyReturnsFalseWithoutKeyFile(): void
    {
        if (file_exists('/dev/shm/.mariadb-encryption-key')) {
            $this->markTestSkipped('TDE key file exists (active session)');
        }

        $this->assertFalse(MariaDbEncryption::isKeyFileReady());
    }

    /**
     * Test isInitialized returns false when marker file missing
     */
    public function testIsInitializedReturnsFalseWithoutMarker(): void
    {
        if (file_exists('/etc/eiou/config/.tde_initialized')) {
            $this->markTestSkipped('TDE marker exists (running in Docker)');
        }

        $this->assertFalse(MariaDbEncryption::isInitialized());
    }

    /**
     * Test setupKeyFile throws when master key not available
     */
    public function testSetupKeyFileThrowsWhenNoMasterKey(): void
    {
        if (file_exists('/dev/shm/.master.key') || file_exists('/etc/eiou/config/.master.key')) {
            $this->markTestSkipped('Master key exists (running in Docker)');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('master key not available');

        MariaDbEncryption::setupKeyFile();
    }
}
