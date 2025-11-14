#!/bin/sh

set -e # Stop script on failure

# Check if network exists and create it if necessary
if docker network inspect eioud-network >/dev/null 2>&1; then
    echo "Network already exists."
else
    echo "Creating network..."
    docker network create --driver bridge eioud-network
fi

# Function to remove a container if it exists
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
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --network=eioud-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-index:/var/www/html" -v "${container}-eiou:/usr/local/bin/" -e QUICKSTART=$container eioud
done