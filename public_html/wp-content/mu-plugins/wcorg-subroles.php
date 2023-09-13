<?php
/**
 * Define subroles and capabilities that can be assigned to specific WordCamp users.
 *
 * @package WordCamp\SubRoles
 */

namespace WordCamp\SubRoles;
use WP_User;

defined( 'WPINC' ) || die();

/**
 * Get any subroles assigned to a specific user.
 *
 * @global array $wcorg_subroles
 *
 * @param int $user_id The ID of the user to retrieve subroles for.
 *
 * @return array A list of subrole strings.
 */
function get_user_subroles( $user_id ) {
	global $wcorg_subroles;

	if ( is_array( $wcorg_subroles ) && isset( $wcorg_subroles[ $user_id ] ) ) {
		return $wcorg_subroles[ $user_id ];
	}

	return array();
}

/**
 * Add capabilities to a user depending on their subroles.
 *
 * @param array   $allcaps The original list of caps for the given user.
 * @param array   $caps    Unused.
 * @param array   $args    Unused.
 * @param WP_User $user    The user object.
 *
 * @return array The modified list of caps for the given user.
 */
function add_subrole_caps( $allcaps, $caps, $args, $user ) {
	$subroles = get_user_subroles( $user->ID );

	if ( empty( $subroles ) ) {
		return $allcaps;
	}

	foreach ( $subroles as $subrole ) {
		$newcaps = array();

		switch ( $subrole ) {
			/**
			 * Mentor Manager
			 *
			 * - Access and use the WordCamp Mentors Dashboard screen on Central.
			 * - Edit `wordcamp` posts on Central.
			 * - Use "WordCamp Post" link in Admin Bar on all sites (sse `add_wcpt_cross_link()`)
			 */
			case 'mentor_manager':
				$newcaps = array(
					'read'                       => true, // Access to wp-admin.
					'wordcamp_manage_mentors'    => true,
					'wordcamp_wrangle_wordcamps' => true,
				);
				break;

			/**
			 * WordCamp Wrangler
			 *
			 * - Edit `wordcamp` posts on Central.
			 * - Use "WordCamp Post" link in Admin Bar on all sites (sse `add_wcpt_cross_link()`)
			 */
			case 'wordcamp_wrangler':
				$newcaps = array(
					'read'                       => true, // Access to wp-admin.
					'wordcamp_wrangle_wordcamps' => true,
				);
				break;

			/**
			 * Meetup Wrangler
			 *
			 * - Edit `wp_meetup` posts on Central.
			 */
			case 'meetup_wrangler':
				$newcaps = array(
					'read'                     => true, // Access to wp-admin.
					'wordcamp_wrangle_meetups' => true,
				);
				break;

			/**
			 * Report Viewer
			 *
			 * - View private `wordcamp` reports on Central.
			 */
			case 'report_viewer':
				// These capabilities only apply on central.wordcamp.org.
				if ( WORDCAMP_ROOT_BLOG_ID === get_current_blog_id() ) {
					$newcaps = array(
						'read'                  => true, // Access to wp-admin.
						'view_wordcamp_reports' => true,
					);
				}
				break;
		}

		$allcaps = array_merge( $allcaps, $newcaps );
	}

	return $allcaps;
}

add_filter( 'user_has_cap', __NAMESPACE__ . '\add_subrole_caps', 10, 4 );

/**
 * Capability mapping for subroles.
 *
 * @param array  $primitive_caps The original list of primitive caps mapped to the given meta cap.
 * @param string $meta_cap       The meta cap in question.
 * @param int    $user_id        The ID of the user.
 * @param array  $args           Additional information for the cap.
 *
 * @return array The modified list of primitive caps mapped to the given meta cap.
 */
function map_subrole_caps( $primitive_caps, $meta_cap, $user_id, $args ) {
	$required_caps = array();
	$current_user  = get_user_by( 'id', $user_id );

	switch ( $meta_cap ) {
		case 'wordcamp_manage_mentors':
		case 'wordcamp_wrangle_wordcamps':
		case 'wordcamp_wrangle_meetups':
			$required_caps[] = $meta_cap;
			break;

		// Allow WordCamp Wranglers to edit WordCamp posts.
		case 'edit_wordcamps':
		case 'edit_published_wordcamps':
		case 'edit_wordcamp':
		case 'edit_others_wordcamps':
			if ( $current_user && $current_user->has_cap( 'wordcamp_wrangle_wordcamps' ) ) {
				$required_caps[] = 'wordcamp_wrangle_wordcamps';
			}
			break;

		// Allow Meetup Wranglers to edit Meetup posts.
		case 'edit_wp_meetups':
		case 'edit_published_wp_meetups':
		case 'edit_wp_meetup':
		case 'edit_others_wp_meetups':
			if ( $current_user && $current_user->has_cap( 'wordcamp_wrangle_meetups' ) ) {
				$required_caps[] = 'wordcamp_wrangle_meetups';
			}
			break;

		// WP_Posts_List_Table checks the `edit_post` cap regardless of post type.
		case 'edit_post':
			if ( ! empty( $args ) ) {
				$post_type = get_post_type( $args[0] );
			} else {
				$post_type = get_post_type();
			}

			if ( defined( 'WCPT_POST_TYPE_ID' ) && WCPT_POST_TYPE_ID === $post_type ) {
				if ( $current_user && $current_user->has_cap( 'wordcamp_wrangle_wordcamps' ) ) {
					$required_caps[] = 'wordcamp_wrangle_wordcamps';
				}
			}

			if ( defined( 'WCPT_MEETUP_SLUG' ) && WCPT_MEETUP_SLUG === $post_type ) {
				if ( $current_user && $current_user->has_cap( 'wordcamp_wrangle_meetups' ) ) {
					$required_caps[] = 'wordcamp_wrangle_meetups';
				}
			}
			break;
	}

	if ( ! empty( $required_caps ) ) {
		return $required_caps;
	}

	return $primitive_caps;
}

add_filter( 'map_meta_cap', __NAMESPACE__ . '\map_subrole_caps', 10, 4 );

/**
 * Ignore capabilities that are "additional" i.e. stored in user meta.
 *
 * Additional capabilities should only be granted via `map_meta_cap`, not via values stored in the user meta table.
 *
 * See `additional_capabilities_display` filter.
 *
 * @param bool[]   $allcaps Array of key/value pairs where keys represent a capability name and boolean values
 *                          represent whether the user has that capability.
 * @param string[] $caps    Unused. Required primitive capabilities for the requested capability.
 * @param array    $args    Unused. Arguments that accompany the requested capability check.
 * @param WP_User  $user    The user object.
 *
 * @return bool[]
 */
function omit_usermeta_caps( $allcaps, $caps, $args, $user ) {
	if ( $user instanceof WP_User && count( $user->caps ) > count( $user->roles ) ) {
		$extraneous_caps = array_diff_key( array_keys( $user->caps ), $user->roles );

		foreach ( $extraneous_caps as $cap ) {
			unset( $allcaps[ $cap ] );
		}
	}

	return $allcaps;
}

add_filter( 'user_has_cap', __NAMESPACE__ . '\omit_usermeta_caps', 10, 4 );
