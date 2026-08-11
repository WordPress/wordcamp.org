<?php
/**
 * Loads the `gatherpress-recurring-events` mu-plugin on the groups network only.
 *
 * The plugin itself lives at `mu-plugins/gatherpress-recurring-events/` so its
 * asset paths and folder structure stay self-contained. This stub is picked up
 * by `wcorg_include_network_only_plugins()` because it sits in the `groups/`
 * network folder, which only loads when `SITE_ID_CURRENT_SITE === GROUPS_NETWORK_ID`.
 *
 * @package WordCamp\Groups
 */

namespace WordCamp\Groups\Loader;

defined( 'WPINC' ) || die();

require_once dirname( __DIR__ ) . '/gatherpress-recurring-events/plugin.php';
