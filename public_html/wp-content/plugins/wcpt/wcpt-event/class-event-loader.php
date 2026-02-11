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
	 * Get the post type ID for this event type.
	 *
	 * @return string
	 */
	abstract public static function post_type();

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

		if ( ! is_admin() ) {
			return $search;
		}

		// Only extend search for this specific event post type.
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) || static::post_type() !== $post_type ) {
			return $search;
		}

		// Only extend search when there's a search term.
		if ( empty( $search ) || ! $query->is_search() ) {
			return $search;
		}

		$search_term = $query->get( 's' );
		if ( empty( $search_term ) ) {
			return $search;
		}

		$searchable_keys = static::get_searchable_meta_keys();

		// Build meta search conditions.
		$like_term   = '%' . $wpdb->esc_like( $search_term ) . '%';
		$prepare_args = array_merge( $searchable_keys, array( $like_term ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- All placeholders are generated and filled dynamically.
		$meta_search = $wpdb->prepare(
			'(pm.meta_key IN (' . implode( ', ', array_fill( 0, count( $searchable_keys ), '%s' ) ) . ') AND pm.meta_value LIKE %s)',
			$prepare_args
		);

		// WordPress $search format from WP_Query::parse_search() is:
		// " AND (((post_title LIKE ...) OR (post_content LIKE ...))) AND (post_password = '')".
		//
		// The first AND group contains the content-matching conditions. We need to add our
		// meta OR inside that first group only, preserving subsequent AND conditions like
		// the post_password check as separate top-level conditions.
		//
		// Find the position after the first AND group by counting balanced parentheses.
		$first_paren = strpos( $search, '(' );
		if ( false === $first_paren ) {
			return $search;
		}

		$depth         = 0;
		$end           = false;
		$search_length = strlen( $search );
		for ( $i = $first_paren; $i < $search_length; $i++ ) {
			if ( '(' === $search[ $i ] ) {
				$depth++;
			} elseif ( ')' === $search[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}

		if ( false !== $end ) {
			// Insert the meta search OR before the closing paren of the first group.
			$search = substr( $search, 0, $end ) . ' OR ' . $meta_search . substr( $search, $end );
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

		if ( ! is_admin() ) {
			return $join;
		}

		// Only extend search for this specific event post type.
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) || static::post_type() !== $post_type ) {
			return $join;
		}

		// Only join when there's a search term.
		if ( ! $query->is_search() || empty( $query->get( 's' ) ) ) {
			return $join;
		}

		// Check if the join already exists to prevent duplicates.
		if ( strpos( $join, "{$wpdb->postmeta} AS pm" ) !== false ) {
			return $join;
		}

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

		if ( ! is_admin() ) {
			return $groupby;
		}

		// Only extend search for this specific event post type.
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) || static::post_type() !== $post_type ) {
			return $groupby;
		}

		// Only group when there's a search term.
		if ( ! $query->is_search() || empty( $query->get( 's' ) ) ) {
			return $groupby;
		}

		// Ensure grouping by post ID to avoid duplicates from the postmeta JOIN.
		$post_id_group = "{$wpdb->posts}.ID";

		if ( empty( $groupby ) ) {
			$groupby = $post_id_group;
		} elseif ( strpos( $groupby, $post_id_group ) === false ) {
			$groupby .= ", {$post_id_group}";
		}

		return $groupby;
	}

}
