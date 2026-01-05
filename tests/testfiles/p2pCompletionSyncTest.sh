#!/bin/sh
# Copyright 2025 The Vowels Company

# Test P2P completion status check before expiring
# Tests the fix for issue #357 - ensures P2P transactions check completion
# status before expiring to prevent status mismatches in the chain

echo -e "\nTesting P2P completion status sync on expiration..."

testname="p2pCompletionSyncTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

# ============================================================================
# Test 1: MessagePayload buildP2pStatusInquiry creates valid payload
# ============================================================================
echo -e "\n[Test 1: MessagePayload buildP2pStatusInquiry]"
totaltests=$(( totaltests + 1 ))

inquiryResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$currentUser = UserContext::getInstance();

    require_once('/etc/eiou/src/schemas/payloads/MessagePayload.php');
    \$messagePayload = new MessagePayload(\$currentUser, \$utilContainer);

    \$testHash = 'test-hash-' . time();
    \$testAddress = 'http://test.local:8080';
    \$payload = \$messagePayload->buildP2pStatusInquiry(\$testAddress, \$testHash);

    // Verify payload structure
    if (
        \$payload['type'] === 'message' &&
        \$payload['typeMessage'] === 'p2p' &&
        \$payload['inquiry'] === true &&
        \$payload['hash'] === \$testHash &&
        isset(\$payload['senderAddress']) &&
        isset(\$payload['senderPublicKey'])
    ) {
        echo 'SUCCESS';
    } else {
        echo 'FAILED: ' . json_encode(\$payload);
    }
" 2>/dev/null || echo "ERROR")

if [[ "$inquiryResult" == "SUCCESS" ]]; then
    printf "\t   MessagePayload buildP2pStatusInquiry ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessagePayload buildP2pStatusInquiry ${RED}FAILED${NC} (%s)\n" "${inquiryResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 2: MessagePayload buildP2pStatusResponse creates valid payload
# ============================================================================
echo -e "\n[Test 2: MessagePayload buildP2pStatusResponse]"
totaltests=$(( totaltests + 1 ))

responseResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$currentUser = UserContext::getInstance();

    require_once('/etc/eiou/src/schemas/payloads/MessagePayload.php');
    \$messagePayload = new MessagePayload(\$currentUser, \$utilContainer);

    \$testHash = 'test-hash-' . time();
    \$testAddress = 'http://test.local:8080';
    \$jsonResponse = \$messagePayload->buildP2pStatusResponse(\$testAddress, \$testHash, 'completed');
    \$response = json_decode(\$jsonResponse, true);

    // Verify response structure
    if (
        \$response['type'] === 'message' &&
        \$response['typeMessage'] === 'p2p' &&
        \$response['inquiry'] === false &&
        \$response['hash'] === \$testHash &&
        \$response['status'] === 'completed' &&
        isset(\$response['senderAddress']) &&
        isset(\$response['senderPublicKey'])
    ) {
        echo 'SUCCESS';
    } else {
        echo 'FAILED: ' . \$jsonResponse;
    }
" 2>/dev/null || echo "ERROR")

if [[ "$responseResult" == "SUCCESS" ]]; then
    printf "\t   MessagePayload buildP2pStatusResponse ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   MessagePayload buildP2pStatusResponse ${RED}FAILED${NC} (%s)\n" "${responseResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 3: P2P Status Response with various statuses
# ============================================================================
echo -e "\n[Test 3: P2P Status Response with Various Statuses]"
totaltests=$(( totaltests + 1 ))

statusesResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$currentUser = UserContext::getInstance();

    require_once('/etc/eiou/src/schemas/payloads/MessagePayload.php');
    \$messagePayload = new MessagePayload(\$currentUser, \$utilContainer);

    \$testHash = 'test-hash-' . time();
    \$testAddress = 'http://test.local:8080';
    \$statuses = ['completed', 'expired', 'sent', 'not_found'];
    \$allValid = true;

    foreach (\$statuses as \$status) {
        \$jsonResponse = \$messagePayload->buildP2pStatusResponse(\$testAddress, \$testHash, \$status);
        \$response = json_decode(\$jsonResponse, true);

        if (\$response['status'] !== \$status) {
            \$allValid = false;
            break;
        }
    }

    echo \$allValid ? 'SUCCESS' : 'FAILED';
" 2>/dev/null || echo "ERROR")

if [[ "$statusesResult" == "SUCCESS" ]]; then
    printf "\t   P2P status response with various statuses ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2P status response with various statuses ${RED}FAILED${NC} (%s)\n" "${statusesResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 4: CleanupService detects local completed transaction
# ============================================================================
echo -e "\n[Test 4: CleanupService Local Completion Detection]"

localCompleteHash="local-complete-hash-$(date +%s)-$(shuf -i 1000-9999 -n 1)"
echo -e "\t   Testing local completion detection with hash: ${localCompleteHash:0:25}..."

totaltests=$(( totaltests + 1 ))

localCheckResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$p2pRepo = \$app->services->getP2pRepository();
    \$txRepo = \$app->services->getTransactionRepository();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$timeUtility = \$utilContainer->getTimeUtility();

    // Create P2P record (expired)
    \$pastExpiration = \$timeUtility->getCurrentMicrotime() - 1000000;
    \$testData = [
        'hash' => '${localCompleteHash}',
        'salt' => 'test-salt-local',
        'time' => \$pastExpiration - 1000000,
        'expiration' => \$pastExpiration,
        'currency' => 'USD',
        'amount' => 700,
        'requestLevel' => 1,
        'maxRequestLevel' => 5,
        'senderPublicKey' => UserContext::getInstance()->getPublicKey(),
        'senderAddress' => 'http://test-sender.local',
        'signature' => 'test-signature'
    ];
    \$p2pRepo->insertP2pRequest(\$testData, null);
    \$p2pRepo->updateStatus('${localCompleteHash}', 'sent');

    // Create a completed transaction with matching memo
    \$txData = [
        'senderAddress' => 'http://test-sender.local',
        'senderPublicKey' => 'test-pubkey-sender',
        'receiverAddress' => UserContext::getInstance()->getUserAddresses()['http'] ?? 'http://receiver.local',
        'receiverPublicKey' => UserContext::getInstance()->getPublicKey(),
        'amount' => 700,
        'currency' => 'USD',
        'txid' => 'tx-' . uniqid(),
        'previousTxid' => null,
        'memo' => '${localCompleteHash}',
        'status' => 'completed'
    ];
    \$txRepo->insertTransaction(\$txData, 'received');
    \$txRepo->updateStatus('${localCompleteHash}', 'completed');

    // Now call expireMessage - should detect local completed transaction
    \$cleanupService = \$app->services->getCleanupService();
    \$p2p = \$p2pRepo->getByHash('${localCompleteHash}');
    \$cleanupService->expireMessage(\$p2p);

    // Check P2P status - should be completed, not expired
    \$updatedP2p = \$p2pRepo->getByHash('${localCompleteHash}');

    if (\$updatedP2p['status'] === 'completed') {
        echo 'SUCCESS';
    } else {
        echo 'FAILED: Status is ' . \$updatedP2p['status'] . ', expected completed';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$localCheckResult" == "SUCCESS" ]]; then
    printf "\t   Local transaction completion detected ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Local completion detection ${RED}FAILED${NC} (%s)\n" "${localCheckResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 5: CleanupService expires when no local completion
# ============================================================================
echo -e "\n[Test 5: CleanupService Expires When No Local Completion]"

noLocalHash="no-local-hash-$(date +%s)-$(shuf -i 1000-9999 -n 1)"
echo -e "\t   Testing expiration without local completion..."

totaltests=$(( totaltests + 1 ))

# Note: This test creates a P2P with a non-existent sender address
# so the sender inquiry will fail, and P2P should be expired
expirationResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$p2pRepo = \$app->services->getP2pRepository();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$timeUtility = \$utilContainer->getTimeUtility();

    // Create P2P record (expired) with non-existent sender
    \$pastExpiration = \$timeUtility->getCurrentMicrotime() - 1000000;
    \$testData = [
        'hash' => '${noLocalHash}',
        'salt' => 'test-salt-nolocal',
        'time' => \$pastExpiration - 1000000,
        'expiration' => \$pastExpiration,
        'currency' => 'USD',
        'amount' => 500,
        'requestLevel' => 1,
        'maxRequestLevel' => 5,
        'senderPublicKey' => UserContext::getInstance()->getPublicKey(),
        'senderAddress' => 'http://nonexistent-sender.invalid:9999',
        'signature' => 'test-signature'
    ];
    \$p2pRepo->insertP2pRequest(\$testData, null);
    \$p2pRepo->updateStatus('${noLocalHash}', 'sent');

    // Call expireMessage - should expire since no local transaction and sender unreachable
    \$cleanupService = \$app->services->getCleanupService();
    \$p2p = \$p2pRepo->getByHash('${noLocalHash}');
    \$cleanupService->expireMessage(\$p2p);

    // Check P2P status - should be expired
    \$updatedP2p = \$p2pRepo->getByHash('${noLocalHash}');

    if (\$updatedP2p['status'] === 'expired') {
        echo 'SUCCESS';
    } else {
        echo 'FAILED: Status is ' . \$updatedP2p['status'] . ', expected expired';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$expirationResult" == "SUCCESS" ]]; then
    printf "\t   CleanupService properly expired P2P ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   CleanupService expiration ${RED}FAILED${NC} (%s)\n" "${expirationResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 6: P2P Repository isCompletedByHash method
# ============================================================================
echo -e "\n[Test 6: P2P Repository isCompletedByHash Method]"
totaltests=$(( totaltests + 1 ))

isCompletedHash="is-completed-hash-$(date +%s)-$(shuf -i 1000-9999 -n 1)"

isCompletedResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$p2pRepo = \$app->services->getP2pRepository();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$timeUtility = \$utilContainer->getTimeUtility();

    // Create P2P record
    \$testData = [
        'hash' => '${isCompletedHash}',
        'salt' => 'test-salt-iscompleted',
        'time' => \$timeUtility->getCurrentMicrotime(),
        'expiration' => \$timeUtility->getCurrentMicrotime() + 3600000000,
        'currency' => 'USD',
        'amount' => 100,
        'requestLevel' => 1,
        'maxRequestLevel' => 5,
        'senderPublicKey' => UserContext::getInstance()->getPublicKey(),
        'senderAddress' => 'http://test.local',
        'signature' => 'test-signature'
    ];
    \$p2pRepo->insertP2pRequest(\$testData, null);

    // Initially should not be completed
    \$notCompleted = !\$p2pRepo->isCompletedByHash('${isCompletedHash}');

    // Mark as completed
    \$p2pRepo->updateStatus('${isCompletedHash}', 'completed', true);

    // Now should be completed
    \$isCompleted = \$p2pRepo->isCompletedByHash('${isCompletedHash}');

    if (\$notCompleted && \$isCompleted) {
        echo 'SUCCESS';
    } else {
        echo 'FAILED: notCompleted=' . (\$notCompleted ? 'true' : 'false') . ', isCompleted=' . (\$isCompleted ? 'true' : 'false');
    }
" 2>/dev/null || echo "ERROR")

if [[ "$isCompletedResult" == "SUCCESS" ]]; then
    printf "\t   P2P Repository isCompletedByHash ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   P2P Repository isCompletedByHash ${RED}FAILED${NC} (%s)\n" "${isCompletedResult}"
    failure=$(( failure + 1 ))
fi

# ============================================================================
# Test 7: CleanupService constructor accepts BalanceRepository
# ============================================================================
echo -e "\n[Test 7: CleanupService Constructor with BalanceRepository]"
totaltests=$(( totaltests + 1 ))

constructorResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();

    // Get CleanupService - should work with BalanceRepository
    try {
        \$cleanupService = \$app->services->getCleanupService();
        echo \$cleanupService !== null ? 'SUCCESS' : 'FAILED: null service';
    } catch (Exception \$e) {
        echo 'FAILED: ' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$constructorResult" == "SUCCESS" ]]; then
    printf "\t   CleanupService constructor with BalanceRepository ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   CleanupService constructor ${RED}FAILED${NC} (%s)\n" "${constructorResult}"
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'P2P completion sync'"
