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
# example: [eioud-0-tor,eioud-1-tor] defines eioud-1-tor as a contact of eioud-0-tor
#          must be accepted in reverse that is to say: 
#          [eioud-0-tor,eioud-1-tor] needs to be followed by [eioud-1-tor,eioud-0-tor]
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


# Send money
echo -e "\nSending money..."
docker exec torA eiou send ${containerAddresses[torA42]} 100 USD
docker exec torA eiou send ${containerAddresses[torA31]} 100 USD
docker exec torA eiou send ${containerAddresses[torA2]} 100 USD


echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec torA eiou viewcontact ${containerAddresses[torA4]}

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances/transaction history)
echo -e "\nSleeping for 10 seconds..."
sleep 10 

# View balances
echo -e "\nViewing balances..."
docker exec torA eiou viewbalances
docker exec torA4 eiou viewbalances
docker exec torA42 eiou viewbalances
docker exec torA3 eiou viewbalances
docker exec torA31 eiou viewbalances
docker exec torA2 eiou viewbalances


# View transaction history
echo -e "\nViewing transaction history..."
docker exec torA eiou history
docker exec torA4 eiou history
docker exec torA42 eiou history
docker exec torA3 eiou history
docker exec torA31 eiou history
docker exec torA2 eiou history

echo -e "\nScript completed successfully."
