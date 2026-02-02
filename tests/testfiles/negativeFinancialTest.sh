#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Negative Financial Test ############################
# Tests negative/error cases for financial operations
#
# Verifies:
# - Send with insufficient funds returns appropriate error
# - Negative amounts are rejected
# - Amounts exceeding MAX_AMOUNT are rejected
# - Invalid currency codes are rejected
# - Non-existent contacts/addresses are rejected
# - Zero amount transactions are rejected
#
# Prerequisites:
# - Containers must be running
# - API must be accessible
# - API key must be available (will create one)
#################################################################################

echo -e "\nRunning Negative Financial Test Suite..."

testname="negativeFinancialTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

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

############################ API KEY SETUP ############################

echo -e "\n[Negative Financial Test API Key Setup]"

# Create API key for testing
echo -e "\n\t-> Creating API key for negative financial testing"
apiKeyOutput=$(docker exec ${testContainer} eiou apikey create "NegativeFinancialTestKey" "wallet:read,wallet:send,contacts:read,contacts:write,system:read" --json 2>&1)

apiKeyId=$(echo "$apiKeyOutput" | grep -o '"key_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"key_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
apiSecret=$(echo "$apiKeyOutput" | grep -o '"secret"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"secret"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

if [[ -z "$apiKeyId" ]] || [[ -z "$apiSecret" ]]; then
    echo -e "\t   ${RED}Failed to create API key for negative financial testing${NC}"
    echo -e "\t   Output: ${apiKeyOutput}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'negative financial test'"
    exit 1
fi

echo -e "\t   API Key ID: ${apiKeyId}"
echo -e "\t   ${GREEN}API key created successfully${NC}"

# Get a real contact address for tests that need a valid recipient
realContactContainer="${containers[1]}"
realContactAddress="${containerAddresses[${realContactContainer}]}"
echo -e "\t   Real contact for testing: ${realContactAddress}"

############################ SECTION 1: NEGATIVE AMOUNT REJECTION ############################

echo -e "\n[Section 1: Negative Amount Rejection]"

# Test 1.1: Send negative amount
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with negative amount (-100)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"-100\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "amount" || "$response" =~ "Amount" || "$response" =~ "positive" || "$response" =~ "INVALID" || "$response" =~ "invalid" ]]; then
    printf "\t   Negative amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Negative amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 2: ZERO AMOUNT REJECTION ############################

echo -e "\n[Section 2: Zero Amount Rejection]"

# Test 2.1: Send zero amount
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with zero amount (0)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"0\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "amount" || "$response" =~ "Amount" || "$response" =~ "zero" || "$response" =~ "INVALID" || "$response" =~ "invalid" || "$response" =~ "positive" || "$response" =~ "greater" ]]; then
    printf "\t   Zero amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Zero amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 3: EXCESSIVE AMOUNT REJECTION ############################

echo -e "\n[Section 3: Excessive Amount Rejection (Exceeds MAX_AMOUNT)]"

# Test 3.1: Send amount exceeding MAX_AMOUNT (999999999999)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with excessive amount (999999999999)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"999999999999\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

# Should fail with error about amount exceeding limit or insufficient funds
if [[ "$response" =~ "false" ]]; then
    printf "\t   Excessive amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Excessive amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 4: INVALID CURRENCY REJECTION ############################

echo -e "\n[Section 4: Invalid Currency Rejection]"

# Test 4.1: Send with invalid currency code
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with invalid currency code (XYZ999)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"10\",\"currency\":\"XYZ999\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "currency" || "$response" =~ "Currency" || "$response" =~ "INVALID" || "$response" =~ "invalid" || "$response" =~ "unsupported" || "$response" =~ "Unsupported" ]]; then
    printf "\t   Invalid currency rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid currency rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 5: NON-EXISTENT CONTACT REJECTION ############################

echo -e "\n[Section 5: Non-Existent Contact Rejection]"

# Test 5.1: Send to non-existent contact/address
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send to non-existent contact (fake_address_12345.onion)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"http://fake_address_that_does_not_exist_12345.onion\",\"amount\":\"10\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "contact" || "$response" =~ "Contact" || "$response" =~ "address" || "$response" =~ "Address" || "$response" =~ "found" || "$response" =~ "exist" || "$response" =~ "INVALID" || "$response" =~ "invalid" ]]; then
    printf "\t   Non-existent contact rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Non-existent contact rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.2: Send to empty address
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send to empty address"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"\",\"amount\":\"10\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]]; then
    printf "\t   Empty address rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Empty address rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 6: INSUFFICIENT FUNDS (CLI TEST) ############################

echo -e "\n[Section 6: Insufficient Funds Test]"

# Test 6.1: Send amount exceeding credit limit via CLI
# The receiver (B) should reject the transaction due to credit limit exceeded
# This tests the async validation flow: A sends -> B validates -> B rejects -> A gets rejection
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with amount exceeding credit limit (1000000 USD via CLI)"

# First check current balance on sender side
currentBalance=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$balances = \$app->services->getBalanceRepository()->getAllBalances();
    \$total = 0;
    foreach (\$balances as \$b) {
        if (isset(\$b['received']) && isset(\$b['sent'])) {
            \$total += \$b['received'] - \$b['sent'];
        }
    }
    echo \$total;
" 2>/dev/null || echo "0")

echo -e "\t   Current balance: ${currentBalance}"

# Send the transaction and capture txid (using JSON output)
sendResult=$(docker exec ${testContainer} eiou send ${realContactAddress} 1000000 USD --json 2>&1)
txid=$(echo "$sendResult" | grep -o '"txid"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"txid"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

if [[ -z "$txid" ]]; then
    # If no txid, check if it was an immediate rejection
    if [[ "$sendResult" =~ "insufficient" || "$sendResult" =~ "Insufficient" || "$sendResult" =~ "credit" || "$sendResult" =~ "Credit" || "$sendResult" =~ "false" ]]; then
        printf "\t   Insufficient funds/credit rejection ${GREEN}PASSED${NC} (immediate rejection)\n"
        passed=$(( passed + 1 ))
    else
        printf "\t   Insufficient funds rejection ${RED}FAILED${NC} (no txid and no rejection)\n"
        printf "\t   Send output: ${sendResult}\n"
        failure=$(( failure + 1 ))
    fi
else
    echo -e "\t   Transaction queued with txid: ${txid}"

    # Trigger message processing: A sends to B
    # Use eiou CLI commands to avoid MSYS path conversion issues on Windows Git Bash
    echo -e "\t   Processing outgoing messages on sender..."
    docker exec -e EIOU_TEST_MODE=true ${testContainer} eiou out >/dev/null 2>&1 || true
    sleep 1

    # Trigger message processing: B receives and validates (should reject)
    echo -e "\t   Processing incoming messages on receiver..."
    docker exec -e EIOU_TEST_MODE=true ${realContactContainer} eiou in >/dev/null 2>&1 || true
    sleep 1

    # Trigger message processing: A receives B's response
    echo -e "\t   Processing response on sender..."
    docker exec -e EIOU_TEST_MODE=true ${testContainer} eiou in >/dev/null 2>&1 || true
    sleep 1

    # Check transaction status - should be rejected
    echo -e "\t   Checking transaction status..."
    txStatus=$(docker exec ${testContainer} php -r "
        require_once('${BOOTSTRAP_PATH}');
        \$app = \Eiou\Core\Application::getInstance();
        // Use getStatusByTxid which returns status string directly
        echo \$app->services->getTransactionRepository()->getStatusByTxid('${txid}') ?? 'unknown';
    " 2>/dev/null || echo "unknown")

    echo -e "\t   Transaction status: ${txStatus}"

    # Check if transaction was rejected
    if [[ "$txStatus" == "rejected" || "$txStatus" == "failed" || "$txStatus" == "cancelled" ]]; then
        printf "\t   Insufficient funds/credit rejection ${GREEN}PASSED${NC} (status: ${txStatus})\n"
        passed=$(( passed + 1 ))
    else
        # Also check if there's a rejection reason in the transaction
        # Note: getByTxid returns array of arrays, so access first element
        rejectionReason=$(docker exec ${testContainer} php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$app = \Eiou\Core\Application::getInstance();
            \$txArray = \$app->services->getTransactionRepository()->getByTxid('${txid}');
            \$tx = \$txArray[0] ?? [];
            echo \$tx['rejection_reason'] ?? \$tx['error'] ?? \$tx['message'] ?? '';
        " 2>/dev/null || echo "")

        if [[ "$rejectionReason" =~ "credit" || "$rejectionReason" =~ "insufficient" || "$rejectionReason" =~ "funds" || "$rejectionReason" =~ "limit" ]]; then
            printf "\t   Insufficient funds/credit rejection ${GREEN}PASSED${NC} (reason: ${rejectionReason})\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Insufficient funds rejection ${RED}FAILED${NC}\n"
            printf "\t   Status: ${txStatus}, Reason: ${rejectionReason:-none}\n"
            failure=$(( failure + 1 ))
        fi
    fi
fi

############################ SECTION 7: INVALID AMOUNT FORMAT ############################

echo -e "\n[Section 7: Invalid Amount Format Rejection]"

# Test 7.1: Send with non-numeric amount
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with non-numeric amount (abc)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"abc\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "amount" || "$response" =~ "Amount" || "$response" =~ "INVALID" || "$response" =~ "invalid" || "$response" =~ "numeric" ]]; then
    printf "\t   Non-numeric amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Non-numeric amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 7.2: Send with special characters in amount
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with special characters in amount (<script>)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"<script>alert(1)</script>\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]]; then
    printf "\t   Special characters in amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Special characters in amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 8: MISSING REQUIRED FIELDS ############################

echo -e "\n[Section 8: Missing Required Fields Rejection]"

# Test 8.1: Send without amount field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send without amount field"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "amount" || "$response" =~ "Amount" || "$response" =~ "required" || "$response" =~ "Required" || "$response" =~ "missing" || "$response" =~ "Missing" ]]; then
    printf "\t   Missing amount field rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing amount field rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 8.2: Send without address field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send without address field"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"amount\":\"10\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "address" || "$response" =~ "Address" || "$response" =~ "required" || "$response" =~ "Required" || "$response" =~ "missing" || "$response" =~ "Missing" ]]; then
    printf "\t   Missing address field rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing address field rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 8.3: Send without currency field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send without currency field"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"10\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "currency" || "$response" =~ "Currency" || "$response" =~ "required" || "$response" =~ "Required" || "$response" =~ "missing" || "$response" =~ "Missing" ]]; then
    printf "\t   Missing currency field rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing currency field rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 9: SELF-SEND REJECTION ############################

echo -e "\n[Section 9: Self-Send Rejection]"

# Test 9.1: Send to self via API
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send to self (own address)"

# Get own address from container
ownAddress=$(docker exec ${testContainer} php -r "
    require_once('${BOOTSTRAP_PATH}');
    \$app = \Eiou\Core\Application::getInstance();
    \$user = \$app->services->getCurrentUser();
    echo \$user->getHttpAddress() ?? \$user->getHttpsAddress() ?? \$user->getTorAddress() ?? '';
" 2>/dev/null)

if [[ -n "$ownAddress" ]]; then
    timestamp=$(date +%s)
    path="/api/v1/wallet/send"
    body="{\"address\":\"${ownAddress}\",\"amount\":\"10\",\"currency\":\"USD\"}"
    bodyB64=$(printf '%s' "$body" | base64 -w 0)

    signature=$(docker exec ${testContainer} php -r "
        \$secret = '${apiSecret}';
        \$body = base64_decode('${bodyB64}');
        \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
        echo hash_hmac('sha256', \$message, \$secret);
    " 2>/dev/null)

    response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
        -X POST \
        -H "X-API-Key: ${apiKeyId}" \
        -H "X-API-Timestamp: ${timestamp}" \
        -H "X-API-Signature: ${signature}" \
        -H "Content-Type: application/json" \
        -d "${body}" \
        "${LOCAL_API_BASE}${path}" 2>&1)

    if [[ "$response" =~ "false" ]] && [[ "$response" =~ "self" || "$response" =~ "Self" || "$response" =~ "yourself" || "$response" =~ "own" || "$response" =~ "SELF_SEND" ]]; then
        printf "\t   Self-send rejection ${GREEN}PASSED${NC}\n"
        passed=$(( passed + 1 ))
    else
        # Even if the error message is different, sending to self should fail
        if [[ "$response" =~ "false" ]]; then
            printf "\t   Self-send rejection ${GREEN}PASSED${NC} (generic error)\n"
            passed=$(( passed + 1 ))
        else
            printf "\t   Self-send rejection ${RED}FAILED${NC}\n"
            printf "\t   Response: ${response}\n"
            failure=$(( failure + 1 ))
        fi
    fi
else
    printf "\t   Self-send rejection ${YELLOW}SKIPPED${NC} (could not determine own address)\n"
fi

############################ SECTION 10: DECIMAL PRECISION ############################

echo -e "\n[Section 10: Decimal Precision Validation]"

# Test 10.1: Send with too many decimal places
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing send with excessive decimal precision (0.123456789012345)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"${realContactAddress}\",\"amount\":\"0.123456789012345\",\"currency\":\"USD\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

# The response should either reject due to precision or potentially succeed with truncation
# Either way, the API should handle this gracefully without errors
if [[ "$response" =~ '"success"' ]]; then
    # Response structure is valid (either accepted or rejected properly)
    printf "\t   Excessive decimal precision handling ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Excessive decimal precision handling ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup]"
echo -e "\t-> Deleting test API key"

docker exec ${testContainer} eiou apikey delete ${apiKeyId} --confirm 2>/dev/null

############################ RESULTS ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'negative financial test'"
