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

# Start message processing in background
# while docker ps | grep -q "messages"; do
#     echo "Stopping existing message processing..."
#     docker stop messages
#     sleep 1
# done


while true; do
    # passeD=
    # echo $passeD
    if (( $(php -r 'require("//var//www//html//eiou//functions//messageCheck.php"); echo $passed;') )); then
        echo "Message processing started successfully."      
        # Start message processing in background
        nohup php /var/www/html/eiou/messages.php > /dev/null 2>&1 &
        # Keep container running
        tail -f /dev/null
        break
    else
        echo "Message processing check failed to complete. Retrying in 5 seconds..."
        sleep 5
        continue
    fi
done

echo "Startup script completed.";
