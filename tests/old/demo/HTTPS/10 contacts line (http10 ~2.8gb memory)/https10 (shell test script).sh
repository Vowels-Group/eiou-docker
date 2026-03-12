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
    "httpsA" 
    "httpsB" 
    "httpsC" 
    "httpsD"
    "httpsE"
    "httpsF"
    "httpsG"
    "httpsH"
    "httpsI"
    "httpsJ")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [httpsA,httpsB] defines httpsB as a contact of httpsA
#          must be accepted in reverse that is to say: 
#          [httpsA,httpsB] needs to be followed by [httpsB,httpsA]
declare -A containersLinks=(
    [httpsA,httpsB]="$defaultFee $defaultCredit USD"
    [httpsB,httpsA]="$defaultFee $defaultCredit USD"
    [httpsB,httpsC]="$defaultFee $defaultCredit USD"
    [httpsC,httpsB]="$defaultFee $defaultCredit USD"
    [httpsC,httpsD]="$defaultFee $defaultCredit USD"
    [httpsD,httpsC]="$defaultFee $defaultCredit USD"
    [httpsD,httpsE]="$defaultFee $defaultCredit USD"
    [httpsE,httpsD]="$defaultFee $defaultCredit USD"
    [httpsE,httpsF]="$defaultFee $defaultCredit USD"
    [httpsF,httpsE]="$defaultFee $defaultCredit USD"
    [httpsF,httpsG]="$defaultFee $defaultCredit USD"
    [httpsG,httpsF]="$defaultFee $defaultCredit USD"
    [httpsG,httpsH]="$defaultFee $defaultCredit USD"
    [httpsH,httpsG]="$defaultFee $defaultCredit USD"
    [httpsH,httpsI]="$defaultFee $defaultCredit USD"
    [httpsI,httpsH]="$defaultFee $defaultCredit USD"
    [httpsI,httpsJ]="$defaultFee $defaultCredit USD"
    [httpsJ,httpsI]="$defaultFee $defaultCredit USD"
)

echo "Removing existing containers (if any)..."
for container in "${containers[@]}"; do
    remove_container_if_exists $container
done

echo "Removing associated volumes (if any)..."
for container in "${containers[@]}"; do
    remove_volume_if_exists "${container}-mysql-data"
    remove_volume_if_exists "${container}-config"
    remove_volume_if_exists "${container}-letsencrypt"
    remove_volume_if_exists "${container}-backups"
done

echo "Building base image..."
docker build -f eiou.dockerfile -t eiou/eiou .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --restart unless-stopped --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-config:/etc/eiou/config" -v "${container}-backups:/var/lib/eiou/backups" -v "${container}-letsencrypt:/etc/letsencrypt" -e QUICKSTART=$container eiou/eiou
done

# Save container Addresses in the associative array containerAddresses
#       containerAddresses[containerName] = containerAddress (https)
echo -e "\nSaving hostnames..."
for container in "${containers[@]}"; do
    containerAddresses[$container]="https://"$container
done

echo -e "\nSleeping for 5 seconds for proper startup of all containers..."
sleep 15

# Add friends
echo -e "\nAdding friends..."
containersLinkKeys=($(for x in ${!containersLinks[@]}; do echo $x; done | sort))
for containersLinkKey in "${containersLinkKeys[@]}"; do
    values=${containersLinks[${containersLinkKey}]}
    containerKeys=(${containersLinkKey//,/ })    
    echo -e "\n\t-> Adding ${containerKeys[0]} To ${containerKeys[1]} as a contact: "
    docker exec ${containerKeys[0]} eiou add ${containerAddresses[${containerKeys[1]}]} ${containerKeys[1]} ${values[0]} ${values[1]} ${values[2]}
done

echo -e "\nSending money..."
docker exec httpsA eiou send ${containerAddresses[httpsB]} 100 USD
docker exec httpsA eiou send ${containerAddresses[httpsC]} 100 USD
docker exec httpsA eiou send ${containerAddresses[httpsD]} 100 USD # first complicated path
docker exec httpsA eiou send ${containerAddresses[httpsE]} 100 USD
docker exec httpsA eiou send ${containerAddresses[httpsF]} 100 USD
docker exec httpsA eiou send ${containerAddresses[httpsG]} 100 USD
docker exec httpsA eiou send ${containerAddresses[httpsH]} 100 USD # payment should fail - too far

echo -e "\nSleeping for 5 seconds for proper transaction completion..."
sleep 15

echo -e "\nChecking errors..."
docker exec httpsA cat /var/log/php_errors.log

echo -e "\nVerifying state..."
docker exec -it httpsA mysql -u root -e "SELECT hash, time, currency, amount, sender_address FROM eiou.rp2p;"

docker exec -it httpsA /usr/bin/php /app/eiou/processors/P2pMessages.php

echo -e "\nScript completed successfully."
