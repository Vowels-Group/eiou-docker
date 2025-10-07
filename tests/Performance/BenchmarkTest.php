<?php
/**
 * Performance Benchmark Tests
 * Tests critical operations to identify performance bottlenecks
 * Copyright 2025
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';

class BenchmarkTest extends TestCase {

    private $pdo;
    private $results = [];

    public function setUp() {
        parent::setUp();
        $this->pdo = createTestDatabase();
        $this->results = [];
    }

    public function tearDown() {
        parent::tearDown();
        if ($this->pdo) {
            $this->pdo = null;
        }
    }

    /**
     * Benchmark database connection establishment
     */
    public function testDatabaseConnectionPerformance() {
        $iterations = 100;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $pdo = createTestDatabase();
            $pdo = null;
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $avgTime = ($duration / $iterations) * 1000; // Convert to ms

        $this->recordResult('Database Connection', $avgTime, 'ms', $iterations);

        // Connection should be fast (< 10ms average)
        $this->assertLessThan(10, $avgTime,
            "Database connection should average less than 10ms");
    }

    /**
     * Benchmark hash generation performance
     */
    public function testHashGenerationPerformance() {
        $iterations = 10000;
        $testData = str_repeat('test data for hashing', 100);

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $hash = hash('sha256', $testData . $i);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to ms
        $avgTime = $duration / $iterations;

        $this->recordResult('SHA-256 Hash Generation', $avgTime, 'ms', $iterations);

        // Hash generation should be very fast (< 0.01ms per hash)
        $this->assertLessThan(0.01, $avgTime,
            "Hash generation should be under 0.01ms");
    }

    /**
     * Benchmark secure random generation
     */
    public function testSecureRandomPerformance() {
        $iterations = 1000;

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $random = Security::generateSecureToken(32);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $avgTime = $duration / $iterations;

        $this->recordResult('Secure Random Token Generation', $avgTime, 'ms', $iterations);

        // Random generation should be reasonably fast (< 1ms)
        $this->assertLessThan(1, $avgTime,
            "Secure random generation should be under 1ms");
    }

    /**
     * Benchmark password hashing performance
     */
    public function testPasswordHashingPerformance() {
        $iterations = 100;
        $password = 'test_password_123!';

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $hash = Security::hashPassword($password);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $avgTime = $duration / $iterations;

        $this->recordResult('Password Hashing (bcrypt)', $avgTime, 'ms', $iterations);

        // Password hashing should be slow (50-250ms) for security
        $this->assertGreaterThan(50, $avgTime,
            "Password hashing should be deliberately slow (> 50ms) for security");
        $this->assertLessThan(300, $avgTime,
            "Password hashing should complete in reasonable time (< 300ms)");
    }

    /**
     * Benchmark input validation performance
     */
    public function testInputValidationPerformance() {
        $iterations = 10000;

        $testData = [
            'senderAddress' => 'http://sender.com',
            'receiverAddress' => 'http://receiver.com',
            'amount' => 100.50,
            'currency' => 'USD',
            'senderPublicKey' => $this->generateTestPublicKey(),
            'receiverPublicKey' => $this->generateTestPublicKey(),
            'signature' => $this->generateTestSignature(),
            'memo' => 'Test transaction'
        ];

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $result = InputValidator::validateTransactionRequest($testData);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $avgTime = $duration / $iterations;

        $this->recordResult('Transaction Request Validation', $avgTime, 'ms', $iterations);

        // Validation should be fast (< 0.5ms)
        $this->assertLessThan(0.5, $avgTime,
            "Input validation should be under 0.5ms");
    }

    /**
     * Benchmark database insert performance (with indexes)
     */
    public function testDatabaseInsertPerformance() {
        $iterations = 1000;

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $this->insertTestTransaction($i);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $avgTime = $duration / $iterations;

        $this->recordResult('Database Insert (Transaction)', $avgTime, 'ms', $iterations);

        // Insert should be reasonably fast (< 5ms with proper indexes)
        $this->assertLessThan(5, $avgTime,
            "Database insert should be under 5ms with proper indexes");
    }

    /**
     * Benchmark database select performance (with indexes)
     */
    public function testDatabaseSelectPerformance() {
        // Insert test data first
        for ($i = 0; $i < 1000; $i++) {
            $this->insertTestTransaction($i);
        }

        $iterations = 1000;

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $stmt = $this->pdo->prepare("SELECT * FROM transactions WHERE txid = :txid");
            $stmt->execute(['txid' => 'test_txid_' . ($i % 1000)]);
            $result = $stmt->fetch();
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $avgTime = $duration / $iterations;

        $this->recordResult('Database Select by Index', $avgTime, 'ms', $iterations);

        // Select by index should be very fast (< 1ms)
        $this->assertLessThan(1, $avgTime,
            "Database select with index should be under 1ms");
    }

    /**
     * Benchmark JSON encoding/decoding performance
     */
    public function testJsonPerformance() {
        $iterations = 10000;

        $testData = [
            'txType' => 'standard',
            'senderAddress' => 'http://sender.com',
            'receiverAddress' => 'http://receiver.com',
            'amount' => 100.50,
            'currency' => 'USD',
            'timestamp' => time()
        ];

        // Test encoding
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $json = json_encode($testData);
        }
        $encodeTime = (microtime(true) - $startTime) * 1000 / $iterations;

        // Test decoding
        $json = json_encode($testData);
        $startTime = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $decoded = json_decode($json, true);
        }
        $decodeTime = (microtime(true) - $startTime) * 1000 / $iterations;

        $this->recordResult('JSON Encoding', $encodeTime, 'ms', $iterations);
        $this->recordResult('JSON Decoding', $decodeTime, 'ms', $iterations);

        // JSON operations should be fast (< 0.05ms)
        $this->assertLessThan(0.05, $encodeTime, "JSON encoding should be under 0.05ms");
        $this->assertLessThan(0.05, $decodeTime, "JSON decoding should be under 0.05ms");
    }

    /**
     * Benchmark XSS sanitization performance
     */
    public function testXssSanitizationPerformance() {
        $iterations = 10000;

        $testData = '<script>alert("XSS")</script><p>Normal text</p>';

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $sanitized = Security::htmlEncode($testData);
        }

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;
        $avgTime = $duration / $iterations;

        $this->recordResult('XSS Sanitization (htmlspecialchars)', $avgTime, 'ms', $iterations);

        // Sanitization should be very fast (< 0.01ms)
        $this->assertLessThan(0.01, $avgTime,
            "XSS sanitization should be under 0.01ms");
    }

    /**
     * Benchmark N+1 query problem scenario
     */
    public function testN1QueryProblem() {
        // Insert test contacts
        for ($i = 0; $i < 100; $i++) {
            $this->insertTestContact($i);
        }

        // BAD: N+1 queries (fetch contacts, then fetch details for each)
        $startTime = microtime(true);

        $stmt = $this->pdo->query("SELECT address FROM contacts LIMIT 100");
        $contacts = $stmt->fetchAll();

        foreach ($contacts as $contact) {
            $stmt = $this->pdo->prepare("SELECT * FROM contacts WHERE address = ?");
            $stmt->execute([$contact['address']]);
            $details = $stmt->fetch();
        }

        $n1Time = (microtime(true) - $startTime) * 1000;

        // GOOD: Single query with JOIN or WHERE IN
        $startTime = microtime(true);

        $stmt = $this->pdo->query("SELECT * FROM contacts LIMIT 100");
        $allContacts = $stmt->fetchAll();

        $optimizedTime = (microtime(true) - $startTime) * 1000;

        $this->recordResult('N+1 Query Problem (100 contacts)', $n1Time, 'ms', 1);
        $this->recordResult('Optimized Single Query (100 contacts)', $optimizedTime, 'ms', 1);

        // Optimized query should be significantly faster (at least 50% faster)
        $this->assertLessThan($n1Time * 0.5, $optimizedTime,
            "Optimized query should be at least 50% faster than N+1 queries");
    }

    /**
     * Generate performance report
     */
    public function generateReport() {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "PERFORMANCE BENCHMARK REPORT\n";
        echo str_repeat("=", 80) . "\n\n";

        echo sprintf("%-50s %15s %10s\n", "Operation", "Avg Time", "Iterations");
        echo str_repeat("-", 80) . "\n";

        foreach ($this->results as $result) {
            echo sprintf("%-50s %10.4f %-4s %10d\n",
                $result['operation'],
                $result['time'],
                $result['unit'],
                $result['iterations']
            );
        }

        echo "\n" . str_repeat("=", 80) . "\n";
        echo "Recommendations:\n";
        echo "- Operations under 1ms are excellent\n";
        echo "- Operations under 10ms are good\n";
        echo "- Operations over 100ms may need optimization\n";
        echo "- Password hashing should be slow (50-250ms) for security\n";
        echo str_repeat("=", 80) . "\n\n";
    }

    // Helper methods

    private function recordResult($operation, $time, $unit, $iterations) {
        $this->results[] = [
            'operation' => $operation,
            'time' => $time,
            'unit' => $unit,
            'iterations' => $iterations
        ];
    }

    private function insertTestTransaction($index) {
        $sql = "INSERT INTO transactions (
            tx_type, sender_address, sender_public_key, sender_public_key_hash,
            receiver_address, receiver_public_key, receiver_public_key_hash,
            amount, currency, txid, memo, status
        ) VALUES (
            'standard', :sender_addr, 'sender_key', :sender_hash,
            'receiver_addr', 'receiver_key', :receiver_hash,
            10000, 'USD', :txid, 'standard', 'pending'
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'sender_addr' => 'http://sender-' . $index . '.com',
            'sender_hash' => hash('sha256', 'sender_key_' . $index),
            'receiver_hash' => hash('sha256', 'receiver_key_' . $index),
            'txid' => 'test_txid_' . $index
        ]);
    }

    private function insertTestContact($index) {
        $sql = "INSERT INTO contacts (
            address, pubkey, pubkey_hash, name, status, fee_percent, credit_limit, currency
        ) VALUES (
            :address, 'test_pubkey', :pubkey_hash, :name, 'accepted', 100, 100000, 'USD'
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'address' => 'http://contact-' . $index . '.com',
            'pubkey_hash' => hash('sha256', 'pubkey_' . $index),
            'name' => 'Contact ' . $index
        ]);
    }

    private function generateTestPublicKey() {
        return "-----BEGIN PUBLIC KEY-----\n" . str_repeat('A', 200) . "\n-----END PUBLIC KEY-----";
    }

    private function generateTestSignature() {
        return base64_encode(str_repeat('S', 128));
    }
}

// Run benchmarks if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new BenchmarkTest();

    echo "\n Running Performance Benchmarks...\n";
    echo "This may take a few minutes...\n\n";

    $test->setUp();

    $test->testDatabaseConnectionPerformance();
    $test->testHashGenerationPerformance();
    $test->testSecureRandomPerformance();
    $test->testPasswordHashingPerformance();
    $test->testInputValidationPerformance();
    $test->testDatabaseInsertPerformance();
    $test->testDatabaseSelectPerformance();
    $test->testJsonPerformance();
    $test->testXssSanitizationPerformance();
    $test->testN1QueryProblem();

    $test->generateReport();
    $test->tearDown();
}
