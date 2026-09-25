#!/bin/bash

echo "Starting up... "

# Add built files if they don't exist yet.
mkdir -p /usr/src/public_html/wp-content/mu-plugins/blocks/build
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.js
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.css

# Start services.
nginx
mailcatcher --http-ip 0.0.0.0
php-fpm

echo "Startup complete."

# Execute the Dockerfile CMD.
exec "$@"
