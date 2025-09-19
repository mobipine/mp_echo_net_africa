# Use webdevops/php-nginx as base image
FROM webdevops/php-nginx:8.3

##change the shell
SHELL ["/bin/bash", "-c"]

ENV PHP_MAX_EXECUTION_TIME=110

# Copy the project files into the container
COPY . /production/echonetafrica

# Set the laravel web folder
ARG WEB_PATH=/production/echonetafrica/public
ENV WEB_DOCUMENT_ROOT=$WEB_PATH

# set the correct laravel app foler
ARG LARAVEL_PATH=/production/echonetafrica
WORKDIR $LARAVEL_PATH

# Install Node.js and npm
RUN curl -sL https://deb.nodesource.com/setup_20.x | bash -
RUN apt-get install -y nodejs

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    libzip-dev \
    supervisor \
    cron \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install JavaScript dependencies
RUN npm install && npm run build

# Install Composer and PHP dependencies
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create necessary directories and set permissions before composer install
RUN mkdir -p bootstrap/cache storage/logs storage/framework/cache storage/framework/sessions storage/framework/views
RUN chmod -R 775 bootstrap/cache storage
RUN chown -R www-data:www-data bootstrap/cache storage

RUN composer install --no-dev --optimize-autoloader

EXPOSE 80

# Laravel specific commands
RUN php artisan storage:link && \
    mkdir -p bootstrap/cache && \
    touch storage/logs/laravel.log && \
    chmod -R 777 storage && \
    chmod -R 777 public && \
    chown -R www-data:www-data storage && \
    chown -R www-data:www-data public && \
    chmod -R 777 bootstrap

# Database configuration arguments
# Note: For production, consider using Docker secrets instead of ARG for sensitive data like DB_PASSWORD
ARG DB_CONNECTION
ARG DB_HOST
ARG DB_PORT
ARG DB_DATABASE
ARG DB_USERNAME
ARG DB_PASSWORD

# Create supervisor configuration for Laravel processes
RUN mkdir -p /etc/supervisor/conf.d

# Copy supervisor configuration file
COPY docker/supervisor/laravel.conf /etc/supervisor/conf.d/laravel.conf

# Copy startup script
COPY docker/scripts/start-laravel.sh /usr/local/bin/start-laravel.sh

# Make startup script executable
RUN chmod +x /usr/local/bin/start-laravel.sh

# Create log directories
RUN mkdir -p /var/log/supervisor

# Set proper permissions for logs
RUN chown -R www-data:www-data /var/log/supervisor

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Use the startup script as the entrypoint
CMD ["/usr/local/bin/start-laravel.sh"]