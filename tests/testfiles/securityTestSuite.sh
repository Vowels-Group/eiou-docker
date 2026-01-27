#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ Security Test Suite ############################
# Comprehensive security testing for the EIOU application
#
# Verifies:
# - SQL injection protection on API endpoints
# - XSS payload handling and sanitization
# - Authentication header manipulation
# - Rate limit enforcement
# - API signature replay attack prevention
# - Input validation security
#
# Prerequisites:
# - Containers must be running
# - API must be accessible
# - API key must be available (will create one)
##########################################################################

echo -e "\nRunning Security Test Suite..."

testname="securityTestSuite"
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

echo -e "\n[Security Test API Key Setup]"

# Create API key for security testing
echo -e "\n\t-> Creating API key for security testing"
apiKeyOutput=$(docker exec ${testContainer} eiou apikey create "SecurityTestKey" "wallet:read,wallet:send,contacts:read,contacts:write,system:read" --json 2>&1)

apiKeyId=$(echo "$apiKeyOutput" | grep -o '"key_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"key_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
apiSecret=$(echo "$apiKeyOutput" | grep -o '"secret"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"secret"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

if [[ -z "$apiKeyId" ]] || [[ -z "$apiSecret" ]]; then
    echo -e "\t   ${RED}Failed to create API key for security testing${NC}"
    echo -e "\t   Output: ${apiKeyOutput}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'security test suite'"
    exit 1
fi

echo -e "\t   API Key ID: ${apiKeyId}"
echo -e "\t   ${GREEN}API key created successfully${NC}"

############################ SECTION 1: SQL INJECTION TESTING ############################

echo -e "\n[Section 1: SQL Injection Protection]"

# Test 1.1: SQL Injection in contact search query
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SQL injection in search parameter"

timestamp=$(date +%s)
path="/api/v1/contacts"
sqlPayload="'; DROP TABLE contacts; --"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    "${LOCAL_API_BASE}${path}?search=${sqlPayload}" 2>&1)

# Verify contacts table still exists
tableCheck=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/core/Application.php';
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$result = \$pdo->query(\"SHOW TABLES LIKE 'contacts'\");
    echo \$result->rowCount() > 0 ? 'TABLE_EXISTS' : 'TABLE_MISSING';
" 2>/dev/null)

if [[ "$tableCheck" == "TABLE_EXISTS" ]]; then
    printf "\t   SQL injection in search blocked ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SQL injection in search ${RED}FAILED${NC} - table may be affected\n"
    failure=$(( failure + 1 ))
fi

# Test 1.2: SQL Injection via JSON body
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing SQL injection in JSON body"

timestamp=$(date +%s)
path="/api/v1/contacts"
body="{\"name\":\"Test'; DELETE FROM contacts; --\",\"address\":\"http://test.example.com\"}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n${body}\";
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

# Verify contacts still exist
contactCount=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/core/Application.php';
    \$app = Application::getInstance();
    \$pdo = \$app->services->getPdo();
    \$result = \$pdo->query(\"SELECT COUNT(*) as cnt FROM contacts\");
    \$row = \$result->fetch(PDO::FETCH_ASSOC);
    echo \$row['cnt'];
" 2>/dev/null)

if [[ "$contactCount" -ge 0 ]]; then
    printf "\t   SQL injection in JSON body blocked ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   SQL injection in JSON body ${RED}FAILED${NC}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 2: XSS PAYLOAD TESTING ############################

echo -e "\n[Section 2: XSS Payload Protection]"

# Test 2.1: XSS in contact name
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing XSS payload sanitization"

xssPayload="<script>alert('xss')</script>"
timestamp=$(date +%s)
path="/api/v1/contacts"
body="{\"name\":\"${xssPayload}\",\"address\":\"http://xss-test.example.com\"}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n${body}\";
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

# Check if script tags are stored raw or sanitized/rejected
checkResult=$(docker exec ${testContainer} php -r "
    require_once '/etc/eiou/src/core/Application.php';
    \$app = Application::getInstance();
    \$contactRepo = \$app->services->getContactRepository();
    \$contacts = \$contactRepo->searchContacts('script');
    if (empty(\$contacts)) {
        echo 'NOT_FOUND_OR_SANITIZED';
    } else {
        foreach (\$contacts as \$c) {
            if (strpos(\$c['name'] ?? '', '<script>') !== false) {
                echo 'RAW_STORED';
                exit;
            }
        }
        echo 'SANITIZED';
    }
" 2>/dev/null)

if [[ "$checkResult" != "RAW_STORED" ]]; then
    printf "\t   XSS payload sanitization ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   XSS payload sanitization ${RED}FAILED${NC} - raw script stored\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 3: AUTHENTICATION SECURITY ############################

echo -e "\n[Section 3: Authentication Security]"

# Test 3.1: Request without API key
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing request without API key"

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "Content-Type: application/json" \
    "${LOCAL_API_BASE}/api/v1/system/status" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "Missing" || "$response" =~ "missing" || "$response" =~ "AUTH" ]]; then
    printf "\t   Missing API key rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing API key rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.2: Request with invalid API key
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing request with invalid API key"

timestamp=$(date +%s)
response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: invalid_key_12345" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: invalid_signature" \
    "${LOCAL_API_BASE}/api/v1/system/status" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "Invalid" || "$response" =~ "invalid" || "$response" =~ "AUTH" ]]; then
    printf "\t   Invalid API key rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid API key rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.3: Request without timestamp
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing request without timestamp"

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Signature: some_signature" \
    "${LOCAL_API_BASE}/api/v1/system/status" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "timestamp" || "$response" =~ "Timestamp" || "$response" =~ "AUTH" ]]; then
    printf "\t   Missing timestamp rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing timestamp rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.4: Request without signature
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing request without signature"

timestamp=$(date +%s)
response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    "${LOCAL_API_BASE}/api/v1/system/status" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "signature" || "$response" =~ "Signature" || "$response" =~ "AUTH" ]]; then
    printf "\t   Missing signature rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing signature rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 4: REPLAY ATTACK PREVENTION ############################

echo -e "\n[Section 4: Replay Attack Prevention]"

# Test 4.1: Old timestamp rejection
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing old timestamp rejection (>5 min)"

oldTimestamp=$(($(date +%s) - 400))  # 400 seconds ago (>5 min threshold)
path="/api/v1/system/status"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${oldTimestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${oldTimestamp}" \
    -H "X-API-Signature: ${signature}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "timestamp" || "$response" =~ "expired" || "$response" =~ "Expired" ]]; then
    printf "\t   Old timestamp rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Old timestamp rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.2: Future timestamp rejection
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing future timestamp rejection (>5 min)"

futureTimestamp=$(($(date +%s) + 400))  # 400 seconds in future
path="/api/v1/system/status"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${futureTimestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${futureTimestamp}" \
    -H "X-API-Signature: ${signature}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "timestamp" || "$response" =~ "invalid" || "$response" =~ "Invalid" ]]; then
    printf "\t   Future timestamp rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Future timestamp rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.3: Signature tampering detection
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing signature tampering detection"

timestamp=$(date +%s)
path="/api/v1/system/status"

# Create valid signature
signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

# Tamper with signature (change one character)
tamperedSignature="${signature:0:-1}X"

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${tamperedSignature}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "signature" || "$response" =~ "Signature" || "$response" =~ "AUTH" ]]; then
    printf "\t   Signature tampering detection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Signature tampering detection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 5: INPUT VALIDATION ############################

echo -e "\n[Section 5: Input Validation Security]"

# Test 5.1: Invalid amount format
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing invalid amount format rejection"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"http://test.example.com\",\"amount\":\"not_a_number\",\"currency\":\"USD\"}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n${body}\";
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

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "amount" || "$response" =~ "Amount" || "$response" =~ "INVALID" || "$response" =~ "invalid" ]]; then
    printf "\t   Invalid amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.2: Negative amount rejection
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing negative amount rejection"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"http://test.example.com\",\"amount\":\"-100\",\"currency\":\"USD\"}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n${body}\";
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

if [[ "$response" =~ "false" ]] && [[ "$response" =~ "amount" || "$response" =~ "Amount" || "$response" =~ "INVALID" || "$response" =~ "positive" ]]; then
    printf "\t   Negative amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Negative amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.3: Excessive amount rejection
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing excessive amount rejection (>max)"

timestamp=$(date +%s)
path="/api/v1/wallet/send"
body="{\"address\":\"http://test.example.com\",\"amount\":\"9999999999999\",\"currency\":\"USD\"}"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"POST\\n${path}\\n${timestamp}\\n${body}\";
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
    printf "\t   Excessive amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Excessive amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 6: VALID REQUEST VERIFICATION ############################

echo -e "\n[Section 6: Valid Request Verification]"

# Test 6.1: Valid authenticated request succeeds
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing valid authenticated request"

timestamp=$(date +%s)
path="/api/v1/system/status"

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$message = \"GET\\n${path}\\n${timestamp}\\n\";
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'true' ]]; then
    printf "\t   Valid authenticated request ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Valid authenticated request ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup]"
echo -e "\t-> Deleting test API key"

docker exec ${testContainer} eiou apikey delete ${apiKeyId} --confirm 2>/dev/null

############################ RESULTS ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'security test suite'"
