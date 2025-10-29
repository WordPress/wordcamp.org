#!/bin/bash

root=$( dirname $( wp config path ) )

wp theme activate wporg-events-2023

wp rewrite structure '/%year%/%monthnum%/%postname%/'
wp rewrite flush --hard

wp option update blogname "WordPress Events"
wp option update blogdescription "Blog Tool, Publishing Platform, and CMS"

wp db query < "${root}/env/wporg_events_dev.sql"
wp db query < "${root}/env/wporg_blogs_dev.sql"

wp post create --post_type=page --post_title="Upcoming Events" --post_name=upcoming --post_status=publish
wp post create --post_type=page --post_title="Organize Events" --post_name=organize-events --post_status=publish
