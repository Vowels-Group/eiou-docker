#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

set -e # Stop script on failure

# Check if network exists and create it if necessary
if docker network inspect network >/dev/null 2>&1; then
    echo "Network already exists."
else
    echo "Creating network..."
    docker network create --driver bridge network
fi

# Function to remove a container if it exists
remove_container_if_exists() {
    local container_name=$1
    if docker ps -a --format '{{.Names}}' | grep -q "^$container_name$"; then
        echo -e "\tRemoving existing container: $container_name..."
        docker rm -f "$container_name"
    fi
}

# Function to remove a volume if it exists
remove_volume_if_exists() {
    local volume_name=$1
    if docker volume ls --format '{{.Name}}' | grep -q "^$volume_name$"; then
        echo -e "\tRemoving existing volume: $volume_name..."
        docker volume rm "$volume_name"
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
# example: [0,1] defines 1 as a contact of 0
#          must be accepted in reverse that is to say: 
#          [0,1] needs to be followed by [1,0]
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

echo "Removing existing containers (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Removing associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_volume_if_exists "${container}-mysql-data"
    remove_volume_if_exists "${container}-files"
    remove_volume_if_exists "${container}-index"
    remove_volume_if_exists "${container}-eiou"
    remove_volume_if_exists "${container}-backups"
done

echo "Building base image..."
docker build -f eiou.dockerfile -t eiou/eiou .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --network=network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-backups:/var/lib/eiou/backups" -e QUICKSTART=$container eiou/eiou
done

# Save container Addresses in the associative array containerAddresses
#       containerAddresses[containerName] = containerAddress (https)
echo -e "\nSaving hostnames..."
for container in "${containers[@]}"; do
    containerAddresses[$container]="https://"$container
done

echo -e "\nSleeping for 5 seconds for proper startup of all containers..."
sleep 5

# Add friends
echo -e "\nAdding friends..."
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
for containersLinkKey in "${containersLinkKeys[@]}"; do
    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })    
    echo -e "\n\t-> Adding ${containerKeys[0]} To ${containerKeys[1]} as a contact: "
    docker exec ${containerKeys[0]} eiou add ${containerAddresses[${containerKeys[1]}]} ${containerKeys[1]} ${values[0]} ${values[1]} ${values[2]}
done


# Send money
echo -e "\nSending money..."
docker exec A0 eiou send ${containerAddresses[A42]} 100 USD
docker exec A0 eiou send ${containerAddresses[A31]} 100 USD
docker exec A0 eiou send ${containerAddresses[A2]} 100 USD

echo -e "\nSleeping for 5 seconds for proper transaction completion..."
sleep 5

echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec A0 eiou viewcontact ${containerAddresses[A4]}

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances/transaction history)
echo -e "\nSleeping for 5 seconds..."
sleep 5 

# View balances
echo -e "\nViewing balances..."
docker exec A0 eiou viewbalances
docker exec A4 eiou viewbalances
docker exec A42 eiou viewbalances
docker exec A3 eiou viewbalances
docker exec A31 eiou viewbalances
docker exec A2 eiou viewbalances


# View transaction history
echo -e "\nViewing transaction history..."
docker exec A0 eiou history
docker exec A4 eiou history
docker exec A42 eiou history
docker exec A3 eiou history
docker exec A31 eiou history
docker exec A2 eiou history

echo -e "\nScript completed successfully."
