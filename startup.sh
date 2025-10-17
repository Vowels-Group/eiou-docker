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

# Check if config.php was already made and if so if user keys exist, if not build config
if [[ $(php -r 'require("/etc/eiou/startup/configCheck.php"); echo $run;') ]]; then
    # If quickstart flag is set, automatically run generate command
    if [ "$QUICKSTART" != "false" ]; then
        echo "Quickstart mode enabled. Running generate command with parameter: $QUICKSTART"
        eiou generate http://$QUICKSTART
        echo "Generate command completed."
    fi
fi

# Check if all precursors to messages.php (also checks for cleanup.php by extension) are available and working
first=true
while true; do
    if [[ $(php -r 'require("/etc/eiou/startup/messageCheck.php"); echo $passed;') ]]; then
        echo "Message processing check completed successfully."  
        # Display all user info for quick access
        http=$(php -r 'require("/etc/eiou/config.php"); if(isset($user["hostname"])){echo $user["hostname"];}')
        tor=$(php -r 'require("/etc/eiou/config.php"); echo $user["torAddress"];')
        pubkey=$(php -r 'require("/etc/eiou/config.php"); echo $user["public"];')
        authcode=$(php -r 'require("/etc/eiou/config.php"); echo $user["authcode"];')
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

# Start p2p message processing in background
nohup php /etc/eiou/p2pMessages.php > /dev/null 2>&1 &
echo "P2p message processing started successfully."  

# Start transaction message processing in background
nohup php /etc/eiou/transactionMessages.php > /dev/null 2>&1 &
echo "Transaction message processing started successfully."  

# Start cleanup message processing in background
nohup php /etc/eiou/cleanupMessages.php > /dev/null 2>&1 &
echo "Cleanup processing started successfully."  

# Keep container running
tail -f /dev/null