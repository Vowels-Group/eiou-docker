<?php
/**
 * Unit Tests for UserContext
 *
 * Tests the UserContext singleton that wraps user configuration.
 * Tests focus on the public API and configuration management methods.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use ReflectionClass;
use Exception;

#[CoversClass(UserContext::class)]
class UserContextTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton before each test
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        // Reset singleton after each test
        $this->resetSingleton();
    }

    /**
     * Reset the singleton instance using reflection
     */
    private function resetSingleton(): void
    {
        $reflection = new ReflectionClass(UserContext::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    /**
     * Test getInstance returns UserContext instance
     */
    public function testGetInstanceReturnsUserContext(): void
    {
        $instance = UserContext::getInstance();

        $this->assertInstanceOf(UserContext::class, $instance);
    }

    /**
     * Test getInstance returns same instance (singleton)
     */
    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = UserContext::getInstance();
        $instance2 = UserContext::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test UserContext is a singleton (private constructor)
     */
    public function testUserContextIsSingleton(): void
    {
        $reflection = new ReflectionClass(UserContext::class);
        $constructor = $reflection->getConstructor();

        $this->assertTrue($constructor->isPrivate());
    }

    /**
     * Test __clone is private
     */
    public function testCloneIsPrivate(): void
    {
        $reflection = new ReflectionClass(UserContext::class);
        $cloneMethod = $reflection->getMethod('__clone');

        $this->assertTrue($cloneMethod->isPrivate());
    }

    /**
     * Test __wakeup throws exception
     */
    public function testWakeupThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot unserialize singleton');

        $instance = UserContext::getInstance();
        $reflection = new ReflectionClass($instance);
        $wakeupMethod = $reflection->getMethod('__wakeup');
        $wakeupMethod->invoke($instance);
    }

    /**
     * Test setUserData sets data correctly
     */
    public function testSetUserDataSetsData(): void
    {
        $instance = UserContext::getInstance();
        $testData = [
            'public' => 'test_public_key',
            'hostname' => 'http://test.com'
        ];

        $instance->setUserData($testData);

        $this->assertEquals($testData, $instance->getAll());
    }

    /**
     * Test setUserData sets initialized flag
     */
    public function testSetUserDataSetsInitializedFlag(): void
    {
        $instance = UserContext::getInstance();
        $instance->clear();

        $this->assertFalse($instance->isInitialized());

        $instance->setUserData(['public' => 'test']);

        $this->assertTrue($instance->isInitialized());
    }

    /**
     * Test get returns value for existing key
     */
    public function testGetReturnsValueForExistingKey(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['testKey' => 'testValue']);

        $this->assertEquals('testValue', $instance->get('testKey'));
    }

    /**
     * Test get returns default for non-existing key
     */
    public function testGetReturnsDefaultForNonExistingKey(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->get('nonExistingKey'));
        $this->assertEquals('default', $instance->get('nonExistingKey', 'default'));
    }

    /**
     * Test set creates new key
     */
    public function testSetCreatesNewKey(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $instance->set('newKey', 'newValue');

        $this->assertEquals('newValue', $instance->get('newKey'));
    }

    /**
     * Test set updates existing key
     */
    public function testSetUpdatesExistingKey(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['existingKey' => 'oldValue']);

        $instance->set('existingKey', 'newValue');

        $this->assertEquals('newValue', $instance->get('existingKey'));
    }

    /**
     * Test has returns true for existing key
     */
    public function testHasReturnsTrueForExistingKey(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['existingKey' => 'value']);

        $this->assertTrue($instance->has('existingKey'));
    }

    /**
     * Test has returns false for non-existing key
     */
    public function testHasReturnsFalseForNonExistingKey(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertFalse($instance->has('nonExistingKey'));
    }

    /**
     * Test getAll returns all data
     */
    public function testGetAllReturnsAllData(): void
    {
        $instance = UserContext::getInstance();
        $testData = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];
        $instance->setUserData($testData);

        $this->assertEquals($testData, $instance->getAll());
    }

    /**
     * Test getPublicKey returns public key value
     */
    public function testGetPublicKeyReturnsPublicKeyValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['public' => 'my_public_key']);

        $this->assertEquals('my_public_key', $instance->getPublicKey());
    }

    /**
     * Test getPublicKey returns null when not set
     */
    public function testGetPublicKeyReturnsNullWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->getPublicKey());
    }

    /**
     * Test getPublicKeyHash returns hash when public key exists
     */
    public function testGetPublicKeyHashReturnsHash(): void
    {
        $instance = UserContext::getInstance();
        $publicKey = 'my_public_key';
        $instance->setUserData(['public' => $publicKey]);

        $expectedHash = hash(Constants::HASH_ALGORITHM, $publicKey);
        $this->assertEquals($expectedHash, $instance->getPublicKeyHash());
    }

    /**
     * Test getHttpAddress returns hostname value
     */
    public function testGetHttpAddressReturnsHostnameValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['hostname' => 'http://example.com']);

        $this->assertEquals('http://example.com', $instance->getHttpAddress());
    }

    /**
     * Test getHttpAddress returns null when not set
     */
    public function testGetHttpAddressReturnsNullWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->getHttpAddress());
    }

    /**
     * Test getHttpsAddress returns hostname_secure value
     */
    public function testGetHttpsAddressReturnsHostnameSecureValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['hostname_secure' => 'https://secure.example.com']);

        $this->assertEquals('https://secure.example.com', $instance->getHttpsAddress());
    }

    /**
     * Test getHttpsAddress returns null when not set
     */
    public function testGetHttpsAddressReturnsNullWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->getHttpsAddress());
    }

    /**
     * Test getTorAddress returns torAddress value
     */
    public function testGetTorAddressReturnsTorAddressValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['torAddress' => 'abc123def456.onion']);

        $this->assertEquals('abc123def456.onion', $instance->getTorAddress());
    }

    /**
     * Test getTorAddress returns null when not set
     */
    public function testGetTorAddressReturnsNullWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->getTorAddress());
    }

    /**
     * Test hasKeys returns false when no keys
     */
    public function testHasKeysReturnsFalseWhenNoKeys(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertFalse($instance->hasKeys());
    }

    /**
     * Test hasKeys returns true when both keys present
     */
    public function testHasKeysReturnsTrueWhenBothKeysPresent(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([
            'public' => 'test_public_key',
            'private_encrypted' => 'encrypted_private_key'
        ]);

        $this->assertTrue($instance->hasKeys());
    }

    /**
     * Test hasKeys returns false when only public key present
     */
    public function testHasKeysReturnsFalseWhenOnlyPublicKeyPresent(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['public' => 'test_public_key']);

        $this->assertFalse($instance->hasKeys());
    }

    /**
     * Test validateWallet returns errors when missing keys
     */
    public function testValidateWalletReturnsErrorsWhenMissingKeys(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $result = $instance->validateWallet();

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test validateWallet returns error when missing address
     */
    public function testValidateWalletReturnsErrorWhenMissingAddress(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([
            'public' => 'test_public_key',
            'private_encrypted' => 'encrypted_private_key',
            'authcode_encrypted' => 'encrypted_authcode'
        ]);

        $result = $instance->validateWallet();

        $this->assertFalse($result['valid']);
        $this->assertContains('No network address configured (HTTP, HTTPS, or Tor)', $result['errors']);
    }

    /**
     * Test getUserAddresses returns array of addresses
     */
    public function testGetUserAddressesReturnsArrayOfAddresses(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([
            'hostname' => 'http://example.com',
            'hostname_secure' => 'https://secure.example.com',
            'torAddress' => 'abc123.onion'
        ]);

        $addresses = $instance->getUserAddresses();

        $this->assertIsArray($addresses);
        $this->assertCount(3, $addresses);
        $this->assertContains('http://example.com', $addresses);
        $this->assertContains('https://secure.example.com', $addresses);
        $this->assertContains('abc123.onion', $addresses);
    }

    /**
     * Test getUserAddresses returns empty array when no addresses
     */
    public function testGetUserAddressesReturnsEmptyArrayWhenNoAddresses(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $addresses = $instance->getUserAddresses();

        $this->assertIsArray($addresses);
        $this->assertEmpty($addresses);
    }

    /**
     * Test getUserLocaters returns keyed array
     */
    public function testGetUserLocatersReturnsKeyedArray(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([
            'hostname' => 'http://example.com',
            'hostname_secure' => 'https://secure.example.com',
            'torAddress' => 'abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion'
        ]);

        $locaters = $instance->getUserLocaters();

        $this->assertIsArray($locaters);
        $this->assertArrayHasKey('http', $locaters);
        $this->assertArrayHasKey('https', $locaters);
        $this->assertArrayHasKey('tor', $locaters);
    }

    /**
     * Test isMyAddress returns true for own address
     */
    public function testIsMyAddressReturnsTrueForOwnAddress(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['hostname' => 'http://myaddress.com']);

        $this->assertTrue($instance->isMyAddress('http://myaddress.com'));
    }

    /**
     * Test isMyAddress returns false for other address
     */
    public function testIsMyAddressReturnsFalseForOtherAddress(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['hostname' => 'http://myaddress.com']);

        $this->assertFalse($instance->isMyAddress('http://otheraddress.com'));
    }

    /**
     * Test getMinimumFee returns default when not set
     */
    public function testGetMinimumFeeReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::TRANSACTION_MINIMUM_FEE, $instance->getMinimumFee());
    }

    /**
     * Test getMinimumFee returns configured value
     */
    public function testGetMinimumFeeReturnsConfiguredValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['minFee' => 0.05]);

        $this->assertEquals(0.05, $instance->getMinimumFee());
    }

    /**
     * Test getDefaultFee returns default when not set
     */
    public function testGetDefaultFeeReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::CONTACT_DEFAULT_FEE_PERCENT, $instance->getDefaultFee());
    }

    /**
     * Test getDefaultFee returns configured value
     */
    public function testGetDefaultFeeReturnsConfiguredValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['defaultFee' => 0.5]);

        $this->assertEquals(0.5, $instance->getDefaultFee());
    }

    /**
     * Test getDefaultCreditLimit returns default when not set
     */
    public function testGetDefaultCreditLimitReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::CONTACT_DEFAULT_CREDIT_LIMIT, $instance->getDefaultCreditLimit());
    }

    /**
     * Test getDefaultCreditLimit returns configured value
     */
    public function testGetDefaultCreditLimitReturnsConfiguredValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['defaultCreditLimit' => 500]);

        $this->assertEquals(500, $instance->getDefaultCreditLimit());
    }

    /**
     * Test getDefaultCurrency returns default when not set
     */
    public function testGetDefaultCurrencyReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::TRANSACTION_DEFAULT_CURRENCY, $instance->getDefaultCurrency());
    }

    /**
     * Test getDefaultCurrency returns configured value
     */
    public function testGetDefaultCurrencyReturnsConfiguredValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['defaultCurrency' => 'EUR']);

        $this->assertEquals('EUR', $instance->getDefaultCurrency());
    }

    /**
     * Test getMaxFee returns default when not set
     */
    public function testGetMaxFeeReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX, $instance->getMaxFee());
    }

    /**
     * Test getMaxP2pLevel returns default when not set
     */
    public function testGetMaxP2pLevelReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL, $instance->getMaxP2pLevel());
    }

    /**
     * Test getP2pExpirationTime returns default when not set
     */
    public function testGetP2pExpirationTimeReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::P2P_DEFAULT_EXPIRATION_SECONDS, $instance->getP2pExpirationTime());
    }

    /**
     * Test getMaxOutput returns default when not set
     */
    public function testGetMaxOutputReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX, $instance->getMaxOutput());
    }

    /**
     * Test getDefaultTransportMode returns default when not set
     */
    public function testGetDefaultTransportModeReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::DEFAULT_TRANSPORT_MODE, $instance->getDefaultTransportMode());
    }

    /**
     * Test getAutoRefreshEnabled returns default when not set
     */
    public function testGetAutoRefreshEnabledReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::AUTO_REFRESH_ENABLED, $instance->getAutoRefreshEnabled());
    }

    /**
     * Test getAutoBackupEnabled returns default when not set
     */
    public function testGetAutoBackupEnabledReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::BACKUP_AUTO_ENABLED, $instance->getAutoBackupEnabled());
    }

    /**
     * Test getAutoAcceptTransaction returns default when not set
     */
    public function testGetAutoAcceptTransactionReturnsDefaultWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertEquals(Constants::AUTO_ACCEPT_TRANSACTION, $instance->getAutoAcceptTransaction());
    }

    /**
     * Test toArray returns same as getAll
     */
    public function testToArrayReturnsSameAsGetAll(): void
    {
        $instance = UserContext::getInstance();
        $testData = ['key' => 'value'];
        $instance->setUserData($testData);

        $this->assertEquals($instance->getAll(), $instance->toArray());
    }

    /**
     * Test isInitialized returns false after clear
     */
    public function testIsInitializedReturnsFalseAfterClear(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['key' => 'value']);
        $instance->clear();

        $this->assertFalse($instance->isInitialized());
    }

    /**
     * Test clear resets data and initialized flag
     */
    public function testClearResetsDataAndInitializedFlag(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['key' => 'value']);

        $instance->clear();

        $this->assertEquals([], $instance->getAll());
        $this->assertFalse($instance->isInitialized());
    }

    /**
     * Test getName returns name value when set
     */
    public function testGetNameReturnsNameValue(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['name' => 'My Node']);

        $this->assertEquals('My Node', $instance->getName());
    }

    /**
     * Test getName returns null when not set
     */
    public function testGetNameReturnsNullWhenNotSet(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->getName());
    }

    /**
     * Test getName handles various name formats
     */
    public function testGetNameHandlesVariousFormats(): void
    {
        $instance = UserContext::getInstance();

        // Test with spaces
        $instance->setUserData(['name' => 'Production Node Alpha']);
        $this->assertEquals('Production Node Alpha', $instance->getName());

        // Test with special characters
        $instance->setUserData(['name' => "Dave's Node"]);
        $this->assertEquals("Dave's Node", $instance->getName());

        // Test with unicode
        $instance->setUserData(['name' => 'Nœud Français']);
        $this->assertEquals('Nœud Français', $instance->getName());
    }

    /**
     * Data provider for address type detection tests
     */
    public static function addressTypeProvider(): array
    {
        return [
            'http address' => ['http://example.com', 'isHttpAddress', true],
            'https address' => ['https://example.com', 'isHttpsAddress', true],
            'tor address' => ['abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion', 'isTorAddress', true],
            'http is not https' => ['http://example.com', 'isHttpsAddress', false],
            'https is not http' => ['https://example.com', 'isHttpAddress', false],
            'regular domain is not tor' => ['http://example.com', 'isTorAddress', false],
        ];
    }

    /**
     * Test address type detection methods
     */
    #[DataProvider('addressTypeProvider')]
    public function testAddressTypeDetection(string $address, string $method, bool $expected): void
    {
        $instance = UserContext::getInstance();

        $this->assertEquals($expected, $instance->$method($address));
    }

    /**
     * Test isAddress returns true for valid addresses
     */
    public function testIsAddressReturnsTrueForValidAddresses(): void
    {
        $instance = UserContext::getInstance();

        $this->assertTrue($instance->isAddress('http://example.com'));
        $this->assertTrue($instance->isAddress('https://example.com'));
    }

    /**
     * Test getPrivateKey returns null when not encrypted
     */
    public function testGetPrivateKeyReturnsNullWhenNotEncrypted(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->getPrivateKey());
    }

    /**
     * Test getAuthCode returns null when not encrypted
     */
    public function testGetAuthCodeReturnsNullWhenNotEncrypted(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);

        $this->assertNull($instance->getAuthCode());
    }

    // =========================================================================
    // BACKUP & LOGGING GETTERS
    // =========================================================================

    public function testGetBackupRetentionCountDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::BACKUP_RETENTION_COUNT, $instance->getBackupRetentionCount());
    }

    public function testGetBackupRetentionCountFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['backupRetentionCount' => 7]);
        $this->assertSame(7, $instance->getBackupRetentionCount());
    }

    public function testGetBackupRetentionCountMinClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['backupRetentionCount' => 0]);
        $this->assertSame(1, $instance->getBackupRetentionCount());
    }

    public function testGetBackupCronHourDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::BACKUP_CRON_HOUR, $instance->getBackupCronHour());
    }

    public function testGetBackupCronHourFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['backupCronHour' => 14]);
        $this->assertSame(14, $instance->getBackupCronHour());
    }

    public function testGetBackupCronHourClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['backupCronHour' => 25]);
        $this->assertSame(23, $instance->getBackupCronHour());
    }

    public function testGetBackupCronMinuteDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::BACKUP_CRON_MINUTE, $instance->getBackupCronMinute());
    }

    public function testGetBackupCronMinuteFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['backupCronMinute' => 30]);
        $this->assertSame(30, $instance->getBackupCronMinute());
    }

    public function testGetLogLevelDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::LOG_LEVEL, $instance->getLogLevel());
    }

    public function testGetLogLevelFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['logLevel' => 'DEBUG']);
        $this->assertSame('DEBUG', $instance->getLogLevel());
    }

    public function testGetLogMaxEntriesDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::LOG_MAX_ENTRIES, $instance->getLogMaxEntries());
    }

    public function testGetLogMaxEntriesFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['logMaxEntries' => 500]);
        $this->assertSame(500, $instance->getLogMaxEntries());
    }

    public function testGetLogMaxEntriesMinClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['logMaxEntries' => 5]);
        $this->assertSame(10, $instance->getLogMaxEntries());
    }

    // =========================================================================
    // DATA RETENTION GETTERS
    // =========================================================================

    public function testGetCleanupDeliveryRetentionDaysDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::CLEANUP_DELIVERY_RETENTION_DAYS, $instance->getCleanupDeliveryRetentionDays());
    }

    public function testGetCleanupDeliveryRetentionDaysFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['cleanupDeliveryRetentionDays' => 60]);
        $this->assertSame(60, $instance->getCleanupDeliveryRetentionDays());
    }

    public function testGetCleanupDlqRetentionDaysDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::CLEANUP_DLQ_RETENTION_DAYS, $instance->getCleanupDlqRetentionDays());
    }

    public function testGetCleanupHeldTxRetentionDaysDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::CLEANUP_HELD_TX_RETENTION_DAYS, $instance->getCleanupHeldTxRetentionDays());
    }

    public function testGetCleanupRp2pRetentionDaysDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::CLEANUP_RP2P_RETENTION_DAYS, $instance->getCleanupRp2pRetentionDays());
    }

    public function testGetCleanupMetricsRetentionDaysDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::CLEANUP_METRICS_RETENTION_DAYS, $instance->getCleanupMetricsRetentionDays());
    }

    public function testCleanupRetentionDaysMinClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([
            'cleanupDeliveryRetentionDays' => 0,
            'cleanupDlqRetentionDays' => -5,
            'cleanupHeldTxRetentionDays' => 0,
            'cleanupRp2pRetentionDays' => 0,
            'cleanupMetricsRetentionDays' => 0,
        ]);
        $this->assertSame(1, $instance->getCleanupDeliveryRetentionDays());
        $this->assertSame(1, $instance->getCleanupDlqRetentionDays());
        $this->assertSame(1, $instance->getCleanupHeldTxRetentionDays());
        $this->assertSame(1, $instance->getCleanupRp2pRetentionDays());
        $this->assertSame(1, $instance->getCleanupMetricsRetentionDays());
    }

    // =========================================================================
    // RATE LIMITING GETTERS
    // =========================================================================

    public function testGetP2pRateLimitPerMinuteDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::P2P_RATE_LIMIT_PER_MINUTE, $instance->getP2pRateLimitPerMinute());
    }

    public function testGetP2pRateLimitPerMinuteFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['p2pRateLimitPerMinute' => 120]);
        $this->assertSame(120, $instance->getP2pRateLimitPerMinute());
    }

    public function testGetRateLimitMaxAttemptsDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::RATE_LIMIT_MAX_ATTEMPTS, $instance->getRateLimitMaxAttempts());
    }

    public function testGetRateLimitWindowSecondsDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::RATE_LIMIT_WINDOW_SECONDS, $instance->getRateLimitWindowSeconds());
    }

    public function testGetRateLimitBlockSecondsDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::RATE_LIMIT_BLOCK_SECONDS, $instance->getRateLimitBlockSeconds());
    }

    public function testRateLimitMinClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([
            'p2pRateLimitPerMinute' => 0,
            'rateLimitMaxAttempts' => 0,
            'rateLimitWindowSeconds' => 0,
            'rateLimitBlockSeconds' => -1,
        ]);
        $this->assertSame(1, $instance->getP2pRateLimitPerMinute());
        $this->assertSame(1, $instance->getRateLimitMaxAttempts());
        $this->assertSame(1, $instance->getRateLimitWindowSeconds());
        $this->assertSame(1, $instance->getRateLimitBlockSeconds());
    }

    // =========================================================================
    // NETWORK GETTERS
    // =========================================================================

    public function testGetHttpTransportTimeoutSecondsDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::HTTP_TRANSPORT_TIMEOUT_SECONDS, $instance->getHttpTransportTimeoutSeconds());
    }

    public function testGetHttpTransportTimeoutSecondsFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['httpTransportTimeoutSeconds' => 30]);
        $this->assertSame(30, $instance->getHttpTransportTimeoutSeconds());
    }

    public function testGetHttpTransportTimeoutSecondsClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['httpTransportTimeoutSeconds' => 2]);
        $this->assertSame(5, $instance->getHttpTransportTimeoutSeconds());

        $this->resetSingleton();
        $instance = UserContext::getInstance();
        $instance->setUserData(['httpTransportTimeoutSeconds' => 200]);
        $this->assertSame(120, $instance->getHttpTransportTimeoutSeconds());
    }

    public function testGetTorTransportTimeoutSecondsDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::TOR_TRANSPORT_TIMEOUT_SECONDS, $instance->getTorTransportTimeoutSeconds());
    }

    public function testGetTorTransportTimeoutSecondsClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['torTransportTimeoutSeconds' => 5]);
        $this->assertSame(10, $instance->getTorTransportTimeoutSeconds());

        $this->resetSingleton();
        $instance = UserContext::getInstance();
        $instance->setUserData(['torTransportTimeoutSeconds' => 500]);
        $this->assertSame(300, $instance->getTorTransportTimeoutSeconds());
    }

    // =========================================================================
    // SYNC GETTERS
    // =========================================================================

    public function testGetSyncChunkSizeDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::SYNC_CHUNK_SIZE, $instance->getSyncChunkSize());
    }

    public function testGetSyncChunkSizeFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['syncChunkSize' => 100]);
        $this->assertSame(100, $instance->getSyncChunkSize());
    }

    public function testGetSyncChunkSizeClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['syncChunkSize' => 5]);
        $this->assertSame(10, $instance->getSyncChunkSize());

        $this->resetSingleton();
        $instance = UserContext::getInstance();
        $instance->setUserData(['syncChunkSize' => 600]);
        $this->assertSame(500, $instance->getSyncChunkSize());
    }

    public function testGetSyncMaxChunksDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::SYNC_MAX_CHUNKS, $instance->getSyncMaxChunks());
    }

    public function testGetSyncMaxChunksFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['syncMaxChunks' => 200]);
        $this->assertSame(200, $instance->getSyncMaxChunks());
    }

    public function testGetSyncMaxChunksClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['syncMaxChunks' => 5]);
        $this->assertSame(10, $instance->getSyncMaxChunks());

        $this->resetSingleton();
        $instance = UserContext::getInstance();
        $instance->setUserData(['syncMaxChunks' => 2000]);
        $this->assertSame(1000, $instance->getSyncMaxChunks());
    }

    public function testGetHeldTxSyncTimeoutSecondsDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::HELD_TX_SYNC_TIMEOUT_SECONDS, $instance->getHeldTxSyncTimeoutSeconds());
    }

    public function testGetHeldTxSyncTimeoutSecondsFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['heldTxSyncTimeoutSeconds' => 60]);
        $this->assertSame(60, $instance->getHeldTxSyncTimeoutSeconds());
    }

    public function testGetHeldTxSyncTimeoutSecondsClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['heldTxSyncTimeoutSeconds' => 10]);
        $this->assertSame(30, $instance->getHeldTxSyncTimeoutSeconds());

        $this->resetSingleton();
        $instance = UserContext::getInstance();
        $instance->setUserData(['heldTxSyncTimeoutSeconds' => 500]);
        $this->assertSame(Constants::P2P_DEFAULT_EXPIRATION_SECONDS - 1, $instance->getHeldTxSyncTimeoutSeconds());
    }

    // =========================================================================
    // DISPLAY GETTERS
    // =========================================================================

    public function testGetDisplayDateFormatDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::DISPLAY_DATE_FORMAT, $instance->getDisplayDateFormat());
    }

    public function testGetDisplayDateFormatFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['displayDateFormat' => 'Y-m-d']);
        $this->assertSame('Y-m-d', $instance->getDisplayDateFormat());
    }

    public function testGetDisplayCurrencyDecimalsDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::DISPLAY_CURRENCY_DECIMALS, $instance->getDisplayCurrencyDecimals());
    }

    public function testGetDisplayCurrencyDecimalsClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['displayCurrencyDecimals' => -1]);
        $this->assertSame(0, $instance->getDisplayCurrencyDecimals());

        $this->resetSingleton();
        $instance = UserContext::getInstance();
        $instance->setUserData(['displayCurrencyDecimals' => 10]);
        $this->assertSame(8, $instance->getDisplayCurrencyDecimals());
    }

    public function testGetDisplayRecentTransactionsLimitDefault(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData([]);
        $this->assertSame(Constants::DISPLAY_RECENT_TRANSACTIONS_LIMIT, $instance->getDisplayRecentTransactionsLimit());
    }

    public function testGetDisplayRecentTransactionsLimitFromConfig(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['displayRecentTransactionsLimit' => 10]);
        $this->assertSame(10, $instance->getDisplayRecentTransactionsLimit());
    }

    public function testGetDisplayRecentTransactionsLimitMinClamp(): void
    {
        $instance = UserContext::getInstance();
        $instance->setUserData(['displayRecentTransactionsLimit' => 0]);
        $this->assertSame(1, $instance->getDisplayRecentTransactionsLimit());
    }
}
