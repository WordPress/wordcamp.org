#!/bin/bash

# Add built files if they don't exist yet.
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.js
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.css

# Start services.
nginx
mailcatcher --http-ip 0.0.0.0
php-fpm

# Keep the container running.
tail -f /dev/null
