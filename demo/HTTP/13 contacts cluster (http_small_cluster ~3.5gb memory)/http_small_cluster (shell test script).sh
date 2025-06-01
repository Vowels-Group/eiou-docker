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

declare -a containers=(
    "eioud-A-http" 
    "eioud-A1-http" 
    "eioud-A11-http" 
    "eioud-A12-http"
    "eioud-A2-http"
    "eioud-A21-http"
    "eioud-A22-http"
    "eioud-A3-http"
    "eioud-A31-http"
    "eioud-A32-http"
    "eioud-A4-http"
    "eioud-A41-http"
    "eioud-A42-http")

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

# for container in "${containers[@]}"; do
#     wait_for_container $container
# done

echo -e "\nGenerate pubkeys and set hostnames..."
for container in "${containers[@]}"; do
    docker exec $container eiou generate "http://"$container
done

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Add friends
# (NOTE that names are NOT arbitrary)

echo -e "\nAdding friends..."
docker exec eioud-A-http eiou add http://eioud-A1-http eioud-A1-http $defaultFee $defaultCredit USD
docker exec eioud-A1-http eiou add http://eioud-A-http eioud-A-http $defaultFee $defaultCredit USD
docker exec eioud-A-http eiou add http://eioud-A2-http eioud-A2-http $defaultFee $defaultCredit USD
docker exec eioud-A2-http eiou add http://eioud-A-http eioud-A-http $defaultFee $defaultCredit USD
docker exec eioud-A-http eiou add http://eioud-A3-http eioud-A3-http $defaultFee $defaultCredit USD
docker exec eioud-A3-http eiou add http://eioud-A-http eioud-A-http $defaultFee $defaultCredit USD
docker exec eioud-A-http eiou add http://eioud-A4-http eioud-A4-http $defaultFee $defaultCredit USD
docker exec eioud-A4-http eiou add http://eioud-A-http eioud-A-http $defaultFee $defaultCredit USD
docker exec eioud-A1-http eiou add http://eioud-A11-http eioud-A11-http $defaultFee $defaultCredit USD
docker exec eioud-A11-http eiou add http://eioud-A1-http eioud-A1-http $defaultFee $defaultCredit USD
docker exec eioud-A1-http eiou add http://eioud-A12-http eioud-A12-http $defaultFee $defaultCredit USD
docker exec eioud-A12-http eiou add http://eioud-A1-http eioud-A1-http $defaultFee $defaultCredit USD
docker exec eioud-A2-http eiou add http://eioud-A21-http eioud-A21-http $defaultFee $defaultCredit USD
docker exec eioud-A21-http eiou add http://eioud-A2-http eioud-A2-http $defaultFee $defaultCredit USD
docker exec eioud-A2-http eiou add http://eioud-A22-http eioud-A22-http $defaultFee $defaultCredit USD
docker exec eioud-A22-http eiou add http://eioud-A2-http eioud-A2-http $defaultFee $defaultCredit USD
docker exec eioud-A3-http eiou add http://eioud-A31-http eioud-A31-http $defaultFee $defaultCredit USD
docker exec eioud-A31-http eiou add http://eioud-A3-http eioud-A3-http $defaultFee $defaultCredit USD
docker exec eioud-A3-http eiou add http://eioud-A32-http eioud-A32-http $defaultFee $defaultCredit USD
docker exec eioud-A32-http eiou add http://eioud-A3-http eioud-A3-http $defaultFee $defaultCredit USD
docker exec eioud-A4-http eiou add http://eioud-A41-http eioud-A41-http $defaultFee $defaultCredit USD
docker exec eioud-A41-http eiou add http://eioud-A4-http eioud-A4-http $defaultFee $defaultCredit USD
docker exec eioud-A4-http eiou add http://eioud-A42-http eioud-A42-http $defaultFee $defaultCredit USD
docker exec eioud-A42-http eiou add http://eioud-A4-http eioud-A4-http $defaultFee $defaultCredit USD



# Send money
echo -e "\nSending money..."
docker exec eioud-A-http eiou send http://eioud-A42-http 100 USD
docker exec eioud-A-http eiou send http://eioud-A31-http 100 USD
docker exec eioud-A-http eiou send http://eioud-A2-http 100 USD


echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec eioud-A-http eiou viewcontact http://eioud-A4-http

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances/transaction history)
echo -e "\nSleeping for 5 seconds..."
sleep 5 

# View balances
echo -e "\nViewing balances..."
docker exec eioud-A-http eiou viewbalances
docker exec eioud-A4-http eiou viewbalances
docker exec eioud-A42-http eiou viewbalances
docker exec eioud-A3-http eiou viewbalances
docker exec eioud-A31-http eiou viewbalances
docker exec eioud-A2-http eiou viewbalances


# View transaction history
echo -e "\nViewing transaction history..."
docker exec eioud-A-http eiou history
docker exec eioud-A4-http eiou history
docker exec eioud-A42-http eiou history
docker exec eioud-A3-http eiou history
docker exec eioud-A31-http eiou history
docker exec eioud-A2-http eiou history

echo -e "\nScript completed successfully."
