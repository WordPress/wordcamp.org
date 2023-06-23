<?php

namespace WordCamp\Permalinks;
defined( 'WPINC' ) || die();

add_filter( 'pre_update_option_permalink_structure', 'wcorg_prevent_date_permalinks' );


/**
 * Prevent permalink structures from starting with `%year%`
 *
 * See https://make.wordpress.org/community/2020/03/03/proposal-for-wordcamp-sites-seo-fixes/#comment-28213.
 * See `WordCamp\Sunrise\Tests\Test_Sunrise\data_get_canonical_year_url()`.
 *
 * @param string $new_value
 *
 * @return string
 */
function wcorg_prevent_date_permalinks( $new_value ) {
	if ( '/%year%' === substr( $new_value, 0, 7 ) ) {
		wp_die(
			'<p>' .
			__( "WordCamp.org permalinks can't start with `%year%`, because that conflicts with our URL structure (`https://city.wordcamp.org/year`).", 'wordcamporg' ) .
			'</p> <p>' .
			__( 'Please add a prefix like `/news/`, or choose a different structure (like `%postname%`).', 'wordcamporg' ) .
			'</p>'
		);
	}

	return $new_value;
}
