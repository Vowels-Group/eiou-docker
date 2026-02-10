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
    "A2" 
    "A3"
    "A4"
    "A5"
    "A6"
    "A7"
    "A8"
)

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
    [A1,A3]="0.5 $defaultCredit USD"
    [A3,A1]="0.5 $defaultCredit USD"
    [A1,A4]="0.3 $defaultCredit USD"
    [A4,A1]="0.3 $defaultCredit USD"
    [A0,A2]="$defaultFee $defaultCredit USD"
    [A2,A0]="$defaultFee $defaultCredit USD"
    [A2,A5]="0.4 $defaultCredit USD"
    [A5,A2]="0.4 $defaultCredit USD" 
    [A2,A4]="0.2 $defaultCredit USD"
    [A4,A2]="0.2 $defaultCredit USD"   
    [A6,A3]="0.6 $defaultCredit USD"
    [A3,A6]="0.6 $defaultCredit USD"
    [A6,A4]="$defaultFee $defaultCredit USD"
    [A4,A6]="$defaultFee $defaultCredit USD"
    [A6,A8]="0.8 $defaultCredit USD"
    [A8,A6]="0.8 $defaultCredit USD"
    [A7,A4]="0.3 $defaultCredit USD"
    [A4,A7]="0.3 $defaultCredit USD"
    [A7,A5]="0.5 $defaultCredit USD"
    [A5,A7]="0.5 $defaultCredit USD"
    [A7,A8]="$defaultFee $defaultCredit USD"
    [A8,A7]="$defaultFee $defaultCredit USD"
)

declare -A expectedContacts=(
    [A0]=2   # Connected to A1 and A2
    [A1]=3   # Connected to A0, A3, A4
    [A2]=3   # Connected to A0, A4 and A5
    [A3]=2   # Connected to A1, A6
    [A4]=4   # Connected to A1, A2, A6 and A7
    [A5]=2   # Connected to A2 and A7
    [A6]=3   # Connected to A3, A4 and A8
    [A7]=3   # Connected to A4, A5 and A8
    [A8]=2   # Only connected to A6 and A7

)

# For 9-node cluster (collision) topology:
##
##            A3
##          /0.5 \0.6
##       A1        A6
##     /0.1 \0.3 /0.1 \0.8
##  A0        A4        A8
##     \0.1 /0.2 \0.3 /0.1
##       A2        A7
##          \0.4 /0.5
##            A5
##

#Best Fee Route from A0 to A8 should be A0->A2->A4->A7->A8

# Test A0->A3 (should route through A1)
# Test A0->A8 (should route through A3, A4 and A7)
# Test A8->A2 (should route through A7 and A2)
# Test A2->A3 (should route through A5, A7, A8 and A6)
declare -A routingTests=(
    [A0,A3]="A1"
    [A0,A8]="A1,A4,A7"
    [A8,A2]="A7,A5"
    [A2,A3]="A5,A7,A8,A6"
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
    docker run -d --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-backups:/var/lib/eiou/backups" -e QUICKSTART=$container -e EIOU_CONTACT_STATUS_ENABLED=$CONTACT_STATUS_FLAG eiou/eiou
done