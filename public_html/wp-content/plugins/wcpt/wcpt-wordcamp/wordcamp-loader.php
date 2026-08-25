<?php

define( 'WCPT_POST_TYPE_ID',   'wordcamp'           );
define( 'WCPT_YEAR_ID',        'wordcamp_year'      );
define( 'WCPT_SLUG',           'wordcamps'          );
define( 'WCPT_DEFAULT_STATUS', 'wcpt-needs-vetting' );
define( 'WCPT_FINAL_STATUS',   'wcpt-closed'        );

/**
 * WordCamp_Loader
 *
 * @package
 * @subpackage Loader
 * @since WordCamp Post Type (0.1)
 */
class WordCamp_Loader extends Event_Loader {

	/**
	 * The main WordCamp Post Type loader
	 */
	function __construct() {
		parent::__construct();
		add_action( 'wp_insert_post_data',             array( $this, 'set_scheduled_date' ), 20 );
		add_filter( 'wordcamp_rewrite_rules',          array( $this, 'wordcamp_rewrite_rules'            ) );
		add_filter( 'query_vars',                      array( $this, 'query_vars'                        ) );
		add_filter( 'rest_wordcamp_collection_params', array( $this, 'set_rest_post_status_default'      ) );
		add_action( 'rest_api_init',                   array( $this, 'register_rest_public_fields'       ) );
		add_action( 'init',                            array( $this, 'register_post_capabilities' ) );

		// Not from `includes()`: that is hooked to `plugins_loaded`, and the Jetpack form
		// bridge requires this loader from a form handler long after that fires.
		WordCamp_Status_Guard::init();
	}

	/**
	 * includes ()
	 *
	 * Include required files
	 *
	 * @uses is_admin If in WordPress admin, load additional file
	 */
	function includes() {
		// Load the files
		require_once WCPT_DIR . 'wcpt-wordcamp/class-wp-rest-wordcamps-controller.php';
		require_once WCPT_DIR . 'wcpt-wordcamp/wordcamp-template.php';

		// MCP vetting abilities (REST, admin, and WP-CLI only).
		//
		// The wordpress/mcp-adapter classes are provided by the root Composer
		// autoloader, which mu-plugins/load-other-mu-plugins.php loads on every
		// request, so there is nothing to require here. The MCP server is only
		// reached over the REST transport, and ability discovery only matters in
		// the admin and to WP-CLI, so the bootstrap is skipped on front-end requests.
		require_once WCPT_DIR . 'wcpt-wordcamp/class-wcpt-vetting-abilities.php';

		$is_rest_request = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( ! empty( $_SERVER['REQUEST_URI'] )
				&& false !== strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), '/' . rest_get_url_prefix() . '/' ) );

		if ( is_admin() || $is_rest_request || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			WCPT_Vetting_Abilities::init();
		}

		// Quick admin check and load if needed
		if ( is_admin() ) {
			require_once WCPT_DIR . 'wcpt-wordcamp/wordcamp-admin.php';
		}

		require_once WCPT_DIR . 'wcpt-wordcamp/wordcamp-new-site.php';

		$GLOBALS['wordcamp_new_site'] = new WordCamp_New_Site();
	}

	/**
	 * register_post_type ()
	 *
	 * Setup the post types and taxonomies
	 *
	 * @todo Finish up the post type admin area with messages, columns, etc...*
	 */
	function register_post_types() {
		// WordCamp post type labels
		$wcpt_labels = array(
			'name'                  => __( 'WordCamps',                   'wordcamporg' ),
			'singular_name'         => __( 'WordCamp',                    'wordcamporg' ),
			'add_new'               => __( 'Add New',                     'wordcamporg' ),
			'add_new_item'          => __( 'Create New WordCamp',         'wordcamporg' ),
			'edit'                  => __( 'Edit',                        'wordcamporg' ),
			'edit_item'             => __( 'Edit WordCamp',               'wordcamporg' ),
			'new_item'              => __( 'New WordCamp',                'wordcamporg' ),
			'view'                  => __( 'View WordCamp',               'wordcamporg' ),
			'view_item'             => __( 'View WordCamp',               'wordcamporg' ),
			'search_items'          => __( 'Search WordCamps',            'wordcamporg' ),
			'not_found'             => __( 'No WordCamps found',          'wordcamporg' ),
			'not_found_in_trash'    => __( 'No WordCamps found in Trash', 'wordcamporg' ),
			'parent_item_colon'     => __( 'Parent WordCamp:',            'wordcamporg' ),
		);

		// WordCamp post type rewrite
		$wcpt_rewrite = array(
			'slug'        => WCPT_SLUG,
			'with_front'  => false,
		);

		// WordCamp post type supports
		$wcpt_supports = array(
			'title',
			'editor',
			'thumbnail',
			'revisions',
			'author',
		);

		// Register WordCamp post type
		register_post_type( WCPT_POST_TYPE_ID, array(
			'labels'                => $wcpt_labels,
			'rewrite'               => $wcpt_rewrite,
			'supports'              => $wcpt_supports,
			'menu_position'         => '100',
			'public'                => true,
			'show_ui'               => true,
			'can_export'            => true,
			'capability_type'       => WCPT_POST_TYPE_ID,
			'capabilities'          => array(
				// `read` and `edit_posts` are intentionally allowed, so organizers can edit their own posts (but not others').
				'create_posts'           => 'wordcamp_wrangle_wordcamps',
				'delete_posts'           => 'wordcamp_wrangle_wordcamps',
				'delete_others_posts'    => 'wordcamp_wrangle_wordcamps',
				'delete_private_posts'   => 'wordcamp_wrangle_wordcamps',
				'delete_published_posts' => 'wordcamp_wrangle_wordcamps',
				'edit_others_posts'      => 'wordcamp_wrangle_wordcamps',
				'edit_private_posts'     => 'wordcamp_wrangle_wordcamps',
				'edit_published_posts'   => 'wordcamp_wrangle_wordcamps',
				'publish_posts'          => 'wordcamp_wrangle_wordcamps',
				'read_private_posts'     => 'wordcamp_wrangle_wordcamps',
			),
			'map_meta_cap'          => true,
			'hierarchical'          => false,
			'has_archive'           => true,
			'query_var'             => true,
			'menu_icon'             => 'dashicons-wordpress',
			'show_in_rest'          => true,
			'rest_base'             => 'wordcamps',
			'rest_controller_class' => 'WordCamp_REST_WordCamps_Controller',
		) );
	}

	/**
	 * Allow some site roles to see WordCamp posts.
	 */
	public function register_post_capabilities() {
		$roles = array(
			'contributor',
			'author',
			'editor',
			'administrator',
		);

		foreach ( $roles as $role ) {
			get_role( $role )->add_cap( 'edit_wordcamps' );
		}
	}



	/**
	 * Save the date that the camp was moved on to the official schedule
	 *
	 * It's stored in the `menu_order` field because the purpose of storing it is so we can sort the archives
	 * by this timestamp. See WordCamp_Loader::query_public_statuses_on_archives().
	 *
	 * Sorting by meta fields would be significantly slower, and the `menu_order` field is a good candidate for
	 * re-purposing because it makes semantic sense and isn't being used.
	 *
	 * @param array $post_data
	 *
	 * @return array
	 */
	public function set_scheduled_date( $post_data ) {
		// Priority 20, so both `WordCamp_Status_Guard::enforce_post_status()` and
		// `WordCamp_Admin::require_complete_meta_to_publish_wordcamp()` have had their say.
		// The stamp is written once and never revised, so a rejected write must not reach it.
		if ( 'wcpt-scheduled' !== $post_data['post_status'] || WCPT_POST_TYPE_ID != $post_data['post_type'] ) {
			return $post_data;
		}

		// Don't overwrite the original timestamp every time the post is updated
		if ( ! empty ( $post_data['menu_order'] ) ) {
			return $post_data;
		}

		$post_data['menu_order'] = time();

		return $post_data;
	}

	/**
	 * Get WordCamp post statuses.
	 *
	 * @return array
	 */
	public static function get_post_statuses() {
		return array(
			'wcpt-needs-vetting'   => _x( 'Needs Vetting',                               'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-orientati' => _x( 'Needs Orientation/Interview',                 'wordcamp status', 'wordcamporg' ),
			'wcpt-more-info-reque' => _x( 'On Hold',                                     'wordcamp status', 'wordcamporg' ),
			'wcpt-interview-sched' => _x( 'Interview/Orientation Scheduled',             'wordcamp status', 'wordcamporg' ),
			'wcpt-rejected'        => _x( 'Declined',                                    'wordcamp status', 'wordcamporg' ),
			'wcpt-cancelled'       => _x( 'Cancelled',                                   'wordcamp status', 'wordcamporg' ),
			'wcpt-approved-pre-pl' => _x( 'Approved for Pre-Planning Pending Agreement', 'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-email'     => _x( 'Needs E-mail Address',                        'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-site'      => _x( 'Needs Site',                                  'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-pre-plann' => _x( 'Needs to be Added to Pre-Planning Schedule',  'wordcamp status', 'wordcamporg' ),
			'wcpt-pre-planning'    => _x( 'In Pre-Planning',                             'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-budget-re' => _x( 'Needs Budget Review',                         'wordcamp status', 'wordcamporg' ),
			'wcpt-budget-rev-sche' => _x( 'Budget Review Scheduled',                     'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-contract'  => _x( 'Needs Contract to be Signed',                 'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-fill-list' => _x( 'Needs to Fill Out WordCamp Listing',          'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-schedule'  => _x( 'Needs to be Added to Official Schedule',      'wordcamp status', 'wordcamporg' ),
			'wcpt-scheduled'       => _x( 'WordCamp Scheduled',                          'wordcamp status', 'wordcamporg' ),
			'wcpt-closed'          => _x( 'WordCamp Closed',                             'wordcamp status', 'wordcamporg' ),
			// CC-exclusive statuses. Hidden from non-CC dropdowns by WordCamp_Admin::get_post_statuses(),
			// but registered here so register_post_statuses() in Event_Loader can call register_post_status()
			// for them and WordPress recognises them as valid post statuses.
			'wcpt-needs-action'    => _x( 'Needs Action', 'campus connect status', 'wordcamporg' ),
		);
	}

	/**
	 * Get post statuses for WordCamps on schedule.
	 *
	 * @return array Post status names.
	 */
	public static function get_public_post_statuses() {
		return array(
			'wcpt-scheduled',
			'wcpt-closed',
		);
	}

	/**
	 * Statuses whose single view resolves for everyone.
	 *
	 * Wider than `get_public_post_statuses()` because two groups aren't listed on the
	 * schedule but are still reached by URL:
	 *
	 * - `wcpt-cancelled` is served over the v2 REST API to unauthenticated clients, so
	 *   Official WordPress Events can drop cancelled camps from the events widget. That
	 *   works via the `public` flag, through the parent `check_read_permission()`.
	 * - Pre-planning camps usually have no site of their own yet, so the map markers link
	 *   their Central permalink through `WordCamp_Central_Theme::get_best_wordcamp_url()`.
	 *
	 * Both are addressable, but each needs its own change first. Until then they keep
	 * resolving.
	 *
	 * @return array Post status names.
	 */
	public static function get_publicly_viewable_post_statuses() {
		return array_merge(
			self::get_public_post_statuses(),
			array( 'wcpt-cancelled' ),
			self::get_pre_planning_post_statuses()
		);
	}

	/**
	 * Get post statuses for WordCamps on pre-planning schedule.
	 *
	 * @return array Post status names.
	 */
	public static function get_pre_planning_post_statuses() {
		return array(
			'wcpt-pre-planning',
			'wcpt-needs-budget-re',
			'wcpt-budget-rev-sche',
			'wcpt-needs-contract',
			'wcpt-needs-fill-list',
			'wcpt-needs-schedule',
		);
	}

	/**
	 * Get the statuses where camps should have a mentor
	 *
	 * @return array
	 */
	public static function get_mentored_post_statuses() {
		return array_merge(
			array( 'wcpt-needs-pre-plann' ),
			self::get_pre_planning_post_statuses(),
			self::get_public_post_statuses(),
			self::get_active_wordcamp_statuses()
		);
	}

	/**
	 * Get the statuses for camps that are currently active
	 *
	 * @return array
	 */
	public static function get_active_wordcamp_statuses() {
		return array_merge(
			array(
				'wcpt-approved-pre-pl',
				'wcpt-needs-email',
				'wcpt-needs-site',
				'wcpt-needs-polldaddy',
				'wcpt-needs-pre-plann',
			),
			self::get_pre_planning_post_statuses(),
			array( 'wcpt-scheduled' )
		);
	}

	/**
	 * Get all the status that occur after a camp has a signed contract.
	 *
	 * @return array
	 */
	public static function get_after_contract_statuses() {
		return array(
			'wcpt-needs-fill-list',
			'wcpt-needs-schedule',
			'wcpt-scheduled',
			'wcpt-closed',
		);
	}

	/**
	 * Get the milestones that correspond to each status
	 *
	 * @return array
	 */
	public static function map_statuses_to_milestones() {
		$milestones = array(
			'wcpt-needs-vetting'   => 'Application received',
			'wcpt-needs-orientati' => 'Application vetted',
			'wcpt-more-info-reque' => 'Application vetted',
			'wcpt-interview-sched' => 'Interview scheduled',
			'wcpt-rejected'        => 'Sent response',
			'wcpt-cancelled'       => 'WordCamp cancelled',
			'wcpt-approved-pre-pl' => 'Orientation/interview held',
			'wcpt-needs-email'     => 'Organizer agreement signed',
			'wcpt-needs-site'      => 'Email address/fwd set up',
			'wcpt-needs-mentor'    => 'Site created',
			'wcpt-needs-pre-plann' => 'Mentor assigned',
			'wcpt-pre-planning'    => 'Added to pre-planning schedule',
			'wcpt-needs-budget-re' => 'Budget review requested',
			'wcpt-budget-rev-sche' => 'Budget review scheduled',
			'wcpt-needs-contract'  => 'Budget approved',
			'wcpt-needs-fill-list' => 'Contract signed',
			'wcpt-needs-schedule'  => 'WordCamp listing filled out',
			'wcpt-scheduled'       => 'WordCamp added to official schedule',
			'wcpt-closed'          => 'Debrief held',
		);

		return $milestones;
	}

	/**
	 * Return valid transitions given a post status.
	 *
	 * @param string $status Current status.
	 *
	 * @return array Valid transitions.
	 */
	public static function get_valid_status_transitions( $status ) {
		$transitions = array(
			'wcpt-needs-vetting'   => array( 'wcpt-needs-orientati', 'wcpt-more-info-reque', 'wcpt-rejected' ),
			'wcpt-needs-orientati' => array( 'wcpt-needs-vetting', 'wcpt-interview-sched' ),
			'wcpt-more-info-reque' => array(),  // Allowed from any status, see below
			'wcpt-interview-sched' => array( 'wcpt-needs-orientati', 'wcpt-approved-pre-pl' ),
			'wcpt-rejected'        => array(),
			'wcpt-cancelled'       => array(),  // Allowed from any status, see below
			'wcpt-approved-pre-pl' => array( 'wcpt-interview-sched', 'wcpt-needs-email', 'wcpt-needs-site' ),
			'wcpt-needs-email'     => array( 'wcpt-approved-pre-pl', 'wcpt-needs-site' ),
			'wcpt-needs-site'      => array( 'wcpt-needs-email', 'wcpt-needs-polldaddy', 'wcpt-needs-pre-plann' ),
			'wcpt-needs-polldaddy' => array( 'wcpt-needs-site', 'wcpt-needs-pre-plann' ),
			'wcpt-needs-pre-plann' => array( 'wcpt-needs-polldaddy', 'wcpt-pre-planning' ),
			'wcpt-pre-planning'    => array( 'wcpt-needs-pre-plann', 'wcpt-needs-budget-re' ),
			'wcpt-needs-budget-re' => array( 'wcpt-pre-planning', 'wcpt-budget-rev-sche' ),
			'wcpt-budget-rev-sche' => array( 'wcpt-needs-budget-re', 'wcpt-needs-contract' ),
			'wcpt-needs-contract'  => array( 'wcpt-budget-rev-sche', 'wcpt-needs-fill-list' ),
			'wcpt-needs-fill-list' => array( 'wcpt-needs-contract', 'wcpt-needs-schedule' ),
			'wcpt-needs-schedule'  => array( 'wcpt-needs-fill-list', 'wcpt-scheduled' ),
			'wcpt-scheduled'       => array( 'wcpt-needs-schedule' ),
			'wcpt-closed'          => array(),
		);

		// Cancelled and More Info Requested can be switched to from any status.
		foreach ( array_keys( $transitions ) as $key ) {
			$transitions[ $key ][] = 'wcpt-more-info-reque';
			$transitions[ $key ][] = 'wcpt-cancelled';
		}

		// Any status can be switched to from More Info Requested and Cancelled.
		foreach ( array( 'wcpt-more-info-reque', 'wcpt-cancelled' ) as $key ) {
			$transitions[ $key ] = array_keys( $transitions );
		}

		if ( empty( $transitions[ $status ] ) ) {
			return array( 'wcpt-needs-vetting' );
		}

		return $transitions[ $status ];
	}

	/**
	 * Meta field keys that are safe to publicly expose in the v2 REST API.
	 *
	 * @return array
	 */
	public static function get_public_meta_keys() {
		require_once __DIR__ . '/wordcamp-admin.php';

		$safe_fields = array(
			// Sourced from wcorg_json_expose_whitelisted_meta_data()
			'Start Date (YYYY-mm-dd)',
			'End Date (YYYY-mm-dd)',
			'Event Timezone',
			'Location',
			'URL',
			'Twitter',
			'WordCamp Hashtag',
			'Number of Anticipated Attendees',
			'Organizer Name',
			'WordPress.org Username',
			'A/V Wrangler Name',
			'Virtual event only',
			'Host region',
			'Venue Name',
			'Physical Address',
			'Maximum Capacity',
			'Available Rooms',
			'Website URL',
			'Exhibition Space Available',
			'Hide from Event Feeds',
		);

		return array_merge(
			$safe_fields,
			WordCamp_Admin::get_venue_address_meta_keys()
		);
	}

	/**
	 * Register fields to publicly expose in the v2 REST API
	 *
	 * @hooked action rest_api_init
	 */
	public function register_rest_public_fields() {
		$keys = self::get_public_meta_keys();

		foreach ( $keys as $key ) {
			register_rest_field(
				'wordcamp',
				$key,
				array(
					'get_callback' => function( $object, $field_name ) {
						return get_post_meta( $object['id'], $field_name, true );
					},
				)
			);
		}

		register_rest_field(
			'wordcamp',
			'session_start_time',
			array(
				'schema'       => array(
					'type'        => 'string',
					'description' => __( 'The start time of the first session of WordCamp, when WordCamp content will begin. This is a true Unix timestamp in UTC, not local time.', 'wordcamporg' ),
				),
				'get_callback' => function( $object, $field_name ) {
					// Short out if the event is not scheduled.
					if ( 'wcpt-scheduled' !== $object['status'] ) {
						return 0;
					}

					return get_first_session_utc_start_time( $object['id'] );
				},
			)
		);
	}

	/**
	 * Get the canonical Campus Connect status list (slug => label).
	 *
	 * This is the authoritative set of the nine statuses available to Campus Connect
	 * posts. It is the source for the CC status dropdown (via
	 * WordCamp_Admin::get_post_statuses()) and for the CC-specific labels used in
	 * status-change log entries. Note that wcpt-needs-action is also registered
	 * globally in get_post_statuses() above, so WordPress treats it as a valid post
	 * status for every subtype.
	 *
	 * @return array Associative array of status slug => human-readable label.
	 */
	public static function get_campus_connect_statuses() {
		return array(
			'wcpt-needs-vetting'   => _x( 'Needs Vetting',             'campus connect status', 'wordcamporg' ),
			'wcpt-needs-action'    => _x( 'Needs Action',              'campus connect status', 'wordcamporg' ),
			'wcpt-needs-orientati' => _x( 'Needs Orientation',         'campus connect status', 'wordcamporg' ),
			'wcpt-more-info-reque' => _x( 'On Hold',                   'campus connect status', 'wordcamporg' ),
			'wcpt-approved-pre-pl' => _x( 'Approved For Pre-Planning', 'campus connect status', 'wordcamporg' ),
			'wcpt-scheduled'       => _x( 'WordCamp Scheduled',        'campus connect status', 'wordcamporg' ),
			'wcpt-closed'          => _x( 'WordCamp Closed',           'campus connect status', 'wordcamporg' ),
			'wcpt-rejected'        => _x( 'Declined',                  'campus connect status', 'wordcamporg' ),
			'wcpt-cancelled'       => _x( 'Cancelled',                 'campus connect status', 'wordcamporg' ),
		);
	}

	/**
	 * Return valid status transitions for a Campus Connect post.
	 *
	 * Transition map (definitive spec):
	 *   Needs Vetting        → Needs Action, On Hold, Approved For Pre-Planning,
	 *                          Declined, Cancelled
	 *   Needs Action         → Needs Orientation, On Hold, Approved For Pre-Planning,
	 *                          WordCamp Scheduled, Declined, Cancelled
	 *   Needs Orientation    → On Hold, Approved For Pre-Planning,
	 *                          WordCamp Scheduled, Declined, Cancelled
	 *   Approved For Pre-Planning → WordCamp Scheduled, Declined, Cancelled, On Hold
	 *   WordCamp Scheduled   → WordCamp Closed, Declined, Cancelled, On Hold
	 *   WordCamp Closed      → Declined, Cancelled, On Hold
	 *   Declined / Cancelled / On Hold → any CC status
	 *
	 * @param string $status Current status slug.
	 * @return array Array of valid next-status slugs.
	 */
	public static function get_campus_connect_status_transitions( $status ) {
		$all_cc = array_keys( self::get_campus_connect_statuses() );

		$transitions = array(
			'wcpt-needs-vetting'   => array( 'wcpt-needs-action', 'wcpt-more-info-reque', 'wcpt-approved-pre-pl', 'wcpt-rejected', 'wcpt-cancelled' ),
			'wcpt-needs-action'    => array( 'wcpt-needs-orientati', 'wcpt-more-info-reque', 'wcpt-approved-pre-pl', 'wcpt-scheduled', 'wcpt-rejected', 'wcpt-cancelled' ),
			'wcpt-needs-orientati' => array( 'wcpt-more-info-reque', 'wcpt-approved-pre-pl', 'wcpt-scheduled', 'wcpt-rejected', 'wcpt-cancelled' ),
			'wcpt-approved-pre-pl' => array( 'wcpt-scheduled', 'wcpt-rejected', 'wcpt-cancelled', 'wcpt-more-info-reque' ),
			'wcpt-scheduled'       => array( 'wcpt-closed', 'wcpt-rejected', 'wcpt-cancelled', 'wcpt-more-info-reque' ),
			'wcpt-closed'          => array( 'wcpt-rejected', 'wcpt-cancelled', 'wcpt-more-info-reque' ),
			'wcpt-rejected'        => $all_cc,
			'wcpt-cancelled'       => $all_cc,
			'wcpt-more-info-reque' => $all_cc,
		);

		// For an unexpected/mid-transition status, allow no transitions rather than
		// defaulting to a real status: the metabox keeps the current status selectable
		// (as a disabled option) so it is never silently mutated.
		return $transitions[ $status ] ?? array();
	}

	/**
	 * Change the default status used for the WordCamp CPT in the v2 REST API.
	 *
	 * @hooked filter rest_wordcamp_collection_params
	 *
	 * @param array $query_params
	 *
	 * @return array
	 */
	public function set_rest_post_status_default( $query_params ) {
		if ( isset( $query_params['status'] ) ) {
			$query_params['status']['default'] = self::get_public_post_statuses();
		}

		return $query_params;
	}

	/**
	 * Additional rules for the WordCamp post type.
	 *
	 * @param array $rules Rewrite rules.
	 *
	 * @return array The final rewrite rules.
	 */
	public function wordcamp_rewrite_rules( $rules ) {
		$rules = array( 'wordcamps/([^/]+)/info/?$' => 'index.php?wordcamp=$matches[1]&wcorg-wordcamp-info=1' ) + $rules;
		return $rules;
	}

	/**
	 * Additional query vars.
	 *
	 * @param array $vars Query vars.
	 *
	 * @return array Resulting query vars.
	 */
	public function query_vars( $vars ) {
		$vars[] = 'wcorg-wordcamp-info';
		return $vars;
	}
}
