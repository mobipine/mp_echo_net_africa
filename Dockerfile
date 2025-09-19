# Use webdevops/php-nginx as base image
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

# Copy supervisor configuration and startup script
COPY docker-configs/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker-configs/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Copy crontab file
COPY docker-configs/laravel-cron /etc/cron.d/laravel-cron

# Give execution rights on the cron job
RUN chmod 0644 /etc/cron.d/laravel-cron

# Apply cron job
RUN crontab /etc/cron.d/laravel-cron

# Create the log directories and files
RUN mkdir -p /var/log/supervisor && \
    mkdir -p /var/run && \
    touch /var/log/cron.log && \
    touch /var/log/supervisor/supervisord.log && \
    chmod 755 /var/log/supervisor

# Database configuration arguments
ARG DB_CONNECTION
ARG DB_HOST
ARG DB_PORT
ARG DB_DATABASE
ARG DB_USERNAME
ARG DB_PASSWORD

EXPOSE 80

# Use startup script to ensure directories exist
CMD ["/usr/local/bin/start.sh"]