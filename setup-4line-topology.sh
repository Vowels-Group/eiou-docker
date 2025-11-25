#!/bin/bash

# Setup script to configure 4-line topology for alice, bob, carol, daniel containers
# Creates a line topology: alice <--> bob <--> carol <--> daniel

set -e # Stop script on failure

echo "Setting up 4-line topology for alice, bob, carol, daniel..."

# Default fee and credit settings
readonly defaultFee=0.1
readonly defaultCredit=1000

# Add contacts to create line topology
echo "Adding alice to bob's contacts..."
docker compose -f docker-compose-4line.yml exec -T bob eiou add http://alice alice $defaultFee $defaultCredit USD

echo "Adding bob to alice's contacts..."
docker compose -f docker-compose-4line.yml exec -T alice eiou add http://bob bob $defaultFee $defaultCredit USD

echo "Adding bob to carol's contacts..."
docker compose -f docker-compose-4line.yml exec -T carol eiou add http://bob bob $defaultFee $defaultCredit USD

echo "Adding carol to bob's contacts..."
docker compose -f docker-compose-4line.yml exec -T bob eiou add http://carol carol $defaultFee $defaultCredit USD

echo "Adding carol to daniel's contacts..."
docker compose -f docker-compose-4line.yml exec -T daniel eiou add http://carol carol $defaultFee $defaultCredit USD

echo "Adding daniel to carol's contacts..."
docker compose -f docker-compose-4line.yml exec -T carol eiou add http://daniel daniel $defaultFee $defaultCredit USD

echo "4-line topology setup complete!"
echo "Topology: alice <--> bob <--> carol <--> daniel"