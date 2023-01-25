<?php

use const WordCamp\Sunrise\{ PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH, PATTERN_YEAR_DOT_CITY_DOMAIN_PATH };

defined( 'WPINC' ) || die();

/*
 * Helper functions related to the `wordcamp` post type.
 */


/**
 * Retrieve `wordcamp` posts and their metadata.
 *
 * @param array $args Optional. Extra arguments to pass to `get_posts()`.
 *
 * @return array
 */
function get_wordcamps( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'post_type'   => WCPT_POST_TYPE_ID,
			'post_status' => 'any',
			'orderby'     => 'ID',
			'numberposts' => -1,
			'perm'        => 'readable',
		)
	);

	$wordcamps = get_posts( $args );

	foreach ( $wordcamps as &$wordcamp ) {
		$wordcamp->meta = get_post_custom( $wordcamp->ID );
	}

	return $wordcamps;
}

/**
 * Retrieves the `wordcamp` post and postmeta associated with the current site.
 *
 * @return false|WP_Post
 */
function get_wordcamp_post( $site_id = null ) {
	if ( ! $site_id ) {
		$site_id = get_current_blog_id();
	}

	// Switch to central.wordcamp.org to get posts.
	switch_to_blog( BLOG_ID_CURRENT_SITE );

	$wordcamp = get_posts( array(
		'post_type'   => 'wordcamp',
		'post_status' => 'any',
		'meta_key'    => '_site_id',
		'meta_value'  => $site_id,
	) );

	if ( isset( $wordcamp[0]->ID ) ) {
		$wordcamp       = $wordcamp[0];
		$wordcamp->meta = get_post_custom( $wordcamp->ID );
	} else {
		$wordcamp = false;
	}

	restore_current_blog();

	return $wordcamp;
}

/**
 * Find the site that corresponds to the given `wordcamp` post
 *
 * @param WP_Post $wordcamp_post
 *
 * @return mixed An integer if successful, or boolean false if failed
 */
function get_wordcamp_site_id( $wordcamp_post ) {
	// Switch to central.wordcamp.org to get post meta.
	switch_to_blog( BLOG_ID_CURRENT_SITE );

	$site_id = get_post_meta( $wordcamp_post->ID, '_site_id', true );
	if ( ! $site_id ) {
		$url = parse_url( get_post_meta( $wordcamp_post->ID, 'URL', true ) );

		if ( isset( $url['host'] ) && isset( $url['path'] ) ) {
			$site = get_site_by_path( $url['host'], $url['path'] );
			if ( $site ) {
				$site_id = $site->blog_id;
			}
		}
	}

	restore_current_blog();

	return $site_id;
}

/**
 * Get a consistent WordCamp name in the 'WordCamp [Location] [Year]' format.
 *
 * The results of bloginfo( 'name' ) don't always contain the year, but the title of the site's corresponding
 * `wordcamp` post is usually named 'WordCamp [Location]', so we can get a consistent name most of the time
 * by using that and adding the year (if available).
 *
 * @param int $site_id Optionally, get the name for a site other than the current one.
 *
 * @return string
 */
function get_wordcamp_name( $site_id = 0 ) {
	$name = false;

	switch_to_blog( $site_id );

	$wordcamp = get_wordcamp_post();
	if ( $wordcamp ) {
		if ( ! empty( $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ) ) {
			$name = $wordcamp->post_title;
			$year = gmdate( 'Y', $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] );

			// Append the year to the WordCamp name if not present within the name.
			if ( ! str_contains( $name, $year ) ) {
				$name .= ' ' . $year;
			}
		}
	}

	if ( ! $name ) {
		$name = get_bloginfo( 'name' );
	}

	restore_current_blog();

	return $name;
}


/**
 * Extract pieces from a WordCamp.org URL
 *
 * @todo find other code that's doing this same task in an ad-hoc manner, and convert it to use this instead
 *
 * @param string $site_url The root URL for the site, without any query string. It can include the site path
 *                         -- e.g., `https://narnia.wordcamp.org/2020` -- but should not include a post slug,
 *                         etc.
 * @param string $part     'city', 'year', or 'city-domain' (city and domain without the year, e.g.
 *                         seattle.wordcamp.org).
 *
 * @return false|string|int False on errors; an integer for years; a string for `city` and `city-domain`.
 */
function wcorg_get_url_part( $site_url, $part ) {
	$result    = false;
	$site_url  = trailingslashit( $site_url );
	$url_parts = wp_parse_url( $site_url );

	$is_year_dot_city_url = preg_match(
		PATTERN_YEAR_DOT_CITY_DOMAIN_PATH,
		$url_parts['host'] . $url_parts['path'],
		$year_dot_city_matches
	);

	$is_city_slash_url = preg_match(
		PATTERN_CITY_SLASH_YEAR_DOMAIN_PATH,
		$url_parts['host'] . $url_parts['path'],
		$city_slash_year_matches
	);

	switch ( $part ) {
		case 'city':
			if ( $is_year_dot_city_url ) {
				$result = $year_dot_city_matches[2];
			} else if ( $is_city_slash_url ) {
				$result = $city_slash_year_matches[1];
			}

			break;

		case 'city-domain':
			if ( $is_year_dot_city_url ) {
				$result = sprintf(
					'%s.%s.%s',
					$year_dot_city_matches[2],
					$year_dot_city_matches[3],
					$year_dot_city_matches[4]
				);

			} else if ( $is_city_slash_url ) {
				$result = sprintf(
					'%s.%s.%s',
					$city_slash_year_matches[1],
					$city_slash_year_matches[2],
					$city_slash_year_matches[3]
				);
			}

			break;

		case 'year':
			if ( $is_year_dot_city_url ) {
				$result = absint( $year_dot_city_matches[1] );
			} else if ( $is_city_slash_url ) {
				$result = absint( trim( $city_slash_year_matches[4], '/' ) );
			}


			break;
	}

	return $result;
}


/**
 * Take the start and end dates for a WordCamp and calculate how many days it lasts.
 *
 * @param WP_Post $wordcamp
 *
 * @return int
 */
function wcorg_get_wordcamp_duration( WP_Post $wordcamp ) {
	// @todo Make sure $wordcamp is the correct post type

	$start = get_post_meta( $wordcamp->ID, 'Start Date (YYYY-mm-dd)', true );
	$end   = get_post_meta( $wordcamp->ID, 'End Date (YYYY-mm-dd)', true );

	// Assume 1 day duration if there is no end date.
	if ( ! $end ) {
		return 1;
	}

	$duration_raw = $end - $start;

	// Add one second and round up to ensure the end date counts as a day as well.
	$duration_days = ceil( ( $duration_raw + 1 ) / DAY_IN_SECONDS );

	return absint( $duration_days );
}

/**
 * Get a <select> dropdown of `wordcamp` posts with a select2 UI.
 *
 * The calling plugin is responsible for validating and processing the form, this just outputs a single field.
 *
 * @param string $name          Optional. The `name` attribute for the `select` element. Defaults to `wordcamp_id`.
 * @param array  $query_options Optional. Extra arguments to pass to `get_posts()`. Defaults to the values in `get_wordcamps()`.
 * @param int    $selected      Optional. The list option to select. Defaults to not selecting any.
 *
 * @return string The HTML for the <select> list.
 */
function get_wordcamp_dropdown( $name = 'wordcamp_id', $query_options = array(), $selected = 0 ) {
	global $wpdb;
	$wordcamps = $wpdb->get_results( "SELECT wc_5_posts.ID, wc_5_posts.post_title, wc_5_posts.post_status, wc_5_postmeta.meta_value  as start_date FROM wc_5_posts LEFT JOIN wc_5_postmeta ON wc_5_postmeta.post_id = wc_5_posts.ID WHERE 1=1 AND wc_5_posts.post_type =  'wordcamp' AND ((wc_5_posts.post_status <> 'trash' AND wc_5_posts.post_status <> 'auto-draft' AND wc_5_posts.post_status <> 'spam')) AND wc_5_postmeta.meta_key = 'Start Date (YYYY-mm-dd)' ORDER BY `wc_5_posts`.`ID` DESC" );

	wp_enqueue_script( 'select2' );
	wp_enqueue_style(  'select2' );

	ob_start();

	?>

	<select name="<?php echo esc_attr( $name ); ?>" class="select2">
		<option value=""><?php esc_html_e( 'Select a WordCamp', 'wordcamporg' ); ?></option>
		<option value=""></option>

		<?php foreach ( $wordcamps as $wordcamp ) : ?>
			<option
				value="<?php echo esc_attr( $wordcamp->ID ); ?>"
				<?php selected( $selected, $wordcamp->ID ); ?>
			>
				<?php

				echo esc_html( $wordcamp->post_title );
				if ( ! empty( $wordcamp->start_date ) ) {
					echo ' ' . esc_html( gmdate( 'Y', $wordcamp->start_date ) );
				}

				?>
			</option>
		<?php endforeach; ?>
	</select>

	<script>
		jQuery( document ).ready( function() {
			jQuery( '.select2' ).select2();
		} );
	</script>

	<?php

	return ob_get_clean();
}

/**
 * Display a human-friendly date range for a given WordCamp.
 *
 * @param WP_Post $wordcamp
 *
 * @return string
 */
function get_wordcamp_date_range( $wordcamp ) {
	if ( ! $wordcamp instanceof WP_Post || 'wordcamp' !== $wordcamp->post_type ) {
		return '';
	}

	// Switch to central.wordcamp.org to get post meta.
	switch_to_blog( BLOG_ID_CURRENT_SITE );
	$start = (int) get_post_meta( $wordcamp->ID, 'Start Date (YYYY-mm-dd)', true );
	$end   = (int) get_post_meta( $wordcamp->ID, 'End Date (YYYY-mm-dd)', true );
	restore_current_blog();

	// Assume a single-day event if there is no end date.
	if ( ! $end ) {
		return gmdate( 'F j, Y', $start );
	}

	$range_str = esc_html__( '%1$s to %2$s', 'wordcamporg' );

	if ( gmdate( 'Y', $start ) !== gmdate( 'Y', $end ) ) {
		return sprintf( $range_str, gmdate( 'F j, Y', $start ), gmdate( 'F j, Y', $end ) );
	} else if ( gmdate( 'm', $start ) !== gmdate( 'm', $end ) ) {
		return sprintf( $range_str, gmdate( 'F j', $start ), gmdate( 'F j, Y', $end ) );
	} else {
		return sprintf( $range_str, gmdate( 'F j', $start ), gmdate( 'j, Y', $end ) );
	}
}

/**
 * Display a human-friendly date range for a given WordCamp.
 *
 * @param WP_Post $wordcamp
 *
 * @return string
 */
function get_wordcamp_location( $wordcamp ) {
	if ( ! $wordcamp instanceof WP_Post || 'wordcamp' !== $wordcamp->post_type ) {
		return;
	}

	// Switch to central.wordcamp.org to get post meta.
	switch_to_blog( BLOG_ID_CURRENT_SITE );
	$venue   = get_post_meta( $wordcamp->ID, 'Venue Name', true );
	$address = get_post_meta( $wordcamp->ID, 'Physical Address', true );
	restore_current_blog();

	return $venue . "\n" . $address;
}

/**
 * Check if this WordCamp is virtual-only or in-person/hybrid.
 *
 * @param WP_Post $wordcamp
 *
 * @return bool
 */
function is_wordcamp_virtual( $wordcamp ) {
	if ( ! $wordcamp instanceof WP_Post || 'wordcamp' !== $wordcamp->post_type ) {
		return false;
	}

	// Switch to central.wordcamp.org to get post meta.
	switch_to_blog( BLOG_ID_CURRENT_SITE );
	$is_virtual = (bool) get_post_meta( $wordcamp->ID, 'Virtual event only', true );
	restore_current_blog();

	return $is_virtual;
}
