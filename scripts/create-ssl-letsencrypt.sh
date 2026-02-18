#!/bin/sh
# Copyright 2025-2026 Vowels Group, LLC
#
# Let's Encrypt Certificate Generator for EIOU Docker
#
# This script obtains a Let's Encrypt SSL certificate that can be shared across
# multiple EIOU node containers. It supports both single-domain and wildcard
# certificates using HTTP-01 or DNS-01 ACME challenges.
#
# Use Cases:
#   - Single node with a real domain (HTTP-01, simplest)
#   - Multiple nodes on one server sharing one cert (same domain, different ports)
#   - Multiple nodes with individual subdomains (DNS-01 wildcard)
#
# Usage:
#   ./scripts/create-ssl-letsencrypt.sh [options]
#
# Options:
#   -d, --domain DOMAIN     Domain to obtain cert for (required)
#   -e, --email EMAIL       Email for Let's Encrypt notifications (required)
#   -o, --output DIR        Output directory (default: ./letsencrypt-certs)
#   -w, --wildcard          Request wildcard cert (*.domain) via DNS-01
#   -p, --dns-plugin NAME   DNS plugin for wildcard certs (e.g., cloudflare, route53)
#   -c, --credentials FILE  DNS plugin credentials file
#   -s, --staging           Use staging server (for testing, avoids rate limits)
#   -h, --help              Show this help message
#
# Examples:
#   # Single domain (HTTP-01 — port 80 must be reachable):
#   ./scripts/create-ssl-letsencrypt.sh -d wallet.eiou.org -e admin@eiou.org
#
#   # Same domain for 150 nodes on different ports (same command — one cert covers all ports):
#   ./scripts/create-ssl-letsencrypt.sh -d wallet.eiou.org -e admin@eiou.org
#
#   # Wildcard cert for subdomains (DNS-01 — no port needed):
#   ./scripts/create-ssl-letsencrypt.sh -d eiou.org -e admin@eiou.org \
#       --wildcard --dns-plugin cloudflare --credentials ./cloudflare.ini
#
#   # Test with staging server first (recommended):
#   ./scripts/create-ssl-letsencrypt.sh -d wallet.eiou.org -e admin@eiou.org --staging
#
# After obtaining the certificate, mount in docker-compose:
#   volumes:
#     - ./letsencrypt-certs:/ssl-certs:ro

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Defaults
DOMAIN=""
EMAIL=""
OUTPUT_DIR="./letsencrypt-certs"
WILDCARD=false
DNS_PLUGIN=""
CREDENTIALS_FILE=""
STAGING=false

# Parse arguments
while [ $# -gt 0 ]; do
    case $1 in
        -d|--domain) DOMAIN="$2"; shift 2 ;;
        -e|--email) EMAIL="$2"; shift 2 ;;
        -o|--output) OUTPUT_DIR="$2"; shift 2 ;;
        -w|--wildcard) WILDCARD=true; shift ;;
        -p|--dns-plugin) DNS_PLUGIN="$2"; shift 2 ;;
        -c|--credentials) CREDENTIALS_FILE="$2"; shift 2 ;;
        -s|--staging) STAGING=true; shift ;;
        -h|--help)
            sed -n '3,/^$/p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *) printf "${RED}Unknown option: %s${NC}\n" "$1"; exit 1 ;;
    esac
done

printf "${GREEN}================================================${NC}\n"
printf "${GREEN}  EIOU Let's Encrypt Certificate Setup${NC}\n"
printf "${GREEN}================================================${NC}\n"
echo ""

# Validate required arguments
if [ -z "$DOMAIN" ]; then
    printf "${RED}Error: --domain is required${NC}\n"
    echo "Usage: $0 -d example.com -e admin@example.com [options]"
    exit 1
fi

if [ -z "$EMAIL" ]; then
    printf "${RED}Error: --email is required${NC}\n"
    echo "Usage: $0 -d example.com -e admin@example.com [options]"
    exit 1
fi

# Check certbot is installed
if ! command -v certbot >/dev/null 2>&1; then
    printf "${RED}Error: certbot is not installed.${NC}\n"
    echo ""
    echo "Install certbot:"
    echo "  Ubuntu/Debian: sudo apt install certbot"
    echo "  macOS:         brew install certbot"
    echo "  RHEL/CentOS:   sudo dnf install certbot"

    if [ "$WILDCARD" = true ] && [ -n "$DNS_PLUGIN" ]; then
        echo ""
        echo "For DNS-01 wildcard, also install the DNS plugin:"
        echo "  sudo apt install python3-certbot-dns-${DNS_PLUGIN}"
    fi
    exit 1
fi

# Wildcard certs require DNS-01 challenge
if [ "$WILDCARD" = true ]; then
    if [ -z "$DNS_PLUGIN" ]; then
        printf "${RED}Error: --dns-plugin is required for wildcard certificates.${NC}\n"
        echo ""
        echo "Wildcard certificates require DNS-01 validation."
        echo "Supported DNS plugins: cloudflare, route53, digitalocean, google, ovh, linode"
        echo ""
        echo "Example:"
        echo "  $0 -d example.com -e admin@example.com --wildcard --dns-plugin cloudflare --credentials ./cloudflare.ini"
        exit 1
    fi

    # Check DNS plugin is installed
    if ! certbot plugins 2>/dev/null | grep -q "dns-${DNS_PLUGIN}"; then
        printf "${YELLOW}Warning: DNS plugin 'dns-%s' may not be installed.${NC}\n" "$DNS_PLUGIN"
        echo "Install with: sudo apt install python3-certbot-dns-${DNS_PLUGIN}"
        echo "  — or: pip install certbot-dns-${DNS_PLUGIN}"
        echo ""
        echo "Attempting anyway..."
    fi

    if [ -z "$CREDENTIALS_FILE" ]; then
        printf "${RED}Error: --credentials is required for DNS-01 challenges.${NC}\n"
        echo ""
        echo "Create a credentials file for your DNS provider."
        echo ""
        case "$DNS_PLUGIN" in
            cloudflare)
                echo "For Cloudflare, create a file with:"
                echo "  dns_cloudflare_api_token = YOUR_API_TOKEN"
                echo ""
                echo "  chmod 600 cloudflare.ini"
                ;;
            route53)
                echo "For Route53, configure AWS credentials in ~/.aws/credentials"
                echo "or set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY"
                ;;
            digitalocean)
                echo "For DigitalOcean, create a file with:"
                echo "  dns_digitalocean_token = YOUR_API_TOKEN"
                ;;
            *)
                echo "See certbot docs for ${DNS_PLUGIN} credentials format:"
                echo "  https://certbot-dns-${DNS_PLUGIN}.readthedocs.io/"
                ;;
        esac
        exit 1
    fi

    if [ ! -f "$CREDENTIALS_FILE" ]; then
        printf "${RED}Error: Credentials file not found: %s${NC}\n" "$CREDENTIALS_FILE"
        exit 1
    fi
fi

# Build certbot command
CERTBOT_ARGS="certonly --non-interactive --agree-tos --email $EMAIL"

if [ "$STAGING" = true ]; then
    CERTBOT_ARGS="$CERTBOT_ARGS --staging"
    printf "${YELLOW}Using Let's Encrypt STAGING server (certificates will NOT be browser-trusted)${NC}\n"
    printf "${YELLOW}Remove --staging once you've verified everything works.${NC}\n"
    echo ""
fi

if [ "$WILDCARD" = true ]; then
    # DNS-01 challenge for wildcard
    CERTBOT_ARGS="$CERTBOT_ARGS --preferred-challenges dns"
    CERTBOT_ARGS="$CERTBOT_ARGS --authenticator dns-${DNS_PLUGIN}"
    CERTBOT_ARGS="$CERTBOT_ARGS --dns-${DNS_PLUGIN}-credentials $CREDENTIALS_FILE"
    CERTBOT_ARGS="$CERTBOT_ARGS -d *.${DOMAIN} -d ${DOMAIN}"
    echo "Requesting wildcard certificate: *.${DOMAIN}"
    echo "Challenge: DNS-01 via ${DNS_PLUGIN}"
else
    # HTTP-01 challenge for single domain
    CERTBOT_ARGS="$CERTBOT_ARGS --standalone"
    CERTBOT_ARGS="$CERTBOT_ARGS -d ${DOMAIN}"
    echo "Requesting certificate: ${DOMAIN}"
    echo "Challenge: HTTP-01 (port 80 must be reachable from the internet)"
fi

echo "Email: ${EMAIL}"
echo ""

# Run certbot
printf "${GREEN}[1/2]${NC} Obtaining certificate from Let's Encrypt...\n"
if ! certbot $CERTBOT_ARGS 2>&1; then
    echo ""
    printf "${RED}Certificate request failed.${NC}\n"
    echo ""
    if [ "$WILDCARD" = true ]; then
        echo "Troubleshooting DNS-01:"
        echo "  - Verify your DNS API credentials are correct"
        echo "  - Ensure the credentials file has restricted permissions (chmod 600)"
        echo "  - Check that the DNS plugin can create TXT records for $DOMAIN"
    else
        echo "Troubleshooting HTTP-01:"
        echo "  - Ensure port 80 is open and reachable from the internet"
        echo "  - Verify $DOMAIN resolves to this server's public IP"
        echo "  - Check no other service is using port 80"
        echo "  - Try --staging first to avoid rate limits"
    fi
    exit 1
fi

echo ""
printf "${GREEN}[2/2]${NC} Copying certificates to output directory...\n"

# Determine the live cert path
CERT_PATH="/etc/letsencrypt/live/${DOMAIN}"

if [ ! -d "$CERT_PATH" ]; then
    printf "${RED}Error: Certificate directory not found at %s${NC}\n" "$CERT_PATH"
    echo "Check /etc/letsencrypt/live/ for the correct directory name."
    exit 1
fi

# Create output directory and copy certs in the format startup.sh expects
mkdir -p "$OUTPUT_DIR"
cp "$CERT_PATH/fullchain.pem" "$OUTPUT_DIR/server.crt"
cp "$CERT_PATH/privkey.pem" "$OUTPUT_DIR/server.key"
cp "$CERT_PATH/chain.pem" "$OUTPUT_DIR/ca-chain.crt"

chmod 644 "$OUTPUT_DIR/server.crt"
chmod 600 "$OUTPUT_DIR/server.key"
chmod 644 "$OUTPUT_DIR/ca-chain.crt"

echo ""
printf "${GREEN}================================================${NC}\n"
printf "${GREEN}  Certificate Obtained Successfully!${NC}\n"
printf "${GREEN}================================================${NC}\n"
echo ""
echo "Files created in ${OUTPUT_DIR}/:"
echo "  - server.crt   (fullchain certificate)"
echo "  - server.key   (private key)"
echo "  - ca-chain.crt (certificate chain)"
echo ""
if [ "$WILDCARD" = true ]; then
    printf "${CYAN}Wildcard certificate covers:${NC}\n"
    echo "  - ${DOMAIN}"
    echo "  - *.${DOMAIN} (any subdomain)"
    echo ""
    echo "Example: alice.${DOMAIN}, bob.${DOMAIN}, node42.${DOMAIN}"
    echo "Each subdomain can run on any port (e.g., :1154, :1155, etc.)"
fi
echo ""
printf "${YELLOW}Next Steps:${NC}\n"
echo ""
echo "1. Mount in docker-compose for all nodes:"
echo ""
echo "   volumes:"
echo "     - ${OUTPUT_DIR}:/ssl-certs:ro"
echo ""
echo "   Example for multi-node:"
echo "   services:"
echo "     node-1:"
echo "       ports: [\"1153:443\"]"
echo "       environment:"
if [ "$WILDCARD" = true ]; then
    echo "         - QUICKSTART=alice.${DOMAIN}"
else
    echo "         - QUICKSTART=${DOMAIN}"
    echo "         - EIOU_PORT=1153"
fi
echo "       volumes:"
echo "         - ${OUTPUT_DIR}:/ssl-certs:ro"
echo ""
echo "2. Set up automatic renewal:"
echo "   ./scripts/renew-ssl-letsencrypt.sh -d ${DOMAIN} -o ${OUTPUT_DIR}"
echo "   # Add to crontab: 0 3 * * * /path/to/renew-ssl-letsencrypt.sh ..."
echo ""
printf "${YELLOW}Certificate expires in 90 days. Set up renewal!${NC}\n"
echo ""
