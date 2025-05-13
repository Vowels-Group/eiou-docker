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
remove_container_if_exists eioud-A-http
remove_container_if_exists eioud-A1-http
remove_container_if_exists eioud-A11-http
remove_container_if_exists eioud-A12-http
remove_container_if_exists eioud-A2-http
remove_container_if_exists eioud-A21-http
remove_container_if_exists eioud-A22-http
remove_container_if_exists eioud-A3-http
remove_container_if_exists eioud-A31-http
remove_container_if_exists eioud-A32-http
remove_container_if_exists eioud-A4-http
remove_container_if_exists eioud-A41-http
remove_container_if_exists eioud-A42-http


echo "Building base image..."
docker build -f eioud.dockerfile -t eioud .

echo "Creating containers..."
docker run -d --network=eioud-network --name eioud-A-http eioud
docker run -d --network=eioud-network --name eioud-A1-http eioud
docker run -d --network=eioud-network --name eioud-A11-http eioud
docker run -d --network=eioud-network --name eioud-A12-http eioud
docker run -d --network=eioud-network --name eioud-A2-http eioud
docker run -d --network=eioud-network --name eioud-A21-http eioud
docker run -d --network=eioud-network --name eioud-A22-http eioud
docker run -d --network=eioud-network --name eioud-A3-http eioud
docker run -d --network=eioud-network --name eioud-A31-http eioud
docker run -d --network=eioud-network --name eioud-A32-http eioud
docker run -d --network=eioud-network --name eioud-A4-http eioud
docker run -d --network=eioud-network --name eioud-A41-http eioud
docker run -d --network=eioud-network --name eioud-A42-http eioud


# Function to wait for a container to be ready
# wait_for_container() {
#     local container_name=$1
#     local max_attempts=10
#     local attempt=0

#     echo "Waiting for $container_name to be ready..."
#     while ! docker exec "$container_name" eiou generate torAddressOnly >/dev/null 2>&1; do
#         attempt=$((attempt + 1))
#         if [ "$attempt" -ge "$max_attempts" ]; then
#             echo "Error: $container_name did not start in time."
#             exit 1
#         fi
#         sleep 1
#     done
# }


echo -e "\nGenerate pubkeys and set hostnames..."
docker exec eioud-A-http eiou generate http://eioud-A-http
docker exec eioud-A1-http eiou generate http://eioud-A1-http
docker exec eioud-A11-http eiou generate http://eioud-A11-http
docker exec eioud-A12-http eiou generate http://eioud-A12-http
docker exec eioud-A2-http eiou generate http://eioud-A2-http
docker exec eioud-A21-http eiou generate http://eioud-A21-http
docker exec eioud-A22-http eiou generate http://eioud-A22-http
docker exec eioud-A3-http eiou generate http://eioud-A3-http
docker exec eioud-A31-http eiou generate http://eioud-A31-http
docker exec eioud-A32-http eiou generate http://eioud-A32-http
docker exec eioud-A4-http eiou generate http://eioud-A4-http
docker exec eioud-A41-http eiou generate http://eioud-A41-http
docker exec eioud-A42-http eiou generate http://eioud-A42-http


# Add friends
# (NOTE that names are NOT arbitrary)

echo -e "\nAdding friends..."
docker exec eioud-A-http eiou add http://eioud-A1-http eioud-A1-http 0.1 1000 USD
docker exec eioud-A1-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A2-http eioud-A2-http 0.1 1000 USD
docker exec eioud-A2-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A3-http eioud-A3-http 0.1 1000 USD
docker exec eioud-A3-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A4-http eioud-A4-http 0.1 1000 USD
docker exec eioud-A4-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A1-http eiou add http://eioud-A11-http eioud-A11-http 0.1 1000 USD
docker exec eioud-A11-http eiou add http://eioud-A1-http eioud-A1-http 0.1 1000 USD
docker exec eioud-A1-http eiou add http://eioud-A12-http eioud-A12-http 0.1 1000 USD
docker exec eioud-A12-http eiou add http://eioud-A1-http eioud-A1-http 0.1 1000 USD
docker exec eioud-A2-http eiou add http://eioud-A21-http eioud-A21-http 0.1 1000 USD
docker exec eioud-A21-http eiou add http://eioud-A2-http eioud-A2-http 0.1 1000 USD
docker exec eioud-A2-http eiou add http://eioud-A22-http eioud-A22-http 0.1 1000 USD
docker exec eioud-A22-http eiou add http://eioud-A2-http eioud-A2-http 0.1 1000 USD
docker exec eioud-A3-http eiou add http://eioud-A31-http eioud-A31-http 0.1 1000 USD
docker exec eioud-A31-http eiou add http://eioud-A3-http eioud-A3-http 0.1 1000 USD
docker exec eioud-A3-http eiou add http://eioud-A32-http eioud-A32-http 0.1 1000 USD
docker exec eioud-A32-http eiou add http://eioud-A3-http eioud-A3-http 0.1 1000 USD
docker exec eioud-A4-http eiou add http://eioud-A41-http eioud-A41-http 0.1 1000 USD
docker exec eioud-A41-http eiou add http://eioud-A4-http eioud-A4-http 0.1 1000 USD
docker exec eioud-A4-http eiou add http://eioud-A42-http eioud-A42-http 0.1 1000 USD
docker exec eioud-A42-http eiou add http://eioud-A4-http eioud-A4-http 0.1 1000 USD



# Send money
echo -e "\nSending money..."
docker exec eioud-A-http eiou send http://eioud-A42-http 100 USD
docker exec eioud-A-http eiou send http://eioud-A31-http 100 USD
docker exec eioud-A-http eiou send http://eioud-A2-http 100 USD


echo -e "\nTesting other functions..."

# Read contacts
echo -e "\nReading contacts..."
docker exec eioud-A-http eiou read http://eioud-A4-http

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances/transaction history)
echo -e "\nSleeping for 5 seconds..."
sleep 5 

# View balances
echo -e "\nViewing balances..."
docker exec eioud-A-http eiou view
docker exec eioud-A4-http eiou view
docker exec eioud-A42-http eiou view
docker exec eioud-A3-http eiou view
docker exec eioud-A31-http eiou view
docker exec eioud-A2-http eiou view


# View transaction history
echo -e "\nViewing transaction history..."
docker exec eioud-A-http eiou history
docker exec eioud-A4-http eiou history
docker exec eioud-A42-http eiou history
docker exec eioud-A3-http eiou history
docker exec eioud-A31-http eiou history
docker exec eioud-A2-http eiou history

echo -e "\nScript completed successfully."
