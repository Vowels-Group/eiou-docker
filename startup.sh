#!/bin/bash

# Copyright 2025

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

# Check if all precursors to messages.php are available and working
first=true
while true; do
    if (( $(php -r 'require("//etc//eiou//functions//messageCheck.php"); echo $passed;') )); then
        echo "Message processing check completed successfully."  
        break
    else
        if ($first); then
            echo "Message processing check failed to complete. Retrying every 5 seconds..."
            echo "Please run the 'generate' command to generate a new wallet and setup message processing"
            echo -e "\t 'docker exec [containerName] eiou generate (torAddressOnly)'"
            echo -e "\t or 'eiou generate (torAddressOnly)' from within the container"
            echo -e "\t where (torAddressOnly) is an optional parameter"
            first=false
        fi
        sleep 5
        continue
    fi
done

# Start message processing in background
nohup php /etc/eiou/messages.php > /dev/null 2>&1 &
echo "Message processing started successfully."  

# Keep container running
tail -f /dev/null
