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
    "torA"
    "torB"
    "torC"
    "torD"
    "torE"
    "torF"
    "torG"
    "torH"
    "torI"
    "torJ")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [torA,torB] defines torB as a contact of torA
#          must be accepted in reverse that is to say: 
#          [torA,torB] needs to be followed by [torB,torA]
declare -A containersLinks=(
    [torA,torB]="$defaultFee $defaultCredit USD"
    [torB,torA]="$defaultFee $defaultCredit USD"
    [torB,torC]="$defaultFee $defaultCredit USD"
    [torC,torB]="$defaultFee $defaultCredit USD"
    [torC,torD]="$defaultFee $defaultCredit USD"
    [torD,torC]="$defaultFee $defaultCredit USD"
    [torD,torE]="$defaultFee $defaultCredit USD"
    [torE,torD]="$defaultFee $defaultCredit USD"
    [torE,torF]="$defaultFee $defaultCredit USD"
    [torF,torE]="$defaultFee $defaultCredit USD"
    [torF,torG]="$defaultFee $defaultCredit USD"
    [torG,torF]="$defaultFee $defaultCredit USD"
    [torG,torH]="$defaultFee $defaultCredit USD"
    [torH,torG]="$defaultFee $defaultCredit USD"
    [torH,torI]="$defaultFee $defaultCredit USD"
    [torI,torH]="$defaultFee $defaultCredit USD"
    [torI,torJ]="$defaultFee $defaultCredit USD"
    [torJ,torI]="$defaultFee $defaultCredit USD"
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

# Save container Addresses in the associative array containerAddresses
#       containerAddresses[containerName] = containerAddress (Tor)
echo -e "\nGetting Tor addresses..."
for container in "${containers[@]}"; do
    containerAddresses[$container]=$(docker exec $container eiou generate torAddressOnly | tr -d '\n')
done

# Add friends
echo -e "\nAdding friends (this might take a moment)..."
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
for containersLinkKey in "${containersLinkKeys[@]}"; do
    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })    
    echo -e "\n\t-> Adding ${containerKeys[0]} To ${containerKeys[1]} as a contact: "
    docker exec ${containerKeys[0]} eiou add ${containerAddresses[${containerKeys[1]}]} ${containerKeys[1]} ${values[0]} ${values[1]} ${values[2]}
done

echo -e "\nBasic Setup Script completed successfully."
