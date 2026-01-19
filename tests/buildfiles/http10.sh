#!/bin/sh
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

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
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
# Pass EIOU_CONTACT_STATUS_ENABLED from parent shell (defaults to true if not set)
CONTACT_STATUS_FLAG="${EIOU_CONTACT_STATUS_ENABLED:-true}"
for container in "${containers[@]}"; do
    docker run -d --network=eioud-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-index:/var/www/html" -v "${container}-eiou:/usr/local/bin/" -e QUICKSTART=$container -e EIOU_CONTACT_STATUS_ENABLED=$CONTACT_STATUS_FLAG eioud
done