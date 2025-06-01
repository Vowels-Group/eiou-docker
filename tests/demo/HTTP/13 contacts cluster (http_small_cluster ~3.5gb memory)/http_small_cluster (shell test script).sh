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
    "A" 
    "A1" 
    "A11" 
    "A12"
    "A2"
    "A21"
    "A22"
    "A3"
    "A31"
    "A32"
    "A4"
    "A41"
    "A42")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [eioud-0-http,eioud-1-http] defines eioud-1-http as a contact of eioud-0-http
#          must be accepted in reverse that is to say: 
#          [eioud-0-http,eioud-1-http] needs to be followed by [eioud-1-http,eioud-0-http]
declare -A containersLinks=(
    [A,A1]="$defaultFee $defaultCredit USD"
    [A1,A]="$defaultFee $defaultCredit USD"
    [A,A2]="$defaultFee $defaultCredit USD"
    [A2,A]="$defaultFee $defaultCredit USD"
    [A,A3]="$defaultFee $defaultCredit USD"
    [A3,A]="$defaultFee $defaultCredit USD"
    [A,A4]="$defaultFee $defaultCredit USD"
    [A4,A]="$defaultFee $defaultCredit USD"
    [A1,A11]="$defaultFee $defaultCredit USD"
    [A11,A1]="$defaultFee $defaultCredit USD"
    [A1,A12]="$defaultFee $defaultCredit USD"
    [A12,A1]="$defaultFee $defaultCredit USD"
    [A2,A21]="$defaultFee $defaultCredit USD"
    [A21,A2]="$defaultFee $defaultCredit USD"
    [A2,A22]="$defaultFee $defaultCredit USD"
    [A22,A2]="$defaultFee $defaultCredit USD"
    [A3,A31]="$defaultFee $defaultCredit USD"
    [A31,A3]="$defaultFee $defaultCredit USD"
    [A3,A32]="$defaultFee $defaultCredit USD"
    [A32,A3]="$defaultFee $defaultCredit USD"
    [A4,A41]="$defaultFee $defaultCredit USD"
    [A41,A4]="$defaultFee $defaultCredit USD"
    [A4,A42]="$defaultFee $defaultCredit USD"
    [A42,A4]="$defaultFee $defaultCredit USD"
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


# Send money
echo -e "\nSending money..."
docker exec eioud-A-http eiou send ${containerAddresses[eioud-A42-http]} 100 USD
docker exec eioud-A-http eiou send ${containerAddresses[eioud-A31-http]} 100 USD
docker exec eioud-A-http eiou send ${containerAddresses[eioud-A2-http]} 100 USD


echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec eioud-A-http eiou viewcontact ${containerAddresses[eioud-A4-http]}

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
