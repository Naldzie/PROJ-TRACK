#!/bin/bash

echo "Starting PROJ-TRACK Azure configuration..."

cd /home/site/wwwroot

# Laravel required directories
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/cache/data
mkdir -p storage/logs
mkdir -p bootstrap/cache

# Laravel writable directories
chmod -R 775 storage bootstrap/cache

# Install our Laravel Nginx configuration
cp /home/site/wwwroot/default /etc/nginx/sites-available/default

# Verify Nginx configuration
nginx -t

# Start/reload Nginx
nginx -s reload

echo "PROJ-TRACK Azure configuration completed."