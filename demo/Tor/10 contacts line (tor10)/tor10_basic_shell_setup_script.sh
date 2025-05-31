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
remove_container_if_exists eioud-4-tor
remove_container_if_exists eioud-5-tor
remove_container_if_exists eioud-6-tor
remove_container_if_exists eioud-7-tor
remove_container_if_exists eioud-8-tor
remove_container_if_exists eioud-9-tor

echo "Building base image..."
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
docker run -d --name eioud-0-tor eioud
docker run -d --name eioud-1-tor eioud
docker run -d --name eioud-2-tor eioud
docker run -d --name eioud-3-tor eioud
docker run -d --name eioud-4-tor eioud
docker run -d --name eioud-5-tor eioud
docker run -d --name eioud-6-tor eioud
docker run -d --name eioud-7-tor eioud
docker run -d --name eioud-8-tor eioud
docker run -d --name eioud-9-tor eioud

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
wait_for_container eioud-4-tor
wait_for_container eioud-5-tor
wait_for_container eioud-6-tor
wait_for_container eioud-7-tor
wait_for_container eioud-8-tor
wait_for_container eioud-9-tor

echo -e "\nGetting Tor addresses..."
addr0=$(docker exec eioud-0-tor eiou generate torAddressOnly | tr -d '\n')
addr1=$(docker exec eioud-1-tor eiou generate torAddressOnly | tr -d '\n')
addr2=$(docker exec eioud-2-tor eiou generate torAddressOnly | tr -d '\n')
addr3=$(docker exec eioud-3-tor eiou generate torAddressOnly | tr -d '\n')
addr4=$(docker exec eioud-4-tor eiou generate torAddressOnly | tr -d '\n')
addr5=$(docker exec eioud-5-tor eiou generate torAddressOnly | tr -d '\n')
addr6=$(docker exec eioud-6-tor eiou generate torAddressOnly | tr -d '\n')
addr7=$(docker exec eioud-7-tor eiou generate torAddressOnly | tr -d '\n')
addr8=$(docker exec eioud-8-tor eiou generate torAddressOnly | tr -d '\n')
addr9=$(docker exec eioud-9-tor eiou generate torAddressOnly | tr -d '\n')

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

echo -e "\n\t-> eioud-3-tor befriends eioud-4-tor, eioud-4-tor befriends eioud-4-tor"
docker exec eioud-3-tor eiou add "$addr4" eioud-4-tor-name 1 1000 USD
docker exec eioud-4-tor eiou add "$addr3" eioud-3-tor-name 1 1000 USD

echo -e "\n\t-> eioud-4-tor befriends eioud-5-tor, eioud-5-tor befriends eioud-4-tor"
docker exec eioud-4-tor eiou add "$addr5" eioud-4-tor-name 1 1000 USD
docker exec eioud-5-tor eiou add "$addr4" eioud-5-tor-name 1 1000 USD

echo -e "\n\t-> eioud-5-tor befriends eioud-6-tor, eioud-6-tor befriends eioud-5-tor"
docker exec eioud-5-tor eiou add "$addr6" eioud-6-tor-name 1 1000 USD
docker exec eioud-6-tor eiou add "$addr5" eioud-5-tor-name 1 1000 USD

echo -e "\n\t-> eioud-7-tor befriends eioud-8-tor, eioud-8-tor befriends eioud-7-tor"
docker exec eioud-7-tor eiou add "$addr8" eioud-8-tor-name 1 1000 USD
docker exec eioud-8-tor eiou add "$addr7" eioud-7-tor-name 1 1000 USD

echo -e "\n\t-> eioud-8-tor befriends eioud-9-tor, eioud-9-tor befriends eioud-8-tor"
docker exec eioud-8-tor eiou add "$addr9" eioud-9-tor-name 1 1000 USD
docker exec eioud-9-tor eiou add "$addr8" eioud-8-tor-name 1 1000 USD

echo -e "\nBasic Setup Script completed successfully."
