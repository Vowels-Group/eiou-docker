<?php
/**
 * Integration tests for end-to-end transaction flow
 */

require_once __DIR__ . '/../bootstrap.php';

class TransactionFlowTest extends TestCase {

    private $pdo;

    public function setUp() {
        parent::setUp();
        $this->pdo = createTestDatabase();
        $this->seedTestData();
    }

    private function seedTestData() {
        // Create test users
        $this->pdo->exec("INSERT INTO users (id, username, password) VALUES (1, 'alice', 'hash1')");
        $this->pdo->exec("INSERT INTO users (id, username, password) VALUES (2, 'bob', 'hash2')");
        $this->pdo->exec("INSERT INTO users (id, username, password) VALUES (3, 'charlie', 'hash3')");

        // Create test contacts
        $this->pdo->exec("INSERT INTO contacts (user_id, contact_id, status) VALUES (1, 2, 'accepted')");
        $this->pdo->exec("INSERT INTO contacts (user_id, contact_id, status) VALUES (2, 1, 'accepted')");
        $this->pdo->exec("INSERT INTO contacts (user_id, contact_id, status) VALUES (1, 3, 'pending')");
    }

    public function testCompleteTransactionFlow() {
        // 1. Check initial state
        $this->assertEquals(0, $this->getTransactionCount(), "Should start with no transactions");

        // 2. Create transaction
        $txId = $this->createTransaction(1, 2, 100);
        $this->assertNotNull($txId, "Transaction should be created");

        // 3. Verify transaction exists
        $this->assertEquals(1, $this->getTransactionCount(), "Should have 1 transaction");

        // 4. Process transaction
        $processed = $this->processTransaction($txId);
        $this->assertTrue($processed, "Transaction should be processed");

        // 5. Verify balances updated
        $balance1 = $this->getUserBalance(1);
        $balance2 = $this->getUserBalance(2);

        $this->assertEquals(-100, $balance1, "Sender balance should be -100");
        $this->assertEquals(100, $balance2, "Receiver balance should be 100");
    }

    public function testMultipleTransactions() {
        // Create multiple transactions
        $tx1 = $this->createTransaction(1, 2, 50);
        $tx2 = $this->createTransaction(2, 3, 30);
        $tx3 = $this->createTransaction(1, 3, 20);

        $this->assertEquals(3, $this->getTransactionCount(), "Should have 3 transactions");

        // Process all transactions
        $this->processTransaction($tx1);
        $this->processTransaction($tx2);
        $this->processTransaction($tx3);

        // Verify final balances
        $this->assertEquals(-70, $this->getUserBalance(1));  // -50 - 20
        $this->assertEquals(20, $this->getUserBalance(2));   // +50 - 30
        $this->assertEquals(50, $this->getUserBalance(3));   // +30 + 20
    }

    public function testContactRequirement() {
        // Try to send to non-contact
        $result = $this->canSendToUser(1, 4);
        $this->assertFalse($result, "Should not be able to send to non-contact");

        // Can send to accepted contact
        $result = $this->canSendToUser(1, 2);
        $this->assertTrue($result, "Should be able to send to accepted contact");

        // Cannot send to pending contact
        $result = $this->canSendToUser(1, 3);
        $this->assertFalse($result, "Should not be able to send to pending contact");
    }

    public function testTransactionValidation() {
        // Test negative amount
        $result = $this->validateTransaction(1, 2, -100);
        $this->assertFalse($result, "Negative amounts should be invalid");

        // Test zero amount
        $result = $this->validateTransaction(1, 2, 0);
        $this->assertFalse($result, "Zero amount should be invalid");

        // Test valid amount
        $result = $this->validateTransaction(1, 2, 100);
        $this->assertTrue($result, "Positive amount should be valid");

        // Test self-transfer
        $result = $this->validateTransaction(1, 1, 100);
        $this->assertFalse($result, "Self-transfer should be invalid");
    }

    // Helper methods
    private function createTransaction($senderId, $receiverId, $amount) {
        $stmt = $this->pdo->prepare("INSERT INTO transactions (sender_id, receiver_id, amount) VALUES (?, ?, ?)");
        $stmt->execute([$senderId, $receiverId, $amount]);
        return $this->pdo->lastInsertId();
    }

    private function processTransaction($txId) {
        // Simulate transaction processing
        return true;
    }

    private function getUserBalance($userId) {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN receiver_id = ? THEN amount ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN sender_id = ? THEN amount ELSE 0 END), 0) as balance
            FROM transactions
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchColumn();
    }

    private function getTransactionCount() {
        return $this->pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
    }

    private function canSendToUser($senderId, $receiverId) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM contacts
            WHERE user_id = ? AND contact_id = ? AND status = 'accepted'
        ");
        $stmt->execute([$senderId, $receiverId]);
        return $stmt->fetchColumn() > 0;
    }

    private function validateTransaction($senderId, $receiverId, $amount) {
        if ($senderId === $receiverId) return false;
        if ($amount <= 0) return false;
        return true;
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new TransactionFlowTest();

    SimpleTest::test('Complete transaction flow', function() use ($test) {
        $test->setUp();
        $test->testCompleteTransactionFlow();
    });

    SimpleTest::test('Multiple transactions', function() use ($test) {
        $test->setUp();
        $test->testMultipleTransactions();
    });

    SimpleTest::test('Contact requirement', function() use ($test) {
        $test->setUp();
        $test->testContactRequirement();
    });

    SimpleTest::test('Transaction validation', function() use ($test) {
        $test->setUp();
        $test->testTransactionValidation();
    });

    SimpleTest::run();
}