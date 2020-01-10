<?php
/**
 * Functionality to output a list of global sponsors.
 *
 * This was written in such a way that it could easily be converted into a fully-fledged block. However, the current
 * use case is only to output a dynamic list of sponsors on the WordCamp Central homepage, and so, by the principle
 * of YAGNI, only the parts of the block necessary for that use case have been fleshed out so far.
 */

namespace WordCamp\Multi_Event_Sponsors\Block;

use MES_Region, MES_Sponsor, MES_Sponsorship_Level;
use function WordCamp\Blocks\Components\{ render_post_list };

defined( 'WPINC' ) || die();

/**
 * Renders the Global Sponsors widget/block.
 *
 * @param array $attributes
 *
 * @return string
 */
function render( $attributes ) {
	$attributes = wp_parse_args(
		$attributes,
		array(
			'number'      => 3,
			'region_id'   => 'all',
			'level_id'    => 'all',
			'image_align' => 'left',
			'image_width' => 300,
		)
	);

	$sponsors = get_sponsor_posts( $attributes );
	$rendered = array();

	foreach ( $sponsors as $sponsor ) {
		ob_start();
		require dirname( __DIR__ ) . '/views/block-sponsor.php';
		$rendered[] = ob_get_clean();
	}

	$container_classes = array(
		'wordcamp-mes-sponsors',
	);

	return render_post_list( $rendered, 'list', 1, $container_classes );
}

/**
 * Get a list of sponsor posts that meet the criteria.
 *
 * @param array $attributes
 *
 * @return array
 */
function get_sponsor_posts( array $attributes ) {
	$post_args = array(
		'post_type'      => MES_Sponsor::POST_TYPE_SLUG,
		'post_status'    => 'publish',
		'orderby'        => 'RAND(' . wp_date( 'YmdH' ) . ')',
		'posts_per_page' => 99,
	);

	$all_sponsor_posts = get_posts( $post_args );
	$region_ids        = get_region_ids();
	$level_ids         = get_level_ids();

	$sponsor_posts = array_filter(
		$all_sponsor_posts,
		function( $post ) use ( $attributes, $region_ids, $level_ids ) {
			$regional_sponsorships = array_map( 'absint', $post->mes_regional_sponsorships );

			$regional_sponsorships = array_filter(
				$regional_sponsorships,
				function( $value, $key ) use ( $attributes, $region_ids, $level_ids ) {
					if ( 'all' === $attributes['region_id'] ) {
						$region_match = in_array( $key, $region_ids, true );
					} else {
						$region_match = $attributes['region_id'] === $key;
					}

					$level_match = false;

					if ( $region_match ) {
						if ( 'all' === $attributes['level_id'] ) {
							$level_match = in_array( $value, $level_ids, true );
						} else {
							$level_match = $attributes['level_id'] === $value;
						}
					}

					return $region_match && $level_match;
				},
				ARRAY_FILTER_USE_BOTH
			);

			return ! empty( $regional_sponsorships );
		}
	);

	return array_slice( $sponsor_posts, 0, $attributes['number'] );
}

/**
 * Get the list of valid region IDs.
 *
 * @return array
 */
function get_region_ids() {
	$terms = get_terms( array(
		'taxonomy'   => MES_Region::TAXONOMY_SLUG,
		'fields'     => 'id=>name',
		'hide_empty' => false, // Terms are not actually assigned to sponsor posts.
	) );

	$terms = array_filter(
		$terms,
		function( $term_name ) {
			if ( false !== stripos( $term_name, 'Deprecated' ) ) {
				return false;
			}

			return true;
		}
	);

	return array_keys( $terms );
}

/**
 * Get the list of valid sponsor level IDs.
 *
 * @return array
 */
function get_level_ids() {
	$level_posts = get_posts( array(
		'post_type'   => MES_Sponsorship_Level::POST_TYPE_SLUG,
		'post_status' => 'publish',
	) );

	return wp_list_pluck( $level_posts, 'ID' );
}
