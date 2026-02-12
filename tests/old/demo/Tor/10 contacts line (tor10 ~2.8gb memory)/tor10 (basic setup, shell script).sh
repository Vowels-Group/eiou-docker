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
        echo "Removing existing container: $container_name..."
        docker rm -f "$container_name"
    fi
}

# Function to remove a volume if it exists
remove_volume_if_exists() {
    local volume_name=$1
    if docker volume ls --format '{{.Name}}' | grep -q "^$volume_name$"; then
        echo -e "\tRemoving existing volume: $volume_name..."
        docker volume rm "$volume_name"
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

echo "Removing associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_volume_if_exists "${container}-mysql-data"
    remove_volume_if_exists "${container}-files"
    remove_volume_if_exists "${container}-index"
    remove_volume_if_exists "${container}-eiou"
    remove_volume_if_exists "${container}-backups"
done

echo "Building base image..."
docker build -f eiou.dockerfile -t eiou/eiou .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --restart unless-stopped --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-backups:/var/lib/eiou/backups" eiou/eiou
done

echo -e "\nWaiting for 5 seconds for proper container startup..."
sleep 5

# Save container Addresses in the associative array containerAddresses
#       containerAddresses[containerName] = containerAddress (Tor)
echo -e "\nGetting Tor addresses..."
for container in "${containers[@]}"; do
    containerAddresses[$container]=$(docker exec $container php -r 'echo json_decode(file_get_contents("/etc/eiou/userconfig.json"),true)["torAddress"];')
done

echo -e "\nWaiting for 15 seconds due to the nature of Tor..."
sleep 15

# Add friends
echo -e "\nAdding friends (this might take a moment)..."
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
for containersLinkKey in "${containersLinkKeys[@]}"; do
    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })    
    echo -e "\t-> Adding ${containerKeys[0]} To ${containerKeys[1]} as a contact: "
    docker exec ${containerKeys[0]} eiou add ${containerAddresses[${containerKeys[1]}]} ${containerKeys[1]} ${values[0]} ${values[1]} ${values[2]}
done

echo -e "\nBasic Setup Script completed successfully."
