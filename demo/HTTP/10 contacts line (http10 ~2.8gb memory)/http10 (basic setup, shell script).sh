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
    "eioud-0-http" 
    "eioud-1-http" 
    "eioud-2-http" 
    "eioud-3-http"
    "eioud-4-http"
    "eioud-5-http"
    "eioud-6-http"
    "eioud-7-http"
    "eioud-8-http"
    "eioud-9-http")

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
docker exec eioud-0-http eiou add http://eioud-1-http eioud-1-http $defaultFee $defaultCredit USD
docker exec eioud-1-http eiou add http://eioud-0-http eioud-0-http $defaultFee $defaultCredit USD
docker exec eioud-1-http eiou add http://eioud-2-http eioud-2-http $defaultFee $defaultCredit USD
docker exec eioud-2-http eiou add http://eioud-1-http eioud-1-http $defaultFee $defaultCredit USD
docker exec eioud-2-http eiou add http://eioud-3-http eioud-3-http $defaultFee $defaultCredit USD
docker exec eioud-3-http eiou add http://eioud-2-http eioud-2-http $defaultFee $defaultCredit USD
docker exec eioud-3-http eiou add http://eioud-4-http eioud-4-http $defaultFee $defaultCredit USD
docker exec eioud-4-http eiou add http://eioud-3-http eioud-3-http $defaultFee $defaultCredit USD
docker exec eioud-4-http eiou add http://eioud-5-http eioud-5-http $defaultFee $defaultCredit USD
docker exec eioud-5-http eiou add http://eioud-4-http eioud-4-http $defaultFee $defaultCredit USD
docker exec eioud-5-http eiou add http://eioud-6-http eioud-6-http $defaultFee $defaultCredit USD
docker exec eioud-6-http eiou add http://eioud-5-http eioud-5-http $defaultFee $defaultCredit USD
docker exec eioud-6-http eiou add http://eioud-7-http eioud-7-http $defaultFee $defaultCredit USD
docker exec eioud-7-http eiou add http://eioud-6-http eioud-6-http $defaultFee $defaultCredit USD
docker exec eioud-7-http eiou add http://eioud-8-http eioud-8-http $defaultFee $defaultCredit USD
docker exec eioud-8-http eiou add http://eioud-7-http eioud-7-http $defaultFee $defaultCredit USD
docker exec eioud-8-http eiou add http://eioud-9-http eioud-9-http $defaultFee $defaultCredit USD
docker exec eioud-9-http eiou add http://eioud-8-http eioud-8-http $defaultFee $defaultCredit USD

echo -e "\nBasic setup completed successfully."
