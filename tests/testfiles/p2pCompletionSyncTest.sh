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

# ============================================================================
# Test 8: Integration test - P2P completion sync between real containers
# ============================================================================
echo -e "\n[Test 8: Integration - P2P Completion Sync Between Containers]"

# Use first two connected containers for testing
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
testPair="${containersLinkKeys[0]}"

if [[ -z "$testPair" ]]; then
    echo -e "${YELLOW}Warning: No container links defined, skipping integration test${NC}"
else
    containerKeys=(${testPair//,/ })
    contactA="${containerKeys[0]}"
    contactB="${containerKeys[1]}"

    # Get addresses from containerAddresses
    contactAAddress="${containerAddresses[${contactA}]}"
    contactBAddress="${containerAddresses[${contactB}]}"

    echo -e "\t   Contact A (sender): ${contactA} (${contactAAddress})"
    echo -e "\t   Contact B (receiver): ${contactB} (${contactBAddress})"

    if [[ -z "$contactAAddress" ]] || [[ -z "$contactBAddress" ]]; then
        echo -e "${YELLOW}Warning: Container addresses not populated, skipping integration test${NC}"
    else
        totaltests=$(( totaltests + 1 ))

        # Ensure contacts exist between containers
        echo -e "\t   Ensuring contacts exist between containers..."
        docker exec ${contactA} eiou add ${contactBAddress} ${contactB} 0 0 USD 2>&1 || true
        docker exec ${contactB} eiou add ${contactAAddress} ${contactA} 0 0 USD 2>&1 || true
        sleep 2

        integrationHash="integration-sync-hash-$(date +%s)-$(shuf -i 1000-9999 -n 1)"
        echo -e "\t   Test hash: ${integrationHash:0:30}..."

        # Step 1: Create completed P2P on Contact B (receiver)
        echo -e "\t   Creating completed P2P on Contact B..."
        createOnBResult=$(docker exec ${contactB} php -r "
            require_once('${REL_APPLICATION}');

            \$app = Application::getInstance();
            \$p2pRepo = \$app->services->getP2pRepository();
            \$utilContainer = \$app->services->getUtilityContainer();
            \$timeUtility = \$utilContainer->getTimeUtility();

            // Get Contact A's public key from contact repository
            \$contactRepo = \$app->services->getContactRepository();
            \$contactAPubkey = \$contactRepo->getContactPubkey('${MODE}', '${contactAAddress}');

            if (!\$contactAPubkey) {
                echo 'ERROR: Could not get Contact A pubkey';
                exit;
            }

            // Create P2P record marked as completed on Contact B
            \$currentTime = \$timeUtility->getCurrentMicrotime();
            \$testData = [
                'hash' => '${integrationHash}',
                'salt' => 'integration-test-salt',
                'time' => \$currentTime - 10000000,
                'expiration' => \$currentTime + 3600000000,
                'currency' => 'USD',
                'amount' => 1000,
                'requestLevel' => 1,
                'maxRequestLevel' => 5,
                'senderPublicKey' => \$contactAPubkey,
                'senderAddress' => '${contactAAddress}',
                'signature' => 'test-signature-integration'
            ];
            \$p2pRepo->insertP2pRequest(\$testData, null);
            \$p2pRepo->updateStatus('${integrationHash}', 'completed', true);

            \$p2p = \$p2pRepo->getByHash('${integrationHash}');
            echo \$p2p['status'] === 'completed' ? 'SUCCESS' : 'FAILED: ' . \$p2p['status'];
        " 2>/dev/null || echo "ERROR")

        if [[ "$createOnBResult" != "SUCCESS" ]]; then
            printf "\t   Create P2P on Contact B ${RED}FAILED${NC} (%s)\n" "${createOnBResult}"
            failure=$(( failure + 1 ))
        else
            echo -e "\t   P2P created on Contact B with status: completed"

            # Step 2: Create same P2P on Contact A with status 'sent' and 5-second expiration
            # Note: Using 'sent' status as it's a valid enum value representing a P2P waiting for completion
            echo -e "\t   Creating P2P on Contact A with 5-second expiration..."
            createOnAResult=$(docker exec ${contactA} php -r "
                require_once('${REL_APPLICATION}');

                \$app = Application::getInstance();
                \$p2pRepo = \$app->services->getP2pRepository();
                \$utilContainer = \$app->services->getUtilityContainer();
                \$timeUtility = \$utilContainer->getTimeUtility();

                // Get Contact B's public key
                \$contactRepo = \$app->services->getContactRepository();
                \$contactBPubkey = \$contactRepo->getContactPubkey('${MODE}', '${contactBAddress}');

                if (!\$contactBPubkey) {
                    echo 'ERROR: Could not get Contact B pubkey';
                    exit;
                }

                // Create P2P record with 5-second expiration
                \$currentTime = \$timeUtility->getCurrentMicrotime();
                \$expirationTime = \$currentTime + 5000000; // 5 seconds in microseconds

                \$testData = [
                    'hash' => '${integrationHash}',
                    'salt' => 'integration-test-salt',
                    'time' => \$currentTime - 10000000,
                    'expiration' => \$expirationTime,
                    'currency' => 'USD',
                    'amount' => 1000,
                    'requestLevel' => 1,
                    'maxRequestLevel' => 5,
                    'senderPublicKey' => UserContext::getInstance()->getPublicKey(),
                    'senderAddress' => '${contactBAddress}',
                    'signature' => 'test-signature-integration'
                ];
                \$p2pRepo->insertP2pRequest(\$testData, null);
                \$p2pRepo->updateStatus('${integrationHash}', 'sent');

                \$p2p = \$p2pRepo->getByHash('${integrationHash}');
                echo \$p2p['status'] === 'sent' ? 'SUCCESS' : 'FAILED: ' . \$p2p['status'];
            " 2>/dev/null || echo "ERROR")

            if [[ "$createOnAResult" != "SUCCESS" ]]; then
                printf "\t   Create P2P on Contact A ${RED}FAILED${NC} (%s)\n" "${createOnAResult}"
                failure=$(( failure + 1 ))
            else
                echo -e "\t   P2P created on Contact A with status: sent"
                echo -e "\t   Waiting 6 seconds for P2P to expire..."
                sleep 6

                # Step 3: Trigger cleanup/expiration processing on Contact A
                echo -e "\t   Triggering expiration processing on Contact A..."
                syncResult=$(docker exec ${contactA} php -r "
                    require_once('${REL_APPLICATION}');

                    \$app = Application::getInstance();
                    \$p2pRepo = \$app->services->getP2pRepository();
                    \$cleanupService = \$app->services->getCleanupService();

                    // Get the P2P and process expiration
                    \$p2p = \$p2pRepo->getByHash('${integrationHash}');

                    if (!\$p2p) {
                        echo 'ERROR: P2P not found';
                        exit;
                    }

                    // Call expireMessage which should check with Contact B and sync status
                    \$cleanupService->expireMessage(\$p2p);

                    // Check the updated status
                    \$updatedP2p = \$p2pRepo->getByHash('${integrationHash}');

                    if (\$updatedP2p['status'] === 'completed') {
                        echo 'SUCCESS: P2P synced to completed status';
                    } else {
                        echo 'RESULT: Status is ' . \$updatedP2p['status'];
                    }
                " 2>/dev/null || echo "ERROR")

                if [[ "$syncResult" == SUCCESS:* ]]; then
                    printf "\t   Integration test - P2P completion sync ${GREEN}PASSED${NC}\n"
                    passed=$(( passed + 1 ))
                else
                    printf "\t   Integration test - P2P completion sync ${RED}FAILED${NC} (%s)\n" "${syncResult}"
                    failure=$(( failure + 1 ))
                fi
            fi
        fi

        # Cleanup: Remove test P2P records from both containers
        echo -e "\t   Cleaning up test P2P records..."
        docker exec ${contactA} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$pdo = \$app->services->getPdo();
            \$pdo->exec(\"DELETE FROM p2p WHERE hash = '${integrationHash}'\");
        " 2>/dev/null || true

        docker exec ${contactB} php -r "
            require_once('${REL_APPLICATION}');
            \$app = Application::getInstance();
            \$pdo = \$app->services->getPdo();
            \$pdo->exec(\"DELETE FROM p2p WHERE hash = '${integrationHash}'\");
        " 2>/dev/null || true
    fi
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'P2P completion sync'"
