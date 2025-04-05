#!/bin/bash

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


while true; do
    if (( $(php -r 'require("//etc//eiou//functions//messageCheck.php"); echo $passed;') )); then
        echo "Message processing check completed successfully."  
        break
    else
        echo "Message processing check failed to complete. Retrying in 5 seconds..."
        sleep 5
        continue
    fi
done

# Start message processing in background
nohup php /etc/eiou/messages.php > /dev/null 2>&1 &
echo "Message processing started successfully."  

# Keep container running
tail -f /dev/null
