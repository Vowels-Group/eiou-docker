<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\PluginSignatureVerifier;

/**
 * Tests the Ed25519 detached-signature verifier. Uses sodium's own keypair
 * generation so we never need real wire-format keys on disk — every test
 * mints its own ephemeral keypair in setUp.
 */
#[CoversClass(PluginSignatureVerifier::class)]
class PluginSignatureVerifierTest extends TestCase
{
    private string $tmpRoot;
    private string $pluginDir;
    private string $trustedKeysDir;
    private PluginSignatureVerifier $svc;

    /** @var string Raw 32-byte pubkey */
    private string $trustedPub;
    /** @var string Raw 64-byte secret key */
    private string $trustedSec;
    /** @var string Fingerprint of the trusted key */
    private string $trustedFp;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/sigtest-' . uniqid('', true);
        $this->pluginDir = $this->tmpRoot . '/my-plugin';
        $this->trustedKeysDir = $this->tmpRoot . '/trusted';
        mkdir($this->pluginDir . '/src', 0777, true);
        mkdir($this->trustedKeysDir, 0777, true);

        $kp = sodium_crypto_sign_keypair();
        $this->trustedSec = sodium_crypto_sign_secretkey($kp);
        $this->trustedPub = sodium_crypto_sign_publickey($kp);
        $this->trustedFp = 'sha256:' . hash('sha256', $this->trustedPub);

        // Stock plugin on disk.
        file_put_contents($this->pluginDir . '/plugin.json', json_encode([
            'name' => 'my-plugin',
            'version' => '1.0.0',
            'entryClass' => 'Eiou\\Plugins\\MyPlugin\\MyPlugin',
            'autoload' => ['psr-4' => ['Eiou\\Plugins\\MyPlugin\\' => 'src/']],
        ]));
        file_put_contents($this->pluginDir . '/src/MyPlugin.php', '<?php // stub');

        $this->svc = new PluginSignatureVerifier([$this->trustedKeysDir]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function testValidSignatureAgainstTrustedKeyReturnsOk(): void
    {
        $this->installTrustedKey($this->trustedPub, 'trusted.pub');
        $this->signPlugin($this->trustedSec, $this->trustedPub);

        $r = $this->svc->verify($this->pluginDir);
        $this->assertSame('ok', $r['status']);
        $this->assertSame($this->trustedFp, $r['key_fingerprint']);
    }

    public function testBuildSignedMessageIsDeterministicAcrossCalls(): void
    {
        $a = $this->svc->buildSignedMessage($this->pluginDir);
        $b = $this->svc->buildSignedMessage($this->pluginDir);
        $this->assertSame($a, $b);
        // Sanity: contains manifest bytes + NUL + hex hash
        $this->assertStringContainsString('"name":"my-plugin"', $a);
        $this->assertStringContainsString("\0", $a);
    }

    public function testFingerprintOfIsDeterministic(): void
    {
        $fp1 = PluginSignatureVerifier::fingerprintOf($this->trustedPub);
        $fp2 = PluginSignatureVerifier::fingerprintOf($this->trustedPub);
        $this->assertSame($fp1, $fp2);
        $this->assertStringStartsWith('sha256:', $fp1);
    }

    // =========================================================================
    // Negative — every rejection path
    // =========================================================================

    public function testUnsignedPluginReturnsUnsigned(): void
    {
        $this->installTrustedKey($this->trustedPub, 'trusted.pub');
        // No plugin.sig written.

        $this->assertSame('unsigned', $this->svc->verify($this->pluginDir)['status']);
    }

    public function testSignatureFromUntrustedKeyIsRejected(): void
    {
        $otherKp = sodium_crypto_sign_keypair();
        $otherSec = sodium_crypto_sign_secretkey($otherKp);
        $otherPub = sodium_crypto_sign_publickey($otherKp);

        // Don't install this key in the trust dir.
        $this->signPlugin($otherSec, $otherPub);

        $r = $this->svc->verify($this->pluginDir);
        $this->assertSame('untrusted_key', $r['status']);
        $this->assertSame(
            'sha256:' . hash('sha256', $otherPub),
            $r['key_fingerprint']
        );
    }

    public function testSignatureOverTamperedManifestFails(): void
    {
        $this->installTrustedKey($this->trustedPub, 'trusted.pub');
        $this->signPlugin($this->trustedSec, $this->trustedPub);

        // Tamper after signing — any byte change invalidates.
        file_put_contents(
            $this->pluginDir . '/plugin.json',
            str_replace('1.0.0', '1.0.1', (string) file_get_contents($this->pluginDir . '/plugin.json'))
        );

        $this->assertSame('bad_signature', $this->svc->verify($this->pluginDir)['status']);
    }

    public function testSignatureOverTamperedSourceFails(): void
    {
        $this->installTrustedKey($this->trustedPub, 'trusted.pub');
        $this->signPlugin($this->trustedSec, $this->trustedPub);

        // Tamper a source file — invalidates the src-tree hash.
        file_put_contents($this->pluginDir . '/src/MyPlugin.php', '<?php // tampered');

        $this->assertSame('bad_signature', $this->svc->verify($this->pluginDir)['status']);
    }

    public function testAddingANewSourceFileFails(): void
    {
        // Signature path covers the whole tree; a new file means the
        // src-tree hash changes.
        $this->installTrustedKey($this->trustedPub, 'trusted.pub');
        $this->signPlugin($this->trustedSec, $this->trustedPub);
        file_put_contents($this->pluginDir . '/src/Extra.php', '<?php // sneaky');

        $this->assertSame('bad_signature', $this->svc->verify($this->pluginDir)['status']);
    }

    public function testMalformedSigJsonReturnsMalformedSig(): void
    {
        file_put_contents($this->pluginDir . '/plugin.sig', 'not valid json');
        $r = $this->svc->verify($this->pluginDir);
        $this->assertSame('malformed_sig', $r['status']);
    }

    public function testUnsupportedAlgorithmRejected(): void
    {
        file_put_contents($this->pluginDir . '/plugin.sig', json_encode([
            'algorithm' => 'rsa-pkcs1',
            'key_fingerprint' => 'sha256:00',
            'signature' => base64_encode(str_repeat("\0", 64)),
        ]));
        $r = $this->svc->verify($this->pluginDir);
        $this->assertSame('malformed_sig', $r['status']);
        $this->assertStringContainsString('unsupported', $r['error']);
    }

    public function testEmptySignatureFieldRejected(): void
    {
        file_put_contents($this->pluginDir . '/plugin.sig', json_encode([
            'algorithm' => 'ed25519',
            'key_fingerprint' => $this->trustedFp,
            'signature' => '',
        ]));
        $r = $this->svc->verify($this->pluginDir);
        $this->assertSame('malformed_sig', $r['status']);
    }

    public function testWrongLengthSignatureRejected(): void
    {
        file_put_contents($this->pluginDir . '/plugin.sig', json_encode([
            'algorithm' => 'ed25519',
            'key_fingerprint' => $this->trustedFp,
            'signature' => base64_encode('short'), // 5 bytes — not 64
        ]));
        $r = $this->svc->verify($this->pluginDir);
        $this->assertSame('malformed_sig', $r['status']);
    }

    public function testMissingManifestReturnsMalformedManifest(): void
    {
        unlink($this->pluginDir . '/plugin.json');
        $this->assertSame(
            'malformed_manifest',
            $this->svc->verify($this->pluginDir)['status']
        );
    }

    // =========================================================================
    // Trusted-keys directory behaviour
    // =========================================================================

    public function testTrustedKeyFileWithCommentsAndBlankLinesIsParsed(): void
    {
        $content = "# eIOU test signer\n"
            . "# fingerprint: " . $this->trustedFp . "\n"
            . "\n"
            . base64_encode($this->trustedPub) . "\n";
        file_put_contents($this->trustedKeysDir . '/trusted.pub', $content);

        $this->signPlugin($this->trustedSec, $this->trustedPub);
        $this->assertSame('ok', $this->svc->verify($this->pluginDir)['status']);
    }

    public function testMultipleKeysInOneFileAllLoaded(): void
    {
        $kp2 = sodium_crypto_sign_keypair();
        $pub2 = sodium_crypto_sign_publickey($kp2);

        $content = base64_encode($this->trustedPub) . "\n"
            . base64_encode($pub2) . "\n";
        file_put_contents($this->trustedKeysDir . '/both.pub', $content);

        $fps = $this->svc->listTrustedFingerprints();
        $this->assertContains($this->trustedFp, $fps);
        $this->assertContains('sha256:' . hash('sha256', $pub2), $fps);
    }

    public function testDuplicateKeysAcrossFilesAreDeduplicated(): void
    {
        file_put_contents($this->trustedKeysDir . '/a.pub', base64_encode($this->trustedPub) . "\n");
        file_put_contents($this->trustedKeysDir . '/b.pub', base64_encode($this->trustedPub) . "\n");

        $fps = $this->svc->listTrustedFingerprints();
        // Only one fingerprint entry even though two files referenced it.
        $this->assertCount(1, $fps);
    }

    public function testMultipleKeyDirsAreAllScanned(): void
    {
        $dir2 = $this->tmpRoot . '/trusted-builtin';
        mkdir($dir2, 0777, true);
        file_put_contents($dir2 . '/builtin.pub', base64_encode($this->trustedPub) . "\n");

        $svc2 = new PluginSignatureVerifier([$this->trustedKeysDir, $dir2]);
        $this->signPlugin($this->trustedSec, $this->trustedPub);
        $this->assertSame('ok', $svc2->verify($this->pluginDir)['status']);
    }

    public function testMissingKeyDirsAreSkippedSilently(): void
    {
        $svc2 = new PluginSignatureVerifier([
            $this->trustedKeysDir,
            '/nonexistent/path',
        ]);
        $this->installTrustedKey($this->trustedPub, 'trusted.pub');
        $this->signPlugin($this->trustedSec, $this->trustedPub);
        $this->assertSame('ok', $svc2->verify($this->pluginDir)['status']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Install a raw pubkey into the trust dir as a .pub file. */
    private function installTrustedKey(string $pub, string $filename): void
    {
        file_put_contents(
            $this->trustedKeysDir . '/' . $filename,
            base64_encode($pub) . "\n"
        );
    }

    /** Produce a plugin.sig signed with the given keypair over the current plugin state. */
    private function signPlugin(string $sec, string $pub): void
    {
        $message = $this->svc->buildSignedMessage($this->pluginDir);
        $sig = sodium_crypto_sign_detached($message, $sec);
        file_put_contents($this->pluginDir . '/plugin.sig', json_encode([
            'algorithm' => 'ed25519',
            'key_fingerprint' => 'sha256:' . hash('sha256', $pub),
            'signature' => base64_encode($sig),
        ]));
    }

    private function rmrf(string $p): void
    {
        if (!is_dir($p)) { if (is_file($p)) unlink($p); return; }
        foreach ((array) scandir($p) as $e) {
            if ($e === '.' || $e === '..') continue;
            $this->rmrf($p . '/' . $e);
        }
        rmdir($p);
    }
}
