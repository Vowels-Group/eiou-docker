# Copyright 2025-2026 Vowels Group, LLC

# SECURITY NOTE: This container runs as root to manage multiple services (Apache, MariaDB, Tor, cron).
# Each service drops privileges to its appropriate user after startup:
#   - Apache: runs as www-data
#   - MariaDB: runs as mysql
#   - Tor: runs as debian-tor

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
    php-mbstring \
    php-mysql \
    tor \
    && rm -rf /var/lib/apt/lists/*

# Edit /etc/tor/torrc
RUN chmod o+w /etc/tor/torrc && \
    echo "HiddenServiceDir /var/lib/tor/hidden_service/" >> /etc/tor/torrc && \
    echo "HiddenServicePort 80 127.0.0.1:80" >> /etc/tor/torrc && \
    chmod o-w /etc/tor/torrc

# Expose HTTP and HTTPS ports
EXPOSE 80
EXPOSE 443

# Set up Apache2 to accept php in .html files
RUN echo "AddType application/x-httpd-php .html" | tee -a /etc/apache2/apache2.conf

# Set ServerName to suppress Apache warning
RUN echo "ServerName localhost" | tee -a /etc/apache2/apache2.conf

# Enable mod_rewrite for API routing and mod_ssl for HTTPS
RUN a2enmod rewrite ssl

# Create SSL certificate directory
RUN mkdir -p /etc/apache2/ssl

# Add API endpoint alias and GUI assets alias to Apache configuration
# This allows /api/* to be served by the Api.php script
# This allows /gui/assets/* to serve Font Awesome and other static assets
RUN echo 'Alias /api /var/www/html/api' >> /etc/apache2/sites-available/000-default.conf && \
    echo 'Alias /gui/assets /etc/eiou/src/gui/assets' >> /etc/apache2/sites-available/000-default.conf && \
    echo '<Directory /etc/eiou/src/gui/assets>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Require all granted' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Options -Indexes' >> /etc/apache2/sites-available/000-default.conf && \
    echo '</Directory>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '<Directory /var/www/html>' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    AllowOverride All' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    Options -Indexes +FollowSymLinks' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteEngine On' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteCond %{REQUEST_FILENAME} !-f' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteCond %{REQUEST_FILENAME} !-d' >> /etc/apache2/sites-available/000-default.conf && \
    echo '    RewriteRule ^api/(.*)$ /var/www/html/api/index.php [L,QSA]' >> /etc/apache2/sites-available/000-default.conf && \
    echo '</Directory>' >> /etc/apache2/sites-available/000-default.conf

# Create SSL VirtualHost configuration
RUN echo '<VirtualHost *:443>' > /etc/apache2/sites-available/default-ssl.conf && \
    echo '    ServerAdmin webmaster@localhost' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    DocumentRoot /var/www/html' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLEngine on' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLCertificateFile /etc/apache2/ssl/server.crt' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLCertificateKeyFile /etc/apache2/ssl/server.key' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    Alias /api /var/www/html/api' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    Alias /gui/assets /etc/eiou/src/gui/assets' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    <Directory /etc/eiou/src/gui/assets>' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        Require all granted' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        Options -Indexes' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    </Directory>' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    <Directory /var/www/html>' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        AllowOverride All' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        Options -Indexes +FollowSymLinks' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        RewriteEngine On' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        RewriteCond %{REQUEST_FILENAME} !-f' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        RewriteCond %{REQUEST_FILENAME} !-d' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '        RewriteRule ^api/(.*)$ /var/www/html/api/index.php [L,QSA]' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    </Directory>' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    ErrorLog ${APACHE_LOG_DIR}/error.log' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    CustomLog ${APACHE_LOG_DIR}/access.log combined' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '</VirtualHost>' >> /etc/apache2/sites-available/default-ssl.conf

# Enable SSL site (will be activated after certificate is generated in startup.sh)
RUN a2ensite default-ssl

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

RUN chown www-data:www-data /etc/eiou/SecurityInit.php \
    /etc/eiou/Functions.php \
    /etc/eiou/P2pMessages.php \
    /etc/eiou/TransactionMessages.php \
    /etc/eiou/CleanupMessages.php

# Set _directories_ in the /etc/eiou/ directory to 755
RUN find /etc/eiou/ -type d -exec chmod 755 "{}" \;

# Set _files_ in the /etc/eiou/ directory and its subdirectories to 644
RUN find /etc/eiou/ -type f -exec chmod 644 "{}" \;

# Create API directory in web root and symlink to actual Api.php
RUN mkdir -p /var/www/html/api && \
    ln -s /etc/eiou/Api.php /var/www/html/api/index.php

# Enable PHP error logging
RUN sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /etc/php/*/apache2/php.ini
RUN touch /var/log/php_errors.log && \
    chown www-data:www-data /var/log/php_errors.log && \
    chmod 640 /var/log/php_errors.log

# Declare volumes for data persistence
VOLUME ["/var/lib/mysql", "/etc/eiou", "/usr/local/bin/", "/var/www/html/"]

# Copy and set up startup script
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

# Start services using the startup script
ENTRYPOINT ["/startup.sh"]