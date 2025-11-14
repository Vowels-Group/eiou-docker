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
for container in "${containers[@]}"; do
    docker run -d --network=eioud-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-index:/var/www/html" -v "${container}-eiou:/usr/local/bin/" -e QUICKSTART=$container eioud
done