<?php
/**
 * Block Name: Events Stat
 * Description: One of the homepage stats, kept current from cron.
 *
 * @package wporg
 */

namespace WordPressdotorg\Theme\Events_2023\Events_Stat;

use WordPressdotorg\Events_2023;

defined( 'WPINC' ) || die();

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Registers the block using the metadata loaded from the `block.json` file.
 */
function init() {
	$build_dir = dirname( __DIR__, 2 ) . '/build/events-stat';

	// The build only exists after `npm run build`; without it there's no block.json to register from.
	if ( ! file_exists( $build_dir . '/block.json' ) ) {
		return;
	}

	register_block_type(
		$build_dir,
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}

/**
 * Render the block content.
 *
 * @param array $attributes Block attributes.
 *
 * @return string Returns the block markup, or nothing for a stat that doesn't exist.
 */
function render( $attributes ) {
	$text = Events_2023\format_stat( (string) ( $attributes['stat'] ?? '' ), Events_2023\get_stats() );

	if ( '' === $text ) {
		return '';
	}

	return sprintf(
		'<p %s>%s</p>',
		get_block_wrapper_attributes( array( 'class' => 'wp-block-wporg-events-stat' ) ),
		esc_html( $text )
	);
}
