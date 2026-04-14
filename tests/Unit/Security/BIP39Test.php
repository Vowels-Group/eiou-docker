<?php
/**
 * Unit Tests for BIP39 Mnemonic Implementation
 *
 * Tests BIP39 seed phrase generation, validation, and key derivation.
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Security\BIP39;

#[CoversClass(BIP39::class)]
class BIP39Test extends TestCase
{
    /**
     * Test generating 12-word mnemonic
     */
    public function testGenerateMnemonic12Words(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);
        $words = explode(' ', $mnemonic);

        $this->assertCount(12, $words);
        $this->assertTrue(BIP39::validateMnemonic($mnemonic));
    }

    /**
     * Test generating 24-word mnemonic
     */
    public function testGenerateMnemonic24Words(): void
    {
        $mnemonic = BIP39::generateMnemonic(24);
        $words = explode(' ', $mnemonic);

        $this->assertCount(24, $words);
        $this->assertTrue(BIP39::validateMnemonic($mnemonic));
    }

    /**
     * Test invalid word count throws exception
     */
    public function testGenerateMnemonicInvalidWordCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Word count must be 12 or 24');

        BIP39::generateMnemonic(15);
    }

    /**
     * Test mnemonic validation with valid phrase
     */
    public function testValidateMnemonicWithValidPhrase(): void
    {
        // Generate a valid mnemonic and verify it validates
        $mnemonic = BIP39::generateMnemonic(12);
        $this->assertTrue(BIP39::validateMnemonic($mnemonic));
    }

    /**
     * Test mnemonic validation with invalid word count
     */
    public function testValidateMnemonicWithInvalidWordCount(): void
    {
        $invalidMnemonic = 'abandon abandon abandon abandon abandon';
        $this->assertFalse(BIP39::validateMnemonic($invalidMnemonic));
    }

    /**
     * Test mnemonic validation with invalid words
     */
    public function testValidateMnemonicWithInvalidWords(): void
    {
        // 12 words but with invalid BIP39 words
        $invalidMnemonic = 'invalid word here abandon abandon abandon abandon abandon abandon abandon abandon abandon';
        $this->assertFalse(BIP39::validateMnemonic($invalidMnemonic));
    }

    /**
     * Test mnemonic to seed conversion is deterministic
     */
    public function testMnemonicToSeedIsDeterministic(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);

        $seed1 = BIP39::mnemonicToSeed($mnemonic);
        $seed2 = BIP39::mnemonicToSeed($mnemonic);

        $this->assertEquals($seed1, $seed2);
        $this->assertEquals(64, strlen($seed1)); // 64 bytes
    }

    /**
     * Test mnemonic to seed with passphrase produces different seed
     */
    public function testMnemonicToSeedWithPassphrase(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);

        $seedWithoutPassphrase = BIP39::mnemonicToSeed($mnemonic, '');
        $seedWithPassphrase = BIP39::mnemonicToSeed($mnemonic, 'my-passphrase');

        $this->assertNotEquals($seedWithoutPassphrase, $seedWithPassphrase);
    }

    /**
     * Test seed to private key seed returns hex string
     */
    public function testSeedToPrivateKeySeed(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);
        $seed = BIP39::mnemonicToSeed($mnemonic);

        $privateKeySeed = BIP39::seedToPrivateKeySeed($seed);

        $this->assertEquals(64, strlen($privateKeySeed)); // 32 bytes = 64 hex chars
        $this->assertTrue(ctype_xdigit($privateKeySeed));
    }

    /**
     * Test seed to key pair generates valid EC keys
     */
    public function testSeedToKeyPair(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);
        $seed = BIP39::mnemonicToSeed($mnemonic);

        $keyPair = BIP39::seedToKeyPair($seed);

        $this->assertArrayHasKey('private', $keyPair);
        $this->assertArrayHasKey('public', $keyPair);
        // OpenSSL may export as PKCS#8 (PRIVATE KEY) or SEC1 (EC PRIVATE KEY)
        $this->assertTrue(
            str_contains($keyPair['private'], 'BEGIN PRIVATE KEY') ||
            str_contains($keyPair['private'], 'BEGIN EC PRIVATE KEY'),
            'Private key should be in PEM format'
        );
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $keyPair['public']);
    }

    /**
     * Test seed to key pair is deterministic
     */
    public function testSeedToKeyPairIsDeterministic(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);
        $seed = BIP39::mnemonicToSeed($mnemonic);

        $keyPair1 = BIP39::seedToKeyPair($seed);
        $keyPair2 = BIP39::seedToKeyPair($seed);

        $this->assertEquals($keyPair1['private'], $keyPair2['private']);
        $this->assertEquals($keyPair1['public'], $keyPair2['public']);
    }

    /**
     * Test get preferred curve returns valid curve name
     */
    public function testGetPreferredCurve(): void
    {
        // Always secp256k1 — the fallback to prime256v1 was removed because a
        // prime-only node is effectively isolated (cannot parse any peer's
        // secp256k1 public key). BIP39::getPreferredCurve() now returns
        // secp256k1 or throws.
        $this->assertSame('secp256k1', BIP39::getPreferredCurve());
    }

    /**
     * Test get wordlist returns 2048 words
     */
    public function testGetWordlistReturns2048Words(): void
    {
        $wordlist = BIP39::getWordlist();

        $this->assertCount(2048, $wordlist);
    }

    /**
     * Test format mnemonic produces numbered lines
     */
    public function testFormatMnemonic(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);
        $formatted = BIP39::formatMnemonic($mnemonic);

        // Should contain numbered words
        $this->assertStringContainsString('1.', $formatted);
        $this->assertStringContainsString('12.', $formatted);
    }

    /**
     * Test secure clear clears data
     */
    public function testSecureClear(): void
    {
        $sensitiveData = 'secret-mnemonic-phrase';
        BIP39::secureClear($sensitiveData);

        $this->assertEquals('', $sensitiveData);
    }

    /**
     * Test seed to auth code generates deterministic code
     */
    public function testSeedToAuthCode(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);
        $seed = BIP39::mnemonicToSeed($mnemonic);

        $authCode1 = BIP39::seedToAuthCode($seed);
        $authCode2 = BIP39::seedToAuthCode($seed);

        $this->assertEquals($authCode1, $authCode2);
        $this->assertEquals(20, strlen($authCode1)); // Default length
        $this->assertTrue(ctype_xdigit($authCode1));
    }

    /**
     * Test seed to auth code with custom length
     */
    public function testSeedToAuthCodeCustomLength(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);
        $seed = BIP39::mnemonicToSeed($mnemonic);

        $authCode = BIP39::seedToAuthCode($seed, 32);

        $this->assertEquals(32, strlen($authCode));
    }

    /**
     * Test entropy to mnemonic produces valid mnemonic
     */
    public function testEntropyToMnemonic(): void
    {
        // 16 bytes entropy = 128 bits = 12 words
        $entropy = random_bytes(16);
        $mnemonic = BIP39::entropyToMnemonic($entropy);

        $words = explode(' ', $mnemonic);
        $this->assertCount(12, $words);
        $this->assertTrue(BIP39::validateMnemonic($mnemonic));
    }

    /**
     * Test different mnemonics produce different seeds
     */
    public function testDifferentMnemonicsProduceDifferentSeeds(): void
    {
        $mnemonic1 = BIP39::generateMnemonic(12);
        $mnemonic2 = BIP39::generateMnemonic(12);

        $seed1 = BIP39::mnemonicToSeed($mnemonic1);
        $seed2 = BIP39::mnemonicToSeed($mnemonic2);

        $this->assertNotEquals($seed1, $seed2);
    }

    /**
     * Test mnemonic normalization handles extra whitespace
     */
    public function testMnemonicNormalizationHandlesWhitespace(): void
    {
        $mnemonic = BIP39::generateMnemonic(12);

        // Add extra whitespace
        $mnemonicWithSpaces = '  ' . str_replace(' ', '   ', $mnemonic) . '  ';

        $seed1 = BIP39::mnemonicToSeed($mnemonic);
        $seed2 = BIP39::mnemonicToSeed($mnemonicWithSpaces);

        $this->assertEquals($seed1, $seed2);
    }
}
