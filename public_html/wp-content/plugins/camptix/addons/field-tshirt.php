<?php
/**
 * T-shirt Field Addon for CampTix
 */
class CampTix_Addon_Tshirt_Field extends CampTix_Addon {

	/**
	 * Runs during camptix_init, @see CampTix_Addon
	 */
	function camptix_init() {
		global $camptix;

		add_filter( 'camptix_question_field_types', array( $this, 'question_field_types' ) );
		add_action( 'camptix_question_field_tshirt', array( $this, 'question_field_tshirt' ), 10, 4 );
		add_action( 'camptix_prime_tshirt_report', array( $this, 'prime_report_cache' ) );

		add_shortcode( 'camptix_tshirt_report', array( $this, 'render_tshirt_report' ) );

		if ( ! wp_next_scheduled( 'camptix_prime_tshirt_report' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'camptix_prime_tshirt_report' );
		}
	}

	function question_field_types( $types ) {
		return array_merge( $types, array(
			'tshirt' => 'T-Shirt Size (public)',
		) );
	}

	function question_field_tshirt( $name, $value, $question, $required = false ) {
		global $camptix;
		$values = get_post_meta( $question->ID, 'tix_values', true );
		?>
		<select
			id="<?php echo esc_attr( $camptix->get_field_id( $name ) ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			<?php if ( $required ) echo 'required'; ?>
		>
			<?php foreach ( (array) $values as $question_value ) : ?>
				<option <?php selected( $question_value, $value ); ?> value="<?php echo esc_attr( $question_value ); ?>"><?php echo esc_html( $question_value ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the tshirt size report
	 *
	 * @return string
	 */
	public function render_tshirt_report() {
		$sizes_by_site = get_site_option( 'tix_aggregated_tshirt_sizes', array() );

		ob_start();
		require_once dirname( dirname( __FILE__ ) ) . '/views/addons/field-tshirt-report.php';

		return ob_get_clean();
	}

	/**
	 * Cache the data needed for the tshirt report
	 */
	public function prime_report_cache() {
		if ( ! is_main_site() ) {
			return;
		}

		$sizes_by_site = array();

		$sites = get_sites( array(
			'fields'  => 'ids',
			'number'  => 200,
			'orderby' => 'id',
			'order'   => 'DESC',
		) );

		$i = 0;
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			$i++;
			$sizes = $this->get_aggregated_sizes();

			if ( ! empty( $sizes ) ) {
				$sizes_by_site[ $site_id ]['name']    = get_wordcamp_name();
				$sizes_by_site[ $site_id ]['message'] = apply_filters( 'camptix_tshirt_report_intro', '', $site_id, $sizes );
				$sizes_by_site[ $site_id ]['sizes']   = $sizes;
			}

			restore_current_blog();
			// Reset DB query log & object cache every 10 loops, to reduce memory usage.
			if ( $i % 10 == 0 ) {
				self::reset_db_query_log();
				self::reset_local_object_cache();
			}
		}

		update_site_option( 'tix_aggregated_tshirt_sizes', $sizes_by_site );
	}

	/**
	 * Resets the WordPress DB query log.
	 * When multiple queries are run, the query log fills up.
	 */
	public function reset_db_query_log() {
		global $wpdb;

		$wpdb->queries = array();
	}

	/**
	 * Reset local WordPress object cache.
	 */
	public function reset_local_object_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops      = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache          = array();

		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset(); // important
		}
	}


	/**
	 * Get the counts for each shirt size that attendees selected
	 *
	 * @return array
	 */
	protected function get_aggregated_sizes() {
		$size_counts = array();

		$questions = get_posts( array(
			'post_type'      => 'tix_question',
			'posts_per_page' => 1000,
			'meta_key'       => 'tix_type',
			'meta_value'     => 'tshirt',
		) );

		if ( empty( $questions ) ) {
			return $size_counts;
		}

		foreach ( $questions as $question ) {
			// Loop around attendees in pages of 1000 to avoid memory issues.
			for ( $i = 1; $i <= 10; $i++ ) {
				$attendees = get_posts( array(
					'post_type'      => 'tix_attendee',
					'posts_per_page' => 1000,
					'paged'          => $i,
				) );

				if ( empty( $attendees ) ) {
					break;
				}

				foreach ( $attendees as $attendee ) {
					if ( empty( $attendee->tix_questions[ $question->ID ] ) ) {
						continue;
					}

					$size = $attendee->tix_questions[ $question->ID ];

					if ( empty( $size_counts[ $size ] ) ) {
						$size_counts[ $size ] = 0;
					}

					$size_counts[ $size ]++;
				}
			}
		}

		return $size_counts;
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Addon_Tshirt_Field' );
