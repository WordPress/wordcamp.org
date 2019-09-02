#!/bin/bash

echo "Starting up... "

# Add built files if they don't exist yet.
mkdir -p /usr/src/public_html/wp-content/mu-plugins/blocks/build
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.js
touch /usr/src/public_html/wp-content/mu-plugins/blocks/build/blocks.min.css

# Install/update 3rd-party plugins and themes if they aren't included via SVN.
if [ -d /usr/src/public_html/wp-content/.svn ]; then
  echo "The wp-content directory appears to be a Subversion repository. Skipping additional setup... "
else
  source /var/scripts/extra-setup.sh
  do_extra_setup
fi

# Start services.
nginx
mailcatcher --http-ip 0.0.0.0
php-fpm

echo "Startup complete."

# Execute the Dockerfile CMD.
exec "$@"
