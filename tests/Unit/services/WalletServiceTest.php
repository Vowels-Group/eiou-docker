<?php
/**
 * WalletService Unit Tests
 *
 * Test Coverage:
 * - Wallet key management
 * - Wallet validation
 * - Address retrieval
 * - Configuration checks
 *
 * Manual Test Instructions:
 * 1. Run: php tests/unit/services/WalletServiceTest.php
 * 2. Expected: All tests pass
 * 3. Tests verify wallet operations
 */

require_once dirname(__DIR__, 2) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 3) . '/src/services/WalletService.php';

class WalletServiceTest extends TestCase {
    private $service;
    private $mockUser;

    public function setUp() {
        parent::setUp();
        $this->mockUser = [
            'public' => 'test_public_key_123',
            'private' => 'test_private_key_456',
            'authcode' => 'test_auth_code_789',
            'torAddress' => 'test.onion',
            'hostname' => 'https://test.com'
        ];
        $this->service = new WalletService($this->mockUser);
    }

    /**
     * Test: Get public key returns expected value
     *
     * Manual Reproduction:
     * 1. Create WalletService with user data containing public key
     * 2. Call getPublicKey()
     * 3. Verify returns the public key string
     *
     * Expected: Public key is returned
     */
    public function testGetPublicKeyReturnsString() {
        $result = $this->service->getPublicKey();
        $this->assertEquals('test_public_key_123', $result, 'Should return public key');
    }

    /**
     * Test: Get private key returns expected value
     */
    public function testGetPrivateKeyReturnsString() {
        $result = $this->service->getPrivateKey();
        $this->assertEquals('test_private_key_456', $result, 'Should return private key');
    }

    /**
     * Test: Get auth code returns expected value
     */
    public function testGetAuthCodeReturnsString() {
        $result = $this->service->getAuthCode();
        $this->assertEquals('test_auth_code_789', $result, 'Should return auth code');
    }

    /**
     * Test: Get Tor address returns expected value
     */
    public function testGetTorAddressReturnsString() {
        $result = $this->service->getTorAddress();
        $this->assertEquals('test.onion', $result, 'Should return Tor address');
    }

    /**
     * Test: Get hostname returns expected value
     */
    public function testGetHostnameReturnsString() {
        $result = $this->service->getHostname();
        $this->assertEquals('https://test.com', $result, 'Should return hostname');
    }

    /**
     * Test: Has keys returns true when wallet has both keys
     */
    public function testHasKeysReturnsTrue() {
        $result = $this->service->hasKeys();
        $this->assertTrue($result, 'Should return true when keys exist');
    }

    /**
     * Test: Has keys returns false when public key missing
     */
    public function testHasKeysReturnsFalseWhenPublicMissing() {
        $user = ['private' => 'private_key'];
        $service = new WalletService($user);

        $result = $service->hasKeys();
        $this->assertFalse($result, 'Should return false when public key missing');
    }

    /**
     * Test: Has keys returns false when private key missing
     */
    public function testHasKeysReturnsFalseWhenPrivateMissing() {
        $user = ['public' => 'public_key'];
        $service = new WalletService($user);

        $result = $service->hasKeys();
        $this->assertFalse($result, 'Should return false when private key missing');
    }

    /**
     * Test: Validate wallet returns valid for complete wallet
     */
    public function testValidateWalletReturnsValid() {
        $result = $this->service->validateWallet();

        $this->assertTrue(is_array($result), 'Should return array');
        $this->assertTrue($result['valid'], 'Should be valid');
        $this->assertTrue(is_array($result['errors']), 'Should have errors array');
        $this->assertEquals(0, count($result['errors']), 'Should have no errors');
    }

    /**
     * Test: Validate wallet returns errors for incomplete wallet
     */
    public function testValidateWalletReturnsErrorsForIncomplete() {
        $user = ['public' => 'pub_key'];
        $service = new WalletService($user);

        $result = $service->validateWallet();

        $this->assertFalse($result['valid'], 'Should be invalid');
        $this->assertTrue(count($result['errors']) > 0, 'Should have errors');
    }

    /**
     * Test: Get public key returns null when not set
     */
    public function testGetPublicKeyReturnsNullWhenNotSet() {
        $user = [];
        $service = new WalletService($user);

        $result = $service->getPublicKey();
        $this->assertNull($result, 'Should return null when not set');
    }

    /**
     * Test: Validate wallet detects missing public key
     */
    public function testValidateWalletDetectsMissingPublicKey() {
        $user = ['private' => 'priv', 'authcode' => 'auth', 'hostname' => 'http://test'];
        $service = new WalletService($user);

        $result = $service->validateWallet();
        $this->assertFalse($result['valid'], 'Should be invalid without public key');
    }

    /**
     * Test: Validate wallet detects missing private key
     */
    public function testValidateWalletDetectsMissingPrivateKey() {
        $user = ['public' => 'pub', 'authcode' => 'auth', 'hostname' => 'http://test'];
        $service = new WalletService($user);

        $result = $service->validateWallet();
        $this->assertFalse($result['valid'], 'Should be invalid without private key');
    }

    /**
     * Test: Validate wallet detects missing auth code
     */
    public function testValidateWalletDetectsMissingAuthCode() {
        $user = ['public' => 'pub', 'private' => 'priv', 'hostname' => 'http://test'];
        $service = new WalletService($user);

        $result = $service->validateWallet();
        $this->assertFalse($result['valid'], 'Should be invalid without auth code');
    }

    /**
     * Test: Validate wallet detects missing network address
     */
    public function testValidateWalletDetectsMissingNetworkAddress() {
        $user = ['public' => 'pub', 'private' => 'priv', 'authcode' => 'auth'];
        $service = new WalletService($user);

        $result = $service->validateWallet();
        $this->assertFalse($result['valid'], 'Should be invalid without network address');
    }
}

// Run tests
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new WalletServiceTest();
    echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║            WalletService Unit Tests                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";

    $tests = [
        'Get public key' => 'testGetPublicKeyReturnsString',
        'Get private key' => 'testGetPrivateKeyReturnsString',
        'Get auth code' => 'testGetAuthCodeReturnsString',
        'Get Tor address' => 'testGetTorAddressReturnsString',
        'Get hostname' => 'testGetHostnameReturnsString',
        'Has keys returns true' => 'testHasKeysReturnsTrue',
        'Has keys false when public missing' => 'testHasKeysReturnsFalseWhenPublicMissing',
        'Has keys false when private missing' => 'testHasKeysReturnsFalseWhenPrivateMissing',
        'Validate wallet returns valid' => 'testValidateWalletReturnsValid',
        'Validate incomplete wallet' => 'testValidateWalletReturnsErrorsForIncomplete',
        'Get public key null when not set' => 'testGetPublicKeyReturnsNullWhenNotSet',
        'Validate detects missing public key' => 'testValidateWalletDetectsMissingPublicKey',
        'Validate detects missing private key' => 'testValidateWalletDetectsMissingPrivateKey',
        'Validate detects missing auth code' => 'testValidateWalletDetectsMissingAuthCode',
        'Validate detects missing network address' => 'testValidateWalletDetectsMissingNetworkAddress',
    ];

    $passed = $failed = 0;
    foreach ($tests as $name => $method) {
        $test->setUp();
        try {
            $test->$method();
            echo "✓ $name\n";
            $passed++;
        } catch (Exception $e) {
            echo "✗ $name: " . $e->getMessage() . "\n";
            $failed++;
        }
        $test->tearDown();
    }

    echo "\n" . str_repeat('─', 70) . "\n";
    echo "Results: $passed passed, $failed failed\n";
    echo ($failed === 0) ? "✅ ALL TESTS PASSED\n" : "❌ SOME TESTS FAILED\n";
    exit($failed > 0 ? 1 : 0);
}
