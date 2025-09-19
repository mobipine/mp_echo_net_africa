#!/bin/bash

# Create necessary directories
mkdir -p /var/log/supervisor
mkdir -p /var/run

# Start the webdevops base services first
/entrypoint supervisord &

# Wait a moment for base services to start
sleep 5

# Start our additional supervisor processes
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf