#!/bin/bash
# Copyright 2025 The Vowels Company

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
TOR_ELAPSED=0
TOR_TEST_URL="${tor}"

while [ $TOR_ELAPSED -lt $TOR_MAX_WAIT ]; do
    # Try to access a known .onion address through Tor's SOCKS proxy
    if curl --socks5-hostname 127.0.0.1:9050 \
            --connect-timeout 5 \
            --max-time 10 \
            --silent \
            --fail \
            --output /dev/null \
            "$TOR_TEST_URL" 2>/dev/null; then
        echo "Tor connected successfully"
        break
    fi

    # If not connected yet, wait and increment counter
    if [ $TOR_ELAPSED -eq 0 ]; then
        echo "Waiting for Tor connection (timeout: ${TOR_MAX_WAIT}s)..."
    fi
    sleep 2
    TOR_ELAPSED=$((TOR_ELAPSED + 2))
done

# Check if Tor connection was established
if [ $TOR_ELAPSED -ge $TOR_MAX_WAIT ]; then
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