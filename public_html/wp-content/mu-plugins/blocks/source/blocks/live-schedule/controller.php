<?php
namespace WordCamp\Blocks\Live_Schedule;

defined( 'WPINC' ) || die();

/**
 * Register Live Schedule block and enqueue assets.
 */
function init() {
	$deps_path    = \WordCamp\Blocks\PLUGIN_DIR . 'build/live-schedule.min.deps.json';
	$dependencies = file_exists( $deps_path ) ? json_decode( file_get_contents( $deps_path ) ) : array();

	wp_register_script(
		'wordcamp-live-schedule',
		\WordCamp\Blocks\PLUGIN_URL . 'build/live-schedule.min.js',
		$dependencies,
		filemtime( \WordCamp\Blocks\PLUGIN_DIR . 'build/live-schedule.min.js' ),
		true
	);

	/** This filter is documented in mu-plugins/blocks/blocks.php */
	$data = apply_filters( 'wordcamp_blocks_script_data', [] );

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
		\WordCamp\Blocks\PLUGIN_URL . 'build/live-schedule.min.css',
		[],
		filemtime( \WordCamp\Blocks\PLUGIN_DIR . 'build/live-schedule.min.css' )
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
	$data['live-schedule'] = [
		'scheduleUrl' => esc_url( site_url( __( 'schedule', 'wordcamporg' ) ) ),
	];

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
