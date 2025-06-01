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
    "http0" 
    "http1" 
    "http2" 
    "http3")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [http0,http1] defines http1 as a contact of http0
#          must be accepted in reverse that is to say: 
#          [http0,http1] needs to be followed by [http1,http0]
declare -A containersLinks=(
    [http0,http1]="$defaultFee $defaultCredit USD"
    [http1,http0]="$defaultFee $defaultCredit USD"
    [http1,http2]="$defaultFee $defaultCredit USD"
    [http2,http1]="$defaultFee $defaultCredit USD"
    [http2,http3]="$defaultFee $defaultCredit USD"
    [http3,http2]="$defaultFee $defaultCredit USD"
)

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

# Save container Addresses in the associative array containerAddresses
#       containerAddresses[containerName] = containerAddress (HTTP)
echo -e "\nGenerate pubkeys and set hostnames..."
for container in "${containers[@]}"; do
    containerAddress="http://"$container
    docker exec $container eiou generate $containerAddress
    containerAddresses[$container]=$containerAddress
done

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
