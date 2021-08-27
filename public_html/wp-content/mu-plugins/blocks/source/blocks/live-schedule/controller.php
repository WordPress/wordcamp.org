<?php
namespace WordCamp\Blocks\Live_Schedule;
use WordCamp_Post_Types_Plugin;

defined( 'WPINC' ) || die();

/**
 * Register Live Schedule block and enqueue assets.
 */
function init() {
	$path        = \WordCamp\Blocks\PLUGIN_DIR . 'build/live-schedule.min.js';
	$deps_path   = \WordCamp\Blocks\PLUGIN_DIR . 'build/live-schedule.min.asset.php';
	$script_info = file_exists( $deps_path )
		? require $deps_path
		: array(
			'dependencies' => array(),
			'version'      => filemtime( $path ),
		);

	// Special case, because this isn't a wp package.
	$script_info['dependencies'][] = 'wp-sanitize';

	wp_register_script(
		'wordcamp-live-schedule',
		\WordCamp\Blocks\PLUGIN_URL . 'build/live-schedule.min.js',
		$script_info['dependencies'],
		$script_info['version'],
		true
	);

	/** This filter is documented in mu-plugins/blocks/blocks.php */
	$data = apply_filters( 'wordcamp_blocks_script_data', array() );

	wp_add_inline_script(
		'wordcamp-live-schedule',
		sprintf(
			'var WordCampBlocks = JSON.parse( decodeURIComponent( \'%s\' ) );',
			rawurlencode( wp_json_encode( $data ) )
		),
		'before'
	);

	wp_set_script_translations( 'wordcamp-live-schedule', 'wordcamporg' );

	wp_register_style(
		'wordcamp-live-schedule',
		\WordCamp\Blocks\PLUGIN_URL . 'build/live-schedule.css',
		array(),
		filemtime( \WordCamp\Blocks\PLUGIN_DIR . 'build/live-schedule.css' )
	);

	register_block_type(
		'wordcamp/live-schedule',
		array(
			'editor_script' => 'wordcamp-blocks',
			'editor_style'  => 'wordcamp-blocks',
			'style'         => 'wordcamp-live-schedule',
			'script'        => 'wordcamp-live-schedule',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	$data['live-schedule'] = array(
		'scheduleUrl' => esc_url( site_url( __( 'schedule', 'wordcamporg' ) ) ),
		'fallbackDuration' => WordCamp_Post_Types_Plugin::SESSION_DEFAULT_DURATION,
	);

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
