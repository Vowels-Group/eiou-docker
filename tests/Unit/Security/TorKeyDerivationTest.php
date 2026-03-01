<?php
/**
 * Unit Tests for TorKeyDerivation
 *
 * Tests Tor v3 hidden service key derivation from BIP39 seeds.
 * Requires sodium extension for Ed25519 operations.
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Eiou\Security\TorKeyDerivation;
use Eiou\Security\BIP39;

#[CoversClass(TorKeyDerivation::class)]
#[RequiresPhpExtension('sodium')]
class TorKeyDerivationTest extends TestCase
{
    private string $testSeed;

    protected function setUp(): void
    {
        // Generate a test seed from a mnemonic
        $mnemonic = BIP39::generateMnemonic(12);
        $this->testSeed = BIP39::mnemonicToSeed($mnemonic);
    }

    /**
     * Test deriveFromSeed returns expected keys
     */
    public function testDeriveFromSeedReturnsExpectedKeys(): void
    {
        $keys = TorKeyDerivation::deriveFromSeed($this->testSeed);

        $this->assertArrayHasKey('secret_key', $keys);
        $this->assertArrayHasKey('public_key', $keys);
        $this->assertArrayHasKey('hostname', $keys);
    }

    /**
     * Test deriveFromSeed produces correct key lengths
     */
    public function testDeriveFromSeedProducesCorrectKeyLengths(): void
    {
        $keys = TorKeyDerivation::deriveFromSeed($this->testSeed);

        // Expanded secret key is 64 bytes (clamped scalar + hash prefix)
        $this->assertEquals(64, strlen($keys['secret_key']));

        // Ed25519 public key is 32 bytes
        $this->assertEquals(32, strlen($keys['public_key']));
    }

    /**
     * Test deriveFromSeed is deterministic
     */
    public function testDeriveFromSeedIsDeterministic(): void
    {
        $keys1 = TorKeyDerivation::deriveFromSeed($this->testSeed);
        $keys2 = TorKeyDerivation::deriveFromSeed($this->testSeed);

        $this->assertEquals($keys1['secret_key'], $keys2['secret_key']);
        $this->assertEquals($keys1['public_key'], $keys2['public_key']);
        $this->assertEquals($keys1['hostname'], $keys2['hostname']);
    }

    /**
     * Test different seeds produce different keys
     */
    public function testDifferentSeedsProduceDifferentKeys(): void
    {
        $mnemonic2 = BIP39::generateMnemonic(12);
        $seed2 = BIP39::mnemonicToSeed($mnemonic2);

        $keys1 = TorKeyDerivation::deriveFromSeed($this->testSeed);
        $keys2 = TorKeyDerivation::deriveFromSeed($seed2);

        $this->assertNotEquals($keys1['hostname'], $keys2['hostname']);
    }

    /**
     * Test publicKeyToOnion generates valid .onion address
     */
    public function testPublicKeyToOnionGeneratesValidAddress(): void
    {
        $keys = TorKeyDerivation::deriveFromSeed($this->testSeed);
        $hostname = $keys['hostname'];

        // Tor v3 onion addresses are 56 characters + ".onion"
        $this->assertStringEndsWith('.onion', $hostname);
        $this->assertEquals(62, strlen($hostname)); // 56 + 6 for ".onion"
    }

    /**
     * Test publicKeyToOnion throws on invalid key length
     */
    public function testPublicKeyToOnionThrowsOnInvalidKeyLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Public key must be 32 bytes');

        TorKeyDerivation::publicKeyToOnion('invalid-short-key');
    }

    /**
     * Test publicKeyToOnion produces lowercase address
     */
    public function testPublicKeyToOnionProducesLowercaseAddress(): void
    {
        $keys = TorKeyDerivation::deriveFromSeed($this->testSeed);

        // Remove .onion suffix and check if lowercase
        $address = substr($keys['hostname'], 0, -6);
        $this->assertEquals(strtolower($address), $address);
    }

    /**
     * Test publicKeyToOnion is deterministic
     */
    public function testPublicKeyToOnionIsDeterministic(): void
    {
        $keys = TorKeyDerivation::deriveFromSeed($this->testSeed);

        $hostname1 = TorKeyDerivation::publicKeyToOnion($keys['public_key']);
        $hostname2 = TorKeyDerivation::publicKeyToOnion($keys['public_key']);

        $this->assertEquals($hostname1, $hostname2);
    }

    /**
     * Test onion address contains only valid base32 characters
     */
    public function testOnionAddressContainsValidBase32Characters(): void
    {
        $keys = TorKeyDerivation::deriveFromSeed($this->testSeed);
        $address = substr($keys['hostname'], 0, -6); // Remove .onion

        // RFC 4648 Base32 alphabet (lowercase)
        $this->assertMatchesRegularExpression('/^[a-z2-7]+$/', $address);
    }

    /**
     * Test deriveFromSeed throws without sodium extension
     */
    public function testDeriveFromSeedRequiresSodium(): void
    {
        // This test only makes sense if we could disable sodium
        // Since we can't, we just verify the method works with sodium
        if (!extension_loaded('sodium')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Sodium extension required');
            TorKeyDerivation::deriveFromSeed($this->testSeed);
        } else {
            // Sodium is loaded, derivation should work
            $keys = TorKeyDerivation::deriveFromSeed($this->testSeed);
            $this->assertNotEmpty($keys['hostname']);
        }
    }

    /**
     * Test verifyKeysMatchSeed returns false when directory doesn't exist
     */
    public function testVerifyKeysMatchSeedReturnsFalseForNonexistentDirectory(): void
    {
        $result = TorKeyDerivation::verifyKeysMatchSeed(
            $this->testSeed,
            '/nonexistent/directory/path'
        );

        $this->assertFalse($result);
    }

    /**
     * Test generateHiddenServiceFiles creates files with restrictive permissions (L-31)
     *
     * Verifies that umask is set to 0077 during file creation so files are
     * never world-readable, even briefly between file_put_contents() and chmod().
     */
    public function testGenerateHiddenServiceFilesCreatesRestrictivePermissions(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tor_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700);

        try {
            TorKeyDerivation::generateHiddenServiceFiles($this->testSeed, $tmpDir);

            // Verify all three files exist
            $this->assertFileExists($tmpDir . '/hs_ed25519_secret_key');
            $this->assertFileExists($tmpDir . '/hs_ed25519_public_key');
            $this->assertFileExists($tmpDir . '/hostname');

            // Verify permissions are 0600 (owner read/write only)
            foreach (['hs_ed25519_secret_key', 'hs_ed25519_public_key', 'hostname'] as $file) {
                $perms = fileperms($tmpDir . '/' . $file) & 0777;
                $this->assertEquals(0600, $perms, "$file should have 0600 permissions, got " . decoct($perms));
            }
        } finally {
            // Clean up
            foreach (['hs_ed25519_secret_key', 'hs_ed25519_public_key', 'hostname'] as $file) {
                @unlink($tmpDir . '/' . $file);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Test generateHiddenServiceFiles restores umask after completion (L-31)
     */
    public function testGenerateHiddenServiceFilesRestoresUmask(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tor_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700);

        $originalUmask = umask();

        try {
            TorKeyDerivation::generateHiddenServiceFiles($this->testSeed, $tmpDir);

            $afterUmask = umask();
            $this->assertEquals($originalUmask, $afterUmask, 'umask should be restored after generateHiddenServiceFiles');
        } finally {
            umask($originalUmask);
            foreach (['hs_ed25519_secret_key', 'hs_ed25519_public_key', 'hostname'] as $file) {
                @unlink($tmpDir . '/' . $file);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Test generateHiddenServiceFiles restores umask even on failure (L-31)
     */
    public function testGenerateHiddenServiceFilesRestoresUmaskOnFailure(): void
    {
        $originalUmask = umask();

        try {
            TorKeyDerivation::generateHiddenServiceFiles($this->testSeed, '/nonexistent/path');
        } catch (\RuntimeException $e) {
            // Expected
        }

        $afterUmask = umask();
        umask($originalUmask);
        $this->assertEquals($originalUmask, $afterUmask, 'umask should be restored even when generateHiddenServiceFiles fails');
    }

    /**
     * Test generateHiddenServiceFiles writes correct file contents
     */
    public function testGenerateHiddenServiceFilesWritesCorrectContents(): void
    {
        $tmpDir = sys_get_temp_dir() . '/tor_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700);

        try {
            $hostname = TorKeyDerivation::generateHiddenServiceFiles($this->testSeed, $tmpDir);

            // Hostname should be a valid .onion address
            $this->assertStringEndsWith('.onion', $hostname);
            $this->assertEquals(62, strlen($hostname));

            // hostname file should match returned hostname
            $fileHostname = trim(file_get_contents($tmpDir . '/hostname'));
            $this->assertEquals($hostname, $fileHostname);

            // Secret key file should have 32-byte header + 64-byte key = 96 bytes
            $secretKey = file_get_contents($tmpDir . '/hs_ed25519_secret_key');
            $this->assertEquals(96, strlen($secretKey));

            // Public key file should have 32-byte header + 32-byte key = 64 bytes
            $publicKey = file_get_contents($tmpDir . '/hs_ed25519_public_key');
            $this->assertEquals(64, strlen($publicKey));
        } finally {
            foreach (['hs_ed25519_secret_key', 'hs_ed25519_public_key', 'hostname'] as $file) {
                @unlink($tmpDir . '/' . $file);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Test generateHiddenServiceFiles throws when directory doesn't exist
     */
    public function testGenerateHiddenServiceFilesThrowsForNonexistentDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        TorKeyDerivation::generateHiddenServiceFiles($this->testSeed, '/nonexistent/path');
    }
}
