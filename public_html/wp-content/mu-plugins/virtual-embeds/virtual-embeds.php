<?php
/**
 * Embeds for virtual events on WordCamp.org:
 *  - CrowdCast block to embed iframe
 *  - StreamText block which saves as the streamtext shortcode
 */

namespace WordCamp\Virtual_Embeds;

defined( 'WPINC' ) || die();

define( __NAMESPACE__ . '\PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', \plugins_url( '/', __FILE__ ) );

/**
 * Register assets.
 *
 * @return void
 */
function register_assets() {
	$script_info = require PLUGIN_DIR . 'build/index.asset.php';

	wp_register_script(
		'virtual-embeds',
		PLUGIN_URL . 'build/index.js',
		$script_info['dependencies'],
		$script_info['version'],
		false
	);

	wp_register_style(
		'virtual-embeds',
		PLUGIN_URL . 'style.css',
		array(),
		$script_info['version']
	);

	wp_set_script_translations( 'virtual-embeds', 'wordcamporg' );

	register_block_type(
		'wordcamp/crowdcast-embed',
		array(
			'editor_script'   => 'virtual-embeds',
			'editor_style'    => 'virtual-embeds',
			'render_callback' => __NAMESPACE__ . '\render_crowdcast_block',
		)
	);

	register_block_type(
		'wordcamp/streamtext-embed',
		array(
			'editor_script' => 'virtual-embeds',
			'editor_style'  => 'virtual-embeds',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_assets', 9 );

/**
 * Render the CrowdCast embed on the front end.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function render_crowdcast_block( $attributes ) {
	if ( ! isset( $attributes['channel'] ) ) {
		return '';
	}

	$channel = sanitize_text_field( $attributes['channel'] );
	$url     = sprintf( 'https://www.crowdcast.io/e/%s?navlinks=false&embed=true', $channel );
	$embed   = '<iframe width="100%" height="800" frameborder="0" marginheight="0" marginwidth="0" allowtransparency="true" src="' . esc_url( $url ) . '" style="border: 1px solid #EEE;border-radius:3px" allowfullscreen="true" webkitallowfullscreen="true" mozallowfullscreen="true" allow="microphone; camera;"></iframe>';

	$classes = '';
	if ( isset( $attributes['align'] ) && $attributes['align'] ) {
		$classes .= 'align' . sanitize_html_class( $attributes['align'] );
	}

	return '<div class="wp-block-wordcamp-crowdcast-embed ' . $classes . '">' . $embed . '</div>';
}
