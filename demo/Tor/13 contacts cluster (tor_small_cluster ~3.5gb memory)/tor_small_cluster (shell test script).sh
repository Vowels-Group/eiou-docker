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
    fi
}

declare -A containerAddresses

declare -a containers=(
    "eioud-A-tor"
    "eioud-A1-tor"
    "eioud-A11-tor"
    "eioud-A12-tor"
    "eioud-A2-tor"
    "eioud-A21-tor"
    "eioud-A22-tor"
    "eioud-A3-tor"
    "eioud-A31-tor"
    "eioud-A32-tor"
    "eioud-A4-tor"
    "eioud-A41-tor"
    "eioud-A42-tor")

echo "Removing existing containers (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Building base image..."
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --network=eioud-network --name $container eioud
done


# Function to wait for a container to be ready
wait_for_container() {
    local container_name=$1
    local max_attempts=10
    local attempt=0

    echo "Waiting for $container_name to be ready..."
    while ! docker exec "$container_name" eiou generate torAddressOnly >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "Error: $container_name did not start in time."
            exit 1
        fi
        sleep 1
    done
}

for container in "${containers[@]}"; do
    wait_for_container $container
done

echo -e "\nGetting Tor addresses..."
for container in "${containers[@]}"; do
    containerAddresses[$container]=$(docker exec $container eiou generate torAddressOnly | tr -d '\n')
done

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Add friends
# (NOTE that names are NOT arbitrary)
echo -e "\nAdding friends (This might take a moment):"
echo -e "\t-> eioud-A-tor befriends eioud-A1-tor, eioud-A1-tor befriends eioud-A-tor"
docker exec eioud-A-tor eiou add "${containerAddresses[eioud-A1-tor]}" eioud-A1-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A1-tor eiou add "${containerAddresses[eioud-A-tor]}" eioud-A-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A-tor befriends eioud-A2-tor, eioud-A2-tor befriends eioud-A-tor"
docker exec eioud-A-tor eiou add "${containerAddresses[eioud-A2-tor]}" eioud-A2-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A2-tor eiou add "${containerAddresses[eioud-A-tor]}" eioud-A-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A-tor befriends eioud-A3-tor, eioud-A3-tor befriends eioud-A-tor"
docker exec eioud-A-tor eiou add "${containerAddresses[eioud-A3-tor]}" eioud-A3-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A3-tor eiou add "${containerAddresses[eioud-A-tor]}" eioud-A-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A-tor befriends eioud-A4-tor, eioud-A4-tor befriends eioud-A-tor"
docker exec eioud-A-tor eiou add "${containerAddresses[eioud-A4-tor]}" eioud-A4-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A4-tor eiou add "${containerAddresses[eioud-A-tor]}" eioud-A-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A1-tor befriends eioud-A11-tor, eioud-A11-tor befriends eioud-A1-tor"
docker exec eioud-A1-tor eiou add "${containerAddresses[eioud-A11-tor]}" eioud-A11-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A11-tor eiou add "${containerAddresses[eioud-A1-tor]}" eioud-A1-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A1-tor befriends eioud-A12-tor, eioud-A12-tor befriends eioud-A1-tor"
docker exec eioud-A1-tor eiou add "${containerAddresses[eioud-A12-tor]}" eioud-A12-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A12-tor eiou add "${containerAddresses[eioud-A1-tor]}" eioud-A1-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A2-tor befriends eioud-A21-tor, eioud-A21-tor befriends eioud-A2-tor"
docker exec eioud-A2-tor eiou add "${containerAddresses[eioud-A21-tor]}" eioud-A21-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A21-tor eiou add "${containerAddresses[eioud-A2-tor]}" eioud-A2-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A2-tor befriends eioud-A22-tor, eioud-A22-tor befriends eioud-A2-tor"
docker exec eioud-A2-tor eiou add "${containerAddresses[eioud-A22-tor]}" eioud-A22-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A22-tor eiou add "${containerAddresses[eioud-A2-tor]}" eioud-A2-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A3-tor befriends eioud-A31-tor, eioud-A31-tor befriends eioud-A3-tor"
docker exec eioud-A3-tor eiou add "${containerAddresses[eioud-A31-tor]}" eioud-A31-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A31-tor eiou add "${containerAddresses[eioud-A3-tor]}" eioud-A3-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A3-tor befriends eioud-A32-tor, eioud-A32-tor befriends eioud-A3-tor"
docker exec eioud-A3-tor eiou add "${containerAddresses[eioud-A32-tor]}" eioud-A32-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A32-tor eiou add "${containerAddresses[eioud-A3-tor]}" eioud-A3-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A4-tor befriends eioud-A41-tor, eioud-A41-tor befriends eioud-A4-tor"
docker exec eioud-A4-tor eiou add "${containerAddresses[eioud-A41-tor]}" eioud-A41-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A41-tor eiou add "${containerAddresses[eioud-A4-tor]}" eioud-A4-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-A4-tor befriends eioud-A42-tor, eioud-A42-tor befriends eioud-A4-tor"
docker exec eioud-A4-tor eiou add "${containerAddresses[eioud-A42-tor]}" eioud-A42-tor-name $defaultFee $defaultCredit USD
docker exec eioud-A42-tor eiou add "${containerAddresses[eioud-A4-tor]}" eioud-A4-tor-name $defaultFee $defaultCredit USD



# Send money
echo -e "\nSending money..."
docker exec eioud-A-tor eiou send ${containerAddresses[eioud-A42-tor]} 100 USD
docker exec eioud-A-tor eiou send ${containerAddresses[eioud-A31-tor]} 100 USD
docker exec eioud-A-tor eiou send ${containerAddresses[eioud-A2-tor]} 100 USD


echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec eioud-A-tor eiou viewcontact ${containerAddresses[eioud-A4-tor]}

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances/transaction history)
echo -e "\nSleeping for 5 seconds..."
sleep 5 

# View balances
echo -e "\nViewing balances..."
docker exec eioud-A-tor eiou viewbalances
docker exec eioud-A4-tor eiou viewbalances
docker exec eioud-A42-tor eiou viewbalances
docker exec eioud-A3-tor eiou viewbalances
docker exec eioud-A31-tor eiou viewbalances
docker exec eioud-A2-tor eiou viewbalances


# View transaction history
echo -e "\nViewing transaction history..."
docker exec eioud-A-tor eiou history
docker exec eioud-A4-tor eiou history
docker exec eioud-A42-tor eiou history
docker exec eioud-A3-tor eiou history
docker exec eioud-A31-tor eiou history
docker exec eioud-A2-tor eiou history

echo -e "\nScript completed successfully."
