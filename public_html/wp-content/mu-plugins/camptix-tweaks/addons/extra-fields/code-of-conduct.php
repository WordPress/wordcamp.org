<?php
namespace WordCamp\CampTix_Tweaks;

defined( 'WPINC' ) or die();

/**
 * Class Code_Of_Conduct_Field.
 *
 * Add a non-optional attendee field confirming that they agree to follow the event code of conduct.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Code_Of_Conduct_Field extends Extra_Fields {
	const SLUG = 'coc';

	public $label          = '';
	public $question       = '';
	public $options        = array();
	public $question_order = 100;
	public $type           = 'checkbox';

	// No need to summarize this field, since it's just a yes checkbox.
	public $enable_summary = false;
	public $enable_export_erase = false;

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function init() {
		$this->label    = __( 'Code of Conduct', 'wordcamporg' );
		$this->question = __( 'Do you agree to follow the event Code of Conduct?', 'wordcamporg' );
		$coc_url = $this->maybe_get_coc_url();
		if ( $coc_url ) {
			$this->question = sprintf(
				/* translators: %s placeholder is a URL */
				__( 'Do you agree to follow the event <a href="%s" target="_blank">Code of Conduct</a>?', 'wordcamporg' ),
				esc_url( $coc_url )
			);
		}

		// Empty options = Only a single YES required checkbox.
		$this->options = array();

		// Registration field
		add_filter( 'camptix_checkout_attendee_info', array( $this, 'validate_registration_field' ) );
		add_action( 'camptix_form_attendee_info_errors', array( $this, 'add_registration_field_validation_error' ) );
	}

	/**
	 * Validate the value of the new field submitted to the registration form during checkout.
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function validate_registration_field( $data ) {
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		$data[ self::SLUG ] = wp_validate_boolean( $data[ self::SLUG ] ?? false );

		if ( true !== $data[ self::SLUG ] ) {
			$camptix->error_flags[ self::SLUG . '_unchecked' ] = true;
		}

		return $data;
	}

	/**
	 * Add a validation message when the checkbox isn't checked.
	 *
	 * @param array $error_flags
	 */
	public function add_registration_field_validation_error( $error_flags ) {
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		if ( isset( $error_flags[ self::SLUG . '_unchecked' ] ) ) {
			$camptix->error( __( 'You must agree to follow the event Code of Conduct to obtain a ticket.', 'wordcamporg' ) );
		}
	}

	/**
	 * If the Code of Conduct page is still the same one created with the site, get its URL.
	 *
	 * @return false|string
	 */
	protected function maybe_get_coc_url() {
		$url = '';

		$coc_page = get_posts( array(
			'post_type'   => 'page',
			'name'        => 'code-of-conduct',
			'numberposts' => 1,
		) );

		if ( $coc_page ) {
			$url = get_the_permalink( array_shift( $coc_page ) );
		}

		return $url;
	}
}

camptix_register_addon( __NAMESPACE__ . '\Code_Of_Conduct_Field' );
