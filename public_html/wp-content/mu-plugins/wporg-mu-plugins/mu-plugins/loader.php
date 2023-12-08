<?php

namespace WordPressdotorg\MU_Plugins;

use WordPressdotorg\Autoload;

/**
 * Load mu-plugins.
 *
 * `utilities/` aren't loaded automatically since they're not used globally.
 */

// Load and register the Autoloader.
if ( ! class_exists( '\WordPressdotorg\Autoload\Autoloader', false ) ) {
	require_once __DIR__ . '/autoloader/class-autoloader.php';
}

Autoload\register_class_path( __NAMESPACE__, __DIR__ );

// Composer loader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	// Production.
	require_once __DIR__ . '/vendor/autoload.php';
} elseif ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	// Development.
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

require_once __DIR__ . '/helpers/helpers.php';
require_once __DIR__ . '/blocks/global-header-footer/blocks.php';
require_once __DIR__ . '/blocks/google-map/index.php';
require_once __DIR__ . '/blocks/horizontal-slider/horizontal-slider.php';
require_once __DIR__ . '/blocks/language-suggest/language-suggest.php';
require_once __DIR__ . '/blocks/local-navigation-bar/index.php';
require_once __DIR__ . '/blocks/latest-news/latest-news.php';
require_once __DIR__ . '/blocks/link-wrapper/index.php';
require_once __DIR__ . '/blocks/navigation/index.php';
require_once __DIR__ . '/blocks/notice/index.php';
require_once __DIR__ . '/blocks/query-filter/index.php';
require_once __DIR__ . '/blocks/query-total/index.php';
require_once __DIR__ . '/blocks/sidebar-container/index.php';
require_once __DIR__ . '/blocks/screenshot-preview/block.php';
require_once __DIR__ . '/blocks/site-breadcrumbs/index.php';
require_once __DIR__ . '/blocks/table-of-contents/index.php';
require_once __DIR__ . '/blocks/time/index.php';
require_once __DIR__ . '/global-fonts/index.php';
require_once __DIR__ . '/plugin-tweaks/index.php';
require_once __DIR__ . '/rest-api/index.php';
require_once __DIR__ . '/skip-to/skip-to.php';
require_once __DIR__ . '/db-user-sessions/index.php';
require_once __DIR__ . '/encryption/index.php';
require_once __DIR__ . '/admin/index.php';
