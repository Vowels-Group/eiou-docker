#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################### Base Configuration #################################
# Shared configuration variables and helper functions for EIOU Docker test suite
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
# NOTE: Paths use double slashes (//etc/eiou/) to prevent Git Bash on Windows
# from converting /etc/ to C:/Program Files/Git/etc/. This is safe on Linux too.
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
USERCONFIG="${EIOU_DIR}//userconfig.json"
MASTER_KEY="${EIOU_DIR}//.master.key"
SRC_DIR="${EIOU_DIR}//src"
CORE_DIR="${SRC_DIR}//core"
DATABASE_DIR="${SRC_DIR}//database"
SERVICES_DIR="${SRC_DIR}//services"
SECURITY_DIR="${SRC_DIR}//security"
APPLICATION_FILE="${CORE_DIR}//Application.php"
PDO_FILE="${DATABASE_DIR}//Pdo.php"
PHP_ERRORS_LOG="//var//log//php_errors.log"
APACHE_ERROR_LOG="//var//log//apache2//error.log"
TOR_HIDDEN_SERVICE_DIR="//var//lib//tor//hidden_service"
TOR_SECRET_KEY="${TOR_HIDDEN_SERVICE_DIR}//hs_ed25519_secret_key"
TOR_PUBLIC_KEY="${TOR_HIDDEN_SERVICE_DIR}//hs_ed25519_public_key"
TOR_HOSTNAME="${TOR_HIDDEN_SERVICE_DIR}//hostname"

# Bootstrap path for PSR-4 autoloading
# The bootstrap file initializes Composer autoloader and must be included before using any classes
BOOTSTRAP_PATH="//etc//eiou//src//bootstrap.php"

# Legacy paths (deprecated - use BOOTSTRAP_PATH with fully qualified class names)
# Kept for reference only
REL_APPLICATION=".//etc//eiou//src//core//Application.php"
REL_FUNCTIONS=".//etc//eiou//Functions.php"

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
            \$tx = \$app->services->getTransactionRepository()->getByTxid('$txid');
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
            \$contact = \$app->services->getContactRepository()->getContactByAddress('${transportCheck}','$address');
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
    remove_volume_if_exists "$container_name-files"
    remove_volume_if_exists "$container_name-index"
    remove_volume_if_exists "$container_name-eiou"
    remove_volume_if_exists "$container_name-backups"
}

#############################################################################

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