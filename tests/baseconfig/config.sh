#!/bin/sh

############################### Base config #################################

RED='\033[0;31m'            # Red for failure/error
GREEN='\033[0;32m'          # Green for success
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
        printf "${GREEN}${CHECK} Passed all ${testname} tests!${NC}\n"
    else
        printf "${GREEN}PASSED ${passed} ${testname} tests ${NC} out of ${totaltests}\n"
        printf "${RED}FAILED ${failure} ${testname} tests ${NC} out of ${totaltests}\n"
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

#############################################################################