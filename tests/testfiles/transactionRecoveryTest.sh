#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

# Transaction Recovery Test Suite
# Tests atomic transaction claiming and crash recovery mechanisms
#
# Verifies:
# - SENDING status prevents duplicate processing
# - Crash recovery resets stuck transactions
# - Max retry exceeded marks for manual review
# - Full transaction flow works correctly

echo -e "\nTesting Transaction Recovery Mechanisms..."

testname="transactionRecoveryTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

############################ STATIC VERIFICATION TESTS ############################

echo -e "\n[Static Verification Tests]"

# Test 1: Verify SENDING status exists in Constants
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SENDING status constant exists"
sendingStatus=$(docker exec ${testContainer} grep -c "STATUS_SENDING = 'sending'" /etc/eiou/src/core/Constants.php 2>/dev/null)

if [ "$sendingStatus" -ge 1 ]; then
    printf "\t   SENDING status constant exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SENDING status constant exists ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 2: Verify recovery configuration constants
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recovery configuration constants"
recoveryConsts=$(docker exec ${testContainer} sh -c "grep -c 'RECOVERY_SENDING_TIMEOUT_SECONDS\|RECOVERY_MAX_RETRY_COUNT' /etc/eiou/src/core/Constants.php" 2>/dev/null)

if [ "$recoveryConsts" -ge 2 ]; then
    printf "\t   Recovery configuration constants exist ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Recovery configuration constants exist ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 3: Verify TransactionRecoveryService exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TransactionRecoveryService exists"
serviceExists=$(docker exec ${testContainer} test -f /etc/eiou/src/services/TransactionRecoveryService.php && echo "1" || echo "0")

if [ "$serviceExists" = "1" ]; then
    printf "\t   TransactionRecoveryService.php exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TransactionRecoveryService.php exists ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 4: Verify claimPendingTransaction method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing claimPendingTransaction method"
claimMethod=$(docker exec ${testContainer} grep -c "function claimPendingTransaction" /etc/eiou/src/database/TransactionRepository.php 2>/dev/null)

if [ "$claimMethod" -ge 1 ]; then
    printf "\t   claimPendingTransaction method exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   claimPendingTransaction method exists ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 5: Verify markAsSent method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing markAsSent method"
markMethod=$(docker exec ${testContainer} grep -c "function markAsSent" /etc/eiou/src/database/TransactionRepository.php 2>/dev/null)

if [ "$markMethod" -ge 1 ]; then
    printf "\t   markAsSent method exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   markAsSent method exists ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6: Verify database schema includes new columns
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing database schema for recovery columns"
schemaColumns=$(docker exec ${testContainer} sh -c "grep -c 'sending_started_at\|recovery_count\|needs_manual_review' /etc/eiou/src/database/DatabaseSchema.php" 2>/dev/null)

if [ "$schemaColumns" -ge 3 ]; then
    printf "\t   Recovery columns in schema ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Recovery columns in schema ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7: Verify processPendingTransactions uses atomic claiming
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing processPendingTransactions uses atomic claiming"
atomicClaim=$(docker exec ${testContainer} grep -c "claimPendingTransaction" /etc/eiou/src/services/TransactionService.php 2>/dev/null)

if [ "$atomicClaim" -ge 1 ]; then
    printf "\t   Atomic claiming in processPendingTransactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Atomic claiming in processPendingTransactions ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 8: Verify startup integration
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recovery integrated into startup"
startupIntegration=$(docker exec ${testContainer} grep -c "runTransactionRecovery" /etc/eiou/src/core/Application.php 2>/dev/null)

if [ "$startupIntegration" -ge 1 ]; then
    printf "\t   Transaction recovery in startup ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction recovery in startup ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ DATABASE SCHEMA TESTS ############################

echo -e "\n[Database Schema Tests]"

# Test 9: Verify 'sending' status exists in transactions table ENUM
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing 'sending' status in database ENUM"
dbSendingStatus=$(docker exec ${testContainer} sh -c "mysql -u root -N -e \"SHOW COLUMNS FROM transactions LIKE 'status';\" eiou 2>/dev/null | grep -c \"'sending'\"")

if [ "$dbSendingStatus" -ge 1 ]; then
    printf "\t   'sending' status in ENUM ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   'sending' status in ENUM ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 10: Verify recovery columns exist in database
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recovery columns in database"
dbColumns=$(docker exec ${testContainer} sh -c "mysql -u root -N -e \"DESCRIBE transactions;\" eiou 2>/dev/null | grep -c -E 'sending_started_at|recovery_count|needs_manual_review'")

if [ "$dbColumns" -ge 3 ]; then
    printf "\t   Recovery columns exist (${dbColumns}/3) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Recovery columns exist (${dbColumns}/3) ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 11: Verify recovery index exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recovery index exists"
dbIndex=$(docker exec ${testContainer} sh -c "mysql -u root -N -e \"SHOW INDEX FROM transactions WHERE Key_name LIKE '%sending_recovery%';\" eiou 2>/dev/null | wc -l")

if [ "$dbIndex" -ge 1 ]; then
    printf "\t   Recovery index exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Recovery index exists ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ FUNCTIONAL TESTS ############################

echo -e "\n[Functional Tests - Database Operations]"

# Test 12-23: Run PHP functional tests
echo -e "\n\t-> Running transaction flow functional tests"

# Create PHP test script and copy to container
PHP_TEST_SCRIPT=$(mktemp)
cat > "$PHP_TEST_SCRIPT" << 'PHPEOF'
<?php
require_once "/etc/eiou/src/core/Constants.php";
require_once "/etc/eiou/src/database/TransactionRepository.php";
require_once "/etc/eiou/src/services/TransactionRecoveryService.php";

$dbConfig = json_decode(file_get_contents("/etc/eiou/dbconfig.json"), true);
$pdo = new PDO("mysql:host={$dbConfig['dbHost']};dbname={$dbConfig['dbName']}", $dbConfig['dbUser'], $dbConfig['dbPass']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$repo = new TransactionRepository($pdo);
$recovery = new TransactionRecoveryService($repo);

$passed = 0;
$failed = 0;
$results = [];

function createTx($pdo, $txid, $status, $extra = []) {
    $sql = "INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
            receiver_address, receiver_public_key, amount, currency, memo, sending_started_at, recovery_count, needs_manual_review)
            VALUES (:txid, 'standard', 'sent', :status, 'test_sender', 'test_pubkey',
            'test_receiver', 'test_recv_pubkey', 100, 'USD', 'standard', :started, :count, :review)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':txid' => $txid, ':status' => $status,
        ':started' => $extra['sending_started_at'] ?? null,
        ':count' => $extra['recovery_count'] ?? 0,
        ':review' => $extra['needs_manual_review'] ?? 0
    ]);
}

function getTx($pdo, $txid) {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE txid = :txid");
    $stmt->execute([':txid' => $txid]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function cleanup($pdo) {
    $pdo->exec("DELETE FROM transactions WHERE txid LIKE 'test_recovery_%'");
}

cleanup($pdo);
$oldTime = date('Y-m-d H:i:s', time() - 300);

// Test 1: Atomic claim succeeds
$txid = 'test_recovery_claim_' . uniqid();
createTx($pdo, $txid, 'pending');
$claimed = $repo->claimPendingTransaction($txid);
$tx = getTx($pdo, $txid);
if ($claimed && $tx['status'] === 'sending') { $passed++; $results[] = 'atomic_claim:PASS'; }
else { $failed++; $results[] = 'atomic_claim:FAIL'; }

// Test 2: Duplicate claim rejected
$secondClaim = $repo->claimPendingTransaction($txid);
if (!$secondClaim) { $passed++; $results[] = 'duplicate_rejected:PASS'; }
else { $failed++; $results[] = 'duplicate_rejected:FAIL'; }

// Test 3: Cannot claim sent transaction
$txid2 = 'test_recovery_sent_' . uniqid();
createTx($pdo, $txid2, 'sent');
$claimSent = $repo->claimPendingTransaction($txid2);
if (!$claimSent) { $passed++; $results[] = 'cannot_claim_sent:PASS'; }
else { $failed++; $results[] = 'cannot_claim_sent:FAIL'; }

// Test 4: Mark as sent works
$txid3 = 'test_recovery_mark_' . uniqid();
createTx($pdo, $txid3, 'pending');
$repo->claimPendingTransaction($txid3);
$marked = $repo->markAsSent($txid3);
$tx3 = getTx($pdo, $txid3);
if ($marked && $tx3['status'] === 'sent') { $passed++; $results[] = 'mark_sent:PASS'; }
else { $failed++; $results[] = 'mark_sent:FAIL'; }

// Test 5: Detect stuck transaction
$txid4 = 'test_recovery_stuck_' . uniqid();
createTx($pdo, $txid4, 'sending', ['sending_started_at' => $oldTime]);
$stuck = $repo->getStuckSendingTransactions(60);
$found = false;
foreach ($stuck as $s) { if ($s['txid'] === $txid4) { $found = true; break; } }
if ($found) { $passed++; $results[] = 'detect_stuck:PASS'; }
else { $failed++; $results[] = 'detect_stuck:FAIL'; }

// Test 6: Recovery resets to pending
$txid5 = 'test_recovery_reset_' . uniqid();
createTx($pdo, $txid5, 'sending', ['sending_started_at' => $oldTime, 'recovery_count' => 0]);
$result = $repo->recoverStuckTransaction($txid5, 3);
$tx5 = getTx($pdo, $txid5);
if ($result['recovered'] && $tx5['status'] === 'pending' && $tx5['recovery_count'] == 1) {
    $passed++; $results[] = 'recovery_reset:PASS';
} else { $failed++; $results[] = 'recovery_reset:FAIL'; }

// Test 7: Max retry marks for review
$txid6 = 'test_recovery_maxretry_' . uniqid();
createTx($pdo, $txid6, 'sending', ['sending_started_at' => $oldTime, 'recovery_count' => 3]);
$result6 = $repo->recoverStuckTransaction($txid6, 3);
$tx6 = getTx($pdo, $txid6);
if ($result6['needs_review'] && $tx6['status'] === 'failed' && $tx6['needs_manual_review'] == 1) {
    $passed++; $results[] = 'max_retry_review:PASS';
} else { $failed++; $results[] = 'max_retry_review:FAIL'; }

// Test 8: Recovery service handles multiple
cleanup($pdo);
for ($i = 0; $i < 3; $i++) {
    createTx($pdo, "test_recovery_multi_$i", 'sending', ['sending_started_at' => $oldTime]);
}
$multiResult = $recovery->recoverStuckTransactions(60, 3);
if ($multiResult['recovered'] >= 3) { $passed++; $results[] = 'multi_recovery:PASS'; }
else { $failed++; $results[] = 'multi_recovery:FAIL'; }

// Test 9: Full flow - pending->sending->sent->accepted
$txid9 = 'test_recovery_flow_' . uniqid();
createTx($pdo, $txid9, 'pending');
$repo->claimPendingTransaction($txid9);
$repo->markAsSent($txid9);
$repo->updateStatus($txid9, 'accepted', true);
$tx9 = getTx($pdo, $txid9);
if ($tx9['status'] === 'accepted') { $passed++; $results[] = 'full_flow:PASS'; }
else { $failed++; $results[] = 'full_flow:FAIL'; }

// Test 10: Crash simulation -> recovery -> retry
$txid10 = 'test_recovery_crash_' . uniqid();
createTx($pdo, $txid10, 'pending');
$repo->claimPendingTransaction($txid10);
$pdo->exec("UPDATE transactions SET sending_started_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE txid = '$txid10'");
$recovery->recoverStuckTransactions(60, 3);
$tx10a = getTx($pdo, $txid10);
if ($tx10a['status'] !== 'pending') { $failed++; $results[] = 'crash_recovery:FAIL(not_pending)'; }
else {
    $repo->claimPendingTransaction($txid10);
    $repo->markAsSent($txid10);
    $tx10b = getTx($pdo, $txid10);
    if ($tx10b['status'] === 'sent') { $passed++; $results[] = 'crash_recovery:PASS'; }
    else { $failed++; $results[] = 'crash_recovery:FAIL(not_sent)'; }
}

// Test 11: Concurrent claims - only one succeeds
$txid11 = 'test_recovery_concurrent_' . uniqid();
createTx($pdo, $txid11, 'pending');
$c1 = $repo->claimPendingTransaction($txid11);
$c2 = $repo->claimPendingTransaction($txid11);
$c3 = $repo->claimPendingTransaction($txid11);
$successCount = ($c1 ? 1 : 0) + ($c2 ? 1 : 0) + ($c3 ? 1 : 0);
if ($successCount === 1) { $passed++; $results[] = 'concurrent_claim:PASS'; }
else { $failed++; $results[] = "concurrent_claim:FAIL($successCount)"; }

// Test 12: Statistics work
cleanup($pdo);
createTx($pdo, 'test_recovery_stat1', 'sending', ['sending_started_at' => $oldTime]);
createTx($pdo, 'test_recovery_stat2', 'failed', ['recovery_count' => 5, 'needs_manual_review' => 1]);
$stats = $recovery->getRecoveryStatistics();
if ($stats['stuck_sending'] >= 1 && $stats['needs_review'] >= 1) {
    $passed++; $results[] = 'statistics:PASS';
} else { $failed++; $results[] = 'statistics:FAIL'; }

cleanup($pdo);

echo json_encode(['passed' => $passed, 'failed' => $failed, 'results' => $results]);
PHPEOF

# Copy and run the PHP test
docker cp "$PHP_TEST_SCRIPT" ${testContainer}:/tmp/functional_test.php >/dev/null 2>&1
functionalResult=$(docker exec ${testContainer} php /tmp/functional_test.php 2>&1)
rm -f "$PHP_TEST_SCRIPT"

# Parse results
funcPassed=$(echo "$functionalResult" | grep -o '"passed":[0-9]*' | grep -o '[0-9]*' || echo "0")
funcFailed=$(echo "$functionalResult" | grep -o '"failed":[0-9]*' | grep -o '[0-9]*' || echo "0")
funcResults=$(echo "$functionalResult" | grep -o '"results":\[[^]]*\]' | sed 's/"results":\[//;s/\]//;s/"//g;s/,/\n/g' | sed 's/^/      /')

# Add functional test counts
totaltests=$(( totaltests + funcPassed + funcFailed ))
passed=$(( passed + funcPassed ))
failure=$(( failure + funcFailed ))

if [ "$funcFailed" -eq 0 ] && [ "$funcPassed" -gt 0 ]; then
    printf "\t   Functional tests: ${funcPassed}/${funcPassed} ${GREEN}PASSED${NC}\n"
else
    printf "\t   Functional tests: ${funcPassed}/$((funcPassed + funcFailed)) ${RED}FAILED${NC}\n"
    printf "\t   Results:\n"
    echo "$funcResults"
fi

############################ SUMMARY ############################

echo -e "\n[Transaction Recovery Test Summary]"
echo -e "\t   Static tests:     $((passed - funcPassed)) passed"
echo -e "\t   Functional tests: ${funcPassed} passed"
echo -e "\t   Total:            ${passed}/${totaltests} passed"
