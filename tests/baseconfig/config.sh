#!/bin/sh

############################### Base config #################################

RED='\033[0;31m'            # Red for failure/error
GREEN='\033[0;32m'          # Green for success
YELLOW='\033[1;33m'
NC='\033[0m'                # Revert to normal color
CHECK='\u2714'              # Checkmark
CROSS='\u274c'              # Crossmark

network="eioud-network"

# Define paths with double slashes to prevent Git Bash MSYS path conversion
EIOU_DIR="//etc//eiou"
USERCONFIG="${EIOU_DIR}//userconfig.json"
MASTER_KEY="${EIOU_DIR}//.master.key"

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

# Function to remove a container if it exists and any volumes associated
remove_container_if_exists() {
    local container_name=$1
    if docker ps -a --format '{{.Names}}' | grep -q "^$container_name$"; then
        echo "Removing existing container: $container_name..."
        docker rm -f "$container_name"
        echo "Removing any volumes of container: $container_name..."
        docker volume rm "$container_name-mysql-data"
        docker volume rm "$container_name-files"
        docker volume rm "$container_name-index"
        docker volume rm "$container_name-eiou"
    fi
}

#############################################################################