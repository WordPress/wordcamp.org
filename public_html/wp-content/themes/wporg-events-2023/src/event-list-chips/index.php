<?php
/**
 * Block Name: WordPress Event List Chips
 * Description: Chips for filtering results from the wporg/event-list block.
 */

namespace WordPressdotorg\Theme\Events_2023\WordPress_Event_List_Chips;
use WP_Block;

add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Register the block.
 */
function init() {
	register_block_type(
		dirname( __DIR__, 2 ) . '/build/event-list-chips',
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}

/**
 * Render the block content.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 *
 * @return string Returns the block markup.
 */
function render( $attributes, $content, $block ) {
	$wrapper_attributes = get_block_wrapper_attributes( array( 'id' => $attributes['id'] ?? '' ) );

	ob_start();
	?>

	<div <?php echo $wrapper_attributes; ?>>
		<button id="wporg-events__see-global">
			Showing events near me
		</button>

		<button id="wporg-events__see-nearby" class="wporg-events__hidden">
			Showing global events
		</button>
	</div>

	<?php

	return ob_get_clean();
}
