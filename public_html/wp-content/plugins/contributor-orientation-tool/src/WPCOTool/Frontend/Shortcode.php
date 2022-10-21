<?php

namespace WPCOTool\Frontend;

use WPCOTool\Admin\Options;
use WPCOTool\Plugin;

/**
 * Class Shortcode responsible for frontend output
 * Shortcode: [contributor-orientation-tool]
 * @package WPCOTool\Frontend
 */
class Shortcode {

	/**
	 * Plugin version.
	 *
	 * @since    0.0.1
	 * @access   public
	 * @var string
	 */
	private $version;

	/**
	 * Shortcode tag
	 * @var string
	 */
	private $shortcode_tag = 'contributor-orientation-tool';

	/**
	 * Prefix used for output to create ids, field names...
	 * @var string
	 */
	private $prefix;

	/**
	 * Active section css class
	 * @var string
	 */
	private $active_section_class;

	/**
	 * Number of sections including teams
	 */
	private $number_of_sections = 4;

	/**
	 * Steps for form header
	 * @var array
	 */
	private $steps = array();

	/**
	 * Shortcode constructor.
	 *
	 * @param string $version Plugin version
	 * @param string $prefix General prefix for plugin
	 */
	public function __construct( string $version, string $prefix ) {

		$this->version = sanitize_text_field( $version );
		$this->prefix = $prefix;
		$this->active_section_class = sprintf( ' %s__section--active', $this->prefix );
		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );
		add_shortcode( $this->shortcode_tag, array( $this, 'output' ) );

		$this->steps = array(
			1 => esc_html__( 'Use of WordPress', 'contributor-orientation-tool' ),
			2 => esc_html__( 'Passionate', 'contributor-orientation-tool' ),
			3 => esc_html__( 'Experience', 'contributor-orientation-tool' ),
			4 => esc_html__( 'Done!', 'contributor-orientation-tool' )
		);

	}

	/**
	 * Html output (shortcode).
	 *
	 * @param array $atts Shortcode attributes.
	 * @param string $content Shortcode content
	 * @return string
	 */
	public function output( $atts, $content = '' ) {

		$selected_teams = $this->get_enabled_teams();

        /**
         * Output
         */
		return sprintf(
			'<div id="%1$s"><form method="post" action="">%4$s<div class="wpcot__questions">%2$s</div><button type="submit">%3$s</button></form></div>',
			esc_attr( $this->prefix ), // %1$s
			implode( '', $this->get_sections( $selected_teams ) ), // %2$s
			esc_html__( 'Submit', 'contributor-orientation-tool' ), // %3$s
			$this->get_form_header() // %4$s
		);

	}

	/**
	 * Return sections with questions for the form
	 * @param array $teams Enabled teams to parse sections
	 *
	 * @return array
	 */
	private function get_sections( $teams ) {

		if ( empty( $teams ) ) {

			return array(
				sprintf(
					'<h4 class="%s">%s</h4>',
					sprintf( '%s__no-teams-selected', $this->prefix ),
					esc_html__( 'Please enable some teams form plugin options!', 'contributor-orientation-tool' )
				)
			);

		}

		/**
		 * Multipart form sections
		 */
		$sections = $this->get_questions_sections( $teams );

		/**
		 * Add teams section as final results
		 */
		$sections[] = $this->get_teams_section( $teams );

		return $sections;

	}

	/**
	 * Return section with teams
	 * @param array $selected_teams Array of enabled teams
	 *
	 * @return string Return section html
	 */
	private function get_teams_section( $selected_teams ) {

		$fields = array();
		foreach ( $selected_teams as $id => $data ) {

			if(
				! isset( $data['name'] )
				|| ! isset( $data['description'] )
				|| ! isset( $data['icon'] )
				|| ! isset( $data['url'] )
			) {
				continue;
			}

			$team = new Team(
				$id,
				$data['name'],
				$data['description'],
				$data['icon'],
				$data['url']
			);

			$fields[] = $this->get_team_checkbox_field(
				sprintf( '%s__teams', $this->prefix ),
				$team->get_id(),
				$team->get_icon(),
				$team->get_name(),
				$team->get_description(),
				$team->get_url()
			);

		}

		return $this->get_section(
			sprintf( '%s-section-teams', $this->prefix ),
			esc_html__( 'Based on your answers, we recommend that you join some of teams below:', 'contributor-orientation-tool' ),
			implode( '', $fields ),
			'',
			$this->get_button( $this->get_back_button_text(), true ),
			'',
			$this->get_form_notice()
		);

	}

	/**
	 * Return sections with questions
	 * @param array $selected_teams Array of enabled teams
	 *
	 * @return array Return array of sections html
	 */
	private function get_questions_sections( $selected_teams ) {

	    $section_1_key = sprintf( '%s-section-1', $this->prefix );

        $form_sections = array(
            $section_1_key => Plugin::get_form_config( 'section-1.php' ),
            sprintf( '%s-section-2', $this->prefix ) => Plugin::get_form_config( 'section-2.php' ),
            sprintf( '%s-section-3', $this->prefix ) => Plugin::get_form_config( 'section-3.php' )
        );

        $sections = array();
        foreach ( $form_sections as $section_id => $section ) {

            $fields = array();
            foreach ( $section['questions'] as $key => $field ) {

                if ( ! isset( $field['label'] ) || ! $field['teams'] ) {
                    continue;
                }

                $question = QuestionFactory::create( $field['label'], $field['teams'] );
                $teams = $question->get_teams();

                /**
                 * Compare if question is referring to one of selected teams and get only enabled teams
                 */
                $enabled_teams = array_filter( $teams, function ( $team ) use ( $selected_teams ) {
                    return in_array( $team, array_keys( $selected_teams ) );
                } );

                if ( empty( $enabled_teams ) ) {
                    continue;
                }

                $fields[] = $this->get_checkbox_field(
	                $question->get_label(),
	                str_replace( '-', '_', $section_id ),
	                implode( ',', $enabled_teams ),
	                sprintf( '%s-%s', $section_id, $key ),
					$field['img']
                );

            }

            $sections[] = $this->get_section(
	            $section_id,
	            $section['headline'],
	            implode( '', $fields ),
	            $this->get_button( $this->get_next_button_text(), false ),
	            $this->get_button( $this->get_back_button_text(), true ),
	            $section_id === $section_1_key ? $this->active_section_class : ''
            );

        }

        return $sections;

    }

	/**
	 * Return section html
	 * @param string $id Section id attribute
	 * @param string $headline Section headline
	 * @param string $content Section content
	 * @param string $button_next Button html
	 * @param string $button_prev Button html
	 * @param bool $active_class If section should have active class
	 * @param string $notice Any text for notice to display in section after headline
	 *
	 * @return string
	 */
    private function get_section( $id, $headline, $content, $button_next = '', $button_prev = '', $active_class = false, $notice = '' ) {

	    return sprintf(
		    '<section id="%1$s" class="%6$s%7$s">
				<h3>%2$s</h3>
				<div class="%11$s">
					%3$s
					<div class="%9$s"></div>
				</div>
				%10$s
				<div class="%8$s">%5$s%4$s</div>
			</section>',
		    esc_attr( $id ), // %1$s
		    esc_html( $headline ), // %2$s
		    $content, // %3$s
		    ! empty( $button_next ) ? wp_kses_post( $button_next ) : '', // %4$s
		    ! empty( $button_prev ) ? wp_kses_post( $button_prev ) : '', // %5$s
		    sprintf( '%s__section', $this->prefix ), // %6$s
		    $active_class, // %7$s
		    sprintf( '%s__buttons', $this->prefix ), // %8$s
		    sprintf( '%s__section-error', $this->prefix ), // %9$s
	        wp_kses_post( $notice ), // %10$s
		    sprintf( '%s__choices', $this->prefix ) // %11$s
	    );

    }

	/**
	 * Return button html
	 * @param string $text Button text
	 * @param bool $prev If it is previous or next button
	 *
	 * @return string
	 */
    private function get_button( $text, $prev = false ) {

		return sprintf(
		'<button class="%1$s" type="button">%2$s</button>',
		$prev ? esc_attr( sprintf( '%s__button-prev', $this->prefix ) ) : esc_attr( sprintf( '%s__button-next', $this->prefix ) ),
			esc_html( $text )
		);

    }

	/**
	 * Return checkbox html
	 * @param string $label Label
	 * @param string $name Input name
	 * @param string $value Input value
	 * @param string $id Input id
	 * @param string $img Image
	 *
	 * @return string
	 */
    private function get_checkbox_field( $label, $name, $value, $id, $img ) {

		return sprintf(
			'<div class="%5$s" style="float:left;width:230px;"><img src="%6$s" alt="%2$s" height="160" /><input id="%1$s" type="checkbox" name="%3$s[]" value="%4$s" /><label for="%1$s">%2$s</label></div>',
			esc_attr( $id ), // %1$s
			esc_html( $label ), // %2$s
			sanitize_text_field( $name ), // %3$s
			esc_js( $value ), // %4$s
			$this->get_checkbox_field_class(), // %5$s
			esc_attr(Plugin::assets_url( 'images/'. $img)), // %6$s
		);

    }

	/**
	 * Return checkbox html
	 *
	 * @param string $name Input name
	 * @param string $value Input value
	 * @param string $team_icon
	 * @param string $team_name
	 * @param string $team_description
	 * @param string $team_url
	 *
	 * @return string
	 */
	private function get_team_checkbox_field( $name, $value, $team_icon, $team_name, $team_description, $team_url ) {

		return sprintf(
			'<div class="%9$s">
				<input id="%1$s" type="checkbox" name="%2$s[]" value="%3$s" />
				<label for="%1$s"><a href="%7$s" title="%5$s" target="_blank">%4$s%5$s</a></label>
				<p>%6$s</p>
				<a href="%7$s" title="%5$s" target="_blank">%8$s</a>
			</div>',
			esc_attr( $value ), // %1$s
			sanitize_text_field( $name ), // %2$s
			esc_js( $value ), // %3$s
			$team_icon, // %4$s
			esc_html( $team_name ), // %5$s
			wp_kses_post( $team_description ), // %6$s
			esc_url( $team_url ), // %7$s
			sprintf( esc_html__( 'Learn more about %s »' ), esc_html( $team_name ) ), // %8$s
			$this->get_checkbox_field_class() // %9$s
		);

	}

	/**
	 * Return class for section input group
	 * @return string
	 */
	private function get_checkbox_field_class() {

		return sprintf( '%s__input-group', $this->prefix );

	}

	/**
	 * Return form header which contain form steps
	 * @return string
	 */
	private function get_form_header() {

		if ( empty( $this->steps ) ) {
			return '';
		}

		$number = $this->number_of_sections;
		$steps = array();
		for( $i = 1; $i <= $number; $i++ ) {

			$steps[] = sprintf(
				'<li%1$s>
					<span class="%2$s">%3$s
						<span>%6$s</span>
					</span>
					<span class="%5$s">%4$d</span>
				</li>',
				$i === 1 ? sprintf( ' class="%s"', sprintf( '%s__steps--active', $this->prefix ) ) : '', // %1$s
				sprintf( '%s__steps-text', $this->prefix ), // %2$s
				sprintf( esc_html__( 'Step %d :', 'contributor-orientation-tool' ), $i ), // %3$s
				$i, // %4$d
				sprintf( '%s__steps-responsive', $this->prefix ), // %5$d
				$this->steps[ $i ] // %6$d
			);

		}

		return sprintf(
			'<div class="%1$s"><ul>%2$s</ul></div>',
			sprintf( '%s__steps', $this->prefix ),
			implode( $steps )
		);

	}

	/**
	 * Return form notice html
	 * @return string
	 */
	private function get_form_notice() {

		return sprintf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( sprintf( '%s__notice', $this->prefix ) ),
			esc_html__( 'Please note that this is not Contributor Day registering form. This is just an orientation tool and results represent recommendations based on your answers. You still need to register for Contributor Day if you are planning to attend one.', 'contributor-orientation-tool' )
		);

	}

	/**
	 * Get all teams from config and filter disabled teams via plugin options
	 * @return array
	 */
	private function get_enabled_teams() {

		$selected_teams = Plugin::get_form_config( 'teams.php' );
		$options = new Options( $this->prefix );
		$disabled_teams = $options->get_values();

		if ( empty( $disabled_teams ) || ! is_array( $disabled_teams ) ) {
			return $selected_teams;
		}

		return array_filter(
			$selected_teams,
			function ( $id ) use ( $disabled_teams ) {

				return ! in_array( $id, $disabled_teams );

			},
			ARRAY_FILTER_USE_KEY
		);


	}

	/**
	 * Return next button text
	 * @return string
	 */
	private function get_next_button_text() {

		return esc_html__( 'Next', 'contributor-orientation-tool' );

	}

	/**
	 * Return back button text
	 * @return string
	 */
	private function get_back_button_text() {

		return esc_html__( 'Go back', 'contributor-orientation-tool' );

	}

	/**
	 * Scripts and styles
	 *
	 * @access public
	 * @since 0.0.1
	 */
	public function scripts() {

		if ( ! is_singular( array( 'post', 'page' ) ) ) {
			return;
		}

		/**
		 * Global $post var
		 * @param WP_Post $post
		 */
		global $post;

		if ( ! has_shortcode( $post->post_content, $this->shortcode_tag ) ) {
			return;
		}

		$handle = sprintf( '%s-public', $this->shortcode_tag );

		wp_enqueue_style(
			$handle,
			Plugin::assets_url( 'css/shortcode.css' ),
			array(),
			$this->version
		);

		wp_enqueue_script(
			$handle,
			Plugin::assets_url( 'js/shortcode.js' ),
			array( 'jquery' ),
			$this->version,
			true
		);

		wp_localize_script(
			$handle,
			sprintf( '%sData', $this->prefix ),
			array(
				'errorMessage' => esc_html__( 'Please select at least one answer!', 'contributor-orientation-tool' )
			)
		);
	}

}
