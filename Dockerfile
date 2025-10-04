# Multi-stage Docker build for eIOU application
# Stage 1: Base image with PHP and extensions
FROM php:8.1-apache AS base

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring exif pcntl bcmath gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html

# Stage 2: Development environment
FROM base AS development

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install development tools
RUN apt-get update && apt-get install -y \
    vim \
    nodejs \
    npm \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy application code
COPY . /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/data \
    && chmod -R 777 /var/www/html/logs

# Install PHP dependencies
RUN if [ -f composer.json ]; then composer install --no-interaction; fi

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Start Apache
CMD ["apache2-foreground"]

# Stage 3: Production environment
FROM base AS production

# Copy only necessary files
COPY --chown=www-data:www-data ./public /var/www/html/public
COPY --chown=www-data:www-data ./src /var/www/html/src
COPY --chown=www-data:www-data ./config /var/www/html/config
COPY --chown=www-data:www-data ./vendor /var/www/html/vendor

# Create data directories
RUN mkdir -p /var/www/html/data /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/data \
    && chmod -R 777 /var/www/html/logs

# Security: Disable Apache server signature
RUN echo "ServerTokens Prod" >> /etc/apache2/apache2.conf \
    && echo "ServerSignature Off" >> /etc/apache2/apache2.conf

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Start Apache
CMD ["apache2-foreground"]