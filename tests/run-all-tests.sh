#!/bin/bash
#
# Master Test Runner for EIOU GUI Modernization
# Runs all test suites and generates comprehensive report
#
# Usage: ./run-all-tests.sh [--skip-docker] [--quick]
#
# Options:
#   --skip-docker  Skip Docker-dependent tests
#   --quick        Skip long-running tests
#

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPORT_FILE="${SCRIPT_DIR}/test-report.html"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# Parse arguments
SKIP_DOCKER=0
QUICK_MODE=0

for arg in "$@"; do
    case $arg in
        --skip-docker)
            SKIP_DOCKER=1
            shift
            ;;
        --quick)
            QUICK_MODE=1
            shift
            ;;
    esac
done

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Print functions
print_header() {
    echo ""
    echo -e "${BLUE}=========================================="
    echo "$1"
    echo -e "==========================================${NC}"
    echo ""
}

print_section() {
    echo ""
    echo -e "${YELLOW}>>> $1${NC}"
    echo ""
}

# Test result tracking
TOTAL_TESTS=0
TOTAL_PASSED=0
TOTAL_FAILED=0
TOTAL_SKIPPED=0

declare -A TEST_RESULTS
declare -A TEST_DURATIONS

# Run a test suite
run_test_suite() {
    local name=$1
    local command=$2
    local start_time=$(date +%s)

    print_section "Running: $name"

    if eval "$command"; then
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))
        TEST_RESULTS[$name]="PASS"
        TEST_DURATIONS[$name]=$duration
        echo -e "${GREEN}✅ PASSED${NC} ($duration seconds)"
        return 0
    else
        local end_time=$(date +%s)
        local duration=$((end_time - start_time))
        TEST_RESULTS[$name]="FAIL"
        TEST_DURATIONS[$name]=$duration
        echo -e "${RED}❌ FAILED${NC} ($duration seconds)"
        return 1
    fi
}

# Start test execution
print_header "EIOU GUI MODERNIZATION TEST SUITE"
echo "Timestamp: $TIMESTAMP"
echo "Mode: $([ $SKIP_DOCKER -eq 1 ] && echo 'Skip Docker' || echo 'Full') $([ $QUICK_MODE -eq 1 ] && echo '(Quick)' || echo '')"
echo ""

# Check prerequisites
print_section "Checking Prerequisites"

# Check PHP
if command -v php >/dev/null 2>&1; then
    PHP_VERSION=$(php -r 'echo PHP_VERSION;')
    echo "✅ PHP $PHP_VERSION"
else
    echo "❌ PHP not found"
    exit 1
fi

# Check Docker
if [ $SKIP_DOCKER -eq 0 ]; then
    if command -v docker >/dev/null 2>&1; then
        DOCKER_VERSION=$(docker --version | awk '{print $3}' | tr -d ',')
        echo "✅ Docker $DOCKER_VERSION"

        # Check if container is running
        if docker ps --filter "name=alice" --format "{{.Names}}" | grep -q "alice"; then
            echo "✅ Docker container running"
        else
            echo "⚠️  Docker container not running"
            echo "   Starting container..."
            docker compose -f docker-compose-single.yml up -d
            sleep 10
        fi
    else
        echo "❌ Docker not found"
        exit 1
    fi
else
    echo "⏭️  Skipping Docker checks"
fi

# Check for browser (optional)
if command -v firefox >/dev/null 2>&1; then
    echo "✅ Firefox available for browser tests"
elif command -v chromium >/dev/null 2>&1; then
    echo "✅ Chromium available for browser tests"
else
    echo "ℹ️  No browser found (browser tests are manual)"
fi

# Run test suites
print_header "RUNNING TEST SUITES"

# Test 1: API Caching Tests
run_test_suite "API Caching Tests" "php ${SCRIPT_DIR}/api/test-caching.php"
CACHE_EXIT=$?

# Test 2: Performance Benchmarks
if [ $SKIP_DOCKER -eq 1 ]; then
    run_test_suite "Performance Benchmarks" "php ${SCRIPT_DIR}/performance/benchmark.php"
else
    run_test_suite "Performance Benchmarks" "php ${SCRIPT_DIR}/performance/benchmark.php --docker"
fi
PERF_EXIT=$?

# Test 3: Integration Tests
if [ $SKIP_DOCKER -eq 0 ]; then
    if [ $QUICK_MODE -eq 1 ]; then
        SKIP_RESTART_TEST=1 run_test_suite "Integration Tests" "${SCRIPT_DIR}/integration/test-gui-flow.sh"
    else
        run_test_suite "Integration Tests" "${SCRIPT_DIR}/integration/test-gui-flow.sh"
    fi
    INTEGRATION_EXIT=$?
else
    echo "⏭️  Skipping Integration Tests (Docker required)"
    TEST_RESULTS["Integration Tests"]="SKIP"
    INTEGRATION_EXIT=0
fi

# Collect results from individual test runs
if [ -f "${SCRIPT_DIR}/test-results.json" ]; then
    CACHE_STATS=$(jq -r '.summary | "Total: \(.total), Passed: \(.passed), Failed: \(.failed)"' "${SCRIPT_DIR}/test-results.json" 2>/dev/null || echo "N/A")
    echo "API Caching Details: $CACHE_STATS"
fi

if [ -f "${SCRIPT_DIR}/performance/performance-metrics.json" ]; then
    PERF_STATS=$(jq -r '.tests | "Passed: \(.[] | select(.status=="PASS") | length // 0)"' "${SCRIPT_DIR}/performance/performance-metrics.json" 2>/dev/null || echo "N/A")
    echo "Performance Details: $PERF_STATS"
fi

if [ -f "${SCRIPT_DIR}/integration/integration-test-results.json" ]; then
    INTEGRATION_STATS=$(jq -r '.summary | "Total: \(.total), Passed: \(.passed), Failed: \(.failed)"' "${SCRIPT_DIR}/integration/integration-test-results.json" 2>/dev/null || echo "N/A")
    echo "Integration Details: $INTEGRATION_STATS"
fi

# Generate HTML report
print_section "Generating Test Report"

cat > "$REPORT_FILE" << 'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EIOU GUI Modernization Test Report</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h1 { color: #333; }
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .metric {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .metric-value {
            font-size: 36px;
            font-weight: bold;
            margin: 10px 0;
        }
        .metric-label {
            color: #666;
            font-size: 14px;
        }
        .pass { color: #28a745; }
        .fail { color: #dc3545; }
        .skip { color: #ffc107; }
        .test-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .test-item {
            padding: 15px;
            margin: 10px 0;
            border-left: 4px solid #ddd;
            background: #f8f9fa;
        }
        .test-item.pass { border-left-color: #28a745; }
        .test-item.fail { border-left-color: #dc3545; }
        .test-item.skip { border-left-color: #ffc107; }
        .test-name {
            font-weight: bold;
            font-size: 16px;
        }
        .test-duration {
            color: #666;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #343a40;
            color: white;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>EIOU GUI Modernization Test Report</h1>
        <p>Issue #137 - GUI Architecture & Performance Modernization</p>
        <p><strong>Timestamp:</strong> TIMESTAMP_PLACEHOLDER</p>
    </div>
EOF

# Add summary metrics
cat >> "$REPORT_FILE" << EOF
    <div class="summary">
        <div class="metric">
            <div class="metric-label">Total Suites</div>
            <div class="metric-value">${#TEST_RESULTS[@]}</div>
        </div>
        <div class="metric">
            <div class="metric-label pass">Passed</div>
            <div class="metric-value pass">$(echo "${TEST_RESULTS[@]}" | tr ' ' '\n' | grep -c "PASS" || echo 0)</div>
        </div>
        <div class="metric">
            <div class="metric-label fail">Failed</div>
            <div class="metric-value fail">$(echo "${TEST_RESULTS[@]}" | tr ' ' '\n' | grep -c "FAIL" || echo 0)</div>
        </div>
        <div class="metric">
            <div class="metric-label skip">Skipped</div>
            <div class="metric-value skip">$(echo "${TEST_RESULTS[@]}" | tr ' ' '\n' | grep -c "SKIP" || echo 0)</div>
        </div>
    </div>

    <div class="test-section">
        <h2>Test Suite Results</h2>
EOF

# Add test results
for test_name in "${!TEST_RESULTS[@]}"; do
    result="${TEST_RESULTS[$test_name]}"
    duration="${TEST_DURATIONS[$test_name]:-0}"
    status_class=$(echo "$result" | tr '[:upper:]' '[:lower:]')

    cat >> "$REPORT_FILE" << EOF
        <div class="test-item $status_class">
            <div class="test-name">$test_name: $result</div>
            <div class="test-duration">Duration: ${duration}s</div>
        </div>
EOF
done

cat >> "$REPORT_FILE" << EOF
    </div>

    <div class="test-section">
        <h2>Manual Testing Required</h2>
        <ul>
            <li><strong>JavaScript Unit Tests:</strong> Open <code>tests/gui/test-toast.html</code> in browser</li>
            <li><strong>Browser Compatibility:</strong> Open <code>tests/browser/compatibility-test.html</code> in different browsers</li>
            <li><strong>Visual Regression:</strong> Compare GUI appearance across browsers</li>
        </ul>
    </div>

    <div class="test-section">
        <h2>Test Files</h2>
        <ul>
            <li>API Caching: <code>tests/api/test-caching.php</code></li>
            <li>Performance: <code>tests/performance/benchmark.php</code></li>
            <li>Integration: <code>tests/integration/test-gui-flow.sh</code></li>
            <li>Toast Tests: <code>tests/gui/test-toast.html</code></li>
            <li>Browser Tests: <code>tests/browser/compatibility-test.html</code></li>
        </ul>
    </div>
</body>
</html>
EOF

# Replace timestamp placeholder
sed -i "s/TIMESTAMP_PLACEHOLDER/$TIMESTAMP/" "$REPORT_FILE"

echo "Report generated: $REPORT_FILE"

# Print summary
print_header "TEST SUMMARY"

echo "Test Suites: ${#TEST_RESULTS[@]}"
echo ""

for test_name in "${!TEST_RESULTS[@]}"; do
    result="${TEST_RESULTS[$test_name]}"
    duration="${TEST_DURATIONS[$test_name]:-0}"

    case $result in
        PASS)
            echo -e "${GREEN}✅ PASS${NC}: $test_name (${duration}s)"
            ;;
        FAIL)
            echo -e "${RED}❌ FAIL${NC}: $test_name (${duration}s)"
            ;;
        SKIP)
            echo -e "${YELLOW}⏭️  SKIP${NC}: $test_name"
            ;;
    esac
done

echo ""
echo -e "${BLUE}Full report available at:${NC} $REPORT_FILE"
echo ""

# Exit with appropriate code
if echo "${TEST_RESULTS[@]}" | grep -q "FAIL"; then
    echo -e "${RED}Some tests failed.${NC}"
    exit 1
else
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
fi
