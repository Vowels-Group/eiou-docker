#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Performance Baseline Test Suite ############################
# Performance benchmarking tests for the eIOU application
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

# API endpoints always use HTTPS (server redirects HTTP→HTTPS)
LOCAL_API_BASE="https://localhost"
CURL_SSL_FLAG="-k"
echo -e "\t   Test container: ${testContainer}"
echo -e "\t   API Base: ${LOCAL_API_BASE}"
echo -e "\t   Mode: ${MODE}"

############################ PERFORMANCE THRESHOLDS ############################
# All times in milliseconds
# These are baseline thresholds - adjust based on your environment

MAX_SINGLE_TX_TIME_MS=5000       # Max time for single transaction processing
MAX_BATCH_TX_TOTAL_TIME_MS=120000 # Max time for 10 transactions batch (sequential with queue processing)
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
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();

    \$start = microtime(true);
    \$result = \$pdo->query('SELECT COUNT(*) as cnt FROM transactions');
    \$row = \$result->fetch(PDO::FETCH_ASSOC);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$queryResult" ]] && [[ $(awk "BEGIN {print ($queryResult < $MAX_DB_QUERY_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();

    \$start = microtime(true);
    // Query using indexed column (txid is primary/unique)
    \$stmt = \$pdo->prepare('SELECT * FROM transactions WHERE txid = :txid');
    \$stmt->execute(['txid' => 'nonexistent_txid_benchmark']);
    \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$indexedQueryResult" ]] && [[ $(awk "BEGIN {print ($indexedQueryResult < $MAX_DB_QUERY_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
    require_once('${BOOTSTRAP_PATH}');
    \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();

    \$start = microtime(true);
    // Query with potential join (contacts table)
    \$result = \$pdo->query('SELECT COUNT(*) as cnt FROM contacts');
    \$row = \$result->fetch(PDO::FETCH_ASSOC);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$joinQueryResult" ]] && [[ $(awk "BEGIN {print ($joinQueryResult < $MAX_DB_QUERY_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/system/status"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n${nonce}\\n\";
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
        'X-API-Nonce: ${nonce}',
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

if [[ -n "$statusTimeResult" ]] && [[ $(awk "BEGIN {print ($statusTimeResult < $MAX_API_RESPONSE_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
    printf "\t   /api/v1/system/status (${statusTimeResult}ms < ${MAX_API_RESPONSE_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   /api/v1/system/status ${RED}FAILED${NC} (${statusTimeResult}ms >= ${MAX_API_RESPONSE_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 2.2: Wallet balance endpoint response time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing /api/v1/wallet/balance response time"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/wallet/balance"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n${nonce}\\n\";
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
        'X-API-Nonce: ${nonce}',
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

if [[ -n "$balanceTimeResult" ]] && [[ $(awk "BEGIN {print ($balanceTimeResult < $MAX_API_RESPONSE_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
    printf "\t   /api/v1/wallet/balance (${balanceTimeResult}ms < ${MAX_API_RESPONSE_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   /api/v1/wallet/balance ${RED}FAILED${NC} (${balanceTimeResult}ms >= ${MAX_API_RESPONSE_TIME_MS}ms)\n"
    failure=$(( failure + 1 ))
fi

# Test 2.3: Contacts list endpoint response time
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing /api/v1/contacts response time"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n${nonce}\\n\";
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
        'X-API-Nonce: ${nonce}',
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

if [[ -n "$contactsTimeResult" ]] && [[ $(awk "BEGIN {print ($contactsTimeResult < $MAX_API_RESPONSE_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$user = \$app->services->getCurrentUser();
    \$privateKey = \$user->getPrivateKey();

    \$testData = 'This is test data for signature benchmark ' . time();

    \$start = microtime(true);
    openssl_sign(\$testData, \$signature, \$privateKey, OPENSSL_ALGO_SHA256);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$signatureTimeResult" ]] && [[ $(awk "BEGIN {print ($signatureTimeResult < $MAX_SIGNATURE_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$user = \$app->services->getCurrentUser();
    \$privateKey = \$user->getPrivateKey();
    \$publicKey = \$user->getPublicKey();

    \$testData = 'This is test data for verification benchmark ' . time();
    openssl_sign(\$testData, \$signature, \$privateKey, OPENSSL_ALGO_SHA256);

    \$start = microtime(true);
    \$valid = openssl_verify(\$testData, \$signature, \$publicKey, OPENSSL_ALGO_SHA256);
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$verificationTimeResult" ]] && [[ $(awk "BEGIN {print ($verificationTimeResult < $MAX_SIGNATURE_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
if [[ -n "$hmacTimeResult" ]] && [[ $(awk "BEGIN {print ($hmacTimeResult < 10) ? 1 : 0}") -eq 1 ]]; then
    printf "\t   HMAC generation (${hmacTimeResult}ms per op, < 10ms) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   HMAC generation ${RED}FAILED${NC} (${hmacTimeResult}ms >= 10ms)\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 4: TRANSACTION PROCESSING ############################

echo -e "\n[Section 4: Transaction Processing Performance]"

# Clean up chain state from prior test suites to prevent chain-integrity errors.
# When run after syncTestSuite/chainDropTestSuite, residual chain gaps block sends.
echo -e "\n\t-> Resetting transaction chain state for clean performance measurement"
for _perfContainer in "${containers[@]}"; do
    docker exec ${_perfContainer} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
        \$pdo->exec(\"DELETE FROM transactions WHERE memo != 'contact'\");
        // Fix dangling previous_txid pointers
        \$broken = \$pdo->query(\"
            SELECT t1.txid FROM transactions t1
            WHERE t1.previous_txid IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM transactions t2 WHERE t2.txid = t1.previous_txid)
        \")->fetchAll(PDO::FETCH_COLUMN);
        foreach (\$broken as \$txid) {
            \$pdo->exec(\"UPDATE transactions SET previous_txid = NULL WHERE txid = '\" . addslashes(\$txid) . \"'\");
        }
        \$pdo->exec('DELETE FROM balances');
        \$pdo->exec('DELETE FROM chain_drop_proposals');
        \$pdo->exec('DELETE FROM p2p');
        \$pdo->exec('DELETE FROM rp2p');
    " 2>/dev/null || true
done
echo -e "\t   Chain state reset complete"

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
            // Return time but mark as error, include first 100 chars of output for diagnostics
            \$preview = substr(\$output ?? 'NULL', 0, 100);
            echo 'ERROR:' . \$elapsed_ms . ':' . \$preview;
        }
    " 2>/dev/null)

    if [[ "$singleTxTimeResult" != ERROR:* ]] && [[ -n "$singleTxTimeResult" ]] && [[ $(awk "BEGIN {print ($singleTxTimeResult < $MAX_SINGLE_TX_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
        printf "\t   Single transaction (${singleTxTimeResult}ms < ${MAX_SINGLE_TX_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    elif [[ "$singleTxTimeResult" == ERROR:* ]]; then
        txTime=$(echo "$singleTxTimeResult" | cut -d: -f2)
        txError=$(echo "$singleTxTimeResult" | cut -d: -f3-)
        printf "\t   Single transaction ${RED}FAILED${NC} (transaction failed, took ${txTime}ms)\n"
        printf "\t   Error: ${txError}\n"
        failure=$(( failure + 1 ))
    else
        printf "\t   Single transaction ${RED}FAILED${NC} (${singleTxTimeResult}ms >= ${MAX_SINGLE_TX_TIME_MS}ms)\n"
        failure=$(( failure + 1 ))
    fi

    # Reset chain state before batch test — the single tx from test 4.1 may still
    # be pending/sending and would block all batch sends with chain-integrity errors.
    for _perfContainer in "${containers[@]}"; do
        docker exec ${_perfContainer} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$pdo = \Eiou\Core\Application::getInstance()->services->getPdo();
            \$pdo->exec(\"DELETE FROM transactions WHERE memo != 'contact'\");
            \$broken = \$pdo->query(\"
                SELECT t1.txid FROM transactions t1
                WHERE t1.previous_txid IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM transactions t2 WHERE t2.txid = t1.previous_txid)
            \")->fetchAll(PDO::FETCH_COLUMN);
            foreach (\$broken as \$txid) {
                \$pdo->exec(\"UPDATE transactions SET previous_txid = NULL WHERE txid = '\" . addslashes(\$txid) . \"'\");
            }
            \$pdo->exec('DELETE FROM balances');
        " 2>/dev/null || true
    done

    # Test 4.2: Batch transaction throughput (10 transactions)
    totaltests=$(( totaltests + 1 ))
    echo -e "\n\t-> Testing batch transaction throughput (10 transactions)"

    # Send 10 transactions sequentially, processing queues between each to ensure
    # the previous transaction completes before the next one starts. This prevents
    # chain conflicts (duplicate previous_txid) and pending-tx blocking.
    batchStart=$(date +%s%N)
    batchSuccessCount=0
    batchFirstError=""
    for _batchI in $(seq 1 10); do
        _txResult=$(docker exec ${testContainer} eiou send ${realContactAddress} 0.01 USD --json 2>&1)
        if echo "$_txResult" | grep -q '"success":true'; then
            batchSuccessCount=$(( batchSuccessCount + 1 ))
        elif [[ -z "$batchFirstError" ]]; then
            batchFirstError=$(echo "$_txResult" | head -c 200)
        fi
        # Wait for daemon processors to complete the transaction naturally
        sleep 5
    done
    batchEnd=$(date +%s%N)
    batchTime=$(( (batchEnd - batchStart) / 1000000 ))

    if [[ "$batchSuccessCount" -ge 8 ]] && [[ $(awk "BEGIN {print ($batchTime < $MAX_BATCH_TX_TOTAL_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
        printf "\t   Batch transactions (${batchSuccessCount}/10 succeeded, ${batchTime}ms < ${MAX_BATCH_TX_TOTAL_TIME_MS}ms) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Batch transactions ${RED}FAILED${NC} (${batchSuccessCount}/10 succeeded, ${batchTime}ms)\n"
        if [[ -n "$batchFirstError" ]]; then
            printf "\t   First error: ${batchFirstError}\n"
        fi
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
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$txRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);

    \$start = microtime(true);
    \$history = \$txRepo->getTransactionHistory(50, 0); // Get up to 50 transactions
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$historyTimeResult" ]] && [[ $(awk "BEGIN {print ($historyTimeResult < $MAX_DB_QUERY_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);

    \$start = microtime(true);
    \$contacts = \$contactRepo->searchContacts('test'); // Search for 'test'
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$contactSearchTimeResult" ]] && [[ $(awk "BEGIN {print ($contactSearchTimeResult < $MAX_DB_QUERY_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
    require_once('${BOOTSTRAP_PATH}');

    \$app = \Eiou\Core\Application::getInstance();
    \$balanceRepository = \$app->services->getRepositoryFactory()->get(\Eiou\Database\BalanceRepository::class);

    \$start = microtime(true);
    \$balances = \$balanceRepository->getUserBalance();
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

if [[ -n "$balanceCalcTimeResult" ]] && [[ $(awk "BEGIN {print ($balanceCalcTimeResult < $MAX_DB_QUERY_TIME_MS) ? 1 : 0}") -eq 1 ]]; then
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
echo -e "\n\t-> Testing \Eiou\Core\Application::getInstance() time"

appInitTimeResult=$(docker exec ${testContainer} php -r "
    \$start = microtime(true);
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$end = microtime(true);

    \$elapsed_ms = round((\$end - \$start) * 1000, 2);
    echo \$elapsed_ms;
" 2>/dev/null)

# Application init can take longer, use 2x the DB query threshold
APP_INIT_THRESHOLD=$((MAX_DB_QUERY_TIME_MS * 2))
if [[ -n "$appInitTimeResult" ]] && [[ $(awk "BEGIN {print ($appInitTimeResult < $APP_INIT_THRESHOLD) ? 1 : 0}") -eq 1 ]]; then
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
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();

    // Warm up
    \$_ = \$app->services->getPdo();

    \$start = microtime(true);
    for (\$i = 0; \$i < 100; \$i++) {
        \$pdo = \$app->services->getPdo();
        \$txRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
        \$contactRepo = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class);
    }
    \$end = microtime(true);

    // Time for 100 iterations, divide for single access
    \$elapsed_ms = round(((\$end - \$start) / 100) * 1000, 4);
    echo \$elapsed_ms;
" 2>/dev/null)

# Service access should be very fast (singleton pattern)
if [[ -n "$serviceAccessTimeResult" ]] && [[ $(awk "BEGIN {print ($serviceAccessTimeResult < 5) ? 1 : 0}") -eq 1 ]]; then
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
