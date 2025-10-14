<?php
/**
 * Integration tests for UserContext class
 * Tests interaction with database repositories, services, and utilities
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';

// Load the UserContext class
require_once dirname(__DIR__, 2) . '/src/context/UserContext.php';

use EIOU\Context\UserContext;

class UserContextIntegrationTest extends TestCase {

    private $testPdo;
    private $sampleUserData;

    public function setUp() {
        parent::setUp();

        // Create test database
        $this->testPdo = createTestDatabase();

        // Setup sample user data
        $this->sampleUserData = [
            'public' => 'integration_test_public_key',
            'private' => 'integration_test_private_key',
            'hostname' => 'localhost:9000',
            'torAddress' => 'test123456789.onion',
            'defaultFee' => 1.0,
            'defaultCurrency' => 'USD',
            'localhostOnly' => true,
            'maxFee' => 5.0,
            'maxP2pLevel' => 6,
            'p2pExpiration' => 300,
            'debug' => false,
            'maxOutput' => 5,
            'dbHost' => 'localhost',
            'dbName' => ':memory:',
            'dbUser' => 'test',
            'dbPass' => 'test'
        ];
    }

    public function tearDown() {
        parent::tearDown();
        $this->testPdo = null;
        $this->sampleUserData = null;
    }

    // ==================== Database Repository Integration ====================

    public function testUserContextWithDatabaseConnection() {
        $context = new UserContext($this->sampleUserData);

        // Verify database config is valid
        $this->assertTrue($context->hasValidDbConfig(), "DB config should be valid");

        // Simulate database connection using context data
        $dbConfig = [
            'host' => $context->getDbHost(),
            'name' => $context->getDbName(),
            'user' => $context->getDbUser(),
            'pass' => $context->getDbPass()
        ];

        $this->assertEquals('localhost', $dbConfig['host'], "DB host should match");
        $this->assertEquals(':memory:', $dbConfig['name'], "DB name should match");
    }

    public function testUserContextWithTransactionRepository() {
        $context = new UserContext($this->sampleUserData);

        // Insert a transaction using context data
        $stmt = $this->testPdo->prepare("INSERT INTO transactions (sender_id, receiver_id, amount) VALUES (?, ?, ?)");
        $result = $stmt->execute([1, 2, 100]);

        $this->assertTrue($result, "Transaction should be inserted");

        // Verify the transaction can be queried
        $query = $this->testPdo->query("SELECT * FROM transactions WHERE sender_id = 1");
        $transaction = $query->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($transaction, "Transaction should be retrievable");
        $this->assertEquals(100, $transaction['amount'], "Transaction amount should match");
    }

    public function testUserContextWithContactRepository() {
        $context = new UserContext($this->sampleUserData);

        // Insert a contact using context data
        $stmt = $this->testPdo->prepare("INSERT INTO contacts (user_id, contact_id, status) VALUES (?, ?, ?)");
        $result = $stmt->execute([1, 2, 'pending']);

        $this->assertTrue($result, "Contact should be inserted");

        // Update contact status
        $stmt = $this->testPdo->prepare("UPDATE contacts SET status = ? WHERE user_id = ? AND contact_id = ?");
        $result = $stmt->execute(['accepted', 1, 2]);

        $this->assertTrue($result, "Contact status should be updated");
    }

    // ==================== Service Integration ====================

    public function testUserContextWithAuthenticationService() {
        $context = new UserContext($this->sampleUserData);

        // Simulate authentication using public/private keys
        $publicKey = $context->getPublicKey();
        $privateKey = $context->getPrivateKey();

        $this->assertNotNull($publicKey, "Public key should be available for auth");
        $this->assertNotNull($privateKey, "Private key should be available for auth");

        // Simulate key validation
        $isValidKeyPair = strlen($publicKey) > 0 && strlen($privateKey) > 0;
        $this->assertTrue($isValidKeyPair, "Key pair should be valid");
    }

    public function testUserContextWithTransactionService() {
        $context = new UserContext($this->sampleUserData);

        // Create a transaction with context-driven fee
        $transactionAmount = 100.0;
        $feePercentage = $context->getDefaultFee();
        $maxFee = $context->getMaxFee();

        $calculatedFee = $transactionAmount * ($feePercentage / 100);

        // Ensure fee doesn't exceed maximum
        if ($calculatedFee > $maxFee) {
            $calculatedFee = $maxFee;
        }

        $this->assertEquals(1.0, $calculatedFee, "Fee should be calculated correctly");

        $finalAmount = $transactionAmount - $calculatedFee;
        $this->assertEquals(99.0, $finalAmount, "Final amount should be correct");
    }

    public function testUserContextWithP2PService() {
        $context = new UserContext($this->sampleUserData);

        // Test P2P configuration
        $maxP2pLevel = $context->getMaxP2pLevel();
        $p2pExpiration = $context->getP2pExpiration();

        $this->assertEquals(6, $maxP2pLevel, "Max P2P level should be accessible");
        $this->assertEquals(300, $p2pExpiration, "P2P expiration should be accessible");

        // Simulate P2P message expiration check
        $currentTime = time();
        $messageTime = $currentTime - 250; // 250 seconds ago
        $isExpired = ($currentTime - $messageTime) > $p2pExpiration;

        $this->assertFalse($isExpired, "Message should not be expired");
    }

    public function testUserContextWithCurrencyService() {
        $context = new UserContext($this->sampleUserData);

        // Get default currency for transactions
        $currency = $context->getDefaultCurrency();
        $this->assertEquals('USD', $currency, "Currency should be USD");

        // Simulate currency validation
        $validCurrencies = ['USD', 'EUR', 'GBP', 'JPY'];
        $isValidCurrency = in_array($currency, $validCurrencies);

        $this->assertTrue($isValidCurrency, "Currency should be valid");
    }

    // ==================== Utility Integration ====================

    public function testUserContextWithAddressValidator() {
        $context = new UserContext($this->sampleUserData);

        // Test address validation
        $myAddresses = $context->getUserAddresses();
        $this->assertEquals(2, count($myAddresses), "Should have 2 addresses");

        // Simulate address validation for incoming transaction
        $incomingAddress = 'localhost:9000';
        $isMyAddress = $context->isMyAddress($incomingAddress);

        $this->assertTrue($isMyAddress, "Should recognize own address");

        // Test with external address
        $externalAddress = 'external.host:8080';
        $isExternal = !$context->isMyAddress($externalAddress);

        $this->assertTrue($isExternal, "Should not recognize external address");
    }

    public function testUserContextWithDebugLogger() {
        $context = new UserContext($this->sampleUserData);

        // Test debug mode for logging
        $debugMode = $context->isDebugMode();
        $this->assertFalse($debugMode, "Debug mode should be false");

        // Simulate conditional logging
        $logMessage = "Test log message";
        $shouldLog = $debugMode;

        $this->assertFalse($shouldLog, "Should not log when debug is false");

        // Enable debug and test again
        $context->set('debug', true);
        $shouldLog = $context->isDebugMode();
        $this->assertTrue($shouldLog, "Should log when debug is true");
    }

    public function testUserContextWithOutputLimiter() {
        $context = new UserContext($this->sampleUserData);

        // Test output limiting
        $maxOutput = $context->getMaxOutput();
        $this->assertEquals(5, $maxOutput, "Max output should be 5");

        // Simulate output limiting
        $items = range(1, 10);
        $limitedItems = array_slice($items, 0, $maxOutput);

        $this->assertEquals(5, count($limitedItems), "Output should be limited to 5 items");
    }

    // ==================== Schema/Config Integration ====================

    public function testUserContextWithConfigSchema() {
        $context = new UserContext($this->sampleUserData);

        // Verify all required schema fields are present
        $requiredFields = [
            'public', 'private', 'hostname', 'torAddress',
            'defaultFee', 'defaultCurrency', 'maxFee',
            'maxP2pLevel', 'p2pExpiration'
        ];

        foreach ($requiredFields as $field) {
            $this->assertTrue($context->has($field), "Schema field '{$field}' should be present");
        }
    }

    public function testUserContextWithDynamicConfigUpdate() {
        $context = new UserContext($this->sampleUserData);

        // Simulate runtime config update
        $initialFee = $context->getDefaultFee();
        $this->assertEquals(1.0, $initialFee, "Initial fee should be 1.0");

        // Update config
        $context->set('defaultFee', 2.5);
        $updatedFee = $context->getDefaultFee();
        $this->assertEquals(2.5, $updatedFee, "Fee should be updated to 2.5");
    }

    // ==================== Multi-Context Integration ====================

    public function testMultipleUserContexts() {
        // Create two separate user contexts
        $context1 = new UserContext(['public' => 'user1_key', 'defaultFee' => 0.5]);
        $context2 = new UserContext(['public' => 'user2_key', 'defaultFee' => 1.5]);

        $this->assertEquals('user1_key', $context1->getPublicKey(), "Context 1 public key");
        $this->assertEquals('user2_key', $context2->getPublicKey(), "Context 2 public key");

        $this->assertEquals(0.5, $context1->getDefaultFee(), "Context 1 fee");
        $this->assertEquals(1.5, $context2->getDefaultFee(), "Context 2 fee");

        // Verify they are independent
        $context1->set('defaultFee', 3.0);
        $this->assertEquals(3.0, $context1->getDefaultFee(), "Context 1 fee updated");
        $this->assertEquals(1.5, $context2->getDefaultFee(), "Context 2 fee unchanged");
    }

    public function testUserContextCloning() {
        $context = new UserContext($this->sampleUserData);

        // Create a modified clone
        $clonedContext = $context->withOverrides(['defaultFee' => 5.0, 'debug' => true]);

        // Original should be unchanged
        $this->assertEquals(1.0, $context->getDefaultFee(), "Original fee unchanged");
        $this->assertFalse($context->isDebugMode(), "Original debug unchanged");

        // Clone should have modifications
        $this->assertEquals(5.0, $clonedContext->getDefaultFee(), "Clone fee modified");
        $this->assertTrue($clonedContext->isDebugMode(), "Clone debug modified");
    }

    // ==================== Error Handling Integration ====================

    public function testUserContextWithInvalidDbConfig() {
        $invalidData = [
            'dbHost' => 'localhost',
            'dbName' => 'test_db'
            // Missing dbUser and dbPass
        ];

        $context = new UserContext($invalidData);

        // Should handle invalid config gracefully
        $this->assertFalse($context->hasValidDbConfig(), "Invalid DB config should be detected");

        // Application should not attempt connection
        $dbUser = $context->getDbUser();
        $this->assertNull($dbUser, "Missing DB user should be null");
    }

    public function testUserContextWithMissingAddresses() {
        $dataWithoutAddresses = [
            'public' => 'test_key',
            'private' => 'test_private'
        ];

        $context = new UserContext($dataWithoutAddresses);

        $addresses = $context->getUserAddresses();
        $this->assertEquals(0, count($addresses), "Should handle missing addresses");

        $isMyAddress = $context->isMyAddress('any.address');
        $this->assertFalse($isMyAddress, "Should not match any address when none configured");
    }

    // ==================== Cross-Feature Integration ====================

    public function testUserContextInTransactionFlow() {
        $context = new UserContext($this->sampleUserData);

        // Simulate full transaction flow
        // 1. Verify sender has keys
        $this->assertNotNull($context->getPublicKey(), "Sender must have public key");
        $this->assertNotNull($context->getPrivateKey(), "Sender must have private key");

        // 2. Calculate fee
        $amount = 1000.0;
        $fee = $amount * ($context->getDefaultFee() / 100);
        $this->assertEquals(10.0, $fee, "Fee should be calculated");

        // 3. Verify fee doesn't exceed max
        $this->assertTrue($fee <= $context->getMaxFee(), "Fee should not exceed max");

        // 4. Insert transaction
        $stmt = $this->testPdo->prepare("INSERT INTO transactions (sender_id, receiver_id, amount) VALUES (?, ?, ?)");
        $result = $stmt->execute([1, 2, $amount - $fee]);

        $this->assertTrue($result, "Transaction should be inserted");

        // 5. Log if debug enabled (simulated)
        if ($context->isDebugMode()) {
            // Would log transaction details
            $logged = true;
        } else {
            $logged = false;
        }

        $this->assertFalse($logged, "Should not log when debug is false");
    }

    public function testUserContextInP2PMessageFlow() {
        $context = new UserContext($this->sampleUserData);

        // Simulate P2P message flow
        // 1. Check if message is for this user
        $messageAddress = 'localhost:9000';
        $isForMe = $context->isMyAddress($messageAddress);
        $this->assertTrue($isForMe, "Message should be for this user");

        // 2. Check P2P level
        $messageLevel = 3;
        $maxLevel = $context->getMaxP2pLevel();
        $canForward = $messageLevel < $maxLevel;
        $this->assertTrue($canForward, "Should be able to forward message");

        // 3. Check expiration
        $messageTimestamp = time() - 100;
        $currentTime = time();
        $isExpired = ($currentTime - $messageTimestamp) > $context->getP2pExpiration();
        $this->assertFalse($isExpired, "Message should not be expired");

        // 4. Forward message if all checks pass
        $shouldForward = $isForMe && $canForward && !$isExpired;
        $this->assertTrue($shouldForward, "Message should be forwarded");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new UserContextIntegrationTest();

    SimpleTest::test('UserContext with database connection', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithDatabaseConnection();
        $test->tearDown();
    });

    SimpleTest::test('UserContext with transaction repository', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithTransactionRepository();
        $test->tearDown();
    });

    SimpleTest::test('UserContext with authentication service', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithAuthenticationService();
        $test->tearDown();
    });

    SimpleTest::test('UserContext with transaction service', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithTransactionService();
        $test->tearDown();
    });

    SimpleTest::test('UserContext with P2P service', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithP2PService();
        $test->tearDown();
    });

    SimpleTest::test('UserContext with address validator', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithAddressValidator();
        $test->tearDown();
    });

    SimpleTest::test('UserContext with debug logger', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithDebugLogger();
        $test->tearDown();
    });

    SimpleTest::test('Multiple user contexts', function() use ($test) {
        $test->setUp();
        $test->testMultipleUserContexts();
        $test->tearDown();
    });

    SimpleTest::test('UserContext cloning', function() use ($test) {
        $test->setUp();
        $test->testUserContextCloning();
        $test->tearDown();
    });

    SimpleTest::test('UserContext with invalid DB config', function() use ($test) {
        $test->setUp();
        $test->testUserContextWithInvalidDbConfig();
        $test->tearDown();
    });

    SimpleTest::test('UserContext in transaction flow', function() use ($test) {
        $test->setUp();
        $test->testUserContextInTransactionFlow();
        $test->tearDown();
    });

    SimpleTest::test('UserContext in P2P message flow', function() use ($test) {
        $test->setUp();
        $test->testUserContextInP2PMessageFlow();
        $test->tearDown();
    });

    SimpleTest::run();
}
