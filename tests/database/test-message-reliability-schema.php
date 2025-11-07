#!/usr/bin/env php
<?php
/**
 * Message Reliability Schema Test Suite
 *
 * Tests all database tables, indexes, foreign keys, and stored procedures
 * for Issue #139 Message Reliability Enhancement
 *
 * Usage: docker exec alice php /app/tests/database/test-message-reliability-schema.php
 */

// Database connection
$host = 'localhost';
$dbname = 'eiou';
$username = 'root';
$password = 'root';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Database connection successful\n\n";
} catch (PDOException $e) {
    die("✗ Database connection failed: " . $e->getMessage() . "\n");
}

// Test counters
$passed = 0;
$failed = 0;
$tests = [];

/**
 * Run a test and track results
 */
function test($description, $callback) {
    global $passed, $failed, $tests;

    try {
        $result = $callback();
        if ($result) {
            echo "✓ $description\n";
            $passed++;
            $tests[] = ['status' => 'PASS', 'test' => $description];
            return true;
        } else {
            echo "✗ $description\n";
            $failed++;
            $tests[] = ['status' => 'FAIL', 'test' => $description];
            return false;
        }
    } catch (Exception $e) {
        echo "✗ $description - Exception: " . $e->getMessage() . "\n";
        $failed++;
        $tests[] = ['status' => 'ERROR', 'test' => $description, 'error' => $e->getMessage()];
        return false;
    }
}

// ============================================================================
// TABLE EXISTENCE TESTS
// ============================================================================

echo "=== Table Existence Tests ===\n";

test("message_acknowledgments table exists", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'message_acknowledgments'");
    return $stmt->rowCount() === 1;
});

test("message_retries table exists", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'message_retries'");
    return $stmt->rowCount() === 1;
});

test("message_deduplication table exists", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'message_deduplication'");
    return $stmt->rowCount() === 1;
});

test("dead_letter_queue table exists", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'dead_letter_queue'");
    return $stmt->rowCount() === 1;
});

test("message_reliability_config table exists", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'message_reliability_config'");
    return $stmt->rowCount() === 1;
});

echo "\n";

// ============================================================================
// COLUMN TESTS
// ============================================================================

echo "=== Column Structure Tests ===\n";

test("message_acknowledgments has required columns", function() use ($pdo) {
    $stmt = $pdo->query("DESCRIBE message_acknowledgments");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required = ['id', 'txid', 'message_hash', 'ack_stage', 'status', 'sender_address',
                 'receiver_address', 'sent_at', 'created_at', 'updated_at'];
    foreach ($required as $col) {
        if (!in_array($col, $columns)) return false;
    }
    return true;
});

test("message_retries has required columns", function() use ($pdo) {
    $stmt = $pdo->query("DESCRIBE message_retries");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required = ['id', 'txid', 'message_hash', 'retry_count', 'max_retries',
                 'backoff_strategy', 'retry_status', 'original_message'];
    foreach ($required as $col) {
        if (!in_array($col, $columns)) return false;
    }
    return true;
});

test("message_deduplication has required columns", function() use ($pdo) {
    $stmt = $pdo->query("DESCRIBE message_deduplication");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required = ['id', 'message_hash', 'idempotency_key', 'txid', 'processing_status',
                 'expires_at'];
    foreach ($required as $col) {
        if (!in_array($col, $columns)) return false;
    }
    return true;
});

test("dead_letter_queue has required columns", function() use ($pdo) {
    $stmt = $pdo->query("DESCRIBE dead_letter_queue");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $required = ['id', 'txid', 'message_hash', 'original_message', 'failure_reason',
                 'dlq_status', 'sender_address', 'receiver_address'];
    foreach ($required as $col) {
        if (!in_array($col, $columns)) return false;
    }
    return true;
});

echo "\n";

// ============================================================================
// INDEX TESTS
// ============================================================================

echo "=== Index Tests ===\n";

test("message_acknowledgments has proper indexes", function() use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM message_acknowledgments");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_unique(array_column($indexes, 'Key_name'));

    $required = ['PRIMARY', 'message_hash', 'idx_ack_txid', 'idx_ack_status', 'idx_ack_stage'];
    foreach ($required as $idx) {
        if (!in_array($idx, $indexNames)) return false;
    }
    return true;
});

test("message_retries has proper indexes", function() use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM message_retries");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_unique(array_column($indexes, 'Key_name'));

    $required = ['PRIMARY', 'idx_retry_txid', 'idx_retry_status', 'idx_retry_next_at'];
    foreach ($required as $idx) {
        if (!in_array($idx, $indexNames)) return false;
    }
    return true;
});

test("message_deduplication has unique constraints", function() use ($pdo) {
    $stmt = $pdo->query("SHOW INDEX FROM message_deduplication");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check for UNIQUE indexes
    $uniqueIndexes = array_filter($indexes, function($idx) {
        return $idx['Non_unique'] == 0;
    });

    return count($uniqueIndexes) >= 2; // message_hash and idempotency_key
});

echo "\n";

// ============================================================================
// FOREIGN KEY TESTS
// ============================================================================

echo "=== Foreign Key Constraint Tests ===\n";

test("message_acknowledgments foreign key to transactions", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = 'eiou'
        AND TABLE_NAME = 'message_acknowledgments'
        AND REFERENCED_TABLE_NAME = 'transactions'
    ");
    return $stmt->rowCount() > 0;
});

test("message_retries foreign key to transactions", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = 'eiou'
        AND TABLE_NAME = 'message_retries'
        AND REFERENCED_TABLE_NAME = 'transactions'
    ");
    return $stmt->rowCount() > 0;
});

test("message_deduplication foreign key to transactions", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = 'eiou'
        AND TABLE_NAME = 'message_deduplication'
        AND REFERENCED_TABLE_NAME = 'transactions'
    ");
    return $stmt->rowCount() > 0;
});

test("dead_letter_queue foreign key to transactions", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = 'eiou'
        AND TABLE_NAME = 'dead_letter_queue'
        AND REFERENCED_TABLE_NAME = 'transactions'
    ");
    return $stmt->rowCount() > 0;
});

echo "\n";

// ============================================================================
// STORED PROCEDURE TESTS
// ============================================================================

echo "=== Stored Procedure/Function Tests ===\n";

test("cleanup_expired_deduplication procedure exists", function() use ($pdo) {
    $stmt = $pdo->query("
        SHOW PROCEDURE STATUS
        WHERE Db = 'eiou' AND Name = 'cleanup_expired_deduplication'
    ");
    return $stmt->rowCount() === 1;
});

test("move_to_dead_letter_queue procedure exists", function() use ($pdo) {
    $stmt = $pdo->query("
        SHOW PROCEDURE STATUS
        WHERE Db = 'eiou' AND Name = 'move_to_dead_letter_queue'
    ");
    return $stmt->rowCount() === 1;
});

test("calculate_next_retry function exists", function() use ($pdo) {
    $stmt = $pdo->query("
        SHOW FUNCTION STATUS
        WHERE Db = 'eiou' AND Name = 'calculate_next_retry'
    ");
    return $stmt->rowCount() === 1;
});

echo "\n";

// ============================================================================
// CONFIGURATION TESTS
// ============================================================================

echo "=== Configuration Tests ===\n";

test("Default configuration values exist", function() use ($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM message_reliability_config");
    return $stmt->fetchColumn() >= 6;
});

test("ack_timeout_seconds config exists", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT config_value FROM message_reliability_config
        WHERE config_key = 'ack_timeout_seconds'
    ");
    return $stmt->fetchColumn() !== false;
});

test("max_retry_attempts config exists", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT config_value FROM message_reliability_config
        WHERE config_key = 'max_retry_attempts'
    ");
    return $stmt->fetchColumn() !== false;
});

test("retry_strategy config exists", function() use ($pdo) {
    $stmt = $pdo->query("
        SELECT config_value FROM message_reliability_config
        WHERE config_key = 'retry_strategy'
    ");
    return $stmt->fetchColumn() !== false;
});

echo "\n";

// ============================================================================
// FUNCTIONAL TESTS
// ============================================================================

echo "=== Functional Tests ===\n";

// First, ensure we have a test transaction
$pdo->exec("
    INSERT IGNORE INTO transactions
    (sender_address, sender_public_key, receiver_address, receiver_public_key,
     amount, currency, txid, sender_public_key_hash, receiver_public_key_hash)
    VALUES
    ('test_sender', 'pubkey_test', 'test_receiver', 'pubkey_receiver',
     100, 'USD', 'test_txid_schema_001', 'hash_sender', 'hash_receiver')
");

test("Can insert acknowledgment record", function() use ($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO message_acknowledgments
        (txid, message_hash, sender_address, receiver_address)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute(['test_txid_schema_001', 'test_hash_001', 'test_sender', 'test_receiver']);
    return $stmt->rowCount() === 1;
});

test("Can update acknowledgment stage", function() use ($pdo) {
    $stmt = $pdo->prepare("
        UPDATE message_acknowledgments
        SET ack_stage = 'received', status = 'acked', ack_received_at = NOW()
        WHERE message_hash = ?
    ");
    $stmt->execute(['test_hash_001']);
    return $stmt->rowCount() === 1;
});

test("Can insert retry record", function() use ($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO message_retries
        (txid, message_hash, original_message, retry_count)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute(['test_txid_schema_001', 'test_hash_002', '{"test": "data"}', 0]);
    return $stmt->rowCount() === 1;
});

test("Can insert deduplication record", function() use ($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO message_deduplication
        (message_hash, idempotency_key, txid, sender_address, receiver_address, expires_at)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
    ");
    $stmt->execute(['test_hash_003', 'idempotency_001', 'test_txid_schema_001',
                    'test_sender', 'test_receiver']);
    return $stmt->rowCount() === 1;
});

test("Can insert dead letter queue record", function() use ($pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO dead_letter_queue
        (txid, message_hash, original_message, failure_reason, sender_address, receiver_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(['test_txid_schema_001', 'test_hash_004', '{"test": "data"}',
                    'Test failure', 'test_sender', 'test_receiver']);
    return $stmt->rowCount() === 1;
});

test("calculate_next_retry function works", function() use ($pdo) {
    $stmt = $pdo->query("SELECT calculate_next_retry(2, 30, 'exponential')");
    $result = $stmt->fetchColumn();
    return $result !== false && strtotime($result) > time();
});

test("cleanup_expired_deduplication procedure runs", function() use ($pdo) {
    $pdo->exec("CALL cleanup_expired_deduplication()");
    return true;
});

test("move_to_dead_letter_queue procedure runs", function() use ($pdo) {
    $pdo->exec("CALL move_to_dead_letter_queue()");
    return true;
});

echo "\n";

// ============================================================================
// CASCADE DELETE TESTS
// ============================================================================

echo "=== Cascade Delete Tests ===\n";

test("Cascade delete works for acknowledgments", function() use ($pdo) {
    // Insert test transaction
    $pdo->exec("
        INSERT IGNORE INTO transactions
        (sender_address, sender_public_key, receiver_address, receiver_public_key,
         amount, currency, txid, sender_public_key_hash, receiver_public_key_hash)
        VALUES
        ('cascade_test', 'pubkey', 'receiver', 'pubkey_r',
         50, 'USD', 'cascade_txid_001', 'hash_s', 'hash_r')
    ");

    // Insert acknowledgment
    $pdo->exec("
        INSERT INTO message_acknowledgments
        (txid, message_hash, sender_address, receiver_address)
        VALUES ('cascade_txid_001', 'cascade_hash_001', 'cascade_test', 'receiver')
    ");

    // Delete transaction
    $pdo->exec("DELETE FROM transactions WHERE txid = 'cascade_txid_001'");

    // Check acknowledgment was deleted
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM message_acknowledgments
        WHERE message_hash = 'cascade_hash_001'
    ");
    return $stmt->fetchColumn() == 0;
});

echo "\n";

// ============================================================================
// CLEANUP TEST DATA
// ============================================================================

echo "=== Cleanup Test Data ===\n";

test("Cleanup test records", function() use ($pdo) {
    $pdo->exec("DELETE FROM message_acknowledgments WHERE message_hash LIKE 'test_hash_%'");
    $pdo->exec("DELETE FROM message_retries WHERE message_hash LIKE 'test_hash_%'");
    $pdo->exec("DELETE FROM message_deduplication WHERE message_hash LIKE 'test_hash_%'");
    $pdo->exec("DELETE FROM dead_letter_queue WHERE message_hash LIKE 'test_hash_%'");
    $pdo->exec("DELETE FROM transactions WHERE txid LIKE 'test_txid_%' OR txid LIKE 'cascade_txid_%'");
    return true;
});

echo "\n";

// ============================================================================
// RESULTS SUMMARY
// ============================================================================

echo "=====================================\n";
echo "TEST RESULTS SUMMARY\n";
echo "=====================================\n";
echo "Total Tests: " . ($passed + $failed) . "\n";
echo "Passed: $passed ✓\n";
echo "Failed: $failed ✗\n";
echo "Success Rate: " . round(($passed / ($passed + $failed)) * 100, 2) . "%\n";
echo "=====================================\n";

if ($failed === 0) {
    echo "\n✓ ALL TESTS PASSED - Schema is valid and functional!\n\n";
    exit(0);
} else {
    echo "\n✗ SOME TESTS FAILED - Review errors above\n\n";
    exit(1);
}
