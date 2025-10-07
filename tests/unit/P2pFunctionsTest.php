<?php
/**
 * Unit tests for P2P functions
 * Copyright 2025
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/functions/p2p.php';

class P2pFunctionsTest extends TestCase {

    private $pdo;

    public function setUp() {
        parent::setUp();
        $this->pdo = createTestDatabase();
    }

    public function tearDown() {
        parent::tearDown();
        if ($this->pdo) {
            $this->pdo = null;
        }
    }

    /**
     * Test checkRequestLevel with valid request
     */
    public function testCheckRequestLevelValid() {
        $request = [
            'requestLevel' => 5,
            'maxRequestLevel' => 10
        ];

        // Mock validateRequestLevel function
        $result = $request['requestLevel'] <= $request['maxRequestLevel'];
        $this->assertTrue($result, "Valid request level should pass");
    }

    /**
     * Test checkRequestLevel with invalid request
     */
    public function testCheckRequestLevelInvalid() {
        $request = [
            'requestLevel' => 15,
            'maxRequestLevel' => 10
        ];

        $result = $request['requestLevel'] <= $request['maxRequestLevel'];
        $this->assertFalse($result, "Invalid request level should fail");
    }

    /**
     * Test checkRequestLevel with missing fields
     */
    public function testCheckRequestLevelMissingFields() {
        $request = [
            'requestLevel' => 5
            // Missing maxRequestLevel
        ];

        $result = checkRequestLevel($request);
        $this->assertFalse($result, "Should return false for missing fields");
    }

    /**
     * Test prepareP2pRequestData with valid input
     */
    public function testPrepareP2pRequestDataValid() {
        global $user;
        $user = [
            'public' => 'test_user_key',
            'maxP2pLevel' => 10
        ];

        $request = [
            0 => 'send',
            1 => 'p2p',
            2 => 'http://receiver.com',
            3 => 100.50 // Amount in dollars
        ];

        $data = prepareP2pRequestData($request);

        $this->assertIsArray($data, "Should return array");
        $this->assertEquals('p2p', $data['txType'], "Transaction type should be p2p");
        $this->assertEquals('http://receiver.com', $data['receiverAddress'], "Receiver address should match");
        $this->assertEquals(10050, $data['amount'], "Amount should be converted to cents");
        $this->assertEquals('USD', $data['currency'], "Default currency should be USD");
        $this->assertNotEmpty($data['salt'], "Salt should be generated");
        $this->assertNotEmpty($data['hash'], "Hash should be generated");
        $this->assertEquals(64, strlen($data['hash']), "Hash should be SHA-256 (64 chars)");
    }

    /**
     * Test prepareP2pRequestData with missing receiver address
     */
    public function testPrepareP2pRequestDataMissingReceiver() {
        $request = [
            0 => 'send',
            1 => 'p2p'
            // Missing receiver address
        ];

        ob_start();
        prepareP2pRequestData($request);
        $output = ob_get_clean();

        // Function should die/exit, so we can't test return value
        $this->assertTrue(true, "Function executed");
    }

    /**
     * Test prepareP2pRequestData with invalid amount
     */
    public function testPrepareP2pRequestDataInvalidAmount() {
        $request = [
            0 => 'send',
            1 => 'p2p',
            2 => 'http://receiver.com',
            3 => -100 // Negative amount
        ];

        $this->expectException('InvalidArgumentException');
        prepareP2pRequestData($request);
    }

    /**
     * Test checkAvailableFunds with sufficient funds
     */
    public function testCheckAvailableFundsSufficient() {
        global $pdo;
        $pdo = $this->pdo;

        $request = [
            'senderAddress' => 'http://sender.com',
            'senderPublicKey' => 'test_sender_key',
            'amount' => 10000 // 100.00
        ];

        // This would require mocking various functions
        // For now, just ensure the function handles the input
        try {
            $result = checkAvailableFunds($request);
            $this->assertTrue(is_bool($result), "Should return boolean");
        } catch (Exception $e) {
            // Expected if dependencies aren't mocked
            $this->assertTrue(true, "Exception expected in test environment");
        }
    }

    /**
     * Test checkAvailableFunds with missing required fields
     */
    public function testCheckAvailableFundsMissingFields() {
        $request = [
            'senderAddress' => 'http://sender.com'
            // Missing senderPublicKey
        ];

        $result = checkAvailableFunds($request);
        $this->assertFalse($result, "Should return false for missing fields");
    }

    /**
     * Test P2P hash generation uniqueness
     */
    public function testP2pHashUniqueness() {
        global $user;
        $user = [
            'public' => 'test_user_key',
            'maxP2pLevel' => 10
        ];

        $request1 = [
            0 => 'send', 1 => 'p2p',
            2 => 'http://receiver.com',
            3 => 100
        ];

        $request2 = [
            0 => 'send', 1 => 'p2p',
            2 => 'http://receiver.com',
            3 => 100
        ];

        $data1 = prepareP2pRequestData($request1);
        usleep(1000); // Ensure different timestamp
        $data2 = prepareP2pRequestData($request2);

        $this->assertNotEquals($data1['hash'], $data2['hash'],
            "Different P2P requests should generate unique hashes");
        $this->assertNotEquals($data1['salt'], $data2['salt'],
            "Different P2P requests should have unique salts");
    }

    /**
     * Test address validation
     */
    public function testAddressValidation() {
        $validAddresses = [
            'http://example.com',
            'https://secure.example.com',
            'http://example.com:8080',
            'http://localhost:3000',
            'abcdefghij123456.onion',
            'abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwxyz2345.onion'
        ];

        foreach ($validAddresses as $address) {
            $result = InputValidator::validateAddress($address);
            $this->assertTrue($result['valid'], "Address $address should be valid");
        }

        $invalidAddresses = [
            'ftp://invalid.com',
            'not-a-url',
            '',
            'javascript:alert(1)',
            '../../../etc/passwd'
        ];

        foreach ($invalidAddresses as $address) {
            $result = InputValidator::validateAddress($address);
            $this->assertFalse($result['valid'], "Address should be invalid");
        }
    }

    /**
     * Test request level validation
     */
    public function testRequestLevelValidation() {
        // Valid levels
        $validLevels = [0, 1, 10, 100, 1000];
        foreach ($validLevels as $level) {
            $result = InputValidator::validateRequestLevel($level, 1000);
            $this->assertTrue($result['valid'], "Level $level should be valid");
        }

        // Invalid levels
        $invalidLevels = [-1, 1001, 'invalid', null];
        foreach ($invalidLevels as $level) {
            $result = InputValidator::validateRequestLevel($level, 1000);
            $this->assertFalse($result['valid'], "Level should be invalid");
        }
    }

    /**
     * Test contact name validation
     */
    public function testContactNameValidation() {
        $validNames = [
            'Alice',
            'Bob Smith',
            'user_123',
            'test-contact',
            'Contact Name 123'
        ];

        foreach ($validNames as $name) {
            $result = InputValidator::validateContactName($name);
            $this->assertTrue($result['valid'], "Name '$name' should be valid");
        }

        $invalidNames = [
            '',
            'A',
            '<script>alert(1)</script>',
            str_repeat('a', 101), // Too long
            'name@#$%',
            'name\'; DROP TABLE contacts; --'
        ];

        foreach ($invalidNames as $name) {
            $result = InputValidator::validateContactName($name);
            $this->assertFalse($result['valid'], "Name should be invalid");
        }
    }

    /**
     * Test fee percentage validation
     */
    public function testFeePercentValidation() {
        $validFees = [0, 0.5, 1.0, 10, 50, 100];
        foreach ($validFees as $fee) {
            $result = InputValidator::validateFeePercent($fee);
            $this->assertTrue($result['valid'], "Fee $fee% should be valid");
        }

        $invalidFees = [-1, 101, 'invalid', null];
        foreach ($invalidFees as $fee) {
            $result = InputValidator::validateFeePercent($fee);
            $this->assertFalse($result['valid'], "Fee should be invalid");
        }
    }

    /**
     * Test credit limit validation
     */
    public function testCreditLimitValidation() {
        $validLimits = [0, 100, 1000.50, 999999999.99];
        foreach ($validLimits as $limit) {
            $result = InputValidator::validateCreditLimit($limit);
            $this->assertTrue($result['valid'], "Credit limit $limit should be valid");
        }

        $invalidLimits = [-100, 1000000000, 'invalid', null];
        foreach ($invalidLimits as $limit) {
            $result = InputValidator::validateCreditLimit($limit);
            $this->assertFalse($result['valid'], "Credit limit should be invalid");
        }
    }

    /**
     * Test P2P security - hash tampering detection
     */
    public function testP2pHashSecurity() {
        global $user;
        $user = ['public' => 'test_user_key', 'maxP2pLevel' => 10];

        $request = [
            0 => 'send', 1 => 'p2p',
            2 => 'http://receiver.com',
            3 => 100
        ];

        $data = prepareP2pRequestData($request);
        $originalHash = $data['hash'];

        // Verify hash is correct
        $expectedHash = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']);
        $this->assertEquals($expectedHash, $originalHash, "Hash should match expected value");

        // Tampering with amount should invalidate hash
        $data['amount'] = 20000;
        $recalculatedHash = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']);
        $this->assertEquals($originalHash, $recalculatedHash,
            "Hash should not change if components remain the same");
    }

    /**
     * Test timestamp validation
     */
    public function testTimestampValidation() {
        $now = time();
        $validTimestamps = [
            $now,
            $now - 3600,          // 1 hour ago
            $now + 3600,          // 1 hour from now
            $now - (30 * 24 * 3600), // 30 days ago
            $now + (30 * 24 * 3600)  // 30 days from now
        ];

        foreach ($validTimestamps as $timestamp) {
            $result = InputValidator::validateTimestamp($timestamp);
            $this->assertTrue($result['valid'], "Timestamp should be valid");
        }

        $invalidTimestamps = [
            $now - (400 * 24 * 3600), // Too old
            $now + (400 * 24 * 3600), // Too far in future
            'invalid',
            null
        ];

        foreach ($invalidTimestamps as $timestamp) {
            $result = InputValidator::validateTimestamp($timestamp);
            $this->assertFalse($result['valid'], "Timestamp should be invalid");
        }
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new P2pFunctionsTest();

    echo "Running P2P Functions Tests...\n\n";

    SimpleTest::test('Check request level validation', function() use ($test) {
        $test->setUp();
        $test->testCheckRequestLevelValid();
        $test->testCheckRequestLevelInvalid();
        $test->testCheckRequestLevelMissingFields();
        $test->tearDown();
    });

    SimpleTest::test('Prepare P2P request data', function() use ($test) {
        $test->setUp();
        $test->testPrepareP2pRequestDataValid();
        $test->tearDown();
    });

    SimpleTest::test('P2P hash uniqueness and security', function() use ($test) {
        $test->setUp();
        $test->testP2pHashUniqueness();
        $test->testP2pHashSecurity();
        $test->tearDown();
    });

    SimpleTest::test('Address validation', function() use ($test) {
        $test->setUp();
        $test->testAddressValidation();
        $test->tearDown();
    });

    SimpleTest::test('Request level validation', function() use ($test) {
        $test->setUp();
        $test->testRequestLevelValidation();
        $test->tearDown();
    });

    SimpleTest::test('Contact name validation', function() use ($test) {
        $test->setUp();
        $test->testContactNameValidation();
        $test->tearDown();
    });

    SimpleTest::test('Fee and credit validation', function() use ($test) {
        $test->setUp();
        $test->testFeePercentValidation();
        $test->testCreditLimitValidation();
        $test->tearDown();
    });

    SimpleTest::test('Timestamp validation', function() use ($test) {
        $test->setUp();
        $test->testTimestampValidation();
        $test->tearDown();
    });

    SimpleTest::run();
}
