<?php
namespace WordCamp\Blocks\Camptix;

defined( 'WPINC' ) || die();

/**
 * Register block types and enqueue scripts.
 *
 * @return void
 */
function init() {
	register_block_type_from_metadata(
		__DIR__,
		array(
			'render_callback' => __NAMESPACE__ . '\render',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\init' );

/**
 * Renders the block on the server.
 *
 * The actual rendering is handled by the CampTix shortcode pipeline.
 * Block attributes are consumed during template_redirect() in the CampTix plugin.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Block default content.
 * @param WP_Block $block      Block instance.
 * @return string Returns the ticket form output.
 */
function render( $attributes, $content, $block ) {
	wp_enqueue_style( 'camptix' );
	wp_enqueue_script( 'camptix' );

	/** @var CampTix_Plugin $camptix */
	global $camptix;

	if ( isset( $camptix ) && ! empty( $camptix->shortcode_contents ) ) {
		return $camptix->shortcode_contents;
	}

	return do_shortcode( '[camptix]' );
}

/**
 * Add data to be used by the JS scripts in the block editor.
 *
 * @param array $data
 *
 * @return array
 */
function add_script_data( array $data ) {
	if (
		! is_admin() ||
		( function_exists( 'wp_should_load_block_editor_scripts_and_styles' ) && ! wp_should_load_block_editor_scripts_and_styles() )
	) {
		return $data;
	}

	$tickets     = array();
	$ticket_posts = get_posts( array(
		'post_type'      => 'tix_ticket',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	) );

	/** @var \CampTix_Plugin $camptix */
	global $camptix;

	foreach ( $ticket_posts as $ticket ) {
		$price = (float) get_post_meta( $ticket->ID, 'tix_price', true );

		$tickets[] = array(
			'id'             => $ticket->ID,
			'title'          => $ticket->post_title,
			'price'          => $price,
			'formattedPrice' => ( 0.0 === $price )
				? __( 'Free', 'wordcamporg' )
				: ( isset( $camptix ) ? $camptix->append_currency( $price, false ) : (string) $price ),
		);
	}

	$has_coupons = isset( $camptix ) ? $camptix->have_coupons() : false;

	$data['camptix'] = array(
		'tickets'    => $tickets,
		'hasCoupons' => $has_coupons,
	);

	return $data;
}
add_filter( 'wordcamp_blocks_script_data', __NAMESPACE__ . '\add_script_data' );
