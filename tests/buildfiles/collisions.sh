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

# Randomized fees (0.1-0.9) per edge, same both directions
# This ensures best-fee routing is tested against varying fee structures
readonly defaultCredit=1000

# Generate a random fee: 0.1 to 0.9 (single decimal digit)
random_fee() { echo "0.$(( RANDOM % 9 + 1 ))"; }

fee_A0_A1=$(random_fee)
fee_A0_A2=$(random_fee)
fee_A1_A3=$(random_fee)
fee_A1_A4=$(random_fee)
fee_A2_A4=$(random_fee)
fee_A2_A5=$(random_fee)
fee_A3_A6=$(random_fee)
fee_A4_A6=$(random_fee)
fee_A4_A7=$(random_fee)
fee_A5_A7=$(random_fee)
fee_A6_A8=$(random_fee)
fee_A7_A8=$(random_fee)

# Define contacts, direction ->
# example: [A0,A1] defines A0 as a contact of A1
#          must be accepted in reverse that is to say:
#          [A0,A1] needs to be followed by [A1,A0]
declare -A containersLinks=(
    [A0,A1]="$fee_A0_A1 $defaultCredit USD"
    [A1,A0]="$fee_A0_A1 $defaultCredit USD"
    [A1,A3]="$fee_A1_A3 $defaultCredit USD"
    [A3,A1]="$fee_A1_A3 $defaultCredit USD"
    [A1,A4]="$fee_A1_A4 $defaultCredit USD"
    [A4,A1]="$fee_A1_A4 $defaultCredit USD"
    [A0,A2]="$fee_A0_A2 $defaultCredit USD"
    [A2,A0]="$fee_A0_A2 $defaultCredit USD"
    [A2,A5]="$fee_A2_A5 $defaultCredit USD"
    [A5,A2]="$fee_A2_A5 $defaultCredit USD"
    [A2,A4]="$fee_A2_A4 $defaultCredit USD"
    [A4,A2]="$fee_A2_A4 $defaultCredit USD"
    [A6,A3]="$fee_A3_A6 $defaultCredit USD"
    [A3,A6]="$fee_A3_A6 $defaultCredit USD"
    [A6,A4]="$fee_A4_A6 $defaultCredit USD"
    [A4,A6]="$fee_A4_A6 $defaultCredit USD"
    [A6,A8]="$fee_A6_A8 $defaultCredit USD"
    [A8,A6]="$fee_A6_A8 $defaultCredit USD"
    [A7,A4]="$fee_A4_A7 $defaultCredit USD"
    [A4,A7]="$fee_A4_A7 $defaultCredit USD"
    [A7,A5]="$fee_A5_A7 $defaultCredit USD"
    [A5,A7]="$fee_A5_A7 $defaultCredit USD"
    [A7,A8]="$fee_A7_A8 $defaultCredit USD"
    [A8,A7]="$fee_A7_A8 $defaultCredit USD"
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

# 9-node cluster (collision) topology with randomized fees:
##
##              A3
##            /    \
##         A1        A6
##       /    \    /    \
##    A0        A4        A8
##       \    /    \    /
##         A2        A7
##            \    /
##              A5
##
## Fees are randomized (0.1-0.9) per run; best-fee route varies each time.
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