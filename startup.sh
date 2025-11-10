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
    else
        # Run automatically without hostname (only tor)
        eiou generate
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
            first=false
        fi
        sleep 5
        continue
    fi
done

# Start p2p message processing in background
nohup php /etc/eiou/p2pMessages.php > /dev/null 2>&1 &
echo "P2p message processing started successfully (PID: $!)"

# Start transaction message processing in background
nohup php /etc/eiou/transactionMessages.php > /dev/null 2>&1 &
echo "Transaction message processing started successfully (PID: $!)"

# Start cleanup message processing in background
nohup php /etc/eiou/cleanupMessages.php > /dev/null 2>&1 &
echo "Cleanup processing started successfully (PID: $!)"

# Keep container running
tail -f /dev/null