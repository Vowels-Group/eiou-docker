<?php
/**
 * Unit tests for UserContext class
 * Tests all getter methods, setter methods, validation, and edge cases
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';

// Load the UserContext class
require_once dirname(__DIR__, 2) . '/src/context/UserContext.php';

use EIOU\Context\UserContext;

class UserContextTest extends TestCase {

    private $sampleUserData;

    public function setUp() {
        parent::setUp();

        // Setup sample user data for testing
        $this->sampleUserData = [
            'public' => 'sample_public_key_12345',
            'private' => 'sample_private_key_67890',
            'hostname' => 'localhost:8080',
            'torAddress' => 'abc123def456ghi789.onion',
            'defaultFee' => 0.5,
            'defaultCurrency' => 'EUR',
            'localhostOnly' => false,
            'maxFee' => 10.0,
            'maxP2pLevel' => 8,
            'p2pExpiration' => 600,
            'debug' => true,
            'maxOutput' => 10,
            'dbHost' => 'localhost',
            'dbName' => 'test_db',
            'dbUser' => 'test_user',
            'dbPass' => 'test_pass',
            'customField' => 'custom_value'
        ];
    }

    public function tearDown() {
        parent::tearDown();
        $this->sampleUserData = null;
    }

    // ==================== Constructor Tests ====================

    public function testConstructorWithEmptyData() {
        $context = new UserContext();
        $this->assertNotNull($context, "UserContext should be created with empty data");
        $this->assertEquals([], $context->toArray(), "Empty UserContext should have empty array");
    }

    public function testConstructorWithData() {
        $context = new UserContext($this->sampleUserData);
        $this->assertNotNull($context, "UserContext should be created with data");
        $this->assertEquals($this->sampleUserData, $context->toArray(), "UserContext should store all data");
    }

    // ==================== Key Getter Tests ====================

    public function testGetPublicKey() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('sample_public_key_12345', $context->getPublicKey(), "Public key should match");
    }

    public function testGetPublicKeyWhenNull() {
        $context = new UserContext([]);
        $this->assertNull($context->getPublicKey(), "Public key should be null when not set");
    }

    public function testGetPrivateKey() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('sample_private_key_67890', $context->getPrivateKey(), "Private key should match");
    }

    public function testGetPrivateKeyWhenNull() {
        $context = new UserContext([]);
        $this->assertNull($context->getPrivateKey(), "Private key should be null when not set");
    }

    // ==================== Address Getter Tests ====================

    public function testGetHostname() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('localhost:8080', $context->getHostname(), "Hostname should match");
    }

    public function testGetHostnameWhenNull() {
        $context = new UserContext([]);
        $this->assertNull($context->getHostname(), "Hostname should be null when not set");
    }

    public function testGetTorAddress() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('abc123def456ghi789.onion', $context->getTorAddress(), "Tor address should match");
    }

    public function testGetTorAddressWhenNull() {
        $context = new UserContext([]);
        $this->assertNull($context->getTorAddress(), "Tor address should be null when not set");
    }

    public function testGetUserAddressesBoth() {
        $context = new UserContext($this->sampleUserData);
        $addresses = $context->getUserAddresses();
        $this->assertEquals(2, count($addresses), "Should have 2 addresses");
        $this->assertTrue(in_array('localhost:8080', $addresses), "Should include hostname");
        $this->assertTrue(in_array('abc123def456ghi789.onion', $addresses), "Should include tor address");
    }

    public function testGetUserAddressesOnlyHostname() {
        $data = ['hostname' => 'localhost:8080'];
        $context = new UserContext($data);
        $addresses = $context->getUserAddresses();
        $this->assertEquals(1, count($addresses), "Should have 1 address");
        $this->assertEquals('localhost:8080', $addresses[0], "Should be hostname");
    }

    public function testGetUserAddressesOnlyTor() {
        $data = ['torAddress' => 'abc123def456ghi789.onion'];
        $context = new UserContext($data);
        $addresses = $context->getUserAddresses();
        $this->assertEquals(1, count($addresses), "Should have 1 address");
        $this->assertEquals('abc123def456ghi789.onion', $addresses[0], "Should be tor address");
    }

    public function testGetUserAddressesEmpty() {
        $context = new UserContext([]);
        $addresses = $context->getUserAddresses();
        $this->assertEquals(0, count($addresses), "Should have no addresses");
    }

    public function testIsMyAddress() {
        $context = new UserContext($this->sampleUserData);
        $this->assertTrue($context->isMyAddress('localhost:8080'), "Should recognize hostname");
        $this->assertTrue($context->isMyAddress('abc123def456ghi789.onion'), "Should recognize tor address");
        $this->assertFalse($context->isMyAddress('other.address'), "Should not recognize unknown address");
    }

    // ==================== Configuration Getter Tests ====================

    public function testGetDefaultFee() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals(0.5, $context->getDefaultFee(), "Default fee should match");
    }

    public function testGetDefaultFeeWithDefault() {
        $context = new UserContext([]);
        $this->assertEquals(0.1, $context->getDefaultFee(), "Default fee should be 0.1");
    }

    public function testGetDefaultCurrency() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('EUR', $context->getDefaultCurrency(), "Default currency should match");
    }

    public function testGetDefaultCurrencyWithDefault() {
        $context = new UserContext([]);
        $this->assertEquals('USD', $context->getDefaultCurrency(), "Default currency should be USD");
    }

    public function testIsLocalhostOnly() {
        $context = new UserContext($this->sampleUserData);
        $this->assertFalse($context->isLocalhostOnly(), "Localhost only should be false");
    }

    public function testIsLocalhostOnlyWithDefault() {
        $context = new UserContext([]);
        $this->assertTrue($context->isLocalhostOnly(), "Localhost only should default to true");
    }

    public function testGetMaxFee() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals(10.0, $context->getMaxFee(), "Max fee should match");
    }

    public function testGetMaxFeeWithDefault() {
        $context = new UserContext([]);
        $this->assertEquals(5.0, $context->getMaxFee(), "Max fee should default to 5.0");
    }

    public function testGetMaxP2pLevel() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals(8, $context->getMaxP2pLevel(), "Max P2P level should match");
    }

    public function testGetMaxP2pLevelWithDefault() {
        $context = new UserContext([]);
        $this->assertEquals(6, $context->getMaxP2pLevel(), "Max P2P level should default to 6");
    }

    public function testGetP2pExpiration() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals(600, $context->getP2pExpiration(), "P2P expiration should match");
    }

    public function testGetP2pExpirationWithDefault() {
        $context = new UserContext([]);
        $this->assertEquals(300, $context->getP2pExpiration(), "P2P expiration should default to 300");
    }

    public function testIsDebugMode() {
        $context = new UserContext($this->sampleUserData);
        $this->assertTrue($context->isDebugMode(), "Debug mode should be true");
    }

    public function testIsDebugModeWithDefault() {
        $context = new UserContext([]);
        $this->assertFalse($context->isDebugMode(), "Debug mode should default to false");
    }

    public function testGetMaxOutput() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals(10, $context->getMaxOutput(), "Max output should match");
    }

    public function testGetMaxOutputWithDefault() {
        $context = new UserContext([]);
        $this->assertEquals(5, $context->getMaxOutput(), "Max output should default to 5");
    }

    // ==================== Database Configuration Tests ====================

    public function testGetDbHost() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('localhost', $context->getDbHost(), "DB host should match");
    }

    public function testGetDbHostWhenNull() {
        $context = new UserContext([]);
        $this->assertNull($context->getDbHost(), "DB host should be null when not set");
    }

    public function testGetDbName() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('test_db', $context->getDbName(), "DB name should match");
    }

    public function testGetDbUser() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('test_user', $context->getDbUser(), "DB user should match");
    }

    public function testGetDbPass() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('test_pass', $context->getDbPass(), "DB pass should match");
    }

    public function testHasValidDbConfig() {
        $context = new UserContext($this->sampleUserData);
        $this->assertTrue($context->hasValidDbConfig(), "DB config should be valid");
    }

    public function testHasValidDbConfigIncomplete() {
        $data = ['dbHost' => 'localhost', 'dbName' => 'test_db'];
        $context = new UserContext($data);
        $this->assertFalse($context->hasValidDbConfig(), "DB config should be invalid");
    }

    public function testHasValidDbConfigEmpty() {
        $context = new UserContext([]);
        $this->assertFalse($context->hasValidDbConfig(), "Empty DB config should be invalid");
    }

    // ==================== Generic Get/Set/Has Tests ====================

    public function testGet() {
        $context = new UserContext($this->sampleUserData);
        $this->assertEquals('custom_value', $context->get('customField'), "Get should return custom field");
    }

    public function testGetWithDefault() {
        $context = new UserContext([]);
        $this->assertEquals('default', $context->get('nonExistent', 'default'), "Get should return default");
    }

    public function testGetNonExistentNull() {
        $context = new UserContext([]);
        $this->assertNull($context->get('nonExistent'), "Get should return null for non-existent key");
    }

    public function testSet() {
        $context = new UserContext([]);
        $result = $context->set('newKey', 'newValue');
        $this->assertEquals($context, $result, "Set should return self for chaining");
        $this->assertEquals('newValue', $context->get('newKey'), "Set should store value");
    }

    public function testSetChaining() {
        $context = new UserContext([]);
        $result = $context->set('key1', 'value1')
                         ->set('key2', 'value2')
                         ->set('key3', 'value3');
        $this->assertEquals('value1', $context->get('key1'), "Should set key1");
        $this->assertEquals('value2', $context->get('key2'), "Should set key2");
        $this->assertEquals('value3', $context->get('key3'), "Should set key3");
    }

    public function testHas() {
        $context = new UserContext($this->sampleUserData);
        $this->assertTrue($context->has('public'), "Has should return true for existing key");
        $this->assertFalse($context->has('nonExistent'), "Has should return false for non-existent key");
    }

    // ==================== Bridge Method Tests ====================

    public function testFromGlobal() {
        global $user;
        $user = $this->sampleUserData;

        $context = UserContext::fromGlobal();
        $this->assertNotNull($context, "FromGlobal should create UserContext");
        $this->assertEquals($this->sampleUserData, $context->toArray(), "FromGlobal should copy global data");
    }

    public function testFromGlobalWithNullUser() {
        global $user;
        $user = null;

        $context = UserContext::fromGlobal();
        $this->assertNotNull($context, "FromGlobal should create UserContext even when global is null");
        $this->assertEquals([], $context->toArray(), "FromGlobal should create empty context");
    }

    public function testToArray() {
        $context = new UserContext($this->sampleUserData);
        $array = $context->toArray();
        $this->assertEquals($this->sampleUserData, $array, "ToArray should return all data");
    }

    public function testUpdateGlobal() {
        global $user;
        $user = [];

        $context = new UserContext($this->sampleUserData);
        $context->updateGlobal();

        $this->assertEquals($this->sampleUserData, $user, "UpdateGlobal should update global variable");
    }

    // ==================== Advanced Method Tests ====================

    public function testWithOverrides() {
        $context = new UserContext(['key1' => 'value1', 'key2' => 'value2']);
        $newContext = $context->withOverrides(['key2' => 'new_value2', 'key3' => 'value3']);

        // Original should be unchanged
        $this->assertEquals('value1', $context->get('key1'), "Original should have key1");
        $this->assertEquals('value2', $context->get('key2'), "Original key2 should be unchanged");
        $this->assertFalse($context->has('key3'), "Original should not have key3");

        // New context should have merged data
        $this->assertEquals('value1', $newContext->get('key1'), "New context should have key1");
        $this->assertEquals('new_value2', $newContext->get('key2'), "New context should have overridden key2");
        $this->assertEquals('value3', $newContext->get('key3'), "New context should have key3");
    }

    // ==================== Edge Case Tests ====================

    public function testTypeCoercionForNumericFields() {
        $data = [
            'defaultFee' => '2.5',  // String
            'maxFee' => 7,          // Int
            'maxP2pLevel' => '10',  // String
            'p2pExpiration' => 500, // Int
            'maxOutput' => '8'      // String
        ];
        $context = new UserContext($data);

        $this->assertEquals(2.5, $context->getDefaultFee(), "Should coerce string to float");
        $this->assertEquals(7.0, $context->getMaxFee(), "Should coerce int to float");
        $this->assertEquals(10, $context->getMaxP2pLevel(), "Should coerce string to int");
        $this->assertEquals(500, $context->getP2pExpiration(), "Should keep int");
        $this->assertEquals(8, $context->getMaxOutput(), "Should coerce string to int");
    }

    public function testTypeCoercionForBooleanFields() {
        $data = [
            'localhostOnly' => 1,
            'debug' => 'true'
        ];
        $context = new UserContext($data);

        $this->assertTrue($context->isLocalhostOnly(), "Should coerce truthy to bool");
        $this->assertTrue($context->isDebugMode(), "Should coerce string to bool");
    }

    public function testNullHandlingInAllGetters() {
        $context = new UserContext([]);

        // These should return null
        $this->assertNull($context->getPublicKey(), "Public key null");
        $this->assertNull($context->getPrivateKey(), "Private key null");
        $this->assertNull($context->getHostname(), "Hostname null");
        $this->assertNull($context->getTorAddress(), "Tor address null");
        $this->assertNull($context->getDbHost(), "DB host null");
        $this->assertNull($context->getDbName(), "DB name null");
        $this->assertNull($context->getDbUser(), "DB user null");
        $this->assertNull($context->getDbPass(), "DB pass null");

        // These should return defaults
        $this->assertEquals(0.1, $context->getDefaultFee(), "Default fee should have default");
        $this->assertEquals('USD', $context->getDefaultCurrency(), "Default currency should have default");
        $this->assertTrue($context->isLocalhostOnly(), "Localhost only should have default");
    }

    public function testEmptyStringHandling() {
        $data = [
            'public' => '',
            'hostname' => '',
            'defaultCurrency' => ''
        ];
        $context = new UserContext($data);

        // Empty strings should be returned as-is, not converted to null
        $this->assertEquals('', $context->getPublicKey(), "Empty string should be preserved");
        $this->assertEquals('', $context->getHostname(), "Empty string should be preserved");
        $this->assertEquals('', $context->getDefaultCurrency(), "Empty string should be preserved");
    }

    public function testLargeDataSet() {
        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["key_$i"] = "value_$i";
        }

        $context = new UserContext($largeData);
        $this->assertEquals('value_500', $context->get('key_500'), "Should handle large datasets");
        $this->assertEquals(1000, count($context->toArray()), "Should store all 1000 items");
    }

    public function testArrayValuesInUserData() {
        $data = [
            'arrayField' => ['nested' => ['value' => 123]],
            'listField' => [1, 2, 3, 4, 5]
        ];
        $context = new UserContext($data);

        $arrayField = $context->get('arrayField');
        $this->assertEquals(123, $arrayField['nested']['value'], "Should handle nested arrays");

        $listField = $context->get('listField');
        $this->assertEquals(5, count($listField), "Should handle array lists");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new UserContextTest();

    SimpleTest::test('Constructor with empty data', function() use ($test) {
        $test->setUp();
        $test->testConstructorWithEmptyData();
        $test->tearDown();
    });

    SimpleTest::test('Constructor with data', function() use ($test) {
        $test->setUp();
        $test->testConstructorWithData();
        $test->tearDown();
    });

    SimpleTest::test('Get public key', function() use ($test) {
        $test->setUp();
        $test->testGetPublicKey();
        $test->tearDown();
    });

    SimpleTest::test('Get public key when null', function() use ($test) {
        $test->setUp();
        $test->testGetPublicKeyWhenNull();
        $test->tearDown();
    });

    SimpleTest::test('Get user addresses (both)', function() use ($test) {
        $test->setUp();
        $test->testGetUserAddressesBoth();
        $test->tearDown();
    });

    SimpleTest::test('Is my address', function() use ($test) {
        $test->setUp();
        $test->testIsMyAddress();
        $test->tearDown();
    });

    SimpleTest::test('Get default fee', function() use ($test) {
        $test->setUp();
        $test->testGetDefaultFee();
        $test->tearDown();
    });

    SimpleTest::test('Get default fee with default', function() use ($test) {
        $test->setUp();
        $test->testGetDefaultFeeWithDefault();
        $test->tearDown();
    });

    SimpleTest::test('Has valid DB config', function() use ($test) {
        $test->setUp();
        $test->testHasValidDbConfig();
        $test->tearDown();
    });

    SimpleTest::test('Has valid DB config (incomplete)', function() use ($test) {
        $test->setUp();
        $test->testHasValidDbConfigIncomplete();
        $test->tearDown();
    });

    SimpleTest::test('Generic get with default', function() use ($test) {
        $test->setUp();
        $test->testGetWithDefault();
        $test->tearDown();
    });

    SimpleTest::test('Generic set', function() use ($test) {
        $test->setUp();
        $test->testSet();
        $test->tearDown();
    });

    SimpleTest::test('Set chaining', function() use ($test) {
        $test->setUp();
        $test->testSetChaining();
        $test->tearDown();
    });

    SimpleTest::test('From global', function() use ($test) {
        $test->setUp();
        $test->testFromGlobal();
        $test->tearDown();
    });

    SimpleTest::test('Update global', function() use ($test) {
        $test->setUp();
        $test->testUpdateGlobal();
        $test->tearDown();
    });

    SimpleTest::test('With overrides', function() use ($test) {
        $test->setUp();
        $test->testWithOverrides();
        $test->tearDown();
    });

    SimpleTest::test('Type coercion for numeric fields', function() use ($test) {
        $test->setUp();
        $test->testTypeCoercionForNumericFields();
        $test->tearDown();
    });

    SimpleTest::test('Null handling in all getters', function() use ($test) {
        $test->setUp();
        $test->testNullHandlingInAllGetters();
        $test->tearDown();
    });

    SimpleTest::test('Large data set handling', function() use ($test) {
        $test->setUp();
        $test->testLargeDataSet();
        $test->tearDown();
    });

    SimpleTest::run();
}
