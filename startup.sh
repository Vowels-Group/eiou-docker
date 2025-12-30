#!/bin/bash
# Copyright 2025 The Vowels Company

# Enable unbuffered output for real-time docker logs
exec 1> >(stdbuf -oL cat)
exec 2> >(stdbuf -oL cat >&2)

# Check for quickstart flag
QUICKSTART=${QUICKSTART:-false}

# Check for restore flag (24-word seed phrase)
#    RESTORE="word1 word2 .... word24"
RESTORE=${RESTORE:-false}

# Generate self-signed SSL certificate if it doesn't exist
if [ ! -f /etc/apache2/ssl/server.crt ]; then
    echo "Generating self-signed SSL certificate..."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/apache2/ssl/server.key \
        -out /etc/apache2/ssl/server.crt \
        -subj "/C=XX/ST=State/L=City/O=EIOU/OU=Node/CN=localhost" \
        2>/dev/null
    chmod 600 /etc/apache2/ssl/server.key
    chmod 644 /etc/apache2/ssl/server.crt
    echo "SSL certificate generated successfully."
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

# Check if userconfig.json was already made and if so if user keys exist, if not build config
if [[ $(php -r 'require_once "/etc/eiou/src/startup/ConfigCheck.php"; echo $run;') ]]; then
    # RESTORE takes priority over QUICKSTART
    if [ "$RESTORE" != "false" ]; then
        echo "Restore mode enabled. Restoring wallet from provided seed phrase..."
        eiou generate restore $RESTORE
        echo "Wallet restore completed."
    elif [ "$QUICKSTART" != "false" ]; then
        echo "Quickstart mode enabled. Running generate command with parameter: $QUICKSTART"
        eiou generate http://$QUICKSTART
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
    HS_MAX_WAIT=30  # Maximum seconds to wait for hidden service

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
        http=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["hostname"])){echo $json["hostname"];}')
        tor=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["torAddress"])){echo $json["torAddress"];}')
        pubkey=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["public"])){echo $json["public"];}')
        authcode=$(php -r 'require_once("/etc/eiou/src/core/UserContext.php"); echo UserContext::getInstance()->getAuthCode();')
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
TOR_MAX_WAIT=60  # Maximum wait time in seconds
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
    echo -e "\t HTTP address: $http"
    # Also show HTTPS address (replace http:// with https://)
    https="${http/http:\/\//https:\/\/}"
    echo -e "\t HTTPS address: $https"
fi
echo -e "\t Tor address: $tor"
readable="${pubkey//$'\n'/$'\n\t\t'}"
echo -e "\t Public Key: \n\t\t $readable"
echo -e "\t Authentication Code: $authcode"


# Start p2p message processing in background
nohup php /etc/eiou/P2pMessages.php > /dev/null 2>&1 &
echo "P2p message processing started successfully (PID: $!)"

# Start transaction message processing in background
nohup php /etc/eiou/TransactionMessages.php > /dev/null 2>&1 &
echo "Transaction message processing started successfully (PID: $!)"

# Start cleanup message processing in background
nohup php /etc/eiou/CleanupMessages.php > /dev/null 2>&1 &
echo "Cleanup processing started successfully (PID: $!)"

# Keep container running
tail -f /dev/null