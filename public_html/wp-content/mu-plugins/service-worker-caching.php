<?php

namespace WordCamp\PWA\Caching;
use WP_Service_Worker_Caching_Routes, WP_Service_Worker_Scripts;

add_action( 'wp_front_service_worker', __NAMESPACE__ . '\register_caching_routes' );
add_action( 'wp_front_service_worker', __NAMESPACE__ . '\set_navigation_caching_strategy' );

/**
 * Register caching routes with the frontend service worker.
 *
 * @param WP_Service_Worker_Scripts $scripts
 */
function register_caching_routes( WP_Service_Worker_Scripts $scripts ) {
	/*
	 * Set up asset cache strategy to pull from the cache first, with no network request if the resource is found,
	 * and save up to 100 cached entries for 1 day.
	 */
	$asset_cache_strategy_args = array(
		'strategy'  => WP_Service_Worker_Caching_Routes::STRATEGY_CACHE_FIRST,
		'cacheName' => 'assets',
		'plugins'   => [
			'expiration' => [
				'maxEntries'    => 100,
				'maxAgeSeconds' => DAY_IN_SECONDS,
			],
		],
	);

	/*
	 * Cache scripts, styles, images, etc from core, themes, and plugins.
	 */
	$scripts->caching_routes()->register(
		'/wp-(content|includes)/.*\.(?:png|gif|jpg|jpeg|svg|webp|css|js)(\?.*)?$',
		$asset_cache_strategy_args
	);

	/*
	 * Cache custom CSS (from customizer).
	 * If we don't have a URL, that's probably because the custom CSS is empty or short enough to be printed
	 * inline instead of enqueued. In that case, the cached pages will have it printed from the `wp_head()`
	 * call anyway.
	 */
	$custom_css_url = wcorg_get_custom_css_url();
	if ( $custom_css_url ) {
		$scripts->caching_routes()->register(
			preg_quote( '/?'. untrailingslashit( wp_parse_url( $custom_css_url, PHP_URL_QUERY ) ), '/' ),
			$asset_cache_strategy_args
		);
	}

	/*
	 * Cache remote CSS endpoint, if Remote CSS has been set up.
	 */
	if ( \WordCamp\RemoteCSS\is_configured() ) {
		$remote_css_url = preg_quote( 'admin-ajax.php?action=' . \WordCamp\RemoteCSS\CSS_HANDLE, '/' );
		$scripts->caching_routes()->register(
			$remote_css_url,
			$asset_cache_strategy_args
		);
	}

	/*
	 * Cache API requests for 15 minutes.
	 */
	$scripts->caching_routes()->register(
		'/wp-json/.*',
		[
			'strategy'  => WP_Service_Worker_Caching_Routes::STRATEGY_CACHE_FIRST,
			'cacheName' => 'rest-api',
			'plugins'   => [
				'expiration' => [
					'maxEntries'    => 60,
					'maxAgeSeconds' => 15 * MINUTE_IN_SECONDS,
				],
			],
		]
	);
}

/**
 * Set the caching strategy for front-end navigation requests.
 */
function set_navigation_caching_strategy() {
	/*
	 * Cache pages that the user visits, so that if they return to them while offline, they'll still be available.
	 * While showing them the cached value, also run a network request to get the latest content.
	 */
	add_filter(
		'wp_service_worker_navigation_caching_strategy',
		function() {
			return WP_Service_Worker_Caching_Routes::STRATEGY_STALE_WHILE_REVALIDATE;
		}
	);

	// todo may no longer be needed after https://github.com/xwp/pwa-wp/issues/176 is resolved.
	add_filter(
		'wp_service_worker_navigation_caching_strategy_args',
		function( $args ) {
			$args['cacheName']                           = 'pages';
			$args['plugins']['expiration']['maxEntries'] = 50;

			return $args;
		}
	);
};
