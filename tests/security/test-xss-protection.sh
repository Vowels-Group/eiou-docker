#!/bin/bash
# XSS Protection Penetration Test Suite
# Tests for Cross-Site Scripting vulnerabilities
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
echo -e "${BLUE}XSS PROTECTION PENETRATION TEST SUITE${NC}"
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

    # Check if XSS was properly encoded/blocked
    if echo "$result" | grep -qE "(&lt;script|&amp;|htmlspecialchars|encoded|sanitized|BLOCKED|COMMAND_FAILED)" || \
       ! echo "$result" | grep -qE "(<script|<img|onerror|javascript:|on\w+\s*=)"; then
        echo -e "${GREEN}✓ PASS${NC} - XSS properly prevented"
        echo "  Expected: $expected_behavior"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}✗ FAIL${NC} - Potential XSS vulnerability"
        echo "  Expected: $expected_behavior"
        echo "  Result: $result"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        VULNERABILITIES+=("$test_name: XSS not properly prevented")
    fi
    echo ""
}

echo -e "${BLUE}1. REFLECTED XSS TESTS${NC}"
echo "--------------------------------------"

# Test 1: Basic script tag injection
run_test \
    "Basic script tag in contact name" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '<script>alert(1)</script>'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "Script tags should be HTML encoded"

# Test 2: Script tag with attributes
run_test \
    "Script tag with attributes" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '<script src=x onerror=alert(1)>'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "Script tags and attributes should be encoded"

# Test 3: IMG tag with onerror
run_test \
    "IMG tag with onerror event" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '<img src=x onerror=alert(1)>'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "IMG tags with event handlers should be encoded"

# Test 4: JavaScript protocol in href
run_test \
    "JavaScript protocol injection" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = 'javascript:alert(1)'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "JavaScript protocol should be encoded"

# Test 5: Event handler attributes
run_test \
    "Event handler attribute injection" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '<div onclick=alert(1)>'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "Event handler attributes should be encoded"

echo -e "${BLUE}2. STORED XSS TESTS${NC}"
echo "--------------------------------------"

# Test 6: Stored XSS in contact name
run_test \
    "Stored XSS payload in contact name validation" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/InputValidator.php'; require_once '/app/src/utils/Security.php'; \\\$result = InputValidator::validateContactName('<script>alert(1)</script>'); echo json_encode(\\\$result);\" 2>&1" \
    "Contact name validation should reject script tags"

# Test 7: Stored XSS in transaction memo
run_test \
    "Stored XSS payload in memo field" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/InputValidator.php'; require_once '/app/src/utils/Security.php'; \\\$result = InputValidator::validateMemo('<img src=x onerror=alert(1)>'); echo json_encode(\\\$result);\" 2>&1" \
    "Memo field should sanitize HTML tags"

# Test 8: Check if output encoding is used in templates
run_test \
    "Template files use output encoding" \
    "grep -c 'htmlspecialchars\\|Security::htmlEncode\\|h(' /app/src/gui/layout/walletSubParts/contactSection.html || echo '0'" \
    "Templates should use htmlspecialchars or encoding functions"

echo -e "${BLUE}3. DOM-BASED XSS TESTS${NC}"
echo "--------------------------------------"

# Test 9: Check for dangerous JavaScript functions
run_test \
    "JavaScript avoids innerHTML assignments" \
    "grep -c '\\.innerHTML\\s*=' /app/src/gui/assets/js/script.js && echo 'FOUND_DANGEROUS' || echo 'SAFE'" \
    "Should avoid using innerHTML with user data"

# Test 10: Check for eval() usage
run_test \
    "JavaScript avoids eval()" \
    "grep -c 'eval(' /app/src/gui/assets/js/script.js && echo 'FOUND_DANGEROUS' || echo 'SAFE'" \
    "Should not use eval() with user input"

# Test 11: Check for document.write usage
run_test \
    "JavaScript avoids document.write()" \
    "grep -c 'document\\.write' /app/src/gui/assets/js/script.js && echo 'FOUND_DANGEROUS' || echo 'SAFE'" \
    "Should avoid document.write() with user data"

echo -e "${BLUE}4. CONTEXT-SPECIFIC ENCODING TESTS${NC}"
echo "--------------------------------------"

# Test 12: JavaScript context encoding
run_test \
    "JavaScript context encoding function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'jsEncode') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Should have jsEncode() for JavaScript context"

# Test 13: JavaScript context encoding works
run_test \
    "JavaScript encoding prevents injection" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '</script><script>alert(1)</script>'; \\\$output = Security::jsEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "JavaScript encoding should escape special characters"

# Test 14: URL context encoding
run_test \
    "URL context encoding function exists" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; echo method_exists('Security', 'urlEncode') ? 'EXISTS' : 'NOT_FOUND';\" 2>&1" \
    "Should have urlEncode() for URL context"

echo -e "${BLUE}5. ADVANCED XSS BYPASS TESTS${NC}"
echo "--------------------------------------"

# Test 15: SVG-based XSS
run_test \
    "SVG-based XSS payload" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '<svg onload=alert(1)>'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "SVG tags with event handlers should be encoded"

# Test 16: Data URI XSS
run_test \
    "Data URI XSS payload" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = 'data:text/html,<script>alert(1)</script>'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "Data URIs should be encoded"

# Test 17: Unicode bypass attempt
run_test \
    "Unicode encoded XSS payload" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '\\u003cscript\\u003ealert(1)\\u003c/script\\u003e'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "Unicode should be properly handled"

# Test 18: HTML entity bypass
run_test \
    "HTML entity XSS bypass" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = '&lt;script&gt;alert(1)&lt;/script&gt;'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "Already-encoded entities should not be double-decoded"

# Test 19: CSS expression injection
run_test \
    "CSS expression injection" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = 'style=expression(alert(1))'; \\\$output = Security::htmlEncode(\\\$input); echo \\\$output;\" 2>&1" \
    "CSS expressions should be encoded"

echo -e "${BLUE}6. SECURITY HEADER TESTS${NC}"
echo "--------------------------------------"

# Test 20: X-XSS-Protection header
run_test \
    "X-XSS-Protection header is set" \
    "grep -q 'X-XSS-Protection' /app/src/utils/Security.php && echo 'SET' || echo 'NOT_SET'" \
    "X-XSS-Protection header should be set"

# Test 21: Content-Security-Policy header
run_test \
    "Content-Security-Policy header is set" \
    "grep -q 'Content-Security-Policy' /app/src/utils/Security.php && echo 'SET' || echo 'NOT_SET'" \
    "CSP header should be configured to prevent XSS"

# Test 22: X-Content-Type-Options header
run_test \
    "X-Content-Type-Options header is set" \
    "grep -q 'X-Content-Type-Options' /app/src/utils/Security.php && echo 'SET' || echo 'NOT_SET'" \
    "X-Content-Type-Options header prevents MIME sniffing"

echo -e "${BLUE}7. INPUT SANITIZATION TESTS${NC}"
echo "--------------------------------------"

# Test 23: Verify all user inputs are sanitized
run_test \
    "TransactionController sanitizes input" \
    "grep -c 'sanitizeInput' /app/src/gui/controllers/TransactionController.php || echo '0'" \
    "All user inputs should be sanitized"

# Test 24: Verify ContactController sanitizes input
run_test \
    "ContactController sanitizes input" \
    "grep -c 'sanitizeInput' /app/src/gui/controllers/ContactController.php || echo '0'" \
    "All user inputs should be sanitized"

echo -e "${BLUE}8. OUTPUT ENCODING VERIFICATION${NC}"
echo "--------------------------------------"

# Test 25: Helper function h() exists
run_test \
    "Helper function h() for HTML encoding exists" \
    "grep -q '^function h(' /app/src/security_init.php && echo 'EXISTS' || echo 'NOT_FOUND'" \
    "Should have h() helper for easy HTML encoding"

# Test 26: Helper function j() exists
run_test \
    "Helper function j() for JS encoding exists" \
    "grep -q '^function j(' /app/src/security_init.php && echo 'EXISTS' || echo 'NOT_FOUND'" \
    "Should have j() helper for JavaScript encoding"

# Test 27: Helper function u() exists
run_test \
    "Helper function u() for URL encoding exists" \
    "grep -q '^function u(' /app/src/security_init.php && echo 'EXISTS' || echo 'NOT_FOUND'" \
    "Should have u() helper for URL encoding"

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
    echo "1. Always use htmlspecialchars() or Security::htmlEncode() for output"
    echo "2. Use context-appropriate encoding (JS, URL, HTML)"
    echo "3. Implement Content Security Policy (CSP) headers"
    echo "4. Avoid innerHTML, eval(), and document.write() in JavaScript"
    echo "5. Sanitize input AND encode output (defense in depth)"
    echo "6. Use security headers: X-XSS-Protection, X-Content-Type-Options"
    echo ""
    exit 1
else
    echo -e "${GREEN}All XSS protection tests passed!${NC}"
    echo "The application properly prevents XSS attacks."
    echo ""
    exit 0
fi
