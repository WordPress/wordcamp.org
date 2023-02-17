#!/bin/bash

# Install Composer inside the container.
#
# See https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md
function install_composer() {
  EXPECTED_SIGNATURE="$(wget -q -O - https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

  if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
      echo 'ERROR: Invalid installer signature'
      rm composer-setup.php
      return 1
  fi

  php composer-setup.php --quiet
  RESULT=$?
  rm composer-setup.php
  return $RESULT
}

function do_extra_setup() {
  PKG_PATH="/usr/src"

  if [ ! -f $PKG_PATH/composer.json ]; then
    echo 'ERROR: composer.json file not found'
    return 1
  fi

  cd $PKG_PATH

  if [ ! -f $PKG_PATH/composer.phar ]; then
    echo "Installing a composer executable... "
    install_composer
  fi

  if [ ! -f $PKG_PATH/composer.lock ]; then
    echo "Installing additional plugins and themes... "
    php composer.phar config --no-plugins allow-plugins.composer/installers true
    php composer.phar install
  fi
}
