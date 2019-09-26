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

	$config = array(
		'scheduleUrl' => esc_url( site_url( __( 'schedule', 'wordcamporg' ) ) ),
	);

	$config_script = sprintf(
		'var blockLiveSchedule = JSON.parse( decodeURIComponent( \'%s\' ) );',
		rawurlencode( wp_json_encode( $config ) )
	);

	wp_add_inline_script( 'wordcamp-live-schedule', $config_script, 'before' );

	wp_register_style(
		'wordcamp-live-schedule',
		\WordCamp\Blocks\PLUGIN_URL . 'build/live-schedule.min.css',
		[],
		filemtime( \WordCamp\Blocks\PLUGIN_DIR . 'build/live-schedule.min.css' )
	);

	register_block_type(
		'wordcamp/live-schedule',
		array(
			'editor_script' => 'wordcam-blocks',
			'editor_style'  => 'wordcam-blocks',
			'style'         => 'wordcamp-live-schedule',
			'script'        => 'wordcamp-live-schedule',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\init' );
