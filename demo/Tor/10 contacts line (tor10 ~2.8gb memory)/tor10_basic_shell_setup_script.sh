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
    "eioud-0-tor"
    "eioud-1-tor"
    "eioud-2-tor"
    "eioud-3-tor"
    "eioud-4-tor"
    "eioud-5-tor"
    "eioud-6-tor"
    "eioud-7-tor"
    "eioud-8-tor"
    "eioud-9-tor")

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
# (Note that the names are arbitrary)

echo -e "\nAdding friends (This might take a moment):"
echo -e "\t-> eioud-0-tor befriends eioud-1-tor, eioud-1-tor befriends eioud-0-tor"
docker exec eioud-0-tor eiou add "${containerAddresses[eioud-1-tor]}" eioud-1-tor-name $defaultFee $defaultCredit USD
docker exec eioud-1-tor eiou add "${containerAddresses[eioud-0-tor]}" eioud-0-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-1-tor befriends eioud-2-tor, eioud-2-tor befriends eioud-1-tor"
docker exec eioud-1-tor eiou add "${containerAddresses[eioud-2-tor]}" eioud-2-tor-name $defaultFee $defaultCredit USD
docker exec eioud-2-tor eiou add "${containerAddresses[eioud-1-tor]}" eioud-1-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-2-tor befriends eioud-3-tor, eioud-3-tor befriends eioud-2-tor"
docker exec eioud-2-tor eiou add "${containerAddresses[eioud-3-tor]}" eioud-3-tor-name $defaultFee $defaultCredit USD
docker exec eioud-3-tor eiou add "${containerAddresses[eioud-2-tor]}" eioud-2-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-3-tor befriends eioud-4-tor, eioud-4-tor befriends eioud-3-tor"
docker exec eioud-3-tor eiou add "${containerAddresses[eioud-4-tor]}" eioud-4-tor-name $defaultFee $defaultCredit USD
docker exec eioud-4-tor eiou add "${containerAddresses[eioud-3-tor]}" eioud-3-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-4-tor befriends eioud-5-tor, eioud-5-tor befriends eioud-4-tor"
docker exec eioud-4-tor eiou add "${containerAddresses[eioud-4-tor]}" eioud-4-tor-name $defaultFee $defaultCredit USD
docker exec eioud-5-tor eiou add "${containerAddresses[eioud-5-tor]}" eioud-5-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-5-tor befriends eioud-6-tor, eioud-6-tor befriends eioud-5-tor"
docker exec eioud-5-tor eiou add "${containerAddresses[eioud-6-tor]}" eioud-6-tor-name $defaultFee $defaultCredit USD
docker exec eioud-6-tor eiou add "${containerAddresses[eioud-5-tor]}" eioud-5-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-6-tor befriends eioud-7-tor, eioud-7-tor befriends eioud-6-tor"
docker exec eioud-6-tor eiou add "${containerAddresses[eioud-7-tor]}" eioud-7-tor-name $defaultFee $defaultCredit USD
docker exec eioud-7-tor eiou add "${containerAddresses[eioud-6-tor]}" eioud-6-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-7-tor befriends eioud-8-tor, eioud-8-tor befriends eioud-7-tor"
docker exec eioud-7-tor eiou add "${containerAddresses[eioud-8-tor]}" eioud-8-tor-name $defaultFee $defaultCredit USD
docker exec eioud-8-tor eiou add "${containerAddresses[eioud-7-tor]}" eioud-7-tor-name $defaultFee $defaultCredit USD

echo -e "\n\t-> eioud-8-tor befriends eioud-9-tor, eioud-9-tor befriends eioud-8-tor"
docker exec eioud-8-tor eiou add "${containerAddresses[eioud-9-tor]}" eioud-9-tor-name $defaultFee $defaultCredit USD
docker exec eioud-9-tor eiou add "${containerAddresses[eioud-8-tor]}" eioud-8-tor-name $defaultFee $defaultCredit USD


echo -e "\nBasic Setup Script completed successfully."
