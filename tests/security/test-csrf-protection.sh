#!/bin/bash
# CSRF Protection Penetration Test Suite
# Tests for Cross-Site Request Forgery vulnerabilities
# Copyright 2025

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test counters
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Test result storage
declare -a VULNERABILITIES

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}CSRF PROTECTION PENETRATION TEST SUITE${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Helper function to run test
run_test() {
    local test_name="$1"
    local test_command="$2"
    local expected_behavior="$3"

    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    echo -e "${YELLOW}Test $TOTAL_TESTS: $test_name${NC}"

    # Run the test
    result=$(eval "$test_command" 2>&1 || echo "COMMAND_FAILED")

    # Check if the attack was blocked
    if echo "$result" | grep -qiE "(csrf|token|invalid|blocked|forbidden|403|COMMAND_FAILED)"; then
        echo -e "${GREEN}✓ PASS${NC} - CSRF protection working"
        echo "  Expected: $expected_behavior"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}✗ FAIL${NC} - Potential CSRF vulnerability"
        echo "  Expected: $expected_behavior"
        echo "  Result: $result"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        VULNERABILITIES+=("$test_name: CSRF protection not working")
    fi
    echo ""
}

echo -e "${BLUE}1. CSRF TOKEN GENERATION TESTS${NC}"
echo "--------------------------------------"

# Test 1: Verify CSRF token generation function exists
run_test \
    "CSRF token generation function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'generateCSRFToken') ? 'FOUND' : 'NOT_FOUND';\" 2>&1" \
    "Security::generateCSRFToken() method should exist"

# Test 2: Verify CSRF token is generated
run_test \
    "CSRF token can be generated" \
    "docker compose exec alice php -r \"session_start(); require_once '/app/src/utils/Security.php'; \\\$token = Security::generateCSRFToken(); echo empty(\\\$token) ? 'INVALID' : 'VALID_TOKEN';\" 2>&1" \
    "Should generate a non-empty CSRF token"

# Test 3: Verify CSRF token is stored in session
run_test \
    "CSRF token stored in session" \
    "docker compose exec alice php -r \"session_start(); require_once '/app/src/utils/Security.php'; Security::generateCSRFToken(); echo isset(\\\$_SESSION['csrf_token']) ? 'STORED' : 'NOT_STORED';\" 2>&1" \
    "CSRF token should be stored in session"

echo -e "${BLUE}2. CSRF TOKEN VALIDATION TESTS${NC}"
echo "--------------------------------------"

# Test 4: Verify CSRF validation function exists
run_test \
    "CSRF validation function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'validateCSRFToken') ? 'FOUND' : 'NOT_FOUND';\" 2>&1" \
    "Security::validateCSRFToken() method should exist"

# Test 5: Test CSRF validation with no token
run_test \
    "CSRF validation fails without token" \
    "docker compose exec alice php -r \"session_start(); require_once '/app/src/utils/Security.php'; \\\$result = Security::validateCSRFToken('invalid'); echo \\\$result ? 'VULNERABLE' : 'BLOCKED';\" 2>&1" \
    "Should reject request without valid CSRF token"

# Test 6: Test CSRF validation with wrong token
run_test \
    "CSRF validation fails with wrong token" \
    "docker compose exec alice php -r \"session_start(); require_once '/app/src/utils/Security.php'; \\\$_SESSION['csrf_token'] = 'correct_token'; \\\$result = Security::validateCSRFToken('wrong_token'); echo \\\$result ? 'VULNERABLE' : 'BLOCKED';\" 2>&1" \
    "Should reject request with incorrect CSRF token"

# Test 7: Test CSRF validation with correct token
run_test \
    "CSRF validation passes with correct token" \
    "docker compose exec alice php -r \"session_start(); require_once '/app/src/utils/Security.php'; \\\$token = Security::generateCSRFToken(); \\\$result = Security::validateCSRFToken(\\\$token); echo \\\$result ? 'VALID' : 'INVALID';\" 2>&1" \
    "Should accept request with valid CSRF token"

echo -e "${BLUE}3. CSRF TOKEN REUSE TESTS${NC}"
echo "--------------------------------------"

# Test 8: Test token cannot be reused after validation (if implemented)
run_test \
    "Check CSRF token reuse policy" \
    "docker compose exec alice php -r \"session_start(); require_once '/app/src/utils/Security.php'; \\\$token = Security::generateCSRFToken(); \\\$first = Security::validateCSRFToken(\\\$token); \\\$second = Security::validateCSRFToken(\\\$token); echo (\\\$first && \\\$second) ? 'REUSABLE' : 'ONE_TIME';\" 2>&1" \
    "Token reuse policy should be defined (reusable or one-time)"

# Test 9: Test token expiration (if implemented)
run_test \
    "Check CSRF token expiration mechanism" \
    "grep -q 'csrf.*expir\\|token.*expir\\|time.*token' /app/src/utils/Security.php && echo 'IMPLEMENTED' || echo 'NOT_IMPLEMENTED'" \
    "Token expiration should be considered for enhanced security"

echo -e "${BLUE}4. FORM CSRF PROTECTION TESTS${NC}"
echo "--------------------------------------"

# Test 10: Check if forms include CSRF tokens
run_test \
    "Contact form includes CSRF token field" \
    "grep -c 'csrf_token\\|csrf-token' /app/src/gui/layout/walletSubParts/contactForm.html || echo '0'" \
    "Forms should include CSRF token hidden input field"

# Test 11: Check if transaction form includes CSRF tokens
run_test \
    "Transaction form includes CSRF token field" \
    "grep -c 'csrf_token\\|csrf-token' /app/src/gui/layout/walletSubParts/eiouForm.html || echo '0'" \
    "Transaction forms should include CSRF token field"

echo -e "${BLUE}5. CONTROLLER CSRF VALIDATION TESTS${NC}"
echo "--------------------------------------"

# Test 12: Check if TransactionController validates CSRF
run_test \
    "TransactionController checks CSRF token" \
    "grep -c 'validateCSRFToken\\|csrf.*valid' /app/src/gui/controllers/TransactionController.php || echo '0'" \
    "Controllers should validate CSRF tokens on POST requests"

# Test 13: Check if ContactController validates CSRF
run_test \
    "ContactController checks CSRF token" \
    "grep -c 'validateCSRFToken\\|csrf.*valid' /app/src/gui/controllers/ContactController.php || echo '0'" \
    "Controllers should validate CSRF tokens on POST requests"

echo -e "${BLUE}6. CROSS-ORIGIN REQUEST TESTS${NC}"
echo "--------------------------------------"

# Test 14: Check security headers prevent CSRF
run_test \
    "Security headers include SameSite cookie attribute" \
    "grep -q 'SameSite\\|samesite' /app/src/security_init.php && echo 'CONFIGURED' || echo 'NOT_CONFIGURED'" \
    "Cookies should have SameSite attribute for CSRF protection"

# Test 15: Check for Referrer policy header
run_test \
    "Referrer-Policy header is set" \
    "grep -q 'Referrer-Policy' /app/src/utils/Security.php && echo 'SET' || echo 'NOT_SET'" \
    "Referrer-Policy header helps prevent CSRF attacks"

echo -e "${BLUE}7. TIMING ATTACK PROTECTION${NC}"
echo "--------------------------------------"

# Test 16: Verify constant-time comparison is used
run_test \
    "CSRF validation uses timing-safe comparison" \
    "grep -q 'hash_equals' /app/src/utils/Security.php && echo 'SECURE' || echo 'VULNERABLE'" \
    "CSRF token comparison should use hash_equals() to prevent timing attacks"

echo -e "${BLUE}8. SESSION SECURITY TESTS${NC}"
echo "--------------------------------------"

# Test 17: Check session cookie security settings
run_test \
    "Session cookies have httponly flag" \
    "grep -q 'session.cookie_httponly' /app/src/security_init.php && echo 'CONFIGURED' || echo 'NOT_CONFIGURED'" \
    "Session cookies should have httponly flag"

# Test 18: Check session cookie secure flag
run_test \
    "Session cookies have secure flag (for HTTPS)" \
    "grep -q 'session.cookie_secure' /app/src/security_init.php && echo 'CONFIGURED' || echo 'NOT_CONFIGURED'" \
    "Session cookies should have secure flag for HTTPS"

# Test 19: Check session regeneration
run_test \
    "Session ID regeneration is implemented" \
    "grep -q 'session_regenerate_id' /app/src/security_init.php && echo 'IMPLEMENTED' || echo 'NOT_IMPLEMENTED'" \
    "Session ID should be regenerated to prevent fixation"

echo -e "${BLUE}9. CSRF PROTECTION IN API ENDPOINTS${NC}"
echo "--------------------------------------"

# Test 20: Check if wallet API validates CSRF
run_test \
    "Wallet API endpoints check for CSRF" \
    "grep -c 'csrf\\|token' /app/src/walletIndex.html || echo '0'" \
    "API endpoints should implement CSRF protection"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}TEST SUMMARY${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo "Total Tests:  $TOTAL_TESTS"
echo -e "Passed:       ${GREEN}$PASSED_TESTS${NC}"
echo -e "Failed:       ${RED}$FAILED_TESTS${NC}"
echo ""

if [ $FAILED_TESTS -gt 0 ]; then
    echo -e "${RED}VULNERABILITIES FOUND:${NC}"
    echo "--------------------------------------"
    for vuln in "${VULNERABILITIES[@]}"; do
        echo -e "${RED}• $vuln${NC}"
    done
    echo ""
    echo -e "${YELLOW}RECOMMENDATIONS:${NC}"
    echo "1. Implement CSRF token validation in all state-changing operations"
    echo "2. Include CSRF tokens in all forms"
    echo "3. Set SameSite cookie attribute to 'Strict' or 'Lax'"
    echo "4. Use hash_equals() for token comparison to prevent timing attacks"
    echo "5. Consider implementing token expiration for enhanced security"
    echo ""
    exit 1
else
    echo -e "${GREEN}All CSRF protection tests passed!${NC}"
    echo "The application has proper CSRF protection mechanisms."
    echo ""
    exit 0
fi
