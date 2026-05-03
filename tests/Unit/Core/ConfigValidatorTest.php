<?php
/**
 * Unit tests for ConfigValidator — boot-time configuration validator.
 *
 * Pins the rule set at the boundary: production+debug, production+ssl-off,
 * missing CA cert, malformed dbconfig.json, malformed JSON config files,
 * malformed TRUSTED_PROXIES entries.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\AppConfig;
use Eiou\Core\ConfigValidator;

#[CoversClass(ConfigValidator::class)]
class ConfigValidatorTest extends TestCase
{
    private string $tempConfigDir = '';

    protected function setUp(): void
    {
        $this->tempConfigDir = sys_get_temp_dir() . '/eiou-config-validator-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempConfigDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempConfigDir !== '' && is_dir($this->tempConfigDir)) {
            foreach (glob($this->tempConfigDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempConfigDir);
        }
    }

    private function makeConfig(array $overrides = []): AppConfig
    {
        $base = new AppConfig(
            pluginHooksTrace: false,
            p2pSslVerify: true,
            p2pCaCert: null,
            trustedProxies: '',
            sslExtraSans: null,
            appEnv: 'development',
            appDebug: true,
        );
        return $base->withOverrides($overrides);
    }

    private function findIssue(array $issues, string $code): ?array
    {
        foreach ($issues as $issue) {
            if ($issue['code'] === $code) {
                return $issue;
            }
        }
        return null;
    }

    public function testCleanDevConfigProducesNoIssues(): void
    {
        // Default development config + nothing on disk => clean.
        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $this->assertSame([], $validator->validate());
    }

    public function testProdWithDebugWarns(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['appEnv' => 'production', 'appDebug' => true]),
            $this->tempConfigDir
        );
        $issue = $this->findIssue($validator->validate(), 'prod_debug_enabled');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
    }

    public function testProdWithoutDebugIsClean(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['appEnv' => 'production', 'appDebug' => false]),
            $this->tempConfigDir
        );
        $this->assertNull($this->findIssue($validator->validate(), 'prod_debug_enabled'));
    }

    public function testProdWithoutSslVerifyIsError(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['appEnv' => 'production', 'p2pSslVerify' => false, 'appDebug' => false]),
            $this->tempConfigDir
        );
        $issue = $this->findIssue($validator->validate(), 'prod_ssl_verify_disabled');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
    }

    public function testDevWithoutSslVerifyIsClean(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['p2pSslVerify' => false]),
            $this->tempConfigDir
        );
        $this->assertNull($this->findIssue($validator->validate(), 'prod_ssl_verify_disabled'));
    }

    public function testCaCertPathExisting(): void
    {
        $caPath = $this->tempConfigDir . '/ca.pem';
        file_put_contents($caPath, "-----BEGIN CERTIFICATE-----\n");

        $validator = new ConfigValidator(
            $this->makeConfig(['p2pCaCert' => $caPath]),
            $this->tempConfigDir
        );
        $this->assertNull($this->findIssue($validator->validate(), 'p2p_ca_cert_missing'));
    }

    public function testCaCertPathMissing(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['p2pCaCert' => $this->tempConfigDir . '/does-not-exist.pem']),
            $this->tempConfigDir
        );
        $issue = $this->findIssue($validator->validate(), 'p2p_ca_cert_missing');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
    }

    /**
     * Helper: a valid TDE-style dbconfig.json (post-migration shape).
     * Real values are produced by KeyEncryption::encrypt() at runtime;
     * the validator only checks for non-empty arrays, so a shape stub
     * is enough.
     */
    private function tdeBlob(): array
    {
        return [
            'ciphertext' => 'AAAA',
            'iv' => 'BBBB',
            'tag' => 'CCCC',
            'version' => 2,
            'aad' => '',
        ];
    }

    /**
     * Helper: signal post-wallet state by writing a fake master key.
     * The validator only checks file_exists, not the content, so a
     * 32-byte placeholder is enough.
     */
    private function markPostWallet(): void
    {
        file_put_contents($this->tempConfigDir . '/.master.key', str_repeat('A', 32));
    }

    public function testDbConfigMissingEncryptedCredentialIsError(): void
    {
        // Post-migration shape with one encrypted blob missing.
        $this->markPostWallet();
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => $this->tdeBlob(),
            // dbPassEncrypted missing
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_missing_fields');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertStringContainsString('dbPassEncrypted', $issue['message']);
    }

    public function testDbConfigEmptyDbHostIsMissing(): void
    {
        $this->markPostWallet();
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => '',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => $this->tdeBlob(),
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_missing_fields');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('dbHost', $issue['message']);
    }

    public function testDbConfigPostMigrationShapeIsClean(): void
    {
        // The shape Application::migrateDbConfigEncryption() leaves on disk:
        // plaintext dbHost + three encrypted blobs. Should pass cleanly
        // (with the master key file present, signalling post-wallet state).
        file_put_contents($this->tempConfigDir . '/.master.key', str_repeat('A', 32));
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => $this->tdeBlob(),
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $this->assertNull($this->findIssue($validator->validate(), 'dbconfig_missing_fields'));
        $this->assertNull($this->findIssue($validator->validate(), 'dbconfig_plaintext_credentials'));
    }

    public function testDbConfigPreWalletBootstrapShapeIsClean(): void
    {
        // First-boot, before generateWallet/restoreWallet creates the master key.
        // Plaintext credentials are legitimate here — Application::migrate-
        // DbConfigEncryption() will re-encrypt them as soon as the master key
        // exists. The validator must NOT fire the post-wallet errors during
        // this transient state. (No /.master.key in tempConfigDir.)
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbName' => 'eiou',
            'dbUser' => 'eiou',
            'dbPass' => 'bootstrap-secret',
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issues = $validator->validate();
        $this->assertNull($this->findIssue($issues, 'dbconfig_missing_fields'));
        $this->assertNull($this->findIssue($issues, 'dbconfig_plaintext_credentials'));
        $this->assertNull($this->findIssue($issues, 'dbconfig_malformed_encrypted_blob'));
        $this->assertNull($this->findIssue($issues, 'dbconfig_no_credentials'));
    }

    public function testDbConfigPreWalletWithNoCredentialsIsError(): void
    {
        // dbconfig.json with only dbHost — no plaintext, no encrypted —
        // pre-wallet bootstrap can't proceed because nothing to migrate.
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_no_credentials');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
    }

    public function testDbConfigEncryptedWithoutMasterKeyIsError(): void
    {
        // Encrypted blobs on disk but master key missing — broken state.
        // Either someone deleted the master key, restored from a partial
        // backup, or tampered with the file. Either way it's unrecoverable
        // without restoring the key (or regenerating from seed).
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => $this->tdeBlob(),
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_encrypted_without_master_key');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
    }

    public function testDbConfigMalformedEncryptedBlobAsStringIsFlagged(): void
    {
        // Someone tries to slip a plaintext value into an "encrypted" slot.
        $this->markPostWallet();
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => 'Dave',  // ← plaintext as a string
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_malformed_encrypted_blob');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertStringContainsString('dbUserEncrypted', $issue['message']);
    }

    public function testDbConfigMalformedEncryptedBlobMissingKeysIsFlagged(): void
    {
        // Object with the right name but the wrong keys.
        $this->markPostWallet();
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => ['foo' => 'bar'],  // ← no ciphertext/iv/tag
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_malformed_encrypted_blob');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('dbUserEncrypted', $issue['message']);
    }

    public function testDbConfigMalformedEncryptedBlobWrongIvLengthIsFlagged(): void
    {
        // IV decodes to 4 bytes (should be 12 for AES-256-GCM).
        $this->markPostWallet();
        $bad = [
            'ciphertext' => 'AAAA',
            'iv' => base64_encode('1234'),  // 4 bytes — wrong
            'tag' => base64_encode(str_repeat('A', 16)),
            'version' => 2,
            'aad' => '',
        ];
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => $bad,
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_malformed_encrypted_blob');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('dbUserEncrypted', $issue['message']);
    }

    public function testDbConfigMalformedEncryptedBlobMissingVersionIsFlagged(): void
    {
        // No version field — could be a downgraded v1 blob attack.
        $this->markPostWallet();
        $bad = [
            'ciphertext' => 'AAAA',
            'iv' => base64_encode(str_repeat('A', 12)),
            'tag' => base64_encode(str_repeat('A', 16)),
            // version omitted
            'aad' => '',
        ];
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => $bad,
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_malformed_encrypted_blob');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('dbUserEncrypted', $issue['message']);
    }

    public function testDbConfigPlaintextCredentialsAreFlagged(): void
    {
        // Migration didn't complete or hand-edited file: plaintext is
        // present alongside (or instead of) encrypted blobs. Validator
        // must flag this as a separate error so the operator notices.
        $this->markPostWallet();
        file_put_contents($this->tempConfigDir . '/dbconfig.json', json_encode([
            'dbHost' => 'localhost',
            'dbName' => 'eiou',
            'dbUser' => 'eiou',
            'dbPass' => 'secret',
            'dbNameEncrypted' => $this->tdeBlob(),
            'dbUserEncrypted' => $this->tdeBlob(),
            'dbPassEncrypted' => $this->tdeBlob(),
        ]));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_plaintext_credentials');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertStringContainsString('dbName', $issue['message']);
        $this->assertStringContainsString('dbPass', $issue['message']);
    }

    public function testDbConfigInvalidJsonIsError(): void
    {
        file_put_contents($this->tempConfigDir . '/dbconfig.json', '{ not valid json');

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'dbconfig_invalid_json');
        $this->assertNotNull($issue);
        $this->assertSame('error', $issue['severity']);
    }

    public function testDbConfigAbsentIsClean(): void
    {
        // Fresh-install path: dbconfig.json doesn't exist yet.
        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $this->assertNull($this->findIssue($validator->validate(), 'dbconfig_invalid_json'));
        $this->assertNull($this->findIssue($validator->validate(), 'dbconfig_missing_fields'));
    }

    public function testDefaultConfigInvalidJsonWarns(): void
    {
        file_put_contents($this->tempConfigDir . '/defaultconfig.json', '{ corrupt');

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'config_invalid_json');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertStringContainsString('defaultconfig.json', $issue['message']);
    }

    public function testEmptyJsonConfigWarns(): void
    {
        file_put_contents($this->tempConfigDir . '/userconfig.json', '');

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $issue = $this->findIssue($validator->validate(), 'config_unreadable');
        $this->assertNotNull($issue);
        $this->assertStringContainsString('userconfig.json', $issue['message']);
    }

    public function testValidJsonConfigIsClean(): void
    {
        file_put_contents($this->tempConfigDir . '/userconfig.json', json_encode(['displayName' => 'alice']));

        $validator = new ConfigValidator($this->makeConfig(), $this->tempConfigDir);
        $this->assertNull($this->findIssue($validator->validate(), 'config_invalid_json'));
        $this->assertNull($this->findIssue($validator->validate(), 'config_unreadable'));
    }

    public function testTrustedProxiesIpv4Cidr(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['trustedProxies' => '10.0.0.0/8, 192.168.1.0/24, 127.0.0.1']),
            $this->tempConfigDir
        );
        $this->assertNull($this->findIssue($validator->validate(), 'trusted_proxies_malformed'));
    }

    public function testTrustedProxiesIpv6Cidr(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['trustedProxies' => '::1, fe80::/10']),
            $this->tempConfigDir
        );
        $this->assertNull($this->findIssue($validator->validate(), 'trusted_proxies_malformed'));
    }

    public function testTrustedProxiesMalformedWarns(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['trustedProxies' => '10.0.0.0/8, not-an-ip, 192.168.1.0/99']),
            $this->tempConfigDir
        );
        $issue = $this->findIssue($validator->validate(), 'trusted_proxies_malformed');
        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertStringContainsString('not-an-ip', $issue['message']);
        $this->assertStringContainsString('192.168.1.0/99', $issue['message']);
    }

    public function testTrustedProxiesEmptyIsClean(): void
    {
        $validator = new ConfigValidator(
            $this->makeConfig(['trustedProxies' => '']),
            $this->tempConfigDir
        );
        $this->assertNull($this->findIssue($validator->validate(), 'trusted_proxies_malformed'));
    }

    public function testFromEnvironmentBuildsValidator(): void
    {
        // Smoke test: factory wires AppConfig from process env.
        $validator = ConfigValidator::fromEnvironment($this->tempConfigDir);
        // Should not throw and should produce a (possibly empty) issue list.
        $this->assertIsArray($validator->validate());
    }
}
