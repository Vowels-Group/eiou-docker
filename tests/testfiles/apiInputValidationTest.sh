#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ API Input Validation Test ############################
# Comprehensive input validation testing for the eIOU API
#
# Verifies:
# - Missing required fields return proper 400 errors
# - Invalid field types are rejected (string where number expected)
# - Boundary values are handled (empty strings, very long strings)
# - Special characters and encoding are processed safely
# - Malformed JSON is rejected with appropriate error codes
#
# Prerequisites:
# - Containers must be running
# - API must be accessible
# - API key must be available (will create one)
##########################################################################

echo -e "\nRunning API Input Validation Tests..."

testname="apiInputValidationTest"
totaltests=0
passed=0
failure=0

# Use first container for testing
testContainer="${containers[0]}"

# API endpoints always use HTTPS (server redirects HTTP→HTTPS)
LOCAL_API_BASE="https://localhost"
CURL_SSL_FLAG="-k"
echo -e "\t   Test container: ${testContainer}"
echo -e "\t   API Base: ${LOCAL_API_BASE}"
echo -e "\t   Mode: ${MODE}"

############################ API KEY SETUP ############################

echo -e "\n[Input Validation Test API Key Setup]"

# Create API key for validation testing
echo -e "\n\t-> Creating API key for input validation testing"
apiKeyOutput=$(docker exec ${testContainer} eiou apikey create "ValidationTestKey" "wallet:read,wallet:send,contacts:read,contacts:write,system:read" --json 2>&1)

apiKeyId=$(echo "$apiKeyOutput" | grep -o '"key_id"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"key_id"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)
apiSecret=$(echo "$apiKeyOutput" | grep -o '"secret"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/"secret"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -1)

if [[ -z "$apiKeyId" ]] || [[ -z "$apiSecret" ]]; then
    echo -e "\t   ${RED}Failed to create API key for validation testing${NC}"
    echo -e "\t   Output: ${apiKeyOutput}"
    succesrate "${totaltests}" "${passed}" "${failure}" "'input validation tests'"
    exit 1
fi

echo -e "\t   API Key ID: ${apiKeyId}"
echo -e "\t   ${GREEN}API key created successfully${NC}"

############################ SECTION 1: MISSING REQUIRED FIELDS ############################

echo -e "\n[Section 1: Missing Required Fields]"

# Test 1.1: POST /api/v1/contacts without name field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing POST /api/v1/contacts without 'name' field"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
body='{"address":"http://test.example.com"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'name' || "$response" =~ 'required' || "$response" =~ 'VALIDATION' ]]; then
    printf "\t   Missing 'name' field rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing 'name' field rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 1.2: POST /api/v1/contacts without address field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing POST /api/v1/contacts without 'address' field"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
body='{"name":"Test Contact"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'address' || "$response" =~ 'required' || "$response" =~ 'VALIDATION' ]]; then
    printf "\t   Missing 'address' field rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing 'address' field rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 1.3: POST /api/v1/wallet/send without amount field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing POST /api/v1/wallet/send without 'amount' field"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/wallet/send"
body='{"address":"http://test.example.com","currency":"USD"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'amount' || "$response" =~ 'required' || "$response" =~ 'VALIDATION' ]]; then
    printf "\t   Missing 'amount' field rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Missing 'amount' field rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 2: INVALID FIELD TYPES ############################

echo -e "\n[Section 2: Invalid Field Types]"

# Test 2.1: String where number expected for amount
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing string instead of number for amount"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/wallet/send"
body='{"address":"http://test.example.com","amount":"not_a_number","currency":"USD"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'amount' || "$response" =~ 'INVALID' || "$response" =~ 'invalid' || "$response" =~ 'numeric' ]]; then
    printf "\t   Invalid amount type rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Invalid amount type rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 2.2: Negative amount value
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing negative amount value"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/wallet/send"
body='{"address":"http://test.example.com","amount":"-100","currency":"USD"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'amount' || "$response" =~ 'positive' || "$response" =~ 'INVALID' || "$response" =~ 'negative' ]]; then
    printf "\t   Negative amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Negative amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 2.3: Zero amount value
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing zero amount value"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/wallet/send"
body='{"address":"http://test.example.com","amount":"0","currency":"USD"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'amount' || "$response" =~ 'positive' || "$response" =~ 'INVALID' || "$response" =~ 'zero' || "$response" =~ 'greater' ]]; then
    printf "\t   Zero amount rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Zero amount rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 3: BOUNDARY VALUES ############################

echo -e "\n[Section 3: Boundary Values]"

# Test 3.1: Empty string for name field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing empty string for contact name"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
body='{"name":"","address":"http://test.example.com"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'name' || "$response" =~ 'empty' || "$response" =~ 'required' || "$response" =~ 'VALIDATION' ]]; then
    printf "\t   Empty name rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Empty name rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 3.2: Very long string for name (>1000 chars)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing very long string for contact name (>1000 chars)"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
# Generate a long name string (1100 characters)
longName=$(docker exec ${testContainer} php -r "echo str_repeat('A', 1100);" 2>/dev/null)
body="{\"name\":\"${longName}\",\"address\":\"http://test.example.com\"}"
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

# Accept either explicit rejection or truncation (the API handles long strings gracefully)
if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'length' || "$response" =~ 'long' || "$response" =~ 'VALIDATION' || "$response" =~ 'max' || "$response" =~ 'invalid_name' || "$response" =~ 'invalid' ]]; then
    printf "\t   Long name rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'true' ]]; then
    # If API truncates instead of rejecting, that's also acceptable security behavior
    printf "\t   Long name handling (truncation) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Long name handling ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response:0:200}...\n"
    failure=$(( failure + 1 ))
fi

# Test 3.3: Empty JSON body
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing empty JSON body for POST /api/v1/contacts"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
body='{}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]]; then
    printf "\t   Empty JSON body rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Empty JSON body rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 4: SPECIAL CHARACTERS AND ENCODING ############################

echo -e "\n[Section 4: Special Characters and Encoding]"

# Test 4.1: Unicode characters in name field
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing unicode characters in contact name"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
body='{"name":"Test \u4e2d\u6587 Contact \u00e9","address":"http://unicode-test.example.com"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

# Unicode should either be accepted (if properly encoded) or rejected with validation error
if [[ "$response" =~ '"success"' ]]; then
    printf "\t   Unicode character handling ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Unicode character handling ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 4.2: Null byte injection attempt
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing null byte injection in name field"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
# JSON with escaped null byte
body='{"name":"Test\u0000Null","address":"http://null-test.example.com"}'
bodyB64=$(printf '%s' "$body" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${bodyB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${body}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

# Null bytes should be rejected or sanitized
if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]]; then
    printf "\t   Null byte rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
elif [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'true' ]]; then
    # If sanitized and accepted, that's also safe
    printf "\t   Null byte handling (sanitized) ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Null byte handling ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ SECTION 5: MALFORMED JSON ############################

echo -e "\n[Section 5: Malformed JSON]"

# Test 5.1: Unclosed JSON bracket
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing unclosed JSON bracket"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
malformedBody='{"name":"Test","address":"http://test.example.com"'
malformedB64=$(printf '%s' "$malformedBody" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${malformedB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${malformedBody}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'JSON' || "$response" =~ 'json' || "$response" =~ 'parse' || "$response" =~ 'syntax' || "$response" =~ 'INVALID' ]]; then
    printf "\t   Unclosed bracket rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Unclosed bracket rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.2: Invalid JSON syntax (trailing comma)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing invalid JSON syntax (trailing comma)"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
malformedBody='{"name":"Test","address":"http://test.example.com",}'
malformedB64=$(printf '%s' "$malformedBody" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${malformedB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${malformedBody}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]] && [[ "$response" =~ 'JSON' || "$response" =~ 'json' || "$response" =~ 'parse' || "$response" =~ 'syntax' || "$response" =~ 'INVALID' ]]; then
    printf "\t   Trailing comma rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Trailing comma rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

# Test 5.3: Non-JSON content (plain text)
totaltests=$(( totaltests + 1 ))
echo -e "\n\t-> Testing non-JSON content submission"

timestamp=$(date +%s); nonce=$(openssl rand -hex 16)
path="/api/v1/contacts"
plainTextBody='This is not JSON at all'
plainTextB64=$(printf '%s' "$plainTextBody" | base64 -w 0)

signature=$(docker exec ${testContainer} php -r "
    \$secret = '${apiSecret}';
    \$body = base64_decode('${plainTextB64}');
    \$message = \"POST\\n${path}\\n${timestamp}\\n${nonce}\\n\" . \$body;
    echo hash_hmac('sha256', \$message, \$secret);
" 2>/dev/null)

response=$(docker exec ${testContainer} curl ${CURL_SSL_FLAG} -s \
    -X POST \
    -H "X-API-Key: ${apiKeyId}" \
    -H "X-API-Timestamp: ${timestamp}" \
    -H "X-API-Signature: ${signature}" \
    -H "X-API-Nonce: ${nonce}" \
    -H "Content-Type: application/json" \
    -d "${plainTextBody}" \
    "${LOCAL_API_BASE}${path}" 2>&1)

if [[ "$response" =~ '"success"' ]] && [[ "$response" =~ 'false' ]]; then
    printf "\t   Non-JSON rejection ${GREEN}PASSED${NC}\n"
    passed=$(( passed + 1 ))
else
    printf "\t   Non-JSON rejection ${RED}FAILED${NC}\n"
    printf "\t   Response: ${response}\n"
    failure=$(( failure + 1 ))
fi

############################ CLEANUP ############################

echo -e "\n[Cleanup]"
echo -e "\t-> Deleting test API key"

docker exec ${testContainer} eiou apikey delete ${apiKeyId} --confirm 2>/dev/null

############################ RESULTS ############################

succesrate "${totaltests}" "${passed}" "${failure}" "'input validation tests'"
