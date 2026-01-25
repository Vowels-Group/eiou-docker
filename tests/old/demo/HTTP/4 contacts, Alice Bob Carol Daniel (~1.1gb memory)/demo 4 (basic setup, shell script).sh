#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

set -e # Stop script on failure

# Check if network exists and create it if necessary
if docker network inspect eiou-network >/dev/null 2>&1; then
    echo "Network already exists."
else
    echo "Creating network..."
    docker network create --driver bridge eiou-network
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
    "Alice" 
    "Bob" 
    "Carol" 
    "Daniel")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [Alice,Bob] defines Bob as a contact of Alice
#          must be accepted in reverse that is to say: 
#          [Alice,Bob] needs to be followed by [Bob,Alice]
declare -A containersLinks=(
    [Alice,Bob]="$defaultFee $defaultCredit USD"
    [Bob,Alice]="$defaultFee $defaultCredit USD"
    [Bob,Carol]="$defaultFee $defaultCredit USD"
    [Carol,Bob]="$defaultFee $defaultCredit USD"
    [Carol,Daniel]="$defaultFee $defaultCredit USD"
    [Daniel,Carol]="$defaultFee $defaultCredit USD"
)

echo "Removing existing containers (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Building base image..."
docker build -f eiou.dockerfile -t eiou/eiou .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-index:/var/www/html" -v "${container}-eiou:/usr/local/bin/" -e QUICKSTART=$container eiou/eiou
done

# Save container Addresses in the associative array containerAddresses
#       containerAddresses[containerName] = containerAddress (HTTP)
echo -e "\nSaving hostnames..."
for container in "${containers[@]}"; do
    containerAddresses[$container]="http://"$container
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

echo -e "\nBasic setup completed successfully."
