<?php
namespace WordCamp\CampTix_Tweaks;

use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Class Privacy_Field.
 *
 * Add an attendee checkbox field for opting into visibility on the public Attendees page.
 *
 * @package WordCamp\CampTix_Tweaks
 */
class Privacy_Field extends Extra_Fields {
	const SLUG = 'privacy';

	public $question_order = 11;
	public $enable_summary = false;

	/**
	 * Setup the question & options.
	 */
	public function init() {
		$this->a11y_label = __( 'Do you want to be listed on the public Attendees page?', 'wordcamporg' );

		$attendees_url = $this->maybe_get_attendees_url();
		if ( $attendees_url ) {
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

		$this->options = array(
			'yes' => _x( 'Yes', 'ticket registration option', 'wordcamporg' ),
			'no'  => _x( 'No', 'ticket registration option', 'wordcamporg' ),
		);

		// Delete cached attendees lists when an attendee privacy setting changes.
		add_action( 'added_post_meta', array( $this, 'invalidate_attendees_cache' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_attendees_cache' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_attendees_cache' ), 10, 3 );
	}

	/**
	 * Save the value of the field to the attendee postmeta for back-compat.
	 *
	 * @param int   $post_id
	 * @param mixed $answer
	 */
	public function save_field( $post_id, $answer ) {
		if ( in_array( $answer, [ 'no', $this->options['no'] ] ) ) {
			// Privacy; No = "do NOT show" = set as private.
			$result = update_post_meta( $post_id, 'tix_' . self::SLUG, 'private' );
		} else {
			// Privacy: Yes = "Show me on attendees page" = delete the meta.
			$result = delete_post_meta( $post_id, 'tix_' . self::SLUG );
		}

		return $result;
	}

	/**
	 * Retrieve the stored value of the new field for use when displaying the attendee info.
	 *
	 * Back-compat only, for where the field was stored outside of the question answers.
	 *
	 * @param array   $ticket_info
	 * @param WP_Post $attendee
	 *
	 * @return array
	 */
	public function populate_attendee_answer( $ticket_info, $attendee ) {
		$ticket_info = parent::populate_attendee_answer( $ticket_info, $attendee );

		$ticket_info[ self::SLUG ] = in_array( $ticket_info[ self::SLUG ], [ 'private', 'no' ] ) ? $this->options['no'] : $this->options['yes'];

		return $ticket_info;
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
