<?php
/**
 * Block Name: wporg Flagship Landing Timeline
 * Description: List of events for the current flagship domain.
 */

namespace WordPressdotorg\Flagship_Landing\Timeline;
use WordPressdotorg\Flagship_Landing;
use WP_Post, WP_Block;

add_action( 'init', __NAMESPACE__ . '\init' );


/**
 * Register the block.
 */
function init() {
	register_block_type(
		dirname( __DIR__, 2 ) . '/build/timeline',
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
	$events = Flagship_Landing\get_flagship_events();

	ob_start();
	?>

	<ul>
		<?php foreach ( $events as $event ) : ?>
			<li class="<?php echo esc_attr( $event->post_status ); ?>">
				<a href="<?php echo esc_url( $event->meta['URL'][0] ); ?>">
					<div>
						<?php echo esc_html( Flagship_Landing\get_wordcamp_year( $event ) ); ?>

						<img src="<?php echo esc_url( get_site_icon( $event ) ); ?>" alt="" />

						<?php echo esc_html( get_location( $event ) ); ?>

						<?php

						if ( 'wcpt-cancelled' === $event->post_status ) {
							esc_html_e( 'Cancelled', 'wordcamporg' );
						} else {
							echo esc_html( get_date_range( $event ) );
						}

						?>
					</div>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php

	$content            = ob_get_clean();
	$wrapper_attributes = get_block_wrapper_attributes();

	return sprintf(
		'<div %1$s>%2$s</div>',
		$wrapper_attributes,
		do_blocks( $content )
	);
}


/**
 * Get the location of the event.
 */
function get_location( WP_Post $wordcamp ): string {
	return $wordcamp->meta['_venue_city'][0] ?? $wordcamp->meta['Location'][0];
}

/**
 * Get the site icon for the event.
 */
function get_site_icon( WP_Post $wordcamp ): string {
	$fallback_image = get_stylesheet_directory_uri() . '/images/w-mark.svg';

	if ( empty( $wordcamp->_site_id ) ) {
		$logo = $fallback_image;
	} else {
		switch_to_blog( $wordcamp->_site_id );
		$logo = get_site_icon_url( 160, $fallback_image, $wordcamp->_site_id );
		restore_current_blog();
	}

	return $logo;
}

/**
 * Get the date range for the event.
 *
 * Forked from `get_wordcamp_date_range()`.
 */
function get_date_range( WP_Post $wordcamp ): string {
	$start = (int) $wordcamp->meta['Start Date (YYYY-mm-dd)'][0];
	$end   = (int) $wordcamp->meta['End Date (YYYY-mm-dd)'][0];

	// Assume a single-day event if there is no end date.
	if ( ! $end ) {
		return gmdate( 'M jS', $start );
	}

	$range_str = esc_html__( '%1$s to %2$s', 'wordcamporg' );

	if ( gmdate( 'Y', $start ) !== gmdate( 'Y', $end ) ) {
		return sprintf( $range_str, gmdate( 'M jS, Y', $start ), gmdate( 'M jS, Y', $end ) );
	} else if ( gmdate( 'm', $start ) !== gmdate( 'm', $end ) ) {
		return sprintf( $range_str, gmdate( 'M jS', $start ), gmdate( 'M jS', $end ) );
	} else {
		return sprintf( $range_str, gmdate( 'M jS', $start ), gmdate( 'jS', $end ) );
	}
}
