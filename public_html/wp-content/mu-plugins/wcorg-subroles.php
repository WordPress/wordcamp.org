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
					'wordcamp_wrangle_meetups'   => true,
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

			/**
			 * Campus Connect Viewer
			 *
			 * - Read the Campus Connect Details report through the REST API on Central.
			 *
			 * Narrower than `report_viewer`, which opens every private report. This grants no `read`, because
			 * the holder only needs the REST endpoint, not wp-admin.
			 */
			case 'campus_connect_viewer':
				// These capabilities only apply on central.wordcamp.org.
				if ( WORDCAMP_ROOT_BLOG_ID === get_current_blog_id() ) {
					$newcaps = array(
						'view_campus_connect_report' => true,
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
			if ( $current_user && $current_user->has_cap( 'wordcamp_wrangle_wordcamps' ) ) {
				$required_caps[] = 'wordcamp_wrangle_wordcamps';
			}
			break;

		case 'edit_others_wordcamps':
			if ( $current_user && $current_user->has_cap( 'wordcamp_wrangle_wordcamps' ) ) {
				$required_caps[] = 'wordcamp_wrangle_wordcamps';
			} elseif ( $current_user && user_mentors_wordcamp( $current_user, get_wordcamp_being_saved( $args ) ) ) {
				/*
				 * Saving a camp whose author isn't the current user makes core ask for this cap without
				 * naming the post (`_wp_translate_postdata()`), so a mentor's Update was refused even though
				 * `edit_post` let them open the screen. Grant it only for the camp the request is saving,
				 * and only to its mentor; the cap stays unavailable everywhere else.
				 */
				$required_caps[] = 'edit_posts';
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
				$post      = get_post( $args[0] );
				$post_type = get_post_type( $args[0] );
			} else {
				$post      = get_post();
				$post_type = get_post_type();
			}

			if ( defined( 'WCPT_POST_TYPE_ID' ) && WCPT_POST_TYPE_ID === $post_type ) {
				if ( $current_user && $current_user->has_cap( 'wordcamp_wrangle_wordcamps' ) ) {
					$required_caps[] = 'wordcamp_wrangle_wordcamps';
				}

				// Mentors can edit their mentee WordCamp posts.
				if ( $current_user && user_mentors_wordcamp( $current_user, $post ) ) {
					// Note: `edit_posts` is only granted to users with at least Contributor-level access.
					// This mapping is intentional and assumes mentors already have contributor+ access.
					$required_caps[] = 'edit_posts';
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
 * Whether the user is the mentor named on a WordCamp post.
 *
 * @param WP_User      $user
 * @param WP_Post|null $post
 *
 * @return bool
 */
function user_mentors_wordcamp( $user, $post ) {
	if ( ! $post || ! defined( 'WCPT_POST_TYPE_ID' ) || WCPT_POST_TYPE_ID !== $post->post_type ) {
		return false;
	}

	$mentor = wcorg_get_user_by_canonical_names( $post->{'Mentor WordPress.org User Name'} );

	return $mentor && $user->ID === $mentor->ID;
}

/**
 * The WordCamp post a save request is about, if the request keeps its author.
 *
 * Core's save path checks `edit_others_wordcamps` with no post argument, because the posted author
 * isn't the current user. post.php always has the post being saved in `post_ID`, and the classic
 * form posts the existing author back in `post_author`, so that's the case a mentor needs. A request
 * that posts a different author is a change of author, which stays a wrangler's job. Nothing else is
 * consulted: a GET or a REST request yields nothing.
 *
 * @param array $args Arguments passed to the capability check.
 *
 * @return WP_Post|null
 */
function get_wordcamp_being_saved( $args ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- post.php verifies the nonce before `edit_post()` runs this check; this only reads which post the request is about.
	$post_id = ! empty( $args[0] ) ? absint( $args[0] ) : 0;

	if ( ! $post_id && ! empty( $_POST['post_ID'] ) ) {
		$post_id = absint( $_POST['post_ID'] );
	}

	$post = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || ! defined( 'WCPT_POST_TYPE_ID' ) || WCPT_POST_TYPE_ID !== $post->post_type ) {
		return null;
	}

	$posted_author = absint( $_POST['post_author_override'] ?? $_POST['post_author'] ?? 0 );

	if ( $posted_author && $posted_author !== (int) $post->post_author ) {
		return null;
	}
	// phpcs:enable

	return $post;
}

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
