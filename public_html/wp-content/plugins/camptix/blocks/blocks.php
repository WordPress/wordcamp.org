<?php
namespace CampTix\Blocks;

defined( 'WPINC' ) || die();

/**
 * Register assets.
 *
 * The assets get enqueued automatically by the registered block types.
 *
 * @return void
 */
function register_assets() {
	$path        = __DIR__ . '/build/attendee-content.js';
	$deps_path   = __DIR__ . '/build/attendee-content.asset.php';
	$script_info = file_exists( $deps_path )
		? require $deps_path
		: array(
			'dependencies' => array(),
			'version'      => filemtime( $path ),
		);

	wp_register_script(
		'camptix-blocks',
		plugins_url( 'build/attendee-content.js', __FILE__ ),
		$script_info['dependencies'],
		$script_info['version'],
		false
	);

	wp_set_script_translations( 'camptix-blocks', 'wordcamporg' );

	// Set up the Attendee Content block.
	register_block_type(
		'camptix/attendee-content',
		array(
			'editor_script'   => 'camptix-blocks',
			'render_callback' => function( $attributes, $content ) {
				return sprintf( '[camptix_private]%s[/camptix_private]', $content );
			},
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_assets' );
