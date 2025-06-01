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
    "A111"
    "A112"
    "A113"
    "A12"
    "A121"
    "A122"
    "A123"
    "A2"
    "A21"
    "A211"
    "A212"
    "A213"
    "A22"
    "A221"
    "A222"
    "A223"
    "A3"
    "A31"
    "A311"
    "A312"
    "A313"
    "A32"
    "A321"
    "A322"
    "A323"
    "A4"
    "A41"
    "A411"
    "A412"
    "A413"
    "A42"
    "A421"
    "A422"
    "A423")


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
    [A,A321]="$defaultFee $defaultCredit USD"
    [A321,A]="$defaultFee $defaultCredit USD"
    [A,A322]="$defaultFee $defaultCredit USD"
    [A322,A]="$defaultFee $defaultCredit USD"
    [A,A323]="$defaultFee $defaultCredit USD"
    [A323,A]="$defaultFee $defaultCredit USD"
    [A,A411]="$defaultFee $defaultCredit USD"
    [A411,A]="$defaultFee $defaultCredit USD"
    [A,A412]="$defaultFee $defaultCredit USD"
    [A412,A]="$defaultFee $defaultCredit USD"
    [A,A413]="$defaultFee $defaultCredit USD"
    [A413,A]="$defaultFee $defaultCredit USD"
    [A1,A11]="$defaultFee $defaultCredit USD"
    [A11,A1]="$defaultFee $defaultCredit USD"
    [A1,A12]="$defaultFee $defaultCredit USD"
    [A12,A1]="$defaultFee $defaultCredit USD"
    [A11,A111]="$defaultFee $defaultCredit USD"
    [A111,A11]="$defaultFee $defaultCredit USD"
    [A11,A112]="$defaultFee $defaultCredit USD"
    [A112,A11]="$defaultFee $defaultCredit USD"
    [A11,A113]="$defaultFee $defaultCredit USD"
    [A113,A11]="$defaultFee $defaultCredit USD"
    [A111,A421]="$defaultFee $defaultCredit USD"
    [A421,A111]="$defaultFee $defaultCredit USD"
    [A111,A422]="$defaultFee $defaultCredit USD"
    [A422,A111]="$defaultFee $defaultCredit USD"
    [A111,A112]="$defaultFee $defaultCredit USD"
    [A112,A111]="$defaultFee $defaultCredit USD"
    [A112,A113]="$defaultFee $defaultCredit USD"
    [A113,A112]="$defaultFee $defaultCredit USD"
    [A112,A421]="$defaultFee $defaultCredit USD"
    [A421,A112]="$defaultFee $defaultCredit USD"
    [A112,A422]="$defaultFee $defaultCredit USD"
    [A422,A112]="$defaultFee $defaultCredit USD"
    [A112,A423]="$defaultFee $defaultCredit USD"
    [A423,A112]="$defaultFee $defaultCredit USD"
    [A113,A421]="$defaultFee $defaultCredit USD"
    [A421,A113]="$defaultFee $defaultCredit USD"
    [A113,A422]="$defaultFee $defaultCredit USD"
    [A422,A113]="$defaultFee $defaultCredit USD"
    [A113,A423]="$defaultFee $defaultCredit USD"
    [A423,A113]="$defaultFee $defaultCredit USD"
    [A12,A121]="$defaultFee $defaultCredit USD"
    [A121,A12]="$defaultFee $defaultCredit USD"
    [A12,A122]="$defaultFee $defaultCredit USD"
    [A122,A12]="$defaultFee $defaultCredit USD"
    [A12,A123]="$defaultFee $defaultCredit USD"
    [A123,A12]="$defaultFee $defaultCredit USD"
    [A12,A21]="$defaultFee $defaultCredit USD"
    [A21,A12]="$defaultFee $defaultCredit USD"
    [A2,A21]="$defaultFee $defaultCredit USD"
    [A21,A2]="$defaultFee $defaultCredit USD"
    [A2,A22]="$defaultFee $defaultCredit USD"
    [A22,A2]="$defaultFee $defaultCredit USD"
    [A21,A211]="$defaultFee $defaultCredit USD"
    [A211,A21]="$defaultFee $defaultCredit USD"
    [A21,A212]="$defaultFee $defaultCredit USD"
    [A212,A21]="$defaultFee $defaultCredit USD"
    [A21,A213]="$defaultFee $defaultCredit USD"
    [A213,A21]="$defaultFee $defaultCredit USD"
    [A22,A221]="$defaultFee $defaultCredit USD"
    [A221,A22]="$defaultFee $defaultCredit USD"
    [A22,A222]="$defaultFee $defaultCredit USD"
    [A222,A22]="$defaultFee $defaultCredit USD"
    [A22,A223]="$defaultFee $defaultCredit USD"
    [A223,A22]="$defaultFee $defaultCredit USD"
    [A221,A313]="$defaultFee $defaultCredit USD"
    [A313,A221]="$defaultFee $defaultCredit USD"
    [A222,A312]="$defaultFee $defaultCredit USD"
    [A312,A222]="$defaultFee $defaultCredit USD"
    [A223,A311]="$defaultFee $defaultCredit USD"
    [A311,A223]="$defaultFee $defaultCredit USD"
    [A3,A31]="$defaultFee $defaultCredit USD"
    [A31,A3]="$defaultFee $defaultCredit USD"
    [A3,A32]="$defaultFee $defaultCredit USD"
    [A32,A3]="$defaultFee $defaultCredit USD"
    [A31,A311]="$defaultFee $defaultCredit USD"
    [A311,A31]="$defaultFee $defaultCredit USD"
    [A31,A312]="$defaultFee $defaultCredit USD"
    [A312,A31]="$defaultFee $defaultCredit USD"
    [A31,A313]="$defaultFee $defaultCredit USD"
    [A311,A31]="$defaultFee $defaultCredit USD"
    [A32,A321]="$defaultFee $defaultCredit USD"
    [A321,A32]="$defaultFee $defaultCredit USD"
    [A32,A322]="$defaultFee $defaultCredit USD"
    [A322,A32]="$defaultFee $defaultCredit USD"
    [A32,A323]="$defaultFee $defaultCredit USD"
    [A323,A32]="$defaultFee $defaultCredit USD" 
    [A4,A41]="$defaultFee $defaultCredit USD"
    [A41,A4]="$defaultFee $defaultCredit USD"
    [A4,A42]="$defaultFee $defaultCredit USD"
    [A42,A4]="$defaultFee $defaultCredit USD"
    [A41,A411]="$defaultFee $defaultCredit USD"
    [A411,A41]="$defaultFee $defaultCredit USD"
    [A41,A412]="$defaultFee $defaultCredit USD"
    [A412,A41]="$defaultFee $defaultCredit USD"      
    [A41,A413]="$defaultFee $defaultCredit USD"
    [A413,A41]="$defaultFee $defaultCredit USD"
    [A42,A421]="$defaultFee $defaultCredit USD"
    [A421,A42]="$defaultFee $defaultCredit USD"
    [A42,A422]="$defaultFee $defaultCredit USD"
    [A422,A42]="$defaultFee $defaultCredit USD"
    [A42,A423]="$defaultFee $defaultCredit USD"
    [A423,A42]="$defaultFee $defaultCredit USD"
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
docker exec A eiou send ${containerAddresses[A422]} 100 USD
docker exec A eiou send ${containerAddresses[A312]} 100 USD
docker exec A eiou send ${containerAddresses[A2]} 100 USD
docker exec A eiou send ${containerAddresses[A21]} 100 USD
docker exec A eiou send ${containerAddresses[A412]} 100 USD
docker exec A eiou send ${containerAddresses[A121]} 100 USD


echo -e "\nTesting other functions..."

# View contacts
echo -e "\nViewing contacts..."
docker exec A eiou viewcontact ${containerAddresses[A4]}
docker exec A421 eiou viewcontact ${containerAddresses[A113]}

# need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances)
echo -e "\nSleeping for 5 seconds..."
sleep 5

# View balances
echo -e "\nViewing balances..."
docker exec A eiou viewbalances 
docker exec A42 eiou viewbalances
docker exec A422 eiou viewbalances
docker exec A312 eiou viewbalances
docker exec A2 eiou viewbalances


# View transaction history
echo -e "\nViewing transaction history..."
docker exec A eiou history
docker exec A42 eiou history
docker exec A422 eiou history
docker exec A312 eiou history
docker exec A2 eiou history

echo -e "\nScript completed successfully."
