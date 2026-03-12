#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# SSL Certificate Authority Generator for eIOU Docker
#
# This script creates a local Certificate Authority (CA) that can be used to sign
# SSL certificates for eIOU nodes. Once the CA root certificate is installed in
# browsers/systems, all nodes using CA-signed certificates will be trusted.
#
# Usage:
#   ./scripts/create-ssl-ca.sh [output_directory]
#
# Example:
#   ./scripts/create-ssl-ca.sh ./ssl-ca
#
# After running:
#   1. Install the CA certificate (ca.crt) in your browser/system trust store
#   2. Mount the ssl-ca directory when running containers:
#      docker run -v ./ssl-ca:/ssl-ca:ro ...
#   Or in docker-compose:
#      volumes:
#        - ./ssl-ca:/ssl-ca:ro

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default output directory
CA_DIR="${1:-./ssl-ca}"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  eIOU SSL Certificate Authority Setup${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Check if CA already exists
if [ -f "$CA_DIR/ca.crt" ] && [ -f "$CA_DIR/ca.key" ]; then
    echo -e "${YELLOW}Warning: CA already exists in $CA_DIR${NC}"
    read -p "Do you want to overwrite it? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 0
    fi
fi

# Create output directory
mkdir -p "$CA_DIR"

echo "Creating Certificate Authority in: $CA_DIR"
echo ""

# Generate CA private key (4096 bits for CA)
echo -e "${GREEN}[1/2]${NC} Generating CA private key..."
openssl genrsa -out "$CA_DIR/ca.key" 4096

# Generate CA certificate (valid for 10 years)
echo -e "${GREEN}[2/2]${NC} Generating CA certificate..."
openssl req -x509 -new -nodes \
    -key "$CA_DIR/ca.key" \
    -sha256 \
    -days 3650 \
    -out "$CA_DIR/ca.crt" \
    -subj "/C=XX/ST=State/L=City/O=EIOU/OU=Certificate Authority/CN=EIOU Root CA"

# Set secure permissions
chmod 600 "$CA_DIR/ca.key"
chmod 644 "$CA_DIR/ca.crt"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  CA Created Successfully!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Files created:"
echo "  - $CA_DIR/ca.crt  (CA certificate - distribute to clients)"
echo "  - $CA_DIR/ca.key  (CA private key - keep secure!)"
echo ""
echo -e "${YELLOW}Next Steps:${NC}"
echo ""
echo "1. Install the CA certificate in browsers/systems:"
echo ""
echo "   ${GREEN}Windows:${NC}"
echo "     Double-click ca.crt > Install Certificate > Local Machine"
echo "     > Trusted Root Certification Authorities > Finish"
echo ""
echo "   ${GREEN}macOS:${NC}"
echo "     Double-click ca.crt > Add to Keychain > System"
echo "     Open Keychain Access > Find 'EIOU Root CA' > Trust > Always Trust"
echo ""
echo "   ${GREEN}Linux (Ubuntu/Debian):${NC}"
echo "     sudo cp $CA_DIR/ca.crt /usr/local/share/ca-certificates/eiou-ca.crt"
echo "     sudo update-ca-certificates"
echo ""
echo "   ${GREEN}Firefox (all platforms):${NC}"
echo "     Settings > Privacy & Security > Certificates > View Certificates"
echo "     > Authorities > Import > Select ca.crt > Trust for websites"
echo ""
echo "2. Use with Docker containers:"
echo ""
echo "   ${GREEN}Docker run:${NC}"
echo "     docker run -v \$(pwd)/$CA_DIR:/ssl-ca:ro ... eiou/eiou"
echo ""
echo "   ${GREEN}Docker Compose:${NC}"
echo "     volumes:"
echo "       - ./$CA_DIR:/ssl-ca:ro"
echo ""
echo "The container will automatically generate CA-signed certificates"
echo "that browsers will trust (after installing the CA)."
echo ""
