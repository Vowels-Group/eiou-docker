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
nohup php /etc/eiou/messages.php > /dev/null 2>&1 &

# Keep container running
tail -f /dev/null
