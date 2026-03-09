#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

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
    "A0" 
    "A1" 
    "A11" 
    "A12"
    "A2"
    "A21"
    "A22"
    "A3"
    "A31"
    "A32"
    "A4"
    "A41"
    "A42")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [A0,A1] defines A0 as a contact of A1
#          must be accepted in reverse that is to say: 
#          [A0,A1] needs to be followed by [A1,A0]
declare -A containersLinks=(
    [A0,A1]="$defaultFee $defaultCredit USD"
    [A1,A0]="$defaultFee $defaultCredit USD"
    [A0,A2]="$defaultFee $defaultCredit USD"
    [A2,A0]="$defaultFee $defaultCredit USD"
    [A0,A3]="$defaultFee $defaultCredit USD"
    [A3,A0]="$defaultFee $defaultCredit USD"
    [A0,A4]="$defaultFee $defaultCredit USD"
    [A4,A0]="$defaultFee $defaultCredit USD"
    [A1,A11]="$defaultFee $defaultCredit USD"
    [A11,A1]="$defaultFee $defaultCredit USD"
    [A1,A12]="$defaultFee $defaultCredit USD"
    [A12,A1]="$defaultFee $defaultCredit USD"
    [A2,A21]="$defaultFee $defaultCredit USD"
    [A21,A2]="$defaultFee $defaultCredit USD"
    [A2,A22]="$defaultFee $defaultCredit USD"
    [A22,A2]="$defaultFee $defaultCredit USD"
    [A3,A31]="$defaultFee $defaultCredit USD"
    [A31,A3]="$defaultFee $defaultCredit USD"
    [A3,A32]="$defaultFee $defaultCredit USD"
    [A32,A3]="$defaultFee $defaultCredit USD"
    [A4,A41]="$defaultFee $defaultCredit USD"
    [A41,A4]="$defaultFee $defaultCredit USD"
    [A4,A42]="$defaultFee $defaultCredit USD"
    [A42,A4]="$defaultFee $defaultCredit USD"
)

declare -A expectedContacts=(
    [A0]=4   # Connected to A1, A2, A3 and A4
    [A1]=3   # Connected to A0, A11 and A12
    [A2]=3   # Connected to A0, A21 and A22
    [A3]=3   # Connected to A0, A31 and A32
    [A4]=3   # Connected to A0, A41 and A42
    [A11]=2  # Only connected to A1
    [A12]=2  # Only connected to A1
    [A21]=2  # Only connected to A2
    [A22]=2  # Only connected to A2
    [A31]=1  # Only connected to A3
    [A32]=1  # Only connected to A3
    [A41]=1  # Only connected to A4
    [A42]=1  # Only connected to A4
)

# For 13-node cluster topology:
## A31 A32   A41 A42
##  \  /      \  /
##   A3        A4
##     \      /
##        A0
##     /      \
##   A2        A1
##  /  \      /  \
## A22 A21  A12  A11  

# Test A0->A12 (should route through A1)
# Test A0->A31 (should route through A3)
# Test A32->A21 (should route through A3, A0 and A2)
# Test A12->A41 (should route through A1, A0 and A4)
declare -A routingTests=(
    [A0,A12]="A1"
    [A0,A31]="A3"
    [A32,A21]="A3,A0,A2"
    [A12,A41]="A1,A0,A4"
)

echo "Removing existing containers and associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Building base image..."
cd ../
docker build -f eiou.dockerfile -t eiou/eiou .

echo -e "\nCreating containers..."
# Pass EIOU_CONTACT_STATUS_ENABLED from parent shell (defaults to true if not set)
CONTACT_STATUS_FLAG="${EIOU_CONTACT_STATUS_ENABLED:-true}"
for container in "${containers[@]}"; do
    docker run -d --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-backups:/var/lib/eiou/backups" -v "${container}-letsencrypt:/etc/letsencrypt" -e QUICKSTART=$container -e EIOU_CONTACT_STATUS_ENABLED=$CONTACT_STATUS_FLAG eiou/eiou
done