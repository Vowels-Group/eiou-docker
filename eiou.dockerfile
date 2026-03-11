# Copyright 2025-2026 Vowels Group, LLC

# SECURITY NOTE: This container starts as root to manage multiple services (nginx, PHP-FPM, MariaDB, Tor, cron).
# Each service drops privileges to its appropriate user after startup:
#   - nginx: master runs as root (binds ports 80/443), workers run as www-data
#   - PHP-FPM: master runs as root, workers run as www-data
#   - MariaDB: runs as mysql
#   - Tor: runs as debian-tor
#   - PHP message processors: run as www-data (via runuser)
#
# Hardening measures:
#   - tini as PID 1 for proper signal forwarding and zombie reaping
#   - cap_drop: ALL with only 7 capabilities re-added (see docker-compose.yml)
#   - no-new-privileges: prevents post-startup privilege escalation
#   - pids_limit: caps process count to prevent fork bombs

# =============================================================================
# EIOU Node Docker Image
# =============================================================================
# Builds a complete EIOU node with:
# - nginx web server + PHP-FPM for GUI, API, and P2P transport
# - MariaDB database for transactions and contacts
# - Tor for anonymous .onion addressing
# - PHP runtime for application logic
#
# nginx provides network-level protections that Apache lacked:
# - Per-IP rate limiting (limit_req_zone) at the connection level
# - Per-IP connection limits (limit_conn_zone)
# - Slow/abusive connection timeouts (client_body_timeout, client_header_timeout)
# - Event-driven architecture (handles thousands of connections with minimal memory)
# =============================================================================

FROM debian:12-slim@sha256:98f4b71de414932439ac6ac690d7060df1f27161073c5036a7553723881bffbe

# OCI Image Labels — https://github.com/opencontainers/image-spec/blob/main/annotations.md
LABEL org.opencontainers.image.title="eiou-node" \
      org.opencontainers.image.description="EIOU peer-to-peer transaction node with GUI, API, Tor, and MariaDB" \
      org.opencontainers.image.url="https://eiou.org" \
      org.opencontainers.image.source="https://github.com/eiou-org/eiou-docker" \
      org.opencontainers.image.documentation="https://github.com/eiou-org/eiou-docker#readme" \
      org.opencontainers.image.vendor="Vowels Group, LLC" \
      org.opencontainers.image.licenses="Apache-2.0" \
      org.opencontainers.image.base.name="debian:12-slim"

# Install required packages:
# - nginx: Web server and reverse proxy for PHP-FPM, SSL termination, rate limiting
# - php-fpm: FastCGI Process Manager for PHP (separate process pool, more efficient than mod_php)
# - php-cli: PHP command-line interpreter for CLI commands and message processors
# - cron: Scheduled task execution for maintenance jobs
# - curl: HTTP client for peer-to-peer communication
# - mariadb-server: Database for wallet, transactions, contacts
# - certbot: Let's Encrypt ACME client for automatic SSL certificates
# - openssl: SSL certificate generation and cryptography
# - php-curl, php-mbstring, php-mysql, php-xml: PHP extensions
#   - php-xml: DOM extension required for Composer dependency resolution
# - tini: Minimal init system for proper signal forwarding and zombie reaping
# - tor: Anonymous network for .onion addresses
# - unzip: Required by Composer for package installation
RUN apt-get update && apt-get install -y \
    certbot \
    cron \
    curl \
    logrotate \
    mariadb-server \
    nginx \
    openssl \
    php-cli \
    php-curl \
    php-fpm \
    php-mbstring \
    php-mysql \
    php-xml \
    tini \
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
# - HiddenServicePort: Maps Tor port 80 to internal nginx
RUN chmod o+w /etc/tor/torrc && \
    echo "HiddenServiceDir /var/lib/tor/hidden_service/" >> /etc/tor/torrc && \
    echo "HiddenServicePort 80 127.0.0.1:80" >> /etc/tor/torrc && \
    chmod o-w /etc/tor/torrc

# Expose HTTP and HTTPS ports
EXPOSE 80
EXPOSE 443

# =============================================================================
# NGINX + PHP-FPM CONFIGURATION
# =============================================================================

# Create SSL certificate directory
RUN mkdir -p /etc/nginx/ssl

# Configure PHP-FPM to use a version-independent socket path
# The default socket path includes the PHP version (e.g., php8.2-fpm.sock).
# Using a fixed path keeps the nginx config stable across PHP version upgrades.
# Override socket path and allow .html files to be processed by PHP-FPM.
# EIOU uses PHP inside .html files (GUI templates) — equivalent to Apache's
# AddType application/x-httpd-php .html. Without this, PHP-FPM blocks .html
# with "Access denied (see security.limit_extensions)".
# PHP-FPM pool tuning:
# - Fixed socket path (version-independent, keeps nginx config stable)
# - Allow .html (EIOU GUI templates contain PHP)
# - pm = ondemand: spawn workers only on request, kill after 10s idle.
#   Better than "dynamic" for containers with intermittent traffic — avoids
#   keeping idle workers alive. max_children=5 caps peak PHP concurrency.
RUN sed -i 's|^listen = .*|listen = /run/php/php-fpm.sock|' /etc/php/*/fpm/pool.d/www.conf && \
    sed -i 's|^;security.limit_extensions = .*|security.limit_extensions = .php .html|' /etc/php/*/fpm/pool.d/www.conf && \
    sed -i 's|^pm = dynamic|pm = ondemand|' /etc/php/*/fpm/pool.d/www.conf && \
    sed -i 's|^;pm.process_idle_timeout = .*|pm.process_idle_timeout = 10s|' /etc/php/*/fpm/pool.d/www.conf

# Fix nginx worker count: "auto" uses host CPU count (not container limit),
# so a 32-core host spawns 32 workers inside a 1-CPU container. Set to 2:
# one for active requests, one spare for SSL handshakes.
RUN sed -i 's|^worker_processes auto;|worker_processes 2;|' /etc/nginx/nginx.conf

# Configure nginx: global settings for rate limiting and security
# Rate limiting zones must be in the http block (nginx.conf), not server blocks.
# These zones are referenced by the server config in eiou.conf.
RUN sed -i '/http {/a \
    # --- EIOU rate limiting zones ---\n\
    # Per-IP request rate limiting at the connection level (before PHP runs)\n\
    limit_req_zone $binary_remote_addr zone=general:10m rate=30r/s;\n\
    limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;\n\
    limit_req_zone $binary_remote_addr zone=p2p:10m rate=20r/s;\n\
    # Per-IP concurrent connection limit\n\
    limit_conn_zone $binary_remote_addr zone=addr:10m;\n\
    # Return 429 Too Many Requests (not 503) when rate limited\n\
    limit_req_status 429;\n\
    limit_conn_status 429;\n\
    # Hide nginx version in Server header and error pages\n\
    server_tokens off;' /etc/nginx/nginx.conf

# Copy nginx server configuration (server blocks, location routing, SSL)
COPY nginx/eiou.conf /etc/nginx/sites-available/eiou.conf
COPY nginx/eiou-locations.conf /etc/nginx/eiou-locations.conf

# Enable EIOU site, disable default site
RUN rm -f /etc/nginx/sites-enabled/default && \
    ln -s /etc/nginx/sites-available/eiou.conf /etc/nginx/sites-enabled/eiou.conf

# Suppress PHP version in response headers
RUN for dir in /etc/php/*/fpm/conf.d /etc/php/*/cli/conf.d; do \
        [ -d "$dir" ] && echo 'expose_php = Off' > "$dir/security-headers.ini"; \
    done

# =============================================================================
# APPLICATION DEPLOYMENT
# =============================================================================
# Source code lives in /app/eiou/ (image filesystem — updates with each build).
# User data lives in /etc/eiou/config/ (Docker volume — persists across upgrades).
# This separation eliminates the need for source file sync at startup.
# =============================================================================

# Copy root files (api/, cli/, processors/, www/, *.php)
COPY files/root/ /app/eiou/

# Copy src folder (namespaced PHP classes)
COPY files/src/ /app/eiou/src/

# Copy scripts (backup-cron, validate-autoload)
COPY files/scripts/ /app/eiou/scripts/

# Copy composer files and install dependencies
COPY files/composer.json /app/eiou/composer.json
RUN cd /app/eiou && composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions on application code
RUN chown www-data:www-data /app/eiou/SecurityInit.php \
    /app/eiou/Functions.php \
    /app/eiou/processors/P2pMessages.php \
    /app/eiou/processors/TransactionMessages.php \
    /app/eiou/processors/CleanupMessages.php \
    /app/eiou/processors/ContactStatusMessages.php
RUN find /app/eiou/ -type d -exec chmod 755 "{}" \;
RUN find /app/eiou/ -type f -exec chmod 644 "{}" \;

# Create config directory for wallet configuration files (volume-backed)
RUN mkdir -p /etc/eiou/config

# Create CLI wrapper in PATH (waits for MariaDB before running commands)
RUN printf '#!/bin/bash\n\
# Wait for MariaDB to be ready before accepting CLI commands\n\
timeout=30\n\
while ! mysqladmin ping -h localhost --silent >/dev/null 2>&1; do\n\
    timeout=$((timeout - 1))\n\
    if [ "$timeout" -le 0 ]; then\n\
        echo "Error: MariaDB is not ready. The node may still be starting up." >&2\n\
        exit 1\n\
    fi\n\
    sleep 1\n\
done\n\
php /app/eiou/cli/Eiou.php "$@"\n' > /usr/local/bin/eiou && \
    chmod +x /usr/local/bin/eiou

# Create symlinks in /var/www/html pointing to application code
# Keeps the web root at /var/www/html (standard nginx/Debian convention)
RUN rm -f /var/www/html/index.nginx-debian.html && \
    ln -s /app/eiou/www/gui /var/www/html/gui && \
    ln -s /app/eiou/www/eiou /var/www/html/eiou && \
    mkdir -p /var/www/html/api && \
    ln -s /app/eiou/api/Api.php /var/www/html/api/index.php

# Enable PHP error logging
RUN sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /etc/php/*/fpm/php.ini
RUN touch /var/log/php_errors.log && \
    chown www-data:www-data /var/log/php_errors.log && \
    chmod 640 /var/log/php_errors.log

# MariaDB security hardening: bind to localhost only, disable symbolic links
RUN printf '[mysqld]\nbind-address=127.0.0.1\nskip-symbolic-links\n' > /etc/mysql/conf.d/security.cnf

# Log rotation for nginx and PHP application logs
RUN printf '/var/log/nginx/*.log {\n    weekly\n    rotate 4\n    compress\n    delaycompress\n    missingok\n    notifempty\n    create 640 www-data adm\n    sharedscripts\n    postrotate\n        if [ -f /var/run/nginx.pid ]; then\n            kill -USR1 $(cat /var/run/nginx.pid) 2>/dev/null || true\n        fi\n    endscript\n}\n' > /etc/logrotate.d/nginx-eiou && \
    printf '/var/log/php_errors.log {\n    weekly\n    rotate 4\n    compress\n    delaycompress\n    missingok\n    notifempty\n    create 640 www-data www-data\n}\n' > /etc/logrotate.d/php-eiou

# Persistent volumes:
# - /var/lib/mysql: Database files (transactions, contacts, balances)
# - /etc/eiou/config: Wallet configuration, encryption keys
# - /var/lib/eiou/backups: Encrypted database backups
# - /etc/letsencrypt: Let's Encrypt certificates and renewal state
#
# Source code is NOT in a volume — it lives in /app/eiou/ (image filesystem)
# and updates automatically with each new image build.
VOLUME ["/var/lib/mysql", "/etc/eiou/config", "/var/lib/eiou/backups", "/etc/letsencrypt"]

# Copy scripts directory (includes banner.sh for warning messages)
COPY scripts/ /app/scripts/
RUN chmod +x /app/scripts/*.sh

# Copy and set up startup script
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

# Health check to verify nginx + PHP-FPM is responding
# - interval: Check every 30 seconds
# - timeout: Allow 20 seconds for check to complete
# - start-period: Wait 120 seconds before first check (MariaDB needs 30-60s to initialize)
# - retries: Mark unhealthy after 5 consecutive failures
HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=5 \
    CMD curl -sf http://localhost/api/health || exit 1

# Ensure Docker sends SIGTERM for graceful shutdown (startup.sh traps this)
STOPSIGNAL SIGTERM

# Start services using tini as PID 1 for proper signal forwarding and zombie reaping.
# tini ensures SIGTERM reaches startup.sh reliably and reaps orphaned child processes
# that would otherwise accumulate as zombies (PHP processors, runuser wrappers).
ENTRYPOINT ["/usr/bin/tini", "--", "/startup.sh"]
