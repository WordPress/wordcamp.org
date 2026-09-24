<?php
/**
 * Loads the `wporg-groups-frontend` mu-plugin on the groups network only.
 *
 * The plugin itself lives at `mu-plugins/wporg-groups-frontend/` so its asset
 * paths and folder structure stay self-contained. This stub is picked up by
 * `wcorg_include_network_only_plugins()` because it sits in the `groups/`
 * network folder, which only loads when `SITE_ID_CURRENT_SITE === GROUPS_NETWORK_ID`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Loader;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/wporg-groups-frontend/wporg-groups-frontend.php';
