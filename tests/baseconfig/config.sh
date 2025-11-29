#!/bin/sh

############################### Base config #################################

RED='\033[0;31m'            # Red for failure/error
GREEN='\033[0;32m'          # Green for success
YELLOW='\033[1;33m'
NC='\033[0m'                # Revert to normal color
CHECK='\u2714'              # Checkmark
CROSS='\u274c'              # Crossmark

#############################################################################



########################## General Functionality ############################

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

function determineTransport(){
    local address="$1"
    if [[ ${address} =~ ^https?:\/\/ ]]; then
        echo 'http'

    elif [[ ${address} =~ \.onion$ ]]; then
        echo 'tor'
    fi
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
            require_once('./etc/eiou/src/core/Application.php');
            \$app = Application::getInstance();
            \$tx = \$app->services->getTransactionRepository()->findByTxid('$txid');
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

    while [ $elapsed -lt $timeout ]; do
        local contact_exists=$(docker exec $container php -r "
            require_once('./etc/eiou/src/core/Application.php');
            \$app = Application::getInstance();
            \$contact = \$app->services->getContactRepository()->getContactByAnyAddress('$address');
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

#############################################################################