#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# lock-node.sh — Lock a LUKS-encrypted eIOU node volume
#
# Usage:
#   sudo ./scripts/lock-node.sh <node-name>
#
# The container must be stopped before locking. After locking,
# the volume data is completely inaccessible without the passphrase.

set -euo pipefail

NODE_NAME="${1:-}"
BASE_DIR="${EIOU_ENCRYPTED_DIR:-/srv/eiou-nodes}"

if [ -z "$NODE_NAME" ]; then
    echo "Usage: $0 <node-name>"
    echo "Locks the LUKS-encrypted volume for the specified node."
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (or with sudo)"
    exit 1
fi

MOUNT_DIR="${BASE_DIR}/${NODE_NAME}"
MAPPER_NAME="eiou-${NODE_NAME}"

# Check if the container is still running
CONTAINER_NAME="${NODE_NAME}"
if docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${CONTAINER_NAME}$"; then
    echo "ERROR: Container '$CONTAINER_NAME' is still running."
    echo "Stop it first with: docker compose -f docker-compose-encrypted.yml down"
    exit 1
fi

# Unmount
if mountpoint -q "$MOUNT_DIR" 2>/dev/null; then
    echo "Unmounting $MOUNT_DIR..."
    umount "$MOUNT_DIR"
fi

# Close LUKS
if [ -e "/dev/mapper/${MAPPER_NAME}" ]; then
    echo "Closing encrypted volume..."
    cryptsetup luksClose "$MAPPER_NAME"
fi

echo "Volume locked. Node data is now inaccessible without the passphrase."
