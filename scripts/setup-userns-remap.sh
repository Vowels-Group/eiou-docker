#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC
#
# setup-userns-remap.sh — Enable Docker user namespace remapping
#
# User namespace remapping maps container UIDs to unprivileged host UIDs.
# Container root (UID 0) becomes an unprivileged user on the host, so files
# written by the container are NOT owned by host root.
#
# Effect on volume security:
#   - Without userns-remap: container root = host root (UID 0)
#     → host root can trivially read all volume files
#   - With userns-remap: container root = host UID 100000 (subordinate range)
#     → volume files are owned by UID 100000, not readable by other users
#     → host root can STILL read them (root bypasses permissions), but:
#       - Casual inspection by non-root host users is blocked
#       - Combined with LUKS, provides strong defense in depth
#
# IMPORTANT:
#   - This affects ALL containers on the Docker daemon (or use per-container override)
#   - Existing volumes may need ownership changes
#   - Some containers may need --userns=host to bypass remapping
#
# Usage:
#   sudo ./scripts/setup-userns-remap.sh [username]
#
# Arguments:
#   username   Host user for the subordinate UID/GID range (default: dockremap)
#              Use "default" to let Docker create the dockremap user automatically.

set -euo pipefail

REMAP_USER="${1:-default}"

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root (or with sudo)"
    exit 1
fi

DAEMON_JSON="/etc/docker/daemon.json"

echo "========================================================================"
echo "Docker User Namespace Remapping Setup"
echo "========================================================================"
echo ""
echo "This will configure Docker to remap container UIDs to unprivileged"
echo "host UIDs. Container root (UID 0) will map to an unprivileged host"
echo "user, so volume files are not owned by host root."
echo ""

# Check current state
if [ -f "$DAEMON_JSON" ]; then
    if grep -q '"userns-remap"' "$DAEMON_JSON" 2>/dev/null; then
        CURRENT=$(grep -o '"userns-remap"[[:space:]]*:[[:space:]]*"[^"]*"' "$DAEMON_JSON" | cut -d'"' -f4)
        echo "User namespace remapping is ALREADY configured: $CURRENT"
        echo ""
        echo "Current $DAEMON_JSON:"
        cat "$DAEMON_JSON"
        echo ""
        echo "To change, edit $DAEMON_JSON manually and restart Docker."
        exit 0
    fi
fi

# If using "default", Docker creates the dockremap user automatically
if [ "$REMAP_USER" = "default" ]; then
    echo "Using Docker's default 'dockremap' user."
    echo ""
    echo "Docker will automatically:"
    echo "  1. Create the 'dockremap' user (if needed)"
    echo "  2. Configure subordinate UID/GID ranges in /etc/subuid and /etc/subgid"
    echo "  3. Remap container UIDs: container UID 0 → host UID 100000 (approx)"
    echo ""
else
    # Create the remap user if it doesn't exist
    if ! id "$REMAP_USER" &>/dev/null; then
        echo "Creating user: $REMAP_USER"
        useradd -r -s /usr/sbin/nologin "$REMAP_USER"
    fi

    # Set up subordinate UID/GID ranges
    if ! grep -q "^${REMAP_USER}:" /etc/subuid 2>/dev/null; then
        echo "${REMAP_USER}:100000:65536" >> /etc/subuid
        echo "Added subordinate UID range for $REMAP_USER"
    fi
    if ! grep -q "^${REMAP_USER}:" /etc/subgid 2>/dev/null; then
        echo "${REMAP_USER}:100000:65536" >> /etc/subgid
        echo "Added subordinate GID range for $REMAP_USER"
    fi
fi

# Update daemon.json
if [ -f "$DAEMON_JSON" ]; then
    # Backup existing config
    cp "$DAEMON_JSON" "${DAEMON_JSON}.bak.$(date +%s)"

    # Add userns-remap to existing JSON
    # This is a simple approach — for complex configs, use jq
    if command -v jq &>/dev/null; then
        jq --arg user "$REMAP_USER" '. + {"userns-remap": $user}' "$DAEMON_JSON" > "${DAEMON_JSON}.tmp"
        mv "${DAEMON_JSON}.tmp" "$DAEMON_JSON"
    else
        echo "WARNING: jq not installed. Please add \"userns-remap\": \"$REMAP_USER\" to $DAEMON_JSON manually."
        echo ""
        echo "Example $DAEMON_JSON:"
        echo '{'
        echo "  \"userns-remap\": \"$REMAP_USER\""
        echo '}'
        exit 1
    fi
else
    # Create new daemon.json
    printf '{\n  "userns-remap": "%s"\n}\n' "$REMAP_USER" > "$DAEMON_JSON"
fi

echo ""
echo "Updated $DAEMON_JSON:"
cat "$DAEMON_JSON"
echo ""
echo "========================================================================"
echo "IMPORTANT: Restart Docker for changes to take effect:"
echo "  sudo systemctl restart docker"
echo ""
echo "After restart:"
echo "  - All new containers will use remapped UIDs"
echo "  - Existing volumes may need ownership updates"
echo "  - Containers that need host UID access can use: --userns=host"
echo ""
echo "To verify:"
echo "  docker run --rm alpine id"
echo "  # Should show uid=0(root) inside container"
echo "  # But 'ps aux' on host shows the process running as UID 100000+"
echo "========================================================================"
