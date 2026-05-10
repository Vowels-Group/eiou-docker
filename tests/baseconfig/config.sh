#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################### Base Configuration #################################
# Shared configuration variables and helper functions for eIOU Docker test suite
#
# This file provides:
# - Container name arrays and network configuration
# - Color codes for test output formatting
# - Path variables (using // prefix for Windows Git Bash compatibility)
# - Common utility functions for container management
#
# Usage: Source this file at the start of test scripts
#        source baseconfig/config.sh
#
# NOTE: Paths use double slashes (//app/eiou/, //etc/eiou/config/) to prevent
# Git Bash on Windows from converting paths. This is safe on Linux too.
##################################################################################

############################### Base config #################################

RED='\033[0;31m'            # Red for failure/error
GREEN='\033[0;32m'          # Green for success
YELLOW='\033[1;33m'
NC='\033[0m'                # Revert to normal color
CHECK='\u2714'              # Checkmark
CROSS='\u274c'              # Crossmark

network="eiou-network"

# Define paths with double slashes to prevent Git Bash MSYS path conversion
# These paths are used INSIDE Docker containers, not on the host system
# Double slashes prevent Git Bash on Windows from converting /etc/ to C:/Program Files/Git/etc/
EIOU_DIR="//etc//eiou"
USERCONFIG="${EIOU_DIR}//config//userconfig.json"
MASTER_KEY="${EIOU_DIR}//config//.master.key"
SRC_DIR="${EIOU_DIR}//src"
CORE_DIR="${SRC_DIR}//core"
DATABASE_DIR="${SRC_DIR}//database"
SERVICES_DIR="${SRC_DIR}//services"
SECURITY_DIR="${SRC_DIR}//security"
APPLICATION_FILE="${CORE_DIR}//Application.php"
PDO_FILE="${DATABASE_DIR}//Pdo.php"
PHP_ERRORS_LOG="//var//log//php_errors.log"
NGINX_ERROR_LOG="//var//log//nginx//error.log"
TOR_HIDDEN_SERVICE_DIR="//var//lib//tor//hidden_service"
TOR_SECRET_KEY="${TOR_HIDDEN_SERVICE_DIR}//hs_ed25519_secret_key"
TOR_PUBLIC_KEY="${TOR_HIDDEN_SERVICE_DIR}//hs_ed25519_public_key"
TOR_HOSTNAME="${TOR_HIDDEN_SERVICE_DIR}//hostname"

# Bootstrap path for Composer autoloading
# The bootstrap file initializes Composer autoloader and must be included before using any classes
BOOTSTRAP_PATH="//app//eiou//src//bootstrap.php"

# Legacy paths (deprecated - use BOOTSTRAP_PATH with fully qualified class names)
# Kept for reference only
REL_APPLICATION=".//app//eiou//src//core//Application.php"
REL_FUNCTIONS=".//app//eiou//Functions.php"

#############################################################################



########################## General Functionality ############################

# Display test results summary with color-coded pass/fail output
# Usage: succesrate total_tests passed_count failed_count test_name
# Example: succesrate 10 8 2 "contact"
function succesrate(){
    local totaltests="$1"
    local passed="$2"
    local failure="$3"
    local testname="$4"
    if [[ $passed == $totaltests ]]; then
        printf "${GREEN}${CHECK} PASSED all ${testname} tests!${NC}\n"
    elif [[ $passed == 0 ]]; then
        printf "${RED}${CROSS} FAILED all ${testname} tests!${NC}\n"
    else
        printf "${GREEN}PASSED ${passed}${NC} ${testname} tests out of ${totaltests}\n"
        printf "${RED}FAILED ${failure}${NC} ${testname} tests out of ${totaltests}\n"
    fi
}

# Determine transport type from address URL
# Usage: determineTransport "http://alice" -> "http"
# Returns: "http", "https", or "tor" based on address format
function determineTransport(){
    local address="$1"
    if [[ ${address} =~ ^https:\/\/ ]]; then
        echo 'https'
    elif [[ ${address} =~ ^http:\/\/ ]]; then
        echo 'http'
    elif [[ ${address} =~ \.onion$ ]]; then
        echo 'tor'
    fi
}

# Check if address uses HTTP-based transport (either http or https)
# Useful for backward compatibility where both protocols share similar handling
function isHttpTransport(){
    local address="$1"
    local transport=$(determineTransport "$address")
    if [[ "$transport" == "http" || "$transport" == "https" ]]; then
        echo 'true'
    else
        echo 'false'
    fi
}

# Get the expected protocol prefix based on MODE variable
# Returns: "http://", "https://", or "" (for tor)
function getExpectedProtocol(){
    case "${MODE:-http}" in
        http)  echo "http://"  ;;
        https) echo "https://" ;;
        tor)   echo ""         ;;
        *)     echo "http://"  ;;  # Default fallback
    esac
}

# Get PHP-compatible transport type from address
# Use this when passing transport type to PHP functions
function getPhpTransportType(){
    local address="$1"
    local transport=$(determineTransport "$address")
    echo "$transport"
}

# Wait for a condition to be met with polling
# Usage: wait_for_condition "condition_command" timeout_seconds check_interval_seconds "description"
# Returns: 0 if condition met, 1 if timeout
# Example: wait_for_condition "docker exec httpA eiou balance | grep -q '10'" 20 1 "balance update"
function wait_for_condition(){
    local condition_cmd="$1"
    local timeout="${2:-20}"
    local interval="${3:-1}"
    local description="${4:-condition}"
    local elapsed=0

    while [ $elapsed -lt $timeout ]; do
        # Evaluate the condition
        if eval "$condition_cmd" 2>/dev/null; then
            return 0
        fi
        sleep $interval
        elapsed=$((elapsed + interval))
    done

    # Timeout reached
    return 1
}

# Wait for balance change on a container
# Usage: wait_for_balance_change container initial_balance pubkey_cmd timeout description
# Returns: new_balance (echoed), return code 0 if changed, 1 if timeout
function wait_for_balance_change(){
    local container="$1"
    local initial_balance="$2"
    local balance_cmd="$3"
    local timeout="${4:-20}"
    local description="${5:-balance change}"
    local elapsed=0
    local new_balance

    while [ $elapsed -lt $timeout ]; do
        new_balance=$(docker exec $container sh -c "$balance_cmd" 2>/dev/null || echo "$initial_balance")

        # Check if balance changed (use awk for float comparison)
        if [ "$(awk "BEGIN {print ($new_balance != $initial_balance) ? 1 : 0}")" -eq 1 ]; then
            echo "$new_balance"
            return 0
        fi

        sleep 1
        elapsed=$((elapsed + 1))
    done

    # Timeout - return initial balance
    echo "$initial_balance"
    return 1
}

# Wait for transaction status to reach expected state
# Usage: wait_for_tx_status container txid expected_status timeout
function wait_for_tx_status(){
    local container="$1"
    local txid="$2"
    local expected_status="$3"
    local timeout="${4:-20}"
    local elapsed=0
    local status

    while [ $elapsed -lt $timeout ]; do
        status=$(docker exec $container php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$app = \Eiou\Core\Application::getInstance();
            \$tx = \$app->services->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class)->getByTxid('$txid');
            echo \$tx['status'] ?? 'unknown';
        " 2>/dev/null || echo "unknown")

        if [ "$status" = "$expected_status" ]; then
            echo "$status"
            return 0
        fi

        sleep 1
        elapsed=$((elapsed + 1))
    done

    echo "$status"
    return 1
}

# Wait for contact to be added
# Usage: wait_for_contact container address timeout
function wait_for_contact(){
    local container="$1"
    local address="$2"
    local timeout="${3:-10}"
    local elapsed=0

    transportCheck=$(getPhpTransportType ${address})
    while [ $elapsed -lt $timeout ]; do
        local contact_exists=$(docker exec $container php -r "
            require_once('${BOOTSTRAP_PATH}');
            \$app = \Eiou\Core\Application::getInstance();
            \$contact = \$app->services->getRepositoryFactory()->get(\Eiou\Database\ContactRepository::class)->getContactByAddress('${transportCheck}','$address');
            echo \$contact ? 'yes' : 'no';
        " 2>/dev/null || echo "no")

        if [ "$contact_exists" = "yes" ]; then
            return 0
        fi

        sleep 1
        elapsed=$((elapsed + 1))
    done
    return 1
}

# Function to remove a volume only if it exists
remove_volume_if_exists() {
    local volume_name=$1
    if docker volume ls -q | grep -q "^${volume_name}$"; then
        docker volume rm "$volume_name"
    fi
}

# Function to remove a container if it exists and any volumes associated
remove_container_if_exists() {
    local container_name=$1
    if docker ps -a --format '{{.Names}}' | grep -q "^$container_name$"; then
        echo "Removing existing container: $container_name..."
        docker rm -f "$container_name"
    fi
    echo "Removing any volumes of container: $container_name (if they exist)..."
    remove_volume_if_exists "$container_name-mysql-data"
    remove_volume_if_exists "$container_name-config"
    remove_volume_if_exists "$container_name-plugins"
    remove_volume_if_exists "$container_name-ssl-cert"
    remove_volume_if_exists "$container_name-backups"
}

#############################################################################

# Wait for a fully-connected Tor hidden-service mesh between containers.
#
# WHY: tests/run-all-tests.sh's per-container `tor_ready` check verifies only
# that a container can reach its OWN .onion through its OWN SOCKS5 proxy.
# That succeeds quickly because the descriptor is in the local Tor cache;
# it does NOT prove cross-container reachability, which requires:
#   (a) container B's descriptor uploaded to enough HSDirs in the consensus,
#   (b) container A successfully fetching B's descriptor from an HSDir,
#   (c) circuit through B's introduction points + rendezvous negotiation.
# Real-world cross-node convergence on a freshly-started Tor v3 hidden
# service is typically 3–10 min and may exceed 15 min on first publish.
# In a 4-container Docker bridge, the consensus is the same as the public
# Tor network (we don't run a private testnet), so the bottleneck is
# descriptor propagation, not local routing.
#
# Tests that exercise contact-pings or P2P delivery via Tor (chainDrop,
# parts of sync) need cross-mesh reachability, not just self-reachability.
#
# WHAT IT DOES: For every (from, to) pair of accepted contact containers,
# repeatedly curls "to"'s .onion through "from"'s SOCKS5 until success or
# timeout. Caches successes so each pair only needs to converge once.
# Reports per-pair status at the end; returns 0 only if every pair
# converged. The mesh may genuinely never fully connect (HSDir uploads
# can fail silently), so the caller must decide whether a partial mesh
# is acceptable for the tests it intends to run.
#
# Usage: wait_for_tor_mesh container1 container2 [container3 ...]
# Env:   EIOU_TOR_MESH_TIMEOUT  Per-pair cap in seconds (default 600 = 10 min)
#        EIOU_TOR_MESH_INTERVAL Seconds between probes (default 10)
# Returns: 0 if every pair converged, 1 if any pair timed out
wait_for_tor_mesh() {
    local containers=("$@")
    local per_pair_timeout="${EIOU_TOR_MESH_TIMEOUT:-600}"
    local probe_interval="${EIOU_TOR_MESH_INTERVAL:-10}"
    local n=${#containers[@]}

    if [ "$n" -lt 2 ]; then
        echo "wait_for_tor_mesh: need at least 2 containers"
        return 0
    fi

    # Resolve and validate every container's .onion before any probing.
    # Builds a plain space-separated "name=onion" map so this works under
    # plain bash without `declare -A` portability concerns.
    local onion_map=""
    local c addr
    for c in "${containers[@]}"; do
        addr=$(docker exec "$c" php -r '
            if (file_exists("/etc/eiou/config/userconfig.json")) {
                $j = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"), true);
                if (isset($j["torAddress"])) echo $j["torAddress"];
            }' 2>/dev/null)
        if [ -z "$addr" ]; then
            printf "  ${RED}%s has no torAddress in userconfig.json${NC}\n" "$c"
            return 1
        fi
        onion_map="${onion_map} ${c}=${addr}"
    done

    printf "\n${GREEN}Waiting for cross-container Tor mesh to converge "
    printf "(per-pair cap: ${per_pair_timeout}s, %d directed pairs)...${NC}\n" $((n * (n - 1)))

    # Probe every directed pair. Each pair is independent.
    local all_ok=true
    local from to target elapsed converged
    for from in "${containers[@]}"; do
        for to in "${containers[@]}"; do
            [ "$from" = "$to" ] && continue
            # Look up `to`'s onion from the precomputed map
            target=$(echo "$onion_map" | tr ' ' '\n' | grep "^${to}=" | cut -d= -f2)
            elapsed=0
            converged=false

            printf "  %s -> %s (%s…)... " "$from" "$to" "${target:0:16}"

            while [ $elapsed -lt $per_pair_timeout ]; do
                if docker exec "$from" curl --socks5-hostname 127.0.0.1:9050 \
                        --connect-timeout 8 \
                        --max-time 20 \
                        --silent \
                        --fail \
                        --output /dev/null \
                        "$target" 2>/dev/null; then
                    converged=true
                    break
                fi
                sleep $probe_interval
                elapsed=$((elapsed + probe_interval))
            done

            if [ "$converged" = true ]; then
                printf "${GREEN}reachable after %ds${NC}\n" "$elapsed"
            else
                printf "${YELLOW}not reachable after %ds (giving up)${NC}\n" "$elapsed"
                all_ok=false
            fi
        done
    done

    if [ "$all_ok" = true ]; then
        printf "${GREEN}Tor mesh fully converged${NC}\n"
        return 0
    else
        printf "${YELLOW}Tor mesh PARTIALLY converged. Tests that route over Tor "
        printf "between non-converged pairs will fail; check Tor logs in the "
        printf "affected containers for HSDir upload errors.${NC}\n"
        return 1
    fi
}

########################## Test Helper Functions ############################
# Source consolidated test helper functions
# These provide common utilities used across multiple test suites
#############################################################################

# Source testHelpers.sh from baseconfig directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/testHelpers.sh" ]; then
    . "${SCRIPT_DIR}/testHelpers.sh"
elif [ -f "./tests/baseconfig/testHelpers.sh" ]; then
    . "./tests/baseconfig/testHelpers.sh"
fi

#############################################################################
# CREDENTIAL LOADING (optional)
# Source credentials.sh if it exists for custom environment configuration
#############################################################################

# Source credentials file if it exists
if [ -f "${SCRIPT_DIR}/credentials.sh" ]; then
    . "${SCRIPT_DIR}/credentials.sh"
elif [ -f "./tests/baseconfig/credentials.sh" ]; then
    . "./tests/baseconfig/credentials.sh"
fi

#############################################################################