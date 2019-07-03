#!/bin/bash

# Add WordPress if it doesn't exist yet.
# todo Maybe setup cron job to run the git pull, like production? The above will only run when starting the container.
[ -f /usr/src/public_html/mu/xmlrpc.php ] || git clone git://core.git.wordpress.org /usr/src/public_html/mu

# Add built files if they don't exist yet.
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.js
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.css

# Start services.
nginx
mailcatcher --http-ip 0.0.0.0
php-fpm

# Keep the container running.
tail -f /dev/null
