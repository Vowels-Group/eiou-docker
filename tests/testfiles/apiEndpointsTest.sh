#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ API Endpoints Test ############################
# Tests REST API endpoints for the EIOU API integration
#
# Verifies:
# - API key creation and authentication
# - System status endpoint responses
# - Wallet information retrieval
# - Contact management via API
# - Transaction operations via API
# - Error handling and validation responses
#
# Prerequisites:
# - Containers must be running
# - Wallet must be initialized
# - API must be accessible
##########################################################################

# Test REST API endpoints for the EIOU API integration
# Tests API key creation, system status, wallet info, contacts, and error handling

echo -e "\nTesting REST API endpoints..."

testname="apiEndpointsTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

# Get real contact address from containerAddresses (populated by hostnameTest.sh or torAddressTest.sh)
# The second container in the topology is a known contact of the first
realContactContainer="${containers[1]}"
realContactAddress="${containerAddresses[${realContactContainer}]}"

if [[ -z "$realContactAddress" ]]; then
    echo -e "\t   ${YELLOW}Warning: containerAddresses not populated. Using fallback contact detection.${NC}"
fi

echo -e "\t   Test container: ${testContainer}"
echo -e "\t   Real contact: ${realContactContainer} (${realContactAddress})"
echo -e "\t   Mode: ${MODE}"

# API endpoints always use HTTPS (server redirects HTTP→HTTPS)
# MODE still controls P2P transport tests, but API is always HTTPS
LOCAL_API_BASE="https://localhost"
CURL_SSL_FLAG="-k"
echo -e "\t   API Base: ${LOCAL_API_BASE}"

# Helper function to display API request details
display_api_request() {
    local container="$1"
    local method="$2"
    local endpoint="$3"
    local body="$4"
    local recipient="$5"

    printf "\t   Container: ${container}\n"
    printf "\t   Endpoint: ${method} ${endpoint}\n"
    if [[ -n "$body" ]]; then
        printf "\t   Body: ${body}\n"
    fi
    if [[ -n "$recipient" ]]; then
        printf "\t   Recipient: ${recipient}\n"
    fi
}

# Helper function to display response details
display_api_response() {
    local response_code="$1"
    local success="$2"

    printf "\t   Response Code: ${response_code}\n"
}

############################ API KEY SETUP ############################

echo -e "\n[API Key Setup]"

# Create API key via CLI
echo -e "\n\t-> Creating API key for testing"
apiKeyOutput=$(docker exec ${testContainer} eiou apikey create "TestAPIKey" "wallet:read,wallet:send,contacts:read,contacts:write,system:read,backup:read,backup:write" --json 2>&1)

# Extract API key ID and secret from the output
apiKeyId=$(echo "$apiKeyOutput" | grep -o '"key_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"key_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
apiSecret=$(echo "$apiKeyOutput" | grep -o '"secret"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"secret"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

if [[ -z "$apiKeyId" ]] || [[ -z "$apiSecret" ]]; then
    # Try alternative extraction pattern
    apiKeyId=$(docker exec ${testContainer} php -r "
        \$json = '$apiKeyOutput';
        \$data = json_decode(\$json, true);
        if (isset(\$data['data']['key_id'])) {
            echo \$data['data']['key_id'];
        }
    " 2>/dev/null)
    apiSecret=$(docker exec ${testContainer} php -r "
        \$json = '$apiKeyOutput';
        \$data = json_decode(\$json, true);
        if (isset(\$data['data']['secret'])) {
            echo \$data['data']['secret'];
        }
    " 2>/dev/null)
fi

if [[ -n "$apiKeyId" ]] && [[ -n "$apiSecret" ]]; then
    printf "\t   API key created: ${apiKeyId}\n"
else
    printf "\t   ${RED}Failed to create API key${NC}\n"
    printf "\t   Output: ${apiKeyOutput}\n"
    # Create key directly via PHP as fallback
    echo -e "\n\t-> Attempting direct PHP API key creation..."
    apiKeyDirect=$(docker exec ${testContainer} php -r "
        require_once '${BOOTSTRAP_PATH}';
        \$app = \Eiou\Core\Application::getInstance();
        \$pdo = \$app->services->getPdo();
        \$repo = new \Eiou\Database\ApiKeyRepository(\$pdo);
        \$key = \$repo->createKey('TestAPIKey', ['wallet:read', 'contacts:read', 'contacts:write', 'system:read'], 1000, null);
        echo json_encode(\$key);
    " 2>/dev/null)

    apiKeyId=$(echo "$apiKeyDirect" | grep -o '"key_id":"[^"]*"' | sed 's/"key_id":"//;s/"$//')
    apiSecret=$(echo "$apiKeyDirect" | grep -o '"secret":"[^"]*"' | sed 's/"secret":"//;s/"$//')

    if [[ -n "$apiKeyId" ]] && [[ -n "$apiSecret" ]]; then
        printf "\t   API key created via PHP: ${apiKeyId}\n"
    else
        printf "\t   ${RED}Failed to create API key via both methods${NC}\n"
    fi
fi

############################ API KEY LIST TEST ############################

echo -e "\n[API Key List Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing 'apikey list' command"
listOutput=$(docker exec ${testContainer} eiou apikey list --json 2>&1)

if [[ "$listOutput" =~ '"success"' ]] && [[ "$listOutput" =~ 'true' ]] && [[ "$listOutput" =~ 'TestAPIKey' ]]; then
    printf "\t   apikey list command ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   apikey list command ${RED}FAILED${NC}\n"
    printf "\t   Output: ${listOutput}\n"
    failure=$(( failure + 1 ))
fi

############################ SYSTEM STATUS API TEST ############################

echo -e "\n[System Status API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/system/status"

# Generate timestamp and signature for API request
timestamp=$(date +%s)
method="GET"
path="/api/v1/system/status"
body=""

# Create HMAC signature - note: the string to sign uses actual newlines
# New format: only send the HMAC, never the secret
signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"${method}\\n${path}\\n${timestamp}\\n${body}\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

statusResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/system/status" 2>&1)

if [[ "$statusResponse" =~ '"success"' ]] && [[ "$statusResponse" =~ 'true' ]] && [[ "$statusResponse" =~ '"status"' ]]; then
    printf "\t   GET /api/v1/system/status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/system/status ${RED}FAILED${NC}\n"
    printf "\t   Response: ${statusResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ WALLET INFO API TEST ############################

echo -e "\n[Wallet Info API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/wallet/info"

timestamp=$(date +%s)
path="/api/v1/wallet/info"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

infoResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/wallet/info" 2>&1)

if [[ "$infoResponse" =~ '"success"' ]] && [[ "$infoResponse" =~ 'true' ]] && [[ "$infoResponse" =~ '"public_key_hash"' ]]; then
    printf "\t   GET /api/v1/wallet/info ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/wallet/info ${RED}FAILED${NC}\n"
    printf "\t   Response: ${infoResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ WALLET BALANCE API TEST ############################

echo -e "\n[Wallet Balance API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/wallet/balance"

timestamp=$(date +%s)
path="/api/v1/wallet/balance"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

balanceResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/wallet/balance" 2>&1)

if [[ "$balanceResponse" =~ '"success"' ]] && [[ "$balanceResponse" =~ 'true' ]] && [[ "$balanceResponse" =~ '"balances"' ]]; then
    printf "\t   GET /api/v1/wallet/balance ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/wallet/balance ${RED}FAILED${NC}\n"
    printf "\t   Response: ${balanceResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ CONTACTS LIST API TEST ############################

echo -e "\n[Contacts List API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/contacts"

timestamp=$(date +%s)
path="/api/v1/contacts"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

contactsResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts" 2>&1)

if [[ "$contactsResponse" =~ '"success"' ]] && [[ "$contactsResponse" =~ 'true' ]] && [[ "$contactsResponse" =~ '"contacts"' ]]; then
    printf "\t   GET /api/v1/contacts ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/contacts ${RED}FAILED${NC}\n"
    printf "\t   Response: ${contactsResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ TRANSACTIONS API TEST ############################

echo -e "\n[Transactions API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/wallet/transactions"

timestamp=$(date +%s)
path="/api/v1/wallet/transactions"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

transactionsResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/wallet/transactions" 2>&1)

if [[ "$transactionsResponse" =~ '"success"' ]] && [[ "$transactionsResponse" =~ 'true' ]] && [[ "$transactionsResponse" =~ '"transactions"' ]]; then
    printf "\t   GET /api/v1/wallet/transactions ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/wallet/transactions ${RED}FAILED${NC}\n"
    printf "\t   Response: ${transactionsResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ SYSTEM METRICS API TEST ############################

echo -e "\n[System Metrics API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/system/metrics"

timestamp=$(date +%s)
path="/api/v1/system/metrics"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

metricsResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/system/metrics" 2>&1)

if [[ "$metricsResponse" =~ '"success"' ]] && [[ "$metricsResponse" =~ 'true' ]] && [[ "$metricsResponse" =~ '"transactions"' ]]; then
    printf "\t   GET /api/v1/system/metrics ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/system/metrics ${RED}FAILED${NC}\n"
    printf "\t   Response: ${metricsResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ API ERROR HANDLING TEST ############################

echo -e "\n[API Error Handling Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing unauthorized access (no API key)"

unauthorizedResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/system/status" 2>&1)

if [[ "$unauthorizedResponse" =~ '"success"' ]] && [[ "$unauthorizedResponse" =~ 'false' ]]; then
    printf "\t   Unauthorized request handling ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Unauthorized request handling ${RED}FAILED${NC}\n"
    printf "\t   Response: ${unauthorizedResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ INVALID PATH TEST ############################

echo -e "\n[Invalid Path Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing invalid API path"

timestamp=$(date +%s)
path="/api/v1/invalid/path"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

invalidResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/invalid/path" 2>&1)

if [[ "$invalidResponse" =~ '"success"' ]] && [[ "$invalidResponse" =~ 'false' ]] && [[ "$invalidResponse" =~ '"error"' ]]; then
    printf "\t   Invalid path handling ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid path handling ${RED}FAILED${NC}\n"
    printf "\t   Response: ${invalidResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ RESPONSE FORMAT VALIDATION ############################

echo -e "\n[Response Format Validation Tests]"

# Test 1: Success response has required fields
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Validating successResponse() format structure"

# Use the cached statusResponse from earlier test
formatValid=$(docker exec ${testContainer} php -r "
    \$json = '${statusResponse}';
    \$response = json_decode(\$json, true);

    // Check required fields for success response
    \$hasSuccess = isset(\$response['success']) && \$response['success'] === true;
    \$hasData = array_key_exists('data', \$response) && is_array(\$response['data']);
    \$hasTimestamp = isset(\$response['timestamp']);
    \$hasRequestId = isset(\$response['request_id']) && strpos(\$response['request_id'], 'req_') === 0;
    \$hasNullError = array_key_exists('error', \$response) && \$response['error'] === null;

    if (\$hasSuccess && \$hasData && \$hasTimestamp && \$hasRequestId && \$hasNullError) {
        echo 'VALID';
    } else {
        \$missing = [];
        if (!\$hasSuccess) \$missing[] = 'success';
        if (!\$hasData) \$missing[] = 'data';
        if (!\$hasTimestamp) \$missing[] = 'timestamp';
        if (!\$hasRequestId) \$missing[] = 'request_id';
        if (!\$hasNullError) \$missing[] = 'error=null';
        echo 'MISSING: ' . implode(', ', \$missing);
    }
" 2>/dev/null)

if [[ "$formatValid" == "VALID" ]]; then
    printf "\t   successResponse format ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   successResponse format ${RED}FAILED${NC}\n"
    printf "\t   Result: ${formatValid}\n"
    failure=$(( failure + 1 ))
fi

# Test 2: Error response has required fields
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Validating errorResponse() format structure"

errorFormatValid=$(docker exec ${testContainer} php -r "
    \$json = '${unauthorizedResponse}';
    \$response = json_decode(\$json, true);

    // Check required fields for error response
    \$hasSuccess = isset(\$response['success']) && \$response['success'] === false;
    \$hasNullData = array_key_exists('data', \$response) && \$response['data'] === null;
    \$hasTimestamp = isset(\$response['timestamp']);
    \$hasRequestId = isset(\$response['request_id']) && strpos(\$response['request_id'], 'req_') === 0;
    \$hasError = isset(\$response['error']) && is_array(\$response['error']);
    \$hasErrorMessage = isset(\$response['error']['message']);
    \$hasErrorCode = isset(\$response['error']['code']);

    if (\$hasSuccess && \$hasNullData && \$hasTimestamp && \$hasRequestId && \$hasError && \$hasErrorMessage && \$hasErrorCode) {
        echo 'VALID';
    } else {
        \$missing = [];
        if (!\$hasSuccess) \$missing[] = 'success=false';
        if (!\$hasNullData) \$missing[] = 'data=null';
        if (!\$hasTimestamp) \$missing[] = 'timestamp';
        if (!\$hasRequestId) \$missing[] = 'request_id';
        if (!\$hasError) \$missing[] = 'error object';
        if (!\$hasErrorMessage) \$missing[] = 'error.message';
        if (!\$hasErrorCode) \$missing[] = 'error.code';
        echo 'MISSING: ' . implode(', ', \$missing);
    }
" 2>/dev/null)

if [[ "$errorFormatValid" == "VALID" ]]; then
    printf "\t   errorResponse format ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   errorResponse format ${RED}FAILED${NC}\n"
    printf "\t   Result: ${errorFormatValid}\n"
    failure=$(( failure + 1 ))
fi

# Test 3: Wallet info response has addresses object
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Validating wallet info addresses structure"

walletInfoValid=$(docker exec ${testContainer} php -r "
    \$json = '${infoResponse}';
    \$response = json_decode(\$json, true);

    \$hasAddresses = isset(\$response['data']['addresses']) && is_array(\$response['data']['addresses']);
    \$hasPubkeyHash = isset(\$response['data']['public_key_hash']);

    if (\$hasAddresses && \$hasPubkeyHash) {
        echo 'VALID';
    } else {
        echo 'INVALID';
    }
" 2>/dev/null)

if [[ "$walletInfoValid" == "VALID" ]]; then
    printf "\t   Wallet info structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Wallet info structure ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 4: Contacts list response has count field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Validating contacts list structure"

contactsValid=$(docker exec ${testContainer} php -r "
    \$json = '${contactsResponse}';
    \$response = json_decode(\$json, true);

    \$hasContacts = isset(\$response['data']['contacts']) && is_array(\$response['data']['contacts']);
    \$hasCount = isset(\$response['data']['count']) && is_numeric(\$response['data']['count']);

    if (\$hasContacts && \$hasCount) {
        echo 'VALID';
    } else {
        echo 'INVALID';
    }
" 2>/dev/null)

if [[ "$contactsValid" == "VALID" ]]; then
    printf "\t   Contacts list structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Contacts list structure ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 5: Transactions response has pagination
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Validating transactions pagination structure"

txPaginationValid=$(docker exec ${testContainer} php -r "
    \$json = '${transactionsResponse}';
    \$response = json_decode(\$json, true);

    \$hasTransactions = isset(\$response['data']['transactions']) && is_array(\$response['data']['transactions']);
    \$hasPagination = isset(\$response['data']['pagination']) && is_array(\$response['data']['pagination']);
    \$hasTotal = isset(\$response['data']['pagination']['total']);
    \$hasLimit = isset(\$response['data']['pagination']['limit']);
    \$hasOffset = isset(\$response['data']['pagination']['offset']);

    if (\$hasTransactions && \$hasPagination && \$hasTotal && \$hasLimit && \$hasOffset) {
        echo 'VALID';
    } else {
        echo 'INVALID';
    }
" 2>/dev/null)

if [[ "$txPaginationValid" == "VALID" ]]; then
    printf "\t   Transactions pagination ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Transactions pagination ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 6: System status response structure
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Validating system status structure"

sysStatusValid=$(docker exec ${testContainer} php -r "
    \$json = '${statusResponse}';
    \$response = json_decode(\$json, true);

    \$hasStatus = isset(\$response['data']['status']);
    \$hasDatabase = isset(\$response['data']['database']);
    \$hasProcessors = isset(\$response['data']['processors']) && is_array(\$response['data']['processors']);

    if (\$hasStatus && \$hasDatabase && \$hasProcessors) {
        echo 'VALID';
    } else {
        echo 'INVALID';
    }
" 2>/dev/null)

if [[ "$sysStatusValid" == "VALID" ]]; then
    printf "\t   System status structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   System status structure ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

# Test 7: System metrics response structure
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Validating system metrics structure"

metricsStructValid=$(docker exec ${testContainer} php -r "
    \$json = '${metricsResponse}';
    \$response = json_decode(\$json, true);

    \$hasTransactions = isset(\$response['data']['transactions']) && is_array(\$response['data']['transactions']);
    \$hasContacts = isset(\$response['data']['contacts']) && is_array(\$response['data']['contacts']);
    \$hasP2p = isset(\$response['data']['p2p']) && is_array(\$response['data']['p2p']);

    if (\$hasTransactions && \$hasContacts && \$hasP2p) {
        echo 'VALID';
    } else {
        echo 'INVALID';
    }
" 2>/dev/null)

if [[ "$metricsStructValid" == "VALID" ]]; then
    printf "\t   System metrics structure ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   System metrics structure ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ POST WALLET SEND TEST ############################

echo -e "\n[Wallet Send API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/wallet/send"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
# Use real contact address from containerAddresses
sendBody="{\"address\":\"${realContactAddress}\",\"amount\":\"0.01\",\"currency\":\"USD\"}"

display_api_request "${testContainer}" "POST" "/api/v1/wallet/send" "${sendBody}" "${realContactAddress}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = '${sendBody}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

sendResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${sendBody}" \
    "${LOCAL_API_BASE}/api/v1/wallet/send" 2>&1)

sendResponseCode=$(echo "$sendResponseFull" | tail -1)
sendResponse=$(echo "$sendResponseFull" | sed '$d')

display_api_response "${sendResponseCode}"

# Transaction to real contact should succeed with proper response
if [[ "$sendResponse" =~ '"success"' ]] && [[ "$sendResponse" =~ 'true' ]] && [[ "$sendResponse" =~ '"error": null' || "$sendResponse" =~ '"error":null' ]]; then
    printf "\t   POST /api/v1/wallet/send ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/wallet/send ${RED}FAILED${NC}\n"
    printf "\t   Response: ${sendResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ POST CONTACTS TEST ############################

echo -e "\n[Contact Creation API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/contacts"

timestamp=$(date +%s)
path="/api/v1/contacts"
# Use real contact address from containerAddresses - may already exist but API should respond properly
contactBody="{\"name\":\"API Test Contact\",\"address\":\"${realContactAddress}\"}"

display_api_request "${testContainer}" "POST" "/api/v1/contacts" "${contactBody}" "${realContactAddress}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = '${contactBody}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

createContactResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${contactBody}" \
    "${LOCAL_API_BASE}/api/v1/contacts" 2>&1)

createContactCode=$(echo "$createContactResponseFull" | tail -1)
createContactResponse=$(echo "$createContactResponseFull" | sed '$d')

display_api_response "${createContactCode}"

# Check if response has proper JSON structure with success field (API or CLI format)
if [[ "$createContactResponse" =~ '"success"' ]]; then
    printf "\t   POST /api/v1/contacts ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/contacts ${RED}FAILED${NC}\n"
    printf "\t   Response: ${createContactResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ GET CONTACT BY ADDRESS TEST ############################

echo -e "\n[Get Contact API Test]"
totaltests=$(( totaltests + 1 ))

# Get the real contact name from the second container (containers[1])
realContactNameForGet="${realContactContainer}"
echo -e "\n\t-> Testing GET /api/v1/contacts/:address"

timestamp=$(date +%s)
path="/api/v1/contacts/${realContactNameForGet}"

display_api_request "${testContainer}" "GET" "/api/v1/contacts/${realContactNameForGet}" "" "${realContactNameForGet}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

getContactResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts/${realContactNameForGet}" 2>&1)

getContactCode=$(echo "$getContactResponseFull" | tail -1)
getContactResponse=$(echo "$getContactResponseFull" | sed '$d')

display_api_response "${getContactCode}"

# Real contact should return success:true with contact data
if [[ "$getContactResponse" =~ '"success"' ]] && [[ "$getContactResponse" =~ 'true' ]] && [[ "$getContactResponse" =~ '"name"' ]]; then
    printf "\t   GET /api/v1/contacts/:address ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/contacts/:address ${RED}FAILED${NC}\n"
    printf "\t   Response: ${getContactResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ DELETE CONTACT TEST ############################

echo -e "\n[Delete Contact API Test]"
totaltests=$(( totaltests + 1 ))

# Use the real contact name for delete test - this tests the API endpoint response format
# The delete may fail because it's an active peer, but API should respond properly
testDeleteAddress="${realContactContainer}"
echo -e "\n\t-> Testing DELETE /api/v1/contacts/:address"

timestamp=$(date +%s)
path="/api/v1/contacts/${testDeleteAddress}"

display_api_request "${testContainer}" "DELETE" "/api/v1/contacts/${testDeleteAddress}" "" "${testDeleteAddress}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"DELETE\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

deleteContactResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
    -X DELETE \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts/${testDeleteAddress}" 2>&1)

deleteContactCode=$(echo "$deleteContactResponseFull" | tail -1)
deleteContactResponse=$(echo "$deleteContactResponseFull" | sed '$d')

display_api_response "${deleteContactCode}"

# Response should have proper JSON structure with success field (may be true or false based on contact state)
if [[ "$deleteContactResponse" =~ '"success"' ]]; then
    printf "\t   DELETE /api/v1/contacts/:address ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   DELETE /api/v1/contacts/:address ${RED}FAILED${NC}\n"
    printf "\t   Response: ${deleteContactResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BLOCK CONTACT TEST ############################

echo -e "\n[Block Contact API Test]"
totaltests=$(( totaltests + 1 ))

# Use real contact address for block test
blockAddress="${realContactAddress}"
echo -e "\n\t-> Testing POST /api/v1/contacts/block/:address"

timestamp=$(date +%s)
path="/api/v1/contacts/block/${blockAddress}"

display_api_request "${testContainer}" "POST" "/api/v1/contacts/block/${blockAddress}" "" "${blockAddress}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

blockContactResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts/block/${blockAddress}" 2>&1)

blockContactCode=$(echo "$blockContactResponseFull" | tail -1)
blockContactResponse=$(echo "$blockContactResponseFull" | sed '$d')

display_api_response "${blockContactCode}"

# Response should have proper JSON structure with success field
if [[ "$blockContactResponse" =~ '"success"' ]]; then
    printf "\t   POST /api/v1/contacts/block/:address ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/contacts/block/:address ${RED}FAILED${NC}\n"
    printf "\t   Response: ${blockContactResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ UNBLOCK CONTACT TEST ############################

echo -e "\n[Unblock Contact API Test]"
totaltests=$(( totaltests + 1 ))

# Use real contact address for unblock test (unblock the contact we just blocked)
unblockAddress="${realContactAddress}"
echo -e "\n\t-> Testing POST /api/v1/contacts/unblock/:address"

timestamp=$(date +%s)
path="/api/v1/contacts/unblock/${unblockAddress}"

display_api_request "${testContainer}" "POST" "/api/v1/contacts/unblock/${unblockAddress}" "" "${unblockAddress}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

unblockContactResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts/unblock/${unblockAddress}" 2>&1)

unblockContactCode=$(echo "$unblockContactResponseFull" | tail -1)
unblockContactResponse=$(echo "$unblockContactResponseFull" | sed '$d')

display_api_response "${unblockContactCode}"

# Response should have proper JSON structure with success field
if [[ "$unblockContactResponse" =~ '"success"' ]]; then
    printf "\t   POST /api/v1/contacts/unblock/:address ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/contacts/unblock/:address ${RED}FAILED${NC}\n"
    printf "\t   Response: ${unblockContactResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ REAL CONTACT TESTS ############################

# Get a real contact from the container's contacts list for subsequent tests
echo -e "\n[Real Contact Tests Setup]"
realContactNameFromViewbalances=""

# Try to get first contact name directly from viewbalances output (path: data.balances.contacts[0].name)
realContactNameFromViewbalances=$(docker exec ${testContainer} sh -c "eiou viewbalances --json 2>/dev/null | php -r '\$d=json_decode(file_get_contents(\"php://stdin\"),true);if(isset(\$d[\"data\"][\"balances\"][\"contacts\"][0][\"name\"]))echo \$d[\"data\"][\"balances\"][\"contacts\"][0][\"name\"];'" 2>/dev/null)

if [[ -n "$realContactNameFromViewbalances" ]]; then
    printf "\t   Found real contact from viewbalances: ${realContactNameFromViewbalances}\n"

    ############################ REAL CONTACT GET TEST ############################

    echo -e "\n[Get Real Contact API Test]"
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing GET /api/v1/contacts/:address (viewbalances contact)"

    timestamp=$(date +%s)
    path="/api/v1/contacts/${realContactNameFromViewbalances}"

    display_api_request "${testContainer}" "GET" "/api/v1/contacts/${realContactNameFromViewbalances}" "" "${realContactNameFromViewbalances}"

    signature=$(docker exec ${testContainer} php -r "
        \$secret = '${apiSecret}';
        \$message = \"GET\\n${path}\\n${timestamp}\\n\";
        echo hash_hmac('sha256', \$message, \$secret);
    " 2>/dev/null)

    realContactResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
        -H "X-API-Key: ${apiKeyId}" \
        -H "X-API-Timestamp: ${timestamp}" \
        -H "X-API-Signature: ${signature}" \
        -H "Content-Type: application/json" \
        "${LOCAL_API_BASE}/api/v1/contacts/${realContactNameFromViewbalances}" 2>&1)

    realContactCode=$(echo "$realContactResponseFull" | tail -1)
    realContactResponse=$(echo "$realContactResponseFull" | sed '$d')

    display_api_response "${realContactCode}"

    # Real contact should return success:true with contact data (allow for JSON whitespace)
    if [[ "$realContactResponse" =~ '"success"' ]] && [[ "$realContactResponse" =~ 'true' ]] && [[ "$realContactResponse" =~ '"name"' ]]; then
        printf "\t   GET /api/v1/contacts/:address (real) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   GET /api/v1/contacts/:address (real) ${RED}FAILED${NC}\n"
        printf "\t   Response: ${realContactResponse}\n"
        failure=$(( failure + 1 ))
    fi

    ############################ REAL CONTACT SEND TEST ############################

    echo -e "\n[Send to Real Contact API Test]"
    totaltests=$(( totaltests + 1 ))

    echo -e "\n\t-> Testing POST /api/v1/wallet/send (viewbalances contact)"

    timestamp=$(date +%s)
    path="/api/v1/wallet/send"
    realSendBody="{\"address\":\"${realContactNameFromViewbalances}\",\"amount\":\"0.01\",\"currency\":\"USD\"}"

    display_api_request "${testContainer}" "POST" "/api/v1/wallet/send" "${realSendBody}" "${realContactNameFromViewbalances}"

    signature=$(docker exec ${testContainer} php -r "
        \$secret = '${apiSecret}';
        \$body = '${realSendBody}';
        \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
        echo hash_hmac('sha256', \$message, \$secret);
    " 2>/dev/null)

    realSendResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
        -X POST \
        -H "X-API-Key: ${apiKeyId}" \
        -H "X-API-Timestamp: ${timestamp}" \
        -H "X-API-Signature: ${signature}" \
        -H "Content-Type: application/json" \
        -d "${realSendBody}" \
        "${LOCAL_API_BASE}/api/v1/wallet/send" 2>&1)

    realSendCode=$(echo "$realSendResponseFull" | tail -1)
    realSendResponse=$(echo "$realSendResponseFull" | sed '$d')

    display_api_response "${realSendCode}"

    # Transaction to real contact should succeed - check for "success": true and "error": null
    if [[ "$realSendResponse" =~ '"success"' ]] && [[ "$realSendResponse" =~ 'true' ]] && [[ "$realSendResponse" =~ '"error": null' || "$realSendResponse" =~ '"error":null' ]]; then
        printf "\t   POST /api/v1/wallet/send (real) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   POST /api/v1/wallet/send (real) ${RED}FAILED${NC}\n"
        printf "\t   Response: ${realSendResponse}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   No real contacts found - skipping real contact tests\n"
fi

############################ WALLET OVERVIEW API TEST ############################

echo -e "\n[Wallet Overview API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/wallet/overview"

timestamp=$(date +%s)
path="/api/v1/wallet/overview"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

overviewResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/wallet/overview" 2>&1)

if [[ "$overviewResponse" =~ '"success"' ]] && [[ "$overviewResponse" =~ 'true' ]] && [[ "$overviewResponse" =~ '"balances"' ]] && [[ "$overviewResponse" =~ '"recent_transactions"' ]]; then
    printf "\t   GET /api/v1/wallet/overview ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/wallet/overview ${RED}FAILED${NC}\n"
    printf "\t   Response: ${overviewResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ PENDING CONTACTS API TEST ############################

echo -e "\n[Pending Contacts API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/contacts/pending"

timestamp=$(date +%s)
path="/api/v1/contacts/pending"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

pendingResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts/pending" 2>&1)

if [[ "$pendingResponse" =~ '"success"' ]] && [[ "$pendingResponse" =~ 'true' ]] && [[ "$pendingResponse" =~ '"pending"' ]] && [[ "$pendingResponse" =~ '"counts"' ]]; then
    printf "\t   GET /api/v1/contacts/pending ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/contacts/pending ${RED}FAILED${NC}\n"
    printf "\t   Response: ${pendingResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ CONTACTS SEARCH API TEST ############################

echo -e "\n[Contacts Search API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/contacts/search"

timestamp=$(date +%s)
path="/api/v1/contacts/search"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

searchApiResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts/search" 2>&1)

if [[ "$searchApiResponse" =~ '"success"' ]] && [[ "$searchApiResponse" =~ 'true' ]] && [[ "$searchApiResponse" =~ '"contacts"' ]]; then
    printf "\t   GET /api/v1/contacts/search ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/contacts/search ${RED}FAILED${NC}\n"
    printf "\t   Response: ${searchApiResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ SYSTEM SETTINGS API TEST ############################

echo -e "\n[System Settings API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/system/settings"

timestamp=$(date +%s)
path="/api/v1/system/settings"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

settingsApiResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/system/settings" 2>&1)

if [[ "$settingsApiResponse" =~ '"success"' ]] && [[ "$settingsApiResponse" =~ 'true' ]] && [[ "$settingsApiResponse" =~ '"settings"' ]]; then
    printf "\t   GET /api/v1/system/settings ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/system/settings ${RED}FAILED${NC}\n"
    printf "\t   Response: ${settingsApiResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ CONTACT PING API TEST ############################

echo -e "\n[Contact Ping API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/contacts/ping/:address"

pingAddress="${realContactAddress}"
timestamp=$(date +%s)
path="/api/v1/contacts/ping/${pingAddress}"

display_api_request "${testContainer}" "POST" "/api/v1/contacts/ping/${pingAddress}" "" "${pingAddress}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

pingApiResponseFull=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s -w "\n%{http_code}" \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/contacts/ping/${pingAddress}" 2>&1)

pingApiCode=$(echo "$pingApiResponseFull" | tail -1)
pingApiResponse=$(echo "$pingApiResponseFull" | sed '$d')

display_api_response "${pingApiCode}"

# Response should have proper JSON structure with success field
if [[ "$pingApiResponse" =~ '"success"' ]]; then
    printf "\t   POST /api/v1/contacts/ping/:address ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/contacts/ping/:address ${RED}FAILED${NC}\n"
    printf "\t   Response: ${pingApiResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP STATUS API TEST ############################

echo -e "\n[Backup Status API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/backup/status"

timestamp=$(date +%s)
path="/api/v1/backup/status"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

backupStatusResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/backup/status" 2>&1)

if [[ "$backupStatusResponse" =~ '"success"' ]] && [[ "$backupStatusResponse" =~ 'true' ]] && [[ "$backupStatusResponse" =~ '"enabled"' ]]; then
    printf "\t   GET /api/v1/backup/status ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/backup/status ${RED}FAILED${NC}\n"
    printf "\t   Response: ${backupStatusResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP LIST API TEST ############################

echo -e "\n[Backup List API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing GET /api/v1/backup/list"

timestamp=$(date +%s)
path="/api/v1/backup/list"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

backupListResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/backup/list" 2>&1)

if [[ "$backupListResponse" =~ '"success"' ]] && [[ "$backupListResponse" =~ 'true' ]] && [[ "$backupListResponse" =~ '"backups"' ]]; then
    printf "\t   GET /api/v1/backup/list ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   GET /api/v1/backup/list ${RED}FAILED${NC}\n"
    printf "\t   Response: ${backupListResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP CREATE API TEST ############################

echo -e "\n[Backup Create API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/backup/create"

timestamp=$(date +%s)
path="/api/v1/backup/create"
backupCreateBody='{"name":"api_test_backup"}'

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = '${backupCreateBody}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

backupCreateResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${backupCreateBody}" \
    "${LOCAL_API_BASE}/api/v1/backup/create" 2>&1)

# Extract filename for later tests
backupFilename=$(echo "$backupCreateResponse" | grep -o '"filename"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"filename"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

if [[ "$backupCreateResponse" =~ '"success"' ]] && [[ "$backupCreateResponse" =~ 'true' ]] && [[ "$backupCreateResponse" =~ '"filename"' ]]; then
    printf "\t   POST /api/v1/backup/create ${GREEN}PASSED${NC}\n"
    printf "\t   Created backup: ${backupFilename}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/backup/create ${RED}FAILED${NC}\n"
    printf "\t   Response: ${backupCreateResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP VERIFY API TEST ############################

echo -e "\n[Backup Verify API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/backup/verify"

if [[ -n "$backupFilename" ]]; then
    timestamp=$(date +%s)
    path="/api/v1/backup/verify"
    backupVerifyBody="{\"filename\":\"${backupFilename}\"}"

    signature=$(docker exec ${testContainer} php -r "
        \$secret = '${apiSecret}';
        \$body = '${backupVerifyBody}';
        \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
        echo hash_hmac('sha256', \$message, \$secret);
    " 2>/dev/null)

    backupVerifyResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
        -X POST \
        -H "X-API-Key: ${apiKeyId}" \
        -H "X-API-Timestamp: ${timestamp}" \
        -H "X-API-Signature: ${signature}" \
        -H "Content-Type: application/json" \
        -d "${backupVerifyBody}" \
        "${LOCAL_API_BASE}/api/v1/backup/verify" 2>&1)

    if [[ "$backupVerifyResponse" =~ '"success"' ]] && [[ "$backupVerifyResponse" =~ 'true' ]] && [[ "$backupVerifyResponse" =~ '"valid"' ]]; then
        printf "\t   POST /api/v1/backup/verify ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   POST /api/v1/backup/verify ${RED}FAILED${NC}\n"
        printf "\t   Response: ${backupVerifyResponse}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   POST /api/v1/backup/verify ${YELLOW}SKIPPED${NC} (no backup file)\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP RESTORE NO CONFIRM TEST ############################

echo -e "\n[Backup Restore (No Confirm) API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/backup/restore (without confirm)"

if [[ -n "$backupFilename" ]]; then
    timestamp=$(date +%s)
    path="/api/v1/backup/restore"
    backupRestoreNoConfirmBody="{\"filename\":\"${backupFilename}\"}"

    signature=$(docker exec ${testContainer} php -r "
        \$secret = '${apiSecret}';
        \$body = '${backupRestoreNoConfirmBody}';
        \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
        echo hash_hmac('sha256', \$message, \$secret);
    " 2>/dev/null)

    backupRestoreNoConfirmResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
        -X POST \
        -H "X-API-Key: ${apiKeyId}" \
        -H "X-API-Timestamp: ${timestamp}" \
        -H "X-API-Signature: ${signature}" \
        -H "Content-Type: application/json" \
        -d "${backupRestoreNoConfirmBody}" \
        "${LOCAL_API_BASE}/api/v1/backup/restore" 2>&1)

    # Should fail with confirmation_required error
    if [[ "$backupRestoreNoConfirmResponse" =~ '"success"' ]] && [[ "$backupRestoreNoConfirmResponse" =~ 'false' ]] && [[ "$backupRestoreNoConfirmResponse" =~ 'confirmation_required' ]]; then
        printf "\t   POST /api/v1/backup/restore (no confirm) ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   POST /api/v1/backup/restore (no confirm) ${RED}FAILED${NC}\n"
        printf "\t   Response: ${backupRestoreNoConfirmResponse}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   POST /api/v1/backup/restore (no confirm) ${YELLOW}SKIPPED${NC} (no backup file)\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP ENABLE API TEST ############################

echo -e "\n[Backup Enable API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/backup/enable"

timestamp=$(date +%s)
path="/api/v1/backup/enable"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

backupEnableResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/backup/enable" 2>&1)

if [[ "$backupEnableResponse" =~ '"success"' ]] && [[ "$backupEnableResponse" =~ 'true' ]] && [[ "$backupEnableResponse" =~ '"enabled"' ]]; then
    printf "\t   POST /api/v1/backup/enable ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/backup/enable ${RED}FAILED${NC}\n"
    printf "\t   Response: ${backupEnableResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP DISABLE API TEST ############################

echo -e "\n[Backup Disable API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/backup/disable"

timestamp=$(date +%s)
path="/api/v1/backup/disable"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

backupDisableResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/backup/disable" 2>&1)

if [[ "$backupDisableResponse" =~ '"success"' ]] && [[ "$backupDisableResponse" =~ 'true' ]] && [[ "$backupDisableResponse" =~ '"enabled"' ]]; then
    printf "\t   POST /api/v1/backup/disable ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/backup/disable ${RED}FAILED${NC}\n"
    printf "\t   Response: ${backupDisableResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP CLEANUP API TEST ############################

echo -e "\n[Backup Cleanup API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing POST /api/v1/backup/cleanup"

timestamp=$(date +%s)
path="/api/v1/backup/cleanup"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

backupCleanupResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/backup/cleanup" 2>&1)

if [[ "$backupCleanupResponse" =~ '"success"' ]] && [[ "$backupCleanupResponse" =~ 'true' ]] && [[ "$backupCleanupResponse" =~ '"deleted_count"' ]]; then
    printf "\t   POST /api/v1/backup/cleanup ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   POST /api/v1/backup/cleanup ${RED}FAILED${NC}\n"
    printf "\t   Response: ${backupCleanupResponse}\n"
    failure=$(( failure + 1 ))
fi

############################ BACKUP DELETE API TEST ############################

echo -e "\n[Backup Delete API Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing DELETE /api/v1/backup/:filename"

if [[ -n "$backupFilename" ]]; then
    timestamp=$(date +%s)
    path="/api/v1/backup/${backupFilename}"

    signature=$(docker exec ${testContainer} php -r "
        \$secret = '${apiSecret}';
        \$message = \"DELETE\\n${path}\\n${timestamp}\\n\";
        echo hash_hmac('sha256', \$message, \$secret);
    " 2>/dev/null)

    backupDeleteResponse=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
        -X DELETE \
        -H "X-API-Key: ${apiKeyId}" \
        -H "X-API-Timestamp: ${timestamp}" \
        -H "X-API-Signature: ${signature}" \
        -H "Content-Type: application/json" \
        "${LOCAL_API_BASE}/api/v1/backup/${backupFilename}" 2>&1)

    if [[ "$backupDeleteResponse" =~ '"success"' ]] && [[ "$backupDeleteResponse" =~ 'true' ]]; then
        printf "\t   DELETE /api/v1/backup/:filename ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   DELETE /api/v1/backup/:filename ${RED}FAILED${NC}\n"
        printf "\t   Response: ${backupDeleteResponse}\n"
        failure=$(( failure + 1 ))
    fi
else
    printf "\t   DELETE /api/v1/backup/:filename ${YELLOW}SKIPPED${NC} (no backup file)\n"
    failure=$(( failure + 1 ))
fi

############################ API KEY DELETE TEST ############################

echo -e "\n[API Key Delete Test]"
totaltests=$(( totaltests + 1 ))

echo -e "\n\t-> Testing API key deletion"

# Delete the test API key
deleteOutput=$(docker exec ${testContainer} eiou apikey delete "${apiKeyId}" --json 2>&1)

if [[ "$deleteOutput" =~ '"success"' ]] && [[ "$deleteOutput" =~ 'true' ]]; then
    printf "\t   API key deletion ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   API key deletion ${RED}FAILED${NC}\n"
    printf "\t   Output: ${deleteOutput}\n"
    failure=$(( failure + 1 ))
fi

##################################################################

succesrate "${totaltests}" "${passed}" "${failure}" "'API endpoints'"
