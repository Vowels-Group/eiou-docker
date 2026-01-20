#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

############################ HTTP 4-Node Build Configuration ############################
# Creates a 4-node HTTP line topology for testing
#
# Topology (linear):
#   Alice <---> Bob <---> Carol <---> Daniel
#
# Usage: Source this file from run-all-tests.sh or directly
#        source buildfiles/http4.sh
#
# Required:
# - $network variable must be set (docker network name)
# - remove_container_if_exists function from config.sh
#
# Configuration:
# - defaultFee: Transaction fee (default: 0.01)
# - defaultCredit: Credit limit (default: 1000)
# - containers: Array of container names
# - containerAddresses: HTTP addresses for each container
# - containersLinks: Contact relationships to establish
#
# Resource Requirements: ~1.1GB RAM for all containers
#####################################################################################

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
    "httpD")

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
)

declare -A expectedContacts=(
    [httpA]=1  # Only connected to httpB
    [httpB]=2  # Connected to httpA and httpC
    [httpC]=2  # Connected to httpB and httpC
    [httpD]=1  # Only connected to httpC
)

# For 4-line topology: A-B-C-D
# Test A->C (should route through B)
# Test A->D (should route through B and C)
# Test B->D (should route through C)
declare -A routingTests=(
    [httpA,httpC]="httpB"
    [httpA,httpD]="httpB,httpC"
    [httpB,httpD]="httpC"
)

echo "Removing existing containers and associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Building base image..."
cd ../
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
# Pass EIOU_CONTACT_STATUS_ENABLED from parent shell (defaults to true if not set)
CONTACT_STATUS_FLAG="${EIOU_CONTACT_STATUS_ENABLED:-true}"
for container in "${containers[@]}"; do
    docker run -d --network=eioud-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-index:/var/www/html" -v "${container}-eiou:/usr/local/bin/" -e QUICKSTART=$container -e EIOU_CONTACT_STATUS_ENABLED=$CONTACT_STATUS_FLAG eioud
done