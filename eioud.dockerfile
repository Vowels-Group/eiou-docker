# Copyright 2025

FROM debian:12-slim

# Install required packages for a LAMP server
RUN apt-get update && apt-get install -y \
    apache2 \
    cron \
    curl \
    mariadb-server \
    openssl \
    php \
    php-curl \
    php-mysql \
    tor 

# Edit /etc/tor/torrc
RUN chmod o+w /etc/tor/torrc
RUN echo "HiddenServiceDir /var/lib/tor/hidden_service/" >> /etc/tor/torrc
RUN echo "HiddenServicePort 80 127.0.0.1:80" >> /etc/tor/torrc
RUN chmod o-w /etc/tor/torrc

# Expose Tor hidden service port
EXPOSE 80

# Set up Apache2 to accept php in .html files
RUN echo "AddType application/x-httpd-php .html" | tee -a /etc/apache2/apache2.conf

# Set ServerName to suppress Apache warning
RUN echo "ServerName localhost" | tee -a /etc/apache2/apache2.conf

# Enable mod_rewrite for API routing
RUN a2enmod rewrite

# Add API endpoint alias to Apache configuration
# This allows /api/* to be served by the Api.php script
RUN echo 'Alias /api /var/www/html/api' >> /etc/apache2/sites-available/000-default.conf && \
    echo '<Directory /var/www/html>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    AllowOverride All' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Options -Indexes +FollowSymLinks' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteEngine On' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteCond %{REQUEST_FILENAME} !-f' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteCond %{REQUEST_FILENAME} !-d' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteRule ^api/(.*)$ /var/www/html/api/index.php [L,QSA]' >> /etc/apache2/sites-available/000-default.conf && \
    echo '</Directory>' >> /etc/apache2/sites-available/000-default.conf

# Copy Eiou.php file to /usr/local/bin and create a wrapper script
COPY files/eiou/Eiou.php /usr/local/bin/eiou.php
RUN echo '#!/bin/bash\nphp /usr/local/bin/eiou.php "$@"' > /usr/local/bin/eiou && \
    chmod +x /usr/local/bin/eiou

# Copy wallet and index files to web directory
COPY files/index/walletIndex.html /var/www/html/index.html
COPY files/index/index.html /var/www/html/eiou/index.html
RUN chown www-data:www-data /var/www/html/eiou -R
RUN chmod 755 /var/www/html/eiou

# Copy root files to a /etc/eiou/
COPY files/root/ /etc/eiou/

# Copy src folder to /etc/eiou/src
COPY files/src/ /etc/eiou/src/

RUN chown www-data:www-data /etc/eiou/SecurityInit.php
RUN chown www-data:www-data /etc/eiou/Functions.php
RUN chown www-data:www-data /etc/eiou/P2pMessages.php
RUN chown www-data:www-data /etc/eiou/TransactionMessages.php
RUN chown www-data:www-data /etc/eiou/CleanupMessages.php

# Set _directories_ in the /etc/eiou/ directory to 755
RUN find /etc/eiou/ -type d -exec chmod 755 "{}" \;

# Set _files_ in the /etc/eiou/ directory and its subdirectories to 644
RUN find /etc/eiou/ -type f -exec chmod 644 "{}" \;

# Create API directory in web root and symlink to actual Api.php
RUN mkdir -p /var/www/html/api && \
    ln -s /etc/eiou/Api.php /var/www/html/api/index.php

# Enable PHP error logging
RUN sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /etc/php/*/apache2/php.ini
RUN touch /var/log/php_errors.log
RUN chmod 666 /var/log/php_errors.log

# Declare volumes for data persistence
VOLUME ["/var/lib/mysql", "/etc/eiou", "/usr/local/bin/", "/var/www/html/"]

# Copy and set up startup script
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

# Start services using the startup script
ENTRYPOINT ["/startup.sh"]