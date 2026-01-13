<?php
/**
 * Transaction Recovery Functional Tests
 *
 * Tests actual transaction flow scenarios including:
 * - Atomic claiming prevents duplicate processing
 * - Crash recovery resets stuck transactions
 * - Max retry exceeded marks for manual review
 * - Concurrent claim attempts are rejected
 *
 * Copyright 2025 Adrien Hubert (adrien@eiou.org)
 */

// Load required files
require_once '/etc/eiou/src/core/Constants.php';
require_once '/etc/eiou/src/database/TransactionRepository.php';
require_once '/etc/eiou/src/services/TransactionRecoveryService.php';
require_once '/etc/eiou/src/utils/SecureLogger.php';

// Test result tracking
$totalTests = 0;
$passed = 0;
$failed = 0;
$testResults = [];

// Colors for output
define('GREEN', "\033[0;32m");
define('RED', "\033[0;31m");
define('YELLOW', "\033[1;33m");
define('NC', "\033[0m");
define('TICK', "✓");
define('CROSS', "✗");

/**
 * Run a test and track results
 */
function runTest(string $name, callable $test): void {
    global $totalTests, $passed, $failed, $testResults;
    $totalTests++;

    echo "\n[Test $totalTests] $name\n";

    try {
        $result = $test();
        if ($result === true) {
            echo GREEN . TICK . " PASSED" . NC . "\n";
            $passed++;
            $testResults[$name] = 'PASSED';
        } else {
            echo RED . CROSS . " FAILED: $result" . NC . "\n";
            $failed++;
            $testResults[$name] = "FAILED: $result";
        }
    } catch (Exception $e) {
        echo RED . CROSS . " ERROR: " . $e->getMessage() . NC . "\n";
        $failed++;
        $testResults[$name] = "ERROR: " . $e->getMessage();
    }
}

/**
 * Create a test transaction in the database
 */
function createTestTransaction(PDO $pdo, string $txid, string $status = 'pending', array $extra = []): void {
    $defaults = [
        'tx_type' => 'standard',
        'type' => 'sent',
        'sender_address' => 'test_sender_' . uniqid(),
        'sender_public_key' => 'test_pubkey_' . uniqid(),
        'receiver_address' => 'test_receiver_' . uniqid(),
        'receiver_public_key' => 'test_receiver_pubkey_' . uniqid(),
        'amount' => 100,
        'currency' => 'USD',
        'memo' => 'standard',
    ];

    $data = array_merge($defaults, $extra);

    $sql = "INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
            receiver_address, receiver_public_key, amount, currency, memo, sending_started_at, recovery_count, needs_manual_review)
            VALUES (:txid, :tx_type, :type, :status, :sender_address, :sender_public_key,
            :receiver_address, :receiver_public_key, :amount, :currency, :memo, :sending_started_at, :recovery_count, :needs_manual_review)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':txid' => $txid,
        ':tx_type' => $data['tx_type'],
        ':type' => $data['type'],
        ':status' => $status,
        ':sender_address' => $data['sender_address'],
        ':sender_public_key' => $data['sender_public_key'],
        ':receiver_address' => $data['receiver_address'],
        ':receiver_public_key' => $data['receiver_public_key'],
        ':amount' => $data['amount'],
        ':currency' => $data['currency'],
        ':memo' => $data['memo'],
        ':sending_started_at' => $data['sending_started_at'] ?? null,
        ':recovery_count' => $data['recovery_count'] ?? 0,
        ':needs_manual_review' => $data['needs_manual_review'] ?? 0,
    ]);
}

/**
 * Get transaction by txid
 */
function getTransaction(PDO $pdo, string $txid): ?array {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE txid = :txid");
    $stmt->execute([':txid' => $txid]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Delete test transactions
 */
function cleanupTestTransactions(PDO $pdo, string $prefix = 'test_tx_'): void {
    $pdo->exec("DELETE FROM transactions WHERE txid LIKE '{$prefix}%'");
}

// ============================================================================
// MAIN TEST EXECUTION
// ============================================================================

echo "\n";
echo "================================================================\n";
echo "  Transaction Recovery Functional Tests\n";
echo "================================================================\n";
echo "Testing actual transaction flow scenarios with database operations\n";

// Connect to database
$dbConfig = json_decode(file_get_contents('/etc/eiou/dbconfig.json'), true);
$pdo = new PDO(
    "mysql:host={$dbConfig['dbHost']};dbname={$dbConfig['dbName']}",
    $dbConfig['dbUser'],
    $dbConfig['dbPass']
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create repository and recovery service
$transactionRepo = new TransactionRepository($pdo);
$recoveryService = new TransactionRecoveryService($transactionRepo);

// Clean up any leftover test data
cleanupTestTransactions($pdo);

// ============================================================================
// TEST SCENARIO 1: Atomic Claiming - Successful Claim
// ============================================================================
runTest("Atomic Claim: Successfully claim a pending transaction", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_claim_success_' . uniqid();

    // Create a pending transaction
    createTestTransaction($pdo, $txid, 'pending');

    // Verify it's pending
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'pending') {
        return "Transaction should be pending, got: {$tx['status']}";
    }

    // Claim the transaction
    $claimed = $transactionRepo->claimPendingTransaction($txid);
    if (!$claimed) {
        return "Failed to claim pending transaction";
    }

    // Verify it's now in 'sending' status
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'sending') {
        return "Transaction should be 'sending' after claim, got: {$tx['status']}";
    }

    // Verify sending_started_at is set
    if (empty($tx['sending_started_at'])) {
        return "sending_started_at should be set after claim";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 2: Atomic Claiming - Duplicate Claim Rejected
// ============================================================================
runTest("Atomic Claim: Second claim attempt is rejected (prevents duplicates)", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_duplicate_claim_' . uniqid();

    // Create a pending transaction
    createTestTransaction($pdo, $txid, 'pending');

    // First claim should succeed
    $firstClaim = $transactionRepo->claimPendingTransaction($txid);
    if (!$firstClaim) {
        return "First claim should succeed";
    }

    // Second claim should fail (transaction is now 'sending', not 'pending')
    $secondClaim = $transactionRepo->claimPendingTransaction($txid);
    if ($secondClaim) {
        return "Second claim should fail but succeeded - DUPLICATE WOULD OCCUR!";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 3: Atomic Claiming - Claim on non-pending fails
// ============================================================================
runTest("Atomic Claim: Cannot claim transaction that's already sent", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_already_sent_' . uniqid();

    // Create a transaction already in 'sent' status
    createTestTransaction($pdo, $txid, 'sent');

    // Claim should fail
    $claimed = $transactionRepo->claimPendingTransaction($txid);
    if ($claimed) {
        return "Should not be able to claim a transaction already in 'sent' status";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 4: Mark As Sent - Successful Transition
// ============================================================================
runTest("Mark As Sent: Transition from 'sending' to 'sent'", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_mark_sent_' . uniqid();

    // Create and claim a transaction
    createTestTransaction($pdo, $txid, 'pending');
    $transactionRepo->claimPendingTransaction($txid);

    // Verify it's in 'sending'
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'sending') {
        return "Transaction should be 'sending', got: {$tx['status']}";
    }

    // Mark as sent
    $marked = $transactionRepo->markAsSent($txid);
    if (!$marked) {
        return "Failed to mark transaction as sent";
    }

    // Verify it's now 'sent'
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'sent') {
        return "Transaction should be 'sent' after markAsSent, got: {$tx['status']}";
    }

    // Verify sending_started_at is cleared
    if (!empty($tx['sending_started_at'])) {
        return "sending_started_at should be cleared after markAsSent";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 5: Crash Simulation - Transaction Stuck in 'sending'
// ============================================================================
runTest("Crash Recovery: Detect transaction stuck in 'sending' status", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_stuck_sending_' . uniqid();

    // Simulate a crashed transaction: status='sending' with old timestamp
    $oldTime = date('Y-m-d H:i:s', time() - 300); // 5 minutes ago
    createTestTransaction($pdo, $txid, 'sending', [
        'sending_started_at' => $oldTime,
        'recovery_count' => 0
    ]);

    // Get stuck transactions (with 60 second timeout for test)
    $stuckTxs = $transactionRepo->getStuckSendingTransactions(60);

    // Should find our stuck transaction
    $found = false;
    foreach ($stuckTxs as $tx) {
        if ($tx['txid'] === $txid) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        return "Stuck transaction not detected by getStuckSendingTransactions";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 6: Crash Recovery - Reset to Pending for Retry
// ============================================================================
runTest("Crash Recovery: Stuck transaction reset to 'pending' for retry", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_recovery_retry_' . uniqid();

    // Simulate a crashed transaction
    $oldTime = date('Y-m-d H:i:s', time() - 300);
    createTestTransaction($pdo, $txid, 'sending', [
        'sending_started_at' => $oldTime,
        'recovery_count' => 0
    ]);

    // Recover the transaction
    $result = $transactionRepo->recoverStuckTransaction($txid, 3);

    if (!$result['recovered']) {
        return "Transaction should be recovered";
    }

    if ($result['needs_review']) {
        return "Transaction should not need review yet (first recovery)";
    }

    // Verify status is back to 'pending'
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'pending') {
        return "Transaction should be 'pending' after recovery, got: {$tx['status']}";
    }

    // Verify recovery_count incremented
    if ($tx['recovery_count'] != 1) {
        return "recovery_count should be 1, got: {$tx['recovery_count']}";
    }

    // Verify sending_started_at cleared
    if (!empty($tx['sending_started_at'])) {
        return "sending_started_at should be cleared after recovery";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 7: Max Retry Exceeded - Mark for Manual Review
// ============================================================================
runTest("Crash Recovery: Transaction exceeding max retries marked for review", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_max_retry_' . uniqid();

    // Simulate a transaction that has already been recovered multiple times
    $oldTime = date('Y-m-d H:i:s', time() - 300);
    createTestTransaction($pdo, $txid, 'sending', [
        'sending_started_at' => $oldTime,
        'recovery_count' => 3  // Already at max
    ]);

    // Try to recover (should exceed max retries of 3)
    $result = $transactionRepo->recoverStuckTransaction($txid, 3);

    if ($result['recovered']) {
        return "Transaction should NOT be recovered (exceeded max retries)";
    }

    if (!$result['needs_review']) {
        return "Transaction should be marked for manual review";
    }

    // Verify status is 'failed'
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'failed') {
        return "Transaction should be 'failed' after exceeding retries, got: {$tx['status']}";
    }

    // Verify needs_manual_review flag
    if ($tx['needs_manual_review'] != 1) {
        return "needs_manual_review should be 1";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 8: Full Recovery Service - Multiple Stuck Transactions
// ============================================================================
runTest("Recovery Service: Recover multiple stuck transactions on startup", function() use ($pdo, $recoveryService) {
    $txids = [
        'test_tx_multi_1_' . uniqid(),
        'test_tx_multi_2_' . uniqid(),
        'test_tx_multi_3_' . uniqid(),
    ];

    // Create multiple stuck transactions
    $oldTime = date('Y-m-d H:i:s', time() - 300);
    foreach ($txids as $txid) {
        createTestTransaction($pdo, $txid, 'sending', [
            'sending_started_at' => $oldTime,
            'recovery_count' => 0
        ]);
    }

    // Run recovery service (with 60 second timeout for test)
    $results = $recoveryService->recoverStuckTransactions(60, 3);

    // Should have recovered all 3
    if ($results['recovered'] < 3) {
        return "Should recover at least 3 transactions, got: {$results['recovered']}";
    }

    // Verify all are back to 'pending'
    foreach ($txids as $txid) {
        $tx = getTransaction($pdo, $txid);
        if ($tx['status'] !== 'pending') {
            return "Transaction $txid should be 'pending', got: {$tx['status']}";
        }
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 9: Simulated Transaction Flow - Normal Success Path
// ============================================================================
runTest("Transaction Flow: Normal success path (pending→sending→sent)", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_flow_success_' . uniqid();

    // Step 1: Create pending transaction (simulating transaction creation)
    createTestTransaction($pdo, $txid, 'pending');
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'pending') {
        return "Step 1 failed: should be 'pending'";
    }

    // Step 2: Claim transaction (simulating processor picking it up)
    $claimed = $transactionRepo->claimPendingTransaction($txid);
    if (!$claimed) {
        return "Step 2 failed: claim should succeed";
    }
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'sending') {
        return "Step 2 failed: should be 'sending'";
    }

    // Step 3: Simulate successful network send, mark as sent
    // (In real code, this happens after sendTransactionMessage returns)
    $marked = $transactionRepo->markAsSent($txid);
    if (!$marked) {
        return "Step 3 failed: markAsSent should succeed";
    }
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'sent') {
        return "Step 3 failed: should be 'sent'";
    }

    // Step 4: Update to accepted (simulating receiver response)
    // Note: updateStatus requires third param $isTxid=true when using txid
    $transactionRepo->updateStatus($txid, 'accepted', true);
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'accepted') {
        return "Step 4 failed: should be 'accepted'";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 10: Simulated Crash During Send - Recovery Works
// ============================================================================
runTest("Transaction Flow: Crash during send → Recovery → Retry succeeds", function() use ($pdo, $transactionRepo, $recoveryService) {
    $txid = 'test_tx_crash_recovery_flow_' . uniqid();

    // Step 1: Create pending transaction
    createTestTransaction($pdo, $txid, 'pending');

    // Step 2: Claim transaction (processor starts processing)
    $transactionRepo->claimPendingTransaction($txid);
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'sending') {
        return "After claim: should be 'sending'";
    }

    // Step 3: SIMULATE CRASH - process dies here
    // Transaction is stuck in 'sending' status
    // Manually backdate the sending_started_at to simulate time passing
    $pdo->exec("UPDATE transactions SET sending_started_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE txid = '$txid'");

    // Step 4: SIMULATE RESTART - recovery service runs
    $results = $recoveryService->recoverStuckTransactions(60, 3);

    // Verify transaction was recovered
    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'pending') {
        return "After recovery: should be 'pending', got: {$tx['status']}";
    }
    if ($tx['recovery_count'] != 1) {
        return "After recovery: recovery_count should be 1";
    }

    // Step 5: RETRY - processor picks up the transaction again
    $claimed = $transactionRepo->claimPendingTransaction($txid);
    if (!$claimed) {
        return "Retry claim should succeed";
    }

    // Step 6: This time send succeeds
    $marked = $transactionRepo->markAsSent($txid);
    if (!$marked) {
        return "Retry markAsSent should succeed";
    }

    $tx = getTransaction($pdo, $txid);
    if ($tx['status'] !== 'sent') {
        return "Final status should be 'sent', got: {$tx['status']}";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 11: Concurrent Processing - Only One Succeeds
// ============================================================================
runTest("Concurrent Processing: Only one of multiple claim attempts succeeds", function() use ($pdo, $transactionRepo) {
    $txid = 'test_tx_concurrent_' . uniqid();

    // Create a pending transaction
    createTestTransaction($pdo, $txid, 'pending');

    // Simulate multiple concurrent processors trying to claim
    // In a real scenario, these would be parallel processes
    // We simulate by checking the atomic nature of the claim

    $claimResults = [];

    // First claim
    $claimResults[] = $transactionRepo->claimPendingTransaction($txid);

    // Immediately try second claim (simulates race condition)
    $claimResults[] = $transactionRepo->claimPendingTransaction($txid);

    // Third claim attempt
    $claimResults[] = $transactionRepo->claimPendingTransaction($txid);

    // Count successful claims
    $successCount = count(array_filter($claimResults));

    if ($successCount !== 1) {
        return "Exactly 1 claim should succeed, got: $successCount - RACE CONDITION!";
    }

    // Verify first claim succeeded, others failed
    if (!$claimResults[0]) {
        return "First claim should have succeeded";
    }
    if ($claimResults[1] || $claimResults[2]) {
        return "Subsequent claims should have failed";
    }

    return true;
});

// ============================================================================
// TEST SCENARIO 12: Recovery Statistics
// ============================================================================
runTest("Recovery Service: Statistics show correct counts", function() use ($pdo, $recoveryService) {
    // Clean up first
    cleanupTestTransactions($pdo, 'test_tx_stats_');

    // Create some stuck transactions
    $oldTime = date('Y-m-d H:i:s', time() - 300);
    for ($i = 0; $i < 3; $i++) {
        createTestTransaction($pdo, "test_tx_stats_stuck_$i", 'sending', [
            'sending_started_at' => $oldTime,
            'recovery_count' => 0
        ]);
    }

    // Create one that needs review
    createTestTransaction($pdo, "test_tx_stats_review", 'failed', [
        'recovery_count' => 5,
        'needs_manual_review' => 1
    ]);

    // Get statistics
    $stats = $recoveryService->getRecoveryStatistics();

    if ($stats['stuck_sending'] < 3) {
        return "Should show at least 3 stuck_sending, got: {$stats['stuck_sending']}";
    }

    if ($stats['needs_review'] < 1) {
        return "Should show at least 1 needs_review, got: {$stats['needs_review']}";
    }

    return true;
});

// ============================================================================
// CLEANUP AND RESULTS
// ============================================================================

// Clean up test data
cleanupTestTransactions($pdo);

// Print results
echo "\n";
echo "================================================================\n";
echo "  Transaction Recovery Functional Test Results\n";
echo "================================================================\n";
echo "Total Tests: $totalTests\n";
echo GREEN . "Passed: $passed" . NC . "\n";
echo RED . "Failed: $failed" . NC . "\n";
echo "================================================================\n";

if ($failed > 0) {
    echo "\n" . RED . "Failed Tests:" . NC . "\n";
    foreach ($testResults as $name => $result) {
        if (strpos($result, 'FAILED') === 0 || strpos($result, 'ERROR') === 0) {
            echo "  - $name: $result\n";
        }
    }
}

// Exit with appropriate code
exit($failed > 0 ? 1 : 0);
