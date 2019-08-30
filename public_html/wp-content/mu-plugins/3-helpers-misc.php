<?php

defined( 'WPINC' ) || die();
use function WordCamp\Logger\log;

/*
 * Miscellaneous helper functions.
 */


/**
 * Determine if a specific feature should be skipped on the current site
 *
 * Warning: Pay careful attention to how things are named, since setting a flag here is typically done when you
 * want a feature to be _enabled_ by default on new sites. In the cases where you want it _disabled_ by default
 * on new sites, you can reverse the name (e.g., `local_terms` instead of `global_terms` for the
 * `global_terms_enabled` callback).
 *
 * Often times we want to add new functionality to plugins and themes, but can't let it run on older sites
 * because that would break backwards compatibility. To get around that, we set a flag on older sites to
 * indicate that they should not have the new feature, and then setup the feature to run on sites that
 * don't have the flag, i.e., to run by default.
 *
 * Doing it this way means that local development environments like the Meta Environment don't to have add any
 * new filters in order to start using the new functionality.
 *
 * See WordCamp_CLI_Miscellaneous::set_skip_feature_flag() for how to set the flags.
 *
 * @param string $flag
 *
 * @return bool
 */
function wcorg_skip_feature( $flag ) {
	$flags = get_option( 'wordcamp_skip_features', array() );

	return isset( $flags[ $flag ] );
}

/**
 * Get a user by the username or nicename
 *
 * Note: This intentionally doesn't lookup users by the display name or nickname, because those can be set by the
 * user, which could result in false-positive matches.
 *
 * @param string $name
 *
 * @return false|WP_User
 */
function wcorg_get_user_by_canonical_names( $name ) {
	$user = get_user_by( 'login', $name ); // user_login.

	if ( ! $user ) {
		$user = get_user_by( 'slug', $name ); // user_nicename.
	}

	return $user;
}

/**
 * Get CLDR country names and codes
 *
 * @todo move the real functionality from get_valid_countries_iso3166() to here, then have the Budgets plugin,
 * QBO, etc call this.
 *
 * @param array $args
 *
 * @return array
 */
function wcorg_get_countries( array $args = array() ) {
	$defaults = array(
		'include_alpha3' => false,
	);

	$args = wp_parse_args( $args, $defaults );

	require_once WP_PLUGIN_DIR . '/wp-cldr/class-wp-cldr.php';

	$cldr        = new WP_CLDR();
	$territories = $cldr->get_territories_contained( '001' ); // "World".
	$countries   = array();

	if ( true === $args['include_alpha3'] ) {
		$data_blob     = WP_CLDR::get_cldr_json_file( 'supplemental', 'codeMappings' );
		$code_mappings = $data_blob['supplemental']['codeMappings'];
	}

	foreach ( $territories as $code ) {
		$countries[ $code ] = array(
			'alpha2' => $code,
			'name'   => $cldr->get_territory_name( $code ),
		);

		if ( true === $args['include_alpha3'] ) {
			$countries[ $code ]['alpha3'] = ( ! empty( $code_mappings[ $code ]['_alpha3'] ) )
				? $code_mappings[ $code ]['_alpha3']
				: '';
		}
	}

	// ASCII transliteration doesn't work if the LC_CTYPE is 'C' or 'POSIX'.
	// See https://www.php.net/manual/en/function.iconv.php#74101.
	$orig_locale = setlocale( LC_CTYPE, 0 );
	setlocale( LC_CTYPE, 'en_US.UTF-8' );

	// Sort the country names based on ASCII transliteration without actually changing any strings.
	usort(
		$countries,
		function( $a, $b ) {
			return strcasecmp(
				iconv( mb_detect_encoding( $a['name'] ), 'ascii//TRANSLIT', $a['name'] ),
				iconv( mb_detect_encoding( $b['name'] ), 'ascii//TRANSLIT', $b['name'] )
			);
		}
	);

	setlocale( LC_CTYPE, $orig_locale );

	return $countries;
}

/**
 * Make a remote HTTP request, and retry if it fails
 *
 * Sometimes the HTTP request times out, or there's a temporary server-side error, etc. Some use cases require a
 * successful request, like stats scripts, where the resulting data would be distorted by a failed response.
 *
 * @todo Add support for wp_remote_post() too
 * @todo Remove this if https://github.com/rmccue/Requests/issues/222 is implemented
 * @todo maybe `set_time_limit( absint( ini_get( 'max_execution_time' ) ) + $retry_after );` before sleep()'ing to
 *       avoid php timeout
 *
 * @param string $request_url
 * @param array  $request_args
 *
 * @return array|WP_Error
 */
function wcorg_redundant_remote_get( $request_url, $request_args = array() ) {
	$attempt_count = 1;

	while ( true ) {
		$response    = wp_remote_get( $request_url, $request_args );
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' ) ?: 5;
		$retry_after = min( $retry_after * $attempt_count, 30 );

		if ( ! is_wp_error( $response ) && 200 === $status_code && $body ) {
			break;
		}

		if ( $attempt_count < 3 ) {
			log( 'request_failed_temporarily', compact( 'request_url', 'request_args', 'response', 'attempt_count', 'retry_after' ) );
			sleep( $retry_after );
		} else {
			log( 'request_failed_permenantly', compact( 'request_url', 'request_args', 'response' ) );
			break;
		}

		$attempt_count++;
	}

	return $response;
}

/**
 * Display the indicator that marks a form field as required
 */
function wcorg_required_indicator() {
	?>

	<span class="wcorg-required" aria-hidden="true">
		<?php // translators: The symbol to indicate the form field is required. ?>
		<?php esc_html_e( '*', 'wordcamporg' ); ?>
	</span>

	<span class="screen-reader-text">
		<?php esc_html_e( 'required field', 'wordcamporg' ); ?>
	</span>

	<?php
}

/**
 * Get the URL of Jetpack's Custom CSS file.
 *
 * Core normally just prints the CSS inline, but Jetpack enqueues it if it's longer than 2k characters. Jetpack
 * doesn't provide a function to access the URL, though, and duplicating the logic in `Jetpack_Custom_CSS_Enhancements::wp_custom_css_cb()`
 * wouldn't be resilient or future-proof. So, we have to jump through some hoops to get it safely.
 *
 * @return bool|string
 */
function wcorg_get_custom_css_url() {
	/*
	 * This has side-effects because `add_hooks()` is called immediately, but it doesn't seem problematic because
	 * it gets loaded on every front/back-end page anyway.
	 */
	require_once WP_PLUGIN_DIR . '/jetpack/modules/custom-css/custom-css-4.7.php';

	ob_start();
	Jetpack_Custom_CSS_Enhancements::wp_custom_css_cb();
	$markup = ob_get_clean();

	if ( ! $markup ) {
		return false;
	}

	$dom = new DOMDocument();
	$dom->loadHTML( $markup );
	$element = $dom->getElementById( 'wp-custom-css' );

	return $element instanceof DOMElement ? $element->getAttribute( 'href' ) : false;
}
