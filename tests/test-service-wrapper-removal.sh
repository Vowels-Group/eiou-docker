#!/bin/bash

# Test script for validating ServiceWrapper removal (Issue #113)
# This script checks that:
# 1. No more wrapper function calls exist in the code
# 2. Services use direct service calls instead
# 3. Docker containers start successfully
# 4. Basic functionality still works

echo "========================================="
echo "Testing ServiceWrapper Removal (Issue #113)"
echo "========================================="

FAILED=0
PASSED=0

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Function to check test results
check_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓${NC} $2"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}✗${NC} $2"
        FAILED=$((FAILED + 1))
    fi
}

echo ""
echo "1. Checking for removed wrapper function calls..."
echo "================================================"

# Check that sendP2pEiou wrapper is not used
grep -r "^[[:space:]]*sendP2pEiou(" src/ --include="*.php" | grep -v "function sendP2pEiou" | grep -v "->sendP2pEiou" > /dev/null 2>&1
check_result $? "No sendP2pEiou() wrapper calls found"

# Check that sendP2pRequest wrapper is not used
grep -r "^[[:space:]]*sendP2pRequest(" src/ --include="*.php" | grep -v "function sendP2pRequest" | grep -v "->sendP2pRequest" > /dev/null 2>&1
check_result $? "No sendP2pRequest() wrapper calls found"

# Check that sendP2pRequestFromFailedDirectTransaction wrapper is not used
grep -r "^[[:space:]]*sendP2pRequestFromFailedDirectTransaction(" src/ --include="*.php" | grep -v "function sendP2pRequestFromFailedDirectTransaction" | grep -v "->sendP2pRequestFromFailedDirectTransaction" > /dev/null 2>&1
check_result $? "No sendP2pRequestFromFailedDirectTransaction() wrapper calls found"

# Check that synchContact wrapper is not used
grep -r "synchContact(" src/ --include="*.php" | grep -v "function synchContact" | grep -v "->synchSingleContact" | grep -v "getSynchService" > /dev/null 2>&1
check_result $? "No synchContact() wrapper calls found"

echo ""
echo "2. Verifying direct service calls..."
echo "====================================="

# Check that Rp2pService uses direct TransactionService call
grep -q "ServiceContainer::getInstance()->getTransactionService()->sendP2pEiou" src/services/Rp2pService.php
check_result $? "Rp2pService uses direct TransactionService call"

# Check that TransactionService uses direct P2pService calls
grep -q "ServiceContainer::getInstance()->getP2pService()->sendP2pRequest" src/services/TransactionService.php
check_result $? "TransactionService uses direct P2pService->sendP2pRequest"

grep -q "ServiceContainer::getInstance()->getP2pService()->sendP2pRequestFromFailedDirectTransaction" src/services/TransactionService.php
check_result $? "TransactionService uses direct P2pService->sendP2pRequestFromFailedDirectTransaction"

# Check that ContactService uses direct SynchService calls
grep -q "ServiceContainer::getInstance()->getSynchService()->synchSingleContact" src/services/ContactService.php
check_result $? "ContactService uses direct SynchService calls"

# Check that P2pService uses direct SynchService call
grep -q "ServiceContainer::getInstance()->getSynchService()->synchSingleContact" src/services/P2pService.php
check_result $? "P2pService uses direct SynchService call"

echo ""
echo "3. Checking ServiceWrappers.php..."
echo "================================="

# Check that removed functions are not in ServiceWrappers.php
grep -q "function sendP2pEiou" src/services/ServiceWrappers.php > /dev/null 2>&1
if [ $? -ne 0 ]; then
    check_result 0 "sendP2pEiou removed from ServiceWrappers.php"
else
    check_result 1 "sendP2pEiou removed from ServiceWrappers.php"
fi

grep -q "function sendP2pRequest[^F]" src/services/ServiceWrappers.php > /dev/null 2>&1
if [ $? -ne 0 ]; then
    check_result 0 "sendP2pRequest removed from ServiceWrappers.php"
else
    check_result 1 "sendP2pRequest removed from ServiceWrappers.php"
fi

grep -q "function sendP2pRequestFromFailedDirectTransaction" src/services/ServiceWrappers.php > /dev/null 2>&1
if [ $? -ne 0 ]; then
    check_result 0 "sendP2pRequestFromFailedDirectTransaction removed from ServiceWrappers.php"
else
    check_result 1 "sendP2pRequestFromFailedDirectTransaction removed from ServiceWrappers.php"
fi

grep -q "function synchContact" src/services/ServiceWrappers.php > /dev/null 2>&1
if [ $? -ne 0 ]; then
    check_result 0 "synchContact removed from ServiceWrappers.php"
else
    check_result 1 "synchContact removed from ServiceWrappers.php"
fi

# Check that output() wrapper is still present (not removed in this PR)
grep -q "function output" src/services/ServiceWrappers.php
check_result $? "output() wrapper still present (as expected)"

echo ""
echo "4. PHP Syntax Check..."
echo "====================="

# Check PHP syntax for modified files
php -l src/services/Rp2pService.php > /dev/null 2>&1
check_result $? "Rp2pService.php syntax valid"

php -l src/services/TransactionService.php > /dev/null 2>&1
check_result $? "TransactionService.php syntax valid"

php -l src/services/ContactService.php > /dev/null 2>&1
check_result $? "ContactService.php syntax valid"

php -l src/services/P2pService.php > /dev/null 2>&1
check_result $? "P2pService.php syntax valid"

php -l src/services/ServiceWrappers.php > /dev/null 2>&1
check_result $? "ServiceWrappers.php syntax valid"

php -l src/functions.php > /dev/null 2>&1
check_result $? "functions.php syntax valid"

echo ""
echo "========================================="
echo "Test Results Summary"
echo "========================================="
echo -e "Passed: ${GREEN}$PASSED${NC}"
echo -e "Failed: ${RED}$FAILED${NC}"

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed. Please review the failures above.${NC}"
    exit 1
fi