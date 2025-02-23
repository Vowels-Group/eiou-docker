FROM debian:latest

# Install required packages for a LAMP server
RUN apt-get update && apt-get install -y \
    apache2 \
    cron \
    mariadb-server \
    openssl \
    php \
    php-curl \
    php-mysql \
    tor \
    php-dev
# remove php-dev once done debugging

# Xdebug- remove once done debugging
RUN pecl install xdebug-3.4.0
COPY src/xdebug/99-xdebug.ini /etc/php/8.2/cli/conf.d/99-docker-php-ext-xdebug.ini
COPY src/xdebug/99-xdebug.ini /etc/php/8.2/cli/mods-available/99-docker-php-ext-xdebug.ini
COPY .vscode /var/www/html/eiou/.vscode

    
# Edit /etc/tor/torrc
RUN  chmod o+w /etc/tor/torrc
RUN echo "HiddenServiceDir /var/lib/tor/hidden_service/" >> /etc/tor/torrc
RUN echo "HiddenServicePort 80 127.0.0.1:80" >> /etc/tor/torrc
RUN  chmod o-w /etc/tor/torrc

# Expose Tor hidden service port
EXPOSE 80

# Set up Apache2 to accept php in .html files
RUN echo "AddType application/x-httpd-php .html" | tee -a /etc/apache2/apache2.conf

# Copy eiou.php file to /usr/local/bin and create a wrapper script
COPY src/eiou.php /usr/local/bin/eiou.php
RUN echo '#!/bin/bash\nphp /usr/local/bin/eiou.php "$@"' > /usr/local/bin/eiou && \
    chmod +x /usr/local/bin/eiou

# Copy index.html file to web directory
COPY src/index.php /var/www/html/eiou/index.html
RUN chown www-data:www-data /var/www/html/eiou -R
RUN chmod 755 /var/www/html/eiou

# Copy functions.php to a common location
COPY src/functions.php /var/www/html/eiou/functions.php
RUN chown www-data:www-data /var/www/html/eiou/functions.php
RUN chmod 644 /var/www/html/eiou/functions.php

# Copy functions folder to /var/www/html/eiou/functions
COPY src/functions /var/www/html/eiou/functions
RUN chown www-data:www-data /var/www/html/eiou/functions -R
RUN chmod 644 /var/www/html/eiou/functions/*

# Copy messages.php to a common location
COPY src/messages.php /var/www/html/eiou/messages.php
RUN chown www-data:www-data /var/www/html/eiou/messages.php
RUN chmod 644 /var/www/html/eiou/messages.php

# Enable PHP error logging
RUN sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /etc/php/*/apache2/php.ini
RUN touch /var/log/php_errors.log
RUN chmod 666 /var/log/php_errors.log

# Copy and set up startup script
COPY startup.sh /startup.sh
RUN chmod +x /startup.sh

# Start services using the startup script
ENTRYPOINT ["/startup.sh"]