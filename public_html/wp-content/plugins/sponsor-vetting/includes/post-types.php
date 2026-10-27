<?php
/**
 * The company and sponsorship post types and their statuses.
 *
 * Registered on Central, where the records live, and on a camp site only when a request there reads or writes them
 * after `switch_to_blog()`. Every other request across the network skips them.
 *
 * @package WordCamp\Sponsor_Vetting
 */

declare( strict_types = 1 );

namespace WordCamp\Sponsor_Vetting;

defined( 'WPINC' ) || die();

add_action( 'init', __NAMESPACE__ . '\register_on_central' );
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\keep_unowned' );

/**
 * The company statuses: the vetting pipeline.
 *
 * Status slugs are global across post types, hence the prefix, and `wp_posts.post_status` holds 20 characters.
 *
 * @return string[] Labels, keyed by status.
 */
function get_company_statuses(): array {
	return array(
		'sv-needs-vetting'  => __( 'Needs Vetting', 'wordcamporg' ),
		'sv-in-progress'    => __( 'In Progress', 'wordcamporg' ),
		'sv-compliance-req' => __( 'Compliance Requested', 'wordcamporg' ),
		'sv-compliant'      => __( 'Compliant', 'wordcamporg' ),
		'sv-not-eligible'   => __( 'Not Eligible', 'wordcamporg' ),
	);
}

/**
 * The sponsorship statuses: whether an event may take the company's sponsorship.
 *
 * @return string[] Labels, keyed by status.
 */
function get_sponsorship_statuses(): array {
	return array(
		'svs-pending'      => __( 'Pending', 'wordcamporg' ),
		'svs-approved'     => __( 'Approved', 'wordcamporg' ),
		'svs-not-approved' => __( 'Not Approved', 'wordcamporg' ),
		'svs-canceled'     => __( 'Canceled', 'wordcamporg' ),
	);
}

/**
 * The statuses of a post type.
 *
 * @param string $post_type The post type.
 * @return string[] Labels, keyed by status; empty for other post types.
 */
function get_statuses( string $post_type ): array {
	return match ( $post_type ) {
		COMPANY_POST_TYPE     => get_company_statuses(),
		SPONSORSHIP_POST_TYPE => get_sponsorship_statuses(),
		default               => array(),
	};
}

/**
 * Registers the post types and statuses on Central, including for WP-CLI and cron there.
 */
function register_on_central(): void {
	if ( is_central() ) {
		register_types();
	}
}

/**
 * Registers the post types and statuses, once per request.
 *
 * Camp-site code calls this before it switches to Central: registrations are global, and `WP_Query` drops
 * statuses that aren't registered, so a query would quietly miss records.
 */
function register_types(): void {
	static $registered = false;

	if ( $registered ) {
		return;
	}

	$registered = true;

	register_post_types();
	register_statuses();
}

/**
 * Registers the company and sponsorship post types.
 */
function register_post_types(): void {
	$shared = array(
		'public'              => false,
		'show_ui'             => is_central(),
		'show_in_rest'        => false,
		'show_in_nav_menus'   => false,
		'show_in_admin_bar'   => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'rewrite'             => false,
		'query_var'           => false,
		'can_export'          => false,
		'delete_with_user'    => false,
		'supports'            => array( 'title' ),
		'map_meta_cap'        => true,
	);

	register_post_type(
		COMPANY_POST_TYPE,
		array_merge(
			$shared,
			array(
				'labels'          => array(
					'name'               => __( 'Sponsor Vetting', 'wordcamporg' ),
					'singular_name'      => __( 'Company', 'wordcamporg' ),
					'all_items'          => __( 'Companies', 'wordcamporg' ),
					'add_new_item'       => __( 'Add Company', 'wordcamporg' ),
					'edit_item'          => __( 'Vet Company', 'wordcamporg' ),
					'search_items'       => __( 'Search Companies', 'wordcamporg' ),
					'not_found'          => __( 'No companies found.', 'wordcamporg' ),
					'not_found_in_trash' => __( 'No companies found in Trash.', 'wordcamporg' ),
				),
				'menu_icon'       => 'dashicons-shield',
				'capability_type' => COMPANY_POST_TYPE,
				// Every capability, creating included, belongs to reviewers alone.
				'capabilities'    => get_capabilities( CAPABILITY ),
			)
		)
	);

	register_post_type(
		SPONSORSHIP_POST_TYPE,
		array_merge(
			$shared,
			array(
				'labels'          => array(
					'name'               => __( 'Sponsorships', 'wordcamporg' ),
					'singular_name'      => __( 'Sponsorship', 'wordcamporg' ),
					'all_items'          => __( 'Sponsorships', 'wordcamporg' ),
					'edit_item'          => __( 'Review Sponsorship', 'wordcamporg' ),
					'search_items'       => __( 'Search Sponsorships', 'wordcamporg' ),
					'not_found'          => __( 'No sponsorships found.', 'wordcamporg' ),
					'not_found_in_trash' => __( 'No sponsorships found in Trash.', 'wordcamporg' ),
				),
				'show_in_menu'    => 'edit.php?post_type=' . COMPANY_POST_TYPE,
				'capability_type' => SPONSORSHIP_POST_TYPE,
				// Sponsorships come from request_vetting() only, so each has a camp sponsor behind it.
				'capabilities'    => array_merge( get_capabilities( CAPABILITY ), array( 'create_posts' => 'do_not_allow' ) ),
			)
		)
	);
}

/**
 * Maps every primitive capability of a post type to one capability.
 *
 * @param string $capability The capability.
 * @return string[]
 */
function get_capabilities( string $capability ): array {
	return array(
		'read'                   => $capability,
		'create_posts'           => $capability,
		'edit_posts'             => $capability,
		'edit_others_posts'      => $capability,
		'edit_private_posts'     => $capability,
		'edit_published_posts'   => $capability,
		'publish_posts'          => $capability,
		'read_private_posts'     => $capability,
		'delete_posts'           => $capability,
		'delete_others_posts'    => $capability,
		'delete_private_posts'   => $capability,
		'delete_published_posts' => $capability,
	);
}

/**
 * Registers the statuses: none are public, so nothing is ever shown on the front end.
 */
function register_statuses(): void {
	/* translators: %s: Number of records. */
	$label_counts = array(
		'sv-needs-vetting'  => _n_noop( 'Needs Vetting <span class="count">(%s)</span>', 'Needs Vetting <span class="count">(%s)</span>', 'wordcamporg' ),
		'sv-in-progress'    => _n_noop( 'In Progress <span class="count">(%s)</span>', 'In Progress <span class="count">(%s)</span>', 'wordcamporg' ),
		'sv-compliance-req' => _n_noop( 'Compliance Requested <span class="count">(%s)</span>', 'Compliance Requested <span class="count">(%s)</span>', 'wordcamporg' ),
		'sv-compliant'      => _n_noop( 'Compliant <span class="count">(%s)</span>', 'Compliant <span class="count">(%s)</span>', 'wordcamporg' ),
		'sv-not-eligible'   => _n_noop( 'Not Eligible <span class="count">(%s)</span>', 'Not Eligible <span class="count">(%s)</span>', 'wordcamporg' ),
		'svs-pending'       => _n_noop( 'Pending <span class="count">(%s)</span>', 'Pending <span class="count">(%s)</span>', 'wordcamporg' ),
		'svs-approved'      => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved <span class="count">(%s)</span>', 'wordcamporg' ),
		'svs-not-approved'  => _n_noop( 'Not Approved <span class="count">(%s)</span>', 'Not Approved <span class="count">(%s)</span>', 'wordcamporg' ),
		'svs-canceled'      => _n_noop( 'Canceled <span class="count">(%s)</span>', 'Canceled <span class="count">(%s)</span>', 'wordcamporg' ),
	);

	foreach ( array_merge( get_company_statuses(), get_sponsorship_statuses() ) as $status => $label ) {
		register_post_status(
			$status,
			array(
				'label'                     => $label,
				'label_count'               => $label_counts[ $status ],
				'public'                    => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);
	}
}

/**
 * Keeps companies and sponsorships unowned, which core's edit screen would otherwise hand to whoever saves one.
 *
 * Ownership matters beyond the list screens: deleting a user from the network deletes every post they authored.
 *
 * @param array $data Post data about to be saved.
 * @return array
 */
function keep_unowned( array $data ): array {
	if ( in_array( $data['post_type'], array( COMPANY_POST_TYPE, SPONSORSHIP_POST_TYPE ), true ) ) {
		$data['post_author'] = 0;
	}

	return $data;
}
