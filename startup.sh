#!/bin/bash

# Copyright 2025

# Check for quickstart flag
QUICKSTART=${QUICKSTART:-false}

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
if [[ $(php -r 'require_once "/etc/eiou/src/startup/configCheck.php"; echo $run;') ]]; then
    # If quickstart flag is set, automatically run generate command
    if [ "$QUICKSTART" != "false" ]; then
        echo "Quickstart mode enabled. Running generate command with parameter: $QUICKSTART"
        eiou generate http://$QUICKSTART
        echo "Generate command completed."
    fi
fi

# Check if all precursors to the message processors are available and working
first=true
while true; do
    if [[ $(php -r 'require_once "/etc/eiou/src/startup/messageCheck.php"; echo $passed;') ]]; then
        echo "Message processing check completed successfully."  
        # Display all user info for quick access
        http=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["hostname"])){echo $json["hostname"];}')
        tor=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["torAddress"])){echo $json["torAddress"];}')
        pubkey=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["public"])){echo $json["public"];}')
        authcode=$(php -r '$json = json_decode(file_get_contents("/etc/eiou/userconfig.json"),true); if(isset($json["authcode"])){echo $json["authcode"];}')
        echo "User Information: "
        if [[ ! -z ${http} ]]; then
            echo -e "\t HTTP address: $http"
        fi
        echo -e "\t Tor address: $tor"
        readable="${pubkey//$'\n'/$'\n\t\t'}"
        echo -e "\t Public Key: \n\t\t $readable"
        echo -e "\t Authentication Code: $authcode"
        break
    else
        if $first; then
            echo "Message processing check failed to complete. Retrying every 5 seconds..."
            if [ "$QUICKSTART" = "false" ]; then
                echo "Please run the 'generate' command to generate a new wallet and setup message processing"
                echo -e "\t 'docker exec [containerName] eiou generate (torAddressOnly) from thr CLI'"
                echo -e "\t or 'eiou generate (torAddressOnly)' from within the container"
                echo -e "\t where (torAddressOnly) is an optional parameter"
                echo -e "\t or use QUICKSTART=[name] environment variable for automatic setup"
            else
                echo "Quickstart mode: generate command was already run with parameter $QUICKSTART, but message processing still not ready. Retrying..."
            fi
            first=false
        fi
        sleep 5
        continue
    fi
done

# Ensure log directory exists
mkdir -p /var/log/eiou
chmod 755 /var/log/eiou

# Start p2p message processing in background
php /etc/eiou/p2pMessages.php > /var/log/eiou/p2p.log 2>&1 &
P2P_PID=$!
echo "P2p message processing started successfully (PID: $P2P_PID)"

# Start transaction message processing in background
php /etc/eiou/transactionMessages.php > /var/log/eiou/transaction.log 2>&1 &
TRANSACTION_PID=$!
echo "Transaction message processing started successfully (PID: $TRANSACTION_PID)"

# Start cleanup message processing in background
php /etc/eiou/cleanupMessages.php > /var/log/eiou/cleanup.log 2>&1 &
CLEANUP_PID=$!
echo "Cleanup processing started successfully (PID: $CLEANUP_PID)"

# Store PIDs for shutdown handler
echo "$P2P_PID" > /tmp/p2p.pid
echo "$TRANSACTION_PID" > /tmp/transaction.pid
echo "$CLEANUP_PID" > /tmp/cleanup.pid

# Function to handle graceful shutdown
graceful_shutdown() {
    echo "Received shutdown signal, initiating graceful shutdown..."

    # Send SIGTERM to all PHP processes
    if [ -f /tmp/p2p.pid ]; then
        PID=$(cat /tmp/p2p.pid)
        if kill -0 $PID 2>/dev/null; then
            echo "Stopping P2P processor (PID: $PID)..."
            kill -TERM $PID
        fi
    fi

    if [ -f /tmp/transaction.pid ]; then
        PID=$(cat /tmp/transaction.pid)
        if kill -0 $PID 2>/dev/null; then
            echo "Stopping Transaction processor (PID: $PID)..."
            kill -TERM $PID
        fi
    fi

    if [ -f /tmp/cleanup.pid ]; then
        PID=$(cat /tmp/cleanup.pid)
        if kill -0 $PID 2>/dev/null; then
            echo "Stopping Cleanup processor (PID: $PID)..."
            kill -TERM $PID
        fi
    fi

    # Wait for processes to exit gracefully (max 30 seconds)
    echo "Waiting for processors to shutdown gracefully (timeout: 30s)..."
    TIMEOUT=30
    ELAPSED=0

    while [ $ELAPSED -lt $TIMEOUT ]; do
        ALL_STOPPED=true

        for PIDFILE in /tmp/p2p.pid /tmp/transaction.pid /tmp/cleanup.pid; do
            if [ -f $PIDFILE ]; then
                PID=$(cat $PIDFILE)
                if kill -0 $PID 2>/dev/null; then
                    ALL_STOPPED=false
                    break
                fi
            fi
        done

        if $ALL_STOPPED; then
            echo "All processors stopped gracefully"
            break
        fi

        sleep 1
        ELAPSED=$((ELAPSED + 1))
    done

    # Force kill any remaining processes
    if [ $ELAPSED -ge $TIMEOUT ]; then
        echo "Timeout reached, forcing shutdown..."
        for PIDFILE in /tmp/p2p.pid /tmp/transaction.pid /tmp/cleanup.pid; do
            if [ -f $PIDFILE ]; then
                PID=$(cat $PIDFILE)
                if kill -0 $PID 2>/dev/null; then
                    echo "Force killing PID: $PID"
                    kill -KILL $PID 2>/dev/null || true
                fi
            fi
        done
    fi

    # Cleanup PID files
    rm -f /tmp/p2p.pid /tmp/transaction.pid /tmp/cleanup.pid

    echo "Shutdown complete"
    exit 0
}

# Register signal handlers
trap graceful_shutdown SIGTERM SIGINT

# Keep container running and wait for signals
echo "All processors running. Container ready. Press Ctrl+C to stop."
wait