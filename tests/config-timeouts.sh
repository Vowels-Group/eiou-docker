#!/bin/bash
# Copyright 2025
# Configurable timeouts based on topology size
# Source this file in test scripts to get appropriate timeout values

# Default timeouts (can be overridden by environment variables)
# All values in seconds

# Topology detection
detect_topology() {
    local build_name="${BUILD_NAME:-http4}"

    # Extract number from build name
    if [[ "$build_name" =~ ([0-9]+) ]]; then
        echo "${BASH_REMATCH[1]}"
    else
        echo "4"
    fi
}

# Set timeouts based on topology
configure_timeouts() {
    local topology="${1:-$(detect_topology)}"

    case "$topology" in
        4|http4)
            # 4-node topology - quickest tests
            TIMEOUT_INIT=${TIMEOUT_INIT:-15}
            TIMEOUT_MESSAGE=${TIMEOUT_MESSAGE:-10}
            TIMEOUT_ROUTING=${TIMEOUT_ROUTING:-15}
            TIMEOUT_TRANSACTION=${TIMEOUT_TRANSACTION:-20}
            TIMEOUT_BALANCE_SYNC=${TIMEOUT_BALANCE_SYNC:-15}
            TIMEOUT_CONTACT_ADD=${TIMEOUT_CONTACT_ADD:-10}
            TIMEOUT_CONTAINER_START=${TIMEOUT_CONTAINER_START:-60}
            TIMEOUT_CONTAINER_HEALTHY=${TIMEOUT_CONTAINER_HEALTHY:-120}
            TIMEOUT_TOR=${TIMEOUT_TOR:-60}
            MAX_RETRY_ATTEMPTS=${MAX_RETRY_ATTEMPTS:-3}
            ;;
        10|http10)
            # 10-node topology - medium complexity
            TIMEOUT_INIT=${TIMEOUT_INIT:-25}
            TIMEOUT_MESSAGE=${TIMEOUT_MESSAGE:-15}
            TIMEOUT_ROUTING=${TIMEOUT_ROUTING:-20}
            TIMEOUT_TRANSACTION=${TIMEOUT_TRANSACTION:-30}
            TIMEOUT_BALANCE_SYNC=${TIMEOUT_BALANCE_SYNC:-25}
            TIMEOUT_CONTACT_ADD=${TIMEOUT_CONTACT_ADD:-15}
            TIMEOUT_CONTAINER_START=${TIMEOUT_CONTAINER_START:-90}
            TIMEOUT_CONTAINER_HEALTHY=${TIMEOUT_CONTAINER_HEALTHY:-180}
            TIMEOUT_TOR=${TIMEOUT_TOR:-90}
            MAX_RETRY_ATTEMPTS=${MAX_RETRY_ATTEMPTS:-4}
            ;;
        13|http13)
            # 13-node topology - largest/slowest
            TIMEOUT_INIT=${TIMEOUT_INIT:-40}
            TIMEOUT_MESSAGE=${TIMEOUT_MESSAGE:-20}
            TIMEOUT_ROUTING=${TIMEOUT_ROUTING:-30}
            TIMEOUT_TRANSACTION=${TIMEOUT_TRANSACTION:-45}
            TIMEOUT_BALANCE_SYNC=${TIMEOUT_BALANCE_SYNC:-35}
            TIMEOUT_CONTACT_ADD=${TIMEOUT_CONTACT_ADD:-20}
            TIMEOUT_CONTAINER_START=${TIMEOUT_CONTAINER_START:-120}
            TIMEOUT_CONTAINER_HEALTHY=${TIMEOUT_CONTAINER_HEALTHY:-240}
            TIMEOUT_TOR=${TIMEOUT_TOR:-120}
            MAX_RETRY_ATTEMPTS=${MAX_RETRY_ATTEMPTS:-5}
            ;;
        *)
            # Default to 4-node values
            configure_timeouts 4
            ;;
    esac

    # Export for use in tests
    export TIMEOUT_INIT
    export TIMEOUT_MESSAGE
    export TIMEOUT_ROUTING
    export TIMEOUT_TRANSACTION
    export TIMEOUT_BALANCE_SYNC
    export TIMEOUT_CONTACT_ADD
    export TIMEOUT_CONTAINER_START
    export TIMEOUT_CONTAINER_HEALTHY
    export TIMEOUT_TOR
    export MAX_RETRY_ATTEMPTS
}

# Print current timeout configuration
print_timeout_config() {
    echo "Timeout Configuration:"
    echo "  Container Start:    ${TIMEOUT_CONTAINER_START}s"
    echo "  Container Healthy:  ${TIMEOUT_CONTAINER_HEALTHY}s"
    echo "  Initialization:     ${TIMEOUT_INIT}s"
    echo "  Message Delivery:   ${TIMEOUT_MESSAGE}s"
    echo "  Routing:            ${TIMEOUT_ROUTING}s"
    echo "  Transaction:        ${TIMEOUT_TRANSACTION}s"
    echo "  Balance Sync:       ${TIMEOUT_BALANCE_SYNC}s"
    echo "  Contact Add:        ${TIMEOUT_CONTACT_ADD}s"
    echo "  Tor Connection:     ${TIMEOUT_TOR}s"
    echo "  Max Retry Attempts: ${MAX_RETRY_ATTEMPTS}"
}

# Calculate adaptive timeout based on container count
calculate_adaptive_timeout() {
    local base_timeout="$1"
    local container_count="${2:-4}"

    # Add 5 seconds per additional container beyond 4
    local extra_containers=$(( container_count - 4 ))
    [ $extra_containers -lt 0 ] && extra_containers=0

    echo $(( base_timeout + (extra_containers * 5) ))
}

# Get timeout for specific operation
get_timeout() {
    local operation="$1"

    case "$operation" in
        init)           echo "$TIMEOUT_INIT" ;;
        message)        echo "$TIMEOUT_MESSAGE" ;;
        routing)        echo "$TIMEOUT_ROUTING" ;;
        transaction)    echo "$TIMEOUT_TRANSACTION" ;;
        balance)        echo "$TIMEOUT_BALANCE_SYNC" ;;
        contact)        echo "$TIMEOUT_CONTACT_ADD" ;;
        container)      echo "$TIMEOUT_CONTAINER_START" ;;
        healthy)        echo "$TIMEOUT_CONTAINER_HEALTHY" ;;
        tor)            echo "$TIMEOUT_TOR" ;;
        *)              echo "30" ;; # Default timeout
    esac
}

# Auto-configure when sourced (if BUILD_NAME is set)
if [[ -n "$BUILD_NAME" ]]; then
    configure_timeouts
fi
