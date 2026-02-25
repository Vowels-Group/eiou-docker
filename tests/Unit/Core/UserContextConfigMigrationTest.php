<?php
/**
 * Unit Tests for UserContext Config Migration
 *
 * Tests the getConfigurableDefaults() method and config migration logic
 * that adds missing keys to defaultconfig.json without overwriting user values.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use ReflectionClass;

#[CoversClass(UserContext::class)]
class UserContextConfigMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
    }

    private function resetSingleton(): void
    {
        $reflection = new ReflectionClass(UserContext::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    /**
     * Test getConfigurableDefaults returns expected keys
     */
    public function testGetConfigurableDefaultsReturnsExpectedKeys(): void
    {
        $defaults = UserContext::getConfigurableDefaults();

        $this->assertIsArray($defaults);

        // Original 11 settings
        $this->assertArrayHasKey('defaultCurrency', $defaults);
        $this->assertArrayHasKey('minFee', $defaults);
        $this->assertArrayHasKey('defaultFee', $defaults);
        $this->assertArrayHasKey('maxFee', $defaults);
        $this->assertArrayHasKey('defaultCreditLimit', $defaults);
        $this->assertArrayHasKey('maxP2pLevel', $defaults);
        $this->assertArrayHasKey('p2pExpiration', $defaults);
        $this->assertArrayHasKey('maxOutput', $defaults);
        $this->assertArrayHasKey('defaultTransportMode', $defaults);
        $this->assertArrayHasKey('autoRefreshEnabled', $defaults);
        $this->assertArrayHasKey('autoBackupEnabled', $defaults);

        // Feature toggles
        $this->assertArrayHasKey('contactStatusEnabled', $defaults);
        $this->assertArrayHasKey('contactStatusSyncOnPing', $defaults);
        $this->assertArrayHasKey('autoChainDropPropose', $defaults);
        $this->assertArrayHasKey('autoChainDropAccept', $defaults);
        $this->assertArrayHasKey('apiEnabled', $defaults);
        $this->assertArrayHasKey('apiCorsAllowedOrigins', $defaults);
        $this->assertArrayHasKey('rateLimitEnabled', $defaults);

        // Backup & logging
        $this->assertArrayHasKey('backupRetentionCount', $defaults);
        $this->assertArrayHasKey('backupCronHour', $defaults);
        $this->assertArrayHasKey('backupCronMinute', $defaults);
        $this->assertArrayHasKey('logLevel', $defaults);
        $this->assertArrayHasKey('logMaxEntries', $defaults);

        // Data retention
        $this->assertArrayHasKey('cleanupDeliveryRetentionDays', $defaults);
        $this->assertArrayHasKey('cleanupDlqRetentionDays', $defaults);
        $this->assertArrayHasKey('cleanupHeldTxRetentionDays', $defaults);
        $this->assertArrayHasKey('cleanupRp2pRetentionDays', $defaults);
        $this->assertArrayHasKey('cleanupMetricsRetentionDays', $defaults);

        // Rate limiting
        $this->assertArrayHasKey('p2pRateLimitPerMinute', $defaults);
        $this->assertArrayHasKey('rateLimitMaxAttempts', $defaults);
        $this->assertArrayHasKey('rateLimitWindowSeconds', $defaults);
        $this->assertArrayHasKey('rateLimitBlockSeconds', $defaults);

        // Network
        $this->assertArrayHasKey('httpTransportTimeoutSeconds', $defaults);
        $this->assertArrayHasKey('torTransportTimeoutSeconds', $defaults);

        // Display
        $this->assertArrayHasKey('displayDateFormat', $defaults);
        $this->assertArrayHasKey('displayCurrencyDecimals', $defaults);
        $this->assertArrayHasKey('displayRecentTransactionsLimit', $defaults);
    }

    /**
     * Test getConfigurableDefaults values match Constants
     */
    public function testGetConfigurableDefaultsValuesMatchConstants(): void
    {
        $defaults = UserContext::getConfigurableDefaults();

        $this->assertSame(Constants::TRANSACTION_DEFAULT_CURRENCY, $defaults['defaultCurrency']);
        $this->assertSame(Constants::TRANSACTION_MINIMUM_FEE, $defaults['minFee']);
        $this->assertSame(Constants::CONTACT_DEFAULT_FEE_PERCENT, $defaults['defaultFee']);
        $this->assertSame(Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX, $defaults['maxFee']);
        $this->assertSame(Constants::CONTACT_DEFAULT_CREDIT_LIMIT, $defaults['defaultCreditLimit']);
        $this->assertSame(Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL, $defaults['maxP2pLevel']);
        $this->assertSame(Constants::P2P_DEFAULT_EXPIRATION_SECONDS, $defaults['p2pExpiration']);
        $this->assertSame(Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX, $defaults['maxOutput']);
        $this->assertSame(Constants::DEFAULT_TRANSPORT_MODE, $defaults['defaultTransportMode']);
        $this->assertSame(Constants::AUTO_REFRESH_ENABLED, $defaults['autoRefreshEnabled']);
        $this->assertSame(Constants::BACKUP_AUTO_ENABLED, $defaults['autoBackupEnabled']);
        $this->assertSame(Constants::CONTACT_STATUS_ENABLED, $defaults['contactStatusEnabled']);
        $this->assertSame(Constants::CONTACT_STATUS_SYNC_ON_PING, $defaults['contactStatusSyncOnPing']);
        $this->assertSame(Constants::AUTO_CHAIN_DROP_PROPOSE, $defaults['autoChainDropPropose']);
        $this->assertSame(Constants::AUTO_CHAIN_DROP_ACCEPT, $defaults['autoChainDropAccept']);
        $this->assertSame(Constants::API_ENABLED, $defaults['apiEnabled']);
        $this->assertSame(Constants::API_CORS_ALLOWED_ORIGINS, $defaults['apiCorsAllowedOrigins']);
        $this->assertSame(Constants::RATE_LIMIT_ENABLED, $defaults['rateLimitEnabled']);
        $this->assertSame(Constants::BACKUP_RETENTION_COUNT, $defaults['backupRetentionCount']);
        $this->assertSame(Constants::BACKUP_CRON_HOUR, $defaults['backupCronHour']);
        $this->assertSame(Constants::BACKUP_CRON_MINUTE, $defaults['backupCronMinute']);
        $this->assertSame(Constants::LOG_LEVEL, $defaults['logLevel']);
        $this->assertSame(Constants::LOG_MAX_ENTRIES, $defaults['logMaxEntries']);
        $this->assertSame(Constants::CLEANUP_DELIVERY_RETENTION_DAYS, $defaults['cleanupDeliveryRetentionDays']);
        $this->assertSame(Constants::CLEANUP_DLQ_RETENTION_DAYS, $defaults['cleanupDlqRetentionDays']);
        $this->assertSame(Constants::CLEANUP_HELD_TX_RETENTION_DAYS, $defaults['cleanupHeldTxRetentionDays']);
        $this->assertSame(Constants::CLEANUP_RP2P_RETENTION_DAYS, $defaults['cleanupRp2pRetentionDays']);
        $this->assertSame(Constants::CLEANUP_METRICS_RETENTION_DAYS, $defaults['cleanupMetricsRetentionDays']);
        $this->assertSame(Constants::P2P_RATE_LIMIT_PER_MINUTE, $defaults['p2pRateLimitPerMinute']);
        $this->assertSame(Constants::RATE_LIMIT_MAX_ATTEMPTS, $defaults['rateLimitMaxAttempts']);
        $this->assertSame(Constants::RATE_LIMIT_WINDOW_SECONDS, $defaults['rateLimitWindowSeconds']);
        $this->assertSame(Constants::RATE_LIMIT_BLOCK_SECONDS, $defaults['rateLimitBlockSeconds']);
        $this->assertSame(Constants::HTTP_TRANSPORT_TIMEOUT_SECONDS, $defaults['httpTransportTimeoutSeconds']);
        $this->assertSame(Constants::TOR_TRANSPORT_TIMEOUT_SECONDS, $defaults['torTransportTimeoutSeconds']);
        $this->assertSame(Constants::DISPLAY_DATE_FORMAT, $defaults['displayDateFormat']);
        $this->assertSame(Constants::DISPLAY_CURRENCY_DECIMALS, $defaults['displayCurrencyDecimals']);
        $this->assertSame(Constants::DISPLAY_RECENT_TRANSACTIONS_LIMIT, $defaults['displayRecentTransactionsLimit']);
    }

    /**
     * Test getConfigurableDefaults total count is 37 (11 original + 26 new)
     */
    public function testGetConfigurableDefaultsHasExpectedCount(): void
    {
        $defaults = UserContext::getConfigurableDefaults();
        $this->assertCount(37, $defaults);
    }

    /**
     * Test feature toggle getters return Constants defaults when no config set
     */
    public function testFeatureToggleGettersReturnDefaults(): void
    {
        $context = UserContext::getInstance();
        $context->setUserData([]);

        $this->assertSame(Constants::CONTACT_STATUS_ENABLED, $context->getContactStatusEnabled());
        $this->assertSame(Constants::CONTACT_STATUS_SYNC_ON_PING, $context->getContactStatusSyncOnPing());
        $this->assertSame(Constants::AUTO_CHAIN_DROP_PROPOSE, $context->getAutoChainDropPropose());
        $this->assertSame(Constants::AUTO_CHAIN_DROP_ACCEPT, $context->getAutoChainDropAccept());
        $this->assertSame(Constants::API_ENABLED, $context->getApiEnabled());
        $this->assertSame(Constants::API_CORS_ALLOWED_ORIGINS, $context->getApiCorsAllowedOrigins());
        $this->assertSame(Constants::RATE_LIMIT_ENABLED, $context->getRateLimitEnabled());
    }

    /**
     * Test feature toggle getters return user-configured values
     */
    public function testFeatureToggleGettersReturnConfigValues(): void
    {
        $context = UserContext::getInstance();
        $context->setUserData([
            'contactStatusEnabled' => false,
            'contactStatusSyncOnPing' => false,
            'autoChainDropPropose' => false,
            'autoChainDropAccept' => true,
            'apiEnabled' => false,
            'apiCorsAllowedOrigins' => 'https://example.com',
            'rateLimitEnabled' => false,
        ]);

        $this->assertFalse($context->getContactStatusEnabled());
        $this->assertFalse($context->getContactStatusSyncOnPing());
        $this->assertFalse($context->getAutoChainDropPropose());
        $this->assertTrue($context->getAutoChainDropAccept());
        $this->assertFalse($context->getApiEnabled());
        $this->assertSame('https://example.com', $context->getApiCorsAllowedOrigins());
        $this->assertFalse($context->getRateLimitEnabled());
    }

    /**
     * Test migration simulation: adds missing keys without overwriting existing
     */
    public function testMigrationAddsKeysWithoutOverwriting(): void
    {
        // Simulate existing config with only original 11 keys and a custom value
        $existingConfig = [
            'defaultCurrency' => 'USD',
            'minFee' => 0.05, // User changed this from 0.01 default
            'defaultFee' => 0.1,
            'maxFee' => 5,
            'defaultCreditLimit' => 1000,
            'maxP2pLevel' => 6,
            'p2pExpiration' => 300,
            'maxOutput' => 5,
            'defaultTransportMode' => 'tor',
            'autoRefreshEnabled' => false,
            'autoBackupEnabled' => true,
        ];

        $defaults = UserContext::getConfigurableDefaults();
        $changed = false;
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $existingConfig)) {
                $existingConfig[$key] = $defaultValue;
                $changed = true;
            }
        }

        // Migration should have added new keys
        $this->assertTrue($changed);

        // User's custom value should be preserved
        $this->assertSame(0.05, $existingConfig['minFee']);

        // New keys should have defaults
        $this->assertSame(Constants::CONTACT_STATUS_ENABLED, $existingConfig['contactStatusEnabled']);
        $this->assertSame(Constants::BACKUP_RETENTION_COUNT, $existingConfig['backupRetentionCount']);
        $this->assertSame(Constants::CLEANUP_DELIVERY_RETENTION_DAYS, $existingConfig['cleanupDeliveryRetentionDays']);
        $this->assertSame(Constants::P2P_RATE_LIMIT_PER_MINUTE, $existingConfig['p2pRateLimitPerMinute']);
        $this->assertSame(Constants::HTTP_TRANSPORT_TIMEOUT_SECONDS, $existingConfig['httpTransportTimeoutSeconds']);
        $this->assertSame(Constants::DISPLAY_DATE_FORMAT, $existingConfig['displayDateFormat']);

        // Total should be 37
        $this->assertCount(37, $existingConfig);
    }

    /**
     * Test migration is idempotent
     */
    public function testMigrationIsIdempotent(): void
    {
        $defaults = UserContext::getConfigurableDefaults();

        // Start with full config (simulates already-migrated)
        $config = $defaults;

        $changed = false;
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $config)) {
                $config[$key] = $defaultValue;
                $changed = true;
            }
        }

        $this->assertFalse($changed);
        $this->assertEquals($defaults, $config);
    }
}
