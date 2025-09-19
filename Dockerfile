FROM webdevops/php-nginx:8.3

##change the shell
SHELL ["/bin/bash", "-c"]

ENV PHP_MAX_EXECUTION_TIME=110

# Install system dependencies first
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    libzip-dev \
    cron \
    supervisor \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Node.js and npm
RUN curl -sL https://deb.nodesource.com/setup_20.x | bash -
RUN apt-get install -y nodejs

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy the project files into the container
COPY . /production/echonetafrica

# Set the laravel web folder
ARG WEB_PATH=/production/echonetafrica/public
ENV WEB_DOCUMENT_ROOT=$WEB_PATH

# set the correct laravel app foler
ARG LARAVEL_PATH=/production/echonetafrica
WORKDIR $LARAVEL_PATH

# Create necessary directories before composer install
RUN mkdir -p bootstrap/cache && \
    mkdir -p storage/logs && \
    mkdir -p storage/framework/sessions && \
    mkdir -p storage/framework/views && \
    mkdir -p storage/framework/cache && \
    chmod -R 775 bootstrap/cache && \
    chmod -R 775 storage

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Install JavaScript dependencies
RUN npm install && npm run build

# Laravel specific commands
RUN php artisan storage:link && \
    touch storage/logs/laravel.log && \
    chmod -R 777 storage && \
    chmod -R 777 public && \
    chown -R www-data:www-data storage && \
    chown -R www-data:www-data public && \
    chmod -R 777 bootstrap

# Copy supervisor configuration for additional services
COPY docker-configs/supervisord.conf /opt/docker/etc/supervisor.d/laravel.conf

# Copy crontab file for Laravel scheduler and install it properly
COPY docker-configs/laravel-cron /tmp/laravel-cron
RUN chmod 0644 /tmp/laravel-cron && \
    crontab -u application /tmp/laravel-cron && \
    rm /tmp/laravel-cron

# Database configuration arguments
ARG DB_CONNECTION
ARG DB_HOST
ARG DB_PORT
ARG DB_DATABASE
ARG DB_USERNAME
ARG DB_PASSWORD

EXPOSE 80

# Create necessary directories for logging and ensure proper permissions
RUN mkdir -p /var/log/supervisor && \
    mkdir -p /tmp && \
    touch /var/log/cron.log && \
    chmod 755 /var/log/supervisor && \
    chown application:application /tmp