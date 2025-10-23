<?php
namespace WordCamp\RemoteCSS\Jetpack_CSS_Module;
use csstidy, csstidy_optimise;

/**
 * Sanitize CSS using Jetpack's Custom CSS Enhancements.
 * This is directly lifted from Jetpack's modules/custom-css/custom-css.php @ v13.7.1
 *
 * NOTE: preprocessor support removed.
 */
function sanitize_css( $css ) {

	$args = array(
		'force' => true
	);

	$warnings = array();

	safecss_class();
	$csstidy           = new csstidy();
	$csstidy->optimise = new safecss( $csstidy );

	$csstidy->set_cfg( 'remove_bslash', false );
	$csstidy->set_cfg( 'compress_colors', false );
	$csstidy->set_cfg( 'compress_font-weight', false );
	$csstidy->set_cfg( 'optimise_shorthands', 0 );
	$csstidy->set_cfg( 'remove_last_;', false );
	$csstidy->set_cfg( 'case_properties', false );
	$csstidy->set_cfg( 'discard_invalid_properties', true );
	$csstidy->set_cfg( 'css_level', 'CSS3.0' );
	$csstidy->set_cfg( 'preserve_css', true );
	$csstidy->set_cfg( 'template', __DIR__ . '/csstidy/wordpress-standard.tpl' );

	// Test for some preg_replace stuff.
	$prev = $css;
	$css  = preg_replace( '/\\\\([0-9a-fA-F]{4})/', '\\\\\\\\$1', $css );
	// prevent content: '\3434' from turning into '\\3434'.
	$css = str_replace( array( '\'\\\\', '"\\\\' ), array( '\'\\', '"\\' ), $css );
	if ( $css !== $prev ) {
		$warnings[] = 'preg_replace found stuff';
	}

	// Some people put weird stuff in their CSS, KSES tends to be greedy.
	$css = str_replace( '<=', '&lt;=', $css );

	// Test for some kses stuff.
	$prev = $css;
	// Why KSES instead of strip_tags?  Who knows?
	$css = wp_kses_split( $css, array(), array() );
	$css = str_replace( '&gt;', '>', $css ); // kses replaces lone '>' with &gt;
	// Why both KSES and strip_tags?  Because we just added some '>'.
	$css = strip_tags( $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- scared to update this to wp_strip_all_tags since we're building a CSS file here.

	if ( $css !== $prev ) {
		$warnings[] = 'kses found stuff';
	}

	// if we're not using a preprocessor.

	/** This action is documented in modules/custom-css/custom-css.php */
	do_action( 'safecss_parse_pre', $csstidy, $css, $args );

	$csstidy->parse( $css );

	/** This action is documented in modules/custom-css/custom-css.php */
	do_action( 'safecss_parse_post', $csstidy, $warnings, $args );

	$css = $csstidy->print->plain();

	return $css;
}

if ( ! function_exists( 'safecss_class' ) ) :
	/**
	 * Load in the class only when needed.  Makes lighter load by having one less class in memory.
	 */
	function safecss_class() {
		// Wrapped so we don't need the parent class just to load the plugin.
		if ( class_exists( __NAMESPACE__ . '\safecss' ) ) {
			return;
		}

		require_once __DIR__ . '/csstidy/class.csstidy.php';

		/**
		 * Class safecss
		 */
		class safecss extends csstidy_optimise { // phpcs:ignore

			/**
			 * Optimises $css after parsing.
			 */
			public function postparse() { // phpcs:ignore MediaWiki.Usage.NestedFunctions.NestedFunction

				/** This action is documented in modules/custom-css/custom-css.php */
				do_action( 'csstidy_optimize_postparse', $this );

				return parent::postparse();
			}

			/**
			 * Optimises a sub-value.
			 */
			public function subvalue() { // phpcs:ignore MediaWiki.Usage.NestedFunctions.NestedFunction

				/** This action is documented in modules/custom-css/custom-css.php */
				do_action( 'csstidy_optimize_subvalue', $this );

				return parent::subvalue();
			}
		}
	}
endif;
