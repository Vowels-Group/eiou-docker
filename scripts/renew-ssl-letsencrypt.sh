#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC
#
# Let's Encrypt Certificate Renewal for eIOU Docker
#
# This script renews Let's Encrypt certificates and updates the shared
# certificate directory used by eIOU node containers.
#
# Designed to be run as a host-level cron job. Certbot only renews
# certificates that are within 30 days of expiry, so running daily is safe.
#
# Usage:
#   ./scripts/renew-ssl-letsencrypt.sh [options]
#
# Options:
#   -o, --output DIR        Certificate output directory (default: ./letsencrypt-certs)
#   -d, --domain DOMAIN     Domain name (for finding the right cert in /etc/letsencrypt/)
#   -r, --restart PATTERN   Docker container name/pattern to restart after renewal
#                           (default: no restart — containers pick up certs on next boot)
#   -g, --graceful          Send SIGHUP to containers instead of restarting
#   -s, --staging           Renew staging certificates
#   -h, --help              Show this help message
#
# Examples:
#   # Basic renewal (run from cron):
#   ./scripts/renew-ssl-letsencrypt.sh -d wallet.example.com -o ./letsencrypt-certs
#
#   # Renew and gracefully reload all eIOU containers:
#   ./scripts/renew-ssl-letsencrypt.sh -d wallet.example.com -o ./letsencrypt-certs \
#       --restart "eiou-*" --graceful
#
# Crontab entry (run daily at 3am):
#   0 3 * * * /path/to/eiou-docker/scripts/renew-ssl-letsencrypt.sh \
#       -d wallet.example.com -o /path/to/letsencrypt-certs >> /var/log/eiou-ssl-renew.log 2>&1

set -e

# Colors (disabled in non-interactive/cron mode)
if [ -t 1 ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    NC='\033[0m'
else
    RED=''
    GREEN=''
    YELLOW=''
    NC=''
fi

# Defaults
OUTPUT_DIR="./letsencrypt-certs"
DOMAIN=""
RESTART_PATTERN=""
GRACEFUL=false
STAGING=false

# Parse arguments
while [ $# -gt 0 ]; do
    case $1 in
        -o|--output) OUTPUT_DIR="$2"; shift 2 ;;
        -d|--domain) DOMAIN="$2"; shift 2 ;;
        -r|--restart) RESTART_PATTERN="$2"; shift 2 ;;
        -g|--graceful) GRACEFUL=true; shift ;;
        -s|--staging) STAGING=true; shift ;;
        -h|--help)
            sed -n '3,/^$/p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *) printf "${RED}Unknown option: %s${NC}\n" "$1"; exit 1 ;;
    esac
done

TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$TIMESTAMP] Let's Encrypt renewal check starting..."

# Validate
if [ -z "$DOMAIN" ]; then
    printf "${RED}Error: --domain is required${NC}\n"
    exit 1
fi

if ! command -v certbot >/dev/null 2>&1; then
    printf "${RED}Error: certbot is not installed${NC}\n"
    exit 1
fi

# Build renewal command
CERTBOT_ARGS="renew --non-interactive"

if [ "$STAGING" = true ]; then
    CERTBOT_ARGS="$CERTBOT_ARGS --staging"
fi

# Record cert modification time before renewal
CERT_PATH="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
if [ -f "$CERT_PATH" ]; then
    BEFORE_MTIME=$(stat -c %Y "$CERT_PATH" 2>/dev/null || stat -f %m "$CERT_PATH" 2>/dev/null)
else
    printf "${RED}Error: No existing certificate found for %s${NC}\n" "$DOMAIN"
    echo "Run create-ssl-letsencrypt.sh first to obtain a certificate."
    exit 1
fi

# Run certbot renew
echo "Running certbot renew..."
certbot $CERTBOT_ARGS 2>&1

# Check if cert was actually renewed (modification time changed)
AFTER_MTIME=$(stat -c %Y "$CERT_PATH" 2>/dev/null || stat -f %m "$CERT_PATH" 2>/dev/null)

if [ "$BEFORE_MTIME" != "$AFTER_MTIME" ]; then
    printf "${GREEN}Certificate was renewed!${NC}\n"
    echo "Updating output directory: ${OUTPUT_DIR}"

    # Copy renewed certs to the shared output directory
    mkdir -p "$OUTPUT_DIR"
    cp "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" "$OUTPUT_DIR/server.crt"
    cp "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" "$OUTPUT_DIR/server.key"
    cp "/etc/letsencrypt/live/${DOMAIN}/chain.pem" "$OUTPUT_DIR/ca-chain.crt"

    chmod 644 "$OUTPUT_DIR/server.crt"
    chmod 600 "$OUTPUT_DIR/server.key"
    chmod 644 "$OUTPUT_DIR/ca-chain.crt"

    echo "Certificate files updated in ${OUTPUT_DIR}/"

    # Restart/reload containers if requested
    if [ -n "$RESTART_PATTERN" ]; then
        echo "Notifying containers matching: ${RESTART_PATTERN}"

        # Get matching container IDs
        CONTAINERS=$(docker ps --filter "name=${RESTART_PATTERN}" --format "{{.Names}}" 2>/dev/null || true)

        if [ -z "$CONTAINERS" ]; then
            printf "${YELLOW}Warning: No running containers match '%s'${NC}\n" "$RESTART_PATTERN"
        else
            for CONTAINER in $CONTAINERS; do
                if [ "$GRACEFUL" = true ]; then
                    # Send SIGHUP for graceful reload — startup.sh traps this
                    echo "  Sending SIGHUP to ${CONTAINER}..."
                    docker kill --signal=SIGHUP "$CONTAINER" 2>/dev/null || true
                else
                    echo "  Restarting ${CONTAINER}..."
                    docker restart "$CONTAINER" 2>/dev/null || true
                fi
            done
            printf "${GREEN}All containers notified.${NC}\n"
        fi
    fi
else
    echo "Certificate not yet due for renewal (valid > 30 days). No action taken."
fi

TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
echo "[$TIMESTAMP] Renewal check complete."
