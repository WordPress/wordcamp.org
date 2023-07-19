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
		add_action( 'wp_insert_post_data',             array( $this, 'set_scheduled_date'                ) );
		add_filter( 'wordcamp_rewrite_rules',          array( $this, 'wordcamp_rewrite_rules'            ) );
		add_filter( 'query_vars',                      array( $this, 'query_vars'                        ) );
		add_filter( 'rest_wordcamp_collection_params', array( $this, 'set_rest_post_status_default'      ) );
		add_action( 'rest_api_init',                   array( $this, 'register_rest_public_fields'       ) );
		add_action( 'init',                            array( $this, 'register_post_capabilities' ) );
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
			'wcpt-more-info-reque' => _x( 'More Info Requested',                         'wordcamp status', 'wordcamporg' ),
			'wcpt-interview-sched' => _x( 'Interview/Orientation Scheduled',             'wordcamp status', 'wordcamporg' ),
			'wcpt-rejected'        => _x( 'Declined',                                    'wordcamp status', 'wordcamporg' ),
			'wcpt-cancelled'       => _x( 'Cancelled',                                   'wordcamp status', 'wordcamporg' ),
			'wcpt-approved-pre-pl' => _x( 'Approved for Pre-Planning Pending Agreement', 'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-email'     => _x( 'Needs E-mail Address',                        'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-site'      => _x( 'Needs Site',                                  'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-polldaddy' => _x( 'Needs Crowdsignal Account',                   'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-mentor'    => _x( 'Needs Mentor',                                'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-pre-plann' => _x( 'Needs to be Added to Pre-Planning Schedule',  'wordcamp status', 'wordcamporg' ),
			'wcpt-pre-planning'    => _x( 'In Pre-Planning',                             'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-budget-re' => _x( 'Needs Budget Review',                         'wordcamp status', 'wordcamporg' ),
			'wcpt-budget-rev-sche' => _x( 'Budget Review Scheduled',                     'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-contract'  => _x( 'Needs Contract to be Signed',                 'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-fill-list' => _x( 'Needs to Fill Out WordCamp Listing',          'wordcamp status', 'wordcamporg' ),
			'wcpt-needs-schedule'  => _x( 'Needs to be Added to Official Schedule',      'wordcamp status', 'wordcamporg' ),
			'wcpt-scheduled'       => _x( 'WordCamp Scheduled',                          'wordcamp status', 'wordcamporg' ),
			'wcpt-closed'          => _x( 'WordCamp Closed',                             'wordcamp status', 'wordcamporg' ),
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
			self::get_public_post_statuses()
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
				'wcpt-needs-mentor',
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
			'wcpt-needs-polldaddy' => 'Site created',
			'wcpt-needs-mentor'    => 'Crowdsignal account created',
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
			'wcpt-needs-vetting'   => array( 'wcpt-needs-orientati', 'wcpt-more-info-reque' ),
			'wcpt-needs-orientati' => array( 'wcpt-needs-vetting', 'wcpt-interview-sched' ),
			'wcpt-more-info-reque' => array(),  // Allowed from any status, see below
			'wcpt-interview-sched' => array( 'wcpt-needs-orientati', 'wcpt-approved-pre-pl' ),
			'wcpt-rejected'        => array(),
			'wcpt-cancelled'       => array(),  // Allowed from any status, see below
			'wcpt-approved-pre-pl' => array( 'wcpt-interview-sched', 'wcpt-needs-email' ),
			'wcpt-needs-email'     => array( 'wcpt-approved-pre-pl', 'wcpt-needs-site' ),
			'wcpt-needs-site'      => array( 'wcpt-needs-email', 'wcpt-needs-polldaddy' ),
			'wcpt-needs-polldaddy' => array( 'wcpt-needs-site', 'wcpt-needs-mentor' ),
			'wcpt-needs-mentor'    => array( 'wcpt-needs-polldaddy', 'wcpt-needs-pre-plann' ),
			'wcpt-needs-pre-plann' => array( 'wcpt-needs-mentor', 'wcpt-pre-planning' ),
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
			'Virtual event only',
			'Host region',
			'Venue Name',
			'Physical Address',
			'Maximum Capacity',
			'Available Rooms',
			'Website URL',
			'Exhibition Space Available',
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
					'description' => __( 'The start time of the first session of WordCamp, when WordCamp content will begin.', 'wordcamporg' ),
				),
				'get_callback' => function( $object, $field_name ) {
					// Short out if the event is not scheduled.
					if ( 'wcpt-scheduled' !== $object['status'] ) {
						return 0;
					}
					$site_id = get_post_meta( $object['id'], '_site_id', true );
					switch_to_blog( $site_id );
					$sessions = get_posts( array(
						'post_type'      => 'wcb_session',
						'posts_per_page' => 1,
						'meta_key'       => '_wcpt_session_time',
						'orderby'        => 'meta_value_num',
						'order'          => 'asc',
					) );
					if ( count( $sessions ) < 1 ) {
						restore_current_blog();
						return 0;
					}
					$session = $sessions[0];
					$value = absint( get_post_meta( $session->ID, '_wcpt_session_time', true ) );

					restore_current_blog();
					return $value;
				},
			)
		);
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
