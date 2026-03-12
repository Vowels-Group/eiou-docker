#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# Base Image Digest Checker for eIOU Docker
#
# Compares the SHA256 digest pinned in eiou.dockerfile against the latest
# digest published on Docker Hub for debian:12-slim. Reports whether the
# pinned image is up to date or stale.
#
# Usage:
#   ./scripts/check-base-image.sh
#
# Exit codes:
#   0 - Pinned digest matches the latest upstream digest
#   1 - Pinned digest is outdated or an error occurred

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Resolve script directory to find the Dockerfile
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DOCKERFILE="$SCRIPT_DIR/../eiou.dockerfile"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  eIOU Base Image Digest Checker${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Step 1: Extract pinned digest from Dockerfile
echo -e "${GREEN}[1/3]${NC} Reading pinned digest from eiou.dockerfile..."

if [ ! -f "$DOCKERFILE" ]; then
    echo -e "${RED}Error: eiou.dockerfile not found at $DOCKERFILE${NC}"
    exit 1
fi

PINNED_DIGEST=$(grep -oP '(?<=@)sha256:[a-f0-9]+' "$DOCKERFILE" | head -1)

if [ -z "$PINNED_DIGEST" ]; then
    echo -e "${RED}Error: No SHA256 digest found in eiou.dockerfile${NC}"
    echo "The FROM line should include @sha256:<digest>"
    exit 1
fi

echo "  Pinned: $PINNED_DIGEST"
echo ""

# Step 2: Query Docker Hub for the latest digest
echo -e "${GREEN}[2/3]${NC} Querying Docker Hub for latest debian:12-slim digest..."

TOKEN=$(curl -s "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/debian:pull" \
    | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

if [ -z "$TOKEN" ]; then
    echo -e "${RED}Error: Failed to obtain Docker Hub authentication token${NC}"
    exit 1
fi

LATEST_DIGEST=$(curl -sI \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/vnd.docker.distribution.manifest.list.v2+json" \
    "https://registry-1.docker.io/v2/library/debian/manifests/12-slim" \
    | grep -i docker-content-digest | tr -d '\r' | awk '{print $2}')

if [ -z "$LATEST_DIGEST" ]; then
    echo -e "${RED}Error: Failed to retrieve latest digest from Docker Hub${NC}"
    exit 1
fi

echo "  Latest: $LATEST_DIGEST"
echo ""

# Step 3: Compare digests
echo -e "${GREEN}[3/3]${NC} Comparing digests..."
echo ""

if [ "$PINNED_DIGEST" = "$LATEST_DIGEST" ]; then
    echo -e "${GREEN}Up to date.${NC} The pinned digest matches the latest upstream digest."
    exit 0
else
    echo -e "${YELLOW}Stale.${NC} The pinned digest does not match the latest upstream digest."
    echo ""
    echo "  Pinned: $PINNED_DIGEST"
    echo "  Latest: $LATEST_DIGEST"
    echo ""
    echo "To update, replace the digest in eiou.dockerfile:"
    echo "  FROM debian:12-slim@$LATEST_DIGEST"
    exit 1
fi
