#!/bin/sh
# Copyright 2025 The Vowels Company

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
docker build -f eioud.dockerfile -t eioud .

echo -e "\nCreating containers..."
for container in "${containers[@]}"; do
    docker run -d --network=eioud-network --name $container -e QUICKSTART=$container eioud
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

# Send money
echo -e "\nSending money..."
docker exec Alice eiou send ${containerAddresses[Bob]} 100 USD
docker exec Alice eiou send ${containerAddresses[Carol]} 100 USD
docker exec Alice eiou send ${containerAddresses[Daniel]} 100 USD # first complicated path

echo -e "\nSleeping for 5 seconds for proper startup of all containers..."
sleep 5

echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec Alice eiou viewcontact ${containerAddresses[Bob]}
docker exec Bob eiou viewcontact ${containerAddresses[Alice]}
docker exec Bob eiou viewcontact ${containerAddresses[Carol]}
docker exec Carol eiou viewcontact ${containerAddresses[Bob]}
docker exec Carol eiou viewcontact ${containerAddresses[Daniel]}
docker exec Daniel eiou viewcontact ${containerAddresses[Carol]}

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances/transaction history)
echo -e "\nSleeping for 5 seconds..."
sleep 5 

# View balances
echo -e "\nViewing balances..."
docker exec Alice eiou viewbalances
docker exec Bob eiou viewbalances
docker exec Carol eiou viewbalances
docker exec Daniel eiou viewbalances

# View transaction history
echo -e "\nViewing transaction history..."
docker exec Alice eiou history
docker exec Bob eiou history
docker exec Carol eiou history
docker exec Daniel eiou history

echo -e "\nScript completed successfully."
