<?php
/**
 * Implements Event_Loader class
 *
 * @package WordCamp Post Type
 */

/**
 * Class Event_Loader
 */
abstract class Event_Loader {

	/**
	 * Event_Loader constructor. Add common hooks.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'includes' ) );
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_post_statuses' ) );
		// Re-register them when the locale changes, to have localised statuses.
		add_action( 'change_locale', array( $this, 'register_post_types' ) );
		add_action( 'change_locale', array( $this, 'register_post_statuses' ) );
		add_filter( 'pre_get_posts', array( $this, 'query_public_statuses_on_archives' ) );
		add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_interval' ) );
		add_filter( 'posts_search', array( $this, 'extend_search_to_postmeta' ), 10, 2 );
		add_filter( 'posts_join', array( $this, 'search_postmeta_join' ), 10, 2 );
		add_filter( 'posts_groupby', array( $this, 'search_postmeta_groupby' ), 10, 2 );
	}

	/**
	 * Include all event specific dependent files.
	 *
	 * @return void
	 */
	abstract protected function includes();

	/**
	 * Register event custom post type.
	 *
	 * @return void
	 */
	abstract protected function register_post_types();

	/**
	 * Return list of available post statuses with their labels.
	 *
	 * @return array
	 */
	abstract public static function get_post_statuses();

	/**
	 * Register post statuses for this event type.
	 */
	public function register_post_statuses() {
		foreach ( $this->get_post_statuses() as $key => $label ) {
			register_post_status(
				$key, array(
					'label'       => $label,
					'public'      => true,
					'label_count' => _nx_noop(
						sprintf( '%s <span class="count">(%s)</span>', $label, '%s' ),
						sprintf( '%s <span class="count">(%s)</span>', $label, '%s' ),
						'wordcamporg'
					),
				)
			);
		}
	}

	/**
	 * List of statuses when an Event can be tracked in any public facing widget.
	 *
	 * @return array
	 */
	abstract public static function get_public_post_statuses();

	/**
	 * Only query the public post statuses on WordCamp archives and feeds
	 *
	 * By default, any public post statuses are queried when the `post_status` parameter is not explicitly passed
	 * to WP_Query. This causes central.wordcamp.org/wordcamps/ and central.wordcamp.org/wordcamps/feed/ to display
	 * camps that are `needs-vetting`, etc, which is not desired.
	 *
	 * Another way to fix this would have been to register some of the posts statuses as `private`, but they're not
	 * consistently used in a public or private way, so that would have had more side effects.
	 *
	 * @param WP_Query $query
	 */
	public function query_public_statuses_on_archives( $query ) {
		if ( ! $query->is_post_type_archive( WCPT_POST_TYPE_ID ) ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		// Sort by the date it was added to the schedule. See WordCamp_Loader::set_scheduled_date() for details.
		if ( '' === $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'menu_order date' );
		}

		if ( ! empty( $query->query_vars['post_status'] ) ) {
			return;
		}

		$query->query_vars['post_status'] = $this->get_public_post_statuses();
	}

	/**
	 * Add weekly schedule option to wp_schedule_event
	 *
	 * @param array $new_schedules
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function add_weekly_cron_interval( $schedules ) {
		if ( isset( $schedules['weekly'] ) ) {
			return $schedules;
		}

		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly', 'wordcamporg' ),
		);

		return $schedules;
	}

	/**
	 * Get searchable post meta keys for this event type.
	 *
	 * @return array
	 */
	abstract public static function get_searchable_meta_keys();

	/**
	 * Extend search to include post meta fields.
	 *
	 * @param string   $search The search SQL.
	 * @param WP_Query $query  The WP_Query instance.
	 *
	 * @return string Modified search SQL.
	 */
	public function extend_search_to_postmeta( $search, $query ) {
		global $wpdb;

		// Only extend search for our event post types.
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) || ! in_array( $post_type, array( WCPT_POST_TYPE_ID, WCPT_MEETUP_SLUG ), true ) ) {
			return $search;
		}

		// Only extend search when there's a search term.
		if ( empty( $search ) || ! $query->is_search() ) {
			return $search;
		}

		$searchable_keys = static::get_searchable_meta_keys();
		if ( empty( $searchable_keys ) ) {
			return $search;
		}

		$search_term = $query->get( 's' );
		if ( empty( $search_term ) ) {
			return $search;
		}

		// Build meta search conditions.
		$meta_search = array();
		foreach ( $searchable_keys as $meta_key ) {
			$meta_search[] = $wpdb->prepare(
				'(pm.meta_key = %s AND pm.meta_value LIKE %s)',
				$meta_key,
				'%' . $wpdb->esc_like( $search_term ) . '%'
			);
		}

		if ( ! empty( $meta_search ) ) {
			$search .= ' OR (' . implode( ' OR ', $meta_search ) . ')';
		}

		return $search;
	}

	/**
	 * Join postmeta table for search queries.
	 *
	 * @param string   $join  The JOIN clause.
	 * @param WP_Query $query The WP_Query instance.
	 *
	 * @return string Modified JOIN clause.
	 */
	public function search_postmeta_join( $join, $query ) {
		global $wpdb;

		// Only extend search for our event post types.
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) || ! in_array( $post_type, array( WCPT_POST_TYPE_ID, WCPT_MEETUP_SLUG ), true ) ) {
			return $join;
		}

		// Only join when there's a search term.
		if ( ! $query->is_search() || empty( $query->get( 's' ) ) ) {
			return $join;
		}

		$searchable_keys = static::get_searchable_meta_keys();
		if ( empty( $searchable_keys ) ) {
			return $join;
		}

		// Add LEFT JOIN to postmeta table.
		$join .= " LEFT JOIN {$wpdb->postmeta} AS pm ON {$wpdb->posts}.ID = pm.post_id";

		return $join;
	}

	/**
	 * Group results to avoid duplicates from postmeta joins.
	 *
	 * @param string   $groupby The GROUP BY clause.
	 * @param WP_Query $query   The WP_Query instance.
	 *
	 * @return string Modified GROUP BY clause.
	 */
	public function search_postmeta_groupby( $groupby, $query ) {
		global $wpdb;

		// Only extend search for our event post types.
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) || ! in_array( $post_type, array( WCPT_POST_TYPE_ID, WCPT_MEETUP_SLUG ), true ) ) {
			return $groupby;
		}

		// Only group when there's a search term.
		if ( ! $query->is_search() || empty( $query->get( 's' ) ) ) {
			return $groupby;
		}

		$searchable_keys = static::get_searchable_meta_keys();
		if ( empty( $searchable_keys ) ) {
			return $groupby;
		}

		// Group by post ID to avoid duplicates.
		if ( empty( $groupby ) ) {
			$groupby = "{$wpdb->posts}.ID";
		}

		return $groupby;
	}

}
