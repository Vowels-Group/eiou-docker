#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

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
        echo -e "\tRemoving existing container: $container_name..."
        docker rm -f "$container_name"
    fi
}

declare -A containerAddresses

declare -a containers=(
    "httpsA" 
    "httpsB" 
    "httpsC" 
    "httpsD")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [httpsA,httpsB] defines httpsB as a contact of httpsA
#          must be accepted in reverse that is to say: 
#          [httpsA,httpsB] needs to be followed by [httpsB,httpsA]
declare -A containersLinks=(
    [httpsA,httpsB]="$defaultFee $defaultCredit USD"
    [httpsB,httpsA]="$defaultFee $defaultCredit USD"
    [httpsB,httpsC]="$defaultFee $defaultCredit USD"
    [httpsC,httpsB]="$defaultFee $defaultCredit USD"
    [httpsC,httpsD]="$defaultFee $defaultCredit USD"
    [httpsD,httpsC]="$defaultFee $defaultCredit USD"
)

echo "Removing existing containers (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Building base image..."
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --network=eioud-network --name $container -e QUICKSTART=$container eioud
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
docker exec httpsA eiou send ${containerAddresses[httpsB]} 100 USD
docker exec httpsA eiou send ${containerAddresses[httpsC]} 100 USD
docker exec httpsA eiou send ${containerAddresses[httpsD]} 100 USD # first complicated path

echo -e "\nSleeping for 5 seconds for proper transaction completion..."
sleep 5

echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec httpsA eiou viewcontact ${containerAddresses[httpsB]}
docker exec httpsB eiou viewcontact ${containerAddresses[httpsA]}
docker exec httpsB eiou viewcontact ${containerAddresses[httpsC]}
docker exec httpsC eiou viewcontact ${containerAddresses[httpsB]}
docker exec httpsC eiou viewcontact ${containerAddresses[httpsD]}
docker exec httpsD eiou viewcontact ${containerAddresses[httpsC]}

# View balances
echo -e "\nViewing balances..."
docker exec httpsA eiou viewbalances
docker exec httpsB eiou viewbalances
docker exec httpsC eiou viewbalances
docker exec httpsD eiou viewbalances

# View transaction history
echo -e "\nViewing transaction history..."
docker exec httpsA eiou history
docker exec httpsB eiou history
docker exec httpsC eiou history
docker exec httpsD eiou history

echo -e "\nScript completed successfully."
