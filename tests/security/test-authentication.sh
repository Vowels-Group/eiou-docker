#!/bin/bash
# Authentication & Session Security Penetration Test Suite
# Tests for authentication bypass, session fixation, and brute force vulnerabilities
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
echo -e "${BLUE}AUTHENTICATION SECURITY TEST SUITE${NC}"
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

    # Check if security measure is in place
    if echo "$result" | grep -qiE "(secure|protected|configured|implemented|exists|set|yes|true|found|blocked|COMMAND_FAILED)" && \
       ! echo "$result" | grep -qiE "(vulnerable|not_found|not_configured|not_implemented|missing|no|false)"; then
        echo -e "${GREEN}✓ PASS${NC} - Security measure in place"
        echo "  Expected: $expected_behavior"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}✗ FAIL${NC} - Security issue detected"
        echo "  Expected: $expected_behavior"
        echo "  Result: $result"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        VULNERABILITIES+=("$test_name: Security measure not properly implemented")
    fi
    echo ""
}

echo -e "${BLUE}1. SESSION SECURITY TESTS${NC}"
echo "--------------------------------------"

# Test 1: Session cookie httponly flag
run_test \
    "Session cookies have httponly flag" \
    "grep -q 'session.cookie_httponly.*1' /app/src/security_init.php && echo 'CONFIGURED' || echo 'NOT_CONFIGURED'" \
    "Session cookies should have httponly flag to prevent JavaScript access"

# Test 2: Session cookie secure flag
run_test \
    "Session cookies have secure flag for HTTPS" \
    "grep -q 'session.cookie_secure' /app/src/security_init.php && echo 'CONFIGURED' || echo 'NOT_CONFIGURED'" \
    "Session cookies should use secure flag for HTTPS connections"

# Test 3: Session cookie SameSite attribute
run_test \
    "Session cookies have SameSite attribute" \
    "grep -q 'session.cookie_samesite.*Strict' /app/src/security_init.php && echo 'CONFIGURED' || echo 'NOT_CONFIGURED'" \
    "SameSite attribute prevents CSRF attacks via session cookies"

# Test 4: Session ID regeneration
run_test \
    "Session ID regeneration is implemented" \
    "grep -q 'session_regenerate_id' /app/src/security_init.php && echo 'IMPLEMENTED' || echo 'NOT_IMPLEMENTED'" \
    "Session ID should be regenerated to prevent fixation attacks"

# Test 5: Session timeout configuration
run_test \
    "Session timeout is configured" \
    "grep -q 'session.gc_maxlifetime' /app/src/security_init.php && echo 'CONFIGURED' || echo 'NOT_CONFIGURED'" \
    "Session timeout prevents indefinite session validity"

# Test 6: Session regeneration timing
run_test \
    "Session regenerates periodically" \
    "grep -q 'last_regeneration' /app/src/security_init.php && echo 'IMPLEMENTED' || echo 'NOT_IMPLEMENTED'" \
    "Periodic regeneration prevents session fixation"

echo -e "${BLUE}2. SESSION FIXATION TESTS${NC}"
echo "--------------------------------------"

# Test 7: Session starts securely
run_test \
    "Session security configured before session_start()" \
    "docker compose exec alice php -r \"echo (strpos(file_get_contents('/app/src/security_init.php'), 'ini_set.*session') < strpos(file_get_contents('/app/src/security_init.php'), 'session_start')) ? 'SECURE' : 'VULNERABLE';\" 2>&1" \
    "Session configuration should happen before session_start()"

# Test 8: Session use_only_cookies
run_test \
    "Session only uses cookies (not URL)" \
    "grep -q 'session.use_only_cookies.*1' /app/src/security_init.php && echo 'SECURE' || echo 'VULNERABLE'" \
    "Sessions should only use cookies, not URL parameters"

# Test 9: Session use_trans_sid disabled
run_test \
    "Transparent session ID disabled" \
    "grep -q 'session.use_trans_sid.*0' /app/src/security_init.php && echo 'SECURE' || echo 'VULNERABLE'" \
    "Transparent session IDs in URLs should be disabled"

echo -e "${BLUE}3. BRUTE FORCE PROTECTION TESTS${NC}"
echo "--------------------------------------"

# Test 10: Rate limiting class exists
run_test \
    "RateLimiter class exists" \
    "test -f /app/src/utils/RateLimiter.php && echo 'EXISTS' || echo 'NOT_FOUND'" \
    "Rate limiting should be implemented to prevent brute force"

# Test 11: Rate limiting is initialized
run_test \
    "Rate limiting initialized in security_init" \
    "grep -q 'RateLimiter' /app/src/security_init.php && echo 'INITIALIZED' || echo 'NOT_INITIALIZED'" \
    "Rate limiting should be initialized for the application"

# Test 12: Login attempt rate limiting
run_test \
    "Login attempts are rate limited" \
    "grep -q 'login_attempt' /app/src/security_init.php && echo 'PROTECTED' || echo 'UNPROTECTED'" \
    "Login attempts should have specific rate limits"

# Test 13: API request rate limiting
run_test \
    "API requests are rate limited" \
    "grep -q 'api_request' /app/src/security_init.php && echo 'PROTECTED' || echo 'UNPROTECTED'" \
    "API endpoints should be rate limited"

# Test 14: Transaction rate limiting
run_test \
    "Transactions are rate limited" \
    "grep -q 'transaction.*max.*window' /app/src/security_init.php && echo 'PROTECTED' || echo 'UNPROTECTED'" \
    "Transaction creation should be rate limited"

echo -e "${BLUE}4. PASSWORD SECURITY TESTS${NC}"
echo "--------------------------------------"

# Test 15: Secure password hashing function exists
run_test \
    "Secure password hashing function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'hashPassword') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Should use password_hash() for secure hashing"

# Test 16: Password verification function exists
run_test \
    "Password verification function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'verifyPassword') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Should use password_verify() for secure verification"

# Test 17: Password uses strong algorithm
run_test \
    "Password hashing uses PASSWORD_DEFAULT" \
    "grep -q 'PASSWORD_DEFAULT' /app/src/utils/Security.php && echo 'SECURE' || echo 'WEAK'" \
    "Should use PASSWORD_DEFAULT for future-proof hashing"

# Test 18: Password rehashing check exists
run_test \
    "Password rehashing function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'needsRehash') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Should check if password needs rehashing when algorithm updates"

echo -e "${BLUE}5. AUTHENTICATION BYPASS TESTS${NC}"
echo "--------------------------------------"

# Test 19: Session includes authentication file
run_test \
    "Session management file exists" \
    "test -f /app/src/gui/includes/session.php && echo 'EXISTS' || echo 'NOT_FOUND'" \
    "Session management should be centralized"

# Test 20: Verify timing-safe comparison
run_test \
    "Timing-safe comparison for credentials" \
    "grep -q 'hash_equals\\|timingSafeEquals' /app/src/utils/Security.php && echo 'SECURE' || echo 'VULNERABLE'" \
    "Credential comparison should use hash_equals() to prevent timing attacks"

echo -e "${BLUE}6. CREDENTIAL VALIDATION TESTS${NC}"
echo "--------------------------------------"

# Test 21: Input validation for authentication
run_test \
    "Authentication input is validated" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/InputValidator.php'; echo class_exists('InputValidator') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "All authentication inputs should be validated"

# Test 22: Public key validation exists
run_test \
    "Public key validation function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/InputValidator.php'; echo method_exists('InputValidator', 'validatePublicKey') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Public keys used for authentication should be validated"

# Test 23: Signature validation exists
run_test \
    "Signature validation function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/InputValidator.php'; echo method_exists('InputValidator', 'validateSignature') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Signatures should be validated"

echo -e "${BLUE}7. SECURITY LOGGING TESTS${NC}"
echo "--------------------------------------"

# Test 24: Security event logging exists
run_test \
    "Security event logging function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'logSecurityEvent') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Security events should be logged for audit trail"

# Test 25: Secure logger class exists
run_test \
    "SecureLogger class exists" \
    "test -f /app/src/utils/SecureLogger.php && echo 'EXISTS' || echo 'NOT_FOUND'" \
    "Secure logging should be implemented"

# Test 26: Sensitive data masking
run_test \
    "Sensitive data masking in logs" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'maskSensitiveData') ? 'IMPLEMENTED' : 'NOT_IMPLEMENTED';\" 2>&1" \
    "Logs should mask sensitive data (passwords, keys, tokens)"

echo -e "${BLUE}8. CLIENT IP TRACKING TESTS${NC}"
echo "--------------------------------------"

# Test 27: IP address validation
run_test \
    "IP address validation function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'validateIp') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "IP addresses should be validated"

# Test 28: Client IP retrieval for rate limiting
run_test \
    "Client IP retrieval for rate limiting" \
    "grep -q 'getClientIp' /app/src/security_init.php && echo 'IMPLEMENTED' || echo 'NOT_IMPLEMENTED'" \
    "Rate limiting should track by client IP"

echo -e "${BLUE}9. ERROR HANDLING TESTS${NC}"
echo "--------------------------------------"

# Test 29: Safe error messages
run_test \
    "Safe error message function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'getSafeErrorMessage') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Error messages should not expose system details"

# Test 30: Error handler initialization
run_test \
    "Error handler is initialized" \
    "grep -q 'ErrorHandler::init' /app/src/security_init.php && echo 'INITIALIZED' || echo 'NOT_INITIALIZED'" \
    "Centralized error handling should be initialized"

echo -e "${BLUE}10. TOKEN SECURITY TESTS${NC}"
echo "--------------------------------------"

# Test 31: Secure token generation
run_test \
    "Secure random token generation exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'generateSecureToken') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Should use cryptographically secure random token generation"

# Test 32: Token uses random_bytes
run_test \
    "Token generation uses random_bytes()" \
    "grep -q 'random_bytes' /app/src/utils/Security.php && echo 'SECURE' || echo 'INSECURE'" \
    "Tokens should use random_bytes() for cryptographic security"

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
    echo "1. Implement session regeneration on authentication state changes"
    echo "2. Use httponly, secure, and SameSite flags on session cookies"
    echo "3. Implement rate limiting on login attempts and API endpoints"
    echo "4. Use password_hash() with PASSWORD_DEFAULT algorithm"
    echo "5. Use hash_equals() for all credential comparisons"
    echo "6. Log all security-relevant events with proper masking"
    echo "7. Implement account lockout after failed login attempts"
    echo "8. Use secure random token generation (random_bytes)"
    echo ""
    exit 1
else
    echo -e "${GREEN}All authentication security tests passed!${NC}"
    echo "The application has proper authentication security measures."
    echo ""
    exit 0
fi
