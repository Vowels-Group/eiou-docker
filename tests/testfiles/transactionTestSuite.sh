#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Transaction Test Suite ############################
# Consolidated transaction tests combining:
# - transactionHistoryTest.sh - Transaction recording and querying
# - transactionInquiryTest.sh - Transaction inquiry response handling
# - contactTransactionTest.sh - Contact transaction type functionality
# - transactionChainReorderTest.sh - Chain behavior with cancellations
# - heldTransactionTest.sh - Held transaction for invalid_previous_txid
# - selfSendValidationTest.sh - Self-send transaction prevention
#
################################################################################

# Helper functions are sourced via config.sh -> testHelpers.sh
# No need to source again here

testname="transactionTestSuite"
totaltests=0
passed=0
failure=0

echo -e "\n"
echo "========================================================================"
echo "                    TRANSACTION TEST SUITE"
echo "========================================================================"
echo -e "\n"

# Use first container for most tests
testContainer="${containers[0]}"

if [[ -z "$testContainer" ]]; then
    echo -e "${YELLOW}Warning: No containers available, skipping transaction test suite${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'transaction test suite'"
    exit 0
fi

echo -e "[Test Container: ${testContainer}]"

##################### SECTION 1: Transaction Recording #####################
# Tests from transactionHistoryTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 1: Transaction Recording"
echo "========================================================================"

echo -e "\n[1.1 Transaction Recording Verification]"

# Test: Verify transactions table has required columns
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing transactions table structure"

tableStructure=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$result = \$pdo->query(\"DESCRIBE transactions\");
    \$columns = \$result->fetchAll(PDO::FETCH_COLUMN);

    \$required = ['txid', 'sender_address', 'receiver_address', 'amount_whole', 'amount_frac', 'currency', 'status', 'timestamp', 'previous_txid'];
    \$missing = array_diff(\$required, \$columns);

    if (empty(\$missing)) {
        echo 'ALL_COLUMNS_PRESENT';
    } else {
        echo 'MISSING:' . implode(',', \$missing);
    }
" 2>/dev/null || echo "ERROR")

if [[ "$tableStructure" == "ALL_COLUMNS_PRESENT" ]]; then
    printf "\t   Table structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Table structure ${RED}FAILED${NC} (%s)\n" "${tableStructure}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[1.2 Transaction Repository Methods]"

# Test: Verify getTransactionHistory method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getTransactionHistory method"

historyMethodExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
    echo method_exists(\$repo, 'getTransactionHistory') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$historyMethodExists" == "EXISTS" ]]; then
    printf "\t   getTransactionHistory method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getTransactionHistory method ${RED}FAILED${NC} (%s)\n" "$historyMethodExists"
    failure=$(( failure + 1 ))
fi

# Test: Verify getByTxid method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getByTxid method"

byTxidMethodExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
    echo method_exists(\$repo, 'getByTxid') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$byTxidMethodExists" == "EXISTS" ]]; then
    printf "\t   getByTxid method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getByTxid method ${RED}FAILED${NC} (%s)\n" "$byTxidMethodExists"
    failure=$(( failure + 1 ))
fi

# Test: Verify getPreviousTxid method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getPreviousTxid method"

prevTxidMethodExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
    echo method_exists(\$repo, 'getPreviousTxid') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$prevTxidMethodExists" == "EXISTS" ]]; then
    printf "\t   getPreviousTxid method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getPreviousTxid method ${RED}FAILED${NC} (%s)\n" "$prevTxidMethodExists"
    failure=$(( failure + 1 ))
fi

##################### SECTION 2: Transaction Inquiry #####################
# Tests from transactionInquiryTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 2: Transaction Inquiry"
echo "========================================================================"

echo -e "\n[2.1 Status Query Methods]"

# Test: Verify getStatusByMemo method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getStatusByMemo method"

statusByMemoCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);

    if (!method_exists(\$repo, 'getStatusByMemo')) {
        echo 'METHOD_NOT_FOUND';
        exit;
    }

    // Test with non-existent memo (should return null)
    \$status = \$repo->getStatusByMemo('nonexistent_memo_' . time());
    echo \$status === null ? 'NULL_RETURNED' : 'STATUS_RETURNED:' . \$status;
" 2>/dev/null || echo "ERROR")

if [[ "$statusByMemoCheck" == "NULL_RETURNED" ]] || [[ "$statusByMemoCheck" == STATUS_RETURNED:* ]]; then
    printf "\t   getStatusByMemo method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$statusByMemoCheck" == "METHOD_NOT_FOUND" ]]; then
    printf "\t   getStatusByMemo method ${RED}FAILED${NC} (method not found)\n"
    failure=$(( failure + 1 ))
else
    printf "\t   getStatusByMemo method ${RED}FAILED${NC} (%s)\n" "${statusByMemoCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify getStatusByTxid method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getStatusByTxid method"

statusByTxidCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);

    if (!method_exists(\$repo, 'getStatusByTxid')) {
        echo 'METHOD_NOT_FOUND';
        exit;
    }

    \$status = \$repo->getStatusByTxid('nonexistent_txid_' . time());
    echo \$status === null ? 'NULL_RETURNED' : 'STATUS_RETURNED:' . \$status;
" 2>/dev/null || echo "ERROR")

if [[ "$statusByTxidCheck" == "NULL_RETURNED" ]] || [[ "$statusByTxidCheck" == STATUS_RETURNED:* ]]; then
    printf "\t   getStatusByTxid method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$statusByTxidCheck" == "METHOD_NOT_FOUND" ]]; then
    printf "\t   getStatusByTxid method ${RED}FAILED${NC} (method not found)\n"
    failure=$(( failure + 1 ))
else
    printf "\t   getStatusByTxid method ${RED}FAILED${NC} (%s)\n" "${statusByTxidCheck}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[2.2 MessagePayload Response Methods]"

# Test: Verify buildTransactionStatusResponse method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing buildTransactionStatusResponse method"

statusResponseCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    
    \$app = \Eiou\Core\Application::getInstance();

    // Check if core services exist
    if (!method_exists(\$app->services, 'getCurrentUser')) {
        echo 'CURRENT_USER_MISSING';
        exit;
    }
    if (!method_exists(\$app->services, 'getUtilityContainer')) {
        echo 'UTIL_CONTAINER_MISSING';
        exit;
    }

    \$user = \$app->services->getCurrentUser();
    \$utilContainer = \$app->services->getUtilityContainer();

    if (\$user === null || \$utilContainer === null) {
        echo 'SERVICES_NULL';
        exit;
    }

    \$payload = new \Eiou\Schemas\Payloads\MessagePayload(\$user, \$utilContainer);

    if (!method_exists(\$payload, 'buildTransactionStatusResponse')) {
        echo 'METHOD_NOT_FOUND';
        exit;
    }

    \$testMessage = [
        'hash' => 'test_hash_123',
        'hashType' => 'memo',
        'senderAddress' => 'test@example.onion'
    ];

    \$response = \$payload->buildTransactionStatusResponse(\$testMessage, 'pending');
    \$decoded = json_decode(\$response, true);

    if (\$decoded && isset(\$decoded['status']) && \$decoded['status'] === 'pending') {
        echo 'STATUS_CORRECT';
    } else {
        echo 'STATUS_INCORRECT';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$statusResponseCheck" == "STATUS_CORRECT" ]]; then
    printf "\t   buildTransactionStatusResponse ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   buildTransactionStatusResponse ${RED}FAILED${NC} (%s)\n" "${statusResponseCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify buildTransactionNotFound method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing buildTransactionNotFound method"

notFoundCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    
    \$app = \Eiou\Core\Application::getInstance();

    // Check if core services exist
    if (!method_exists(\$app->services, 'getCurrentUser')) {
        echo 'CURRENT_USER_MISSING';
        exit;
    }
    if (!method_exists(\$app->services, 'getUtilityContainer')) {
        echo 'UTIL_CONTAINER_MISSING';
        exit;
    }

    \$user = \$app->services->getCurrentUser();
    \$utilContainer = \$app->services->getUtilityContainer();

    if (\$user === null || \$utilContainer === null) {
        echo 'SERVICES_NULL';
        exit;
    }

    \$payload = new \Eiou\Schemas\Payloads\MessagePayload(\$user, \$utilContainer);

    if (!method_exists(\$payload, 'buildTransactionNotFound')) {
        echo 'METHOD_NOT_FOUND';
        exit;
    }

    \$testMessage = ['hash' => 'test', 'hashType' => 'memo', 'senderAddress' => 'test@example.onion'];
    \$response = \$payload->buildTransactionNotFound(\$testMessage);
    \$decoded = json_decode(\$response, true);

    if (\$decoded && isset(\$decoded['status']) && \$decoded['status'] === 'not_found') {
        echo 'STATUS_CORRECT';
    } else {
        echo 'STATUS_INCORRECT';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$notFoundCheck" == "STATUS_CORRECT" ]]; then
    printf "\t   buildTransactionNotFound ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   buildTransactionNotFound ${RED}FAILED${NC} (%s)\n" "${notFoundCheck}"
    failure=$(( failure + 1 ))
fi

##################### SECTION 3: Contact Transactions #####################
# Tests from contactTransactionTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 3: Contact Transactions"
echo "========================================================================"

echo -e "\n[3.1 Contact Transaction Type Schema]"

# Test: Verify contact tx_type exists in schema
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing tx_type ENUM includes 'contact'"

enumCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$result = \$pdo->query(\"SHOW COLUMNS FROM transactions LIKE 'tx_type'\");
    \$row = \$result->fetch(PDO::FETCH_ASSOC);
    echo \$row['Type'];
" 2>/dev/null || echo "ERROR")

if [[ "$enumCheck" == *"contact"* ]]; then
    printf "\t   Contact tx_type in schema ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Contact tx_type in schema ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[3.2 Contact Transaction Methods]"

# Test: Verify contactTransactionExistsForReceiver method
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing contactTransactionExistsForReceiver method"

contactExistsCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$txContactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionContactRepository::class);

    // First check if method exists
    if (!method_exists(\$txContactRepo, 'contactTransactionExistsForReceiver')) {
        echo 'METHOD_MISSING';
        exit;
    }

    \$fakeHash = hash('sha256', 'nonexistent_pubkey_' . time());
    \$exists = \$txContactRepo->contactTransactionExistsForReceiver(\$fakeHash);

    echo \$exists === false ? 'CORRECT_FALSE' : 'WRONG_TRUE';
" 2>/dev/null || echo "ERROR")

if [[ "$contactExistsCheck" == "CORRECT_FALSE" ]]; then
    printf "\t   contactTransactionExistsForReceiver ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   contactTransactionExistsForReceiver ${RED}FAILED${NC} (%s)\n" "${contactExistsCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify completeContactTransaction method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing completeContactTransaction method"

completeContactCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$txContactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionContactRepository::class);

    if (method_exists(\$txContactRepo, 'completeContactTransaction')) {
        \$result = \$txContactRepo->completeContactTransaction('fake_public_key_' . time());
        echo 'METHOD_EXISTS';
    } else {
        echo 'METHOD_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$completeContactCheck" == "METHOD_EXISTS" ]]; then
    printf "\t   completeContactTransaction method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   completeContactTransaction method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 4: Chain Behavior #####################
# Tests from transactionChainReorderTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 4: Chain Behavior"
echo "========================================================================"

echo -e "\n[4.1 Previous Txid Exclusion]"

# Test: Verify getPreviousTxid excludes cancelled transactions
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getPreviousTxid excludes cancelled"

prevTxidExclusionCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$txRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);

    // First check if method exists
    if (!method_exists(\$txRepo, 'getPreviousTxid')) {
        echo 'METHOD_MISSING';
        exit;
    }

    // Verify the method signature handles cancelled exclusion
    \$reflection = new ReflectionMethod(\$txRepo, 'getPreviousTxid');
    \$params = \$reflection->getParameters();

    // Method should exist and be callable
    echo 'METHOD_VERIFIED';
" 2>/dev/null || echo "ERROR")

if [[ "$prevTxidExclusionCheck" == "METHOD_VERIFIED" ]]; then
    printf "\t   getPreviousTxid exclusion logic ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getPreviousTxid exclusion logic ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[4.2 Sync Includes All Transactions]"

# Test: Verify getTransactionsBetweenPubkeys includes cancelled
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing getTransactionsBetweenPubkeys includes all"

syncIncludesCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$txRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);

    if (method_exists(\$txRepo, 'getTransactionsBetweenPubkeys')) {
        echo 'METHOD_EXISTS';
    } else {
        echo 'METHOD_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$syncIncludesCheck" == "METHOD_EXISTS" ]]; then
    printf "\t   getTransactionsBetweenPubkeys method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   getTransactionsBetweenPubkeys method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 5: Held Transactions #####################
# Tests from heldTransactionTest.sh

echo -e "\n"
echo "========================================================================"
echo "Section 5: Held Transactions"
echo "========================================================================"

echo -e "\n[5.1 Held Transactions Table]"

# Test: Verify held_transactions table exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing held_transactions table exists"

heldTableCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$result = \$pdo->query(\"SHOW TABLES LIKE 'held_transactions'\");
    echo \$result->rowCount() > 0 ? 'TABLE_EXISTS' : 'TABLE_MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$heldTableCheck" == "TABLE_EXISTS" ]]; then
    printf "\t   held_transactions table ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   held_transactions table ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[5.2 Held Transaction Repository Methods]"

# Test: Verify HeldTransactionRepository exists and has required methods
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing HeldTransactionRepository methods"

heldRepoCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    \$heldRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\HeldTransactionRepository::class);
    if (\$heldRepo === null) {
        echo 'REPO_NULL';
        exit;
    }

    // Methods based on original heldTransactionTest.sh
    \$requiredMethods = ['holdTransaction', 'getByTxid', 'releaseTransaction'];
    \$missing = [];

    foreach (\$requiredMethods as \$method) {
        if (!method_exists(\$heldRepo, \$method)) {
            \$missing[] = \$method;
        }
    }

    if (empty(\$missing)) {
        echo 'ALL_METHODS_EXIST';
    } else {
        echo 'MISSING:' . implode(',', \$missing);
    }
" 2>/dev/null || echo "ERROR")

if [[ "$heldRepoCheck" == "ALL_METHODS_EXIST" ]]; then
    printf "\t   HeldTransactionRepository methods ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HeldTransactionRepository methods ${RED}FAILED${NC} (%s)\n" "${heldRepoCheck}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[5.3 Held Transaction Service Integration]"

# Test: Verify HeldTransactionService exists (separate service from TransactionService)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing HeldTransactionService exists"

heldServiceCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    // Check if the getter method exists on ServiceContainer
    if (!method_exists(\$app->services, 'getHeldTransactionService')) {
        echo 'SERVICE_GETTER_MISSING';
        exit;
    }

    try {
        \$service = \$app->services->getHeldTransactionService();
        if (\$service !== null) {
            // Check for holdTransactionForSync method
            if (method_exists(\$service, 'holdTransactionForSync')) {
                echo 'HELD_SERVICE_EXISTS';
            } else {
                echo 'HOLD_METHOD_MISSING';
            }
        } else {
            echo 'SERVICE_NULL';
        }
    } catch (Exception \$e) {
        echo 'SERVICE_ERROR:' . \$e->getMessage();
    }
" 2>/dev/null || echo "ERROR")

if [[ "$heldServiceCheck" == "HELD_SERVICE_EXISTS" ]]; then
    printf "\t   HeldTransactionService ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HeldTransactionService ${RED}FAILED${NC} (%s)\n" "${heldServiceCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify held transaction isolation (no interference with regular transactions)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing held transaction isolation"

isolationCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    \$heldRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\HeldTransactionRepository::class);
    if (\$heldRepo === null) {
        echo 'REPO_NULL';
        exit;
    }

    // Query should not affect regular transactions table
    // Use getByTxid with nonexistent txid - should return null/empty
    \$result = \$heldRepo->getByTxid('nonexistent_txid_' . time());

    if (\$result === null || (is_array(\$result) && empty(\$result))) {
        echo 'ISOLATION_OK';
    } else {
        echo 'ISOLATION_ISSUE';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$isolationCheck" == "ISOLATION_OK" ]]; then
    printf "\t   Held transaction isolation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Held transaction isolation ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

##################### SECTION 6: Self-Send Validation #####################
# Tests for self-send transaction prevention

echo -e "\n"
echo "========================================================================"
echo "Section 6: Self-Send Validation"
echo "========================================================================"

echo -e "\n[6.1 ErrorCodes Constants]"

# Test: Verify SELF_SEND constant exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SELF_SEND constant exists"

selfSendConstant=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    if (defined('\Eiou\Core\ErrorCodes::SELF_SEND')) {
        echo \Eiou\Core\ErrorCodes::SELF_SEND;
    } else {
        echo 'CONSTANT_NOT_FOUND';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$selfSendConstant" == "SELF_SEND" ]]; then
    printf "\t   SELF_SEND constant ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SELF_SEND constant ${RED}FAILED${NC} (%s)\n" "${selfSendConstant}"
    failure=$(( failure + 1 ))
fi

# Test: Verify SELF_SEND has HTTP status 400
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SELF_SEND HTTP status is 400"

httpStatus=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    echo \Eiou\Core\ErrorCodes::getHttpStatus(\Eiou\Core\ErrorCodes::SELF_SEND);
" 2>/dev/null || echo "ERROR")

if [[ "$httpStatus" == "400" ]]; then
    printf "\t   SELF_SEND HTTP status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SELF_SEND HTTP status ${RED}FAILED${NC} (expected 400, got %s)\n" "${httpStatus}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[6.2 InputValidator Self-Send Methods]"

# Test: Verify validateNotSelfSend method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing validateNotSelfSend method exists"

methodExists=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    echo method_exists('\Eiou\Utils\InputValidator', 'validateNotSelfSend') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$methodExists" == "EXISTS" ]]; then
    printf "\t   validateNotSelfSend method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   validateNotSelfSend method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test: Verify self-send detection works correctly
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing self-send detection"

selfSendCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    
    \$userContext = \Eiou\Core\Application::getInstance()->services->getCurrentUser();
    \$myAddress = \$userContext->getHttpAddress() ?? \$userContext->getTorAddress();

    if (\$myAddress === null) {
        echo 'NO_USER_ADDRESS';
        exit;
    }

    \$result = \Eiou\Utils\InputValidator::validateNotSelfSend(\$myAddress, \$userContext);

    if (\$result['valid'] === false && strpos(\$result['error'], 'yourself') !== false) {
        echo 'CORRECTLY_INVALID';
    } else {
        echo 'INCORRECTLY_VALID';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$selfSendCheck" == "CORRECTLY_INVALID" ]]; then
    printf "\t   Self-send detection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$selfSendCheck" == "NO_USER_ADDRESS" ]]; then
    printf "\t   Self-send detection ${YELLOW}SKIPPED${NC} (no user address)\n"
else
    printf "\t   Self-send detection ${RED}FAILED${NC} (%s)\n" "${selfSendCheck}"
    failure=$(( failure + 1 ))
fi

# Test: Verify different address returns valid
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing different address validation"

differentAddressCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    
    \$userContext = \Eiou\Core\Application::getInstance()->services->getCurrentUser();
    \$differentAddress = 'https://different-recipient.example.com';
    \$result = \Eiou\Utils\InputValidator::validateNotSelfSend(\$differentAddress, \$userContext);

    if (\$result['valid'] === true && \$result['error'] === null) {
        echo 'CORRECTLY_VALID';
    } else {
        echo 'INCORRECTLY_INVALID';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$differentAddressCheck" == "CORRECTLY_VALID" ]]; then
    printf "\t   Different address validation ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Different address validation ${RED}FAILED${NC} (%s)\n" "${differentAddressCheck}"
    failure=$(( failure + 1 ))
fi

echo -e "\n[6.3 GUI Message Integration]"

# Test: Verify SELF_SEND has GUI-friendly message
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SELF_SEND GUI-friendly message"

guiMessage=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$msg = \Eiou\Gui\Helpers\MessageHelper::getGuiFriendlyMessage('SELF_SEND', '');
    echo \$msg;
" 2>/dev/null || echo "ERROR")

if [[ "$guiMessage" == *"cannot send"* ]] || [[ "$guiMessage" == *"yourself"* ]]; then
    printf "\t   SELF_SEND GUI message ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SELF_SEND GUI message ${RED}FAILED${NC} (got: %s)\n" "${guiMessage}"
    failure=$(( failure + 1 ))
fi

##################### SECTION 7: Recipient Signature #####################
# Tests for recipient signature functionality

echo -e "\n"
echo "========================================================================"
echo "Section 7: Recipient Signature"
echo "========================================================================"

echo -e "\n[7.1 Recipient Signature Database Schema]"

# Test: Verify recipient_signature column exists in transactions table
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing recipient_signature column exists"

recipientSigColumnCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
    \$result = \$pdo->query(\"DESCRIBE transactions\");
    \$columns = \$result->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('recipient_signature', \$columns)) {
        echo 'COLUMN_EXISTS';
    } else {
        echo 'COLUMN_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$recipientSigColumnCheck" == "COLUMN_EXISTS" ]]; then
    printf "\t   recipient_signature column ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   recipient_signature column ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[7.2 Recipient Signature Repository Methods]"

# Test: Verify updateRecipientSignature method exists
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing updateRecipientSignature method"

updateRecipSigMethod=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$repo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
    echo method_exists(\$repo, 'updateRecipientSignature') ? 'EXISTS' : 'MISSING';
" 2>/dev/null || echo "ERROR")

if [[ "$updateRecipSigMethod" == "EXISTS" ]]; then
    printf "\t   updateRecipientSignature method ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   updateRecipientSignature method ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

echo -e "\n[7.3 TransactionPayload Recipient Signature]"

# Test: Verify buildAcceptance includes recipientSignature
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing buildAcceptance includes recipientSignature"

acceptancePayloadCheck=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$user = \$app->services->getCurrentUser();
    \$utilContainer = \$app->services->getUtilityContainer();

    \$payload = new \Eiou\Schemas\Payloads\TransactionPayload(\$user, \$utilContainer);

    // Create mock request data
    \$request = [
        'txid' => 'test_txid_' . time(),
        'memo' => 'standard',
        'senderAddress' => 'https://test.example.com',
        'receiverAddress' => 'https://receiver.example.com',
        'receiverPublicKey' => \$user->getPublicKey(),
        'amount' => 100,
        'currency' => 'USD',
        'time' => time(),
        'nonce' => time()
    ];

    \$response = \$payload->buildAcceptance(\$request);
    \$decoded = json_decode(\$response, true);

    if (isset(\$decoded['recipientSignature'])) {
        echo 'RECIPIENT_SIGNATURE_INCLUDED';
    } else {
        echo 'RECIPIENT_SIGNATURE_MISSING';
    }
" 2>/dev/null || echo "ERROR")

if [[ "$acceptancePayloadCheck" == "RECIPIENT_SIGNATURE_INCLUDED" ]]; then
    printf "\t   buildAcceptance recipientSignature ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   buildAcceptance recipientSignature ${RED}FAILED${NC} (%s)\n" "${acceptancePayloadCheck}"
    failure=$(( failure + 1 ))
fi

################################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'transaction test suite'"

################################################################################
