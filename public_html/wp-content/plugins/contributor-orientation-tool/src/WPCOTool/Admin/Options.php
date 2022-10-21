<?php

namespace WPCOTool\Admin;

use WPCOTool\Frontend\Team;
use WPCOTool\Plugin;

/**
 * Class Options
 * Manages plugin options
 * @package WPCOTool\Admin
 */
class Options {

	/**
	 * Prefix to use for the page
	 * @var string
	 */
	private $prefix;

	/**
	 * Options section id
	 * @var string
	 */
	private $section_id;

	/**
	 * Options page id on which to display section
	 * @var string
	 */
	private $page_id;

	/**
	 * Options group
	 * @var static
	 */
	private $options_group;

	/**
	 * Options name
	 * @var string
	 */
	private $options_name;

	/**
	 * Options constructor.
	 *
	 * @param string $prefix General prefix for plugin
	 */
	public function __construct( string $prefix ) {

		$this->prefix = $prefix;
		$this->options_name = sprintf( '%s_enabled_teams', $this->prefix );
		
	}

	/**
	 * Add hooks and define properties for options page
	 */
	public function init_admin_form() {

		$this->section_id = sprintf( '%s_options_section', $this->prefix );
		$this->page_id = sprintf( '%s_options_page', $this->prefix );
		$this->options_group = sprintf( '%s_options_group', $this->prefix );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( sprintf( '%s_settings_page', $this->prefix ), array( $this, 'page' ) );

	}

	/**
	 * Options page callback
	 */
	public function page() {

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Contributor orientation tool settings', 'contributor-orientation-tool' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				// This prints out all hidden setting fields
				settings_fields( $this->options_group );
				do_settings_sections( $this->page_id );
				submit_button();
				?>
			</form>
		</div>
		<?php

	}

	/**
	 * Register and add settings
	 */
	public function register() {

		register_setting(
			$this->options_group, // Option group
			$this->options_name, // Option name
			array( $this, 'sanitize' ) // Sanitize
		);

		add_settings_section(
			$this->section_id, // ID
			esc_html__( 'Enabled WordPress org teams', 'contributor-orientation-tool' ), // Title
			array( $this, 'section_info' ), // Callback
			$this->page_id // Page
		);

		add_settings_field(
			$this->options_name, // ID
			esc_html__( 'Select teams to disable:', 'contributor-orientation-tool' ), // Title
			array( $this, 'fields' ), // Callback
			$this->page_id, // Page
			$this->section_id // Section
		);

	}

	/**
	 * Sanitize form fields
	 * @param array $input Contains all settings fields as array keys
	 *
	 * @return array
	 */
	public function sanitize( $input ) {

		if ( empty( $input ) ) {
			return $input;
		}

		return array_map( 'sanitize_text_field', $input );
	}

	/**
	 * Print the Section text
	 */
	public function section_info() {

		esc_html_e( 'Use this option to disable teams that you don\'t need it the tool.', 'contributor-orientation-tool' );

	}

	/**
	 * Get the settings option array and print one of its values
	 */
	public function fields() {

		$values = $this->get_values();
		$teams = Plugin::get_form_config( 'teams.php' );

		foreach ( $teams as $id => $team ) {

			$team = new Team( $id, $team['name'] );
			$team_id = $team->get_id();

			printf(
				'<fieldset>
					<legend class="screen-reader-text">%4$s</legend>
					<input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s" %5$s/>
					<label for="%1$s">%4$s</label>
				</fieldset>',
				sprintf( '%s-%s', $this->prefix, $team_id ),
				$this->options_name,
				$team_id,
				$team->get_name(),
				! empty( $values ) && in_array( $team_id, $values ) ? ' checked="checked"' : ''
			);

		}

	}

	/**
	 * Return plugins options
	 * @return array
	 */
	public function get_values() {

		$values = get_option( $this->options_name );

		return empty( $values ) ? array() : $values;

	}


}
