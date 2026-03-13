#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# create-encrypted-node.sh — Create a LUKS-encrypted volume for an eIOU node
#
# This script creates a LUKS2-encrypted disk image file for a single eIOU node.
# All node data (database, config, backups) is stored inside the encrypted
# volume. The host server cannot read any node data without the passphrase.
#
# Usage:
#   sudo ./scripts/create-encrypted-node.sh <node-name> [size-mb]
#
# Examples:
#   sudo ./scripts/create-encrypted-node.sh alice          # 2GB default
#   sudo ./scripts/create-encrypted-node.sh bob 4096       # 4GB
#
# After creation:
#   sudo ./scripts/unlock-node.sh alice                    # Unlock (needs passphrase)
#   docker compose -f docker-compose-encrypted.yml up -d   # Start node
#   sudo ./scripts/lock-node.sh alice                      # Lock after stopping
#
# Prerequisites:
#   - cryptsetup (apt-get install cryptsetup)
#   - Root or sudo access
#   - Enough disk space for the image file
#
# Security model:
#   - Each node has its own LUKS volume with its own passphrase
#   - The host operator creates the volume but the NODE OWNER sets the passphrase
#   - While locked: host sees only an encrypted blob (completely opaque)
#   - While unlocked: data is transparently decrypted via dm-crypt
#     (host root CAN read mounted data — combine with userns-remap for defense in depth)
#
# For production hosting, consider:
#   - Network-bound disk encryption (Clevis + Tang) for automatic unlock
#   - HashiCorp Vault transit engine for remote key management
#   - Kubernetes encrypted volumes with external KMS

set -euo pipefail

# =============================================================================
# Configuration
# =============================================================================

NODE_NAME="${1:-}"
SIZE_MB="${2:-2048}"
BASE_DIR="${EIOU_ENCRYPTED_DIR:-/srv/eiou-nodes}"

if [ -z "$NODE_NAME" ]; then
    echo "Usage: $0 <node-name> [size-mb]"
    echo ""
    echo "Creates a LUKS-encrypted volume for an eIOU node."
    echo ""
    echo "Arguments:"
    echo "  node-name   Name for the node (e.g., alice, bob)"
    echo "  size-mb     Volume size in MB (default: 2048 = 2GB)"
    echo ""
    echo "Environment:"
    echo "  EIOU_ENCRYPTED_DIR   Base directory (default: /srv/eiou-nodes)"
    exit 1
fi

# Validate node name (alphanumeric + hyphens only)
if ! echo "$NODE_NAME" | grep -qE '^[a-zA-Z0-9_-]+$'; then
    echo "ERROR: Node name must be alphanumeric (hyphens and underscores allowed)"
    exit 1
fi

# Check prerequisites
if ! command -v cryptsetup &>/dev/null; then
    echo "ERROR: cryptsetup is required. Install with: apt-get install cryptsetup"
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (or with sudo)"
    exit 1
fi

# =============================================================================
# Create encrypted volume
# =============================================================================

LUKS_FILE="${BASE_DIR}/${NODE_NAME}.luks"
MOUNT_DIR="${BASE_DIR}/${NODE_NAME}"
MAPPER_NAME="eiou-${NODE_NAME}"

if [ -f "$LUKS_FILE" ]; then
    echo "ERROR: Volume already exists: $LUKS_FILE"
    echo "To recreate, first remove the old volume:"
    echo "  sudo ./scripts/lock-node.sh $NODE_NAME"
    echo "  sudo rm $LUKS_FILE"
    exit 1
fi

# Create base directory
mkdir -p "$BASE_DIR"
chmod 700 "$BASE_DIR"

echo "========================================================================"
echo "Creating encrypted volume for node: $NODE_NAME"
echo "  Image file: $LUKS_FILE"
echo "  Size: ${SIZE_MB}MB"
echo "  Mount point: $MOUNT_DIR"
echo "========================================================================"
echo ""
echo "You will be asked to set a passphrase for this volume."
echo "The NODE OWNER should set this passphrase — not the server operator."
echo "If the passphrase is lost, the volume data is UNRECOVERABLE."
echo "(The wallet can still be restored from the 24-word seed phrase.)"
echo ""

# Create sparse file (allocates space on write, not upfront)
echo "Creating disk image..."
dd if=/dev/zero of="$LUKS_FILE" bs=1M count=0 seek="$SIZE_MB" status=none
chmod 600 "$LUKS_FILE"

# Format as LUKS2 with Argon2id KDF
echo "Formatting as LUKS2 (Argon2id KDF)..."
echo ""
cryptsetup luksFormat \
    --type luks2 \
    --cipher aes-xts-plain64 \
    --key-size 512 \
    --hash sha256 \
    --pbkdf argon2id \
    --label "eiou-${NODE_NAME}" \
    "$LUKS_FILE"

if [ $? -ne 0 ]; then
    echo "ERROR: LUKS format failed"
    rm -f "$LUKS_FILE"
    exit 1
fi

# Open the volume to create filesystem
echo ""
echo "Opening volume to create filesystem..."
echo "Enter the passphrase you just set:"
cryptsetup luksOpen "$LUKS_FILE" "$MAPPER_NAME"

# Create ext4 filesystem
echo "Creating ext4 filesystem..."
mkfs.ext4 -q -L "eiou-${NODE_NAME}" "/dev/mapper/${MAPPER_NAME}"

# Mount and create directory structure
mkdir -p "$MOUNT_DIR"
mount "/dev/mapper/${MAPPER_NAME}" "$MOUNT_DIR"

echo "Creating node directory structure..."
mkdir -p "${MOUNT_DIR}/mysql"
mkdir -p "${MOUNT_DIR}/config"
mkdir -p "${MOUNT_DIR}/backups"
mkdir -p "${MOUNT_DIR}/letsencrypt"

# Set ownership for Docker — these will be remapped if userns-remap is enabled
# Inside the container: mysql (UID 27), www-data (UID 33)
# Without userns-remap, these are the same UIDs on the host
chmod 700 "${MOUNT_DIR}/mysql"
chmod 700 "${MOUNT_DIR}/config"
chmod 700 "${MOUNT_DIR}/backups"
chmod 755 "${MOUNT_DIR}/letsencrypt"

# Unmount and close
umount "$MOUNT_DIR"
cryptsetup luksClose "$MAPPER_NAME"

echo ""
echo "========================================================================"
echo "Encrypted volume created successfully!"
echo "========================================================================"
echo ""
echo "To use this volume:"
echo ""
echo "  1. Unlock:  sudo ./scripts/unlock-node.sh $NODE_NAME"
echo "  2. Start:   NODE_NAME=$NODE_NAME docker compose -f docker-compose-encrypted.yml up -d"
echo "  3. Stop:    docker compose -f docker-compose-encrypted.yml down"
echo "  4. Lock:    sudo ./scripts/lock-node.sh $NODE_NAME"
echo ""
echo "Volume file: $LUKS_FILE"
echo "Mount point: $MOUNT_DIR (when unlocked)"
echo ""
