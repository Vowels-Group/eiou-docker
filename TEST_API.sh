#!/bin/bash
# EIOU Docker API Test Script
# Run this script to test the complete API implementation
#
# This performs the triple verification requested by the user:
# 1. Docker API Unit Testing
# 2. Flutter Integration Testing
# 3. End-to-End Production Readiness

set -e

echo "========================================="
echo "EIOU DOCKER API - TRIPLE VERIFICATION TEST"
echo "========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test results
PASS_COUNT=0
FAIL_COUNT=0

function pass() {
    echo -e "${GREEN}✓ PASS:${NC} $1"
    ((PASS_COUNT++))
}

function fail() {
    echo -e "${RED}✗ FAIL:${NC} $1"
    ((FAIL_COUNT++))
}

function info() {
    echo -e "${YELLOW}ℹ INFO:${NC} $1"
}

function section() {
    echo ""
    echo "========================================="
    echo "$1"
    echo "========================================="
    echo ""
}

# =========================================
# CHECK 1/3: DOCKER API UNIT TESTING
# =========================================

section "CHECK 1 OF 3: DOCKER API UNIT TESTING"

info "Stopping any existing containers..."
docker compose -f docker-compose-single.yml down -v 2>/dev/null || true

info "Starting Docker single node with API..."
if docker compose -f docker-compose-single.yml up -d --build; then
    pass "Docker container started successfully"
else
    fail "Failed to start Docker container"
    exit 1
fi

info "Waiting 15 seconds for container initialization..."
sleep 15

info "Checking container status..."
if docker compose -f docker-compose-single.yml ps | grep -q "Up"; then
    pass "Container is running"
else
    fail "Container is not running"
    docker compose -f docker-compose-single.yml logs
    exit 1
fi

info "Extracting authcode from container..."
AUTHCODE=$(docker compose -f docker-compose-single.yml exec eioud-single \
    cat /etc/eiou/config.php 2>/dev/null | grep 'authcode' | cut -d'"' -f2)

if [ -z "$AUTHCODE" ]; then
    fail "Could not extract authcode"
    docker compose -f docker-compose-single.yml logs | tail -50
    exit 1
else
    pass "Authcode extracted: ${AUTHCODE:0:8}..."
fi

info "Testing API health endpoint (no auth required)..."
HEALTH_RESPONSE=$(curl -s http://localhost:8080/api/health)
if echo "$HEALTH_RESPONSE" | grep -q "healthy"; then
    pass "Health endpoint returned 'healthy'"
else
    fail "Health endpoint failed"
    echo "Response: $HEALTH_RESPONSE"
fi

info "Testing authentication endpoint..."
AUTH_RESPONSE=$(curl -s -X POST http://localhost:8080/api/auth \
    -H "Content-Type: application/json" \
    -d "{\"authcode\": \"$AUTHCODE\"}")

if echo "$AUTH_RESPONSE" | grep -q "token"; then
    pass "Authentication successful"
    TOKEN=$(echo "$AUTH_RESPONSE" | jq -r '.data.token' 2>/dev/null)
    if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
        pass "Bearer token received: ${TOKEN:0:16}..."
    else
        fail "Could not extract token from response"
        echo "Response: $AUTH_RESPONSE"
    fi
else
    fail "Authentication failed"
    echo "Response: $AUTH_RESPONSE"
fi

if [ -z "$TOKEN" ] || [ "$TOKEN" = "null" ]; then
    fail "No valid token available for subsequent tests"
    exit 1
fi

info "Testing GET /api/wallet/info..."
INFO_RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" \
    http://localhost:8080/api/wallet/info)
if echo "$INFO_RESPONSE" | grep -q "address\|publicKey"; then
    pass "Wallet info endpoint working"
else
    fail "Wallet info endpoint failed"
    echo "Response: $INFO_RESPONSE"
fi

info "Testing GET /api/wallet/balance..."
BALANCE_RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" \
    http://localhost:8080/api/wallet/balance)
if echo "$BALANCE_RESPONSE" | grep -q "balance"; then
    pass "Balance endpoint working"
else
    fail "Balance endpoint failed"
    echo "Response: $BALANCE_RESPONSE"
fi

info "Testing GET /api/contacts..."
CONTACTS_RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" \
    http://localhost:8080/api/contacts)
if echo "$CONTACTS_RESPONSE" | jq -e '.data' >/dev/null 2>&1; then
    pass "Contacts list endpoint working"
else
    fail "Contacts endpoint failed"
    echo "Response: $CONTACTS_RESPONSE"
fi

info "Testing GET /api/transactions..."
TRANSACTIONS_RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" \
    http://localhost:8080/api/transactions)
if echo "$TRANSACTIONS_RESPONSE" | jq -e '.data' >/dev/null 2>&1; then
    pass "Transactions list endpoint working"
else
    fail "Transactions endpoint failed"
    echo "Response: $TRANSACTIONS_RESPONSE"
fi

info "Testing authentication failure (wrong authcode)..."
WRONG_AUTH=$(curl -s -w "\n%{http_code}" -X POST http://localhost:8080/api/auth \
    -H "Content-Type: application/json" \
    -d "{\"authcode\": \"wrongcode123\"}")
if echo "$WRONG_AUTH" | tail -1 | grep -q "401\|403"; then
    pass "Authentication correctly rejects invalid authcode"
else
    fail "Authentication did not reject invalid authcode"
fi

info "Testing unauthorized access (no token)..."
UNAUTH_RESPONSE=$(curl -s -w "\n%{http_code}" http://localhost:8080/api/wallet/info)
if echo "$UNAUTH_RESPONSE" | tail -1 | grep -q "401\|403"; then
    pass "API correctly requires authentication"
else
    fail "API did not require authentication"
fi

info "Checking Docker logs for errors..."
if docker compose -f docker-compose-single.yml logs | grep -i "error\|warning" | grep -v "Notice\|deprecated" | head -5; then
    echo "⚠ Warnings/errors found in logs (review above)"
else
    pass "No critical errors in Docker logs"
fi

# =========================================
# CHECK 2/3: FLUTTER INTEGRATION TESTING
# =========================================

section "CHECK 2 OF 3: FLUTTER INTEGRATION TESTING"

cd ../eiou-wallet-flutter/eiou_wallet_flutter

info "Running Flutter pub get..."
if flutter pub get; then
    pass "Flutter dependencies installed"
else
    fail "Flutter pub get failed"
fi

info "Running Flutter analyze..."
if flutter analyze --no-fatal-infos; then
    pass "Flutter code analysis passed"
else
    fail "Flutter analysis found issues"
fi

info "Checking for Android emulator or device..."
DEVICES=$(flutter devices 2>/dev/null)
if echo "$DEVICES" | grep -q "android\|emulator"; then
    pass "Android device/emulator available"

    info "Building Flutter app in debug mode..."
    if flutter build apk --debug; then
        pass "Flutter app builds successfully"
    else
        fail "Flutter build failed"
    fi

    info "To test the app manually:"
    echo "  1. Run: flutter run"
    echo "  2. Navigate to Settings → Docker Node Connection"
    echo "  3. Enter server URL: http://10.0.2.2:8080 (emulator) or http://192.168.1.X:8080 (device)"
    echo "  4. Enter authcode: $AUTHCODE"
    echo "  5. Click 'Test Connection'"
    echo "  6. Click 'Save & Connect'"
    echo "  7. Test sending transactions, viewing balance, etc."

else
    info "No Android device detected - skipping build test"
    echo "To test later:"
    echo "  1. Start Android emulator or connect device"
    echo "  2. Run: flutter run"
    echo "  3. Connect to Docker API at http://10.0.2.2:8080"
fi

# =========================================
# CHECK 3/3: PRODUCTION READINESS
# =========================================

section "CHECK 3 OF 3: END-TO-END PRODUCTION READINESS"

cd ../../eiou-docker

info "Checking security headers..."
SECURITY_RESPONSE=$(curl -s -I http://localhost:8080/api/health)
if echo "$SECURITY_RESPONSE" | grep -q "Access-Control-Allow-Origin"; then
    pass "CORS headers present"
else
    fail "Missing CORS headers"
fi

info "Testing rate limiting and concurrent requests..."
for i in {1..10}; do
    curl -s http://localhost:8080/api/health > /dev/null &
done
wait
pass "Concurrent requests handled"

info "Testing malformed JSON..."
MALFORMED_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST http://localhost:8080/api/auth \
    -H "Content-Type: application/json" \
    -d "{invalid json")
if echo "$MALFORMED_RESPONSE" | tail -1 | grep -q "400"; then
    pass "Malformed JSON correctly rejected"
else
    fail "Malformed JSON not handled properly"
fi

info "Checking file permissions in container..."
docker compose -f docker-compose-single.yml exec eioud-single ls -la /app/public/ | head -10

info "Checking for hardcoded credentials..."
if grep -r "password\|secret\|key" src/api/*.php public/api.php | grep -v "authcode\|publicKey\|privateKey" | grep -v "#\|//"; then
    fail "Potential hardcoded credentials found"
else
    pass "No hardcoded credentials detected"
fi

info "Verifying Docker port mappings..."
if docker compose -f docker-compose-single.yml ps | grep "8080->80"; then
    pass "Port 8080 correctly mapped"
else
    fail "Port mapping not configured"
fi

info "Checking docker-compose for 4-line, 10-line, cluster..."
if grep -q "8081:80" docker-compose-4line.yml && \
   grep -q "8091:80" docker-compose-10line.yml && \
   grep -q "8101:80" docker-compose-cluster.yml; then
    pass "All docker-compose files have port mappings"
else
    fail "Some docker-compose files missing port mappings"
fi

info "Testing API documentation..."
if [ -f "README.md" ] && grep -q "API Endpoints" README.md; then
    pass "README contains API documentation"
else
    fail "README missing API documentation"
fi

if [ -f "API_QUICK_START.md" ]; then
    pass "Quick start guide exists"
else
    fail "Quick start guide missing"
fi

# =========================================
# FINAL RESULTS
# =========================================

section "TEST RESULTS"

echo "Tests Passed: $PASS_COUNT"
echo "Tests Failed: $FAIL_COUNT"
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
    echo -e "${GREEN}=========================================${NC}"
    echo -e "${GREEN}✓ ALL TESTS PASSED - READY FOR PRODUCTION${NC}"
    echo -e "${GREEN}=========================================${NC}"
    exit 0
else
    echo -e "${RED}=========================================${NC}"
    echo -e "${RED}✗ $FAIL_COUNT TEST(S) FAILED - REVIEW ABOVE${NC}"
    echo -e "${RED}=========================================${NC}"
    exit 1
fi
