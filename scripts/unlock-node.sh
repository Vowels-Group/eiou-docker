#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# unlock-node.sh — Unlock a LUKS-encrypted eIOU node volume
#
# Usage:
#   sudo ./scripts/unlock-node.sh <node-name>
#
# The node owner provides their passphrase to decrypt the volume.
# After unlocking, the node can be started with docker compose.

set -euo pipefail

NODE_NAME="${1:-}"
BASE_DIR="${EIOU_ENCRYPTED_DIR:-/srv/eiou-nodes}"

if [ -z "$NODE_NAME" ]; then
    echo "Usage: $0 <node-name>"
    echo "Unlocks the LUKS-encrypted volume for the specified node."
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (or with sudo)"
    exit 1
fi

LUKS_FILE="${BASE_DIR}/${NODE_NAME}.luks"
MOUNT_DIR="${BASE_DIR}/${NODE_NAME}"
MAPPER_NAME="eiou-${NODE_NAME}"

if [ ! -f "$LUKS_FILE" ]; then
    echo "ERROR: Volume not found: $LUKS_FILE"
    echo "Create it first with: sudo ./scripts/create-encrypted-node.sh $NODE_NAME"
    exit 1
fi

# Check if already unlocked
if [ -e "/dev/mapper/${MAPPER_NAME}" ]; then
    if mountpoint -q "$MOUNT_DIR" 2>/dev/null; then
        echo "Volume is already unlocked and mounted at $MOUNT_DIR"
        exit 0
    fi
    # Mapped but not mounted — mount it
    echo "Volume is unlocked but not mounted. Mounting..."
    mkdir -p "$MOUNT_DIR"
    mount "/dev/mapper/${MAPPER_NAME}" "$MOUNT_DIR"
    echo "Mounted at $MOUNT_DIR"
    exit 0
fi

# Unlock
echo "Unlocking volume for node: $NODE_NAME"
cryptsetup luksOpen "$LUKS_FILE" "$MAPPER_NAME"

if [ $? -ne 0 ]; then
    echo "ERROR: Failed to unlock volume (wrong passphrase?)"
    exit 1
fi

# Mount
mkdir -p "$MOUNT_DIR"
mount "/dev/mapper/${MAPPER_NAME}" "$MOUNT_DIR"

echo "Volume unlocked and mounted at $MOUNT_DIR"
echo ""
echo "Start the node with:"
echo "  NODE_NAME=$NODE_NAME docker compose -f docker-compose-encrypted.yml up -d"
