<?php
/**
 * Loads the global sponsors store on the events network.
 *
 * The sponsors themselves render on the group sites (groups network), but the
 * posts live on the events root site — see the file header of
 * `wporg-groups-frontend/inc/sponsors.php` for why. That site is on this
 * network, so the post type and its admin screens have to be registered here
 * as well; `mu-plugins/groups/wporg-groups-frontend.php` covers the reading
 * side.
 *
 * Only the sponsors file is loaded, not the whole `wporg-groups-frontend`
 * plugin: nothing else in it applies to a site that has no group and no
 * GatherPress.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Events_Loader;

use WordCamp\Groups\Frontend\Sponsors;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/wporg-groups-frontend/inc/sponsors.php';

Sponsors\bootstrap();
