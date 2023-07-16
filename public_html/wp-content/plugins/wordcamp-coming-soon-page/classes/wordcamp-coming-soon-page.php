<?php

class WordCamp_Coming_Soon_Page {
	protected $override_theme_template;
	const VERSION = '0.2';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init',                       array( $this, 'init'                            ), 11    );  // After WCCSP_Settings::init().
		add_action( 'wp_enqueue_scripts',         array( $this, 'manage_plugin_theme_stylesheets' ), 99    );  // (Hopefully) after all plugins/themes have enqueued their styles.
		add_action( 'wp_head',                    array( $this, 'render_dynamic_styles'           )        );
		add_filter( 'template_include',           array( $this, 'override_theme_template'         )        );
		add_action( 'template_redirect',          array( $this, 'disable_jetpacks_open_graph'     )        );
		add_filter( 'rest_request_before_callbacks', array( $this, 'disable_rest_endpoints'       ), 99, 3 );
		add_action( 'admin_bar_menu',             array( $this, 'admin_bar_menu_item'             ), 1000  );
		add_action( 'admin_head',                 array( $this, 'admin_bar_styling'               )        );
		add_action( 'wp_head',                    array( $this, 'admin_bar_styling'               )        );
		add_action( 'admin_notices',              array( $this, 'block_new_post_admin_notice'     )        );
		add_filter( 'get_post_metadata',          array( $this, 'jetpack_dont_email_post_to_subs' ), 10, 4 );
		add_filter( 'publicize_should_publicize_published_post', array( $this, 'jetpack_prevent_publicize' ) );

		add_image_size( 'wccsp_image_medium_rectangle', 500, 300 );
	}

	/**
	 * Initialize variables
	 */
	public function init() {
		$settings                      = $GLOBALS['WCCSP_Settings']->get_settings();
		$show_page                     = 'on' === $settings['enabled'] && ! current_user_can( 'edit_posts' );
		$this->override_theme_template = $show_page || $this->is_coming_soon_preview();
	}

	/**
	 * Check if the current page is our section of the Previewer
	 *
	 * @return bool
	 */
	public function is_coming_soon_preview() {
		global $wp_customize;

		return isset( $_GET['wccsp-preview'] ) && $wp_customize->is_preview();
	}

	/**
	 * Ensure the template has a consistent base of CSS rules, regardless of the current theme or Custom CSS
	 */
	public function manage_plugin_theme_stylesheets() {
		if ( ! $this->override_theme_template ) {
			return;
		}

		$this->dequeue_all_stylesheets();

		if ( is_user_logged_in() ) {
			wp_enqueue_style( 'admin-bar' );
		}

		wp_register_style(
			'open-sans',
			'https://fonts.googleapis.com/css?family=Open+Sans:300italic,400italic,600italic,300,400,600',
			array(),
			2
		);

		wp_enqueue_style(
			'wccsp-template',
			plugins_url( '/css/template-coming-soon.css', __DIR__ ),
			array( 'open-sans' ),
			2
		);
	}

	/**
	 * Dequeue all enqueued stylesheets and Custom CSS.
	 *
	 * This prevents Custom CSS & Remote CSS styles from conflicting with the Coming Soon template. Coming Soon
	 * is intended to be a stripped down placeholder with minimal customization.
	 */
	protected function dequeue_all_stylesheets() {
		foreach ( $GLOBALS['wp_styles']->queue as $stylesheet ) {
			wp_dequeue_style( $stylesheet );
		}

		// Core and Jetpack's Custom CSS module both output Custom CSS, so they both need to be disabled.
		remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
		remove_action( 'wp_head', array( 'Jetpack_Custom_CSS_Enhancements', 'wp_custom_css_cb' ), 101 );
	}

	/**
	 * Render dynamic CSS styles
	 */
	public function render_dynamic_styles() {
		if ( ! $this->override_theme_template ) {
			return;
		}

		// TODO: Figure out an alternative here.
		//phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Not sure whats the alternative to this could be.
		extract( $GLOBALS['WordCamp_Coming_Soon_Page']->get_template_variables() );

		require_once( dirname( __DIR__ ) . '/css/template-coming-soon-dynamic.php' );
	}

	/**
	 * Load the Coming Soon template instead of a theme template
	 *
	 * @param string $template
	 * @return string
	 */
	public function override_theme_template( $template ) {
		if ( $this->override_theme_template ) {
			$template = dirname( __DIR__ ) . '/views/template-coming-soon.php';
		}

		return $template;
	}

	/**
	 * Disable Jetpack's Open Graph meta tags when the Coming Soon page is active
	 */
	public function disable_jetpacks_open_graph() {
		if ( $this->override_theme_template ) {
			add_filter( 'jetpack_enable_open_graph', '__return_false' );
		}
	}

	/**
	 * Disable the REST API for unauthenticated requests when the Coming Soon page is active.
	 *
	 * @param WP_HTTP_Response|WP_Error $response
	 * @param array                     $handler
	 * @param WP_REST_Request           $request
	 *
	 * @return WP_HTTP_Response|WP_Error
	 */
	public function disable_rest_endpoints( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		/*
		 * Jetpack endpoints are whitelisted because some of them are needed to connect sites to WordPress.com
		 * while Coming Soon is still enabled.
		 *
		 * Safelist entries generally _should not_ include a version number, to insure forward-compatibly. They
		 * _should_ include the directory markers and `v` prefix, though, to avoid false-positive matches.
		 *
		 * @todo This works, but there are some additional, unknown steps needed to allow connecting to WPCOM
		 * via the REST API. This is being left here because it will be needed when/if Jetpack removes XMLRPC
		 * support and uses the REST API exclusively for registration. If that happens, we'll need to figure
		 * out what extra steps are needed.
		 */
		$safelisted_namespaces = apply_filters( 'wccs_safelisted_namespaces', array( '/jetpack/v' ) );

		$safelisted = array_filter( $safelisted_namespaces, function( $namespace ) use ( $request ) {
			return false !== strpos( $request->get_route(), $namespace );
		} );

		if ( $this->override_theme_template && ! $safelisted ) {
			return new WP_Error(
				'rest_cannot_access',
				__( 'The REST API is not available while the site is in Coming Soon mode.', 'wordcamporg' ),
				array( 'status' => 403 )
			);
		}

		return $response;
	}

	/**
	 * Collect all of the variables the Coming Soon template will need
	 * Doing this here keeps the template less cluttered and more of a pure view
	 *
	 * @return array
	 */
	public function get_template_variables() {
		$variables = array(
			'image_url'              => $this->get_image_url(),
			'background_url'         => $this->get_bg_image_url(),
			'dates'                  => $this->get_dates(),
			'active_modules'         => Jetpack::$instance->get_active_modules(),
			'contact_form_shortcode' => $this->get_contact_form_shortcode(),
			'colors'                 => $this->get_colors(),
			'introduction'           => $this->get_introduction(),
			'status'                 => $this->get_status(),
		);

		return $variables;
	}

	/**
	 * Retrieve the colors for the template
	 *
	 * @return array
	 */
	public function get_colors() {
		$settings = $GLOBALS['WCCSP_Settings']->get_settings();

		if ( ! class_exists( 'Jetpack_Color' ) && defined( 'JETPACK__PLUGIN_DIR' ) ) {
			include JETPACK__PLUGIN_DIR . '/_inc/lib/class.color.php';
		}

		// If they never changed from the old default background color, then use the new default.
		$background = $settings['body_background_color'];
		if ( '#666666' === $background ) {
			$background = '#0073aa';
		}

		// Just in case we can't find Jetpack_Color.
		if ( class_exists( 'Jetpack_Color' ) ) {
			$color     = new Jetpack_Color( $background, 'hex' );
			$color_hsl = $color->toHsl();

			$lighter_color = new Jetpack_Color(
				array(
					$color_hsl['h'],
					$color_hsl['s'],
					( $color_hsl['l'] >= 85 ) ? 100 : $color_hsl['l'] + 15,
				),
				'hsl'
			);

			$darker_color = new Jetpack_Color(
				array(
					$color_hsl['h'],
					$color_hsl['s'],
					( $color_hsl['l'] < 10 ) ? 0 : $color_hsl['l'] - 10,
				),
				'hsl'
			);

			$background_lighter = '#' . $lighter_color->toHex();
			$background_darker  = '#' . $darker_color->toHex();
		} else {
			$background_lighter = $background;
			$background_darker  = $background;
		}

		$colors['main']    = $background;
		$colors['lighter'] = $background_lighter;
		$colors['darker']  = $background_darker;

		// Not currently customizable.
		$colors['text']       = '#32373c';
		$colors['light-text'] = '#b4b9be';
		$colors['border']     = '#00669b';

		return $colors;
	}

	/**
	 * Retrieve the URL of the logo image displayed in the template.
	 *
	 * @return string|false
	 */
	public function get_image_url() {
		$settings   = $GLOBALS['WCCSP_Settings']->get_settings();
		$image_meta = wp_get_attachment_metadata( $settings['image_id'] );
		$size       = isset( $image_meta['sizes']['wccsp_image_medium_rectangle'] ) ? 'wccsp_image_medium_rectangle' : 'full';
		$image      = wp_get_attachment_image_src( $settings['image_id'], $size );

		return $image ? $image[0] : false;
	}

	/**
	 * Retrieve the URL of the background image displayed in the template.
	 *
	 * @return string|false
	 */
	public function get_bg_image_url() {
		$settings   = $GLOBALS['WCCSP_Settings']->get_settings();
		$image_meta = wp_get_attachment_metadata(  $settings['background_id']         );
		$image      = wp_get_attachment_image_src( $settings['background_id'], 'full' );

		return empty( $image[0] ) ? false : $image[0];
	}

	/**
	 * Retrieve the dates of the WordCamp
	 *
	 * @return string|false
	 */
	public function get_dates() {
		if ( 'wcpt-cancelled' === $this->get_status() ) {
			return esc_html__( 'Cancelled', 'wordcamporg' );
		}

		$dates         = false;
		$wordcamp_post = get_wordcamp_post();

		if ( isset( $wordcamp_post->ID ) ) {
			if ( ! empty( $wordcamp_post->meta['Start Date (YYYY-mm-dd)'][0] ) ) {
				// translators: date format, see https://php.net/date.
				$dates = date_i18n( __( 'F jS Y', 'wordcamporg' ), $wordcamp_post->meta['Start Date (YYYY-mm-dd)'][0] );

				if ( ! empty( $wordcamp_post->meta['End Date (YYYY-mm-dd)'][0] ) ) {
					if ( $wordcamp_post->meta['Start Date (YYYY-mm-dd)'][0] !== $wordcamp_post->meta['End Date (YYYY-mm-dd)'][0] ) {
						// translators: date format, see https://php.net/date.
						$dates .= ' - ' . date_i18n( __( 'F jS Y', 'wordcamporg' ), $wordcamp_post->meta['End Date (YYYY-mm-dd)'][0] );
					}
				}
			}
		}

		return $dates;
	}

	/**
	 * Loop through all pages and renders first contact-us form or contact-us block.
	 *
	 * We can't just create an arbitrary shortcode because of https://github.com/Automattic/jetpack/issues/102. Instead we have to use a form that's tied to a page.
	 * This is somewhat fragile, though. It should work in most cases because the first $page that contains [contact-form] will be the one we automatically create
	 * when the site is created, but if the organizers delete that and then add multiple forms, the wrong form could be displayed. The alternative approaches also
	 * have problems, though, and #102 should be fixed relatively soon, so hopefully this will be good enough until it can be refactored.
	 * todo Refactor this once #102-jetpack is fixed.
	 *
	 * @return string|false
	 */
	public function get_contact_form_shortcode() {
		$contact_form_content = false;
		$shortcode_regex      = get_shortcode_regex();

		$all_pages = get_posts( array(
			'post_type'      => 'page',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		) );

		foreach ( $all_pages as $page ) {

			if ( has_shortcode( $page->post_content, 'contact-form' ) ) {
				preg_match_all( '/' . $shortcode_regex . '/s', $page->post_content, $matches, PREG_SET_ORDER );
				foreach ( $matches as $shortcode ) {
					if ( 'contact-form' === $shortcode[2] ) {
						global $post;
						//phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- We need this because of jetpack bug #102
						$post = $page;
						setup_postdata( $post );

						ob_start();
						echo do_shortcode( $shortcode[0] );
						$contact_form_content = ob_get_clean();

						wp_reset_postdata();
						break;
					}
				}
			} elseif ( has_block( 'jetpack/contact-form', $page->post_content ) ) {
				// Along with shortcodes, also check for blocks.
				$blocks = parse_blocks( $page->post_content );
				foreach ( $blocks as $block ) {
					if ( 'jetpack/contact-form' === $block['blockName'] ) {
						global $post;
						//phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- We need this because of jetpack bug #102
						$post = $page;
						setup_postdata( $post );
						$contact_form_content = render_block( $block );
						wp_reset_postdata();
						break;
					}
				}
			}

			if ( $contact_form_content ) {
				break;
			}
		}

		return $contact_form_content;
	}

	/**
	 * Retrieve the optional introduction overwriting the default string.
	 *
	 * @return string
	 */
	public function get_introduction() {
		$settings = $GLOBALS['WCCSP_Settings']->get_settings();

		return $settings['introduction'];
	}

	/**
	 * Retrieve the WordCamp status.
	 *
	 * @return string
	 */
	public function get_status() {
		$wordcamp_post = get_wordcamp_post();

		if ( isset( $wordcamp_post->ID ) ) {
			return $wordcamp_post->post_status;
		}

		return null;
	}

	/**
	 * Display notice in admin bar when Coming Soon mode is on.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar WP_Admin_Bar instance, passed by reference.
	 */
	public function admin_bar_menu_item( $wp_admin_bar ) {
		$settings = $GLOBALS['WCCSP_Settings']->get_settings();

		if ( 'on' !== $settings['enabled'] ) {
			return;
		}

		$menu_slug   = WordCamp_Coming_Soon_Page::get_menu_slug();
		$setting_url = admin_url( $menu_slug );

		if ( ! current_user_can( 'manage_options' ) ) {
			$setting_url = '';
		}

		$wp_admin_bar->add_node( array(
			'id'     => 'wordcamp-coming-soon-info',
			'href'   => $setting_url,
			'parent' => 'root-default',
			'title'  => __( 'Coming Soon Mode ON', 'wordcamporg' ),
			'meta'   => array( 'class' => 'wc-coming-soon-info' ),
		) );
	}

	/**
	 * Styles for the Coming Soon flag in the Admin bar.
	 */
	public function admin_bar_styling() {
		$settings = $GLOBALS['WCCSP_Settings']->get_settings();

		if ( 'on' !== $settings['enabled'] ) {
			return;
		}

		?>

		<style type="text/css">
			#wpadminbar .wc-coming-soon-info .ab-item,
			#wpadminbar .wc-coming-soon-info .ab-item a {
				background: #FFE399;
				color: #23282d;
			}
		</style>

		<?php
	}

	/**
	 * Get the slug for the Coming Soon menu item.
	 *
	 * This is also the query string for links to the Coming Soon panel in the Customizer.
	 *
	 * @return string
	 */
	public static function get_menu_slug() {
		return add_query_arg(
			array(
				'autofocus[section]' => 'wccsp_live_preview',
				'url'                => rawurlencode( add_query_arg( 'wccsp-preview', '', site_url() ) ),
			),
			'/customize.php'
		);
	}

	/**
	 * Show a notice if Coming Soon is enabled.
	 *
	 * Explain to users why publishing is disabled when Coming Soon is enabled.
	 */
	public function block_new_post_admin_notice() {
		$settings = $GLOBALS['WCCSP_Settings']->get_settings();

		if ( 'on' !== $settings['enabled'] ) {
			return;
		}

		$menu_slug   = self::get_menu_slug();
		$setting_url = admin_url( $menu_slug );
		$screen      = get_current_screen();

		if ( 'post' === trim( $screen->id ) ) {
			$class   = 'notice notice-warning';
			$message = sprintf(
				__( '<a href="%s">Coming Soon mode</a> is enabled. Site subscribers will not receive email notifications about published posts. Published posts will not be automatically cross-posted to social media accounts.', 'wordcamporg' ),
				esc_url( $setting_url )
			);

			if ( ! current_user_can( 'manage_options' ) ) {
				$message = wp_strip_all_tags( $message );
			}

			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_data( $message ) );
		}
	}

	/**
	 * Disable sending of Jetpack emails when Coming Soon mode is on.
	 *
	 * @param null|array|string $value     The value get_metadata() should return - a single metadata value,
	 *                                     or an array of values.
	 * @param int               $object_id Object ID.
	 * @param string            $meta_key  Meta key.
	 * @param bool              $single    Whether to return only the first value of the specified $meta_key.
	 *
	 * @return null|array|string
	 */
	public function jetpack_dont_email_post_to_subs( $value, $object_id, $meta_key, $single ) {
		if ( '_jetpack_dont_email_post_to_subs' === $meta_key ) {
			$settings = $GLOBALS['WCCSP_Settings']->get_settings();

			if ( 'on' === $settings['enabled'] ) {
				return true;
			}
		}

		return $value;
	}

	/**
	 * Disable publicizing posts when Coming Soon mode is on. Note that even if we return false here, when publishing a
	 * post, we will see a message that post has been cross posted in social media accounts. That message is incorrect,
	 * but can only be fixed in JetPack, not here.
	 *
	 * @param bool $should_publicize Whether this post can be publicized or not.
	 *
	 * @return bool
	 */
	public function jetpack_prevent_publicize( $should_publicize ) {
		$settings = $GLOBALS['WCCSP_Settings']->get_settings();

		if ( 'on' === $settings['enabled'] ) {
			return false;
		}

		return $should_publicize;
	}

} // end WordCamp_Coming_Soon_Page.
