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

echo "Removing existing containers (if any)..."
remove_container_if_exists eioud-A-http
remove_container_if_exists eioud-A1-http
remove_container_if_exists eioud-A11-http
remove_container_if_exists eioud-A111-http
remove_container_if_exists eioud-A112-http
remove_container_if_exists eioud-A113-http
remove_container_if_exists eioud-A12-http
remove_container_if_exists eioud-A121-http
remove_container_if_exists eioud-A122-http
remove_container_if_exists eioud-A123-http
remove_container_if_exists eioud-A2-http
remove_container_if_exists eioud-A21-http
remove_container_if_exists eioud-A211-http
remove_container_if_exists eioud-A212-http
remove_container_if_exists eioud-A213-http
remove_container_if_exists eioud-A22-http
remove_container_if_exists eioud-A221-http
remove_container_if_exists eioud-A222-http
remove_container_if_exists eioud-A223-http
remove_container_if_exists eioud-A3-http
remove_container_if_exists eioud-A31-http
remove_container_if_exists eioud-A311-http
remove_container_if_exists eioud-A312-http
remove_container_if_exists eioud-A313-http
remove_container_if_exists eioud-A32-http
remove_container_if_exists eioud-A321-http
remove_container_if_exists eioud-A322-http
remove_container_if_exists eioud-A323-http
remove_container_if_exists eioud-A4-http
remove_container_if_exists eioud-A41-http
remove_container_if_exists eioud-A411-http
remove_container_if_exists eioud-A412-http
remove_container_if_exists eioud-A413-http
remove_container_if_exists eioud-A42-http
remove_container_if_exists eioud-A421-http
remove_container_if_exists eioud-A422-http
remove_container_if_exists eioud-A423-http

echo "Building base image..."
docker build -f eioud.dockerfile -t eioud .

echo "Creating containers..."
docker run -d --network=eioud-network --name eioud-A-http eioud
docker run -d --network=eioud-network --name eioud-A1-http eioud
docker run -d --network=eioud-network --name eioud-A11-http eioud
docker run -d --network=eioud-network --name eioud-A111-http eioud
docker run -d --network=eioud-network --name eioud-A112-http eioud
docker run -d --network=eioud-network --name eioud-A113-http eioud
docker run -d --network=eioud-network --name eioud-A12-http eioud
docker run -d --network=eioud-network --name eioud-A121-http eioud
docker run -d --network=eioud-network --name eioud-A122-http eioud
docker run -d --network=eioud-network --name eioud-A123-http eioud
docker run -d --network=eioud-network --name eioud-A2-http eioud
docker run -d --network=eioud-network --name eioud-A21-http eioud
docker run -d --network=eioud-network --name eioud-A211-http eioud
docker run -d --network=eioud-network --name eioud-A212-http eioud
docker run -d --network=eioud-network --name eioud-A213-http eioud
docker run -d --network=eioud-network --name eioud-A22-http eioud
docker run -d --network=eioud-network --name eioud-A221-http eioud
docker run -d --network=eioud-network --name eioud-A222-http eioud
docker run -d --network=eioud-network --name eioud-A223-http eioud
docker run -d --network=eioud-network --name eioud-A3-http eioud
docker run -d --network=eioud-network --name eioud-A31-http eioud
docker run -d --network=eioud-network --name eioud-A311-http eioud
docker run -d --network=eioud-network --name eioud-A312-http eioud
docker run -d --network=eioud-network --name eioud-A313-http eioud
docker run -d --network=eioud-network --name eioud-A32-http eioud
docker run -d --network=eioud-network --name eioud-A321-http eioud
docker run -d --network=eioud-network --name eioud-A322-http eioud
docker run -d --network=eioud-network --name eioud-A323-http eioud
docker run -d --network=eioud-network --name eioud-A4-http eioud
docker run -d --network=eioud-network --name eioud-A41-http eioud
docker run -d --network=eioud-network --name eioud-A411-http eioud
docker run -d --network=eioud-network --name eioud-A412-http eioud
docker run -d --network=eioud-network --name eioud-A413-http eioud
docker run -d --network=eioud-network --name eioud-A42-http eioud
docker run -d --network=eioud-network --name eioud-A421-http eioud
docker run -d --network=eioud-network --name eioud-A422-http eioud
docker run -d --network=eioud-network --name eioud-A423-http eioud

# Function to wait for a container to be ready
# wait_for_container() {
#     local container_name=$1
#     local max_attempts=10
#     local attempt=0

#     echo "Waiting for $container_name to be ready..."
#     while ! docker exec "$container_name" eiou generate torAddressOnly >/dev/null 2>&1; do
#         attempt=$((attempt + 1))
#         if [ "$attempt" -ge "$max_attempts" ]; then
#             echo "Error: $container_name did not start in time."
#             exit 1
#         fi
#         sleep 1
#     done
# }

# echo -e "\nWaiting for containers to be ready..."
# wait_for_container eioud-A-http
# wait_for_container eioud-A1-http
# wait_for_container eioud-A11-http
# wait_for_container eioud-A111-http
# wait_for_container eioud-A112-http
# wait_for_container eioud-A113-http
# wait_for_container eioud-A12-http
# wait_for_container eioud-A121-http
# wait_for_container eioud-A122-http 
# wait_for_container eioud-A123-http
# wait_for_container eioud-A2-http
# wait_for_container eioud-A21-http
# wait_for_container eioud-A211-http
# wait_for_container eioud-A212-http
# wait_for_container eioud-A213-http
# wait_for_container eioud-A22-http
# wait_for_container eioud-A221-http
# wait_for_container eioud-A222-http
# wait_for_container eioud-A223-http
# wait_for_container eioud-A3-http
# wait_for_container eioud-A31-http
# wait_for_container eioud-A311-http
# wait_for_container eioud-A312-http
# wait_for_container eioud-A313-http
# wait_for_container eioud-A32-http
# wait_for_container eioud-A321-http 
# wait_for_container eioud-A322-http
# wait_for_container eioud-A323-http
# wait_for_container eioud-A4-http
# wait_for_container eioud-A41-http
# wait_for_container eioud-A411-http
# wait_for_container eioud-A412-http
# wait_for_container eioud-A413-http
# wait_for_container eioud-A42-http
# wait_for_container eioud-A421-http
# wait_for_container eioud-A422-http
# wait_for_container eioud-A423-http

echo -e "\nGenerate pubkeys and set hostnames..."
docker exec eioud-A-http eiou generate http://eioud-A-http
docker exec eioud-A1-http eiou generate http://eioud-A1-http
docker exec eioud-A11-http eiou generate http://eioud-A11-http
docker exec eioud-A111-http eiou generate http://eioud-A111-http
docker exec eioud-A112-http eiou generate http://eioud-A112-http
docker exec eioud-A113-http eiou generate http://eioud-A113-http
docker exec eioud-A12-http eiou generate http://eioud-A12-http
docker exec eioud-A121-http eiou generate http://eioud-A121-http
docker exec eioud-A122-http eiou generate http://eioud-A122-http
docker exec eioud-A123-http eiou generate http://eioud-A123-http
docker exec eioud-A2-http eiou generate http://eioud-A2-http
docker exec eioud-A21-http eiou generate http://eioud-A21-http
docker exec eioud-A211-http eiou generate http://eioud-A211-http
docker exec eioud-A212-http eiou generate http://eioud-A212-http
docker exec eioud-A213-http eiou generate http://eioud-A213-http
docker exec eioud-A22-http eiou generate http://eioud-A22-http
docker exec eioud-A221-http eiou generate http://eioud-A221-http
docker exec eioud-A222-http eiou generate http://eioud-A222-http
docker exec eioud-A223-http eiou generate http://eioud-A223-http
docker exec eioud-A3-http eiou generate http://eioud-A3-http
docker exec eioud-A31-http eiou generate http://eioud-A31-http
docker exec eioud-A311-http eiou generate http://eioud-A311-http
docker exec eioud-A312-http eiou generate http://eioud-A312-http
docker exec eioud-A313-http eiou generate http://eioud-A313-http
docker exec eioud-A32-http eiou generate http://eioud-A32-http
docker exec eioud-A321-http eiou generate http://eioud-A321-http
docker exec eioud-A322-http eiou generate http://eioud-A322-http
docker exec eioud-A323-http eiou generate http://eioud-A323-http
docker exec eioud-A4-http eiou generate http://eioud-A4-http
docker exec eioud-A41-http eiou generate http://eioud-A41-http
docker exec eioud-A411-http eiou generate http://eioud-A411-http
docker exec eioud-A412-http eiou generate http://eioud-A412-http
docker exec eioud-A413-http eiou generate http://eioud-A413-http
docker exec eioud-A42-http eiou generate http://eioud-A42-http
docker exec eioud-A421-http eiou generate http://eioud-A421-http
docker exec eioud-A422-http eiou generate http://eioud-A422-http
docker exec eioud-A423-http eiou generate http://eioud-A423-http

# Add friends
# (NOTE that names are NOT arbitrary)

echo -e "\nAdding friends..."
docker exec eioud-A-http eiou add http://eioud-A1-http eioud-A1-http 0.1 1000 USD
docker exec eioud-A1-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A2-http eioud-A2-http 0.1 1000 USD
docker exec eioud-A2-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A3-http eioud-A3-http 0.1 1000 USD
docker exec eioud-A3-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A4-http eioud-A4-http 0.1 1000 USD
docker exec eioud-A4-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A321-http eioud-A321-http 0.1 1000 USD
docker exec eioud-A321-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A322-http eioud-A322-http 0.1 1000 USD
docker exec eioud-A322-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A323-http eioud-A323-http 0.1 1000 USD
docker exec eioud-A323-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A411-http eioud-A411-http 0.1 1000 USD
docker exec eioud-A411-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A412-http eioud-A412-http 0.1 1000 USD
docker exec eioud-A412-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A-http eiou add http://eioud-A413-http eioud-A413-http 0.1 1000 USD
docker exec eioud-A413-http eiou add http://eioud-A-http eioud-A-http 0.1 1000 USD
docker exec eioud-A1-http eiou add http://eioud-A11-http eioud-A11-http 0.1 1000 USD
docker exec eioud-A11-http eiou add http://eioud-A1-http eioud-A1-http 0.1 1000 USD
docker exec eioud-A1-http eiou add http://eioud-A12-http eioud-A12-http 0.1 1000 USD
docker exec eioud-A12-http eiou add http://eioud-A1-http eioud-A1-http 0.1 1000 USD
docker exec eioud-A11-http eiou add http://eioud-A111-http eioud-A111-http 0.1 1000 USD
docker exec eioud-A111-http eiou add http://eioud-A11-http eioud-A11-http 0.1 1000 USD
docker exec eioud-A11-http eiou add http://eioud-A112-http eioud-A112-http 0.1 1000 USD
docker exec eioud-A112-http eiou add http://eioud-A11-http eioud-A11-http 0.1 1000 USD
docker exec eioud-A11-http eiou add http://eioud-A113-http eioud-A113-http 0.1 1000 USD
docker exec eioud-A113-http eiou add http://eioud-A11-http eioud-A11-http 0.1 1000 USD
docker exec eioud-A111-http eiou add http://eioud-A421-http eioud-A421-http 0.1 1000 USD
docker exec eioud-A421-http eiou add http://eioud-A111-http eioud-A111-http 0.1 1000 USD
docker exec eioud-A111-http eiou add http://eioud-A422-http eioud-A422-http 0.1 1000 USD
docker exec eioud-A422-http eiou add http://eioud-A111-http eioud-A111-http 0.1 1000 USD
docker exec eioud-A111-http eiou add http://eioud-A112-http eioud-A112-http 0.1 1000 USD
docker exec eioud-A112-http eiou add http://eioud-A111-http eioud-A111-http 0.1 1000 USD
docker exec eioud-A112-http eiou add http://eioud-A113-http eioud-A113-http 0.1 1000 USD
docker exec eioud-A113-http eiou add http://eioud-A112-http eioud-A112-http 0.1 1000 USD
docker exec eioud-A112-http eiou add http://eioud-A421-http eioud-A421-http 0.1 1000 USD
docker exec eioud-A421-http eiou add http://eioud-A112-http eioud-A112-http 0.1 1000 USD
docker exec eioud-A112-http eiou add http://eioud-A422-http eioud-A422-http 0.1 1000 USD
docker exec eioud-A422-http eiou add http://eioud-A112-http eioud-A112-http 0.1 1000 USD
docker exec eioud-A112-http eiou add http://eioud-A423-http eioud-A423-http 0.1 1000 USD
docker exec eioud-A423-http eiou add http://eioud-A112-http eioud-A112-http 0.1 1000 USD
docker exec eioud-A113-http eiou add http://eioud-A421-http eioud-A421-http 0.1 1000 USD
docker exec eioud-A421-http eiou add http://eioud-A113-http eioud-A113-http 0.1 1000 USD
docker exec eioud-A113-http eiou add http://eioud-A422-http eioud-A422-http 0.1 1000 USD
docker exec eioud-A422-http eiou add http://eioud-A113-http eioud-A113-http 0.1 1000 USD
docker exec eioud-A113-http eiou add http://eioud-A423-http eioud-A423-http 0.1 1000 USD
docker exec eioud-A423-http eiou add http://eioud-A113-http eioud-A113-http 0.1 1000 USD
docker exec eioud-A12-http eiou add http://eioud-A121-http eioud-A121-http 0.1 1000 USD
docker exec eioud-A121-http eiou add http://eioud-A12-http eioud-A12-http 0.1 1000 USD
docker exec eioud-A12-http eiou add http://eioud-A122-http eioud-A122-http 0.1 1000 USD
docker exec eioud-A122-http eiou add http://eioud-A12-http eioud-A12-http 0.1 1000 USD
docker exec eioud-A12-http eiou add http://eioud-A123-http eioud-A123-http 0.1 1000 USD
docker exec eioud-A123-http eiou add http://eioud-A12-http eioud-A12-http 0.1 1000 USD
docker exec eioud-A12-http eiou add http://eioud-A21-http eioud-A21-http 0.1 1000 USD
docker exec eioud-A21-http eiou add http://eioud-A12-http eioud-A12-http 0.1 1000 USD
docker exec eioud-A2-http eiou add http://eioud-A21-http eioud-A21-http 0.1 1000 USD
docker exec eioud-A21-http eiou add http://eioud-A2-http eioud-A2-http 0.1 1000 USD
docker exec eioud-A2-http eiou add http://eioud-A22-http eioud-A22-http 0.1 1000 USD
docker exec eioud-A22-http eiou add http://eioud-A2-http eioud-A2-http 0.1 1000 USD
docker exec eioud-A21-http eiou add http://eioud-A211-http eioud-A211-http 0.1 1000 USD
docker exec eioud-A211-http eiou add http://eioud-A21-http eioud-A21-http 0.1 1000 USD
docker exec eioud-A21-http eiou add http://eioud-A212-http eioud-A212-http 0.1 1000 USD
docker exec eioud-A212-http eiou add http://eioud-A21-http eioud-A21-http 0.1 1000 USD
docker exec eioud-A21-http eiou add http://eioud-A213-http eioud-A213-http 0.1 1000 USD
docker exec eioud-A213-http eiou add http://eioud-A21-http eioud-A21-http 0.1 1000 USD
docker exec eioud-A22-http eiou add http://eioud-A221-http eioud-A221-http 0.1 1000 USD
docker exec eioud-A221-http eiou add http://eioud-A22-http eioud-A22-http 0.1 1000 USD
docker exec eioud-A22-http eiou add http://eioud-A222-http eioud-A222-http 0.1 1000 USD
docker exec eioud-A222-http eiou add http://eioud-A22-http eioud-A22-http 0.1 1000 USD
docker exec eioud-A22-http eiou add http://eioud-A223-http eioud-A223-http 0.1 1000 USD
docker exec eioud-A223-http eiou add http://eioud-A22-http eioud-A22-http 0.1 1000 USD
docker exec eioud-A221-http eiou add http://eioud-A313-http eioud-A313-http 0.1 1000 USD
docker exec eioud-A313-http eiou add http://eioud-A221-http eioud-A221-http 0.1 1000 USD
docker exec eioud-A222-http eiou add http://eioud-A312-http eioud-A312-http 0.1 1000 USD
docker exec eioud-A312-http eiou add http://eioud-A222-http eioud-A222-http 0.1 1000 USD
docker exec eioud-A223-http eiou add http://eioud-A311-http eioud-A311-http 0.1 1000 USD
docker exec eioud-A311-http eiou add http://eioud-A223-http eioud-A223-http 0.1 1000 USD
docker exec eioud-A3-http eiou add http://eioud-A31-http eioud-A31-http 0.1 1000 USD
docker exec eioud-A31-http eiou add http://eioud-A3-http eioud-A3-http 0.1 1000 USD
docker exec eioud-A3-http eiou add http://eioud-A32-http eioud-A32-http 0.1 1000 USD
docker exec eioud-A32-http eiou add http://eioud-A3-http eioud-A3-http 0.1 1000 USD
docker exec eioud-A31-http eiou add http://eioud-A311-http eioud-A311-http 0.1 1000 USD
docker exec eioud-A311-http eiou add http://eioud-A31-http eioud-A31-http 0.1 1000 USD
docker exec eioud-A31-http eiou add http://eioud-A312-http eioud-A312-http 0.1 1000 USD
docker exec eioud-A312-http eiou add http://eioud-A31-http eioud-A31-http 0.1 1000 USD
docker exec eioud-A31-http eiou add http://eioud-A313-http eioud-A313-http 0.1 1000 USD
docker exec eioud-A313-http eiou add http://eioud-A31-http eioud-A31-http 0.1 1000 USD
docker exec eioud-A32-http eiou add http://eioud-A321-http eioud-A321-http 0.1 1000 USD
docker exec eioud-A321-http eiou add http://eioud-A32-http eioud-A32-http 0.1 1000 USD
docker exec eioud-A32-http eiou add http://eioud-A322-http eioud-A322-http 0.1 1000 USD
docker exec eioud-A322-http eiou add http://eioud-A32-http eioud-A32-http 0.1 1000 USD
docker exec eioud-A32-http eiou add http://eioud-A323-http eioud-A323-http 0.1 1000 USD
docker exec eioud-A323-http eiou add http://eioud-A32-http eioud-A32-http 0.1 1000 USD
docker exec eioud-A4-http eiou add http://eioud-A41-http eioud-A41-http 0.1 1000 USD
docker exec eioud-A41-http eiou add http://eioud-A4-http eioud-A4-http 0.1 1000 USD
docker exec eioud-A4-http eiou add http://eioud-A42-http eioud-A42-http 0.1 1000 USD
docker exec eioud-A42-http eiou add http://eioud-A4-http eioud-A4-http 0.1 1000 USD
docker exec eioud-A41-http eiou add http://eioud-A411-http eioud-A411-http 0.1 1000 USD
docker exec eioud-A411-http eiou add http://eioud-A41-http eioud-A41-http 0.1 1000 USD
docker exec eioud-A41-http eiou add http://eioud-A412-http eioud-A412-http 0.1 1000 USD
docker exec eioud-A412-http eiou add http://eioud-A41-http eioud-A41-http 0.1 1000 USD
docker exec eioud-A41-http eiou add http://eioud-A413-http eioud-A413-http 0.1 1000 USD
docker exec eioud-A413-http eiou add http://eioud-A41-http eioud-A41-http 0.1 1000 USD
docker exec eioud-A42-http eiou add http://eioud-A421-http eioud-A421-http 0.1 1000 USD
docker exec eioud-A421-http eiou add http://eioud-A42-http eioud-A42-http 0.1 1000 USD
docker exec eioud-A42-http eiou add http://eioud-A422-http eioud-A422-http 0.1 1000 USD
docker exec eioud-A422-http eiou add http://eioud-A42-http eioud-A42-http 0.1 1000 USD
docker exec eioud-A42-http eiou add http://eioud-A423-http eioud-A423-http 0.1 1000 USD
docker exec eioud-A423-http eiou add http://eioud-A42-http eioud-A42-http 0.1 1000 USD





# Send money
echo -e "\nSending money..."
docker exec eioud-A-http eiou send http://eioud-A422-http 100 USD
docker exec eioud-A-http eiou send http://eioud-A312-http 100 USD
docker exec eioud-A-http eiou send http://eioud-A2-http 100 USD


echo -e "\nTesting other functions..."

# Read contacts
echo -e "\nReading contacts..."
ocker exec eioud-A-http eiou read http://eioud-A4-http
docker exec eioud-A421-http eiou read http://eioud-A113-http


echo -e "\nSleeping for 5 seconds..."
#need a moment for the whole P2P/RP2P/Transaction to be completed (otherwise it's not available yet in the balances)
sleep 5

# View balances
echo -e "\nViewing balances..."
docker exec eioud-A-http eiou view
docker exec eioud-A42-http eiou view
docker exec eioud-A422-http eiou view
docker exec eioud-A312-http eiou view
docker exec eioud-A2-http eiou view


# View transaction history
echo -e "\nViewing transaction history..."
docker exec eioud-A-http eiou history
docker exec eioud-A42-http eiou history
docker exec eioud-A422-http eiou history
docker exec eioud-A312-http eiou history
docker exec eioud-A2-http eiou history

echo -e "\nScript completed successfully."
