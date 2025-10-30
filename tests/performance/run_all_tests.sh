#!/bin/bash
#
# Comprehensive Performance Testing Suite for Docker Cache Implementation
# This script runs all performance tests and generates a comparison report
#
# Usage: ./run_all_tests.sh [topology] [skip-baseline]
#
# Arguments:
#   topology: single, 4line, 10line, cluster, or all (default: all)
#   skip-baseline: skip baseline tests if already run (default: false)
#
# Example: ./run_all_tests.sh 4line skip-baseline

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
TOPOLOGY="${1:-all}"
SKIP_BASELINE="${2:-false}"
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$TEST_DIR/../.." && pwd)"
LOG_DIR="$PROJECT_ROOT/logs"
REPORT_DIR="$PROJECT_ROOT/reports"

# Ensure directories exist
mkdir -p "$LOG_DIR" "$REPORT_DIR"

# Function to print colored output
print_color() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Function to print section header
print_header() {
    local title=$1
    echo ""
    print_color "$BLUE" "================================================================"
    print_color "$BLUE" "  $title"
    print_color "$BLUE" "================================================================"
    echo ""
}

# Function to check if Docker is running
check_docker() {
    print_header "Checking Docker Environment"

    if ! docker info >/dev/null 2>&1; then
        print_color "$RED" "❌ Docker is not running. Please start Docker and try again."
        exit 1
    fi

    print_color "$GREEN" "✅ Docker is running"

    # Check if containers are running for the specified topology
    if [ "$TOPOLOGY" != "all" ]; then
        local compose_file="$PROJECT_ROOT/docker-compose-${TOPOLOGY}.yml"

        if [ "$TOPOLOGY" = "single" ]; then
            compose_file="$PROJECT_ROOT/docker-compose-single.yml"
        elif [ "$TOPOLOGY" = "10line" ]; then
            compose_file="$PROJECT_ROOT/docker-compose-10line.yml"
        elif [ "$TOPOLOGY" = "cluster" ]; then
            compose_file="$PROJECT_ROOT/docker-compose-cluster.yml"
        fi

        if [ ! -f "$compose_file" ]; then
            print_color "$RED" "❌ Docker Compose file not found: $compose_file"
            exit 1
        fi

        print_color "$YELLOW" "Checking if containers are running for topology: $TOPOLOGY"

        # Start containers if not running
        if ! docker-compose -f "$compose_file" ps | grep -q "Up"; then
            print_color "$YELLOW" "Starting containers for topology: $TOPOLOGY"
            docker-compose -f "$compose_file" up -d --build

            # Wait for containers to be ready
            print_color "$YELLOW" "Waiting for containers to be ready..."
            sleep 10
        fi

        print_color "$GREEN" "✅ Containers are running for topology: $TOPOLOGY"
    fi
}

# Function to run baseline performance tests
run_baseline_tests() {
    print_header "Running Baseline Performance Tests (Without Cache)"

    if [ "$SKIP_BASELINE" = "skip-baseline" ] && [ -f "$LOG_DIR/performance_baseline.log" ]; then
        print_color "$YELLOW" "⏭️  Skipping baseline tests (already exists)"
        return
    fi

    # Backup existing baseline if it exists
    if [ -f "$LOG_DIR/performance_baseline.log" ]; then
        local backup_file="$LOG_DIR/performance_baseline_$(date +%Y%m%d_%H%M%S).log.bak"
        mv "$LOG_DIR/performance_baseline.log" "$backup_file"
        print_color "$YELLOW" "📦 Backed up existing baseline to: $backup_file"
    fi

    if [ "$TOPOLOGY" = "all" ]; then
        local topologies=("single" "4line" "10line" "cluster")
    else
        local topologies=("$TOPOLOGY")
    fi

    for topo in "${topologies[@]}"; do
        print_color "$BLUE" "Testing topology: $topo"

        # Start containers for this topology
        local compose_file="$PROJECT_ROOT/docker-compose-${topo}.yml"
        if [ "$topo" = "single" ]; then
            compose_file="$PROJECT_ROOT/docker-compose-single.yml"
        elif [ "$topo" = "10line" ]; then
            compose_file="$PROJECT_ROOT/docker-compose-10line.yml"
        fi

        if [ -f "$compose_file" ]; then
            docker-compose -f "$compose_file" up -d --build >/dev/null 2>&1
            sleep 5

            # Run baseline test
            php "$TEST_DIR/baseline_performance.php" "$topo"

            # Stop containers
            docker-compose -f "$compose_file" down >/dev/null 2>&1
        else
            print_color "$YELLOW" "⚠️  Compose file not found for topology: $topo"
        fi
    done

    print_color "$GREEN" "✅ Baseline tests complete"
}

# Function to run cache implementation tests
run_cache_tests() {
    print_header "Running Cache Implementation Tests"

    # Check if DockerCache class exists
    if [ ! -f "$PROJECT_ROOT/src/services/DockerCache.php" ]; then
        print_color "$YELLOW" "⚠️  DockerCache.php not found - using stub implementation"
        print_color "$YELLOW" "   The Coder needs to implement the actual DockerCache class"
    fi

    # Run all test suites
    local test_suites=("functional" "performance" "invalidation" "concurrent" "memory" "stress")

    for suite in "${test_suites[@]}"; do
        print_color "$BLUE" "Running $suite tests..."

        php "$TEST_DIR/cache_test_framework.php" "$suite" "$TOPOLOGY" || {
            print_color "$RED" "❌ $suite tests failed"
            # Continue with other tests even if one fails
        }
    done

    print_color "$GREEN" "✅ Cache tests complete"
}

# Function to run real-time monitoring
run_monitoring() {
    print_header "Starting Cache Monitor (10 seconds)"

    print_color "$YELLOW" "Monitor will run in background for 10 seconds..."

    # Start monitor in background
    timeout 10 php "$TEST_DIR/cache_monitor.php" 1 > "$LOG_DIR/monitor_output.log" 2>&1 &
    local monitor_pid=$!

    # Show progress
    for i in {1..10}; do
        echo -n "."
        sleep 1
    done
    echo ""

    # Wait for monitor to finish or timeout
    wait $monitor_pid 2>/dev/null || true

    print_color "$GREEN" "✅ Monitoring complete"
}

# Function to generate comparison report
generate_report() {
    print_header "Generating Performance Comparison Report"

    # Generate reports in all formats
    local formats=("html" "markdown" "json")

    for format in "${formats[@]}"; do
        print_color "$BLUE" "Generating $format report..."

        php "$TEST_DIR/performance_comparison_report.php" "$TOPOLOGY" "$format" || {
            print_color "$RED" "❌ Failed to generate $format report"
        }
    done

    # Find the latest HTML report
    local latest_report=$(ls -t "$REPORT_DIR"/performance_report_*.html 2>/dev/null | head -1)

    if [ -f "$latest_report" ]; then
        print_color "$GREEN" "✅ Reports generated successfully"
        print_color "$GREEN" "📊 View report: file://$latest_report"

        # Try to open in browser if available
        if command -v xdg-open >/dev/null 2>&1; then
            xdg-open "$latest_report" 2>/dev/null &
        elif command -v open >/dev/null 2>&1; then
            open "$latest_report" 2>/dev/null &
        fi
    else
        print_color "$YELLOW" "⚠️  No report generated"
    fi
}

# Function to display summary
display_summary() {
    print_header "Test Execution Summary"

    # Check for test results
    local cache_reports=$(ls "$LOG_DIR"/cache_test_report_*.json 2>/dev/null | wc -l)
    local baseline_exists=false
    local all_criteria_met=false

    if [ -f "$LOG_DIR/performance_baseline.log" ]; then
        baseline_exists=true
    fi

    # Parse the latest cache test report for summary
    if [ "$cache_reports" -gt 0 ]; then
        local latest_cache_report=$(ls -t "$LOG_DIR"/cache_test_report_*.json | head -1)

        if [ -f "$latest_cache_report" ]; then
            # Extract summary using PHP or jq if available
            if command -v jq >/dev/null 2>&1; then
                local success_rate=$(jq -r '.summary.success_rate' "$latest_cache_report" 2>/dev/null)
                if [ "$success_rate" = "100" ]; then
                    all_criteria_met=true
                fi
            fi
        fi
    fi

    echo "Test Results:"
    echo "----------------------------------------"

    if [ "$baseline_exists" = true ]; then
        print_color "$GREEN" "✅ Baseline performance data collected"
    else
        print_color "$RED" "❌ No baseline data found"
    fi

    if [ "$cache_reports" -gt 0 ]; then
        print_color "$GREEN" "✅ Cache implementation tests completed ($cache_reports reports)"
    else
        print_color "$RED" "❌ No cache test reports found"
    fi

    if [ "$all_criteria_met" = true ]; then
        print_color "$GREEN" "✅ All acceptance criteria met"
    else
        print_color "$YELLOW" "⚠️  Some acceptance criteria not met"
    fi

    echo ""
    echo "Output Locations:"
    echo "----------------------------------------"
    echo "📁 Logs:    $LOG_DIR"
    echo "📁 Reports: $REPORT_DIR"

    # List generated files
    echo ""
    echo "Generated Files:"
    echo "----------------------------------------"
    ls -lht "$LOG_DIR"/*.log 2>/dev/null | head -5 || echo "No log files found"
    ls -lht "$REPORT_DIR"/*.html 2>/dev/null | head -3 || echo "No report files found"
}

# Function to clean up
cleanup() {
    print_header "Cleaning Up"

    # Stop any running monitors
    pkill -f "cache_monitor.php" 2>/dev/null || true

    # Optionally stop Docker containers
    if [ "$TOPOLOGY" != "all" ]; then
        read -p "Stop Docker containers? (y/n): " -n 1 -r
        echo ""
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            local compose_file="$PROJECT_ROOT/docker-compose-${TOPOLOGY}.yml"
            if [ "$TOPOLOGY" = "single" ]; then
                compose_file="$PROJECT_ROOT/docker-compose-single.yml"
            fi

            if [ -f "$compose_file" ]; then
                docker-compose -f "$compose_file" down
                print_color "$GREEN" "✅ Containers stopped"
            fi
        fi
    fi
}

# Main execution
main() {
    print_header "Docker Cache Performance Testing Suite"

    echo "Configuration:"
    echo "  Topology: $TOPOLOGY"
    echo "  Skip Baseline: $SKIP_BASELINE"
    echo "  Test Directory: $TEST_DIR"
    echo "  Project Root: $PROJECT_ROOT"
    echo ""

    # Run tests
    check_docker
    run_baseline_tests
    run_cache_tests
    run_monitoring
    generate_report
    display_summary

    # Cleanup
    trap cleanup EXIT

    print_header "Testing Complete!"

    # Display recommendations
    echo ""
    print_color "$BLUE" "Next Steps:"
    echo "1. Review the performance report in your browser"
    echo "2. Check if all acceptance criteria are met"
    echo "3. Review any failed tests in the logs"
    echo "4. If all tests pass, the cache implementation is ready for production"
}

# Handle script interruption
trap 'print_color "$RED" "\n\n❌ Testing interrupted by user"; cleanup; exit 1' INT TERM

# Run main function
main