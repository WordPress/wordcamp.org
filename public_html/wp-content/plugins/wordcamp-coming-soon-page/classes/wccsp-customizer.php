<?php

class WCCSP_Customizer {
	protected $settings;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init',                  array( $this, 'init'                           ), 11 ); // After WCCSP_Settings::init().
		add_action( 'customize_register',    array( $this, 'register_customizer_components' )     );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_customizer_scripts'     )     );
	}

	/**
	 * Initializes variables
	 */
	public function init() {
		$this->settings = $GLOBALS['WCCSP_Settings']->get_settings();
	}

	/**
	 * Register our Customizer settings, panels, sections, and controls
	 *
	 * @param \WP_Customize_Manager $wp_customize
	 */
	public function register_customizer_components( $wp_customize ) {
		$wp_customize->add_section(
			'wccsp_live_preview',
			array(
				'capability'  => $GLOBALS['WCCSP_Settings']::REQUIRED_CAPABILITY,
				'title'       => __( 'Coming Soon Page', 'wordcamporg' ),
				'description' => __(
					'When enabled, the Coming Soon page will be displayed to logged-out users, giving you a chance to setup all of your content before your site is visible to the world.',
					'wordcamporg'
				) . $GLOBALS['WCCSP_Settings']->render_admin_notices(),
			)
		);

		$wp_customize->add_setting(
			'wccsp_settings[enabled]',
			array(
				'default'           => 'off',
				'type'              => 'option',
				'capability'        => $GLOBALS['WCCSP_Settings']::REQUIRED_CAPABILITY,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'maybe_prevent_disable' ),
			)
		);

		$wp_customize->add_control(
			'wccsp_settings[enabled]',
			array(
				'section'    => 'wccsp_live_preview',
				'type'       => 'radio',
				'choices'    => array( 'on' => 'On', 'off' => 'Off' ),
				'priority'   => 1,
				'capability' => $GLOBALS['WCCSP_Settings']::REQUIRED_CAPABILITY,
			)
		);

		$wp_customize->add_setting(
			'wccsp_settings[body_background_color]',
			array(
				'default'           => '#0073aa',
				'type'              => 'option',
				'capability'        => $GLOBALS['WCCSP_Settings']::REQUIRED_CAPABILITY,
				'sanitize_callback' => 'sanitize_hex_color',
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Color_Control(
				$wp_customize,
				'wccsp_settings[body_background_color]',
				array(
					'section'     => 'wccsp_live_preview',
					'label'       => __( 'Accent Color', 'wordcamporg' ),
					'description' => __( 'This color is used to generate the header background, and the button colors.', 'wordcamporg' ),
				)
			)
		);

		$wp_customize->add_setting(
			'wccsp_settings[image_id]',
			array(
				'default'           => 0,
				'type'              => 'option',
				'capability'        => $GLOBALS['WCCSP_Settings']::REQUIRED_CAPABILITY,
				'sanitize_callback' => 'absint',
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Media_Control(
				$wp_customize,
				'wccsp_settings[image_id]',
				array(
					'label'       => __( 'Logo', 'wordcamporg' ),
					'description' => __( 'A smaller image displayed above your WordCamp name.<br />Best size is less than 500px wide.', 'wordcamporg' ),
					'section'     => 'wccsp_live_preview',
					'mime_type'   => 'image',
				)
			)
		);

		$wp_customize->add_setting(
			'wccsp_settings[background_id]',
			array(
				'default'           => 0,
				'type'              => 'option',
				'capability'        => $GLOBALS['WCCSP_Settings']::REQUIRED_CAPABILITY,
				'sanitize_callback' => 'absint',
			)
		);

		$wp_customize->add_control(
			new WP_Customize_Media_Control(
				$wp_customize,
				'wccsp_settings[background_id]',
				array(
					'label'       => __( 'Background Image', 'wordcamporg' ),
					'description' => __( 'A larger image displayed behind the header text.<br />Best size is larger than 1000px wide.', 'wordcamporg' ),
					'section'     => 'wccsp_live_preview',
					'mime_type'   => 'image',
				)
			)
		);

		$wp_customize->add_setting(
			'wccsp_settings[introduction]',
			array(
				'default'           => '',
				'type'              => 'option',
				'capability'        => $GLOBALS['WCCSP_Settings']::REQUIRED_CAPABILITY,
				'sanitize_callback' => array( $this, 'sanitize_introduction' ),
			)
		);

		$wp_customize->add_control(
			'wccsp_settings[introduction]',
			array(
				'label'       => __( 'Custom Message', 'wordcamporg' ),
				'description' => __( 'This will replace the default message shown beneath the header.', 'wordcamporg' ),
				'section'     => 'wccsp_live_preview',
				'type'        => 'textarea',
			)
		);
	}

	/**
	 * Sanitize the contents of the Custom Message textarea before storing in the database.
	 *
	 * This uses the same sanitization strategy as menu item descriptions and post content in wp_insert_post.
	 *
	 * @param string $value The unsanitized introduction string.
	 *
	 * @return string The sanitized introduction string.
	 */
	public function sanitize_introduction( $value ) {
		return wp_unslash( apply_filters( 'content_save_pre', wp_slash( $value ) ) );
	}

	/**
	 * Enqueue scripts and styles for the Customizer
	 */
	public function enqueue_customizer_scripts() {
		if ( ! isset( $GLOBALS['wp_customize'] ) ) {
			return;
		}

		$GLOBALS['WordCamp_Coming_Soon_Page']->manage_plugin_theme_stylesheets();

		wp_enqueue_script(
			'wccsp-customizer',
			plugins_url( 'javascript/wccsp-customizer.js', __DIR__ ),
			array(),
			1,
			true
		);
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
	 * Validate the wccsp_settings[enabled] and maybe prevent disabling the coming soon page.
	 *
	 * Non valid field prevents the save, thus also disabling the coming soon page.
	 *
	 * @param  boolean $validity Status of the field validity.
	 * @param  string  $value    Field value.
	 *
	 * @return boolean           Status of the field validity.
	 */
	public function maybe_prevent_disable( $validity, $value ) {
		// Coming soon page on is always valid.
		if ( 'on' === $value ) {
			return $validity;
		}

		// If WordCamp is not added to schedule, field is not valid.
		if ( 'wcpt-scheduled' !== $this->get_status() ) {
			return new WP_Error( 'wcpt-not-in-schedule', __( 'The Coming Soon page can not be turned off because WordCamp is not yet published in the schedule.' ) );
		}

		return $validity;
	}
}
