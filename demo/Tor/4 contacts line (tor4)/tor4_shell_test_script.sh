#!/bin/sh

set -e # Stop script on failure

# Function to remove a container if it exists
remove_container_if_exists() {
    local container_name=$1
    if docker ps -a --format '{{.Names}}' | grep -q "^$container_name$"; then
        echo "Removing existing container: $container_name..."
        docker rm -f "$container_name"
    fi
}

echo "Removing existing containers (if any)..."
remove_container_if_exists eioud-0-tor
remove_container_if_exists eioud-1-tor
remove_container_if_exists eioud-2-tor
remove_container_if_exists eioud-3-tor

echo "Building base image..."
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
docker run -d --name eioud-0-tor eioud
docker run -d --name eioud-1-tor eioud
docker run -d --name eioud-2-tor eioud
docker run -d --name eioud-3-tor eioud

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

wait_for_container eioud-0-tor
wait_for_container eioud-1-tor
wait_for_container eioud-2-tor
wait_for_container eioud-3-tor

echo -e "\nGetting Tor addresses..."
addr0=$(docker exec eioud-0-tor eiou generate torAddressOnly | tr -d '\n')
addr1=$(docker exec eioud-1-tor eiou generate torAddressOnly | tr -d '\n')
addr2=$(docker exec eioud-2-tor eiou generate torAddressOnly | tr -d '\n')
addr3=$(docker exec eioud-3-tor eiou generate torAddressOnly | tr -d '\n')

# Add friends
# (Note that the names are arbitrary)

echo -e "\nAdding friends (This might take a moment):"
echo -e "\t-> eioud-0-tor befriends eioud-1-tor, eioud-1-tor befriends eioud-0-tor"
docker exec eioud-0-tor eiou add "$addr1" eioud-1-tor-name 1 1000 USD
docker exec eioud-1-tor eiou add "$addr0" eioud-0-tor-name 1 1000 USD

echo -e "\n\t-> eioud-1-tor befriends eioud-2-tor, eioud-2-tor befriends eioud-1-tor"
docker exec eioud-1-tor eiou add "$addr2" eioud-2-tor-name 1 1000 USD
docker exec eioud-2-tor eiou add "$addr1" eioud-1-tor-name 1 1000 USD

echo -e "\n\t-> eioud-2-tor befriends eioud-3-tor, eioud-3-tor befriends eioud-2-tor"
docker exec eioud-2-tor eiou add "$addr3" eioud-3-tor-name 1 1000 USD
docker exec eioud-3-tor eiou add "$addr2" eioud-2-tor-name 1 1000 USD

echo -e "\nSending money:"
echo -e "\t-> eioud-0-tor sends money to eioud-1-tor, directly"
docker exec eioud-0-tor eiou send "$addr1" 1 USD

echo -e "\t-> eioud-0-tor sends money to eioud-2-tor, through eioud-1-tor"
docker exec eioud-0-tor eiou send "$addr2" 1 USD

echo -e "\t-> eioud-0-tor sends money to eioud-3-tor, through eioud-1-tor & eioud-2-tor"
docker exec eioud-0-tor eiou send "$addr3" 1 USD

echo -e "\t-> eioud-0-tor sends money to eioud-1-tor, using name"
docker exec eioud-0-tor eiou send eioud-1-tor-name 1 USD

echo -e "\nScript completed successfully."
