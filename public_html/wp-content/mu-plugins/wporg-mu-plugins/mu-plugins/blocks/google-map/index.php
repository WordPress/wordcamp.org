<?php

/**
 * Block Name: WordPress.org Google Map
 * Description: Renders a Google Map in a block template (no editor UI).
 */

namespace WordPressdotorg\MU_Plugins\Google_Map;

require_once __DIR__ . '/inc/event-filters.php';

add_action( 'init', __NAMESPACE__ . '\init' );


/**
 * Registers the block from `block.json`.
 */
function init() {
	register_block_type(
		__DIR__ . '/build',
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
	if ( ! empty( $attributes['apiKey'] ) ) {
		// See README for why this has to be a constant.
		$attributes['apiKey'] = constant( $attributes['apiKey'] );
	}

	$attributes['startDate'] = (int) strtotime( $attributes['startDate'] );
	$attributes['endDate']   = (int) strtotime( $attributes['endDate'] );

	$attributes['searchIcon'] = plugins_url( 'images/search.svg', __FILE__ );

	$attributes['markerIcon'] = array(
		'imagesDirUrl'        => plugins_url( 'images', __FILE__ ),
		'markerHeight'        => 68,
		'markerWidth'         => 68,
		'markerAnchorYOffset' => -5,
		'clusterWidth'        => 38,
		'clusterHeight'       => 38,
	);

	$attributes['markerIcon']['markerAnchorXOffset'] = $attributes['markerIcon']['markerWidth'] / -4;

	if ( ! empty( $attributes['filterSlug'] ) ) {
		$attributes['markers'] = get_events( $attributes['filterSlug'], $attributes['startDate'], $attributes['endDate'] );

		// This has to be called in `render()` to know which slug/dates to use.
		schedule_filter_cron( $attributes['filterSlug'], $attributes['startDate'], $attributes['endDate'] );
	}

	$handles = array( $block->block_type->view_script_handles[0], $block->block_type->editor_script_handles[0] );

	foreach ( $handles as $handle ) {
		wp_add_inline_script(
			$handle,
			sprintf(
				'var wporgGoogleMap = wporgGoogleMap || {};
				wporgGoogleMap["%s"] = %s;',
				$attributes['id'],
				wp_json_encode( $attributes )
			),
			'before'
		);
	}

	$wrapper_attributes = get_block_wrapper_attributes( array(
		'id'          => 'wp-block-wporg-google-map-' . $attributes['id'],
		'class'       => isset( $attributes['align'] ) ? 'align' . $attributes['align'] : '',
		'data-map-id' => $attributes['id'],
	) );

	ob_start();

	?>

	<div <?php echo wp_kses_data( $wrapper_attributes ); ?>>
		Loading...
	</div>

	<?php

	return ob_get_clean();
}
