#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Performance Baseline Test Suite ############################
# Performance benchmarking tests for the EIOU application
#
# Verifies:
# - Transaction processing time within acceptable limits
# - Batch transaction throughput
# - API endpoint response times
# - Database query performance
#
# Prerequisites:
# - Containers must be running
# - API must be accessible
# - API key must be available (will create one)
#
# Note: These tests establish performance baselines. Thresholds are intentionally
# generous to allow for variance across different environments (CI, local, WSL2).
# Adjust thresholds as needed for your environment.
##########################################################################

echo -e "\nRunning Performance Baseline Test Suite..."

testname="performanceBaseline"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

if [[ -z "$testContainer" ]]; then
    echo -e "${YELLOW}Warning: No containers available, skipping performance baseline tests${NC}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'performance baseline'"
    exit 0
fi

# Determine local API base URL based on MODE
LOCAL_API_BASE="$(getExpectedProtocol)localhost"
echo -e "\t   Test container: ${testContainer}"
echo -e "\t   API Base: ${LOCAL_API_BASE}"
echo -e "\t   Mode: ${MODE}"

# Set curl SSL flag for HTTPS mode
if [[ "$MODE" == "https" ]]; then
    CURL_SSL_FLAG="-k"
else
    CURL_SSL_FLAG=""
fi

############################ PERFORMANCE THRESHOLDS ############################
# All times in milliseconds
# These are baseline thresholds - adjust based on your environment

MAX_SINGLE_TX_TIME_MS=5000       # Max time for single transaction processing
MAX_BATCH_TX_TOTAL_TIME_MS=30000 # Max time for 10 transactions batch
MAX_API_RESPONSE_TIME_MS=2000    # Max time for API endpoint response
MAX_DB_QUERY_TIME_MS=1000        # Max time for simple database query
MAX_SIGNATURE_TIME_MS=500        # Max time for signature generation

echo -e "\n[Performance Thresholds]"
echo -e "\t   Single transaction: ${MAX_SINGLE_TX_TIME_MS}ms"
echo -e "\t   Batch (10 tx): ${MAX_BATCH_TX_TOTAL_TIME_MS}ms"
echo -e "\t   API response: ${MAX_API_RESPONSE_TIME_MS}ms"
echo -e "\t   Database query: ${MAX_DB_QUERY_TIME_MS}ms"
echo -e "\t   Signature generation: ${MAX_SIGNATURE_TIME_MS}ms"

############################ HELPER FUNCTIONS ############################

# Get current time in milliseconds
get_time_ms() {
    # Use date with nanoseconds and convert to milliseconds
    # Works on Linux/Docker containers
    echo $(($(date +%s%N)/1000000))
}

# Calculate elapsed time in milliseconds
# Usage: calc_elapsed_ms start_ms end_ms
calc_elapsed_ms() {
    local start=$1
    local end=$2
    echo $((end - start))
}

############################ API KEY SETUP ############################

echo -e "\n[Performance Test API Key Setup]"

# Create API key for performance testing
echo -e "\n\t-> Creating API key for performance testing"
apiKeyOutput=$(docker exec ${testContainer} eiou apikey create "PerformanceTestKey" "wallet:read,wallet:send,contacts:read,system:read" --json 2>&1)

apiKeyId=$(echo "$apiKeyOutput" | grep -o '"key_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"key_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
apiSecret=$(echo "$apiKeyOutput" | grep -o '"secret"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"secret"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

if [[ -z "$apiKeyId" ]] || [[ -z "$apiSecret" ]]; then
    echo -e "\t   ${RED}Failed to create API key for performance testing${NC}"
    echo -e "\t   Output: ${apiKeyOutput}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'performance baseline'"
    exit 1
fi

echo -e "\t   API Key ID: ${apiKeyId}"
echo -e "\t   ${GREEN}API key created successfully${NC}"

# Get a real contact address for transaction tests
realContactContainer="${containers[1]}"
realContactAddress="${containerAddresses[${realContactContainer}]}"

if [[ -z "$realContactAddress" ]]; then
    echo -e "\t   ${YELLOW}Warning: No contact address available. Some tests may be skipped.${NC}"
fi

############################ SECTION 1: DATABASE QUERY PERFORMANCE ############################

echo -e "\n[Section 1: Database Query Performance]"

# Test 1.1: Simple SELECT query time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing simple SELECT query performance"

queryResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$pdo = Application::getInstance()->services->getPdo();

    \$start = microtime(true);
    \$result = \$pdo->query('SELECT COUNT(*) as cnt FROM transactions');
    \$row = \$result->fetch(PDO::FETCH_ASSOC);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$queryResult" ]] && [[ $(echo "$queryResult < $MAX_DB_QUERY_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Simple SELECT query (${queryResult}ms < ${MAX_DB_QUERY_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Simple SELECT query ${RED}FAILED${NC} (${queryResult}ms >= ${MAX_DB_QUERY_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 1.2: Indexed query performance
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing indexed query performance"

indexedQueryResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$pdo = Application::getInstance()->services->getPdo();

    \$start = microtime(true);
    // Query using indexed column (txid is primary/unique)
    \$stmt = \$pdo->prepare('SELECT * FROM transactions WHERE txid = :txid');
    \$stmt->execute(['txid' => 'nonexistent_txid_benchmark']);
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$indexedQueryResult" ]] && [[ $(echo "$indexedQueryResult < $MAX_DB_QUERY_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Indexed query (${indexedQueryResult}ms < ${MAX_DB_QUERY_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Indexed query ${RED}FAILED${NC} (${indexedQueryResult}ms >= ${MAX_DB_QUERY_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 1.3: Join query performance
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing join query performance"

joinQueryResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$pdo = Application::getInstance()->services->getPdo();

    \$start = microtime(true);
    // Query with potential join (contacts table)
    \$result = \$pdo->query('SELECT COUNT(*) as cnt FROM contacts');
    \$row = \$result->fetch(PDO::FETCH_ASSOC);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$joinQueryResult" ]] && [[ $(echo "$joinQueryResult < $MAX_DB_QUERY_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Join/complex query (${joinQueryResult}ms < ${MAX_DB_QUERY_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Join/complex query ${RED}FAILED${NC} (${joinQueryResult}ms >= ${MAX_DB_QUERY_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 2: API ENDPOINT RESPONSE TIMES ############################

echo -e "\n[Section 2: API Endpoint Response Times]"

# Test 2.1: System status endpoint response time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing /api/v1/system/status response time"

timestamp=$(date +%s)
path="/api/v1/system/status"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

statusTimeResult=$(docker exec ${testContainer} php -r "
    \$start = microtime(true);

    \$ch = curl_init('${LOCAL_API_BASE}${path}');
    curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt(\$ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ${apiKeyId}',
        'X-API-Timestamp: ${timestamp}',
        'X-API-Signature: ${signature}',
        'Content-Type: application/json'
    ]);
    curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt(\$ch, CURLOPT_SSL_VERIFYHOST, false);

    \$response = curl_exec(\$ch);
    curl_close(\$ch);

    \$end = microtime(true);
    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$statusTimeResult" ]] && [[ $(echo "$statusTimeResult < $MAX_API_RESPONSE_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   /api/v1/system/status (${statusTimeResult}ms < ${MAX_API_RESPONSE_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   /api/v1/system/status ${RED}FAILED${NC} (${statusTimeResult}ms >= ${MAX_API_RESPONSE_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 2.2: Wallet balance endpoint response time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing /api/v1/wallet/balance response time"

timestamp=$(date +%s)
path="/api/v1/wallet/balance"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

balanceTimeResult=$(docker exec ${testContainer} php -r "
    \$start = microtime(true);

    \$ch = curl_init('${LOCAL_API_BASE}${path}');
    curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt(\$ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ${apiKeyId}',
        'X-API-Timestamp: ${timestamp}',
        'X-API-Signature: ${signature}',
        'Content-Type: application/json'
    ]);
    curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt(\$ch, CURLOPT_SSL_VERIFYHOST, false);

    \$response = curl_exec(\$ch);
    curl_close(\$ch);

    \$end = microtime(true);
    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$balanceTimeResult" ]] && [[ $(echo "$balanceTimeResult < $MAX_API_RESPONSE_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   /api/v1/wallet/balance (${balanceTimeResult}ms < ${MAX_API_RESPONSE_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   /api/v1/wallet/balance ${RED}FAILED${NC} (${balanceTimeResult}ms >= ${MAX_API_RESPONSE_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 2.3: Contacts list endpoint response time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing /api/v1/contacts response time"

timestamp=$(date +%s)
path="/api/v1/contacts"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

contactsTimeResult=$(docker exec ${testContainer} php -r "
    \$start = microtime(true);

    \$ch = curl_init('${LOCAL_API_BASE}${path}');
    curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt(\$ch, CURLOPT_HTTPHEADER, [
        'X-API-Key: ${apiKeyId}',
        'X-API-Timestamp: ${timestamp}',
        'X-API-Signature: ${signature}',
        'Content-Type: application/json'
    ]);
    curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt(\$ch, CURLOPT_SSL_VERIFYHOST, false);

    \$response = curl_exec(\$ch);
    curl_close(\$ch);

    \$end = microtime(true);
    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$contactsTimeResult" ]] && [[ $(echo "$contactsTimeResult < $MAX_API_RESPONSE_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   /api/v1/contacts (${contactsTimeResult}ms < ${MAX_API_RESPONSE_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   /api/v1/contacts ${RED}FAILED${NC} (${contactsTimeResult}ms >= ${MAX_API_RESPONSE_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 3: CRYPTOGRAPHIC OPERATIONS ############################

echo -e "\n[Section 3: Cryptographic Operations Performance]"

# Test 3.1: Signature generation time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing signature generation time"

signatureTimeResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$user = \$app->services->getCurrentUser();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$signer = \$utilContainer->getSigner();

    \$testData = 'This is test data for signature benchmark ' . time();

    \$start = microtime(true);
    \$signature = \$signer->sign(\$testData, \$user->getPrivateKey());
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$signatureTimeResult" ]] && [[ $(echo "$signatureTimeResult < $MAX_SIGNATURE_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Signature generation (${signatureTimeResult}ms < ${MAX_SIGNATURE_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Signature generation ${RED}FAILED${NC} (${signatureTimeResult}ms >= ${MAX_SIGNATURE_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 3.2: Signature verification time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing signature verification time"

verificationTimeResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$user = \$app->services->getCurrentUser();
    \$utilContainer = \$app->services->getUtilityContainer();
    \$signer = \$utilContainer->getSigner();

    \$testData = 'This is test data for verification benchmark ' . time();
    \$signature = \$signer->sign(\$testData, \$user->getPrivateKey());

    \$start = microtime(true);
    \$valid = \$signer->verify(\$testData, \$signature, \$user->getPublicKey());
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$verificationTimeResult" ]] && [[ $(echo "$verificationTimeResult < $MAX_SIGNATURE_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Signature verification (${verificationTimeResult}ms < ${MAX_SIGNATURE_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Signature verification ${RED}FAILED${NC} (${verificationTimeResult}ms >= ${MAX_SIGNATURE_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 3.3: HMAC generation time (for API authentication)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing HMAC generation time"

hmacTimeResult=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = 'GET\\n/api/v1/test\\n' . time() . '\\n';

    \$start = microtime(true);
    for (\$i = 0; \$i < 100; \$i++) {
        \$hmac = hash_hmac('sha256', \$message, \$secret);
    }
    \$end = microtime(true);

    // Time for 100 operations, divide by 100 for single operation
    \$elapsed_ms = round(((\$end - \$start) / 100) * 1000, 4);
    echo \$elapsed_ms;
" 2>/dev/null)

# HMAC should be very fast (< 1ms typically)
if [[ -n "$hmacTimeResult" ]] && [[ $(echo "$hmacTimeResult < 10" | bc -l) -eq 1 ]]; then
    printf "\t   HMAC generation (${hmacTimeResult}ms per op, < 10ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HMAC generation ${RED}FAILED${NC} (${hmacTimeResult}ms >= 10ms)\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 4: TRANSACTION PROCESSING ############################

echo -e "\n[Section 4: Transaction Processing Performance]"

# Skip transaction tests if no contact address available
if [[ -n "$realContactAddress" ]]; then

    # Test 4.1: Single transaction processing time (via CLI)
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing single transaction processing time"

    singleTxTimeResult=$(docker exec ${testContainer} php -r "
        \$start = microtime(true);

        // Execute CLI command
        \$output = shell_exec('eiou send ${realContactAddress} 0.01 USD --json 2>&1');

        \$end = microtime(true);
        \$elapsed_ms = round((\$end - \$start) * 1000, 2);

        // Check if transaction was successful
        \$result = json_decode(\$output, true);
        if (\$result && isset(\$result['success']) && \$result['success'] === true) {
            echo \$elapsed_ms;
        } else {
            // Return time but mark as error
            echo 'ERROR:' . \$elapsed_ms;
        }
    " 2>/dev/null)

    if [[ "$singleTxTimeResult" != ERROR:* ]] && [[ -n "$singleTxTimeResult" ]] && [[ $(echo "$singleTxTimeResult < $MAX_SINGLE_TX_TIME_MS" | bc -l) -eq 1 ]]; then
        printf "\t   Single transaction (${singleTxTimeResult}ms < ${MAX_SINGLE_TX_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$singleTxTimeResult" == ERROR:* ]]; then
        txTime=$(echo "$singleTxTimeResult" | cut -d: -f2)
        printf "\t   Single transaction ${RED}FAILED${NC} (transaction failed, took ${txTime}ms)\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   Single transaction ${RED}FAILED${NC} (${singleTxTimeResult}ms >= ${MAX_SINGLE_TX_TIME_MS}ms)\n"
        failure=$(( failure + 1 ))
    fi

    # Test 4.2: Batch transaction throughput (10 transactions)
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing batch transaction throughput (10 transactions)"

    batchTxTimeResult=$(docker exec ${testContainer} php -r "
        \$start = microtime(true);
        \$success_count = 0;

        for (\$i = 0; \$i < 10; \$i++) {
            \$output = shell_exec('eiou send ${realContactAddress} 0.01 USD --json 2>&1');
            \$result = json_decode(\$output, true);
            if (\$result && isset(\$result['success']) && \$result['success'] === true) {
                \$success_count++;
            }
            // Small delay to prevent rate limiting
            usleep(100000); // 100ms
        }

        \$end = microtime(true);
        \$elapsed_ms = round((\$end - \$start) * 1000, 2);

        echo \$success_count . ':' . \$elapsed_ms;
    " 2>/dev/null)

    batchSuccessCount=$(echo "$batchTxTimeResult" | cut -d: -f1)
    batchTime=$(echo "$batchTxTimeResult" | cut -d: -f2)

    if [[ "$batchSuccessCount" -ge 8 ]] && [[ $(echo "$batchTime < $MAX_BATCH_TX_TOTAL_TIME_MS" | bc -l) -eq 1 ]]; then
        printf "\t   Batch transactions (${batchSuccessCount}/10 succeeded, ${batchTime}ms < ${MAX_BATCH_TX_TOTAL_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Batch transactions ${RED}FAILED${NC} (${batchSuccessCount}/10 succeeded, ${batchTime}ms)\n"
        failure=$(( failure + 1 ))
    fi

else
    echo -e "\n\t   ${YELLOW}Skipping transaction processing tests - no contact address available${NC}"
fi

############################ SECTION 5: REPOSITORY OPERATIONS ############################

echo -e "\n[Section 5: Repository Operations Performance]"

# Test 5.1: Transaction history retrieval time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing transaction history retrieval time"

historyTimeResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$txRepo = \$app->services->getTransactionRepository();

    \$start = microtime(true);
    \$history = \$txRepo->getTransactionHistory(50, 0); // Get up to 50 transactions
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$historyTimeResult" ]] && [[ $(echo "$historyTimeResult < $MAX_DB_QUERY_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Transaction history retrieval (${historyTimeResult}ms < ${MAX_DB_QUERY_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transaction history retrieval ${RED}FAILED${NC} (${historyTimeResult}ms >= ${MAX_DB_QUERY_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 5.2: Contact search time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing contact search time"

contactSearchTimeResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$contactRepo = \$app->services->getContactRepository();

    \$start = microtime(true);
    \$contacts = \$contactRepo->searchContacts('test'); // Search for 'test'
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$contactSearchTimeResult" ]] && [[ $(echo "$contactSearchTimeResult < $MAX_DB_QUERY_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Contact search (${contactSearchTimeResult}ms < ${MAX_DB_QUERY_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Contact search ${RED}FAILED${NC} (${contactSearchTimeResult}ms >= ${MAX_DB_QUERY_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 5.3: Balance calculation time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing balance calculation time"

balanceCalcTimeResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');

    \$app = Application::getInstance();
    \$balanceService = \$app->services->getBalanceService();

    \$start = microtime(true);
    \$balances = \$balanceService->getBalances();
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$balanceCalcTimeResult" ]] && [[ $(echo "$balanceCalcTimeResult < $MAX_DB_QUERY_TIME_MS" | bc -l) -eq 1 ]]; then
    printf "\t   Balance calculation (${balanceCalcTimeResult}ms < ${MAX_DB_QUERY_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Balance calculation ${RED}FAILED${NC} (${balanceCalcTimeResult}ms >= ${MAX_DB_QUERY_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 6: SERVICE INITIALIZATION ############################

echo -e "\n[Section 6: Service Initialization Performance]"

# Test 6.1: Application getInstance time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing Application::getInstance() time"

appInitTimeResult=$(docker exec ${testContainer} php -r "
    \$start = microtime(true);
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

# Application init can take longer, use 2x the DB query threshold
APP_INIT_THRESHOLD=$((MAX_DB_QUERY_TIME_MS * 2))
if [[ -n "$appInitTimeResult" ]] && [[ $(echo "$appInitTimeResult < $APP_INIT_THRESHOLD" | bc -l) -eq 1 ]]; then
    printf "\t   Application init (${appInitTimeResult}ms < ${APP_INIT_THRESHOLD}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Application init ${RED}FAILED${NC} (${appInitTimeResult}ms >= ${APP_INIT_THRESHOLD}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 6.2: Service Container access time (subsequent calls)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing ServiceContainer repeated access time"

serviceAccessTimeResult=$(docker exec ${testContainer} php -r "
    require_once('${REL_APPLICATION}');
    \$app = Application::getInstance();

    // Warm up
    \$_ = \$app->services->getPdo();

    \$start = microtime(true);
    for (\$i = 0; \$i < 100; \$i++) {
        \$pdo = \$app->services->getPdo();
        \$txRepo = \$app->services->getTransactionRepository();
        \$contactRepo = \$app->services->getContactRepository();
    }
    \$end = microtime(true);

    // Time for 100 iterations, divide for single access
    \$elapsed_ms = round(((\$end - \$start) / 100) * 1000, 4);
    echo \$elapsed_ms;
" 2>/dev/null)

# Service access should be very fast (singleton pattern)
if [[ -n "$serviceAccessTimeResult" ]] && [[ $(echo "$serviceAccessTimeResult < 5" | bc -l) -eq 1 ]]; then
    printf "\t   Service container access (${serviceAccessTimeResult}ms per access, < 5ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Service container access ${RED}FAILED${NC} (${serviceAccessTimeResult}ms >= 5ms per access)\n"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup]"
echo -e "\t-> Deleting performance test API key"

docker exec ${testContainer} eiou apikey delete ${apiKeyId} --confirm 2>/dev/null

############################ PERFORMANCE SUMMARY ############################

echo -e "\n[Performance Summary]"
echo -e "\t   Database queries:"
echo -e "\t     - Simple SELECT: ${queryResult:-N/A}ms"
echo -e "\t     - Indexed query: ${indexedQueryResult:-N/A}ms"
echo -e "\t     - Complex query: ${joinQueryResult:-N/A}ms"
echo -e "\t   API endpoints:"
echo -e "\t     - /system/status: ${statusTimeResult:-N/A}ms"
echo -e "\t     - /wallet/balance: ${balanceTimeResult:-N/A}ms"
echo -e "\t     - /contacts: ${contactsTimeResult:-N/A}ms"
echo -e "\t   Cryptographic operations:"
echo -e "\t     - Signature gen: ${signatureTimeResult:-N/A}ms"
echo -e "\t     - Signature verify: ${verificationTimeResult:-N/A}ms"
echo -e "\t     - HMAC gen: ${hmacTimeResult:-N/A}ms"
if [[ -n "$realContactAddress" ]]; then
    echo -e "\t   Transaction processing:"
    echo -e "\t     - Single tx: ${singleTxTimeResult:-N/A}ms"
    echo -e "\t     - Batch (10 tx): ${batchTime:-N/A}ms (${batchSuccessCount:-0}/10 success)"
fi
echo -e "\t   Repository operations:"
echo -e "\t     - Tx history: ${historyTimeResult:-N/A}ms"
echo -e "\t     - Contact search: ${contactSearchTimeResult:-N/A}ms"
echo -e "\t     - Balance calc: ${balanceCalcTimeResult:-N/A}ms"
echo -e "\t   Service init:"
echo -e "\t     - App init: ${appInitTimeResult:-N/A}ms"
echo -e "\t     - Service access: ${serviceAccessTimeResult:-N/A}ms/access"

############################ RESULTS ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'performance baseline'"
