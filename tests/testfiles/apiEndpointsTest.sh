#!/bin/sh

# Test REST API endpoints for the EIOU API integration
# Tests API key creation, system status, wallet info, contacts, and error handling
# Issue #145 - REST API Integration Infrastructure

echo -e "\nTesting REST API endpoints..."

testname="apiEndpointsTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

############################ API KEY SETUP ############################

echo -e "\n[API Key Setup]"

# Create API key via CLI
echo -e "\n\t-> Creating API key for testing"
apiKeyOutput=$(docker exec ${testContainer} eiou apikey create "TestAPIKey" "wallet:read,contacts:read,contacts:write,system:read" --json 2>&1)

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
        require_once '/etc/eiou/src/core/Application.php';
        \$app = Application::getInstance();
        \$pdo = \$app->services->getPdo();
        require_once '/etc/eiou/src/database/ApiKeyRepository.php';
        \$repo = new ApiKeyRepository(\$pdo);
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
signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"${method}\\n${path}\\n${timestamp}\\n${body}\";
    \$hmac = hash_hmac('sha256', \$message, \$secret);
    echo \$secret . ':' . \$hmac;
" 2>/dev/null)

statusResponse=$(docker exec ${testContainer} curl -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/system/status" 2>&1)

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
    \$hmac = hash_hmac('sha256', \$message, \$secret);
    echo \$secret . ':' . \$hmac;
" 2>/dev/null)

infoResponse=$(docker exec ${testContainer} curl -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/wallet/info" 2>&1)

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
    \$hmac = hash_hmac('sha256', \$message, \$secret);
    echo \$secret . ':' . \$hmac;
" 2>/dev/null)

balanceResponse=$(docker exec ${testContainer} curl -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/wallet/balance" 2>&1)

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
    \$hmac = hash_hmac('sha256', \$message, \$secret);
    echo \$secret . ':' . \$hmac;
" 2>/dev/null)

contactsResponse=$(docker exec ${testContainer} curl -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/contacts" 2>&1)

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
    \$hmac = hash_hmac('sha256', \$message, \$secret);
    echo \$secret . ':' . \$hmac;
" 2>/dev/null)

transactionsResponse=$(docker exec ${testContainer} curl -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/wallet/transactions" 2>&1)

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
    \$hmac = hash_hmac('sha256', \$message, \$secret);
    echo \$secret . ':' . \$hmac;
" 2>/dev/null)

metricsResponse=$(docker exec ${testContainer} curl -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/system/metrics" 2>&1)

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

unauthorizedResponse=$(docker exec ${testContainer} curl -s \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/system/status" 2>&1)

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
    \$hmac = hash_hmac('sha256', \$message, \$secret);
    echo \$secret . ':' . \$hmac;
" 2>/dev/null)

invalidResponse=$(docker exec ${testContainer} curl -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    "http://localhost/api/v1/invalid/path" 2>&1)

if [[ "$invalidResponse" =~ '"success"' ]] && [[ "$invalidResponse" =~ 'false' ]] && [[ "$invalidResponse" =~ '"error"' ]]; then
    printf "\t   Invalid path handling ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid path handling ${RED}FAILED${NC}\n"
    printf "\t   Response: ${invalidResponse}\n"
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
