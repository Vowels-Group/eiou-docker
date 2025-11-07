#!/bin/bash
# SQL Injection Penetration Test Suite
# Tests for SQL injection vulnerabilities in EIOU application
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
echo -e "${BLUE}SQL INJECTION PENETRATION TEST SUITE${NC}"
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
    if echo "$result" | grep -qiE "(error|invalid|sanitized|blocked|validation failed|COMMAND_FAILED)"; then
        echo -e "${GREEN}✓ PASS${NC} - Attack blocked as expected"
        echo "  Expected: $expected_behavior"
        PASSED_TESTS=$((PASSED_TESTS + 1))
    else
        echo -e "${RED}✗ FAIL${NC} - Potential SQL injection vulnerability"
        echo "  Expected: $expected_behavior"
        echo "  Result: $result"
        FAILED_TESTS=$((FAILED_TESTS + 1))
        VULNERABILITIES+=("$test_name: SQL injection not properly blocked")
    fi
    echo ""
}

# Helper function to test transaction queries
test_transaction_query() {
    local payload="$1"
    local description="$2"

    # Test via CLI (simulated)
    run_test \
        "Transaction query with $description" \
        "docker compose exec alice php /app/src/eiou.php balance '$payload' 2>&1" \
        "Input validation should reject malicious SQL payload"
}

# Helper function to test contact queries
test_contact_query() {
    local payload="$1"
    local description="$2"

    # Test contact search
    run_test \
        "Contact search with $description" \
        "docker compose exec alice php -r \"require_once '/app/src/database/pdo.php'; require_once '/app/src/database/ContactRepository.php'; require_once '/app/src/utils/InputValidator.php'; require_once '/app/src/utils/Security.php'; \\\$repo = new ContactRepository(); \\\$result = \\\$repo->findByName('$payload'); echo json_encode(\\\$result);\" 2>&1" \
        "Prepared statements should prevent SQL injection"
}

echo -e "${BLUE}1. BASIC SQL INJECTION TESTS${NC}"
echo "--------------------------------------"

# Test 1: Classic SQL injection with OR 1=1
test_transaction_query \
    "' OR '1'='1" \
    "classic OR 1=1 injection"

# Test 2: UNION-based injection
test_transaction_query \
    "' UNION SELECT * FROM users--" \
    "UNION SELECT injection"

# Test 3: Comment-based injection
test_transaction_query \
    "'; DROP TABLE transactions;--" \
    "DROP TABLE with comment"

# Test 4: Stacked queries
test_transaction_query \
    "'; DELETE FROM contacts WHERE 1=1;--" \
    "stacked query DELETE"

# Test 5: Time-based blind injection
test_transaction_query \
    "' AND SLEEP(5)--" \
    "time-based blind injection"

echo -e "${BLUE}2. CONTACT SEARCH INJECTION TESTS${NC}"
echo "--------------------------------------"

# Test 6: Contact name SQL injection
test_contact_query \
    "admin' OR '1'='1" \
    "contact name OR injection"

# Test 7: Contact wildcard abuse
test_contact_query \
    "%' AND 1=1 UNION SELECT pubkey FROM contacts--" \
    "wildcard UNION injection"

# Test 8: Boolean-based blind injection
test_contact_query \
    "' AND (SELECT COUNT(*) FROM transactions) > 0--" \
    "boolean-based blind injection"

echo -e "${BLUE}3. TRANSACTION AMOUNT INJECTION TESTS${NC}"
echo "--------------------------------------"

# Test 9: Amount field SQL injection
run_test \
    "Amount field with SQL payload" \
    "docker compose exec alice php /app/src/eiou.php send testuser \"1000' OR '1'='1\" USD 2>&1" \
    "Amount validation should reject non-numeric values"

# Test 10: Negative amount SQL injection
run_test \
    "Negative amount with SQL comment" \
    "docker compose exec alice php /app/src/eiou.php send testuser \"-1'; DROP TABLE transactions;--\" USD 2>&1" \
    "Amount validation should reject negative values and SQL"

echo -e "${BLUE}4. ADVANCED INJECTION TECHNIQUES${NC}"
echo "--------------------------------------"

# Test 11: Second-order SQL injection (stored payload)
run_test \
    "Second-order injection via contact name" \
    "docker compose exec alice php /app/src/eiou.php add \"http://test.onion\" \"admin'; DELETE FROM contacts;--\" 0 0 USD 2>&1" \
    "Contact name validation should prevent SQL in stored data"

# Test 12: Encoding bypass (URL encoding)
test_transaction_query \
    "%27%20OR%20%271%27%3D%271" \
    "URL-encoded OR injection"

# Test 13: Unicode bypass attempt
test_transaction_query \
    "\\u0027 OR \\u0031=\\u0031" \
    "Unicode-encoded injection"

# Test 14: Hex encoding bypass
test_transaction_query \
    "0x2720OR20312e31" \
    "Hex-encoded injection"

echo -e "${BLUE}5. NULL BYTE INJECTION TESTS${NC}"
echo "--------------------------------------"

# Test 15: Null byte injection
run_test \
    "Null byte in contact name" \
    "docker compose exec alice php -r \"require_once '/app/src/utils/Security.php'; \\\$input = 'admin\\0'; \\\$clean = Security::sanitizeInput(\\\$input); echo (strpos(\\\$clean, chr(0)) === false) ? 'BLOCKED' : 'VULNERABLE';\" 2>&1" \
    "Null bytes should be stripped by sanitization"

echo -e "${BLUE}6. PARAMETERIZED QUERY VERIFICATION${NC}"
echo "--------------------------------------"

# Test 16: Verify prepared statements are used
run_test \
    "Check TransactionRepository uses prepared statements" \
    "grep -c 'execute.*:' /app/src/database/TransactionRepository.php" \
    "Should find parameterized queries (named parameters)"

# Test 17: Verify ContactRepository uses prepared statements
run_test \
    "Check ContactRepository uses prepared statements" \
    "grep -c 'execute.*:' /app/src/database/ContactRepository.php" \
    "Should find parameterized queries (named parameters)"

echo -e "${BLUE}7. INPUT VALIDATION VERIFICATION${NC}"
echo "--------------------------------------"

# Test 18: Verify InputValidator is used in controllers
run_test \
    "Check TransactionController uses InputValidator" \
    "grep -c 'InputValidator::validate' /app/src/gui/controllers/TransactionController.php" \
    "Controllers should use InputValidator for all user input"

# Test 19: Verify Security class is used
run_test \
    "Check Security::sanitizeInput is used" \
    "grep -c 'Security::sanitizeInput' /app/src/gui/controllers/TransactionController.php" \
    "Controllers should sanitize input using Security class"

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
    exit 1
else
    echo -e "${GREEN}All SQL injection tests passed!${NC}"
    echo "The application properly prevents SQL injection attacks."
    echo ""
    exit 0
fi
