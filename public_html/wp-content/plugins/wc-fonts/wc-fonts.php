<?php
/*
 * Plugin Name: WordCamp.org Fonts
 */

require_once __DIR__ . '/wc-google-fonts-provider.php';

class WordCamp_Fonts_Plugin {
	protected $options;

	/**
	 * Runs when file is loaded.
	 */
	public function __construct() {
		add_action( 'init',       array( $this, 'init'       ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		add_action( 'wp_head',            array( $this, 'wp_head_typekit'          ), 102 ); // After safecss_style.
		add_action( 'wp_head',            array( $this, 'wp_head_google_web_fonts' ) );
		add_action( 'wp_head',            array( $this, 'wp_head_font_awesome'     ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_core_fonts'       ) );
		add_action( 'init',               array( $this, 'register_fonts_for_editor' ) );
	}

	/**
	 * Runs during init, loads the options array.
	 */
	public function init() {
		$this->options = (array) get_option( 'wc-fonts-options', array() );
	}

	/**
	 * Provides the <head> output for Typekit settings.
	 */
	public function wp_head_typekit() {
		if ( ! isset( $this->options['typekit-id'] ) || empty( $this->options['typekit-id'] ) ) {
			return;
		}

		// phpcs:ignore -- Allow hardcoded script, and allow `sanitize_key` as an escaping function.
		printf( '<script type="text/javascript" src="https://use.typekit.com/%s.js"></script>' . "\n", sanitize_key( $this->options['typekit-id'] ) );
		printf( '<script type="text/javascript">try{Typekit.load();}catch(e){}</script>' );
	}

	/**
	 * Provides the <head> output for Google Web Fonts
	 */
	public function wp_head_google_web_fonts() {
		if ( ! isset( $this->options['google-web-fonts'] ) || empty( $this->options['google-web-fonts'] ) ) {
			return;
		}

		if ( ! wp_is_block_theme() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf( '<style>%s</style>', $this->options['google-web-fonts'] );
		}
	}

	/**
	 * Provides the <head> output for Font Awesome
	 */
	public function wp_head_font_awesome() {
		if ( ! empty( $this->options['font-awesome-url'] ) ) {
			printf( "<style>@import url( '%s' );</style>", esc_url( $this->options['font-awesome-url'] ) );
		}

		if ( ! empty( $this->options['font-awesome-kit'] ) ) {
			// phpcs:ignore -- Allow hardcoded script, and allow `sanitize_key` as an escaping function.
			printf( '<script src="https://kit.fontawesome.com/%s.js"></script>', sanitize_key( $this->options['font-awesome-kit'] ) );
		}
	}

	/**
	 * Allow sites to use Core fonts on the front-end
	 */
	public function enqueue_core_fonts() {
		if ( isset( $this->options['dashicons'] ) && $this->options['dashicons'] ) {
			wp_enqueue_style( 'dashicons' );
		}
	}

	/**
	 * Runs during admin init, does Settings API
	 */
	public function admin_init() {
		register_setting( 'wc-fonts-options', 'wc-fonts-options', array( $this, 'validate_options' ) );
		add_settings_section( 'general', '', '__return_null', 'wc-fonts-options' );

		add_settings_field(
			'typekit-id',
			__( 'Typekit ID', 'wordcamporg' ),
			array( $this, 'field_typekit_id' ),
			'wc-fonts-options',
			'general'
		);

		add_settings_field(
			'google-web-fonts',
			__( 'Google Web Fonts', 'wordcamporg' ),
			array( $this, 'field_google_web_fonts' ),
			'wc-fonts-options',
			'general'
		);

		add_settings_field(
			'font-awesome-url',
			__( 'Font Awesome', 'wordcamporg' ),
			array( $this, 'field_font_awesome_url' ),
			'wc-fonts-options',
			'general'
		);

		add_settings_field(
			'font-awesome-kit',
			__( 'Font Awesome Kit', 'wordcamporg' ),
			array( $this, 'field_font_awesome_kit' ),
			'wc-fonts-options',
			'general'
		);

		add_settings_field(
			'dashicons',
			esc_html__( 'Dashicons', 'wordcamporg' ),
			array( $this, 'field_dashicons' ),
			'wc-fonts-options',
			'general'
		);
	}

	/**
	 * Runs during admin_menu, adds a Fonts section to Appearance
	 */
	public function admin_menu() {
		$fonts = add_theme_page(
			esc_html__( 'Fonts', 'wordcamporg' ),
			esc_html__( 'Fonts', 'wordcamporg' ),
			'edit_theme_options',
			'wc-fonts-options',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Uses the Settings API to render the Appearance > Fonts page
	 */
	public function render_admin_page() {
		?>

		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Fonts', 'wordcamporg' ); ?>
			</h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" enctype="multipart/form-data">
				<?php
					settings_fields( 'wc-fonts-options' );
					do_settings_sections( 'wc-fonts-options' );
					submit_button();
				?>
			</form>
		</div>

		<?php
	}

	/**
	 * Settings API field for the Typekit ID
	 */
	public function field_typekit_id() {
		$value = isset( $this->options['typekit-id'] ) ? $this->options['typekit-id'] : '';
		?>

		<input type="text" name="wc-fonts-options[typekit-id]" value="<?php echo esc_attr( $value ); ?>" class="regular-text code" />
		<p class="description">
			<?php esc_html_e( 'Enter your Typekit Kit ID only. Do not add any URLs or JavaScript.', 'wordcamporg' ); ?>
		</p>

		<?php
	}

	/**
	 * Settings API field for the Google Web Fonts URLs
	 */
	public function field_google_web_fonts() {
		$value = isset( $this->options['google-web-fonts'] ) ? $this->options['google-web-fonts'] : '';
		?>

		<textarea rows="5" name="wc-fonts-options[google-web-fonts]" class="large-text code"><?php
			echo esc_textarea( $value );
		?></textarea>

		<p class="description">
			<?php esc_html_e( 'Paste the Google Web Fonts @import URLs in this area, each one on a separate line.', 'wordcamporg' ); ?>
		</p>

		<?php
	}

	/**
	 * Settings API field for the Google Web Fonts URLs
	 */
	public function field_font_awesome_url() {
		$value = isset( $this->options['font-awesome-url'] ) ? $this->options['font-awesome-url'] : '';
		?>

		<input type="text" name="wc-fonts-options[font-awesome-url]" value="<?php echo esc_url( $value ); ?>" class="large-text code" />
		<p class="description">
			<?php esc_html_e( 'For Font Awesome 4.7 and below. Enter the BootstrapCDN URL for the version you want.', 'wordcamporg' ); ?>
		</p>

		<?php
	}

	/**
	 * Settings API field for the Font Awesome kit ID
	 */
	public function field_font_awesome_kit() {
		$value = isset( $this->options['font-awesome-kit'] ) ? $this->options['font-awesome-kit'] : '';
		?>

		<input type="text" name="wc-fonts-options[font-awesome-kit]" value="<?php echo esc_attr( $value ); ?>" class="regular-text code" />
		<p class="description">
			<?php esc_html_e( 'For Font Awesome 5+. Enter your Font Awesome Kit ID only. Do not add any URLs or JavaScript.', 'wordcamporg' ); ?>
		</p>

		<?php
	}

	/**
	 * Settings API field for the Dashicons checkbox
	 */
	public function field_dashicons() {
		$value = isset( $this->options['dashicons'] ) ? $this->options['dashicons'] : '';
		?>

		<label>
			<input type="checkbox" name="wc-fonts-options[dashicons]" <?php checked( $value ); ?> />
			<?php esc_html_e( 'Enqueue Dashicons', 'wordcamporg' ); ?>
		</label>

		<?php
	}

	/**
	 * Triggered by the Settings API upon settings save.
	 */
	public function validate_options( $input ) {
		$output = $this->options;

		// Typekit.
		if ( isset( $input['typekit-id'] ) ) {
			$output['typekit-id'] = preg_replace( '/[^0-9a-zA-Z]+/', '', $input['typekit-id'] );
		}

		// Google Web Fonts.
		if ( isset( $input['google-web-fonts'] ) ) {
			$fonts = array();
			$lines = explode( "\n", $input['google-web-fonts'] );
			foreach ( $lines as $line ) {
				$matches = array();
				$url     = preg_match( '#fonts\.googleapis\.com/css2?\?family=[^\)\'"]+#', $line, $matches );

				if ( $matches ) {
					$url = esc_url_raw( 'http://' . $matches[0] );
				}

				if ( ! $url ) {
					continue;
				}

				$url = wp_parse_url( $url );

				if ( 'fonts.googleapis.com' != $url['host'] ) {
					continue;
				}

				if ( ! preg_match( '/^family=(.+)/i', $url['query'] ) ) {
					continue;
				}

				$url     = 'https://' . $url['host'] . $url['path'] . '?' . $url['query'];
				$import  = "@import url('" . esc_url_raw( $url ) . "');";
				$fonts[] = $import;
			}
			$output['google-web-fonts'] = implode( "\n", $fonts );
		}

		// Font Awesome.
		$output['font-awesome-url'] = '';

		if ( isset( $input['font-awesome-url'] ) ) {
			$url = wp_parse_url( $input['font-awesome-url'] );

			if ( isset( $url['host'] ) && isset( $url['path'] ) ) {
				$valid_hostname  = in_array( $url['host'], [ 'maxcdn.bootstrapcdn.com', 'stackpath.bootstrapcdn.com' ] );
				$valid_extension = '.css' === substr( $url['path'], strlen( $url['path'] ) - 4, 4 );

				if ( $valid_hostname && $valid_extension ) {
					$output['font-awesome-url'] = esc_url_raw( 'https://' . $url['host'] . $url['path'] );
				}
			}
		}

		// Font Awesome Kit.
		if ( isset( $input['font-awesome-kit'] ) ) {
			$output['font-awesome-kit'] = preg_replace( '/[^0-9a-zA-Z]+/', '', $input['font-awesome-kit'] );
		}

		// Dashicons.
		$output['dashicons'] = isset( $input['dashicons'] ) && $input['dashicons'] ? true : false;

		return $output;
	}

	/**
	 * Register the google fonts into WordPress so they can be used in the Site Editor.
	 */
	public function register_fonts_for_editor() {
		if ( ! function_exists( 'wp_register_fonts' ) ) {
			return;
		}

		if ( ! isset( $this->options['google-web-fonts'] ) || empty( $this->options['google-web-fonts'] ) ) {
			return;
		}

		$lines = explode( "\n", $this->options['google-web-fonts'] );
		$fonts = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '#https?://fonts\.googleapis\.com/css2?\?family=[^\)\'"]+#', $line, $matches ) ) {
				$url = $matches[0];
				$query = explode( '&', wp_parse_url( $url, PHP_URL_QUERY ) );

				// Multiple families can be added to one URL, so we need to loop over to parse them.
				// We can't just use wp_parse_str because each `family` will overwrite the previous.
				foreach ( $query as $family ) {
					// Make sure we're working with a `family=` value and not `display=` or anything else.
					if ( ! str_starts_with( $family, 'family=' ) ) {
						continue;
					}
					$details = explode( ':', $family );
					$name = str_replace( 'family=', '', $details[0] );
					$styles = $details[1] ?? '';

					$variations = array(
						array(
							'font-family' => urldecode( $name ),
							'provider'    => 'wordcamp-google',
						),
					);

					if ( str_contains( $styles, '@' ) ) {
						$variations = array_map(
							function( $var ) use ( $name ) {
								$variation = array(
									'font-family' => urldecode( $name ),
									'provider'    => 'wordcamp-google',
								);
								if ( isset( $var['ital'] ) ) {
									$variation['font-style']  = '0' === $var['ital'] ? 'normal' : 'italic';
								}
								if ( isset( $var['wght'] ) ) {
									$variation['font-weight'] = str_replace( '..', ' ', $var['wght'] );
								}
								return $variation;
							},
							$this->parse_google_font_variations( $styles )
						);
					}

					$fonts[ urldecode( $name ) ] = $variations;
				}
			}
		}

		wp_register_fonts( $fonts );
		wp_enqueue_fonts( array_keys( $fonts ) );
	}

	/**
	 * Parse the google font options.
	 *
	 * Converts the string in a google fonts URL into an array of variations.
	 * For example, `ital,wght@0,700;1,700` should return:
	 * [
	 *   [ 'ital' => 0, 'wght' => '700' ],
	 *   [ 'ital' => 1, 'wght' => '700' ],
	 * ]
	 */
	public function parse_google_font_variations( $styles ) {
		list( $props, $values ) = explode( '@', $styles );
		$props = explode( ',', $props );
		$values = explode( ';', $values );

		$result = array();
		foreach ( $values as $i => $value ) {
			$style = explode( ',', $value );
			foreach ( $props as $j => $prop ) {
				$result[ $i ][ $prop ] = $style[ $j ];
			}
		}
		return $result;
	}
}

new WordCamp_Fonts_Plugin();
