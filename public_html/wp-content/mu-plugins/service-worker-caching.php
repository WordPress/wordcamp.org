<?php

namespace WordCamp\PWA\Caching;
use WP_Service_Worker_Caching_Routes;

add_action( 'wp_front_service_worker', __NAMESPACE__ . '\register_caching_routes' );
add_action( 'wp_front_service_worker',    __NAMESPACE__ . '\set_navigation_caching_strategy' );

/**
 * Register caching routes with both service workers.
 */
function register_caching_routes() {
	$custom_css_url_parts = wp_parse_url( wcorg_get_custom_css_url() );

	$static_asset_route_params = array(
		'strategy'  => WP_Service_Worker_Caching_Routes::STRATEGY_CACHE_FIRST,
		'cacheName' => 'assets',
		'plugins'   => [
			'expiration' => [
				'maxEntries'    => 60,
				'maxAgeSeconds' => DAY_IN_SECONDS,
			],
		],
	);

	wp_register_service_worker_caching_route(
		'/wp-(content|includes)/.*\.(?:png|gif|jpg|jpeg|svg|webp|css|js)(\?.*)?$',
		$static_asset_route_params
	);

	if ( isset( $custom_css_url_parts['path'], $custom_css_url_parts['query'] ) ) {
		wp_register_service_worker_caching_route(
			$custom_css_url_parts['path'] . $custom_css_url_parts['query'] . '$',
			$static_asset_route_params
		);
	}

	wp_register_service_worker_caching_route(
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
	 * If they're online, though, fetch the latest version since it could have changed since they last visited.
	 */
	add_filter(
		'wp_service_worker_navigation_caching_strategy',
		function() {
			return WP_Service_Worker_Caching_Routes::STRATEGY_NETWORK_FIRST;
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
