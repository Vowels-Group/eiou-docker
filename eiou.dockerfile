# Copyright 2025-2026 Vowels Group, LLC

# SECURITY NOTE: This container runs as root to manage multiple services (Apache, MariaDB, Tor, cron).
# Each service drops privileges to its appropriate user after startup:
#   - Apache: runs as www-data
#   - MariaDB: runs as mysql
#   - Tor: runs as debian-tor

# =============================================================================
# EIOU Node Docker Image
# =============================================================================
# Builds a complete EIOU node with:
# - Apache2 web server for GUI and API
# - MariaDB database for transactions and contacts
# - Tor for anonymous .onion addressing
# - PHP runtime for application logic
# =============================================================================

FROM debian:12-slim

# Install required packages:
# - apache2: Web server for GUI and REST API endpoints
# - cron: Scheduled task execution for maintenance jobs
# - curl: HTTP client for peer-to-peer communication
# - mariadb-server: Database for wallet, transactions, contacts
# - openssl: SSL certificate generation and cryptography
# - php, php-*: PHP runtime with required extensions
#   - php-xml: DOM extension required for Composer dependency resolution
# - tor: Anonymous network for .onion addresses
# - unzip: Required by Composer for package installation
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
    php-xml \
    tor \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer for PSR-4 autoloading
# Using the official installer script for security
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Configure Tor hidden service:
# - HiddenServiceDir: Directory for Tor identity keys and hostname
# - HiddenServicePort: Maps port 80 to internal Apache
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

# Copy Eiou.php to /etc/eiou/cli (covered by volume sync) and create a wrapper in PATH
COPY files/eiou/Eiou.php /etc/eiou/cli/eiou.php
RUN echo '#!/bin/bash\nphp /etc/eiou/cli/eiou.php "$@"' > /usr/local/bin/eiou && \
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

# Copy composer.json and generate PSR-4 autoloader
# This creates vendor/autoload.php for namespace-based class loading
COPY files/composer.json /etc/eiou/composer.json
RUN cd /etc/eiou && composer install --no-dev --optimize-autoloader --no-interaction

RUN chown www-data:www-data /etc/eiou/SecurityInit.php \
    /etc/eiou/Functions.php \
    /etc/eiou/processors/P2pMessages.php \
    /etc/eiou/processors/TransactionMessages.php \
    /etc/eiou/processors/CleanupMessages.php \
    /etc/eiou/processors/ContactStatusMessages.php

# Set _directories_ in the /etc/eiou/ directory to 755
RUN find /etc/eiou/ -type d -exec chmod 755 "{}" \;

# Set _files_ in the /etc/eiou/ directory and its subdirectories to 644
RUN find /etc/eiou/ -type f -exec chmod 644 "{}" \;

# Create API directory in web root and symlink to actual Api.php
RUN mkdir -p /var/www/html/api && \
    ln -s /etc/eiou/api/Api.php /var/www/html/api/index.php

# Enable PHP error logging
RUN sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /etc/php/*/apache2/php.ini
RUN touch /var/log/php_errors.log && \
    chown www-data:www-data /var/log/php_errors.log && \
    chmod 640 /var/log/php_errors.log

# Persistent volumes:
# - /var/lib/mysql: Database files (transactions, contacts, balances)
# - /etc/eiou: Wallet configuration and encryption keys
# - /var/www/html: Web interface files
# - /var/lib/eiou/backups: Encrypted database backups
VOLUME ["/var/lib/mysql", "/etc/eiou", "/var/www/html/", "/var/lib/eiou/backups"]

# Copy scripts directory (includes banner.sh for warning messages)
COPY scripts/ /app/scripts/
RUN chmod +x /app/scripts/*.sh

# =============================================================================
# SOURCE FILE BACKUP FOR VOLUME SYNC
# =============================================================================
# The /etc/eiou directory is a Docker volume. When existing containers are
# updated, the volume retains old files. We create a backup of source files
# in /app/eiou-src-backup/ that startup.sh will use to sync to the volume.
# This ensures users always get the latest code without losing their data.
# =============================================================================
RUN mkdir -p /app/eiou-src-backup
COPY files/src/ /app/eiou-src-backup/src/
COPY files/root/ /app/eiou-src-backup/
COPY files/composer.json /app/eiou-src-backup/composer.json
COPY files/eiou/Eiou.php /app/eiou-src-backup/cli/eiou.php

# Copy and set up startup script
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

# Health check to verify Apache is responding
# - interval: Check every 30 seconds
# - timeout: Allow 20 seconds for check to complete
# - start-period: Wait 120 seconds before first check (MariaDB needs 30-60s to initialize)
# - retries: Mark unhealthy after 5 consecutive failures
HEALTHCHECK --interval=30s --timeout=20s --start-period=120s --retries=5 \
    CMD curl -f http://localhost/ || exit 1

# Start services using the startup script
ENTRYPOINT ["/startup.sh"]