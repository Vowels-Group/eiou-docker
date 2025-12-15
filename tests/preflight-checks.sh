#!/bin/bash
# Copyright 2025
# Pre-flight validation for test environment
# Validates system requirements before running tests

set -e

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Default resource requirements (can be overridden)
MIN_DOCKER_VERSION="20.10"
MIN_DISK_GB=${MIN_DISK_GB:-5}
MIN_MEM_MB=${MIN_MEM_MB:-2048}

# Log functions
log_pass() { echo -e "${GREEN}✔ PASS${NC}: $1"; }
log_fail() { echo -e "${RED}✘ FAIL${NC}: $1"; }
log_warn() { echo -e "${YELLOW}⚠ WARN${NC}: $1"; }
log_info() { echo -e "  INFO: $1"; }

# Track overall status
PREFLIGHT_PASSED=true

# Validate Docker daemon is running
validate_docker() {
    echo "Checking Docker..."

    if ! command -v docker &> /dev/null; then
        log_fail "Docker is not installed"
        PREFLIGHT_PASSED=false
        return 1
    fi

    if ! docker info > /dev/null 2>&1; then
        log_fail "Docker daemon is not running"
        PREFLIGHT_PASSED=false
        return 1
    fi

    # Get Docker version
    DOCKER_VERSION=$(docker --version | grep -oP '\d+\.\d+' | head -1)

    # Compare versions (basic comparison)
    if [[ "$(printf '%s\n' "$MIN_DOCKER_VERSION" "$DOCKER_VERSION" | sort -V | head -n1)" != "$MIN_DOCKER_VERSION" ]]; then
        log_warn "Docker version $DOCKER_VERSION may be too old (minimum: $MIN_DOCKER_VERSION)"
    else
        log_pass "Docker daemon running (version $DOCKER_VERSION)"
    fi

    return 0
}

# Validate system resources
validate_system_resources() {
    echo "Checking system resources..."

    # Check disk space
    local AVAILABLE_GB
    if [[ -d /var/lib/docker ]]; then
        AVAILABLE_GB=$(df /var/lib/docker 2>/dev/null | awk 'NR==2 {printf "%.0f", $4/1024/1024}')
    else
        AVAILABLE_GB=$(df / 2>/dev/null | awk 'NR==2 {printf "%.0f", $4/1024/1024}')
    fi

    if [[ -z "$AVAILABLE_GB" ]] || [[ "$AVAILABLE_GB" -lt "$MIN_DISK_GB" ]]; then
        log_fail "Insufficient disk space: ${AVAILABLE_GB:-unknown}GB available, ${MIN_DISK_GB}GB required"
        PREFLIGHT_PASSED=false
    else
        log_pass "Disk space: ${AVAILABLE_GB}GB available (${MIN_DISK_GB}GB required)"
    fi

    # Check available memory
    local AVAILABLE_MEM_MB
    if command -v free &> /dev/null; then
        AVAILABLE_MEM_MB=$(free -m 2>/dev/null | awk 'NR==2 {print $7}')
    fi

    if [[ -z "$AVAILABLE_MEM_MB" ]] || [[ "$AVAILABLE_MEM_MB" -lt "$MIN_MEM_MB" ]]; then
        log_warn "Available memory: ${AVAILABLE_MEM_MB:-unknown}MB (${MIN_MEM_MB}MB recommended)"
    else
        log_pass "Available memory: ${AVAILABLE_MEM_MB}MB (${MIN_MEM_MB}MB required)"
    fi

    return 0
}

# Validate required tools
validate_dependencies() {
    echo "Checking dependencies..."

    local REQUIRED_TOOLS=("docker" "curl" "bash")
    local missing_tools=()

    for tool in "${REQUIRED_TOOLS[@]}"; do
        if ! command -v "$tool" &> /dev/null; then
            missing_tools+=("$tool")
        fi
    done

    if [[ ${#missing_tools[@]} -gt 0 ]]; then
        log_fail "Missing required tools: ${missing_tools[*]}"
        PREFLIGHT_PASSED=false
        return 1
    fi

    log_pass "All required tools available"
    return 0
}

# Validate network connectivity
validate_network() {
    echo "Checking network..."

    # Check if we can create Docker network
    if docker network ls | grep -q "eioud-network"; then
        log_pass "Test network 'eioud-network' exists"
    else
        log_info "Test network 'eioud-network' will be created"
    fi

    return 0
}

# Check for port conflicts
validate_ports() {
    echo "Checking ports..."

    # Check common ports that might conflict
    local PORTS_TO_CHECK=(80 443 3306 9050)
    local conflicts=()

    for port in "${PORTS_TO_CHECK[@]}"; do
        if netstat -tuln 2>/dev/null | grep -q ":${port} " || ss -tuln 2>/dev/null | grep -q ":${port} "; then
            # Port in use, but Docker can still use it internally
            log_info "Port $port in use (OK for containerized tests)"
        fi
    done

    log_pass "Port check complete"
    return 0
}

# Clean up stale test containers
cleanup_stale_containers() {
    echo "Checking for stale test containers..."

    local stale_containers=$(docker ps -a --filter "name=http" --format "{{.Names}}" 2>/dev/null | wc -l)

    if [[ "$stale_containers" -gt 0 ]]; then
        log_warn "Found $stale_containers stale test container(s)"
        log_info "Run 'docker rm -f \$(docker ps -aq --filter name=http)' to clean up"
    else
        log_pass "No stale test containers found"
    fi

    return 0
}

# Set resource requirements based on topology
set_topology_requirements() {
    local topology="${1:-4}"

    case "$topology" in
        4|http4)
            MIN_DISK_GB=5
            MIN_MEM_MB=2048
            ;;
        10|http10)
            MIN_DISK_GB=15
            MIN_MEM_MB=4096
            ;;
        13|http13)
            MIN_DISK_GB=20
            MIN_MEM_MB=6144
            ;;
        *)
            MIN_DISK_GB=5
            MIN_MEM_MB=2048
            ;;
    esac

    log_info "Resource requirements for topology '$topology': ${MIN_DISK_GB}GB disk, ${MIN_MEM_MB}MB RAM"
}

# Main function
main() {
    echo "================================================================"
    echo "     EIOU Docker Test Suite - Pre-flight Validation"
    echo "================================================================"
    echo ""

    # Parse topology argument if provided
    if [[ -n "$1" ]]; then
        set_topology_requirements "$1"
    fi

    validate_docker
    validate_system_resources
    validate_dependencies
    validate_network
    validate_ports
    cleanup_stale_containers

    echo ""
    echo "================================================================"

    if [[ "$PREFLIGHT_PASSED" == true ]]; then
        echo -e "${GREEN}Pre-flight checks PASSED${NC}"
        echo "================================================================"
        exit 0
    else
        echo -e "${RED}Pre-flight checks FAILED${NC}"
        echo "Please resolve the issues above before running tests."
        echo "================================================================"
        exit 1
    fi
}

# Run if executed directly
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
