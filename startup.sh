#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# =============================================================================
# startup.sh - eIOU Node Container Entrypoint
# =============================================================================
# This script initializes and runs an eIOU node container.
#
# Startup Sequence:
#   1. Configure output buffering for real-time logging
#   2. Register signal handlers for graceful shutdown (SIGTERM, SIGINT, SIGHUP)
#   3. Generate or install SSL certificates
#   4. Start services (cron, tor, nginx, php-fpm, mariadb)
#   5. Wait for MariaDB readiness
#   6. Generate wallet or restore from seed phrase
#   7. Restart Tor to load hidden service keys
#   8. Validate message processing prerequisites
#   9. Wait for Tor connectivity (if enabled)
#   10. Start background message processors
#   11. Start watchdog for process monitoring
#   12. Keep container running while handling signals
#
# Environment Variables:
#   QUICKSTART          - Hostname for quickstart mode (e.g., "alice", "192.168.1.100:8080")
#   RESTORE             - 24-word seed phrase for wallet restoration
#   RESTORE_FILE        - Path to file containing seed phrase (more secure)
#   SSL_DOMAIN          - Primary domain for SSL certificate CN
#   SSL_EXTRA_SANS      - Additional Subject Alternative Names for SSL
#   LETSENCRYPT_EMAIL   - Email for Let's Encrypt (enables automatic trusted certs)
#   LETSENCRYPT_DOMAIN  - Domain for Let's Encrypt cert (default: SSL_DOMAIN)
#   LETSENCRYPT_STAGING - Use staging server for testing (default: false)
#   EIOU_HS_TIMEOUT     - Tor hidden service timeout in seconds (default: 60)
#   EIOU_TOR_TIMEOUT    - Tor connectivity timeout in seconds (default: 120)
#   EIOU_NAME           - Display name for the node (shown in local UI)
#   EIOU_HOST           - Externally reachable address (IP or domain, optional :port)
#   EIOU_PORT           - Port for HTTP/HTTPS URLs (appended to addresses)
#   EIOU_TEST_MODE      - Enable test mode for manual message processing
#   EIOU_CONTACT_STATUS_ENABLED - Enable contact status ping feature
#
# Service Tuning (applied at boot, no volume mount needed):
#   NGINX_WORKER_PROCESSES   - Nginx worker threads (default: 2, match to CPU allocation)
#   NGINX_WORKER_CONNECTIONS - Max connections per worker (default: 768)
#   NGINX_CLIENT_MAX_BODY    - Max request body size (default: 10m)
#   NGINX_RATE_LIMIT_GENERAL - General rate limit (default: 30r/s)
#   NGINX_RATE_LIMIT_API     - API rate limit (default: 10r/s)
#   NGINX_RATE_LIMIT_P2P     - P2P rate limit (default: 20r/s)
#   NGINX_CONN_LIMIT         - Max concurrent connections per IP (default: 50)
#   PHP_FPM_PM               - Process manager mode: ondemand|dynamic|static (default: ondemand)
#   PHP_FPM_MAX_CHILDREN     - Max PHP worker processes (default: 5, ~20-30MB RAM each)
#   PHP_FPM_START_SERVERS    - Initial workers on startup (dynamic mode only, default: 2)
#   PHP_FPM_MIN_SPARE        - Min idle workers (dynamic mode only, default: 1)
#   PHP_FPM_MAX_SPARE        - Max idle workers (dynamic mode only, default: 3)
#   PHP_FPM_IDLE_TIMEOUT     - Kill idle workers after N seconds (ondemand only, default: 10s)
#   PHP_FPM_MAX_REQUESTS     - Recycle worker after N requests to prevent memory leaks (default: 0/unlimited)
#
# =============================================================================

# Enable unbuffered output for real-time docker logs
exec 1> >(stdbuf -oL cat)
exec 2> >(stdbuf -oL cat >&2)

# Shell safety: catch undefined variables and pipe failures
set -u
set -o pipefail

# Source the banner script for warning messages
# This file can be edited to update the warning banners without modifying startup.sh
if [ -f "/app/scripts/banner.sh" ]; then
    source /app/scripts/banner.sh
    # Display alpha/testing warning + legal notice at container start
    show_startup_warnings
fi

# Detect PHP-FPM service name (version-independent, e.g., php8.2-fpm)
PHP_FPM_SERVICE=$(basename /etc/init.d/php*-fpm 2>/dev/null || echo "php-fpm")

# Graceful shutdown configuration
SHUTDOWN_TIMEOUT="${EIOU_SHUTDOWN_TIMEOUT:-30}"  # Configurable via env, default 30s
SHUTDOWN_IN_PROGRESS=false
SHUTDOWN_FLAG="/tmp/eiou_shutdown.flag"  # Shared with PHP (ApiController, Application)
MAINTENANCE_LOCKFILE="/tmp/eiou_maintenance.lock"  # Must be set before signal handler can fire

# Store background process PIDs
P2P_PID=""
TRANSACTION_PID=""
CLEANUP_PID=""
CONTACT_STATUS_PID=""
WATCHDOG_PID=""

# -----------------------------------------------------------------------------
# graceful_shutdown() - Signal handler for clean container termination
# -----------------------------------------------------------------------------
# Called on SIGTERM, SIGINT, or SIGHUP signals. Performs ordered shutdown:
#   1. Stop watchdog to prevent process respawning during shutdown
#   2. Send SIGTERM to PHP message processors and wait for completion
#   3. Force kill any processors that exceed timeout (SHUTDOWN_TIMEOUT)
#   4. Stop services in reverse startup order (nginx, php-fpm, mariadb, tor, cron)
#   5. Clean up lockfiles to prevent stale state on next startup
# -----------------------------------------------------------------------------
graceful_shutdown() {
    # Prevent duplicate shutdown handling
    if [ "$SHUTDOWN_IN_PROGRESS" = true ]; then
        return
    fi
    SHUTDOWN_IN_PROGRESS=true

    # Set shutdown flag to prevent watchdog from restarting processes
    touch "$SHUTDOWN_FLAG" 2>/dev/null || true

    # Stop watchdog first to prevent it from restarting processes during shutdown
    if [ -n "$WATCHDOG_PID" ] && kill -0 "$WATCHDOG_PID" 2>/dev/null; then
        echo "[Shutdown] Stopping watchdog (PID: $WATCHDOG_PID)"
        kill "$WATCHDOG_PID" 2>/dev/null
        wait "$WATCHDOG_PID" 2>/dev/null || true
    fi

    echo ""
    echo "=========================================="
    echo "Graceful shutdown initiated..."
    echo "=========================================="

    SHUTDOWN_START=$(date +%s)

    # Step 0: Create automatic pre-shutdown backup (for safe upgrades)
    # Always runs regardless of EIOU_BACKUP_AUTO_ENABLED — this is a safety
    # backup for upgrades, not a scheduled backup. Uses BackupService directly
    # instead of backup-cron.php (which skips when auto-backup is disabled).
    # Cleanup runs after to maintain the max backup count.
    if mysqladmin ping -h localhost --silent 2>/dev/null; then
        echo "[Shutdown] Creating automatic pre-shutdown backup..."
        if timeout 20 runuser -u www-data -- php -r '
            chdir("/app/eiou");
            require_once "/app/eiou/src/bootstrap.php";
            $app = \Eiou\Core\Application::getInstance();
            $bs = $app->services->getBackupService();
            $r = $bs->createBackup();
            if ($r["success"]) {
                echo "Backup created: " . $r["filename"] . "\n";
                $bs->cleanupOldBackups();
            } else {
                echo "Backup failed: " . ($r["error"] ?? "unknown") . "\n";
                exit(1);
            }
        ' 2>/dev/null; then
            echo "[Shutdown] Pre-shutdown backup created successfully"
        else
            echo "[Shutdown] Pre-shutdown backup failed (non-critical, continuing shutdown)"
        fi
    fi

    # Step 1: Signal PHP processors to stop (they handle SIGTERM gracefully)
    echo "[Shutdown] Stopping PHP message processors..."

    # Send SIGTERM to PHP processes if they exist
    local pids_to_wait=""

    if [ -n "$P2P_PID" ] && kill -0 "$P2P_PID" 2>/dev/null; then
        echo "[Shutdown] Sending SIGTERM to P2P processor (PID: $P2P_PID)"
        kill -TERM "$P2P_PID" 2>/dev/null
        pids_to_wait="$pids_to_wait $P2P_PID"
    fi

    if [ -n "$TRANSACTION_PID" ] && kill -0 "$TRANSACTION_PID" 2>/dev/null; then
        echo "[Shutdown] Sending SIGTERM to Transaction processor (PID: $TRANSACTION_PID)"
        kill -TERM "$TRANSACTION_PID" 2>/dev/null
        pids_to_wait="$pids_to_wait $TRANSACTION_PID"
    fi

    if [ -n "$CLEANUP_PID" ] && kill -0 "$CLEANUP_PID" 2>/dev/null; then
        echo "[Shutdown] Sending SIGTERM to Cleanup processor (PID: $CLEANUP_PID)"
        kill -TERM "$CLEANUP_PID" 2>/dev/null
        pids_to_wait="$pids_to_wait $CLEANUP_PID"
    fi

    if [ -n "$CONTACT_STATUS_PID" ] && kill -0 "$CONTACT_STATUS_PID" 2>/dev/null; then
        echo "[Shutdown] Sending SIGTERM to ContactStatus processor (PID: $CONTACT_STATUS_PID)"
        kill -TERM "$CONTACT_STATUS_PID" 2>/dev/null
        pids_to_wait="$pids_to_wait $CONTACT_STATUS_PID"
    fi

    # Also check for any PHP processors by their lockfiles
    for lockfile in /tmp/p2pmessages_lock.pid /tmp/transactionmessages_lock.pid /tmp/cleanupmessages_lock.pid /tmp/contact_status.pid; do
        if [ -f "$lockfile" ]; then
            local pid=$(cat "$lockfile" 2>/dev/null)
            if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
                echo "[Shutdown] Found additional processor from lockfile (PID: $pid)"
                kill -TERM "$pid" 2>/dev/null
                pids_to_wait="$pids_to_wait $pid"
            fi
        fi
    done

    # Step 2: Wait for PHP processes to finish their current tasks
    if [ -n "$pids_to_wait" ]; then
        echo "[Shutdown] Waiting for PHP processors to complete current tasks (timeout: ${SHUTDOWN_TIMEOUT}s)..."
        local wait_start=$(date +%s)
        local all_stopped=false

        while [ $(($(date +%s) - wait_start)) -lt $SHUTDOWN_TIMEOUT ]; do
            all_stopped=true
            for pid in $pids_to_wait; do
                if kill -0 "$pid" 2>/dev/null; then
                    all_stopped=false
                    break
                fi
            done

            if [ "$all_stopped" = true ]; then
                echo "[Shutdown] All PHP processors stopped gracefully"
                break
            fi
            sleep 1
        done

        # Force kill any remaining processes
        if [ "$all_stopped" = false ]; then
            echo "[Shutdown] Timeout reached. Force killing remaining processes..."
            for pid in $pids_to_wait; do
                if kill -0 "$pid" 2>/dev/null; then
                    echo "[Shutdown] Force killing PID: $pid"
                    kill -9 "$pid" 2>/dev/null
                fi
            done
        fi

        # Reap background jobs to suppress shell "Killed" messages
        for pid in $pids_to_wait; do
            wait "$pid" 2>/dev/null || true
        done
    else
        echo "[Shutdown] No PHP processors were running"
    fi

    # Step 3: Stop services in reverse order of startup
    # Each service stop is wrapped in a timeout to ensure the entire shutdown
    # completes within Docker's stop_grace_period (45s). The PHP processor
    # shutdown above may have used up to 30s, so service stops must be quick.
    echo "[Shutdown] Stopping services in reverse order..."

    echo "[Shutdown] Stopping nginx..."
    timeout 5 nginx -s quit 2>/dev/null || timeout 3 service nginx stop 2>/dev/null || true

    echo "[Shutdown] Stopping PHP-FPM..."
    timeout 5 service "$PHP_FPM_SERVICE" stop 2>/dev/null || true

    echo "[Shutdown] Stopping MariaDB..."
    timeout 5 service mariadb stop 2>/dev/null || true

    # Tor's init script may report "failed" with a PID mismatch warning —
    # this is a known quirk of the Tor service script and is harmless.
    echo "[Shutdown] Stopping Tor..."
    timeout 3 service tor stop 2>/dev/null || true

    echo "[Shutdown] Stopping Cron..."
    timeout 2 service cron stop 2>/dev/null || true

    # Step 4: Clean up lockfiles and shutdown flag
    echo "[Shutdown] Cleaning up lockfiles..."
    rm -f /tmp/p2pmessages_lock.pid 2>/dev/null
    rm -f /tmp/transactionmessages_lock.pid 2>/dev/null
    rm -f /tmp/cleanupmessages_lock.pid 2>/dev/null
    rm -f /tmp/contact_status.pid 2>/dev/null
    rm -f "$SHUTDOWN_FLAG" 2>/dev/null
    rm -f "$MAINTENANCE_LOCKFILE" 2>/dev/null

    SHUTDOWN_END=$(date +%s)
    SHUTDOWN_DURATION=$((SHUTDOWN_END - SHUTDOWN_START))

    echo "=========================================="
    echo "Graceful shutdown completed in ${SHUTDOWN_DURATION}s"
    echo "=========================================="

    exit 0
}

# Register signal handlers for graceful shutdown
trap graceful_shutdown SIGTERM SIGINT SIGHUP

# Check for quickstart flag
QUICKSTART=${QUICKSTART:-false}

# New optional env vars for address/name separation (issue #579)
# These allow separating the node's display name from its network address.
# When omitted, QUICKSTART provides backward-compatible behavior.
EIOU_NAME=${EIOU_NAME:-false}
EIOU_HOST=${EIOU_HOST:-false}
EIOU_PORT=${EIOU_PORT:-false}

# Validate environment variable values to prevent injection or naming errors.
# Hostnames: alphanumeric, dots, hyphens, and optional :port (valid DNS + IP:port).
# Names: alphanumeric, spaces, hyphens, underscores, and dots only.
# Port: numeric only.
validate_hostname() {
    local val="$1" varname="$2"
    if [ "$val" != "false" ] && ! echo "$val" | grep -qE '^[a-zA-Z0-9._-]+(:[0-9]+)?$'; then
        echo "ERROR: $varname contains invalid characters: '$val'"
        echo "       Only alphanumeric characters, dots, hyphens, underscores, and optional :port are allowed."
        exit 1
    fi
}
validate_name() {
    local val="$1" varname="$2"
    if [ "$val" != "false" ] && ! echo "$val" | grep -qE '^[a-zA-Z0-9 ._-]+$'; then
        echo "ERROR: $varname contains invalid characters: '$val'"
        echo "       Only alphanumeric characters, spaces, dots, hyphens, and underscores are allowed."
        exit 1
    fi
}

validate_hostname "$QUICKSTART" "QUICKSTART"
validate_hostname "$EIOU_HOST" "EIOU_HOST"
validate_name "$EIOU_NAME" "EIOU_NAME"
if [ "$EIOU_PORT" != "false" ] && ! echo "$EIOU_PORT" | grep -qE '^[0-9]+$'; then
    echo "ERROR: EIOU_PORT must be numeric, got: '$EIOU_PORT'"
    exit 1
fi

# Extract embedded port from QUICKSTART or EIOU_HOST (e.g., "192.168.1.100:8080")
# The embedded port is used when EIOU_PORT is not explicitly set.
extract_host_port() {
    local val="$1"
    if echo "$val" | grep -qE ':[0-9]+$'; then
        echo "${val##*:}"
    fi
}

if [ "$QUICKSTART" != "false" ] && echo "$QUICKSTART" | grep -qE ':[0-9]+$'; then
    QUICKSTART_PORT=$(extract_host_port "$QUICKSTART")
    QUICKSTART="${QUICKSTART%:*}"
fi
if [ "$EIOU_HOST" != "false" ] && echo "$EIOU_HOST" | grep -qE ':[0-9]+$'; then
    EIOU_HOST_PORT=$(extract_host_port "$EIOU_HOST")
    EIOU_HOST="${EIOU_HOST%:*}"
fi

# Resolve the effective address host:
# Priority: EIOU_HOST > QUICKSTART (for address construction)
if [ "$EIOU_HOST" != "false" ]; then
    EFFECTIVE_HOST="$EIOU_HOST"
elif [ "$QUICKSTART" != "false" ]; then
    EFFECTIVE_HOST="$QUICKSTART"
else
    EFFECTIVE_HOST="false"
fi

# Resolve the effective port:
# Priority: EIOU_PORT (explicit) > embedded port from EIOU_HOST > embedded port from QUICKSTART
if [ "$EIOU_PORT" = "false" ]; then
    if [ -n "${EIOU_HOST_PORT:-}" ]; then
        EIOU_PORT="$EIOU_HOST_PORT"
    elif [ -n "${QUICKSTART_PORT:-}" ]; then
        EIOU_PORT="$QUICKSTART_PORT"
    fi
fi

# Resolve the effective display name:
# Priority: EIOU_NAME > QUICKSTART
if [ "$EIOU_NAME" != "false" ]; then
    EFFECTIVE_NAME="$EIOU_NAME"
elif [ "$QUICKSTART" != "false" ]; then
    EFFECTIVE_NAME="$QUICKSTART"
else
    EFFECTIVE_NAME="false"
fi

# Build the full address URL with optional port
if [ "$EFFECTIVE_HOST" != "false" ]; then
    if [ "$EIOU_PORT" != "false" ]; then
        EFFECTIVE_URL="https://${EFFECTIVE_HOST}:${EIOU_PORT}"
    else
        EFFECTIVE_URL="https://${EFFECTIVE_HOST}"
    fi
else
    EFFECTIVE_URL="false"
fi

# Check for restore flag (24-word seed phrase)
# Two methods are available for wallet restoration:
#
# Method 1 - RESTORE_FILE (RECOMMENDED - Most Secure):
#   Mount a file containing the seedphrase and provide the path:
#   docker run -v /host/path/seedphrase.txt:/restore/seed:ro -e RESTORE_FILE=/restore/seed ...
#   Benefits: Seedphrase never appears in container environment or docker inspect
#
# Method 2 - RESTORE (Convenient but less secure):
#   Pass seedphrase directly via environment variable:
#   docker run -e RESTORE="word1 word2 ... word24" ...
#   Note: Seedphrase visible in docker inspect and container environment
#
RESTORE_FILE=${RESTORE_FILE:-false}
RESTORE=${RESTORE:-false}

# =============================================================================
# VOLUME ENCRYPTION KEY HANDLING
# =============================================================================
# EIOU_VOLUME_KEY or EIOU_VOLUME_KEY_FILE protects all volume data at rest.
# When set, the master encryption key is encrypted with a passphrase-derived
# key using Argon2id. The host server cannot read the master key from the
# Docker volume without this passphrase.
#
# EIOU_VOLUME_KEY_FILE (recommended): Path to a file containing the passphrase
# EIOU_VOLUME_KEY: Passphrase as environment variable (less secure)
# =============================================================================
EIOU_VOLUME_KEY=${EIOU_VOLUME_KEY:-}
EIOU_VOLUME_KEY_FILE=${EIOU_VOLUME_KEY_FILE:-}

if [ -n "$EIOU_VOLUME_KEY_FILE" ]; then
    # File-based volume key (recommended — not visible in docker inspect)
    if [ ! -f "$EIOU_VOLUME_KEY_FILE" ]; then
        echo "ERROR: Volume key file not found at $EIOU_VOLUME_KEY_FILE"
        exit 1
    fi
    if [ ! -r "$EIOU_VOLUME_KEY_FILE" ]; then
        echo "ERROR: Cannot read volume key file at $EIOU_VOLUME_KEY_FILE"
        exit 1
    fi
    # Store in /dev/shm (RAM-backed) for PHP access, then clear from env
    cat "$EIOU_VOLUME_KEY_FILE" > /dev/shm/.volume_key
    chmod 600 /dev/shm/.volume_key
    echo "Volume encryption key loaded from file."
elif [ -n "$EIOU_VOLUME_KEY" ]; then
    # Environment variable volume key (convenient but visible in docker inspect)
    printf '%s' "$EIOU_VOLUME_KEY" > /dev/shm/.volume_key
    chmod 600 /dev/shm/.volume_key
    # Clear from shell environment (does not clear /proc/<pid>/environ)
    unset EIOU_VOLUME_KEY
    export EIOU_VOLUME_KEY=""
    echo "Volume encryption key loaded from environment."
    echo "NOTE: For better security, use EIOU_VOLUME_KEY_FILE instead."
fi

# =============================================================================
# SSL CERTIFICATE SETUP
# =============================================================================
# Environment Variables:
#   SSL_DOMAIN          - Primary domain for certificate CN (default: QUICKSTART value or localhost)
#   SSL_EXTRA_SANS      - Additional SANs in format "DNS:name,IP:addr" (comma-separated)
#   LETSENCRYPT_EMAIL   - Email for Let's Encrypt registration (enables automatic LE certs)
#   LETSENCRYPT_DOMAIN  - Domain for Let's Encrypt cert (default: SSL_DOMAIN)
#   LETSENCRYPT_STAGING - Use Let's Encrypt staging server for testing (default: false)
#
# External Certificate Support:
#   Mount certificates at /ssl-certs/ with files: server.crt, server.key, ca-chain.crt (optional)
#
# CA-Signed Certificate Support:
#   Mount CA at /ssl-ca/ with files: ca.crt, ca.key
#
# Priority: 1. External (/ssl-certs/) 2. Let's Encrypt 3. CA-signed (/ssl-ca/) 4. Self-signed
# =============================================================================

SSL_CERT_INSTALLED=false

# --- Priority 1: Externally provided certificates ---
if [ -f /ssl-certs/server.crt ] && [ -f /ssl-certs/server.key ]; then
    echo "Installing externally provided SSL certificates..."

    cp /ssl-certs/server.crt /etc/nginx/ssl/server.crt
    cp /ssl-certs/server.key /etc/nginx/ssl/server.key
    chmod 600 /etc/nginx/ssl/server.key
    chmod 644 /etc/nginx/ssl/server.crt

    # Handle certificate chain if provided (for Let's Encrypt fullchain, etc.)
    if [ -f /ssl-certs/ca-chain.crt ]; then
        cp /ssl-certs/ca-chain.crt /etc/nginx/ssl/ca-chain.crt
        chmod 644 /etc/nginx/ssl/ca-chain.crt
        echo "  Certificate chain installed."
    fi

    echo "External SSL certificates installed successfully."
    SSL_CERT_INSTALLED=true
fi

# --- Priority 2: Let's Encrypt automatic certificate ---
# Requires LETSENCRYPT_EMAIL to be set and a valid FQDN domain.
# Uses HTTP-01 challenge via certbot standalone mode (port 80 must be reachable).
# Certs persist in /etc/letsencrypt/ volume across container restarts.
if [ "$SSL_CERT_INSTALLED" = "false" ] && [ -n "${LETSENCRYPT_EMAIL:-}" ]; then
    LE_DOMAIN="${LETSENCRYPT_DOMAIN:-${SSL_DOMAIN:-${EFFECTIVE_HOST:-}}}"

    # Strip port from domain (e.g. "example.com:1154" → "example.com")
    LE_DOMAIN=$(echo "$LE_DOMAIN" | sed 's/:[0-9]*$//')

    # Validate domain: must be a real FQDN (not IP, localhost, or bare container name)
    if echo "$LE_DOMAIN" | grep -qE '^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$'; then
        echo "Let's Encrypt: Checking certificate for $LE_DOMAIN..."

        # Check if a valid (not-yet-expired) cert already exists from a previous run
        if [ -f "/etc/letsencrypt/live/$LE_DOMAIN/fullchain.pem" ] && \
           [ -f "/etc/letsencrypt/live/$LE_DOMAIN/privkey.pem" ] && \
           openssl x509 -checkend 86400 -noout -in "/etc/letsencrypt/live/$LE_DOMAIN/fullchain.pem" 2>/dev/null; then

            echo "  Existing Let's Encrypt certificate is still valid."
            cp "/etc/letsencrypt/live/$LE_DOMAIN/fullchain.pem" /etc/nginx/ssl/server.crt
            cp "/etc/letsencrypt/live/$LE_DOMAIN/privkey.pem" /etc/nginx/ssl/server.key
            chmod 600 /etc/nginx/ssl/server.key
            chmod 644 /etc/nginx/ssl/server.crt
            SSL_CERT_INSTALLED=true
        else
            # Request new certificate using certbot standalone mode
            # Standalone runs its own HTTP server on port 80 (nginx hasn't started yet)
            echo "  Requesting certificate from Let's Encrypt..."
            CERTBOT_CMD="certbot certonly --standalone --non-interactive --agree-tos"
            CERTBOT_CMD="$CERTBOT_CMD --email $LETSENCRYPT_EMAIL -d $LE_DOMAIN"

            if [ "${LETSENCRYPT_STAGING:-false}" = "true" ]; then
                CERTBOT_CMD="$CERTBOT_CMD --staging"
                echo "  (Using staging server — certificate will NOT be browser-trusted)"
            fi

            if $CERTBOT_CMD 2>&1; then
                echo "  Let's Encrypt certificate obtained successfully for $LE_DOMAIN"
                cp "/etc/letsencrypt/live/$LE_DOMAIN/fullchain.pem" /etc/nginx/ssl/server.crt
                cp "/etc/letsencrypt/live/$LE_DOMAIN/privkey.pem" /etc/nginx/ssl/server.key
                chmod 600 /etc/nginx/ssl/server.key
                chmod 644 /etc/nginx/ssl/server.crt
                SSL_CERT_INSTALLED=true
            else
                echo "  WARNING: Let's Encrypt certificate request failed."
                echo "  Common causes:"
                echo "    - Port 80 is not reachable from the internet"
                echo "    - Domain '$LE_DOMAIN' does not resolve to this server's public IP"
                echo "    - Rate limit exceeded (use LETSENCRYPT_STAGING=true for testing)"
                echo "  Falling back to auto-generated certificate."
            fi
        fi

        # Set up automatic renewal cron job (runs daily at 3am, renews if < 30 days left)
        if [ "$SSL_CERT_INSTALLED" = "true" ]; then
            LE_RENEW_HOOK="cp /etc/letsencrypt/live/$LE_DOMAIN/fullchain.pem /etc/nginx/ssl/server.crt && cp /etc/letsencrypt/live/$LE_DOMAIN/privkey.pem /etc/nginx/ssl/server.key && chmod 600 /etc/nginx/ssl/server.key && chmod 644 /etc/nginx/ssl/server.crt && nginx -s reload"
            # Append to crontab without duplicating (remove any existing certbot line first)
            (crontab -l 2>/dev/null | grep -v 'certbot renew'; echo "0 3 * * * certbot renew --quiet --deploy-hook '$LE_RENEW_HOOK' >> /var/log/letsencrypt-renew.log 2>&1") | crontab -
            echo "  Automatic renewal cron job installed (daily at 3:00 AM)."
        fi
    else
        echo "WARNING: LETSENCRYPT_EMAIL is set but '$LE_DOMAIN' is not a valid FQDN."
        echo "  Let's Encrypt requires a real domain name (e.g., node.example.com)."
        echo "  It cannot issue certificates for IP addresses, 'localhost', or container names."
        echo "  For multi-node setups, use scripts/create-ssl-letsencrypt.sh on the host"
        echo "  to get a wildcard cert, then mount it at /ssl-certs/."
        echo "  Falling back to auto-generated certificate."
    fi
fi

# --- Priority 3/4: Generate certificate (CA-signed or self-signed) ---
if [ "$SSL_CERT_INSTALLED" = "false" ] && [ ! -f /etc/nginx/ssl/server.crt ]; then
    echo "Generating SSL certificate..."

    # Determine primary CN
    # Priority: SSL_DOMAIN env var > QUICKSTART hostname > localhost
    SSL_DOMAIN=${SSL_DOMAIN:-${EFFECTIVE_HOST:-localhost}}
    if [ "$SSL_DOMAIN" = "false" ]; then
        SSL_DOMAIN="localhost"
    fi

    # Strip port from SSL_DOMAIN (e.g. "88.99.69.172:1152" → "88.99.69.172")
    # Ports are not valid in certificate CN or SAN fields
    SSL_DOMAIN=$(echo "$SSL_DOMAIN" | sed 's/:[0-9]*$//')

    # Build Subject Alternative Names list
    # Use IP: prefix for IP addresses, DNS: for hostnames
    SAN_LIST="DNS:localhost"
    if echo "$SSL_DOMAIN" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
        SAN_LIST="$SAN_LIST,IP:$SSL_DOMAIN"
    elif [ "$SSL_DOMAIN" != "localhost" ]; then
        SAN_LIST="$SAN_LIST,DNS:$SSL_DOMAIN"
    fi

    # Add container hostname if different from SSL_DOMAIN
    CONTAINER_HOSTNAME=$(hostname 2>/dev/null || echo "")
    if [ -n "$CONTAINER_HOSTNAME" ] && [ "$CONTAINER_HOSTNAME" != "$SSL_DOMAIN" ] && [ "$CONTAINER_HOSTNAME" != "localhost" ]; then
        SAN_LIST="$SAN_LIST,DNS:$CONTAINER_HOSTNAME"
    fi

    # Auto-detect all container IP addresses and add as SANs
    DETECTED_IPS=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || echo "")
    for IP in $DETECTED_IPS; do
        if [ "$IP" != "127.0.0.1" ]; then
            SAN_LIST="$SAN_LIST,IP:$IP"
        fi
    done

    # Always include loopback for local testing
    SAN_LIST="$SAN_LIST,IP:127.0.0.1"

    # Add user-specified extra SANs
    # Example: SSL_EXTRA_SANS="DNS:node1.example.com,DNS:node1.local,IP:10.0.0.50"
    if [ -n "${SSL_EXTRA_SANS:-}" ]; then
        SAN_LIST="$SAN_LIST,$SSL_EXTRA_SANS"
    fi

    echo "  CN: $SSL_DOMAIN"
    echo "  SANs: $SAN_LIST"

    # Create OpenSSL configuration with SANs
    # Modern browsers require SANs - CN alone is deprecated
    cat > /tmp/openssl-san.cnf << SSLEOF
[req]
default_bits = 4096
prompt = no
default_md = sha256
distinguished_name = dn
req_extensions = req_ext
x509_extensions = v3_ext

[dn]
C = XX
ST = State
L = City
O = EIOU
OU = Node
CN = $SSL_DOMAIN

[req_ext]
subjectAltName = $SAN_LIST

[v3_ext]
subjectAltName = $SAN_LIST
basicConstraints = critical, CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
SSLEOF

    # Check if CA is mounted for CA-signed certificate generation
    if [ -f /ssl-ca/ca.crt ] && [ -f /ssl-ca/ca.key ]; then
        echo "  Generating CA-signed certificate..."

        CA_SIGN_OK=true

        # Generate private key and CSR
        if ! openssl req -new -nodes -newkey rsa:4096 \
            -keyout /etc/nginx/ssl/server.key \
            -out /tmp/server.csr \
            -config /tmp/openssl-san.cnf \
            2>&1; then
            echo "  WARNING: Failed to generate CSR from CA. Check /ssl-ca/ file permissions."
            CA_SIGN_OK=false
        fi

        # Sign with CA (browsers will trust this if CA is in their trust store)
        if [ "$CA_SIGN_OK" = true ] && ! openssl x509 -req -in /tmp/server.csr \
            -CA /ssl-ca/ca.crt \
            -CAkey /ssl-ca/ca.key \
            -CAserial /tmp/ca.srl -CAcreateserial \
            -out /etc/nginx/ssl/server.crt \
            -days 365 \
            -sha256 \
            -extfile /tmp/openssl-san.cnf \
            -extensions v3_ext \
            2>&1; then
            echo "  WARNING: Failed to sign certificate with CA. Check that ca.key is valid."
            CA_SIGN_OK=false
        fi

        rm -f /tmp/server.csr /tmp/ca.srl

        if [ "$CA_SIGN_OK" = true ]; then
            # Copy CA cert for client verification
            cp /ssl-ca/ca.crt /etc/nginx/ssl/ca.crt
            chmod 644 /etc/nginx/ssl/ca.crt
            echo "  CA-signed certificate generated successfully."
        else
            echo "  WARNING: CA-signed certificate generation failed. Falling back to self-signed."
            rm -f /etc/nginx/ssl/server.key /etc/nginx/ssl/server.crt

            openssl req -x509 -nodes -days 365 -newkey rsa:4096 \
                -keyout /etc/nginx/ssl/server.key \
                -out /etc/nginx/ssl/server.crt \
                -config /tmp/openssl-san.cnf \
                2>&1

            echo "  Self-signed certificate generated as fallback."
        fi
    else
        # Generate self-signed certificate
        echo "  Generating self-signed certificate..."

        openssl req -x509 -nodes -days 365 -newkey rsa:4096 \
            -keyout /etc/nginx/ssl/server.key \
            -out /etc/nginx/ssl/server.crt \
            -config /tmp/openssl-san.cnf \
            2>/dev/null

        echo "  Self-signed certificate generated successfully."
        echo "  Note: Browsers will show warnings for self-signed certificates."
        echo "  For trusted certificates, use Let's Encrypt (LETSENCRYPT_EMAIL),"
        echo "  mount a CA at /ssl-ca/, or external certs at /ssl-certs/"
    fi

    rm -f /tmp/openssl-san.cnf
    chmod 600 /etc/nginx/ssl/server.key
    chmod 644 /etc/nginx/ssl/server.crt

# --- Existing certificate found (from volume persistence) ---
elif [ "$SSL_CERT_INSTALLED" = "false" ]; then
    echo "Existing SSL certificate found, skipping generation."
fi

# =============================================================================
# MAINTENANCE MODE (Upgrade Lock)
# =============================================================================
# Create a maintenance lockfile before source sync and migrations. Any HTTP
# requests that reach PHP while the lockfile exists get a 503 response,
# preventing partial code execution against mid-sync source files or a
# mid-migration database schema. The lockfile is removed after all
# initialization is complete and processors are started.
# =============================================================================
echo "Entering maintenance mode (upgrade lock)..."
echo "$(date +%s)" > "$MAINTENANCE_LOCKFILE"

# =============================================================================
# SERVICE TUNING (env var overrides applied at boot)
# =============================================================================
# These modify the Dockerfile defaults before services start. Settings persist
# across container restarts via docker-compose.yml environment variables.
# =============================================================================

# --- PHP-FPM tuning ---
PHP_FPM_CONF=$(ls /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -1)
if [ -n "$PHP_FPM_CONF" ]; then
    if [ -n "${PHP_FPM_PM:-}" ]; then
        sed -i "s|^pm = .*|pm = $PHP_FPM_PM|" "$PHP_FPM_CONF"
        echo "PHP-FPM: pm=$PHP_FPM_PM"
    fi
    if [ -n "${PHP_FPM_MAX_CHILDREN:-}" ]; then
        sed -i "s|^;*pm.max_children = .*|pm.max_children = $PHP_FPM_MAX_CHILDREN|" "$PHP_FPM_CONF"
        echo "PHP-FPM: max_children=$PHP_FPM_MAX_CHILDREN"
    fi
    if [ -n "${PHP_FPM_START_SERVERS:-}" ]; then
        sed -i "s|^;*pm.start_servers = .*|pm.start_servers = $PHP_FPM_START_SERVERS|" "$PHP_FPM_CONF"
    fi
    if [ -n "${PHP_FPM_MIN_SPARE:-}" ]; then
        sed -i "s|^;*pm.min_spare_servers = .*|pm.min_spare_servers = $PHP_FPM_MIN_SPARE|" "$PHP_FPM_CONF"
    fi
    if [ -n "${PHP_FPM_MAX_SPARE:-}" ]; then
        sed -i "s|^;*pm.max_spare_servers = .*|pm.max_spare_servers = $PHP_FPM_MAX_SPARE|" "$PHP_FPM_CONF"
    fi
    if [ -n "${PHP_FPM_IDLE_TIMEOUT:-}" ]; then
        sed -i "s|^;*pm.process_idle_timeout = .*|pm.process_idle_timeout = $PHP_FPM_IDLE_TIMEOUT|" "$PHP_FPM_CONF"
    fi
    if [ -n "${PHP_FPM_MAX_REQUESTS:-}" ]; then
        sed -i "s|^;*pm.max_requests = .*|pm.max_requests = $PHP_FPM_MAX_REQUESTS|" "$PHP_FPM_CONF"
    fi
fi

# --- Nginx tuning ---
NGINX_CONF="/etc/nginx/nginx.conf"
if [ -n "${NGINX_WORKER_PROCESSES:-}" ]; then
    sed -i "s|^worker_processes .*;|worker_processes $NGINX_WORKER_PROCESSES;|" "$NGINX_CONF"
    echo "Nginx: worker_processes=$NGINX_WORKER_PROCESSES"
fi
if [ -n "${NGINX_WORKER_CONNECTIONS:-}" ]; then
    sed -i "s|worker_connections .*;|worker_connections $NGINX_WORKER_CONNECTIONS;|" "$NGINX_CONF"
    echo "Nginx: worker_connections=$NGINX_WORKER_CONNECTIONS"
fi
if [ -n "${NGINX_RATE_LIMIT_GENERAL:-}" ]; then
    sed -i "s|zone=general:10m rate=[^;]*;|zone=general:10m rate=$NGINX_RATE_LIMIT_GENERAL;|" "$NGINX_CONF"
fi
if [ -n "${NGINX_RATE_LIMIT_API:-}" ]; then
    sed -i "s|zone=api:10m rate=[^;]*;|zone=api:10m rate=$NGINX_RATE_LIMIT_API;|" "$NGINX_CONF"
fi
if [ -n "${NGINX_RATE_LIMIT_P2P:-}" ]; then
    sed -i "s|zone=p2p:10m rate=[^;]*;|zone=p2p:10m rate=$NGINX_RATE_LIMIT_P2P;|" "$NGINX_CONF"
fi

# Apply per-server-block settings (client_max_body_size, limit_conn)
EIOU_CONF="/etc/nginx/sites-available/eiou.conf"
if [ -n "${NGINX_CLIENT_MAX_BODY:-}" ]; then
    sed -i "s|client_max_body_size .*;|client_max_body_size $NGINX_CLIENT_MAX_BODY;|" "$EIOU_CONF"
fi
if [ -n "${NGINX_CONN_LIMIT:-}" ]; then
    sed -i "s|limit_conn addr .*;|limit_conn addr $NGINX_CONN_LIMIT;|" "$EIOU_CONF"
fi

# Create log directory and app log file before PHP-FPM starts.
# The file must be owned by www-data so PHP-FPM workers can write to it;
# otherwise the first root-level PHP process creates it as root:root.
mkdir -p /var/log/eiou
touch /var/log/eiou/app.log
chown -R www-data:www-data /var/log/eiou
chmod 755 /var/log/eiou
chmod 640 /var/log/eiou/app.log

# =============================================================================
# PRE-SERVICE ENCRYPTION SETUP
# =============================================================================
# These steps must run BEFORE MariaDB starts:
#   1. Volume encryption: if active, decrypt master key to /dev/shm
#   2. MariaDB TDE: if already enabled, write TDE key file to /dev/shm
#      so MariaDB can read its encrypted tables on startup
# =============================================================================

# Step 1: Volume encryption (optional — for environments with secrets management)
if [ -f /dev/shm/.volume_key ] || [ -f /etc/eiou/config/.master.key.enc ]; then
    echo "Initializing volume encryption..."
    VOLUME_INIT_RESULT=$(php /app/eiou/scripts/volume-encryption-init.php 2>&1)
    VOLUME_INIT_EXIT=$?
    echo "$VOLUME_INIT_RESULT"
    if [ $VOLUME_INIT_EXIT -ne 0 ]; then
        echo ""
        echo "========================================================================"
        echo "FATAL: Volume encryption initialization failed."
        echo "The node cannot start without access to the master encryption key."
        echo ""
        echo "If you have lost the volume key, the node data cannot be recovered."
        echo "You must restore from your 24-word seed phrase."
        echo "========================================================================"
        exit 1
    fi
fi

# Step 2: MariaDB TDE key file (for subsequent boots where TDE is already enabled)
# The TDE key file lives in /dev/shm (RAM) and is lost on container restart.
# It must be recreated from the master key BEFORE MariaDB starts, otherwise
# MariaDB cannot read its encrypted tables.
if [ -f /etc/mysql/conf.d/encryption.cnf ]; then
    MASTER_KEY_PATH=""
    if [ -f /dev/shm/.master.key ]; then
        MASTER_KEY_PATH="/dev/shm/.master.key"
    elif [ -f /etc/eiou/config/.master.key ]; then
        MASTER_KEY_PATH="/etc/eiou/config/.master.key"
    fi

    if [ -n "$MASTER_KEY_PATH" ]; then
        echo "Preparing MariaDB TDE key file for startup..."
        TDE_KEY_RESULT=$(php /app/eiou/scripts/mariadb-tde-init.php setup-key 2>&1)
        TDE_KEY_EXIT=$?
        if [ $TDE_KEY_EXIT -eq 0 ]; then
            echo "MariaDB TDE: encryption key ready"
        else
            echo "WARNING: Failed to prepare TDE key file — MariaDB may fail to start"
            echo "$TDE_KEY_RESULT"
        fi
    else
        echo "WARNING: MariaDB TDE config exists but master key not available yet"
        echo "MariaDB may fail to read encrypted tables on this boot"
    fi
fi

# =============================================================================
# MARIADB VERSION UPGRADE DETECTION
# =============================================================================
# When the image is rebuilt, apt may install a newer MariaDB version.
# InnoDB redo logs contain version-specific encryption metadata — if the
# binary version doesn't match the redo logs on the persistent volume,
# MariaDB fails with:
#   "Reading log encryption info failed; the log was created with MariaDB X.Y.Z"
#
# MariaDB 10.5+ does NOT recreate ib_logfile0 when it's missing, so we
# cannot simply delete stale redo logs. (If ib_logfile0 is missing entirely,
# the MISSING REDO LOG DETECTION section below handles that separately.)
# Instead, on version mismatch we
# start MariaDB with innodb_force_recovery=1 (skips redo log application),
# then do a clean shutdown to write fresh redo logs in the new format,
# then restart normally. After normal startup, mariadb-upgrade handles
# any schema changes.
# =============================================================================
MARIADB_VERSION_FILE="/var/lib/mysql/.mariadb_version"
CURRENT_MARIADB_VERSION=$(mariadbd --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)
if [ -z "$CURRENT_MARIADB_VERSION" ]; then
    CURRENT_MARIADB_VERSION=$(mysqld --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)
fi
MARIADB_UPGRADE_NEEDED=false
MARIADB_VERSION_CHANGED=false

if [ -n "$CURRENT_MARIADB_VERSION" ] && [ -f "$MARIADB_VERSION_FILE" ]; then
    STORED_MARIADB_VERSION=$(cat "$MARIADB_VERSION_FILE" 2>/dev/null)
    if [ "$CURRENT_MARIADB_VERSION" != "$STORED_MARIADB_VERSION" ]; then
        echo "========================================================================"
        echo "MariaDB version change detected: $STORED_MARIADB_VERSION -> $CURRENT_MARIADB_VERSION"
        echo "Will use force-recovery to regenerate redo logs in new format."
        echo "========================================================================"
        MARIADB_VERSION_CHANGED=true
        MARIADB_UPGRADE_NEEDED=true
    fi
elif [ -n "$CURRENT_MARIADB_VERSION" ] && [ -d /var/lib/mysql/mysql ]; then
    # Existing database but no version file — first boot with version tracking.
    # Run mariadb-upgrade to be safe.
    echo "MariaDB: Adding version tracking (first tracked boot: $CURRENT_MARIADB_VERSION)"
    MARIADB_UPGRADE_NEEDED=true
fi

# =============================================================================
# MISSING REDO LOG DETECTION
# =============================================================================
# If ibdata1 exists but ib_logfile0 does not, MariaDB will refuse to start
# even with innodb_force_recovery — InnoDB requires the redo log FILE to
# exist before it can initialize the plugin. This can happen when:
#   - A prior container crashed during initialization
#   - The volume was partially restored or corrupted
#   - A broken prior image never completed MariaDB setup
#
# Fix: create a zero-filled ib_logfile0 of the correct size before starting
# MariaDB. InnoDB will detect the invalid (empty) redo log and rebuild it
# when started with innodb_force_recovery.
# =============================================================================
if [ -f /var/lib/mysql/ibdata1 ] && [ ! -f /var/lib/mysql/ib_logfile0 ]; then
    echo "========================================================================"
    echo "WARNING: InnoDB redo log (ib_logfile0) is missing!"
    echo "Data directory exists but redo logs do not."
    echo "Creating empty redo log to allow MariaDB recovery..."
    echo "========================================================================"

    # MariaDB 10.11 default innodb_log_file_size is 96MB.
    # Read the configured value if available, otherwise use default.
    CONFIGURED_LOG_SIZE=$(my_print_defaults --mysqld 2>/dev/null | grep -oP '(?<=innodb.log.file.size=)\d+' | head -1)
    LOG_SIZE_BYTES="${CONFIGURED_LOG_SIZE:-100663296}"  # 96MB = 100663296 bytes
    LOG_SIZE_MB=$((LOG_SIZE_BYTES / 1048576))
    if [ "$LOG_SIZE_MB" -lt 1 ]; then
        LOG_SIZE_MB=96
    fi

    dd if=/dev/zero of=/var/lib/mysql/ib_logfile0 bs=1M count="$LOG_SIZE_MB" 2>/dev/null
    chown mysql:mysql /var/lib/mysql/ib_logfile0
    chmod 660 /var/lib/mysql/ib_logfile0
    echo "Created empty ib_logfile0 (${LOG_SIZE_MB}MB)"

    # Force the recovery path so MariaDB rebuilds the redo log properly
    MARIADB_VERSION_CHANGED=true
    MARIADB_UPGRADE_NEEDED=true
fi

# Start services
service cron start
service tor start
service "$PHP_FPM_SERVICE" start
service nginx start

# Helper: wait for MariaDB with timeout, returns 0 on success, 1 on timeout
wait_for_mariadb() {
    local label="${1:-MariaDB}"
    local timeout="${2:-60}"
    local elapsed=0
    while [ $elapsed -lt $timeout ]; do
        if mysqladmin ping -h localhost --silent 2>/dev/null; then
            return 0
        fi
        elapsed=$((elapsed + 1))
        if [ $((elapsed % 10)) -eq 0 ]; then
            echo "Waiting for $label... (${elapsed}s/${timeout}s)"
        fi
        sleep 1
    done
    return 1
}

# On version change: start with force-recovery to bypass incompatible redo logs,
# then clean shutdown to regenerate them, then restart normally.
if [ "$MARIADB_VERSION_CHANGED" = "true" ]; then
    echo "Starting MariaDB with innodb_force_recovery=1 to bypass stale redo logs..."
    printf '[mysqld]\ninnodb_force_recovery=1\n' > /etc/mysql/conf.d/zz-force-recovery.cnf

    service mariadb start
    if wait_for_mariadb "MariaDB force-recovery" 60; then
        echo "Force-recovery succeeded. Performing clean shutdown to regenerate redo logs..."
        service mariadb stop
        sleep 2
        rm -f /etc/mysql/conf.d/zz-force-recovery.cnf
        echo "Restarting MariaDB in normal mode..."
        service mariadb start
        if wait_for_mariadb "MariaDB normal restart" 60; then
            MARIADB_STARTED=true
            echo "MariaDB version upgrade completed successfully."
        else
            MARIADB_STARTED=false
        fi
    else
        # Force-recovery also failed — fatal
        rm -f /etc/mysql/conf.d/zz-force-recovery.cnf
        MARIADB_STARTED=false
    fi
else
    # Normal startup (no version change)
    service mariadb start
    if wait_for_mariadb "MariaDB" 60; then
        MARIADB_STARTED=true
    else
        MARIADB_STARTED=false
    fi
fi

# Reactive fallback: if normal startup failed (no proactive detection),
# try force-recovery in case of redo log corruption or version issues
# that weren't caught by version tracking (e.g., first boot with tracking).
if [ "$MARIADB_STARTED" = "false" ] && [ "$MARIADB_VERSION_CHANGED" = "false" ]; then
    echo "========================================================================"
    echo "WARNING: MariaDB failed to start within 60s"
    echo "Attempting recovery with innodb_force_recovery=1..."
    echo "========================================================================"

    service mariadb stop 2>/dev/null || true
    sleep 2

    printf '[mysqld]\ninnodb_force_recovery=1\n' > /etc/mysql/conf.d/zz-force-recovery.cnf
    service mariadb start
    MARIADB_UPGRADE_NEEDED=true

    if wait_for_mariadb "MariaDB force-recovery" 60; then
        echo "Force-recovery succeeded. Clean shutdown to regenerate redo logs..."
        service mariadb stop
        sleep 2
        rm -f /etc/mysql/conf.d/zz-force-recovery.cnf
        echo "Restarting MariaDB in normal mode..."
        service mariadb start
        if wait_for_mariadb "MariaDB normal restart" 60; then
            MARIADB_STARTED=true
            echo "MariaDB recovery completed successfully."
        fi
    else
        rm -f /etc/mysql/conf.d/zz-force-recovery.cnf
    fi
fi

if [ "$MARIADB_STARTED" = "false" ]; then
    echo "========================================================================"
    echo "FATAL: MariaDB failed to start after all recovery attempts."
    echo ""
    echo "The database volume may be corrupted. Options:"
    echo "  - Check container logs: docker logs <container> 2>&1 | grep -i innodb"
    echo "  - Restore from backup: eiou backup list / eiou backup restore"
    echo "  - Restore from seed: use RESTORE_FILE with fresh volumes"
    echo "========================================================================"
    exit 1
fi

# Complete MariaDB upgrade if version changed
if [ "$MARIADB_UPGRADE_NEEDED" = "true" ] && [ -n "$CURRENT_MARIADB_VERSION" ]; then
    echo "Running mariadb-upgrade for version $CURRENT_MARIADB_VERSION..."
    if mariadb-upgrade 2>&1; then
        echo "MariaDB upgrade completed successfully."
    elif mysql_upgrade 2>&1; then
        echo "MariaDB upgrade completed successfully (via mysql_upgrade)."
    else
        echo "WARNING: mariadb-upgrade not available or failed — manual check recommended."
    fi
fi

# Store current MariaDB version for future upgrade detection
if [ -n "$CURRENT_MARIADB_VERSION" ]; then
    echo "$CURRENT_MARIADB_VERSION" > "$MARIADB_VERSION_FILE"
fi

# Check if config/userconfig.json was already made and if so if user keys exist, if not build config
if [[ $(php -r 'require_once "/app/eiou/src/startup/ConfigCheck.php"; echo $run;') ]]; then
    # RESTORE_FILE takes priority over RESTORE, which takes priority over QUICKSTART
    if [ "$RESTORE_FILE" != "false" ]; then
        # Method 1: File-based restore (most secure)
        # The seedphrase is read from a mounted file, never exposed in environment
        echo "Restore mode enabled (file-based). Restoring wallet from mounted file..."

        # Verify the file exists
        if [ ! -f "$RESTORE_FILE" ]; then
            echo "ERROR: Seed phrase file not found at $RESTORE_FILE"
            echo "Ensure the file is mounted correctly, e.g.:"
            echo "  docker run -v /host/path/seed.txt:/restore/seed:ro -e RESTORE_FILE=/restore/seed ..."
            exit 1
        fi

        # Verify file is readable
        if [ ! -r "$RESTORE_FILE" ]; then
            echo "ERROR: Cannot read seed phrase file at $RESTORE_FILE"
            exit 1
        fi

        # Pass file directly to eiou command
        RESTORE_RESULT=$(eiou generate restore-file "$RESTORE_FILE" 2>&1)
        RESTORE_EXIT_CODE=$?

        # Check if restore was successful
        if [ $RESTORE_EXIT_CODE -ne 0 ]; then
            if type show_error_banner &>/dev/null; then
                show_error_banner "WALLET RESTORATION FAILED" "$RESTORE_RESULT"
            else
                echo "ERROR: Wallet restoration failed:"
                echo "$RESTORE_RESULT"
            fi
            exit 1
        fi

        echo "$RESTORE_RESULT"
        echo "Wallet restore completed."
        echo "NOTE: You can now safely unmount and delete the seed phrase file from the host."

        # Apply hostname to restored wallet if set
        # Restore only configures Tor address; QUICKSTART/EIOU_HOST adds HTTP/HTTPS addressing
        if [ "$EFFECTIVE_URL" != "false" ]; then
            echo "Applying hostname ($EFFECTIVE_URL) to restored wallet..."
            HOSTNAME_RESULT=$(eiou changesettings hostname "$EFFECTIVE_URL" 2>&1)
            HOSTNAME_EXIT_CODE=$?
            if [ $HOSTNAME_EXIT_CODE -ne 0 ]; then
                echo "WARNING: Failed to apply hostname to restored wallet:"
                echo "$HOSTNAME_RESULT"
            else
                echo "HTTP/HTTPS hostname configured: $EFFECTIVE_URL"
            fi
            if [ "$EFFECTIVE_NAME" != "false" ]; then
                eiou changesettings name "$EFFECTIVE_NAME" 2>&1
                echo "Display name configured: $EFFECTIVE_NAME"
            fi
        elif [ "$QUICKSTART" != "false" ]; then
            echo "Applying QUICKSTART hostname ($QUICKSTART) to restored wallet..."
            HOSTNAME_RESULT=$(eiou changesettings hostname "https://$QUICKSTART" 2>&1)
            HOSTNAME_EXIT_CODE=$?
            if [ $HOSTNAME_EXIT_CODE -ne 0 ]; then
                echo "WARNING: Failed to apply hostname ($QUICKSTART) to restored wallet:"
                echo "$HOSTNAME_RESULT"
                echo "You can set it manually later with: eiou changesettings hostname https://$QUICKSTART"
            else
                echo "HTTP/HTTPS hostname configured: https://$QUICKSTART"
            fi
        fi

    elif [ "$RESTORE" != "false" ]; then
        # Method 2: Environment variable restore (convenient but less secure)
        # Warning: Seedphrase remains visible in docker inspect and container env
        echo ""
        echo "========================================================================"
        echo "WARNING: RESTORE environment variable detected."
        echo "For better security, use RESTORE_FILE instead."
        echo ""
        echo "RESTORE_FILE method:"
        echo "  docker run -v /host/path/seed.txt:/restore/seed:ro \\"
        echo "             -e RESTORE_FILE=/restore/seed ..."
        echo ""
        echo "The seed phrase in RESTORE is visible via 'docker inspect' and may"
        echo "appear in shell history. RESTORE_FILE keeps the seed phrase in a file"
        echo "that can be securely deleted after container startup."
        echo "========================================================================"
        echo ""
        echo "Restore mode enabled (env var). Restoring wallet from environment variable..."

        # SECURITY: Write seedphrase to temp file instead of passing via command line
        # This prevents the seedphrase from appearing in process lists (ps aux)
        RESTORE_TEMP_FILE="/dev/shm/.eiou_restore_$$"

        # Write seedphrase to temp file
        # Use printf instead of echo for consistent behavior across shells
        # echo can have issues with special characters or leading dashes
        printf '%s\n' "$RESTORE" > "$RESTORE_TEMP_FILE"

        # Verify file was created successfully
        if [ ! -f "$RESTORE_TEMP_FILE" ]; then
            echo "ERROR: Failed to create restore file at $RESTORE_TEMP_FILE"
            exit 1
        fi

        chmod 600 "$RESTORE_TEMP_FILE"

        # Pass seedphrase via file to eiou command
        # The restore-file flag reads the seedphrase from file instead of args
        RESTORE_RESULT=$(eiou generate restore-file "$RESTORE_TEMP_FILE" 2>&1)
        RESTORE_EXIT_CODE=$?

        # Securely delete the temp file
        shred -u "$RESTORE_TEMP_FILE" 2>/dev/null || rm -f "$RESTORE_TEMP_FILE"

        # SECURITY: Clear the RESTORE environment variable immediately after use
        # This removes the seed phrase from the shell environment, reducing exposure
        # Note: This does not clear the variable from /proc/<pid>/environ or docker inspect
        # For complete security, use RESTORE_FILE method instead
        unset RESTORE
        export RESTORE=""

        # Check if restore was successful
        if [ $RESTORE_EXIT_CODE -ne 0 ]; then
            if type show_error_banner &>/dev/null; then
                show_error_banner "WALLET RESTORATION FAILED" "$RESTORE_RESULT"
            else
                echo "ERROR: Wallet restoration failed:"
                echo "$RESTORE_RESULT"
            fi
            exit 1
        fi

        echo "$RESTORE_RESULT"
        echo "Wallet restore completed."
        echo "NOTE: RESTORE environment variable has been cleared from this shell."

        # Apply hostname to restored wallet if set
        # Restore only configures Tor address; QUICKSTART/EIOU_HOST adds HTTP/HTTPS addressing
        if [ "$EFFECTIVE_URL" != "false" ]; then
            echo "Applying hostname ($EFFECTIVE_URL) to restored wallet..."
            HOSTNAME_RESULT=$(eiou changesettings hostname "$EFFECTIVE_URL" 2>&1)
            HOSTNAME_EXIT_CODE=$?
            if [ $HOSTNAME_EXIT_CODE -ne 0 ]; then
                echo "WARNING: Failed to apply hostname to restored wallet:"
                echo "$HOSTNAME_RESULT"
            else
                echo "HTTP/HTTPS hostname configured: $EFFECTIVE_URL"
            fi
            if [ "$EFFECTIVE_NAME" != "false" ]; then
                eiou changesettings name "$EFFECTIVE_NAME" 2>&1
                echo "Display name configured: $EFFECTIVE_NAME"
            fi
        elif [ "$QUICKSTART" != "false" ]; then
            echo "Applying QUICKSTART hostname ($QUICKSTART) to restored wallet..."
            HOSTNAME_RESULT=$(eiou changesettings hostname "https://$QUICKSTART" 2>&1)
            HOSTNAME_EXIT_CODE=$?
            if [ $HOSTNAME_EXIT_CODE -ne 0 ]; then
                echo "WARNING: Failed to apply hostname ($QUICKSTART) to restored wallet:"
                echo "$HOSTNAME_RESULT"
                echo "You can set it manually later with: eiou changesettings hostname https://$QUICKSTART"
            else
                echo "HTTP/HTTPS hostname configured: https://$QUICKSTART"
            fi
        fi

    elif [ "$EFFECTIVE_URL" != "false" ]; then
        echo "Quickstart mode enabled. Running generate command with address: $EFFECTIVE_URL"
        # Use HTTPS for secure P2P communication (SSL certificates are auto-generated)
        if [ "$EFFECTIVE_NAME" != "false" ]; then
            GENERATE_RESULT=$(eiou generate "$EFFECTIVE_URL" "$EFFECTIVE_NAME" 2>&1)
        else
            GENERATE_RESULT=$(eiou generate "$EFFECTIVE_URL" 2>&1)
        fi
        GENERATE_EXIT_CODE=$?
        echo "$GENERATE_RESULT"
        if [ $GENERATE_EXIT_CODE -ne 0 ]; then
            echo "ERROR: Wallet generation failed (exit code: $GENERATE_EXIT_CODE)"
            echo "Cannot continue startup without a wallet. Exiting."
            exit 1
        fi
        echo "Generate command completed."
    else
        # Run automatically without hostname (only tor)
        GENERATE_RESULT=$(eiou generate 2>&1)
        GENERATE_EXIT_CODE=$?
        echo "$GENERATE_RESULT"
        if [ $GENERATE_EXIT_CODE -ne 0 ]; then
            echo "ERROR: Wallet generation failed (exit code: $GENERATE_EXIT_CODE)"
            echo "Cannot continue startup without a wallet. Exiting."
            exit 1
        fi
        echo "Generate command completed."
    fi

    # Restart Tor to load the newly generated hidden service keys
    # Tor was started before wallet generation, so it needs to reload the new keys
    echo "Restarting Tor to load new hidden service keys..."

    # Configuration for Tor restart
    TOR_RESTART_MAX_ATTEMPTS=3
    TOR_RESTART_ATTEMPT=0
    TOR_RESTART_SUCCESS=false
    HS_DIR="/var/lib/tor/hidden_service"
    HS_HOSTNAME_FILE="${HS_DIR}/hostname"
    # WSL2 environments have slower I/O; use EIOU_HS_TIMEOUT env var to override
    HS_MAX_WAIT=${EIOU_HS_TIMEOUT:-60}  # Maximum seconds to wait for hidden service

    # Ensure correct permissions on hidden service directory before restart
    # This fixes potential permission issues from PHP-based key generation
    # If these fail, Tor will reject the key files and generate its own random keys,
    # causing the .onion address to NOT match the seed phrase
    if [ -d "$HS_DIR" ]; then
        if ! chown -R debian-tor:debian-tor "$HS_DIR" 2>&1; then
            echo "ERROR: Failed to set ownership on $HS_DIR — Tor may generate wrong keys!"
        fi
        if ! chmod 700 "$HS_DIR" 2>&1; then
            echo "ERROR: Failed to set directory permissions on $HS_DIR"
        fi
        if ! find "$HS_DIR" -type f -exec chmod 600 {} \; 2>&1; then
            echo "ERROR: Failed to set file permissions in $HS_DIR"
        fi
    fi

    # Attempt restart with retry logic
    while [ $TOR_RESTART_ATTEMPT -lt $TOR_RESTART_MAX_ATTEMPTS ]; do
        TOR_RESTART_ATTEMPT=$((TOR_RESTART_ATTEMPT + 1))
        echo "Tor restart attempt $TOR_RESTART_ATTEMPT of $TOR_RESTART_MAX_ATTEMPTS..."

        # Force stop any existing Tor process (service stop often fails with PID issues)
        pkill -x tor 2>/dev/null || true
        sleep 1

        # Start Tor fresh
        if service tor start; then
            # Wait for Tor process to initialize
            sleep 3

            # Verify Tor is running
            if pgrep -x "tor" > /dev/null 2>&1; then
                # Wait for hidden service hostname file to be generated/verified
                HS_WAIT=0
                while [ $HS_WAIT -lt $HS_MAX_WAIT ]; do
                    if [ -f "$HS_HOSTNAME_FILE" ] && [ -s "$HS_HOSTNAME_FILE" ]; then
                        ONION_ADDRESS=$(cat "$HS_HOSTNAME_FILE" 2>/dev/null | tr -d '\n')
                        if [ -n "$ONION_ADDRESS" ]; then
                            echo "Tor restarted successfully."
                            TOR_RESTART_SUCCESS=true
                            break 2  # Exit both loops
                        fi
                    fi
                    sleep 1
                    HS_WAIT=$((HS_WAIT + 1))
                done

                echo "WARNING: Hidden service hostname file not ready after ${HS_MAX_WAIT}s"
            else
                echo "WARNING: Tor process not running after restart attempt $TOR_RESTART_ATTEMPT"
            fi
        else
            echo "WARNING: Tor restart command failed on attempt $TOR_RESTART_ATTEMPT"
        fi

        # Wait before retry (exponential backoff)
        sleep $((TOR_RESTART_ATTEMPT * 2))
    done

    # Final status check
    if [ "$TOR_RESTART_SUCCESS" = false ]; then
        echo "ERROR: Failed to restart Tor after $TOR_RESTART_MAX_ATTEMPTS attempts"
        echo "The node will continue startup, but Tor-based features may not work."
        echo "Check Tor logs with: cat /var/log/tor/log"
        # Log Tor errors for debugging
        if [ -f /var/log/tor/log ]; then
            echo "Recent Tor log entries:"
            tail -10 /var/log/tor/log 2>/dev/null || true
        fi
    fi
else
    # Wallet already exists (volume restart) — check if Tor hidden service files need regeneration
    # The HS directory is NOT in a Docker volume, so it's lost on container restart.
    # Without the correct key files, Tor generates random keys → different .onion address
    # → watchdog self-check fails forever because it curls the old address from userconfig.
    HS_DIR="/var/lib/tor/hidden_service"
    HS_HOSTNAME_FILE="${HS_DIR}/hostname"

    # Compare the .onion address Tor is currently using with the one in userconfig.
    # On container restart, Tor starts before this block and generates random keys
    # (HS dir is not in a volume), so the files exist but with the WRONG address.
    EXPECTED_ONION=$(php -r '$c = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"), true); echo $c["torAddress"] ?? "";' 2>/dev/null)
    CURRENT_ONION=""
    if [ -f "$HS_HOSTNAME_FILE" ]; then
        CURRENT_ONION=$(tr -d '\n' < "$HS_HOSTNAME_FILE" 2>/dev/null)
    fi

    if [ -n "$EXPECTED_ONION" ] && [ "$CURRENT_ONION" != "$EXPECTED_ONION" ]; then
        echo "Tor hidden service address mismatch (container restart) — regenerating from seed..."
        echo "  Expected: $EXPECTED_ONION"
        echo "  Current:  ${CURRENT_ONION:-(missing)}"

        # Regenerate HS files from the encrypted mnemonic stored in userconfig.json
        # SECURITY: stderr suppressed to prevent stack traces from leaking decrypted mnemonic
        REGEN_RESULT=$(php -r '
            require_once("/app/eiou/vendor/autoload.php");
            require_once("/app/eiou/src/bootstrap.php");
            use Eiou\Security\TorKeyDerivation;
            use Eiou\Security\BIP39;
            use Eiou\Security\KeyEncryption;

            $config = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"), true);
            if (!isset($config["mnemonic_encrypted"])) {
                fwrite(STDERR, "No encrypted mnemonic in userconfig.json\n");
                exit(1);
            }

            $mnemonic = KeyEncryption::decrypt($config["mnemonic_encrypted"]);
            if (!$mnemonic) {
                fwrite(STDERR, "Failed to decrypt mnemonic\n");
                exit(1);
            }

            $seed = BIP39::mnemonicToSeed($mnemonic);
            BIP39::secureClear($mnemonic);

            $hostname = TorKeyDerivation::generateHiddenServiceFiles($seed);
            BIP39::secureClear($seed);

            echo $hostname;
        ' 2>/dev/null)
        REGEN_EXIT_CODE=$?

        if [ $REGEN_EXIT_CODE -eq 0 ] && [ -n "$REGEN_RESULT" ]; then
            echo "Hidden service keys regenerated: $REGEN_RESULT"

            # Fix permissions for Tor
            if [ -d "$HS_DIR" ]; then
                chown -R debian-tor:debian-tor "$HS_DIR" 2>/dev/null || true
                chmod 700 "$HS_DIR" 2>/dev/null || true
                find "$HS_DIR" -type f -exec chmod 600 {} \; 2>/dev/null || true
            fi

            # Restart Tor to load the regenerated keys
            echo "Restarting Tor to load regenerated hidden service keys..."
            pkill -x tor 2>/dev/null || true
            sleep 2
            if service tor start 2>/dev/null; then
                sleep 3
                if pgrep -x "tor" > /dev/null 2>&1; then
                    echo "Tor restarted with correct hidden service keys."
                else
                    echo "WARNING: Tor process not running after restart"
                fi
            else
                echo "WARNING: Tor restart command failed"
            fi
        else
            echo "WARNING: Failed to regenerate hidden service keys"
            echo "Tor may be running with incorrect .onion address"
        fi
    fi
fi

# =============================================================================
# MARIADB TRANSPARENT DATA ENCRYPTION (TDE) — FIRST-TIME SETUP
# =============================================================================
# MariaDB TDE encrypts all database files at rest (transactions, contacts,
# balances, redo logs, temp tables). Enabled automatically after wallet
# generation — no user configuration required.
#
# On first boot: master key just became available → write TDE config, restart
# MariaDB to load the encryption plugin, encrypt existing tables.
# On subsequent boots: TDE key file was already set up in the pre-service
# section above, and MariaDB loaded the plugin on start.
# =============================================================================
if [ -f /dev/shm/.master.key ] || [ -f /etc/eiou/config/.master.key ]; then
    if [ ! -f /etc/mysql/conf.d/encryption.cnf ]; then
        # First-time TDE setup: write config + prepare for restart
        echo "Enabling MariaDB data-at-rest encryption..."
        TDE_RESULT=$(php /app/eiou/scripts/mariadb-tde-init.php setup 2>&1)
        TDE_EXIT=$?
        echo "$TDE_RESULT"

        if [ $TDE_EXIT -eq 0 ] && echo "$TDE_RESULT" | grep -q "RESTART_REQUIRED"; then
            echo "Restarting MariaDB to load encryption plugin..."
            service mariadb restart

            # Wait for MariaDB to be ready after restart
            while ! mysqladmin ping -h localhost --silent; do
                echo "Waiting for MariaDB to restart..."
                sleep 1
            done

            # Encrypt existing tables
            echo "Encrypting existing database tables..."
            TDE_ENCRYPT_RESULT=$(php /app/eiou/scripts/mariadb-tde-init.php encrypt-tables 2>&1)
            TDE_ENCRYPT_EXIT=$?
            echo "$TDE_ENCRYPT_RESULT"

            if [ $TDE_ENCRYPT_EXIT -ne 0 ]; then
                echo "WARNING: Table encryption deferred — will complete on next restart."
            fi
        elif [ $TDE_EXIT -ne 0 ]; then
            echo "WARNING: MariaDB TDE setup failed — database files remain unencrypted."
        fi
    fi
fi

# Check if all precursors to the message processors are available and working
first=true
while true; do
    if [[ $(php -r 'require_once "/app/eiou/src/startup/MessageCheck.php"; echo $passed;') ]]; then
        echo "Message processing check completed successfully."  
        # Display all user info for quick access
        http=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"),true); if(isset($json["hostname"])){echo $json["hostname"];}')
        tor=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"),true); if(isset($json["torAddress"])){echo $json["torAddress"];}')
        pubkey=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"),true); if(isset($json["public"])){echo $json["public"];}')
        # Check for existing seedphrase file (from initial wallet generation) or create authcode file
        # On first creation, the seedphrase file already contains the authcode — no need for a second file.
        # On restart/restore, no seedphrase file exists so we create an authcode-only file.
        # SECURITY: The authcode never touches a bash variable — it stays inside the PHP process
        authcode_file=$(php -r '
            require_once("/app/eiou/vendor/autoload.php");
            require_once("/app/eiou/src/bootstrap.php");
            // Check if a seedphrase file already exists (first wallet creation)
            $seedFiles = glob("/dev/shm/eiou_wallet_info_*");
            if (empty($seedFiles)) { $seedFiles = glob("/tmp/eiou_wallet_info_*"); }
            if (!empty($seedFiles)) {
                // Seedphrase file exists (contains authcode too) — use it
                echo "seedfile:" . $seedFiles[0];
            } else {
                // No seedphrase file — create authcode-only file (restart/restore)
                $ac = \Eiou\Core\UserContext::getInstance()->getAuthCode();
                if ($ac) {
                    $r = \Eiou\Utils\SecureSeedphraseDisplay::displayAuthcode($ac);
                    if ($r["success"] && $r["method"] === "tty") { echo "tty"; }
                    elseif ($r["success"] && isset($r["filepath"])) { echo $r["filepath"]; }
                }
            }
        ' 2>/dev/null)
        displayname=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"),true); if(isset($json["name"])){echo $json["name"];}')
        break
    else
        if $first; then
            echo "Message processing check failed to complete. Retrying every 5 seconds..."
            first=false
        fi
        sleep 5
        continue
    fi
done

# Wait for Tor to be ready and connected
echo "Waiting for Tor to establish connection with container..."
# WSL2 environments have slower network; use EIOU_TOR_TIMEOUT env var to override
TOR_MAX_WAIT=${EIOU_TOR_TIMEOUT:-120}  # Maximum wait time in seconds
TOR_START_TIME=$(date +%s)
TOR_TEST_URL="${tor}"
TOR_FIRST_ATTEMPT=true
TOR_CONNECTED=false

# Note: Using timestamp-based timeout (date +%s) instead of counter
# because curl --max-time can vary, making counter-based timing inaccurate
while true; do
    # Calculate elapsed time from timestamp (accurate regardless of curl duration)
    TOR_CURRENT_TIME=$(date +%s)
    TOR_ELAPSED=$((TOR_CURRENT_TIME - TOR_START_TIME))

    # Check if we've exceeded the timeout
    if [ $TOR_ELAPSED -ge $TOR_MAX_WAIT ]; then
        break
    fi

    # Try to access the .onion address through Tor's SOCKS proxy
    # Reduced --max-time from 10 to 8 to allow faster iteration
    if curl --socks5-hostname 127.0.0.1:9050 \
            --connect-timeout 5 \
            --max-time 8 \
            --silent \
            --fail \
            --output /dev/null \
            "$TOR_TEST_URL" 2>/dev/null; then
        echo "Tor connected successfully (${TOR_ELAPSED}s)"
        TOR_CONNECTED=true
        break
    fi

    # Show waiting message on first attempt only
    if [ "$TOR_FIRST_ATTEMPT" = true ]; then
        echo "Waiting for Tor connection (timeout: ${TOR_MAX_WAIT}s)..."
        TOR_FIRST_ATTEMPT=false
    fi

    # Brief pause before retry (curl timeout handles most of the delay)
    sleep 2
done

# Check if Tor connection was established
if [ "$TOR_CONNECTED" = false ]; then
    echo "WARNING: Tor connection could not be verified after ${TOR_MAX_WAIT}s"
    echo "Continuing startup anyway. Tor-dependent features may not work."
fi

# Display user information
echo "User Information: "
if [[ ! -z ${displayname} ]]; then
    echo -e "\t Display name: $displayname"
fi
echo -e "\t Tor address: $tor"
if [[ ! -z ${http} ]]; then
    # Always show both HTTP and HTTPS addresses regardless of configured protocol
    if [[ ${http} == https://* ]]; then
        # URL is HTTPS, derive HTTP from it
        httpAddr="${http/https:\/\//http:\/\/}"
        httpsAddr="$http"
    elif [[ ${http} == http://* ]]; then
        # URL is HTTP, derive HTTPS from it
        httpAddr="$http"
        httpsAddr="${http/http:\/\//https:\/\/}"
    else
        # No protocol prefix, add both
        httpAddr="http://$http"
        httpsAddr="https://$http"
    fi
    if [ "$EIOU_HOST" = "false" ] && [ "$QUICKSTART" != "false" ]; then
        ADDR_WARN="\033[33m⚠\033[0m "
    else
        ADDR_WARN=""
    fi
    echo -e "\t HTTPS address: ${ADDR_WARN}$httpsAddr"
    echo -e "\t HTTP address:  ${ADDR_WARN}$httpAddr"
    if [ "$EIOU_HOST" = "false" ] && [ "$QUICKSTART" != "false" ]; then
        echo -e "\t \033[33m⚠ These addresses are Docker-internal only (resolved via Docker DNS)."
        echo -e "\t   They are not reachable from outside the Docker network."
        echo -e "\t   For external access, set EIOU_HOST to a real IP or domain and EIOU_PORT to the mapped port."
        if [ "${P2P_SSL_VERIFY:-}" != "false" ] && [ -z "${P2P_CA_CERT:-}" ]; then
            echo -e "\t   HTTPS between nodes will also fail — self-signed certs are rejected by default"
            echo -e "\t   (P2P_SSL_VERIFY=true). Set P2P_SSL_VERIFY=false or use P2P_CA_CERT with a shared CA."
        fi
        echo -e "\033[0m"
    fi
fi
readable="${pubkey//$'\n'/$'\n\t\t'}"
echo -e "\t Public Key: \n\t\t $readable"
if [ "$authcode_file" = "tty" ]; then
    echo -e "\t Authentication Code: (displayed securely via terminal)"
elif [[ "$authcode_file" == seedfile:* ]]; then
    # First wallet creation — seedphrase file already contains the authcode
    seedfile_path="${authcode_file#seedfile:}"
    echo -e "\t Seedphrase & Auth Code: (stored in secure temp file)"
    echo -e "\t   View: docker exec \"$(hostname)\" cat \"$seedfile_path\""
    echo -e "\t   Auto-deletes in 300 seconds"
elif [ -n "$authcode_file" ]; then
    echo -e "\t Authentication Code: (stored in secure temp file)"
    echo -e "\t   View: docker exec \"$(hostname)\" cat \"$authcode_file\""
    echo -e "\t   Auto-deletes in 300 seconds"
else
    echo -e "\t Authentication Code: (unavailable - see 'eiou info --show-auth')"
fi

# ========================
# Backup System Setup
# ========================
echo "Setting up backup system..."

# Log directory already created before PHP-FPM start (see above)

# Create backup directory with proper permissions
mkdir -p /var/lib/eiou/backups
chown www-data:www-data /var/lib/eiou/backups
chmod 700 /var/lib/eiou/backups

# Set up cron for daily backups at midnight (if auto backup is enabled by default)
if [ "${EIOU_BACKUP_AUTO_ENABLED:-true}" = "true" ]; then
    # Ensure cron service is running
    service cron start 2>/dev/null || true

    # Install backup cron job (daily at midnight)
    CRON_JOB="0 0 * * * runuser -u www-data -- /usr/bin/php /app/eiou/scripts/backup-cron.php >> /var/log/eiou/backup.log 2>&1"

    # Remove existing backup cron entry and add new one
    (crontab -l 2>/dev/null | grep -v "backup-cron.php"; echo "$CRON_JOB") | crontab -

    echo "Backup cron job installed (daily at midnight)"
else
    echo "Automatic backups disabled via EIOU_BACKUP_AUTO_ENABLED"
fi

# Set up cron for daily update check (2 AM UTC, avoids overlap with midnight backup)
if [ "${EIOU_UPDATE_CHECK_ENABLED:-true}" = "true" ]; then
    UPDATE_CRON_JOB="0 2 * * * runuser -u www-data -- /usr/bin/php /app/eiou/scripts/update-check-cron.php >> /var/log/eiou/update-check.log 2>&1"
    (crontab -l 2>/dev/null | grep -v "update-check-cron.php"; echo "$UPDATE_CRON_JOB") | crontab -
    echo "Update check cron job installed (daily at 2 AM UTC)"

    # Run initial check in background (non-blocking — don't delay startup)
    runuser -u www-data -- php /app/eiou/scripts/update-check-cron.php >> /var/log/eiou/update-check.log 2>&1 &
else
    # Remove any existing update check cron entry
    (crontab -l 2>/dev/null | grep -v "update-check-cron.php") | crontab -
    echo "Update checks disabled via EIOU_UPDATE_CHECK_ENABLED"
fi

# Set up cron for weekly analytics submission (Sundays at 3 AM UTC)
# Always installed — the PHP script checks analyticsEnabled and exits
# gracefully if disabled, so users can enable via GUI/CLI/API at any time
# without needing a container restart.
ANALYTICS_CRON_JOB="0 3 * * 0 runuser -u www-data -- /usr/bin/php /app/eiou/scripts/analytics-cron.php >> /var/log/eiou/analytics.log 2>&1"
(crontab -l 2>/dev/null | grep -v "analytics-cron.php"; echo "$ANALYTICS_CRON_JOB") | crontab -
echo "Analytics cron job installed (weekly, Sundays at 3 AM UTC)"

# Clear any stale shutdown flag from previous runs
rm -f "$SHUTDOWN_FLAG" 2>/dev/null

# Exit maintenance mode — all initialization, migrations, and sync are complete.
# HTTP requests will now be processed normally.
if [ -f "$MAINTENANCE_LOCKFILE" ]; then
    rm -f "$MAINTENANCE_LOCKFILE"
    echo "Maintenance mode ended (upgrade lock released)"
fi

# ========================
# Drop Privileges for PHP Processors (M-22)
# ========================
# PHP message processors run as www-data instead of root for defense-in-depth.
# Ensure all directories they write to are owned by www-data.
chown -R www-data:www-data /etc/eiou/config

# Start p2p message processing in background (as www-data)
nohup runuser -u www-data -- php /app/eiou/processors/P2pMessages.php > /dev/null 2>&1 &
P2P_PID=$!
echo "P2p message processing started successfully (PID: $P2P_PID)"

# Start transaction message processing in background (as www-data)
nohup runuser -u www-data -- php /app/eiou/processors/TransactionMessages.php > /dev/null 2>&1 &
TRANSACTION_PID=$!
echo "Transaction message processing started successfully (PID: $TRANSACTION_PID)"

# Start cleanup message processing in background (as www-data)
nohup runuser -u www-data -- php /app/eiou/processors/CleanupMessages.php > /dev/null 2>&1 &
CLEANUP_PID=$!
echo "Cleanup processing started successfully (PID: $CLEANUP_PID)"

# Start contact status polling in background (optional - respects EIOU_CONTACT_STATUS_ENABLED)
# Check if contact status is enabled before starting (default: true if not set)
CONTACT_STATUS_ENABLED="${EIOU_CONTACT_STATUS_ENABLED:-true}"
if [ "$CONTACT_STATUS_ENABLED" = "true" ] || [ "$CONTACT_STATUS_ENABLED" = "1" ]; then
    nohup runuser -u www-data -- php /app/eiou/processors/ContactStatusMessages.php > /dev/null 2>&1 &
    CONTACT_STATUS_PID=$!
    echo "Contact status polling started successfully (PID: $CONTACT_STATUS_PID)"
else
    CONTACT_STATUS_PID=""
    echo "Contact status polling disabled (EIOU_CONTACT_STATUS_ENABLED=$CONTACT_STATUS_ENABLED)"
fi

# -----------------------------------------------------------------------------
# watchdog() - Background process monitor for automatic processor recovery
# -----------------------------------------------------------------------------
# Monitors PHP message processor PIDs and restarts them if they crash.
# Features:
#   - Checks all processors every WATCHDOG_INTERVAL seconds (default: 30)
#   - Enforces RESTART_COOLDOWN between restarts of same processor (default: 60s)
#   - Limits total restarts per processor to MAX_RESTARTS (default: 10)
#   - Tracks P2pMessages, TransactionMessages, CleanupMessages, ContactStatusMessages
#   - Respects SHUTDOWN_FLAG: skips restarts when intentional shutdown was requested
# Note: Watchdog is stopped first during graceful_shutdown() to prevent
#       process respawning during container termination.
# -----------------------------------------------------------------------------
write_tor_gui_status() {
    # Write Tor GUI status file safely — remove-then-write to avoid permission
    # issues when the file was created by a different user (www-data vs root).
    local status_file="/tmp/tor-gui-status"
    rm -f "$status_file" 2>/dev/null
    echo "$1" > "$status_file" 2>/dev/null
    chmod 666 "$status_file" 2>/dev/null
    chown www-data:www-data "$status_file" 2>/dev/null
}

watchdog() {
    local WATCHDOG_INTERVAL=30       # Check every 30 seconds
    local RESTART_COOLDOWN=60        # Minimum seconds between restarts of same processor
    local MAX_RESTARTS=10            # Maximum restarts before giving up

    # Track restart counts and last restart times
    local P2P_RESTARTS=0
    local TRANSACTION_RESTARTS=0
    local CLEANUP_RESTARTS=0
    local CONTACT_STATUS_RESTARTS=0
    local P2P_LAST_RESTART=0
    local TRANSACTION_LAST_RESTART=0
    local CLEANUP_LAST_RESTART=0
    local CONTACT_STATUS_LAST_RESTART=0

    # Tor hidden service self-health check
    local TOR_CHECK_INTERVAL=300     # Check Tor reachability every 5 minutes
    local TOR_INITIAL_GRACE=120      # Wait 120s before first self-check (descriptor propagation)
    local TOR_LAST_CHECK=$(($(date +%s) - TOR_CHECK_INTERVAL + TOR_INITIAL_GRACE))  # First check at ~120s, not immediately
    local TOR_RESTART_COUNT=0
    local TOR_MAX_RESTARTS=5         # Max Tor restart attempts before giving up
    local TOR_RESTART_COOLDOWN=300   # Minimum 5 minutes between periodic Tor restarts
    local TOR_VERIFY_COOLDOWN=90    # Shorter cooldown after post-restart verification failure
    local TOR_SIGNAL_COOLDOWN=60    # Minimum 60 seconds between signal-triggered restarts
    local TOR_LAST_RESTART=$(date +%s)  # Treat initial Tor start as a "restart" for cooldown purposes
    local TOR_RESET_COOLDOWN=300    # Reset restart counter after 5 minutes of no restarts
    local TOR_HS_DIR="/var/lib/tor/hidden_service"

    local WAS_SHUTDOWN=false  # Track shutdown-to-normal transitions

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Watchdog started - monitoring processor PIDs and Tor health"

    while true; do
        sleep $WATCHDOG_INTERVAL
        local CURRENT_TIME=$(date +%s)

        # Skip restart cycle if shutdown was requested via 'eiou shutdown'
        if [ -f "$SHUTDOWN_FLAG" ]; then
            if [ "$WAS_SHUTDOWN" = false ]; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Shutdown flag detected, pausing processor monitoring"
            fi
            WAS_SHUTDOWN=true
            continue
        fi

        # Reset restart counters when coming out of shutdown (e.g., after 'eiou start')
        if [ "$WAS_SHUTDOWN" = true ]; then
            WAS_SHUTDOWN=false
            P2P_RESTARTS=0
            TRANSACTION_RESTARTS=0
            CLEANUP_RESTARTS=0
            CONTACT_STATUS_RESTARTS=0
            P2P_LAST_RESTART=0
            TRANSACTION_LAST_RESTART=0
            CLEANUP_LAST_RESTART=0
            CONTACT_STATUS_LAST_RESTART=0
            TOR_RESTART_COUNT=0
            TOR_LAST_RESTART=$CURRENT_TIME
            TOR_LAST_CHECK=$((CURRENT_TIME - TOR_CHECK_INTERVAL + TOR_INITIAL_GRACE))
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Shutdown flag cleared, resuming processor monitoring with reset counters"
        fi

        # Check P2pMessages processor
        if ! kill -0 "$P2P_PID" 2>/dev/null; then
            local TIME_SINCE_RESTART=$((CURRENT_TIME - P2P_LAST_RESTART))
            if [ $P2P_RESTARTS -lt $MAX_RESTARTS ] && [ $TIME_SINCE_RESTART -ge $RESTART_COOLDOWN ]; then
                P2P_RESTARTS=$((P2P_RESTARTS + 1))
                P2P_LAST_RESTART=$CURRENT_TIME
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: P2pMessages died (was PID $P2P_PID), restarting (attempt $P2P_RESTARTS/$MAX_RESTARTS)..."
                nohup runuser -u www-data -- php /app/eiou/processors/P2pMessages.php > /dev/null 2>&1 &
                P2P_PID=$!
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: P2pMessages restarted (new PID: $P2P_PID)"
            elif [ $P2P_RESTARTS -ge $MAX_RESTARTS ]; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: P2pMessages exceeded max restarts ($MAX_RESTARTS), not restarting"
            fi
        fi

        # Check TransactionMessages processor
        if ! kill -0 "$TRANSACTION_PID" 2>/dev/null; then
            local TIME_SINCE_RESTART=$((CURRENT_TIME - TRANSACTION_LAST_RESTART))
            if [ $TRANSACTION_RESTARTS -lt $MAX_RESTARTS ] && [ $TIME_SINCE_RESTART -ge $RESTART_COOLDOWN ]; then
                TRANSACTION_RESTARTS=$((TRANSACTION_RESTARTS + 1))
                TRANSACTION_LAST_RESTART=$CURRENT_TIME
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: TransactionMessages died (was PID $TRANSACTION_PID), restarting (attempt $TRANSACTION_RESTARTS/$MAX_RESTARTS)..."
                nohup runuser -u www-data -- php /app/eiou/processors/TransactionMessages.php > /dev/null 2>&1 &
                TRANSACTION_PID=$!
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: TransactionMessages restarted (new PID: $TRANSACTION_PID)"
            elif [ $TRANSACTION_RESTARTS -ge $MAX_RESTARTS ]; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: TransactionMessages exceeded max restarts ($MAX_RESTARTS), not restarting"
            fi
        fi

        # Check CleanupMessages processor
        if ! kill -0 "$CLEANUP_PID" 2>/dev/null; then
            local TIME_SINCE_RESTART=$((CURRENT_TIME - CLEANUP_LAST_RESTART))
            if [ $CLEANUP_RESTARTS -lt $MAX_RESTARTS ] && [ $TIME_SINCE_RESTART -ge $RESTART_COOLDOWN ]; then
                CLEANUP_RESTARTS=$((CLEANUP_RESTARTS + 1))
                CLEANUP_LAST_RESTART=$CURRENT_TIME
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: CleanupMessages died (was PID $CLEANUP_PID), restarting (attempt $CLEANUP_RESTARTS/$MAX_RESTARTS)..."
                nohup runuser -u www-data -- php /app/eiou/processors/CleanupMessages.php > /dev/null 2>&1 &
                CLEANUP_PID=$!
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: CleanupMessages restarted (new PID: $CLEANUP_PID)"
            elif [ $CLEANUP_RESTARTS -ge $MAX_RESTARTS ]; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: CleanupMessages exceeded max restarts ($MAX_RESTARTS), not restarting"
            fi
        fi

        # Check ContactStatusMessages processor (only if enabled - skip if CONTACT_STATUS_PID is empty)
        if [ -n "$CONTACT_STATUS_PID" ]; then
            if ! kill -0 "$CONTACT_STATUS_PID" 2>/dev/null; then
                local TIME_SINCE_RESTART=$((CURRENT_TIME - CONTACT_STATUS_LAST_RESTART))
                if [ $CONTACT_STATUS_RESTARTS -lt $MAX_RESTARTS ] && [ $TIME_SINCE_RESTART -ge $RESTART_COOLDOWN ]; then
                    CONTACT_STATUS_RESTARTS=$((CONTACT_STATUS_RESTARTS + 1))
                    CONTACT_STATUS_LAST_RESTART=$CURRENT_TIME
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: ContactStatusMessages died (was PID $CONTACT_STATUS_PID), restarting (attempt $CONTACT_STATUS_RESTARTS/$MAX_RESTARTS)..."
                    nohup runuser -u www-data -- php /app/eiou/processors/ContactStatusMessages.php > /dev/null 2>&1 &
                    CONTACT_STATUS_PID=$!
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: ContactStatusMessages restarted (new PID: $CONTACT_STATUS_PID)"
                elif [ $CONTACT_STATUS_RESTARTS -ge $MAX_RESTARTS ]; then
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: ContactStatusMessages exceeded max restarts ($MAX_RESTARTS), not restarting"
                fi
            fi
        fi

        # =====================================================================
        # Tor SOCKS5 failure signal check (from PHP TransportUtilityService)
        # =====================================================================
        # PHP writes /tmp/tor-restart-requested when a SOCKS5 proxy failure
        # is detected during message sending. This allows immediate restart
        # (within ~30s) instead of waiting for the 5-minute periodic check.
        # =====================================================================
        if [ -n "$tor" ] && [ -f /tmp/tor-restart-requested ]; then
            rm -f /tmp/tor-restart-requested
            local TOR_TIME_SINCE_RESTART=$((CURRENT_TIME - TOR_LAST_RESTART))
            if [ $TOR_RESTART_COUNT -lt $TOR_MAX_RESTARTS ] && [ $TOR_TIME_SINCE_RESTART -ge $TOR_SIGNAL_COOLDOWN ]; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: SOCKS5 failure signal detected — triggering immediate Tor restart"
                TOR_RESTART_COUNT=$((TOR_RESTART_COUNT + 1))
                TOR_LAST_RESTART=$CURRENT_TIME

                if [ -d "$TOR_HS_DIR" ]; then
                    chown -R debian-tor:debian-tor "$TOR_HS_DIR" 2>/dev/null || true
                    chmod 700 "$TOR_HS_DIR" 2>/dev/null || true
                    find "$TOR_HS_DIR" -type f -exec chmod 600 {} \; 2>/dev/null || true
                fi

                pkill -x tor 2>/dev/null || true
                sleep 2
                if service tor start 2>/dev/null; then
                    sleep 5
                    if pgrep -x "tor" > /dev/null 2>&1; then
                        echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restarted successfully via signal (attempt $TOR_RESTART_COUNT/$TOR_MAX_RESTARTS) — verifying hidden service in ~90s"
                        # Schedule a follow-up self-check in 90s to allow descriptor propagation (typically 60-120s)
                        TOR_LAST_CHECK=$((CURRENT_TIME - TOR_CHECK_INTERVAL + 90))
                        # Update GUI status to indicate Tor is restarting
                        write_tor_gui_status "{\"status\":\"restarting\",\"timestamp\":$CURRENT_TIME,\"message\":\"Tor service restarted — verifying connectivity\"}"
                    else
                        echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restart via signal failed — process not running after start"
                        write_tor_gui_status "{\"status\":\"issue\",\"timestamp\":$CURRENT_TIME,\"message\":\"Tor restart failed — connectivity may be limited\"}"
                    fi
                else
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restart via signal — start command failed"
                    write_tor_gui_status "{\"status\":\"issue\",\"timestamp\":$CURRENT_TIME,\"message\":\"Tor restart failed — connectivity may be limited\"}"
                fi
            elif [ $TOR_RESTART_COUNT -ge $TOR_MAX_RESTARTS ]; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: SOCKS5 signal received but max restarts exceeded — waiting for cooldown reset"
            else
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: SOCKS5 signal received but within cooldown — skipping restart"
            fi
        fi

        # =====================================================================
        # Tor hidden service self-health check
        # =====================================================================
        # Periodically verify that our own .onion address is reachable through
        # the SOCKS5 proxy. If unreachable, Tor may have crashed, the hidden
        # service descriptor may have expired, or permissions may have changed.
        # This catches silent failures that leave the node running internally
        # but unreachable over Tor to all contacts.
        # =====================================================================
        local TOR_TIME_SINCE_CHECK=$((CURRENT_TIME - TOR_LAST_CHECK))
        if [ -n "$tor" ] && [ $TOR_TIME_SINCE_CHECK -ge $TOR_CHECK_INTERVAL ]; then
            TOR_LAST_CHECK=$CURRENT_TIME

            # Self-check: curl our own .onion through the SOCKS5 proxy
            if ! curl --socks5-hostname 127.0.0.1:9050 \
                    --connect-timeout 15 \
                    --max-time 20 \
                    --silent \
                    --fail \
                    --output /dev/null \
                    "$tor" 2>/dev/null; then

                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor self-check FAILED — own .onion address unreachable"

                # Check if Tor process is running at all
                if ! pgrep -x "tor" > /dev/null 2>&1; then
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor process is not running"
                else
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor process is running but hidden service unreachable — descriptor may be stale"
                fi

                # Attempt restart if within limits and cooldown
                local TOR_TIME_SINCE_RESTART=$((CURRENT_TIME - TOR_LAST_RESTART))
                if [ $TOR_RESTART_COUNT -lt $TOR_MAX_RESTARTS ] && [ $TOR_TIME_SINCE_RESTART -ge $TOR_RESTART_COOLDOWN ]; then
                    TOR_RESTART_COUNT=$((TOR_RESTART_COUNT + 1))
                    TOR_LAST_RESTART=$CURRENT_TIME
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Restarting Tor to republish hidden service descriptor (attempt $TOR_RESTART_COUNT/$TOR_MAX_RESTARTS)..."

                    # Fix permissions before restart (most common cause of descriptor issues)
                    if [ -d "$TOR_HS_DIR" ]; then
                        chown -R debian-tor:debian-tor "$TOR_HS_DIR" 2>/dev/null || true
                        chmod 700 "$TOR_HS_DIR" 2>/dev/null || true
                        find "$TOR_HS_DIR" -type f -exec chmod 600 {} \; 2>/dev/null || true
                    fi

                    # Restart Tor
                    pkill -x tor 2>/dev/null || true
                    sleep 2
                    if service tor start 2>/dev/null; then
                        sleep 5
                        if pgrep -x "tor" > /dev/null 2>&1; then
                            echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restarted successfully — verifying hidden service in ~90s"
                            # Schedule a follow-up self-check in 90s to allow descriptor propagation (typically 60-120s)
                            TOR_LAST_CHECK=$((CURRENT_TIME - TOR_CHECK_INTERVAL + 90))
                        else
                            echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restart failed — process not running after start"
                        fi
                    else
                        echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restart command failed"
                    fi
                elif [ $TOR_RESTART_COUNT -ge $TOR_MAX_RESTARTS ]; then
                    if [ $TOR_TIME_SINCE_RESTART -ge $TOR_RESET_COOLDOWN ]; then
                        echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Resetting Tor restart counter after 5m cooldown — retrying recovery"
                        TOR_RESTART_COUNT=0
                    else
                        echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor exceeded max restarts ($TOR_MAX_RESTARTS) — will retry after cooldown ($((TOR_RESET_COOLDOWN - TOR_TIME_SINCE_RESTART))s remaining)"
                    fi
                else
                    # Recently restarted — check if enough time for descriptor propagation
                    if [ $TOR_TIME_SINCE_RESTART -ge $TOR_VERIFY_COOLDOWN ]; then
                        # Descriptor had enough time but still unreachable — retry restart
                        TOR_RESTART_COUNT=$((TOR_RESTART_COUNT + 1))
                        TOR_LAST_RESTART=$CURRENT_TIME
                        echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Hidden service still unreachable after ${TOR_TIME_SINCE_RESTART}s — restarting Tor (attempt $TOR_RESTART_COUNT/$TOR_MAX_RESTARTS)..."

                        if [ -d "$TOR_HS_DIR" ]; then
                            chown -R debian-tor:debian-tor "$TOR_HS_DIR" 2>/dev/null || true
                            chmod 700 "$TOR_HS_DIR" 2>/dev/null || true
                            find "$TOR_HS_DIR" -type f -exec chmod 600 {} \; 2>/dev/null || true
                        fi

                        pkill -x tor 2>/dev/null || true
                        sleep 2
                        if service tor start 2>/dev/null; then
                            sleep 5
                            if pgrep -x "tor" > /dev/null 2>&1; then
                                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restarted successfully — verifying hidden service in ~90s"
                                TOR_LAST_CHECK=$((CURRENT_TIME - TOR_CHECK_INTERVAL + 90))
                            else
                                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restart failed — process not running after start"
                            fi
                        else
                            echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor restart command failed"
                        fi
                    else
                        # Still within verification window — just recheck
                        echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor still unreachable after recent restart — rechecking in ~30s"
                        TOR_LAST_CHECK=$((CURRENT_TIME - TOR_CHECK_INTERVAL + 30))
                    fi
                fi
            else
                # Self-check passed — reset restart counter on sustained success
                if [ $TOR_RESTART_COUNT -gt 0 ]; then
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: Tor self-check passed — hidden service reachable again, resetting restart counter"
                    TOR_RESTART_COUNT=0
                    # Update GUI status to indicate Tor has recovered
                    write_tor_gui_status "{\"status\":\"recovered\",\"timestamp\":$CURRENT_TIME,\"message\":\"Tor connectivity restored\"}"
                fi
            fi
        fi
    done
}

# Display alpha/testing reminder before watchdog starts
if type show_alpha_warning_short &>/dev/null; then
    show_alpha_warning_short
fi

# Start watchdog in background
watchdog &
WATCHDOG_PID=$!
echo "Watchdog started (PID: $WATCHDOG_PID)"

echo ""
echo "=========================================="
echo "eIOU Node started successfully!"
echo "All processors running. Ready for graceful shutdown on SIGTERM/SIGINT."
echo "=========================================="

# Keep container running with a wait loop that can be interrupted by signals
# Using 'wait' allows the trap to be processed immediately when signal is received
while true; do
    # Wait for any background process, or sleep if none
    # The sleep allows the trap to be processed
    sleep 1 &
    wait $! 2>/dev/null || true
done