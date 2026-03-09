#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ HTTP 10-Node Build Configuration ############################
# Creates a 10-node HTTP line topology for extended testing
#
# Topology (linear chain):
#   A <-> B <-> C <-> D <-> E <-> F <-> G <-> H <-> I <-> J
#
# This topology tests:
# - Multi-hop message routing (up to 9 hops)
# - P2P request level limits (default max is 5)
# - Longer transaction chains and sync behavior
#
# Usage: Source this file from run-all-tests.sh or directly
#        source buildfiles/http10.sh
#
# Required:
# - $network variable must be set (docker network name)
# - remove_container_if_exists function from config.sh
#
# Configuration:
# - defaultFee: Transaction fee (default: 0.01)
# - defaultCredit: Credit limit (default: 1000)
# - containers: Array of container names (node-a through node-j)
#
# Resource Requirements: ~2.8GB RAM for all containers
#
# NOTE: p2pRequestLevel has a max of 5 hops by default. Some routing tests
# may require increasing this limit or testing within the 5-hop range.
######################################################################################

set -e # Stop script on failure

# Check if network exists and create it if necessary
if docker network inspect "${network}" >/dev/null 2>&1; then
    echo "Network already exists."
else
    echo "Creating network..."
    docker network create --driver bridge "${network}"
fi

declare -A containerAddresses

declare -a containers=(
    "httpA" 
    "httpB" 
    "httpC" 
    "httpD"
    "httpE"
    "httpF"
    "httpG"
    "httpH"
    "httpI"
    "httpJ")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [httpA,httpB] defines httpB as a contact of httpA
#          must be accepted in reverse that is to say: 
#          [httpA,httpB] needs to be followed by [httpB,httpA]
declare -A containersLinks=(
    [httpA,httpB]="$defaultFee $defaultCredit USD"
    [httpB,httpA]="$defaultFee $defaultCredit USD"
    [httpB,httpC]="$defaultFee $defaultCredit USD"
    [httpC,httpB]="$defaultFee $defaultCredit USD"
    [httpC,httpD]="$defaultFee $defaultCredit USD"
    [httpD,httpC]="$defaultFee $defaultCredit USD"
    [httpD,httpE]="$defaultFee $defaultCredit USD"
    [httpE,httpD]="$defaultFee $defaultCredit USD"
    [httpE,httpF]="$defaultFee $defaultCredit USD"
    [httpF,httpE]="$defaultFee $defaultCredit USD"
    [httpF,httpG]="$defaultFee $defaultCredit USD"
    [httpG,httpF]="$defaultFee $defaultCredit USD"
    [httpG,httpH]="$defaultFee $defaultCredit USD"
    [httpH,httpG]="$defaultFee $defaultCredit USD"
    [httpH,httpI]="$defaultFee $defaultCredit USD"
    [httpI,httpH]="$defaultFee $defaultCredit USD"
    [httpI,httpJ]="$defaultFee $defaultCredit USD"
    [httpJ,httpI]="$defaultFee $defaultCredit USD"
)

declare -A expectedContacts=(
    [httpA]=1  # Only connected to httpB
    [httpB]=2  # Connected to httpA and httpC
    [httpC]=2  # Connected to httpB and httpC
    [httpD]=2  # Connected to httpC and httpE
    [httpE]=2  # Connected to httpD and httpF
    [httpF]=2  # Connected to httpE and httpG
    [httpG]=2  # Connected to httpF and httpH
    [httpH]=2  # Connected to httpG and httpI
    [httpI]=2  # Connected to httpH and httpJ
    [httpJ]=1  # Only connected to httpJ
)

# For 10-line topology: A-B-C-D-E-F-G-H-I-J
# Test A->C (should route through B)
# Test A->D (should route through B and C)
# Test A->E (should route through B, C and D)
# Test A->F (should route through B, C, D and E)
# Test A->G (should route through B, C, D, E and F)
# Test G->J (should route through H and I)
### Note that by default the p2pRequestLevel of 6 does not result in a succesfull p2p > 6 contacts so A->I does not work
declare -A routingTests=(
    [httpA,httpC]="httpB"
    [httpA,httpD]="httpB,httpC"
    [httpA,httpE]="httpB,httpC,httpD"
    [httpA,httpF]="httpB,httpC,httpD,httpE"
    [httpA,httpG]="httpB,httpC,httpD,httpE,httpF"
    [httpG,httpJ]="httpH,httpI"    
)

echo "Removing existing containers and associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Building base image..."
cd ../
docker build -f eiou.dockerfile -t eiou/eiou .

echo -e "\nCreating containers..."
# Pass env flags from parent shell (defaults if not set)
CONTACT_STATUS_FLAG="${EIOU_CONTACT_STATUS_ENABLED:-true}"
TOR_FORCE_FAST_FLAG="${EIOU_TOR_FORCE_FAST:-true}"
DEFAULT_TRANSPORT_FLAG="${EIOU_DEFAULT_TRANSPORT_MODE:-http}"
HOP_BUDGET_RANDOMIZED_FLAG="${EIOU_HOP_BUDGET_RANDOMIZED:-false}"
for container in "${containers[@]}"; do
    docker run -d --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-backups:/var/lib/eiou/backups" -v "${container}-letsencrypt:/etc/letsencrypt" -e QUICKSTART=$container -e EIOU_CONTACT_STATUS_ENABLED=$CONTACT_STATUS_FLAG -e EIOU_TOR_FORCE_FAST=$TOR_FORCE_FAST_FLAG -e EIOU_DEFAULT_TRANSPORT_MODE=$DEFAULT_TRANSPORT_FLAG -e EIOU_HOP_BUDGET_RANDOMIZED=$HOP_BUDGET_RANDOMIZED_FLAG eiou/eiou
done