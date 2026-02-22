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
# - certbot: Let's Encrypt ACME client for automatic SSL certificates
# - openssl: SSL certificate generation and cryptography
# - php, php-*: PHP runtime with required extensions
#   - php-xml: DOM extension required for Composer dependency resolution
# - tor: Anonymous network for .onion addresses
# - unzip: Required by Composer for package installation
RUN apt-get update && apt-get install -y \
    apache2 \
    certbot \
    cron \
    curl \
    mariadb-server \
    openssl \
    php \
    php-curl \
    php-mbstring \
    php-mysql \
    php-xml \
    logrotate \
    tor \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer for PSR-4 autoloading
# Hash-verified install to prevent supply chain attacks
RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && EXPECTED_HASH=$(curl -sS https://composer.github.io/installer.sig) \
    && ACTUAL_HASH=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');") \
    && if [ "$EXPECTED_HASH" != "$ACTUAL_HASH" ]; then echo 'Composer installer corrupt'; exit 1; fi \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php

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

# Enable mod_rewrite for API routing, mod_ssl for HTTPS, mod_headers for HSTS
RUN a2enmod rewrite ssl headers

# Create SSL certificate directory
RUN mkdir -p /etc/apache2/ssl

# Configure Apache HTTP VirtualHost
# DocumentRoot stays at /var/www/html (Debian default, already has Require all granted).
# Symlinks in /var/www/html point to actual files under /etc/eiou/ (the persistent volume).
# This avoids needing a /var/www/html volume — it only contains symlinks in the container layer.
RUN echo 'RedirectMatch ^/$ /gui/' >> /etc/apache2/sites-available/000-default.conf && \
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

# HTTP to HTTPS redirect
# Exceptions:
#   - /eiou transport endpoint: P2P backward compatibility (nodes may still use HTTP)
#   - .onion hosts: Tor hidden services are already end-to-end encrypted;
#     HTTPS is unnecessary and port 443 is not mapped through the hidden service
RUN sed -i '/<\/VirtualHost>/i \    RewriteEngine On\n    RewriteCond %{HTTPS} off\n    RewriteCond %{HTTP_HOST} !\\.onion$ [NC]\n    RewriteCond %{REQUEST_URI} !^/eiou\n    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]' /etc/apache2/sites-available/000-default.conf

# Suppress server version information in responses (L-29)
RUN echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security.conf && \
    echo 'ServerSignature Off' >> /etc/apache2/conf-available/security.conf && \
    (a2enconf security 2>/dev/null || true) && \
    for dir in /etc/php/*/apache2/conf.d /etc/php/*/cli/conf.d; do \
        [ -d "$dir" ] && echo 'expose_php = Off' > "$dir/security-headers.ini"; \
    done

# Create SSL VirtualHost configuration
RUN echo '<VirtualHost *:443>' > /etc/apache2/sites-available/default-ssl.conf && \
    echo '    ServerAdmin webmaster@localhost' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    DocumentRoot /var/www/html' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLEngine on' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLCipherSuite HIGH:!aNULL:!MD5:!3DES:!RC4' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLHonorCipherOrder on' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    Header always set Strict-Transport-Security "max-age=31536000"' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLCertificateFile /etc/apache2/ssl/server.crt' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    SSLCertificateKeyFile /etc/apache2/ssl/server.key' >> /etc/apache2/sites-available/default-ssl.conf && \
    echo '    RedirectMatch ^/$ /gui/' >> /etc/apache2/sites-available/default-ssl.conf && \
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

# Copy root files to /etc/eiou/ (includes api/, cli/, processors/, www/)
COPY files/root/ /etc/eiou/

# Create config directory for wallet configuration files
RUN mkdir -p /etc/eiou/config

# Create CLI wrapper in PATH
RUN echo '#!/bin/bash\nphp /etc/eiou/cli/Eiou.php "$@"' > /usr/local/bin/eiou && \
    chmod +x /usr/local/bin/eiou

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

# Create symlinks in /var/www/html pointing to files under /etc/eiou/
# This keeps DocumentRoot at /var/www/html (standard Apache access policy)
# while actual files live in the /etc/eiou volume for persistence and sync
RUN rm -f /var/www/html/index.html && \
    ln -s /etc/eiou/www/gui /var/www/html/gui && \
    ln -s /etc/eiou/www/eiou /var/www/html/eiou && \
    mkdir -p /var/www/html/api && \
    ln -s /etc/eiou/api/Api.php /var/www/html/api/index.php

# Enable PHP error logging
RUN sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /etc/php/*/apache2/php.ini
RUN touch /var/log/php_errors.log && \
    chown www-data:www-data /var/log/php_errors.log && \
    chmod 640 /var/log/php_errors.log

# MariaDB security hardening: bind to localhost only, disable symbolic links
RUN printf '[mysqld]\nbind-address=127.0.0.1\nskip-symbolic-links\n' > /etc/mysql/conf.d/security.cnf

# Log rotation for Apache and PHP application logs
RUN printf '/var/log/apache2/*.log {\n    weekly\n    rotate 4\n    compress\n    delaycompress\n    missingok\n    notifempty\n    create 640 root adm\n    sharedscripts\n    postrotate\n        if [ -f /var/run/apache2/apache2.pid ]; then\n            /usr/sbin/apachectl graceful > /dev/null 2>&1 || true\n        fi\n    endscript\n}\n' > /etc/logrotate.d/apache2-eiou && \
    printf '/var/log/php_errors.log {\n    weekly\n    rotate 4\n    compress\n    delaycompress\n    missingok\n    notifempty\n    create 640 www-data www-data\n}\n' > /etc/logrotate.d/php-eiou

# Persistent volumes:
# - /var/lib/mysql: Database files (transactions, contacts, balances)
# - /etc/eiou: Wallet configuration, encryption keys, and web files (www/)
# - /var/lib/eiou/backups: Encrypted database backups
# - /etc/letsencrypt: Let's Encrypt certificates and renewal state
VOLUME ["/var/lib/mysql", "/etc/eiou", "/var/lib/eiou/backups", "/etc/letsencrypt"]

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
COPY files/root/ /app/eiou-src-backup/root/
COPY files/composer.json /app/eiou-src-backup/composer.json
COPY files/composer.lock /app/eiou-src-backup/composer.lock

# Copy and set up startup script
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

# Health check to verify Apache is responding
# - interval: Check every 30 seconds
# - timeout: Allow 20 seconds for check to complete
# - start-period: Wait 120 seconds before first check (MariaDB needs 30-60s to initialize)
# - retries: Mark unhealthy after 5 consecutive failures
HEALTHCHECK --interval=30s --timeout=20s --start-period=120s --retries=5 \
    CMD curl -f http://localhost/gui/ || exit 1

# Ensure Docker sends SIGTERM for graceful shutdown (startup.sh traps this)
STOPSIGNAL SIGTERM

# Start services using the startup script
ENTRYPOINT ["/startup.sh"]