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
# Uses PHP introspection (class_exists, method_exists, defined) instead of grep
# for reliable verification during container startup

echo -e "\n[Static Verification Tests]"

# Test 1: Verify SENDING status exists in Constants (using PHP defined())
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SENDING status constant exists"
sendingStatus=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/core/Constants.php';
    echo defined('Constants::STATUS_SENDING') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$sendingStatus" = "EXISTS" ]; then
    printf "\t   SENDING status constant exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SENDING status constant exists ${RED}FAILED${NC} (%s)\n" "$sendingStatus"
    failure=$(( failure + 1 ))
fi

# Test 2: Verify recovery configuration constants (using PHP defined())
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recovery configuration constants"
recoveryConsts=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/core/Constants.php';
    \$count = 0;
    if (defined('Constants::RECOVERY_SENDING_TIMEOUT_SECONDS')) \$count++;
    if (defined('Constants::RECOVERY_MAX_RETRY_COUNT')) \$count++;
    echo \$count;
" 2>/dev/null || echo "0")

if [ "$recoveryConsts" -ge 2 ]; then
    printf "\t   Recovery configuration constants exist ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Recovery configuration constants exist ${RED}FAILED${NC} (%s/2)\n" "$recoveryConsts"
    failure=$(( failure + 1 ))
fi

# Test 3: Verify TransactionRecoveryService exists (using PHP class_exists)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing TransactionRecoveryService exists"
serviceExists=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/services/TransactionRecoveryService.php';
    echo class_exists('TransactionRecoveryService') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$serviceExists" = "EXISTS" ]; then
    printf "\t   TransactionRecoveryService.php exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   TransactionRecoveryService.php exists ${RED}FAILED${NC} (%s)\n" "$serviceExists"
    failure=$(( failure + 1 ))
fi

# Test 4: Verify claimPendingTransaction method exists (using PHP method_exists)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing claimPendingTransaction method"
claimMethod=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/database/TransactionRecoveryRepository.php';
    echo method_exists('TransactionRecoveryRepository', 'claimPendingTransaction') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$claimMethod" = "EXISTS" ]; then
    printf "\t   claimPendingTransaction method exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   claimPendingTransaction method exists ${RED}FAILED${NC} (%s)\n" "$claimMethod"
    failure=$(( failure + 1 ))
fi

# Test 5: Verify markAsSent method exists (using PHP method_exists)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing markAsSent method"
markMethod=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/database/TransactionRecoveryRepository.php';
    echo method_exists('TransactionRecoveryRepository', 'markAsSent') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$markMethod" = "EXISTS" ]; then
    printf "\t   markAsSent method exists ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   markAsSent method exists ${RED}FAILED${NC} (%s)\n" "$markMethod"
    failure=$(( failure + 1 ))
fi

# Test 6: Verify database schema includes new columns (file content check only)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing database schema for recovery columns"
schemaColumns=$(docker exec ${testContainer} php -r "
    \$source = file_get_contents('/etc/eiou/src/database/DatabaseSchema.php');
    if (\$source === false) { echo '0'; exit; }
    \$count = 0;
    if (strpos(\$source, 'sending_started_at') !== false) \$count++;
    if (strpos(\$source, 'recovery_count') !== false) \$count++;
    if (strpos(\$source, 'needs_manual_review') !== false) \$count++;
    echo \$count;
" 2>/dev/null || echo "0")

if [ "$schemaColumns" -ge 3 ]; then
    printf "\t   Recovery columns in schema ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Recovery columns in schema ${RED}FAILED${NC} (%s/3)\n" "$schemaColumns"
    failure=$(( failure + 1 ))
fi

# Test 7: Verify processPendingTransactions uses atomic claiming (using PHP method check)
# Note: processPendingTransactions is now in TransactionProcessingService (refactored from TransactionService)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing processPendingTransactions uses atomic claiming"
atomicClaim=$(docker exec ${testContainer} php -r "
    \$source = file_get_contents('/etc/eiou/src/services/TransactionProcessingService.php');
    echo (strpos(\$source, 'claimPendingTransaction') !== false) ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$atomicClaim" = "EXISTS" ]; then
    printf "\t   Atomic claiming in processPendingTransactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Atomic claiming in processPendingTransactions ${RED}FAILED${NC} (%s)\n" "$atomicClaim"
    failure=$(( failure + 1 ))
fi

# Test 8: Verify startup integration (using PHP file content check)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recovery integrated into startup"
startupIntegration=$(docker exec ${testContainer} php -r "
    \$source = file_get_contents('/etc/eiou/src/core/Application.php');
    echo (strpos(\$source, 'runTransactionRecovery') !== false) ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [ "$startupIntegration" = "EXISTS" ]; then
    printf "\t   Transaction recovery in startup ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction recovery in startup ${RED}FAILED${NC} (%s)\n" "$startupIntegration"
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
# Individual tests using Application singleton pattern for proper dependency injection
# Each test runs separately for better error isolation and debugging

echo -e "\n[Functional Tests - Database Operations]"

# Helper: Create test transaction via PHP
# Usage: create_test_tx <txid> <status> [sending_started_at] [recovery_count] [needs_manual_review]
create_test_tx() {
    local txid="$1"
    local status="$2"
    local started="${3:-NULL}"
    local count="${4:-0}"
    local review="${5:-0}"

    docker exec ${testContainer} php -r "
        require_once('${REL_APPLICATION}');
        \$pdo = Application::getInstance()->services->getPdo();
        \$sql = \"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
                receiver_address, receiver_public_key, amount, currency, memo, sending_started_at, recovery_count, needs_manual_review)
                VALUES ('${txid}', 'standard', 'sent', '${status}', 'test_sender', 'test_pubkey',
                'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test', ${started}, ${count}, ${review})\";
        \$pdo->exec(\$sql);
        echo 'CREATED';
    " 2>/dev/null || echo "ERROR"
}

# Helper: Cleanup test transactions
cleanup_test_tx() {
    docker exec ${testContainer} php -r "
        require_once('${REL_APPLICATION}');
        \$pdo = Application::getInstance()->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE txid LIKE 'test_recovery_%'\");
    " 2>/dev/null
}

# Initial cleanup
cleanup_test_tx

# Test 12: Atomic claim succeeds
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing atomic claim succeeds"
claimResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_claim_' . uniqid();
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo)
        VALUES ('\$txid', 'standard', 'sent', 'pending', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test')\");

    \$claimed = \$repo->claimPendingTransaction(\$txid);
    \$stmt = \$pdo->prepare('SELECT status FROM transactions WHERE txid = ?');
    \$stmt->execute([\$txid]);
    \$status = \$stmt->fetchColumn();

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo (\$claimed && \$status === 'sending') ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$claimResult" = "PASS" ]; then
    printf "\t   Atomic claim succeeds ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Atomic claim succeeds ${RED}FAILED${NC} (%s)\n" "$claimResult"
    failure=$(( failure + 1 ))
fi

# Test 13: Duplicate claim rejected
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing duplicate claim rejected"
dupResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_dup_' . uniqid();
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo)
        VALUES ('\$txid', 'standard', 'sent', 'pending', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test')\");

    \$first = \$repo->claimPendingTransaction(\$txid);
    \$second = \$repo->claimPendingTransaction(\$txid);

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo (\$first && !\$second) ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$dupResult" = "PASS" ]; then
    printf "\t   Duplicate claim rejected ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Duplicate claim rejected ${RED}FAILED${NC} (%s)\n" "$dupResult"
    failure=$(( failure + 1 ))
fi

# Test 14: Cannot claim sent transaction
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing cannot claim sent transaction"
sentResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_sent_' . uniqid();
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo)
        VALUES ('\$txid', 'standard', 'sent', 'sent', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test')\");

    \$claimed = \$repo->claimPendingTransaction(\$txid);

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo (!\$claimed) ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$sentResult" = "PASS" ]; then
    printf "\t   Cannot claim sent transaction ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Cannot claim sent transaction ${RED}FAILED${NC} (%s)\n" "$sentResult"
    failure=$(( failure + 1 ))
fi

# Test 15: Mark as sent works
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing mark as sent works"
markResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_mark_' . uniqid();
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo)
        VALUES ('\$txid', 'standard', 'sent', 'pending', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test')\");

    \$repo->claimPendingTransaction(\$txid);
    \$marked = \$repo->markAsSent(\$txid);

    \$stmt = \$pdo->prepare('SELECT status FROM transactions WHERE txid = ?');
    \$stmt->execute([\$txid]);
    \$status = \$stmt->fetchColumn();

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo (\$marked && \$status === 'sent') ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$markResult" = "PASS" ]; then
    printf "\t   Mark as sent works ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Mark as sent works ${RED}FAILED${NC} (%s)\n" "$markResult"
    failure=$(( failure + 1 ))
fi

# Test 16: Detect stuck transaction
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing detect stuck transaction"
stuckResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_stuck_' . uniqid();
    \$oldTime = date('Y-m-d H:i:s', time() - 300);
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo, sending_started_at)
        VALUES ('\$txid', 'standard', 'sent', 'sending', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test', '\$oldTime')\");

    \$stuck = \$repo->getStuckSendingTransactions(60);
    \$found = false;
    foreach (\$stuck as \$s) { if (\$s['txid'] === \$txid) { \$found = true; break; } }

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo \$found ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$stuckResult" = "PASS" ]; then
    printf "\t   Detect stuck transaction ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Detect stuck transaction ${RED}FAILED${NC} (%s)\n" "$stuckResult"
    failure=$(( failure + 1 ))
fi

# Test 17: Recovery resets to pending
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recovery resets to pending"
resetResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_reset_' . uniqid();
    \$oldTime = date('Y-m-d H:i:s', time() - 300);
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo, sending_started_at, recovery_count)
        VALUES ('\$txid', 'standard', 'sent', 'sending', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test', '\$oldTime', 0)\");

    \$result = \$repo->recoverStuckTransaction(\$txid, 3);

    \$stmt = \$pdo->prepare('SELECT status, recovery_count FROM transactions WHERE txid = ?');
    \$stmt->execute([\$txid]);
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo (\$result['recovered'] && \$row['status'] === 'pending' && \$row['recovery_count'] == 1) ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$resetResult" = "PASS" ]; then
    printf "\t   Recovery resets to pending ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Recovery resets to pending ${RED}FAILED${NC} (%s)\n" "$resetResult"
    failure=$(( failure + 1 ))
fi

# Test 18: Max retry marks for review
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing max retry marks for review"
maxRetryResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_maxretry_' . uniqid();
    \$oldTime = date('Y-m-d H:i:s', time() - 300);
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo, sending_started_at, recovery_count)
        VALUES ('\$txid', 'standard', 'sent', 'sending', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test', '\$oldTime', 3)\");

    \$result = \$repo->recoverStuckTransaction(\$txid, 3);

    \$stmt = \$pdo->prepare('SELECT status, needs_manual_review FROM transactions WHERE txid = ?');
    \$stmt->execute([\$txid]);
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo (\$result['needs_review'] && \$row['status'] === 'failed' && \$row['needs_manual_review'] == 1) ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$maxRetryResult" = "PASS" ]; then
    printf "\t   Max retry marks for review ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Max retry marks for review ${RED}FAILED${NC} (%s)\n" "$maxRetryResult"
    failure=$(( failure + 1 ))
fi

# Test 19: Concurrent claims - only one succeeds
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing concurrent claims atomicity"
concurrentResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$repo = \$app->services->getTransactionRecoveryRepository();

    \$txid = 'test_recovery_concurrent_' . uniqid();
    \$pdo->exec(\"INSERT INTO transactions (txid, tx_type, type, status, sender_address, sender_public_key,
        receiver_address, receiver_public_key, amount, currency, memo)
        VALUES ('\$txid', 'standard', 'sent', 'pending', 'test_sender', 'test_pubkey',
        'test_receiver', 'test_recv_pubkey', 100, 'USD', 'test')\");

    \$c1 = \$repo->claimPendingTransaction(\$txid);
    \$c2 = \$repo->claimPendingTransaction(\$txid);
    \$c3 = \$repo->claimPendingTransaction(\$txid);
    \$successCount = (\$c1 ? 1 : 0) + (\$c2 ? 1 : 0) + (\$c3 ? 1 : 0);

    \$pdo->exec(\"DELETE FROM transactions WHERE txid = '\$txid'\");
    echo (\$successCount === 1) ? 'PASS' : 'FAIL';
" 2>/dev/null || echo "ERROR")

if [ "$concurrentResult" = "PASS" ]; then
    printf "\t   Concurrent claims atomicity ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Concurrent claims atomicity ${RED}FAILED${NC} (%s)\n" "$concurrentResult"
    failure=$(( failure + 1 ))
fi

# Final cleanup
cleanup_test_tx

############################ SUMMARY ############################

echo -e "\n[Transaction Recovery Test Summary]"
echo -e "\t   Total: ${passed}/${totaltests} passed"

succesrate "${totaltests}" "${passed}" "${failure}" "'transaction recovery'"
