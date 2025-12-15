#!/bin/bash
# Copyright 2025
# Retry framework with exponential backoff for test reliability
# Source this file in test scripts to use retry functionality

# Color codes (reuse from config if available)
RED=${RED:-'\033[0;31m'}
GREEN=${GREEN:-'\033[0;32m'}
YELLOW=${YELLOW:-'\033[1;33m'}
NC=${NC:-'\033[0m'}

# Default retry settings
DEFAULT_MAX_ATTEMPTS=5
DEFAULT_INITIAL_TIMEOUT=1
DEFAULT_MAX_TIMEOUT=30
DEFAULT_BACKOFF_MULTIPLIER=2

# Retry a command with exponential backoff
# Usage: retry_with_backoff [options] command [args...]
# Options:
#   -a, --attempts N     Maximum number of attempts (default: 5)
#   -t, --timeout N      Initial timeout in seconds (default: 1)
#   -m, --max-timeout N  Maximum timeout between retries (default: 30)
#   -q, --quiet          Suppress retry messages
retry_with_backoff() {
    local max_attempts=$DEFAULT_MAX_ATTEMPTS
    local timeout=$DEFAULT_INITIAL_TIMEOUT
    local max_timeout=$DEFAULT_MAX_TIMEOUT
    local quiet=false

    # Parse options
    while [[ $# -gt 0 ]]; do
        case "$1" in
            -a|--attempts)
                max_attempts="$2"
                shift 2
                ;;
            -t|--timeout)
                timeout="$2"
                shift 2
                ;;
            -m|--max-timeout)
                max_timeout="$2"
                shift 2
                ;;
            -q|--quiet)
                quiet=true
                shift
                ;;
            *)
                break
                ;;
        esac
    done

    local attempt=1

    while [ $attempt -le $max_attempts ]; do
        if "$@"; then
            return 0
        fi

        if [ $attempt -lt $max_attempts ]; then
            [[ "$quiet" == false ]] && echo -e "${YELLOW}Attempt $attempt/$max_attempts failed, retrying in ${timeout}s...${NC}" >&2
            sleep "$timeout"
            # Exponential backoff with cap
            timeout=$(( timeout * DEFAULT_BACKOFF_MULTIPLIER ))
            [ $timeout -gt $max_timeout ] && timeout=$max_timeout
            attempt=$(( attempt + 1 ))
        else
            [[ "$quiet" == false ]] && echo -e "${RED}All $max_attempts attempts failed${NC}" >&2
            return 1
        fi
    done
}

# Wait for a condition to become true
# Usage: wait_for_condition "condition_command" [max_wait_seconds] [check_interval] [description]
# Returns: 0 if condition met, 1 if timeout
# Example: wait_for_condition "docker exec httpA eiou balance | grep -q '10'" 20 1 "balance update"
wait_for_condition() {
    local condition="$1"
    local max_wait="${2:-60}"
    local check_interval="${3:-1}"
    local description="${4:-condition}"
    local elapsed=0

    while [ $elapsed -lt $max_wait ]; do
        # Evaluate the condition
        if eval "$condition" 2>/dev/null; then
            return 0
        fi
        sleep "$check_interval"
        elapsed=$(( elapsed + check_interval ))
        # Adaptive: back off after half timeout
        if [ $elapsed -gt $(( max_wait / 2 )) ] && [ "$check_interval" -lt 5 ]; then
            check_interval=5
        fi
    done

    # Timeout reached
    echo -e "${RED}Timeout waiting for $description (${max_wait}s)${NC}" >&2
    return 1
}

# Wait for Docker container to be healthy
# Usage: wait_for_container_healthy container_name [timeout_seconds]
wait_for_container_healthy() {
    local container="$1"
    local timeout="${2:-60}"

    wait_for_condition \
        "docker inspect --format='{{.State.Health.Status}}' '$container' 2>/dev/null | grep -q 'healthy'" \
        "$timeout" \
        2 \
        "container '$container' to become healthy"
}

# Wait for Docker container to start
# Usage: wait_for_container_running container_name [timeout_seconds]
wait_for_container_running() {
    local container="$1"
    local timeout="${2:-30}"

    wait_for_condition \
        "docker inspect --format='{{.State.Running}}' '$container' 2>/dev/null | grep -q 'true'" \
        "$timeout" \
        1 \
        "container '$container' to start running"
}

# Wait for HTTP endpoint to respond
# Usage: wait_for_http_ready url [timeout_seconds] [expected_status]
wait_for_http_ready() {
    local url="$1"
    local timeout="${2:-30}"
    local expected_status="${3:-200}"

    wait_for_condition \
        "curl -s -o /dev/null -w '%{http_code}' '$url' 2>/dev/null | grep -q '$expected_status'" \
        "$timeout" \
        2 \
        "HTTP endpoint '$url' to return $expected_status"
}

# Wait for database to be ready inside container
# Usage: wait_for_database_ready container_name [timeout_seconds]
wait_for_database_ready() {
    local container="$1"
    local timeout="${2:-30}"

    wait_for_condition \
        "docker exec '$container' mysqladmin ping -h localhost --silent 2>/dev/null" \
        "$timeout" \
        1 \
        "database in '$container' to be ready"
}

# Wait for EIOU application to be ready
# Usage: wait_for_eiou_ready container_name [timeout_seconds]
wait_for_eiou_ready() {
    local container="$1"
    local timeout="${2:-60}"

    wait_for_condition \
        "docker exec '$container' test -f /etc/eiou/userconfig.json 2>/dev/null" \
        "$timeout" \
        2 \
        "EIOU application in '$container' to initialize"
}

# Retry a docker exec command
# Usage: retry_docker_exec container_name command [args...]
retry_docker_exec() {
    local container="$1"
    shift
    retry_with_backoff docker exec "$container" "$@"
}

# Print retry status (for verbose output)
print_retry_status() {
    local attempt="$1"
    local max_attempts="$2"
    local operation="$3"

    echo -e "  ${YELLOW}Retry $attempt/$max_attempts: $operation${NC}"
}
