<?php

namespace WordCamp\RemoteCSS;
use WordCamp\Logger;
use Jetpack_Custom_CSS_Enhancements;
use Exception;

defined( 'WPINC' ) || die();

/**
 * Synchronizes the local safe/cached copy of the CSS with the canonical, remote source.
 *
 * @todo Minification was removed from Jetpack 4.2.2, but will probably be added back in the future. Once it is,
 *       make sure that it's being run here.
 *
 * @param string $remote_css_url
 */
function synchronize_remote_css( $remote_css_url ) {
	$unsafe_css = fetch_unsafe_remote_css( $remote_css_url );
	$safe_css   = sanitize_unsafe_css( $unsafe_css );

	save_safe_css( $safe_css );
	update_option( OPTION_LAST_UPDATE, time() );
}

/**
 * Fetch the unsafe CSS from the remote server
 *
 * @param string $remote_css_url
 *
 * @throws Exception If the response body could not be retrieved for any reason.
 *
 * @return string
 */
function fetch_unsafe_remote_css( $remote_css_url ) {
	$response = wp_remote_get(
		$remote_css_url,
		array(
			'user-agent'         => USER_AGENT,
			'reject_unsafe_urls' => true,
		)
	);

	if ( is_wp_error( $response ) ) {
		Logger\log( 'request_error', compact( 'remote_css_url', 'response' ) );
		throw new Exception( $response->get_error_message() );
	}

	$response_code = (int) wp_remote_retrieve_response_code( $response );

	if ( ! in_array( $response_code, array( 200, 301, 302, 303, 307, 308 ), true ) ) {
		Logger\log( 'invalid_response_code', compact( 'remote_css_url', 'response' ) );

		throw new Exception( sprintf(
			__( 'The remote server responded with status code <code>%d</code>, which is not valid.', 'wordcamporg' ),
			$response_code
		) );
	}

	return apply_filters( 'wcrcss_unsafe_remote_css', wp_remote_retrieve_body( $response ), $remote_css_url );
}

/**
 * Sanitize unsafe CSS
 *
 * Jetpack/CSSTidy will validate and normalize the CSS, but they do _NOT_ perform comprehensive sanitization from
 * a security perspective. So, we still need to add our custom sanitization to the process. That's done in
 * `mu-plugins/jetpack-tweaks/css-sanitization.php`, but we need to confirm that it actually ran, to protect against
 * a situation where there was a change in Jetpack/CSSTidy, or in our sanitization, and this function wasn't updated
 * to reflect them.
 *
 * @param string $unsafe_css
 *
 * @return string
 *
 * @throws Exception
 */
function sanitize_unsafe_css( $unsafe_css ) {
	if ( ! class_exists( 'Jetpack_Custom_CSS_Enhancements' ) ) {
		if ( version_compare( JETPACK__VERSION, '11.6', '<' ) ) {
			require_once JETPACK__PLUGIN_DIR . '/modules/custom-css/custom-css-4.7.php';
		} else {
			require_once JETPACK__PLUGIN_DIR . '/modules/custom-css/custom-css.php';
		}
	}

	$parser_rules_setup          = has_filter( 'csstidy_optimize_postparse', 'WordCamp\Jetpack_Tweaks\sanitize_csstidy_parsed_rules' );
	$subvalue_sanitization_setup = has_filter( 'csstidy_optimize_subvalue',  'WordCamp\Jetpack_Tweaks\sanitize_csstidy_subvalues'    );

	if ( ! $parser_rules_setup || ! $subvalue_sanitization_setup ) {
		throw new Exception( sprintf(
			// translators: %s is an email address.
			__( 'Could not update CSS because sanitization was not available. Please notify us at %s.', 'wordcamporg' ),
			EMAIL_CENTRAL_SUPPORT
		) );
	}

	$safe_css = Jetpack_Custom_CSS_Enhancements::sanitize_css( $unsafe_css, array( 'force' => true ) );

	/*
	 * It's expected for `csstidy_optimize_subvalue` to not run on some inputs, but `csstidy_optimize_postparse`
	 * should always run.
	 */
	if ( did_action( 'csstidy_optimize_postparse' ) < 1 ) {
		throw new Exception( sprintf(
			// translators: %s is an email address.
			__( 'Could not update CSS because sanitization did not run. Please notify us at %s.', 'wordcamporg' ),
			EMAIL_CENTRAL_SUPPORT
		) );
	}

	return $safe_css;
}

/**
 * Save the safe CSS
 *
 * @param string $safe_css
 */
function save_safe_css( $safe_css ) {
	$post               = get_safe_css_post();
	$post->post_content = $safe_css;

	/*
	 * We don't want KSES running because it will mangle valid CSS. For example, `body > div { color: red; }` will
	 * become `body &gt; div { color: red; }`. It's also not necessary, since we've already sanitized the CSS.
	 */
	kses_remove_filters();
	wp_update_post( $post );
	kses_init();
}
