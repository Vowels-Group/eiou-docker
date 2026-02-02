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
}
