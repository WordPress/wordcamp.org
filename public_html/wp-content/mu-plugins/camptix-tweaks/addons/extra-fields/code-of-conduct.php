<?php
namespace WordCamp\CampTix_Tweaks;

defined( 'WPINC' ) || die();

/**
 * Class Code_Of_Conduct_Field.
 *
 * Add a non-optional attendee field confirming that they agree to follow the event code of conduct.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Code_Of_Conduct_Field extends Extra_Fields {
	const SLUG = 'coc';

	public $question_order      = 100;
	public $type                = 'checkbox';
	public $enable_summary      = false;
	public $enable_export_erase = false;

	/**
	 * Setup the question & options.
	 */
	public function init() {
		$this->question = __( 'Do you agree to follow the event Code of Conduct?', 'wordcamporg' );
		$coc_url = $this->maybe_get_coc_url();
		if ( $coc_url ) {
			$this->question = sprintf(
				/* translators: %s placeholder is a URL */
				__( 'Do you agree to follow the event <a href="%s" target="_blank">Code of Conduct</a>?', 'wordcamporg' ),
				esc_url( $coc_url )
			);
		}

		$this->options = array(
			'yes' => _x( 'Yes', 'ticket registration option', 'wordcamporg' ),
		);
	}

	/**
	 * Save the value of the field to the attendee postmeta for back-compat.
	 *
	 * @param int    $post_id
	 * @param string $answer
	 */
	public function save_field( $post_id, $answer ) {
		// For back-compat, we only store a value of '1'.
		return update_post_meta( $post_id, 'tix_' . static::SLUG, 1 );
	}

	/**
	 * All tickets are required to have the Code of Conduct checkbox set.
	 *
	 * @param array $ticket_info
	 * @param int   $attendee
	 */
	public function populate_attendee_answer( $ticket_info, $attendee ) {
		$ticket_info[ static::SLUG ] = __( 'Yes', 'wordcamporg' );

		return $ticket_info;
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
