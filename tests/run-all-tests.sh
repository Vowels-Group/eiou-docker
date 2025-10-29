#!/bin/bash
#
# Master test runner for ServiceWrappers removal
# Runs all tests in sequence and provides summary
#

set -e  # Exit on first error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test tracking
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0
FAILED_NAMES=()

echo "==========================================="
echo "ServiceWrappers Removal - Complete Test Suite"
echo "==========================================="
echo ""

# Function to run a test and track results
run_test() {
    local test_name="$1"
    local test_command="$2"

    echo -e "${BLUE}Running: $test_name${NC}"
    echo "Command: $test_command"
    echo ""

    ((TOTAL_TESTS++))

    if eval "$test_command"; then
        echo -e "${GREEN}✓ $test_name PASSED${NC}\n"
        ((PASSED_TESTS++))
    else
        echo -e "${RED}✗ $test_name FAILED${NC}\n"
        ((FAILED_TESTS++))
        FAILED_NAMES+=("$test_name")
    fi

    echo "-------------------------------------------"
    echo ""
}

# Navigate to project root
cd /home/admin/eiou/ai-dev/github/eiou-docker || exit 1

# Test 1: Check for wrapper function usage in source
echo -e "${YELLOW}=== Pre-refactoring Checks ===${NC}\n"

run_test "Wrapper Function Usage Check" "
    usage_count=\$(grep -r 'sendP2pEiou\\|sendP2pRequest\\|sendP2pRequestFromFailedDirectTransaction\\|synchContact' src/ \\
        --exclude='ServiceWrappers.php' --exclude-dir='services' 2>/dev/null | wc -l)
    if [ \"\$usage_count\" -eq 0 ]; then
        echo 'No wrapper function usage found in source'
        exit 0
    else
        echo \"Found \$usage_count instances of wrapper functions still in use\"
        grep -r 'sendP2pEiou\\|sendP2pRequest\\|sendP2pRequestFromFailedDirectTransaction\\|synchContact' src/ \\
            --exclude='ServiceWrappers.php' --exclude-dir='services' | head -5
        exit 1
    fi
"

# Test 2: PHP syntax check
run_test "PHP Syntax Check" "
    errors=\$(find src/ -name '*.php' -exec php -l {} \\; 2>&1 | grep -v 'No syntax errors' | wc -l)
    if [ \"\$errors\" -eq 0 ]; then
        echo 'All PHP files have valid syntax'
        exit 0
    else
        echo \"Found \$errors syntax errors\"
        find src/ -name '*.php' -exec php -l {} \\; 2>&1 | grep -v 'No syntax errors' | head -5
        exit 1
    fi
"

# Test 3: Integration test (if not in Docker)
if [ ! -f /.dockerenv ]; then
    echo -e "${YELLOW}=== Integration Tests ===${NC}\n"

    run_test "Service Container Integration Test" "
        if [ -f tests/integration/test-service-container.php ]; then
            php tests/integration/test-service-container.php
        else
            echo 'Integration test file not found, skipping'
            exit 0
        fi
    "
fi

# Test 4: Docker startup validation
echo -e "${YELLOW}=== Docker Validation Tests ===${NC}\n"

if command -v docker &> /dev/null; then
    run_test "Docker Startup Validation" "./tests/docker/startup-validation.sh"
    run_test "Docker Runtime Tests" "./tests/docker/runtime-tests.sh"
else
    echo -e "${YELLOW}Docker not available, skipping Docker tests${NC}\n"
fi

# Test 5: File permissions
echo -e "${YELLOW}=== File System Tests ===${NC}\n"

run_test "File Permissions Check" "
    files=(
        'src/services/ServiceContainer.php'
        'src/services/TransactionService.php'
        'src/services/P2pService.php'
        'src/services/SynchService.php'
    )

    missing=0
    for file in \"\${files[@]}\"; do
        if [ ! -f \"\$file\" ]; then
            echo \"Missing: \$file\"
            ((missing++))
        elif [ ! -r \"\$file\" ]; then
            echo \"Not readable: \$file\"
            ((missing++))
        fi
    done

    if [ \$missing -eq 0 ]; then
        echo 'All required service files present and readable'
        exit 0
    else
        exit 1
    fi
"

# Test 6: Check test files exist
run_test "Test Files Verification" "
    test_files=(
        'tests/TEST_STRATEGY_SERVICE_WRAPPERS.md'
        'tests/PR_VALIDATION_CHECKLIST.md'
        'tests/integration/test-service-container.php'
        'tests/docker/startup-validation.sh'
        'tests/docker/runtime-tests.sh'
        'tests/unit/ServiceRefactoringTest.php'
    )

    missing=0
    for file in \"\${test_files[@]}\"; do
        if [ ! -f \"\$file\" ]; then
            echo \"Missing test file: \$file\"
            ((missing++))
        fi
    done

    if [ \$missing -eq 0 ]; then
        echo 'All test files present'
        exit 0
    else
        echo \"Missing \$missing test files\"
        exit 1
    fi
"

# Final Summary
echo ""
echo "==========================================="
echo "FINAL TEST SUMMARY"
echo "==========================================="
echo -e "${BLUE}Total Tests Run:${NC} $TOTAL_TESTS"
echo -e "${GREEN}Tests Passed:${NC} $PASSED_TESTS"
echo -e "${RED}Tests Failed:${NC} $FAILED_TESTS"

if [ ${#FAILED_NAMES[@]} -gt 0 ]; then
    echo ""
    echo -e "${RED}Failed Tests:${NC}"
    for test_name in "${FAILED_NAMES[@]}"; do
        echo "  - $test_name"
    done
fi

echo ""
if [ "$FAILED_TESTS" -eq 0 ]; then
    echo -e "${GREEN}✓✓✓ ALL TESTS PASSED ✓✓✓${NC}"
    echo "Ready for PR submission!"
    exit 0
else
    echo -e "${RED}⚠⚠⚠ TESTS FAILED ⚠⚠⚠${NC}"
    echo "Please fix the issues above before submitting PR"
    exit 1
fi