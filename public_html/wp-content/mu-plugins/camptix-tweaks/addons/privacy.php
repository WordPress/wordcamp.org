<?php

namespace WordCamp\CampTix_Tweaks;
defined( 'WPINC' ) or die();

use CampTix_Plugin, CampTix_Addon;
use WP_Post;

/**
 * Class Privacy_Field.
 *
 * Add an attendee checkbox field for opting into visibility on the public Attendees page.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Privacy_Field extends CampTix_Addon {
	const SLUG = 'privacy';

	public $question = '';

	public $options = array();

	/**
	 * Hook into WordPress and Camptix.
	 */
	public function camptix_init() {
		if ( $attendees_url = $this->maybe_get_attendees_url() ) {
			$this->question = sprintf(
				/* translators: 1: placeholder for URL to Attendees page; 2: placeholder for URL to privacy policy page. */
				__( 'Do you want to be listed on the public <a href="%1$s" target="_blank">Attendees page</a>? <a href="%2$s" target="_blank">Learn more.</a>', 'wordcamporg' ),
				esc_url( $attendees_url ),
				esc_url( get_privacy_policy_url() )
			);
		} else {
			$this->question = sprintf(
				/* translators: %s placeholder for URL to privacy policy page. */
				__( 'Do you want to be listed on the public Attendees page? <a href="%s" target="_blank">Learn more.</a>', 'wordcamporg' ),
				esc_url( get_privacy_policy_url() )
			);
		}
		$this->a11y_label = __( 'Do you want to be listed on the public Attendees page?', 'wordcamporg' );

		$this->options = array(
			'yes' => _x( 'Yes', 'ticket registration option', 'wordcamporg' ),
			'no'  => _x( 'No', 'ticket registration option', 'wordcamporg' ),
		);

		// Registration field.
		add_action( 'camptix_attendee_form_after_questions', array( $this, 'render_registration_field' ), 10, 2 );
		add_filter( 'camptix_checkout_attendee_info', array( $this, 'validate_registration_field' ) );
		add_filter( 'camptix_form_register_complete_attendee_object', array( $this, 'populate_attendee_object' ), 10, 2 );
		add_action( 'camptix_checkout_update_post_meta', array( $this, 'save_registration_field' ), 10, 2 );

		// Edit info field.
		add_filter( 'camptix_form_edit_attendee_ticket_info', array( $this, 'populate_ticket_info_array' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_update_post_meta', array( $this, 'validate_save_ticket_info_field' ), 10, 2 );
		add_action( 'camptix_form_edit_attendee_after_questions', array( $this, 'render_ticket_info_field' ), 10 );

		// Delete cached attendees lists when an attendee privacy setting changes.
		add_action( 'added_post_meta', array( $this, 'invalidate_attendees_cache' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_attendees_cache' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_attendees_cache' ), 10, 3 );
	}

	/**
	 * Render the field, used on both Registration and when editing an existing ticket.
	 *
	 * @param string $name
	 * @param string $value
	 */
	public function render_field( $name, $value ) {
		?>

		<tr class="tix-row-<?php echo esc_attr( self::SLUG ); ?>">
			<td class="tix-left">
				<?php echo wp_kses_post( $this->question ); ?>
				<span aria-hidden="true" class="tix-required-star">*</span>
			</td>

			<td class="tix-right">
				<fieldset class="tix-screen-reader-fieldset" aria-label="<?php echo esc_attr( $this->a11y_label ); ?>">
					<label>
						<input
							name="<?php echo esc_attr( $name ); ?>"
							type="radio"
							value="yes"
							<?php checked( 'yes', $value ); ?>
							required
						/>
						<?php echo esc_html( $this->options['yes'] ); ?>
					</label>
					<br />
					<label>
						<input
							name="<?php echo esc_attr( $name ); ?>"
							type="radio"
							value="no"
							<?php checked( 'no', $value ); ?>
							required
						/>
						<?php echo esc_html( $this->options['no'] ); ?>
					</label>
				</fieldset>
			</td>
		</tr>

		<?php
	}

	/**
	 * Render the new field for the registration form during checkout.
	 *
	 * @param array $form_data
	 * @param int   $i
	 */
	public function render_registration_field( $form_data, $i ) {
		$current_data = ( isset( $form_data['tix_attendee_info'][ $i ] ) ) ?: array();

		$current_data = wp_parse_args( $current_data, array(
			self::SLUG => '',
		) );

		$this->render_field(
			sprintf( 'tix_attendee_info[%d][%s]', $i, self::SLUG ),
			$current_data[ self::SLUG ]
		);
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

		if ( ! isset( $data[ self::SLUG ] ) || empty( $data[ self::SLUG ] ) ) {
			$camptix->error_flags['required_fields'] = true;
		} else {
			$data[ self::SLUG ] = ( 'yes' === $data[ self::SLUG ] ) ? true : false;
		}

		return $data;
	}

	/**
	 * Add the value of the new field to the attendee object during checkout processing.
	 *
	 * @param WP_Post $attendee
	 * @param array   $data
	 *
	 * @return WP_Post
	 */
	public function populate_attendee_object( $attendee, $data ) {
		$attendee->{ self::SLUG } = $data[ self::SLUG ];

		return $attendee;
	}

	/**
	 * Save the value of the new field to the attendee post upon completion of checkout.
	 *
	 * @param int     $post_id
	 * @param WP_Post $attendee
	 *
	 * @return bool|int
	 */
	public function save_registration_field( $post_id, $attendee ) {
		if ( true === wp_validate_boolean( $attendee->{ self::SLUG } ) ) {
			$result = delete_post_meta( $post_id, 'tix_' . self::SLUG );
		} else {
			$result = update_post_meta( $post_id, 'tix_' . self::SLUG, 'private' );
		}

		return $result;
	}

	/**
	 * Retrieve the stored value of the new field for use on the Edit Info form.
	 *
	 * @param array   $ticket_info
	 * @param WP_Post $attendee
	 *
	 * @return array
	 */
	public function populate_ticket_info_array( $ticket_info, $attendee ) {
		$raw_value = get_post_meta( $attendee->ID, 'tix_' . self::SLUG, true );

		if ( 'private' === $raw_value ) {
			$ticket_info[ self::SLUG ] = 'no';
		} else {
			$ticket_info[ self::SLUG ] = 'yes';
		}

		return $ticket_info;
	}

	/**
	 * Update the stored value of the new field if it was changed in the Edit Info form.
	 *
	 * @param array   $data
	 * @param WP_Post $attendee
	 *
	 * @return bool|int
	 */
	public function validate_save_ticket_info_field( $data, $attendee ) {
		$data = $this->validate_registration_field( $data );

		if ( true === wp_validate_boolean( $data[ self::SLUG ] ) ) {
			$result = delete_post_meta( $attendee->ID, 'tix_' . self::SLUG );
		} else {
			$result = update_post_meta( $attendee->ID, 'tix_' . self::SLUG, 'private' );
		}

		return $result;
	}

	/**
	 * Render the new field for the Edit Info form.
	 *
	 * @param array $ticket_info
	 */
	public function render_ticket_info_field( $ticket_info ) {
		$current_data = wp_parse_args( $ticket_info, array(
			self::SLUG => 'yes',
		) );

		$this->render_field(
			sprintf( 'tix_ticket_info[%s]', self::SLUG ),
			$current_data[ self::SLUG ]
		);
	}

	/**
	 * Clear all of the cached instances of the camptix_attendees shortcode content when attendee privacy changes.
	 *
	 * The shortcode content is cached based on the attributes of the shortcode instance, so there can be multiple
	 * cache entries. Thus the need to retrieve a list of all the cache keys first.
	 *
	 * Note: This won't work anymore if/when WordCamp switches to an external object cache, since the data wouldn't
	 * be stored in the options table anymore. If that happens, hopefully there will be a way to pattern match the keys
	 * in that cache.
	 *
	 * @param int    $meta_id  Unused.
	 * @param int    $post_id  Unused.
	 * @param string $meta_key The key of the current post meta value being changed.
	 *
	 * @return void
	 */
	public function invalidate_attendees_cache( $meta_id, $post_id, $meta_key ) {
		if ( 'tix_' . self::SLUG !== $meta_key ) {
			return;
		}

		global $wpdb;

		$cache_entries = $wpdb->get_col( "
			SELECT option_name
			FROM $wpdb->options
			WHERE option_name LIKE '_transient_camptix-attendees-%'
		" );

		foreach ( $cache_entries as $transient ) {
			$key = str_replace( '_transient_', '', $transient );
			delete_transient( $key );
		}

		// Clear WP Super Cache.
		if ( is_callable( 'wp_cache_clean_cache' ) && is_callable( 'wp_cache_regenerate_cache_file_stats' ) ) {
			global $file_prefix;
			wp_cache_clean_cache( $file_prefix, true );
			wp_cache_regenerate_cache_file_stats();
		}
	}

	/**
	 * If the Attendees page is still the same one created with the site, get its URL.
	 *
	 * @return false|string
	 */
	protected function maybe_get_attendees_url() {
		$url = '';

		$attendees_page = get_posts( array(
			'post_type'   => 'page',
			'name'        => 'attendees',
			'numberposts' => 1,
		) );

		if ( $attendees_page ) {
			$url = get_the_permalink( array_shift( $attendees_page ) );
		}

		return $url;
	}
}

camptix_register_addon( __NAMESPACE__ . '\Privacy_Field' );
