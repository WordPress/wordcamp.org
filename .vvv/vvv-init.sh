#!/usr/bin/env bash
# Provision WordPress Stable

set -eo pipefail

PKG_PATH=${VVV_PATH_TO_SITE}

# Set up a database
# Make a database, if we don't already have one
echo -e "\nCreating database mysql (if it's not already there)"
mysql -u root --password=root -e "CREATE DATABASE IF NOT EXISTS mysql"
echo -e "\nGranting the wordcamp_dev user priviledges to the mysql database"
mysql -u root --password=root -e "GRANT ALL PRIVILEGES ON mysql.* TO wordcamp_dev@localhost IDENTIFIED BY 'wordcamp_dev';"
echo -e "\n DB operations done.\n\n"

echo "Setting up the log subfolder for Nginx logs"
noroot mkdir -p ${VVV_PATH_TO_SITE}/log
noroot touch ${VVV_PATH_TO_SITE}/log/nginx-error.log
noroot touch ${VVV_PATH_TO_SITE}/log/nginx-access.log

# Add built files if they don't exist yet.
noroot mkdir -p ${VVV_PATH_TO_SITE}/public_html/wp-content/mu-plugins/blocks/build
noroot touch ${VVV_PATH_TO_SITE}/public_html/wp-content/mu-plugins/blocks/build/blocks.min.js
noroot touch ${VVV_PATH_TO_SITE}/public_html/wp-content/mu-plugins/blocks/build/blocks.min.css

noroot cp -f ${VVV_PATH_TO_SITE}/.docker/wp-config.php ${VVV_PATH_TO_SITE}/public_html/wp-config.php
noroot cp -f ${VVV_PATH_TO_SITE}/.docker/wp-cli.yml ${VVV_PATH_TO_SITE}/public_html/wp-cli.yml

if [[ ! -d ${VVV_PATH_TO_SITE}/public_html/mu/.git ]]; then
	noroot git clone git://core.git.wordpress.org/ --depth="10" --branch="5.2" ${VVV_PATH_TO_SITE}/public_html/mu
fi

cd "${VVV_PATH_TO_SITE}/.docker/config/"
noroot composer install --working-dir="${VVV_PATH_TO_SITE}"

cd ${VVV_PATH_TO_SITE}

# Install/update 3rd-party plugins and themes if they aren't included via SVN.
if [ -d ${VVV_PATH_TO_SITE}/public_html/wp-content/.svn ]; then
  echo "The wp-content directory appears to be a Subversion repository. Skipping additional setup... "
else
  ( cd ${VVV_PATH_TO_SITE}/.docker/bin &&  source extra-setup.sh && noroot do_extra_setup )
fi
