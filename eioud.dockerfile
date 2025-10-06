# Copyright 2025

FROM debian:12-slim

# Install required packages for a LAMP server
RUN apt-get update && apt-get install -y \
    apache2 \
    cron \
    mariadb-server \
    openssl \
    php \
    php-curl \
    php-mysql \
    tor 

# Edit /etc/tor/torrc
RUN  chmod o+w /etc/tor/torrc
RUN echo "HiddenServiceDir /var/lib/tor/hidden_service/" >> /etc/tor/torrc
RUN echo "HiddenServicePort 80 127.0.0.1:80" >> /etc/tor/torrc
RUN  chmod o-w /etc/tor/torrc

# Expose Tor hidden service port
EXPOSE 80

# Set up Apache2 to accept php in .html files
RUN echo "AddType application/x-httpd-php .html" | tee -a /etc/apache2/apache2.conf

# Set ServerName to suppress Apache warning
RUN echo "ServerName localhost" | tee -a /etc/apache2/apache2.conf

# Copy eiou.php file to /usr/local/bin and create a wrapper script
COPY src/eiou.php /usr/local/bin/eiou.php
RUN echo '#!/bin/bash\nphp /usr/local/bin/eiou.php "$@"' > /usr/local/bin/eiou && \
    chmod +x /usr/local/bin/eiou

# Copy wallet and index files to web directory
COPY src/walletIndex.html /var/www/html/index.html
COPY src/index.html /var/www/html/eiou/index.html
RUN chown www-data:www-data /var/www/html/eiou -R
RUN chmod 755 /var/www/html/eiou

# Copy wallet (GUI) folder to /etc/eiou/src/wallet
COPY src/gui /etc/eiou/src/gui
RUN chmod 755 /etc/eiou/src/gui
RUN chmod 644 /etc/eiou/src/gui/*
RUN chmod 755 /etc/eiou/src/gui/assets
RUN chmod 644 /etc/eiou/src/gui/assets/*
RUN chmod 755 /etc/eiou/src/gui/assets/css
RUN chmod 644 /etc/eiou/src/gui/assets/css/*
RUN chmod 755 /etc/eiou/src/gui/assets/js
RUN chmod 644 /etc/eiou/src/gui/assets/js/*
RUN chmod 755 /etc/eiou/src/gui/functions
RUN chmod 644 /etc/eiou/src/gui/functions/*
RUN chmod 755 /etc/eiou/src/gui/layout
RUN chmod 644 /etc/eiou/src/gui/layout/*
RUN chmod 755 /etc/eiou/src/gui/layout/walletSubParts
RUN chmod 644 /etc/eiou/src/gui/layout/walletSubParts/*

# Copy functions.php to a common location
COPY src/functions.php /etc/eiou/functions.php
RUN chown www-data:www-data /etc/eiou/functions.php
RUN chmod 644 /etc/eiou/functions.php

# Copy functions folder to /etc/eiou/src/functions
COPY src/functions /etc/eiou/src/functions
RUN chmod 644 /etc/eiou/src/functions/*

# Copy config folder to /etc/eiou/src/config
COPY src/config /etc/eiou/src/config
RUN chmod 755 /etc/eiou/src/config
RUN chmod 644 /etc/eiou/src/config/*

# Copy core folder to /etc/eiou/src/core
COPY src/core /etc/eiou/src/core
RUN chmod 755 /etc/eiou/src/core
RUN chmod 644 /etc/eiou/src/core/*


# Copy database folder to /etc/eiou/src/database
COPY src/database /etc/eiou/src/database
RUN chmod 755 /etc/eiou/src/database
RUN chmod 644 /etc/eiou/src/database/*

# Copy schemas folder to /etc/eiou/src/schemas
COPY src/schemas /etc/eiou/src/schemas
RUN chmod 755 /etc/eiou/src/schemas
RUN chmod 644 /etc/eiou/src/schemas/*
RUN chmod 755 /etc/eiou/src/schemas/payloads
RUN chmod 644 /etc/eiou/src/schemas/payloads/*

# Copy utils folder to /etc/eiou/src/utils
COPY src/utils /etc/eiou/src/utils
RUN chmod 755 /etc/eiou/src/utils
RUN chmod 644 /etc/eiou/src/utils/*

# Copy startup folder to /etc/eiou/src/startup
COPY src/startup /etc/eiou/src/startup
RUN chmod 755 /etc/eiou/src/startup
RUN chmod 644 /etc/eiou/src/startup/*

# Copy p2pMessages.php to a common location
COPY src/p2pMessages.php /etc/eiou/p2pMessages.php
RUN chown www-data:www-data /etc/eiou/p2pMessages.php
RUN chmod 644 /etc/eiou/p2pMessages.php

# Copy transactionMessages.php to a common location
COPY src/transactionMessages.php /etc/eiou/transactionMessages.php
RUN chown www-data:www-data /etc/eiou/transactionMessages.php
RUN chmod 644 /etc/eiou/transactionMessages.php

# Copy cleanupMessages.php to a common location
COPY src/cleanupMessages.php /etc/eiou/cleanupMessages.php
RUN chown www-data:www-data /etc/eiou/cleanupMessages.php
RUN chmod 644 /etc/eiou/cleanupMessages.php

# Enable PHP error logging
RUN sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /etc/php/*/apache2/php.ini
RUN touch /var/log/php_errors.log
RUN chmod 666 /var/log/php_errors.log

# Declare volumes for data persistence
VOLUME ["/var/lib/mysql", "/etc/eiou"]

# Copy and set up startup script
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

# Start services using the startup script
ENTRYPOINT ["/startup.sh"]