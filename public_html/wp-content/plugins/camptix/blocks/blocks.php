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
	// Set up the Attendee Content block.
	register_block_type_from_metadata(
		__DIR__ . '/src/attendee-content',
		array(
			'render_callback' => function( $attributes, $content ) {
				return sprintf(
					'[camptix_private mark_attended="%s"]%s[/camptix_private]',
					$attributes['markAttended'],
					$content
				);
			},
		)
	);
	wp_set_script_translations( 'camptix-attendee-content-editor-script', 'wordcamporg' );
}
add_action( 'init', __NAMESPACE__ . '\register_assets' );
