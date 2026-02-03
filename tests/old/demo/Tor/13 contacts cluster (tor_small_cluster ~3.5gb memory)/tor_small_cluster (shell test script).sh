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
    "torA1"
    "torA11"
    "torA12"
    "torA2"
    "torA21"
    "torA22"
    "torA3"
    "torA31"
    "torA32"
    "torA4"
    "torA41"
    "torA42")

# Setup of simple fees and credit, easy edit for every person
readonly defaultFee=0.1
readonly defaultCredit=1000

# Define contacts, direction ->
# example: [eiou-0-tor,eiou-1-tor] defines eiou-1-tor as a contact of eiou-0-tor
#          must be accepted in reverse that is to say: 
#          [eiou-0-tor,eiou-1-tor] needs to be followed by [eiou-1-tor,eiou-0-tor]
declare -A containersLinks=(
    [torA,torA1]="$defaultFee $defaultCredit USD"
    [torA1,torA]="$defaultFee $defaultCredit USD"
    [torA,torA2]="$defaultFee $defaultCredit USD"
    [torA2,torA]="$defaultFee $defaultCredit USD"
    [torA,torA3]="$defaultFee $defaultCredit USD"
    [torA3,torA]="$defaultFee $defaultCredit USD"
    [torA,torA4]="$defaultFee $defaultCredit USD"
    [torA4,torA]="$defaultFee $defaultCredit USD"
    [torA1,torA11]="$defaultFee $defaultCredit USD"
    [torA11,torA1]="$defaultFee $defaultCredit USD"
    [torA1,torA12]="$defaultFee $defaultCredit USD"
    [torA12,torA1]="$defaultFee $defaultCredit USD"
    [torA2,torA21]="$defaultFee $defaultCredit USD"
    [torA21,torA2]="$defaultFee $defaultCredit USD"
    [torA2,torA22]="$defaultFee $defaultCredit USD"
    [torA22,torA2]="$defaultFee $defaultCredit USD"
    [torA3,torA31]="$defaultFee $defaultCredit USD"
    [torA31,torA3]="$defaultFee $defaultCredit USD"
    [torA3,torA32]="$defaultFee $defaultCredit USD"
    [torA32,torA3]="$defaultFee $defaultCredit USD"
    [torA4,torA41]="$defaultFee $defaultCredit USD"
    [torA41,torA4]="$defaultFee $defaultCredit USD"
    [torA4,torA42]="$defaultFee $defaultCredit USD"
    [torA42,torA4]="$defaultFee $defaultCredit USD"
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
    docker run -d --network=eiou-network --name $container -v "${container}-mysql-data:/var/lib/mysql" -v "${container}-files:/etc/eiou/" -v "${container}-index:/var/www/html" -v "${container}-backups:/var/lib/eiou/backups" eiou/eiou
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


# Send money
echo -e "\nSending money..."
docker exec torA eiou send ${containerAddresses[torA42]} 100 USD
docker exec torA eiou send ${containerAddresses[torA31]} 100 USD
docker exec torA eiou send ${containerAddresses[torA2]} 100 USD

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances)
echo -e "\nSleeping for 10 seconds for proper completion of transaction cycles..."
sleep 10

echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec torA eiou viewcontact ${containerAddresses[torA4]}

# View balances
echo -e "\nViewing balances..."
echo -e "\nViewing balances of torA"
docker exec torA eiou viewbalances

echo -e "\nViewing balances of torA4"
docker exec torA4 eiou viewbalances

echo -e "\nViewing balances of torA42"
docker exec torA42 eiou viewbalances

echo -e "\nViewing balances of torA3"
docker exec torA3 eiou viewbalances

echo -e "\nViewing balances of torA31"
docker exec torA31 eiou viewbalances

echo -e "\nViewing balances of torA2"
docker exec torA2 eiou viewbalances


# View transaction history
echo -e "\nViewing transaction history..."

echo -e "\nViewing history of torA"
docker exec torA eiou history

echo -e "\nViewing history of torA4"
docker exec torA4 eiou history

echo -e "\nViewing history of torA42"
docker exec torA42 eiou history

echo -e "\nViewing history of torA3"
docker exec torA3 eiou history

echo -e "\nViewing history of torA31"
docker exec torA31 eiou history

echo -e "\nViewing history of torA2"
docker exec torA2 eiou history

echo -e "\nScript completed successfully."
