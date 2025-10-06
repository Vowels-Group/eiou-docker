<?php
/**
 * Unit tests for database operations
 */

require_once dirname(__DIR__, 2). '/tests/walletTests/bootstrap.php';

class DatabaseTest extends TestCase {

    public function testDatabaseConnection() {
        $pdo = createTestDatabase();
        $this->assertNotNull($pdo, "Database connection should be created");
        $this->assertTrue($pdo instanceof PDO, "Should return PDO instance");
    }

    public function testTransactionInsertion() {
        $pdo = createTestDatabase();

        // Insert test data
        $stmt = $pdo->prepare("INSERT INTO transactions (sender_id, receiver_id, amount) VALUES (?, ?, ?)");
        $result = $stmt->execute([1, 2, 100]);

        $this->assertTrue($result, "Transaction should be inserted successfully");

        // Verify insertion
        $count = $pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
        $this->assertEquals(1, $count, "Should have 1 transaction");
    }

    public function testContactManagement() {
        $pdo = createTestDatabase();

        // Insert contact
        $stmt = $pdo->prepare("INSERT INTO contacts (user_id, contact_id, status) VALUES (?, ?, ?)");
        $result = $stmt->execute([1, 2, 'pending']);

        $this->assertTrue($result, "Contact should be inserted");

        // Update contact status
        $stmt = $pdo->prepare("UPDATE contacts SET status = ? WHERE user_id = ? AND contact_id = ?");
        $result = $stmt->execute(['accepted', 1, 2]);

        $this->assertTrue($result, "Contact status should be updated");

        // Verify update
        $stmt = $pdo->prepare("SELECT status FROM contacts WHERE user_id = ? AND contact_id = ?");
        $stmt->execute([1, 2]);
        $status = $stmt->fetchColumn();

        $this->assertEquals('accepted', $status, "Contact status should be 'accepted'");
    }

    public function testTransactionRollback() {
        $pdo = createTestDatabase();

        try {
            $pdo->beginTransaction();

            // Insert transaction
            $stmt = $pdo->prepare("INSERT INTO transactions (sender_id, receiver_id, amount) VALUES (?, ?, ?)");
            $stmt->execute([1, 2, 100]);

            // Rollback
            $pdo->rollback();

            // Check that no transaction exists
            $count = $pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
            $this->assertEquals(0, $count, "No transactions should exist after rollback");

        } catch (Exception $e) {
            $this->assertTrue(false, "Rollback test failed: " . $e->getMessage());
        }
    }

    public function testPreparedStatements() {
        $pdo = createTestDatabase();

        // Test with different parameter types
        $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (:username, :password)");
        $result = $stmt->execute([
            ':username' => 'testuser',
            ':password' => 'hashedpassword'
        ]);

        $this->assertTrue($result, "Named parameters should work");

        // Test with positional parameters
        $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $result = $stmt->execute(['testuser2', 'hashedpassword2']);

        $this->assertTrue($result, "Positional parameters should work");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new DatabaseTest();

    SimpleTest::test('Database connection test', function() use ($test) {
        $test->testDatabaseConnection();
    });

    SimpleTest::test('Transaction insertion test', function() use ($test) {
        $test->testTransactionInsertion();
    });

    SimpleTest::test('Contact management test', function() use ($test) {
        $test->testContactManagement();
    });

    SimpleTest::test('Transaction rollback test', function() use ($test) {
        $test->testTransactionRollback();
    });

    SimpleTest::test('Prepared statements test', function() use ($test) {
        $test->testPreparedStatements();
    });

    SimpleTest::run();
}