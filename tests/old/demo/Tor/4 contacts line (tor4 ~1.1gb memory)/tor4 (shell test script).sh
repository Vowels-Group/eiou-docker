#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC

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
        echo -e "\tRemoving existing container: $container_name..."
        docker rm -f "$container_name"
    fi
}

declare -A containerAddresses

declare -a containers=(
    "torA"
    "torB"
    "torC"
    "torD")

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

echo -e "\nSending money:"
echo -e "\t-> torA sends money to torB, directly"
docker exec torA eiou send "${containerAddresses[torB]}" 12 USD

echo -e "\t-> torA sends money to torC, through torB"
docker exec torA eiou send "${containerAddresses[torC]}" 43 USD

echo -e "\t-> torA sends money to torD, through torB/C"
docker exec torA eiou send "${containerAddresses[torD]}" 110 USD

echo -e "\t-> torA sends money to torB, using name"
docker exec torA eiou send torB-name 50 USD

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances)
echo -e "\nSleeping for 10 seconds for proper completion of transaction cycles..."
sleep 10

# View balances
echo -e "\nViewing balances..."

echo -e "\nViewing balances of torA"
docker exec torA eiou viewbalances

echo -e "\nViewing balances of torB"
docker exec torB eiou viewbalances


# View transaction history
echo -e "\nViewing transaction history..."

echo -e "\nViewing history of torA"
docker exec torA eiou history

echo -e "\nViewing history of torB"
docker exec torB eiou history

echo -e "\nScript completed successfully."
