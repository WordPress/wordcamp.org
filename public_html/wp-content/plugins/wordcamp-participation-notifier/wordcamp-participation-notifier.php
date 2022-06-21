<?php
/*
Plugin Name: WordCamp Participation Notifier
Description: Updates a user's WordPress.org profile when they participate in a WordCamp.
Author:      WordCamp Central
Author URI:  http://wordcamp.org
Version:     0.1
License:     GPLv2 or later
*/

use WordPressdotorg\Profiles;

/*
 * Requests will always fail when in local environments, unless the dev is proxied. Proxied devs could test
 * locally if they're careful (especially with user IDs), but it's better to test on w.org sandboxes with
 * test accounts. That prevents real profiles from having test data accidentally added to them.
 */
if ( 'local' === WORDCAMP_ENVIRONMENT ) {
	return;
}

class WordCamp_Participation_Notifier {
	const PROFILES_HANDLER_URL = 'https://profiles.wordpress.org/wp-admin/admin-ajax.php';

	/**
	 * Constructor
	 */
	public function __construct() {
		require_once WP_CONTENT_DIR . '/mu-plugins-private/wporg-mu-plugins/pub/profile-helpers.php';

		// Sync with the wp.org username changes.
		add_filter( 'update_post_meta', array( $this, 'username_meta_update' ), 10, 4 );
		add_filter( 'add_post_meta',    array( $this, 'username_meta_add' ), 10, 3 );
		add_filter( 'delete_post_meta', array( $this, 'username_meta_delete' ), 10, 3 );

		add_action( 'camptix_rl_buyer_completed_registration', array( $this, 'primary_attendee_registered' ), 10, 2 );
		add_action( 'camptix_rl_registration_confirmed',       array( $this, 'additional_attendee_confirmed_registration' ), 10, 2 );
		add_action( 'added_post_meta',                         array( $this, 'attendee_checked_in' ), 10, 4 );
		add_action( 'update_meetup_organizers',                array( $this, 'update_meetup_organizers' ), 10, 2 );
	}

	/**
	 * Add badges to meetup organizers.
	 *
	 * @param array   $organizers
	 * @param WP_Post $post
	 *
	 * @return mixed
	 */
	public function update_meetup_organizers( $organizers, $post ) {
		if ( empty( $organizers ) ) {
			return;
		}

		return Profiles\api( $this->get_meetup_org_payload( $organizers, $post ) );
	}

	/**
	 * Helper function to create post data for AJAX request
	 *
	 * @param array   $organizers
	 * @param WP_Post $post
	 *
	 * @return array
	 */
	protected function get_meetup_org_payload( $organizers, $post ) {
		$association = array(
			'action'      => 'wporg_handle_association',
			'source'      => 'meetups',
			'command'     => 'add',
			'users'       => $organizers,
			'meetup_id'   => $post->ID,
			'association' => 'meetup-organizer'
		);

		return apply_filters( 'wp_meetup_organizer_association_payload', $association, $post );
	}

	/**
	 * Determines whether or not a post should trigger a notification.
	 *
	 * Note: post_status values also need to be checked, but that is handled by post_updated()
	 * because the logic differs based on the payload being generated.
	 *
	 * @param WP_Post $post The post
	 *
	 * @return boolean true if the post can be notified about; false if it can't
	 */
	protected function is_post_notifiable( $post ) {
		$notifiable       = false;
		$valid_post_types = array( 'wcb_speaker', 'wcb_organizer' );

		if ( is_a( $post, 'WP_Post' ) && empty( $post->post_password ) && in_array( $post->post_type, $valid_post_types ) ) {
			$notifiable = true;
		}

		return apply_filters( 'wpn_is_post_notifiable', $notifiable, $post );
	}

	/**
	 * Add the organizer or speaker badge when the User ID meta is added
	 *
	 * @param int    $object_id  ID of the object metadata is for.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value. Serialized if non-scalar.
	 */
	public function username_meta_add( $object_id, $meta_key, $meta_value ) {
		if ( '_wcpt_user_id' === $meta_key && $meta_value ) {
			$post = get_post( $object_id );
			if ( 'publish' !== $post->post_status ) {
				return;
			}

			$this->add_activity( $post, $meta_value );
			$this->add_badge( $post, $meta_value );
		}
	}

	/**
	 * Maybe add or remove the organizer or speaker badge when the User ID meta is updated
	 *
	 * @param int    $meta_id    ID of the metadata entry to update.
	 * @param int    $object_id  ID of the object metadata is for.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value. Serialized if non-scalar.
	 */
	public function username_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( '_wcpt_user_id' === $meta_key && $meta_value ) {
			$post = get_post( $object_id );
			if ( 'publish' !== $post->post_status ) {
				return;
			}

			$meta_value = absint( $meta_value );

			$prev_value = absint( get_post_meta( $object_id, $meta_key, true ) );
			if ( $prev_value && $prev_value !== $meta_value ) {
				$this->maybe_remove_badge( $post, $prev_value );
			}

			$this->add_activity( $post, $meta_value );
			$this->add_badge( $post, $meta_value );
		}
	}

	/**
	 * Remove the organizer or speaker badge when the User ID meta is deleted
	 *
	 * @param string[] $meta_ids   An array of metadata entry IDs to delete.
	 * @param int      $object_id  ID of the object metadata is for.
	 * @param string   $meta_key   Metadata key.
	 */
	public function username_meta_delete( $meta_ids, $object_id, $meta_key ) {
		if ( '_wcpt_user_id' === $meta_key ) {
			$post = get_post( $object_id );
			$prev_value = absint( get_post_meta( $object_id, $meta_key, true ) );
			$this->maybe_remove_badge( $post, $prev_value );
		}
	}

	/**
	 * Makes request to Profile URL to add the speaker or organizer entry to the profile's activity section
	 *
	 * @param WP_Post $post     Speaker/Organizer Post Object.
	 * @param int     $user_id  User ID to add badge for.
	 */
	protected function add_activity( $post, $user_id ) {
		$published_activity_key = $this->get_published_activity_key( $post );
		if ( ! get_user_meta( $user_id, $published_activity_key ) ) {
			Profiles\api( $this->get_post_activity_payload( $post, $user_id ) );
			update_user_meta( $user_id, $published_activity_key, true );
		}
	}

	/**
	 * Makes request to Profile URL to add badge to organizer/speaker. Also adds a meta entry which is used by
	 * `maybe_remove_badge` function to figure out whether to remove a badge or not.
	 *
	 * @param WP_Post $post     Speaker/Organizer Post Object.
	 * @param int     $user_id  User ID to add badge for.
	 */
	protected function add_badge( $post, $user_id ) {
		if ( ! $this->is_post_notifiable( $post ) ) {
			return;
		}

		$meta_key = $this->get_user_meta_key( $post );

		// User already has a badge. Prevent wasteful API call and bail.
		if ( get_user_meta( $user_id, $meta_key, true ) ) {
			return;
		}

		update_user_meta( $user_id, $meta_key, true );

		Profiles\api( $this->get_post_association_payload( $post, 'add', $user_id ) );
	}

	/**
	 * Makes a request to remove the speaker or organizer badge from a user, if the user is removed from all
	 * WordCamps where they were a speaker or organizer.
	 *
	 * @param WP_Post $post     Speaker/Organizer Post Object.
	 * @param int     $user_id  User ID to remove the badge for.
	 */
	protected function maybe_remove_badge( $post, $user_id ) {
		global $wpdb;

		if ( ! $this->is_post_notifiable( $post ) ) {
			return;
		}

		$meta_key = $this->get_user_meta_key( $post );

		// User does not have a badge anyway. Prevent wasteful API call and bail.
		if ( ! $user_id || ! get_user_meta( $user_id, $meta_key, true ) ) {
			return;
		}

		delete_user_meta( $user_id, $meta_key );

		$meta_key_prefix = $this->get_user_meta_key_prefix( $post );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->usermeta WHERE user_id = %d AND meta_key like %s;",
				$user_id,
				"$meta_key_prefix%"
			)
		);

		if ( '0' === $count ) {
			Profiles\api( $this->get_post_association_payload( $post, 'remove', $user_id ) );
		}
	}

	/**
	 * Meta key to store user metadata for organizer/speaker association.
	 *
	 * We store meta_key in form of wc_{post_type}_{blog_id}_{post_id} because
	 *
	 * 1. Since meta_key is indexed, count query on wc_{post_type}% for a specific user_id will be performant. We will use this query to figure out if we want to remove a badge.
	 *
	 * 2. Adding separate rows per WordCamp per User per Post allows us to avoid incorrect badge removal in some cases.
	 * For egs, when an organizer accidentally adds same wporg name to multiple speaker post, and then corrects their mistake by removing it in all but one post.
	 *
	 * 3. Because of verbosity, deleting user meta entry will be less error prone.
	 *
	 * @param WP_Post $post Sponsor/Organizer post object.
	 *
	 * @return string
	 */
	private function get_user_meta_key( $post ) {
		return $this->get_user_meta_key_prefix( $post ) . get_current_blog_id() . '_' . $post->ID;
	}

	/**
	 * Meta key prefix to store user metadata for organizer/speaker association. This prefix is used in count query to figure if a user should have an organizer/speaker badge.
	 *
	 * @param WP_Post $post Sponsor/Organizer post object.
	 *
	 * @return string
	 */
	private function get_user_meta_key_prefix( $post ) {
		return 'wc_' .  $post->post_type . '_';
	}

	/**
	 * Meta key name to store user matadata for whether activity is published or not.
	 * Used to prevent publishing duplicate activities.
	 *
	 * @param WP_Post $post Post object, will be organizer/sponsor/tix_attendee.
	 *
	 * @return string
	 */
	private function get_published_activity_key( $post ) {
		return 'wc_published_activity_' . get_current_blog_id() . "_$post->ID";
	}

	/**
	 * Send a notification when someone successfully registered for a ticket.
	 *
	 * If they purchased multiple tickets, this will only send a notification for the one they bought for theirself.
	 * Notifications for the other attendees will be sent in additional_attendee_confirmed_registration().
	 *
	 * @todo Handle cases where the username changes, either from the admin editing the back-end post, or a from a
	 *       different user updating via the edit token?
	 * @todo The handler doesn't support removing activity, but maybe do that here if support is added.
	 *
	 * @param WP_Post $attendee
	 * @param string  $username
	 */
	public function primary_attendee_registered( $attendee, $username ) {
		$user_id = $this->get_saved_wporg_user_id( $attendee );
		Profiles\api( $this->get_post_activity_payload( $attendee, $user_id, 'attendee_registered' ) );
	}

	/**
	 * Send a notification when an attendee whose ticked was bought on their behalf confirms their registration.
	 *
	 * @todo Handle cases where the username changes, either from the admin editing the back-end post, or a from a
	 *       different user updating via the edit token?
	 * @todo The handler doesn't support removing activity, but maybe do that here if support is added.
	 *
	 * @param int $attendee_id
	 * @param string $username
	 */
	public function additional_attendee_confirmed_registration( $attendee_id, $username ) {
		$attendee = get_post( $attendee_id );
		$user_id  = $this->get_saved_wporg_user_id( $attendee );

		Profiles\api( $this->get_post_activity_payload( $attendee, $user_id, 'attendee_registered' ) );
	}

	/**
	 * Send a notification when an attendee is marked as having checked in at the venue.
	 *
	 * @todo The handler doesn't support removing activity, but maybe do that here if support is added.
	 *
	 * @param int $meta_id
	 * @param int $attendee_id
	 * @param string $meta_key
	 * @param string $meta_value
	 */
	public function attendee_checked_in( $meta_id, $attendee_id, $meta_key, $meta_value ) {
		if ( 'tix_attended' != $meta_key || true !== $meta_value ) {
			return;
		}

		$attendee = get_post( $attendee_id );
		$user_id  = $this->get_saved_wporg_user_id( $attendee );

		Profiles\api( $this->get_post_activity_payload( $attendee, $user_id, 'attendee_checked_in' ) );
	}

	/**
	 * Builds the payload for an activity notification based on a new post
	 *
	 * @param WP_Post $post
	 * @param int     $user_id
	 * @param string  $activity_type
	 *
	 * @return array|false
	 */
	protected function get_post_activity_payload( $post, $user_id = null, $activity_type = null ) {
		$wordcamp = get_wordcamp_post();

		if ( ! $user_id ) {
			return false;
		}

		$activity = array(
			'action'        => 'wporg_handle_activity',
			'source'        => 'wordcamp',
			'timestamp'     => strtotime( $post->post_modified_gmt ),
			'user'          => $user_id,
			'wordcamp_id'   => get_current_blog_id(),
			'wordcamp_name' => get_wordcamp_name(),
			'wordcamp_date' => empty( $wordcamp->meta['Start Date (YYYY-mm-dd)'][0] ) ? false : date( 'F jS', $wordcamp->meta['Start Date (YYYY-mm-dd)' ][0] ),
			'url'           => site_url(),
		);

		switch( $post->post_type ) {
			case 'wcb_speaker':
				$activity['speaker_id']   = $post->ID;
			break;

			case 'wcb_organizer':
				$activity['organizer_id'] = $post->ID;
			break;

			case 'tix_attendee':
				$activity['attendee_id']  = $post->ID;
				$activity['activity_type'] = $activity_type;

				if ( 'attendee_checked_in' == $activity_type ) {
					$checked_in = new WP_Query( array(
						'post_type'      => 'tix_attendee',
						'posts_per_page' => 1,
						'meta_query'     => array(
							array(
								'key'   => 'tix_attended',
								'value' => true
							)
						)
					) );

					$activity['checked_in_count'] = $checked_in->found_posts;
				}
			break;

			default:
				$activity = false;
			break;
		}

		return apply_filters( 'wpn_post_activity_payload', $activity, $post, $user_id );
	}

	/**
	 * Build the payload for an association notification based on a new or updated post
	 *
	 * @param WP_Post $post
	 * @param string  $command 'add' | 'remove'
	 * @param int     $user_id
	 * @return array|false
	 */
	protected function get_post_association_payload( $post, $command, $user_id = null ) {
		$association = false;

		if ( $user_id ) {
			$association = array(
				'action'        => 'wporg_handle_association',
				'source'        => 'wordcamp',
				'command'       => $command,
				'user_id'       => $user_id,
				'wordcamp_id'   => get_current_blog_id(),
				'wordcamp_name' => get_wordcamp_name(),
				'url'           => site_url(),
			);

			switch( $post->post_type ) {
				case 'wcb_speaker':
					$association['association'] = 'wordcamp-speaker';
				break;

				case 'wcb_organizer':
					$association['association'] = 'wordcamp-organizer';
				break;

				case 'tix_attendee':
				default:
					$association = false;
				break;
			}
		}

		return apply_filters( 'wpn_post_association_payload', $association, $post, $command, $user_id );
	}

	/**
	 * Get the previous WordPress.org user_id associated with a custom post
	 *
	 * This is called during the context of a post being updated, so the saved username is the one saved in
	 * the database, as opposed to the one in the $_POST request or the currently logged in user.
	 *
	 * @param WP_Post $post
	 * @return false|int
	 */
	protected function get_saved_wporg_user_id( $post ) {
		$user_id = false;

		if ( in_array( $post->post_type, array( 'wcb_speaker', 'wcb_organizer' ) ) ) {
			$user_id = (int) get_post_meta( $post->ID, '_wcpt_user_id', true );
		} elseif ( 'tix_attendee' == $post->post_type ) {
			$user = get_user_by( 'login', get_post_meta( $post->ID, 'tix_username', true ) );

			if ( is_a( $user, 'WP_User' ) ) {
				$user_id = $user->ID;
			}
		}

		return $user_id;
	}
}

$GLOBALS['WordCamp_Participation_Notifier'] = new WordCamp_Participation_Notifier();
