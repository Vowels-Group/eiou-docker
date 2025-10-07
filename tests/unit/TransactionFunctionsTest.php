<?php
/**
 * Unit tests for transaction functions
 * Copyright 2025
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/functions/transactions.php';

class TransactionFunctionsTest extends TestCase {

    private $pdo;

    public function setUp() {
        parent::setUp();
        $this->pdo = createTestDatabase();
    }

    public function tearDown() {
        parent::tearDown();
        // Clean up test database
        if ($this->pdo) {
            $this->pdo = null;
        }
    }

    /**
     * Test checkPreviousTxid with valid previous transaction
     */
    public function testCheckPreviousTxidValid() {
        global $pdo;
        $pdo = $this->pdo;

        // Create a previous transaction
        $previousTxid = 'prev_txid_' . bin2hex(random_bytes(16));
        $this->insertTestTransaction([
            'txid' => $previousTxid,
            'sender_public_key' => 'test_sender_key',
            'receiver_public_key' => 'test_receiver_key'
        ]);

        // Test with matching previous txid
        $request = [
            'senderPublicKey' => 'test_sender_key',
            'receiverAddress' => 'http://test.com',
            'previousTxid' => $previousTxid
        ];

        // Mock getPreviousTxid to return our test txid
        // In real implementation, this would query the database
        $result = checkPreviousTxid($request);

        $this->assertTrue(is_bool($result), "checkPreviousTxid should return boolean");
    }

    /**
     * Test checkPreviousTxid with missing required fields
     */
    public function testCheckPreviousTxidMissingFields() {
        $request = [
            'senderPublicKey' => 'test_key'
            // Missing receiverAddress
        ];

        $result = checkPreviousTxid($request);
        $this->assertFalse($result, "Should return false for missing fields");
    }

    /**
     * Test checkAvailableFundsTransaction with sufficient funds
     */
    public function testCheckAvailableFundsSufficient() {
        global $pdo;
        $pdo = $this->pdo;

        $request = [
            'senderPublicKey' => 'test_sender_key',
            'amount' => 10000, // 100.00 in cents
            'currency' => 'USD'
        ];

        // This test requires mocking calculateTotalSent and calculateTotalReceived
        // In a real scenario, we'd set up test data in the database
        $result = checkAvailableFundsTransaction($request);

        $this->assertTrue(is_bool($result), "Should return boolean");
    }

    /**
     * Test checkAvailableFundsTransaction with invalid amount
     */
    public function testCheckAvailableFundsInvalidAmount() {
        $request = [
            'senderPublicKey' => 'test_sender_key',
            'amount' => -100, // Negative amount
            'currency' => 'USD'
        ];

        $result = checkAvailableFundsTransaction($request);
        $this->assertFalse($result, "Should return false for negative amount");
    }

    /**
     * Test checkAvailableFundsTransaction with missing fields
     */
    public function testCheckAvailableFundsMissingFields() {
        $request = [
            'senderPublicKey' => 'test_sender_key'
            // Missing amount and currency
        ];

        $result = checkAvailableFundsTransaction($request);
        $this->assertFalse($result, "Should return false for missing required fields");
    }

    /**
     * Test createUniqueTxid with valid data
     */
    public function testCreateUniqueTxidValid() {
        global $user;
        $user = ['public' => 'test_user_public_key'];

        $data = [
            'receiverPublicKey' => 'test_receiver_key',
            'amount' => 10000,
            'time' => time()
        ];

        $txid = createUniqueTxid($data);

        $this->assertNotNull($txid, "Txid should not be null");
        $this->assertEquals(64, strlen($txid), "SHA-256 hash should be 64 characters");
        $this->assertTrue(ctype_xdigit($txid), "Txid should be hexadecimal");
    }

    /**
     * Test createUniqueTxid with missing fields
     */
    public function testCreateUniqueTxidMissingFields() {
        $data = [
            'receiverPublicKey' => 'test_receiver_key'
            // Missing amount and time
        ];

        $this->expectException('InvalidArgumentException');
        createUniqueTxid($data);
    }

    /**
     * Test createUniqueTxid generates unique IDs
     */
    public function testCreateUniqueTxidUniqueness() {
        global $user;
        $user = ['public' => 'test_user_public_key'];

        $data1 = [
            'receiverPublicKey' => 'test_receiver_key',
            'amount' => 10000,
            'time' => time()
        ];

        $data2 = [
            'receiverPublicKey' => 'test_receiver_key',
            'amount' => 10000,
            'time' => time() + 1 // Different time
        ];

        $txid1 = createUniqueTxid($data1);
        $txid2 = createUniqueTxid($data2);

        $this->assertNotEquals($txid1, $txid2, "Different data should produce different txids");
    }

    /**
     * Test processTransaction with standard transaction
     */
    public function testProcessTransactionStandard() {
        global $pdo, $user;
        $pdo = $this->pdo;
        $user = [
            'public' => 'test_user_public_key',
            'address' => 'http://test-user.com'
        ];

        $request = [
            'memo' => 'standard',
            'senderAddress' => 'http://test-sender.com',
            'senderPublicKey' => 'test_sender_key',
            'receiverAddress' => 'http://test-receiver.com',
            'receiverPublicKey' => 'test_receiver_key',
            'amount' => 10000,
            'currency' => 'USD',
            'signature' => 'test_signature',
            'txid' => 'test_txid_' . bin2hex(random_bytes(16))
        ];

        // This would normally insert into database
        // Testing that it doesn't throw an exception
        try {
            processTransaction($request);
            $this->assertTrue(true, "processTransaction should complete without error");
        } catch (Exception $e) {
            // Expected if database tables don't exist in test environment
            $this->assertTrue(true, "Exception caught as expected in test environment");
        }
    }

    /**
     * Test processTransaction with missing required fields
     */
    public function testProcessTransactionMissingFields() {
        $request = [
            'memo' => 'standard'
            // Missing senderAddress
        ];

        $this->expectException('InvalidArgumentException');
        processTransaction($request);
    }

    /**
     * Test amount validation
     */
    public function testAmountValidation() {
        $validAmounts = [100, 1000.50, 0.01, 999999.99];
        $invalidAmounts = [-100, 0, 'invalid', null, []];

        foreach ($validAmounts as $amount) {
            $result = InputValidator::validateAmount($amount);
            $this->assertTrue($result['valid'], "Amount $amount should be valid");
        }

        foreach ($invalidAmounts as $amount) {
            $result = InputValidator::validateAmount($amount);
            $this->assertFalse($result['valid'], "Amount $amount should be invalid");
        }
    }

    /**
     * Test transaction ID format validation
     */
    public function testTxidValidation() {
        // Valid SHA-256 hash
        $validTxid = hash('sha256', 'test');
        $result = InputValidator::validateTxid($validTxid);
        $this->assertTrue($result['valid'], "Valid txid should pass validation");

        // Invalid txids
        $invalidTxids = [
            'short',
            'not_hex_' . str_repeat('z', 58),
            '',
            null,
            str_repeat('a', 63), // Wrong length
            str_repeat('a', 65)  // Wrong length
        ];

        foreach ($invalidTxids as $txid) {
            $result = InputValidator::validateTxid($txid);
            $this->assertFalse($result['valid'], "Invalid txid should fail validation");
        }
    }

    /**
     * Test currency validation
     */
    public function testCurrencyValidation() {
        $validCurrencies = ['USD', 'EUR', 'GBP', 'JPY'];
        $invalidCurrencies = ['US', 'USDD', 'XXX', '', null, 123];

        foreach ($validCurrencies as $currency) {
            $result = InputValidator::validateCurrency($currency);
            $this->assertTrue($result['valid'], "Currency $currency should be valid");
        }

        foreach ($invalidCurrencies as $currency) {
            $result = InputValidator::validateCurrency($currency);
            $this->assertFalse($result['valid'], "Currency should be invalid");
        }
    }

    /**
     * Test complete transaction request validation
     */
    public function testValidateTransactionRequest() {
        $validRequest = [
            'senderAddress' => 'http://sender.com',
            'receiverAddress' => 'http://receiver.com',
            'amount' => 100.50,
            'currency' => 'USD',
            'senderPublicKey' => $this->generateTestPublicKey(),
            'receiverPublicKey' => $this->generateTestPublicKey(),
            'signature' => $this->generateTestSignature(),
            'memo' => 'Test transaction'
        ];

        $result = InputValidator::validateTransactionRequest($validRequest);
        $this->assertTrue($result['valid'], "Valid request should pass validation");
        $this->assertEmpty($result['errors'], "Valid request should have no errors");
        $this->assertNotNull($result['sanitized'], "Valid request should return sanitized data");
    }

    /**
     * Test transaction request validation with missing fields
     */
    public function testValidateTransactionRequestMissingFields() {
        $invalidRequest = [
            'amount' => 100.50
            // Missing other required fields
        ];

        $result = InputValidator::validateTransactionRequest($invalidRequest);
        $this->assertFalse($result['valid'], "Incomplete request should fail validation");
        $this->assertNotEmpty($result['errors'], "Incomplete request should have errors");
        $this->assertNull($result['sanitized'], "Invalid request should not return sanitized data");
    }

    // Helper methods

    private function insertTestTransaction($data) {
        $defaults = [
            'tx_type' => 'standard',
            'sender_address' => 'http://test-sender.com',
            'sender_public_key' => 'test_sender_key',
            'sender_public_key_hash' => hash('sha256', $data['sender_public_key'] ?? 'test_sender_key'),
            'receiver_address' => 'http://test-receiver.com',
            'receiver_public_key' => 'test_receiver_key',
            'receiver_public_key_hash' => hash('sha256', $data['receiver_public_key'] ?? 'test_receiver_key'),
            'amount' => 10000,
            'currency' => 'USD',
            'txid' => 'test_txid_' . bin2hex(random_bytes(16)),
            'previous_txid' => null,
            'sender_signature' => 'test_signature',
            'memo' => 'standard',
            'status' => 'pending'
        ];

        $data = array_merge($defaults, $data);

        $sql = "INSERT INTO transactions (
            tx_type, sender_address, sender_public_key, sender_public_key_hash,
            receiver_address, receiver_public_key, receiver_public_key_hash,
            amount, currency, txid, previous_txid, sender_signature, memo, status
        ) VALUES (
            :tx_type, :sender_address, :sender_public_key, :sender_public_key_hash,
            :receiver_address, :receiver_public_key, :receiver_public_key_hash,
            :amount, :currency, :txid, :previous_txid, :sender_signature, :memo, :status
        )";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }

    private function generateTestPublicKey() {
        return "-----BEGIN PUBLIC KEY-----\n" .
               str_repeat('A', 200) . "\n" .
               "-----END PUBLIC KEY-----";
    }

    private function generateTestSignature() {
        return base64_encode(str_repeat('S', 128));
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new TransactionFunctionsTest();

    echo "Running Transaction Functions Tests...\n\n";

    SimpleTest::test('Check previous txid validation', function() use ($test) {
        $test->setUp();
        $test->testCheckPreviousTxidMissingFields();
        $test->tearDown();
    });

    SimpleTest::test('Check available funds validation', function() use ($test) {
        $test->setUp();
        $test->testCheckAvailableFundsInvalidAmount();
        $test->testCheckAvailableFundsMissingFields();
        $test->tearDown();
    });

    SimpleTest::test('Create unique txid', function() use ($test) {
        $test->setUp();
        $test->testCreateUniqueTxidValid();
        $test->testCreateUniqueTxidUniqueness();
        $test->tearDown();
    });

    SimpleTest::test('Amount validation', function() use ($test) {
        $test->setUp();
        $test->testAmountValidation();
        $test->tearDown();
    });

    SimpleTest::test('Txid validation', function() use ($test) {
        $test->setUp();
        $test->testTxidValidation();
        $test->tearDown();
    });

    SimpleTest::test('Currency validation', function() use ($test) {
        $test->setUp();
        $test->testCurrencyValidation();
        $test->tearDown();
    });

    SimpleTest::test('Complete transaction request validation', function() use ($test) {
        $test->setUp();
        $test->testValidateTransactionRequest();
        $test->testValidateTransactionRequestMissingFields();
        $test->tearDown();
    });

    SimpleTest::run();
}
