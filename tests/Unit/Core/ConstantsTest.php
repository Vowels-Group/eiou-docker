<?php
/**
 * Unit Tests for Constants
 *
 * Tests application-wide constants and utility methods.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\Constants;
use ReflectionClass;

#[CoversClass(Constants::class)]
class ConstantsTest extends TestCase
{
    /**
     * Test all status constants are defined with expected string values
     */
    public function testStatusConstantsAreDefined(): void
    {
        $this->assertEquals('pending', Constants::STATUS_PENDING);
        $this->assertEquals('sending', Constants::STATUS_SENDING);
        $this->assertEquals('sent', Constants::STATUS_SENT);
        $this->assertEquals('accepted', Constants::STATUS_ACCEPTED);
        $this->assertEquals('completed', Constants::STATUS_COMPLETED);
        $this->assertEquals('cancelled', Constants::STATUS_CANCELLED);
        $this->assertEquals('rejected', Constants::STATUS_REJECTED);
        $this->assertEquals('expired', Constants::STATUS_EXPIRED);
        $this->assertEquals('paid', Constants::STATUS_PAID);
        $this->assertEquals('failed', Constants::STATUS_FAILED);
        $this->assertEquals('initial', Constants::STATUS_INITIAL);
        $this->assertEquals('queued', Constants::STATUS_QUEUED);
        $this->assertEquals('processed', Constants::STATUS_PROCESSED);
    }

    /**
     * Test all status constants are strings
     */
    public function testStatusConstantsAreStrings(): void
    {
        $this->assertIsString(Constants::STATUS_PENDING);
        $this->assertIsString(Constants::STATUS_SENDING);
        $this->assertIsString(Constants::STATUS_SENT);
        $this->assertIsString(Constants::STATUS_ACCEPTED);
        $this->assertIsString(Constants::STATUS_COMPLETED);
        $this->assertIsString(Constants::STATUS_CANCELLED);
        $this->assertIsString(Constants::STATUS_REJECTED);
        $this->assertIsString(Constants::STATUS_EXPIRED);
        $this->assertIsString(Constants::STATUS_PAID);
        $this->assertIsString(Constants::STATUS_FAILED);
        $this->assertIsString(Constants::STATUS_INITIAL);
        $this->assertIsString(Constants::STATUS_QUEUED);
        $this->assertIsString(Constants::STATUS_PROCESSED);
    }

    /**
     * Test hash/crypto constants are defined
     */
    public function testHashCryptoConstantsAreDefined(): void
    {
        $this->assertEquals('sha256', Constants::HASH_ALGORITHM);
        $this->assertIsString(Constants::HASH_ALGORITHM);
    }

    /**
     * Test transaction timeout/retry constants are defined
     */
    public function testTransactionTimeoutRetryConstantsAreDefined(): void
    {
        // Polling intervals
        $this->assertEquals(100, Constants::TRANSACTION_MIN_INTERVAL_MS);
        $this->assertEquals(5000, Constants::TRANSACTION_MAX_INTERVAL_MS);
        $this->assertEquals(2000, Constants::TRANSACTION_IDLE_INTERVAL_MS);
        $this->assertTrue(Constants::TRANSACTION_ADAPTIVE_POLLING);

        // Recovery configuration
        $this->assertEquals(120, Constants::RECOVERY_SENDING_TIMEOUT_SECONDS);
        $this->assertEquals(3, Constants::RECOVERY_MAX_RETRY_COUNT);
        $this->assertEquals(300, Constants::RECOVERY_LOCK_TIMEOUT_SECONDS);
    }

    /**
     * Test P2P timeout/retry constants are defined
     */
    public function testP2PTimeoutRetryConstantsAreDefined(): void
    {
        $this->assertEquals(100, Constants::P2P_MIN_INTERVAL_MS);
        $this->assertEquals(5000, Constants::P2P_MAX_INTERVAL_MS);
        $this->assertEquals(2000, Constants::P2P_IDLE_INTERVAL_MS);
        $this->assertTrue(Constants::P2P_ADAPTIVE_POLLING);

        // P2P network configuration
        $this->assertEquals(6, Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL);
        $this->assertEquals(300, Constants::P2P_DEFAULT_EXPIRATION_SECONDS);
        $this->assertEquals(60, Constants::P2P_MIN_EXPIRATION_SECONDS);
        $this->assertEquals(1000, Constants::P2P_REQUEST_LEVEL_VALIDATION_MAX);
        $this->assertEquals(10, Constants::P2P_MAX_ROUTING_LEVEL);
    }

    /**
     * Test cleanup timeout/retry constants are defined
     */
    public function testCleanupTimeoutRetryConstantsAreDefined(): void
    {
        $this->assertEquals(1000, Constants::CLEANUP_MIN_INTERVAL_MS);
        $this->assertEquals(30000, Constants::CLEANUP_MAX_INTERVAL_MS);
        $this->assertEquals(10000, Constants::CLEANUP_IDLE_INTERVAL_MS);
        $this->assertTrue(Constants::CLEANUP_ADAPTIVE_POLLING);
    }

    /**
     * Test contact status constants are defined
     */
    public function testContactStatusConstantsAreDefined(): void
    {
        $this->assertEquals(300000, Constants::CONTACT_STATUS_POLLING_INTERVAL_MS);
        $this->assertEquals(300000, Constants::CONTACT_STATUS_MIN_INTERVAL_MS);
        $this->assertEquals(1800000, Constants::CONTACT_STATUS_MAX_INTERVAL_MS);
        $this->assertEquals(300000, Constants::CONTACT_STATUS_IDLE_INTERVAL_MS);
        $this->assertTrue(Constants::CONTACT_STATUS_ADAPTIVE_POLLING);
        $this->assertTrue(Constants::CONTACT_STATUS_SYNC_ON_PING);
    }

    /**
     * Test delivery stage constants are defined with expected string values
     */
    public function testDeliveryStageConstantsAreDefined(): void
    {
        $this->assertEquals('pending', Constants::DELIVERY_PENDING);
        $this->assertEquals('sent', Constants::DELIVERY_SENT);
        $this->assertEquals('completed', Constants::DELIVERY_COMPLETED);
        $this->assertEquals('failed', Constants::DELIVERY_FAILED);
        $this->assertEquals('received', Constants::DELIVERY_RECEIVED);
        $this->assertEquals('inserted', Constants::DELIVERY_INSERTED);
        $this->assertEquals('forwarded', Constants::DELIVERY_FORWARDED);
        $this->assertEquals('acknowledged', Constants::DELIVERY_ACKNOWLEDGED);
        $this->assertEquals('warning', Constants::DELIVERY_WARNING);
        $this->assertEquals('updated', Constants::DELIVERY_UPDATED);
        $this->assertEquals('rejected', Constants::DELIVERY_REJECTED);
    }

    /**
     * Test delivery stage constants are strings
     */
    public function testDeliveryStageConstantsAreStrings(): void
    {
        $this->assertIsString(Constants::DELIVERY_PENDING);
        $this->assertIsString(Constants::DELIVERY_SENT);
        $this->assertIsString(Constants::DELIVERY_COMPLETED);
        $this->assertIsString(Constants::DELIVERY_FAILED);
        $this->assertIsString(Constants::DELIVERY_RECEIVED);
        $this->assertIsString(Constants::DELIVERY_INSERTED);
        $this->assertIsString(Constants::DELIVERY_FORWARDED);
        $this->assertIsString(Constants::DELIVERY_ACKNOWLEDGED);
        $this->assertIsString(Constants::DELIVERY_WARNING);
        $this->assertIsString(Constants::DELIVERY_UPDATED);
        $this->assertIsString(Constants::DELIVERY_REJECTED);
    }

    /**
     * Test transaction type constants are defined
     */
    public function testTransactionTypeConstantsAreDefined(): void
    {
        $this->assertEquals('sent', Constants::TX_TYPE_SENT);
        $this->assertEquals('received', Constants::TX_TYPE_RECEIVED);
        $this->assertIsString(Constants::TX_TYPE_SENT);
        $this->assertIsString(Constants::TX_TYPE_RECEIVED);
    }

    /**
     * Test contact status value constants are defined
     */
    public function testContactStatusValueConstantsAreDefined(): void
    {
        $this->assertEquals('pending', Constants::CONTACT_STATUS_PENDING);
        $this->assertEquals('accepted', Constants::CONTACT_STATUS_ACCEPTED);
        $this->assertEquals('blocked', Constants::CONTACT_STATUS_BLOCKED);
        $this->assertIsString(Constants::CONTACT_STATUS_PENDING);
        $this->assertIsString(Constants::CONTACT_STATUS_ACCEPTED);
        $this->assertIsString(Constants::CONTACT_STATUS_BLOCKED);
    }

    /**
     * Test contact online status constants are defined
     */
    public function testContactOnlineStatusConstantsAreDefined(): void
    {
        $this->assertEquals('online', Constants::CONTACT_ONLINE_STATUS_ONLINE);
        $this->assertEquals('offline', Constants::CONTACT_ONLINE_STATUS_OFFLINE);
        $this->assertEquals('unknown', Constants::CONTACT_ONLINE_STATUS_UNKNOWN);
        $this->assertIsString(Constants::CONTACT_ONLINE_STATUS_ONLINE);
        $this->assertIsString(Constants::CONTACT_ONLINE_STATUS_OFFLINE);
        $this->assertIsString(Constants::CONTACT_ONLINE_STATUS_UNKNOWN);
    }

    /**
     * Test validation constants are integers
     */
    public function testValidationConstantsAreIntegers(): void
    {
        $this->assertIsInt(Constants::VALIDATION_PUBLIC_KEY_MIN_LENGTH);
        $this->assertIsInt(Constants::VALIDATION_SIGNATURE_MIN_LENGTH);
        $this->assertIsInt(Constants::VALIDATION_TOR_V3_ADDRESS_LENGTH);
        $this->assertIsInt(Constants::VALIDATION_TOR_V2_ADDRESS_LENGTH);
        $this->assertIsInt(Constants::VALIDATION_HASH_LENGTH_SHA256);
        $this->assertIsInt(Constants::VALIDATION_CURRENCY_CODE_MIN_LENGTH);
        $this->assertIsInt(Constants::VALIDATION_CURRENCY_CODE_MAX_LENGTH);
        $this->assertIsInt(Constants::VALIDATION_MEMO_MAX_LENGTH);
        $this->assertIsInt(Constants::VALIDATION_FEE_MIN_PERCENT);
        $this->assertIsInt(Constants::VALIDATION_FEE_MAX_PERCENT);
    }

    /**
     * Test validation constants have expected values
     */
    public function testValidationConstantsHaveExpectedValues(): void
    {
        $this->assertEquals(100, Constants::VALIDATION_PUBLIC_KEY_MIN_LENGTH);
        $this->assertEquals(100, Constants::VALIDATION_SIGNATURE_MIN_LENGTH);
        $this->assertEquals(56, Constants::VALIDATION_TOR_V3_ADDRESS_LENGTH);
        $this->assertEquals(16, Constants::VALIDATION_TOR_V2_ADDRESS_LENGTH);
        $this->assertEquals(64, Constants::VALIDATION_HASH_LENGTH_SHA256);
        $this->assertEquals(3, Constants::VALIDATION_CURRENCY_CODE_MIN_LENGTH);
        $this->assertEquals(9, Constants::VALIDATION_CURRENCY_CODE_MAX_LENGTH);
        $this->assertEquals(500, Constants::VALIDATION_MEMO_MAX_LENGTH);
        $this->assertEquals(0, Constants::VALIDATION_FEE_MIN_PERCENT);
        $this->assertEquals(100, Constants::VALIDATION_FEE_MAX_PERCENT);
    }

    /**
     * Test time conversion constants are integers
     */
    public function testTimeConversionConstantsAreIntegers(): void
    {
        $this->assertIsInt(Constants::TIME_MICROSECONDS_TO_INT);
        $this->assertIsInt(Constants::TIME_SECONDS_PER_MINUTE);
        $this->assertIsInt(Constants::TIME_MINUTES_PER_HOUR);
        $this->assertIsInt(Constants::TIME_HOURS_PER_DAY);
    }

    /**
     * Test time conversion constants have expected values
     */
    public function testTimeConversionConstantsHaveExpectedValues(): void
    {
        $this->assertEquals(10000, Constants::TIME_MICROSECONDS_TO_INT);
        $this->assertEquals(60, Constants::TIME_SECONDS_PER_MINUTE);
        $this->assertEquals(60, Constants::TIME_MINUTES_PER_HOUR);
        $this->assertEquals(24, Constants::TIME_HOURS_PER_DAY);
    }

    /**
     * Test transaction limit constants are defined
     */
    public function testTransactionLimitConstantsAreDefined(): void
    {
        $this->assertEquals(2305843009213693951, Constants::TRANSACTION_MAX_AMOUNT); // PHP_INT_MAX / 4
        $this->assertEquals('USD', Constants::TRANSACTION_DEFAULT_CURRENCY);
        $this->assertEquals(0.00000001, Constants::TRANSACTION_MINIMUM_FEE);
        $this->assertEquals(100000000, Constants::INTERNAL_CONVERSION_FACTOR);
        $this->assertEquals(8, Constants::INTERNAL_PRECISION);
        $this->assertEquals(2, Constants::DISPLAY_DECIMALS);
    }

    /**
     * Test contact management constants are defined
     */
    public function testContactManagementConstantsAreDefined(): void
    {
        $this->assertEquals(0.01, Constants::CONTACT_DEFAULT_FEE_PERCENT);
        $this->assertEquals(5, Constants::CONTACT_DEFAULT_FEE_PERCENT_MAX);
        $this->assertEquals(1000, Constants::CONTACT_DEFAULT_CREDIT_LIMIT);
        $this->assertEquals(255, Constants::CONTACT_MAX_NAME_LENGTH);
        $this->assertEquals(2, Constants::CONTACT_MIN_NAME_LENGTH);
    }

    /**
     * Test app environment constants are defined
     */
    public function testAppEnvironmentConstantsAreDefined(): void
    {
        $this->assertEquals('development', Constants::APP_ENV);
        $this->assertNotEmpty(Constants::APP_VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-\w+)?$/', Constants::APP_VERSION);
        $this->assertIsBool(Constants::APP_DEBUG);
    }

    /**
     * Test network constants are defined
     */
    public function testNetworkConstantsAreDefined(): void
    {
        $this->assertEquals('tor', Constants::DEFAULT_TRANSPORT_MODE);
        // Membership matters, order does not — Constants::VALID_TRANSPORT_INDICES
        // is the canonical list and may be reordered (e.g. preferred-first).
        $this->assertEqualsCanonicalizing(
            ['http', 'https', 'tor'],
            Constants::VALID_TRANSPORT_INDICES
        );
    }

    /**
     * Test Tor circuit health constants are defined
     */
    public function testTorCircuitHealthConstantsAreDefined(): void
    {
        $this->assertSame(3, Constants::TOR_CIRCUIT_MAX_FAILURES);
        $this->assertSame(300, Constants::TOR_CIRCUIT_COOLDOWN_SECONDS);
        $this->assertTrue(Constants::TOR_FAILURE_TRANSPORT_FALLBACK);
        $this->assertTrue(Constants::TOR_FALLBACK_REQUIRE_ENCRYPTED);
    }

    /**
     * Test UI/Display constants are defined
     */
    public function testUIDisplayConstantsAreDefined(): void
    {
        $this->assertEquals('d/m/Y H:i:s', Constants::DISPLAY_DATE_FORMAT);
        $this->assertEquals(8, Constants::DISPLAY_CURRENCY_DECIMALS);
        $this->assertEquals(10, Constants::DISPLAY_DEFAULT_OUTPUT_LINES_MAX);
        $this->assertIsBool(Constants::AUTO_REFRESH_ENABLED);
    }

    /**
     * Test file path constants are defined
     */
    public function testFilePathConstantsAreDefined(): void
    {
        $this->assertEquals('/etc/eiou/config/', Constants::PATH_CONFIG_DIR);
        $this->assertEquals('/var/log/eiou/app.log', Constants::LOG_FILE_APP);
        $this->assertEquals('INFO', Constants::LOG_LEVEL);
        $this->assertEquals(100, Constants::LOG_MAX_ENTRIES);
    }

    /**
     * Test backup constants are defined
     */
    public function testBackupConstantsAreDefined(): void
    {
        $this->assertTrue(Constants::BACKUP_AUTO_ENABLED);
        $this->assertEquals(3, Constants::BACKUP_RETENTION_COUNT);
        $this->assertEquals('/var/lib/eiou/backups', Constants::BACKUP_DIRECTORY);
        $this->assertEquals('.eiou.enc', Constants::BACKUP_FILE_EXTENSION);
        $this->assertEquals(0, Constants::BACKUP_CRON_HOUR);
        $this->assertEquals(0, Constants::BACKUP_CRON_MINUTE);
    }

    /**
     * Test adaptive polling constants are defined
     */
    public function testAdaptivePollingConstantsAreDefined(): void
    {
        $this->assertEquals(100, Constants::ADAPTIVE_POLLING_QUEUE_DIVISOR);
        $this->assertEquals(0.1, Constants::ADAPTIVE_POLLING_MIN_FACTOR);
    }

    /**
     * Test internal conversion factor and display decimals are defined
     */
    public function testInternalConversionFactorAndDisplayDecimalsDefined(): void
    {
        $this->assertEquals(100000000, Constants::INTERNAL_CONVERSION_FACTOR);
        $this->assertEquals(8, Constants::INTERNAL_PRECISION);
        $this->assertEquals(2, Constants::DISPLAY_DECIMALS);
        $this->assertEquals(100, Constants::FEE_CONVERSION_FACTOR);
        $this->assertEquals(2, Constants::FEE_PERCENT_DECIMAL_PRECISION);
    }

    /**
     * Test getConversionFactor always returns internal factor
     */
    public function testGetConversionFactorReturnsInternalFactor(): void
    {
        // Always returns INTERNAL_CONVERSION_FACTOR regardless of currency
        $this->assertEquals(100000000, Constants::getConversionFactor('USD'));
        $this->assertEquals(100000000, Constants::getConversionFactor('BTC'));
        $this->assertEquals(100000000, Constants::getConversionFactor('XYZ'));
    }

    /**
     * Test getCurrencyDecimals always returns internal precision
     */
    public function testGetCurrencyDecimalsReturnsInternalPrecision(): void
    {
        $this->assertEquals(8, Constants::getCurrencyDecimals('USD'));
        $this->assertEquals(8, Constants::getCurrencyDecimals('BTC'));
        $this->assertEquals(8, Constants::getCurrencyDecimals('EUR'));
    }

    /**
     * Test getDisplayDecimals returns global default (no currency parameter)
     */
    public function testGetDisplayDecimalsReturnsGlobalDefault(): void
    {
        $this->assertEquals(Constants::DISPLAY_DECIMALS, Constants::getDisplayDecimals());
        $this->assertEquals(2, Constants::getDisplayDecimals());
    }

    /**
     * Test P2P request level randomization constants are defined
     */
    public function testP2PRequestLevelRandomizationConstantsAreDefined(): void
    {
        $this->assertEquals(300, Constants::P2P_MIN_REQUEST_LEVEL_RANGE_LOW);
        $this->assertEquals(700, Constants::P2P_MIN_REQUEST_LEVEL_RANGE_HIGH);
        $this->assertEquals(200, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_LOW);
        $this->assertEquals(500, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_HIGH);
        $this->assertEquals(1, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_LOW);
        $this->assertEquals(10, Constants::P2P_MIN_REQUEST_LEVEL_RANDOM_OFFSET_HIGH);
    }

    /**
     * Test rate limiting and API constants are defined
     */
    public function testRateLimitingAndAPIConstantsAreDefined(): void
    {
        $this->assertTrue(Constants::RATE_LIMIT_ENABLED);
        $this->assertTrue(Constants::API_ENABLED);
        $this->assertEquals('', Constants::API_CORS_ALLOWED_ORIGINS);
    }

    /**
     * Use reflection to verify all expected constants exist
     */
    public function testAllExpectedConstantsExistUsingReflection(): void
    {
        $reflection = new ReflectionClass(Constants::class);
        $constants = $reflection->getConstants();

        // Status constants
        $expectedStatusConstants = [
            'STATUS_PENDING', 'STATUS_SENDING', 'STATUS_SENT', 'STATUS_ACCEPTED',
            'STATUS_COMPLETED', 'STATUS_CANCELLED', 'STATUS_REJECTED', 'STATUS_EXPIRED',
            'STATUS_PAID', 'STATUS_FAILED', 'STATUS_INITIAL', 'STATUS_QUEUED', 'STATUS_PROCESSED'
        ];

        foreach ($expectedStatusConstants as $constant) {
            $this->assertArrayHasKey($constant, $constants, "Missing constant: $constant");
        }

        // Delivery stage constants
        $expectedDeliveryConstants = [
            'DELIVERY_PENDING', 'DELIVERY_SENT', 'DELIVERY_COMPLETED', 'DELIVERY_FAILED',
            'DELIVERY_RECEIVED', 'DELIVERY_INSERTED', 'DELIVERY_FORWARDED',
            'DELIVERY_ACKNOWLEDGED', 'DELIVERY_WARNING', 'DELIVERY_UPDATED', 'DELIVERY_REJECTED'
        ];

        foreach ($expectedDeliveryConstants as $constant) {
            $this->assertArrayHasKey($constant, $constants, "Missing constant: $constant");
        }

        // Hash/crypto constants
        $this->assertArrayHasKey('HASH_ALGORITHM', $constants);

        // Transaction constants
        $expectedTransactionConstants = [
            'TRANSACTION_MAX_AMOUNT', 'TRANSACTION_DEFAULT_CURRENCY',
            'TRANSACTION_MINIMUM_FEE', 'INTERNAL_CONVERSION_FACTOR',
            'INTERNAL_PRECISION', 'DISPLAY_DECIMALS',
            'TRANSACTION_MIN_INTERVAL_MS', 'TRANSACTION_MAX_INTERVAL_MS',
            'TRANSACTION_IDLE_INTERVAL_MS', 'TRANSACTION_ADAPTIVE_POLLING'
        ];

        foreach ($expectedTransactionConstants as $constant) {
            $this->assertArrayHasKey($constant, $constants, "Missing constant: $constant");
        }

        // Validation constants
        $expectedValidationConstants = [
            'VALIDATION_PUBLIC_KEY_MIN_LENGTH', 'VALIDATION_SIGNATURE_MIN_LENGTH',
            'VALIDATION_TOR_V3_ADDRESS_LENGTH', 'VALIDATION_TOR_V2_ADDRESS_LENGTH',
            'VALIDATION_HASH_LENGTH_SHA256', 'VALIDATION_CURRENCY_CODE_MIN_LENGTH', 'VALIDATION_CURRENCY_CODE_MAX_LENGTH',
            'VALIDATION_MEMO_MAX_LENGTH', 'VALIDATION_FEE_MIN_PERCENT', 'VALIDATION_FEE_MAX_PERCENT'
        ];

        foreach ($expectedValidationConstants as $constant) {
            $this->assertArrayHasKey($constant, $constants, "Missing constant: $constant");
        }

        // Time conversion constants
        $expectedTimeConstants = [
            'TIME_MICROSECONDS_TO_INT', 'TIME_SECONDS_PER_MINUTE',
            'TIME_MINUTES_PER_HOUR', 'TIME_HOURS_PER_DAY'
        ];

        foreach ($expectedTimeConstants as $constant) {
            $this->assertArrayHasKey($constant, $constants, "Missing constant: $constant");
        }
    }

    /**
     * Test get method returns constant value
     */
    public function testGetMethodReturnsConstantValue(): void
    {
        $this->assertEquals('sha256', Constants::get('HASH_ALGORITHM'));
        $this->assertEquals('pending', Constants::get('STATUS_PENDING'));
        $this->assertEquals(100, Constants::get('TRANSACTION_MIN_INTERVAL_MS'));
    }

    /**
     * Test get method returns default for non-existent constant
     */
    public function testGetMethodReturnsDefaultForNonExistentConstant(): void
    {
        $this->assertNull(Constants::get('NON_EXISTENT_CONSTANT'));
        $this->assertEquals('default_value', Constants::get('NON_EXISTENT_CONSTANT', 'default_value'));
        $this->assertEquals(123, Constants::get('NON_EXISTENT_CONSTANT', 123));
    }

    /**
     * Test all method returns array of all constants
     */
    public function testAllMethodReturnsArrayOfAllConstants(): void
    {
        $all = Constants::all();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);

        // Check that it contains expected keys
        $this->assertArrayHasKey('HASH_ALGORITHM', $all);
        $this->assertArrayHasKey('STATUS_PENDING', $all);
        $this->assertArrayHasKey('DELIVERY_SENT', $all);
    }

    /**
     * Test singleton getInstance method
     * Note: This test documents that getInstance() has a namespace bug
     * that causes it to fail when Constants::all() is called internally.
     * The singleton pattern itself works correctly once the bug is fixed.
     */
    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = Constants::getInstance();
        $instance2 = Constants::getInstance();
        $this->assertSame($instance1, $instance2);
        $this->assertInstanceOf(Constants::class, $instance1);
    }

    /**
     * Test isContactStatusEnabled method returns boolean
     */
    public function testIsContactStatusEnabledReturnsBoolean(): void
    {
        $result = Constants::isContactStatusEnabled();
        $this->assertIsBool($result);
    }

    /**
     * Test isAutoBackupEnabled method returns boolean
     */
    public function testIsAutoBackupEnabledReturnsBoolean(): void
    {
        $result = Constants::isAutoBackupEnabled();
        $this->assertIsBool($result);
    }

    /**
     * Test isContactStatusEnabled respects environment variable
     */
    public function testIsContactStatusEnabledRespectsEnvVariable(): void
    {
        // Save original env value
        $originalValue = getenv('EIOU_CONTACT_STATUS_ENABLED');

        // Test with env variable set to false
        putenv('EIOU_CONTACT_STATUS_ENABLED=false');
        $this->assertFalse(Constants::isContactStatusEnabled());

        // Test with env variable set to true
        putenv('EIOU_CONTACT_STATUS_ENABLED=true');
        $this->assertTrue(Constants::isContactStatusEnabled());

        // Test with env variable set to 0
        putenv('EIOU_CONTACT_STATUS_ENABLED=0');
        $this->assertFalse(Constants::isContactStatusEnabled());

        // Test with env variable set to 1
        putenv('EIOU_CONTACT_STATUS_ENABLED=1');
        $this->assertTrue(Constants::isContactStatusEnabled());

        // Restore original value
        if ($originalValue === false) {
            putenv('EIOU_CONTACT_STATUS_ENABLED');
        } else {
            putenv("EIOU_CONTACT_STATUS_ENABLED=$originalValue");
        }
    }

    /**
     * Test isAutoBackupEnabled respects environment variable
     */
    public function testIsAutoBackupEnabledRespectsEnvVariable(): void
    {
        // Save original env value
        $originalValue = getenv('EIOU_BACKUP_AUTO_ENABLED');

        // Test with env variable set to false
        putenv('EIOU_BACKUP_AUTO_ENABLED=false');
        $this->assertFalse(Constants::isAutoBackupEnabled());

        // Test with env variable set to true
        putenv('EIOU_BACKUP_AUTO_ENABLED=true');
        $this->assertTrue(Constants::isAutoBackupEnabled());

        // Restore original value
        if ($originalValue === false) {
            putenv('EIOU_BACKUP_AUTO_ENABLED');
        } else {
            putenv("EIOU_BACKUP_AUTO_ENABLED=$originalValue");
        }
    }

    /**
     * Test all constants count is reasonable
     */
    public function testConstantsCountIsReasonable(): void
    {
        $all = Constants::all();

        // Sanity check — Constants is a single class and should not blow up
        // unbounded. Bounds are deliberately wide (80..500) so this catches
        // accidental duplication or mass deletion without flapping every
        // time a couple of constants are added or removed.
        $this->assertGreaterThanOrEqual(80, count($all));
        $this->assertLessThan(500, count($all));
    }

    /**
     * Test polling interval constants are within reasonable ranges
     */
    public function testPollingIntervalConstantsAreWithinReasonableRanges(): void
    {
        // Transaction polling
        $this->assertGreaterThan(0, Constants::TRANSACTION_MIN_INTERVAL_MS);
        $this->assertGreaterThan(Constants::TRANSACTION_MIN_INTERVAL_MS, Constants::TRANSACTION_MAX_INTERVAL_MS);
        $this->assertGreaterThanOrEqual(Constants::TRANSACTION_MIN_INTERVAL_MS, Constants::TRANSACTION_IDLE_INTERVAL_MS);
        $this->assertLessThanOrEqual(Constants::TRANSACTION_MAX_INTERVAL_MS, Constants::TRANSACTION_IDLE_INTERVAL_MS);

        // P2P polling
        $this->assertGreaterThan(0, Constants::P2P_MIN_INTERVAL_MS);
        $this->assertGreaterThan(Constants::P2P_MIN_INTERVAL_MS, Constants::P2P_MAX_INTERVAL_MS);
        $this->assertGreaterThanOrEqual(Constants::P2P_MIN_INTERVAL_MS, Constants::P2P_IDLE_INTERVAL_MS);
        $this->assertLessThanOrEqual(Constants::P2P_MAX_INTERVAL_MS, Constants::P2P_IDLE_INTERVAL_MS);

        // Cleanup polling
        $this->assertGreaterThan(0, Constants::CLEANUP_MIN_INTERVAL_MS);
        $this->assertGreaterThan(Constants::CLEANUP_MIN_INTERVAL_MS, Constants::CLEANUP_MAX_INTERVAL_MS);
        $this->assertGreaterThanOrEqual(Constants::CLEANUP_MIN_INTERVAL_MS, Constants::CLEANUP_IDLE_INTERVAL_MS);
        $this->assertLessThanOrEqual(Constants::CLEANUP_MAX_INTERVAL_MS, Constants::CLEANUP_IDLE_INTERVAL_MS);
    }

    /**
     * Test contact status polling constants are within reasonable ranges
     */
    public function testContactStatusPollingConstantsAreWithinReasonableRanges(): void
    {
        $this->assertGreaterThan(0, Constants::CONTACT_STATUS_MIN_INTERVAL_MS);
        $this->assertGreaterThan(Constants::CONTACT_STATUS_MIN_INTERVAL_MS, Constants::CONTACT_STATUS_MAX_INTERVAL_MS);
        $this->assertGreaterThanOrEqual(Constants::CONTACT_STATUS_MIN_INTERVAL_MS, Constants::CONTACT_STATUS_IDLE_INTERVAL_MS);
        $this->assertLessThanOrEqual(Constants::CONTACT_STATUS_MAX_INTERVAL_MS, Constants::CONTACT_STATUS_IDLE_INTERVAL_MS);
    }

    /**
     * Test validation length constants are positive
     */
    public function testValidationLengthConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, Constants::VALIDATION_PUBLIC_KEY_MIN_LENGTH);
        $this->assertGreaterThan(0, Constants::VALIDATION_SIGNATURE_MIN_LENGTH);
        $this->assertGreaterThan(0, Constants::VALIDATION_TOR_V3_ADDRESS_LENGTH);
        $this->assertGreaterThan(0, Constants::VALIDATION_TOR_V2_ADDRESS_LENGTH);
        $this->assertGreaterThan(0, Constants::VALIDATION_HASH_LENGTH_SHA256);
        $this->assertGreaterThan(0, Constants::VALIDATION_CURRENCY_CODE_MIN_LENGTH);
        $this->assertGreaterThan(0, Constants::VALIDATION_CURRENCY_CODE_MAX_LENGTH);
        $this->assertGreaterThan(0, Constants::VALIDATION_MEMO_MAX_LENGTH);
    }

    /**
     * Test fee percent validation bounds are logical
     */
    public function testFeePercentValidationBoundsAreLogical(): void
    {
        $this->assertGreaterThanOrEqual(0, Constants::VALIDATION_FEE_MIN_PERCENT);
        $this->assertGreaterThan(Constants::VALIDATION_FEE_MIN_PERCENT, Constants::VALIDATION_FEE_MAX_PERCENT);
        $this->assertEquals(100, Constants::VALIDATION_FEE_MAX_PERCENT);
    }

    /**
     * Test contact name length bounds are logical
     */
    public function testContactNameLengthBoundsAreLogical(): void
    {
        $this->assertGreaterThan(0, Constants::CONTACT_MIN_NAME_LENGTH);
        $this->assertGreaterThan(Constants::CONTACT_MIN_NAME_LENGTH, Constants::CONTACT_MAX_NAME_LENGTH);
    }

    /**
     * Test P2P request level bounds are logical
     */
    public function testP2PRequestLevelBoundsAreLogical(): void
    {
        $this->assertGreaterThan(0, Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL);
        $this->assertLessThanOrEqual(Constants::P2P_MAX_ROUTING_LEVEL, Constants::P2P_DEFAULT_MAX_REQUEST_LEVEL);
        $this->assertGreaterThan(0, Constants::P2P_MIN_EXPIRATION_SECONDS);
        $this->assertGreaterThan(Constants::P2P_MIN_EXPIRATION_SECONDS, Constants::P2P_DEFAULT_EXPIRATION_SECONDS);
    }

    /**
     * Test session timeout constants are valid
     */
    public function testSessionTimeoutConstantsAreValid(): void
    {
        $this->assertGreaterThan(0, Constants::SESSION_TIMEOUT_MINUTES);
        $this->assertIsArray(Constants::SESSION_TIMEOUT_OPTIONS);
        $this->assertNotEmpty(Constants::SESSION_TIMEOUT_OPTIONS);
        $this->assertContains(Constants::SESSION_TIMEOUT_MINUTES, Constants::SESSION_TIMEOUT_OPTIONS);

        // All options must be positive integers
        foreach (Constants::SESSION_TIMEOUT_OPTIONS as $option) {
            $this->assertIsInt($option);
            $this->assertGreaterThan(0, $option);
        }

        // Options should be sorted ascending
        $sorted = Constants::SESSION_TIMEOUT_OPTIONS;
        sort($sorted);
        $this->assertSame($sorted, Constants::SESSION_TIMEOUT_OPTIONS);
    }

    // =========================================================================
    // ADDRESS_TYPE_DISPLAY registry + getAddressTypeDisplay fallback.
    //
    // Consumed by the contact modal's address dropdown (script.js) and the
    // pending-contact modal's address pills (contactSection.html). Also
    // bootstrapped to JS globals via wallet.html. A missing entry for a
    // schema column (e.g. a future `i2p` transport) MUST fall back to
    // UPPERCASE(type) label + `fa-question` icon rather than breaking
    // template rendering.
    // =========================================================================

    public function testAddressTypeDisplayKnownTypes(): void
    {
        $tor = Constants::getAddressTypeDisplay('tor');
        $this->assertSame('Tor', $tor['label']);
        $this->assertSame('fa-user-secret', $tor['icon']);

        $https = Constants::getAddressTypeDisplay('https');
        $this->assertSame('HTTPS', $https['label']);
        $this->assertSame('fa-lock', $https['icon']);

        $http = Constants::getAddressTypeDisplay('http');
        $this->assertSame('HTTP', $http['label']);
        $this->assertSame('fa-globe', $http['icon']);
    }

    public function testAddressTypeDisplayFallsBackForUnknownType(): void
    {
        // Simulates a freshly-added `addresses.i2p` column before the
        // registry has been taught about it — must not throw, must return
        // a sensible placeholder so the GUI keeps rendering.
        $unknown = Constants::getAddressTypeDisplay('i2p');
        $this->assertSame('I2P', $unknown['label']);
        $this->assertSame('fa-question', $unknown['icon']);

        // Pathological input: empty string. Falls back with empty uppercase.
        $empty = Constants::getAddressTypeDisplay('');
        $this->assertSame('', $empty['label']);
        $this->assertSame('fa-question', $empty['icon']);
    }

    public function testAddressTypeDisplayKeysMatchValidTransportIndices(): void
    {
        // Drift guard. The two constants describe the same universe of
        // "known address types":
        //   - VALID_TRANSPORT_INDICES: the canonical priority order used
        //     for display ordering and env-var validation.
        //   - ADDRESS_TYPE_DISPLAY:    the label+icon registry for the
        //     contact-modal dropdown and pending-contact pills.
        // Adding a transport to one but not the other produces working-
        // but-ugly output (fa-question fallback) or a type that renders
        // last despite having proper metadata. This test keeps the two
        // in sync unless someone explicitly updates both.
        $displayKeys = array_keys(Constants::ADDRESS_TYPE_DISPLAY);
        sort($displayKeys);
        $validTransports = Constants::VALID_TRANSPORT_INDICES;
        sort($validTransports);
        $this->assertSame(
            $validTransports,
            $displayKeys,
            'ADDRESS_TYPE_DISPLAY keys and VALID_TRANSPORT_INDICES must describe the same set'
        );
    }
}
