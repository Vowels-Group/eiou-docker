#!/bin/bash
# Copyright 2025-2026 Vowels Group, LLC

# =============================================================================
# startup.sh - EIOU Node Container Entrypoint
# =============================================================================
# This script initializes and runs an EIOU node container.
#
# Startup Sequence:
#   1. Configure output buffering for real-time logging
#   2. Register signal handlers for graceful shutdown (SIGTERM, SIGINT, SIGHUP)
#   3. Generate or install SSL certificates
#   4. Start services (cron, tor, apache2, mariadb)
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
#   QUICKSTART          - Hostname for quickstart mode (e.g., "alice")
#   RESTORE             - 24-word seed phrase for wallet restoration
#   RESTORE_FILE        - Path to file containing seed phrase (more secure)
#   SSL_DOMAIN          - Primary domain for SSL certificate CN
#   SSL_EXTRA_SANS      - Additional Subject Alternative Names for SSL
#   EIOU_HS_TIMEOUT     - Tor hidden service timeout in seconds (default: 60)
#   EIOU_TOR_TIMEOUT    - Tor connectivity timeout in seconds (default: 120)
#   EIOU_TEST_MODE      - Enable test mode for manual message processing
#   EIOU_CONTACT_STATUS_ENABLED - Enable contact status ping feature
#
# =============================================================================

# Enable unbuffered output for real-time docker logs
exec 1> >(stdbuf -oL cat)
exec 2> >(stdbuf -oL cat >&2)

# Source the banner script for warning messages
# This file can be edited to update the warning banners without modifying startup.sh
if [ -f "/app/scripts/banner.sh" ]; then
    source /app/scripts/banner.sh
    # Display alpha/testing warning at container start
    show_alpha_warning
fi

# Graceful shutdown configuration
SHUTDOWN_TIMEOUT=30  # Maximum seconds to wait for processes to terminate
SHUTDOWN_IN_PROGRESS=false

# Store background process PIDs
P2P_PID=""
TRANSACTION_PID=""
CLEANUP_PID=""

# -----------------------------------------------------------------------------
# graceful_shutdown() - Signal handler for clean container termination
# -----------------------------------------------------------------------------
# Called on SIGTERM, SIGINT, or SIGHUP signals. Performs ordered shutdown:
#   1. Stop watchdog to prevent process respawning during shutdown
#   2. Send SIGTERM to PHP message processors and wait for completion
#   3. Force kill any processors that exceed timeout (SHUTDOWN_TIMEOUT)
#   4. Stop services in reverse startup order (apache2, mariadb, tor, cron)
#   5. Clean up lockfiles to prevent stale state on next startup
# -----------------------------------------------------------------------------
graceful_shutdown() {
    # Prevent duplicate shutdown handling
    if [ "$SHUTDOWN_IN_PROGRESS" = true ]; then
        return
    fi
    SHUTDOWN_IN_PROGRESS=true

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

    # Also check for any PHP processors by their lockfiles
    for lockfile in /tmp/p2pmessages_lock.pid /tmp/transactionmessages_lock.pid /tmp/cleanupmessages_lock.pid; do
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
    else
        echo "[Shutdown] No PHP processors were running"
    fi

    # Step 3: Stop services in reverse order of startup
    echo "[Shutdown] Stopping services in reverse order..."

    echo "[Shutdown] Stopping Apache..."
    service apache2 stop 2>/dev/null || true

    echo "[Shutdown] Stopping MariaDB..."
    service mariadb stop 2>/dev/null || true

    echo "[Shutdown] Stopping Tor..."
    service tor stop 2>/dev/null || true

    echo "[Shutdown] Stopping Cron..."
    service cron stop 2>/dev/null || true

    # Step 4: Clean up lockfiles
    echo "[Shutdown] Cleaning up lockfiles..."
    rm -f /tmp/p2pmessages_lock.pid 2>/dev/null
    rm -f /tmp/transactionmessages_lock.pid 2>/dev/null
    rm -f /tmp/cleanupmessages_lock.pid 2>/dev/null

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
# SSL CERTIFICATE GENERATION
# =============================================================================
# Environment Variables:
#   SSL_DOMAIN      - Primary domain for certificate CN (default: QUICKSTART value or localhost)
#   SSL_EXTRA_SANS  - Additional SANs in format "DNS:name,IP:addr" (comma-separated)
#
# External Certificate Support:
#   Mount certificates at /ssl-certs/ with files: server.crt, server.key, ca-chain.crt (optional)
#
# CA-Signed Certificate Support:
#   Mount CA at /ssl-ca/ with files: ca.crt, ca.key
#
# Priority: 1. External certs (/ssl-certs/) 2. CA-signed (/ssl-ca/) 3. Auto-generated
# =============================================================================

# Check for externally provided certificates (Let's Encrypt, corporate CA, etc.)
if [ -f /ssl-certs/server.crt ] && [ -f /ssl-certs/server.key ]; then
    echo "Installing externally provided SSL certificates..."

    cp /ssl-certs/server.crt /etc/apache2/ssl/server.crt
    cp /ssl-certs/server.key /etc/apache2/ssl/server.key
    chmod 600 /etc/apache2/ssl/server.key
    chmod 644 /etc/apache2/ssl/server.crt

    # Handle certificate chain if provided (for Let's Encrypt fullchain, etc.)
    if [ -f /ssl-certs/ca-chain.crt ]; then
        cp /ssl-certs/ca-chain.crt /etc/apache2/ssl/ca-chain.crt
        chmod 644 /etc/apache2/ssl/ca-chain.crt
        echo "  Certificate chain installed."
    fi

    echo "External SSL certificates installed successfully."

# Generate certificate (self-signed or CA-signed) if none exists
elif [ ! -f /etc/apache2/ssl/server.crt ]; then
    echo "Generating SSL certificate..."

    # Determine primary CN
    # Priority: SSL_DOMAIN env var > QUICKSTART hostname > localhost
    SSL_DOMAIN=${SSL_DOMAIN:-${QUICKSTART:-localhost}}
    if [ "$SSL_DOMAIN" = "false" ]; then
        SSL_DOMAIN="localhost"
    fi

    # Build Subject Alternative Names list
    SAN_LIST="DNS:localhost,DNS:$SSL_DOMAIN"

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
    if [ -n "$SSL_EXTRA_SANS" ]; then
        SAN_LIST="$SAN_LIST,$SSL_EXTRA_SANS"
    fi

    echo "  CN: $SSL_DOMAIN"
    echo "  SANs: $SAN_LIST"

    # Create OpenSSL configuration with SANs
    # Modern browsers require SANs - CN alone is deprecated
    cat > /tmp/openssl-san.cnf << SSLEOF
[req]
default_bits = 2048
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

        # Generate private key and CSR
        openssl req -new -nodes -newkey rsa:2048 \
            -keyout /etc/apache2/ssl/server.key \
            -out /tmp/server.csr \
            -config /tmp/openssl-san.cnf \
            2>/dev/null

        # Sign with CA (browsers will trust this if CA is in their trust store)
        openssl x509 -req -in /tmp/server.csr \
            -CA /ssl-ca/ca.crt \
            -CAkey /ssl-ca/ca.key \
            -CAcreateserial \
            -out /etc/apache2/ssl/server.crt \
            -days 365 \
            -sha256 \
            -extfile /tmp/openssl-san.cnf \
            -extensions v3_ext \
            2>/dev/null

        # Copy CA cert for client verification
        cp /ssl-ca/ca.crt /etc/apache2/ssl/ca.crt
        chmod 644 /etc/apache2/ssl/ca.crt

        rm -f /tmp/server.csr
        echo "  CA-signed certificate generated successfully."
    else
        # Generate self-signed certificate
        echo "  Generating self-signed certificate..."

        openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
            -keyout /etc/apache2/ssl/server.key \
            -out /etc/apache2/ssl/server.crt \
            -config /tmp/openssl-san.cnf \
            2>/dev/null

        echo "  Self-signed certificate generated successfully."
        echo "  Note: Browsers will show warnings for self-signed certificates."
        echo "  For trusted certificates, mount a CA at /ssl-ca/ or external certs at /ssl-certs/"
    fi

    rm -f /tmp/openssl-san.cnf
    chmod 600 /etc/apache2/ssl/server.key
    chmod 644 /etc/apache2/ssl/server.crt
else
    echo "Existing SSL certificate found, skipping generation."
fi

# =============================================================================
# SOURCE FILE SYNC (Docker Volume Update)
# =============================================================================
# The /etc/eiou directory is a Docker volume. When the image is updated, the
# volume retains old files. This section syncs source files from a backup
# location in the image to ensure the latest code is always used.
#
# Files synced: src/, root PHP files, composer.json
# Files preserved: config/userconfig.json, config/dbconfig.json, config/ encryption keys
# =============================================================================

# Debug: Show source backup status
echo "Checking for source file backup..."
if [ -d /app/eiou-src-backup ]; then
    echo "  Backup directory found at /app/eiou-src-backup"
    ls -la /app/eiou-src-backup/ 2>/dev/null | head -5
else
    echo "  WARNING: Backup directory /app/eiou-src-backup not found!"
    echo "  This means the Docker image was not rebuilt with --build flag."
    echo "  Run: docker-compose -f <compose-file>.yml up -d --build"
fi

# Check if source backup exists (created during docker build)
if [ -d /app/eiou-src-backup ]; then
    echo "Syncing source files from image to volume..."

    # Sync src directory (namespaced code)
    if [ -d /app/eiou-src-backup/src ]; then
        cp -r /app/eiou-src-backup/src/* /etc/eiou/src/ 2>/dev/null || true
        echo "  Source code updated."
    fi

    # Sync root files to /etc/eiou/ (includes api/, cli/, processors/, www/, *.php)
    if [ -d /app/eiou-src-backup/root ]; then
        cp -r /app/eiou-src-backup/root/* /etc/eiou/ 2>/dev/null || true
        echo "  Root files updated."
    fi

    # Sync composer.json
    if [ -f /app/eiou-src-backup/composer.json ]; then
        cp /app/eiou-src-backup/composer.json /etc/eiou/composer.json 2>/dev/null || true
        echo "  Composer config updated."
    fi

    # Reapply permissions after sync (mirroring dockerfile build steps)
    find /etc/eiou/ -type d -exec chmod 755 "{}" \;
    find /etc/eiou/ -type f -exec chmod 644 "{}" \;
    chown www-data:www-data /etc/eiou/SecurityInit.php \
        /etc/eiou/Functions.php \
        /etc/eiou/processors/P2pMessages.php \
        /etc/eiou/processors/TransactionMessages.php \
        /etc/eiou/processors/CleanupMessages.php \
        /etc/eiou/processors/ContactStatusMessages.php 2>/dev/null || true
    echo "  Permissions reapplied."

    echo "Source file sync completed."
fi

# =============================================================================
# CONFIG FILE MIGRATION
# =============================================================================
# Move config files from /etc/eiou/ root to /etc/eiou/config/ subdirectory.
# This handles existing containers that have config files at the old location.
# =============================================================================
mkdir -p /etc/eiou/config
if [ -f /etc/eiou/userconfig.json ] && [ ! -f /etc/eiou/config/userconfig.json ]; then
    mv /etc/eiou/userconfig.json /etc/eiou/config/userconfig.json 2>/dev/null || true
    echo "Migrated userconfig.json to config/"
fi
if [ -f /etc/eiou/dbconfig.json ] && [ ! -f /etc/eiou/config/dbconfig.json ]; then
    mv /etc/eiou/dbconfig.json /etc/eiou/config/dbconfig.json 2>/dev/null || true
    echo "Migrated dbconfig.json to config/"
fi
if [ -f /etc/eiou/defaultconfig.json ] && [ ! -f /etc/eiou/config/defaultconfig.json ]; then
    mv /etc/eiou/defaultconfig.json /etc/eiou/config/defaultconfig.json 2>/dev/null || true
    echo "Migrated defaultconfig.json to config/"
fi
if [ -f /etc/eiou/.master.key ] && [ ! -f /etc/eiou/config/.master.key ]; then
    mv /etc/eiou/.master.key /etc/eiou/config/.master.key 2>/dev/null || true
    echo "Migrated .master.key to config/"
fi

# =============================================================================
# COMPOSER AUTOLOADER SETUP
# =============================================================================
# Ensure the Composer autoloader exists and is up-to-date.
# =============================================================================

# Always regenerate autoloader after source sync to ensure class map is current
if [ -d /app/eiou-src-backup ] || [ ! -f /etc/eiou/vendor/autoload.php ]; then
    echo "Generating Composer autoloader..."

    # Ensure composer.json exists
    if [ ! -f /etc/eiou/composer.json ]; then
        echo "  Creating composer.json..."
        cat > /etc/eiou/composer.json << 'COMPOSEREOF'
{
    "name": "eiou/node",
    "description": "EIOU Node - Distributed IOU Network",
    "type": "project",
    "license": "proprietary",
    "autoload": {
        "classmap": ["src/"],
        "files": [
            "src/database/Pdo.php",
            "src/database/DatabaseSetup.php",
            "src/database/DatabaseSchema.php",
            "src/services/ServiceWrappers.php",
            "src/schemas/OutputSchema.php"
        ]
    },
    "require": {
        "php": ">=8.1"
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
COMPOSEREOF
    fi

    # Run composer install/dump-autoload to generate autoloader
    cd /etc/eiou && composer dump-autoload --optimize --no-interaction 2>&1 | while read line; do
        echo "  $line"
    done

    if [ -f /etc/eiou/vendor/autoload.php ]; then
        echo "Composer autoloader generated successfully."
    else
        echo "ERROR: Failed to generate autoloader. PHP functionality may be impaired."
    fi
else
    echo "Composer autoloader found."
fi

# Start services
service cron start
service tor start
service apache2 start
service mariadb start

# Wait for MariaDB to be ready
while ! mysqladmin ping -h localhost --silent; do
    echo "Waiting for MariaDB to be ready..."
    sleep 1
done

# Check if config/userconfig.json was already made and if so if user keys exist, if not build config
if [[ $(php -r 'require_once "/etc/eiou/src/startup/ConfigCheck.php"; echo $run;') ]]; then
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
    elif [ "$QUICKSTART" != "false" ]; then
        echo "Quickstart mode enabled. Running generate command with parameter: $QUICKSTART"
        # Use HTTPS for secure P2P communication (SSL certificates are auto-generated)
        eiou generate https://$QUICKSTART
        echo "Generate command completed."
    else
        # Run automatically without hostname (only tor)
        eiou generate
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
    if [ -d "$HS_DIR" ]; then
        chown -R debian-tor:debian-tor "$HS_DIR" 2>/dev/null || true
        chmod 700 "$HS_DIR" 2>/dev/null || true
        find "$HS_DIR" -type f -exec chmod 600 {} \; 2>/dev/null || true
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
fi

# Check if all precursors to the message processors are available and working
first=true
while true; do
    if [[ $(php -r 'require_once "/etc/eiou/src/startup/MessageCheck.php"; echo $passed;') ]]; then
        echo "Message processing check completed successfully."  
        # Display all user info for quick access
        http=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"),true); if(isset($json["hostname"])){echo $json["hostname"];}')
        tor=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"),true); if(isset($json["torAddress"])){echo $json["torAddress"];}')
        pubkey=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/config/userconfig.json"),true); if(isset($json["public"])){echo $json["public"];}')
        authcode=$(php -r 'require_once("/etc/eiou/src/bootstrap.php"); echo Eiou\Core\UserContext::getInstance()->getAuthCode();')
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
    echo -e "\t HTTP address: $httpAddr"
    echo -e "\t HTTPS address: $httpsAddr"
fi
echo -e "\t Tor address: $tor"
readable="${pubkey//$'\n'/$'\n\t\t'}"
echo -e "\t Public Key: \n\t\t $readable"
echo -e "\t Authentication Code: (see secure temp file)"

# ========================
# Backup System Setup
# ========================
echo "Setting up backup system..."

# Create log directory if it doesn't exist
mkdir -p /var/log/eiou
chown www-data:www-data /var/log/eiou
chmod 755 /var/log/eiou

# Create backup directory with proper permissions
mkdir -p /var/lib/eiou/backups
chown www-data:www-data /var/lib/eiou/backups
chmod 700 /var/lib/eiou/backups

# Set up cron for daily backups at midnight (if auto backup is enabled by default)
if [ "${EIOU_BACKUP_AUTO_ENABLED:-true}" = "true" ]; then
    # Ensure cron service is running
    service cron start 2>/dev/null || true

    # Install backup cron job (daily at midnight)
    CRON_JOB="0 0 * * * /usr/bin/php /etc/eiou/scripts/backup-cron.php >> /var/log/eiou/backup.log 2>&1"

    # Remove existing backup cron entry and add new one
    (crontab -l 2>/dev/null | grep -v "backup-cron.php"; echo "$CRON_JOB") | crontab -

    echo "Backup cron job installed (daily at midnight)"
else
    echo "Automatic backups disabled via EIOU_BACKUP_AUTO_ENABLED"
fi

# Start p2p message processing in background
nohup php /etc/eiou/processors/P2pMessages.php > /dev/null 2>&1 &
P2P_PID=$!
echo "P2p message processing started successfully (PID: $P2P_PID)"

# Start transaction message processing in background
nohup php /etc/eiou/processors/TransactionMessages.php > /dev/null 2>&1 &
TRANSACTION_PID=$!
echo "Transaction message processing started successfully (PID: $TRANSACTION_PID)"

# Start cleanup message processing in background
nohup php /etc/eiou/processors/CleanupMessages.php > /dev/null 2>&1 &
CLEANUP_PID=$!
echo "Cleanup processing started successfully (PID: $CLEANUP_PID)"

# Start contact status polling in background (optional - respects EIOU_CONTACT_STATUS_ENABLED)
# Check if contact status is enabled before starting (default: true if not set)
CONTACT_STATUS_ENABLED="${EIOU_CONTACT_STATUS_ENABLED:-true}"
if [ "$CONTACT_STATUS_ENABLED" = "true" ] || [ "$CONTACT_STATUS_ENABLED" = "1" ]; then
    nohup php /etc/eiou/processors/ContactStatusMessages.php > /dev/null 2>&1 &
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
# Note: Watchdog is stopped first during graceful_shutdown() to prevent
#       process respawning during container termination.
# -----------------------------------------------------------------------------
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

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Watchdog started - monitoring processor PIDs"

    while true; do
        sleep $WATCHDOG_INTERVAL
        local CURRENT_TIME=$(date +%s)

        # Check P2pMessages processor
        if ! kill -0 "$P2P_PID" 2>/dev/null; then
            local TIME_SINCE_RESTART=$((CURRENT_TIME - P2P_LAST_RESTART))
            if [ $P2P_RESTARTS -lt $MAX_RESTARTS ] && [ $TIME_SINCE_RESTART -ge $RESTART_COOLDOWN ]; then
                P2P_RESTARTS=$((P2P_RESTARTS + 1))
                P2P_LAST_RESTART=$CURRENT_TIME
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: P2pMessages died (was PID $P2P_PID), restarting (attempt $P2P_RESTARTS/$MAX_RESTARTS)..."
                nohup php /etc/eiou/processors/P2pMessages.php > /dev/null 2>&1 &
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
                nohup php /etc/eiou/processors/TransactionMessages.php > /dev/null 2>&1 &
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
                nohup php /etc/eiou/processors/CleanupMessages.php > /dev/null 2>&1 &
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
                    nohup php /etc/eiou/processors/ContactStatusMessages.php > /dev/null 2>&1 &
                    CONTACT_STATUS_PID=$!
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: ContactStatusMessages restarted (new PID: $CONTACT_STATUS_PID)"
                elif [ $CONTACT_STATUS_RESTARTS -ge $MAX_RESTARTS ]; then
                    echo "[$(date '+%Y-%m-%d %H:%M:%S')] WATCHDOG: ContactStatusMessages exceeded max restarts ($MAX_RESTARTS), not restarting"
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
echo "EIOU Node started successfully!"
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