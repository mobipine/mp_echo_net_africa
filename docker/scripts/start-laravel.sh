#!/bin/bash

# Set environment variables
export DB_CONNECTION=${DB_CONNECTION:-mysql}
export DB_HOST=${DB_HOST:-localhost}
export DB_PORT=${DB_PORT:-3306}
export DB_DATABASE=${DB_DATABASE:-trustfund}
export DB_USERNAME=${DB_USERNAME:-root}
export DB_PASSWORD=${DB_PASSWORD:-}

# Wait for database to be ready
echo "Waiting for database connection..."
while ! php artisan migrate:status > /dev/null 2>&1; do
    echo "Database not ready, waiting..."
    sleep 5
done
echo "Database connection established!"

# Run database migrations
echo "Running database migrations..."
php artisan migrate

# Clear and cache configuration
echo "Optimizing Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Create necessary directories and set permissions
mkdir -p storage/logs
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache

# Set proper permissions
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Start supervisor to manage all processes
echo "Starting Laravel application with supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/laravel.conf
