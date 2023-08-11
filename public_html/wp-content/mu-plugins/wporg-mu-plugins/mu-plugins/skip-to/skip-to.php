<?php
/**
 * Plugin Name: Skip-to for classic themes
 * Description: This is a copy of the block theme skip-to functionality for classic themes.
 * 
 * See: https://github.com/WordPress/wordpress-develop/blob/f7d2a2ee9d003633e6c729c0835cd4addd23f9b3/src/wp-includes/theme-templates.php#L101-L205
 */
namespace WordPressdotorg\MU_Plugins\Skip_To_Links {
	function skip_to( $selector = 'main', $css = false ) {

		$selector = apply_filters( 'wporg_skip_link_target', $selector );
		if ( ! $selector ) {
			return;
		}

		// Noop for Block themes. Use the_block_template_skip_link() instead.
		if (
			'main' === $selector &&
			current_theme_supports( 'block-templates' ) &&
			! empty( $_wp_current_template_content )
		) {
			return;
		}

		if ( $css ) {
			add_action( 'wp_head', __NAMESPACE__ . '\css' );
		}

		add_action( 'wp_body_open', function() use( $selector ) {
			skip_tag( $selector );
		}, -1 );

		// If a HTML ID is not passed, some JS will be needed.
		if ( '#' !== substr( $selector, 0, 1 ) ) {
			add_action( 'wp_footer', __NAMESPACE__ . '\js' );
		}

		// Don't allow a second call to this functionality.
		add_filter( 'wporg_skip_link_target', '__return_false', 100 );
	}

	/**
	 * Print the skip link.
	 */
	function skip_tag( $selector ) {
		$target = ( '#' === substr( $selector, 0, 1 ) ? $selector : '' );
		$tabindex = ( $target ? '' : 'tabindex="-1"' ); // Will be removed once the target is set.
		printf(
			'<a id="wporg-skip-link" %s class="skip-link screen-reader-text" href="%s" data-selector="%s">%s</a>'."\n",
			$tabindex,
			esc_attr( $target ),
			esc_attr( $selector ),
			__( 'Skip to content', 'wporg' )
		);
	}

	/**
	 * Print the skip-link styles.
	 */
	function css() {
		?>
		<style id="skip-link-styles">
			.skip-link.screen-reader-text {
				border: 0;
				clip: rect(1px,1px,1px,1px);
				clip-path: inset(50%);
				height: 1px;
				margin: -1px;
				overflow: hidden;
				padding: 0;
				position: absolute !important;
				width: 1px;
				word-wrap: normal !important;
			}

			.skip-link.screen-reader-text:focus {
				background-color: #eee;
				clip: auto !important;
				clip-path: none;
				color: #444;
				display: block;
				font-size: 1em;
				height: auto;
				left: 5px;
				line-height: normal;
				padding: 15px 23px 14px;
				text-decoration: none;
				top: 5px;
				width: auto;
				z-index: 100000;
			}
		</style>
		<?php
	}

	function js() {
		?>
		<script>
			( function() {
				var skipLink = document.getElementById( 'wporg-skip-link' ),
					skipLinkTarget, skipLinkTargetID;

				if ( ! skipLink ) {
					return;
				}

				skipLinkTarget = document.querySelector( skipLink.dataset.selector );
				if ( ! skipLinkTarget ) {
					skipLink.remove();
					return;
				}

				skipLinkTargetID = skipLinkTarget.id;
				if ( ! skipLinkTargetID ) {
					skipLinkTargetID = 'wp--skip-link--target';
					skipLinkTarget.id = skipLinkTargetID;
				}

				skipLink.href = '#' + skipLinkTargetID;
				skipLink.tabIndex = '';
			}() );
		</script>
		<?php
	}
}

// Allow importing as `WordPressdotorg\skip_to()` or `WordPressdotorg\skip_to_styled()`.
namespace WordPressdotorg {
	function skip_to( $selector = 'main', $css = false ) {
		return MU_Plugins\Skip_To_Links\skip_to( $selector, $css );
	}

	// Exists to be added as an action directly to `wp_head` which will pass the first param.
	function skip_to_main() {
		return MU_Plugins\Skip_To_Links\skip_to( 'main', false );
	}
}
