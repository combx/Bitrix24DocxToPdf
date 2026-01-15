FROM php:8.3-fpm-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    git \
    zip \
    unzip \
    linux-headers \
    rabbitmq-c-dev \
    && docker-php-ext-install sockets \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /app

# Copy dependency definition
COPY src/composer.json .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy source code
COPY src/ .

# Permissions
RUN chown -R www-data:www-data /app

# Default command (can be overridden in docker-compose)
CMD ["php-fpm"]
