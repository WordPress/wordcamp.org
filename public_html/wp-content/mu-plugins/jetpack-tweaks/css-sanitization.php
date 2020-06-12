<?php
namespace WordCamp\Jetpack_Tweaks;

use Exception;
use WordCamp\RemoteCSS;
use WordCamp\Logger;

defined( 'WPINC' ) || die();

add_filter( 'update_custom_css_data',     __NAMESPACE__ . '\sanitize_custom_css', 15       ); // After Jetpack_Custom_CSS_Enhancements::update_custom_css_data().
add_action( 'csstidy_optimize_postparse', __NAMESPACE__ . '\sanitize_csstidy_parsed_rules' );
add_action( 'admin_notices',              __NAMESPACE__ . '\notify_import_rules_stripped'  );
add_action( 'csstidy_optimize_subvalue',  __NAMESPACE__ . '\sanitize_csstidy_subvalues'    );
add_action( 'safecss_parse_pre',          __NAMESPACE__ . '\update_csstidy_safelist', 0    );

/**
 * Sanitize CSS saved through the Core/Jetpack editor inside the Customizer
 *
 * By default, the Additional CSS section is only available to users with `unfiltered_html` -- which nobody on
 * wordcamp.org has, not even super-admins -- but Jetpack re-maps that to `edit_theme_options`, allowing
 * regular admins on all sites to use it.
 *
 * @param array $post
 *
 * @return array
 */
function sanitize_custom_css( $post ) {
	try {
		$post['css'] = RemoteCSS\sanitize_unsafe_css( $post['css'] );
	} catch ( Exception $exception ) {
		/*
		 * We can't save unsanitized CSS, and also don't want to overwrite the known-good value in the database.
		 * There's no way to gracefully abort the process and show an error message, so just die.
		 */
		Logger\log( 'custom_css_sanitization_failed', compact( 'post', 'exception' ) );
		wp_die( wp_kses_data( $exception->getMessage() ) );
	}

	return $post;
}

/**
 * Sanitize parsed Custom CSS rules
 *
 * @import rules are stripped because they can introduce security vulnerabilities by embedding external
 * stylesheets that haven't been sanitized, and they also present a maintenance problem because they rely on
 * external resources which could go offline at any point.
 *
 * @charset rules are stripped because manipulating the charset can allow an attacker to introduce XSS
 * vulnerabilities by tricking the browser into interpreting the CSS as HTML.
 *
 * @param \safecss $safecss
 */
function sanitize_csstidy_parsed_rules( $safecss ) {
	if ( ! empty( $safecss->parser->import ) ) {
		update_option( 'custom_css_import_stripped', true );
	}

	$safecss->parser->import  = array();
	$safecss->parser->charset = array();
}

/**
 * Notify the user that @import rules were stripped from their CSS
 *
 * @todo Since WP 4.7 / Jetpack 4.2.2, we also need a way to show this warning in Customizer > Additional CSS. It
 *       still needs to work in Remote CSS, though.
 */
function notify_import_rules_stripped() {
	global $current_screen;
	$relevant_screens = array( 'appearance_page_editcss', 'appearance_page_remote-css' );

	if ( ! is_a( $current_screen, 'WP_Screen' ) || ! in_array( $current_screen->id, $relevant_screens, true ) ) {
		return;
	}

	if ( ! get_option( 'custom_css_import_stripped' ) ) {
		return;
	}

	delete_option( 'custom_css_import_stripped' );

	?>

	<div class="notice notice-warning">
		<p>
			<?php printf(
				// translators: 1: '@import', 2: Fonts tool URL.
				wp_kses(
					__(
						'WARNING: %1$s rules were stripped for security reasons. Please use <a href="%2$s">the Fonts tool</a> to add web fonts, and merge other stylesheets directly into your custom CSS.',
						'wordcamporg'
					),
					array(
						'a'    => array( 'href' => true ),
						'code' => true,
					)
				),
				'<code>@import</code>',
				esc_url( admin_url( 'themes.php?page=wc-fonts-options' ) )
			); ?>
		</p>
	</div>

	<?php
}

/**
 * Sanitize Custom CSS subvalues
 *
 * @param \safecss $safecss
 */
function sanitize_csstidy_subvalues( $safecss ) {
	$safecss->sub_value = trim( $safecss->sub_value );

	// Send any urls through our filter.
	if ( preg_match( '!^\s*(?P<url_expression>url\s*(?P<opening_paren>\(|\\0028)(?P<parenthetical_content>.*)(?P<closing_paren>\)|\\0029))(.*)$!Dis', $safecss->sub_value, $matches ) ) {
		$safecss->sub_value = sanitize_urls_in_css_properties( $matches['parenthetical_content'], $safecss->property );

		// Only replace the url([...]) portion of the sub_value so we don't
		// lose things like trailing commas or !important declarations.
		if ( $safecss->sub_value ) {
			$safecss->sub_value = str_replace( $matches['url_expression'], $safecss->sub_value, $matches[0] );
		}
	}

	// Strip any expressions.
	if ( preg_match( '!^\\s*expression!Dis', $safecss->sub_value ) ) {
		$safecss->sub_value = '';
	}
}

/**
 * Sanitize URLs used in CSS properties
 *
 * @param string $url
 * @param string $property
 *
 * @return string
 */
function sanitize_urls_in_css_properties( $url, $property ) {
	$allowed_properties = array(
		'background',
		'background-image',
		'border-image',
		'border-image-source',
		'clip-path',
		'content',
		'cursor',
		'list-style',
		'list-style-image',
		'mask',
		'mask-image',
		'shape-outside',
	);
	$allowed_protocols  = array( 'http', 'https' );
	// todo maybe add permanent warning note that `data` shouldn't be allowed, see #1616:comment:4.

	// Clean up the string.
	$url = trim( $url, "' \" \r \n" );

	// Check against whitelist for properties allowed to have URL values.
	if ( ! in_array( trim( $property ), $allowed_properties, true ) ) {
		// trim() is because multiple properties with the same name are stored with
		// additional trailing whitespace so they don't overwrite each other in the hash.
		return '';
	}

	$good_protocol_url = wp_kses_bad_protocol( $url, $allowed_protocols );

	if ( empty( $good_protocol_url ) || strtolower( $good_protocol_url ) !== strtolower( $url ) ) {
		return '';
	}

	return "url('" . addcslashes( $good_protocol_url, '\'\\' ) . "')";
}

/**
 * Additional CSS properties that the CSS sanitizer should recognize as valid.
 *
 * @return array
 */
function get_custom_css_properties_safelist() {
	return array(
		'aspect-ratio',
		'background-blend-mode',
		'isolation',
		'mask',
		'mix-blend-mode',
		'shape-image-threshold',
		'shape-margin',
		'shape-outside',
	);
}

/**
 * Add the custom safelisted properties to CSSTidy's config.
 *
 * Hooked to `safecss_parse_pre` to try and ensure that the `csstidy` global exists before trying to modify it.
 *
 * @return void
 */
function update_csstidy_safelist() {
	$properties = get_custom_css_properties_safelist();

	/**
	 * CSSTidy uses a config variable for CSS spec version to determine which properties are valid. This tells csstidy
	 * that the properties we're adding are part of the CSS3.0 spec, even though they actually aren't yet.
	 */
	$properties_for_csstidy = array_fill_keys( $properties, 'CSS3.0' );

	if ( ! empty( $GLOBALS['csstidy']['all_properties'] ) ) {
		$GLOBALS['csstidy']['all_properties'] = array_merge( $GLOBALS['csstidy']['all_properties'], $properties_for_csstidy );
	}
}
