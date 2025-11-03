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
    "torA111"
    "torA112"
    "torA113"
    "torA12"
    "torA121"
    "torA122"
    "torA123"
    "torA2"
    "torA21"
    "torA211"
    "torA212"
    "torA213"
    "torA22"
    "torA221"
    "torA222"
    "torA223"
    "torA3"
    "torA31"
    "torA311"
    "torA312"
    "torA313"
    "torA32"
    "torA321"
    "torA322"
    "torA323"
    "torA4"
    "torA41"
    "torA411"
    "torA412"
    "torA413"
    "torA42"
    "torA421"
    "torA422"
    "torA423")


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
    [torA,torA321]="$defaultFee $defaultCredit USD"
    [torA321,torA]="$defaultFee $defaultCredit USD"
    [torA,torA322]="$defaultFee $defaultCredit USD"
    [torA322,torA]="$defaultFee $defaultCredit USD"
    [torA,torA323]="$defaultFee $defaultCredit USD"
    [torA323,torA]="$defaultFee $defaultCredit USD"
    [torA,torA411]="$defaultFee $defaultCredit USD"
    [torA411,torA]="$defaultFee $defaultCredit USD"
    [torA,torA412]="$defaultFee $defaultCredit USD"
    [torA412,torA]="$defaultFee $defaultCredit USD"
    [torA,torA413]="$defaultFee $defaultCredit USD"
    [torA413,torA]="$defaultFee $defaultCredit USD"
    [torA1,torA11]="$defaultFee $defaultCredit USD"
    [torA11,torA1]="$defaultFee $defaultCredit USD"
    [torA1,torA12]="$defaultFee $defaultCredit USD"
    [torA12,torA1]="$defaultFee $defaultCredit USD"
    [torA11,torA111]="$defaultFee $defaultCredit USD"
    [torA111,torA11]="$defaultFee $defaultCredit USD"
    [torA11,torA112]="$defaultFee $defaultCredit USD"
    [torA112,torA11]="$defaultFee $defaultCredit USD"
    [torA11,torA113]="$defaultFee $defaultCredit USD"
    [torA113,torA11]="$defaultFee $defaultCredit USD"
    [torA111,torA421]="$defaultFee $defaultCredit USD"
    [torA421,torA111]="$defaultFee $defaultCredit USD"
    [torA111,torA422]="$defaultFee $defaultCredit USD"
    [torA422,torA111]="$defaultFee $defaultCredit USD"
    [torA111,torA112]="$defaultFee $defaultCredit USD"
    [torA112,torA111]="$defaultFee $defaultCredit USD"
    [torA112,torA113]="$defaultFee $defaultCredit USD"
    [torA113,torA112]="$defaultFee $defaultCredit USD"
    [torA112,torA421]="$defaultFee $defaultCredit USD"
    [torA421,torA112]="$defaultFee $defaultCredit USD"
    [torA112,torA422]="$defaultFee $defaultCredit USD"
    [torA422,torA112]="$defaultFee $defaultCredit USD"
    [torA112,torA423]="$defaultFee $defaultCredit USD"
    [torA423,torA112]="$defaultFee $defaultCredit USD"
    [torA113,torA421]="$defaultFee $defaultCredit USD"
    [torA421,torA113]="$defaultFee $defaultCredit USD"
    [torA113,torA422]="$defaultFee $defaultCredit USD"
    [torA422,torA113]="$defaultFee $defaultCredit USD"
    [torA113,torA423]="$defaultFee $defaultCredit USD"
    [torA423,torA113]="$defaultFee $defaultCredit USD"
    [torA12,torA121]="$defaultFee $defaultCredit USD"
    [torA121,torA12]="$defaultFee $defaultCredit USD"
    [torA12,torA122]="$defaultFee $defaultCredit USD"
    [torA122,torA12]="$defaultFee $defaultCredit USD"
    [torA12,torA123]="$defaultFee $defaultCredit USD"
    [torA123,torA12]="$defaultFee $defaultCredit USD"
    [torA12,torA21]="$defaultFee $defaultCredit USD"
    [torA21,torA12]="$defaultFee $defaultCredit USD"
    [torA2,torA21]="$defaultFee $defaultCredit USD"
    [torA21,torA2]="$defaultFee $defaultCredit USD"
    [torA2,torA22]="$defaultFee $defaultCredit USD"
    [torA22,torA2]="$defaultFee $defaultCredit USD"
    [torA21,torA211]="$defaultFee $defaultCredit USD"
    [torA211,torA21]="$defaultFee $defaultCredit USD"
    [torA21,torA212]="$defaultFee $defaultCredit USD"
    [torA212,torA21]="$defaultFee $defaultCredit USD"
    [torA21,torA213]="$defaultFee $defaultCredit USD"
    [torA213,torA21]="$defaultFee $defaultCredit USD"
    [torA22,torA221]="$defaultFee $defaultCredit USD"
    [torA221,torA22]="$defaultFee $defaultCredit USD"
    [torA22,torA222]="$defaultFee $defaultCredit USD"
    [torA222,torA22]="$defaultFee $defaultCredit USD"
    [torA22,torA223]="$defaultFee $defaultCredit USD"
    [torA223,torA22]="$defaultFee $defaultCredit USD"
    [torA221,torA313]="$defaultFee $defaultCredit USD"
    [torA313,torA221]="$defaultFee $defaultCredit USD"
    [torA222,torA312]="$defaultFee $defaultCredit USD"
    [torA312,torA222]="$defaultFee $defaultCredit USD"
    [torA223,torA311]="$defaultFee $defaultCredit USD"
    [torA311,torA223]="$defaultFee $defaultCredit USD"
    [torA3,torA31]="$defaultFee $defaultCredit USD"
    [torA31,torA3]="$defaultFee $defaultCredit USD"
    [torA3,torA32]="$defaultFee $defaultCredit USD"
    [torA32,torA3]="$defaultFee $defaultCredit USD"
    [torA31,torA311]="$defaultFee $defaultCredit USD"
    [torA311,torA31]="$defaultFee $defaultCredit USD"
    [torA31,torA312]="$defaultFee $defaultCredit USD"
    [torA312,torA31]="$defaultFee $defaultCredit USD"
    [torA31,torA313]="$defaultFee $defaultCredit USD"
    [torA311,torA31]="$defaultFee $defaultCredit USD"
    [torA32,torA321]="$defaultFee $defaultCredit USD"
    [torA321,torA32]="$defaultFee $defaultCredit USD"
    [torA32,torA322]="$defaultFee $defaultCredit USD"
    [torA322,torA32]="$defaultFee $defaultCredit USD"
    [torA32,torA323]="$defaultFee $defaultCredit USD"
    [torA323,torA32]="$defaultFee $defaultCredit USD" 
    [torA4,torA41]="$defaultFee $defaultCredit USD"
    [torA41,torA4]="$defaultFee $defaultCredit USD"
    [torA4,torA42]="$defaultFee $defaultCredit USD"
    [torA42,torA4]="$defaultFee $defaultCredit USD"
    [torA41,torA411]="$defaultFee $defaultCredit USD"
    [torA411,torA41]="$defaultFee $defaultCredit USD"
    [torA41,torA412]="$defaultFee $defaultCredit USD"
    [torA412,torA41]="$defaultFee $defaultCredit USD"      
    [torA41,torA413]="$defaultFee $defaultCredit USD"
    [torA413,torA41]="$defaultFee $defaultCredit USD"
    [torA42,torA421]="$defaultFee $defaultCredit USD"
    [torA421,torA42]="$defaultFee $defaultCredit USD"
    [torA42,torA422]="$defaultFee $defaultCredit USD"
    [torA422,torA42]="$defaultFee $defaultCredit USD"
    [torA42,torA423]="$defaultFee $defaultCredit USD"
    [torA423,torA42]="$defaultFee $defaultCredit USD"
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
#       containerAddresses[containerName] = containerAddress (Tor)
echo -e "\nGetting Tor addresses..."
wait_for_container() {
    local container_name=$1
    local max_attempts=10
    local attempt=0

    echo "Waiting for $container_name's Tor address..."
     while ! containerAddresses[$container_name]=$(docker exec $container_name eiou generate torAddressOnly | tr -d '\n') >/dev/null 2>&1; do
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

echo -e "\nBasic Setup completed successfully."
