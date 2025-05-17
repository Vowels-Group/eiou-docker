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

echo "Removing existing containers (if any)..."
remove_container_if_exists eioud-0-http
remove_container_if_exists eioud-1-http
remove_container_if_exists eioud-2-http
remove_container_if_exists eioud-3-http
remove_container_if_exists eioud-4-http
remove_container_if_exists eioud-5-http
remove_container_if_exists eioud-6-http
remove_container_if_exists eioud-7-http
remove_container_if_exists eioud-8-http
remove_container_if_exists eioud-9-http

echo "Building base image..."
docker build -f eioud.dockerfile -t eioud .

echo "Creating containers..."
docker run -d --network=eioud-network --name eioud-0-http eioud
docker run -d --network=eioud-network --name eioud-1-http eioud
docker run -d --network=eioud-network --name eioud-2-http eioud
docker run -d --network=eioud-network --name eioud-3-http eioud
docker run -d --network=eioud-network --name eioud-4-http eioud
docker run -d --network=eioud-network --name eioud-5-http eioud
docker run -d --network=eioud-network --name eioud-6-http eioud
docker run -d --network=eioud-network --name eioud-7-http eioud
docker run -d --network=eioud-network --name eioud-8-http eioud
docker run -d --network=eioud-network --name eioud-9-http eioud

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

# wait_for_container eioud-0-http
# wait_for_container eioud-1-http
# wait_for_container eioud-2-http
# wait_for_container eioud-3-http
# wait_for_container eioud-4-http
# wait_for_container eioud-5-http
# wait_for_container eioud-6-http
# wait_for_container eioud-7-http
# wait_for_container eioud-8-http
# wait_for_container eioud-9-http

echo -e "\nGenerate pubkeys and set hostnames..."
docker exec eioud-0-http eiou generate http://eioud-0-http
docker exec eioud-1-http eiou generate http://eioud-1-http
docker exec eioud-2-http eiou generate http://eioud-2-http
docker exec eioud-3-http eiou generate http://eioud-3-http
docker exec eioud-4-http eiou generate http://eioud-4-http
docker exec eioud-5-http eiou generate http://eioud-5-http
docker exec eioud-6-http eiou generate http://eioud-6-http
docker exec eioud-7-http eiou generate http://eioud-7-http
docker exec eioud-8-http eiou generate http://eioud-8-http
docker exec eioud-9-http eiou generate http://eioud-9-http

# Add friends
# (NOTE that names are NOT arbitrary)

echo -e "\nAdding friends..."
docker exec eioud-0-http eiou add http://eioud-1-http eioud-1-http 0.1 1000 USD
docker exec eioud-1-http eiou add http://eioud-0-http eioud-0-http 0.1 1000 USD
docker exec eioud-1-http eiou add http://eioud-2-http eioud-2-http 0.1 1000 USD
docker exec eioud-2-http eiou add http://eioud-1-http eioud-1-http 0.1 1000 USD
docker exec eioud-2-http eiou add http://eioud-3-http eioud-3-http 0.1 1000 USD
docker exec eioud-3-http eiou add http://eioud-2-http eioud-2-http 0.1 1000 USD
docker exec eioud-3-http eiou add http://eioud-4-http eioud-4-http 0.1 1000 USD
docker exec eioud-4-http eiou add http://eioud-3-http eioud-3-http 0.1 1000 USD
docker exec eioud-4-http eiou add http://eioud-5-http eioud-5-http 0.1 1000 USD
docker exec eioud-5-http eiou add http://eioud-4-http eioud-4-http 0.1 1000 USD
docker exec eioud-5-http eiou add http://eioud-6-http eioud-6-http 0.1 1000 USD
docker exec eioud-6-http eiou add http://eioud-5-http eioud-5-http 0.1 1000 USD
docker exec eioud-6-http eiou add http://eioud-7-http eioud-7-http 0.1 1000 USD
docker exec eioud-7-http eiou add http://eioud-6-http eioud-6-http 0.1 1000 USD
docker exec eioud-7-http eiou add http://eioud-8-http eioud-8-http 0.1 1000 USD
docker exec eioud-8-http eiou add http://eioud-7-http eioud-7-http 0.1 1000 USD
docker exec eioud-8-http eiou add http://eioud-9-http eioud-9-http 0.1 1000 USD
docker exec eioud-9-http eiou add http://eioud-8-http eioud-8-http 0.1 1000 USD


echo -e "\nSending money..."
docker exec eioud-0-http eiou send http://eioud-1-http 100 USD
docker exec eioud-0-http eiou send http://eioud-2-http 100 USD
docker exec eioud-0-http eiou send http://eioud-3-http 100 USD # first complicated path
docker exec eioud-0-http eiou send http://eioud-4-http 100 USD
docker exec eioud-0-http eiou send http://eioud-5-http 100 USD
docker exec eioud-0-http eiou send http://eioud-6-http 100 USD
docker exec eioud-0-http eiou send http://eioud-7-http 100 USD # payment should fail - too far


echo -e "\nChecking errors..."
docker exec eioud-0-http cat /var/log/php_errors.log

echo -e "\nVerifying state..."
docker exec -it eioud-0-http mysql -u root -e "SELECT hash, time, currency, amount, sender_address FROM eiou.rp2p;"

docker exec -it eioud-0-http /usr/bin/php /etc/eiou/messages.php

echo -e "\nScript completed successfully."
