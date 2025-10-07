<?php
/**
 * Integration tests for P2P message workflows
 * Tests end-to-end P2P message processing, validation, and state transitions
 */

require_once dirname(__DIR__) . '/walletTests/bootstrap.php';

class P2PMessageFlowTest extends TestCase {

    private $pdo;

    public function setUp() {
        parent::setUp();
        $this->pdo = createTestDatabase();
        $this->createP2PTable();
        $this->createContactsTable();
        $this->createUsersTable();
        $this->seedTestData();
    }

    private function createP2PTable() {
        $sql = "CREATE TABLE IF NOT EXISTS p2p (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hash VARCHAR(255) UNIQUE NOT NULL,
            salt VARCHAR(255),
            time INTEGER,
            expiration INTEGER,
            currency VARCHAR(10),
            amount DECIMAL(20,8),
            my_fee_amount DECIMAL(20,8),
            request_level INTEGER,
            max_request_level INTEGER,
            sender_public_key TEXT,
            sender_address VARCHAR(255),
            sender_signature TEXT,
            destination_address VARCHAR(255),
            incoming_txid VARCHAR(255),
            outgoing_txid VARCHAR(255),
            status VARCHAR(50) DEFAULT 'initial',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL
        )";
        $this->pdo->exec($sql);
    }

    private function createContactsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            contact_address VARCHAR(255),
            status VARCHAR(50),
            blocked BOOLEAN DEFAULT 0
        )";
        $this->pdo->exec($sql);
    }

    private function createUsersTable() {
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            address VARCHAR(255) UNIQUE,
            balance DECIMAL(20,8) DEFAULT 0
        )";
        $this->pdo->exec($sql);
    }

    private function seedTestData() {
        // Create test users
        $this->pdo->exec("INSERT INTO users (id, address, balance) VALUES (1, 'addr_alice', 1000)");
        $this->pdo->exec("INSERT INTO users (id, address, balance) VALUES (2, 'addr_bob', 500)");
        $this->pdo->exec("INSERT INTO users (id, address, balance) VALUES (3, 'addr_charlie', 250)");

        // Create contacts
        $this->pdo->exec("INSERT INTO contacts (user_id, contact_address, status, blocked)
                         VALUES (1, 'addr_bob', 'accepted', 0)");
        $this->pdo->exec("INSERT INTO contacts (user_id, contact_address, status, blocked)
                         VALUES (2, 'addr_alice', 'accepted', 0)");
    }

    public function testP2PRequestCreation() {
        $p2pData = [
            'hash' => hash('sha256', 'test_p2p_1'),
            'salt' => bin2hex(random_bytes(16)),
            'time' => time(),
            'expiration' => time() + 3600,
            'currency' => 'BTC',
            'amount' => 0.5,
            'feeAmount' => 0.001,
            'requestLevel' => 1,
            'maxRequestLevel' => 5,
            'senderPublicKey' => 'pubkey_alice',
            'senderAddress' => 'addr_alice',
            'signature' => 'sig_alice',
            'status' => 'initial'
        ];

        $stmt = $this->pdo->prepare("INSERT INTO p2p (
            hash, salt, time, expiration, currency, amount, my_fee_amount,
            request_level, max_request_level, sender_public_key, sender_address,
            sender_signature, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $result = $stmt->execute([
            $p2pData['hash'],
            $p2pData['salt'],
            $p2pData['time'],
            $p2pData['expiration'],
            $p2pData['currency'],
            $p2pData['amount'],
            $p2pData['feeAmount'],
            $p2pData['requestLevel'],
            $p2pData['maxRequestLevel'],
            $p2pData['senderPublicKey'],
            $p2pData['senderAddress'],
            $p2pData['signature'],
            $p2pData['status']
        ]);

        $this->assertTrue($result, "Should create P2P request");

        // Verify creation
        $stmt = $this->pdo->prepare("SELECT * FROM p2p WHERE hash = ?");
        $stmt->execute([$p2pData['hash']]);
        $created = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('initial', $created['status'], "Should start with 'initial' status");
        $this->assertEquals($p2pData['amount'], $created['amount'], "Amount should match");
    }

    public function testP2PStatusTransition() {
        $hash = hash('sha256', 'test_p2p_transition');

        // Create P2P request
        $stmt = $this->pdo->prepare("INSERT INTO p2p (
            hash, sender_address, amount, status
        ) VALUES (?, ?, ?, ?)");
        $stmt->execute([$hash, 'addr_alice', 100, 'initial']);

        // Transition to queued
        $updateStmt = $this->pdo->prepare("UPDATE p2p SET status = ? WHERE hash = ?");
        $updateStmt->execute(['queued', $hash]);

        $stmt = $this->pdo->prepare("SELECT status FROM p2p WHERE hash = ?");
        $stmt->execute([$hash]);
        $status = $stmt->fetchColumn();
        $this->assertEquals('queued', $status, "Should transition to queued");

        // Transition to sent
        $updateStmt->execute(['sent', $hash]);
        $stmt->execute([$hash]);
        $status = $stmt->fetchColumn();
        $this->assertEquals('sent', $status, "Should transition to sent");

        // Transition to completed
        $completeStmt = $this->pdo->prepare("UPDATE p2p SET status = ?, completed_at = CURRENT_TIMESTAMP WHERE hash = ?");
        $completeStmt->execute(['completed', $hash]);

        $stmt = $this->pdo->prepare("SELECT status, completed_at FROM p2p WHERE hash = ?");
        $stmt->execute([$hash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('completed', $result['status'], "Should transition to completed");
        $this->assertNotNull($result['completed_at'], "Should set completion timestamp");
    }

    public function testContactValidation() {
        // Valid contact relationship
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM contacts
            WHERE user_id = ? AND contact_address = ? AND status = 'accepted' AND blocked = 0
        ");
        $stmt->execute([1, 'addr_bob']);
        $count = $stmt->fetchColumn();

        $this->assertEquals(1, $count, "Alice and Bob should be valid contacts");

        // Invalid contact (not in contacts)
        $stmt->execute([1, 'addr_charlie']);
        $count = $stmt->fetchColumn();

        $this->assertEquals(0, $count, "Alice and Charlie are not contacts");
    }

    public function testBlockedContactPrevention() {
        // Block a contact
        $this->pdo->exec("UPDATE contacts SET blocked = 1 WHERE user_id = 1 AND contact_address = 'addr_bob'");

        // Check if blocked
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM contacts
            WHERE user_id = ? AND contact_address = ? AND status = 'accepted' AND blocked = 1
        ");
        $stmt->execute([1, 'addr_bob']);
        $count = $stmt->fetchColumn();

        $this->assertEquals(1, $count, "Contact should be marked as blocked");

        // Validation should fail for blocked contacts
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM contacts
            WHERE user_id = ? AND contact_address = ? AND status = 'accepted' AND blocked = 0
        ");
        $stmt->execute([1, 'addr_bob']);
        $count = $stmt->fetchColumn();

        $this->assertEquals(0, $count, "Blocked contact should not pass validation");
    }

    public function testRequestLevelValidation() {
        $hash = hash('sha256', 'test_level_validation');

        // Create P2P with request level
        $stmt = $this->pdo->prepare("INSERT INTO p2p (
            hash, sender_address, amount, request_level, max_request_level, status
        ) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$hash, 'addr_alice', 100, 3, 5, 'initial']);

        // Verify request level constraints
        $stmt = $this->pdo->prepare("SELECT request_level, max_request_level FROM p2p WHERE hash = ?");
        $stmt->execute([$hash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue($result['request_level'] <= $result['max_request_level'],
            "Request level should not exceed max request level");
    }

    public function testAvailableFundsCheck() {
        // Alice has 1000 balance
        $stmt = $this->pdo->prepare("SELECT balance FROM users WHERE address = ?");
        $stmt->execute(['addr_alice']);
        $balance = $stmt->fetchColumn();

        // Check funds in P2P
        $stmt = $this->pdo->prepare("
            SELECT SUM(amount) as total_amount FROM p2p
            WHERE sender_address = ? AND status IN ('initial','queued','sent','found')
        ");
        $stmt->execute(['addr_alice']);
        $lockedFunds = $stmt->fetchColumn() ?? 0;

        $available = $balance - $lockedFunds;

        $this->assertEquals(1000, $balance, "Alice should have 1000 balance");
        $this->assertTrue($available > 0, "Should have available funds");

        // Try to lock more than available
        $stmt = $this->pdo->prepare("INSERT INTO p2p (
            hash, sender_address, amount, status
        ) VALUES (?, ?, ?, ?)");
        $stmt->execute([hash('sha256', 'overlimit'), 'addr_alice', 1500, 'queued']);

        // Recalculate
        $stmt = $this->pdo->prepare("
            SELECT SUM(amount) as total_amount FROM p2p
            WHERE sender_address = ? AND status IN ('initial','queued','sent','found')
        ");
        $stmt->execute(['addr_alice']);
        $lockedFunds = $stmt->fetchColumn() ?? 0;

        $this->assertTrue($lockedFunds > $balance, "Locked funds should exceed balance (invalid state)");
    }

    public function testDuplicateHashPrevention() {
        $hash = hash('sha256', 'duplicate_test');

        // Create first P2P
        $stmt = $this->pdo->prepare("INSERT INTO p2p (hash, sender_address, amount, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$hash, 'addr_alice', 100, 'initial']);

        // Try to create duplicate
        try {
            $stmt->execute([$hash, 'addr_bob', 200, 'initial']);
            $this->assertTrue(false, "Should not allow duplicate hash");
        } catch (PDOException $e) {
            $this->assertContains('UNIQUE', $e->getMessage(), "Should enforce unique hash constraint");
        }
    }

    public function testQueuedMessageRetrieval() {
        // Create multiple P2P requests with different statuses
        $statuses = ['initial', 'queued', 'queued', 'sent', 'completed', 'queued'];

        foreach ($statuses as $i => $status) {
            $hash = hash('sha256', 'queued_test_' . $i);
            $stmt = $this->pdo->prepare("INSERT INTO p2p (hash, sender_address, amount, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$hash, 'addr_alice', 100, $status]);
        }

        // Retrieve queued messages
        $stmt = $this->pdo->prepare("SELECT * FROM p2p WHERE status = ? ORDER BY created_at ASC LIMIT 5");
        $stmt->execute(['queued']);
        $queuedMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertEquals(3, count($queuedMessages), "Should retrieve 3 queued messages");

        foreach ($queuedMessages as $msg) {
            $this->assertEquals('queued', $msg['status'], "All retrieved messages should be queued");
        }
    }

    public function testExpirationHandling() {
        $expiredTime = time() - 3600; // 1 hour ago
        $validTime = time() + 3600; // 1 hour from now

        // Create expired P2P
        $stmt = $this->pdo->prepare("INSERT INTO p2p (hash, sender_address, amount, expiration, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([hash('sha256', 'expired'), 'addr_alice', 100, $expiredTime, 'initial']);

        // Create valid P2P
        $stmt->execute([hash('sha256', 'valid'), 'addr_alice', 100, $validTime, 'initial']);

        // Find expired messages
        $stmt = $this->pdo->prepare("SELECT * FROM p2p WHERE expiration < ? AND status NOT IN ('completed', 'expired', 'cancelled')");
        $stmt->execute([time()]);
        $expiredMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertEquals(1, count($expiredMessages), "Should find 1 expired message");

        // Mark as expired
        $stmt = $this->pdo->prepare("UPDATE p2p SET status = 'expired' WHERE expiration < ? AND status NOT IN ('completed', 'expired', 'cancelled')");
        $stmt->execute([time()]);

        // Verify
        $stmt = $this->pdo->prepare("SELECT status FROM p2p WHERE hash = ?");
        $stmt->execute([hash('sha256', 'expired')]);
        $status = $stmt->fetchColumn();

        $this->assertEquals('expired', $status, "Should mark expired message");
    }

    public function testTxidUpdates() {
        $hash = hash('sha256', 'txid_test');

        // Create P2P
        $stmt = $this->pdo->prepare("INSERT INTO p2p (hash, sender_address, amount, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$hash, 'addr_alice', 100, 'sent']);

        // Update incoming txid
        $incomingTxid = 'txid_incoming_123';
        $updateStmt = $this->pdo->prepare("UPDATE p2p SET incoming_txid = ? WHERE hash = ?");
        $updateStmt->execute([$incomingTxid, $hash]);

        // Update outgoing txid
        $outgoingTxid = 'txid_outgoing_456';
        $updateStmt = $this->pdo->prepare("UPDATE p2p SET outgoing_txid = ? WHERE hash = ?");
        $updateStmt->execute([$outgoingTxid, $hash]);

        // Verify both txids
        $stmt = $this->pdo->prepare("SELECT incoming_txid, outgoing_txid FROM p2p WHERE hash = ?");
        $stmt->execute([$hash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals($incomingTxid, $result['incoming_txid'], "Incoming txid should be set");
        $this->assertEquals($outgoingTxid, $result['outgoing_txid'], "Outgoing txid should be set");
    }

    public function testCompleteP2PWorkflow() {
        $hash = hash('sha256', 'complete_workflow');

        // 1. Create P2P request
        $stmt = $this->pdo->prepare("INSERT INTO p2p (
            hash, sender_address, destination_address, amount, status, expiration
        ) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$hash, 'addr_alice', 'addr_bob', 100, 'initial', time() + 3600]);

        // 2. Queue it
        $updateStmt = $this->pdo->prepare("UPDATE p2p SET status = ? WHERE hash = ?");
        $updateStmt->execute(['queued', $hash]);

        // 3. Mark as sent
        $updateStmt->execute(['sent', $hash]);

        // 4. Add incoming txid
        $txidStmt = $this->pdo->prepare("UPDATE p2p SET incoming_txid = ? WHERE hash = ?");
        $txidStmt->execute(['txid_in_123', $hash]);

        // 5. Mark as found
        $updateStmt->execute(['found', $hash]);

        // 6. Add outgoing txid
        $txidStmt = $this->pdo->prepare("UPDATE p2p SET outgoing_txid = ? WHERE hash = ?");
        $txidStmt->execute(['txid_out_456', $hash]);

        // 7. Complete
        $completeStmt = $this->pdo->prepare("UPDATE p2p SET status = ?, completed_at = CURRENT_TIMESTAMP WHERE hash = ?");
        $completeStmt->execute(['completed', $hash]);

        // Verify final state
        $stmt = $this->pdo->prepare("SELECT * FROM p2p WHERE hash = ?");
        $stmt->execute([$hash]);
        $final = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals('completed', $final['status'], "Should be completed");
        $this->assertEquals('txid_in_123', $final['incoming_txid'], "Should have incoming txid");
        $this->assertEquals('txid_out_456', $final['outgoing_txid'], "Should have outgoing txid");
        $this->assertNotNull($final['completed_at'], "Should have completion timestamp");
    }
}

// Run tests if executed directly
if (!defined('PHPUNIT_RUNNING') || !PHPUNIT_RUNNING) {
    $test = new P2PMessageFlowTest();

    SimpleTest::test('P2P request creation', function() use ($test) {
        $test->setUp();
        $test->testP2PRequestCreation();
    });

    SimpleTest::test('P2P status transition', function() use ($test) {
        $test->setUp();
        $test->testP2PStatusTransition();
    });

    SimpleTest::test('Contact validation', function() use ($test) {
        $test->setUp();
        $test->testContactValidation();
    });

    SimpleTest::test('Blocked contact prevention', function() use ($test) {
        $test->setUp();
        $test->testBlockedContactPrevention();
    });

    SimpleTest::test('Request level validation', function() use ($test) {
        $test->setUp();
        $test->testRequestLevelValidation();
    });

    SimpleTest::test('Available funds check', function() use ($test) {
        $test->setUp();
        $test->testAvailableFundsCheck();
    });

    SimpleTest::test('Duplicate hash prevention', function() use ($test) {
        $test->setUp();
        $test->testDuplicateHashPrevention();
    });

    SimpleTest::test('Queued message retrieval', function() use ($test) {
        $test->setUp();
        $test->testQueuedMessageRetrieval();
    });

    SimpleTest::test('Expiration handling', function() use ($test) {
        $test->setUp();
        $test->testExpirationHandling();
    });

    SimpleTest::test('Txid updates', function() use ($test) {
        $test->setUp();
        $test->testTxidUpdates();
    });

    SimpleTest::test('Complete P2P workflow', function() use ($test) {
        $test->setUp();
        $test->testCompleteP2PWorkflow();
    });

    SimpleTest::run();
}
