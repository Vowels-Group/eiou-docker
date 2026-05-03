<?php
/**
 * Unit tests for AppConfig — typed env-driven config value object.
 * Pins the env-parsing rules at the boundary so a future env-name
 * rename / default change can't slip past review.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\AppConfig;
use Eiou\Core\Constants;

#[CoversClass(AppConfig::class)]
class AppConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    private const ENV_KEYS = [
        'PLUGIN_HOOKS_TRACE',
        'P2P_SSL_VERIFY',
        'P2P_CA_CERT',
        'TRUSTED_PROXIES',
        'SSL_EXTRA_SANS',
        'APP_ENV',
        'APP_DEBUG',
    ];

    protected function setUp(): void
    {
        // Snapshot every env var the value object reads so a stray
        // value in the host environment can't perturb the test.
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $original = $this->envSnapshot[$key] ?? false;
            if ($original === false) {
                putenv($key);
            } else {
                putenv("$key=$original");
            }
        }
    }

    public function testEmptyEnvironmentReturnsDefaults(): void
    {
        $cfg = AppConfig::fromEnvironment();

        $this->assertFalse($cfg->pluginHooksTrace);
        // P2P_SSL_VERIFY defaults to TRUE — strict-by-default per the
        // SSL hardening contract. Only the literal string 'false'
        // disables it.
        $this->assertTrue($cfg->p2pSslVerify);
        $this->assertNull($cfg->p2pCaCert);
        $this->assertSame('', $cfg->trustedProxies);
        $this->assertNull($cfg->sslExtraSans);
        $this->assertSame(Constants::APP_ENV, $cfg->appEnv);
        $this->assertSame(Constants::APP_DEBUG, $cfg->appDebug);
    }

    public function testPluginHooksTraceParsesBooleanish(): void
    {
        putenv('PLUGIN_HOOKS_TRACE=1');
        $this->assertTrue(AppConfig::fromEnvironment()->pluginHooksTrace);

        putenv('PLUGIN_HOOKS_TRACE=true');
        $this->assertTrue(AppConfig::fromEnvironment()->pluginHooksTrace);

        putenv('PLUGIN_HOOKS_TRACE=0');
        $this->assertFalse(AppConfig::fromEnvironment()->pluginHooksTrace);

        putenv('PLUGIN_HOOKS_TRACE=garbage');
        $this->assertFalse(AppConfig::fromEnvironment()->pluginHooksTrace);
    }

    public function testP2pSslVerifyOnlyDisabledOnLiteralFalse(): void
    {
        // Strict-by-default invariant: anything but the literal 'false'
        // string keeps verification ON. Pinning this prevents a
        // typo-fix in the future from accidentally relaxing the gate.
        putenv('P2P_SSL_VERIFY=false');
        $this->assertFalse(AppConfig::fromEnvironment()->p2pSslVerify);

        putenv('P2P_SSL_VERIFY=true');
        $this->assertTrue(AppConfig::fromEnvironment()->p2pSslVerify);

        putenv('P2P_SSL_VERIFY=0');
        $this->assertTrue(AppConfig::fromEnvironment()->p2pSslVerify);

        putenv('P2P_SSL_VERIFY=');
        $this->assertTrue(AppConfig::fromEnvironment()->p2pSslVerify);
    }

    public function testP2pCaCertEmptyStringIsNull(): void
    {
        // We treat empty-string the same as missing so callers can
        // safely `if ($cfg->p2pCaCert && file_exists(...))` without a
        // separate empty-check.
        putenv('P2P_CA_CERT=');
        $this->assertNull(AppConfig::fromEnvironment()->p2pCaCert);

        putenv('P2P_CA_CERT=/etc/ssl/certs/ca.pem');
        $this->assertSame('/etc/ssl/certs/ca.pem', AppConfig::fromEnvironment()->p2pCaCert);
    }

    public function testSslExtraSansEmptyStringIsNull(): void
    {
        putenv('SSL_EXTRA_SANS=');
        $this->assertNull(AppConfig::fromEnvironment()->sslExtraSans);

        putenv('SSL_EXTRA_SANS=DNS:my-host.local,IP:10.0.0.1');
        $this->assertSame('DNS:my-host.local,IP:10.0.0.1', AppConfig::fromEnvironment()->sslExtraSans);
    }

    public function testTrustedProxiesPassThrough(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1,10.0.0.2');
        $this->assertSame('10.0.0.1,10.0.0.2', AppConfig::fromEnvironment()->trustedProxies);
    }

    public function testAppEnvFallsBackWhenEmptyString(): void
    {
        // Empty string is treated as absent so a `set -e` script that
        // exports APP_ENV='' doesn't accidentally clobber the constant.
        putenv('APP_ENV=');
        $this->assertSame(Constants::APP_ENV, AppConfig::fromEnvironment()->appEnv);

        putenv('APP_ENV=production');
        $this->assertSame('production', AppConfig::fromEnvironment()->appEnv);
    }

    public function testWithOverridesProducesIndependentInstance(): void
    {
        $base = AppConfig::fromEnvironment();
        $modified = $base->withOverrides([
            'pluginHooksTrace' => true,
            'p2pSslVerify' => false,
            'p2pCaCert' => '/tmp/ca.pem',
            'trustedProxies' => '127.0.0.1',
        ]);

        $this->assertNotSame($base, $modified);
        $this->assertFalse($base->pluginHooksTrace, 'base unchanged');
        $this->assertTrue($base->p2pSslVerify, 'base unchanged');

        $this->assertTrue($modified->pluginHooksTrace);
        $this->assertFalse($modified->p2pSslVerify);
        $this->assertSame('/tmp/ca.pem', $modified->p2pCaCert);
        $this->assertSame('127.0.0.1', $modified->trustedProxies);
    }

    public function testWithOverridesAcceptsExplicitNullForNullableFields(): void
    {
        // p2pCaCert and sslExtraSans are nullable, so withOverrides
        // must distinguish "key absent → keep current" from "key present
        // and explicitly null → clear" via array_key_exists.
        $base = AppConfig::fromEnvironment()->withOverrides([
            'p2pCaCert' => '/tmp/ca.pem',
            'sslExtraSans' => 'DNS:foo',
        ]);

        $cleared = $base->withOverrides([
            'p2pCaCert' => null,
            'sslExtraSans' => null,
        ]);

        $this->assertNull($cleared->p2pCaCert);
        $this->assertNull($cleared->sslExtraSans);
    }
}
